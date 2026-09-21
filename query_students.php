<?php
require __DIR__.'/laravel-app/vendor/autoload.php';
$app = require_once __DIR__.'/laravel-app/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$studentIds = ['6722000170', '6712000605', '6722000385', '6823000608', '6723000212'];

$tables = DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'db_import_%_student'");

foreach ($tables as $tableRow) {
    $table = $tableRow->name;
    
    // Check if the columns exist
    $columns = DB::select("PRAGMA table_info(`$table`)");
    $colNames = array_map(function($c) { return $c->name; }, $columns);
    
    $queryCols = ['_perf_id10', 'name', 'surname'];
    
    foreach (['nt_sara1', 'nt_sara2', 'nt_sem', 'nt_nosem', 'nnet', 'n_net', 'eexam', 'e_exam', 'nnet_stat', 'exm_status', 'nt_result', 'nt_res', 'nnet_pass', 'nnet_result', 'eexam_status', 'e_exam_stat'] as $c) {
        if (in_array($c, $colNames)) {
            $queryCols[] = $c;
        }
    }
    
    $results = DB::table($table)->whereIn('_perf_id10', $studentIds)->select($queryCols)->get();
    
    if (count($results) > 0) {
        echo "Table: $table\n";
        foreach ($results as $row) {
            print_r($row);
        }
    }
}
