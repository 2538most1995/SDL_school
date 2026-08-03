<?php

namespace App\Http\Controllers\Api\Auth;

use App\Contracts\LegacyIdentityProvider;
use App\Http\Controllers\Controller;
use App\Models\District;
use Illuminate\Http\JsonResponse;

final class DistrictOptionsController extends Controller
{
    public function __invoke(LegacyIdentityProvider $legacy): JsonResponse
    {
        $districts = config('legacy.enabled')
            ? $legacy->districts()
            : District::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code'])->toArray();

        return response()->json([
            'data' => $districts,
            'meta' => ['data_source' => config('legacy.enabled') ? 'legacy' : 'local'],
        ]);
    }
}
