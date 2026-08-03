<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Learning\DemoLearningPortal;
use App\Domain\Learning\DemoResponseMeta;
use App\Http\Controllers\Controller;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class ExamRoomController extends Controller
{
    public function __construct(private readonly DatabaseManager $database) {}

    public function __invoke(Request $request, DemoLearningPortal $demo): JsonResponse
    {
        return $this->index($request, $demo);
    }

    public function index(Request $request, DemoLearningPortal $demo): JsonResponse
    {
        if (! (bool) config('legacy.enabled')) {
            $filters = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
            $items = $demo->examRooms($filters['date'] ?? null);

            return response()->json(['data' => $items, 'meta' => [
                ...DemoResponseMeta::collection(count($items), $filters),
                'source_batch' => 'demo-only',
                'sync_enabled' => false,
                'read_only' => true,
            ]]);
        }
        $districtId = $this->districtId($request);
        $items = $this->read()->table('exam_rooms')
            ->where('district_id', $districtId)
            ->orderByDesc('term')->orderBy('subject_code')->orderBy('room_name')
            ->limit(500)->get()->map(fn (object $row): array => $this->payload($row))->all();

        return response()->json(['data' => $items, 'meta' => [
            'source' => 'legacy_controlled_write',
            'read_only' => ! (bool) config('legacy.write_enabled'),
            'district_id' => $districtId,
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertWriteEnabled();
        $validated = $this->validated($request);
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
        $before = $this->room($request, $examRoom);
        $this->write()->table('exam_rooms')
            ->where('id', $examRoom)
            ->where('district_id', $this->districtId($request))
            ->update($this->validated($request));
        $after = $this->room($request, $examRoom);
        $this->audit($request, 'admin.exam_room.updated', $examRoom, $this->payload($before), $this->payload($after));

        return response()->json(['data' => $this->payload($after)]);
    }

    public function destroy(Request $request, int $examRoom): JsonResponse
    {
        $this->assertWriteEnabled();
        $before = $this->room($request, $examRoom);
        $deleted = $this->write()->table('exam_rooms')
            ->where('id', $examRoom)
            ->where('district_id', $this->districtId($request))
            ->delete();
        abort_unless($deleted === 1, 404);
        $this->audit($request, 'admin.exam_room.deleted', $examRoom, $this->payload($before), null);

        return response()->json(['data' => ['deleted' => true, 'id' => $examRoom]]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'term' => ['required', 'string', 'max:50', 'regex:/^(?:[12]\/(?:25)?\d{2}|(?:25)?\d{2}\/[12])$/'],
            'subject_code' => ['required', 'string', 'max:100'],
            'assignment_type' => ['required', Rule::in(['group_range', 'student_range'])],
            'start_val' => ['required', 'string', 'max:100'],
            'end_val' => ['required', 'string', 'max:100'],
            'room_name' => ['required', 'string', 'max:100'],
        ]);
    }

    private function room(Request $request, int $id): object
    {
        $row = $this->read()->table('exam_rooms')
            ->where('id', $id)
            ->where('district_id', $this->districtId($request))
            ->first();
        abort_unless($row, 404);

        return $row;
    }

    /** @return array<string, mixed> */
    private function payload(object $row): array
    {
        $start = filter_var($row->start_val, FILTER_VALIDATE_INT);
        $end = filter_var($row->end_val, FILTER_VALIDATE_INT);

        return [
            'id' => (int) $row->id,
            'district_id' => (int) $row->district_id,
            'term' => (string) $row->term,
            'subject_code' => (string) $row->subject_code,
            'assignment_type' => (string) $row->assignment_type,
            'start_val' => (string) $row->start_val,
            'end_val' => (string) $row->end_val,
            'room_name' => (string) $row->room_name,
            'capacity' => $start !== false && $end !== false ? max(0, $end - $start + 1) : null,
            'status' => 'ready',
        ];
    }

    private function audit(Request $request, string $event, int $id, ?array $before, ?array $after): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'district_id' => $this->districtId($request),
            'event' => $event,
            'auditable_type' => 'legacy_exam_room',
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
        abort_unless((bool) config('legacy.write_enabled'), 503, 'ระบบเขียนข้อมูลยังไม่เปิดใช้งาน');
    }

    private function read()
    {
        return $this->database->connection((string) config('legacy.connection'));
    }

    private function write()
    {
        return $this->database->connection((string) config('legacy.write_connection'));
    }
}
