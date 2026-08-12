<?php

namespace App\Services\Learning;

use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class AssignmentWorkflowService
{
    public function __construct(
        private DatabaseManager $database,
        private LearningScorebookService $scorebooks,
    ) {}

    /** @return array<string, mixed> */
    public function workspace(User $viewer, int $districtId, ?int $selectedAssignmentId = null): array
    {
        $catalog = $this->scorebooks->assignmentCatalog($viewer, $districtId);
        $term = $catalog['selected_term'];
        $registrations = $catalog['registrations'];
        $query = $this->database->connection()->table('learning_assignments as assignment')
            ->leftJoin('users as creator', 'creator.id', '=', 'assignment.created_by')
            ->where('assignment.district_id', $districtId)
            ->when($term !== null, fn ($builder) => $builder->where(function ($part) use ($term): void {
                $part->where('assignment.academic_term', $term)->orWhereNull('assignment.academic_term');
            }))
            ->when($viewer->role === 'teacher', fn ($builder) => $builder->where('assignment.created_by', (int) $viewer->id))
            ->when($viewer->role === 'student', fn ($builder) => $builder->whereIn('assignment.status', ['open', 'closed']))
            ->selectRaw("assignment.*, COALESCE(creator.name, '') AS teacher_name")
            ->orderByDesc('assignment.created_at')
            ->limit(250);
        $assignmentRows = $query->get();
        $assignmentIds = $assignmentRows->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $submissions = $assignmentIds === []
            ? collect()
            : $this->database->connection()->table('learning_submissions')
                ->whereIn('assignment_id', $assignmentIds)
                ->get()
                ->groupBy('assignment_id');

        $ownStudentCode = $viewer->role === 'student'
            ? trim((string) ($viewer->student_code ?: $viewer->username))
            : '';
        $assignments = [];
        $audiences = [];
        foreach ($assignmentRows as $assignment) {
            $audience = $this->audience($registrations, $assignment);
            if ($viewer->role === 'student' && ! collect($audience)->contains(
                static fn (array $student): bool => $student['student_code'] === $ownStudentCode,
            )) {
                continue;
            }
            $audiences[(int) $assignment->id] = $audience;
            $assignmentSubmissions = $submissions->get((int) $assignment->id, collect());
            $ownSubmission = $viewer->role === 'student'
                ? $assignmentSubmissions->firstWhere('student_code', $ownStudentCode)
                : null;
            $assignments[] = $this->assignmentPayload(
                $viewer,
                $assignment,
                count($audience),
                $assignmentSubmissions->whereIn('status', ['submitted', 'reviewed'])->count(),
                $ownSubmission,
            );
        }

        $selected = $selectedAssignmentId === null ? ($assignments[0] ?? null) : null;
        if ($selectedAssignmentId !== null) {
            foreach ($assignments as $assignment) {
                if ((int) $assignment['id'] === $selectedAssignmentId) {
                    $selected = $assignment;
                    break;
                }
            }
        }
        if ($selectedAssignmentId !== null && $selected === null) {
            abort(404);
        }

        $students = [];
        if ($selected !== null && $viewer->role !== 'student') {
            $selectedId = (int) $selected['id'];
            $submissionMap = $submissions->get($selectedId, collect())->keyBy('student_code');
            foreach ($audiences[$selectedId] ?? [] as $student) {
                $submission = $submissionMap->get($student['student_code']);
                $students[] = [
                    ...$student,
                    'submission' => $submission === null ? null : $this->submissionPayload($submission),
                ];
            }
        }

        return [
            'term' => $term,
            'terms' => $catalog['terms'],
            'subjects' => $catalog['subjects'],
            'assignments' => $assignments,
            'selected_assignment' => $selected,
            'students' => $students,
        ];
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    public function saveAssignment(User $viewer, int $districtId, array $values, ?int $assignmentId, ?string $ipAddress): array
    {
        abort_unless(in_array($viewer->role, ['teacher', 'admin', 'super_admin'], true), 403);
        $catalog = $this->scorebooks->assignmentCatalog($viewer, $districtId);
        $term = (string) ($catalog['selected_term'] ?? '');
        if ($term === '') {
            throw ValidationException::withMessages(['subject_code' => 'ยังไม่พบรายวิชาลงทะเบียนในภาคเรียนปัจจุบัน']);
        }
        $subject = collect($catalog['subjects'])->first(
            static fn (array $subject): bool => $subject['code'] === $values['subject_code']
                && (int) $subject['level'] === (int) $values['education_level'],
        );
        if ($subject === null) {
            throw ValidationException::withMessages(['subject_code' => 'รายวิชานี้ไม่อยู่ในข้อมูลลงทะเบียนภาคเรียนปัจจุบันหรืออยู่นอกขอบเขตครู']);
        }
        $group = trim((string) ($values['target_group'] ?? ''));
        if ($group !== '' && ! collect($subject['groups'])->contains(
            static fn (array $item): bool => in_array($group, [$item['code'], $item['name']], true),
        )) {
            throw ValidationException::withMessages(['target_group' => 'กลุ่มนี้ไม่มีผู้เรียนลงทะเบียนในรายวิชาที่เลือก']);
        }
        $audience = array_values(array_filter(
            $catalog['registrations'],
            static fn (array $row): bool => $row['subject_code'] === $subject['code']
                && (int) $row['level'] === (int) $subject['level']
                && ($group === '' || in_array($group, [$row['group_code'], $row['group_name']], true)),
        ));
        if ($audience === []) {
            throw ValidationException::withMessages(['target_group' => 'ไม่พบผู้เรียนที่ลงทะเบียนในขอบเขตนี้']);
        }

        $connection = $this->database->connection();
        $before = null;
        if ($assignmentId !== null) {
            $before = $this->ownedAssignment($viewer, $districtId, $assignmentId);
        }
        $stored = [
            'academic_term' => $term,
            'subject_code' => $subject['code'],
            'subject_name' => $subject['name'],
            'education_level' => (int) $subject['level'],
            'title' => trim((string) $values['title']),
            'instructions' => trim((string) ($values['instructions'] ?? '')) ?: null,
            'target_type' => $group === '' ? 'all' : 'group',
            'target_value' => $group === '' ? null : $group,
            'max_score' => (float) $values['max_score'],
            'opens_at' => $values['opens_at'] ?? null,
            'due_at' => $values['due_at'],
            'status' => $values['status'],
            'updated_at' => now(),
        ];
        if ($assignmentId === null) {
            $assignmentId = (int) $connection->table('learning_assignments')->insertGetId([
                ...$stored,
                'district_id' => $districtId,
                'created_by' => (int) $viewer->id,
                'created_at' => now(),
            ]);
            $event = 'learning.assignment.created';
        } else {
            $connection->table('learning_assignments')->where('id', $assignmentId)->update($stored);
            $event = 'learning.assignment.updated';
        }
        $this->audit($viewer, $districtId, $event, $assignmentId, $ipAddress, [
            'term' => $term,
            'subject_code' => $subject['code'],
            'education_level' => (int) $subject['level'],
            'target_group' => $group,
            'audience_count' => count(array_unique(array_column($audience, 'student_code'))),
            'previous_status' => $before?->status,
        ]);

        return ['id' => (string) $assignmentId];
    }

    public function deleteAssignment(User $viewer, int $districtId, int $assignmentId, ?string $ipAddress): void
    {
        $assignment = $this->ownedAssignment($viewer, $districtId, $assignmentId);
        $paths = $this->database->connection()->table('learning_submissions')
            ->where('assignment_id', $assignmentId)->pluck('attachment_path')->filter()->all();
        $this->database->connection()->transaction(function () use ($assignmentId): void {
            $this->database->connection()->table('learning_submissions')->where('assignment_id', $assignmentId)->delete();
            $this->database->connection()->table('learning_assignments')->where('id', $assignmentId)->delete();
        });
        foreach ($paths as $path) {
            if ($this->ownedSubmissionPath($districtId, $assignmentId, (string) $path)) {
                Storage::disk('local')->delete((string) $path);
            }
        }
        $this->audit($viewer, $districtId, 'learning.assignment.deleted', $assignmentId, $ipAddress, [
            'title' => (string) $assignment->title,
        ]);
    }

    /** @return array<string, mixed> */
    public function submit(User $viewer, int $districtId, int $assignmentId, string $type, ?string $url, ?UploadedFile $file, ?string $ipAddress): array
    {
        abort_unless($viewer->role === 'student', 403);
        $workspace = $this->workspace($viewer, $districtId, $assignmentId);
        $assignment = $workspace['selected_assignment'];
        abort_unless($assignment !== null && $assignment['status'] === 'open', 422, 'งานนี้ยังไม่เปิดรับหรือปิดรับแล้ว');
        if ($assignment['opens_at'] !== null && Carbon::parse($assignment['opens_at'])->isFuture()) {
            abort(422, 'งานนี้ยังไม่ถึงเวลาเปิดรับ');
        }
        $studentCode = trim((string) ($viewer->student_code ?: $viewer->username));
        abort_if($studentCode === '', 403);

        $connection = $this->database->connection();
        $existing = $connection->table('learning_submissions')
            ->where('assignment_id', $assignmentId)->where('student_code', $studentCode)->first();
        $newPath = null;
        if ($type === 'pdf' && $file !== null) {
            $newPath = $file->storeAs(
                "learning/submissions/{$districtId}/{$assignmentId}",
                Str::uuid().'.pdf',
                'local',
            );
        }
        $studentId = null;
        if ($connection->getSchemaBuilder()->hasTable('students')) {
            $studentId = $connection->table('students')
                ->where('district_id', $districtId)->where('student_code', $studentCode)->value('id');
        }
        $values = [
            'student_id' => $studentId,
            'submission_type' => $type,
            'external_url' => $type === 'link' ? $url : null,
            'attachment_disk' => $type === 'pdf' ? 'local' : null,
            'attachment_path' => $newPath,
            'original_filename' => $type === 'pdf' ? $file?->getClientOriginalName() : null,
            'file_size' => $type === 'pdf' ? $file?->getSize() : null,
            'submitted_at' => now(),
            'status' => 'submitted',
            'score' => null,
            'feedback' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'updated_at' => now(),
        ];
        try {
            $connection->transaction(function () use ($connection, $assignmentId, $studentCode, $values): void {
                $query = $connection->table('learning_submissions')
                    ->where('assignment_id', $assignmentId)->where('student_code', $studentCode);
                $query->exists()
                    ? $query->update($values)
                    : $connection->table('learning_submissions')->insert([
                        ...$values,
                        'assignment_id' => $assignmentId,
                        'student_code' => $studentCode,
                        'created_at' => now(),
                    ]);
            });
        } catch (\Throwable $exception) {
            if ($newPath !== null) {
                Storage::disk('local')->delete($newPath);
            }
            throw $exception;
        }
        $oldPath = (string) ($existing->attachment_path ?? '');
        if ($oldPath !== '' && $oldPath !== $newPath && $this->ownedSubmissionPath($districtId, $assignmentId, $oldPath)) {
            Storage::disk('local')->delete($oldPath);
        }
        $submission = $connection->table('learning_submissions')
            ->where('assignment_id', $assignmentId)->where('student_code', $studentCode)->firstOrFail();
        $this->audit($viewer, $districtId, 'learning.assignment.submitted', $assignmentId, $ipAddress, [
            'submission_id' => (int) $submission->id,
            'submission_type' => $type,
            'is_late' => Carbon::parse((string) $assignment['due_at'])->isPast(),
        ]);

        return $this->submissionPayload($submission);
    }

    /** @return array<string, mixed> */
    public function review(User $viewer, int $districtId, int $assignmentId, int $submissionId, ?float $score, ?string $feedback, ?string $ipAddress): array
    {
        $assignment = $this->ownedAssignment($viewer, $districtId, $assignmentId);
        if ($score !== null && $score > (float) $assignment->max_score) {
            throw ValidationException::withMessages(['score' => 'คะแนนต้องไม่เกินคะแนนเต็มของงาน']);
        }
        $workspace = $this->workspace($viewer, $districtId, $assignmentId);
        $allowedCodes = array_fill_keys(array_column($workspace['students'], 'student_code'), true);
        $submission = $this->database->connection()->table('learning_submissions')
            ->where('id', $submissionId)->where('assignment_id', $assignmentId)->first();
        abort_unless($submission !== null && isset($allowedCodes[(string) $submission->student_code]), 404);
        $this->database->connection()->table('learning_submissions')->where('id', $submissionId)->update([
            'score' => $score,
            'feedback' => trim((string) $feedback) ?: null,
            'status' => 'reviewed',
            'reviewed_by' => (int) $viewer->id,
            'reviewed_at' => now(),
            'updated_at' => now(),
        ]);
        $this->audit($viewer, $districtId, 'learning.assignment.reviewed', $assignmentId, $ipAddress, [
            'submission_id' => $submissionId,
            'student_code' => (string) $submission->student_code,
            'score' => $score,
        ]);

        return $this->submissionPayload(
            $this->database->connection()->table('learning_submissions')->where('id', $submissionId)->firstOrFail(),
        );
    }

    public function submissionForDownload(User $viewer, int $districtId, int $assignmentId, int $submissionId): object
    {
        $submission = $this->database->connection()->table('learning_submissions as submission')
            ->join('learning_assignments as assignment', 'assignment.id', '=', 'submission.assignment_id')
            ->where('assignment.id', $assignmentId)
            ->where('assignment.district_id', $districtId)
            ->where('submission.id', $submissionId)
            ->select('submission.*', 'assignment.created_by')
            ->first();
        abort_unless($submission !== null && $submission->submission_type === 'pdf', 404);
        if ($viewer->role === 'student') {
            abort_unless((string) $submission->student_code === trim((string) ($viewer->student_code ?: $viewer->username)), 404);
        } elseif ($viewer->role === 'teacher') {
            abort_unless((int) $submission->created_by === (int) $viewer->id, 404);
        }
        $path = (string) $submission->attachment_path;
        abort_unless($this->ownedSubmissionPath($districtId, $assignmentId, $path) && Storage::disk('local')->exists($path), 404);

        return $submission;
    }

    /** @param list<array<string, mixed>> $registrations @return list<array<string, string|int>> */
    private function audience(array $registrations, object $assignment): array
    {
        $students = [];
        foreach ($registrations as $row) {
            if ((string) $row['subject_code'] !== (string) $assignment->subject_code) {
                continue;
            }
            if ($assignment->education_level !== null && (int) $row['level'] !== (int) $assignment->education_level) {
                continue;
            }
            $target = trim((string) ($assignment->target_value ?? ''));
            if ($target !== '' && ! in_array($target, [(string) $row['group_code'], (string) $row['group_name']], true)) {
                continue;
            }
            $students[(string) $row['student_code']] = [
                'student_code' => (string) $row['student_code'],
                'full_name' => (string) $row['student_name'],
                'group_code' => (string) $row['group_code'],
                'group_name' => (string) $row['group_name'],
                'education_level' => (int) $row['level'],
            ];
        }
        $students = array_values($students);
        usort($students, static fn (array $left, array $right): int => [
            $left['group_name'], $left['full_name'], $left['student_code'],
        ] <=> [
            $right['group_name'], $right['full_name'], $right['student_code'],
        ]);

        return $students;
    }

    /** @return array<string, mixed> */
    private function assignmentPayload(User $viewer, object $assignment, int $studentCount, int $submittedCount, ?object $ownSubmission): array
    {
        return [
            'id' => (string) $assignment->id,
            'title' => (string) $assignment->title,
            'instructions' => (string) ($assignment->instructions ?? ''),
            'academic_term' => (string) ($assignment->academic_term ?? ''),
            'subject_code' => (string) ($assignment->subject_code ?? ''),
            'subject_name' => (string) ($assignment->subject_name ?: $assignment->subject_code),
            'education_level' => $assignment->education_level === null ? null : (int) $assignment->education_level,
            'target_group' => (string) ($assignment->target_value ?? ''),
            'max_score' => (float) ($assignment->max_score ?? 0),
            'opens_at' => $assignment->opens_at,
            'due_at' => $assignment->due_at,
            'status' => (string) $assignment->status,
            'teacher_name' => trim((string) ($assignment->teacher_name ?? '')) ?: 'ครูผู้สอน',
            'student_count' => $studentCount,
            'submitted_count' => $submittedCount,
            'can_edit' => in_array($viewer->role, ['admin', 'super_admin'], true)
                || ($viewer->role === 'teacher' && (int) $assignment->created_by === (int) $viewer->id),
            'submission' => $ownSubmission === null ? null : $this->submissionPayload($ownSubmission),
        ];
    }

    /** @return array<string, mixed> */
    private function submissionPayload(object $submission): array
    {
        return [
            'id' => (string) $submission->id,
            'student_code' => (string) ($submission->student_code ?? ''),
            'type' => (string) ($submission->submission_type ?? ''),
            'url' => (string) ($submission->external_url ?? ''),
            'filename' => (string) ($submission->original_filename ?? ''),
            'file_size' => $submission->file_size === null ? null : (int) $submission->file_size,
            'submitted_at' => $submission->submitted_at,
            'status' => (string) $submission->status,
            'score' => $submission->score === null ? null : (float) $submission->score,
            'feedback' => (string) ($submission->feedback ?? ''),
            'reviewed_at' => $submission->reviewed_at,
            'download_url' => $submission->submission_type === 'pdf'
                ? '/api/v1/learning/assignments/'.(int) $submission->assignment_id.'/submissions/'.(int) $submission->id.'/file'
                : null,
        ];
    }

    private function ownedAssignment(User $viewer, int $districtId, int $assignmentId): object
    {
        abort_unless(in_array($viewer->role, ['teacher', 'admin', 'super_admin'], true), 403);
        $query = $this->database->connection()->table('learning_assignments')
            ->where('id', $assignmentId)->where('district_id', $districtId);
        if ($viewer->role === 'teacher') {
            $query->where('created_by', (int) $viewer->id);
        }
        $assignment = $query->first();
        abort_unless($assignment !== null, 404);

        return $assignment;
    }

    private function ownedSubmissionPath(int $districtId, int $assignmentId, string $path): bool
    {
        return preg_match(
            '#^learning/submissions/'.preg_quote((string) $districtId, '#').'/'.preg_quote((string) $assignmentId, '#').'/[0-9a-f-]+\.pdf$#i',
            $path,
        ) === 1;
    }

    /** @param array<string, mixed> $context */
    private function audit(User $viewer, int $districtId, string $event, int $assignmentId, ?string $ipAddress, array $context): void
    {
        $this->database->connection()->table('audit_logs')->insert([
            'user_id' => (int) $viewer->id,
            'district_id' => $districtId,
            'event' => $event,
            'auditable_type' => 'system_learning_assignment',
            'auditable_id' => $assignmentId,
            'ip_address' => $ipAddress,
            'context' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
