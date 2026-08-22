<?php

namespace App\Services\Learning;

use App\Domain\Students\Models\Grade;
use App\Domain\Students\Models\Student;
use App\Domain\Students\Repositories\StudentRepository;
use App\Domain\Students\Services\LegacyStudentReportService;
use App\Domain\Students\Services\StudentDirectoryService;
use App\Domain\Students\Support\AcademicTerm;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

final readonly class LearningScorebookService
{
    public function __construct(
        private DatabaseManager $database,
        private LegacyStudentReportService $legacyReports,
        private StudentDirectoryService $directory,
        private StudentRepository $students,
    ) {}

    /** @return array<string, mixed> */
    public function studentScores(User $viewer, int $districtId): array
    {
        abort_unless($viewer->role === 'student', 403);
        $accessible = $this->directory->accessibleStudents($viewer);
        if ($accessible === []) {
            return $this->emptyStudentScores();
        }
        $registered = [];
        $gradesByStudent = $this->students->gradesForMany($accessible);
        foreach ($accessible as $student) {
            $studentKey = "{$student->districtId}|{$student->level}|{$student->code}";
            foreach ($gradesByStudent[$studentKey] ?? [] as $grade) {
                $registered[$grade->term.'|'.$student->level.'|'.$grade->subjectCode] = [
                    'credits' => $grade->credits,
                    'group_code' => $student->groupCode,
                    'group_name' => $student->groupName,
                ];
            }
        }

        $studentCode = trim((string) ($viewer->student_code ?: $viewer->username));
        $scorebooks = $this->database->connection()->table('learning_scorebooks as scorebook')
            ->where('scorebook.district_id', $districtId)
            ->whereExists(function ($query) use ($studentCode): void {
                $query->selectRaw('1')->from('learning_score_entries as own_entry')
                    ->whereColumn('own_entry.scorebook_id', 'scorebook.id')
                    ->where('own_entry.student_code', $studentCode);
            })
            ->orderByDesc('scorebook.academic_term')->orderBy('scorebook.subject_code')
            ->get(['scorebook.id', 'scorebook.academic_term', 'scorebook.subject_code', 'scorebook.subject_name', 'scorebook.education_level', 'scorebook.group_code', 'scorebook.coursework_weight', 'scorebook.final_exam_weight']);
        $eligible = $scorebooks->filter(static function (object $scorebook) use ($registered): bool {
            $key = (string) $scorebook->academic_term.'|'.(int) $scorebook->education_level.'|'.(string) $scorebook->subject_code;
            $registration = $registered[$key] ?? null;

            return $registration !== null && ((string) $scorebook->group_code === ''
                || in_array((string) $scorebook->group_code, [$registration['group_code'], $registration['group_name']], true));
        })->values();
        if ($eligible->isEmpty()) {
            return $this->emptyStudentScores();
        }
        $latestTerm = AcademicTerm::latest($eligible->pluck('academic_term')->map(static fn ($term): string => (string) $term)->all());
        $eligible = $eligible->filter(static fn (object $scorebook): bool => (string) $scorebook->academic_term === $latestTerm)->values();
        $ids = $eligible->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $componentRows = $this->database->connection()->table('learning_score_components')
            ->whereIn('scorebook_id', $ids)->orderBy('position')->orderBy('id')
            ->get(['scorebook_id', 'id', 'category', 'title', 'max_score', 'position'])->groupBy('scorebook_id');
        $scoreRows = $this->database->connection()->table('learning_score_entries')
            ->whereIn('scorebook_id', $ids)->where('student_code', $studentCode)
            ->get(['scorebook_id', 'component_id', 'score'])
            ->keyBy(static fn (object $entry): string => (int) $entry->scorebook_id.'|'.(int) $entry->component_id);
        $notes = $this->database->connection()->table('learning_score_notes')
            ->whereIn('scorebook_id', $ids)->where('student_code', $studentCode)->pluck('note', 'scorebook_id');
        $courses = [];
        foreach ($eligible as $scorebook) {
            $key = (string) $scorebook->academic_term.'|'.(int) $scorebook->education_level.'|'.(string) $scorebook->subject_code;
            $courseComponents = $componentRows->get((int) $scorebook->id, collect());
            $componentScore = function (object $component) use ($scoreRows, $scorebook): float {
                $entry = $scoreRows->get((int) $scorebook->id.'|'.(int) $component->id);

                return $entry?->score === null ? 0.0 : (float) $entry->score;
            };
            $total = (float) $courseComponents->sum($componentScore);
            $hasStructuredRatio = $scorebook->coursework_weight !== null && $scorebook->final_exam_weight !== null;
            $courseworkScore = $hasStructuredRatio
                ? (float) $courseComponents->where('category', 'coursework')->sum($componentScore)
                : $total;
            $finalExamScore = $hasStructuredRatio
                ? (float) $courseComponents->where('category', 'final_exam')->sum($componentScore)
                : null;
            $maximum = (float) $courseComponents->sum(static fn (object $component): float => (float) $component->max_score);
            $courses[] = [
                'id' => 'scorebook-'.(int) $scorebook->id,
                'subject_code' => (string) $scorebook->subject_code,
                'subject_name' => (string) $scorebook->subject_name,
                'credits' => (float) ($registered[$key]['credits'] ?? 0),
                'score_ratio' => $hasStructuredRatio ? (int) $scorebook->coursework_weight.':'.(int) $scorebook->final_exam_weight : null,
                'assignment_score' => round($courseworkScore, 2),
                'exam_score' => $finalExamScore === null ? null : round($finalExamScore, 2),
                'total_score' => round($total, 2),
                'maximum_score' => round($maximum, 2),
                'grade' => null,
                'status' => 'studying',
                'note' => $notes[(int) $scorebook->id] ?? null,
                'components' => $courseComponents->map(function (object $component) use ($scoreRows, $scorebook): array {
                    $entry = $scoreRows->get((int) $scorebook->id.'|'.(int) $component->id);

                    return [
                        'id' => (string) $component->id,
                        'category' => (string) $component->category,
                        'title' => (string) $component->title,
                        'score' => $entry?->score === null ? null : (float) $entry->score,
                        'max_score' => (float) $component->max_score,
                    ];
                })->all(),
            ];
        }

        return [
            'term' => $latestTerm,
            'summary' => [
                'score' => round(array_sum(array_column($courses, 'total_score')), 2),
                'maximum_score' => round(array_sum(array_column($courses, 'maximum_score')), 2),
                'items' => count($courses),
            ],
            'courses' => $courses,
            'disclaimer' => 'คะแนนเก็บภายในระบบ ยังไม่ใช่ผลการเรียนปลายภาค',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function workspace(User $viewer, int $districtId, array $filters): array
    {
        abort_unless(in_array($viewer->role, ['teacher', 'admin', 'super_admin'], true), 403);
        $source = $this->registrationSource($viewer, $districtId, $filters);
        $rows = $source['rows'];
        $subjects = $this->subjects($rows);
        $requestedSubject = trim((string) ($filters['subject_code'] ?? ''));
        $requestedLevel = isset($filters['level']) ? (int) $filters['level'] : null;
        $selectedSubject = $this->selectedSubject($subjects, $requestedSubject, $requestedLevel);

        $selectedRows = $selectedSubject === null ? [] : array_values(array_filter(
            $rows,
            static fn (array $row): bool => (string) $row['subject_code'] === (string) $selectedSubject['code']
                && (int) $row['level'] === (int) $selectedSubject['level'],
        ));
        $group = trim((string) ($filters['group'] ?? ''));
        if ($group !== '') {
            $selectedRows = array_values(array_filter(
                $selectedRows,
                static fn (array $row): bool => in_array($group, [(string) $row['group_code'], (string) $row['group_name']], true),
            ));
        }

        $scorebook = $selectedSubject === null
            ? null
            : $this->matchingScorebook($viewer, $districtId, (string) $source['selected_term'], $selectedSubject, $group, $filters);
        $components = $scorebook === null ? [] : $this->components((int) $scorebook->id);
        [$scores, $notes] = $scorebook === null
            ? [[], []]
            : $this->savedStudentValues((int) $scorebook->id);

        $studentRows = [];
        foreach ($selectedRows as $row) {
            $studentCode = (string) $row['student_code'];
            $studentScores = [];
            $total = 0.0;
            $courseworkTotal = 0.0;
            $finalExamTotal = 0.0;
            foreach ($components as $component) {
                $key = (string) $component['id'];
                $value = $scores[$studentCode][$key] ?? null;
                $studentScores[$key] = $value;
                $total += $value ?? 0;
                if ($component['category'] === 'final_exam') {
                    $finalExamTotal += $value ?? 0;
                } else {
                    $courseworkTotal += $value ?? 0;
                }
            }
            $hasStructuredRatio = $scorebook !== null && $this->scoreRatio($scorebook) !== null;
            $studentRows[] = [
                'student_code' => $studentCode,
                'full_name' => (string) $row['student_name'],
                'group_code' => (string) $row['group_code'],
                'group_name' => (string) $row['group_name'],
                'scores' => $studentScores,
                'coursework_score' => round($courseworkTotal, 2),
                'final_exam_score' => $hasStructuredRatio ? round($finalExamTotal, 2) : null,
                'total' => round($total, 2),
                'note' => $notes[$studentCode] ?? null,
            ];
        }
        usort($studentRows, static fn (array $left, array $right): int => [
            $left['group_name'], $left['full_name'], $left['student_code'],
        ] <=> [
            $right['group_name'], $right['full_name'], $right['student_code'],
        ]);

        return [
            'terms' => $source['terms'],
            'selected_term' => $source['selected_term'],
            'subjects' => $subjects,
            'selected_subject' => $selectedSubject,
            'scorebook' => $scorebook === null ? null : [
                'id' => (string) $scorebook->id,
                'created_by' => (string) $scorebook->created_by,
                // A scorebook belongs to its district/term/subject/group scope,
                // not exclusively to the account that created it. This lets a
                // replacement or co-teacher maintain scores for an assigned
                // group while the registration source still enforces scope.
                'can_edit' => $viewer->role !== 'teacher' || $selectedRows !== [],
                'group' => (string) $scorebook->group_code,
                'score_ratio' => $this->scoreRatio($scorebook),
                'coursework_weight' => $scorebook->coursework_weight === null ? null : (int) $scorebook->coursework_weight,
                'final_exam_weight' => $scorebook->final_exam_weight === null ? null : (int) $scorebook->final_exam_weight,
                'components' => $components,
                'maximum_score' => round(array_sum(array_column($components, 'max_score')), 2),
            ],
            'students' => $studentRows,
        ];
    }

    /**
     * Share the same current-term registration source with assignment flows.
     * The returned rows are already district and teacher-group scoped. Student
     * viewers are additionally reduced to their own imported student code.
     *
     * @return array{terms: list<string>, selected_term: ?string, subjects: list<array<string, mixed>>, registrations: list<array<string, mixed>>}
     */
    public function assignmentCatalog(User $viewer, int $districtId): array
    {
        $source = $this->registrationSource($viewer, $districtId, []);
        $rows = $source['rows'];
        if ($viewer->role === 'student') {
            $studentCode = trim((string) ($viewer->student_code ?: $viewer->username));
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => (string) $row['student_code'] === $studentCode,
            ));
        }

        return [
            'terms' => $source['terms'],
            'selected_term' => $source['selected_term'],
            'subjects' => $this->subjects($rows),
            'registrations' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function create(User $viewer, int $districtId, array $values, ?string $ipAddress): array
    {
        $workspace = $this->workspace($viewer, $districtId, [
            'term' => $values['term'],
            'subject_code' => $values['subject_code'],
            'level' => $values['level'],
            'group' => $values['group'] ?? '',
        ]);
        $subject = $workspace['selected_subject'];
        if ($subject === null || $workspace['students'] === []) {
            throw ValidationException::withMessages([
                'subject_code' => 'ไม่พบรายวิชาที่ลงทะเบียนในขอบเขตครูและภาคเรียนที่เลือก',
            ]);
        }
        [$courseworkWeight, $finalExamWeight] = $this->assertScoreStructure($values['score_ratio'], $values['components']);
        $group = trim((string) ($values['group'] ?? ''));
        $connection = $this->database->connection();

        try {
            $id = $connection->transaction(function () use ($connection, $viewer, $districtId, $values, $subject, $group, $courseworkWeight, $finalExamWeight, $ipAddress): int {
                $existing = $connection->table('learning_scorebooks')
                    ->where('district_id', $districtId)
                    ->where('academic_term', $values['term'])
                    ->where('subject_code', $values['subject_code'])
                    ->where('education_level', (int) $values['level'])
                    ->where('group_code', $group)
                    ->first();
                if ($existing !== null) {
                    throw ValidationException::withMessages(['subject_code' => 'มีสมุดคะแนนสำหรับรายวิชาและกลุ่มนี้แล้ว']);
                }

                $scorebookId = (int) $connection->table('learning_scorebooks')->insertGetId([
                    'district_id' => $districtId,
                    'created_by' => (int) $viewer->id,
                    'academic_term' => $values['term'],
                    'subject_code' => $values['subject_code'],
                    'subject_name' => $subject['name'],
                    'education_level' => (int) $values['level'],
                    'group_code' => $group,
                    'coursework_weight' => $courseworkWeight,
                    'final_exam_weight' => $finalExamWeight,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->replaceComponents($scorebookId, $values['components']);
                $this->audit($viewer, $districtId, 'learning.scorebook.created', $scorebookId, $ipAddress, [
                    'term' => $values['term'], 'subject_code' => $values['subject_code'], 'level' => (int) $values['level'], 'group' => $group,
                    'score_ratio' => $values['score_ratio'],
                ]);

                return $scorebookId;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['subject_code' => 'มีสมุดคะแนนสำหรับรายวิชาและกลุ่มนี้แล้ว']);
        }

        return [
            'id' => (string) $id,
            'score_ratio' => $values['score_ratio'],
            'coursework_weight' => $courseworkWeight,
            'final_exam_weight' => $finalExamWeight,
            'components' => $this->components($id),
        ];
    }

    /** @param list<array<string, mixed>> $components
     * @return array<string, mixed>
     */
    public function updateStructure(User $viewer, int $districtId, int $scorebookId, string $scoreRatio, array $components, ?string $ipAddress): array
    {
        $scorebook = $this->scopedScorebook($districtId, $scorebookId);
        $this->assertCurrentScorebookScope($viewer, $districtId, $scorebook);
        [$courseworkWeight, $finalExamWeight] = $this->assertScoreStructure($scoreRatio, $components);
        $this->assertUniqueComponentIds($components);
        $this->assertScoresFitStructure($scorebookId, $components);
        $this->database->connection()->transaction(function () use ($viewer, $districtId, $scorebookId, $scoreRatio, $courseworkWeight, $finalExamWeight, $components, $ipAddress): void {
            $before = $this->components($scorebookId);
            $this->replaceComponents($scorebookId, $components);
            $this->database->connection()->table('learning_scorebooks')->where('id', $scorebookId)->update([
                'coursework_weight' => $courseworkWeight,
                'final_exam_weight' => $finalExamWeight,
                'updated_at' => now(),
            ]);
            $this->audit($viewer, $districtId, 'learning.scorebook.structure_updated', $scorebookId, $ipAddress, [
                'before_components' => $before,
                'after_components' => $this->components($scorebookId),
                'score_ratio' => $scoreRatio,
            ]);
        });

        $result = $this->components($scorebookId);

        return [
            'id' => (string) $scorebookId,
            'score_ratio' => $scoreRatio,
            'coursework_weight' => $courseworkWeight,
            'final_exam_weight' => $finalExamWeight,
            'components' => $result,
            'maximum_score' => round(array_sum(array_column($result, 'max_score')), 2),
        ];
    }

    /** @param list<array<string, mixed>> $studentValues
     * @return array<string, mixed>
     */
    public function saveEntries(User $viewer, int $districtId, int $scorebookId, array $studentValues, ?string $ipAddress): array
    {
        $scorebook = $this->scopedScorebook($districtId, $scorebookId);
        $workspace = $this->workspace($viewer, $districtId, [
            'term' => (string) $scorebook->academic_term,
            'subject_code' => (string) $scorebook->subject_code,
            'level' => (int) $scorebook->education_level,
            'group' => (string) $scorebook->group_code,
            'scorebook_id' => $scorebookId,
        ]);
        abort_unless($workspace['selected_subject'] !== null && $workspace['students'] !== [], 404);
        $allowedStudents = array_fill_keys(array_column($workspace['students'], 'student_code'), true);
        $components = collect($this->components($scorebookId))->keyBy(fn (array $component): string => (string) $component['id']);
        $seenStudents = [];

        foreach ($studentValues as $studentIndex => $student) {
            $studentCode = trim((string) $student['student_code']);
            if (isset($seenStudents[$studentCode])) {
                throw ValidationException::withMessages(["students.{$studentIndex}.student_code" => 'รหัสนักศึกษาซ้ำในคำขอบันทึก']);
            }
            $seenStudents[$studentCode] = true;
            if (! isset($allowedStudents[$studentCode])) {
                throw ValidationException::withMessages(["students.{$studentIndex}.student_code" => 'นักศึกษาไม่ได้ลงทะเบียนรายวิชานี้หรืออยู่นอกขอบเขตครู']);
            }
            $seenComponents = [];
            foreach ($student['scores'] as $scoreIndex => $score) {
                $componentId = (string) $score['component_id'];
                if (isset($seenComponents[$componentId])) {
                    throw ValidationException::withMessages(["students.{$studentIndex}.scores.{$scoreIndex}.component_id" => 'ช่องคะแนนซ้ำสำหรับนักศึกษาคนเดียวกัน']);
                }
                $seenComponents[$componentId] = true;
                $component = $components->get($componentId);
                if ($component === null) {
                    throw ValidationException::withMessages(["students.{$studentIndex}.scores.{$scoreIndex}.component_id" => 'ช่องคะแนนไม่อยู่ในสมุดคะแนนนี้']);
                }
                if ($score['score'] !== null && (float) $score['score'] > (float) $component['max_score']) {
                    throw ValidationException::withMessages(["students.{$studentIndex}.scores.{$scoreIndex}.score" => 'คะแนนต้องไม่เกินคะแนนเต็มของช่อง']);
                }
            }
        }

        $connection = $this->database->connection();
        $connection->transaction(function () use ($connection, $viewer, $districtId, $scorebookId, $studentValues, $ipAddress): void {
            foreach ($studentValues as $student) {
                $studentCode = trim((string) $student['student_code']);
                foreach ($student['scores'] as $score) {
                    $identity = ['scorebook_id' => $scorebookId, 'component_id' => (int) $score['component_id'], 'student_code' => $studentCode];
                    $query = $connection->table('learning_score_entries')->where($identity);
                    $values = ['score' => $score['score'], 'updated_by' => (int) $viewer->id, 'updated_at' => now()];
                    $query->exists()
                        ? $query->update($values)
                        : $connection->table('learning_score_entries')->insert([...$identity, ...$values, 'created_at' => now()]);
                }
                $note = trim((string) ($student['note'] ?? ''));
                $noteIdentity = ['scorebook_id' => $scorebookId, 'student_code' => $studentCode];
                $noteQuery = $connection->table('learning_score_notes')->where($noteIdentity);
                $noteValues = ['note' => $note === '' ? null : $note, 'updated_by' => (int) $viewer->id, 'updated_at' => now()];
                $noteQuery->exists()
                    ? $noteQuery->update($noteValues)
                    : $connection->table('learning_score_notes')->insert([...$noteIdentity, ...$noteValues, 'created_at' => now()]);
            }
            $this->audit($viewer, $districtId, 'learning.scorebook.entries_saved', $scorebookId, $ipAddress, [
                'student_count' => count($studentValues),
            ]);
        });

        return ['id' => (string) $scorebookId, 'saved_students' => count($studentValues)];
    }

    /** @param array<string, mixed> $filters
     * @return array{terms: list<string>, selected_term: ?string, rows: list<array<string, mixed>>}
     */
    private function registrationSource(User $viewer, int $districtId, array $filters): array
    {
        $sourceFilters = array_filter([
            'term' => $filters['term'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        if ((bool) config('system_data.student_enabled')) {
            return $this->legacyReports->scorebookRegistrations($viewer, $districtId, $sourceFilters);
        }

        $students = $this->directory->accessibleStudents($viewer);
        $gradesByStudent = $this->students->gradesForMany($students);
        $terms = [];
        foreach ($gradesByStudent as $grades) {
            foreach ($grades as $grade) {
                $terms[] = $grade->term;
            }
        }
        $terms = array_values(array_unique(array_filter(array_map(AcademicTerm::normalize(...), $terms))));
        usort($terms, static fn (string $left, string $right): int => AcademicTerm::compare($right, $left));
        $selectedTerm = AcademicTerm::normalize((string) ($sourceFilters['term'] ?? '')) ?? ($terms[0] ?? null);
        $rows = [];
        foreach ($students as $student) {
            $key = "{$student->districtId}|{$student->level}|{$student->code}";
            foreach ($gradesByStudent[$key] ?? [] as $grade) {
                if ($selectedTerm === null || $grade->term !== $selectedTerm) {
                    continue;
                }
                $rows[] = $this->demoRegistrationRow($student, $grade);
            }
        }

        return ['terms' => $terms, 'selected_term' => $selectedTerm, 'rows' => $rows];
    }

    /** @return array<string, mixed> */
    private function demoRegistrationRow(Student $student, Grade $grade): array
    {
        return [
            'level' => $student->level,
            'student_code' => $student->code,
            'student_name' => $student->fullName(),
            'group_code' => $student->groupCode,
            'group_name' => $student->groupName,
            'subject_code' => $grade->subjectCode,
            'subject_name' => $grade->subjectName,
            'subject_credit' => $grade->credits,
            'subject_type' => $grade->subjectType,
        ];
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private function subjects(array $rows): array
    {
        $subjects = [];
        foreach ($rows as $row) {
            $key = (int) $row['level'].'|'.(string) $row['subject_code'];
            $subjects[$key] ??= [
                'code' => (string) $row['subject_code'],
                'name' => (string) $row['subject_name'],
                'level' => (int) $row['level'],
                'level_label' => $this->levelLabel((int) $row['level']),
                '_students' => [],
                '_groups' => [],
            ];
            $subjects[$key]['_students'][(string) $row['student_code']] = true;
            $groupCode = (string) $row['group_code'];
            $subjects[$key]['_groups'][$groupCode] = ['code' => $groupCode, 'name' => (string) $row['group_name']];
        }
        $result = [];
        foreach ($subjects as $subject) {
            $groups = array_values($subject['_groups']);
            usort($groups, static fn (array $left, array $right): int => strnatcasecmp($left['name'], $right['name']));
            $result[] = [
                'code' => $subject['code'], 'name' => $subject['name'], 'level' => $subject['level'],
                'level_label' => $subject['level_label'], 'student_count' => count($subject['_students']), 'groups' => $groups,
            ];
        }
        usort($result, static fn (array $left, array $right): int => [$left['level'], $left['code']] <=> [$right['level'], $right['code']]);

        return $result;
    }

    /** @param list<array<string, mixed>> $subjects @return array<string, mixed>|null */
    private function selectedSubject(array $subjects, string $requestedCode, ?int $requestedLevel): ?array
    {
        if ($requestedCode === '') {
            return $subjects[0] ?? null;
        }
        $matches = array_values(array_filter($subjects, static fn (array $subject): bool => $subject['code'] === $requestedCode
            && ($requestedLevel === null || $subject['level'] === $requestedLevel)));

        return count($matches) === 1 ? $matches[0] : null;
    }

    /** @param array<string, mixed> $selectedSubject @param array<string, mixed> $filters */
    private function matchingScorebook(User $viewer, int $districtId, string $term, array $selectedSubject, string $group, array $filters): ?object
    {
        $query = $this->database->connection()->table('learning_scorebooks')
            ->where('district_id', $districtId)
            ->where('academic_term', $term)
            ->where('subject_code', $selectedSubject['code'])
            ->where('education_level', $selectedSubject['level'])
            ->where('group_code', $group);
        if (isset($filters['scorebook_id'])) {
            $query->where('id', (int) $filters['scorebook_id']);
        }

        return $query->orderByDesc('updated_at')->orderByDesc('id')->first();
    }

    private function scopedScorebook(int $districtId, int $scorebookId): object
    {
        $scorebook = $this->database->connection()->table('learning_scorebooks')
            ->where('id', $scorebookId)
            ->where('district_id', $districtId)
            ->first();
        abort_unless($scorebook !== null, 404);

        return $scorebook;
    }

    /** @return list<array{id: string, key: string, category: string, title: string, max_score: float, position: int}> */
    private function components(int $scorebookId): array
    {
        return $this->database->connection()->table('learning_score_components')
            ->where('scorebook_id', $scorebookId)->orderBy('position')->orderBy('id')->get()
            ->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'key' => (string) $row->id,
                'category' => (string) ($row->category ?: 'coursework'),
                'title' => (string) $row->title,
                'max_score' => (float) $row->max_score,
                'position' => (int) $row->position,
            ])->all();
    }

    /** @return array{array<string, array<string, ?float>>, array<string, ?string>} */
    private function savedStudentValues(int $scorebookId): array
    {
        $scores = [];
        foreach ($this->database->connection()->table('learning_score_entries')->where('scorebook_id', $scorebookId)->get(['student_code', 'component_id', 'score']) as $entry) {
            $scores[(string) $entry->student_code][(string) $entry->component_id] = $entry->score === null ? null : (float) $entry->score;
        }
        $notes = [];
        foreach ($this->database->connection()->table('learning_score_notes')->where('scorebook_id', $scorebookId)->get(['student_code', 'note']) as $note) {
            $notes[(string) $note->student_code] = $note->note === null ? null : (string) $note->note;
        }

        return [$scores, $notes];
    }

    /** @param list<array<string, mixed>> $components @return array{int, int} */
    private function assertScoreStructure(string $scoreRatio, array $components): array
    {
        [$courseworkWeight, $finalExamWeight] = match ($scoreRatio) {
            '60:40' => [60, 40],
            '70:30' => [70, 30],
            '80:20' => [80, 20],
            default => throw ValidationException::withMessages(['score_ratio' => 'โครงสร้างคะแนนต้องเป็น 60:40, 70:30 หรือ 80:20']),
        };
        $courseworkTotal = 0.0;
        $finalExamTotal = 0.0;
        $finalExamComponents = 0;
        foreach ($components as $component) {
            if (($component['category'] ?? null) === 'final_exam') {
                $finalExamTotal += (float) $component['max_score'];
                $finalExamComponents++;
            } else {
                $courseworkTotal += (float) $component['max_score'];
            }
        }
        if ($finalExamComponents !== 1) {
            throw ValidationException::withMessages(['components' => 'ต้องมีช่องคะแนนสอบปลายภาคหนึ่งช่อง']);
        }
        if (abs($courseworkTotal - $courseworkWeight) > 0.001 || abs($finalExamTotal - $finalExamWeight) > 0.001) {
            throw ValidationException::withMessages([
                'components' => "คะแนนเก็บต้องรวม {$courseworkWeight} คะแนน และคะแนนสอบปลายภาคต้องรวม {$finalExamWeight} คะแนน",
            ]);
        }

        return [$courseworkWeight, $finalExamWeight];
    }

    /** @param list<array<string, mixed>> $components */
    private function replaceComponents(int $scorebookId, array $components): void
    {
        $connection = $this->database->connection();
        $existingIds = $connection->table('learning_score_components')->where('scorebook_id', $scorebookId)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $keptIds = [];
        $connection->table('learning_score_components')->where('scorebook_id', $scorebookId)->update(['position' => $connection->raw('position + 1000')]);

        foreach (array_values($components) as $position => $component) {
            $id = isset($component['id']) ? (int) $component['id'] : 0;
            $values = ['category' => $component['category'], 'title' => $component['title'], 'max_score' => $component['max_score'], 'position' => $position, 'updated_at' => now()];
            if ($id > 0 && in_array($id, $existingIds, true)) {
                $connection->table('learning_score_components')->where('scorebook_id', $scorebookId)->where('id', $id)->update($values);
                $keptIds[] = $id;
            } elseif ($id === 0) {
                $keptIds[] = (int) $connection->table('learning_score_components')->insertGetId([...$values, 'scorebook_id' => $scorebookId, 'created_at' => now()]);
            } else {
                throw ValidationException::withMessages(['components' => 'มีช่องคะแนนที่ไม่ได้อยู่ในสมุดคะแนนนี้']);
            }
        }
        $removed = array_values(array_diff($existingIds, $keptIds));
        if ($removed !== []) {
            $connection->table('learning_score_entries')->where('scorebook_id', $scorebookId)->whereIn('component_id', $removed)->delete();
            $connection->table('learning_score_components')->where('scorebook_id', $scorebookId)->whereIn('id', $removed)->delete();
        }
        $connection->table('learning_scorebooks')->where('id', $scorebookId)->update(['updated_at' => now()]);
    }

    /** @param array<string, mixed> $context */
    private function audit(User $viewer, int $districtId, string $event, int $scorebookId, ?string $ipAddress, array $context): void
    {
        $this->database->connection()->table('audit_logs')->insert([
            'user_id' => $viewer->id,
            'district_id' => $districtId,
            'event' => $event,
            'auditable_type' => 'system_learning_scorebook',
            'auditable_id' => $scorebookId,
            'ip_address' => $ipAddress,
            'context' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    private function assertCurrentScorebookScope(User $viewer, int $districtId, object $scorebook): void
    {
        $workspace = $this->workspace($viewer, $districtId, [
            'term' => (string) $scorebook->academic_term,
            'subject_code' => (string) $scorebook->subject_code,
            'level' => (int) $scorebook->education_level,
            'group' => (string) $scorebook->group_code,
            'scorebook_id' => (int) $scorebook->id,
        ]);
        abort_unless($workspace['selected_subject'] !== null && $workspace['students'] !== [], 404);
    }

    /** @param list<array<string, mixed>> $components */
    private function assertUniqueComponentIds(array $components): void
    {
        $ids = array_values(array_filter(array_map(
            static fn (array $component): int => isset($component['id']) ? (int) $component['id'] : 0,
            $components,
        )));
        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['components' => 'รหัสช่องคะแนนต้องไม่ซ้ำกัน']);
        }
    }

    /** @param list<array<string, mixed>> $components */
    private function assertScoresFitStructure(int $scorebookId, array $components): void
    {
        $requestedMax = [];
        foreach ($components as $component) {
            if (isset($component['id'])) {
                $requestedMax[(int) $component['id']] = (float) $component['max_score'];
            }
        }
        if ($requestedMax === []) {
            return;
        }
        $savedMaxima = $this->database->connection()->table('learning_score_entries')
            ->where('scorebook_id', $scorebookId)
            ->whereIn('component_id', array_keys($requestedMax))
            ->selectRaw('component_id, MAX(score) AS maximum_saved_score')
            ->groupBy('component_id')
            ->get();
        foreach ($savedMaxima as $row) {
            if ($row->maximum_saved_score !== null && (float) $row->maximum_saved_score > $requestedMax[(int) $row->component_id]) {
                throw ValidationException::withMessages(['components' => 'ลดคะแนนเต็มต่ำกว่าคะแนนที่บันทึกไว้ไม่ได้']);
            }
        }
    }

    private function levelLabel(int $level): string
    {
        return match ($level) {
            1 => 'ประถมศึกษา',
            2 => 'มัธยมศึกษาตอนต้น',
            3 => 'มัธยมศึกษาตอนปลาย',
            default => 'ไม่ระบุระดับ',
        };
    }

    private function scoreRatio(object $scorebook): ?string
    {
        if ($scorebook->coursework_weight === null || $scorebook->final_exam_weight === null) {
            return null;
        }

        return (int) $scorebook->coursework_weight.':'.(int) $scorebook->final_exam_weight;
    }

    /** @return array<string, mixed> */
    private function emptyStudentScores(): array
    {
        return [
            'term' => null,
            'summary' => ['score' => 0.0, 'maximum_score' => 0.0, 'items' => 0],
            'courses' => [],
            'disclaimer' => 'คะแนนเก็บภายในระบบ ยังไม่ใช่ผลการเรียนปลายภาค',
        ];
    }
}
