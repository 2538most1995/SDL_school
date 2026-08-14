<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\District;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

final class DistrictController extends Controller
{
    public function index(): JsonResponse
    {
        $districts = District::query()
            ->select(['id', 'name', 'code', 'is_active', 'created_at'])
            ->withCount('users')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->limit(1000)
            ->get()
            ->map(fn (District $district): array => $this->payload($district))
            ->values();

        return response()->json([
            'data' => $districts,
            'meta' => [
                'source' => 'system_database',
                'read_only' => ! $this->writeEnabled(),
                'active_count' => $districts->where('is_active', true)->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($this->writeEnabled(), 503, 'ระบบเพิ่มอำเภอยังไม่เปิดใช้งาน');

        $request->merge([
            'name' => trim((string) $request->input('name')),
            'code' => mb_strtolower(trim((string) $request->input('code'))),
        ]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'min:2',
                'max:40',
                'regex:/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/',
                Rule::unique('districts', 'code'),
            ],
        ], [
            'name.required' => 'กรุณาระบุชื่ออำเภอ',
            'code.required' => 'กรุณาระบุรหัสอำเภอ',
            'code.regex' => 'รหัสอำเภอใช้ได้เฉพาะ a-z, 0-9, ขีดกลาง หรือขีดล่าง และต้องไม่ขึ้นหรือลงท้ายด้วยขีด',
            'code.unique' => 'รหัสอำเภอนี้มีอยู่ในระบบแล้ว',
        ]);

        $district = DB::transaction(function () use ($request, $validated): District {
            $district = District::query()->create([
                'name' => $validated['name'],
                'code' => $validated['code'],
                'is_active' => true,
            ]);

            $this->audit($request, $district);

            return $district;
        });
        $district->setAttribute('users_count', 0);

        return response()->json([
            'data' => $this->payload($district),
            'meta' => ['source' => 'system_database', 'read_only' => false],
        ], 201);
    }

    /** @return array{id: int, name: string, code: string, is_active: bool, users_count: int, created_at: string|null} */
    private function payload(District $district): array
    {
        return [
            'id' => (int) $district->id,
            'name' => (string) $district->name,
            'code' => (string) $district->code,
            'is_active' => (bool) $district->is_active,
            'users_count' => (int) ($district->users_count ?? 0),
            'created_at' => $district->created_at?->toIso8601String(),
        ];
    }

    private function audit(Request $request, District $district): void
    {
        $entry = [
            'user_id' => (int) $request->user()->id,
            'district_id' => (int) $district->id,
            'event' => 'super_admin.district.created',
            'auditable_type' => 'system_district',
            'auditable_id' => (int) $district->id,
            'ip_address' => $request->ip(),
            'before' => null,
            'after' => json_encode([
                'id' => (int) $district->id,
                'name' => (string) $district->name,
                'code' => (string) $district->code,
                'is_active' => (bool) $district->is_active,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ];

        try {
            DB::table('audit_logs')->insert($entry);
        } catch (Throwable $exception) {
            Log::warning('super_admin.district.audit_fallback', [
                ...$entry,
                'exception' => $exception::class,
            ]);
        }
    }

    private function writeEnabled(): bool
    {
        return (bool) config('system_data.enabled') && (bool) config('system_data.write_enabled');
    }
}
