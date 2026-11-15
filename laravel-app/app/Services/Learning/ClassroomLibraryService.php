<?php

namespace App\Services\Learning;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ClassroomLibraryService
{
    private const FIELDS = [
        'activities' => ['type', 'title', 'content', 'links', 'questions', 'sort_order'],
        'assignments' => ['title', 'instructions', 'max_score', 'material_url', 'material_path', 'material_filename', 'material_size'],
        'resources' => ['title', 'description', 'resource_type', 'storage_path', 'external_url', 'visibility'],
    ];

    private const TABLES = ['activities' => 'learning_classroom_activities', 'assignments' => 'learning_assignments', 'resources' => 'learning_resources'];

    public function ready(User $viewer): void
    {
        abort_unless(in_array($viewer->role, ['teacher', 'admin', 'super_admin'], true), 403);
        abort_unless(Schema::hasTable('learning_classroom_templates'), 503, 'คลังบทเรียนยังไม่พร้อม กรุณาให้ผู้ดูแลรัน php artisan migrate --force');
        app(ClassroomUnitService::class)->ready();
    }

    private function templates(User $viewer, int $districtId): Builder
    {
        return DB::table('learning_classroom_templates')->where('district_id', $districtId)
            ->when($viewer->role === 'teacher', fn ($query) => $query->where('created_by', $viewer->id));
    }

    private function sources(User $viewer, int $districtId): Builder
    {
        return DB::table('learning_classroom_units')->where('district_id', $districtId)
            ->when($viewer->role === 'teacher', fn ($query) => $query->where('created_by', $viewer->id)
                ->where('target_group', '!=', '')->whereIn('target_group', $viewer->assigned_groups ?? []));
    }

    /** Virtual sources retain old unbound course content even after registration changes. */
    private function legacySources(User $viewer, int $districtId): Builder
    {
        $queries = [];
        foreach (['learning_classroom_activities' => 'target_group', 'learning_assignments' => 'target_value'] as $table => $group) {
            $query = DB::table($table)->where('district_id', $districtId)->whereNull('classroom_unit_id')
                ->whereNotNull('academic_term')->whereNotNull('subject_code')->where('subject_code', '!=', '')
                ->whereIn('education_level', [1, 2, 3]);
            if ($viewer->role === 'teacher') {
                $query->where('created_by', $viewer->id)->where($group, '!=', '')->whereIn($group, $viewer->assigned_groups ?? []);
            }
            $queries[] = $query->selectRaw("academic_term, subject_code, subject_name, education_level, COALESCE({$group}, '') as target_group, created_at");
        }
        // Resources have no academic_term. Keep a separate termless source so
        // media-only courses remain recoverable after registrations change.
        $resources = DB::table('learning_resources')->where('district_id', $districtId)->whereNull('classroom_unit_id')
            ->whereNotNull('subject_code')->where('subject_code', '!=', '')->whereIn('education_level', [1, 2, 3]);
        if ($viewer->role === 'teacher') {
            $resources->where('uploaded_by', $viewer->id)->where('target_group', '!=', '')->whereIn('target_group', $viewer->assigned_groups ?? []);
        }
        $resources->selectRaw("'library' as academic_term, subject_code, subject_code as subject_name, education_level, COALESCE(target_group, '') as target_group, created_at");
        $combined = $queries[0]->unionAll($queries[1])->unionAll($resources);

        return DB::query()->fromSub($combined, 'legacy_contents')->selectRaw("'legacy' as source_kind, NULL as id, 'บทเรียนและงานเดิม' as title, academic_term, subject_code, MAX(subject_name) as subject_name, education_level, target_group, MAX(created_at) as created_at")
            ->groupBy('academic_term', 'subject_code', 'education_level', 'target_group');
    }

    private function sourceList(User $viewer, int $districtId): Builder
    {
        $units = $this->sources($viewer, $districtId)->selectRaw("'unit' as source_kind, id, title, academic_term, subject_code, subject_name, education_level, target_group, created_at");

        return DB::query()->fromSub($units->unionAll($this->legacySources($viewer, $districtId)), 'library_sources');
    }

