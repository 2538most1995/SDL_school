<?php

namespace App\Domain\Students\Services;

use App\Domain\Students\Models\Grade;
use App\Domain\Students\Models\Student;
use App\Domain\Students\Repositories\StudentRepository;
use App\Domain\Students\Support\AcademicTerm;
use App\Domain\Students\Support\CurriculumCatalog;
use App\Domain\Students\Support\StudentAccessScope;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class CourseRegistrationService
{
    public function __construct(
        private StudentDirectoryService $directory,
        private StudentRepository $repository,
    ) {}

    /**
     * Return students list with registration status for the registration workspace.
     *
     * @param  array{term?: ?string, group?: ?string, level?: ?int, search?: ?string, page?: int, per_page?: int}  $filters
     * @return array<string, mixed>
     */
    public function workspace(User $viewer, array $filters = []): array
    {
        $allStudents = $this->directory->accessibleStudents($viewer);
        $groups = [];
        $terms = [];

        foreach ($allStudents as $student) {
            $grp = trim($student->groupCode);
            if ($grp !== '') {
                $groups[$grp] = trim($student->groupName) ?: $grp;
            }
            if ($student->currentTerm !== '') {
                $terms[$student->currentTerm] = true;
            }
        }

        $termList = array_keys($terms);
        usort($termList, static fn (string $a, string $b): int => AcademicTerm::sortKey($b) <=> AcademicTerm::sortKey($a));
        $selectedTerm = $filters['term'] ?? ($termList[0] ?? '1/2569');

        $filtered = array_values(array_filter($allStudents, function (Student $student) use ($filters): bool {
            if (! empty($filters['group']) && $student->groupCode !== $filters['group']) {
                return false;
            }
            if (! empty($filters['level']) && (int) $student->level !== (int) $filters['level']) {
                return false;
            }
            if (! empty($filters['search'])) {
                $q = mb_strtolower(trim($filters['search']));
                $name = mb_strtolower($student->fullName());
                $code = mb_strtolower($student->code);
                $citizen = mb_strtolower((string) $student->citizenId);
                if (! str_contains($name, $q) && ! str_contains($code, $q) && ! str_contains($citizen, $q)) {
                    return false;
                }
            }

            return true;
        }));

        $studentCodes = array_map(static fn (Student $s): string => $s->code, $filtered);
        $districtId = $viewer->district_id;

        $registeredMap = [];
        if ($studentCodes !== [] && $districtId !== null) {
            $regRows = DB::table('learning_course_registrations')
                ->where('district_id', $districtId)
                ->where('academic_term', $selectedTerm)
                ->whereIn('student_code', $studentCodes)
                ->get(['student_code', 'compulsory_subjects', 'elective_subjects', 'updated_at']);

            foreach ($regRows as $row) {
                $compulsory = json_decode((string) $row->compulsory_subjects, true) ?: [];
                $elective = json_decode((string) $row->elective_subjects, true) ?: [];

                $regCompulsory = count(array_filter($compulsory, static fn (array $s): bool => ! empty($s['registered'])));
                $regElective = count(array_filter($elective, static fn (array $s): bool => ! empty($s['registered'])));
                $transferred = count(array_filter(array_merge($compulsory, $elective), static fn (array $s): bool => ! empty($s['transferred'])));

                $registeredMap[$row->student_code] = [
                    'is_saved' => true,
                    'compulsory_count' => $regCompulsory,
                    'elective_count' => $regElective,
                    'transferred_count' => $transferred,
                    'total_count' => $regCompulsory + $regElective,
                    'updated_at' => $row->updated_at,
                ];
            }
        }

        $items = array_map(static function (Student $student) use ($registeredMap): array {
            $reg = $registeredMap[$student->code] ?? [
                'is_saved' => false,
                'compulsory_count' => 0,
                'elective_count' => 0,
                'transferred_count' => 0,
                'total_count' => 0,
                'updated_at' => null,
            ];

            return [
                'code' => $student->code,
                'name' => $student->fullName(),
                'level' => $student->level,
                'level_label' => CurriculumCatalog::levelLabel($student->level),
                'group_code' => $student->groupCode,
                'group_name' => $student->groupName,
                'credits_earned' => $student->creditsEarned,
                'credits_required' => $student->creditsRequired,
                'registration' => $reg,
            ];
        }, $filtered);

        ksort($groups, SORT_NATURAL);
        $groupOptions = [];
        foreach ($groups as $code => $label) {
            $groupOptions[] = ['value' => $code, 'label' => "{$label} ({$code})"];
        }

        return [
            'term' => $selectedTerm,
            'terms' => $termList,
            'groups' => $groupOptions,
            'total_students' => count($filtered),
            'items' => $items,
        ];
    }

    /**
     * Return detailed registration data for a student in a specific term.
     *
     * @return array<string, mixed>|null
     */
    public function studentRegistration(User $viewer, string $studentCode, ?string $term = null): ?array
    {
        $student = $this->directory->findAccessible($viewer, $studentCode);
        if ($student === null) {
            return null;
        }

        $targetTerm = $term ? trim($term) : ($student->currentTerm ?: '1/2569');

        // Historical passed subjects & grades
        $allGrades = $this->repository->gradesFor($student);
        $passedSubjects = [];
        $historicalRegisteredInTerm = [];

        foreach ($allGrades as $grade) {
            $code = trim($grade->subjectCode);
            if ($grade->isPassed()) {
                $passedSubjects[$code] = [
                    'term' => $grade->term,
                    'grade' => $grade->grade,
                    'credits' => $grade->credits,
                    'name' => $grade->subjectName,
                ];
            }
            if ($grade->term === $targetTerm) {
                $historicalRegisteredInTerm[$code] = $grade;
            }
        }

        // Standard compulsory subjects for level
        $catalogCompulsory = CurriculumCatalog::compulsorySubjects($student->level);
        $reqs = CurriculumCatalog::creditRequirements($student->level);

        // Check if saved registration exists in learning_course_registrations
        $saved = DB::table('learning_course_registrations')
            ->where('district_id', $student->districtId)
            ->where('academic_term', $targetTerm)
            ->where('student_code', $student->code)
            ->first();

        $compulsoryList = [];
        $electiveList = [];
        $notes = '';

        if ($saved !== null) {
            $notes = (string) ($saved->notes ?? '');
            $savedCompulsory = json_decode((string) $saved->compulsory_subjects, true) ?: [];
            $savedElective = json_decode((string) $saved->elective_subjects, true) ?: [];

            $savedCompulsoryMap = [];
            foreach ($savedCompulsory as $s) {
                if (isset($s['code'])) {
                    $savedCompulsoryMap[trim($s['code'])] = $s;
                }
            }

            foreach ($catalogCompulsory as $c) {
                $code = $c['code'];
                $s = $savedCompulsoryMap[$code] ?? null;
                $passed = $passedSubjects[$code] ?? null;

                $compulsoryList[] = [
                    'code' => $code,
                    'name' => $c['name'],
                    'credits' => $c['credits'],
                    'registered' => ! empty($s['registered']),
                    'transferred' => ! empty($s['transferred']),
                    'remark' => (string) ($s['remark'] ?? ''),
                    'is_passed' => $passed !== null,
                    'passed_grade' => $passed['grade'] ?? null,
                    'passed_term' => $passed['term'] ?? null,
                ];
            }

            foreach ($savedElective as $e) {
                $code = trim((string) ($e['code'] ?? ''));
                if ($code === '') {
                    continue;
                }
                $passed = $passedSubjects[$code] ?? null;
                $electiveList[] = [
                    'code' => $code,
                    'name' => trim((string) ($e['name'] ?? '')),
                    'credits' => (float) ($e['credits'] ?? 2.0),
                    'registered' => ! empty($e['registered']),
                    'transferred' => ! empty($e['transferred']),
                    'remark' => (string) ($e['remark'] ?? ''),
                    'is_passed' => $passed !== null,
                    'passed_grade' => $passed['grade'] ?? null,
                    'passed_term' => $passed['term'] ?? null,
                ];
            }
        } else {
            // First time opening: populate from standard compulsory and pre-check any historical subject in this term
            foreach ($catalogCompulsory as $c) {
                $code = $c['code'];
                $passed = $passedSubjects[$code] ?? null;
                $inTerm = $historicalRegisteredInTerm[$code] ?? null;

                $compulsoryList[] = [
                    'code' => $code,
                    'name' => $c['name'],
                    'credits' => $c['credits'],
                    'registered' => $inTerm !== null && ! $inTerm->transferred,
                    'transferred' => $inTerm !== null && $inTerm->transferred,
                    'remark' => '',
                    'is_passed' => $passed !== null,
                    'passed_grade' => $passed['grade'] ?? null,
                    'passed_term' => $passed['term'] ?? null,
                ];
            }

            // Check if student has electives in this term from historical data
            foreach ($historicalRegisteredInTerm as $code => $grade) {
                if ($grade->subjectType === 'elective') {
                    $passed = $passedSubjects[$code] ?? null;
                    $electiveList[] = [
                        'code' => $code,
                        'name' => $grade->subjectName ?: $code,
                        'credits' => $grade->credits,
                        'registered' => ! $grade->transferred,
                        'transferred' => $grade->transferred,
                        'remark' => '',
                        'is_passed' => $passed !== null,
                        'passed_grade' => $passed['grade'] ?? null,
                        'passed_term' => $passed['term'] ?? null,
                    ];
                }
            }
        }

        $compulsoryEarned = $student->compulsoryCreditsEarned;
        $compulsoryRequired = $student->compulsoryCreditsRequired > 0 ? $student->compulsoryCreditsRequired : $reqs['compulsory'];
        $compulsoryRemaining = max(0.0, round($compulsoryRequired - $compulsoryEarned, 1));

        $electiveEarned = $student->electiveCreditsEarned;
        $electiveRequired = $student->electiveCreditsRequired > 0 ? $student->electiveCreditsRequired : $reqs['elective'];
        $electiveRemaining = max(0.0, round($electiveRequired - $electiveEarned, 1));

        $addrParts = $this->parseAddress($student->currentAddress ?: $student->registeredAddress ?: '');
        [$termNo, $termYear] = $this->splitTerm($targetTerm);

        $groupSubdistrict = CurriculumCatalog::resolveGroupSubdistrict($student->groupName ?: $student->groupCode);
        $defaultBoxSubdistrict = $groupSubdistrict !== '' ? $groupSubdistrict : $addrParts['subdistrict'];

        $defaultTeacherName = $viewer->role === 'teacher' ? $viewer->name : '';
        if ($defaultTeacherName === '' && $student->groupCode !== '') {
            $t = User::query()
                ->where('role', 'teacher')
                ->where('district_id', $student->districtId)
                ->get()
                ->first(static function (User $u) use ($student): bool {
                    $groups = (array) ($u->assigned_groups ?? []);
                    return in_array($student->groupCode, $groups, true);
                });
            if ($t) {
                $defaultTeacherName = $t->name;
            }
        }

        $savedStudentInfo = null;
        if ($saved !== null && ! empty($saved->student_info)) {
            $savedStudentInfo = json_decode((string) $saved->student_info, true) ?: null;
            if (is_array($savedStudentInfo)) {
                if (empty($savedStudentInfo['box_subdistrict']) || ($groupSubdistrict !== '' && ($savedStudentInfo['box_subdistrict'] === $addrParts['subdistrict']))) {
                    $savedStudentInfo['box_subdistrict'] = $defaultBoxSubdistrict;
                }
                if (empty($savedStudentInfo['teacher_name']) && $defaultTeacherName !== '') {
                    $savedStudentInfo['teacher_name'] = $defaultTeacherName;
                }
                if (empty($savedStudentInfo['facebook']) || $savedStudentInfo['facebook'] === '&nbsp;') {
                    $savedStudentInfo['facebook'] = '-';
                }
                if (empty($savedStudentInfo['line_id']) || $savedStudentInfo['line_id'] === '&nbsp;') {
                    $savedStudentInfo['line_id'] = '-';
                }
            }
        }

        $studentInfo = $savedStudentInfo ?? [
            'name' => $student->fullName(),
            'phone' => $student->phone ?? '',
            'facebook' => $student->facebookUrl ?: '-',
            'line_id' => $student->lineId ?: '-',
            'house_no' => $addrParts['house_no'],
            'moo' => $addrParts['moo'],
            'subdistrict' => $addrParts['subdistrict'],
            'district' => $addrParts['district'],
            'province' => $addrParts['province'],
            'citizen_id' => $student->citizenId ?? '',
            'code' => $student->code,
            'group' => $student->groupName ?: $student->groupCode,
            'box_subdistrict' => $defaultBoxSubdistrict,
            'teacher_name' => $defaultTeacherName,
            'compulsory_earned' => $compulsoryEarned,
            'elective_earned' => $electiveEarned,
            'compulsory_remaining' => $compulsoryRemaining,
            'elective_remaining' => $electiveRemaining,
            'term_no' => $termNo,
            'term_year' => $termYear,
        ];

        return [
            'student' => [
                'code' => $student->code,
                'name' => $student->fullName(),
                'prefix' => $student->prefix,
                'first_name' => $student->firstName,
                'last_name' => $student->lastName,
                'citizen_id' => $student->citizenId ?? $student->demographics['citizen_id_masked'] ?? '',
                'citizen_id_raw' => $student->citizenId ?? '',
                'level' => $student->level,
                'level_label' => CurriculumCatalog::levelLabel($student->level),
                'group_code' => $student->groupCode,
                'group_name' => $student->groupName,
                'district_name' => $student->districtName,
                'phone' => $student->phone ?? '',
                'facebook' => $student->facebookUrl ?? '',
                'line_id' => $student->lineId ?? '',
                'address' => $student->currentAddress ?: $student->registeredAddress ?: '',
            ],
            'student_info' => $studentInfo,
            'academic_term' => $targetTerm,
            'requirements' => [
                'compulsory_required' => $compulsoryRequired,
                'compulsory_earned' => $compulsoryEarned,
                'compulsory_remaining' => $compulsoryRemaining,
                'elective_required' => $electiveRequired,
                'elective_earned' => $electiveEarned,
                'elective_remaining' => $electiveRemaining,
                'total_required' => $reqs['total'],
                'total_earned' => $student->creditsEarned,
            ],
            'compulsory_subjects' => $compulsoryList,
            'elective_subjects' => $electiveList,
            'common_electives' => CurriculumCatalog::commonElectiveSubjects($student->level),
            'notes' => $notes,
            'is_saved' => $saved !== null,
        ];
    }

    /**
     * Save or update course registration for a student.
     *
     * @param  array{academic_term: string, student_info?: ?array<string, mixed>, compulsory_subjects: list<array<string, mixed>>, elective_subjects: list<array<string, mixed>>, notes?: ?string}  $data
     * @return array<string, mixed>
     */
    public function save(User $viewer, string $studentCode, array $data): array
    {
        $student = $this->directory->findAccessible($viewer, $studentCode);
        abort_if($student === null, 404, 'ไม่พบข้อมูลนักศึกษาหรือไม่มีสิทธิ์เข้าถึง');

        $term = trim($data['academic_term']);
        abort_if($term === '', 422, 'กรุณาระบุภาคเรียน');

        $compulsorySanitized = [];
        foreach ($data['compulsory_subjects'] ?? [] as $s) {
            $code = trim((string) ($s['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $compulsorySanitized[] = [
                'code' => $code,
                'name' => trim((string) ($s['name'] ?? '')),
                'credits' => (float) ($s['credits'] ?? 0),
                'registered' => ! empty($s['registered']),
                'transferred' => ! empty($s['transferred']),
                'remark' => trim((string) ($s['remark'] ?? '')),
            ];
        }

        $electiveSanitized = [];
        foreach ($data['elective_subjects'] ?? [] as $s) {
            $code = trim((string) ($s['code'] ?? ''));
            $name = trim((string) ($s['name'] ?? ''));
            if ($code === '' && $name === '') {
                continue;
            }
            $electiveSanitized[] = [
                'code' => $code,
                'name' => $name,
                'credits' => (float) ($s['credits'] ?? 2.0),
                'registered' => ! empty($s['registered']),
                'transferred' => ! empty($s['transferred']),
                'remark' => trim((string) ($s['remark'] ?? '')),
            ];
        }

        $studentInfo = isset($data['student_info']) && is_array($data['student_info'])
            ? $data['student_info']
            : null;

        $notes = isset($data['notes']) ? trim((string) $data['notes']) : null;

        DB::table('learning_course_registrations')->updateOrInsert(
            [
                'district_id' => $student->districtId,
                'academic_term' => $term,
                'student_code' => $student->code,
            ],
            [
                'education_level' => $student->level,
                'group_code' => $student->groupCode,
                'student_info' => $studentInfo ? json_encode($studentInfo, JSON_UNESCAPED_UNICODE) : null,
                'compulsory_subjects' => json_encode($compulsorySanitized, JSON_UNESCAPED_UNICODE),
                'elective_subjects' => json_encode($electiveSanitized, JSON_UNESCAPED_UNICODE),
                'notes' => $notes,
                'registered_by' => $viewer->id,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return $this->studentRegistration($viewer, $studentCode, $term) ?? [];
    }

    /**
     * @return array{house_no: string, moo: string, subdistrict: string, district: string, province: string}
     */
    private function parseAddress(string $address): array
    {
        $houseNo = '';
        $moo = '';
        $subdistrict = '';
        $district = '';
        $province = '';

        if (preg_match('/(?:บ้านเลขที่\s*|เลขที่\s*)([0-9\/\-]+)/u', $address, $m)) {
            $houseNo = $m[1];
        } elseif (preg_match('/^([0-9\/\-]+)/u', trim($address), $m)) {
            $houseNo = $m[1];
        }

        if (preg_match('/(?:หมู่\s*ที่\s*|หมู่\s*)([0-9]+)/u', $address, $m)) {
            $moo = $m[1];
        }

        if (preg_match('/(?:ตำบล|แขวง)\s*([^\s]+)/u', $address, $m)) {
            $subdistrict = $m[1];
        }

        if (preg_match('/(?:อำเภอ|เขต)\s*([^\s]+)/u', $address, $m)) {
            $district = $m[1];
        }

        if (preg_match('/(?:จังหวัด)\s*([^\s]+)/u', $address, $m)) {
            $province = $m[1];
        }

        return [
            'house_no' => $houseNo,
            'moo' => $moo,
            'subdistrict' => $subdistrict,
            'district' => $district,
            'province' => $province,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitTerm(string $term): array
    {
        $parts = explode('/', trim($term));

        return [
            trim($parts[0] ?? ''),
            trim($parts[1] ?? ''),
        ];
    }
}
