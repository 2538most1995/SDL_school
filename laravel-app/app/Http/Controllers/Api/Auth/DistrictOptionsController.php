<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\District;
use Illuminate\Http\JsonResponse;

final class DistrictOptionsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $districts = District::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->toArray();

        return response()->json([
            'data' => $districts,
            'meta' => ['data_source' => 'system_database'],
        ]);
    }
}
