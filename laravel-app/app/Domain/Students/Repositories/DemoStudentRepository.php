<?php

namespace App\Domain\Students\Repositories;

use App\Domain\Students\Models\Grade;
use App\Domain\Students\Models\KpchActivity;
use App\Domain\Students\Models\MoralAssessment;
use App\Domain\Students\Models\RegisteredSubject;
use App\Domain\Students\Models\Student;

/**
 * Canonical synthetic dataset used while legacy DBF imports are being migrated.
 * No query in this repository connects to the production or import databases.
 */
final class DemoStudentRepository implements StudentRepository
{
    /** @param list<int>|null $districtIds
     * @return list<Student>
     */
    public function students(?array $districtIds = null): array
    {
        $students = array_map(
            fn (array $row): Student => $this->studentFromArray($row),
            $this->studentRows(),
        );

        if ($districtIds === null) {
            return $students;
        }

        return array_values(array_filter(
            $students,
            static fn (Student $student): bool => in_array($student->districtId, $districtIds, true),
        ));
    }

    public function find(string $code, ?int $districtId = null, ?int $level = null): ?Student
    {
        foreach ($this->students($districtId === null ? null : [$districtId]) as $student) {
            if (hash_equals($student->code, trim($code))
                && ($districtId === null || $student->districtId === $districtId)
                && ($level === null || $student->level === $level)) {
                return $student;
            }
        }

        return null;
    }

    /** @return list<Grade> */
    public function gradesFor(Student $student): array
    {
        return array_map(
            fn (array $row): Grade => new Grade(
                studentCode: $student->code,
                subjectCode: $row['subjectCode'],
                subjectName: $row['subjectName'],
                credits: $row['credits'],
                subjectType: $row['subjectType'],
                term: $row['term'],
                grade: $row['grade'],
                transferred: $row['transferred'],
                examAttended: $row['examAttended'],
            ),
            $this->academicRows($student),
        );
    }

    /** @param list<Student> $students @return array<string, list<Grade>> */
    public function gradesForMany(array $students): array
    {
        $results = [];
        foreach ($students as $student) {
            $results[$this->studentKey($student)] = $this->gradesFor($student);
        }

        return $results;
    }

    /** @return list<RegisteredSubject> */
    public function subjectsFor(Student $student): array
    {
        return array_map(
            static fn (Grade $grade): RegisteredSubject => new RegisteredSubject(
                studentCode: $grade->studentCode,
                code: $grade->subjectCode,
                name: $grade->subjectName,
                credits: $grade->credits,
                type: $grade->subjectType,
                term: $grade->term,
                registrationStatus: match (true) {
                    $grade->transferred => 'transferred',
                    $grade->grade === null => 'registered',
                    $grade->isPassed() => 'passed',
                    default => 'needs_improvement',
                },
                transferred: $grade->transferred,
                grade: $grade->grade,
                examAttended: $grade->examAttended,
            ),
            $this->gradesFor($student),
        );
    }

    /** @return list<KpchActivity> */
    public function kpchFor(Student $student): array
    {
        $firstHours = round($student->kpchHours * .45, 1);
        $secondHours = round($student->kpchHours * .30, 1);
        $thirdHours = round($student->kpchHours - $firstHours - $secondHours, 1);

        return [
            new KpchActivity($student->code, "{$student->code}-A1", 'อาสาพัฒนาศูนย์การเรียนรู้', '1/2568', $firstHours, 'จิตอาสา', '2568-08-24'),
            new KpchActivity($student->code, "{$student->code}-A2", 'โครงการอ่านสร้างสุข', '1/2568', $secondHours, 'พัฒนาตนเอง', '2568-10-19'),
            new KpchActivity($student->code, "{$student->code}-A3", 'ชุมชนสะอาดร่วมใจ', '2/2568', $thirdHours, 'พัฒนาชุมชน', '2569-02-08'),
        ];
    }

    /** @return list<MoralAssessment> */
    public function moralFor(Student $student): array
    {
        $base = match ($student->moralResult) {
            'ดีเยี่ยม' => 4,
            'ดี' => 3,
            default => 2,
        };

        $categories = [
            $this->moralCategory('พัฒนาตนเอง', ['สะอาด', 'สุภาพ', 'กตัญญูกตเวที'], $base),
            $this->moralCategory('พัฒนาการทำงาน', ['ขยัน', 'ประหยัด', 'ซื่อสัตย์สุจริต'], $base),
            $this->moralCategory('อยู่ร่วมกันในสังคม', ['สามัคคี', 'มีน้ำใจ', 'มีวินัย'], $base),
            $this->moralCategory('พัฒนาประเทศชาติ', ['รักชาติ ศาสน์ กษัตริย์ และความเป็นไทย', 'ยึดมั่นประชาธิปไตย'], $base),
        ];
        $score = (float) array_sum(array_column($categories, 'score'));

        return [new MoralAssessment(
            studentCode: $student->code,
            term: $student->currentTerm,
            categories: $categories,
            score: $score,
            maximumScore: 44,
            result: $student->moralResult,
            assessedOn: '2569-03-01',
        )];
    }

