<?php

namespace App\Services\Learning;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class ClassroomTaskService
{
    public function __construct(private OnlineClassroomService $classroom) {}

    public function ready(): void
    {
        $this->classroom->ready();
        foreach (['learning_classroom_tasks', 'learning_classroom_task_items', 'learning_classroom_completions'] as $table) {
            abort_unless(Schema::hasTable($table), 503, 'การสั่งงานยังไม่พร้อม กรุณาให้ผู้ดูแลรัน php artisan migrate --force');
        }
    }

    private function course(User $viewer, int $districtId, array $values): array
    {
        $catalog = $this->classroom->catalog($viewer, $districtId);
        $subject = collect($catalog['subjects'])->first(fn ($row) => $row['code'] === $values['subject_code'] && $row['level'] === (int) $values['education_level']);
        abort_if($subject === null, 404);
        $catalog['registrations'] = array_values(array_filter($catalog['registrations'], fn ($row) => $row['subject_code'] === $subject['code'] && (int) $row['level'] === $subject['level']));

        return [$catalog, $subject];
    }

    private function audience(array $catalog, object $row): array
    {
        $groups = json_decode($row->audience_groups, true) ?? [];

        return array_values(array_filter($catalog['registrations'], fn ($student) => $student['subject_code'] === $row->subject_code
            && (int) $student['level'] === (int) $row->education_level
            && in_array($student['group_code'], $groups, true)
            && ($row->target_group === '' || in_array($row->target_group, [$student['group_code'], $student['group_name']], true))));
    }

    /** Options use the current registered roster, never registration snapshots as access authority. */
    public function options(User $viewer, int $districtId, array $values, bool $lock = false): array
    {
        abort_unless(in_array($viewer->role, ['teacher', 'admin', 'super_admin'], true), 403);
        [$catalog, $subject] = $this->course($viewer, $districtId, $values);
        $group = trim((string) ($values['target_group'] ?? ''));
        $students = array_values(array_filter($catalog['registrations'], fn ($row) => $group === '' || in_array($group, [$row['group_code'], $row['group_name']], true)));
        abort_if($students === [], 422, 'กลุ่มเรียนอยู่นอกขอบเขตรายวิชา');
        $activities = DB::table('learning_classroom_activities')->where('district_id', $districtId)
            ->where('academic_term', $catalog['term'])->where('subject_code', $subject['code'])->where('education_level', $subject['level'])
            ->where('status', 'published')->when($viewer->role === 'teacher', fn ($query) => $query->where('created_by', $viewer->id))
            ->orderByDesc('id')->limit(501)->when($lock, fn ($query) => $query->lockForUpdate())->get(['id', 'title', 'type', 'target_group', 'audience_groups']);
        $items = [];
        foreach ($activities as $activity) {
            $audienceGroups = $activity->audience_groups === null ? null : json_decode($activity->audience_groups, true);
            $eligible = array_filter($students, fn ($row) => ($activity->target_group === '' || in_array($activity->target_group, [$row['group_code'], $row['group_name']], true))
                && ($audienceGroups === null || in_array($row['group_code'], $audienceGroups, true)));
            if (count($eligible) === count($students)) {
                $items[] = ['id' => (string) $activity->id, 'kind' => 'activity', 'title' => $activity->title, 'type' => $activity->type, 'target_group' => $activity->target_group];
            }
        }
        $assignments = DB::table('learning_assignments')->where('district_id', $districtId)->where('subject_code', $subject['code'])
            ->where(fn ($query) => $query->where('academic_term', $catalog['term'])->orWhereNull('academic_term'))
            ->where(fn ($query) => $query->where('education_level', $subject['level'])->orWhereNull('education_level'))
            ->where('status', 'open')->when($viewer->role === 'teacher', fn ($query) => $query->where('created_by', $viewer->id))
            ->orderByDesc('id')->limit(501)->when($lock, fn ($query) => $query->lockForUpdate())->get(['id', 'title', 'target_value']);
        foreach ($assignments as $assignment) {
            $target = trim((string) $assignment->target_value);
            if (count(array_filter($students, fn ($row) => $target === '' || in_array($target, [$row['group_code'], $row['group_name']], true))) === count($students)) {
                $items[] = ['id' => (string) $assignment->id, 'kind' => 'assignment', 'title' => $assignment->title, 'type' => 'worksheet', 'target_group' => $target];
            }
        }
        // Do not silently issue an incomplete "all" task after truncation.
        abort_if($activities->count() > 500 || $assignments->count() > 500 || count($items) > 200, 422, 'มีกิจกรรมมากเกินไป กรุณาแบ่งกลุ่มหรือจัดการกิจกรรมให้ไม่เกิน 200 รายการ');

        return ['items' => $items, 'groups' => $subject['groups']];
    }

