<?php
require __DIR__.'/laravel-app/vendor/autoload.php';
$app = require_once __DIR__.'/laravel-app/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
$conn = DB::connection('mysql');
echo "MySQL Host: " . config("database.connections.mysql.host") . "\n";
echo "MySQL DB: " . config("database.connections.mysql.database") . "\n";

$tables = $conn->select("SHOW TABLES LIKE 'db_import_%_student'");
foreach ($tables as $t) {
    echo "Table: " . current((array)$t) . "\n";
}
