<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
if (app()->environment() !== 'testing' || config('database.connections.mysql.host') !== 'mixpost-local-rehearsal-ttb-db') {
    throw new RuntimeException('Isolated database only');
}
$result = [];
foreach (DB::select('SHOW TABLES') as $row) {
    $table = array_values((array) $row)[0];
    if ($table !== 'users' && ! str_starts_with($table, 'mixpost_') && ! str_starts_with($table, 'peachy_')) {
        continue;
    }
    if (! preg_match('/^[a-zA-Z0-9_]+$/D', $table)) {
        throw new RuntimeException('Unexpected table identifier');
    }
    $keys = DB::select("SHOW INDEX FROM `$table` WHERE Key_name='PRIMARY'");
    usort($keys, fn ($a, $b) => $a->Seq_in_index <=> $b->Seq_in_index);
    $query = DB::table($table);
    $hash = hash_init('sha256');
    $count = 0;
    if ($keys) {
        foreach ($keys as $key) {
            $query->orderBy($key->Column_name);
        }
        foreach ($query->cursor() as $item) {
            hash_update($hash, json_encode($item, JSON_THROW_ON_ERROR)."\n");
            $count++;
        }
    } else {
        $rows = [];
        foreach ($query->cursor() as $item) {
            $rows[] = hash('sha256', json_encode($item, JSON_THROW_ON_ERROR));
            $count++;
        }
        sort($rows);
        foreach ($rows as $value) {
            hash_update($hash, $value."\n");
        }
    }
    $result[$table] = ['count' => $count, 'sha256' => hash_final($hash)];
}
ksort($result);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