    private function sourceId(object $row): string
    {
        return $row->source_kind === 'unit' ? (string) $row->id : 'legacy:'.rtrim(strtr(base64_encode(json_encode([
            $row->academic_term, $row->subject_code, (int) $row->education_level, $row->target_group,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    private function resolveSource(User $viewer, int $districtId, string $id): object
    {
        if (ctype_digit($id)) {
            $row = $this->sources($viewer, $districtId)->where('id', $id)->lockForUpdate()->first();
            abort_if($row === null, 404);
            $row->source_kind = 'unit';

            return $row;
        }
        abort_unless(str_starts_with($id, 'legacy:') && strlen($id) <= 1024, 404);
        $decoded = base64_decode(strtr(substr($id, 7), '-_', '+/'), true);
        $tuple = $decoded === false ? null : json_decode($decoded, true);
        abort_unless(is_array($tuple) && array_is_list($tuple) && count($tuple) === 4
            && is_string($tuple[0]) && is_string($tuple[1]) && in_array($tuple[2], [1, 2, 3], true) && is_string($tuple[3]), 404);
        // The opaque ID is only a selector. Its ownership and scope come from the source SQL.
        $row = DB::query()->fromSub($this->legacySources($viewer, $districtId), 'authorized_legacy')
            ->where('academic_term', $tuple[0])->where('subject_code', $tuple[1])->where('education_level', $tuple[2])->where('target_group', $tuple[3])->first();
        abort_if($row === null, 404);

        return $row;
    }

    public function listing(User $viewer, int $districtId, array $filters, bool $sources = false): array
    {
        $this->ready($viewer);
        $query = $sources ? $this->sourceList($viewer, $districtId) : $this->templates($viewer, $districtId);
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $query->where(fn ($part) => $part->where('title', 'like', '%'.$search.'%')->orWhere('subject_code', 'like', '%'.$search.'%')->orWhere('subject_name', 'like', '%'.$search.'%'));
        }
        $columns = ['id', 'title', 'academic_term', 'subject_code', 'subject_name', 'education_level', 'created_at'];
        $columns[] = $sources ? 'target_group' : 'content_count';
        if ($sources) {
            $columns[] = 'source_kind';
        }
        $page = $query->orderByDesc('created_at')->orderByDesc('id')->orderBy('academic_term')->orderBy('subject_code')->orderBy('education_level')->orderBy('title')
            ->when($sources, fn ($part) => $part->orderBy('target_group'))->paginate(20, $columns, 'page', $filters['page'] ?? 1);
        $rows = array_map(function ($row) use ($sources) {
            $row->id = $sources ? $this->sourceId($row) : (string) $row->id;
            unset($row->source_kind);
            if ($row->academic_term === 'library') {
                $row->academic_term = 'ไม่ระบุ';
            }

            return $row;
        }, $page->items());

        return [$sources ? 'units' : 'templates' => $rows, 'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]];
    }

    public function save(User $viewer, int $districtId, int|string $unitId, ?string $title = null): int
    {
        $this->ready($viewer);
        $files = [];
        try {
            return DB::transaction(function () use ($viewer, $districtId, $unitId, $title, &$files): int {
                $unit = $this->resolveSource($viewer, $districtId, (string) $unitId);
                $snapshot = ['description' => $unit->description ?? '', 'activities' => [], 'assignments' => [], 'resources' => []];
                $id = DB::table('learning_classroom_templates')->insertGetId(['district_id' => $districtId, 'created_by' => $viewer->id,
                    'title' => $title ?: $unit->title, 'academic_term' => $unit->academic_term, 'subject_code' => $unit->subject_code,
                    'subject_name' => $unit->subject_name ?: $unit->subject_code, 'education_level' => $unit->education_level, 'content_count' => 0,
                    'snapshot' => '{}', 'created_at' => now(), 'updated_at' => now()]);
                $count = 0;
                foreach (self::TABLES as $kind => $table) {
                    $query = DB::table($table)->where('district_id', $districtId);
                    if ($unit->source_kind === 'unit') {
                        $query->where('classroom_unit_id', $unitId);
                    } else {
                        $query->whereNull('classroom_unit_id')->where($kind === 'assignments' ? 'target_value' : 'target_group', $unit->target_group);
                        if ($kind !== 'resources') {
                            $query->where('academic_term', $unit->academic_term);
                        }
                    }
                    if ($viewer->role === 'teacher') {
                        $query->where($kind === 'resources' ? 'uploaded_by' : 'created_by', $viewer->id);
                    }
                    // Never import a child deliberately attached to another course/scope.
                    $query->where('subject_code', $unit->subject_code)->where('education_level', $unit->education_level);
                    $rows = $query->orderBy('id')->limit(501)->lockForUpdate()->get();
                    $count += $rows->count();
                    if ($count > 500) {
                        throw ValidationException::withMessages(['unit_id' => 'จัดเก็บได้ครั้งละไม่เกิน 500 รายการต่อบทเรียน']);
                    }
                    foreach ($rows as $row) {
                        $item = array_intersect_key((array) $row, array_flip(self::FIELDS[$kind]));
                        if ($kind === 'activities') {
                            $questions = json_decode($item['questions'], true) ?? [];
                            foreach ($questions as &$question) {
                                if (empty($question['image_id'])) {
                                    continue;
                                }
                                $image = DB::table('learning_classroom_images')->where('district_id', $districtId)->where('activity_id', $row->id)->where('id', $question['image_id'])->first();
                                abort_if($image === null, 422, 'ไม่พบภาพคำถามต้นฉบับ');
                                $question['library_image'] = ['path' => $this->copyFile($image->path, "classroom-library/{$districtId}/{$id}", $files), 'mime' => $image->mime];
                                $question['image_id'] = null;
                            }
                            unset($question);
                            $item['questions'] = $questions;
                        }
                        foreach (['material_path', 'storage_path'] as $column) {
                            if (! empty($item[$column])) {
                                $disk = $column === 'material_path' ? ($row->material_disk ?? 'local') : ($row->storage_disk ?? 'local');
                                abort_unless($disk === 'local', 422, 'ไฟล์ต้นฉบับต้องอยู่ใน private storage');
                                $item[$column] = $this->copyFile($item[$column], "classroom-library/{$districtId}/{$id}", $files);
                            }
                        }
                        $snapshot[$kind][] = $item;
                    }
                }
                DB::table('learning_classroom_templates')->where('id', $id)->update(['content_count' => $count, 'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);

                return $id;
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($files);
            throw $exception;
        }
    }

    public function restore(User $viewer, int $districtId, int $id, array $values): int
    {
        $this->ready($viewer);
        $files = [];
        try {
            return DB::transaction(function () use ($viewer, $districtId, $id, $values, &$files): int {
                $template = $this->templates($viewer, $districtId)->where('id', $id)->lockForUpdate()->first();
                abort_if($template === null, 404);
                $snapshot = json_decode($template->snapshot, true, 512, JSON_THROW_ON_ERROR);
                $unitId = app(ClassroomUnitService::class)->save($viewer, $districtId, [...$values, 'title' => $values['title'] ?? $template->title, 'description' => $snapshot['description'], 'status' => 'draft']);
                $unit = DB::table('learning_classroom_units')->where('id', $unitId)->first();
                foreach (self::TABLES as $kind => $table) {
                    foreach ($snapshot[$kind] as $item) {
                        $item = array_intersect_key($item, array_flip(self::FIELDS[$kind]));
                        $stored = [...$item, 'district_id' => $districtId, 'subject_code' => $unit->subject_code, 'education_level' => $unit->education_level,
                            'classroom_unit_id' => $unitId, 'created_at' => now(), 'updated_at' => now()];
                        if ($kind === 'resources') {
                            $stored['uploaded_by'] = $viewer->id;
                            $stored['target_group'] = $unit->target_group;
                        } else {
                            $stored = [...$stored, 'created_by' => $viewer->id, 'academic_term' => $unit->academic_term,
                                'subject_name' => $unit->subject_name, 'status' => 'draft', 'due_at' => null];
                        }
                        if ($kind === 'activities') {
                            $stored['target_group'] = $unit->target_group;
                            $stored['audience_groups'] = $unit->audience_groups;
                            $questions = $stored['questions'];
                            $stored['questions'] = '[]';
                            $activityId = DB::table($table)->insertGetId($stored);
                            foreach ($questions as &$question) {
                                $image = $question['library_image'] ?? null;
                                unset($question['library_image']);
                                if ($image !== null) {
                                    $path = $this->copyFile($image['path'], "classroom-restored/{$districtId}/{$unitId}", $files);
                                    $question['image_id'] = (string) DB::table('learning_classroom_images')->insertGetId(['district_id' => $districtId, 'created_by' => $viewer->id,
                                        'activity_id' => $activityId, 'path' => $path, 'mime' => $image['mime'], 'created_at' => now(), 'updated_at' => now()]);
                                }
                            }
                            unset($question);
                            DB::table($table)->where('id', $activityId)->update(['questions' => json_encode($questions, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
                        } else {
                            if ($kind === 'assignments') {
                                $stored['target_type'] = $unit->target_group === '' ? 'all' : 'group';
                                $stored['target_value'] = $unit->target_group ?: null;
                                $stored['opens_at'] = null;
                            }
                            $pathColumn = $kind === 'assignments' ? 'material_path' : 'storage_path';
                            $sourcePath = $stored[$pathColumn] ?? null;
                            $stored[$pathColumn] = null;
                            $contentId = DB::table($table)->insertGetId($stored);
                            if ($sourcePath !== null && $sourcePath !== '') {
                                // Existing download and cleanup endpoints require ID-owned paths.
                                $directory = $kind === 'assignments' ? "learning/assignments/{$districtId}/{$contentId}" : "learning/resources/{$districtId}/{$contentId}";
                                $path = $this->copyFile($sourcePath, $directory, $files);
                                DB::table($table)->where('id', $contentId)->update([$pathColumn => $path,
                                    $kind === 'assignments' ? 'material_disk' : 'storage_disk' => 'local']);
                            }
                        }
                    }
                }

                return $unitId;
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($files);
            throw $exception;
        }
    }

    public function delete(User $viewer, int $districtId, int $id): void
    {
        $this->ready($viewer);
        DB::transaction(function () use ($viewer, $districtId, $id): void {
            $row = $this->templates($viewer, $districtId)->where('id', $id)->lockForUpdate()->first();
            abort_if($row === null, 404);
            DB::table('learning_classroom_templates')->where('id', $id)->delete();
        });
        Storage::disk('local')->deleteDirectory("classroom-library/{$districtId}/{$id}");
    }

    private function copyFile(string $path, string $directory, array &$files): string
    {
        abort_unless(Storage::disk('local')->exists($path), 422, 'ไม่พบไฟล์ต้นฉบับ กรุณาตรวจสอบก่อนจัดเก็บบทเรียน');
        $target = $directory.'/'.Str::uuid().'.'.pathinfo($path, PATHINFO_EXTENSION);
        abort_unless(Storage::disk('local')->copy($path, $target), 503, 'ไม่สามารถคัดลอกสื่อได้');
        $files[] = $target;

        return $target;
    }
}
