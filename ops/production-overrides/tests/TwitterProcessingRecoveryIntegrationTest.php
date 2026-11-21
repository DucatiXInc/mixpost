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
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\{DB, Event, Bus, Notification, Http, Storage};
use Inovector\Mixpost\Models\{Post, Account, Workspace, Media};
use Inovector\Mixpost\Facades\WorkspaceManager;
use Inovector\Mixpost\Actions\Post\{AccountPublishPost, FinalizePostPublishing};
use Inovector\Mixpost\Enums\{PostAccountStatus, SocialProviderResponseStatus};
use Inovector\Mixpost\Events\Post\PostPublishedFailed;
use Inovector\Mixpost\Notifications\PostPublishingFailedNotification;
use Inovector\Mixpost\Support\SocialProviderResponse;
use Inovector\Mixpost\SocialProviders\Twitter\TwitterProvider;
use Inovector\Mixpost\Contracts\SocialProvider;
Event::fake(); Bus::fake(); Notification::fake(); Http::preventStrayRequests();
class PipelineXTransport extends TwitterOAuth {
    public int $uploads = 0;
    public int $creates = 0;
    public int $code = 200;
    public array $statuses = [];
    public bool $requiresProcessing = true;
    public function __construct() {}
    public function getLastHttpCode(): int { return $this->code; }
    public function upload(string $path, array $parameters = [], array $options = []) {
        $this->uploads++; $this->code = 200;
        $response = ['media_id_string' => '9223372036854775999'];
        if ($this->requiresProcessing) {
            $response['processing_info'] = ['state' => 'pending', 'check_after_secs' => 1];
        }

        return json_decode(json_encode($response, JSON_THROW_ON_ERROR));
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
    public static function getTier(): string { return 'basic'; }
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
    pipelineCheck(Notification::getFacadeRoot()->sentNotifications() === [], 'successful X video recovery sends no email');

    $cloudDisk = Storage::build([
        'driver' => 's3', 'region' => 'us-east-1', 'bucket' => 'fixture',
        'key' => 'fixture', 'secret' => 'fixture',
    ]);
    $fixtureDisk = new class($cloudDisk->getDriver(), $cloudDisk->getAdapter()) extends FilesystemAdapter {
        public bool $available = false;

        public function readStream($path)
        {
            if ($path !== 'fixture.jpg') {
                throw new LogicException('Unexpected X storage fixture path.');
            }
            if (! $this->available) {
                return null;
            }
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, 'fixture-image');
            rewind($stream);

            return $stream;
        }
    };
    Storage::set('x-rehearsal-cloud', $fixtureDisk);

    $image = $media->replicate();
    $image->forceFill([
        'uuid' => (string) Illuminate\Support\Str::uuid(), 'mime_type' => 'image/jpeg',
        'disk' => 'x-rehearsal-cloud', 'path' => 'fixture.jpg',
        'size' => strlen('fixture-image'), 'data' => [],
    ])->saveQuietly();

    foreach ([true, false] as $recover) {
        Notification::fake();
        Event::fake();
        $fixtureDisk->available = false;
        $transport = new PipelineXTransport;
        $transport->requiresProcessing = false;
        $imagePost = $post->replicate();
        $imagePost->forceFill([
            'uuid' => (string) Illuminate\Support\Str::uuid(), 'status' => 1,
            'schedule_status' => 1, 'publishing_runs' => 1, 'published_at' => null,
        ])->saveQuietly();
        $imagePost->accounts()->attach($account->id, ['status' => PostAccountStatus::PUBLISHING->value]);
        $imagePost->versions()->create([
            'account_id' => 0, 'is_original' => true, 'options' => [],
            'content' => [['body' => 'X image storage fixture', 'media' => [$image->id], 'video_thumbs' => []]],
        ]);

        foreach ([15, 30] as $delay) {
            $response = pipelineAction($transport)($account, $imagePost->fresh());
            $freshPost = $imagePost->fresh();
            pipelineCheck($response->isPending() && $response->retryAfter() === $delay
                && $freshPost->accountPublishStatus($account) === PostAccountStatus::PUBLISHING
                && $freshPost->hasPublishState($account) && ! $freshPost->hasErrors()
                && $transport->uploads === 0 && $transport->creates === 0
                && Notification::getFacadeRoot()->sentNotifications() === [],
                "X image storage retry waits {$delay}s without recording a failure or sending an email");
            Event::assertNotDispatched(PostPublishedFailed::class);
        }

        $fixtureDisk->available = $recover;
        $response = pipelineAction($transport)($account, $imagePost->fresh());
        (new FinalizePostPublishing)($imagePost->fresh());
        $freshPost = $imagePost->fresh();
        if ($recover) {
            pipelineCheck($response->isOk() && $freshPost->isPublished()
                && ! $freshPost->hasPublishState($account) && $transport->uploads === 1 && $transport->creates === 1
                && Notification::getFacadeRoot()->sentNotifications() === [],
                'X image storage recovery publishes once and sends no failure email');
        } else {
            pipelineCheck($response->hasError() && $freshPost->isFailed()
                && ! $freshPost->hasPublishState($account) && $transport->uploads === 0 && $transport->creates === 0,
                'exhausted X image storage retries settle as a terminal failure');
            Notification::assertSentOnDemandTimes(PostPublishingFailedNotification::class, 1);
            echo "PASS exhausted X image storage retries queue one terminal email
";
        }
    }
} finally { DB::rollBack(); }
