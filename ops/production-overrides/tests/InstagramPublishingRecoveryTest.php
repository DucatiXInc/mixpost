<?php

// Exact candidate dependencies; HTTP fakes only, no application, database, queue or external calls.
require '/var/www/html/vendor/autoload.php';

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Inovector\Mixpost\Support\InstagramPublishingRecovery as Recovery;
use Inovector\Mixpost\Support\PublishingMediaFallback;

Facade::setFacadeApplication(new Container);
function recoveryCheck(bool $ok): void { if (! $ok) { throw new RuntimeException('Recovery contract failed'); } }
function recoveryTest(string $name, callable $test): void { $test(); echo 'PASS '.$name.PHP_EOL; }
function rejected(array $changes = [], array $requested = ['private_person', 'other.person']): array {
    return Recovery::rejectedCollaborators(array_replace(['code' => 110, 'error_subcode' => 2207018,
        'error_user_msg' => 'The following user(s) cannot be accessed: private_person'], $changes), $requested);
}
recoveryTest('explicit private collaborator identified', fn () => recoveryCheck(rejected() === ['private_person']));
recoveryTest('other requested collaborators preserved by selection', fn () => recoveryCheck(! in_array('other.person', rejected(), true)));
recoveryTest('multiple explicitly named collaborators', fn () => recoveryCheck(rejected(['error_user_msg' => 'The following user(s) cannot be accessed: private_person, other.person']) === ['private_person', 'other.person']));
recoveryTest('case and at-sign normalization', fn () => recoveryCheck(rejected(['error_user_msg' => 'The following user(s) cannot be accessed: @Private_Person']) === ['private_person']));
recoveryTest('unknown username cannot authorize removal', fn () => recoveryCheck(rejected(['error_user_msg' => 'The following user(s) cannot be accessed: someone_else']) === []));
recoveryTest('partial match list cannot authorize removals', fn () => recoveryCheck(rejected(['error_user_msg' => 'The following user(s) cannot be accessed: private_person, someone_else']) === []));
recoveryTest('generic invalid user id cannot authorize removal', fn () => recoveryCheck(rejected(['error_user_msg' => 'Invalid user id']) === []));
recoveryTest('wrong error code cannot authorize removal', fn () => recoveryCheck(rejected(['code' => 190]) === []));
recoveryTest('wrong subcode cannot authorize removal', fn () => recoveryCheck(rejected(['error_subcode' => 2207013]) === []));
recoveryTest('empty requested list cannot authorize removal', fn () => recoveryCheck(rejected([], []) === []));
recoveryTest('explicit not-visible collaborator identified', fn () => recoveryCheck(rejected(['code' => 210, 'error_subcode' => 2207066, 'error_user_msg' => 'The user private_person cannot be tagged on this media.']) === ['private_person']));
recoveryTest('not-visible without a named user is unsafe', fn () => recoveryCheck(rejected(['code' => 210, 'error_subcode' => 2207066, 'error_user_msg' => 'User not visible']) === []));
recoveryTest('usernames are matched as whole values', fn () => recoveryCheck(rejected([], ['private_person_extra']) === []));
recoveryTest('media fetch rejection recognized', fn () => recoveryCheck(Recovery::storageError(['code' => 9004, 'error_subcode' => 2207052])));
recoveryTest('bounded existing timeout recognized', fn () => recoveryCheck(Recovery::storageError(['code' => -2, 'error_subcode' => 2207003])));
recoveryTest('unrelated media or permission errors excluded', function () {
    foreach ([[9004,2207004], [190,2207052], [-2,2207004], [100,33]] as [$code,$sub]) {
        recoveryCheck(! Recovery::storageError(['code' => $code, 'error_subcode' => $sub]));
    }
});
class PublicRecoveryProbe extends PublishingMediaFallback {
    public function verify(string $url, int $size, string $hash): void { $this->verifyPublic($url, $size, $hash); }
}
function fakeRecoveryHttp(string $bytes, int $status = 200): PublicRecoveryProbe {
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake(['https://media.test/*' => Http::response($bytes, $status)]);
    return new PublicRecoveryProbe;
}
function recoveryMustFail(callable $fn): void {
    try { $fn(); } catch (RuntimeException $e) { return; }
    throw new RuntimeException('Unsafe media passed verification');
}
$bytes = 'identical-public-image'; $url = 'https://media.test/owned.jpg'; $hash = hash('sha256', $bytes);
recoveryTest('public readback verifies every byte', fn () => fakeRecoveryHttp($bytes)->verify($url, strlen($bytes), $hash));
recoveryTest('truncated public image is rejected', fn () => recoveryMustFail(fn () => fakeRecoveryHttp('short')->verify($url, strlen($bytes), $hash)));
recoveryTest('same-size wrong public image is rejected', fn () => recoveryMustFail(fn () => fakeRecoveryHttp(str_repeat('x', strlen($bytes)))->verify($url, strlen($bytes), $hash)));
recoveryTest('oversized public response is rejected', fn () => recoveryMustFail(fn () => fakeRecoveryHttp($bytes.'extra')->verify($url, strlen($bytes), $hash)));
recoveryTest('public error is rejected', fn () => recoveryMustFail(fn () => fakeRecoveryHttp($bytes, 503)->verify($url, strlen($bytes), $hash)));
recoveryTest('public redirect is not trusted', fn () => recoveryMustFail(fn () => fakeRecoveryHttp($bytes, 302)->verify($url, strlen($bytes), $hash)));
