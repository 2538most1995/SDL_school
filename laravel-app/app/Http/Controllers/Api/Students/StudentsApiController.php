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
            'mode' => config('legacy.enabled') ? 'production' : 'demo',
            'source' => config('legacy.enabled') ? 'legacy_read_only' : 'synthetic_canonical_dataset',
            'generated_at' => now()->toIso8601String(),
            'contains_personal_data' => (bool) config('legacy.enabled'),
            'read_only' => (bool) config('legacy.enabled'),
            ...$extra,
        ];
    }
}
