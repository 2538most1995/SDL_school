<?php

namespace App\Support;

use App\Models\District;
use Illuminate\Support\Facades\Storage;

final class DistrictBranding
{
    public const DEFAULT_HERO_URL = '/images/sena-students-hero.png';
    public const DEFAULT_DASHBOARD_HERO_URL = '/images/dashboard-hero-sena-v2.webp';

    /** @return array<string, mixed> */
    public function payload(District $district): array
    {
        $heroPath = (string) $district->login_hero_path;
        $hasCustomHero = $this->isOwnedHeroPath($district->id, $heroPath) && Storage::disk('local')->exists($heroPath);
        $logoPath = (string) $district->logo_path;
        $hasCustomLogo = $this->isOwnedAssetPath($district->id, 'logo', $logoPath) && Storage::disk('local')->exists($logoPath);
        $dashboardHeroPath = (string) $district->dashboard_hero_path;
        $hasCustomDashboardHero = $this->isOwnedAssetPath($district->id, 'dashboard-hero', $dashboardHeroPath) && Storage::disk('local')->exists($dashboardHeroPath);
        $heroVersion = $district->login_hero_updated_at?->getTimestamp() ?? $district->updated_at?->getTimestamp() ?? 1;
        $logoVersion = $district->logo_updated_at?->getTimestamp() ?? $district->updated_at?->getTimestamp() ?? 1;
        $dashboardVersion = $district->dashboard_hero_updated_at?->getTimestamp() ?? $district->updated_at?->getTimestamp() ?? 1;

        return [
            'schoolName' => (string) ($district->login_title ?: "ศูนย์ส่งเสริมการเรียนรู้ระดับ{$district->name}"),
            'portalName' => (string) ($district->portal_name ?: 'SDL School'),
            'welcomeMessage' => (string) ($district->welcome_message ?: 'เรียนง่าย เห็นความก้าวหน้าชัดเจน'),
            'primaryColor' => (string) ($district->primary_color ?: '#2563eb'),
            'districtName' => $district->name,
            'logoImageUrl' => $hasCustomLogo
                ? "/api/v1/auth/branding/assets/logo?district_id={$district->id}&v={$logoVersion}"
                : null,
            'hasCustomLogo' => $hasCustomLogo,
            'heroImageUrl' => $hasCustomHero
                ? "/api/v1/auth/branding/hero?district_id={$district->id}&v={$heroVersion}"
                : self::DEFAULT_HERO_URL,
            'hasCustomHero' => $hasCustomHero,
            'dashboardHeroImageUrl' => $hasCustomDashboardHero
                ? "/api/v1/auth/branding/assets/dashboard-hero?district_id={$district->id}&v={$dashboardVersion}"
                : self::DEFAULT_DASHBOARD_HERO_URL,
            'hasCustomDashboardHero' => $hasCustomDashboardHero,
        ];
    }

    public function isOwnedHeroPath(int $districtId, string $path): bool
    {
        return $path !== '' && str_starts_with($path, "branding/districts/{$districtId}/hero/");
    }

    public function isOwnedAssetPath(int $districtId, string $slot, string $path): bool
    {
        $folder = match ($slot) {
            'logo' => 'logo',
            'dashboard-hero' => 'dashboard-hero',
            default => '',
        };

        return $folder !== '' && $path !== '' && str_starts_with($path, "branding/districts/{$districtId}/{$folder}/");
    }

    public function assetPath(District $district, string $slot): string
    {
        return match ($slot) {
            'logo' => (string) $district->logo_path,
            'dashboard-hero' => (string) $district->dashboard_hero_path,
            default => '',
        };
    }
}