    public function create(User $viewer, int $districtId, array $values): int
    {
        abort_unless(in_array($viewer->role, ['teacher', 'admin', 'super_admin'], true), 403);
        [$catalog, $subject] = $this->course($viewer, $districtId, $values);

        return DB::transaction(function () use ($viewer, $districtId, $values, $catalog, $subject): int {
            // Current locking reads also avoid stale revalidation under MySQL REPEATABLE READ.
            $options = $this->options($viewer, $districtId, $values, true)['items'];
            $wanted = [...array_map(fn ($id) => 'activity:'.$id, $values['activity_ids'] ?? []), ...array_map(fn ($id) => 'assignment:'.$id, $values['assignment_ids'] ?? [])];
            $items = $values['selection_mode'] === 'all' ? $options : array_values(array_filter($options, fn ($item) => in_array($item['kind'].':'.$item['id'], $wanted, true)));
            if ($items === [] || ($values['selection_mode'] === 'selected' && count($items) !== count($wanted))) {
                throw ValidationException::withMessages(['activity_ids' => 'เลือกกิจกรรมที่เผยแพร่แล้วและผู้เรียนทุกคนในกลุ่มเข้าถึงได้อย่างน้อยหนึ่งรายการ']);
            }
            $fileIds = array_column(array_filter($items, fn ($row) => $row['kind'] === 'assignment'), 'id');
            if (DB::table('learning_assignments')->whereIn('id', $fileIds)->where('opens_at', '>', Carbon::parse($values['due_at'])->setTimezone(config('app.timezone'))->toDateTimeString())->lockForUpdate()->first(['id']) !== null) {
                throw ValidationException::withMessages(['due_at' => 'กำหนดส่งต้องอยู่หลังเวลาเปิดรับใบงานที่เลือก']);
            }

            $taskId = DB::table('learning_classroom_tasks')->insertGetId(['district_id' => $districtId, 'created_by' => $viewer->id,
                'academic_term' => $catalog['term'], 'subject_code' => $subject['code'], 'subject_name' => $subject['name'], 'education_level' => $subject['level'],
                'target_group' => trim((string) ($values['target_group'] ?? '')), 'audience_groups' => json_encode(array_column($subject['groups'], 'code')),
                'title' => trim($values['title']), 'instructions' => $values['instructions'] ?? '', 'selection_mode' => $values['selection_mode'],
                'items' => json_encode($items, JSON_UNESCAPED_UNICODE), 'due_at' => Carbon::parse($values['due_at'])->setTimezone(config('app.timezone'))->toDateTimeString(),
                'created_at' => now(), 'updated_at' => now()]);
            DB::table('learning_classroom_task_items')->insert(array_map(fn ($item) => ['task_id' => $taskId, 'kind' => $item['kind'], 'content_id' => (int) $item['id']], $items));

            return $taskId;
        });
    }

    public function listing(User $viewer, int $districtId, array $filters): array
    {
        $catalog = empty($filters['subject_code']) ? $this->classroom->catalog($viewer, $districtId) : $this->course($viewer, $districtId, $filters)[0];
        $pairs = collect($catalog['registrations'])->unique(fn ($row) => $row['subject_code'].'|'.$row['level'].'|'.$row['group_code'].'|'.$row['group_name']);
        $page = DB::table('learning_classroom_tasks')->when($pairs->isEmpty(), fn ($query) => $query->whereRaw('1 = 0'))->where('district_id', $districtId)->where('academic_term', $catalog['term'])
            ->when(! empty($filters['subject_code']), fn ($query) => $query->where('subject_code', $filters['subject_code'])->where('education_level', $filters['education_level']))
            ->when($viewer->role === 'teacher', fn ($query) => $query->where('created_by', $viewer->id))
            ->where(function ($query) use ($pairs): void {
                foreach ($pairs as $pair) {
                    $query->orWhere(fn ($part) => $part->where('subject_code', $pair['subject_code'])->where('education_level', $pair['level'])->whereIn('target_group', ['', $pair['group_code'], $pair['group_name']])->whereJsonContains('audience_groups', $pair['group_code']));
                }
            })->orderBy('due_at')->orderByDesc('id')->paginate(50, ['*'], 'page', $filters['page'] ?? 1);
        $rows = $page->items();
        $evidence = $this->evidence($rows, array_column($catalog['registrations'], 'student_code'));

        return ['tasks' => array_map(fn ($row) => $this->payload($viewer, $catalog, $row, $evidence), $rows), 'pagination' => $this->pagination($page)];
    }

