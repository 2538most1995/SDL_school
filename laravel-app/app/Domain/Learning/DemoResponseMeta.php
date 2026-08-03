<?php

namespace App\Domain\Learning;

final class DemoResponseMeta
{
    /**
     * @param  array<string, scalar|null>  $filters
     * @return array<string, mixed>
     */
    public static function collection(int $total, array $filters = []): array
    {
        return [
            'mode' => 'demo',
            'source' => 'canonical_demo',
            'generated_at' => now()->toIso8601String(),
            'contains_personal_data' => false,
            'read_only' => true,
            'pagination' => [
                'page' => 1,
                'per_page' => $total,
                'total' => $total,
                'last_page' => 1,
            ],
            'filters' => array_filter($filters, fn (mixed $value): bool => $value !== null && $value !== ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function item(): array
    {
        return [
            'mode' => 'demo',
            'source' => 'canonical_demo',
            'generated_at' => now()->toIso8601String(),
            'contains_personal_data' => false,
            'read_only' => true,
        ];
    }
}
