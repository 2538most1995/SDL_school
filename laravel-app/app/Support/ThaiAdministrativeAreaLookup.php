<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class ThaiAdministrativeAreaLookup
{
    private const CACHE_KEY = 'thai-administrative-areas-v1';

    private const RESOURCE_ID = '48039a2a-2f01-448c-b2a2-bb0d541dedcd';

    /** @var array<string, array{subdistrict: string, district: string, province: string}>|null */
    private ?array $loadedAreas = null;

    /** @return array{subdistrict: string, district: string, province: string}|null */
    public function resolve(?string $code): ?array
    {
        $digits = preg_replace('/\D+/', '', (string) $code) ?? '';
        if (strlen($digits) !== 6) {
            return null;
        }

        return $this->areas()[$digits] ?? null;
    }

    /** @return array<string, array{subdistrict: string, district: string, province: string}> */
    private function areas(): array
    {
        if ($this->loadedAreas !== null) {
            return $this->loadedAreas;
        }

        try {
            return $this->loadedAreas = Cache::remember(
                self::CACHE_KEY,
                now()->addYear(),
                fn (): array => $this->download(),
            );
        } catch (Throwable) {
            return $this->loadedAreas = [];
        }
    }

    /** @return array<string, array{subdistrict: string, district: string, province: string}> */
    private function download(): array
    {
        $response = Http::acceptJson()
            ->timeout(10)
            ->retry(1, 200)
            ->get('https://www.data.go.th/api/3/action/datastore_search', [
                'resource_id' => self::RESOURCE_ID,
                'limit' => 8000,
            ]);

        if (! $response->successful() || $response->json('success') !== true) {
            throw new RuntimeException('Unable to load Thai administrative area reference data.');
        }

        $areas = [];
        foreach ((array) $response->json('result.records', []) as $record) {
            $code = str_pad((string) ((int) ($record['TA_ID'] ?? 0)), 6, '0', STR_PAD_LEFT);
            if (strlen($code) !== 6 || $code === '000000') {
                continue;
            }

            $areas[$code] = [
                'subdistrict' => $this->cleanName((string) ($record['TAMBON_T'] ?? ''), 'ต.'),
                'district' => $this->cleanName((string) ($record['AMPHOE_T'] ?? ''), 'อ.'),
                'province' => $this->cleanName((string) ($record['CHANGWAT_T'] ?? ''), 'จ.'),
            ];
        }

        return $areas;
    }

    private function cleanName(string $value, string $prefix): string
    {
        return trim((string) preg_replace('/^'.preg_quote($prefix, '/').'\s*/u', '', trim($value)));
    }
}
