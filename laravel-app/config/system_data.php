<?php

$systemMode = env('SENA_DATA_SOURCE', 'system') !== 'demo';

return [
    'enabled' => $systemMode,
    'student_enabled' => env('SYSTEM_STUDENT_DATA_ENABLED', $systemMode),
    'write_enabled' => env('SYSTEM_WRITES_ENABLED', $systemMode),
    'import_queue_connection' => env('SYSTEM_IMPORT_QUEUE_CONNECTION', 'database'),
    'import_autostart_connection' => env('SYSTEM_IMPORT_AUTOSTART_CONNECTION', 'background'),
    // Import tables are immutable and every replacement receives a new batch
    // key, so computed aggregates can be reused safely for several minutes.
    'cache_seconds' => (int) env('SYSTEM_DATA_CACHE_SECONDS', 900),
    'fpt_memo_root' => env('SYSTEM_IMPORT_MEMO_ROOT') ?: base_path('../uploads/extracted'),
    'zip_root' => env('SYSTEM_IMPORT_ZIP_ROOT') ?: base_path('../uploads/zips'),
    'extract_root' => env('SYSTEM_IMPORT_EXTRACT_ROOT') ?: base_path('../uploads/extracted'),
];
