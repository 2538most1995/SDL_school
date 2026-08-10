<?php

namespace App\Services\Legacy;

use App\Domain\Students\Support\AcademicTerm;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class LegacyPortalReadService
{
    public function __construct(private readonly DatabaseManager $database) {}

    /** @return list<array<string, mixed>> */
    public function assignments(User $viewer, int $districtId, ?string $status = null, ?string $search = null): array
    {
        $query = $this->connection()->table('learning_assignments as a')
            ->leftJoin('users as creator', 'creator.id', '=', 'a.created_by')
            ->selectRaw("a.*, COALESCE(creator.name, '') AS teacher_name")
            ->where('a.district_id', $districtId);

        $this->scopeTargetedContent($query, $viewer, 'a');

        if ($viewer->role === 'student') {
            $studentCode = (string) ($viewer->student_code ?: $viewer->username);
            $studentId = $this->connection()->table('students')
                ->where('district_id', $districtId)
                ->where('student_code', $studentCode)
                ->value('id');
            if ($studentId !== null) {
                $query->leftJoin('learning_submissions as submission', function ($join) use ($studentId): void {
                    $join->on('submission.assignment_id', '=', 'a.id')
                        ->where('submission.student_id', '=', (int) $studentId);
                })->addSelect([
                    'submission.status as submission_state',
                    'submission.score as submission_score',
                ]);
            }
        }

        if ($search !== null && trim($search) !== '') {
            $needle = '%'.trim($search).'%';
            $query->where(fn (Builder $part) => $part
                ->where('a.title', 'like', $needle)
                ->orWhere('a.subject_code', 'like', $needle));
        }

        $items = $query->orderBy('a.due_at')->limit(250)->get()->map(function (object $row) use ($viewer): array {
            $submission = (string) ($row->submission_state ?? '');
            $itemStatus = match (true) {
                $submission === 'reviewed' => 'completed',
                $submission === 'submitted' => 'in_progress',
                (string) $row->status === 'closed' => 'completed',
                default => 'pending',
            };

            return [
                'id' => (string) $row->id,
                'subject_code' => (string) ($row->subject_code ?? ''),
                'subject_name' => (string) ($row->subject_code ?? ''),
                'title' => (string) $row->title,
                'teacher_name' => trim((string) $row->teacher_name) ?: 'ครูผู้สอน',
                'due_at' => $row->due_at,
                'status' => $itemStatus,
                'submission_status' => match ($submission) {
                    'reviewed' => 'graded',
                    'submitted' => 'submitted',
                    default => $viewer->role === 'student' ? 'not_submitted' : $itemStatus,
                },
                'progress_percent' => $submission === 'reviewed' ? 100 : ($submission === 'submitted' ? 80 : 0),
                'points' => (float) ($row->max_score ?? 0),
                'score' => isset($row->submission_score) ? (float) $row->submission_score : null,
                'accent' => 'violet',
                'can_edit' => $viewer->role !== 'teacher' || (int) $row->created_by === (int) $viewer->id,
                'raw' => [
                    'title' => (string) $row->title, 'subject' => (string) ($row->subject_code ?? ''),
                    'description' => (string) ($row->instructions ?? ''), 'due_at' => $row->due_at,
                    'target_group' => (string) ($row->target_value ?? ''), 'target_mode' => (string) $row->target_type,
                    'status' => (string) $row->status,
                ],
            ];
        })->all();

        return $status === null
            ? $items
            : array_values(array_filter($items, static fn (array $item): bool => $item['status'] === $status));
    }

    /** @return list<array<string, mixed>> */
    public function resources(User $viewer, int $districtId, ?string $category = null, ?string $search = null): array
    {
        $query = $this->connection()->table('learning_resources as resource')
            ->where('resource.district_id', $districtId);
        $this->scopeGroupContent($query, $viewer, 'resource', 'target_group', 'uploaded_by');

        if ($search !== null && trim($search) !== '') {
            $needle = '%'.trim($search).'%';
            $query->where(fn (Builder $part) => $part
                ->where('resource.title', 'like', $needle)
                ->orWhere('resource.subject_code', 'like', $needle)
                ->orWhere('resource.description', 'like', $needle));
        }

        $items = $query->orderByDesc('resource.created_at')->limit(250)->get()->map(function (object $row) use ($viewer): array {
            $mappedCategory = match ((string) $row->resource_type) {
                'video', 'youtube' => 'วิดีโอ',
                'exercise' => 'แบบฝึกหัด',
                default => 'คู่มือ',
            };

            return [
                'id' => (string) $row->id,
                'title' => (string) $row->title,
                'description' => (string) ($row->description ?? ''),
                'category' => $mappedCategory,
                'type' => (string) $row->resource_type,
                'subject_code' => (string) ($row->subject_code ?? ''),
                'duration_minutes' => null,
                'size_label' => null,
                'published_at' => $row->created_at,
                'is_downloadable' => in_array($row->resource_type, ['pdf', 'exercise'], true),
                'accent' => 'sky',
                'can_edit' => $viewer->role !== 'teacher' || (int) $row->uploaded_by === (int) $viewer->id,
                'raw' => [
                    'title' => (string) $row->title, 'subject' => (string) ($row->subject_code ?? ''),
                    'description' => (string) ($row->description ?? ''), 'resource_type' => (string) $row->resource_type,
                    'url' => (string) ($row->external_url ?? ''), 'level' => (string) ($row->education_level ?? ''),
                    'target_group' => (string) ($row->target_group ?? ''),
                ],
            ];
        })->all();

        return $category === null
            ? $items
            : array_values(array_filter($items, static fn (array $item): bool => $item['category'] === $category));
    }

    /** @return list<array<string, mixed>> */
    public function calendar(User $viewer, int $districtId, ?string $type = null): array
    {
        return $this->calendarItems($viewer, $districtId, null, $type);
    }

    /**
     * Build calendar data, optionally reusing assignments already loaded by a
     * caller such as overview(). This keeps the public response unchanged while
     * avoiding a duplicate assignment query in the same request.
     *
     * @param  list<array<string, mixed>>|null  $assignments
     * @return list<array<string, mixed>>
     */
    private function calendarItems(User $viewer, int $districtId, ?array $assignments = null, ?string $type = null): array
    {
        $events = $this->connection()->table('learning_calendar_events as event')
            ->where('event.district_id', $districtId);
        $this->scopeTargetedContent($events, $viewer, 'event');

        $items = $events->orderBy('event.starts_at')->limit(250)->get()
            ->map(function (object $row) use ($viewer): array {
                $startsAt = Carbon::parse($row->starts_at);
                $endsAt = filled($row->ends_at ?? null) ? Carbon::parse($row->ends_at) : $startsAt;

                return [
                    'id' => 'event-'.(int) $row->id,
                    'type' => (string) ($row->event_type ?? 'meeting'),
                    'title' => (string) $row->title,
                    'starts_at' => (string) $row->starts_at,
                    'ends_at' => (string) ($row->ends_at ?? $row->starts_at),
                    'location' => (string) ($row->location ?? ''),
                    'subject_code' => null,
                    'accent' => 'sky',
                    'can_edit' => $viewer->role !== 'teacher' || (int) $row->created_by === (int) $viewer->id,
                    'raw' => [
                        'title' => (string) $row->title, 'event_date' => $startsAt->format('Y-m-d'),
                        'start_time' => $startsAt->format('H:i'), 'end_time' => $endsAt->format('H:i'),
                        'location' => (string) ($row->location ?? ''), 'target_group' => (string) ($row->target_value ?? ''),
                        'notes' => (string) ($row->description ?? ''),
                    ],
                ];
            })->all();

        foreach ($assignments ?? $this->assignments($viewer, $districtId) as $assignment) {
            $items[] = [
                'id' => 'assignment-'.$assignment['id'],
                'type' => 'assignment',
                'title' => $assignment['title'],
                'starts_at' => $assignment['due_at'],
                'ends_at' => $assignment['due_at'],
                'location' => 'ส่งผ่านระบบ',
                'subject_code' => $assignment['subject_code'],
                'accent' => 'violet',
            ];
        }

        usort($items, static fn (array $left, array $right): int => strcmp((string) $left['starts_at'], (string) $right['starts_at']));

        return $type === null
            ? $items
            : array_values(array_filter($items, static fn (array $item): bool => $item['type'] === $type));
    }

    /** @return array<string, mixed> */
    public function scores(User $viewer, int $districtId): array
    {
        $query = $this->connection()->table('learning_submissions as submission')
            ->join('learning_assignments as assignment', 'assignment.id', '=', 'submission.assignment_id')
            ->join('students as student', 'student.id', '=', 'submission.student_id')
            ->where('assignment.district_id', $districtId)
            ->select([
                'submission.id', 'submission.score', 'submission.status', 'submission.created_at',
                'assignment.subject_code', 'assignment.title', 'assignment.max_score',
            ]);

        if ($viewer->role === 'student') {
            $query->where('student.student_code', (string) ($viewer->student_code ?: $viewer->username));
        } elseif ($viewer->role === 'teacher') {
            $query->where('assignment.created_by', (int) $viewer->id);
        }

        $rows = $query->whereIn('submission.status', ['submitted', 'reviewed'])
            ->orderByDesc('submission.created_at')->limit(250)->get();
        $courses = $rows->map(static fn (object $row): array => [
            'id' => 'score-'.(int) $row->id,
            'subject_code' => (string) ($row->subject_code ?? ''),
            'subject_name' => (string) $row->title,
            'credits' => 0,
            'assignment_score' => (float) $row->score,
            'exam_score' => null,
            'total_score' => (float) $row->score,
            'grade' => null,
            'status' => 'studying',
        ])->all();

        return [
            'term' => null,
            'summary' => [
                'score' => (float) $rows->sum('score'),
                'maximum_score' => (float) $rows->sum('max_score'),
                'items' => $rows->count(),
            ],
            'courses' => $courses,
            'disclaimer' => 'คะแนนเก็บภายในระบบ ยังไม่ใช่ผลการเรียนปลายภาค',
        ];
    }

    /** @return list<array<string, mixed>> */
    public function lessonPlans(User $viewer, int $districtId): array
    {
        $query = $this->connection()->table('learning_lesson_plans')->where('district_id', $districtId);
        if ($viewer->role === 'teacher') {
            $query->where('teacher_id', (int) $viewer->id);
        }

        return $query->orderByDesc('updated_at')->limit(250)->get()->map(fn (object $row): array => [
            'id' => (string) $row->id,
            'title' => (string) $row->title,
            'description' => Str::limit((string) ($row->objectives ?? ''), 180),
            'course' => (string) ($row->subject_code ?? ''),
            'timing' => (string) ($row->academic_term ?? '-'),
            'status' => (string) $row->status === 'published' ? 'เผยแพร่แล้ว' : 'ฉบับร่าง',
            'can_edit' => $viewer->role !== 'teacher' || (int) $row->teacher_id === (int) $viewer->id,
            'raw' => [
                'title' => (string) $row->title, 'subject' => (string) ($row->subject_code ?? ''),
                'level' => (string) ($row->education_level ?? ''), 'semester' => (string) ($row->academic_term ?? ''),
                'objectives' => (string) ($row->objectives ?? ''), 'activities' => (string) ($row->activities ?? ''),
                'assessment' => (string) ($row->assessment ?? ''),
            ],
        ])->all();
    }

    /** @return list<array<string, mixed>> */
    public function schedules(User $viewer, int $districtId): array
    {
        $query = $this->connection()->table('learning_schedules as schedule')
            ->leftJoin('users as teacher', 'teacher.id', '=', 'schedule.teacher_id')
            ->where('schedule.district_id', $districtId)
            ->select('schedule.*', 'teacher.name as teacher_name');
        $this->scopeGroupContent($query, $viewer, 'schedule', 'group_code', 'teacher_id');
        $weekdays = [1 => 'วันจันทร์', 2 => 'วันอังคาร', 3 => 'วันพุธ', 4 => 'วันพฤหัสบดี', 5 => 'วันศุกร์', 6 => 'วันเสาร์', 7 => 'วันอาทิตย์'];

        return $query->orderBy('schedule.starts_at')->limit(250)->get()->map(static function (object $row) use ($weekdays): array {
            $startsAt = Carbon::parse($row->starts_at);
            $endsAt = filled($row->ends_at ?? null) ? Carbon::parse($row->ends_at) : $startsAt;

            return [
                'id' => (string) $row->id,
                'title' => (string) $row->subject_name,
                'description' => (string) ($row->teacher_name ?? ''),
                'course' => $weekdays[$startsAt->dayOfWeekIso] ?? 'ไม่ระบุวัน',
                'timing' => $startsAt->format('H:i').' - '.$endsAt->format('H:i').' น.',
                'status' => (string) ($row->room ?? '-'),
            ];
        })->all();
    }

    /** @return array<string, mixed> */
    public function overview(User $viewer, int $districtId): array
    {
        $assignments = $this->assignments($viewer, $districtId);
        $resources = $this->resources($viewer, $districtId);
        $calendar = array_slice($this->calendarItems($viewer, $districtId, $assignments), 0, 5);
        $subjects = [];
        foreach ([...$assignments, ...$resources] as $item) {
            $subject = trim((string) ($item['subject_name'] ?? $item['subject_code'] ?? ''));
            if ($subject !== '') {
                $subjects[$subject] = true;
            }
        }

        $courses = array_values(array_map(static fn (string $subject, int $index): array => [
            'id' => (string) $index,
            'code' => $subject,
            'title' => $subject,
            'teacher' => 'ข้อมูลจากระบบการเรียนรู้',
            'next' => 'ดูงานและสื่อในรายวิชา',
            'tone' => ['emerald', 'sky', 'amber'][$index % 3],
        ], array_keys($subjects), array_keys(array_keys($subjects))));

        return [
            'studentName' => $viewer->name,
            'dueAssignments' => count(array_filter($assignments, static fn (array $item): bool => $item['status'] !== 'completed')),
            'completedAssignments' => count(array_filter($assignments, static fn (array $item): bool => $item['status'] === 'completed')),
            'resources' => count($resources),
            'courses' => array_slice($courses, 0, 12),
            'upcoming' => array_map(static fn (array $item): array => [
                'id' => (string) $item['id'],
                'date' => (string) $item['starts_at'],
                'title' => (string) $item['title'],
                'meta' => trim((string) ($item['location'] ?? '')),
                'type' => (string) $item['type'],
            ], $calendar),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function users(int $districtId, ?string $role = null, ?string $search = null): array
    {
        $query = $this->connection()->table('users as user')
            ->leftJoin('districts as district', 'district.id', '=', 'user.district_id')
            ->where('user.district_id', $districtId);
        if ($role !== null) {
            $query->where('user.role', $role);
        }
        if ($search !== null && trim($search) !== '') {
            $needle = '%'.trim($search).'%';
            $query->where(fn (Builder $part) => $part
                ->where('user.username', 'like', $needle)
                ->orWhere('user.first_name', 'like', $needle)
                ->orWhere('user.last_name', 'like', $needle));
        }

        return $query->select([
            'user.id', 'user.username', 'user.first_name', 'user.last_name', 'user.role',
            'user.district_id', 'user.assigned_groups', 'user.created_at', 'district.name as district_name',
        ])->orderBy('user.role')->orderBy('user.first_name')->limit(250)->get()->map(function (object $row): array {
            $groups = is_array($row->assigned_groups ?? null)
                ? $row->assigned_groups
                : json_decode((string) ($row->assigned_groups ?? '[]'), true);

            return [
                'id' => (string) $row->id,
                'display_name' => trim((string) $row->first_name.' '.(string) $row->last_name),
                'username' => (string) $row->username,
                'role' => (string) $row->role,
                'district_id' => (int) $row->district_id,
                'district_name' => (string) ($row->district_name ?? ''),
                'group' => is_array($groups) && $groups !== [] ? implode(', ', array_map('strval', $groups)) : null,
                'status' => 'active',
                'last_seen_at' => null,
            ];
        })->all();
    }

    /** @return list<array<string, mixed>> */
    public function imports(int $districtId, ?string $status = null, ?string $search = null): array
    {
        $rows = $this->connection()->select(
            "SELECT ib.import_history_id AS id, ib.batch_key, ib.district_id, d.name AS district_name,
                    ih.file_name, ih.status, COALESCE(ib.created_at, ih.created_at) AS created_at,
                    (SELECT COUNT(*) FROM information_schema.tables t
                     WHERE t.table_schema = DATABASE() AND t.table_name LIKE CONCAT('db_', ib.batch_key, '_%')) AS table_count,
                    (SELECT COALESCE(SUM(t.table_rows), 0) FROM information_schema.tables t
                     WHERE t.table_schema = DATABASE() AND t.table_name LIKE CONCAT('db_', ib.batch_key, '_%')) AS row_count
             FROM import_batches ib
             INNER JOIN import_history ih
                ON ih.id = ib.import_history_id
               AND BINARY ih.batch_key = BINARY ib.batch_key
               AND ih.district_id = ib.district_id
             INNER JOIN districts d ON d.id = ib.district_id
             WHERE ib.district_id = ?
             ORDER BY COALESCE(ib.created_at, ih.created_at) DESC",
            [$districtId],
            true,
        );
        $activeKey = collect($rows)->first(fn (object $row): bool => $row->status === 'success')?->batch_key;
        $items = [];
        foreach ($rows as $row) {
            $itemStatus = $row->status === 'failed' ? 'failed' : ($row->batch_key === $activeKey ? 'active' : 'archived');
            $term = $this->batchAcademicTerm((string) $row->batch_key);
            $items[] = [
                'id' => (int) $row->id,
                'batch_key' => (string) $row->batch_key,
                'district_id' => (int) $row->district_id,
                'district_name' => (string) $row->district_name,
                'academic_term' => $term ?? '-',
                'source_filename' => (string) $row->file_name,
                'status' => $itemStatus,
                'row_count' => (int) $row->row_count,
                'table_count' => (int) $row->table_count,
                'warning_count' => $row->status === 'failed' ? 1 : 0,
                'created_at' => $row->created_at,
                'activated_at' => $row->batch_key === $activeKey ? $row->created_at : null,
                'is_active' => $row->batch_key === $activeKey,
            ];
        }

        return array_values(array_filter($items, static function (array $item) use ($status, $search): bool {
            if ($status !== null && $item['status'] !== $status) {
                return false;
            }

            return $search === null || trim($search) === '' || Str::contains(
                Str::lower(implode(' ', [$item['batch_key'], $item['district_name'], $item['academic_term'], $item['source_filename']])),
                Str::lower(trim($search)),
            );
        }));
    }

    /** @return list<array<string, mixed>> */
    public function examRooms(int $districtId): array
    {
        return $this->connection()->table('exam_rooms')->where('district_id', $districtId)
            ->orderByDesc('term')->orderBy('room_name')->limit(250)->get()->map(static function (object $row): array {
                $start = filter_var($row->start_val, FILTER_VALIDATE_INT);
                $end = filter_var($row->end_val, FILTER_VALIDATE_INT);

                return [
                    'id' => (string) $row->id,
                    'exam_date' => null,
                    'date' => (string) $row->term,
                    'building' => (string) $row->subject_code,
                    'room' => (string) $row->room_name,
                    'seat_capacity' => $start !== false && $end !== false ? max(0, $end - $start + 1) : 0,
                    'capacity' => $start !== false && $end !== false ? max(0, $end - $start + 1) : 0,
                    'status' => 'ready',
                    'range' => trim((string) $row->start_val.' - '.(string) $row->end_val),
                ];
            })->all();
    }

    /** @return array<string, mixed> */
    public function safetyState(int $districtId): array
    {
        $batchCount = $this->connection()->table('import_batches')->where('district_id', $districtId)->count();
        $writeEnabled = (bool) config('system_data.write_enabled');

        return [
            'operations' => [
                ['key' => 'import-write', 'label' => 'นำเข้าข้อมูลใหม่', 'reason' => $writeEnabled ? 'เปิดใช้งานผ่าน staging, validation และการสลับ batch' : 'ปิดการเขียนข้อมูลจริง', 'state' => $writeEnabled ? 'enabled' : 'disabled'],
                ['key' => 'cleanup-write', 'label' => 'ลบหรือ cleanup batch', 'reason' => $writeEnabled ? 'เปิดใช้งานโดยจำกัด batch ตามอำเภอและบันทึก audit log' : 'ปิดไว้เพื่อป้องกันข้อมูลจริงสูญหาย', 'state' => $writeEnabled ? 'enabled' : 'disabled'],
            ],
            'required_controls' => [
                ['key' => 'district-scope', 'label' => "ตรวจขอบเขตอำเภอแล้ว ({$districtId})", 'state' => 'ready'],
                ['key' => 'system-database', 'label' => 'ใช้ฐานข้อมูลภายในระบบเท่านั้น', 'state' => 'ready'],
                ['key' => 'batch-registry', 'label' => "พบ batch ในทะเบียน {$batchCount} รายการ", 'state' => $batchCount > 0 ? 'ready' : 'required'],
                ['key' => 'atomic-activation', 'label' => 'เปิดใช้ชุดใหม่ก่อนลบชุดเดิม', 'state' => $writeEnabled ? 'ready' : 'required'],
            ],
        ];
    }

    private function scopeTargetedContent(Builder $query, User $viewer, string $alias): void
    {
        if ($viewer->role === 'student') {
            $groups = $this->groups($viewer);
            $query->where(function (Builder $scope) use ($alias, $groups): void {
                $scope->where("{$alias}.target_type", 'all');
                if ($groups !== []) {
                    $scope->orWhere(function (Builder $groupScope) use ($alias, $groups): void {
                        $groupScope->where("{$alias}.target_type", 'group')
                            ->whereIn("{$alias}.target_value", $groups);
                    });
                }
            });
            if ($alias === 'a') {
                $query->where("{$alias}.status", 'open');
            }
        } elseif ($viewer->role === 'teacher') {
            $groups = $this->groups($viewer);
            $query->where(function (Builder $scope) use ($alias, $groups, $viewer): void {
                $scope->where("{$alias}.created_by", (int) $viewer->id);
                if ($groups !== []) {
                    $scope->orWhere(function (Builder $groupScope) use ($alias, $groups): void {
                        $groupScope->where("{$alias}.target_type", 'group')
                            ->whereIn("{$alias}.target_value", $groups);
                    });
                }
            });
        }
    }

    private function scopeGroupContent(
        Builder $query,
        User $viewer,
        string $alias,
        string $groupColumn = 'target_group',
        string $actorColumn = 'created_by',
    ): void {
        $groups = $this->groups($viewer);
        if ($viewer->role === 'student') {
            $query->where(function (Builder $scope) use ($alias, $groupColumn, $groups): void {
                $scope->whereNull("{$alias}.{$groupColumn}")->orWhere("{$alias}.{$groupColumn}", '');
                if ($groups !== []) {
                    $scope->orWhereIn("{$alias}.{$groupColumn}", $groups);
                }
            });
        } elseif ($viewer->role === 'teacher') {
            $query->where(function (Builder $scope) use ($actorColumn, $alias, $groupColumn, $groups, $viewer): void {
                $scope->where("{$alias}.{$actorColumn}", (int) $viewer->id);
                if ($groups !== []) {
                    $scope->orWhereIn("{$alias}.{$groupColumn}", $groups);
                }
            });
        }
    }

    /** @return list<string> */
    private function groups(User $viewer): array
    {
        return array_values(array_filter(array_map('strval', $viewer->assigned_groups ?? [])));
    }

    private function batchAcademicTerm(string $batchKey): ?string
    {
        if (preg_match('/^import_\d{10}_[A-Za-z0-9]+$/', $batchKey) !== 1) {
            return null;
        }

        $tables = $this->connection()->table('information_schema.tables')
            ->selectRaw('TABLE_NAME AS resolved_table_name')
            ->where('table_schema', $this->connection()->getDatabaseName())
            ->where('table_name', 'like', "db_{$batchKey}_%_grade")
            ->get()
            ->map(static fn (object $row): string => (string) $row->resolved_table_name);
        $terms = [];
        foreach ($tables as $table) {
            if (preg_match('/^db_'.preg_quote($batchKey, '/').'_[123]_grade$/', (string) $table) !== 1) {
                continue;
            }
            $identifier = $this->identifier((string) $table);
            foreach ($this->connection()->select("SELECT DISTINCT _perf_semestry AS term FROM {$identifier} WHERE _perf_semestry IS NOT NULL", [], true) as $row) {
                $terms[] = $row->term ?? null;
            }
        }

        return AcademicTerm::latest($terms);
    }

    private function identifier(string $identifier): string
    {
        if (strlen($identifier) > 64 || preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
            throw new InvalidArgumentException('Invalid imported table identifier.');
        }

        return "`{$identifier}`";
    }

    private function connection(): ConnectionInterface
    {
        return $this->database->connection();
    }
}
