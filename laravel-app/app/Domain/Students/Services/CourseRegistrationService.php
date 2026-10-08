<?php

namespace App\Domain\Students\Services;

use App\Domain\Students\Models\Grade;
use App\Domain\Students\Models\Student;
use App\Domain\Students\Repositories\StudentRepository;
use App\Domain\Students\Support\AcademicTerm;
use App\Domain\Students\Support\CurriculumCatalog;
use App\Domain\Students\Support\GraduationOpportunity;
use App\Domain\Students\Support\RegistrationCreditPolicy;
use App\Domain\Students\Support\StudentGroupOptions;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CourseRegistrationService
{
    public function __construct(
        private StudentDirectoryService $directory,
        private StudentRepository $repository,
    ) {}

    /**
     * Return students list with registration status for the registration workspace.
     *
     * @param  array{term?: ?string, group?: ?string, level?: ?int, search?: ?string, graduation_status?: ?string, page?: int, per_page?: int}  $filters
     * @return array<string, mixed>
     */
    public function workspace(User $viewer, int $districtId, array $filters = []): array
    {
        $allStudents = $this->directory->accessibleStudents($viewer);
        $terms = [];
        $maxStudentTerm = null;

        foreach ($allStudents as $student) {
            if ($student->currentTerm !== '') {
                $norm = AcademicTerm::normalize($student->currentTerm) ?? $student->currentTerm;
                $terms[$norm] = true;
                if ($maxStudentTerm === null || AcademicTerm::sortKey($norm) > AcademicTerm::sortKey($maxStudentTerm)) {
                    $maxStudentTerm = $norm;
                }
            }
        }

        $termList = $this->resolveWorkspaceTerms($terms, $maxStudentTerm, $districtId);
        $nextRegisterableTerm = $termList[0] ?? AcademicTerm::nextTerm($maxStudentTerm ?? '1/2569');
        $selectedTerm = $filters['term'] ?? $nextRegisterableTerm;
        $selectedLevel = ! empty($filters['level']) ? (int) $filters['level'] : null;
        $groupOptions = StudentGroupOptions::fromStudents($allStudents, $selectedLevel);

        $filtered = array_values(array_filter($allStudents, function (Student $student) use ($filters): bool {
            if (! empty($filters['group']) && ! StudentGroupOptions::matches($student, (string) $filters['group'])) {
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
        $registeredMap = [];
        if ($studentCodes !== []) {
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
                    'compulsory_subjects' => $compulsory,
                    'elective_subjects' => $elective,
                ];
            }
        }

        $gradesByStudent = $filtered === [] ? [] : $this->repository->gradesForMany($filtered);
        $items = array_map(function (Student $student) use ($registeredMap, $gradesByStudent): array {
            $reg = $registeredMap[$student->code] ?? [
                'is_saved' => false,
                'compulsory_count' => 0,
                'elective_count' => 0,
                'transferred_count' => 0,
                'total_count' => 0,
                'updated_at' => null,
                'compulsory_subjects' => [],
                'elective_subjects' => [],
            ];
            $progress = $this->academicProgress(
                $student,
                $gradesByStudent[$this->studentKey($student)] ?? [],
            );
            $policy = $this->registrationPolicy(
                $student,
                $progress,
                $reg['compulsory_subjects'],
                $reg['elective_subjects'],
            );
            unset($reg['compulsory_subjects'], $reg['elective_subjects']);
            $reg['credit_policy'] = $policy;

            return [
                'code' => $student->code,
                'name' => $student->fullName(),
                'level' => $student->level,
                'level_label' => CurriculumCatalog::levelLabel($student->level),
                'group_code' => $student->groupCode,
                'group_name' => $student->groupName,
                'credits_earned' => $student->creditsEarned,
                'credits_required' => $student->creditsRequired,
                'is_credit_complete' => $policy['is_credit_complete'],
                'is_potential_graduate' => $policy['is_potential_graduate'],
                'registration' => $reg,
            ];
        }, $filtered);
        if (! empty($filters['graduation_status'])) {
            $field = $filters['graduation_status'] === 'complete' ? 'is_credit_complete' : 'is_potential_graduate';
            $items = array_values(array_filter($items, static fn (array $item): bool => $item[$field]));
        }

        return [
            'term' => $selectedTerm,
            'terms' => $termList,
            'groups' => $groupOptions,
            'total_students' => count($items),
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

        $allGrades = $this->repository->gradesFor($student);

        // Gather all terms for available_terms
        $studentTerms = [];
        $maxStudentTerm = null;
        if ($student->currentTerm !== '') {
            $norm = AcademicTerm::normalize($student->currentTerm) ?? $student->currentTerm;
            $studentTerms[$norm] = true;
            $maxStudentTerm = $norm;
        }
        foreach ($allGrades as $g) {
            if ($g->term !== '') {
                $norm = AcademicTerm::normalize($g->term) ?? $g->term;
                $studentTerms[$norm] = true;
                if ($maxStudentTerm === null || AcademicTerm::sortKey($norm) > AcademicTerm::sortKey($maxStudentTerm)) {
                    $maxStudentTerm = $norm;
                }
            }
        }
        $availableTerms = $this->resolveWorkspaceTerms($studentTerms, $maxStudentTerm, $student->districtId);
        $nextRegisterableTerm = $availableTerms[0] ?? AcademicTerm::nextTerm($maxStudentTerm ?? '1/2569');
        $targetTerm = $term ? trim($term) : $nextRegisterableTerm;
        if (! in_array($targetTerm, $availableTerms, true)) {
            $availableTerms[] = $targetTerm;
            usort($availableTerms, static fn (string $a, string $b): int => AcademicTerm::sortKey($b) <=> AcademicTerm::sortKey($a));
        }

        // Historical passed subjects, grades, and enrollments
        $historyPassed = [];
        $historyTransferred = [];
        $historyUnpassed = []; // Tracks latest attempt of failed ('0') or absent ('ข')
        $historyPending = [];
        $historicalRegisteredInTerm = [];

        foreach ($allGrades as $grade) {
            $code = trim($grade->subjectCode);
            if ($grade->term === $targetTerm) {
                $historicalRegisteredInTerm[$code] = $grade;
            }

            $rawGrade = $grade->grade !== null ? trim((string) $grade->grade) : '';
            $isAbsent = in_array($rawGrade, ['ข', 'ขาดสอบ'], true) || (! $grade->examAttended && in_array($rawGrade, ['ข', 'ม', ''], true));
            $isFailedZero = $rawGrade === '0';

            if ($grade->transferred) {
                $historyTransferred[$code] = [
                    'term' => $grade->term,
                    'name' => $grade->subjectName,
                    'credits' => $grade->credits,
                ];
            } elseif ($grade->isPassed()) {
                $historyPassed[$code] = [
                    'term' => $grade->term,
                    'grade' => $grade->grade,
                    'credits' => $grade->credits,
                    'name' => $grade->subjectName,
                ];
            } elseif ($isAbsent) {
                if (! isset($historyUnpassed[$code]) || AcademicTerm::compare($grade->term, $historyUnpassed[$code]['term']) >= 0) {
                    $historyUnpassed[$code] = [
                        'type' => 'absent_exam',
                        'term' => $grade->term,
                        'grade' => 'ข',
                        'credits' => $grade->credits,
                        'name' => $grade->subjectName,
                    ];
                }
            } elseif ($isFailedZero) {
                if (! isset($historyUnpassed[$code]) || AcademicTerm::compare($grade->term, $historyUnpassed[$code]['term']) >= 0) {
                    $historyUnpassed[$code] = [
                        'type' => 'failed',
                        'term' => $grade->term,
                        'grade' => '0',
                        'credits' => $grade->credits,
                        'name' => $grade->subjectName,
                    ];
                }
            } else {
                $historyPending[$code] = [
                    'term' => $grade->term,
                    'name' => $grade->subjectName,
                    'credits' => $grade->credits,
                ];
            }
        }

        // Active / pending registrations in other terms from learning_course_registrations
        $learningPending = [];
        $otherSavedRows = DB::table('learning_course_registrations')
            ->where('district_id', $student->districtId)
            ->where('student_code', $student->code)
            ->where('academic_term', '!=', $targetTerm)
            ->get(['academic_term', 'compulsory_subjects', 'elective_subjects']);

        foreach ($otherSavedRows as $row) {
            $comp = json_decode((string) $row->compulsory_subjects, true) ?: [];
            $elec = json_decode((string) $row->elective_subjects, true) ?: [];
            foreach (array_merge($comp, $elec) as $sub) {
                if (! empty($sub['registered']) && ! empty($sub['code'])) {
                    $c = trim((string) $sub['code']);
                    if (! isset($historyPassed[$c]) && ! isset($historyTransferred[$c])) {
                        $learningPending[$c] = [
                            'term' => (string) $row->academic_term,
                            'name' => (string) ($sub['name'] ?? ''),
                        ];
                    }
                }
            }
        }

        $resolveStatus = static function (string $code) use ($historyPassed, $historyTransferred, $historyPending, $learningPending, $historyUnpassed): array {
            $code = trim($code);
            if (isset($historyPassed[$code])) {
                $p = $historyPassed[$code];
                $grd = (string) $p['grade'];
                $t = (string) $p['term'];

                return [
                    'status' => 'passed',
                    'status_label' => "มีเกรดแล้ว: {$grd} (เทอม {$t})",
                    'status_badge' => "มีเกรดแล้ว ({$grd})",
                    'status_color' => 'emerald',
                    'has_grade' => true,
                    'is_pending' => false,
                    'is_transferred' => false,
                    'grade' => $grd,
                    'term' => $t,
                    'warning' => "วิชานี้มีผลการเรียนแล้ว (เกรด {$grd} เทอม {$t}) ไม่แนะนำให้ลงทะเบียนซ้ำ",
                ];
            }
            if (isset($historyTransferred[$code])) {
                $tr = $historyTransferred[$code];
                $t = (string) $tr['term'];

                return [
                    'status' => 'transferred',
                    'status_label' => $t !== '' ? "เทียบโอนแล้ว (เทอม {$t})" : 'เทียบโอนแล้ว',
                    'status_badge' => 'เทียบโอนแล้ว',
                    'status_color' => 'purple',
                    'has_grade' => true,
                    'is_pending' => false,
                    'is_transferred' => true,
                    'grade' => null,
                    'term' => $t,
                    'warning' => 'วิชานี้ได้รับการเทียบโอนแล้ว',
                ];
            }
            if (isset($historyPending[$code])) {
                $hp = $historyPending[$code];
                $t = (string) $hp['term'];

                return [
                    'status' => 'pending_grade',
                    'status_label' => "รอเกรดอยู่ (เทอม {$t})",
                    'status_badge' => "รอเกรด (เทอม {$t})",
                    'status_color' => 'amber',
                    'has_grade' => false,
                    'is_pending' => true,
                    'is_transferred' => false,
                    'grade' => null,
                    'term' => $t,
                    'warning' => "วิชานี้ลงทะเบียนไว้ในเทอม {$t} แล้วและกำลังรอผลการเรียน",
                ];
            }
            if (isset($learningPending[$code])) {
                $lp = $learningPending[$code];
                $t = (string) $lp['term'];

                return [
                    'status' => 'pending_grade',
                    'status_label' => "รอเกรดอยู่ (ลงทะเบียนเทอม {$t})",
                    'status_badge' => "รอเกรด (เทอม {$t})",
                    'status_color' => 'amber',
                    'has_grade' => false,
                    'is_pending' => true,
                    'is_transferred' => false,
                    'grade' => null,
                    'term' => $t,
                    'warning' => "วิชานี้ได้บันทึกการลงทะเบียนในเทอม {$t} ไว้แล้วและกำลังรอผลการเรียน",
                ];
            }
            if (isset($historyUnpassed[$code])) {
                $unp = $historyUnpassed[$code];
                $t = (string) $unp['term'];

                if ($unp['type'] === 'absent_exam') {
                    return [
                        'status' => 'absent_exam',
                        'status_label' => "ขาดสอบ (เกรด \"ข\" เทอม {$t})",
                        'status_badge' => 'เกรด "ข" ขาดสอบ (ลงเรียนใหม่ได้)',
                        'status_color' => 'orange',
                        'has_grade' => true,
                        'is_pending' => false,
                        'is_transferred' => false,
                        'grade' => 'ข',
                        'term' => $t,
                        'warning' => "เคยขาดสอบ (เกรด \"ข\" ในเทอม {$t}) แนะนำให้ลงทะเบียนเรียนใหม่",
                    ];
                }

                return [
                    'status' => 'failed',
                    'status_label' => "ไม่ผ่าน (เกรด 0 เทอม {$t})",
                    'status_badge' => 'เกรด 0 (ลงแก้ตัวได้)',
                    'status_color' => 'rose',
                    'has_grade' => true,
                    'is_pending' => false,
                    'is_transferred' => false,
                    'grade' => '0',
                    'term' => $t,
                    'warning' => "เคยได้เกรด 0 ในเทอม {$t} (แนะนำให้ลงทะเบียนเพื่อแก้ผลการเรียน)",
                ];
            }

            return [
                'status' => 'not_taken',
                'status_label' => 'ยังไม่ได้เรียน (แนะนำ)',
                'status_badge' => 'ยังไม่ได้เรียน',
                'status_color' => 'blue',
                'has_grade' => false,
                'is_pending' => false,
                'is_transferred' => false,
                'grade' => null,
                'term' => null,
                'warning' => null,
            ];
        };

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
                $statusInfo = $resolveStatus($code);

                $compulsoryList[] = [
                    'code' => $code,
                    'name' => $c['name'],
                    'credits' => $c['credits'],
                    'registered' => ! empty($s['registered']),
                    'transferred' => ! empty($s['transferred']),
                    'remark' => (string) ($s['remark'] ?? ''),
                    'is_passed' => $statusInfo['has_grade'] && $statusInfo['status'] === 'passed',
                    'passed_grade' => $statusInfo['grade'],
                    'passed_term' => $statusInfo['term'],
                    'course_status' => $statusInfo,
                ];
            }

            foreach ($savedElective as $e) {
                $code = trim((string) ($e['code'] ?? ''));
                if ($code === '') {
                    continue;
                }
                $statusInfo = $resolveStatus($code);
                $electiveList[] = [
                    'code' => $code,
                    'name' => trim((string) ($e['name'] ?? '')),
                    'credits' => (float) ($e['credits'] ?? 2.0),
                    'registered' => ! empty($e['registered']),
                    'transferred' => ! empty($e['transferred']),
                    'remark' => (string) ($e['remark'] ?? ''),
                    'is_passed' => $statusInfo['has_grade'] && $statusInfo['status'] === 'passed',
                    'passed_grade' => $statusInfo['grade'],
                    'passed_term' => $statusInfo['term'],
                    'course_status' => $statusInfo,
                ];
            }
        } else {
            // First time opening: populate from standard compulsory and pre-check any historical subject in this term
            foreach ($catalogCompulsory as $c) {
                $code = $c['code'];
                $statusInfo = $resolveStatus($code);
                $inTerm = $historicalRegisteredInTerm[$code] ?? null;

                $compulsoryList[] = [
                    'code' => $code,
                    'name' => $c['name'],
                    'credits' => $c['credits'],
                    'registered' => $inTerm !== null && ! $inTerm->transferred,
                    'transferred' => $inTerm !== null && $inTerm->transferred,
                    'remark' => '',
                    'is_passed' => $statusInfo['has_grade'] && $statusInfo['status'] === 'passed',
                    'passed_grade' => $statusInfo['grade'],
                    'passed_term' => $statusInfo['term'],
                    'course_status' => $statusInfo,
                ];
            }

            // Check if student has electives in this term from historical data
            foreach ($historicalRegisteredInTerm as $code => $grade) {
                if ($grade->subjectType === 'elective') {
                    $statusInfo = $resolveStatus($code);
                    $electiveList[] = [
                        'code' => $code,
                        'name' => $grade->subjectName ?: $code,
                        'credits' => $grade->credits,
                        'registered' => ! $grade->transferred,
                        'transferred' => $grade->transferred,
                        'remark' => '',
                        'is_passed' => $statusInfo['has_grade'] && $statusInfo['status'] === 'passed',
                        'passed_grade' => $statusInfo['grade'],
                        'passed_term' => $statusInfo['term'],
                        'course_status' => $statusInfo,
                    ];
                }
            }
        }

        $progress = $this->academicProgress($student, $allGrades);
        $compulsoryEarned = $progress['compulsory_earned'];
        $compulsoryRequired = $student->compulsoryCreditsRequired > 0 ? $student->compulsoryCreditsRequired : $reqs['compulsory'];
        $compulsoryRemaining = max(0.0, round($compulsoryRequired - $compulsoryEarned, 1));

        $electiveEarned = $progress['elective_earned'];
        $electiveRequired = $student->electiveCreditsRequired > 0 ? $student->electiveCreditsRequired : $reqs['elective'];
        $electiveRemaining = max(0.0, round($electiveRequired - $electiveEarned, 1));
        $creditPolicy = $this->registrationPolicy($student, $progress, $compulsoryList, $electiveList);

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
        $defaultTeacherName = CurriculumCatalog::ensureTeacherPrefix($defaultTeacherName, $student->districtId);

        $savedStudentInfo = null;
        if ($saved !== null && ! empty($saved->student_info)) {
            $savedStudentInfo = json_decode((string) $saved->student_info, true) ?: null;
            if (is_array($savedStudentInfo)) {
                if (empty($savedStudentInfo['box_subdistrict']) || ($groupSubdistrict !== '' && ($savedStudentInfo['box_subdistrict'] === $addrParts['subdistrict']))) {
                    $savedStudentInfo['box_subdistrict'] = $defaultBoxSubdistrict;
                }
                if (empty($savedStudentInfo['teacher_name']) && $defaultTeacherName !== '') {
                    $savedStudentInfo['teacher_name'] = $defaultTeacherName;
                } elseif (! empty($savedStudentInfo['teacher_name'])) {
                    $savedStudentInfo['teacher_name'] = CurriculumCatalog::ensureTeacherPrefix($savedStudentInfo['teacher_name'], $student->districtId);
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
            'available_terms' => $availableTerms,
            'requirements' => [
                'compulsory_required' => $compulsoryRequired,
                'compulsory_earned' => $compulsoryEarned,
                'compulsory_remaining' => $compulsoryRemaining,
                'elective_required' => $electiveRequired,
                'elective_earned' => $electiveEarned,
                'elective_remaining' => $electiveRemaining,
                'source_compulsory_earned' => $student->compulsoryCreditsEarned,
                'source_elective_earned' => $student->electiveCreditsEarned,
                'total_required' => $reqs['total'],
                'total_earned' => round($compulsoryEarned + $electiveEarned, 2),
            ],
            'completed_electives' => array_values(array_filter(
                $progress['completed_subjects'],
                static fn (array $subject): bool => $subject['type'] === 'elective',
            )),
            'compulsory_subjects' => $compulsoryList,
            'elective_subjects' => $electiveList,
            'common_electives' => (function () use ($student, $catalogCompulsory, $historyUnpassed, $resolveStatus): array {
                $commonElectives = CurriculumCatalog::commonElectiveSubjects($student->level);
                $existingCodes = array_flip(array_map(static fn ($e) => trim((string) $e['code']), $commonElectives));
                $compulsoryCodes = array_flip(array_map(static fn ($c) => trim((string) $c['code']), $catalogCompulsory));

                $extraElectives = [];
                foreach ($historyUnpassed as $code => $unp) {
                    if (! isset($compulsoryCodes[$code]) && ! isset($existingCodes[$code])) {
                        $extraElectives[] = [
                            'code' => $code,
                            'name' => $unp['name'] ?: $code,
                            'credits' => $unp['credits'] > 0 ? (float) $unp['credits'] : 2.0,
                        ];
                        $existingCodes[$code] = true;
                    }
                }

                $merged = array_merge($extraElectives, $commonElectives);

                return array_map(static function (array $ce) use ($resolveStatus): array {
                    $code = trim((string) ($ce['code'] ?? ''));

                    return [
                        ...$ce,
                        'course_status' => $resolveStatus($code),
                    ];
                }, $merged);
            })(),
            'credit_policy' => $creditPolicy,
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

        $term = AcademicTerm::normalize((string) $data['academic_term']);
        if ($term === null) {
            throw ValidationException::withMessages([
                'academic_term' => 'รูปแบบภาคเรียนไม่ถูกต้อง',
            ]);
        }

        $allGrades = $this->repository->gradesFor($student);
        $progress = $this->academicProgress($student, $allGrades);
        $compulsoryCatalog = collect(CurriculumCatalog::compulsorySubjects($student->level))->keyBy('code');
        $seenCodes = [];

        $compulsorySanitized = [];
        foreach ($data['compulsory_subjects'] ?? [] as $s) {
            $code = trim((string) ($s['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            if (isset($seenCodes[$code])) {
                throw ValidationException::withMessages([
                    'compulsory_subjects' => "พบรหัสวิชาซ้ำ {$code}",
                ]);
            }
            $seenCodes[$code] = true;
            $catalogSubject = $compulsoryCatalog->get($code);
            if ($catalogSubject === null) {
                throw ValidationException::withMessages([
                    'compulsory_subjects' => "รหัสวิชาบังคับ {$code} ไม่อยู่ในหลักสูตรระดับนี้",
                ]);
            }
            $compulsorySanitized[] = [
                'code' => $code,
                'name' => (string) $catalogSubject['name'],
                'credits' => (float) $catalogSubject['credits'],
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
            if ($code === '') {
                throw ValidationException::withMessages([
                    'elective_subjects' => 'กรุณาระบุรหัสวิชาเลือกให้ครบถ้วน',
                ]);
            }
            if (isset($seenCodes[$code])) {
                throw ValidationException::withMessages([
                    'elective_subjects' => "พบรหัสวิชาซ้ำ {$code}",
                ]);
            }
            $seenCodes[$code] = true;
            $electiveSanitized[] = [
                'code' => $code,
                'name' => $name,
                'credits' => (float) ($s['credits'] ?? 2.0),
                'registered' => ! empty($s['registered']),
                'transferred' => ! empty($s['transferred']),
                'remark' => trim((string) ($s['remark'] ?? '')),
            ];
        }

        $creditPolicy = $this->registrationPolicy(
            $student,
            $progress,
            $compulsorySanitized,
            $electiveSanitized,
        );
        if ($creditPolicy['exceeds_limit']) {
            $kind = $creditPolicy['uses_final_term_limit'] ? 'ภาคเรียนสุดท้าย' : 'ภาคเรียนปกติ';
            throw ValidationException::withMessages([
                'credits' => 'ลงทะเบียนรวม '.number_format($creditPolicy['total_selected'], 2)
                    .' หน่วยกิต เกินเพดาน'.$kind.' '.$creditPolicy['applicable_limit']
                    .' หน่วยกิต (เกิน '.number_format($creditPolicy['excess_credits'], 2).' หน่วยกิต)',
            ]);
        }

        $studentInfo = isset($data['student_info']) && is_array($data['student_info'])
            ? $data['student_info']
            : null;

        if ($studentInfo !== null && isset($studentInfo['teacher_name'])) {
            $studentInfo['teacher_name'] = CurriculumCatalog::ensureTeacherPrefix((string) $studentInfo['teacher_name'], $student->districtId);
        }

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

    /**
     * @param  array<string, bool>  $existingTerms
     * @return list<string>
     */
    private function resolveWorkspaceTerms(array $existingTerms, ?string $baseMaxTerm, int $districtId): array
    {
        $termsMap = $existingTerms;

        if ($baseMaxTerm === null) {
            foreach (array_keys($existingTerms) as $t) {
                if ($baseMaxTerm === null || AcademicTerm::sortKey($t) > AcademicTerm::sortKey($baseMaxTerm)) {
                    $baseMaxTerm = $t;
                }
            }
        }

        $baseTerm = $baseMaxTerm ?? '1/2569';
        if (AcademicTerm::sortKey($baseTerm) < AcademicTerm::sortKey('1/2569')) {
            $termsMap['1/2569'] = true;
            $baseTerm = '1/2569';
        }

        // Add strictly the single next upcoming registerable term (e.g. 1/2569 -> 2/2569)
        $nextTerm = AcademicTerm::nextTerm($baseTerm);
        $termsMap[$nextTerm] = true;

        // Also check any terms saved in learning_course_registrations
        $savedTerms = DB::table('learning_course_registrations')
            ->where('district_id', $districtId)
            ->distinct()
            ->pluck('academic_term')
            ->filter()
            ->all();
        foreach ($savedTerms as $st) {
            $normalized = AcademicTerm::normalize((string) $st);
            if ($normalized !== null && AcademicTerm::sortKey($normalized) <= AcademicTerm::sortKey($nextTerm)) {
                $termsMap[$normalized] = true;
            }
        }

        $finalList = array_keys($termsMap);
        $finalList = array_filter($finalList, static fn (string $t): bool => AcademicTerm::sortKey($t) <= AcademicTerm::sortKey($nextTerm));
        usort($finalList, static fn (string $a, string $b): int => AcademicTerm::sortKey($b) <=> AcademicTerm::sortKey($a));

        return array_values($finalList);
    }

    /**
     * @param  list<Grade>  $grades
     * @return array{compulsory_earned: float, elective_earned: float, completed_codes: array<string, bool>, completed_subjects: list<array<string, mixed>>}
     */
    private function academicProgress(Student $student, array $grades): array
    {
        $completed = [];
        $compulsoryCodes = array_fill_keys(
            array_column(CurriculumCatalog::compulsorySubjects($student->level), 'code'),
            true,
        );
        foreach ($grades as $grade) {
            if (! $grade->transferred && ! $grade->isPassed()) {
                continue;
            }

            $code = trim($grade->subjectCode);
            if ($code === '' || isset($completed[$code])) {
                continue;
            }

            $completed[$code] = [
                'code' => $code,
                'name' => $grade->subjectName ?: $code,
                'type' => isset($compulsoryCodes[$code]) ? 'compulsory' : 'elective',
                'credits' => max(0, $grade->credits),
                'term' => $grade->term,
                'grade' => $grade->grade,
                'transferred' => $grade->transferred,
            ];
        }

        $compulsoryFromGrades = 0.0;
        $electiveFromGrades = 0.0;
        foreach ($completed as $subject) {
            if ($subject['type'] === 'compulsory') {
                $compulsoryFromGrades += $subject['credits'];
            } else {
                $electiveFromGrades += $subject['credits'];
            }
        }

        return [
            'compulsory_earned' => round($compulsoryFromGrades, 2),
            'elective_earned' => round($electiveFromGrades, 2),
            'completed_codes' => array_fill_keys(array_keys($completed), true),
            'completed_subjects' => array_values($completed),
        ];
    }

    /**
     * @param  array{compulsory_earned: float, elective_earned: float, completed_codes: array<string, bool>, completed_subjects: list<array<string, mixed>>}  $progress
     * @param  list<array<string, mixed>>  $compulsorySubjects
     * @param  list<array<string, mixed>>  $electiveSubjects
     * @return array<string, float|bool>
     */
    private function registrationPolicy(
        Student $student,
        array $progress,
        array $compulsorySubjects,
        array $electiveSubjects,
    ): array {
        $selected = static function (array $subjects, array $completedCodes): float {
            $credits = 0.0;
            $seen = [];
            foreach ($subjects as $subject) {
                $code = trim((string) ($subject['code'] ?? ''));
                if ($code === '' || isset($seen[$code]) || isset($completedCodes[$code])) {
                    continue;
                }
                $seen[$code] = true;
                if (! empty($subject['registered']) || ! empty($subject['transferred'])) {
                    $credits += max(0, (float) ($subject['credits'] ?? 0));
                }
            }

            return round($credits, 2);
        };
        $termLoad = static function (array $subjects): float {
            $credits = 0.0;
            $seen = [];
            foreach ($subjects as $subject) {
                $code = trim((string) ($subject['code'] ?? ''));
                if ($code === '' || isset($seen[$code]) || empty($subject['registered']) || ! empty($subject['transferred'])) {
                    continue;
                }
                $seen[$code] = true;
                $credits += max(0, (float) ($subject['credits'] ?? 0));
            }

            return round($credits, 2);
        };

        $requirements = CurriculumCatalog::creditRequirements($student->level);
        $policy = RegistrationCreditPolicy::evaluate(
            $student->level,
            (float) $progress['compulsory_earned'],
            (float) $progress['elective_earned'],
            $selected($compulsorySubjects, $progress['completed_codes']),
            $selected($electiveSubjects, $progress['completed_codes']),
            $student->compulsoryCreditsRequired > 0 ? $student->compulsoryCreditsRequired : $requirements['compulsory'],
            $student->electiveCreditsRequired > 0 ? $student->electiveCreditsRequired : $requirements['elective'],
        );

        $policy['compulsory_selected'] = $termLoad($compulsorySubjects);
        $policy['elective_selected'] = $termLoad($electiveSubjects);
        $policy['total_selected'] = round($policy['compulsory_selected'] + $policy['elective_selected'], 2);
        $policy['is_credit_complete'] = $policy['compulsory_earned'] >= $policy['compulsory_required']
            && $policy['elective_earned'] >= $policy['elective_required'];
        $remainingCompulsory = array_values(array_filter(
            CurriculumCatalog::compulsorySubjects($student->level),
            static fn (array $subject): bool => ! isset($progress['completed_codes'][$subject['code']]),
        ));
        $newRegistrationPlan = GraduationOpportunity::plan(
            $policy['compulsory_earned'],
            $policy['compulsory_required'],
            $policy['elective_earned'],
            $policy['elective_required'],
            $policy['final_term_limit'],
            $remainingCompulsory,
        );
        $policy['new_registration_plan'] = $newRegistrationPlan;
        $policy['can_complete_with_new_registration'] = ! $policy['is_credit_complete']
            && $newRegistrationPlan !== null;
        $policy['is_potential_graduate'] = ! $policy['is_credit_complete']
            && ($policy['can_complete_with_new_registration']
                || ($policy['projected_compulsory'] >= $policy['compulsory_required']
                    && $policy['projected_elective'] >= $policy['elective_required']));
        $policy['is_active_student'] = true;
        $policy['uses_final_term_limit'] = $policy['is_credit_complete'] || $policy['is_potential_graduate'];
        $policy['applicable_limit'] = $policy['uses_final_term_limit']
            ? $policy['final_term_limit']
            : $policy['regular_limit'];
        $policy['excess_credits'] = round(max(0, $policy['total_selected'] - $policy['applicable_limit']), 2);
        $policy['exceeds_limit'] = $policy['excess_credits'] > 0;

        return $policy;
    }

    private function studentKey(Student $student): string
    {
        return "{$student->districtId}|{$student->level}|{$student->code}";
    }
}
