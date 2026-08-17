<?php

namespace App\Services\Legacy;

use App\Domain\Students\Repositories\StudentRepository;
use App\Domain\Students\Support\AcademicTerm;
use Illuminate\Database\DatabaseManager;

final class ExamRoomScheduleSourceService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly StudentRepository $students,
    ) {}

    /**
     * Build the same room assignments used by LegacyExamScheduleService:
     * current grade roomno, then a room carried by SCHEDULE.DBF, then room 1.
     *
     * @return list<array{term: string, subject_code: string, assignment_type: string, start_val: string, end_val: string, room_name: string}>
     */
    public function rowsForDistrict(int $districtId, string $currentTerm): array
    {
        $batchKey = $this->activeBatchKey($districtId);
        if ($batchKey === null) {
            return [];
        }

        $studentCodes = $this->currentStudentCodes($districtId, $currentTerm);
        $rows = [];
        foreach ([1, 2, 3] as $level) {
            $studentsBySuffix = $studentCodes[$level] ?? [];
            if ($studentsBySuffix === []) {
                continue;
            }
            $scheduleTable = $this->tableFor($batchKey, $level, 'schedule');
            $gradeTable = $this->tableFor($batchKey, $level, 'grade');
            if ($scheduleTable === null || $gradeTable === null) {
                continue;
            }
            $scheduleRooms = $this->scheduleRooms($scheduleTable, $currentTerm);
            if ($scheduleRooms === []) {
                continue;
            }

            foreach ($this->termRows($gradeTable, $currentTerm) as $record) {
                $row = (array) $record;
                $subjectCode = trim((string) ($row['sub_code'] ?? $row['_perf_sub'] ?? ''));
                if ($subjectCode === '' || ! array_key_exists($subjectCode, $scheduleRooms)) {
                    continue;
                }
                $studentSuffix = trim((string) ($row['_perf_std10'] ?? ''));
                if ($studentSuffix === '') {
                    $rawStudentCode = trim((string) ($row['std_code'] ?? ''));
                    $studentSuffix = $rawStudentCode === '' ? '' : substr($rawStudentCode, -10);
                }
                if ($studentSuffix === '' || ! isset($studentsBySuffix[$studentSuffix])) {
                    continue;
                }

                $gradeRoom = trim((string) ($row['roomno'] ?? $row['room_no'] ?? $row['room'] ?? ''));
                $roomName = $this->roomName($gradeRoom)
                    ?? $scheduleRooms[$subjectCode]
                    ?? 'ห้อง 1';
                foreach ($studentsBySuffix[$studentSuffix] as $studentCode) {
                    $key = implode("\0", [$studentCode, $subjectCode]);
                    if ($gradeRoom === '' && isset($rows[$key])) {
                        continue;
                    }
                    $rows[$key] = [
                        'term' => $currentTerm,
                        'subject_code' => $subjectCode,
                        'assignment_type' => 'student_range',
                        'start_val' => $studentCode,
                        'end_val' => $studentCode,
                        'room_name' => $roomName,
                    ];
                }
            }
        }

        $rows = array_values($rows);
        usort($rows, static fn (array $left, array $right): int => [
            $left['subject_code'], $left['room_name'], $left['start_val'],
        ] <=> [
            $right['subject_code'], $right['room_name'], $right['start_val'],
        ]);

        return $rows;
    }

    /** @return array<int, array<string, list<string>>> */
    private function currentStudentCodes(int $districtId, string $currentTerm): array
    {
        $codes = [];
        foreach ($this->students->students([$districtId]) as $student) {
            if (! in_array($student->level, [1, 2, 3], true)
                || AcademicTerm::normalize($student->currentTerm) !== $currentTerm) {
                continue;
            }
            $code = trim($student->code);
            if ($code === '') {
                continue;
            }
            $suffix = substr($code, -10);
            $codes[$student->level][$suffix][$code] = true;
        }

        foreach ($codes as $level => $suffixes) {
            foreach ($suffixes as $suffix => $studentCodes) {
                $codes[$level][$suffix] = array_keys($studentCodes);
            }
        }

        return $codes;
    }

    /** @return array<string, string|null> */
    private function scheduleRooms(string $table, string $currentTerm): array
    {
        $rooms = [];
        foreach ($this->termRows($table, $currentTerm) as $record) {
            $row = (array) $record;
            $subjectCode = trim((string) ($row['sub_code'] ?? $row['_perf_sub'] ?? ''));
            if ($subjectCode === '') {
                continue;
            }
            $room = trim((string) (
                $row['room'] ?? $row['room_no'] ?? $row['roomname'] ?? $row['room_name']
                ?? $row['exam_room'] ?? $row['rm_no'] ?? $row['room_code'] ?? $row['building'] ?? ''
            ));
            $rooms[$subjectCode] = $this->roomName($room);
        }

        return $rooms;
    }

    /** @return iterable<int, object> */
    private function termRows(string $table, string $currentTerm): iterable
    {
        $schema = $this->database->connection()->getSchemaBuilder();
        $query = $this->database->connection()->table($table);
        if ($schema->hasColumn($table, '_perf_semestry')) {
            $query->whereIn('_perf_semestry', AcademicTerm::variants($currentTerm));
        }

        foreach ($query->get() as $record) {
            $row = (array) $record;
            $rawTerm = trim((string) ($row['semestry'] ?? $row['_perf_semestry'] ?? ''));
            if ($rawTerm !== '' && AcademicTerm::normalize($rawTerm) !== $currentTerm) {
                continue;
            }
            yield $record;
        }
    }

    private function roomName(string $room): ?string
    {
        $room = trim($room);
        if ($room === '') {
            return null;
        }
        $clean = ltrim($room, '0') ?: $room;

        return preg_match('/^\d+$/', $clean) === 1 ? 'ห้อง '.$clean : $clean;
    }

    private function activeBatchKey(int $districtId): ?string
    {
        $connection = $this->database->connection();
        $mysql = $connection->getDriverName() === 'mysql';
        $key = (string) ($connection->table('import_batches as batch')
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

        return preg_match('/^import_\d{10}_[A-Za-z0-9]+$/', $key) === 1 ? $key : null;
    }

    private function tableFor(string $batchKey, int $level, string $type): ?string
    {
        if (! in_array($level, [1, 2, 3], true) || ! in_array($type, ['grade', 'schedule'], true)) {
            return null;
        }
        $expected = 'db_'.$batchKey.'_'.$level.'_'.$type;
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $expected) !== 1) {
            return null;
        }

        return $this->database->connection()->getSchemaBuilder()->hasTable($expected) ? $expected : null;
    }
}
