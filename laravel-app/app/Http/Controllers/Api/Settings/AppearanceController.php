<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class AppearanceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->payload($request), 'meta' => ['source' => 'laravel_control_plane']]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'theme' => ['required', Rule::in(['light', 'dark', 'system'])],
            'colorScheme' => ['sometimes', Rule::in(['blue', 'teal', 'violet', 'rose', 'amber'])],
            'fontSize' => ['required', Rule::in(['normal', 'large'])],
            'density' => ['required', Rule::in(['comfortable', 'compact'])],
        ]);
        $request->user()->update([
            'theme' => $validated['theme'],
            'color_scheme' => $validated['colorScheme'] ?? ($request->user()->color_scheme ?: 'blue'),
            'font_size' => $validated['fontSize'],
            'density' => $validated['density'],
        ]);
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'district_id' => $request->attributes->get('district_id'),
            'event' => 'appearance.updated',
            'auditable_type' => 'user',
            'auditable_id' => $request->user()->id,
            'ip_address' => $request->ip(),
            'context' => json_encode(['changed_fields' => ['theme', 'color_scheme', 'font_size', 'density']], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return response()->json(['data' => $this->payload($request), 'meta' => ['source' => 'laravel_control_plane']]);
    }

    /** @return array<string, string> */
    private function payload(Request $request): array
    {
        return [
            'theme' => (string) ($request->user()->theme ?: 'system'),
            'colorScheme' => (string) ($request->user()->color_scheme ?: 'blue'),
            'fontSize' => (string) ($request->user()->font_size ?: 'normal'),
            'density' => (string) ($request->user()->density ?: 'comfortable'),
        ];
    }
}
