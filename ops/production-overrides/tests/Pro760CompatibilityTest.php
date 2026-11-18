<?php
// Exact Pro 7.6 candidate only, on an isolated restored database.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $e): never { fwrite(STDERR, 'FAIL Pro 7.6 compatibility: '.$e::class.': '.$e->getMessage()."\n"); exit(1); });
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Inovector\Mixpost\Facades\WorkspaceManager;
use Inovector\Mixpost\Models\Workspace;
use Inovector\Mixpost\Models\Media;
use Inovector\Mixpost\Http\Base\Requests\Workspace\Post\PostFormRequest;
use Inovector\Mixpost\Rules\VideoThumbMomentRule;
if (!app()->environment('testing') || !str_starts_with(config('database.connections.mysql.host'), 'mixpost-local-rehearsal-')) throw new RuntimeException('Isolated restored MySQL required');
Http::preventStrayRequests();
function frameCheck(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
$columns=collect(DB::select('SHOW COLUMNS FROM mixpost_media'))->keyBy('Field');
frameCheck($columns['parent_id']->Type==='bigint unsigned' && $columns['parent_id']->Null==='YES', 'nullable unsigned parent preserves original media');
frameCheck($columns['is_visible']->Default==='1' && $columns['is_visible']->Null==='NO', 'library visibility defaults to visible');
frameCheck(!DB::table('mixpost_media')->whereNotNull('parent_id')->orWhere('is_visible',false)->exists(), 'all restored media remains visible and unparented');
$pair=['media_id'=>101,'timestamp_ms'=>0];
frameCheck(PostFormRequest::videoThumbsOfMedia([$pair+['thumb_id'=>null,'extra'=>'drop']], [101])===[$pair], 'editor preserves zero timestamp and drops null cover and unknown keys');
frameCheck(PostFormRequest::videoThumbsOfMedia([['media_id'=>102,'timestamp_ms'=>1000]], [101])===[], 'stale timestamp cover is discarded');
DB::beginTransaction();
try {
 $row=(array)DB::table('mixpost_media')->first(); unset($row['id']);
 WorkspaceManager::setCurrent(Workspace::findOrFail($row['workspace_id']));
 $row['uuid']=(string)Illuminate\Support\Str::uuid(); $row['mime_type']='video/mp4'; $row['data']=json_encode(['duration_ms'=>10000]); $row['parent_id']=null; $row['is_visible']=true;
 $videoId=DB::table('mixpost_media')->insertGetId($row);
 $data=['versions'=>[['content'=>[['media'=>[$videoId], 'video_thumbs'=>[['media_id'=>$videoId,'timestamp_ms'=>9999]]]]]]];
 $rules=['versions.*.content.*.video_thumbs.*.timestamp_ms'=>['integer','min:0',new VideoThumbMomentRule]];
 frameCheck(Validator::make($data,$rules)->passes(), 'cover frame immediately before video end accepted');
 $data['versions'][0]['content'][0]['video_thumbs'][0]['timestamp_ms']=10000;
 frameCheck(Validator::make($data,$rules)->fails(), 'cover frame at video end rejected');
 $data['versions'][0]['content'][0]['video_thumbs'][0]['timestamp_ms']=-1;
 frameCheck(Validator::make($data,$rules)->fails(), 'negative cover timestamp rejected');
 $row['uuid']=(string)Illuminate\Support\Str::uuid();$row['mime_type']='image/jpeg';$row['parent_id']=$videoId;$row['is_visible']=false;$row['created_at']=now()->subHours(2);
 $frameId=DB::table('mixpost_media')->insertGetId($row);
 frameCheck(!Media::query()->visible()->whereKey($frameId)->exists() && Media::query()->visible()->whereKey($videoId)->exists(), 'hidden frame stays out of library while parent stays visible');
 $pruner=new Inovector\Mixpost\Actions\Media\PruneVideoFrames;
 frameCheck($pruner->countStale((int)$row['workspace_id'])===1, 'only the unused old hidden frame is eligible for cleanup');
 DB::table('mixpost_media')->where('id',$videoId)->delete();
 frameCheck(DB::table('mixpost_media')->where('id',$frameId)->value('parent_id')===null, 'parent deletion retains derived row with null foreign key');
} finally { DB::rollBack(); }
echo "RESULT Pro 7.6 compatibility passed\n";
