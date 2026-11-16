<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, 'FAIL isolated test exception ('.get_class($e).")\n");
    exit(1);
});

use Illuminate\Support\Facades\{DB, Bus, Event, Http, Cache};
use Inovector\Mixpost\Support\{TtbInstagramCommentSync as Sync, InstagramCommentAvailability as State};
use Inovector\Mixpost\Models\Account;
use Inovector\Mixpost\Models\Inbox\{Conversation, Message};
use Inovector\Mixpost\Facades\WorkspaceManager;
use Inovector\Mixpost\Jobs\Inbox\ProcessInboundWebhookJob;
use Inovector\Mixpost\Actions\Inbox\{SendReply, LikeMessage, IngestInboundMessage};
use Inovector\Mixpost\Exceptions\{InboxReplyException, InboxLikeException};
use Inovector\Mixpost\Events\Inbox\InboxMessageUpdated;

$checks = 0;
function check($ok, $name) { global $checks; if (! $ok) throw new RuntimeException($name); $checks++; echo "PASS $name\n"; }
check(app()->environment()==='testing' && str_starts_with(config('database.connections.mysql.host'), 'mixpost-local-rehearsal-'), 'local isolated database guard');
Bus::fake(); Event::fake(); Http::preventStrayRequests();
$account=Account::withoutWorkspace()->findOrFail(109);
WorkspaceManager::setCurrent($account->workspace);
$sync=new Sync;
check(Sync::applies($account) && !Sync::applies(Account::withoutWorkspace()->findOrFail(85)), 'pilot scope is only account 109 in workspace 12 via Facebook');
$thread=Conversation::withoutWorkspace()->where('account_id',109)->where('provider_post_id','18034794539882533')->firstOrFail();
$baselineConversations=Conversation::withoutWorkspace()->where('account_id',109)->count();
$baselineMessages=Message::withoutWorkspace()->where('account_id',109)->count();
check($baselineConversations>=10 && $baselineMessages>=2,'restored TTB history supports bounded rotation and preservation tests');
$now=time();
$snapshot=fn($ids,$complete=true)=>fn()=>['complete'=>$complete,'ids'=>$ids,'requests'=>1];
DB::beginTransaction();
try {
    $existing=Message::withoutWorkspace()->where('conversation_id',$thread->id)->get();
    check($existing->count()===2,'known test thread retains its two older comments');
    $ids=$existing->pluck('provider_message_id')->map('strval')->all();
    $row=$existing->first()->getRawOriginal();
    unset($row['id']); $row['uuid']=(string) Illuminate\Support\Str::uuid();
    $row['provider_message_id']='17904916827374716';
    $row['body']='Isolated deleted-test fixture';
    $row['data']=json_encode(['liked'=>true,'fixture_preserve'=>['original'=>1]]);
    $row['created_at']=gmdate('Y-m-d H:i:s',$now-7200); $row['updated_at']=$row['created_at'];
    $fixtureId=DB::table('mixpost_inbox_messages')->insertGetId($row);
    $protected=DB::table('mixpost_inbox_messages')->where('id','!=',$fixtureId)->orderBy('id')->get()->toJson();
    $conversations=DB::table('mixpost_inbox_conversations')->orderBy('id')->get()->toJson();
    $post=DB::table('mixpost_posts')->where('id',2314)->first();
    $links=DB::table('mixpost_post_accounts')->where('post_id',2314)->orderBy('id')->get()->toJson();
    $r=$sync->reconcile($thread,$snapshot($ids),$now);
    $m=Message::withoutWorkspace()->findOrFail($fixtureId);
    check($r['complete'] && $m->data[State::KEY]['state']==='pending','first complete scan flags only the known deleted fixture as pending');
    check(Message::withoutWorkspace()->where('account_id',109)->count()===218,'pending absence retains every history row');
    $sync->reconcile($thread,$snapshot([] ,false),$now+700);
    check(!isset(Message::withoutWorkspace()->findOrFail($fixtureId)->data[State::KEY]),'failed/inaccessible fetch resets pending evidence');
    $sync->reconcile($thread,$snapshot($ids),$now+800);
    $sync->reconcile($thread,$snapshot($ids),$now+1400);
    $m=Message::withoutWorkspace()->findOrFail($fixtureId);
    check($m->data[State::KEY]['state']==='unavailable' && $m->body===$row['body'] && $m->data['liked']===true,'two complete scans retain body, liked flag and history while marking unavailable');
    check($m->data['fixture_preserve']===['original'=>1],'unrelated nested message metadata survives');
    foreach ([SendReply::class=>InboxReplyException::class, LikeMessage::class=>InboxLikeException::class] as $action=>$expected) {
        try { $action===SendReply::class ? app($action)($thread,$m,'no actual reply',1) : app($action)($thread,$m); throw new RuntimeException('guard did not reject'); }
        catch (Throwable $e) {check($e instanceof $expected,'unavailable comment cannot be replied to or liked through backend');}
    }
    check(Http::recorded()->isEmpty(), 'blocked actions send no provider request');
    $sync->reconcile($thread,$snapshot(array_merge($ids,[$row['provider_message_id']])),$now+2000);
    check(Message::withoutWorkspace()->findOrFail($fixtureId)->data===['liked'=>true,'fixture_preserve'=>['original'=>1]],'complete reappearance removes only availability metadata');
    $sync->reconcile($thread,function()use($fixtureId,$ids){ DB::table('mixpost_inbox_messages')->where('id',$fixtureId)->update(['body'=>'concurrent edit']);return ['complete'=>true,'ids'=>$ids,'requests'=>1];},$now+2100);
    check(!isset(Message::withoutWorkspace()->findOrFail($fixtureId)->data[State::KEY]),'concurrently changed row is skipped rather than overwritten');
    DB::table('mixpost_inbox_messages')->where('id',$fixtureId)->update(['body'=>$row['body']]);
    $fresh=$row; $fresh['uuid']=(string)Illuminate\Support\Str::uuid();$fresh['provider_message_id']='999000111222333';$fresh['created_at']=gmdate('Y-m-d H:i:s',$now);
    $freshId=DB::table('mixpost_inbox_messages')->insertGetId($fresh);
    $sync->reconcile($thread,$snapshot($ids),$now);
    check(!isset(Message::withoutWorkspace()->findOrFail($freshId)->data[State::KEY]),'new arrivals within grace are not marked missing');
    DB::table('mixpost_inbox_messages')->where('id',$freshId)->delete();
    $foreign=Conversation::withoutWorkspace()->where('account_id',85)->firstOrFail();
    $r=$sync->reconcile($foreign,function(){throw new RuntimeException('must not fetch');},$now);
    check(($r['skipped']??false)===true,'other accounts are not fetched or modified');
    $raw=['object'=>'instagram','entry'=>[['id'=>(string)$account->provider_id,'changes'=>[['field'=>'comments','value'=>['verb'=>'remove','id'=>$row['provider_message_id'],'media'=>['id'=>$thread->provider_post_id]]]]]]];
    (new ProcessInboundWebhookJob('instagram',json_encode($raw)))->handle();
    $m=Message::withoutWorkspace()->findOrFail($fixtureId);
    check($m->data[State::KEY]['source']==='webhook' && $m->body===$row['body'],'actual parsed deletion webhook marks unavailable without deleting content');
    $before=$m->getRawOriginal();(new ProcessInboundWebhookJob('instagram',json_encode($raw)))->handle();
    check(Message::withoutWorkspace()->findOrFail($fixtureId)->getRawOriginal()===$before,'duplicate deletion webhook is idempotent');
    app(IngestInboundMessage::class)(['provider'=>'instagram','account_id'=>109,'type'=>$thread->type,'backfill'=>true,'conversation'=>['provider_conversation_id'=>$thread->provider_conversation_id,'provider_post_id'=>$thread->provider_post_id], 'message'=>['provider_message_id'=>$row['provider_message_id'],'direction'=>$m->direction,'body'=>$row['body'],'platform_created_at'=>$m->platform_created_at]]);
    $m=Message::withoutWorkspace()->findOrFail($fixtureId);
    check(!isset($m->data[State::KEY]) && $m->data['liked']===true && $m->data['fixture_preserve']===['original'=>1],'positive ingestion restores availability without erasing unrelated metadata');
    // Upstream ingestion updates conversation activity. Restore it solely for this local preservation comparison.
    foreach(json_decode($conversations,true) as $c) DB::table('mixpost_inbox_conversations')->where('id',$c['id'])->update($c);
    check(DB::table('mixpost_inbox_messages')->where('id','!=',$fixtureId)->orderBy('id')->get()->toJson()===$protected,'all earlier messages across accounts preserved byte for byte');
    check(DB::table('mixpost_inbox_conversations')->orderBy('id')->get()->toJson()===$conversations,'reconciliation preserves conversation rows/status/unread/activity');
    check(DB::table('mixpost_posts')->where('id',2314)->first()==$post && DB::table('mixpost_post_accounts')->where('post_id',2314)->orderBy('id')->get()->toJson()===$links,'post 2314 and all account schedules unchanged');
} finally { DB::rollBack(); }
check(Conversation::withoutWorkspace()->where('account_id',109)->count()===$baselineConversations && Message::withoutWorkspace()->where('account_id',109)->count()===$baselineMessages,'fixture transaction rolled back to exact TTB history counts');

