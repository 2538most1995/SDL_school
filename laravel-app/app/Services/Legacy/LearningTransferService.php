<?php

namespace App\Services\Legacy;

use App\Support\DbfWriteException;
use App\Support\VisualFoxProDbfEditor;
use Generator;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;
use ZipArchive;

/** A fixed, versioned data format; never executes SQL or paths from an archive. */
final class LearningTransferService
{
    private const DIRECTORY = 'sdl-transfer';

    private const TABLES = [
        'registrations' => ['learning_course_registrations', ['id', 'academic_term', 'student_code', 'education_level', 'group_code', 'student_info', 'compulsory_subjects', 'elective_subjects', 'notes']],
        'scorebooks' => ['learning_scorebooks', ['id', 'academic_term', 'subject_code', 'subject_name', 'education_level', 'group_code', 'coursework_weight', 'final_exam_weight']],
        'components' => ['learning_score_components', ['id', 'scorebook_id', 'category', 'title', 'max_score', 'position']],
        'entries' => ['learning_score_entries', ['id', 'scorebook_id', 'component_id', 'student_code', 'score']],
        'notes' => ['learning_score_notes', ['id', 'scorebook_id', 'student_code', 'note']],
    ];

    private const JSON_COLUMNS = ['student_info', 'compulsory_subjects', 'elective_subjects'];

    public static function isTransferEntry(string $name): bool
    {
        return $name === self::DIRECTORY.'/manifest.json'
            || in_array($name, array_map(fn ($type) => self::DIRECTORY.'/'.$type.'.jsonl', array_keys(self::TABLES)), true);
    }

