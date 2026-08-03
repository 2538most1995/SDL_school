<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\District;
use App\Support\DistrictBranding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class BrandingController extends Controller
{
    public function __construct(private readonly DistrictBranding $branding) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->branding->payload($this->district($request)), 'meta' => ['source' => 'laravel_control_plane']]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'schoolName' => ['required', 'string', 'max:120'],
            'portalName' => ['required', 'string', 'max:120'],
            'welcomeMessage' => ['required', 'string', 'max:220'],
            'primaryColor' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'districtName' => ['nullable', 'string', 'max:120'],
        ]);
        $district = $this->district($request);
        $district->update([
            'login_title' => trim($validated['schoolName']),
            'portal_name' => trim($validated['portalName']),
            'welcome_message' => trim($validated['welcomeMessage']),
            'primary_color' => mb_strtolower($validated['primaryColor']),
        ]);
        $this->audit($request, $district, 'branding.updated', ['login_title', 'portal_name', 'welcome_message', 'primary_color']);

        return $this->response($district);
    }

    public function updateHero(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'hero' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:6144', 'dimensions:min_width=800,min_height=600,max_width=6000,max_height=6000'],
        ]);
        $district = $this->district($request);
        $path = $validated['hero']->store("branding/districts/{$district->id}/hero", 'local');

        abort_if($path === false, 500, 'ไม่สามารถบันทึกรูปหน้าปกได้');

        $oldPath = (string) $district->login_hero_path;
        $district->update(['login_hero_path' => $path, 'login_hero_updated_at' => now()]);

        if ($this->branding->isOwnedHeroPath($district->id, $oldPath) && $oldPath !== $path) {
            Storage::disk('local')->delete($oldPath);
        }

        $this->audit($request, $district, 'branding.hero_updated', ['login_hero_path']);

        return $this->response($district->fresh());
    }

    public function destroyHero(Request $request): JsonResponse
    {
        $district = $this->district($request);
        $path = (string) $district->login_hero_path;

        if ($this->branding->isOwnedHeroPath($district->id, $path)) {
            Storage::disk('local')->delete($path);
        }

        $district->update(['login_hero_path' => null, 'login_hero_updated_at' => now()]);
        $this->audit($request, $district, 'branding.hero_removed', ['login_hero_path']);

        return $this->response($district->fresh());
    }

    public function updateAsset(Request $request, string $slot): JsonResponse
    {
        $rules = match ($slot) {
            'logo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=128,min_height=128,max_width=3000,max_height=3000'],
            'dashboard-hero' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192', 'dimensions:min_width=1200,min_height=600,max_width=7000,max_height=5000'],
            default => abort(404),
        };
        $validated = $request->validate(['asset' => $rules]);
        $district = $this->district($request);
        $path = $validated['asset']->store("branding/districts/{$district->id}/{$slot}", 'local');

        abort_if($path === false, 500, 'ไม่สามารถบันทึกไฟล์แบรนด์ได้');

        [$pathColumn, $updatedColumn] = match ($slot) {
            'logo' => ['logo_path', 'logo_updated_at'],
            'dashboard-hero' => ['dashboard_hero_path', 'dashboard_hero_updated_at'],
        };
        $oldPath = (string) $district->{$pathColumn};
        $district->update([$pathColumn => $path, $updatedColumn => now()]);

        if ($this->branding->isOwnedAssetPath($district->id, $slot, $oldPath) && $oldPath !== $path) {
            Storage::disk('local')->delete($oldPath);
        }

        $this->audit($request, $district, 'branding.asset_updated', [$pathColumn], ['slot' => $slot]);

        return $this->response($district->fresh());
    }

    public function destroyAsset(Request $request, string $slot): JsonResponse
    {
        [$pathColumn, $updatedColumn] = match ($slot) {
            'logo' => ['logo_path', 'logo_updated_at'],
            'dashboard-hero' => ['dashboard_hero_path', 'dashboard_hero_updated_at'],
            default => abort(404),
        };
        $district = $this->district($request);
        $path = (string) $district->{$pathColumn};

        if ($this->branding->isOwnedAssetPath($district->id, $slot, $path)) {
            Storage::disk('local')->delete($path);
        }

        $district->update([$pathColumn => null, $updatedColumn => now()]);
        $this->audit($request, $district, 'branding.asset_removed', [$pathColumn], ['slot' => $slot]);

        return $this->response($district->fresh());
    }

    private function district(Request $request): District
    {
        return District::query()->findOrFail((int) $request->attributes->get('district_id'));
    }

    private function response(District $district): JsonResponse
    {
        return response()->json(['data' => $this->branding->payload($district), 'meta' => ['source' => 'laravel_control_plane']]);
    }

    /** @param list<string> $changedFields */
    private function audit(Request $request, District $district, string $event, array $changedFields, array $extraContext = []): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'district_id' => $district->id,
            'event' => $event,
            'auditable_type' => 'district',
            'auditable_id' => $district->id,
            'ip_address' => $request->ip(),
            'context' => json_encode(['changed_fields' => $changedFields, ...$extraContext], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
