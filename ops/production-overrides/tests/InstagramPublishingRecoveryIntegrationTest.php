<?php

// Restored MySQL, private storage volume, internal Docker network. Never run on production.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (app()->environment() !== 'testing' || ! str_starts_with(config('database.connections.mysql.host'), 'mixpost-local-rehearsal-')) {
    throw new RuntimeException('Refusing recovery test outside isolated rehearsal');
}
set_exception_handler(function (Throwable $error) { fwrite(STDERR, (string) $error); exit(1); });

use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\{Auth, DB, Event, Http, Log, Notification, Storage, Validator};
use Illuminate\Support\Str;
use Inovector\Mixpost\Actions\Post\{AccountPublishPost, FinalizePostPublishing};
use Inovector\Mixpost\Enums\SocialProviderResponseStatus as Status;
use Inovector\Mixpost\Facades\WorkspaceManager;
use Inovector\Mixpost\Models\{Account, Media, Post, PostVersion, Workspace};
use Inovector\Mixpost\Notifications\PostPublishingRecoveryNotification;
use Inovector\Mixpost\Support\{InstagramPublishingRecovery as Recovery, PublishingMediaFallback, SocialProviderResponse};

Event::fake(); Notification::fake(); Log::swap(new Psr\Log\NullLogger);
Http::preventStrayRequests(); config(['cache.default' => 'array']);
$workspace = Workspace::withoutGlobalScopes()->findOrFail(1);
WorkspaceManager::setCurrent($workspace);
$user = config('auth.providers.users.model')::firstOrFail(); Auth::login($user);
$account = Account::where('provider', 'instagram')->firstOrFail();
function contract(bool $ok, string $label): void { if (! $ok) { throw new RuntimeException($label); } echo 'PASS '.$label.PHP_EOL; }
function stateNow(Post $post, Account $account, callable $edit): void {
    $v = $post->versions()->where('account_id', $account->id)->firstOrFail();
    $o = $v->options; $edit($o[Recovery::KEY]); $v->options = $o; $v->save(); $post->unsetRelation('versions');
}
function fixture(Account $account, string $path): array {
    $post = Post::withoutGlobalScopes()->firstOrFail()->replicate();
    $post->forceFill(['uuid' => (string) Str::uuid(), 'workspace_id' => $account->workspace_id, 'user_id' => Auth::id(),
        'status' => 1, 'schedule_status' => 1, 'publishing_runs' => 1, 'published_at' => null, 'deleted_at' => null])->save();
    $media = new Media;
    $media->forceFill(['uuid' => (string) Str::uuid(), 'workspace_id' => $account->workspace_id, 'name' => 'Recovery fixture',
        'mime_type' => 'image/jpeg', 'disk' => 's3', 'path' => $path, 'size' => 22, 'size_total' => 22,
        'conversions' => [], 'processing' => false, 'data' => ['peachy_storage' => ['state' => 'ready', 'local_files' => [], 'replaced_files' => [], 'retained_local_files' => [], 'target_disk' => 's3']]])->save();
    $v = new PostVersion(['account_id' => 0, 'is_original' => true,
        'content' => [['body' => '<div>Keep @private_person and this caption</div>', 'media' => [$media->id], 'url' => null, 'video_thumbs' => []]],
        'options' => ['instagram' => ['type' => 'post', 'collaborators' => ['private_person', 'other.person', 'third_person']]]]);
    $v->post_id = $post->id; $v->save();
    $post->accounts()->attach($account->id, ['status' => 1]);
    return [$post, $media, $v];
}
function responseError(array $error, string $url = ''): SocialProviderResponse {
    return new SocialProviderResponse(Status::ERROR, ['error' => $error, 'phase' => 'instagram_container_creation', 'rejected_media_url' => $url]);
}
class RecoveryCloud extends S3Client {
    public bool $stalled = false;
    public bool $badSize = false;
    public bool $badHash = false;
    public int $reads = 0;
    public array $objects = [];
    public function getObject(array $request): Result {
        $this->reads++;
        if ($this->stalled) { throw new RuntimeException('Injected storage outage'); }
        $bytes = $this->objects[$request['Key']];
        return new Result(['ContentLength' => strlen($bytes) + (int) $this->badSize,
            'ETag' => '"'.($this->badHash ? str_repeat('0', 32) : md5($bytes)).'"', 'Body' => Utils::streamFor($bytes)]);
    }
    public function execute(\Aws\CommandInterface $command) { throw new RuntimeException('Unexpected external storage command'); }
}
$cloud = new RecoveryCloud(['service' => 's3', 'version' => 'latest', 'region' => 'hel1', 'credentials' => ['key' => 'fixture', 'secret' => 'fixture'], 'endpoint' => 'http://unreachable.invalid']);
$adapter = new League\Flysystem\AwsS3V3\AwsS3V3Adapter($cloud, 'fixture');
Storage::set('s3', new AwsS3V3Adapter(new League\Flysystem\Filesystem($adapter), $adapter, ['bucket' => 'fixture', 'url' => 'https://fixture-storage.invalid'], $cloud));
config(['filesystems.disks.s3.driver' => 's3']);
$recovery = new Recovery;
$ownedFiles = [];
DB::beginTransaction();
try {
    [$post, $media, $original] = fixture($account, 'fixture/'.Str::uuid().'.jpg');
    $original->refresh(); $before = $original->getAttributes();
    $private = ['code' => 110, 'error_subcode' => 2207018, 'error_user_msg' => 'The following user(s) cannot be accessed: private_person'];
    Http::swap(new Illuminate\Http\Client\Factory); Http::preventStrayRequests(); Http::fake(['*' => Http::response(['error' => $private], 400)]);
    $out = (new AccountPublishPost)($account, $post);
    contract($out->isPending() && $post->accounts()->find($account->id)->pivot->errors === null, 'actual publisher keeps named collaborator error pending without recording failure');
    $version = $post->versions()->where('account_id', $account->id)->firstOrFail();
    contract($version->options['instagram']['collaborators'] === ['other.person', 'third_person'], 'only explicitly rejected collaborator removed');
    contract($original->fresh()->getAttributes() === $before && $version->content === $original->content, 'original version, media and caption mentions unchanged');
    $edit = Inovector\Mixpost\Http\Base\Requests\Workspace\Post\UpdatePostVersions::create('/', 'PUT');
    $edit->setRouteResolver(fn () => new class($post->uuid) {
        public function __construct(private string $uuid) {}
        public function parameter($key, $default = null) { return $key === 'post' ? $this->uuid : $default; }
    });
    $validator = Validator::make([], []); $edit->withValidator($validator);
    contract($validator->fails() && $validator->errors()->has('publishing'), 'editor autosave cannot overwrite recovery state while publishing');
    $second = ['code' => 210, 'error_subcode' => 2207066, 'error_user_msg' => 'The user other.person cannot be tagged on this media.'];
    contract($recovery->recover($post, $account, responseError($second))->isPending(), 'second named rejection can recover in the same run');
    contract($post->versions()->where('account_id', $account->id)->first()->options['instagram']['collaborators'] === ['third_person'], 'remaining invitation retained after sequential recovery');
    contract($recovery->recover($post, $account, responseError($private)) === null, 'already removed or unknown collaborator cannot repeat changes');
    $post->accounts()->updateExistingPivot($account->id, ['provider_post_id' => 'already-live']);
    contract($recovery->recover($post, $account, responseError($second)) === null, 'live provider ID blocks recovery and duplicate publication');
    $post->accounts()->updateExistingPivot($account->id, ['provider_post_id' => null, 'data' => json_encode(['post_published' => true])]);
    contract($recovery->recover($post, $account, responseError($second)) === null, 'live marker without ID also blocks recovery');
    $post->accounts()->updateExistingPivot($account->id, ['data' => null]);
    $foreign = $account->replicate(); $foreign->id = $account->id; $foreign->workspace_id = $account->workspace_id + 100000;
    contract($recovery->recover($post, $foreign, responseError($second)) === null, 'foreign workspace cannot trigger recovery');
    $unsafe = responseError($second)->useContext(['error' => $second, 'phase' => 'instagram_media_publish']);
    contract($recovery->recover($post, $account, $unsafe) === null, 'publish endpoint errors cannot authorize a retry');
    contract($recovery->recover($post, $account, responseError($second)->useContext(['error' => $second])) === null, 'unproven endpoint errors cannot authorize a retry');

    [$storagePost, $storageMedia, $storageOriginal] = fixture($account, 'fixture/'.Str::uuid().'.jpg');
    $bytes = 'verified fixture image'; contract(strlen($bytes) === 22, 'bounded fixture size recorded');
    $cloud->objects[$storageMedia->path] = $bytes;
    $error = ['code' => 9004, 'error_subcode' => 2207052];
    contract($recovery->recover($storagePost, $account, responseError($error, 'https://untrusted.invalid/arbitrary')) === null, 'arbitrary provider error URL cannot be fetched');
    $sourcePath = $storageMedia->path;
    contract($recovery->recover($storagePost, $account, responseError($error, $storageMedia->getUrl()))->isPending(), 'known selected S3 asset enters durable recovery');
    $cloud->stalled = true;
    contract($recovery->prepare($storagePost, $account)->isPending() && $storageMedia->fresh()->disk === 's3', 'storage outage waits without promoting or deleting original');
    $state = $recovery->state($storagePost, $account);
    contract((new Recovery)->state($storagePost->fresh(), $account)['expires_at'] === $state['expires_at'], 'worker replacement preserves recovery deadline');
    $readCount = $cloud->reads;
    contract((new Recovery)->prepare($storagePost, $account)->isPending() && $cloud->reads === $readCount, 'backoff survives worker replacement without extra source reads');
    stateNow($storagePost, $account, function (&$s) { $s['next_attempt_at'] = 0; });
    $cloud->stalled = false; $cloud->badHash = true;
    Http::swap(new Illuminate\Http\Client\Factory); Http::preventStrayRequests(); Http::fake(fn () => Http::response($bytes));
    contract($recovery->prepare($storagePost, $account)->isPending() && $storageMedia->fresh()->disk === 's3', 'bad source checksum cannot promote media');
    stateNow($storagePost, $account, function (&$s) { $s['next_attempt_at'] = 0; });
    $cloud->badHash = false; $cloud->badSize = true;
    contract($recovery->prepare($storagePost, $account)->isPending() && $storageMedia->fresh()->disk === 's3', 'bad source size cannot promote media');
    stateNow($storagePost, $account, function (&$s) { $s['next_attempt_at'] = 0; });
    $cloud->badSize = false;
    Http::swap(new Illuminate\Http\Client\Factory); Http::preventStrayRequests(); Http::fake(['*' => Http::response(str_repeat('x', strlen($bytes)))]);
    contract($recovery->prepare($storagePost, $account)->isPending() && $storageMedia->fresh()->disk === 's3', 'bad public readback cannot promote media');
    stateNow($storagePost, $account, function (&$s) { $s['next_attempt_at'] = 0; });
    $cloud->stalled = true; $readsBeforeReceiptResume = $cloud->reads;
    Http::swap(new Illuminate\Http\Client\Factory); Http::preventStrayRequests(); Http::fake(fn () => Http::response($bytes));
    contract($recovery->prepare($storagePost, $account) === null, 'storage recovery resumes after verified source and public readback');
    contract($cloud->reads === $readsBeforeReceiptResume, 'verified retained receipt recovers while source remains unavailable');
    $cloud->stalled = false;
    $storageMedia->refresh(); $ownedFiles[] = $storageMedia->path;
    contract($storageMedia->disk === 'public' && Storage::disk('public')->get($storageMedia->path) === $bytes && $cloud->objects[$sourcePath] === $bytes, 'identical durable public copy promoted with source object preserved');
    contract($storageMedia->data['peachy_storage']['local_files'] === [] && $storageMedia->data['peachy_storage']['state'] === 'ready', 'staging cleanup cannot delete promoted fallback');
    contract((new PublishingMediaFallback)->recover($storageMedia)['sha256'] === hash('sha256', $bytes), 'restart re-verifies previously promoted copy');
    $saved = Storage::disk('public')->get($storageMedia->path); Storage::disk('public')->put($storageMedia->path, 'tampered');
    $blocked = false; try { (new PublishingMediaFallback)->recover($storageMedia); } catch (RuntimeException $e) { $blocked = true; }
    contract($blocked, 'tampered retained fallback cannot pass restart recovery'); Storage::disk('public')->put($storageMedia->path, $saved);
    stateNow($storagePost, $account, function (&$s) { $s['pending'] = ['media_id' => 99999999, 'conversion' => null]; $s['expires_at'] = time() - 1; });
    contract((new Recovery)->prepare($storagePost, $account)->hasError(), 'exhausted recovery reports a bounded final failure');

    [$carousel, $carouselMedia, $carouselVersion] = fixture($account, 'fixture/'.Str::uuid().'.jpg');
    $otherMedia = $carouselMedia->replicate(); $otherMedia->uuid = (string) Str::uuid(); $otherMedia->save();
    $items = $carouselVersion->content; $items[0]['media'][] = $otherMedia->id; $carouselVersion->content = $items; $carouselVersion->save();
    $childCreates = 0; $parentCreates = 0; $publishes = 0;
    Http::swap(new Illuminate\Http\Client\Factory); Http::preventStrayRequests(); Http::fake(function ($request) use (&$childCreates, &$parentCreates, &$publishes, $private) {
        if (str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/media')) {
            if ($request['is_carousel_item'] ?? false) { return Http::response(['id' => 'child-'.++$childCreates]); }
            $parentCreates++;
            return $parentCreates === 1 ? Http::response(['error' => $private], 400) : Http::response(['id' => 'parent-ready']);
        }
        if (str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/media_publish')) {
            $publishes++; return Http::response(['id' => 'fixture-carousel-published']);
        }
        return Http::response(['status_code' => 'FINISHED', 'shortcode' => 'fixture-carousel']);
    });
    contract((new AccountPublishPost)($account, $carousel)->isPending() && $childCreates === 2 && $parentCreates === 1 && $publishes === 0, 'carousel invite rejection keeps accepted children without publishing');
    contract((new AccountPublishPost)($account, $carousel->fresh())->isOk() && $childCreates === 2 && $parentCreates === 2 && $publishes === 1, 'carousel recovery reuses every child and publishes exactly once');

    [$conversionPost, $conversionMedia] = fixture($account, 'fixture/'.Str::uuid().'.jpg');
    $conversionSource = 'fixture/'.Str::uuid().'-social.mp4'; $thumbSource = 'fixture/thumb.jpg';
    $conversionMedia->conversions = [
        ['name' => 'social_video', 'disk' => 's3', 'path' => $conversionSource, 'size' => strlen($bytes)],
        ['name' => 'thumb', 'disk' => 's3', 'path' => $thumbSource, 'size' => strlen($bytes)],
    ]; $conversionMedia->save(); $cloud->objects[$conversionSource] = $bytes;
    Http::swap(new Illuminate\Http\Client\Factory); Http::preventStrayRequests(); Http::fake(fn () => Http::response($bytes));
    (new PublishingMediaFallback)->recover($conversionMedia, 'social_video'); $conversionMedia->refresh();
    $ownedFiles[] = $conversionMedia->getConversion('social_video')['path'];
    contract($conversionMedia->disk === 's3' && $conversionMedia->getConversion('social_video')['disk'] === 'public'
        && $conversionMedia->getConversion('thumb')['path'] === $thumbSource, 'only rejected optimized conversion moves; original and thumbnail are preserved');

    // Finalization and notification operate on real account/version rows; delivery is faked.
    $post->accounts()->updateExistingPivot($account->id, ['status' => 2, 'provider_post_id' => 'fixture-live-post', 'data' => json_encode(['post_published' => true]), 'errors' => null]);
    (new FinalizePostPublishing)($post);
    $sent = collect(Notification::getFacadeRoot()->sentNotifications())->flatten(3)->filter(fn ($row) => is_array($row) && isset($row['notification']));
    contract($post->fresh()->isPublished() && $sent->isEmpty(), 'successful automatic recovery settles published without any email');
    $oldSuccess = new PostPublishingRecoveryNotification($post, [], false);
    contract(! $oldSuccess->shouldSend(new stdClass, 'mail'), 'previously queued recovery success mail is suppressed');

    $post->accounts()->updateExistingPivot($account->id, ['status' => 3, 'provider_post_id' => null, 'data' => null, 'errors' => json_encode(['Recovery exhausted'])]);
    $post->setFailed();
    (new FinalizePostPublishing)($post->fresh());
    $sent = collect(Notification::getFacadeRoot()->sentNotifications())->flatten(3)->filter(fn ($row) => is_array($row) && isset($row['notification']));
    contract($sent->count() === 1, 'unrecovered terminal failure sends one recovery explanation');
    $notice = $sent->first(); $notification = $notice['notification'];
    contract($notification instanceof PostPublishingRecoveryNotification && $notice['notifiable']->routes['mail'] === 'socials@ducatix.com'
        && $notification->shouldSend($notice['notifiable'], 'mail'), 'terminal alert uses existing recipient and verifies fresh failed state');
    $mail = $notification->toMail($notice['notifiable']); $body = implode(' ', $mail->introLines);
    contract(str_contains($body, '@private_person') && str_contains($body, '@other.person') && str_contains($body, 'still failed') && str_contains($mail->actionUrl, $post->uuid), 'terminal email preserves recovery details and exact post link');
    $html = (string) app(Illuminate\Mail\Markdown::class)->render($mail->markdown, $mail->data());
    contract(str_contains($html, '@private_person') && str_contains($html, $post->uuid), 'terminal recovery email renders correctly');
    (new FinalizePostPublishing)($post->fresh());
    $sent = collect(Notification::getFacadeRoot()->sentNotifications())->flatten(3)->filter(fn ($row) => is_array($row) && isset($row['notification']));
    contract($sent->count() === 1, 'repeated failure finalization does not duplicate recovery alert');
    $normalFailure = new Inovector\Mixpost\Notifications\PostPublishingFailedNotification($post, true);
    contract($normalFailure->shouldSend(new stdClass, 'mail'), 'ordinary terminal failure is still eligible for email');
    $post->forceFill(['publishing_runs' => 1])->save();
    contract(! $notification->shouldSend(new stdClass, 'mail') && ! $normalFailure->shouldSend(new stdClass, 'mail'), 'queued alerts are suppressed while a retry is active');
    $post->forceFill(['publishing_runs' => 0])->save();
    $post->accounts()->updateExistingPivot($account->id, ['status' => 2, 'provider_post_id' => 'fixture-recovered', 'errors' => null]);
    $post->setPublished();
    contract(! $notification->shouldSend(new stdClass, 'mail') && ! $normalFailure->shouldSend(new stdClass, 'mail'), 'stale terminal alerts including failed batches are suppressed after recovery');
} finally {
    DB::rollBack();
    foreach ($ownedFiles as $path) { Storage::disk('public')->delete($path); }
}
