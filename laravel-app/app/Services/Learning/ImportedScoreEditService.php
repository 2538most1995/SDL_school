<?php

namespace App\Services\Learning;

use App\Domain\Students\Services\StudentDirectoryService;
use App\Domain\Students\Support\AcademicTerm;
use App\Models\User;
use App\Support\DbfWriteException;
use App\Support\VisualFoxProDbfEditor;
use App\Support\VisualFoxProDbfReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use ZipArchive;

final class ImportedScoreEditService
{
    public function __construct(private readonly StudentDirectoryService $directory) {}

    public static function currentBatch(int $districtId): ?string
    {
        if (! Schema::hasTable('import_batches') || ! Schema::hasTable('import_history')) {
            return null;
        }

        return DB::table('import_batches as b')->join('import_history as h', function ($join): void {
            $join->on('h.id', '=', 'b.import_history_id')->on('h.district_id', '=', 'b.district_id')->on('h.batch_key', '=', 'b.batch_key');
        })->where('b.district_id', $districtId)->where('h.status', 'success')
            ->orderByRaw('COALESCE(b.created_at, h.created_at) DESC')->orderByDesc('b.batch_key')->value('b.batch_key');
    }

    public static function token(array $row, string $batch): string
    {
        return hash('sha256', json_encode([
            $batch, (int) $row['level'], (string) $row['student_code'], (string) $row['subject_code'],
            AcademicTerm::normalize($row['academic_term']),
            array_map(static fn ($value) => $value === null ? null : (float) $value, $row['assessment_scores']),
            $row['midterm_score'] === null ? null : (float) $row['midterm_score'],
            $row['final_exam_score'] === null ? null : (float) $row['final_exam_score'],
            $row['total_score'] === null ? null : (float) $row['total_score'],
            in_array(trim((string) $row['grade']), ['', '-'], true) ? null : trim((string) $row['grade']),
            (bool) ($row['is_remedial'] ?? false),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function save(User $user, int $districtId, array $input, ?string $ip): array
    {
        abort_unless(in_array($user->role, ['teacher', 'admin', 'super_admin'], true), 403);
        abort_unless((bool) config('system_data.enabled') && (bool) config('system_data.student_enabled') && (bool) config('system_data.write_enabled'), 503, 'ระบบบันทึกคะแนนนำเข้ายังไม่เปิดใช้งาน');
        abort_unless(Schema::hasTable('imported_score_edits'), 503, 'กรุณารัน migration ก่อนเปิดแก้ไขคะแนนนำเข้า');
        $student = $this->directory->findAccessible($user, $input['student_code'], $input['level']);
        abort_unless($student !== null && $student->districtId === $districtId, 404, 'ไม่พบนักศึกษาที่มีสิทธิ์แก้ไข');
        $batch = $input['batch_key'];
        abort_unless(preg_match('/^import_\d{10}_[A-Za-z0-9]+$/D', $batch) === 1, 422);
        $lock = null;
        if (DB::getDriverName() === 'mysql') {
            $lock = 'sena_import_district_'.$districtId;
            abort_unless((int) (DB::selectOne('SELECT GET_LOCK(?, 5) AS acquired', [$lock])->acquired ?? 0) === 1, 409, 'มีการนำเข้าหรือแก้คะแนนอยู่ กรุณาลองใหม่');
        }
        $temporary = sys_get_temp_dir().'/sdl-score-edit-'.bin2hex(random_bytes(12));
        try {
            File::makeDirectory($temporary, 0700);

            return DB::transaction(function () use ($user, $districtId, $input, $ip, $student, $batch, $temporary): array {
                abort_unless(DB::table('import_batches')->where('district_id', $districtId)->where('batch_key', $batch)->lockForUpdate()->first() !== null, 409, 'ชุดข้อมูลเปลี่ยนแล้ว กรุณาโหลดใหม่');
                abort_unless(self::currentBatch($districtId) === $batch, 409, 'ชุดข้อมูลเปลี่ยนแล้ว กรุณาโหลดตารางใหม่ก่อนแก้คะแนน');
                $table = 'db_'.$batch.'_'.$input['level'].'_grade';
                abort_unless(Schema::hasTable($table), 404, 'ไม่พบตารางคะแนนของระดับนี้');
                $candidates = DB::table($table)->where('_perf_std10', $student->code)->where('_perf_sub', $input['subject_code'])
                    ->whereIn('_perf_semestry', AcademicTerm::variants($input['academic_term']))->lockForUpdate()->get();
                $matching = $candidates->filter(fn ($row) => hash_equals(self::token(self::rowState((array) $row, $input), $batch), $input['edit_token']));
                abort_unless($matching->count() === 1, 409, 'คะแนนเปลี่ยนแล้วหรือมีรายการซ้ำ กรุณาโหลดใหม่และตรวจรายการปกติ/ซ่อมก่อนบันทึก');
                $row = (array) $matching->first();
                if (($input['entry_mode'] ?? 'individual') === 'bulk') {
                    abort_unless(Schema::hasTable('imported_score_completions'), 503, 'กรุณารัน migration สำหรับการกรอกคะแนนทั้งตาราง');
                    abort_if(DB::table('imported_score_completions')->where('district_id', $districtId)->where('batch_key', $batch)
                        ->where('score_key', self::scoreKey(self::rowState($row, $input)))->exists(), 409, 'รายการนี้บันทึกเสร็จแล้ว กรุณาเปิดแก้ไขทีละคน');
                }

                abort_if(trim((string) ($row['typ_code'] ?? '')) === '1'
                    || in_array(trim((string) ($row['grade'] ?? '')), ['ม', 'มส', 'ผ', 'มผ', 'ร'], true), 422, 'ไม่แก้คะแนนทับรายการเทียบโอนหรือเกรดพิเศษ');
                [$entry, $path] = self::copyGrade($batch, (int) $input['level'], $temporary);
                $physical = null;
                $identity = null;
                $ordinal = 0;
                foreach ((new VisualFoxProDbfReader($path))->records(true) as $index => $source) {
                    if (++$ordinal === (int) $row['_id']) {
                        $identity = array_intersect_key($source, array_flip(['std_code', 'sub_code', 'semestry', 'typ_code']));
                        $physical = $index;
                        break;
                    }
                }
                abort_unless($physical !== null && $identity !== null
                    && trim((string) $identity['std_code']) === trim((string) $row['std_code'])
                    && trim((string) $identity['sub_code']) === trim((string) $row['sub_code'])
                    && trim((string) $identity['semestry']) === trim((string) $row['semestry'])
                    && trim((string) $identity['typ_code']) === trim((string) $row['typ_code']), 409, 'ตำแหน่งคะแนนไม่ตรงกับ DBF ต้นฉบับ กรุณานำเข้าชุดข้อมูลใหม่ก่อนแก้ไข');
                $values = [];
                foreach ($input['assessment_scores'] as $index => $value) {
                    $values['midterm'.($index + 1)] = $value;
                }
                $values['midterm'] = $input['midterm_score'];
                $values['final'] = $input['final_exam_score'];
                $values['total'] = $values['midterm'] !== null && $values['final'] !== null ? $values['midterm'] + $values['final'] : null;
                abort_if($values['total'] !== null && $values['total'] > 100, 422, 'คะแนนกลางภาคและปลายภาครวมกันต้องไม่เกิน 100');
                $unchanged = true;
                foreach ($values as $field => $value) {
                    $old = $row[$field] ?? null;
                    if (($old === null) !== ($value === null) || ($value !== null && (float) $old !== (float) $value)) {
                        $unchanged = false;
                        break;
                    }
                }
                if ($unchanged) {
                    return ['saved' => true, 'grade_recalculation_required' => false, 'grade' => $row['grade'] ?? null,
                        'edit_token' => self::token(self::rowState($row, $input), $batch)];
                }
                $values['grade'] = null;
                $editor = new VisualFoxProDbfEditor($path);
                $editor->update($physical, $values); // Validate widths/precision/NULL handling before SQL writes.
                $sqlValues = $values;
                if (array_key_exists('_nullflags', $row)) {
                    $sqlValues['_nullflags'] = $editor->nullFlags($physical);
                }
                DB::table($table)->where('_id', $row['_id'])->update($sqlValues);
                $scope = ['district_id' => $districtId, 'batch_key' => $batch, 'education_level' => (int) $input['level'], 'grade_row_id' => (int) $row['_id']];
                $previous = DB::table('imported_score_edits')->where($scope)->first();
                DB::table('imported_score_edits')->updateOrInsert($scope, [
                    'record_index' => $physical, 'zip_entry' => $entry, 'identity' => json_encode($identity, JSON_THROW_ON_ERROR),
                    'values' => json_encode($values, JSON_THROW_ON_ERROR), 'version' => (int) ($previous->version ?? 0) + 1,
                    'updated_by' => $user->id, 'created_at' => $previous->created_at ?? now(), 'updated_at' => now(),
                ]);
                DB::table('audit_logs')->insert([
                    'user_id' => $user->id, 'district_id' => $districtId, 'event' => 'learning.imported_score.updated',
                    'auditable_type' => $table, 'auditable_id' => $row['_id'], 'ip_address' => $ip,
                    'before' => json_encode(array_intersect_key($row, $values), JSON_THROW_ON_ERROR),
                    'after' => json_encode($values, JSON_THROW_ON_ERROR), 'created_at' => now(),
                ]);

                $state = self::rowState([...$row, ...$values], $input);

                return ['saved' => true, 'grade_recalculation_required' => true, 'edit_token' => self::token($state, $batch), 'grade' => $state['grade']];
            });
        } finally {
            File::deleteDirectory($temporary);
            if ($lock !== null) {
                DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$lock]);
            }
        }
    }

    public static function scoreKey(array $row): string
    {
        return hash('sha256', json_encode([(int) $row['level'], (string) $row['student_code'], (string) $row['subject_code'],
            AcademicTerm::normalize($row['academic_term']), (bool) ($row['is_remedial'] ?? false)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private static function rowState(array $row, array $identity): array
    {
        return ['level' => $identity['level'], 'student_code' => $identity['student_code'], 'subject_code' => $identity['subject_code'],
            'academic_term' => $identity['academic_term'], 'assessment_scores' => array_map(fn ($n) => $row['midterm'.$n] ?? null, range(1, 9)),
            'midterm_score' => $row['midterm'] ?? null, 'final_exam_score' => $row['final'] ?? null, 'total_score' => $row['total'] ?? null,
            'grade' => $row['grade'] ?? null, 'is_remedial' => trim((string) ($row['typ_code'] ?? '')) === '7'];
    }

    /** Finish only the explicitly listed, accessible rows after all autosaves have settled. */
    public function complete(User $user, int $districtId, array $input, ?string $ip): array
    {
        abort_unless(in_array($user->role, ['teacher', 'admin', 'super_admin'], true), 403);
        abort_unless(config('system_data.enabled') && config('system_data.student_enabled') && config('system_data.write_enabled'), 503, 'ระบบบันทึกคะแนนยังไม่เปิดใช้งาน');
        abort_unless(Schema::hasTable('imported_score_completions') && Schema::hasTable('imported_score_edits'), 503, 'กรุณารัน migration ก่อนเปิดบันทึกเสร็จ');
        $batch = $input['batch_key'];
        $allowed = [];
        foreach ($this->directory->accessibleStudents($user) as $student) {
            if ($student->districtId === $districtId) {
                $allowed[$student->level.'|'.$student->code] = true;
            }
        }
        foreach ($input['rows'] as $row) {
            abort_unless(isset($allowed[$row['level'].'|'.$row['student_code']]), 404, 'ไม่พบนักศึกษาที่มีสิทธิ์แก้ไข');
        }
        $lock = DB::getDriverName() === 'mysql' ? 'sena_import_district_'.$districtId : null;
        if ($lock !== null) {
            abort_unless((int) (DB::selectOne('SELECT GET_LOCK(?, 5) AS acquired', [$lock])->acquired ?? 0) === 1, 409, 'มีการนำเข้าหรือแก้คะแนนอยู่ กรุณาลองใหม่');
        }
        try {
            return DB::transaction(function () use ($user, $districtId, $input, $ip, $batch): array {
                abort_unless(DB::table('import_batches')->where('district_id', $districtId)->where('batch_key', $batch)->lockForUpdate()->first() !== null
                    && self::currentBatch($districtId) === $batch, 409, 'ชุดข้อมูลเปลี่ยนแล้ว กรุณาโหลดใหม่');
                $records = [];
                foreach (collect($input['rows'])->groupBy('level', true) as $level => $requested) {
                    $table = 'db_'.$batch.'_'.$level.'_grade';
                    abort_unless(Schema::hasTable($table), 404);
                    $terms = array_values(array_unique(array_merge(...$requested->map(fn ($row) => AcademicTerm::variants($row['academic_term']))->all())));
                    $candidates = DB::table($table)->whereIn('_perf_std10', $requested->pluck('student_code')->unique()->all())
                        ->whereIn('_perf_sub', $requested->pluck('subject_code')->unique()->all())->whereIn('_perf_semestry', $terms)->lockForUpdate()->get();
                    $indexed = $candidates->groupBy(fn ($row) => trim($row->_perf_std10).'|'.trim($row->_perf_sub).'|'.AcademicTerm::normalize($row->_perf_semestry));
                    foreach ($requested as $requestIndex => $identity) {
                        $matching = ($indexed[$identity['student_code'].'|'.$identity['subject_code'].'|'.AcademicTerm::normalize($identity['academic_term'])] ?? collect())
                            ->filter(fn ($row) => hash_equals(self::token(self::rowState((array) $row, $identity), $batch), $identity['edit_token']));
                        if ($matching->count() !== 1) {
                            $message = 'คะแนนของ '.$identity['student_code'].' วิชา '.$identity['subject_code'].' เปลี่ยนแล้ว กรุณาใช้คะแนนล่าสุดก่อนบันทึกเสร็จ';
                            abort(response()->json(['message' => $message, 'errors' => ['rows.'.$requestIndex.'.edit_token' => [$message]]], 409));
                        }
                        $source = (array) $matching->first();
                        abort_if(trim((string) ($source['typ_code'] ?? '')) === '1' || in_array(trim((string) ($source['grade'] ?? '')), ['ม', 'มส', 'ผ', 'มผ', 'ร'], true), 422, 'ไม่เปิดกรอกทับรายการเทียบโอนหรือเกรดพิเศษ');
                        $key = self::scoreKey(self::rowState($source, $identity));
                        abort_if(isset($records[$key]), 422, 'มีรายการคะแนนซ้ำ');
                        $records[$key] = ['district_id' => $districtId, 'batch_key' => $batch, 'score_key' => $key,
                            'completed_by' => $user->id, 'completed_at' => now()];
                    }
                }
                DB::table('imported_score_completions')->upsert(array_values($records), ['district_id', 'batch_key', 'score_key'], ['completed_by', 'completed_at']);
                DB::table('audit_logs')->insert(['user_id' => $user->id, 'district_id' => $districtId, 'event' => 'learning.imported_score.completed',
                    'auditable_type' => 'imported_score_completions', 'ip_address' => $ip,
                    'after' => json_encode(['batch_key' => $batch, 'score_keys' => array_keys($records)], JSON_THROW_ON_ERROR), 'created_at' => now()]);

                return ['completed' => count($records)];
            });
        } finally {
            if ($lock !== null) {
                DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$lock]);
            }
        }
    }

    public static function copyGrade(string $batch, int $level, string $directory): array
    {
        $root = realpath((string) config('system_data.zip_root'));
        $source = $root === false ? false : $root.'/'.$batch.'.zip';
        if ($source === false || is_link($source) || realpath($source) !== $source || ! is_file($source)) {
            throw new DbfWriteException('ไม่พบ ZIP ต้นฉบับเพื่อยืนยันโครงสร้าง DBF');
        }
        $zip = new ZipArchive;
        if ($zip->open($source) !== true) {
            throw new DbfWriteException('เปิด ZIP ต้นฉบับไม่สำเร็จ');
        }
        try {
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (preg_match('~(?:^|/)'.$level.'/grade\.dbf$~i', str_replace('\\', '/', $name)) === 1) {
                    $names[] = $name;
                }
            }
            if (count($names) !== 1) {
                throw new DbfWriteException('ต้องมี GRADE.DBF ของระดับนี้เพียงไฟล์เดียวใน ZIP');
            }
            $path = $directory.'/grade.dbf';
            $in = $zip->getStream($names[0]);
            $out = fopen($path, 'xb');
            if ($in === false || $out === false) {
                if (is_resource($in)) {
                    fclose($in);
                }
                if (is_resource($out)) {
                    fclose($out);
                }
                throw new DbfWriteException('คัดลอก DBF เพื่อยืนยันคะแนนไม่สำเร็จ');
            }
            try {
                $bytes = stream_copy_to_stream($in, $out, 268_435_457);
                if ($bytes === false || $bytes > 268_435_456 || $bytes !== $zip->statName($names[0])['size']) {
                    throw new DbfWriteException('ขนาด DBF ไม่ถูกต้องหรือเกินกำหนด');
                }
            } finally {
                fclose($in);
                fclose($out);
            }

            return [$names[0], $path];
        } finally {
            $zip->close();
        }
    }
}
