<?php

namespace App\Domain\Students\Services;

use App\Domain\Students\Models\Student;
use App\Domain\Students\Repositories\StudentRepository;
use App\Domain\Students\Support\CurriculumCatalog;
use App\Models\District;
use App\Models\User;

final readonly class CourseRegistrationExportService
{
    public function __construct(
        private StudentDirectoryService $directory,
        private CourseRegistrationService $registrationService,
    ) {}

    /**
     * Build print payload for single student, group, or blank template.
     *
     * @param  array{scope: string, student?: ?string, group?: ?string, level?: ?int, term?: ?string}  $filters
     * @return array<string, mixed>
     */
    public function build(User $viewer, array $filters): array
    {
        $scope = $filters['scope'] ?? 'student';
        $documents = [];

        if ($scope === 'blank') {
            $level = (int) ($filters['level'] ?? 3);
            if (! in_array($level, [1, 2, 3], true)) {
                $level = 3;
            }
            $term = $filters['term'] ?? '';
            $districtName = $this->resolveDistrictName($viewer);
            $documents[] = $this->buildBlankDocument($level, $term, $districtName);
        } elseif ($scope === 'student') {
            $studentCode = (string) ($filters['student'] ?? '');
            $regData = $this->registrationService->studentRegistration($viewer, $studentCode, $filters['term'] ?? null);
            abort_if($regData === null, 404, 'ไม่พบข้อมูลนักศึกษาหรือไม่มีสิทธิ์เข้าถึง');
            $documents[] = $this->buildStudentDocument($regData);
        } elseif ($scope === 'group') {
            $groupCode = (string) ($filters['group'] ?? '');
            $levelFilter = isset($filters['level']) ? (int) $filters['level'] : null;
            $term = $filters['term'] ?? null;

            $students = $this->directory->accessibleStudents($viewer);
            $groupStudents = array_filter($students, static function (Student $s) use ($groupCode, $levelFilter): bool {
                if ($groupCode !== '' && $s->groupCode !== $groupCode) {
                    return false;
                }
                if ($levelFilter !== null && $s->level !== $levelFilter) {
                    return false;
                }

                return true;
            });

            abort_if($groupStudents === [], 404, 'ไม่พบนักศึกษาในกลุ่มที่เลือก');

            foreach ($groupStudents as $student) {
                $regData = $this->registrationService->studentRegistration($viewer, $student->code, $term);
                if ($regData !== null) {
                    $documents[] = $this->buildStudentDocument($regData);
                }
            }
        }

        return [
            'documents' => $documents,
            'scope' => $scope,
            'count' => count($documents),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function buildStudentDocument(array $data): array
    {
        $st = $data['student'];
        $reqs = $data['requirements'];
        $level = (int) $st['level'];
        $term = (string) $data['academic_term'];
        $info = isset($data['student_info']) && is_array($data['student_info']) ? $data['student_info'] : [];

        [$termNo, $termYear] = $this->splitTerm($term);
        if (! empty($info['term_no'])) {
            $termNo = (string) $info['term_no'];
        }
        if (! empty($info['term_year'])) {
            $termYear = (string) $info['term_year'];
        }

        $addrParts = $this->parseAddress((string) $st['address']);
        $name = (string) ($info['name'] ?? $st['name']);
        $phone = (string) ($info['phone'] ?? $st['phone']);
        $facebook = (string) ($info['facebook'] ?? $st['facebook']);
        $lineId = (string) ($info['line_id'] ?? $st['line_id']);
        $houseNo = (string) ($info['house_no'] ?? $addrParts['house_no']);
        $moo = (string) ($info['moo'] ?? $addrParts['moo']);
        $subdistrict = (string) ($info['subdistrict'] ?? $addrParts['subdistrict']);
        $district = (string) ($info['district'] ?? $addrParts['district']);
        $province = (string) ($info['province'] ?? $addrParts['province']);
        $group = (string) ($info['group'] ?? ($st['group_name'] ?: $st['group_code']));
        $boxSubdistrict = (string) ($info['box_subdistrict'] ?? $subdistrict);

        $citizenRaw = (string) ($info['citizen_id'] ?? ($st['citizen_id_raw'] ?: $st['citizen_id']));
        $citizenDigits = $this->boxDigits($citizenRaw, 13);

        $studentCodeRaw = (string) ($info['code'] ?? $st['code']);
        $studentCodeDigits = $this->boxDigits($studentCodeRaw, 10);

        $compulsoryEarned = isset($info['compulsory_earned']) && $info['compulsory_earned'] !== ''
            ? $info['compulsory_earned']
            : $reqs['compulsory_earned'];
        $electiveEarned = isset($info['elective_earned']) && $info['elective_earned'] !== ''
            ? $info['elective_earned']
            : $reqs['elective_earned'];
        $compulsoryRemaining = isset($info['compulsory_remaining']) && $info['compulsory_remaining'] !== ''
            ? $info['compulsory_remaining']
            : $reqs['compulsory_remaining'];
        $electiveRemaining = isset($info['elective_remaining']) && $info['elective_remaining'] !== ''
            ? $info['elective_remaining']
            : $reqs['elective_remaining'];

        // Format compulsory subjects
        $compulsorySubjects = $data['compulsory_subjects'] ?? [];
        $compulsoryTotal = $reqs['compulsory_required'] ?? 44.0;

        // Format elective subjects and pad to 6-7 rows
        $electiveSubjects = $data['elective_subjects'] ?? [];
        $electiveTotal = $reqs['elective_required'] ?? 32.0;

        $targetElectiveRows = max(6, count($electiveSubjects));
        $paddedElectives = [];
        for ($i = 0; $i < $targetElectiveRows; $i++) {
            if (isset($electiveSubjects[$i])) {
                $paddedElectives[] = $electiveSubjects[$i];
            } else {
                $paddedElectives[] = [
                    'code' => '',
                    'name' => '',
                    'credits' => '',
                    'registered' => false,
                    'transferred' => false,
                    'remark' => '',
                ];
            }
        }

        return [
            'level' => $level,
            'level_title' => CurriculumCatalog::levelDocumentTitle($level),
            'term_display' => $term,
            'term_no' => $termNo,
            'term_year' => $termYear,
            'district_center_name' => CurriculumCatalog::formatDistrictCenterName($st['district_name'] ?? null),
            'student' => [
                'name' => $name,
                'phone' => $phone,
                'facebook' => $facebook,
                'line_id' => $lineId,
                'house_no' => $houseNo,
                'moo' => $moo,
                'subdistrict' => $subdistrict,
                'district' => $district,
                'province' => $province,
                'citizen_digits' => $citizenDigits,
                'student_code_digits' => $studentCodeDigits,
                'group' => $group,
                'box_subdistrict' => $boxSubdistrict,
                'compulsory_earned' => $compulsoryEarned,
                'elective_earned' => $electiveEarned,
                'compulsory_remaining' => $compulsoryRemaining,
                'elective_remaining' => $electiveRemaining,
            ],
            'compulsory_total' => (int) $compulsoryTotal,
            'compulsory_subjects' => $compulsorySubjects,
            'elective_total' => (int) $electiveTotal,
            'elective_subjects' => $paddedElectives,
            'notes' => $data['notes'] ?? '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildBlankDocument(int $level, string $term, string $districtName): array
    {
        [$termNo, $termYear] = $this->splitTerm($term);
        $reqs = CurriculumCatalog::creditRequirements($level);
        $compulsorySubjects = array_map(static fn (array $c): array => [
            'code' => $c['code'],
            'name' => $c['name'],
            'credits' => $c['credits'],
            'registered' => false,
            'transferred' => false,
            'remark' => '',
        ], CurriculumCatalog::compulsorySubjects($level));

        $paddedElectives = [];
        for ($i = 0; $i < 6; $i++) {
            $paddedElectives[] = [
                'code' => '',
                'name' => '',
                'credits' => '',
                'registered' => false,
                'transferred' => false,
                'remark' => '',
            ];
        }

        return [
            'level' => $level,
            'level_title' => CurriculumCatalog::levelDocumentTitle($level),
            'term_display' => $term,
            'term_no' => $termNo,
            'term_year' => $termYear,
            'district_center_name' => CurriculumCatalog::formatDistrictCenterName($districtName ?: null),
            'student' => [
                'name' => '',
                'phone' => '',
                'facebook' => '',
                'line_id' => '',
                'house_no' => '',
                'moo' => '',
                'subdistrict' => '',
                'district' => '',
                'province' => '',
                'citizen_digits' => array_fill(0, 13, ''),
                'student_code_digits' => array_fill(0, 10, ''),
                'group' => '',
                'box_subdistrict' => '',
                'compulsory_earned' => '',
                'elective_earned' => '',
                'compulsory_remaining' => '',
                'elective_remaining' => '',
            ],
            'compulsory_total' => (int) $reqs['compulsory'],
            'compulsory_subjects' => $compulsorySubjects,
            'elective_total' => (int) $reqs['elective'],
            'elective_subjects' => $paddedElectives,
            'notes' => '',
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
     * Split digits into exact fixed-length array of single characters.
     *
     * @return list<string>
     */
    private function boxDigits(string $raw, int $length): array
    {
        $clean = preg_replace('/\D+/', '', $raw) ?? '';
        $digits = mb_str_split($clean);

        $result = [];
        for ($i = 0; $i < $length; $i++) {
            $result[] = $digits[$i] ?? '';
        }

        return $result;
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

    private function resolveDistrictName(User $viewer): string
    {
        if ($viewer->district_id) {
            $d = District::find($viewer->district_id);
            if ($d) {
                return $d->name;
            }
        }

        return '';
    }
}
