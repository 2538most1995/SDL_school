<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\District;
use App\Support\DistrictBranding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PublicBrandingController extends Controller
{
    public function __construct(private readonly DistrictBranding $branding) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->branding->payload($this->district($request)),
            'meta' => ['source' => 'laravel_control_plane'],
        ]);
    }

    public function hero(Request $request): StreamedResponse
    {
        $district = $this->district($request);
        $path = (string) $district->login_hero_path;

        abort_unless(
            $this->branding->isOwnedHeroPath($district->id, $path) && Storage::disk('local')->exists($path),
            404,
        );

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function asset(Request $request, string $slot): StreamedResponse
    {
        $district = $this->district($request);
        $path = $this->branding->assetPath($district, $slot);

        abort_unless(
            $this->branding->isOwnedAssetPath($district->id, $slot, $path) && Storage::disk('local')->exists($path),
            404,
        );

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function district(Request $request): District
    {
        $validated = $request->validate(['district_id' => ['nullable', 'integer', 'min:1']]);

        return District::query()
            ->where('is_active', true)
            ->when(isset($validated['district_id']), fn ($query) => $query->whereKey((int) $validated['district_id']))
            ->orderBy('id')
            ->firstOrFail();
    }
}