    private function authorized(User $viewer, int $districtId, int $id): array
    {
        $row = DB::table('learning_classroom_tasks')->where('district_id', $districtId)->where('id', $id)->first();
        abort_if($row === null, 404);
        abort_if($viewer->role === 'teacher' && (int) $row->created_by !== (int) $viewer->id, 403);
        [$catalog] = $this->course($viewer, $districtId, ['subject_code' => $row->subject_code, 'education_level' => $row->education_level]);
        abort_unless($row->academic_term === $catalog['term'] && $this->audience($catalog, $row) !== [], 404);

        return [$row, $catalog];
    }

    public function detail(User $viewer, int $districtId, int $id, int $page, string $status = 'all'): array
    {
        [$row, $catalog] = $this->authorized($viewer, $districtId, $id);
        $audience = $this->audience($catalog, $row);
        $evidence = $this->evidence([$row], array_column($audience, 'student_code'));
        $payload = $this->payload($viewer, $catalog, $row, $evidence);
        $ownCode = (string) ($viewer->student_code ?: $viewer->username);
        $payload['items'] = array_map(fn ($item) => [...$item, 'completed' => $viewer->role === 'student' ? isset($evidence[$id][$ownCode][$item['kind'].':'.$item['id']]) : null], json_decode($row->items, true));
        $students = $viewer->role === 'student' ? [] : array_map(fn ($student) => [...$student, ...$this->progress($row, $evidence[$id][$student['student_code']] ?? [])], $audience);
        $students = array_values(array_filter($students, fn ($student) => $status === 'all' || $student['status'] === $status));
        $payload['students'] = array_slice($students, ($page - 1) * 50, 50);
        $payload['pagination'] = $viewer->role === 'student' ? null : ['current_page' => $page, 'last_page' => max(1, (int) ceil(count($students) / 50)), 'total' => count($students)];

        return $payload;
    }

    public function complete(User $viewer, int $districtId, int $taskId, int $activityId): void
    {
        abort_unless($viewer->role === 'student', 403);
        [$row] = $this->authorized($viewer, $districtId, $taskId);
        $item = collect(json_decode($row->items, true))->first(fn ($item) => $item['kind'] === 'activity' && (int) $item['id'] === $activityId);
        abort_unless($item !== null && in_array($item['type'], ['knowledge', 'video'], true), 422, 'กิจกรรมนี้ต้องส่งคำตอบผ่านหน้ากิจกรรม');
        $this->classroom->authorizedActivity($viewer, $districtId, $activityId);
        DB::table('learning_classroom_completions')->insertOrIgnore(['task_id' => $taskId, 'activity_id' => $activityId,
            'student_code' => (string) ($viewer->student_code ?: $viewer->username), 'completed_at' => now()]);
    }

