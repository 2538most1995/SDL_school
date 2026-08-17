<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Learning\DemoLearningPortal;
use App\Domain\Learning\DemoResponseMeta;
use App\Domain\Students\Support\AcademicTerm;
use App\Http\Controllers\Controller;
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
    ) {}

    public function __invoke(Request $request, DemoLearningPortal $demo): JsonResponse
    {
        return $this->index($request, $demo);
    }

    public function index(Request $request, DemoLearningPortal $demo): JsonResponse
    {
        if (! (bool) config('system_data.enabled')) {
            $filters = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
            $items = $demo->examRooms($filters['date'] ?? null);

            return response()->json(['data' => $items, 'meta' => [
                ...DemoResponseMeta::collection(count($items), $filters),
                'source_batch' => 'demo-only',
                'sync_enabled' => false,
                'read_only' => true,
                'current_term' => null,
                'subdistricts' => [],
                'education_levels' => [],
            ]]);
        }
        $districtId = $this->districtId($request);
        $scope = $this->scope->forDistrict($districtId);
        $items = $scope['term'] === null ? [] : $this->read()->table('exam_rooms')
            ->where('district_id', $districtId)
            ->whereIn('term', AcademicTerm::variants($scope['term']))
            ->orderBy('subject_code')->orderBy('room_name')
            ->limit(5000)->get()->map(fn (object $row): array => $this->payload(
                $row,
                $this->scope->forRoom($row, $scope),
            ))->all();
        $carryForward = $scope['term'] === null || $items !== []
            ? ['available' => false, 'source_term' => null, 'count' => 0]
            : $this->carryForwardAvailability($districtId, $scope['term']);

        return response()->json(['data' => $items, 'meta' => [
            'source' => 'system_database',
            'read_only' => ! (bool) config('system_data.write_enabled'),
            'district_id' => $districtId,
            'current_term' => $scope['term'],
            'subdistricts' => $scope['subdistricts'],
            'education_levels' => $scope['education_levels'],
            'carry_forward' => $carryForward,
        ]]);
    }

    public function carryForward(Request $request): JsonResponse
    {
        $this->assertWriteEnabled();
        $districtId = $this->districtId($request);
        $currentTerm = $this->requireCurrentTerm($request);

        $result = $this->write()->transaction(function () use ($districtId, $currentTerm): array {
            $this->write()->table('districts')->where('id', $districtId)->lockForUpdate()->first();
            $alreadyExists = $this->write()->table('exam_rooms')
                ->where('district_id', $districtId)
                ->whereIn('term', AcademicTerm::variants($currentTerm))
                ->exists();
            abort_if($alreadyExists, 409, "ภาคเรียน {$currentTerm} มีรายการห้องสอบแล้ว ระบบจึงไม่คัดลอกซ้ำ");

            $availability = $this->carryForwardAvailability($districtId, $currentTerm);
            abort_unless($availability['available'], 422, 'ไม่พบชุดห้องสอบจากภาคเรียนก่อนหน้าสำหรับนำมาใช้');
            $sourceTerm = (string) $availability['source_term'];
            $sourceRows = $this->read()->table('exam_rooms')
                ->where('district_id', $districtId)
                ->whereIn('term', AcademicTerm::variants($sourceTerm))
                ->orderBy('id')
                ->get(['subject_code', 'assignment_type', 'start_val', 'end_val', 'room_name']);
            $now = now();
            foreach ($sourceRows->chunk(500) as $chunk) {
                $this->write()->table('exam_rooms')->insert($chunk->map(static function (object $row) use ($districtId, $currentTerm, $now): array {
                    $start = (string) $row->start_val;
                    $end = trim((string) $row->end_val);

                    return [
                        'district_id' => $districtId,
                        'term' => $currentTerm,
                        'subject_code' => (string) $row->subject_code,
                        'assignment_type' => (string) $row->assignment_type,
                        'start_val' => $start,
                        'end_val' => $end === '' ? $start : $end,
                        'room_name' => (string) $row->room_name,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                })->all());
            }

            return [
                'copied' => $sourceRows->count(),
                'source_term' => $sourceTerm,
                'current_term' => $currentTerm,
                'first_id' => (int) $this->write()->table('exam_rooms')
                    ->where('district_id', $districtId)
                    ->whereIn('term', AcademicTerm::variants($currentTerm))
                    ->min('id'),
            ];
        });
        $this->audit($request, 'admin.exam_rooms.carried_forward', $result['first_id'], null, [
            'source_term' => $result['source_term'],
            'current_term' => $result['current_term'],
            'copied' => $result['copied'],
        ]);
        unset($result['first_id']);

        return response()->json(['data' => $result], 201);
    }

    public function store(Request $request): JsonResponse
    {
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
        $this->write()->table('exam_rooms')
            ->where('id', $examRoom)
            ->where('district_id', $this->districtId($request))
            ->whereIn('term', AcademicTerm::variants($currentTerm))
            ->update($this->validated($request, $currentTerm));
        $after = $this->room($request, $examRoom, $currentTerm);
        $this->audit($request, 'admin.exam_room.updated', $examRoom, $this->payload($before), $this->payload($after));

        return response()->json(['data' => $this->payload($after)]);
    }

    public function destroy(Request $request, int $examRoom): JsonResponse
    {
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
    private function payload(object $row, array $scopes = ['education_levels' => [], 'subdistricts' => []]): array
    {
        return [
            'id' => (int) $row->id,
            'district_id' => (int) $row->district_id,
            'term' => (string) $row->term,
            'subject_code' => (string) $row->subject_code,
            'assignment_type' => (string) $row->assignment_type,
            'start_val' => (string) $row->start_val,
            'end_val' => (string) $row->end_val,
            'room_name' => (string) $row->room_name,
            'capacity' => $this->scope->rangeCapacity((string) $row->start_val, (string) $row->end_val),
            'status' => 'ready',
            'education_levels' => $scopes['education_levels'],
            'subdistricts' => $scopes['subdistricts'],
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

    /** @return array{available: bool, source_term: string|null, count: int} */
    private function carryForwardAvailability(int $districtId, string $currentTerm): array
    {
        $terms = $this->read()->table('exam_rooms')
            ->where('district_id', $districtId)
            ->distinct()
            ->pluck('term')
            ->map(static fn (mixed $term): ?string => AcademicTerm::normalize((string) $term))
            ->filter(static fn (?string $term): bool => $term !== null && AcademicTerm::compare($term, $currentTerm) < 0)
            ->values()
            ->all();
        $sourceTerm = AcademicTerm::latest($terms);
        if ($sourceTerm === null) {
            return ['available' => false, 'source_term' => null, 'count' => 0];
        }

        return [
            'available' => true,
            'source_term' => $sourceTerm,
            'count' => (int) $this->read()->table('exam_rooms')
                ->where('district_id', $districtId)
                ->whereIn('term', AcademicTerm::variants($sourceTerm))
                ->count(),
        ];
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
