<?php

namespace App\Services\Learning;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ClassroomLegacyDeleteService
{
    public function delete(User $viewer, int $districtId, string $code, int $level): array
    {
        abort_unless(in_array($viewer->role, ['teacher', 'admin', 'super_admin'], true), 403);
        abort_unless(config('system_data.write_enabled'), 503, 'ระบบเขียนข้อมูลยังไม่เปิดใช้งาน');
        app(ClassroomUnitService::class)->ready();
        $catalog = app(OnlineClassroomService::class)->catalog($viewer, $districtId);
        $subject = collect($catalog['subjects'])->first(fn ($item) => $item['code'] === $code && (int) $item['level'] === $level);
        abort_if($subject === null, 404);
        $codes = array_column($subject['groups'], 'code');
        $aliases = array_values(array_filter(array_unique([...$codes, ...array_column($subject['groups'], 'name')]), static fn ($value) => $value !== ''));

        return DB::transaction(function () use ($viewer, $districtId, $code, $level, $catalog, $codes, $aliases): array {
            $selected = [];
            foreach (['activities' => ['learning_classroom_activities', 'target_group', 'created_by'],
                'assignments' => ['learning_assignments', 'target_value', 'created_by'],
                'resources' => ['learning_resources', 'target_group', 'uploaded_by']] as $kind => [$table, $target, $owner]) {
                $query = DB::table($table)->where('district_id', $districtId)->whereNull('classroom_unit_id')
                    ->where('subject_code', $code)->where('education_level', $level)
                    ->where(fn ($part) => $part->whereIn($target, $aliases)->orWhere($target, '')->orWhereNull($target));
                if ($kind !== 'resources') {
                    $query->where(fn ($part) => $part->where('academic_term', $catalog['term'])->orWhereNull('academic_term'));
                }
                if ($viewer->role === 'teacher') {
                    $query->where($owner, $viewer->id);
                    if ($kind === 'assignments') {
                        $query->where('target_type', 'group')->whereIn($target, $aliases);
                    } elseif ($kind === 'resources') {
                        $query->whereIn($target, $aliases);
                    }
                }
                $rows = $query->orderBy('id')->limit(501)->lockForUpdate()->get();
                abort_if($rows->count() > 500, 422, 'มีเนื้อหาเดิมเกิน 500 รายการ กรุณาจัดการแยกเป็นรายการก่อน');
                if ($kind === 'activities') {
                    $rows = $rows->filter(function ($row) use ($viewer, $codes): bool {
                        $audience = $row->audience_groups === null ? null : json_decode($row->audience_groups, true);
                        if ($audience !== null && array_intersect($audience, $codes) === []) {
                            return false;
                        }

                        return $viewer->role !== 'teacher' || trim((string) $row->target_group) !== '' || ($audience !== null && array_diff($audience, $codes) === []);
                    });
                }
                $selected[$kind] = $rows;
            }
            $activityIds = $selected['activities']->pluck('id')->all();
            $assignmentIds = $selected['assignments']->pluck('id')->all();
            $protected = DB::table('learning_classroom_submissions')->whereIn('activity_id', $activityIds)->exists()
                || DB::table('learning_submissions')->whereIn('assignment_id', $assignmentIds)->exists()
                || DB::table('learning_classroom_task_items')->where(fn ($part) => $part
                    ->where(fn ($scope) => $scope->where('kind', 'activity')->whereIn('content_id', $activityIds))
                    ->orWhere(fn ($scope) => $scope->where('kind', 'assignment')->whereIn('content_id', $assignmentIds)))->exists();
            if ($protected) {
                throw ValidationException::withMessages(['legacy' => 'ลบเนื้อหาเดิมไม่ได้ เนื่องจากมีงานที่มอบหมาย คำตอบ หรือคะแนนของนักศึกษา กรุณาจัดการแต่ละรายการเพื่อรักษาประวัติ']);
            }
            $counts = array_map(fn ($rows) => $rows->count(), $selected);
            if (array_sum($counts) === 0) {
                throw ValidationException::withMessages(['legacy' => 'ไม่มีเนื้อหาเดิมที่ลบได้ในขอบเขตของคุณ สื่อที่ใช้ร่วมกันทุกวิชาต้องจัดการในเมนูสื่อเพิ่มเติม']);
            }
            $files = [];
            $images = DB::table('learning_classroom_images')->where('district_id', $districtId)->whereIn('activity_id', $activityIds)->lockForUpdate()->get();
            foreach ($images as $image) {
                if (str_starts_with($image->path, "classroom/{$districtId}/") || str_starts_with($image->path, "classroom-restored/{$districtId}/")) {
                    $files[] = $image->path;
                }
            }
            foreach ($selected['assignments'] as $row) {
                if (($row->material_disk ?? 'local') === 'local' && str_starts_with((string) ($row->material_path ?? ''), "learning/assignments/{$districtId}/{$row->id}/")) {
                    $files[] = $row->material_path;
                }
            }
            foreach ($selected['resources'] as $row) {
                if ($row->storage_disk === 'local' && str_starts_with((string) $row->storage_path, "learning/resources/{$districtId}/{$row->id}/")) {
                    $files[] = $row->storage_path;
                }
            }
            DB::table('learning_classroom_images')->where('district_id', $districtId)->whereIn('activity_id', $activityIds)->delete();
            DB::table('learning_classroom_activities')->where('district_id', $districtId)->whereIn('id', $activityIds)->delete();
            DB::table('learning_assignments')->where('district_id', $districtId)->whereIn('id', $assignmentIds)->delete();
            DB::table('learning_resources')->where('district_id', $districtId)->whereIn('id', $selected['resources']->pluck('id')->all())->delete();
            DB::afterCommit(function () use ($files): void {
                try {
                    $referenced = array_merge(
                        DB::table('learning_classroom_images')->whereIn('path', $files)->pluck('path')->all(),
                        DB::table('learning_assignments')->whereIn('material_path', $files)->pluck('material_path')->all(),
                        DB::table('learning_resources')->whereIn('storage_path', $files)->pluck('storage_path')->all(),
                    );
                    Storage::disk('local')->delete(array_values(array_diff(array_unique($files), $referenced)));
                } catch (Throwable $exception) {
                    report($exception);
                }
            });

            return ['deleted' => true, 'counts' => $counts];
        }, 3);
    }
}
