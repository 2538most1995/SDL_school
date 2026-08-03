<?php

return [
    'enabled' => env('SENA_DATA_SOURCE', 'demo') === 'legacy',
    'student_enabled' => env('LEGACY_STUDENT_ENABLED', false),
    'connection' => env('SENA_LEGACY_CONNECTION', 'legacy'),
    'read_only' => env('SENA_LEGACY_READ_ONLY', true),
    'write_enabled' => env('SENA_LEGACY_WRITE_ENABLED', false),
    'write_connection' => env('SENA_LEGACY_WRITE_CONNECTION', 'legacy_write'),
    'allow_config_fallback' => env('SENA_LEGACY_CONFIG_FALLBACK', false),
    // Import tables are immutable and every replacement receives a new batch
    // key, so computed aggregates can be reused safely for several minutes.
    'cache_seconds' => (int) env('SENA_LEGACY_CACHE_SECONDS', 900),
    'fpt_memo_root' => env('SENA_LEGACY_MEMO_ROOT') ?: base_path('../uploads/extracted'),
    'zip_root' => env('SENA_LEGACY_ZIP_ROOT') ?: base_path('../uploads/zips'),
    'extract_root' => env('SENA_LEGACY_EXTRACT_ROOT') ?: base_path('../uploads/extracted'),
];
