<?php
$source = getenv('SYNC_SOURCE_DIR') ?: __DIR__.'/../src';
require $source.'/InstagramCommentSnapshot.php';
require $source.'/InstagramCommentAvailability.php';
use Inovector\Mixpost\Support\{InstagramCommentSnapshot, InstagramCommentAvailability as State};

$checks = 0;
function check($value, $name) { global $checks; if (! $value) throw new RuntimeException($name); $checks++; echo "PASS $name\n"; }
function response($json, $status = 200) { return new class($json, $status) {
    public function __construct(private mixed $json, private int $status) {}
    public function successful() { return $this->status >= 200 && $this->status < 300; }
    public function json() { return $this->json; }
}; }
function runSnapshot(array $responses, int $cap = 100): array {
    $calls = [];
    $s = new InstagramCommentSnapshot(function ($id, $edge, $params) use (&$calls, &$responses) {
        $calls[] = [$id, $edge, $params];
        $value = array_shift($responses);
        if ($value instanceof Throwable) throw $value;
        return $value;
    }, $cap);
    return [$s->fetch('100'), $calls];
}
$page = fn ($ids, $cursor = null) => response(['data' => array_map(fn ($id) => ['id' => $id], $ids)] + ($cursor ? ['paging' => ['next' => 'https://untrusted.invalid/?access_token=never-follow-this', 'cursors' => ['after' => $cursor]]] : []));
[$r,$calls] = runSnapshot([$page(['1'], 'root2'), $page(['2']), $page(['3'], 'reply2'), $page(['4']), $page([])]);
check($r['complete'] && array_map('strval',$r['ids']) === ['1','3','4','2'], 'complete roots and replies across both pagination edges');
check($calls[1] === ['100','comments',['fields'=>'id','limit'=>50,'after'=>'root2']], 'root pagination uses the known endpoint and opaque cursor');
check($calls[3] === ['1','replies',['fields'=>'id','limit'=>50,'after'=>'reply2']], 'reply pagination uses the known endpoint and opaque cursor');
check($r['requests'] === 5, 'request accounting includes replies');
[$r] = runSnapshot([response(['data'=>[], 'paging'=>['cursors'=>['after'=>'terminal']]])]);
check($r['complete'] && $r['requests']===1, 'terminal cursor without next is complete');
foreach ([400,401,403,429,500,503] as $status) {
    [$r] = runSnapshot([response(['error'=>['code'=>100,'error_subcode'=>33]], $status)]);
    check(! $r['complete'] && $r['ids']===[], "HTTP $status cannot become deletion evidence");
}
foreach ([null, [], ['data'=>null], ['data'=>['not'=>'a-list']], ['data'=>[['id'=>'unsafe/id']]], ['data'=>[], 'error'=>['code'=>190]], ['data'=>[], 'paging'=>'bad'], ['data'=>[], 'paging'=>['next'=>'redacted']]] as $bad) {
    [$r] = runSnapshot([response($bad)]);
    check(! $r['complete'] && $r['ids']===[], 'malformed or error response rejected');
}
[$r] = runSnapshot([new RuntimeException('sensitive URL/token must not appear')]);
check(! $r['complete'] && ! str_contains(json_encode($r), 'sensitive'), 'transport exception is sanitized');
[$r] = runSnapshot([$page(['1']), response(['error'=>['code'=>10]],403)]);
check(! $r['complete'] && $r['ids']===[], 'reply denial invalidates the entire snapshot');
[$r] = runSnapshot([$page(['1'], 'same'), $page(['2'], 'same')]);
check(! $r['complete'], 'repeated cursor is incomplete');
[$r] = runSnapshot([$page(['1','1']), $page([]), $page([])]);
check(! $r['complete'], 'duplicate IDs reject an unstable scan');
[$r,$calls] = runSnapshot([$page(['1']), $page([])], 1);
check(! $r['complete'] && count($calls)===1 && $r['requests']===1, 'request cap cannot produce a complete snapshot or overrun budget');
$keep=['liked'=>true,'other'=>['preserve'=>'value']];
check(State::next($keep,true,true,1000)===$keep && State::next([],true,true,1000)===[], 'present comments retain exact unrelated metadata');
$pending=State::next($keep,false,true,1000);
check($pending[State::KEY]['state']==='pending' && $pending['other']===$keep['other'], 'first absence is pending and preserves metadata');
check(State::next($pending,false,true,1000)[State::KEY]['state']==='pending', 'same-time repeat cannot confirm absence');
check(State::next($pending,false,true,1599)[State::KEY]['state']==='pending', 'grace period enforced');
$gone=State::next($pending,false,true,1600);
check($gone[State::KEY]['state']==='unavailable', 'second complete scan after grace marks unavailable');
check(State::next($pending,false,false,1700)===$keep, 'failed scan resets pending evidence');
check(State::next($gone,false,false,1700)===$gone, 'API failure cannot clear an established marker');
check(State::next($gone,true,true,1800)===$keep, 'reappearance restores availability and original metadata');
check(State::next(null,false,false,1000)===null, 'failure leaves null metadata untouched');
echo "RESULT $checks checks passed\n";
