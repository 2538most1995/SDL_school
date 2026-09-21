<?php
require __DIR__.'/laravel-app/vendor/autoload.php';
$app = require_once __DIR__.'/laravel-app/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
config(['database.connections.mysql.host' => '127.0.0.1']);
config(['database.connections.mysql.port' => 8889]);
config(['database.connections.mysql.database' => 'krumostc_sena_care']);
config(['database.connections.mysql.username' => 'root']);
config(['database.connections.mysql.password' => 'root']);
config(['database.connections.mysql.unix_socket' => '']);

$conn = DB::connection('mysql');

$studentIds = ['6722000170', '6712000605', '6722000385', '6823000608', '6723000212'];

$tables = $conn->select("SHOW TABLES LIKE 'db_import_%_student'");

foreach ($tables as $t) {
    $table = current((array)$t);
    $results = $conn->table($table)->whereIn('_perf_id10', $studentIds)->get();
    if (count($results) > 0) {
        echo "Table: $table\n";
        foreach ($results as $row) {
            echo "ID: " . $row->_perf_id10 . " NNET: " . ($row->nnet ?? $row->nnet_stat ?? $row->exm_status ?? $row->n_net ?? 'null') . " SARA1: " . ($row->nt_sara1 ?? 'null') . " SARA2: " . ($row->nt_sara2 ?? 'null') . " NT_SEM: " . ($row->nt_sem ?? 'null') . " NT_NOSEM: " . ($row->nt_nosem ?? 'null') . "\n";
        }
    }
}
