<?php
// Run against the exact licensed candidate on a restored, internal-only database.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): never {
    fwrite(STDERR, 'FAIL Pro 7.5.3 compatibility: '.$error::class."\n");
    exit(1);
});
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inovector\Mixpost\Facades\WorkspaceManager;
use Inovector\Mixpost\Http\Base\Requests\Workspace\Post\PostFormRequest;
use Inovector\Mixpost\Http\Api\Requests\Workspace\Post\PostFormRequest as ApiPostFormRequest;
use Inovector\Mixpost\Models\Workspace;

if (! app()->environment('testing') || ! str_starts_with(config('database.connections.mysql.host'), 'mixpost-local-rehearsal-')) {
    throw new RuntimeException('Restored isolated database required');
}
Http::preventStrayRequests();
function coverCheck(bool $ok, string $label): void {
    if (! $ok) throw new RuntimeException($label);
    echo "PASS $label\n";
}
$builder = new class {
    use Inovector\Mixpost\Mcp\Concerns\BuildsPostVersions;
    public function content(array $items, array $stored = []): array { return $this->buildContentItems($items, $stored); }
};
$stored = [['body'=>'old', 'media'=>[101], 'video_thumbs'=>[['media_id'=>101,'thumb_id'=>102]]]];
coverCheck($builder->content([['body'=>'edited']], $stored)[0]['video_thumbs']===$stored[0]['video_thumbs'], 'MCP text edits preserve stored video covers');
coverCheck($builder->content([['body'=>'edited','video_thumbs'=>[]]], $stored)[0]['video_thumbs']===[], 'MCP explicit empty covers remove the cover');
coverCheck($builder->content([['video_thumbs'=>[['media_id'=>101,'thumb_id'=>103]]]], $stored)[0]['video_thumbs'][0]['thumb_id']===103, 'MCP cover changes accept a retained video');
try {
    $builder->content([['media'=>[101],'video_thumbs'=>[['media_id'=>104,'thumb_id'=>102]]]]);
    throw new RuntimeException('Unrelated MCP cover accepted');
} catch (ValidationException $error) {
    coverCheck($error->status===422, 'MCP rejects covers for unrelated media with validation status 422');
}
coverCheck(PostFormRequest::videoThumbsOfMedia([['media_id'=>101,'thumb_id'=>102],['media_id'=>104,'thumb_id'=>105]], [101])===[['media_id'=>101,'thumb_id'=>102]], 'editor drops stale covers without losing the retained cover');
WorkspaceManager::setCurrent(Workspace::firstOrFail());
$request=new class extends ApiPostFormRequest {};
$request->initialize([], ['versions'=>[['content'=>[['media'=>[101]]]]]]);
$request->setMethod('POST');
$rules=$request->rules();
$membership=array_values(array_filter($rules['versions.*.content.*.video_thumbs.*.media_id'],fn($rule)=>$rule instanceof Closure));
coverCheck(count($membership)===1, 'REST API retains content-item cover validation');
$errors=[];
$membership[0]('versions.0.content.0.video_thumbs.0.media_id',104,function($message)use(&$errors){$errors[]=$message;});
coverCheck(count($errors)===1, 'REST rejects a cover video outside its content item');
$membership[0]('versions.0.content.0.video_thumbs.0.media_id',101,function($message){throw new RuntimeException($message);});
coverCheck(true,'REST accepts a cover video inside its content item');
DB::beginTransaction();
try {
    $row=(array)DB::table('mixpost_media')->first();
    unset($row['id']);
    WorkspaceManager::setCurrent(Workspace::findOrFail($row['workspace_id']));
    foreach(['video/webm','video/quicktime','video/mp4'] as $mime){
        $row['uuid']=(string)Illuminate\Support\Str::uuid();$row['mime_type']=$mime;
        $id=DB::table('mixpost_media')->insertGetId($row);
        coverCheck(Validator::make(['id'=>$id],['id'=>[PostFormRequest::videoThumbVideoRule()]])->passes(),$mime.' accepts a custom video cover');
    }
    $row['uuid']=(string)Illuminate\Support\Str::uuid();$row['mime_type']='image/gif';
    $id=DB::table('mixpost_media')->insertGetId($row);
    coverCheck(Validator::make(['id'=>$id],['id'=>[PostFormRequest::videoThumbImageRule()]])->fails(),'animated GIF remains invalid as a video cover');
    $row['uuid']=(string)Illuminate\Support\Str::uuid();$row['mime_type']='image/jpeg';
    $id=DB::table('mixpost_media')->insertGetId($row);
    coverCheck(Validator::make(['id'=>$id],['id'=>[PostFormRequest::videoThumbImageRule()]])->passes(),'JPEG remains valid as a video cover');
} finally { DB::rollBack(); }
echo "RESULT Pro 7.5.3 compatibility passed\n";
