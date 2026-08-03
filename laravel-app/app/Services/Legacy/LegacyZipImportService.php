<?php

namespace App\Services\Legacy;

use App\Support\VisualFoxProDbfReader;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use ZipArchive;

final class LegacyZipImportService
{
    private const MAX_FILES = 2_000;

    private const MAX_ENTRY_BYTES = 268_435_456;

    private const MAX_TOTAL_BYTES = 2_147_483_648;

    /** @var list<string> */
    private const REQUIRED_DATA_TYPES = ['student', 'grade', 'subject', 'activity', 'virtue', 'group'];

    /** DBF data that is safe to import when present, but is not required for a valid student batch. */
    private const SUPPORTED_DATA_TYPES = [...self::REQUIRED_DATA_TYPES, 'schedule', 'field'];

    /** @var list<string> */
    private array $createdTables = [];

    public function __construct(private readonly DatabaseManager $database) {}

    /** @return array<string, mixed> */
    public function import(UploadedFile $archive, string $academicTerm, int $districtId, int $userId, ?string $ipAddress, ?callable $progress = null): array
    {
        if (! (bool) config('legacy.write_enabled')) {
            throw new RuntimeException('ระบบเขียนข้อมูลยังไม่เปิดใช้งาน');
        }
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('เซิร์ฟเวอร์ยังไม่เปิดส่วนขยาย ZIP');
        }

        @set_time_limit(0);
        $batchKey = 'import_'.time().'_'.bin2hex(random_bytes(4));
        $zipRoot = $this->absoluteDirectory((string) config('legacy.zip_root'));
        $extractRoot = $this->absoluteDirectory((string) config('legacy.extract_root'));
        $zipPath = $zipRoot.DIRECTORY_SEPARATOR.$batchKey.'.zip';
        $extractPath = $extractRoot.DIRECTORY_SEPARATOR.$batchKey;
        $this->createdTables = [];
        $lockName = null;