// Verify the real facade-backed HTTP path uses fake credentials and follows every reply page.
$provider=(new ReflectionClass(Inovector\Mixpost\SocialProviders\Meta\InstagramProvider::class))->newInstanceWithoutConstructor();
(new ReflectionProperty($provider,'apiVersion'))->setValue($provider,'v27.0');
$provider->useAccessToken(['access_token'=>'isolated-fixture-token']);
$calls=[];
Http::fake(function($request)use(&$calls){
    $calls[]=$request->url();
    if(str_contains($request->url(),'/100/comments'))return Http::response(['data'=>[['id'=>'1']]]);
    if(str_contains($request->url(),'/1/replies') && !isset($request['after']))return Http::response(['data'=>[['id'=>'2']],'paging'=>['next'=>'https://ignored.invalid/','cursors'=>['after'=>'second']]]);
    return Http::response(['data'=>[['id'=>'3']]]);
});
$r=$provider->fetchCommentSnapshot('100');
check($r['complete'] && count($r['ids'])===3 && count($calls)===3,'actual Instagram provider fetches every reply page through Laravel HTTP');
check(count(array_filter($calls,fn($u)=>str_contains($u,'ignored.invalid')))===0,'provider paging URL never requested');

DB::beginTransaction();
try {
    Cache::flush();
    $requests=0;
    Http::swap(new Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
    Http::fake(function()use(&$requests){$requests++;return Http::response(['data'=>[]]);});
    $sync->run();
    check($requests===10,'scheduled runner checks at most ten conversations per batch');
    $firstCursor=Cache::get('peachy:ttb-comment-sync:cursor');
    $requests=0; $sync->run();
    check($requests===10 && Cache::get('peachy:ttb-comment-sync:cursor')>$firstCursor,'scheduled rotation advances rather than repeatedly scanning the first threads');
    Cache::flush(); $requests=0;
    Http::swap(new Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
    Http::fake(function()use(&$requests){$requests++;return Http::response(['error'=>['code'=>4]],429);});
    $sync->run();
    check($requests===1,'throttling stops the entire current batch after one request');
    DB::table('mixpost_accounts')->where('id',109)->update(['authorized'=>false]);
    $requests=0;$sync->run();
    check($requests===0,'unauthorized account is never fetched');
    check(Message::withoutWorkspace()->where('account_id',109)->count()===$baselineMessages && Conversation::withoutWorkspace()->where('account_id',109)->count()===$baselineConversations,'scheduled polling never deletes messages or conversations');
} finally {DB::rollBack(); Cache::flush();}

// Exercise real commit callbacks separately from the rollback-only history fixtures.
Event::fake();
$row['uuid']=(string)Illuminate\Support\Str::uuid();
$fixtureId=DB::table('mixpost_inbox_messages')->insertGetId($row);
try {
    $sync->preserveDeletion($account,$row['provider_message_id']);
    check(Event::dispatched(InboxMessageUpdated::class)->count()===1,'committed unavailability emits the existing inbox update event');
    $sync->reconcile($thread,$snapshot(array_merge($ids,[$row['provider_message_id']])),$now+3000);
    check(Event::dispatched(InboxMessageUpdated::class)->count()===2,'committed reappearance emits an update without a create or delete');
} finally {DB::table('mixpost_inbox_messages')->where('id',$fixtureId)->delete();}
echo "RESULT $checks checks passed\n";
