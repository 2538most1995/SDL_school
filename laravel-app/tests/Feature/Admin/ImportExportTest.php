<?php

namespace Tests\Feature\Admin;

use App\Models\District;
use App\Models\User;
use App\Services\Legacy\LegacyZipImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

final class ImportExportTest extends TestCase
{
    use RefreshDatabase;

    private string $workspace;

    private District $district;

    private User $admin;

    private string $batch = 'import_1700000000_abcd';

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = sys_get_temp_dir().'/sdl-export-'.bin2hex(random_bytes(6));
        File::makeDirectory($this->workspace.'/zips', 0750, true);
        config()->set('system_data.enabled', true);
        config()->set('system_data.zip_root', $this->workspace.'/zips');
        config()->set('system_data.extract_root', $this->workspace.'/extracted');
        $this->district = District::create(['name' => 'อำเภอทดสอบ', 'code' => 'export-test']);
        $this->admin = User::factory()->create(['role' => 'admin', 'district_id' => $this->district->id]);
        $historyId = DB::table('import_history')->insertGetId([
            'file_name' => 'original.zip',
            'saved_file_name' => $this->batch.'.zip',
            'batch_key' => $this->batch,
            'district_id' => $this->district->id,
            'status' => 'success',
        ]);
        DB::table('import_batches')->insert([
            'batch_key' => $this->batch,
            'district_id' => $this->district->id,
            'import_history_id' => $historyId,
            'created_at' => now(),
        ]);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($this->archivePath(), ZipArchive::CREATE) === true);
        foreach (['student', 'grade', 'subject', 'activity', 'virtue', 'group', 'schedule', 'field'] as $type) {
            $zip->addFromString('1/'.strtoupper($type).'.DBF', $this->dbf());
        }
        $zip->addFromString('1/STUDENT.FPT', 'original memo bytes');
        $zip->close();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);
        parent::tearDown();
    }

    public function test_export_preserves_original_zip_and_can_be_reimported_in_another_district(): void
    {
        $this->seedLearning($this->district->id);
        $this->seedLearning($this->district->id, '2/2568');
        $other = District::create(['name' => 'ข้อมูลที่ไม่ส่งออก', 'code' => 'export-excluded']);
        $this->seedLearning($other->id);
        $originalHash = hash_file('sha256', $this->archivePath());
        Sanctum::actingAs($this->admin);
        $response = $this->getJson($this->endpoint())
            ->assertOk()
            ->assertHeader('Content-Type', 'application/zip')
            ->assertDownload('district-'.$this->district->id.'-'.$this->batch.'.zip');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $bytes = $response->streamedContent();
        File::put($this->workspace.'/download.zip', $bytes);
        $source = new ZipArchive;
        $download = new ZipArchive;
        $source->open($this->archivePath());
        $download->open($this->workspace.'/download.zip');
        for ($index = 0; $index < $source->numFiles; $index++) {
            $this->assertSame($source->getFromIndex($index), $download->getFromName($source->getNameIndex($index)));
        }
        $manifest = json_decode($download->getFromName('sdl-transfer/manifest.json'), true);
        $this->assertSame(2, $manifest['files']['registrations']['rows']);
        $this->assertSame(2, $manifest['files']['scorebooks']['rows']);
        $this->assertSame(8, $manifest['files']['entries']['rows']);
        $this->assertStringNotContainsString('district_id', $download->getFromName('sdl-transfer/registrations.jsonl'));
        $source->close();
        $download->close();
        $this->assertSame($originalHash, hash_file('sha256', $this->archivePath()));
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'admin.import.exported', 'user_id' => $this->admin->id, 'district_id' => $this->district->id,
        ]);

        $destination = District::create(['name' => 'เครื่องใหม่จำลอง', 'code' => 'export-destination']);
        config()->set('system_data.write_enabled', true);
        $importer = User::factory()->create(['role' => 'admin', 'district_id' => $destination->id]);
        $result = app(LegacyZipImportService::class)->import(
            new UploadedFile($this->workspace.'/download.zip', 'download.zip', 'application/zip', null, true),
            '1/2569', $destination->id, $importer->id, '127.0.0.1',
        );
        $this->assertSame(8, $result['table_count']);
        $this->assertSame(8, $result['row_count']);
        $this->assertDatabaseHas('import_batches', ['batch_key' => $result['batch_key'], 'district_id' => $destination->id]);
        $this->assertDatabaseHas('import_batches', ['batch_key' => $this->batch, 'district_id' => $this->district->id]);
        $this->assertSame('original memo bytes', File::get($this->workspace.'/extracted/'.$result['batch_key'].'/1/STUDENT.FPT'));
        $this->assertSame(['registrations' => 2, 'scorebooks' => 2, 'components' => 6, 'entries' => 8, 'notes' => 2], $result['validation_summary']['learning_transfer']);
        foreach (['1/2569', '2/2568'] as $term) {
            $registration = DB::table('learning_course_registrations')->where('district_id', $destination->id)->where('academic_term', $term)->first();
            $this->assertSame($importer->id, $registration->registered_by);
            $this->assertSame('ทดสอบวิชาเลือก', json_decode($registration->elective_subjects, true)[0]['name']);
            $this->assertTrue(json_decode($registration->elective_subjects, true)[0]['transferred']);
            $book = DB::table('learning_scorebooks')->where('district_id', $destination->id)->where('academic_term', $term)->first();
            $this->assertSame(60, $book->coursework_weight);
            $this->assertSame($importer->id, $book->created_by);
            $scores = DB::table('learning_score_entries as scores')->join('learning_score_components as parts', 'parts.id', '=', 'scores.component_id')
                ->where('scores.scorebook_id', $book->id)->where('scores.student_code', '0000000001')->orderBy('parts.position')->get(['parts.title', 'scores.score', 'scores.updated_by']);
            $this->assertSame(['คะแนนเก็บ', 'กลางภาค', 'ปลายภาค'], $scores->pluck('title')->all());
            $this->assertSame([0.0, 17.5, 30.0], $scores->pluck('score')->map(fn ($score) => (float) $score)->all());
            $this->assertSame([$importer->id], $scores->pluck('updated_by')->unique()->values()->all());
            $this->assertDatabaseHas('learning_score_entries', ['scorebook_id' => $book->id, 'student_code' => '0000000002', 'score' => null]);
            $this->assertDatabaseHas('learning_score_notes', ['scorebook_id' => $book->id, 'note' => 'หมายเหตุคะแนน']);
        }
    }

    public function test_conflicting_scorebook_rolls_back_registrations_and_keeps_old_batch(): void
    {
        $this->seedLearning($this->district->id);
        $bytes = $this->download();
        $destination = District::create(['name' => 'ปลายทางมีข้อมูล', 'code' => 'export-conflict']);
        DB::table('learning_scorebooks')->insert([
            'district_id' => $destination->id, 'created_by' => $this->admin->id, 'academic_term' => '1/2569',
            'subject_code' => 'TEST01', 'subject_name' => 'คะแนนเดิม', 'education_level' => 1, 'group_code' => 'G1',
        ]);
        $tables = DB::getSchemaBuilder()->getTableListing();
        try {
            $this->importBytes($bytes, $destination->id);
            $this->fail('Conflicting learning records must reject the entire import.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('โดยไม่ทับข้อมูลเดิม', $exception->getMessage());
        }
        $this->assertSame($tables, DB::getSchemaBuilder()->getTableListing());
        $this->assertDatabaseMissing('learning_course_registrations', ['district_id' => $destination->id]);
        $this->assertDatabaseMissing('import_batches', ['district_id' => $destination->id]);
        $this->assertDatabaseHas('learning_scorebooks', ['district_id' => $destination->id, 'subject_name' => 'คะแนนเดิม']);
        $this->assertDatabaseHas('import_batches', ['batch_key' => $this->batch, 'district_id' => $this->district->id]);
    }

    public function test_corrupted_transfer_checksum_is_rejected_before_writes(): void
    {
        $this->seedLearning($this->district->id);
        $bytes = $this->tamper($this->download(), 'entries', function (&$rows): void {
            $rows[0]['score'] = 1;
        }, false);
        $this->assertRejectedTransfer($bytes, 'checksum');
    }

    public function test_forged_district_or_actor_fields_in_transfer_are_rejected(): void
    {
        $this->seedLearning($this->district->id);
        $bytes = $this->tamper($this->download(), 'scorebooks', function (&$rows): void {
            $rows[0]['district_id'] = 999;
        });
        $this->assertRejectedTransfer($bytes, 'ไม่ผ่านการตรวจสอบ');
    }

    public function test_score_component_relationship_and_score_limits_are_validated(): void
    {
        $this->seedLearning($this->district->id);
        $original = $this->download();
        $bytes = $this->tamper($original, 'entries', function (&$rows): void {
            $rows[0]['component_id'] = 99999;
        });
        $this->assertRejectedTransfer($bytes, 'อ้างถึงช่องคะแนน');
        $bytes = $this->tamper($original, 'entries', function (&$rows): void {
            $rows[0]['score'] = 999;
        });
        $this->assertRejectedTransfer($bytes, 'เกินคะแนนเต็ม');
    }

    public function test_reexport_replaces_old_transfer_with_current_learning_data(): void
    {
        $this->seedLearning($this->district->id);
        $old = $this->download();
        File::put($this->archivePath(), $old);
        DB::table('learning_score_entries')->where('student_code', '0000000001')->update(['score' => 5]);
        $this->importBytes($this->download(), District::create(['name' => 'ปลายทาง', 'code' => 'reexport-target'])->id);
        $this->assertSame(3, DB::table('learning_score_entries')->where('score', 5)->whereNotIn('scorebook_id', DB::table('learning_scorebooks')->where('district_id', $this->district->id)->select('id'))->count());
    }

    public function test_original_itw_zip_without_transfer_still_imports(): void
    {
        $destination = District::create(['name' => 'ปลายทาง', 'code' => 'ordinary-itw']);
        $result = $this->importBytes(file_get_contents($this->archivePath()), $destination->id);
        $this->assertSame([], $result['validation_summary']['learning_transfer']);
        $this->assertSame(8, $result['table_count']);
    }

    public function test_uploaded_transfer_restores_scores_through_the_import_queue(): void
    {
        $this->seedLearning($this->district->id);
        File::put($this->workspace.'/queue-transfer.zip', $this->download());
        $destination = District::create(['name' => 'เครื่องใหม่', 'code' => 'queue-transfer']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'district_id' => $destination->id]));
        config()->set('system_data.write_enabled', true);
        config()->set('system_data.import_queue_connection', 'database');
        config()->set('system_data.import_autostart_connection', 'deferred');
        $this->withoutDefer();
        $this->post('/api/v1/admin/imports', [
            'archive' => new UploadedFile($this->workspace.'/queue-transfer.zip', 'transfer.zip', 'application/zip', null, true),
            'academic_term' => '1/2569',
        ], ['Accept' => 'application/json'])
            ->assertAccepted()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.result.validation_summary.learning_transfer.registrations', 1)
            ->assertJsonPath('data.result.validation_summary.learning_transfer.scorebooks', 1)
            ->assertJsonPath('data.result.validation_summary.learning_transfer.entries', 4);
        $this->assertDatabaseHas('learning_course_registrations', ['district_id' => $destination->id, 'student_code' => '0000000001']);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_export_is_available_when_import_writes_are_disabled(): void
    {
        config()->set('system_data.write_enabled', false);
        Sanctum::actingAs($this->admin);
        $this->getJson($this->endpoint())->assertOk();
    }

    public function test_export_requires_authentication_and_an_admin_role(): void
    {
        $this->getJson($this->endpoint())->assertUnauthorized();
        foreach (['teacher', 'student'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role, 'district_id' => $this->district->id]));
            $this->getJson($this->endpoint())->assertForbidden();
        }
        $this->assertDatabaseMissing('audit_logs', ['event' => 'admin.import.exported']);
    }

    public function test_admin_cannot_export_another_district_or_override_scope(): void
    {
        $other = District::create(['name' => 'อีกอำเภอ', 'code' => 'export-other']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'district_id' => $other->id]));
        $this->getJson($this->endpoint())->assertNotFound();
        $this->getJson($this->endpoint(), ['X-District-Id' => (string) $this->district->id])->assertForbidden();
    }

    public function test_super_admin_must_select_the_owning_district(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'super_admin', 'district_id' => null]));
        $this->getJson($this->endpoint())->assertUnprocessable();
        $this->getJson($this->endpoint(), ['X-District-Id' => (string) $this->district->id])->assertOk();
        $other = District::create(['name' => 'อีกอำเภอ', 'code' => 'export-other']);
        $this->getJson($this->endpoint(), ['X-District-Id' => (string) $other->id])->assertNotFound();
    }

    public function test_missing_original_zip_returns_an_actionable_error(): void
    {
        unlink($this->archivePath());
        Sanctum::actingAs($this->admin);
        $this->getJson($this->endpoint())->assertNotFound()->assertJsonPath('message',
            'ไม่พบไฟล์ ZIP ต้นฉบับของชุดนี้ กรุณาใช้ไฟล์ต้นฉบับจาก ITW เพื่อนำเข้าบนเครื่องใหม่');
    }

    public function test_failed_or_mismatched_history_is_not_exportable(): void
    {
        Sanctum::actingAs($this->admin);
        DB::table('import_history')->update(['status' => 'failed']);
        $this->getJson($this->endpoint())->assertNotFound();
        DB::table('import_history')->update(['status' => 'success', 'batch_key' => 'import_1700000001_other']);
        $this->getJson($this->endpoint())->assertNotFound();
    }

    public function test_export_rejects_symlinks_and_unregistered_files(): void
    {
        Sanctum::actingAs($this->admin);
        rename($this->archivePath(), $this->workspace.'/outside.zip');
        symlink($this->workspace.'/outside.zip', $this->archivePath());
        $this->getJson($this->endpoint())->assertNotFound();
        copy($this->workspace.'/outside.zip', $this->workspace.'/zips/import_1700000001_unknown.zip');
        $this->getJson('/api/v1/admin/imports/import_1700000001_unknown/export')->assertNotFound();
        $this->getJson('/api/v1/admin/imports/invalid-batch/export')->assertNotFound();
    }

    public function test_demo_mode_does_not_expose_real_archives(): void
    {
        Sanctum::actingAs($this->admin);
        config()->set('system_data.enabled', false);
        $this->getJson($this->endpoint())->assertStatus(503);
    }

    public function test_corrupt_original_zip_returns_a_generic_error_without_paths(): void
    {
        File::put($this->archivePath(), 'invalid zip');
        Sanctum::actingAs($this->admin);
        $response = $this->getJson($this->endpoint())->assertStatus(503);
        $this->assertStringNotContainsString($this->workspace, $response->getContent());
    }

    private function seedLearning(int $districtId, string $term = '1/2569'): void
    {
        DB::table('learning_course_registrations')->insert([
            'district_id' => $districtId, 'academic_term' => $term, 'student_code' => '0000000001', 'education_level' => 1,
            'group_code' => 'G1', 'student_info' => json_encode(['name' => 'นักศึกษาจำลอง', 'code' => '0000000001']),
            'compulsory_subjects' => json_encode([['code' => 'TEST01', 'name' => 'ทดสอบวิชาบังคับ', 'credits' => 2, 'registered' => true, 'transferred' => false, 'remark' => '']]),
            'elective_subjects' => json_encode([['code' => 'TEST02', 'name' => 'ทดสอบวิชาเลือก', 'credits' => 1, 'registered' => false, 'transferred' => true, 'remark' => 'เทียบโอน']]),
            'notes' => 'หมายเหตุทะเบียน', 'registered_by' => $this->admin->id,
        ]);
        $book = DB::table('learning_scorebooks')->insertGetId([
            'district_id' => $districtId, 'created_by' => $this->admin->id, 'academic_term' => $term,
            'subject_code' => 'TEST01', 'subject_name' => 'ทดสอบวิชาบังคับ', 'education_level' => 1, 'group_code' => 'G1',
            'coursework_weight' => 60, 'final_exam_weight' => 40,
        ]);
        foreach ([['คะแนนเก็บ', 'coursework', 40, 0], ['กลางภาค', 'coursework', 20, 17.5], ['ปลายภาค', 'final_exam', 40, 30]] as $position => [$title, $category, $maximum, $score]) {
            $component = DB::table('learning_score_components')->insertGetId([
                'scorebook_id' => $book, 'title' => $title, 'category' => $category, 'max_score' => $maximum, 'position' => $position,
            ]);
            DB::table('learning_score_entries')->insert([
                'scorebook_id' => $book, 'component_id' => $component, 'student_code' => '0000000001', 'score' => $score, 'updated_by' => $this->admin->id,
            ]);
            if ($position === 2) {
                DB::table('learning_score_entries')->insert([
                    'scorebook_id' => $book, 'component_id' => $component, 'student_code' => '0000000002', 'score' => null, 'updated_by' => $this->admin->id,
                ]);
            }
        }
        DB::table('learning_score_notes')->insert(['scorebook_id' => $book, 'student_code' => '0000000001', 'note' => 'หมายเหตุคะแนน']);
    }

    private function download(): string
    {
        Sanctum::actingAs($this->admin);

        return $this->getJson($this->endpoint())->assertOk()->streamedContent();
    }

    private function importBytes(string $bytes, int $districtId): array
    {
        File::put($this->workspace.'/transfer.zip', $bytes);
        config()->set('system_data.write_enabled', true);

        return app(LegacyZipImportService::class)->import(
            new UploadedFile($this->workspace.'/transfer.zip', 'transfer.zip', 'application/zip', null, true),
            '1/2569', $districtId, $this->admin->id, '127.0.0.1',
        );
    }

    private function assertRejectedTransfer(string $bytes, string $message): void
    {
        $tables = DB::getSchemaBuilder()->getTableListing();
        $books = DB::table('learning_scorebooks')->count();
        try {
            $this->importBytes($bytes, $this->district->id);
            $this->fail('Invalid transfer must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
        $this->assertSame($tables, DB::getSchemaBuilder()->getTableListing());
        $this->assertSame($books, DB::table('learning_scorebooks')->count());
        $this->assertDatabaseHas('import_batches', ['batch_key' => $this->batch]);
    }

    private function tamper(string $bytes, string $type, callable $change, bool $refreshChecksum = true): string
    {
        $path = $this->workspace.'/tampered.zip';
        File::put($path, $bytes);
        $zip = new ZipArchive;
        $zip->open($path);
        $name = 'sdl-transfer/'.$type.'.jsonl';
        $rows = array_map(fn ($line) => json_decode($line, true), explode("\n", rtrim($zip->getFromName($name), "\n")));
        $change($rows);
        $text = implode("\n", array_map(fn ($row) => json_encode($row, JSON_UNESCAPED_UNICODE), $rows))."\n";
        $zip->addFromString($name, $text);
        if ($refreshChecksum) {
            $manifest = json_decode($zip->getFromName('sdl-transfer/manifest.json'), true);
            $manifest['files'][$type] = ['rows' => count($rows), 'sha256' => hash('sha256', $text)];
            $zip->addFromString('sdl-transfer/manifest.json', json_encode($manifest));
        }
        $zip->close();

        return file_get_contents($path);
    }

    private function endpoint(): string
    {
        return '/api/v1/admin/imports/'.$this->batch.'/export';
    }

    private function archivePath(): string
    {
        return $this->workspace.'/zips/'.$this->batch.'.zip';
    }

    private function dbf(): string
    {
        // One synthetic character field/record; no real student data.
        return chr(0x30).str_repeat("\0", 3).pack('Vvv', 1, 65, 11).str_repeat("\0", 20)
            .str_pad('id', 11, "\0").'C'.str_repeat("\0", 4).chr(10).str_repeat("\0", 15)
            .chr(0x0D).' 0000000001'.chr(0x1A);
    }
}
