<?php

$systemMode = env('SENA_DATA_SOURCE', 'system') !== 'demo';

return [
    'enabled' => $systemMode,
    'student_enabled' => env('SYSTEM_STUDENT_DATA_ENABLED', $systemMode),
    'write_enabled' => env('SYSTEM_WRITES_ENABLED', $systemMode),
    'import_queue_connection' => env('SYSTEM_IMPORT_QUEUE_CONNECTION', 'database'),
    // Run the queue kicker after the upload response in the same PHP worker.
    // Unlike the background driver this works when shared hosting disables
    // proc_open / detached processes.
    'import_autostart_connection' => 'deferred',
    // Replacements receive a new batch key. Academic cache keys also include
    // the scoped imported-score edit revision, so saved marks are visible immediately.
    'cache_seconds' => (int) env('SYSTEM_DATA_CACHE_SECONDS', 900),
    'student_api_auth_rate_limit_per_minute' => max(1, (int) env('STUDENT_DATA_API_AUTH_RATE_LIMIT_PER_MINUTE', 20)),
    'student_api_rate_limit_per_minute' => max(1, (int) env('STUDENT_DATA_API_RATE_LIMIT_PER_MINUTE', 60)),
    'fpt_memo_root' => env('SYSTEM_IMPORT_MEMO_ROOT') ?: base_path('../uploads/extracted'),
    'zip_root' => env('SYSTEM_IMPORT_ZIP_ROOT') ?: base_path('../uploads/zips'),
    'extract_root' => env('SYSTEM_IMPORT_EXTRACT_ROOT') ?: base_path('../uploads/extracted'),
];
