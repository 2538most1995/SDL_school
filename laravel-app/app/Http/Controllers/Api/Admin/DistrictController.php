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
            ->select(['id', 'name', 'code', 'school_code', 'is_active', 'created_at'])
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
            'school_code' => $this->normalizedSchoolCode($request),
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
            'school_code' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]+$/'],
        ], [
            'name.required' => 'กรุณาระบุชื่ออำเภอ',
            'code.required' => 'กรุณาระบุรหัสอำเภอ',
            'code.regex' => 'รหัสอำเภอใช้ได้เฉพาะ a-z, 0-9, ขีดกลาง หรือขีดล่าง และต้องไม่ขึ้นหรือลงท้ายด้วยขีด',
            'code.unique' => 'รหัสอำเภอนี้มีอยู่ในระบบแล้ว',
            'school_code.regex' => 'รหัสสถานศึกษาต้องเป็นตัวเลขเท่านั้น',
        ]);

        $district = DB::transaction(function () use ($request, $validated): District {
            $district = District::query()->create([
                'name' => $validated['name'],
                'code' => $validated['code'],
                'school_code' => $validated['school_code'] ?? null,
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

    public function update(Request $request, District $district): JsonResponse
    {
        abort_unless($this->writeEnabled(), 503, 'ระบบแก้ไขอำเภอยังไม่เปิดใช้งาน');

        $updatesSchoolCode = $request->exists('school_code');
        $normalized = ['name' => trim((string) $request->input('name'))];
        if ($updatesSchoolCode) {
            $normalized['school_code'] = $this->normalizedSchoolCode($request);
        }
        $request->merge($normalized);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'school_code' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]+$/'],
        ], [
            'name.required' => 'กรุณาระบุชื่ออำเภอ',
            'school_code.regex' => 'รหัสสถานศึกษาต้องเป็นตัวเลขเท่านั้น',
        ]);

        DB::transaction(function () use ($district, $request, $updatesSchoolCode, $validated): void {
            $before = $this->auditPayload($district);
            $changes = ['name' => $validated['name']];
            if ($updatesSchoolCode) {
                $changes['school_code'] = $validated['school_code'] ?? null;
            }
            $district->fill($changes)->save();
            $this->audit($request, $district, 'super_admin.district.updated', $before);
        });

        $district->loadCount('users');

        return response()->json([
            'data' => $this->payload($district),
            'meta' => ['source' => 'system_database', 'read_only' => false],
        ]);
    }

    /** @return array{id: int, name: string, code: string, school_code: string|null, is_active: bool, users_count: int, created_at: string|null} */
    private function payload(District $district): array
    {
        return [
            'id' => (int) $district->id,
            'name' => (string) $district->name,
            'code' => (string) $district->code,
            'school_code' => filled($district->school_code) ? (string) $district->school_code : null,
            'is_active' => (bool) $district->is_active,
            'users_count' => (int) ($district->users_count ?? 0),
            'created_at' => $district->created_at?->toIso8601String(),
        ];
    }

    /** @param array<string, mixed>|null $before */
    private function audit(Request $request, District $district, string $event = 'super_admin.district.created', ?array $before = null): void
    {
        $entry = [
            'user_id' => (int) $request->user()->id,
            'district_id' => (int) $district->id,
            'event' => $event,
            'auditable_type' => 'system_district',
            'auditable_id' => (int) $district->id,
            'ip_address' => $request->ip(),
            'before' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'after' => json_encode($this->auditPayload($district), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
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

    /** @return array<string, mixed> */
    private function auditPayload(District $district): array
    {
        return [
            'id' => (int) $district->id,
            'name' => (string) $district->name,
            'code' => (string) $district->code,
            'school_code' => filled($district->school_code) ? (string) $district->school_code : null,
            'is_active' => (bool) $district->is_active,
        ];
    }

    private function normalizedSchoolCode(Request $request): ?string
    {
        $value = trim((string) $request->input('school_code'));

        return $value === '' ? null : $value;
    }

    private function writeEnabled(): bool
    {
        return (bool) config('system_data.enabled') && (bool) config('system_data.write_enabled');
    }
}
