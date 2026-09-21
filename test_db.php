<?php
require __DIR__.'/laravel-app/vendor/autoload.php';
$app = require_once __DIR__.'/laravel-app/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
$conn = config('database.default');
echo "Default DB Connection: " . $conn . "\n";
echo "Host: " . config("database.connections.{$conn}.host") . "\n";
echo "Database: " . config("database.connections.{$conn}.database") . "\n";
