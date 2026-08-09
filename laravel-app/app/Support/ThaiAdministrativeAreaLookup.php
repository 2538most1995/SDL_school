<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Throwable;

final class ThaiAdministrativeAreaLookup
{
    private const CACHE_KEY = 'thai-administrative-areas-local-v1';

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
                fn (): array => $this->loadLocalSnapshot(),
            );
        } catch (Throwable) {
            return $this->loadedAreas = [];
        }
    }

    /** @return array<string, array{subdistrict: string, district: string, province: string}> */
    private function loadLocalSnapshot(): array
    {
        $path = resource_path('data/thai_administrative_areas.csv');
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $areas = [];
        try {
            fgetcsv($handle, null, ',', '"', '');
            while (($record = fgetcsv($handle, null, ',', '"', '')) !== false) {
                [$code, $subdistrict, $district, $province] = array_pad($record, 4, '');
                $code = trim((string) $code);
                if (preg_match('/^\d{6}$/', $code) !== 1) {
                    continue;
                }

                $areas[$code] = [
                    'subdistrict' => trim((string) $subdistrict),
                    'district' => trim((string) $district),
                    'province' => trim((string) $province),
                ];
            }
        } finally {
            fclose($handle);
        }

        return $areas;
    }
}
