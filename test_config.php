<?php
require __DIR__.'/laravel-app/vendor/autoload.php';
$app = require_once __DIR__.'/laravel-app/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Student Enabled: " . config('system_data.student_enabled') . "\n";
