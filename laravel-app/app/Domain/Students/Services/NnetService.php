<?php

namespace App\Domain\Students\Services;

use App\Domain\Students\Models\Student;
use App\Domain\Students\Repositories\StudentRepository;
use App\Models\District;
use App\Models\NnetResult;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class NnetService
{
    public const DEFAULT_SUBJECT_NAMES = [
        'ทักษะการเรียนรู้',
        'ความรู้พื้นฐาน',
        'การประกอบอาชีพ',
        'ทักษะการดำเนินชีวิต',
        'การพัฒนาสังคม',
    ];

    public function __construct(
        private readonly StudentRepository $studentRepository
    ) {}

    public static function levelLabel(int $level): string
    {
        return match ($level) {
            1 => 'ประถมศึกษา',
            2 => 'มัธยมศึกษาตอนต้น',
            3 => 'มัธยมศึกษาตอนปลาย',
            default => "ระดับชั้น {$level}",
        };
    }

    public static function parseLevel(?string $raw): int
    {
        $text = trim((string) $raw);
        if ($text === '1' || str_contains($text, 'ประถม')) {
            return 1;
        }
        if ($text === '2' || str_contains($text, 'มัธยมศึกษาตอนต้น') || str_contains($text, 'ม.ต้น')) {
            return 2;
        }
        if ($text === '3' || str_contains($text, 'มัธยมศึกษาตอนปลาย') || str_contains($text, 'ม.ปลาย')) {
            return 3;
        }

        return 2; // Default to ม.ต้น if not clear
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function listRecords(array $filters, User $user): array
    {
        $districtId = $this->resolveDistrictId($filters['district_id'] ?? null, $user);

        $query = NnetResult::query();

        if ($districtId !== null) {
            $query->where('district_id', $districtId);
        }

        if ($user->role === 'student') {
            $studentCitizen = $this->resolveStudentCitizenId($user);
            if ($studentCitizen) {
                $query->where('citizen_id', $studentCitizen);
            } else {
                return ['items' => [], 'total' => 0, 'meta' => []];
            }
        }

        if (! empty($filters['education_level'])) {
            $query->where('education_level', (int) $filters['education_level']);
        }

        if (! empty($filters['academic_year'])) {
            $query->where('academic_year', trim((string) $filters['academic_year']));
        }

        if (! empty($filters['round'])) {
            $query->where('round', (int) $filters['round']);
        }

        if (! empty($filters['status'])) {
            if ($filters['status'] === 'scored') {
                $query->where('has_score', true);
            } elseif ($filters['status'] === 'absent') {
                $query->where('has_score', false);
            }
        }

        if (! empty($filters['group'])) {
            $grp = trim((string) $filters['group']);
            $query->where(function ($q) use ($grp): void {
                $q->where('group_code', $grp)
                    ->orWhere('group_name', $grp);
            });
        }

        if (! empty($filters['search'])) {
            $s = trim((string) $filters['search']);
            $query->where(function ($q) use ($s): void {
                $q->where('student_name', 'like', "%{$s}%")
                    ->orWhere('citizen_id', 'like', "%{$s}%")
                    ->orWhere('student_code', 'like', "%{$s}%")
                    ->orWhere('seat_no', 'like', "%{$s}%")
                    ->orWhere('group_name', 'like', "%{$s}%");
            });
        }

        $sortField = $filters['sort_by'] ?? 'seat_no';
        $sortDirection = strtolower((string) ($filters['sort_direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

        $allowedSorts = ['seat_no', 'student_name', 'total_score', 'citizen_id', 'student_code', 'education_level', 'created_at'];
        if (! in_array($sortField, $allowedSorts, true)) {
            $sortField = 'seat_no';
        }

        $query->orderBy($sortField, $sortDirection)->orderBy('id', 'asc');

        $pageSize = isset($filters['page_size']) ? (int) $filters['page_size'] : 50;
        if ($pageSize <= 0 || $pageSize > 500) {
            $pageSize = 50;
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate($pageSize);

        // Fetch distinct filter options
        $availableYears = NnetResult::query()
            ->when($districtId !== null, fn ($q) => $q->where('district_id', $districtId))
            ->distinct()
            ->pluck('academic_year')
            ->sortDesc()
            ->values()
            ->all();

        $availableGroups = NnetResult::query()
            ->when($districtId !== null, fn ($q) => $q->where('district_id', $districtId))
            ->whereNotNull('group_name')
            ->where('group_name', '!=', '')
            ->distinct()
            ->pluck('group_name')
            ->sort()
            ->values()
            ->all();

        return [
            'items' => $paginator->items(),
            'total' => $paginator->total(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'page_size' => $paginator->perPage(),
            'available_years' => $availableYears,
            'available_groups' => $availableGroups,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summary(array $filters, User $user): array
    {
        $districtId = $this->resolveDistrictId($filters['district_id'] ?? null, $user);

        $query = NnetResult::query();

        if ($districtId !== null) {
            $query->where('district_id', $districtId);
        }

        $specificLevel = ! empty($filters['education_level']) ? (int) $filters['education_level'] : null;
        if ($specificLevel !== null) {
            $query->where('education_level', $specificLevel);
        }

        if (! empty($filters['academic_year'])) {
            $query->where('academic_year', trim((string) $filters['academic_year']));
        }

        if (! empty($filters['round'])) {
            $query->where('round', (int) $filters['round']);
        }

        if (! empty($filters['group'])) {
            $grp = trim((string) $filters['group']);
            $query->where(function ($q) use ($grp): void {
                $q->where('group_code', $grp)
                    ->orWhere('group_name', $grp);
            });
        }

        if ($user->role === 'student') {
            $studentCitizen = $this->resolveStudentCitizenId($user);
            if ($studentCitizen) {
                $query->where('citizen_id', $studentCitizen);
            }
        }

        /** @var Collection<int, NnetResult> $records */
        $records = $query->get();

        $totalCount = $records->count();
        $scoredRecords = $records->filter(fn (NnetResult $r): bool => $r->has_score && $r->total_score !== null);
        $scoredCount = $scoredRecords->count();
        $absentCount = $totalCount - $scoredCount;

        $maxTotalScore = $scoredCount > 0 ? (float) $scoredRecords->max('total_score') : 0.0;
        $minTotalScore = $scoredCount > 0 ? (float) $scoredRecords->min('total_score') : 0.0;

        // -----------------------------------------------------------------
        // กรณีที่ 1: เลือกระดับชั้นเฉพาะ (ประถม, ม.ต้น หรือ ม.ปลาย)
        // -----------------------------------------------------------------
        if ($specificLevel !== null) {
            $firstWithSubjects = $records->first(fn (NnetResult $r): bool => ! empty($r->subject_codes));
            $subjectCodes = $firstWithSubjects?->subject_codes ?? ['411', '412', '413', '414', '415'];
            $subjectNames = $firstWithSubjects?->subject_names ?? [];

            $subjectStats = [];
            $bestSubject = null;
            $bestAvg = -1.0;
            $totalScoresCount = 0;

            foreach ($subjectCodes as $idx => $code) {
                $codeStr = (string) $code;
                $name = $subjectNames[$codeStr] ?? ($subjectNames[$idx] ?? (self::DEFAULT_SUBJECT_NAMES[$idx] ?? "สาระที่ {$codeStr}"));

                $scoresForSubject = [];
                foreach ($scoredRecords as $rec) {
                    $score = $this->extractSubjectScore($rec, $idx, $codeStr);
                    if ($score !== null) {
                        $scoresForSubject[] = $score;
                    }
                }

                $count = count($scoresForSubject);
                $totalScoresCount += $count;
                $avg = $count > 0 ? round(array_sum($scoresForSubject) / $count, 2) : 0.0;
                $max = $count > 0 ? max($scoresForSubject) : 0.0;
                $min = $count > 0 ? min($scoresForSubject) : 0.0;

                if ($count > 0 && $avg > $bestAvg) {
                    $bestAvg = $avg;
                    $bestSubject = [
                        'code' => $codeStr,
                        'name' => $name,
                        'avg' => $avg,
                    ];
                }

                $subjectStats[] = [
                    'code' => $codeStr,
                    'name' => $name,
                    'average' => $avg,
                    'max' => $max,
                    'min' => $min,
                    'percentage' => min(100.0, max(0.0, $avg)),
                ];
            }

            // ค่าเฉลี่ยรวม: เอาคะแนนเฉลี่ยจำแนกตามแต่ละสาระบวกกัน แล้วหารด้วยจำนวนสาระ
            $subjectAverages = array_column($subjectStats, 'average');
            $subjectCount = count($subjectAverages);
            if ($totalScoresCount > 0 && $subjectCount > 0) {
                $avgTotalScore = round(array_sum($subjectAverages) / $subjectCount, 2);
            } else {
                $avgTotalScore = $scoredCount > 0 ? round((float) $scoredRecords->avg('total_score'), 2) : 0.0;
            }

            return [
                'total_students' => $totalCount,
                'scored_students' => $scoredCount,
                'absent_students' => $absentCount,
                'average_total_score' => $avgTotalScore,
                'max_total_score' => $maxTotalScore,
                'min_total_score' => $minTotalScore,
                'best_subject' => $bestSubject,
                'subjects' => $subjectStats,
            ];
        }

        // -----------------------------------------------------------------
        // กรณีที่ 2: ทุกระดับชั้น (All Levels)
        // ตามเงื่อนไข:
        // - แต่ละสาระ ให้เอาคะแนนเฉลี่ยของ ประถม ม.ต้น ม.ปลาย บวกกันแล้วหาร 3
        // - คะแนนรวมเฉลี่ย ให้เอา คะแนนรวมเฉลี่ยแต่ละระดับชั้นบวก แล้วหาร 3
        // -----------------------------------------------------------------
        $levelSubjectAverages = []; // [level => [0 => avg, 1 => avg, 2 => avg, 3 => avg, 4 => avg]]
        $levelOverallAverages = []; // [level => avg]

        $knownLevels = [1, 2, 3];
        $distinctLevels = $records->pluck('education_level')->unique()->filter()->all();
        $targetLevels = ! empty($distinctLevels)
            ? array_values(array_unique(array_merge($knownLevels, $distinctLevels)))
            : $knownLevels;

        foreach ($targetLevels as $lvl) {
            $lvlScored = $scoredRecords->where('education_level', $lvl);
            if ($lvlScored->isEmpty()) {
                continue;
            }

            $subAvgsForLvl = [];
            $totalSubScoresCountForLvl = 0;

            for ($idx = 0; $idx < 5; $idx++) {
                $scoresForSub = [];
                foreach ($lvlScored as $rec) {
                    $score = $this->extractSubjectScore($rec, $idx);
                    if ($score !== null) {
                        $scoresForSub[] = $score;
                    }
                }

                $cnt = count($scoresForSub);
                $totalSubScoresCountForLvl += $cnt;
                $subAvgsForLvl[$idx] = $cnt > 0 ? round(array_sum($scoresForSub) / $cnt, 2) : 0.0;
            }

            $levelSubjectAverages[$lvl] = $subAvgsForLvl;

            // คะแนนรวมเฉลี่ยของระดับชั้นนี้ (เฉลี่ยจาก 5 สาระของระดับนั้น)
            if ($totalSubScoresCountForLvl > 0) {
                $levelOverallAverages[$lvl] = round(array_sum($subAvgsForLvl) / 5, 2);
            } else {
                $levelOverallAverages[$lvl] = round((float) $lvlScored->avg('total_score'), 2);
            }
        }

        $activeLevelCount = count($levelSubjectAverages);

        $subjectStats = [];
        $bestSubject = null;
        $bestAvg = -1.0;

        for ($idx = 0; $idx < 5; $idx++) {
            $name = self::DEFAULT_SUBJECT_NAMES[$idx] ?? "สาระที่ " . ($idx + 1);
            $codeStr = "สาระที่ " . ($idx + 1);

            // เอาคะแนนเฉลี่ยของ ประถม ม.ต้น ม.ปลาย บวกกันแล้วหารจำนวนระดับชั้น (เช่น หาร 3)
            $sumAcrossLevels = 0.0;
            foreach ($levelSubjectAverages as $lvl => $subAvgs) {
                $sumAcrossLevels += ($subAvgs[$idx] ?? 0.0);
            }

            $avg = $activeLevelCount > 0 ? round($sumAcrossLevels / $activeLevelCount, 2) : 0.0;

            // คะแนนสูงสุด/ต่ำสุดของสาระนี้จากนักศึกษาทุกคนในทุกระดับ
            $allScoresForSub = [];
            foreach ($scoredRecords as $rec) {
                $score = $this->extractSubjectScore($rec, $idx);
                if ($score !== null) {
                    $allScoresForSub[] = $score;
                }
            }
            $max = count($allScoresForSub) > 0 ? max($allScoresForSub) : 0.0;
            $min = count($allScoresForSub) > 0 ? min($allScoresForSub) : 0.0;

            if ($avg > $bestAvg) {
                $bestAvg = $avg;
                $bestSubject = [
                    'code' => $codeStr,
                    'name' => $name,
                    'avg' => $avg,
                ];
            }

            $subjectStats[] = [
                'code' => $codeStr,
                'name' => $name,
                'average' => $avg,
                'max' => $max,
                'min' => $min,
                'percentage' => min(100.0, max(0.0, $avg)),
            ];
        }

        // คะแนนรวมเฉลี่ย: เอาคะแนนรวมเฉลี่ยแต่ละระดับชั้นบวก แล้วหาร 3 (หรือจำนวนระดับชั้นที่มีข้อมูล)
        if ($activeLevelCount > 0) {
            $avgTotalScore = round(array_sum($levelOverallAverages) / $activeLevelCount, 2);
        } else {
            $avgTotalScore = $scoredCount > 0 ? round((float) $scoredRecords->avg('total_score'), 2) : 0.0;
        }

        return [
            'total_students' => $totalCount,
            'scored_students' => $scoredCount,
            'absent_students' => $absentCount,
            'average_total_score' => $avgTotalScore,
            'max_total_score' => $maxTotalScore,
            'min_total_score' => $minTotalScore,
            'best_subject' => $bestSubject,
            'subjects' => $subjectStats,
        ];
    }

    /**
     * Bulk import N-NET results from parsed Excel data.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function bulkImport(array $payload, User $user): array
    {
        $districtId = $this->resolveDistrictId($payload['district_id'] ?? null, $user);
        if ($districtId === null) {
            throw ValidationException::withMessages(['district_id' => 'ไม่พบข้อมูลอำเภอสำหรับบันทึกข้อมูล']);
        }

        $level = (int) ($payload['education_level'] ?? 2);
        if ($level < 1 || $level > 3) {
            $level = self::parseLevel($payload['level_name'] ?? null);
        }
        $levelLabel = self::levelLabel($level);

        $academicYear = trim((string) ($payload['academic_year'] ?? '2569'));
        if ($academicYear === '') {
            $academicYear = '2569';
        }

        $round = (int) ($payload['round'] ?? 1);
        if ($round < 1) {
            $round = 1;
        }

        $rows = $payload['rows'] ?? [];
        if (! is_array($rows) || empty($rows)) {
            throw ValidationException::withMessages(['rows' => 'ไม่พบแถวข้อมูลผลการทดสอบสำหรับการนำเข้า']);
        }

        $subjectCodes = $payload['subject_codes'] ?? ['411', '412', '413', '414', '415'];
        $subjectNames = $payload['subject_names'] ?? [];

        // Build a student lookup dictionary from StudentRepository for this district
        $students = $this->studentRepository->students([$districtId]);
        $studentByCitizen = [];
        $studentByName = [];

        foreach ($students as $st) {
            if ($st->citizenId) {
                $cleanCitizen = preg_replace('/\D+/u', '', $st->citizenId);
                if (strlen($cleanCitizen) > 0 && strlen($cleanCitizen) < 13) {
                    $cleanCitizen = str_pad($cleanCitizen, 13, '0', STR_PAD_LEFT);
                }
                if (strlen($cleanCitizen) === 13) {
                    $studentByCitizen[$cleanCitizen] = $st;
                }
            }
            $cleanName = preg_replace('/\s+/u', '', $st->fullName());
            if ($cleanName !== '') {
                $studentByName[$cleanName] = $st;
            }
        }

        $imported = 0;
        $updated = 0;
        $matchedCount = 0;
        $unmatchedCount = 0;

        foreach ($rows as $row) {
            $rawCitizen = trim((string) ($row['citizen'] ?? $row['citizen_id'] ?? ''));
            $cleanCitizen = preg_replace('/\D+/u', '', $rawCitizen);
            if (strlen($cleanCitizen) > 0 && strlen($cleanCitizen) < 13) {
                $cleanCitizen = str_pad($cleanCitizen, 13, '0', STR_PAD_LEFT);
            }

            if (strlen($cleanCitizen) !== 13) {
                continue; // Skip invalid citizen IDs
            }

            $name = trim((string) ($row['name'] ?? ''));
            $seatNo = trim((string) ($row['seat'] ?? $row['seat_no'] ?? ''));
            $rawTotal = $row['total'] ?? $row['total_score'] ?? null;

            $hasScore = true;
            $totalScore = null;

            if ($rawTotal === '-' || $rawTotal === null || $rawTotal === '') {
                $hasScore = false;
                $totalScore = null;
            } elseif (is_numeric($rawTotal)) {
                $totalScore = round((float) $rawTotal, 2);
                $hasScore = true;
            }

            $rawScores = $row['scores'] ?? [];
            $rawLevels = $row['levels'] ?? [];

            $formattedScores = [];
            $formattedLevels = [];

            foreach ($subjectCodes as $idx => $code) {
                $codeStr = (string) $code;
                $scoreVal = $rawScores[$idx] ?? ($rawScores[$codeStr] ?? null);
                if ($scoreVal !== null && $scoreVal !== '-' && is_numeric($scoreVal)) {
                    $formattedScores[$codeStr] = round((float) $scoreVal, 2);
                } else {
                    $formattedScores[$codeStr] = null;
                }

                $levelVal = $rawLevels[$idx] ?? ($rawLevels[$codeStr] ?? null);
                if ($levelVal !== null && $levelVal !== '-') {
                    $formattedLevels[$codeStr] = trim((string) $levelVal);
                }
            }

            // Student matching
            $matchedStudent = $studentByCitizen[$cleanCitizen] ?? null;
            if (! $matchedStudent) {
                $cleanRowName = preg_replace('/\s+/u', '', $name);
                $matchedStudent = $studentByName[$cleanRowName] ?? null;
            }

            $studentCode = null;
            $groupCode = null;
            $groupName = null;

            if ($matchedStudent) {
                $matchedCount++;
                $studentCode = $matchedStudent->code;
                $groupCode = $matchedStudent->groupCode;
                $groupName = $matchedStudent->groupName;
                if ($name === '') {
                    $name = $matchedStudent->fullName();
                }
            } else {
                $unmatchedCount++;
            }

            $existing = NnetResult::query()
                ->where('district_id', $districtId)
                ->where('academic_year', $academicYear)
                ->where('round', $round)
                ->where('education_level', $level)
                ->where('citizen_id', $cleanCitizen)
                ->first();

            $recordData = [
                'district_id' => $districtId,
                'academic_year' => $academicYear,
                'round' => $round,
                'education_level' => $level,
                'education_level_label' => $levelLabel,
                'seat_no' => $seatNo ?: null,
                'citizen_id' => $cleanCitizen,
                'student_code' => $studentCode,
                'student_name' => $name,
                'group_code' => $groupCode,
                'group_name' => $groupName,
                'total_score' => $totalScore,
                'has_score' => $hasScore,
                'subject_codes' => array_values(array_map('strval', $subjectCodes)),
                'subject_names' => $subjectNames,
                'subject_scores' => $formattedScores,
                'subject_levels' => $formattedLevels,
                'created_by' => $user->id,
            ];

            if ($existing) {
                $existing->update($recordData);
                $updated++;
            } else {
                NnetResult::create($recordData);
                $imported++;
            }
        }

        return [
            'total_processed' => $imported + $updated,
            'imported' => $imported,
            'updated' => $updated,
            'matched_students' => $matchedCount,
            'unmatched_students' => $unmatchedCount,
            'district_id' => $districtId,
            'academic_year' => $academicYear,
            'round' => $round,
            'education_level' => $level,
            'education_level_label' => $levelLabel,
        ];
    }

    /**
     * Create single record (CRUD C).
     *
     * @param  array<string, mixed>  $data
     */
    public function createRecord(array $data, User $user): NnetResult
    {
        $districtId = $this->resolveDistrictId($data['district_id'] ?? null, $user);
        if ($districtId === null) {
            throw ValidationException::withMessages(['district_id' => 'ไม่พบข้อมูลอำเภอ']);
        }

        $cleanCitizen = preg_replace('/\D+/u', '', (string) ($data['citizen_id'] ?? ''));
        if (strlen($cleanCitizen) > 0 && strlen($cleanCitizen) < 13) {
            $cleanCitizen = str_pad($cleanCitizen, 13, '0', STR_PAD_LEFT);
        }
        if (strlen($cleanCitizen) !== 13) {
            throw ValidationException::withMessages(['citizen_id' => 'เลขประจำตัวประชาชนต้องมี 13 หลัก']);
        }

        $level = (int) ($data['education_level'] ?? 2);
        $academicYear = trim((string) ($data['academic_year'] ?? '2569'));
        $round = (int) ($data['round'] ?? 1);

        $exists = NnetResult::query()
            ->where('district_id', $districtId)
            ->where('academic_year', $academicYear)
            ->where('round', $round)
            ->where('education_level', $level)
            ->where('citizen_id', $cleanCitizen)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['citizen_id' => 'มีข้อมูลผลสอบของนักศึกษารายนี้ในรอบและระดับชั้นดังกล่าวแล้ว']);
        }

        // Try to match student
        $students = $this->studentRepository->students([$districtId]);
        $matched = null;
        foreach ($students as $st) {
            if ($st->citizenId) {
                $stCit = preg_replace('/\D+/u', '', $st->citizenId);
                if (strlen($stCit) > 0 && strlen($stCit) < 13) {
                    $stCit = str_pad($stCit, 13, '0', STR_PAD_LEFT);
                }
                if ($stCit === $cleanCitizen) {
                    $matched = $st;
                    break;
                }
            }
        }

        $studentCode = $data['student_code'] ?? $matched?->code;
        $groupCode = $data['group_code'] ?? $matched?->groupCode;
        $groupName = $data['group_name'] ?? $matched?->groupName;
        $studentName = trim((string) ($data['student_name'] ?? ($matched?->fullName() ?? '')));

        if ($studentName === '') {
            throw ValidationException::withMessages(['student_name' => 'กรุณาระบุชื่อ-สกุลนักศึกษา']);
        }

        $hasScore = isset($data['has_score']) ? (bool) $data['has_score'] : true;
        $totalScore = $hasScore && isset($data['total_score']) && is_numeric($data['total_score']) ? (float) $data['total_score'] : null;

        return NnetResult::create([
            'district_id' => $districtId,
            'academic_year' => $academicYear,
            'round' => $round,
            'education_level' => $level,
            'education_level_label' => self::levelLabel($level),
            'seat_no' => $data['seat_no'] ?? null,
            'citizen_id' => $cleanCitizen,
            'student_code' => $studentCode,
            'student_name' => $studentName,
            'group_code' => $groupCode,
            'group_name' => $groupName,
            'total_score' => $totalScore,
            'has_score' => $hasScore,
            'subject_codes' => $data['subject_codes'] ?? ['411', '412', '413', '414', '415'],
            'subject_names' => $data['subject_names'] ?? [],
            'subject_scores' => $data['subject_scores'] ?? [],
            'subject_levels' => $data['subject_levels'] ?? [],
            'created_by' => $user->id,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * Get single record (CRUD R).
     */
    public function getRecord(int $id, User $user): NnetResult
    {
        $record = NnetResult::with('district')->findOrFail($id);
        $this->ensureCanAccessRecord($record, $user);

        return $record;
    }

    /**
     * Update single record (CRUD U).
     *
     * @param  array<string, mixed>  $data
     */
    public function updateRecord(int $id, array $data, User $user): NnetResult
    {
        $record = NnetResult::findOrFail($id);
        $this->ensureCanAccessRecord($record, $user);

        if (isset($data['seat_no'])) {
            $record->seat_no = $data['seat_no'] ?: null;
        }

        if (isset($data['student_name']) && trim((string) $data['student_name']) !== '') {
            $record->student_name = trim((string) $data['student_name']);
        }

        if (isset($data['student_code'])) {
            $record->student_code = $data['student_code'] ?: null;
        }

        if (isset($data['group_name'])) {
            $record->group_name = $data['group_name'] ?: null;
        }

        if (isset($data['has_score'])) {
            $record->has_score = (bool) $data['has_score'];
        }

        if (isset($data['total_score'])) {
            $record->total_score = ($record->has_score && is_numeric($data['total_score']))
                ? round((float) $data['total_score'], 2)
                : null;
        }

        if (isset($data['subject_scores'])) {
            $record->subject_scores = $data['subject_scores'];
        }

        if (isset($data['subject_levels'])) {
            $record->subject_levels = $data['subject_levels'];
        }

        if (isset($data['notes'])) {
            $record->notes = $data['notes'];
        }

        $record->save();

        return $record;
    }

    /**
     * Delete single record (CRUD D).
     */
    public function deleteRecord(int $id, User $user): bool
    {
        $record = NnetResult::findOrFail($id);
        $this->ensureCanAccessRecord($record, $user);

        return (bool) $record->delete();
    }

    /**
     * Bulk clear records matching filters.
     *
     * @param  array<string, mixed>  $filters
     */
    public function clearRecords(array $filters, User $user): int
    {
        $districtId = $this->resolveDistrictId($filters['district_id'] ?? null, $user);
        if ($districtId === null) {
            throw ValidationException::withMessages(['district_id' => 'ไม่พบข้อมูลอำเภอสำหรับการล้างข้อมูล']);
        }

        $query = NnetResult::query()->where('district_id', $districtId);

        if (! empty($filters['education_level'])) {
            $query->where('education_level', (int) $filters['education_level']);
        }

        if (! empty($filters['academic_year'])) {
            $query->where('academic_year', trim((string) $filters['academic_year']));
        }

        if (! empty($filters['round'])) {
            $query->where('round', (int) $filters['round']);
        }

        return $query->delete();
    }

    private function resolveDistrictId(mixed $requestedDistrictId, User $user): ?int
    {
        if ($user->role === 'super_admin') {
            return $requestedDistrictId ? (int) $requestedDistrictId : $user->district_id;
        }

        return $user->district_id;
    }

    private function resolveStudentCitizenId(User $user): ?string
    {
        if ($user->role !== 'student') {
            return null;
        }

        // Student's username or legacy identity is often citizen ID
        $clean = preg_replace('/\D+/u', '', $user->username);
        if (strlen($clean) > 0 && strlen($clean) < 13) {
            $clean = str_pad($clean, 13, '0', STR_PAD_LEFT);
        }
        if (strlen($clean) === 13) {
            return $clean;
        }

        if ($user->legacy_ref) {
            $clean = preg_replace('/\D+/u', '', $user->legacy_ref);
            if (strlen($clean) > 0 && strlen($clean) < 13) {
                $clean = str_pad($clean, 13, '0', STR_PAD_LEFT);
            }
            if (strlen($clean) === 13) {
                return $clean;
            }
        }

        return null;
    }

    private function ensureCanAccessRecord(NnetResult $record, User $user): void
    {
        if ($user->role === 'super_admin') {
            return;
        }

        if ($user->role === 'admin' || $user->role === 'teacher') {
            if ($user->district_id && $record->district_id !== $user->district_id) {
                abort(403, 'คุณไม่มีสิทธิ์เข้าถึงข้อมูลของอำเภออื่น');
            }

            return;
        }

        if ($user->role === 'student') {
            $citizen = $this->resolveStudentCitizenId($user);
            if (! $citizen || $record->citizen_id !== $citizen) {
                abort(403, 'คุณสามารถดูผลคะแนน N-NET ของตนเองได้เท่านั้น');
            }

            return;
        }

        abort(403, 'คุณไม่มีสิทธิ์ในการจัดการข้อมูลนี้');
    }

    private function extractSubjectScore(NnetResult $rec, int $idx, ?string $code = null): ?float
    {
        $scores = $rec->subject_scores;
        if (! is_array($scores)) {
            return null;
        }

        // 1. Look up by provided code
        if ($code !== null && isset($scores[$code]) && is_numeric($scores[$code])) {
            return (float) $scores[$code];
        }

        // 2. Look up by record's own subject_codes at $idx
        $recCodes = $rec->subject_codes;
        if (is_array($recCodes) && isset($recCodes[$idx])) {
            $recCode = (string) $recCodes[$idx];
            if (isset($scores[$recCode]) && is_numeric($scores[$recCode])) {
                return (float) $scores[$recCode];
            }
        }

        // 3. Look up by index
        if (isset($scores[$idx]) && is_numeric($scores[$idx])) {
            return (float) $scores[$idx];
        }

        if (isset($scores[(string) $idx]) && is_numeric($scores[(string) $idx])) {
            return (float) $scores[(string) $idx];
        }

        return null;
    }
}
