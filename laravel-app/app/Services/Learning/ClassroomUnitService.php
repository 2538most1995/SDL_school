<?php

namespace App\Services\Learning;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class ClassroomUnitService
{
    public function ready(): void
    {
        abort_unless(Schema::hasTable('learning_classroom_units'), 503, 'กิจกรรมรายวิชายังไม่พร้อม กรุณาให้ผู้ดูแลรัน php artisan migrate --force');
        foreach (['learning_classroom_activities', 'learning_assignments', 'learning_resources', 'learning_classroom_tasks'] as $table) {
            abort_unless(Schema::hasColumn($table, 'classroom_unit_id'), 503, 'กิจกรรมรายวิชายังไม่พร้อม กรุณาให้ผู้ดูแลรัน php artisan migrate --force');
        }
    }

    private function catalog(User $viewer, int $districtId): array
    {
        return app(OnlineClassroomService::class)->catalog($viewer, $districtId);
    }

    private function course(array $catalog, string $code, int $level): array
    {
        $subject = collect($catalog['subjects'])->first(fn ($row) => $row['code'] === $code && (int) $row['level'] === $level);
        abort_if($subject === null, 404);

        return $subject;
    }

    private function audience(array $catalog, object $unit): array
    {
        $groups = json_decode($unit->audience_groups, true) ?? [];

        return array_values(array_filter($catalog['registrations'], fn ($row) => $row['subject_code'] === $unit->subject_code
            && (int) $row['level'] === (int) $unit->education_level
            && in_array($row['group_code'], $groups, true)
            && ($unit->target_group === '' || in_array($unit->target_group, [$row['group_code'], $row['group_name']], true))));
    }

    private function visibleQuery(User $viewer, int $districtId, array $catalog): Builder
    {
        $pairs = collect($catalog['registrations'])->unique(fn ($row) => $row['subject_code'].'|'.$row['level'].'|'.$row['group_code'].'|'.$row['group_name']);
        $query = DB::table('learning_classroom_units')->where('district_id', $districtId)->where('academic_term', $catalog['term'])
            ->when($viewer->role === 'student', fn ($part) => $part->where('status', 'published'))
            ->when($viewer->role === 'teacher', fn ($part) => $part->where('created_by', $viewer->id));
        if ($pairs->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($scope) use ($pairs): void {
            foreach ($pairs as $pair) {
                $scope->orWhere(fn ($part) => $part->where('subject_code', $pair['subject_code'])->where('education_level', $pair['level'])
                    ->whereIn('target_group', ['', $pair['group_code'], $pair['group_name']])->whereJsonContains('audience_groups', $pair['group_code']));
            }
        });
    }

    public function authorized(User $viewer, int $districtId, int $id, bool $write = false, ?array $catalog = null): object
    {
        $this->ready();
        $catalog ??= $this->catalog($viewer, $districtId);
        $row = $this->visibleQuery($viewer, $districtId, $catalog)->where('id', $id)->first();
        abort_if($row === null, 404);
        if ($write) {
            abort_unless(in_array($viewer->role, ['teacher', 'admin', 'super_admin'], true), 403);
            abort_unless($this->canEdit($viewer, $row, $catalog), 403);
        }

        return $row;
    }

    private function canEdit(User $viewer, object $unit, array $catalog): bool
    {
        if ($viewer->role !== 'teacher') {
            return in_array($viewer->role, ['admin', 'super_admin'], true);
        }

        return (int) $unit->created_by === (int) $viewer->id && $unit->target_group !== '' && $this->audience($catalog, $unit) !== [];
    }

    /** Existing unbound records remain backward compatible; bound content must pass its parent's access check. */
    public function assertContentAccessible(User $viewer, int $districtId, object $row, ?array $catalog = null): void
    {
        if (($row->classroom_unit_id ?? null) !== null) {
            $parent = $this->authorized($viewer, $districtId, (int) $row->classroom_unit_id, false, $catalog);
            abort_if(isset($row->subject_code) && $row->subject_code !== $parent->subject_code, 404);
            abort_if(isset($row->education_level) && (int) $row->education_level !== (int) $parent->education_level, 404);
            abort_if(isset($row->academic_term) && $row->academic_term !== $parent->academic_term, 404);
        }
    }

