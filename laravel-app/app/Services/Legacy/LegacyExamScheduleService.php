<?php

namespace App\Services\Legacy;

use App\Domain\Students\Models\Student;
use App\Domain\Students\Repositories\StudentRepository;
use App\Domain\Students\Support\AcademicTerm;
use App\Support\VisualFoxProDbfReader;
use Illuminate\Database\DatabaseManager;

final class LegacyExamScheduleService
{
    /** @var array<int, string|null> */
    private array $batchKeys = [];

    /** @var array<string, string|null> */
    private array $dbfPaths = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $dbfRecords = [];

    /** @var array<string, array<string, string>> */
    private array $fieldMaps = [];

    /** @var array<string, list<object>> */
    private array $examRooms = [];

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly StudentRepository $students,
    ) {}

    /** @return array<string, mixed> */
    public function forStudent(Student $student): array
    {
        $registered = array_values(array_filter(
            $this->students->subjectsFor($student),
            static fn ($subject): bool => $subject->term === $student->currentTerm && $subject->grade === null,
        ));
        $subjects = [];
        foreach ($registered as $subject) {
            $subjects[trim($subject->code)] = $subject;
        }

        $batchKey = $this->activeBatchKey($student->districtId);
        $schedulePath = $batchKey === null ? null : $this->findDbf($batchKey, 'schedule', (string) $student->level);
        $fieldPath = $batchKey === null ? null : $this->findDbf($batchKey, 'field');
        $fields = $this->fieldMap($batchKey, $fieldPath);
        $groupFields = $this->groupFieldMap($batchKey);
        $studentGroupFld = $groupFields[trim((string) $student->groupCode)] ?? '';

        $rows = [];
        if ($schedulePath !== null) {
            foreach ($this->records($schedulePath) as $row) {
                $code = trim((string) ($row['sub_code'] ?? ''));
                $rawTerm = trim((string) ($row['semestry'] ?? ''));
                $term = AcademicTerm::normalize($rawTerm) ?? $rawTerm;
                $date = trim((string) ($row['exam_day'] ?? ''));
                if (! isset($subjects[$code]) || $term !== $student->currentTerm || $date === '') {
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
                $location = $fields[$fieldCode] ?? ($directLoc !== '' ? $directLoc : '');
                $location = $this->normalizeSchoolName($location);
                if ($location === '' || $location === '-' || $location === $student->groupName || str_starts_with($location, 'ศกร.') || str_starts_with($location, 'กลุ่ม')) {
                    $district = (string) ($student->districtName ?? '');
                    if (str_contains($district, 'เสนา')) {
                        $location = 'โรงเรียนเสนา "เสนาประสิทธิ์"';
                    } elseif (str_contains($district, 'ไพศาลี')) {
                        $location = 'โรงเรียนไพศาลีพิทยา';
                    } elseif ($district !== '') {
                        $location = 'โรงเรียนประจำ'.preg_replace('/^(อำเภอ|กศน\.อำเภอ)\s*/u', '', $district);
                    } else {
                        $location = 'โรงเรียนเสนา "เสนาประสิทธิ์"';
                    }
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
            ],
            'term' => $student->currentTerm,
            'rows' => $rows,
            'source_ready' => $schedulePath !== null,
        ];
    }

    private function activeBatchKey(int $districtId): ?string
    {
        if (array_key_exists($districtId, $this->batchKeys)) {
            return $this->batchKeys[$districtId];
        }
        if (! (bool) config('legacy.student_enabled')) {
            return $this->batchKeys[$districtId] = null;
        }
        $connection = $this->database->connection((string) config('legacy.connection'));
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

    private function findDbf(string $batchKey, string $type, ?string $level = null): ?string
    {
        $cacheKey = implode('|', [$batchKey, $type, $level ?? '*']);
        if (array_key_exists($cacheKey, $this->dbfPaths)) {
            return $this->dbfPaths[$cacheKey];
        }
        $root = rtrim((string) config('legacy.extract_root'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$batchKey;
        if (! is_dir($root)) {
            return $this->dbfPaths[$cacheKey] = null;
        }
        $matches = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file->isFile() || strtolower($file->getExtension()) !== 'dbf' || strtolower($file->getBasename('.'.$file->getExtension())) !== $type) {
                continue;
            }
            if ($level !== null && basename($file->getPath()) !== $level) {
                continue;
            }
            $matches[] = $file->getPathname();
        }
        sort($matches, SORT_STRING);

        return $this->dbfPaths[$cacheKey] = $matches[0] ?? null;
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
        if (! (bool) config('legacy.enabled')) {
            return '-';
        }
        $cacheKey = implode('|', [$student->districtId, $term, $subjectCode]);
        if (! isset($this->examRooms[$cacheKey])) {
            $rows = $this->database->connection((string) config('legacy.connection'))->table('exam_rooms')
                ->where('district_id', $student->districtId)
                ->where(function ($q) use ($term): void {
                    $q->where('term', $term)
                        ->orWhere('term', 'all')
                        ->orWhere('term', '*');
                })
                ->where(function ($q) use ($subjectCode): void {
                    $q->where('subject_code', $subjectCode)
                        ->orWhere('subject_code', 'all')
                        ->orWhere('subject_code', '*');
                })
                ->orderBy('id')->get()->all();

            if ($rows === []) {
                $rows = $this->database->connection((string) config('legacy.connection'))->table('exam_rooms')
                    ->where('district_id', $student->districtId)
                    ->where(function ($q) use ($term): void {
                        $q->where('term', $term)
                            ->orWhere('term', 'all')
                            ->orWhere('term', '*');
                    })
                    ->orderBy('id')->get()->all();
            }

            $this->examRooms[$cacheKey] = $rows;
        }

        foreach ($this->examRooms[$cacheKey] as $row) {
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
        if ($this->examRooms[$cacheKey] !== []) {
            $firstRoom = trim((string) ($this->examRooms[$cacheKey][0]->room_name ?? ''));
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
            $numVal = (float) $val;
            $numStart = (float) $start;
            $numEnd = (float) $end;

            return $numVal >= $numStart && $numVal <= $numEnd;
        }

        if (strcasecmp($val, $start) === 0 || strcasecmp($val, $end) === 0) {
            return true;
        }

        if (str_contains($val, $start) || str_contains($val, $end)) {
            return true;
        }

        return strnatcasecmp($val, $start) >= 0 && strnatcasecmp($val, $end) <= 0;
    }

    /** @return list<array<string, mixed>> */
    private function records(string $path): array
    {
        return $this->dbfRecords[$path] ??= iterator_to_array((new VisualFoxProDbfReader($path))->records(), false);
    }

    private function normalizeSchoolName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || $name === '-') {
            return '';
        }
        if (str_contains($name, 'เสนาบดี')) {
            return 'โรงเรียนเสนา "เสนาประสิทธิ์"';
        }

        return $name;
    }

    /** @return array<string, string> */
    private function fieldMap(?string $batchKey, ?string $path): array
    {
        $cacheKey = 'fieldMap|'.($batchKey ?? '').'|'.($path ?? '');
        if (isset($this->fieldMaps[$cacheKey])) {
            return $this->fieldMaps[$cacheKey];
        }
        $fields = [];
        if ($path !== null && is_file($path)) {
            foreach ($this->records($path) as $row) {
                $code = trim((string) (
                    $row['fld_code'] ?? $row['fldcode'] ?? $row['field_code'] ?? $row['fld_id'] ?? $row['id'] ?? ''
                ));
                $name = $this->normalizeSchoolName((string) (
                    $row['fld_name'] ?? $row['fldname'] ?? $row['field_name'] ?? $row['loc_name'] ?? $row['place_name'] ?? $row['school_name'] ?? $row['name'] ?? $row['title'] ?? ''
                ));
                if ($code !== '') {
                    $fields[$code] = $name !== '' ? $name : $code;
                }
            }
        }
        if ($fields === [] && $batchKey !== null && (bool) config('legacy.enabled')) {
            try {
                $connection = $this->database->connection((string) config('legacy.connection'));
                $tableNames = ["db_import_{$batchKey}_field", "db_import_{$batchKey}_0_field"];
                foreach ($tableNames as $tableName) {
                    if ($connection->getSchemaBuilder()->hasTable($tableName)) {
                        foreach ($connection->table($tableName)->get() as $r) {
                            $row = (array) $r;
                            $code = trim((string) ($row['fld_code'] ?? $row['fldcode'] ?? $row['field_code'] ?? $row['fld_id'] ?? $row['id'] ?? ''));
                            $name = $this->normalizeSchoolName((string) ($row['fld_name'] ?? $row['fldname'] ?? $row['field_name'] ?? $row['loc_name'] ?? $row['place_name'] ?? $row['school_name'] ?? $row['name'] ?? $row['title'] ?? ''));
                            if ($code !== '') {
                                $fields[$code] = $name !== '' ? $name : $code;
                            }
                        }
                        if ($fields !== []) {
                            break;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Ignore DB query errors
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
        $groupPath = $this->findDbf($batchKey, 'group');
        if ($groupPath !== null && is_file($groupPath)) {
            foreach ($this->records($groupPath) as $row) {
                $grpCode = trim((string) ($row['grp_code'] ?? ''));
                $fldCode = trim((string) ($row['grp_field'] ?? $row['fld_code'] ?? $row['field'] ?? ''));
                if ($grpCode !== '' && $fldCode !== '') {
                    $map[$grpCode] = $fldCode;
                }
            }
        }
        if ($map === [] && (bool) config('legacy.enabled')) {
            try {
                $connection = $this->database->connection((string) config('legacy.connection'));
                $tableNames = ["db_import_{$batchKey}_group", "db_import_{$batchKey}_0_group"];
                foreach ($tableNames as $tableName) {
                    if ($connection->getSchemaBuilder()->hasTable($tableName)) {
                        foreach ($connection->table($tableName)->get() as $r) {
                            $row = (array) $r;
                            $grpCode = trim((string) ($row['grp_code'] ?? ''));
                            $fldCode = trim((string) ($row['grp_field'] ?? $row['fld_code'] ?? $row['field'] ?? ''));
                            if ($grpCode !== '' && $fldCode !== '') {
                                $map[$grpCode] = $fldCode;
                            }
                        }
                        if ($map !== []) {
                            break;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Ignore DB query errors
            }
        }

        return $this->fieldMaps[$cacheKey] = $map;
    }

    /** @return array<string, string> */
    private function studentGradeRoomMap(string $batchKey, string $level, string $studentCode): array
    {
        $cacheKey = 'studentGradeRoomMap|'.$batchKey.'|'.$level.'|'.$studentCode;
        if (isset($this->fieldMaps[$cacheKey])) {
            return $this->fieldMaps[$cacheKey];
        }

        $map = [];

        $gradePath = $this->findDbf($batchKey, 'grade', $level);
        if ($gradePath !== null && is_file($gradePath)) {
            foreach ($this->records($gradePath) as $row) {
                $std = trim((string) ($row['std_code'] ?? ''));
                if ($std !== $studentCode && ! str_ends_with($std, $studentCode) && ! str_ends_with($studentCode, $std)) {
                    continue;
                }
                $sub = trim((string) ($row['sub_code'] ?? ''));
                $rm = trim((string) ($row['roomno'] ?? $row['room_no'] ?? $row['room'] ?? ''));
                if ($sub !== '' && $rm !== '') {
                    $map[$sub] = $rm;
                }
            }
        }

        if ($map === [] && (bool) config('legacy.enabled')) {
            try {
                $connection = $this->database->connection((string) config('legacy.connection'));
                $tableNames = ["db_import_{$batchKey}_{$level}_grade", "db_import_{$batchKey}_grade"];
                foreach ($tableNames as $tableName) {
                    if ($connection->getSchemaBuilder()->hasTable($tableName)) {
                        $rows = $connection->table($tableName)
                            ->where('_perf_std10', substr($studentCode, -10))
                            ->orWhere('std_code', $studentCode)
                            ->get();
                        foreach ($rows as $r) {
                            $row = (array) $r;
                            $sub = trim((string) ($row['sub_code'] ?? ''));
                            $rm = trim((string) ($row['roomno'] ?? $row['room_no'] ?? $row['room'] ?? ''));
                            if ($sub !== '' && $rm !== '') {
                                $map[$sub] = $rm;
                            }
                        }
                        if ($map !== []) {
                            break;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Ignore DB query errors
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
