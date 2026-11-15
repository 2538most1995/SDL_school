<?php

namespace App\Domain\Students\Support;

use Illuminate\Validation\ValidationException;

final class ExtendedStudentStatistics
{
    public const KINDS = ['gender-statistics', 'age-statistics', 'exam-disqualified', 'nnet-statistics', 'credit-pending'];

    /** @return list<array{min: int, max: int, label: string}> */
    public static function ageRanges(string $input): array
    {
        $ranges = [];
        foreach (preg_split('/[,;\r\n]+/u', trim($input)) ?: [] as $part) {
            if (! preg_match('/^\s*(\d{1,3})\s*[-–]\s*(\d{1,3})\s*(?:ปี)?\s*$/u', $part, $match)) {
                throw ValidationException::withMessages(['age_ranges' => ['ระบุช่วงอายุ เช่น 0-15, 16-24 โดยแต่ละช่วงต้องไม่ทับซ้อนกัน']]);
            }
            $min = (int) $match[1];
            $max = (int) $match[2];
            if ($min > $max || $max > 120 || count($ranges) >= 20) {
                throw ValidationException::withMessages(['age_ranges' => ['ช่วงอายุต้องอยู่ระหว่าง 0-120 ปี เริ่มต้นไม่เกินสิ้นสุด และไม่เกิน 20 ช่วง']]);
            }
            foreach ($ranges as $range) {
                if ($min <= $range['max'] && $max >= $range['min']) {
                    throw ValidationException::withMessages(['age_ranges' => ['ช่วงอายุทับซ้อนกัน กรุณาแก้ไขก่อนประมวลผล']]);
                }
            }
            $ranges[] = ['min' => $min, 'max' => $max, 'label' => "{$min}-{$max} ปี"];
        }

        return $ranges;
    }

    /**
     * @param  array<string, mixed>  $student  Minimal imported fields, never a profile projection.
     * @param  list<array<string, mixed>>  $grades
     * @param  list<array<string, mixed>>  $activities
     * @param  list<array{min: int, max: int, label: string}>  $ranges
     * @return array<string, mixed>|null
     */
    public static function classify(string $kind, array $student, array $grades, array $activities, string $term, array $ranges = []): ?array
    {
        $student['gender'] = RegistrationStatistics::itemLabel('gender', (string) ($student['gender'] ?? ''));
        if ($kind === 'gender-statistics') {
            $student['metric'] = $student['gender'];

            return $student;
        }
        if ($kind === 'age-statistics') {
            $age = trim((string) ($student['age'] ?? ''));
            $valid = preg_match('/^\d{1,3}$/', $age) && (int) $age <= 120;
            $student['age_band'] = $valid ? 'อยู่นอกช่วงที่กำหนด' : 'ไม่ระบุอายุ';
            foreach ($ranges as $range) {
                if ($valid && (int) $age >= $range['min'] && (int) $age <= $range['max']) {
                    $student['age_band'] = $range['label'];
                    break;
                }
            }
            $student['metric'] = $student['age_band'];

            return $student;
        }

        $disqualified = false;
        $examTaken = false;
        $hasDatedExam = false;
        foreach (['nt_sem', 'nt_nosem'] as $field) {
            $examTerm = AcademicTerm::normalize((string) ($student[$field] ?? ''));
            $hasDatedExam = $hasDatedExam || $examTerm !== null;
            if ($examTerm !== null && AcademicTerm::compare($examTerm, $term) <= 0) {
                $examTaken = true;
            }
        }
        // Scores/explicit status have no reliable historical date in ITW;
        // these fields represent the latest imported evidence.
        foreach (['nt_sara1', 'nt_sara2'] as $field) {
            if (! $hasDatedExam || $examTaken) {
                $examTaken = $examTaken || (is_numeric($student[$field] ?? null) && (float) $student[$field] > 0);
            }
        }
        $flag = mb_strtoupper(trim((string) ($student['nnet_flag'] ?? '')));
        if (! $hasDatedExam || $examTaken) {
            $examTaken = $examTaken || in_array($flag, ['1', '2', 'Y', 'YES', 'P', 'PASS', 'PASSED', 'OK', 'DONE', 'TAKEN', 'สอบแล้ว', 'ผ่าน', 'สอบผ่าน', 'เข้าสอบ', 'มีผล'], true);
        }
        $passedSubjects = [];
        foreach ($grades as $grade) {
            $gradeTerm = AcademicTerm::normalize((string) ($grade['term'] ?? ''));
            if ($gradeTerm === null || AcademicTerm::compare($gradeTerm, $term) > 0) {
                continue;
            }
            $value = trim((string) ($grade['grade'] ?? ''));
            if ($gradeTerm === $term && ExamEligibilityStatistics::isDisqualifyingStatus($value)) {
                $disqualified = true;
            }
            $subject = mb_strtoupper(trim((string) ($grade['subject_code'] ?? '')));
            $name = mb_strtoupper((string) ($grade['subject_name'] ?? ''));
            $isExam = str_contains($subject.' '.$name, 'N-NET') || str_contains($subject.' '.$name, 'E-EXAM');
            if ($isExam) {
                $examTaken = $examTaken || (is_numeric($value) && (float) $value > 0);

                continue;
            }
            $passed = (is_numeric($value) && (float) $value >= 1) || in_array($value, ['ผ', 'ผ่าน'], true) || (string) ($grade['typ_code'] ?? '') === '1';
            if ($subject !== '' && $passed) {
                // A repeat registration never earns the same subject twice.
                $passedSubjects[$subject] = $grade;
            }
        }
        $student['nnet'] = $examTaken ? 'สอบแล้ว' : 'ยังไม่ได้สอบ';
        if ($kind === 'exam-disqualified') {
            if (! $disqualified) {
                return null;
            }
            $student['metric'] = 'ตัดสิทธิ์สอบ (ม/มส)';

            return $student;
        }
        if ($kind === 'nnet-statistics') {
            $student['metric'] = $student['nnet'];

            return $student;
        }
        $compulsory = 0.0;
        $elective = 0.0;
        foreach ($passedSubjects as $grade) {
            $credits = max(0, (float) ($grade['credits'] ?? 0));
            if (in_array((string) ($grade['subject_type'] ?? ''), ['1', 'compulsory', 'บังคับ'], true)) {
                $compulsory += $credits;
            } else {
                $elective += $credits;
            }
        }
        [$requiredComp, $requiredElec] = match ((int) $student['level_id']) {
            1 => [36, 12], 2 => [40, 16], 3 => [44, 32], default => [INF, INF],
        };
        if ($compulsory < $requiredComp || $elective < $requiredElec) {
            return null;
        }
        $hours = 0.0;
        foreach ($activities as $activity) {
            $activityTerm = AcademicTerm::normalize((string) ($activity['term'] ?? ''));
            if ($activityTerm !== null && AcademicTerm::compare($activityTerm, $term) <= 0) {
                $hours += max(0, (float) ($activity['hours'] ?? 0));
            }
        }
        $missingKpch = $hours < 200;
        if (! $missingKpch && $examTaken) {
            return null;
        }
        $student['completion_status'] = $missingKpch ? ($examTaken ? 'ขาด กพช.' : 'ขาด กพช. และ N-NET') : 'ขาด N-NET';
        $student['metric'] = $student['completion_status'];
        $student['kpch_hours'] = $hours;
        $student['credits_earned'] = $compulsory + $elective;

        return $student;
    }
}