    private function studentKey(Student $student): string
    {
        return "{$student->districtId}|{$student->level}|{$student->code}";
    }

    /** @return list<array<string, mixed>> */
    private function studentRows(): array
    {
        return [
            ['6650100001', 1, 'อำเภอเสนา', 'นาย', 'ธีรภัทร', 'แสงทอง', 1, 'ประถมศึกษา', 'SENA-P1-A', 'เสนา ประถม A', '1/2567', '2/2568', 'studying', 'กำลังศึกษา', 3.42, 38, 48, 128, 'ดีเยี่ยม'],
            ['6650100002', 1, 'อำเภอเสนา', 'นางสาว', 'ณัฐณิชา', 'บุญช่วย', 1, 'ประถมศึกษา', 'SENA-P1-A', 'เสนา ประถม A', '1/2567', '2/2568', 'studying', 'กำลังศึกษา', 3.18, 35, 48, 112, 'ดี'],
            ['6650200003', 1, 'อำเภอเสนา', 'นาย', 'ภัทรพล', 'ใจมั่น', 2, 'มัธยมศึกษาตอนต้น', 'SENA-M2-A', 'เสนา ม.ต้น A', '2/2566', '2/2568', 'studying', 'กำลังศึกษา', 2.76, 46, 56, 146, 'ดี'],
            ['6650200004', 1, 'อำเภอเสนา', 'นางสาว', 'พิมพ์ชนก', 'เพียรดี', 2, 'มัธยมศึกษาตอนต้น', 'SENA-M2-A', 'เสนา ม.ต้น A', '2/2566', '2/2568', 'graduated', 'จบการศึกษา', 3.64, 56, 56, 204, 'ดีเยี่ยม'],
            ['6650300005', 1, 'อำเภอเสนา', 'นาย', 'ชยพล', 'รุ่งเรือง', 3, 'มัธยมศึกษาตอนปลาย', 'SENA-M3-A', 'เสนา ม.ปลาย A', '1/2566', '2/2568', 'studying', 'กำลังศึกษา', 3.02, 61, 76, 170, 'ดี'],
            ['6650300006', 1, 'อำเภอเสนา', 'นางสาว', 'กัญญารัตน์', 'สุขสันต์', 3, 'มัธยมศึกษาตอนปลาย', 'SENA-M3-B', 'เสนา ม.ปลาย B', '1/2566', '2/2568', 'transferred', 'ย้ายสถานศึกษา', 2.58, 42, 76, 96, 'ผ่าน'],
            ['6650300007', 1, 'อำเภอเสนา', 'นาย', 'ธนภัทร', 'อินทร์อ่อน', 3, 'มัธยมศึกษาตอนปลาย', 'SENA-M3-B', 'เสนา ม.ปลาย B', '1/2567', '2/2568', 'studying', 'กำลังศึกษา', 3.88, 54, 76, 156, 'ดีเยี่ยม'],
            ['6650200008', 1, 'อำเภอเสนา', 'นางสาว', 'อรอนงค์', 'ศรีสุข', 2, 'มัธยมศึกษาตอนต้น', 'SENA-M2-B', 'เสนา ม.ต้น B', '1/2567', '2/2568', 'studying', 'กำลังศึกษา', 2.34, 31, 56, 82, 'ผ่าน'],
            ['6650300009', 2, 'อำเภอบางซ้าย', 'นาย', 'กิตติพงศ์', 'ทองใบ', 3, 'มัธยมศึกษาตอนปลาย', 'BSAI-M3-A', 'บางซ้าย ม.ปลาย A', '1/2567', '2/2568', 'studying', 'กำลังศึกษา', 3.26, 52, 76, 144, 'ดี'],
            ['6650200010', 2, 'อำเภอบางซ้าย', 'นางสาว', 'สุพิชญา', 'คงดี', 2, 'มัธยมศึกษาตอนต้น', 'BSAI-M2-A', 'บางซ้าย ม.ต้น A', '1/2567', '2/2568', 'studying', 'กำลังศึกษา', 3.52, 40, 56, 136, 'ดีเยี่ยม'],
        ];
    }

