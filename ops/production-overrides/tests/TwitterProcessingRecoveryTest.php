<?php
// Exact installed trait with fake storage and X transport. No app bootstrap or network.
require getenv('MIXPOST_VENDOR_AUTOLOAD') ?: '/var/www/html/vendor/autoload.php';
use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\{Config, Facade, File, Log, Storage};
use Illuminate\Support\Str;
use Illuminate\Container\Container;
use Inovector\Mixpost\Models\Media;
use Inovector\Mixpost\Enums\SocialProviderResponseStatus as Status;
use Inovector\Mixpost\Support\{PublishCheckpoint, SocialProviderResponse, TemporaryDirectory};

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
    public ?Closure $beforeUpload = null;
    public function __construct() { $this->finalized = json_decode('{"media_id_string":"9223372036854775999","processing_info":{"state":"pending","check_after_secs":1}}'); }
    public function setApiVersion($version): void {}
    public function setChunkSize($size): void {}
    public function getLastHttpCode(): int { return $this->code; }
    public function upload(...$args): object {
        $this->uploads++;
        $this->beforeUpload?->__invoke(...$args);
        $this->code = 200;
        return $this->finalized;
    }
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

class XImageMedia extends Media
{
    public function isLocalAdapter(): bool
    {
        return $this->disk === 'local';
    }

    public function getFullPath(): string
    {
        return $this->path;
    }

    public function getUrl(): string
    {
        if ($this->disk !== 'external_media') {
            throw new RuntimeException('Managed images must not use their public URL');
        }
        return $this->path;
    }
}

