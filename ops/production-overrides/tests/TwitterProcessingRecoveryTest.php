<?php
// Exact installed trait; no bootstrap, database, storage, mail or external network.
require '/var/www/html/vendor/autoload.php';
use Illuminate\Support\Facades\{Facade, Log};
use Illuminate\Container\Container;
use Inovector\Mixpost\Models\Media;
use Inovector\Mixpost\Enums\SocialProviderResponseStatus as Status;
use Inovector\Mixpost\Support\{PublishCheckpoint, SocialProviderResponse};

Facade::setFacadeApplication(new Container);
Log::swap(new Psr\Log\NullLogger);
set_error_handler(function ($level, $message, $file, $line) { throw new ErrorException($message, 0, $level, $file, $line); });
set_exception_handler(function (Throwable $e) { fwrite(STDERR, (string) $e); exit(1); });
class XRecoveryMedia extends Media {
    public function isVideo(): bool { return true; }
    public function isLocalAdapter(): bool { return true; }
    public function getFullPath(): string { return '/tmp/fixture.mp4'; }
}
class XRecoveryTransport {
    public int $uploads = 0;
    public int $creates = 0;
    public int $code = 200;
    public array $statuses = [];
    public array $polledIds = [];
    public object $finalized;
    public function __construct() { $this->finalized = json_decode('{"media_id_string":"9223372036854775999","processing_info":{"state":"pending","check_after_secs":1}}'); }
    public function setApiVersion($version): void {}
    public function setChunkSize($size): void {}
    public function getLastHttpCode(): int { return $this->code; }
    public function upload(...$args): object { $this->uploads++; $this->code = 200; return $this->finalized; }
    public function mediaStatus(string $id): mixed {
        $this->polledIds[] = $id;
        [$this->code, $result] = array_shift($this->statuses);
        if ($result instanceof Exception) { throw $result; }
        return $result;
    }
}
class XRecoveryProvider {
    use Inovector\Mixpost\SocialProviders\Twitter\Concerns\ManagesResources;
    public array $values = ['account_id' => 101];
    public function __construct(public XRecoveryTransport $connection) {}
    public function getTier(): string { return 'basic'; }
    public function response($status, $context): SocialProviderResponse { return new SocialProviderResponse($status, $context); }
    protected function storePostWithApiV2(string $text, array $mediaResult, array $params): SocialProviderResponse {
        $this->connection->creates++;
        return new SocialProviderResponse(Status::OK, ['id' => 'fixture-post', 'media' => $mediaResult['ids']]);
    }
}
function xCheck(bool $condition, string $label): void { if (! $condition) { throw new RuntimeException($label); } echo 'PASS '.$label.PHP_EOL; }
function statusFixture(string $state, array $extra = []): object { return (object) ['processing_info' => (object) array_merge(['state' => $state], $extra)]; }
function xStart(): array {
    $connection = new XRecoveryTransport;
    $media = (new XRecoveryMedia)->forceFill(['id' => 1910, 'mime_type' => 'video/mp4', 'size' => 34, 'conversions' => []]);
    $result = (new XRecoveryProvider($connection))->publishPost('Caption', collect([$media]));
    return [$connection, $media, $result];
}
function xResume(XRecoveryTransport $connection, Media $media, SocialProviderResponse $pending): SocialProviderResponse {
    // JSON roundtrip and new provider reproduce a fresh queue worker.
    return (new XRecoveryProvider($connection))->publishPost('Caption', collect([$media]), ['resume' => json_decode(json_encode($pending->resumeState()), true)]);
}
[$c, $m, $r] = xStart();
xCheck($r->isPending() && $r->retryAfter() === 1 && $c->uploads === 1 && $c->creates === 0, 'accepted upload checkpoints before polling or creating a post');
xCheck($r->resumeState()['state']['uploads'][1910]['id'] === '9223372036854775999', 'large media IDs remain exact strings');
$checkpoint = PublishCheckpoint::fromResponse($r, 0);
xCheck($checkpoint->stage->value === 'processing' && ! $checkpoint->live, 'pipeline stores unfinished media without marking a live post');
$c->statuses = [[200, (object) []], [503, (object) ['message' => 'Temporary provider outage']], [200, statusFixture('succeeded')]];
$r = xResume($c, $m, $r);
xCheck($r->isPending() && $r->retryAfter() === 15 && $c->creates === 0, 'missing processing_info recovers without undefined properties or publication');
$next = PublishCheckpoint::fromResponse($r, 0, $checkpoint);
xCheck($next->expiresAt === $checkpoint->expiresAt, 'retries preserve the fixed processing deadline');
$r = xResume($c, $m, $r);
xCheck($r->isPending() && $r->retryAfter() === 30, 'consecutive temporary failures back off');
$r = xResume($c, $m, $r);
xCheck($r->isOk() && $c->uploads === 1 && $c->creates === 1 && count(array_unique($c->polledIds)) === 1, 'recovery across fresh workers reuses the same upload and creates once');
foreach ([[429, (object) ['errors' => [(object) ['code' => 88, 'message' => 'Rate limited']]]], [0, new RuntimeException('connection lost')], [200, null], [200, statusFixture('unknown')], [408, (object) []]] as $fixture) {
    [$c, $m, $r] = xStart(); $c->statuses = [$fixture]; $r = xResume($c, $m, $r);
    xCheck($r->isPending() && $c->uploads === 1 && $c->creates === 0, 'temporary status '.$fixture[0].' remains pending');
}
[$c, $m, $r] = xStart(); $c->statuses = array_fill(0, 8, [200, (object) []]);
for ($i = 0; $i < 8; $i++) { $r = xResume($c, $m, $r); }
xCheck($r->hasError() && str_contains(json_encode($r->context()), 'could not recover') && $c->creates === 0 && $c->uploads === 1, 'eight incomplete statuses exhaust recovery without replaying publication');
[$c, $m, $r] = xStart(); $c->statuses = [[403, (object) ['errors' => [(object) ['code' => 220, 'message' => 'Permission denied']]]]];
$r = xResume($c, $m, $r);
xCheck($r->hasError() && str_contains(json_encode($r->context()), 'Permission denied') && $c->creates === 0, 'permanent permissions failure retains the provider explanation');
[$c, $m, $r] = xStart(); $c->statuses = [[200, statusFixture('failed', ['error' => (object) ['message' => 'Invalid video']])]];
$r = xResume($c, $m, $r);
xCheck($r->hasError() && str_contains(json_encode($r->context()), 'Invalid video') && $c->creates === 0, 'terminal processing rejection cannot publish');
[$c, $m, $r] = xStart(); $c->statuses = [[200, statusFixture('in_progress', ['check_after_secs' => 10000])]];
$r = xResume($c, $m, $r);
xCheck($r->isPending() && $r->retryAfter() === 300, 'provider wait is bounded without blocking a worker');
[$c, $m, $r] = xStart(); $c->statuses = [[200, statusFixture('succeeded')]];
$r = xResume($c, $m, $r);
xCheck($r->isOk() && $r->context()['media'] === ['9223372036854775999'], 'confirmed processing passes the exact media ID to publication');
[$c, $m, $r] = xStart(); $c->finalized = (object) ['media_id_string' => 'ready-id'];
$r = (new XRecoveryProvider($c))->publishPost('Caption', collect([$m]));
xCheck($r->isOk(), 'synchronous upload without processing_info remains supported');