    /** @param resource $source Original ITW ZIP, opened after authorization. */
    public function create($source, int $districtId, string $batch, bool $itw = false, bool $includeScorebooks = false): string
    {
        @set_time_limit(0);
        $this->assertSchema();
        $directory = sys_get_temp_dir().'/sdl-transfer-'.bin2hex(random_bytes(12));
        File::makeDirectory($directory, 0700, true);
        $dataDirectory = $directory.'/'.self::DIRECTORY;
        File::makeDirectory($dataDirectory, 0700);
        register_shutdown_function(static fn () => File::deleteDirectory($directory));
        $archive = $directory.'/export.zip';
        $zip = new ZipArchive;
        try {
            $output = fopen($archive, 'xb');
            try {
                if (stream_copy_to_stream($source, $output) === false) {
                    throw new RuntimeException('คัดลอก ZIP ไม่สำเร็จ');
                }
            } finally {
                fclose($output);
            }
            if ($zip->open($archive) !== true) {
                throw new RuntimeException('เปิด ZIP ต้นฉบับไม่สำเร็จ');
            }
            $manifest = DB::transaction(function () use ($districtId, $batch, $dataDirectory, $directory): array {
                $files = [];
                foreach (self::TABLES as $type => [$table, $columns]) {
                    $path = $dataDirectory.'/'.$type.'.jsonl';
                    $handle = fopen($path, 'xb');
                    $count = 0;
                    $bytes = 0;
                    try {
                        $this->exportQuery($type, $districtId)->select(array_map(fn ($column) => 'data.'.$column, $columns))
                            ->chunkById(500, function ($rows) use ($handle, &$count, &$bytes): void {
                                foreach ($rows as $row) {
                                    $values = (array) $row;
                                    foreach (self::JSON_COLUMNS as $column) {
                                        if (array_key_exists($column, $values) && $values[$column] !== null) {
                                            $values[$column] = json_decode($values[$column], true, 64, JSON_THROW_ON_ERROR);
                                        }
                                    }
                                    $line = json_encode($values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
                                    $bytes += strlen($line);
                                    if ($count >= 1_000_000 || $bytes > 90 * 1024 * 1024) {
                                        throw new RuntimeException('ข้อมูลเว็บมีขนาดเกินขีดจำกัดของชุดส่งออก');
                                    }
                                    if (strlen($line) > 1_048_576 || fwrite($handle, $line) !== strlen($line)) {
                                        throw new RuntimeException('ข้อมูลส่งออกมีขนาดเกินกำหนดหรือเขียนไฟล์ไม่สำเร็จ');
                                    }
                                    $count++;
                                }
                            }, 'data.id', 'id');
                    } finally {
                        fclose($handle);
                    }
                    $files[$type] = ['rows' => $count, 'sha256' => hash_file('sha256', $path)];
                }

                $edits = fopen($directory.'/direct-edits.jsonl', 'xb');
                try {
                    if (Schema::hasTable('imported_score_edits')) {
                        DB::table('imported_score_edits')->where('district_id', $districtId)->where('batch_key', $batch)
                            ->orderBy('id')->chunkById(500, function ($rows) use ($edits): void {
                                foreach ($rows as $row) {
                                    $line = json_encode((array) $row, JSON_THROW_ON_ERROR)."\n";
                                    if (fwrite($edits, $line) !== strlen($line)) {
                                        throw new RuntimeException('บันทึกคะแนนส่งออกไม่สำเร็จ');
                                    }
                                }
                            });
                    }
                } finally {
                    fclose($edits);
                }

                return ['format' => 'sdl-school-learning', 'version' => 1, 'source_batch' => $batch,
                    'exported_at' => now()->toIso8601String(), 'files' => $files];
            });
            File::put($dataDirectory.'/manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $this->validate($directory);
            // Replace prior transfer files on re-export, so stale scores cannot survive.
            $retainedFiles = $zip->numFiles;
            for ($index = $zip->numFiles - 1; $index >= 0; $index--) {
                if (str_starts_with(strtolower(str_replace('\\', '/', $zip->getNameIndex($index))), self::DIRECTORY.'/')) {
                    $zip->deleteIndex($index);
                    $retainedFiles--;
                }
            }
            if ($retainedFiles + count(self::TABLES) + 1 + ($itw && $zip->locateName('SDL_ITW_README.txt') === false ? 1 : 0) > 2000) {
                throw new RuntimeException('จำนวนไฟล์รวมเกินขีดจำกัด 2,000 ไฟล์ของระบบนำเข้า');
            }
            if ($itw) {
                $manifest['itw_dbf_export'] = app(ItwDbfExportService::class)->apply($zip, $directory, $dataDirectory, $includeScorebooks);
            }
            $manifest['imported_score_edits'] = $this->applyDirectEdits($zip, $directory);
            if ($itw) {
                $manifest['itw_dbf_export']['scores'] += $manifest['imported_score_edits'];
            }
            foreach (array_keys(self::TABLES) as $type) {
                $name = self::DIRECTORY.'/'.$type.'.jsonl';
                if (! $zip->addFile($dataDirectory.'/'.$type.'.jsonl', $name)) {
                    throw new RuntimeException('เพิ่มข้อมูลเว็บใน ZIP ไม่สำเร็จ');
                }
                // Stored JSONL cannot trigger the importer's compression-ratio limit.
                $zip->setCompressionName($name, ZipArchive::CM_STORE);
            }
            $zip->addFromString(self::DIRECTORY.'/manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if (! $zip->close()) {
                throw new RuntimeException('บันทึก ZIP ไม่สำเร็จ');
            }
            clearstatcache(true, $archive);
            if (filesize($archive) > 90 * 1024 * 1024) {
                throw new RuntimeException('ZIP รวมข้อมูลเว็บเกิน 90 MB ซึ่งเป็นขนาดสูงสุดที่ระบบนำเข้าได้');
            }
            File::deleteDirectory($dataDirectory);
            File::delete($directory.'/direct-edits.jsonl');
            File::delete(glob($directory.'/itw-*') ?: []);

            return $archive;
        } catch (Throwable $exception) {
            if ($zip->status === ZipArchive::ER_OK) {
                try {
                    $zip->close();
                } catch (Throwable) {
                    // The archive may already be closed.
                }
            }
            File::deleteDirectory($directory);
            throw $exception;
        }
    }

    private function applyDirectEdits(ZipArchive $zip, string $directory): int
    {
        $handle = fopen($directory.'/direct-edits.jsonl', 'rb');
        $paths = [];
        $count = 0;
        try {
            while (($line = fgets($handle)) !== false) {
                $edit = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
                $level = (int) $edit['education_level'];
                $name = $edit['zip_entry'];
                if (! in_array($level, [1, 2, 3], true) || preg_match('~(?:^|/)'.$level.'/grade\.dbf$~i', str_replace('\\', '/', $name)) !== 1) {
                    throw new DbfWriteException('ตำแหน่ง DBF ของคะแนนไม่ถูกต้อง');
                }
                $path = $directory.'/itw-'.$level.'-grade.dbf';
                if (! isset($paths[$level])) {
                    if (! is_file($path)) {
                        $source = $zip->getStream($name);
                        if ($source === false) {
                            throw new DbfWriteException('ไม่พบ GRADE.DBF สำหรับคะแนนที่แก้ไข');
                        }
                        $target = fopen($path, 'xb');
                        try {
                            $bytes = stream_copy_to_stream($source, $target, 268_435_457);
                            if ($bytes === false || $bytes > 268_435_456 || $bytes !== $zip->statName($name)['size']) {
                                throw new DbfWriteException('คัดลอก GRADE.DBF ไม่ครบหรือเกินขนาด');
                            }
                        } finally {
                            fclose($source);
                            fclose($target);
                        }
                    }
                    $paths[$level] = ['path' => $path, 'name' => $name, 'edits' => []];
                }
                if ($paths[$level]['name'] !== $name) {
                    throw new DbfWriteException('พบไฟล์คะแนนซ้ำในระดับเดียวกัน');
                }
                $paths[$level]['edits'][(int) $edit['record_index']] = $edit;
            }
        } finally {
            fclose($handle);
        }
        foreach ($paths as $data) {
            $editor = new VisualFoxProDbfEditor($data['path']);
            $pending = $data['edits'];
            foreach ($editor->records() as $index => $row) {
                if (! isset($pending[$index])) {
                    continue;
                }
                $edit = $pending[$index];
                $identity = json_decode($edit['identity'], true, 16, JSON_THROW_ON_ERROR);
                foreach (['std_code', 'sub_code', 'semestry', 'typ_code'] as $field) {
                    if (trim((string) ($identity[$field] ?? '')) !== trim((string) ($row[$field] ?? ''))) {
                        throw new DbfWriteException('รายการคะแนนไม่ตรงกับ DBF ต้นฉบับ กรุณาตรวจทะเบียนก่อนส่งออก');
                    }
                }
                $values = json_decode($edit['values'], true, 16, JSON_THROW_ON_ERROR);
                $allowed = array_merge(array_map(fn ($n) => 'midterm'.$n, range(1, 9)), ['midterm', 'final', 'total', 'grade']);
                if (array_diff(array_keys($values), $allowed) !== [] || count($values) !== count($allowed)) {
                    throw new DbfWriteException('ช่องคะแนนส่งออกไม่ครบหรือไม่รองรับ');
                }
                $editor->update($index, $values);
                $count++;
                unset($pending[$index]);
            }
            if ($pending !== [] || ! $zip->addFile($data['path'], $data['name'])) {
                throw new DbfWriteException('บันทึกคะแนนลง DBF ไม่ครบ');
            }
        }

        return $count;
    }

    /** Validate everything before the importer creates tables or activates a batch. */
    public function validate(string $extractPath): ?array
    {
        $directory = $extractPath.'/'.self::DIRECTORY;
        if (! is_dir($directory)) {
            return null; // Ordinary ITW ZIPs remain compatible.
        }
        $this->assertSchema();
        $path = $directory.'/manifest.json';
        if (! is_file($path) || filesize($path) > 65_536) {
            throw new RuntimeException('ไม่พบ manifest ข้อมูลทะเบียนและคะแนนที่ถูกต้อง');
        }
        $manifest = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($manifest) || ($manifest['format'] ?? null) !== 'sdl-school-learning' || ($manifest['version'] ?? null) !== 1
            || ! is_array($manifest['files'] ?? null) || array_keys($manifest['files']) !== array_keys(self::TABLES)) {
            throw new RuntimeException('รูปแบบหรือเวอร์ชันข้อมูลทะเบียนและคะแนนไม่รองรับ');
        }
        $books = [];
        $components = [];
        foreach (self::TABLES as $type => [$table, $columns]) {
            $path = $directory.'/'.$type.'.jsonl';
            $metadata = $manifest['files'][$type];
            if (! is_array($metadata) || ! is_file($path) || ($metadata['sha256'] ?? null) !== hash_file('sha256', $path)) {
                throw new RuntimeException('ไฟล์ข้อมูลทะเบียนและคะแนนไม่ครบหรือ checksum ไม่ตรง');
            }
            $count = 0;
            $ids = [];
            foreach ($this->rows($directory, $type) as $row) {
                $validator = Validator::make($row, $this->rules($type));
                if (array_diff(array_keys($row), $columns) !== [] || $validator->fails()) {
                    throw new RuntimeException('ข้อมูลทะเบียนหรือคะแนนไม่ผ่านการตรวจสอบ ('.$type.')');
                }
                if (isset($ids[$row['id']])) {
                    throw new RuntimeException('พบรหัสข้อมูลซ้ำในไฟล์ '.$type);
                }
                $ids[$row['id']] = true;
                if ($type === 'scorebooks') {
                    $cw = $row['coursework_weight'];
                    $final = $row['final_exam_weight'];
                    if (! (($cw === null && $final === null) || (in_array((int) $cw, [60, 70, 80], true) && (int) $cw + (int) $final === 100))) {
                        throw new RuntimeException('สัดส่วนคะแนนในไฟล์ไม่ถูกต้อง');
                    }
                    $books[$row['id']] = true;
                }
                if (isset($row['scorebook_id']) && ! isset($books[$row['scorebook_id']])) {
                    throw new RuntimeException('ข้อมูลคะแนนอ้างถึงสมุดคะแนนที่ไม่มีในไฟล์');
                }
                if ($type === 'components') {
                    $components[$row['id']] = [$row['scorebook_id'], (float) $row['max_score']];
                }
                if ($type === 'entries') {
                    $component = $components[$row['component_id']] ?? null;
                    if ($component === null || (int) $component[0] !== (int) $row['scorebook_id']
                        || ($row['score'] !== null && (float) $row['score'] > $component[1])) {
                        throw new RuntimeException('คะแนนอ้างถึงช่องคะแนนไม่ถูกต้องหรือเกินคะแนนเต็ม');
                    }
                }
                if (++$count > 1_000_000) {
                    throw new RuntimeException('จำนวนข้อมูลทะเบียนและคะแนนเกินกำหนด');
                }
            }
            if (($metadata['rows'] ?? null) !== $count) {
                throw new RuntimeException('จำนวนข้อมูลทะเบียนและคะแนนไม่ครบ');
            }
        }

        return $manifest;
    }

    /** Must be called inside the same transaction as batch registration. */
    public function restore(string $extractPath, int $districtId, int $userId, ?array $manifest): array
    {
        if ($manifest === null) {
            return [];
        }
        $directory = $extractPath.'/'.self::DIRECTORY;
        $books = [];
        $components = [];
        $counts = [];
        try {
            foreach (self::TABLES as $type => [$table]) {
                $buffer = [];
                $counts[$type] = 0;
                foreach ($this->rows($directory, $type) as $row) {
                    $sourceId = $row['id'];
                    unset($row['id']);
                    $row['created_at'] = $row['updated_at'] = now();
                    if ($type === 'registrations') {
                        foreach (self::JSON_COLUMNS as $column) {
                            $row[$column] = $row[$column] === null ? null : json_encode($row[$column], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                        }
                        $row['district_id'] = $districtId;
                        $row['registered_by'] = $userId;
                    } elseif ($type === 'scorebooks') {
                        $row['district_id'] = $districtId;
                        $row['created_by'] = $userId;
                        $books[$sourceId] = DB::table($table)->insertGetId($row);
                    } else {
                        $row['scorebook_id'] = $books[$row['scorebook_id']];
                        if ($type === 'components') {
                            $components[$sourceId] = DB::table($table)->insertGetId($row);
                        } else {
                            $row['updated_by'] = $userId;
                            if ($type === 'entries') {
                                $row['component_id'] = $components[$row['component_id']];
                            }
                        }
                    }
                    if (! in_array($type, ['scorebooks', 'components'], true)) {
                        $buffer[] = $row;
                        if (count($buffer) === 500) {
                            DB::table($table)->insert($buffer);
                            $buffer = [];
                        }
                    }
                    $counts[$type]++;
                }
                if ($buffer !== []) {
                    DB::table($table)->insert($buffer);
                }
            }
        } catch (UniqueConstraintViolationException $exception) {
            throw new RuntimeException('อำเภอปลายทางมีทะเบียนหรือสมุดคะแนนซ้ำกับไฟล์ ระบบยกเลิกการนำเข้าโดยไม่ทับข้อมูลเดิม กรุณาใช้อำเภอปลายทางที่ยังไม่มีข้อมูลชุดนี้', 0, $exception);
        }

        return $counts;
    }

    private function exportQuery(string $type, int $districtId): Builder
    {
        $query = DB::table(self::TABLES[$type][0].' as data');
        if (in_array($type, ['registrations', 'scorebooks'], true)) {
            return $query->where('data.district_id', $districtId);
        }

        return $query->join('learning_scorebooks as books', 'books.id', '=', 'data.scorebook_id')->where('books.district_id', $districtId);
    }

    private function assertSchema(): void
    {
        foreach (self::TABLES as [$table, $columns]) {
            if (! Schema::hasColumns($table, $columns)) {
                throw new RuntimeException('โครงสร้างทะเบียนหรือสมุดคะแนนยังไม่พร้อม กรุณารัน migration ก่อนย้ายข้อมูล');
            }
        }
    }

    private function rows(string $directory, string $type): Generator
    {
        $handle = fopen($directory.'/'.$type.'.jsonl', 'rb');
        try {
            while (($line = fgets($handle, 1_048_578)) !== false) {
                if (strlen($line) > 1_048_576 || ! str_ends_with($line, "\n")) {
                    throw new RuntimeException('แถวข้อมูลทะเบียนหรือคะแนนยาวเกินกำหนดหรือไม่ครบ');
                }
                $row = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                if (! is_array($row) || array_is_list($row)) {
                    throw new RuntimeException('แถวข้อมูลทะเบียนหรือคะแนนไม่ถูกต้อง');
                }
                yield $row;
            }
            if (! feof($handle)) {
                throw new RuntimeException('อ่านข้อมูลทะเบียนหรือคะแนนไม่ครบ');
            }
        } finally {
            fclose($handle);
        }
    }

    private function rules(string $type): array
    {
        $student = ['required', 'string', 'max:64'];
        $term = ['required', 'string', 'regex:/^[12]\/25\d{2}$/D'];
        $rules = match ($type) {
            'registrations' => [
                'academic_term' => $term, 'student_code' => $student, 'education_level' => ['required', 'integer', 'in:1,2,3'],
                'group_code' => ['present', 'nullable', 'string', 'max:64'], 'student_info' => ['present', 'nullable', 'array'],
                'compulsory_subjects' => ['present', 'nullable', 'array', 'max:500'], 'elective_subjects' => ['present', 'nullable', 'array', 'max:500'],
                'notes' => ['present', 'nullable', 'string', 'max:10000'],
            ],
            'scorebooks' => [
                'academic_term' => $term, 'subject_code' => ['required', 'string', 'max:32'], 'subject_name' => ['required', 'string', 'max:220'],
                'education_level' => ['required', 'integer', 'in:1,2,3'], 'group_code' => ['present', 'string', 'max:120'],
                'coursework_weight' => ['present', 'nullable', 'integer', 'in:60,70,80'], 'final_exam_weight' => ['present', 'nullable', 'integer', 'in:20,30,40'],
            ],
            'components' => ['scorebook_id' => ['required', 'integer', 'min:1'], 'category' => ['required', 'in:coursework,final_exam'],
                'title' => ['required', 'string', 'max:120'], 'max_score' => ['required', 'numeric', 'gt:0', 'max:99999.99'], 'position' => ['required', 'integer', 'between:0,65535']],
            'entries' => ['scorebook_id' => ['required', 'integer', 'min:1'], 'component_id' => ['required', 'integer', 'min:1'],
                'student_code' => $student, 'score' => ['present', 'nullable', 'numeric', 'between:0,99999.99']],
            'notes' => ['scorebook_id' => ['required', 'integer', 'min:1'], 'student_code' => $student, 'note' => ['present', 'nullable', 'string', 'max:10000']],
        };
        if ($type === 'registrations') {
            $rules['student_info.*'] = ['nullable', function ($attribute, $value, $fail): void {
                if (! is_scalar($value)) {
                    $fail('ข้อมูลนักศึกษาในทะเบียนต้องเป็นข้อความหรือตัวเลข');
                }
            }];
            foreach (['compulsory_subjects', 'elective_subjects'] as $column) {
                $rules[$column.'.*'] = ['array:code,name,credits,registered,transferred,remark'];
                $rules[$column.'.*.code'] = ['required', 'string', 'max:32'];
                $rules[$column.'.*.name'] = ['nullable', 'string', 'max:220'];
                $rules[$column.'.*.credits'] = ['nullable', 'numeric', 'between:0,10'];
                $rules[$column.'.*.registered'] = ['nullable', 'boolean'];
                $rules[$column.'.*.transferred'] = ['nullable', 'boolean'];
                $rules[$column.'.*.remark'] = ['nullable', 'string', 'max:120'];
            }
        }

        return ['id' => ['required', 'integer', 'min:1'], ...$rules];
    }
}