    /** Batched evidence queries select only identities and timestamps, never answers or attachments. */
    private function evidence(array $tasks, array $studentCodes): array
    {
        $activityIds = [];
        $assignmentIds = [];
        foreach ($tasks as $task) {
            foreach (json_decode($task->items, true) as $item) {
                if ($item['kind'] === 'activity') {
                    $activityIds[] = $item['id'];
                } else {
                    $assignmentIds[] = $item['id'];
                }
            }
        }
        $online = DB::table('learning_classroom_submissions')->whereIn('activity_id', array_unique($activityIds))->whereIn('student_code', $studentCodes)
            ->whereIn('status', ['submitted', 'reviewed'])->get(['activity_id', 'student_code', 'submitted_at']);
        $files = DB::table('learning_submissions')->whereIn('assignment_id', array_unique($assignmentIds))->whereIn('student_code', $studentCodes)
            ->whereIn('status', ['submitted', 'reviewed'])->get(['assignment_id', 'student_code', 'submitted_at']);
        $reads = DB::table('learning_classroom_completions')->whereIn('task_id', array_column($tasks, 'id'))->whereIn('student_code', $studentCodes)
            ->get(['task_id', 'activity_id', 'student_code', 'completed_at']);
        $source = [];
        foreach ($online as $record) {
            $source['activity:'.$record->activity_id][$record->student_code] = $record->submitted_at;
        }
        foreach ($files as $record) {
            $source['assignment:'.$record->assignment_id][$record->student_code] = $record->submitted_at;
        }
        $result = [];
        foreach ($tasks as $task) {
            foreach (json_decode($task->items, true) as $item) {
                $key = $item['kind'].':'.$item['id'];
                foreach ($source[$key] ?? [] as $code => $timestamp) {
                    $result[$task->id][$code][$key] = $timestamp;
                }
            }
        }
        foreach ($reads as $record) {
            $result[$record->task_id][$record->student_code]['activity:'.$record->activity_id] = $record->completed_at;
        }

        return $result;
    }

    private function progress(object $task, array $evidence): array
    {
        $total = count(json_decode($task->items, true));
        $completed = count($evidence);
        $status = $completed === $total ? 'completed' : ($completed === 0 ? 'pending' : 'in_progress');
        $latest = $evidence === [] ? null : max(array_values($evidence));

        return ['completed' => $completed, 'total' => $total, 'status' => $status,
            'overdue' => $status !== 'completed' && now()->gt($task->due_at), 'late' => $latest !== null && Carbon::parse($latest)->gt($task->due_at)];
    }

    private function payload(User $viewer, array $catalog, object $row, array $evidence): array
    {
        $audience = $this->audience($catalog, $row);
        $completed = 0;
        foreach ($audience as $student) {
            if ($this->progress($row, $evidence[$row->id][$student['student_code']] ?? [])['status'] === 'completed') {
                $completed++;
            }
        }

        return ['id' => (string) $row->id, 'title' => $row->title, 'instructions' => $row->instructions ?? '', 'subject_code' => $row->subject_code,
            'subject_name' => $row->subject_name, 'education_level' => (int) $row->education_level, 'target_group' => $row->target_group,
            'selection_mode' => $row->selection_mode, 'due_at' => Carbon::parse($row->due_at)->toIso8601String(), 'item_count' => count(json_decode($row->items, true)),
            'student_count' => count($audience), 'completed_count' => $completed,
            'progress' => $viewer->role === 'student' ? $this->progress($row, $evidence[$row->id][(string) ($viewer->student_code ?: $viewer->username)] ?? []) : null];
    }

    public static function assertContentEditable(string $kind, int $id): void
    {
        if (Schema::hasTable('learning_classroom_task_items') && DB::table('learning_classroom_task_items')->where('kind', $kind)->where('content_id', $id)->exists()) {
            throw ValidationException::withMessages(['activity' => 'กิจกรรมนี้อยู่ในงานที่สั่งแล้ว กรุณาสร้างกิจกรรมใหม่เพื่อรักษางานและผลการทำเดิม']);
        }
    }

    public function activityDeadline(User $viewer, int $districtId, object $activity, array $catalog): ?string
    {
        if (! Schema::hasTable('learning_classroom_task_items')) {
            return $activity->due_at;
        }
        $student = collect($catalog['registrations'])->first(fn ($row) => $row['subject_code'] === $activity->subject_code && (int) $row['level'] === (int) $activity->education_level);
        if ($student === null) {
            return $activity->due_at;
        }
        $due = DB::table('learning_classroom_tasks')->where('district_id', $districtId)->where('academic_term', $catalog['term'])
            ->where('subject_code', $activity->subject_code)->where('education_level', $activity->education_level)
            ->whereIn('target_group', ['', $student['group_code'], $student['group_name']])->whereJsonContains('audience_groups', $student['group_code'])
            ->whereIn('id', DB::table('learning_classroom_task_items')->select('task_id')->where('kind', 'activity')->where('content_id', $activity->id))->max('due_at');

        return $due ?? $activity->due_at;
    }

    private function pagination($page): array
    {
        return ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()];
    }
}