    /** @param list<mixed> $row */
    private function studentFromArray(array $row): Student
    {
        [$code, $districtId, $districtName, $prefix, $firstName, $lastName, $level, $levelLabel,
            $groupCode, $groupName, $enrollmentTerm, $currentTerm, $status, $statusLabel, $gpax,
            $creditsEarned, $creditsRequired, $kpchHours, $moralResult] = $row;

        return new Student(
            code: $code,
            districtId: $districtId,
            districtName: $districtName,
            prefix: $prefix,
            firstName: $firstName,
            lastName: $lastName,
            level: $level,
            levelLabel: $levelLabel,
            groupCode: $groupCode,
            groupName: $groupName,
            enrollmentTerm: $enrollmentTerm,
            currentTerm: $currentTerm,
            status: $status,
            statusLabel: $statusLabel,
            gpax: $gpax,
            creditsEarned: $creditsEarned,
            creditsRequired: $creditsRequired,
            kpchHours: $kpchHours,
            moralResult: $moralResult,
            contact: [
                'phone_masked' => '08x-xxx-'.substr($code, -4),
                'email' => "student.{$code}@example.test",
            ],
            guardian: [
                'name' => 'ผู้ปกครองตัวอย่าง',
                'phone_masked' => '09x-xxx-'.str_pad((string) (((int) substr($code, -4) + 731) % 10000), 4, '0', STR_PAD_LEFT),
            ],
        );
    }

    /** @return list<array<string, mixed>> */
    private function academicRows(Student $student): array
    {
        $catalog = match ($student->level) {
            1 => [
                ['ทช11001', 'เศรษฐกิจพอเพียง', 1.0, 'compulsory'],
                ['พท11001', 'ภาษาไทย', 3.0, 'compulsory'],
                ['พค11001', 'คณิตศาสตร์', 3.0, 'compulsory'],
                ['สค11001', 'สังคมศึกษา', 3.0, 'compulsory'],
                ['อช11001', 'ช่องทางการเข้าสู่อาชีพ', 2.0, 'elective'],
                ['ทช12005', 'สุขภาวะดีในชุมชน', 2.0, 'elective'],
            ],
            2 => [
                ['ทช21001', 'เศรษฐกิจพอเพียง', 1.0, 'compulsory'],
                ['พท21001', 'ภาษาไทย', 4.0, 'compulsory'],
                ['พค21001', 'คณิตศาสตร์', 4.0, 'compulsory'],
                ['พว21001', 'วิทยาศาสตร์', 4.0, 'compulsory'],
                ['อช21001', 'ช่องทางการพัฒนาอาชีพ', 2.0, 'elective'],
                ['สค22016', 'พลเมืองดิจิทัล', 2.0, 'elective'],
            ],
            default => [
                ['ทช31001', 'เศรษฐกิจพอเพียง', 1.0, 'compulsory'],
                ['พท31001', 'ภาษาไทย', 5.0, 'compulsory'],
                ['พค31001', 'คณิตศาสตร์', 5.0, 'compulsory'],
                ['พว31001', 'วิทยาศาสตร์', 5.0, 'compulsory'],
                ['อช31001', 'ช่องทางการขยายอาชีพ', 2.0, 'elective'],
                ['สค32034', 'การเงินเพื่อชีวิต', 3.0, 'elective'],
            ],
        };

        $gradeSets = [
            ['3.5', '3', '2.5', '4', '3', null],
            ['4', '3.5', '3', '3.5', '4', '2.5'],
            ['2.5', '2', '1.5', '3', '2.5', 'มส'],
            ['4', '4', '3.5', '4', '3.5', '4'],
            ['3', '2.5', '2', '3.5', '3', null],
            ['2', '1.5', '0', '2.5', '2', null],
            ['4', '4', '3.5', '4', '4', '3.5'],
            ['2', '1', '0', '2', '2.5', 'มส'],
        ];
        $index = max(0, ((int) substr($student->code, -2)) - 1) % count($gradeSets);
        $grades = $gradeSets[$index];

        return array_map(
            static fn (array $subject, int $subjectIndex): array => [
                'subjectCode' => $subject[0],
                'subjectName' => $subject[1],
                'credits' => $subject[2],
                'subjectType' => $subject[3],
                'term' => $subjectIndex < 3 ? '1/2568' : '2/2568',
                'grade' => $grades[$subjectIndex],
                'transferred' => $subjectIndex === 0 && ((int) substr($student->code, -1)) % 3 === 0,
                'examAttended' => ! ($grades[$subjectIndex] === 'มส'),
            ],
            $catalog,
            array_keys($catalog),
        );
    }

    /**
     * @param  list<string>  $items
     * @return array<string, mixed>
     */
    private function moralCategory(string $name, array $items, int $base): array
    {
        $itemRows = array_map(
            static fn (string $label, int $index): array => [
                'label' => $label,
                'score' => max(1, $base - ($index === 2 ? 1 : 0)),
                'maximum_score' => 4,
            ],
            $items,
            array_keys($items),
        );

        return [
            'name' => $name,
            'items' => $itemRows,
            'score' => array_sum(array_column($itemRows, 'score')),
            'maximum_score' => count($itemRows) * 4,
        ];
    }
}
