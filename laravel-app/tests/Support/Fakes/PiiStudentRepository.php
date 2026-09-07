<?php

namespace Tests\Support\Fakes;

use App\Domain\Students\Models\Grade;
use App\Domain\Students\Models\KpchActivity;
use App\Domain\Students\Models\MoralAssessment;
use App\Domain\Students\Models\RegisteredSubject;
use App\Domain\Students\Models\Student;
use App\Domain\Students\Repositories\StudentRepository;

final class PiiStudentRepository implements StudentRepository
{
    /** @var list<Student> */
    private array $students;

    public function __construct(int $districtId, string $districtName, int $otherDistrictId)
    {
        $this->students = [
            $this->student('STU001', $districtId, $districtName, '1111111111111'),
            $this->student('STU002', $districtId, $districtName, '2222222222222'),
            $this->student('OTHER001', $otherDistrictId, 'อำเภออื่น', '3333333333333'),
        ];
    }

    public function students(?array $districtIds = null): array
    {
        if ($districtIds === null) {
            return $this->students;
        }

        return array_values(array_filter(
            $this->students,
            static fn (Student $student): bool => in_array($student->districtId, $districtIds, true),
        ));
    }

    public function find(string $code, ?int $districtId = null, ?int $level = null): ?Student
    {
        $matches = array_values(array_filter(
            $this->students,
            static fn (Student $student): bool => $student->code === $code
                && ($districtId === null || $student->districtId === $districtId)
                && ($level === null || $student->level === $level),
        ));

        return count($matches) === 1 ? $matches[0] : null;
    }

    public function gradesFor(Student $student): array
    {
        return [new Grade(
            studentCode: $student->code,
            subjectCode: 'TH1001',
            subjectName: 'ภาษาไทย',
            credits: 3,
            subjectType: 'compulsory',
            term: '1/2569',
            grade: '3.5',
        )];
    }

    public function gradesForMany(array $students): array
    {
        $result = [];
        foreach ($students as $student) {
            $result["{$student->districtId}|{$student->level}|{$student->code}"] = $this->gradesFor($student);
        }

        return $result;
    }

    public function subjectsFor(Student $student): array
    {
        return [new RegisteredSubject(
            studentCode: $student->code,
            code: 'TH1001',
            name: 'ภาษาไทย',
            credits: 3,
            type: 'compulsory',
            term: '1/2569',
            registrationStatus: 'passed',
            transferred: false,
            grade: '3.5',
            examAttended: true,
        )];
    }

    public function kpchFor(Student $student): array
    {
        return [new KpchActivity(
            studentCode: $student->code,
            id: 'KPCH-1',
            name: 'จิตอาสา',
            term: '1/2569',
            hours: 20,
            category: 'community',
            completedOn: '2569-06-01',
        )];
    }

    public function moralFor(Student $student): array
    {
        return [new MoralAssessment(
            studentCode: $student->code,
            term: '1/2569',
            categories: [['name' => 'วินัย', 'items' => [['label' => 'ตรงต่อเวลา', 'score' => 9]]]],
            score: 9,
            maximumScore: 10,
            result: 'ดีมาก',
            assessedOn: '2569-06-01',
        )];
    }

    private function student(string $code, int $districtId, string $districtName, string $citizenId): Student
    {
        return new Student(
            code: $code,
            districtId: $districtId,
            districtName: $districtName,
            prefix: 'นาย',
            firstName: "ทดสอบ{$code}",
            lastName: 'ข้อมูลส่วนบุคคล',
            level: 2,
            levelLabel: 'มัธยมศึกษาตอนต้น',
            groupCode: 'GROUP-A',
            groupName: 'กลุ่ม A',
            enrollmentTerm: '1/2568',
            currentTerm: '1/2569',
            status: 'studying',
            statusLabel: 'กำลังศึกษา',
            gpax: 3.5,
            creditsEarned: 20,
            creditsRequired: 56,
            kpchHours: 20,
            moralResult: 'ดีมาก',
            contact: ['phone_masked' => '08x-xxx-9999', 'email_masked' => 's***@example.test'],
            guardian: ['name' => 'ผู้ปกครองลับ'],
            demographics: ['citizen_id_masked' => '1-xxxx-xxxxx-xx-1', 'birth_date' => '1 มกราคม 2550'],
            dataClassification: 'personal_data_sensitive',
            citizenId: $citizenId,
            phone: '0899999999',
            registeredAddress: 'บ้านเลขที่ 99 ที่อยู่ทะเบียนลับ',
            currentAddress: 'บ้านเลขที่ 100 ที่อยู่ปัจจุบันลับ',
            facebookUrl: 'https://facebook.com/private-student',
            lineId: 'private-line',
        );
    }
}
