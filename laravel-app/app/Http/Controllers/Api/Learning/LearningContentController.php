<?php

namespace App\Http\Controllers\Api\Learning;

use App\Http\Controllers\Controller;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class LearningContentController extends Controller
{
    private const TABLES = [
        'assignments' => 'learning_assignments',
        'resources' => 'learning_resources',
        'lesson-plans' => 'learning_lesson_plans',
        'calendar' => 'learning_group_events',
    ];

    public function __construct(private readonly DatabaseManager $database) {}

    public function store(Request $request, string $kind): JsonResponse
    {
        $this->assertWriteEnabled();
        $values = $this->validated($request, $kind);
        $actor = $this->actorId($request);
        $id = $this->write()->table($this->table($kind))->insertGetId([
            ...$values,
            'district_id' => $this->districtId($request),
            'created_by' => $actor,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->audit($request, "learning.{$kind}.created", $kind, $id, null, $values);

        return response()->json(['data' => ['id' => (string) $id, ...$values]], 201);
    }

    public function update(Request $request, string $kind, int $content): JsonResponse
    {
        $this->assertWriteEnabled();
        $row = $this->ownedRow($request, $kind, $content);
        $values = $this->validated($request, $kind);
        $updated = $this->write()->table($this->table($kind))
            ->where('id', $content)->where('district_id', $this->districtId($request))
            ->when($request->user()->role === 'teacher', fn ($query) => $query->where('created_by', $this->actorId($request)))
            ->update([...$values, 'updated_at' => now()]);
        abort_unless($updated === 1, 404);
        $this->audit($request, "learning.{$kind}.updated", $kind, $content, (array) $row, $values);

        return response()->json(['data' => ['id' => (string) $content, ...$values]]);
    }

    public function destroy(Request $request, string $kind, int $content): JsonResponse
    {
        $this->assertWriteEnabled();
        $row = $this->ownedRow($request, $kind, $content);
        $deleted = $this->write()->table($this->table($kind))
            ->where('id', $content)->where('district_id', $this->districtId($request))
            ->when($request->user()->role === 'teacher', fn ($query) => $query->where('created_by', $this->actorId($request)))
            ->delete();
        abort_unless($deleted === 1, 404);
        $this->audit($request, "learning.{$kind}.deleted", $kind, $content, (array) $row, null);

        return response()->json(['data' => ['deleted' => true, 'id' => (string) $content]]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, string $kind): array
    {
        $rules = match ($kind) {
            'assignments' => [
                'title' => ['required', 'string', 'max:220'], 'subject' => ['required', 'string', 'max:120'],
                'description' => ['nullable', 'string', 'max:5000'], 'due_at' => ['required', 'date'],
                'target_group' => ['nullable', 'string', 'max:120'], 'target_mode' => ['required', Rule::in(['all', 'group'])],
                'status' => ['required', Rule::in(['draft', 'open', 'closed'])],
            ],
            'resources' => [
                'title' => ['required', 'string', 'max:220'], 'subject' => ['required', 'string', 'max:120'],
                'description' => ['nullable', 'string', 'max:5000'], 'resource_type' => ['required', Rule::in(['link', 'video', 'youtube', 'pdf', 'exercise'])],
                'url' => ['required', 'url:http,https', 'max:2000'], 'level' => ['nullable', Rule::in(['1', '2', '3'])],
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
                'start_time' => ['required', 'date_format:H:i'], 'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
                'location' => ['nullable', 'string', 'max:255'], 'target_group' => ['nullable', 'string', 'max:120'],
                'notes' => ['nullable', 'string', 'max:5000'],
            ],
            default => abort(404),
        };
        $values = $request->validate($rules);
        if ($request->user()->role === 'teacher' && isset($values['target_group']) && $values['target_group'] !== '') {
            abort_unless(in_array((string) $values['target_group'], array_map('strval', $request->user()->assigned_groups ?? []), true), 403, 'กลุ่มนี้อยู่นอกขอบเขตที่รับผิดชอบ');
        }

        return $values;
    }

    private function ownedRow(Request $request, string $kind, int $id): object
    {
        $query = $this->read()->table($this->table($kind))->where('id', $id)->where('district_id', $this->districtId($request));
        if ($request->user()->role === 'teacher') {
            $query->where('created_by', $this->actorId($request));
        }
        $row = $query->first();
        abort_unless($row, 404);

        return $row;
    }

    private function actorId(Request $request): ?int
    {
        $id = (int) $request->user()->legacy_user_id;
        if ($request->user()->role === 'teacher') {
            abort_unless($id > 0, 403, 'บัญชีครูยังไม่เชื่อมกับผู้ใช้ระบบเดิม');
        }

        return $id > 0 ? $id : null;
    }

    private function table(string $kind): string
    {
        abort_unless(isset(self::TABLES[$kind]), 404);

        return self::TABLES[$kind];
    }

    private function districtId(Request $request): int { return (int) $request->attributes->get('district_id'); }
    private function read() { return $this->database->connection((string) config('legacy.connection')); }
    private function write() { return $this->database->connection((string) config('legacy.write_connection')); }
    private function assertWriteEnabled(): void { abort_unless((bool) config('legacy.write_enabled'), 503, 'ระบบเขียนข้อมูลยังไม่เปิดใช้งาน'); }

    private function audit(Request $request, string $event, string $kind, int $id, ?array $before, ?array $after): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id, 'district_id' => $this->districtId($request), 'event' => $event,
            'auditable_type' => "legacy_learning_{$kind}", 'auditable_id' => $id, 'ip_address' => $request->ip(),
            'before' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'after' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}