        try {
            $this->reportProgress($progress, 'กำลังคัดลอกไฟล์ ZIP เข้าพื้นที่ปลอดภัย', 5);
            $this->copyUpload($archive, $zipPath);
            $this->reportProgress($progress, 'กำลังตรวจสอบและแตกไฟล์ ZIP', 10);
            [$zipFiles, $uncompressedBytes] = $this->extractSafely($zipPath, $extractPath);
            $candidates = $this->dbfCandidates($extractPath);
            $this->assertRequiredDataset($candidates);
            $this->assertStudentMemoCompanions($candidates);
            $this->reportProgress($progress, 'ตรวจสอบชุดข้อมูลผ่านแล้ว กำลังเตรียมฐานข้อมูล', 20);
            $lockName = $this->acquireDistrictImportLock($districtId);

            $tableReport = [];
            $recordCounts = array_map(
                static fn (array $candidate): int => (new VisualFoxProDbfReader($candidate['path']))->recordCount(),
                $candidates,
            );
            $totalRecords = max(1, array_sum($recordCounts));
            $completedRecords = 0;
            foreach ($candidates as $index => $candidate) {
                $level = in_array($candidate['parent'], ['1', '2', '3'], true) ? 'ระดับ '.$candidate['parent'] : '';
                $tableLabel = trim("{$candidate['type']} {$level}");
                $this->reportProgress(
                    $progress,
                    "กำลังนำเข้าตาราง {$tableLabel}",
                    20 + (int) floor(($completedRecords / $totalRecords) * 70),
                    ['processed_rows' => $completedRecords, 'total_rows' => $totalRecords, 'current_table' => $tableLabel],
                );
                $tableReport[] = $this->importDbf(
                    $batchKey,
                    $candidate['parent'],
                    $candidate['type'],
                    $candidate['path'],
                    function (int $tableProcessed, int $tableTotal) use ($progress, $completedRecords, $totalRecords, $tableLabel): void {
                        $processed = min($totalRecords, $completedRecords + min($tableProcessed, $tableTotal));
                        $this->reportProgress(
                            $progress,
                            "กำลังนำเข้าตาราง {$tableLabel} ".number_format($tableProcessed).'/'.number_format($tableTotal).' แถว',
                            20 + (int) floor(($processed / $totalRecords) * 70),
                            ['processed_rows' => $processed, 'total_rows' => $totalRecords, 'current_table' => $tableLabel],
                        );
                    },
                );
                $completedRecords += $recordCounts[$index] ?? 0;
                $this->reportProgress(
                    $progress,
                    "นำเข้าตาราง {$tableLabel} แล้ว",
                    20 + (int) floor((min($completedRecords, $totalRecords) / $totalRecords) * 70),
                    ['processed_rows' => min($completedRecords, $totalRecords), 'total_rows' => $totalRecords, 'current_table' => $tableLabel],
                );
            }

            $rowCount = array_sum(array_column($tableReport, 'row_count'));
            $this->reportProgress($progress, 'กำลังลงทะเบียนและเปิดใช้ชุดข้อมูลใหม่', 92);
            $districtName = (string) $this->read()->table('districts')->where('id', $districtId)->value('name');
            $historyId = $this->registerBatch(
                $archive->getClientOriginalName(),
                basename($zipPath),
                $batchKey,
                (int) ceil((int) $archive->getSize() / 1024),
                $zipFiles,
                $districtId,
            );
            $replacement = ['removed_count' => 0, 'removed_batch_keys' => [], 'warnings' => []];
            try {
                $this->reportProgress($progress, 'กำลังลบชุดข้อมูลเดิมของอำเภอ', 96);
                $replacement = $this->replaceExistingDistrictBatches($districtId, $batchKey);
            } catch (Throwable $cleanupException) {
                report($cleanupException);
                $replacement['warnings'][] = 'นำเข้าชุดใหม่สำเร็จ แต่ลบชุดเก่าบางส่วนไม่สำเร็จ กรุณาติดต่อผู้ดูแลระบบ';
            }
            $summary = [
                'batch_key' => $batchKey,
                'academic_term' => $academicTerm,
                'source_filename' => $archive->getClientOriginalName(),
                'table_count' => count($tableReport),
                'row_count' => $rowCount,
                'warning_count' => count($replacement['warnings']),
                'zip_file_count' => $zipFiles,
                'uncompressed_bytes' => $uncompressedBytes,
                'tables' => $tableReport,
                'replacement' => $replacement,
            ];
            try {
                $this->audit($userId, $districtId, $historyId, $ipAddress, $summary);
            } catch (Throwable $auditException) {
                report($auditException);
            }

            $this->reportProgress($progress, 'นำเข้าและเปิดใช้ชุดข้อมูลใหม่เรียบร้อยแล้ว', 100);

            return [
                'id' => $historyId,
                'batch_key' => $batchKey,
                'district_id' => $districtId,
                'district_name' => $districtName,
                'academic_term' => $academicTerm,
                'source_filename' => $archive->getClientOriginalName(),
                'status' => 'active',
                'row_count' => $rowCount,
                'table_count' => count($tableReport),
                'warning_count' => count($replacement['warnings']),
                'replaced_batch_count' => $replacement['removed_count'],
                'created_at' => now()->toIso8601String(),
                'activated_at' => now()->toIso8601String(),
                'is_active' => true,
                'validation_summary' => $summary,
            ];
        } catch (Throwable $exception) {
            $this->dropCreatedTables();
            $this->removeTree($extractPath);
            if (is_file($zipPath)) {
                @unlink($zipPath);
            }
            report($exception);
            throw new RuntimeException('นำเข้าข้อมูลไม่สำเร็จ: '.$exception->getMessage(), 0, $exception);
        } finally {
            $this->releaseDistrictImportLock($lockName);
        }
    }

    private function copyUpload(UploadedFile $archive, string $destination): void
    {
        $source = $archive->getRealPath();
        if ($source === false || ! is_file($source)) {
            throw new RuntimeException('ไม่พบไฟล์อัปโหลดชั่วคราว');
        }
        $input = fopen($source, 'rb');
        $output = fopen($destination, 'xb');
        if ($input === false || $output === false) {
            throw new RuntimeException('ไม่สามารถบันทึกไฟล์ ZIP ในพื้นที่ staging');
        }
        try {
            if (stream_copy_to_stream($input, $output) === false) {
                throw new RuntimeException('บันทึกไฟล์ ZIP ไม่สำเร็จ');
            }
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    /** @return array{int, int} */
    private function extractSafely(string $zipPath, string $extractPath): array
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('เปิดไฟล์ ZIP ไม่สำเร็จ');
        }
        if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_FILES) {
            $zip->close();
            throw new RuntimeException('จำนวนไฟล์ใน ZIP ไม่อยู่ในช่วงที่อนุญาต');
        }
        if (! mkdir($extractPath, 0750, true) && ! is_dir($extractPath)) {
            $zip->close();
            throw new RuntimeException('ไม่สามารถสร้างพื้นที่แตกไฟล์ได้');
        }

        $numberOfFiles = $zip->numFiles;
        $total = 0;
        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
                if (! is_array($stat)) {
                    throw new RuntimeException('อ่านรายการไฟล์ใน ZIP ไม่สำเร็จ');
                }
                $name = str_replace('\\', '/', (string) $stat['name']);
                $this->assertSafeEntry($zip, $index, $name, $stat);
                $size = (int) ($stat['size'] ?? 0);
                $total += $size;
                if ($total > self::MAX_TOTAL_BYTES) {
                    throw new RuntimeException('ขนาดไฟล์รวมหลังแตก ZIP เกิน 2 GB');
                }
                if (str_ends_with($name, '/')) {
                    continue;
                }
                $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (! in_array($extension, ['dbf', 'fpt'], true)) {
                    continue;
                }

                $target = $extractPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $name);
                $directory = dirname($target);
                if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
                    throw new RuntimeException('ไม่สามารถสร้างโฟลเดอร์สำหรับไฟล์ DBF');
                }
                $input = $zip->getStream((string) $stat['name']);
                $output = fopen($target, 'xb');
                if ($input === false || $output === false) {
                    throw new RuntimeException('ไม่สามารถแตกไฟล์ข้อมูลจาก ZIP');
                }
                try {
                    $written = stream_copy_to_stream($input, $output, self::MAX_ENTRY_BYTES + 1);
                    if ($written === false || $written !== $size) {
                        throw new RuntimeException('ขนาดไฟล์ที่แตกไม่ตรงกับรายการ ZIP');
                    }
                } finally {
                    fclose($input);
                    fclose($output);
                }
            }
        } finally {
            $zip->close();
        }

        return [$numberOfFiles, $total];
    }

    /** @param array<string, mixed> $stat */
    private function assertSafeEntry(ZipArchive $zip, int $index, string $name, array $stat): void
    {
        if ($name === '' || str_contains($name, "\0") || str_starts_with($name, '/')
            || preg_match('/^[A-Za-z]:\//', $name) === 1
            || in_array('..', explode('/', $name), true)) {
            throw new RuntimeException('พบ path ไม่ปลอดภัยใน ZIP');
        }
        $size = (int) ($stat['size'] ?? 0);
        $compressed = max(1, (int) ($stat['comp_size'] ?? 0));
        if ($size > self::MAX_ENTRY_BYTES || ($size > 1_048_576 && $size / $compressed > 200)) {
            throw new RuntimeException('พบไฟล์ขนาดใหญ่หรืออัตราบีบอัดผิดปกติใน ZIP');
        }

        $operations = 0;
        $attributes = 0;
        if ($zip->getExternalAttributesIndex($index, $operations, $attributes)
            && (($attributes >> 16) & 0xF000) === 0xA000) {
            throw new RuntimeException('ไม่อนุญาต symbolic link ใน ZIP');
        }
    }

    /** @return list<array{parent: string, type: string, path: string, mtime: int}> */
    private function dbfCandidates(string $extractPath): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extractPath, \FilesystemIterator::SKIP_DOTS),
        );
        $selected = [];
        foreach ($iterator as $file) {
            if (! $file->isFile() || strtolower($file->getExtension()) !== 'dbf') {
                continue;
            }
            $parent = preg_replace('/[^A-Za-z0-9]/', '', basename($file->getPath())) ?? '';
            $type = preg_replace('/[^a-z0-9_]/', '', strtolower($file->getBasename('.'.$file->getExtension()))) ?? '';
            if ($parent === '' || $type === '' || (! in_array($parent, ['1', '2', '3'], true) && ! in_array($type, ['group', 'field'], true))) {
                continue;
            }
            if (! in_array($type, self::SUPPORTED_DATA_TYPES, true)) {
                continue;
            }
            $key = strtolower($parent.'_'.$type);
            $candidate = ['parent' => $parent, 'type' => $type, 'path' => $file->getPathname(), 'mtime' => $file->getMTime()];
            if (! isset($selected[$key]) || $candidate['mtime'] > $selected[$key]['mtime']) {
                $selected[$key] = $candidate;
            }
        }
        ksort($selected);

        return array_values($selected);
    }

    /** @param list<array{parent: string, type: string, path: string, mtime: int}> $candidates */
    private function assertRequiredDataset(array $candidates): void
    {
        if ($candidates === []) {
            throw new RuntimeException('ไม่พบไฟล์ DBF ที่รองรับใน ZIP');
        }
        $byLevel = [];
        foreach ($candidates as $candidate) {
            if (in_array($candidate['parent'], ['1', '2', '3'], true)) {
                $byLevel[$candidate['parent']][] = $candidate['type'];
            }
        }
        $complete = collect($byLevel)->contains(fn (array $types): bool => count(array_intersect(['student', 'grade', 'subject'], $types)) === 3);
        if (! $complete) {
            throw new RuntimeException('ข้อมูลไม่ครบ: ต้องมี student.dbf, grade.dbf และ subject.dbf อย่างน้อยหนึ่งระดับ');
        }
    }

    /**
     * Visual FoxPro stores long student values (phone, address and email) in a
     * sibling FPT file. Activating a DBF without that companion would silently
     * publish a batch whose contact data can never be decoded.
     *
     * @param list<array{parent: string, type: string, path: string, mtime: int}> $candidates
     */
    private function assertStudentMemoCompanions(array $candidates): void
    {
        foreach ($candidates as $candidate) {
            if ($candidate['type'] !== 'student') {
                continue;
            }

            $reader = new VisualFoxProDbfReader($candidate['path']);
            $hasMemoField = false;
            foreach ($reader->fields() as $field) {
                if (in_array(strtoupper((string) ($field['type'] ?? '')), ['M', 'G', 'P'], true)) {
                    $hasMemoField = true;

                    break;
                }
            }
            if (! $hasMemoField) {
                continue;
            }

            $directory = dirname($candidate['path']);
            $baseName = pathinfo($candidate['path'], PATHINFO_FILENAME);
            $hasCompanion = false;
            foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $file) {
                if ($file->isFile()
                    && strcasecmp($file->getExtension(), 'fpt') === 0
                    && strcasecmp($file->getBasename('.'.$file->getExtension()), $baseName) === 0) {
                    $hasCompanion = true;

                    break;
                }
            }

            if (! $hasCompanion) {
                $level = in_array($candidate['parent'], ['1', '2', '3'], true)
                    ? $candidate['parent']
                    : 'ไม่ระบุ';
                throw new RuntimeException("ข้อมูลไม่ครบ: STUDENT.DBF ระดับ {$level} ต้องมีไฟล์ STUDENT.FPT คู่กัน");
            }
        }
    }

    /** @return array{physical_table: string, education_level: int, data_type: string, row_count: int, schema_hash: string, import_seconds: float} */
    private function importDbf(string $batchKey, string $parent, string $type, string $path, ?callable $progress = null): array
    {
        $startedAt = hrtime(true);
        $table = $this->tableName($batchKey, $parent, $type);
        $reader = new VisualFoxProDbfReader($path);
        $fields = $reader->fields();
        $columns = array_column($fields, 'name');
        $performance = $this->performanceColumns($type);
        $allColumns = [...$columns, ...array_keys($performance)];

        $definitions = array_map(fn (array $field): string => $this->dbfColumnDefinition($field), $fields);
        foreach (array_keys($performance) as $column) {
            $definitions[] = $this->quoteIdentifier($column).' VARCHAR(100) NULL';
        }
        $sql = $this->write()->getDriverName() === 'mysql'
            ? 'CREATE TABLE '.$this->quoteIdentifier($table)
                .' (`_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '.implode(', ', $definitions)
                .', `_imported_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            : 'CREATE TABLE '.$this->quoteIdentifier($table)
                .' (`_id` INTEGER PRIMARY KEY AUTOINCREMENT, '.implode(', ', $definitions)
                .', `_imported_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)';
        $this->write()->statement($sql);
        $this->createdTables[] = $table;

        $quotedColumns = implode(', ', array_map($this->quoteIdentifier(...), $allColumns));
        $rowPlaceholders = '('.implode(', ', array_fill(0, count($allColumns), '?')).')';
        $insertPrefix = 'INSERT INTO '.$this->quoteIdentifier($table)." ({$quotedColumns}) VALUES ";
        // Keep safely below MySQL's 65,535 placeholder ceiling and SQLite's
        // lower test limit while replacing hundreds of single-row round trips.
        $placeholderLimit = $this->write()->getDriverName() === 'mysql' ? 50_000 : 900;
        $batchSize = max(1, min(750, intdiv($placeholderLimit, max(1, count($allColumns)))));
        $recordCount = max(1, $reader->recordCount());
        $reportTableProgress = static function (int $seen, float $start, float $span) use ($progress, $recordCount): void {
            if ($progress === null) {
                return;
            }
            $progress((int) floor($recordCount * min(1, $start + ((min($seen, $recordCount) / $recordCount) * $span))), $recordCount);
        };
        $selectedStudentRows = $type === 'student' && in_array('cardid', $columns, true) && in_array('id', $columns, true)
            ? $this->selectedStudentRowIndexes($reader, fn (int $seen): mixed => $reportTableProgress($seen, 0, 0.30))
            : null;
        $insertStart = $selectedStudentRows === null ? 0.0 : 0.30;
        $insertSpan = $selectedStudentRows === null ? 0.92 : 0.62;
        $rows = 0;
        $recordsSeen = 0;
        $pendingRows = [];
        $flush = function () use (&$pendingRows, $insertPrefix, $rowPlaceholders): void {
            if ($pendingRows === []) {
                return;
            }
            $parameters = [];
            foreach ($pendingRows as $values) {
                array_push($parameters, ...$values);
            }
            $statement = $this->write()->getPdo()->prepare(
                $insertPrefix.implode(', ', array_fill(0, count($pendingRows), $rowPlaceholders)),
            );
            $statement->execute($parameters);
            $pendingRows = [];
        };
        $this->write()->beginTransaction();
        try {
            foreach ($reader->records() as $recordIndex => $record) {
                $recordsSeen = $recordIndex + 1;
                $cardId = trim((string) ($record['cardid'] ?? ''));
                if ($selectedStudentRows !== null && $cardId !== '' && ! isset($selectedStudentRows[$recordIndex])) {
                    if ($recordsSeen % 1_000 === 0) {
                        $reportTableProgress($recordsSeen, $insertStart, $insertSpan);
                    }
                    continue;
                }
                $values = [];
                foreach ($columns as $column) {
                    $values[] = $record[$column] ?? null;
                }
                foreach ($performance as $source) {
                    $value = trim((string) ($record[$source['field']] ?? ''));
                    $values[] = $source['last'] === null ? ($value ?: null) : ($value === '' ? null : substr($value, -$source['last']));
                }
                $pendingRows[] = $values;
                $rows++;
                if (count($pendingRows) >= $batchSize) {
                    $flush();
                    $reportTableProgress($recordsSeen, $insertStart, $insertSpan);
                }
            }
            $flush();
            $reportTableProgress($recordCount, $insertStart, $insertSpan);
            $this->write()->commit();
        } catch (Throwable $exception) {
            $this->write()->rollBack();
            throw $exception;
        }
        $this->createPerformanceIndexes($table, $this->performanceIndexColumns($type));
        if ($progress !== null) {
            $progress($recordCount, $recordCount);
        }

        return [
            'physical_table' => $table,
            'education_level' => in_array($parent, ['1', '2', '3'], true) ? (int) $parent : 0,
            'data_type' => $type,
            'row_count' => $rows,
            'schema_hash' => hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR)),
            'import_seconds' => round((hrtime(true) - $startedAt) / 1_000_000_000, 3),
        ];
    }

    /** @param array<string, mixed> $metrics */
    private function reportProgress(?callable $progress, string $message, int $percentage, array $metrics = []): void
    {
        if ($progress !== null) {
            $progress($message, max(0, min(100, $percentage)), $metrics);
        }
    }

    /** @param array{name: string, type: string, length: int, decimal: int} $field */
    private function dbfColumnDefinition(array $field): string
    {
        // DBF fields have a fixed byte width. VARCHAR preserves the source text
        // exactly while avoiding the off-page overhead incurred by making every
        // small code, date and grade a TEXT column.
        $length = max(1, min(16_000, (int) $field['length']));
        if (in_array(strtoupper((string) $field['type']), ['I', 'T', 'B', 'G', 'P', 'Y'], true)) {
            $length = min(16_000, $length * 2);
        }

        return $this->quoteIdentifier((string) $field['name'])." VARCHAR({$length}) NULL";
    }

    /**
     * Reproduce the legacy duplicate rule before writing to MySQL. A second DBF
     * pass is much cheaper than inserting duplicate students and running an
     * unindexed self-join DELETE afterwards.
     *
     * @return array<int, true>
     */
    private function selectedStudentRowIndexes(VisualFoxProDbfReader $reader, ?callable $progress = null): array
    {
        $latestByCard = [];
        foreach ($reader->records() as $recordIndex => $record) {
            if ($progress !== null && (($recordIndex + 1) % 1_000 === 0)) {
                $progress($recordIndex + 1);
            }
            $cardId = trim((string) ($record['cardid'] ?? ''));
            if ($cardId === '') {
                continue;
            }
            $id = trim((string) ($record['id'] ?? ''));
            $id10 = $id === '' ? '' : substr($id, -10);
            $current = $latestByCard[$cardId] ?? null;
            if ($current === null || $id10 > $current['id10'] || ($id10 === $current['id10'] && $recordIndex > $current['index'])) {
                $latestByCard[$cardId] = ['id10' => $id10, 'index' => $recordIndex];
            }
        }

        $selected = [];
        foreach ($latestByCard as $record) {
            $selected[$record['index']] = true;
        }
        if ($progress !== null) {
            $progress($reader->recordCount());
        }

        return $selected;
    }

    /** @return array<string, array{field: string, last: int|null}> */
    private function performanceColumns(string $type): array
    {
        return match ($type) {
            'student' => [
                '_perf_id10' => ['field' => 'id', 'last' => 10],
                '_perf_std10' => ['field' => 'std_code', 'last' => 10],
                '_perf_expsem' => ['field' => 'expsem', 'last' => null],
                '_perf_grp' => ['field' => 'grp_code', 'last' => null],
                '_perf_cardid' => ['field' => 'cardid', 'last' => null],
            ],
            'grade' => [
                '_perf_std10' => ['field' => 'std_code', 'last' => 10],
                '_perf_semestry' => ['field' => 'semestry', 'last' => null],
                '_perf_sub' => ['field' => 'sub_code', 'last' => null],
                '_perf_grp' => ['field' => 'grp_code', 'last' => null],
            ],
            'subject' => ['_perf_sub' => ['field' => 'sub_code', 'last' => null]],
            'activity' => [
                '_perf_std10' => ['field' => 'std_code', 'last' => 10],
                '_perf_semestry' => ['field' => 'semestry', 'last' => null],
            ],
            'virtue' => [
                '_perf_std10' => ['field' => 'std_code', 'last' => 10],
                '_perf_semester' => ['field' => 'semester', 'last' => null],
            ],
            'group' => ['_perf_grp' => ['field' => 'grp_code', 'last' => null]],
            'schedule' => [
                '_perf_sub' => ['field' => 'sub_code', 'last' => null],
                '_perf_semestry' => ['field' => 'semestry', 'last' => null],
            ],
            'field' => ['_perf_fld' => ['field' => 'fld_code', 'last' => null]],
            default => [],
        };
    }

    /** @return list<list<string>> */
    private function performanceIndexColumns(string $type): array
    {
        return match ($type) {
            'student' => [['_perf_id10'], ['_perf_expsem'], ['_perf_grp'], ['_perf_cardid']],
            // The portal normally locates a student's rows first and then
            // narrows by semester/subject. The composite index serves all
            // three left-prefix shapes while the two singles serve global
            // latest-term and subject joins.
            'grade' => [['_perf_std10', '_perf_semestry', '_perf_sub'], ['_perf_semestry'], ['_perf_sub']],
            'subject' => [['_perf_sub']],
            'activity' => [['_perf_std10', '_perf_semestry']],
            'virtue' => [['_perf_std10', '_perf_semester']],
            'group' => [['_perf_grp']],
            'schedule' => [['_perf_sub', '_perf_semestry'], ['_perf_semestry']],
            'field' => [['_perf_fld']],
            default => [],
        };
    }

    /** @param list<list<string>> $definitions */
    private function createPerformanceIndexes(string $table, array $definitions): int
    {
        if ($definitions === []) {
            return 0;
        }

        $existing = [];
        foreach ($this->write()->getSchemaBuilder()->getIndexes($table) as $index) {
            $columns = array_map('strtolower', array_values($index['columns'] ?? []));
            if ($columns !== []) {
                $existing[implode("\0", $columns)] = true;
            }
        }
        $missing = array_values(array_filter(
            $definitions,
            static fn (array $columns): bool => ! isset($existing[implode("\0", array_map('strtolower', $columns))]),
        ));
        if ($missing === []) {
            return 0;
        }

        $indexName = static function (array $columns) use ($table): string {
            $label = implode('_', array_map(static fn (string $column): string => ltrim($column, '_'), $columns));

            return substr('idx_'.$label.'_'.substr(hash('sha256', $table."\0".implode("\0", $columns)), 0, 8), 0, 60);
        };
        if ($this->write()->getDriverName() === 'mysql') {
            $alterDefinitions = array_map(fn (array $columns): string => 'ADD INDEX '
                .$this->quoteIdentifier($indexName($columns)).' ('
                .implode(', ', array_map($this->quoteIdentifier(...), $columns)).')', $missing);
            // One ALTER lets InnoDB build all secondary indexes in one table
            // operation instead of rescanning the imported table for each index.
            $this->write()->statement('ALTER TABLE '.$this->quoteIdentifier($table).' '.implode(', ', $alterDefinitions));

            return count($missing);
        }

        foreach ($missing as $columns) {
            $this->write()->statement('CREATE INDEX '.$this->quoteIdentifier($indexName($columns)).' ON '
                .$this->quoteIdentifier($table).' ('.implode(', ', array_map($this->quoteIdentifier(...), $columns)).')');
        }

        return count($missing);
    }

    /** @return array{batch_key: string, optimized_tables: int, added_indexes: int} */
    public function optimizeRegisteredBatchIndexes(int $districtId): array
    {
        if ($districtId < 1) {
            throw new InvalidArgumentException('ไม่พบอำเภอเป้าหมาย');
        }
        $batchKey = (string) $this->write()->table('import_batches')
            ->where('district_id', $districtId)
            ->orderByDesc('created_at')
            ->value('batch_key');
        $this->assertBatchKey($batchKey);
        $prefix = 'db_'.$batchKey.'_';
        $optimizedTables = 0;
        $addedIndexes = 0;

        foreach ($this->write()->getSchemaBuilder()->getTableListing(null, false) as $table) {
            if (! str_starts_with($table, $prefix)
                || preg_match('/_(student|grade|subject|activity|virtue|group|schedule|field)$/', $table, $matches) !== 1) {
                continue;
            }
            if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $table) !== 1) {
                throw new RuntimeException('พบชื่อตารางนำเข้าที่ไม่ปลอดภัย');
            }
            $added = $this->createPerformanceIndexes($table, $this->performanceIndexColumns($matches[1]));
            if ($added > 0) {
                $optimizedTables++;
                $addedIndexes += $added;
            }
        }

        return ['batch_key' => $batchKey, 'optimized_tables' => $optimizedTables, 'added_indexes' => $addedIndexes];
    }

    private function registerBatch(string $original, string $saved, string $batchKey, int $sizeKb, int $fileCount, int $districtId): int
    {
        return $this->write()->transaction(function () use ($original, $saved, $batchKey, $sizeKb, $fileCount, $districtId): int {
            $historyId = $this->write()->table('import_history')->insertGetId([
                'file_name' => Str::limit(basename($original), 255, ''),
                'saved_file_name' => $saved,
                'batch_key' => $batchKey,
                'file_size_kb' => $sizeKb,
                'level' => 'ทุกระดับ',
                'file_count' => $fileCount,
                'status' => 'success',
                'district_id' => $districtId,
                'created_at' => now(),
            ]);
            $this->write()->table('import_batches')->insert([
                'batch_key' => $batchKey,
                'district_id' => $districtId,
                'import_history_id' => $historyId,
                'created_at' => now(),
            ]);

            return (int) $historyId;
        });
    }

    /**
     * Keep exactly one registered dataset per district. The new batch is already
     * active before old physical tables and files are removed, so readers never
     * fall back to another district or to a partially imported batch.
     *
     * @return array{removed_count: int, removed_batch_keys: list<string>, warnings: list<string>}
     */
    public function replaceExistingDistrictBatches(int $districtId, string $activeBatchKey): array
    {
        $this->assertBatchKey($activeBatchKey);
        $activeExists = $this->write()->table('import_batches')
            ->where('district_id', $districtId)
            ->where('batch_key', $activeBatchKey)
            ->exists();
        if (! $activeExists) {
            throw new RuntimeException('ไม่พบ batch ใหม่ในทะเบียนของอำเภอ จึงยกเลิกการลบชุดเดิม');
        }

        $mysql = $this->write()->getDriverName() === 'mysql';
        $rows = $this->write()->table('import_batches as batches')
            ->leftJoin('import_history as history', function ($join) use ($mysql): void {
                $join->on('history.id', '=', 'batches.import_history_id')
                    ->on('history.district_id', '=', 'batches.district_id');
                if ($mysql) {
                    $join->whereRaw('BINARY history.batch_key = BINARY batches.batch_key');
                } else {
                    $join->on('history.batch_key', '=', 'batches.batch_key');
                }
            })
            ->where('batches.district_id', $districtId)
            ->where('batches.batch_key', '<>', $activeBatchKey)
            ->orderBy('batches.created_at')
            ->get([
                'batches.batch_key',
                'batches.import_history_id',
            ]);

        $removed = [];
        $warnings = [];
        foreach ($rows as $row) {
            $batchKey = (string) $row->batch_key;
            try {
                $this->assertBatchKey($batchKey);
                $this->dropBatchTables($batchKey);
                $this->removeBatchFiles($batchKey);
                $this->deleteBatchRegistry($districtId, $batchKey, $row->import_history_id === null ? null : (int) $row->import_history_id);
                $removed[] = $batchKey;
            } catch (Throwable $exception) {
                report($exception);
                $warnings[] = "ลบชุดเก่า {$batchKey} ไม่สำเร็จ";
            }
        }

        return [
            'removed_count' => count($removed),
            'removed_batch_keys' => $removed,
            'warnings' => $warnings,
        ];
    }

    /** @return array{removed_table_count: int, removed_zip: bool, removed_extract_directory: bool} */
    public function removeUnregisteredStagingBatch(string $batchKey): array
    {
        $this->assertBatchKey($batchKey);
        if ($this->write()->table('import_batches')->where('batch_key', $batchKey)->exists()) {
            throw new RuntimeException('ไม่สามารถลบ staging ที่ลงทะเบียนเป็นชุดใช้งานแล้ว');
        }

        $prefix = 'db_'.$batchKey.'_';
        $tableCount = count(array_filter(
            $this->write()->getSchemaBuilder()->getTableListing(null, false),
            static fn (string $table): bool => str_starts_with($table, $prefix),
        ));
        $zipPath = $this->absoluteDirectory((string) config('legacy.zip_root')).DIRECTORY_SEPARATOR.$batchKey.'.zip';
        $extractPath = $this->absoluteDirectory((string) config('legacy.extract_root')).DIRECTORY_SEPARATOR.$batchKey;
        $hadZip = is_file($zipPath);
        $hadExtractDirectory = is_dir($extractPath) || is_link($extractPath);

        $this->dropBatchTables($batchKey);
        $this->removeBatchFiles($batchKey);

        return [
            'removed_table_count' => $tableCount,
            'removed_zip' => $hadZip && ! is_file($zipPath),
            'removed_extract_directory' => $hadExtractDirectory && ! file_exists($extractPath) && ! is_link($extractPath),
        ];
    }

    /** @return array{batch_key: string, removed_table_count: int, removed_zip: bool, removed_extract_directory: bool} */
    public function deleteDistrictBatch(int $districtId, string $batchKey): array
    {
        if ($districtId < 1) {
            throw new InvalidArgumentException('ไม่พบอำเภอเป้าหมาย');
        }
        $this->assertBatchKey($batchKey);
        $lockName = $this->acquireDistrictImportLock($districtId);

        try {
            $batch = $this->write()->table('import_batches')
                ->where('district_id', $districtId)
                ->where('batch_key', $batchKey)
                ->first(['import_history_id']);
            if ($batch === null) {
                throw new InvalidArgumentException('ไม่พบชุดข้อมูลในอำเภอที่เลือก');
            }

            $prefix = 'db_'.$batchKey.'_';
            $tableCount = count(array_filter(
                $this->write()->getSchemaBuilder()->getTableListing(null, false),
                static fn (string $table): bool => str_starts_with($table, $prefix),
            ));
            $zipPath = $this->absoluteDirectory((string) config('legacy.zip_root')).DIRECTORY_SEPARATOR.$batchKey.'.zip';
            $extractPath = $this->absoluteDirectory((string) config('legacy.extract_root')).DIRECTORY_SEPARATOR.$batchKey;
            $hadZip = is_file($zipPath);
            $hadExtractDirectory = is_dir($extractPath) || is_link($extractPath);

            $this->dropBatchTables($batchKey);
            $this->removeBatchFiles($batchKey);
            $this->deleteBatchRegistry(
                $districtId,
                $batchKey,
                $batch->import_history_id === null ? null : (int) $batch->import_history_id,
            );

            return [
                'batch_key' => $batchKey,
                'removed_table_count' => $tableCount,
                'removed_zip' => $hadZip && ! is_file($zipPath),
                'removed_extract_directory' => $hadExtractDirectory && ! file_exists($extractPath) && ! is_link($extractPath),
            ];
        } finally {
            $this->releaseDistrictImportLock($lockName);
        }
    }

    private function dropBatchTables(string $batchKey): void
    {
        $prefix = 'db_'.$batchKey.'_';
        $tables = $this->write()->getSchemaBuilder()->getTableListing(null, false);

        foreach ($tables as $table) {
            if (! str_starts_with($table, $prefix)) {
                continue;
            }
            if (preg_match('/^db_'.preg_quote($batchKey, '/').'_[A-Za-z0-9_]+$/', $table) !== 1) {
                throw new RuntimeException('พบชื่อตารางชุดเก่าที่ไม่ปลอดภัย');
            }
            $this->write()->statement('DROP TABLE IF EXISTS '.$this->quoteIdentifier($table));
        }
    }

    private function removeBatchFiles(string $batchKey): void
    {
        $zipRoot = $this->absoluteDirectory((string) config('legacy.zip_root'));
        $extractRoot = $this->absoluteDirectory((string) config('legacy.extract_root'));
        $zipPath = $zipRoot.DIRECTORY_SEPARATOR.$batchKey.'.zip';
        if (is_file($zipPath) && ! @unlink($zipPath)) {
            throw new RuntimeException('ไม่สามารถลบไฟล์ ZIP ชุดเก่าได้');
        }

        $extractPath = $extractRoot.DIRECTORY_SEPARATOR.$batchKey;
        if (is_link($extractPath)) {
            if (! @unlink($extractPath)) {
                throw new RuntimeException('ไม่สามารถลบ symbolic link ของชุดเก่าได้');
            }
        } else {
            $this->removeTree($extractPath);
        }
        if (file_exists($extractPath) || is_link($extractPath)) {
            throw new RuntimeException('ไม่สามารถลบไฟล์ DBF/FPT ชุดเก่าได้ครบ');
        }
    }

    private function deleteBatchRegistry(int $districtId, string $batchKey, ?int $historyId): void
    {
        $this->write()->transaction(function () use ($districtId, $batchKey, $historyId): void {
            $this->write()->table('import_batches')
                ->where('district_id', $districtId)
                ->where('batch_key', $batchKey)
                ->delete();

            $history = $this->write()->table('import_history')
                ->where('district_id', $districtId)
                ->where('batch_key', $batchKey);
            if ($historyId !== null) {
                $history->where('id', $historyId);
            }
            $history->delete();
        });
    }

    private function assertBatchKey(string $batchKey): void
    {
        if (preg_match('/^import_\d{10}_[A-Za-z0-9]+$/', $batchKey) !== 1) {
            throw new RuntimeException('ชื่อ batch ไม่ผ่านการตรวจสอบความปลอดภัย');
        }
    }

    private function acquireDistrictImportLock(int $districtId): ?string
    {
        if ($this->write()->getDriverName() !== 'mysql') {
            return null;
        }

        $lockName = 'sena_import_district_'.$districtId;
        $result = $this->write()->selectOne('SELECT GET_LOCK(?, 180) AS acquired', [$lockName]);
        if ((int) ($result->acquired ?? 0) !== 1) {
            throw new RuntimeException('มีการนำเข้าข้อมูลของอำเภอนี้อยู่ กรุณารอให้รายการเดิมเสร็จก่อน');
        }

        return $lockName;
    }

    private function releaseDistrictImportLock(?string $lockName): void
    {
        if ($lockName === null || $this->write()->getDriverName() !== 'mysql') {
            return;
        }

        try {
            $this->write()->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** @param array<string, mixed> $summary */
    private function audit(int $userId, int $districtId, int $historyId, ?string $ipAddress, array $summary): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $userId,
            'district_id' => $districtId,
            'event' => 'admin.import.completed',
            'auditable_type' => 'legacy_import_batch',
            'auditable_id' => $historyId,
            'ip_address' => $ipAddress,
            'after' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    private function tableName(string $batchKey, string $parent, string $type): string
    {
        $base = 'db_'.$batchKey.'_'.$parent.'_';
        $table = $base.substr($type, 0, 64 - strlen($base));
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $table) !== 1) {
            throw new RuntimeException('ชื่อ physical table ไม่ผ่านการตรวจสอบ');
        }

        return $table;
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $identifier) !== 1) {
            throw new RuntimeException('พบ identifier ฐานข้อมูลไม่ปลอดภัย');
        }

        return '`'.$identifier.'`';
    }

    private function dropCreatedTables(): void
    {
        foreach (array_reverse($this->createdTables) as $table) {
            try {
                $this->write()->statement('DROP TABLE IF EXISTS '.$this->quoteIdentifier($table));
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    private function absoluteDirectory(string $path): string
    {
        if ($path === '') {
            throw new RuntimeException('ยังไม่ได้กำหนดพื้นที่จัดเก็บไฟล์นำเข้า');
        }
        if (! is_dir($path) && ! mkdir($path, 0750, true) && ! is_dir($path)) {
            throw new RuntimeException('ไม่สามารถสร้างพื้นที่จัดเก็บไฟล์นำเข้า');
        }
        $real = realpath($path);
        if ($real === false) {
            throw new RuntimeException('ไม่สามารถตรวจสอบพื้นที่จัดเก็บไฟล์นำเข้า');
        }

        return $real;
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path) || is_link($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }

    private function read(): ConnectionInterface
    {
        return $this->database->connection((string) config('legacy.connection'));
    }

    private function write(): ConnectionInterface
    {
        return $this->database->connection((string) config('legacy.write_connection'));
    }
}
