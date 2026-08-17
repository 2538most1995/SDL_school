<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Learning\DemoLearningPortal;
use App\Domain\Learning\DemoResponseMeta;
use App\Domain\Students\Support\AcademicTerm;
use App\Http\Controllers\Controller;
use App\Services\Legacy\ExamRoomScheduleSourceService;
use App\Services\Legacy\ExamRoomScopeService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ExamRoomController extends Controller
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ExamRoomScopeService $scope,
        private readonly ExamRoomScheduleSourceService $scheduleSource,
    ) {}

    public function __invoke(Request $request, DemoLearningPortal $demo): JsonResponse
    {
        return $this->index($request, $demo);
    }

    public function index(Request $request, DemoLearningPortal $demo): JsonResponse
    {
        if (! (bool) config('system_data.enabled')) {
            $filters = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
            $items = array_map(static fn (array $item): array => [
                ...$item,
                'subject_name' => (string) ($item['subject_name'] ?? $item['subject_code'] ?? ''),
                'education_levels' => $item['education_levels'] ?? [],
                'groups' => $item['groups'] ?? [],
            ], $demo->examRooms($filters['date'] ?? null));

            return response()->json(['data' => $items, 'meta' => [
                ...DemoResponseMeta::collection(count($items), $filters),
                'source_batch' => 'demo-only',
                'sync_enabled' => false,
                'read_only' => true,
                'current_term' => null,
                'groups' => [],
                'education_levels' => [],
                'teacher_scoped' => false,
                'permissions' => [
                    'can_create' => false,
                    'can_update' => false,
                    'can_delete' => false,
                    'can_sync' => false,
                ],
            ]]);
        }
        $districtId = $this->districtId($request);
        $scope = $this->scope->forDistrict($districtId);
        $viewerGroups = $this->viewerGroupValues($request, $scope);
        $subjectNames = $this->scheduleSource->subjectNamesForDistrict($districtId);
        $items = $scope['term'] === null ? [] : $this->read()->table('exam_rooms')
            ->where('district_id', $districtId)
            ->whereIn('term', AcademicTerm::variants($scope['term']))
            ->orderBy('subject_code')->orderBy('room_name')
            ->limit(5000)->get()->map(fn (object $row): array => $this->payload(
                $row,
                $this->scope->forRoom($row, $scope),
                $subjectNames[(string) $row->subject_code] ?? null,
            ))->all();
        if ($viewerGroups !== null) {
            $items = array_values(array_filter(
                $items,
                fn (array $item): bool => $this->scopeBelongsToViewer($item['groups'], $viewerGroups),
            ));
        }
        $administrator = $this->isAdministrator($request);
        $writeEnabled = (bool) config('system_data.write_enabled');
        $scheduleSync = ! $administrator || $scope['term'] === null || $items !== []
            ? ['available' => false, 'term' => $scope['term'], 'count' => 0]
            : $this->scheduleSyncAvailability($districtId, $scope['term']);

        return response()->json(['data' => $items, 'meta' => [
            'source' => 'system_database',
            'read_only' => ! $writeEnabled,
            'district_id' => $districtId,
            'current_term' => $scope['term'],
            'groups' => $this->visibleGroups($scope, $viewerGroups),
            'education_levels' => $this->visibleEducationLevels($scope, $viewerGroups),
            'teacher_scoped' => $viewerGroups !== null,
            'permissions' => [
                'can_create' => $administrator && $writeEnabled,
                'can_update' => $writeEnabled && ($viewerGroups === null || $viewerGroups !== []),
                'can_delete' => $administrator && $writeEnabled,
                'can_sync' => $administrator && $writeEnabled,
            ],
            'schedule_sync' => $scheduleSync,
            // Keep one deployment window compatible with the previous frontend.
            'carry_forward' => [
                'available' => $scheduleSync['available'],
                'source_term' => $scheduleSync['term'],
                'count' => $scheduleSync['count'],
            ],
        ]]);
    }

    public function syncFromSchedule(Request $request): JsonResponse
    {
        $this->assertAdministrator($request);
        $this->assertWriteEnabled();
        $districtId = $this->districtId($request);
        $currentTerm = $this->requireCurrentTerm($request);
        $sourceRows = $this->scheduleSource->rowsForDistrict($districtId, $currentTerm);
        abort_if($sourceRows === [], 422, "ไม่พบค่าห้องสอบจากตารางสอบภาคเรียน {$currentTerm}");

        $schema = $this->write()->getSchemaBuilder();
        $now = now();
        $timestamps = [
            ...($schema->hasColumn('exam_rooms', 'created_at') ? ['created_at' => $now] : []),
            ...($schema->hasColumn('exam_rooms', 'updated_at') ? ['updated_at' => $now] : []),
        ];

        $result = $this->write()->transaction(function () use ($districtId, $currentTerm, $sourceRows, $timestamps): array {
            $this->write()->table('districts')->where('id', $districtId)->lockForUpdate()->first();
            $alreadyExists = $this->write()->table('exam_rooms')
                ->where('district_id', $districtId)
                ->whereIn('term', AcademicTerm::variants($currentTerm))
                ->exists();
            abort_if($alreadyExists, 409, "ภาคเรียน {$currentTerm} มีรายการห้องสอบแล้ว ระบบจึงไม่คัดลอกซ้ำ");

            foreach (array_chunk($sourceRows, 250) as $chunk) {
                $this->write()->table('exam_rooms')->insert(array_map(
                    static fn (array $row): array => [
                        'district_id' => $districtId,
                        ...$row,
                        ...$timestamps,
                    ],
                    $chunk,
                ));
            }

            return [
                'synced' => count($sourceRows),
                'source' => 'current_exam_schedule',
                'current_term' => $currentTerm,
                'first_id' => (int) $this->write()->table('exam_rooms')
                    ->where('district_id', $districtId)
                    ->whereIn('term', AcademicTerm::variants($currentTerm))
                    ->min('id'),
            ];
        });
        $this->audit($request, 'admin.exam_rooms.synced_from_schedule', $result['first_id'], null, [
            'source' => $result['source'],
            'current_term' => $result['current_term'],
            'synced' => $result['synced'],
        ]);
        unset($result['first_id']);

        return response()->json(['data' => $result], 201);
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertAdministrator($request);
        $this->assertWriteEnabled();
        $currentTerm = $this->requireCurrentTerm($request);
        $validated = $this->validated($request, $currentTerm);
        $id = $this->write()->table('exam_rooms')->insertGetId([
            ...$validated,
            'district_id' => $this->districtId($request),
            'created_at' => now(),
        ]);
        $row = $this->room($request, $id);
        $this->audit($request, 'admin.exam_room.created', $id, null, $this->payload($row));

        return response()->json(['data' => $this->payload($row)], 201);
    }

    public function update(Request $request, int $examRoom): JsonResponse
    {
        $this->assertWriteEnabled();
        $currentTerm = $this->requireCurrentTerm($request);
        $before = $this->room($request, $examRoom, $currentTerm);
        $districtScope = $this->scope->forDistrict($this->districtId($request));
        $beforeScope = $this->scope->forRoom($before, $districtScope);
        $viewerGroups = $this->viewerGroupValues($request, $districtScope);
        if ($viewerGroups !== null) {
            abort_unless($this->scopeBelongsToViewer($beforeScope['groups'], $viewerGroups), 403, 'แก้ไขได้เฉพาะผู้เรียนในกลุ่มที่รับผิดชอบ');
        }
        $validated = $this->validated($request, $currentTerm);
        $changes = $viewerGroups === null ? $validated : ['room_name' => $validated['room_name']];
        $this->write()->table('exam_rooms')
            ->where('id', $examRoom)
            ->where('district_id', $this->districtId($request))
            ->whereIn('term', AcademicTerm::variants($currentTerm))
            ->update($changes);
        $after = $this->room($request, $examRoom, $currentTerm);
        $afterScope = $this->scope->forRoom($after, $districtScope);
        $this->audit($request, 'admin.exam_room.updated', $examRoom, $this->payload($before, $beforeScope), $this->payload($after, $afterScope));

        return response()->json(['data' => $this->payload($after, $afterScope)]);
    }

    public function bulkUpdate(Request $request): JsonResponse
    {
        $this->assertWriteEnabled();
        $currentTerm = $this->requireCurrentTerm($request);
        $validated = $request->validate([
            'room_ids' => ['required', 'array', 'min:1', 'max:5000'],
            'room_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
            'room_name' => ['required', 'string', 'max:100'],
        ]);
        $roomIds = array_values(array_map('intval', $validated['room_ids']));
        $districtId = $this->districtId($request);
        $rooms = $this->read()->table('exam_rooms')
            ->where('district_id', $districtId)
            ->whereIn('term', AcademicTerm::variants($currentTerm))
            ->whereIn('id', $roomIds)
            ->get()
            ->keyBy('id');
        abort_unless($rooms->count() === count($roomIds), 404, 'ไม่พบรายการห้องสอบที่เลือกในภาคเรียนปัจจุบัน');

        $districtScope = $this->scope->forDistrict($districtId);
        $viewerGroups = $this->viewerGroupValues($request, $districtScope);
        if ($viewerGroups !== null) {
            foreach ($rooms as $room) {
                $roomScope = $this->scope->forRoom($room, $districtScope);
                abort_unless(
                    $this->scopeBelongsToViewer($roomScope['groups'], $viewerGroups),
                    403,
                    'แก้ไขได้เฉพาะผู้เรียนในกลุ่มที่รับผิดชอบ',
                );
            }
        }

        $before = $rooms->map(static fn (object $room): array => [
            'id' => (int) $room->id,
            'room_name' => (string) $room->room_name,
        ])->values()->all();
        $updated = $this->write()->table('exam_rooms')
            ->where('district_id', $districtId)
            ->whereIn('term', AcademicTerm::variants($currentTerm))
            ->whereIn('id', $roomIds)
            ->update(['room_name' => trim((string) $validated['room_name'])]);
        $this->audit($request, 'admin.exam_rooms.bulk_updated', $roomIds[0], [
            'count' => count($roomIds),
            'rooms' => $before,
        ], [
            'count' => count($roomIds),
            'room_ids' => $roomIds,
            'room_name' => trim((string) $validated['room_name']),
        ]);

        return response()->json(['data' => [
            'updated' => $updated,
            'selected' => count($roomIds),
            'room_ids' => $roomIds,
            'room_name' => trim((string) $validated['room_name']),
        ]]);
    }

    public function destroy(Request $request, int $examRoom): JsonResponse
    {
        $this->assertAdministrator($request);
        $this->assertWriteEnabled();
        $currentTerm = $this->requireCurrentTerm($request);
        $before = $this->room($request, $examRoom, $currentTerm);
        $deleted = $this->write()->table('exam_rooms')
            ->where('id', $examRoom)
            ->where('district_id', $this->districtId($request))
            ->whereIn('term', AcademicTerm::variants($currentTerm))
            ->delete();
        abort_unless($deleted === 1, 404);
        $this->audit($request, 'admin.exam_room.deleted', $examRoom, $this->payload($before), null);

        return response()->json(['data' => ['deleted' => true, 'id' => $examRoom]]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, string $currentTerm): array
    {
        $validated = $request->validate([
            'term' => ['required', 'string', 'max:50', 'regex:/^(?:[12]\/(?:25)?\d{2}|(?:25)?\d{2}\/[12])$/'],
            'subject_code' => ['required', 'string', 'max:100'],
            'assignment_type' => ['required', Rule::in(['group_range', 'student_range'])],
            'start_val' => ['required', 'string', 'max:100'],
            'end_val' => ['required', 'string', 'max:100'],
            'room_name' => ['required', 'string', 'max:100'],
        ]);
        if (AcademicTerm::normalize($validated['term']) !== $currentTerm) {
            throw ValidationException::withMessages([
                'term' => "จัดการห้องสอบได้เฉพาะภาคเรียนปัจจุบัน {$currentTerm}",
            ]);
        }
        $validated['term'] = $currentTerm;

        return $validated;
    }

    private function room(Request $request, int $id, ?string $currentTerm = null): object
    {
        $query = $this->read()->table('exam_rooms')
            ->where('id', $id)
            ->where('district_id', $this->districtId($request));
        if ($currentTerm !== null) {
            $query->whereIn('term', AcademicTerm::variants($currentTerm));
        }
        $row = $query->first();
        abort_unless($row, 404);

        return $row;
    }

    /** @return array<string, mixed> */
    private function payload(object $row, array $scopes = ['education_levels' => [], 'groups' => []], ?string $subjectName = null): array
    {
        return [
            'id' => (int) $row->id,
            'district_id' => (int) $row->district_id,
            'term' => (string) $row->term,
            'subject_code' => (string) $row->subject_code,
            'subject_name' => trim((string) $subjectName) ?: (string) $row->subject_code,
            'assignment_type' => (string) $row->assignment_type,
            'start_val' => (string) $row->start_val,
            'end_val' => (string) $row->end_val,
            'room_name' => (string) $row->room_name,
            'capacity' => $this->scope->rangeCapacity((string) $row->start_val, (string) $row->end_val),
            'status' => 'ready',
            'education_levels' => $scopes['education_levels'],
            'groups' => $scopes['groups'],
        ];
    }

    private function requireCurrentTerm(Request $request): string
    {
        $term = $this->scope->forDistrict($this->districtId($request))['term'];
        if ($term === null) {
            throw ValidationException::withMessages([
                'term' => 'ยังไม่พบภาคเรียนปัจจุบันจากชุดข้อมูลนักศึกษาของอำเภอนี้',
            ]);
        }

        return $term;
    }

    /** @return array{available: bool, term: string, count: int} */
    private function scheduleSyncAvailability(int $districtId, string $currentTerm): array
    {
        $rows = $this->scheduleSource->rowsForDistrict($districtId, $currentTerm);

        return [
            'available' => $rows !== [],
            'term' => $currentTerm,
            'count' => count($rows),
        ];
    }

    /**
     * @param  array<string, mixed>  $districtScope
     * @return list<string>|null
     */
    private function viewerGroupValues(Request $request, array $districtScope): ?array
    {
        if ((string) $request->user()->role !== 'teacher') {
            return null;
        }
        $assigned = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            is_array($request->user()->assigned_groups) ? $request->user()->assigned_groups : [],
        )));

        return array_values(array_map(
            static fn (array $group): string => (string) $group['value'],
            array_filter(
                $districtScope['groups'],
                static fn (array $group): bool => in_array((string) $group['value'], $assigned, true)
                    || in_array((string) $group['label'], $assigned, true),
            ),
        ));
    }

    /**
     * @param  array<string, mixed>  $districtScope
     * @param  list<string>|null  $viewerGroups
     * @return list<array{value: string, label: string}>
     */
    private function visibleGroups(array $districtScope, ?array $viewerGroups): array
    {
        if ($viewerGroups === null) {
            return $districtScope['groups'];
        }

        return array_values(array_filter(
            $districtScope['groups'],
            static fn (array $group): bool => in_array((string) $group['value'], $viewerGroups, true),
        ));
    }

    /**
     * @param  array<string, mixed>  $districtScope
     * @param  list<string>|null  $viewerGroups
     * @return list<array{value: int, label: string}>
     */
    private function visibleEducationLevels(array $districtScope, ?array $viewerGroups): array
    {
        if ($viewerGroups === null) {
            return $districtScope['education_levels'];
        }
        $levels = [];
        foreach ($districtScope['student_targets'] as $target) {
            if ($target['group'] !== null && in_array($target['group'], $viewerGroups, true)) {
                $levels[(int) $target['education_level']] = true;
            }
        }

        return array_values(array_filter(
            $districtScope['education_levels'],
            static fn (array $level): bool => isset($levels[(int) $level['value']]),
        ));
    }

    /**
     * @param  list<string>  $roomGroups
     * @param  list<string>  $viewerGroups
     */
    private function scopeBelongsToViewer(array $roomGroups, array $viewerGroups): bool
    {
        return $roomGroups !== [] && array_diff($roomGroups, $viewerGroups) === [];
    }

    private function isAdministrator(Request $request): bool
    {
        return in_array((string) $request->user()->role, ['admin', 'super_admin'], true);
    }

    private function assertAdministrator(Request $request): void
    {
        abort_unless($this->isAdministrator($request), 403, 'สิทธิ์นี้สำหรับผู้ดูแลระบบเท่านั้น');
    }

    private function audit(Request $request, string $event, int $id, ?array $before, ?array $after): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'district_id' => $this->districtId($request),
            'event' => $event,
            'auditable_type' => 'system_exam_room',
            'auditable_id' => $id,
            'ip_address' => $request->ip(),
            'before' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'after' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    private function districtId(Request $request): int
    {
        return (int) $request->attributes->get('district_id');
    }

    private function assertWriteEnabled(): void
    {
        abort_unless((bool) config('system_data.write_enabled'), 503, 'ระบบเขียนข้อมูลยังไม่เปิดใช้งาน');
    }

    private function read()
    {
        return $this->database->connection();
    }

    private function write()
    {
        return $this->database->connection();
    }
}
