<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\District;
use App\Support\StudentDashboardMenu;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class StudentMenuController extends Controller
{
    public function show(Request $request, StudentDashboardMenu $menu): JsonResponse
    {
        return response()->json(['data' => [
            'items' => $menu->items((int) $request->attributes->get('district_id')),
            'defaults' => $menu->defaults(),
            'can_save' => $menu->canSave(),
        ]]);
    }

    public function update(Request $request, StudentDashboardMenu $menu): JsonResponse
    {
        $keys = array_column($menu->defaults(), 'key');
        $validated = $request->validate([
            'items' => ['required', 'array', 'list', 'size:'.count($keys)],
            'items.*' => ['required', 'array:key,label,description,visible'],
            'items.*.key' => ['required', 'string', 'distinct', Rule::in($keys)],
            'items.*.label' => ['required', 'string', 'max:80', 'regex:/\S/u'],
            'items.*.description' => ['present', 'nullable', 'string', 'max:160'],
            'items.*.visible' => ['present', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_bool($value)) {
                    $fail('สถานะการแสดงเมนูต้องเป็น true หรือ false');
                }
            }],
        ]);
        $submitted = array_column($validated['items'], 'key');
        sort($keys);
        sort($submitted);
        if ($keys !== $submitted) {
            throw ValidationException::withMessages(['items' => ['ต้องระบุเมนูนักศึกษาให้ครบและไม่ซ้ำกัน']]);
        }
        abort_unless($menu->canSave(), 503, 'กรุณารัน migration ก่อนบันทึกเมนูนักศึกษา');
        $districtId = (int) $request->attributes->get('district_id');
        $items = array_map(fn (array $item): array => [
            'key' => $item['key'], 'label' => trim($item['label']),
            'description' => trim($item['description'] ?? ''), 'visible' => $item['visible'],
        ], $validated['items']);
        DB::transaction(function () use ($request, $items, $districtId): void {
            $district = District::query()->lockForUpdate()->findOrFail($districtId);
            $district->student_menu_preferences = $items;
            $district->save();
            DB::table('audit_logs')->insert([
                'user_id' => $request->user()->id, 'district_id' => $districtId,
                'event' => 'student-menu.updated', 'auditable_type' => 'district', 'auditable_id' => $districtId,
                'ip_address' => $request->ip(),
                'context' => json_encode(['visible_items' => count(array_filter($items, fn (array $item): bool => $item['visible']))], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        });

        return $this->show($request, $menu);
    }
}
