<?php

namespace App\Http\Middleware;

use App\Models\District;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveDistrictContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user, 401);

        $requestedDistrict = $request->header('X-District-Id');

        if ($user->role === 'super_admin') {
            abort_if(blank($requestedDistrict), 422, 'กรุณาเลือกอำเภอก่อนเรียกดูข้อมูล');
            $districtId = filter_var($requestedDistrict, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            abort_unless($districtId, 422, 'รหัสอำเภอไม่ถูกต้อง');
        } else {
            abort_unless($user->district_id, 403, 'บัญชีนี้ยังไม่ได้กำหนดอำเภอ');
            $districtId = (int) $user->district_id;

            if (filled($requestedDistrict) && (int) $requestedDistrict !== $districtId) {
                abort(403, 'ไม่สามารถเข้าถึงข้อมูลของอำเภออื่นได้');
            }
        }

        $isActive = District::query()
            ->whereKey($districtId)
            ->where('is_active', true)
            ->exists();

        abort_unless($isActive, 404, 'ไม่พบอำเภอที่เปิดใช้งาน');

        $request->attributes->set('district_id', (int) $districtId);
        $user->setRelation('selectedDistrictContext', (int) $districtId);

        return $next($request);
    }
}
