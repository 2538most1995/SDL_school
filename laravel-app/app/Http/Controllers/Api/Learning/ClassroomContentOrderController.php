<?php

namespace App\Http\Controllers\Api\Learning;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\Learning\OnlineClassroomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ClassroomContentOrderController extends Controller
{
    public function __invoke(Request $request, int $activity, OnlineClassroomService $classroom): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['teacher', 'admin', 'super_admin'], true), 403);
        abort_unless(config('system_data.write_enabled'), 503, 'ระบบเขียนข้อมูลยังไม่เปิดใช้งาน');
        $classroom->ready();
        abort_unless(Schema::hasColumn('learning_classroom_activities', 'sort_order'), 503, 'กรุณาให้ผู้ดูแลรัน php artisan migrate --force');
        $values = $request->validate([
            'placement' => ['required', Rule::in(['before', 'after', 'first'])],
            'anchor_id' => ['required_unless:placement,first', 'nullable', 'integer', 'min:1'],
        ]);
        $viewer = $request->user();
        $district = (int) $request->attributes->get('district_id');
        $catalog = $classroom->catalog($viewer, $district);
        DB::transaction(function () use ($classroom, $viewer, $district, $catalog, $activity, $values): void {
            $row = $classroom->authorizedActivity($viewer, $district, $activity, true, $catalog);
            $query = DB::table('learning_classroom_activities')->where('district_id', $district)
                ->where('academic_term', $row->academic_term)->where('subject_code', $row->subject_code)
                ->where('education_level', $row->education_level)->where('classroom_unit_id', $row->classroom_unit_id)
                ->when($viewer->role === 'teacher', fn ($part) => $part->where('created_by', $viewer->id));
            // Legacy content can contain unrelated groups. Keep their ordering independent.
            if ($row->classroom_unit_id === null) {
                $query->where('target_group', $row->target_group);
            }
            $ids = $query->orderByRaw('COALESCE(sort_order, 2147483647)')->orderByDesc('id')
                ->lockForUpdate()->get(['id', 'audience_groups'])->filter(function ($candidate) use ($row): bool {
                    $expected = $row->audience_groups === null ? null : json_decode($row->audience_groups, true);
                    $actual = $candidate->audience_groups === null ? null : json_decode($candidate->audience_groups, true);
                    if (is_array($expected) && is_array($actual)) {
                        sort($expected);
                        sort($actual);
                    }

                    return $expected === $actual;
                })->pluck('id')->map(fn ($id) => (int) $id)->all();
            abort_unless(in_array($activity, $ids, true), 409, 'เนื้อหาถูกเปลี่ยนระหว่างจัดลำดับ กรุณาโหลดใหม่');
            $ordered = array_values(array_diff($ids, [$activity]));
            $index = 0;
            if ($values['placement'] !== 'first') {
                $anchor = (int) $values['anchor_id'];
                $classroom->authorizedActivity($viewer, $district, $anchor, true, $catalog);
                $index = array_search($anchor, $ordered, true);
                if ($index === false) {
                    throw ValidationException::withMessages(['anchor_id' => 'เลือกเนื้อหาภายในบทเรียนและกลุ่มเดียวกัน']);
                }
                $index += $values['placement'] === 'after' ? 1 : 0;
            }
            array_splice($ordered, $index, 0, [$activity]);
            foreach (array_chunk($ordered, 500, true) as $chunk) {
                $cases = [];
                $bindings = [];
                foreach ($chunk as $position => $id) {
                    $cases[] = 'WHEN ? THEN ?';
                    array_push($bindings, $id, $position);
                }
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                DB::update('UPDATE learning_classroom_activities SET sort_order = CASE id '.implode(' ', $cases).' END WHERE district_id = ? AND id IN ('.$placeholders.')', [...$bindings, $district, ...array_values($chunk)]);
            }
        }, 3);
        AuditService::logFromRequest($request, 'learning.classroom.reordered', 'learning_classroom_activity', $activity);

        return response()->json(['data' => ['saved' => true]]);
    }
}
