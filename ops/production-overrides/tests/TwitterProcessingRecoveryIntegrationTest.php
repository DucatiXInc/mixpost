<?php
// Restored MySQL only. The real publishing action/provider use a fake X transport.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! app()->environment('testing') || ! str_starts_with(config('database.connections.mysql.host'), 'mixpost-local-rehearsal-')) {
    throw new RuntimeException('Isolated restored database required');
}
set_exception_handler(function (Throwable $e) { fwrite(STDERR, (string) $e); exit(1); });
use Abraham\TwitterOAuth\TwitterOAuth;
use Illuminate\Support\Facades\{DB, Event, Bus, Notification, Http};
use Inovector\Mixpost\Models\{Post, Account, Workspace, Media};
use Inovector\Mixpost\Facades\WorkspaceManager;
use Inovector\Mixpost\Actions\Post\{AccountPublishPost, FinalizePostPublishing};
use Inovector\Mixpost\Enums\{PostAccountStatus, SocialProviderResponseStatus};
use Inovector\Mixpost\Support\SocialProviderResponse;
use Inovector\Mixpost\SocialProviders\Twitter\TwitterProvider;
use Inovector\Mixpost\Contracts\SocialProvider;
Event::fake(); Bus::fake(); Notification::fake(); Http::preventStrayRequests();
class PipelineXTransport extends TwitterOAuth {
    public int $uploads = 0;
    public int $creates = 0;
    public int $code = 200;
    public array $statuses = [];
    public function __construct() {}
    public function getLastHttpCode(): int { return $this->code; }
    public function upload(string $path, array $parameters = [], array $options = []) {
        $this->uploads++; $this->code = 200;
        return json_decode('{"media_id_string":"9223372036854775999","processing_info":{"state":"pending","check_after_secs":1}}');
    }
    public function mediaStatus(string $media_id) {
        if ($media_id !== '9223372036854775999') { throw new RuntimeException('Media ID changed'); }
        $this->code = 200; return array_shift($this->statuses) ?? (object) [];
    }
    public function post(string $path, array $parameters = [], array $options = []) {
        if ($path !== 'tweets' || $parameters['media']['media_ids'] !== ['9223372036854775999']) { throw new RuntimeException('Unexpected publication'); }
        $this->creates++; $this->code = 201; return (object) ['data' => (object) ['id' => 'fixture-x-published']];
    }
}
class PipelineXProvider extends TwitterProvider {
    public function getTier(): string { return 'basic'; }
    public function buildResponse($response, ?Closure $okResult = null): SocialProviderResponse {
        return new SocialProviderResponse(SocialProviderResponseStatus::OK, $okResult ? $okResult() : []);
    }
}
class PipelineXAction extends AccountPublishPost {
    public function __construct(public PipelineXProvider $provider) {}
    public function connectProvider(Account $account): SocialProvider { return $this->provider; }
    public function prepareSocialVideos(Account $account, Post $post): bool { return true; }
}
function pipelineCheck(bool $condition, string $label): void { if (! $condition) { throw new RuntimeException($label); } echo 'PASS '.$label.PHP_EOL; }
$account = Account::withoutGlobalScopes()->where('provider', 'twitter')->firstOrFail();
WorkspaceManager::setCurrent(Workspace::findOrFail($account->workspace_id));
$transport = new PipelineXTransport;
function pipelineAction(PipelineXTransport $transport): PipelineXAction {
    $provider = new PipelineXProvider(Illuminate\Http\Request::create('/'), '', '', '', []); $provider->connection = $transport;
    return new PipelineXAction($provider);
}
DB::beginTransaction();
try {
    $post = Post::firstOrFail()->replicate();
    $post->forceFill(['uuid' => (string) Illuminate\Support\Str::uuid(), 'status' => 1, 'schedule_status' => 1, 'publishing_runs' => 1, 'published_at' => null])->saveQuietly();
    $post->accounts()->attach($account->id, ['status' => PostAccountStatus::PUBLISHING->value]);
    $media = Media::firstOrFail()->replicate();
    $media->forceFill(['uuid' => (string) Illuminate\Support\Str::uuid(), 'mime_type' => 'video/mp4', 'disk' => 'public', 'path' => 'fixture.mp4', 'size' => 34, 'conversions' => [], 'processing' => false, 'data' => ['duration' => 30, 'duration_ms' => 30000]])->saveQuietly();
    $post->versions()->create(['account_id' => 0, 'is_original' => true, 'content' => [['body' => 'X recovery fixture', 'media' => [$media->id], 'video_thumbs' => []]], 'options' => []]);
    $response = pipelineAction($transport)($account, $post);
    pipelineCheck($response->isPending() && $post->fresh()->hasPublishState($account) && $transport->uploads === 1 && $transport->creates === 0, 'actual publishing action persists accepted X media before releasing');
    $state = $post->fresh()->publishState($account); $deadline = $state['expires_at'];
    pipelineCheck($state['state']['uploads'][$media->id]['id'] === '9223372036854775999' && ! $post->fresh()->hasErrors(), 'durable MySQL checkpoint retains exact media ID without recording a failure');
    $response = pipelineAction($transport)($account, $post->fresh());
    pipelineCheck($response->isPending() && $post->fresh()->publishState($account)['expires_at'] === $deadline && $transport->uploads === 1 && $transport->creates === 0, 'fresh action recovers missing status using original upload and deadline');
    $transport->statuses = [json_decode('{"processing_info":{"state":"succeeded"}}')];
    $response = pipelineAction($transport)($account, $post->fresh());
    pipelineCheck($response->isOk() && $post->fresh()->accountPublishStatus($account) === PostAccountStatus::PUBLISHED && ! $post->fresh()->hasPublishState($account), 'confirmed X processing publishes and clears the persisted checkpoint');
    (new FinalizePostPublishing)($post->fresh());
    pipelineCheck($post->fresh()->isPublished() && $transport->uploads === 1 && $transport->creates === 1, 'restarted pipeline settles with exactly one upload and one tweet');
    Notification::assertNothingSent();
    pipelineCheck(true, 'successful X recovery sends no email');
} finally { DB::rollBack(); }
