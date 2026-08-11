<?php

namespace App\Http\Controllers\Api\Learning;

use App\Http\Controllers\Controller;
use App\Services\Learning\DistrictLearningGroupCatalog;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

final class LearningContentController extends Controller
{
    private const TABLES = [
        'assignments' => 'learning_assignments',
        'resources' => 'learning_resources',
        'lesson-plans' => 'learning_lesson_plans',
        'calendar' => 'learning_calendar_events',
    ];

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly DistrictLearningGroupCatalog $groupCatalog,
    ) {}

    public function store(Request $request, string $kind): JsonResponse
    {
        $this->assertWriteEnabled();
        $values = $this->validated($request, $kind);
        $stored = $this->storedValues($kind, $values);
        $actor = $this->actorId($request);
        $storedImagePath = null;
        $storedResourcePath = null;

        try {
            $id = $this->write()->transaction(function () use ($request, $kind, $values, $stored, $actor, &$storedImagePath, &$storedResourcePath): int {
                $id = (int) $this->write()->table($this->table($kind))->insertGetId([
                    ...$stored,
                    'district_id' => $this->districtId($request),
                    $this->actorColumn($kind) => $actor,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $storedImagePath = $this->storeCalendarImage($request, $kind, $id, $values);
                if ($storedImagePath !== null) {
                    $this->write()->table($this->table($kind))->where('id', $id)->update([
                        'image_path' => $storedImagePath,
                        'image_updated_at' => now(),
                    ]);
                }
                $storedResourcePath = $this->storeResourceFile($request, $kind, $id, $values);
                if ($storedResourcePath !== null) {
                    $this->write()->table($this->table($kind))->where('id', $id)->update([
                        'storage_disk' => 'local',
                        'storage_path' => $storedResourcePath,
                    ]);
                }
                $this->enforceFeaturedSelection($request, $kind, $id, $values);
                $this->audit($request, "learning.{$kind}.created", $kind, $id, null, $this->auditValues($values));

                return $id;
            });
        } catch (Throwable $exception) {
            if ($storedImagePath !== null) {
                Storage::disk('local')->delete($storedImagePath);
            }
            if ($storedResourcePath !== null) {
                Storage::disk('local')->delete($storedResourcePath);
            }

            throw $exception;
        }

        return response()->json(['data' => ['id' => (string) $id, ...$this->responseValues($values)]], 201);
    }

    public function update(Request $request, string $kind, int $content): JsonResponse
    {
        $this->assertWriteEnabled();
        $row = $this->ownedRow($request, $kind, $content);
        $values = $this->validated($request, $kind, $row);
        $stored = $this->storedValues($kind, $values);
        $oldImagePath = $kind === 'calendar' ? (string) ($row->image_path ?? '') : '';
        $newImagePath = null;
        $oldResourcePath = $kind === 'resources' ? (string) ($row->storage_path ?? '') : '';
        $newResourcePath = null;
        $removeImage = $kind === 'calendar' && (bool) ($values['remove_image'] ?? false);

        try {
            $newImagePath = $this->storeCalendarImage($request, $kind, $content, $values);
            $newResourcePath = $this->storeResourceFile($request, $kind, $content, $values);
            $media = match (true) {
                $newImagePath !== null => ['image_path' => $newImagePath, 'image_updated_at' => now()],
                $removeImage => ['image_path' => null, 'image_updated_at' => now()],
                default => [],
            };
            $resourceMedia = match (true) {
                $newResourcePath !== null => ['storage_disk' => 'local', 'storage_path' => $newResourcePath],
                $kind === 'resources' && $this->usesExternalUrl($values) => ['storage_disk' => null, 'storage_path' => null],
                default => [],
            };
            $updated = $this->write()->transaction(function () use ($request, $kind, $content, $stored, $media, $resourceMedia, $values): int {
                $updated = $this->write()->table($this->table($kind))
                    ->where('id', $content)->where('district_id', $this->districtId($request))
                    ->when($request->user()->role === 'teacher', fn ($query) => $query->where($this->actorColumn($kind), $this->actorId($request)))
                    ->update([...$stored, ...$media, ...$resourceMedia, 'updated_at' => now()]);
                if ($updated === 1) {
                    $this->enforceFeaturedSelection($request, $kind, $content, $values);
                }

                return $updated;
            });
        } catch (Throwable $exception) {
            if ($newImagePath !== null) {
                Storage::disk('local')->delete($newImagePath);
            }
            if ($newResourcePath !== null) {
                Storage::disk('local')->delete($newResourcePath);
            }

            throw $exception;
        }
        if ($updated !== 1) {
            if ($newImagePath !== null) {
                Storage::disk('local')->delete($newImagePath);
            }
            if ($newResourcePath !== null) {
                Storage::disk('local')->delete($newResourcePath);
            }
            abort(404);
        }
        if (($newImagePath !== null || $removeImage) && $this->isOwnedCalendarImage($this->districtId($request), $content, $oldImagePath)) {
            Storage::disk('local')->delete($oldImagePath);
        }
        if (($newResourcePath !== null || ($kind === 'resources' && $this->usesExternalUrl($values)))
            && $this->isOwnedResourceFile($this->districtId($request), $content, $oldResourcePath)) {
            Storage::disk('local')->delete($oldResourcePath);
        }
        $this->audit($request, "learning.{$kind}.updated", $kind, $content, $this->auditBefore($kind, $row), $this->auditValues($values));

        return response()->json(['data' => ['id' => (string) $content, ...$this->responseValues($values)]]);
    }

    public function destroy(Request $request, string $kind, int $content): JsonResponse
    {
        $this->assertWriteEnabled();
        $row = $this->ownedRow($request, $kind, $content);
        $deleted = $this->write()->table($this->table($kind))
            ->where('id', $content)->where('district_id', $this->districtId($request))
            ->when($request->user()->role === 'teacher', fn ($query) => $query->where($this->actorColumn($kind), $this->actorId($request)))
            ->delete();
        abort_unless($deleted === 1, 404);
        $imagePath = $kind === 'calendar' ? (string) ($row->image_path ?? '') : '';
        if ($this->isOwnedCalendarImage($this->districtId($request), $content, $imagePath)) {
            Storage::disk('local')->delete($imagePath);
        }
        $resourcePath = $kind === 'resources' ? (string) ($row->storage_path ?? '') : '';
        if ($this->isOwnedResourceFile($this->districtId($request), $content, $resourcePath)) {
            Storage::disk('local')->delete($resourcePath);
        }
        $this->audit($request, "learning.{$kind}.deleted", $kind, $content, $this->auditBefore($kind, $row), null);

        return response()->json(['data' => ['deleted' => true, 'id' => (string) $content]]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, string $kind, ?object $existing = null): array
    {
        $rules = match ($kind) {
            'assignments' => [
                'title' => ['required', 'string', 'max:220'], 'subject' => ['required', 'string', 'max:120'],
                'description' => ['nullable', 'string', 'max:5000'], 'due_at' => ['required', 'date'],
                'target_group' => ['nullable', 'string', 'max:120'], 'target_mode' => ['required', Rule::in(['all', 'group'])],
                'status' => ['required', Rule::in(['draft', 'open', 'closed'])],
            ],
            'resources' => [
                'title' => ['required', 'string', 'max:220'], 'subject' => ['required', 'string', 'max:32'],
                'description' => ['nullable', 'string', 'max:5000'], 'resource_type' => ['required', Rule::in(['link', 'video', 'youtube', 'pdf', 'exercise', 'file'])],
                'url' => [Rule::requiredIf(fn (): bool => $this->requestUsesExternalUrl($request)), 'nullable', 'url:http,https', 'max:2000'],
                'file' => [Rule::requiredIf(fn (): bool => $this->resourceFileIsRequired($request, $existing)), 'nullable', 'file', 'mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,zip', 'max:20480'],
                'level' => ['nullable', Rule::in(['1', '2', '3'])],
                'target_group' => ['nullable', 'string', 'max:120'],
            ],
            'lesson-plans' => [
                'title' => ['required', 'string', 'max:220'], 'subject' => ['required', 'string', 'max:120'],
                'level' => ['required', Rule::in(['1', '2', '3'])], 'semester' => ['required', 'string', 'max:50'],
                'objectives' => ['nullable', 'string', 'max:10000'], 'activities' => ['nullable', 'string', 'max:10000'],
                'assessment' => ['nullable', 'string', 'max:10000'],
            ],
            'calendar' => [
                'title' => ['required', 'string', 'max:220'], 'event_date' => ['required', 'date_format:Y-m-d'],
                'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:event_date'],
                'start_time' => ['required', 'date_format:H:i'], 'end_time' => ['required', 'date_format:H:i'],
                'event_type' => ['sometimes', Rule::in(['meeting', 'activity', 'exam'])],
                'location' => ['nullable', 'string', 'max:255'], 'target_group' => ['nullable', 'string', 'max:120'],
                'external_url' => ['nullable', 'url:http,https', 'max:2000'],
                'featured_on_dashboard' => ['sometimes', 'boolean'],
                'daily_schedule' => ['nullable', 'array', 'max:31'],
                'daily_schedule.*.date' => ['required', 'date_format:Y-m-d'],
                'daily_schedule.*.start_time' => ['required', 'date_format:H:i'],
                'daily_schedule.*.end_time' => ['required', 'date_format:H:i'],
                'notes' => ['nullable', 'string', 'max:5000'], 'remove_image' => ['sometimes', 'boolean'],
                'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:6144', 'dimensions:min_width=480,min_height=270,max_width=6000,max_height=6000'],
            ],
            default => abort(404),
        };
        $values = $request->validate($rules);
        if ($kind === 'resources') {
            $external = $this->usesExternalUrl($values);
            if ($external && isset($values['file'])) {
                throw ValidationException::withMessages(['file' => 'สื่อประเภทลิงก์ไม่สามารถแนบไฟล์ได้']);
            }
            if (! $external && filled($values['url'] ?? null)) {
                throw ValidationException::withMessages(['url' => 'สื่อประเภทไฟล์ไม่สามารถใช้ลิงก์ภายนอกได้']);
            }
            if (($values['resource_type'] ?? null) === 'pdf' && isset($values['file'])
                && strtolower((string) $values['file']->getClientOriginalExtension()) !== 'pdf') {
                throw ValidationException::withMessages(['file' => 'สื่อประเภท PDF ต้องแนบไฟล์ PDF เท่านั้น']);
            }
        }
        if ($kind === 'calendar') {
            $values['end_date'] = ($values['end_date'] ?? null) ?: $values['event_date'];
            $values['daily_schedule'] = $this->normalizeDailySchedule($values);
            $values['start_time'] = $values['daily_schedule'][0]['start_time'];
            $values['end_time'] = $values['daily_schedule'][array_key_last($values['daily_schedule'])]['end_time'];
            $values['external_url'] = ($values['external_url'] ?? null) ?: null;
            if (array_key_exists('featured_on_dashboard', $values) && $request->user()->role === 'teacher') {
                abort(403, 'เฉพาะผู้ดูแลอำเภอเท่านั้นที่เลือกกิจกรรมสำหรับหน้าแรกได้');
            }
        }
        if (filled($values['target_group'] ?? null)) {
            $allowed = $this->groupCatalog->canTarget(
                $request->user(),
                $this->districtId($request),
                (string) $values['target_group'],
            );
            if ($request->user()->role === 'teacher') {
                abort_unless($allowed, 403, 'กลุ่มนี้อยู่นอกขอบเขตที่รับผิดชอบ');
            }
            if (! $allowed) {
                throw ValidationException::withMessages([
                    'target_group' => 'ไม่พบกลุ่มเรียนนี้ในข้อมูลปัจจุบันของอำเภอ',
                ]);
            }
        }

        return $values;
    }

    private function ownedRow(Request $request, string $kind, int $id): object
    {
        $query = $this->read()->table($this->table($kind))->where('id', $id)->where('district_id', $this->districtId($request));
        if ($request->user()->role === 'teacher') {
            $query->where($this->actorColumn($kind), $this->actorId($request));
        }
        $row = $query->first();
        abort_unless($row, 404);

        return $row;
    }

    private function actorId(Request $request): ?int
    {
        return (int) $request->user()->id;
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function storedValues(string $kind, array $values): array
    {
        return match ($kind) {
            'assignments' => [
                'title' => $values['title'],
                'subject_code' => $values['subject'],
                'instructions' => $values['description'] ?? null,
                'due_at' => $values['due_at'],
                'target_type' => $values['target_mode'],
                'target_value' => ($values['target_group'] ?? null) ?: null,
                'status' => $values['status'],
            ],
            'resources' => [
                'title' => $values['title'],
                'subject_code' => $values['subject'],
                'description' => $values['description'] ?? null,
                'resource_type' => $values['resource_type'],
                'external_url' => $this->usesExternalUrl($values) ? $values['url'] : null,
                'education_level' => ($values['level'] ?? null) ?: null,
                'target_group' => ($values['target_group'] ?? null) ?: null,
                'visibility' => 'district',
            ],
            'lesson-plans' => [
                'title' => $values['title'],
                'subject_code' => $values['subject'],
                'education_level' => $values['level'],
                'academic_term' => $values['semester'],
                'objectives' => $values['objectives'] ?? null,
                'activities' => $values['activities'] ?? null,
                'assessment' => $values['assessment'] ?? null,
                'status' => 'published',
            ],
            'calendar' => [
                'title' => $values['title'],
                'description' => $values['notes'] ?? null,
                'event_type' => $values['event_type'] ?? 'meeting',
                'starts_at' => $values['event_date'].' '.$values['start_time'].':00',
                'ends_at' => $values['end_date'].' '.$values['end_time'].':00',
                'location' => $values['location'] ?? null,
                'target_type' => filled($values['target_group'] ?? null) ? 'group' : 'all',
                'target_value' => ($values['target_group'] ?? null) ?: null,
                'daily_schedule' => json_encode($values['daily_schedule'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'external_url' => $values['external_url'] ?? null,
                ...(array_key_exists('featured_on_dashboard', $values)
                    ? ['featured_on_dashboard' => (bool) $values['featured_on_dashboard']]
                    : []),
            ],
            default => abort(404),
        };
    }

    private function actorColumn(string $kind): string
    {
        return match ($kind) {
            'resources' => 'uploaded_by',
            'lesson-plans' => 'teacher_id',
            default => 'created_by',
        };
    }

    private function table(string $kind): string
    {
        abort_unless(isset(self::TABLES[$kind]), 404);

        return self::TABLES[$kind];
    }

    private function districtId(Request $request): int
    {
        return (int) $request->attributes->get('district_id');
    }

    private function read()
    {
        return $this->database->connection();
    }

    private function write()
    {
        return $this->database->connection();
    }

    private function assertWriteEnabled(): void
    {
        abort_unless((bool) config('system_data.write_enabled'), 503, 'ระบบเขียนข้อมูลยังไม่เปิดใช้งาน');
    }

    /** @param array<string, mixed> $values
     * @return list<array{date: string, start_time: string, end_time: string}>
     */
    private function normalizeDailySchedule(array $values): array
    {
        $startDate = Carbon::createFromFormat('Y-m-d', (string) $values['event_date'])->startOfDay();
        $endDate = Carbon::createFromFormat('Y-m-d', (string) $values['end_date'])->startOfDay();
        $expectedDates = [];
        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
            $expectedDates[] = $date->format('Y-m-d');
            if (count($expectedDates) > 31) {
                throw ValidationException::withMessages([
                    'end_date' => 'กิจกรรมหนึ่งรายการกำหนดต่อเนื่องได้ไม่เกิน 31 วัน',
                ]);
            }
        }

        $provided = $values['daily_schedule'] ?? [];
        $usesDailySchedule = $provided !== [];
        if ($provided === []) {
            $provided = array_map(static fn (string $date): array => [
                'date' => $date,
                'start_time' => (string) $values['start_time'],
                'end_time' => (string) $values['end_time'],
            ], $expectedDates);
        }
        usort($provided, static fn (array $left, array $right): int => strcmp((string) $left['date'], (string) $right['date']));
        $providedDates = array_column($provided, 'date');
        if ($providedDates !== $expectedDates || count(array_unique($providedDates)) !== count($providedDates)) {
            throw ValidationException::withMessages([
                'daily_schedule' => 'กรุณากำหนดเวลาให้ครบทุกวันตั้งแต่วันเริ่มถึงวันสิ้นสุด',
            ]);
        }

        return array_map(static function (array $day, int $index) use ($usesDailySchedule): array {
            $startsAt = Carbon::createFromFormat('Y-m-d H:i', $day['date'].' '.$day['start_time']);
            $endsAt = Carbon::createFromFormat('Y-m-d H:i', $day['date'].' '.$day['end_time']);
            if (! $endsAt->greaterThan($startsAt)) {
                throw ValidationException::withMessages([
                    $usesDailySchedule ? "daily_schedule.{$index}.end_time" : 'end_time' => 'เวลาสิ้นสุดของแต่ละวันต้องอยู่หลังเวลาเริ่ม',
                ]);
            }

            return [
                'date' => (string) $day['date'],
                'start_time' => (string) $day['start_time'],
                'end_time' => (string) $day['end_time'],
            ];
        }, $provided, array_keys($provided));
    }

    /** @param array<string, mixed> $values */
    private function enforceFeaturedSelection(Request $request, string $kind, int $contentId, array $values): void
    {
        if ($kind !== 'calendar' || ! ($values['featured_on_dashboard'] ?? false)) {
            return;
        }

        $this->write()->table('learning_calendar_events')
            ->where('district_id', $this->districtId($request))
            ->where('id', '!=', $contentId)
            ->where('featured_on_dashboard', true)
            ->update(['featured_on_dashboard' => false, 'updated_at' => now()]);
    }

    /** @param array<string, mixed> $values */
    private function storeCalendarImage(Request $request, string $kind, int $contentId, array $values): ?string
    {
        if ($kind !== 'calendar' || ! isset($values['image'])) {
            return null;
        }

        $path = $values['image']->store(
            "learning/calendar/{$this->districtId($request)}/{$contentId}",
            'local',
        );
        abort_if($path === false, 500, 'ไม่สามารถบันทึกรูปกิจกรรมได้');

        return $path;
    }

    private function isOwnedCalendarImage(int $districtId, int $contentId, string $path): bool
    {
        return $path !== '' && str_starts_with($path, "learning/calendar/{$districtId}/{$contentId}/");
    }

    /** @param array<string, mixed> $values */
    private function storeResourceFile(Request $request, string $kind, int $contentId, array $values): ?string
    {
        if ($kind !== 'resources' || ! isset($values['file'])) {
            return null;
        }

        $extension = strtolower((string) $values['file']->getClientOriginalExtension());
        $filename = Str::uuid()->toString().($extension === '' ? '' : '.'.$extension);
        $path = $values['file']->storeAs(
            "learning/resources/{$this->districtId($request)}/{$contentId}",
            $filename,
            'local',
        );
        abort_if($path === false, 500, 'ไม่สามารถบันทึกไฟล์สื่อการเรียนได้');

        return $path;
    }

    private function isOwnedResourceFile(int $districtId, int $contentId, string $path): bool
    {
        return $path !== '' && str_starts_with($path, "learning/resources/{$districtId}/{$contentId}/");
    }

    private function requestUsesExternalUrl(Request $request): bool
    {
        return in_array((string) $request->input('resource_type'), ['link', 'video', 'youtube'], true);
    }

    /** @param array<string, mixed> $values */
    private function usesExternalUrl(array $values): bool
    {
        return in_array((string) ($values['resource_type'] ?? ''), ['link', 'video', 'youtube'], true);
    }

    private function resourceFileIsRequired(Request $request, ?object $existing): bool
    {
        if ($this->requestUsesExternalUrl($request)) {
            return false;
        }
        if ($existing === null || trim((string) ($existing->storage_path ?? '')) === '') {
            return true;
        }

        return (string) ($existing->resource_type ?? '') !== (string) $request->input('resource_type');
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function responseValues(array $values): array
    {
        $hasFile = isset($values['file']);
        unset($values['image'], $values['remove_image'], $values['file']);
        if ($hasFile) {
            $values['has_file'] = true;
        }

        return $values;
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function auditValues(array $values): array
    {
        $audit = $this->responseValues($values);

        if (array_key_exists('resource_type', $audit)) {
            unset($audit['url']);
            $audit['resource_source'] = $this->usesExternalUrl($values) ? 'external_url' : 'private_file';
        }

        if (isset($values['image']) || array_key_exists('remove_image', $values)) {
            $audit['image_changed'] = isset($values['image']) || (bool) ($values['remove_image'] ?? false);
        }

        return $audit;
    }

    /** @return array<string, mixed> */
    private function auditBefore(string $kind, object $row): array
    {
        $before = (array) $row;
        if ($kind === 'resources') {
            unset($before['storage_disk'], $before['storage_path'], $before['external_url']);
        }

        return $before;
    }

    private function audit(Request $request, string $event, string $kind, int $id, ?array $before, ?array $after): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id, 'district_id' => $this->districtId($request), 'event' => $event,
            'auditable_type' => "system_learning_{$kind}", 'auditable_id' => $id, 'ip_address' => $request->ip(),
            'before' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'after' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}