$fixture = (new TemporaryDirectory(sys_get_temp_dir()))->create();
try {
    $stage = $fixture->path('stage');
    Config::swap(new Repository(['mixpost' => ['temporary_directory_path' => $stage]]));
    File::swap(new Filesystem);
    Str::macro('randomSafe', static fn (): string => bin2hex(random_bytes(16)));
    $storage = new class {
        public string $bytes = 'original-image';
        public int $reads = 0;
        public bool $unavailable = false;

        public function disk(string $disk): self
        {
            if ($disk !== 's3') {
                throw new RuntimeException('Unexpected storage disk');
            }
            return $this;
        }

        public function readStream(string $path)
        {
            if ($path !== 'workspace/uploads/original.jpg') {
                throw new RuntimeException('Unexpected object key');
            }
            $this->reads++;
            if ($this->unavailable) {
                throw new RuntimeException('Storage read timed out');
            }
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, $this->bytes);
            rewind($stream);

            return $stream;
        }
    };
    Storage::swap($storage);

    $media = (new XImageMedia)->forceFill([
        'id' => 1911, 'disk' => 's3', 'path' => 'workspace/uploads/original.jpg',
        'mime_type' => 'image/jpeg', 'size' => strlen($storage->bytes),
    ]);
    $transport = new XRecoveryTransport;
    $transport->finalized = (object) ['media_id_string' => 'image-id'];
    $provider = new XRecoveryProvider($transport);
    $uploaded = [];
    $transport->beforeUpload = static function (string $endpoint, array $parameters) use (&$uploaded): void {
        $uploaded = [$endpoint, $parameters, file_get_contents($parameters['media'])];
    };
    $response = $provider->publishPost('Caption', collect([$media]));
    xCheck($response->isOk() && $storage->reads === 1 && $transport->creates === 1
        && $uploaded[0] === 'media/upload' && $uploaded[2] === $storage->bytes
        && $uploaded[1]['media_category'] === 'tweet_image' && $uploaded[1]['media_type'] === 'image/jpeg'
        && $uploaded[1]['total_bytes'] === $media->size && glob($stage.'/*') === [],
        'cloud image uses its configured disk, uploads exact original bytes and cleans the temporary copy');

    $storage->unavailable = true;
    $response = $provider->publishPost('Caption', collect([$media]));
    $checkpoint = PublishCheckpoint::fromResponse($response, 0);
    xCheck($response->isPending() && $response->retryAfter() === 15
        && $checkpoint->stage->value === 'transfer' && ! $checkpoint->live
        && $transport->uploads === 1 && $transport->creates === 1 && glob($stage.'/*') === [],
        'storage interruption releases the existing publishing job before uploading or marking the destination failed');

    $storage->unavailable = false;
    $original = $storage->bytes;
    $storage->bytes = 'partial';
    $response = xResume($transport, $media, $response);
    xCheck($response->isPending() && $response->retryAfter() === 30
        && $transport->uploads === 1 && $transport->creates === 1 && glob($stage.'/*') === [],
        'a truncated read is retried after a fresh worker restores the storage checkpoint');

    $storage->bytes = $original;
    $recovered = xResume($transport, $media, $response);
    xCheck($recovered->isOk() && $transport->uploads === 2 && $transport->creates === 2
        && $uploaded[2] === $original && glob($stage.'/*') === [],
        'storage recovery publishes once with the original bytes and cleans up');

    $storage->unavailable = true;
    $readCount = $storage->reads;
    $exhausted = $provider->publishPost('Caption', collect([$media]));
    $exhausted = xResume($transport, $media, $exhausted);
    $exhausted = xResume($transport, $media, $exhausted);
    xCheck($exhausted->hasError() && $storage->reads === $readCount + 3
        && $exhausted->context() === ['Mixpost could not read the complete image from storage.']
        && $transport->uploads === 2 && $transport->creates === 2 && glob($stage.'/*') === [],
        'persistent storage failure becomes terminal after three reads without uploading or creating a tweet');

    $storage->unavailable = false;
    $transport->beforeUpload = static function (): void { throw new RuntimeException('Upload timed out'); };
    $response = $provider->publishPost('Caption', collect([$media]));
    xCheck($response->hasError() && $transport->uploads === 3 && $transport->creates === 2 && glob($stage.'/*') === [],
        'an X upload exception cleans the staged image and keeps normal failure handling');

    $source = $fixture->path('local.jpg');
    file_put_contents($source, $original);
    $local = (new XImageMedia)->forceFill([
        'id' => 1912, 'disk' => 'local', 'path' => $source,
        'mime_type' => 'image/jpeg', 'size' => strlen($original),
    ]);
    $readCount = $storage->reads;
    $transport->beforeUpload = static function (string $endpoint, array $parameters) use (&$uploaded): void {
        $uploaded = $parameters;
    };
    $response = $provider->publishPost('Caption', collect([$local]));
    xCheck($response->isOk() && $uploaded['media'] === $source && is_file($source) && $storage->reads === $readCount,
        'local images are passed through and the source is retained');

    $url = 'https://example.test/stock-image.jpg';
    $external = (new XImageMedia)->forceFill([
        'id' => 1913, 'disk' => 'external_media', 'path' => $url, 'mime_type' => 'image/jpeg',
    ]);
    $response = $provider->publishPost('Caption', collect([$external]));
    xCheck($response->isOk() && $uploaded['media'] === $url && $storage->reads === $readCount,
        'external images retain their URL without consulting a managed disk');

    $storage->unavailable = true;
    $transport = new XRecoveryTransport;
    $transport->finalized = (object) ['media_id_string' => 'first-image'];
    $images = collect([$local, $media]);
    $pending = (new XRecoveryProvider($transport))->publishPost('Caption', $images);
    $checkpoint = PublishCheckpoint::fromResponse($pending, 0);
    xCheck($pending->isPending() && $transport->creates === 0 && $transport->uploads === 1
        && $checkpoint->state['uploads'][$local->id]['id'] === 'first-image',
        'a second image read failure checkpoints the already uploaded first image');
    $storage->unavailable = false;
    $transport->finalized = (object) ['media_id_string' => 'second-image'];
    $resume = PublishCheckpoint::fromArray(json_decode(json_encode($checkpoint->toArray()), true))->toProviderState();
    $response = (new XRecoveryProvider($transport))->publishPost('Caption', $images, ['resume' => $resume]);
    xCheck($response->isOk() && $response->context()['media'] === ['first-image', 'second-image']
        && $transport->uploads === 2 && $transport->creates === 1,
        'the resumed job uploads only the missing image and creates one tweet with both IDs');
} finally {
    $fixture->delete();
}
