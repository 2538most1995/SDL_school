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
        $fields = $fieldPath === null ? [] : $this->fieldMap($fieldPath);

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
                $fieldCode = trim((string) ($row['fld_code'] ?? ''));
                $rows[] = [
                    'subject_code' => $code,
                    'subject_name' => $subjects[$code]->name,
                    'term' => $term,
                    'exam_date' => $date,
                    'exam_date_display' => $this->date($date),
                    'start_time' => $this->time((string) ($row['exam_start'] ?? '')),
                    'end_time' => $this->time((string) ($row['exam_end'] ?? '')),
                    'location' => $fields[$fieldCode] ?? ($fieldCode ?: '-'),
                    'room' => $this->examRoom($student, $term, $code),
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

    private function examRoom(Student $student, string $term, string $subjectCode): string
    {
        if (! (bool) config('legacy.enabled')) {
            return '-';
        }
        $cacheKey = implode('|', [$student->districtId, $term, $subjectCode]);
        $rows = $this->examRooms[$cacheKey] ??= $this->database->connection((string) config('legacy.connection'))->table('exam_rooms')
            ->where('district_id', $student->districtId)->where('term', $term)->where('subject_code', $subjectCode)
            ->orderBy('id')->get()->all();
        foreach ($rows as $row) {
            $value = (string) ($row->assignment_type === 'student_range' ? $student->code : $student->groupCode);
            if ($value !== '' && strnatcasecmp($value, (string) $row->start_val) >= 0 && strnatcasecmp($value, (string) $row->end_val) <= 0) {
                return (string) $row->room_name;
            }
        }

        return '-';
    }

    /** @return list<array<string, mixed>> */
    private function records(string $path): array
    {
        return $this->dbfRecords[$path] ??= iterator_to_array((new VisualFoxProDbfReader($path))->records(), false);
    }

    /** @return array<string, string> */
    private function fieldMap(string $path): array
    {
        if (isset($this->fieldMaps[$path])) {
            return $this->fieldMaps[$path];
        }
        $fields = [];
        foreach ($this->records($path) as $row) {
            $code = trim((string) ($row['fld_code'] ?? ''));
            if ($code !== '') {
                $fields[$code] = trim((string) ($row['fld_name'] ?? $code));
            }
        }

        return $this->fieldMaps[$path] = $fields;
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
        if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{2,4}$/', $value) === 1) {
            return $value;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) === 1) {
            $year = (int) $matches[1];

            return sprintf('%s/%s/%d', $matches[3], $matches[2], $year < 2400 ? $year + 543 : $year);
        }

        return $value ?: '-';
    }

}
