<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\District;
use App\Support\DistrictNavigation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class NavigationController extends Controller
{
    public function show(Request $request, DistrictNavigation $navigation): JsonResponse
    {
        return response()->json(['data' => ['groups' => $navigation->groups((int) $request->attributes->get('district_id'))]]);
    }

    public function update(Request $request, DistrictNavigation $navigation): JsonResponse
    {
        $keys = collect(config('sena.modules'))->flatMap(fn (array $group) => $group['items'])->pluck('key')->all();
        $validated = $request->validate([
            'groups' => ['required', 'array', 'min:1', 'max:30'],
            'groups.*' => ['required', 'array:key,label,items'],
            'groups.*.key' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/', 'distinct'],
            'groups.*.label' => ['required', 'string', 'max:80', 'regex:/\S/u'],
            'groups.*.items' => ['present', 'array'],
            'groups.*.items.*' => ['required', 'string', Rule::in($keys)],
        ]);
        $submitted = collect($validated['groups'])->flatMap(fn (array $group) => $group['items'])->all();
        sort($keys);
        sort($submitted);
        if ($keys !== $submitted) {
            throw ValidationException::withMessages(['groups' => ['ต้องจัดทุกเมนูให้ครบและไม่ซ้ำกัน']]);
        }
        abort_unless(Schema::hasColumn('districts', 'navigation_preferences'), 503, 'กรุณารัน migration ก่อนบันทึกเมนู');
        $districtId = (int) $request->attributes->get('district_id');
        DB::transaction(function () use ($request, $validated, $districtId): void {
            $district = District::query()->findOrFail($districtId);
            $district->navigation_preferences = array_map(fn (array $group) => [...$group, 'label' => trim($group['label'])], $validated['groups']);
            $district->save();
            DB::table('audit_logs')->insert([
                'user_id' => $request->user()->id, 'district_id' => $districtId,
                'event' => 'navigation.updated', 'auditable_type' => 'district', 'auditable_id' => $districtId,
                'ip_address' => $request->ip(), 'context' => json_encode(['categories' => count($validated['groups'])], JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
        });

        return $this->show($request, $navigation);
    }
}