    /** Only static application aliases are accepted, never request identifiers. */
    public function scopeQuery(Builder $query, User $viewer, int $districtId, string $alias = '', ?array $catalog = null): void
    {
        if (! Schema::hasTable('learning_classroom_units')) {
            return;
        }
        $this->ready();
        if ($alias !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias) !== 1) {
            throw new \InvalidArgumentException('Invalid application query alias');
        }
        $column = ($alias === '' ? '' : $alias.'.').'classroom_unit_id';
        $catalog ??= $this->catalog($viewer, $districtId);
        $visible = $this->visibleQuery($viewer, $districtId, $catalog)->select('id');
        $query->where(fn ($part) => $part->whereNull($column)->orWhereIn($column, $visible));
    }

    public function validateContentUnit(User $viewer, int $districtId, array $values, ?object $existing = null, ?array $catalog = null): ?int
    {
        if (array_key_exists('unit_id', $values)) {
            $this->ready();
        }
        $requested = array_key_exists('unit_id', $values) ? $values['unit_id'] : ($existing->classroom_unit_id ?? null);
        if ($requested === null || $requested === '' || $requested === 'legacy') {
            return null;
        }
        if (! ctype_digit((string) $requested) || (int) $requested < 1) {
            throw ValidationException::withMessages(['unit_id' => 'กิจกรรมไม่ถูกต้อง']);
        }
        $this->ready();
        $catalog ??= $this->catalog($viewer, $districtId);
        if (DB::transactionLevel() > 0) {
            DB::table('learning_classroom_units')->where('id', (int) $requested)->lockForUpdate()->first();
        }
        $unit = $this->authorized($viewer, $districtId, (int) $requested, true, $catalog);
        $code = (string) ($values['subject_code'] ?? $values['subject'] ?? $existing->subject_code ?? '');
        $level = (int) ($values['education_level'] ?? $values['level'] ?? $existing->education_level ?? 0);
        if ($code !== $unit->subject_code || $level !== (int) $unit->education_level) {
            throw ValidationException::withMessages(['unit_id' => 'กิจกรรมต้องอยู่ในรายวิชาและระดับเดียวกัน']);
        }
        $target = trim((string) ($values['target_group'] ?? $existing->target_group ?? $existing->target_value ?? ''));
        $audience = $this->audience($catalog, $unit);
        $allowedCodes = array_column($audience, 'student_code');
        $children = array_values(array_filter($catalog['registrations'], fn ($row) => $row['subject_code'] === $code && (int) $row['level'] === $level
            && ($target === '' || in_array($target, [$row['group_code'], $row['group_name']], true))));
        if ($children === [] || array_diff(array_column($children, 'student_code'), $allowedCodes) !== []) {
            throw ValidationException::withMessages(['target_group' => 'กลุ่มเป้าหมายต้องอยู่ภายในกิจกรรมที่เลือก']);
        }

        return (int) $unit->id;
    }

    public function save(User $viewer, int $districtId, array $values, ?int $id = null): int
    {
        $this->ready();
        abort_unless(in_array($viewer->role, ['teacher', 'admin', 'super_admin'], true), 403);
        $catalog = $this->catalog($viewer, $districtId);

        return DB::transaction(function () use ($viewer, $districtId, $values, $id, $catalog): int {
            $existing = null;
            if ($id !== null) {
                DB::table('learning_classroom_units')->where('id', $id)->lockForUpdate()->first();
                $existing = $this->authorized($viewer, $districtId, $id, true, $catalog);
            }
            $code = (string) ($values['subject_code'] ?? $existing?->subject_code ?? '');
            $level = (int) ($values['education_level'] ?? $existing?->education_level ?? 0);
            $subject = $this->course($catalog, $code, $level);
            $target = trim((string) ($values['target_group'] ?? $existing?->target_group ?? ''));
            if (($viewer->role === 'teacher' && $target === '') || ($target !== '' && ! collect($subject['groups'])->contains(fn ($group) => in_array($target, [$group['code'], $group['name']], true)))) {
                throw ValidationException::withMessages(['target_group' => 'เลือกกลุ่มเรียนที่รับผิดชอบในรายวิชานี้']);
            }
            $status = $values['status'] ?? $existing?->status ?? 'draft';
            if ($existing !== null) {
                $hasContent = $this->hasContent($id);
                if ($hasContent && ($code !== $existing->subject_code || $level !== (int) $existing->education_level || $target !== $existing->target_group)) {
                    throw ValidationException::withMessages(['target_group' => 'กิจกรรมมีเนื้อหาแล้ว เปลี่ยนรายวิชา ระดับ หรือกลุ่มไม่ได้ กรุณาสร้างกิจกรรมใหม่']);
                }
                if ($status !== $existing->status && $this->hasIssuedWork($id)) {
                    throw ValidationException::withMessages(['status' => 'กิจกรรมถูกสั่งงานแล้ว เปลี่ยนการเผยแพร่ไม่ได้']);
                }
            }
            $stored = ['subject_code' => $code, 'subject_name' => $subject['name'], 'education_level' => $level,
                'target_group' => $target, 'academic_term' => $catalog['term'], 'audience_groups' => $existing !== null && $code === $existing->subject_code && $level === (int) $existing->education_level && $target === $existing->target_group ? $existing->audience_groups : json_encode(array_column($subject['groups'], 'code')),
                'title' => trim($values['title'] ?? $existing?->title ?? ''), 'description' => $values['description'] ?? $existing?->description ?? '',
                'status' => $status, 'updated_at' => now()];
            if ($id === null) {
                return DB::table('learning_classroom_units')->insertGetId([...$stored, 'district_id' => $districtId, 'created_by' => $viewer->id, 'created_at' => now()]);
            }
            DB::table('learning_classroom_units')->where('id', $id)->update($stored);

            return $id;
        });
    }

    public function delete(User $viewer, int $districtId, int $id): void
    {
        $this->ready();
        DB::transaction(function () use ($viewer, $districtId, $id): void {
            DB::table('learning_classroom_units')->where('id', $id)->lockForUpdate()->first();
            $this->authorized($viewer, $districtId, $id, true);
            if ($this->hasContent($id)) {
                throw ValidationException::withMessages(['unit_id' => 'ลบได้เฉพาะกิจกรรมที่ยังไม่มีเนื้อหาหรืองานที่สั่ง กรุณาจัดการรายการภายในก่อน']);
            }
            DB::table('learning_classroom_units')->where('id', $id)->delete();
        });
    }

    private function hasContent(int $id): bool
    {
        foreach (['learning_classroom_activities', 'learning_assignments', 'learning_resources', 'learning_classroom_tasks'] as $table) {
            if (DB::table($table)->where('classroom_unit_id', $id)->exists()) {
                return true;
            }
        }

        return false;
    }

    private function hasIssuedWork(int $id): bool
    {
        if (DB::table('learning_classroom_tasks')->where('classroom_unit_id', $id)->exists()) {
            return true;
        }
        $activityIds = DB::table('learning_classroom_activities')->where('classroom_unit_id', $id)->select('id');
        $assignmentIds = DB::table('learning_assignments')->where('classroom_unit_id', $id)->select('id');

        return DB::table('learning_classroom_task_items')->where(fn ($query) => $query
            ->where(fn ($part) => $part->where('kind', 'activity')->whereIn('content_id', $activityIds))
            ->orWhere(fn ($part) => $part->where('kind', 'assignment')->whereIn('content_id', $assignmentIds)))->exists();
    }

    public function listing(User $viewer, int $districtId, array $filters): array
    {
        $this->ready();
        $catalog = $this->catalog($viewer, $districtId);
        $subject = $this->course($catalog, $filters['subject_code'], (int) $filters['education_level']);
        $page = $this->visibleQuery($viewer, $districtId, $catalog)->where('subject_code', $subject['code'])->where('education_level', $subject['level'])
            ->orderByDesc('id')->paginate(50, ['*'], 'page', $filters['page'] ?? 1);
        $rows = $page->items();
        $ids = array_map(fn ($row) => (int) $row->id, $rows);
        $counts = $this->contentCounts($viewer, $districtId, $catalog, $subject, $ids);
        $occupied = $this->occupiedIds($ids);
        $units = array_map(fn ($row) => $this->payload($viewer, $row, $catalog, $counts[(string) $row->id] ?? [], false, in_array((int) $row->id, $occupied, true)), $rows);
        $legacy = $counts['legacy'] ?? [];
        if ((int) ($filters['page'] ?? 1) === 1 && array_sum($legacy) > 0) {
            array_unshift($units, $this->legacyPayload($subject, $legacy));
        }

        return ['units' => $units, 'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total() + (array_sum($legacy) > 0 ? 1 : 0)]];
    }

    public function detail(User $viewer, int $districtId, int $id, ?array $catalog = null): array
    {
        $catalog ??= $this->catalog($viewer, $districtId);
        $unit = $this->authorized($viewer, $districtId, $id, false, $catalog);
        $subject = $this->course($catalog, $unit->subject_code, (int) $unit->education_level);
        $counts = $this->contentCounts($viewer, $districtId, $catalog, $subject, [$id]);

        return $this->payload($viewer, $unit, $catalog, $counts[(string) $id] ?? [], false);
    }

    public function legacyPayload(array $subject, array $counts = []): array
    {
        $counts = array_replace(['video' => 0, 'knowledge' => 0, 'worksheet' => 0, 'quiz' => 0], $counts);
        $counts['all'] = array_sum($counts);

        return ['id' => 'legacy', 'title' => 'บทเรียนและงานเดิม', 'description' => 'เนื้อหาเดิมของรายวิชานี้ งานส่งและคะแนนยังอยู่ครบ', 'status' => 'published',
            'target_group' => '', 'subject_code' => $subject['code'], 'education_level' => (int) $subject['level'], 'can_edit' => false, 'can_delete' => false,
            'content_count' => $counts['all'], 'activity_counts' => $counts, 'is_legacy' => true];
    }

    private function payload(User $viewer, object $row, array $catalog, array $counts, bool $legacy, ?bool $occupied = null): array
    {
        $counts = array_replace(['video' => 0, 'knowledge' => 0, 'worksheet' => 0, 'quiz' => 0], $counts);
        $counts['all'] = array_sum($counts);
        $canEdit = $this->canEdit($viewer, $row, $catalog);

        return ['id' => (string) $row->id, 'title' => $row->title, 'description' => $row->description ?? '', 'status' => $row->status,
            'target_group' => $row->target_group, 'subject_code' => $row->subject_code, 'education_level' => (int) $row->education_level,
            'can_edit' => $canEdit, 'can_delete' => $canEdit && ! ($occupied ?? $this->hasContent((int) $row->id)),
            'content_count' => $counts['all'], 'activity_counts' => $counts, 'is_legacy' => $legacy];
    }

    private function occupiedIds(array $ids): array
    {
        $occupied = [];
        foreach (['learning_classroom_activities', 'learning_assignments', 'learning_resources', 'learning_classroom_tasks'] as $table) {
            array_push($occupied, ...DB::table($table)->whereIn('classroom_unit_id', $ids)->distinct()->pluck('classroom_unit_id')->map(fn ($id) => (int) $id)->all());
        }

        return array_values(array_unique($occupied));
    }

    /** Counts are scoped before aggregation; old content remains a virtual, read-only container. */
    private function contentCounts(User $viewer, int $districtId, array $catalog, array $subject, array $ids): array
    {
        $pairs = collect($catalog['registrations'])->filter(fn ($row) => $row['subject_code'] === $subject['code'] && (int) $row['level'] === (int) $subject['level'])
            ->unique(fn ($row) => $row['group_code'].'|'.$row['group_name']);
        $result = [];
        foreach ([['learning_classroom_activities', 'target_group', 'created_by'], ['learning_assignments', 'target_value', 'created_by'], ['learning_resources', 'target_group', null]] as [$table, $groupColumn, $creator]) {
            $query = DB::table($table)->where('district_id', $districtId)
                ->where(fn ($part) => $part->where('subject_code', $subject['code'])->when($table === 'learning_resources', fn ($shared) => $shared->orWhereNull('subject_code')->orWhere('subject_code', '')))
                ->where(fn ($part) => $part->where('education_level', $subject['level'])->orWhereNull('education_level'))
                ->where(fn ($part) => $part->whereNull('classroom_unit_id')->orWhereIn('classroom_unit_id', $ids));
            if ($table !== 'learning_resources') {
                $query->where(fn ($part) => $part->where('academic_term', $catalog['term'])->orWhereNull('academic_term'));
            }
            if ($table === 'learning_classroom_activities' && $viewer->role === 'student') {
                $query->where('status', 'published');
            }
            if ($table === 'learning_assignments' && $viewer->role === 'student') {
                $query->whereIn('status', ['open', 'closed']);
            }
            if ($viewer->role === 'teacher' && $creator === null) {
                $allowedGroups = $pairs->flatMap(fn ($pair) => [$pair['group_code'], $pair['group_name']])->unique()->values()->all();
                $query->whereIn($groupColumn, $allowedGroups);
            }
            if ($viewer->role === 'teacher' && $creator !== null) {
                $query->where($creator, $viewer->id);
            }
            $query->where(function ($scope) use ($pairs, $groupColumn, $table): void {
                foreach ($pairs as $pair) {
                    $scope->orWhere(function ($part) use ($pair, $groupColumn, $table): void {
                        $part->where(fn ($target) => $target->whereIn($groupColumn, ['', $pair['group_code'], $pair['group_name']])->orWhereNull($groupColumn));
                        if ($table === 'learning_classroom_activities') {
                            $part->where(fn ($audience) => $audience->whereNull('audience_groups')->orWhereJsonContains('audience_groups', $pair['group_code']));
                        }
                    });
                }
            });
            if ($pairs->isEmpty()) {
                $query->whereRaw('1 = 0');
            }
            $type = $table === 'learning_classroom_activities' ? 'type' : ($table === 'learning_assignments' ? "'worksheet'" : "CASE WHEN resource_type IN ('video','youtube') THEN 'video' ELSE 'knowledge' END");
            $rows = $query->selectRaw('classroom_unit_id, '.$type.' as content_type, COUNT(*) as total')->groupBy('classroom_unit_id')->groupByRaw($type)->get();
            foreach ($rows as $row) {
                $key = $row->classroom_unit_id === null ? 'legacy' : (string) $row->classroom_unit_id;
                $result[$key][$row->content_type] = ($result[$key][$row->content_type] ?? 0) + (int) $row->total;
            }
        }

        return $result;
    }
}
