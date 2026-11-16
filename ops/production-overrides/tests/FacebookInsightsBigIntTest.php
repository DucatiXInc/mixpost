<?php
// MySQL integration test for the candidate's actual private import method.
// Run only in the network-isolated restored candidate with APP_ENV=testing.
declare(strict_types=1);

set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, 'FAIL Facebook insights BIGINT import: '.get_class($error).': '.$error->getMessage().PHP_EOL);
    exit(1);
});
$options = getopt('', ['app-root:', 'workspace-id:', 'account-id:']);
foreach (['workspace-id', 'account-id'] as $key) {
    if (!isset($options[$key]) || !ctype_digit((string)$options[$key]) || (int)$options[$key] <= 0) {
        throw new RuntimeException('Explicit isolated test scope is required');
    }
}
$root = $options['app-root'] ?? '/var/www/html';
chdir($root);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection();
if (!$app->environment('testing') || $db->getDriverName() !== 'mysql'
    || !preg_match('/rehearsal|isolated|test/i', $db->getDatabaseName())) {
    throw new RuntimeException('Refusing any database outside an explicit isolated MySQL test environment');
}
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Bus::fake();
Illuminate\Support\Facades\Queue::fake();
Illuminate\Support\Facades\Notification::fake();
$workspace = (int)$options['workspace-id'];
$accountId = (int)$options['account-id'];
Inovector\Mixpost\Facades\WorkspaceManager::loadById($workspace);
$account = Inovector\Mixpost\Models\Account::withoutGlobalScopes()->findOrFail($accountId);
if ((int)$account->workspace_id !== $workspace || $account->provider !== 'facebook_page') {
    throw new RuntimeException('Test account scope does not match');
}
$table = static fn() => $db->table('mixpost_facebook_insights');
$fingerprint = static fn() => hash('sha256', json_encode($table()->orderBy('id')->get()->all(), JSON_THROW_ON_ERROR));
$before = $fingerprint();
$dates = ['2098-01-01', '2098-01-02', '2098-01-03'];
if ($table()->where('workspace_id', $workspace)->where('account_id', $accountId)->where('type', 28)->whereIn('date', $dates)->exists()) {
    throw new RuntimeException('Synthetic dates are already occupied');
}
$checks = 0;
$db->beginTransaction();
try {
    $table()->insert(['workspace_id' => $workspace, 'account_id' => $accountId, 'type' => 28,
        'date' => $dates[2], 'value' => 123456, 'created_at' => '2098-01-03 00:00:00', 'updated_at' => '2098-01-03 00:00:00']);
    $unrelated = $table()->where('workspace_id', $workspace)->where('account_id', $accountId)->where('type', 28)->where('date', $dates[2])->first();
    $job = new Inovector\Mixpost\SocialProviders\Meta\Jobs\ImportFacebookInsightsDayPeriodJob($account);
    $method = new ReflectionMethod($job, 'import');
    $method->setAccessible(true);
    $type = Inovector\Mixpost\SocialProviders\Meta\Enums\FacebookInsightType::PAGE_VIDEO_VIEW_TIME;
    $expected = [$dates[0] => 2147483648, $dates[1] => 4294967296];
    $items = [['end_time' => $dates[0].'T00:00:00+00:00', 'value' => 2147483648],
              ['end_time' => $dates[1].'T00:00:00+00:00', 'value' => 4294967296]];
    foreach ([1, 2] as $pass) {
        $method->invoke($job, $type, $items);
        $rows = $table()->where('workspace_id', $workspace)->where('account_id', $accountId)->where('type', 28)
            ->whereIn('date', array_keys($expected))->orderBy('date')->get(['date', 'value']);
        $actual = [];
        foreach ($rows as $row) { $actual[$row->date] = (int)$row->value; }
        if (count($rows) !== 2 || $actual !== $expected) {
            throw new RuntimeException('Large-value round trip or repeated import failed');
        }
        $checks++;
        $now = $table()->where('id', $unrelated->id)->first();
        if (json_encode($now) !== json_encode($unrelated)) {
            throw new RuntimeException('Repeated import changed an unrelated row');
        }
        $checks++;
    }
} finally {
    $db->rollBack();
    Inovector\Mixpost\Facades\WorkspaceManager::forgetCurrent();
}
if ($fingerprint() !== $before) {
    throw new RuntimeException('Test rollback did not preserve every existing insight row');
}
$checks++;
echo 'PASS Facebook insights BIGINT import: '.$checks.' checks; actual importer, both 32-bit boundaries, repeat import, unrelated row and full data rollback'.PHP_EOL;
