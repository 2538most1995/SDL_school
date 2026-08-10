<?php

namespace App\Http\Controllers\Api\Students;

use App\Http\Controllers\Controller;

abstract class StudentsApiController extends Controller
{
    /** @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function meta(array $extra = []): array
    {
        return [
            'mode' => config('system_data.enabled') ? 'production' : 'demo',
            'source' => config('system_data.enabled') ? 'system_database' : 'synthetic_canonical_dataset',
            'generated_at' => now()->toIso8601String(),
            'contains_personal_data' => (bool) config('system_data.enabled'),
            'read_only' => (bool) config('system_data.enabled'),
            ...$extra,
        ];
    }
}
