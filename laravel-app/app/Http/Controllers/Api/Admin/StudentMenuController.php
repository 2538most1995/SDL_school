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
            'internal_routes' => $menu->internalRoutes(),
        ]]);
    }

    public function update(Request $request, StudentDashboardMenu $menu): JsonResponse
    {
        $keys = array_column($menu->defaults(), 'key');
        $validated = $request->validate([
            'items' => ['required', 'array', 'list', 'min:'.count($keys), 'max:'.(count($keys) + 50)],
            'items.*' => ['required', 'array:key,label,description,visible,link_type,url'],
            'items.*.key' => ['required', 'string', 'distinct', function (string $attribute, mixed $value, Closure $fail) use ($keys, $menu): void {
                if (! in_array($value, $keys, true) && ! $menu->isCustomKey($value)) {
                    $fail('รหัสเมนูไม่ถูกต้อง');
                }
            }],
            'items.*.label' => ['required', 'string', 'max:80', 'regex:/\S/u'],
            'items.*.description' => ['present', 'nullable', 'string', 'max:160'],
            'items.*.visible' => ['present', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_bool($value)) {
                    $fail('สถานะการแสดงเมนูต้องเป็น true หรือ false');
                }
            }],
            'items.*.link_type' => ['sometimes', 'string', Rule::in(['internal', 'external'])],
            'items.*.url' => ['sometimes', 'string', 'max:2048'],
        ]);
        $submitted = array_values(array_filter(array_column($validated['items'], 'key'), fn (string $key): bool => ! $menu->isCustomKey($key)));
        sort($keys);
        sort($submitted);
        if ($keys !== $submitted) {
            throw ValidationException::withMessages(['items' => ['ต้องระบุเมนูนักศึกษาให้ครบและไม่ซ้ำกัน']]);
        }
        foreach ($validated['items'] as $index => &$item) {
            $item['description'] ??= '';
            if ($menu->isCustomKey($item['key'])) {
                if ($menu->customItem($item) === null) {
                    throw ValidationException::withMessages(["items.$index.url" => ['กรุณาเลือกลิงก์ภายในที่นักศึกษาเข้าถึงได้ หรือกรอก URL แบบ HTTP/HTTPS ที่ถูกต้อง']]);
                }
            } elseif (array_key_exists('url', $item) || array_key_exists('link_type', $item)) {
                throw ValidationException::withMessages(["items.$index" => ['ไม่สามารถเปลี่ยนปลายทางของเมนูระบบได้']]);
            }
        }
        unset($item);
        abort_unless($menu->canSave(), 503, 'กรุณารัน migration ก่อนบันทึกเมนูนักศึกษา');
        $districtId = (int) $request->attributes->get('district_id');
        $items = array_map(fn (array $item): array => [
            'key' => $item['key'], 'label' => trim($item['label']),
            'description' => trim($item['description'] ?? ''), 'visible' => $item['visible'],
            ...($menu->isCustomKey($item['key']) ? ['link_type' => $item['link_type'], 'url' => $item['url']] : []),
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
