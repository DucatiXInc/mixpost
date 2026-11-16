<?php
// Read-only acceptance check. Pass private expected date=>value JSON through STDIN.
// Example: php verify-facebook-insights-bigint.php --expected=- --workspace-id=24
// --account-id=84 --metric-type=28 --from=2026-05-05 --to=2026-08-03 < expected-values.json
declare(strict_types=1);

$onFailure = static function (Throwable $error): void {
    fwrite(STDERR, json_encode(['passed' => false, 'check' => 'facebook-insights-bigint',
        'error' => $error instanceof RuntimeException ? $error->getMessage() : get_class($error)], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
};
set_exception_handler($onFailure);

$options = getopt('', ['app-root:', 'expected:', 'workspace-id:', 'account-id:', 'metric-type:', 'from:', 'to:']);
foreach (['expected', 'workspace-id', 'account-id', 'metric-type', 'from', 'to'] as $key) {
    if (!isset($options[$key]) || !is_string($options[$key]) || $options[$key] === '') {
        throw new RuntimeException('Missing required option: '.$key);
    }
}
if (PHP_INT_SIZE !== 8) {
    throw new RuntimeException('A 64-bit PHP runtime is required');
}
foreach (['workspace-id', 'account-id', 'metric-type'] as $key) {
    if (!ctype_digit($options[$key]) || (int)$options[$key] <= 0) {
        throw new RuntimeException('Invalid numeric scope');
    }
}
$from = DateTimeImmutable::createFromFormat('!Y-m-d', $options['from'], new DateTimeZone('UTC'));
$to = DateTimeImmutable::createFromFormat('!Y-m-d', $options['to'], new DateTimeZone('UTC'));
if (!$from || !$to || $from->format('Y-m-d') !== $options['from'] || $to->format('Y-m-d') !== $options['to'] || $from > $to) {
    throw new RuntimeException('Invalid date bounds');
}
$json = $options['expected'] === '-' ? stream_get_contents(STDIN) : file_get_contents($options['expected']);
if ($json === false) {
    throw new RuntimeException('Expected-value evidence is unreadable');
}
$expected = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
if (!is_array($expected) || !$expected || array_is_list($expected)) {
    throw new RuntimeException('Expected date-to-integer map is missing');
}
$dates = [];
for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
    $dates[] = $day->format('Y-m-d');
}
ksort($expected);
if (array_keys($expected) !== $dates) {
    throw new RuntimeException('Expected evidence does not cover every requested date exactly');
}
foreach ($expected as $value) {
    if (!is_int($value) || $value < 0) {
        throw new RuntimeException('Expected values must be nonnegative 64-bit integers');
    }
}
if (!array_filter($expected, static fn(int $v): bool => $v > 4294967295)) {
    throw new RuntimeException('Baseline lacks the accepted value above the unsigned 32-bit limit');
}

$root = $options['app-root'] ?? '/var/www/html';
chdir($root);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
// Laravel replaces PHP's handler during bootstrap. Restore the explicit failure exit.
set_exception_handler($onFailure);
$db = Illuminate\Support\Facades\DB::connection();
if ($db->getDriverName() !== 'mysql') {
    throw new RuntimeException('Acceptance requires the actual MySQL database');
}
$column = $db->selectOne("SELECT DATA_TYPE AS data_type, COLUMN_TYPE AS column_type, IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS column_default FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mixpost_facebook_insights' AND COLUMN_NAME='value'");
if (!$column || strtolower($column->data_type) !== 'bigint'
    || !preg_match('/^bigint(?:\([0-9]+\))?$/i', $column->column_type)
    || $column->is_nullable !== 'NO' || $column->column_default !== null) {
    throw new RuntimeException('Field must remain signed BIGINT NOT NULL with no explicit default');
}
$rows = $db->table('mixpost_facebook_insights')->where('workspace_id', (int)$options['workspace-id'])
    ->where('account_id', (int)$options['account-id'])->where('type', (int)$options['metric-type'])
    ->whereBetween('date', [$options['from'], $options['to']])->orderBy('date')->get(['date', 'value']);
$actual = [];
foreach ($rows as $row) {
    if (array_key_exists($row->date, $actual)) {
        throw new RuntimeException('Duplicate historical date');
    }
    if (!is_int($row->value) && !(is_string($row->value) && ctype_digit($row->value) && (string)(int)$row->value === $row->value)) {
        throw new RuntimeException('Database value is not an exact 64-bit integer');
    }
    $actual[$row->date] = (int)$row->value;
}
if ($actual !== $expected) {
    throw new RuntimeException('Historical dates or values differ from accepted private evidence');
}
echo json_encode(['passed' => true, 'check' => 'facebook-insights-bigint', 'signed_bigint_not_null' => true,
    'dates_verified' => count($actual), 'expected_file_sha256' => hash('sha256', $json),
    'canonical_values_sha256' => hash('sha256', json_encode($actual, JSON_THROW_ON_ERROR))], JSON_THROW_ON_ERROR).PHP_EOL;
