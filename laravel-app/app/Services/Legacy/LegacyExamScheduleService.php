<?php

namespace App\Services\Legacy;

use App\Domain\Students\Models\Student;
use App\Domain\Students\Repositories\StudentRepository;
use App\Domain\Students\Support\AcademicTerm;
use Illuminate\Database\DatabaseManager;

final class LegacyExamScheduleService
{
    /** @var array<int, string|null> */
    private array $batchKeys = [];

    /** @var array<string, list<string>> */
    private array $importedTables = [];

    /** @var array<string, array<string, string>> */
    private array $fieldMaps = [];

    /** @var array<int, string|null> */
    private array $districtSchoolCodes = [];

    /** @var array<string, list<array{group_code: string, group_name: string, school_code: string, advisor: string}>> */
    private array $groupCatalogs = [];

    /** @var array<string, list<object>> */
    private array $examRoomsByTerm = [];

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly StudentRepository $students,
    ) {}

    /** @return array<string, mixed> */
    public function forStudent(Student $student): array
    {
        $normalizedStudentTerm = AcademicTerm::normalize($student->currentTerm) ?? trim((string) $student->currentTerm);
        $allSubjects = $this->students->subjectsFor($student);
        $registered = array_values(array_filter(
            $allSubjects,
            static function ($subject) use ($normalizedStudentTerm, $student): bool {
                $normalizedTerm = AcademicTerm::normalize($subject->term) ?? trim((string) $subject->term);
                $matchesTerm = $normalizedTerm === $normalizedStudentTerm || $subject->term === $student->currentTerm;
                if (! $matchesTerm) {
                    return false;
                }

                return $subject->grade === null || trim((string) $subject->grade) === '' || $subject->registrationStatus === 'registered';
            },
        ));

        if ($registered === []) {
            $registered = array_values(array_filter(
                $allSubjects,
                static function ($subject) use ($normalizedStudentTerm, $student): bool {
                    $normalizedTerm = AcademicTerm::normalize($subject->term) ?? trim((string) $subject->term);

                    return $normalizedTerm === $normalizedStudentTerm || $subject->term === $student->currentTerm;
                },
            ));
        }

        $subjects = [];
        foreach ($registered as $subject) {
            $subjects[trim($subject->code)] = $subject;
        }

        $batchKey = $this->activeBatchKey($student->districtId);
        $scheduleTable = $batchKey === null ? null : ($this->tablesFor($batchKey, 'schedule', (string) $student->level)[0] ?? null);
        $fieldReady = $batchKey !== null && $this->tablesFor($batchKey, 'field') !== [];
        $groupReady = $batchKey !== null && $this->tablesFor($batchKey, 'group') !== [];
        $fields = $this->fieldMap($batchKey);
        $groupFields = $this->groupFieldMap($batchKey);
        $studentGroupFld = $groupFields[trim((string) $student->groupCode)] ?? '';
        $groupMetadata = $this->groupMetadata($batchKey, (string) $student->groupCode, (string) $student->groupName);
        $schoolCode = $this->districtSchoolCode($student->districtId) ?? $groupMetadata['school_code'];

        $rows = [];
        if ($scheduleTable !== null && $subjects !== []) {
            $termVariants = array_values(array_unique(array_filter([
                $student->currentTerm,
                $normalizedStudentTerm,
                ...AcademicTerm::variants($student->currentTerm),
            ])));

            $scheduleRows = $this->database->connection()->table($scheduleTable)
                ->whereIn('_perf_sub', array_keys($subjects))
                ->get();
            foreach ($scheduleRows as $record) {
                $row = (array) $record;
                $code = trim((string) ($row['sub_code'] ?? $row['_perf_sub'] ?? ''));
                $rawTerm = trim((string) ($row['semestry'] ?? $row['_perf_semestry'] ?? ''));
                $term = AcademicTerm::normalize($rawTerm) ?? $rawTerm;
                $date = trim((string) ($row['exam_day'] ?? ''));
                if (! isset($subjects[$code]) || ($term !== $normalizedStudentTerm && $term !== $student->currentTerm && ! in_array($rawTerm, $termVariants, true)) || $date === '') {
                    continue;
                }
                $fieldCode = trim((string) (
                    $row['fld_code'] ?? $row['fldcode'] ?? $row['field_code'] ?? $row['fld_id'] ?? $row['field'] ?? ''
                ));
                if ($fieldCode === '' && $studentGroupFld !== '') {
                    $fieldCode = $studentGroupFld;
                }
                $directLoc = trim((string) (
                    $row['fld_name'] ?? $row['fldname'] ?? $row['field_name'] ?? $row['location'] ?? $row['place'] ?? $row['exam_place'] ?? $row['loc_name'] ?? ''
                ));
                $location = $fields[$fieldCode] ?? ($directLoc !== '' ? $directLoc : ($fields['1'] ?? ''));
                if ($location === '' || $location === '-') {
                    $location = $student->districtName ?: '-';
                }

                $rows[] = [
                    'subject_code' => $code,
                    'subject_name' => $subjects[$code]->name,
                    'term' => $term,
                    'exam_date' => $date,
                    'exam_date_display' => $this->date($date),
                    'start_time' => $this->time((string) ($row['exam_start'] ?? '')),
                    'end_time' => $this->time((string) ($row['exam_end'] ?? '')),
                    'location' => $location,
                    'room' => $this->examRoom($student, $term, $code, $row, $batchKey),
                ];
            }
        }
        usort($rows, static fn (array $left, array $right): int => [$left['exam_date'], $left['start_time'], $left['subject_code']] <=> [$right['exam_date'], $right['start_time'], $right['subject_code']]);

        return [
            'student' => [
                'code' => $student->code,
                'name' => $student->fullName(),
                'level' => $student->levelLabel,
                'group' => $student->groupName ?: $student->groupCode,
                'district' => $student->districtName,
                'school_code' => $schoolCode ?: '-',
                'advisor' => $groupMetadata['advisor'] ?: '-',
            ],
            'term' => $student->currentTerm,
            'rows' => $rows,
            'source_ready' => $scheduleTable !== null,
            'sources' => [
                'schedule' => $scheduleTable !== null,
                'field' => $fieldReady,
                'group' => $groupReady,
                'exam_rooms' => $this->database->connection()->getSchemaBuilder()->hasTable('exam_rooms'),
            ],
        ];
    }

    private function activeBatchKey(int $districtId): ?string
    {
        if (array_key_exists($districtId, $this->batchKeys)) {
            return $this->batchKeys[$districtId];
        }
        if (! (bool) config('system_data.student_enabled')) {
            return $this->batchKeys[$districtId] = null;
        }
        $connection = $this->database->connection();
        $mysql = $connection->getDriverName() === 'mysql';
        $key = (string) ($connection
            ->table('import_batches as batch')
            ->join('import_history as history', function ($join) use ($mysql): void {
                $join->on('history.id', '=', 'batch.import_history_id')
                    ->on('history.district_id', '=', 'batch.district_id');
                if ($mysql) {
                    $join->whereRaw('BINARY history.batch_key = BINARY batch.batch_key');
                } else {
                    $join->on('history.batch_key', '=', 'batch.batch_key');
                }
            })
            ->where('batch.district_id', $districtId)
            ->where('history.status', 'success')
            ->orderByDesc('batch.created_at')
            ->value('batch.batch_key') ?? '');

        return $this->batchKeys[$districtId] = preg_match('/^import_\d{10}_[A-Za-z0-9]+$/', $key) === 1 ? $key : null;
    }

    /** @return list<string> */
    private function tablesFor(string $batchKey, string $type, ?string $level = null): array
    {
        $cacheKey = implode('|', [$batchKey, $type, $level ?? '*']);
        if (array_key_exists($cacheKey, $this->importedTables)) {
            return $this->importedTables[$cacheKey];
        }
        if (preg_match('/^import_\d{10}_[A-Za-z0-9]+$/', $batchKey) !== 1
            || preg_match('/^[a-z]+$/', $type) !== 1
            || ($level !== null && ! in_array($level, ['1', '2', '3'], true))) {
            return $this->importedTables[$cacheKey] = [];
        }

        $prefix = 'db_'.$batchKey.'_';
        $expected = $level === null ? null : $prefix.$level.'_'.$type;
        $tables = array_values(array_filter(
            $this->database->connection()->getSchemaBuilder()->getTableListing(null, false),
            static function (string $table) use ($prefix, $expected, $type): bool {
                if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $table) !== 1 || ! str_starts_with($table, $prefix)) {
                    return false;
                }

                return $expected === null ? str_ends_with($table, '_'.$type) : $table === $expected;
            },
        ));
        sort($tables, SORT_NATURAL | SORT_FLAG_CASE);

        return $this->importedTables[$cacheKey] = $tables;
    }

    private function examRoom(Student $student, string $term, string $subjectCode, array $row = [], ?string $batchKey = null): string
    {
        $roomFromDb = $this->queryExamRoomDb($student, $term, $subjectCode);
        if ($roomFromDb !== '-') {
            return $roomFromDb;
        }

        if ($batchKey !== null) {
            $gradeRooms = $this->studentGradeRoomMap($batchKey, (string) $student->level, (string) $student->code);
            $roomNo = $gradeRooms[$subjectCode] ?? '';
            if ($roomNo !== '') {
                $cleanNo = ltrim($roomNo, '0') ?: $roomNo;

                return preg_match('/^\d+$/', $cleanNo) ? 'ห้อง '.$cleanNo : $cleanNo;
            }
        }

        $dbfRoom = trim((string) (
            $row['room'] ?? $row['room_no'] ?? $row['roomname'] ?? $row['room_name'] ?? $row['exam_room'] ?? $row['rm_no'] ?? $row['room_code'] ?? $row['building'] ?? ''
        ));
        if ($dbfRoom !== '') {
            $clean = ltrim($dbfRoom, '0') ?: $dbfRoom;

            return preg_match('/^\d+$/', $clean) ? 'ห้อง '.$clean : $clean;
        }

        return 'ห้อง 1';
    }

    private function queryExamRoomDb(Student $student, string $term, string $subjectCode): string
    {
        if (! (bool) config('system_data.enabled')) {
            return '-';
        }
        if (! $this->database->connection()->getSchemaBuilder()->hasTable('exam_rooms')) {
            return '-';
        }
        $termCacheKey = implode('|', [$student->districtId, $term]);
        if (! array_key_exists($termCacheKey, $this->examRoomsByTerm)) {
            $this->examRoomsByTerm[$termCacheKey] = $this->database->connection()->table('exam_rooms')
                ->select(['id', 'subject_code', 'assignment_type', 'start_val', 'end_val', 'room_name'])
                ->where('district_id', $student->districtId)
                ->where(function ($q) use ($term): void {
                    $q->whereIn('term', AcademicTerm::variants($term))
                        ->orWhere('term', 'all')
                        ->orWhere('term', '*');
                })->orderBy('id')->get()->all();
        }

        $rows = array_values(array_filter(
            $this->examRoomsByTerm[$termCacheKey],
            static function (object $row) use ($subjectCode): bool {
                $candidate = trim((string) ($row->subject_code ?? ''));

                return strcasecmp($candidate, $subjectCode) === 0 || $candidate === 'all' || $candidate === '*';
            },
        ));
        // Preserve the previous fallback: when there is no subject-specific or
        // wildcard row, evaluate all rooms for the term.
        if ($rows === []) {
            $rows = $this->examRoomsByTerm[$termCacheKey];
        }

        foreach ($rows as $row) {
            $assignmentType = (string) ($row->assignment_type ?? 'student_range');
            $startVal = trim((string) ($row->start_val ?? ''));
            $endVal = trim((string) ($row->end_val ?? ''));
            $roomName = trim((string) ($row->room_name ?? ''));

            if ($roomName === '') {
                continue;
            }

            $candidateValues = $assignmentType === 'group_range'
                ? array_filter([$student->groupCode, $student->groupName])
                : array_filter([$student->code]);

            foreach ($candidateValues as $val) {
                if ($this->matchValue((string) $val, $startVal, $endVal)) {
                    return $roomName;
                }
            }
        }

        // If exam_rooms has rows for this district, return the first room as fallback
        if ($rows !== []) {
            $firstRoom = trim((string) ($rows[0]->room_name ?? ''));
            if ($firstRoom !== '') {
                return $firstRoom;
            }
        }

        return '-';
    }

    private function matchValue(string $val, string $start, string $end): bool
    {
        $val = trim($val);
        $start = trim($start);
        $end = trim($end);

        if ($val === '') {
            return false;
        }

        if ($start === '' || $start === '*' || strtolower($start) === 'all' || $end === '' || $end === '*' || strtolower($end) === 'all') {
            return true;
        }

        if (ctype_digit($val) && ctype_digit($start) && ctype_digit($end)) {
            $numVal = ltrim($val, '0') ?: '0';
            $numStart = ltrim($start, '0') ?: '0';
            $numEnd = ltrim($end, '0') ?: '0';
            $compareNumbers = static fn (string $left, string $right): int => strlen($left) <=> strlen($right)
                ?: strcmp($left, $right);

            return $compareNumbers($numVal, $numStart) >= 0
                && $compareNumbers($numVal, $numEnd) <= 0;
        }

        if (strcasecmp($val, $start) === 0 || strcasecmp($val, $end) === 0) {
            return true;
        }

        if (str_contains($val, $start) || str_contains($val, $end)) {
            return true;
        }

        return strnatcasecmp($val, $start) >= 0 && strnatcasecmp($val, $end) <= 0;
    }

    /** @return array<string, string> */
    private function fieldMap(?string $batchKey): array
    {
        $cacheKey = 'fieldMap|'.($batchKey ?? '');
        if (isset($this->fieldMaps[$cacheKey])) {
            return $this->fieldMaps[$cacheKey];
        }
        $fields = [];
        if ($batchKey !== null) {
            foreach ($this->tablesFor($batchKey, 'field') as $table) {
                foreach ($this->database->connection()->table($table)->get() as $record) {
                    $row = (array) $record;
                    $code = trim((string) (
                        $row['fld_code'] ?? $row['fldcode'] ?? $row['field_code'] ?? $row['fld_id'] ?? $row['id'] ?? ''
                    ));
                    $name = trim((string) (
                        $row['fld_name'] ?? $row['fldname'] ?? $row['field_name'] ?? $row['loc_name'] ?? $row['place_name'] ?? $row['school_name'] ?? $row['name'] ?? $row['title'] ?? ''
                    ));
                    if ($code !== '') {
                        $fields[$code] = $name !== '' ? $name : $code;
                    }
                }
            }
        }

        return $this->fieldMaps[$cacheKey] = $fields;
    }

    /** @return array<string, string> */
    private function groupFieldMap(?string $batchKey): array
    {
        if ($batchKey === null) {
            return [];
        }
        $cacheKey = 'groupFieldMap|'.$batchKey;
        if (isset($this->fieldMaps[$cacheKey])) {
            return $this->fieldMaps[$cacheKey];
        }
        $map = [];
        foreach ($this->tablesFor($batchKey, 'group') as $table) {
            foreach ($this->database->connection()->table($table)->get() as $record) {
                $row = (array) $record;
                $grpCode = trim((string) ($row['grp_code'] ?? ''));
                $fldCode = trim((string) ($row['grp_field'] ?? $row['fld_code'] ?? $row['field'] ?? ''));
                if ($grpCode !== '' && $fldCode !== '') {
                    $map[$grpCode] = $fldCode;
                }
            }
        }

        return $this->fieldMaps[$cacheKey] = $map;
    }

    /** @return array{school_code: string, advisor: string} */
    private function groupMetadata(?string $batchKey, string $groupCode, string $groupName): array
    {
        $empty = ['school_code' => '', 'advisor' => ''];
        if ($batchKey === null) {
            return $empty;
        }

        $groupCode = trim($groupCode);
        $groupName = trim($groupName);
        $cacheKey = 'groupMetadata|'.$batchKey.'|'.$groupCode.'|'.$groupName;
        if (isset($this->fieldMaps[$cacheKey])) {
            return $this->fieldMaps[$cacheKey];
        }

        $catalog = $this->groupCatalog($batchKey);
        $matches = array_values(array_filter(
            $catalog,
            static fn (array $item): bool => $groupCode !== '' && $item['group_code'] === $groupCode,
        ));
        if ($matches === [] && $groupName !== '') {
            $matches = array_values(array_filter(
                $catalog,
                static fn (array $item): bool => $item['group_name'] === $groupName,
            ));
        }
        if (count($matches) === 1) {
            return $this->fieldMaps[$cacheKey] = [
                'school_code' => $matches[0]['school_code'],
                'advisor' => $matches[0]['advisor'],
            ];
        }

        return $this->fieldMaps[$cacheKey] = $empty;
    }

    /** @return list<array{group_code: string, group_name: string, school_code: string, advisor: string}> */
    private function groupCatalog(string $batchKey): array
    {
        if (array_key_exists($batchKey, $this->groupCatalogs)) {
            return $this->groupCatalogs[$batchKey];
        }

        $catalog = [];
        $prefix = 'db_'.$batchKey.'_';
        foreach ($this->tablesFor($batchKey, 'group') as $table) {
            $schoolCode = '';
            if (preg_match('/^'.preg_quote($prefix, '/').'([0-9]{2,20})_group$/', $table, $matches) === 1) {
                $schoolCode = $matches[1];
            }
            foreach ($this->database->connection()->table($table)->get() as $record) {
                $row = (array) $record;
                $catalog[] = [
                    'group_code' => trim((string) ($row['grp_code'] ?? '')),
                    'group_name' => trim((string) ($row['grp_name'] ?? '')),
                    'school_code' => $schoolCode,
                    'advisor' => trim((string) ($row['grp_advis'] ?? $row['advisor'] ?? $row['teacher_name'] ?? '')),
                ];
            }
        }

        return $this->groupCatalogs[$batchKey] = $catalog;
    }

    private function districtSchoolCode(int $districtId): ?string
    {
        if (array_key_exists($districtId, $this->districtSchoolCodes)) {
            return $this->districtSchoolCodes[$districtId];
        }

        $value = trim((string) ($this->database->connection()->table('districts')
            ->where('id', $districtId)
            ->value('school_code') ?? ''));

        return $this->districtSchoolCodes[$districtId] = $value === '' ? null : $value;
    }

    /** @return array<string, string> */
    private function studentGradeRoomMap(string $batchKey, string $level, string $studentCode): array
    {
        $cacheKey = 'studentGradeRoomMap|'.$batchKey.'|'.$level.'|'.$studentCode;
        if (isset($this->fieldMaps[$cacheKey])) {
            return $this->fieldMaps[$cacheKey];
        }

        $map = [];
        $table = $this->tablesFor($batchKey, 'grade', $level)[0] ?? null;
        if ($table !== null) {
            $rows = $this->database->connection()->table($table)
                ->where('_perf_std10', substr($studentCode, -10))
                ->orWhere('std_code', $studentCode)
                ->get();
            foreach ($rows as $record) {
                $row = (array) $record;
                $sub = trim((string) ($row['sub_code'] ?? ''));
                $rm = trim((string) ($row['roomno'] ?? $row['room_no'] ?? $row['room'] ?? ''));
                if ($sub !== '' && $rm !== '') {
                    $map[$sub] = $rm;
                }
            }
        }

        return $this->fieldMaps[$cacheKey] = $map;
    }

    private function time(string $value): string
    {
        $digits = preg_replace('/[^0-9]/', '', trim($value)) ?? '';
        if (strlen($digits) === 3) {
            $digits = '0'.$digits;
        }
        if (strlen($digits) >= 4) {
            return substr($digits, 0, 2).':'.substr($digits, 2, 2);
        }

        return trim($value) ?: '-';
    }

    private function date(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '-';
        }

        $day = null;
        $month = null;
        $year = null;

        if (preg_match('/^(\d{1,2})[\/\.-](\d{1,2})[\/\.-](\d{2,4})$/', $value, $matches) === 1) {
            $day = (int) $matches[1];
            $month = (int) $matches[2];
            $rawYear = (int) $matches[3];
            if ($rawYear < 100) {
                $year = 2500 + $rawYear;
            } elseif ($rawYear < 2400) {
                $year = $rawYear + 543;
            } else {
                $year = $rawYear;
            }
        } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) === 1) {
            $rawYear = (int) $matches[1];
            $month = (int) $matches[2];
            $day = (int) $matches[3];
            $year = $rawYear < 2400 ? $rawYear + 543 : $rawYear;
        }

        $thaiMonths = [
            1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.',
            5 => 'พ.ค.', 6 => 'มิ.ย.', 7 => 'ก.ค.', 8 => 'ส.ค.',
            9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.',
        ];

        if ($day !== null && isset($thaiMonths[$month]) && $year !== null) {
            return sprintf('%d %s %d', $day, $thaiMonths[$month], $year);
        }

        return $value;
    }
}
