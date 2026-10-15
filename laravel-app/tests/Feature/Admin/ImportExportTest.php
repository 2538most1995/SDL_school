<?php

namespace Tests\Feature\Admin;

use App\Domain\Students\Models\Grade;
use App\Domain\Students\Models\Student;
use App\Domain\Students\Repositories\StudentRepository;
use App\Models\District;
use App\Models\User;
use App\Services\Learning\ImportedScoreEditService;
use App\Services\Legacy\LegacyZipImportService;
use App\Support\VisualFoxProDbfReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
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

    public function test_itw_export_overwrites_dbf_scores_and_adds_selected_registration_and_subject(): void
    {
        $this->seedLearning($this->district->id);
        DB::table('learning_score_entries')->where('score', 17.5)->update(['score' => 18]);
        $this->useItwArchive();
        $sourceHash = hash_file('sha256', $this->archivePath());
        Sanctum::actingAs($this->admin);
        $response = $this->getJson($this->endpoint().'?format=itw&scores=scorebooks')->assertOk()
            ->assertDownload('itw-district-'.$this->district->id.'-'.$this->batch.'.zip');
        $zip = $this->openDownload($response->streamedContent());
        $rows = $this->dbfRows($zip, '1/GRADE.DBF');
        $first = collect($rows)->firstWhere('std_code', '12141200000000000001');
        $this->assertSame('0', $first['midterm1']);
        $this->assertSame('18', $first['midterm2']);
        $this->assertSame('18', $first['midterm']);
        $this->assertSame('30', $first['final']);
        $this->assertSame('48', $first['total']);
        $this->assertNull($first['grade'], 'Changed grades must be recalculated by ITW.');
        $this->assertSame('008', $first['roomno']);
        $this->assertSame('19', $first['final1']);
        $this->assertSame('32', $first['final2']);
        $this->assertSame('G1', $first['grp_code']);
        $transfer = collect($rows)->firstWhere('sub_code', 'TEST02');
        $this->assertSame('1', $transfer['typ_code']);
        $this->assertSame('69/1', $transfer['semestry']);
        $this->assertNull($transfer['final']);
        $empty = collect($rows)->firstWhere('std_code', '12141200000000000002');
        $this->assertNull($empty['midterm']);
        $this->assertNull($empty['final']);
        $this->assertNull($empty['total']);
        $this->assertNull($empty['grade']);
        $untouched = collect($rows)->firstWhere('sub_code', 'OLD01');
        $this->assertSame('75', $untouched['total']);
        $this->assertSame('3.5', $untouched['grade']);
        $subjects = collect($this->dbfRows($zip, '1/SUBJECT.DBF'))->keyBy('sub_code');
        $this->assertSame('ทดสอบวิชาเลือก', $subjects['TEST02']['sub_name']);
        $this->assertSame('1.0', $subjects['TEST02']['sub_credit']);
        $this->assertSame('60', $subjects['TEST01']['midterm']);
        $this->assertSame('40', $subjects['TEST01']['final']);
        $this->assertSame('F', $subjects['TEST01']['defmidfin']);
        $this->assertSame('original-index-definitions', $zip->getFromName('1/GRADE.CDX'));
        $this->assertStringContainsString('REINDEX', $zip->getFromName('SDL_ITW_README.txt'));
        $this->assertSame('original memo bytes', $zip->getFromName('1/STUDENT.FPT'));
        $zip->close();
        $this->assertSame($sourceHash, hash_file('sha256', $this->archivePath()));
        $this->assertDatabaseHas('learning_score_entries', ['score' => 18]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.import.exported']);
    }

    public function test_itw_export_rejects_fractional_scores_with_context_without_changing_source(): void
    {
        $this->seedLearning($this->district->id);
        $this->useItwArchive();
        $hash = hash_file('sha256', $this->archivePath());
        Sanctum::actingAs($this->admin);
        $response = $this->getJson($this->endpoint().'?format=itw&scores=scorebooks')->assertUnprocessable();
        $this->assertStringContainsString('0000000001', $response->json('message'));
        $this->assertStringContainsString('MIDTERM2', $response->json('message'));
        $this->assertStringContainsString('ระบบไม่ปัดค่า', $response->json('message'));
        $this->assertSame($hash, hash_file('sha256', $this->archivePath()));
        $this->assertDatabaseMissing('audit_logs', ['event' => 'admin.import.exported']);
    }

    public function test_itw_export_rejects_ambiguous_grade_rows_and_overlong_subject_codes(): void
    {
        $this->seedLearning($this->district->id);
        DB::table('learning_score_entries')->where('score', 17.5)->update(['score' => 18]);
        $this->useItwArchive(true);
        Sanctum::actingAs($this->admin);
        $this->assertStringContainsString('หลายระเบียน', $this->getJson($this->endpoint().'?format=itw&scores=scorebooks')->assertUnprocessable()->json('message'));
        $this->useItwArchive();
        $registration = DB::table('learning_course_registrations')->first();
        $subjects = json_decode($registration->elective_subjects, true);
        $subjects[0]['code'] = 'TOO-LONG-CODE';
        DB::table('learning_course_registrations')->update(['elective_subjects' => json_encode($subjects)]);
        $this->assertStringContainsString('SUB_CODE', $this->getJson($this->endpoint().'?format=itw&scores=scorebooks')->assertUnprocessable()->json('message'));
    }

    public function test_itw_export_uses_the_same_admin_and_district_boundaries(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'teacher', 'district_id' => $this->district->id]));
        $this->getJson($this->endpoint().'?format=itw&scores=scorebooks')->assertForbidden();
        $other = District::create(['name' => 'อีกอำเภอ', 'code' => 'itw-other']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'district_id' => $other->id]));
        $this->getJson($this->endpoint().'?format=itw&scores=scorebooks')->assertNotFound();
        Sanctum::actingAs($this->admin);
        $this->getJson($this->endpoint().'?format=sql')->assertUnprocessable();
    }

    public function test_itw_export_clears_all_scores_for_an_explicit_blank_save_marker(): void
    {
        $this->seedLearning($this->district->id);
        $this->useItwArchive();
        DB::table('learning_score_entries')->where('student_code', '0000000001')->delete();
        DB::table('learning_score_notes')->where('student_code', '0000000001')->update(['note' => null]);
        Sanctum::actingAs($this->admin);
        $zip = $this->openDownload($this->getJson($this->endpoint().'?format=itw&scores=scorebooks')->assertOk()->streamedContent());
        $row = collect($this->dbfRows($zip, '1/GRADE.DBF'))->firstWhere('std_code', '12141200000000000001');
        foreach (['midterm1', 'midterm2', 'midterm', 'final', 'total', 'grade'] as $field) {
            $this->assertNull($row[$field], $field.' must be cleared rather than retain the original score.');
        }
        $zip->close();
    }

    public function test_itw_export_rejects_wrong_groups_and_more_than_nine_coursework_slots(): void
    {
        $this->seedLearning($this->district->id);
        $this->useItwArchive();
        DB::table('learning_scorebooks')->update(['group_code' => 'OTHER']);
        Sanctum::actingAs($this->admin);
        $this->assertStringContainsString('ไม่ตรงกับกลุ่ม', $this->getJson($this->endpoint().'?format=itw&scores=scorebooks')->assertUnprocessable()->json('message'));
        DB::table('learning_scorebooks')->update(['group_code' => 'G1']);
        $book = DB::table('learning_scorebooks')->first();
        for ($i = 3; $i <= 11; $i++) {
            DB::table('learning_score_components')->insert(['scorebook_id' => $book->id, 'category' => 'coursework', 'title' => 'เก็บเพิ่ม', 'max_score' => 1, 'position' => $i]);
        }
        $this->assertStringContainsString('1–9', $this->getJson($this->endpoint().'?format=itw&scores=scorebooks')->assertUnprocessable()->json('message'));
    }

    public function test_itw_export_resolves_a_group_name_from_the_shared_itw_group_dbf(): void
    {
        $this->seedLearning($this->district->id);
        $this->useItwArchive();
        DB::table('learning_score_entries')->where('score', 17.5)->update(['score' => 18]);
        DB::table('learning_scorebooks')->update(['group_code' => 'กลุ่มทดสอบ']);
        $zip = new ZipArchive;
        $zip->open($this->archivePath());
        $zip->addFromString('1214120000/GROUP.DBF', $this->itwDbf([['GRP_CODE', 'C', 8], ['GRP_NAME', 'C', 80]], [['GRP_CODE' => 'G1', 'GRP_NAME' => 'กลุ่มทดสอบ']]));
        $zip->close();
        Sanctum::actingAs($this->admin);
        $response = $this->getJson($this->endpoint().'?format=itw&scores=scorebooks')->assertOk();
        $this->assertNotEmpty($response->streamedContent());
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

    public function test_direct_imported_score_edit_updates_sql_and_both_zip_formats_without_archived_scorebook_override(): void
    {
        $input = $this->prepareDirectScores();
        $this->seedLearning($this->district->id); // Archived book has fractional marks: default export must ignore it.
        $sourceHash = hash_file('sha256', $this->archivePath());
        $input['assessment_scores'] = [0, 18, null, null, null, null, null, null, null];
        $input['midterm_score'] = 18;
        $input['final_exam_score'] = 30;
        $this->putJson('/api/v1/learning/scores/imported', $input)->assertOk()->assertJsonPath('data.saved', true);
        $this->assertDatabaseHas('db_'.$this->batch.'_1_grade', ['_id' => 1, 'midterm1' => 0, 'midterm' => 18, 'final' => 30, 'total' => 48, 'grade' => null]);
        $this->assertDatabaseHas('imported_score_edits', ['district_id' => $this->district->id, 'version' => 1, 'record_index' => 0]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'learning.imported_score.updated', 'user_id' => $this->admin->id]);
        foreach (['sdl', 'itw'] as $format) {
            $zip = $this->openDownload($this->getJson($this->endpoint().'?format='.$format)->assertOk()->streamedContent());
            File::put($this->workspace.'/readback.dbf', $zip->getFromName('1/GRADE.DBF'));
            $zip->close();
            $rows = iterator_to_array((new VisualFoxProDbfReader($this->workspace.'/readback.dbf'))->records());
            $this->assertEquals(0, $rows[0]['midterm1']);
            $this->assertEquals(18, $rows[0]['midterm']);
            $this->assertEquals(30, $rows[0]['final']);
            $this->assertEquals(48, $rows[0]['total']);
            $this->assertNull($rows[0]['grade']);
            $this->assertEquals(82, $rows[1]['total']);
        }
        $this->assertSame($sourceHash, hash_file('sha256', $this->archivePath()));
        $this->putJson('/api/v1/learning/scores/imported', $input)->assertConflict();
        $this->assertDatabaseCount('imported_score_edits', 1);
    }

    public function test_direct_scores_reject_decimal_overflow_invalid_payload_and_protect_other_groups(): void
    {
        $input = $this->prepareDirectScores();
        $input['assessment_scores'][0] = 17.5;
        $this->putJson('/api/v1/learning/scores/imported', $input)->assertUnprocessable();
        $this->assertDatabaseCount('imported_score_edits', 0);
        $this->assertDatabaseHas('db_'.$this->batch.'_1_grade', ['_id' => 1, 'midterm' => 50]);
        $input['assessment_scores'][0] = 100; // MIDTERM1 N(2) cannot hold 100.
        $this->putJson('/api/v1/learning/scores/imported', $input)->assertUnprocessable();
        $input['assessment_scores'] = [1];
        $this->putJson('/api/v1/learning/scores/imported', $input)->assertUnprocessable();
        Sanctum::actingAs(User::factory()->create(['role' => 'teacher', 'district_id' => $this->district->id, 'assigned_groups' => ['OTHER']]));
        $input['assessment_scores'] = array_fill(0, 9, null);
        $this->putJson('/api/v1/learning/scores/imported', $input)->assertNotFound();
        Sanctum::actingAs(User::factory()->create(['role' => 'student', 'district_id' => $this->district->id, 'student_code' => $input['student_code']]));
        $this->putJson('/api/v1/learning/scores/imported', $input)->assertForbidden();
    }

    public function test_direct_scores_can_clear_marks_and_teacher_can_edit_only_assigned_group(): void
    {
        $input = $this->prepareDirectScores();
        Sanctum::actingAs(User::factory()->create(['role' => 'teacher', 'district_id' => $this->district->id, 'assigned_groups' => ['G1']]));
        $input['assessment_scores'] = array_fill(0, 9, null);
        $input['midterm_score'] = null;
        $input['final_exam_score'] = null;
        $this->putJson('/api/v1/learning/scores/imported', $input)->assertOk();
        $this->assertDatabaseHas('db_'.$this->batch.'_1_grade', ['_id' => 1, 'midterm1' => null, 'midterm' => null, 'final' => null, 'total' => null, 'grade' => null]);
        Sanctum::actingAs($this->admin);
        $zip = $this->openDownload($this->getJson($this->endpoint().'?format=itw')->assertOk()->streamedContent());
        File::put($this->workspace.'/readback.dbf', $zip->getFromName('1/GRADE.DBF'));
        $zip->close();
        $rows = iterator_to_array((new VisualFoxProDbfReader($this->workspace.'/readback.dbf'))->records());
        $this->assertNull($rows[0]['midterm']);
        $this->assertNull($rows[0]['final']);
        $this->assertNull($rows[0]['midterm1']);
        $this->assertNull($rows[0]['total']);
    }

    public function test_direct_scores_reject_changed_batch_and_disabled_writes(): void
    {
        $input = $this->prepareDirectScores();
        config()->set('system_data.write_enabled', false);
        $this->putJson('/api/v1/learning/scores/imported', $input)->assertStatus(503);
        config()->set('system_data.write_enabled', true);
        $history = DB::table('import_history')->insertGetId(['district_id' => $this->district->id, 'batch_key' => 'import_1800000000_new', 'file_name' => 'new.zip', 'saved_file_name' => 'new.zip', 'status' => 'success', 'created_at' => now()->addDay()]);
        DB::table('import_batches')->insert(['district_id' => $this->district->id, 'batch_key' => 'import_1800000000_new', 'import_history_id' => $history, 'created_at' => now()->addDay()]);
        $this->putJson('/api/v1/learning/scores/imported', $input)->assertConflict();
        $this->assertDatabaseCount('imported_score_edits', 0);
    }

    public function test_imported_grid_token_roundtrip_handles_deleted_dbf_records_and_second_edit(): void
    {
        $this->prepareDirectScores(true);
        $data = $this->getJson('/api/v1/learning/scores/imported')->assertOk()->assertJsonPath('data.can_edit', true)->json('data');
        $row = collect($data['rows'])->firstWhere('subject_code', 'TEST01');
        $input = [...$row, 'batch_key' => $data['batch_key'], 'midterm_score' => 40, 'final_exam_score' => 0];
        $this->putJson('/api/v1/learning/scores/imported', $input)->assertOk();
        $this->assertDatabaseHas('imported_score_edits', ['record_index' => 1, 'version' => 1]);
        $data = $this->getJson('/api/v1/learning/scores/imported')->assertOk()->json('data');
        $row = collect($data['rows'])->firstWhere('subject_code', 'TEST01');
        $this->assertEquals(40, $row['total_score']);
        $this->assertNull($row['grade']);
        $this->assertNotSame($input['edit_token'], $row['edit_token']);
        $this->putJson('/api/v1/learning/scores/imported', [...$row, 'batch_key' => $data['batch_key'], 'final_exam_score' => 20])->assertOk();
        $this->assertDatabaseHas('imported_score_edits', ['record_index' => 1, 'version' => 2]);
        $zip = $this->openDownload($this->getJson($this->endpoint().'?format=itw')->assertOk()->streamedContent());
        File::put($this->workspace.'/readback.dbf', $zip->getFromName('1/GRADE.DBF'));
        $zip->close();
        $rows = iterator_to_array((new VisualFoxProDbfReader($this->workspace.'/readback.dbf'))->records(true));
        $this->assertArrayNotHasKey(0, $rows);
        $this->assertEquals(60, $rows[1]['total']);
        $this->assertEquals(82, $rows[2]['total']);
        $destination = District::create(['name' => 'ปลายทางคะแนนตรง', 'code' => 'direct-destination']);
        $result = app(LegacyZipImportService::class)->import(
            UploadedFile::fake()->createWithContent('direct.zip', File::get($this->workspace.'/itw-download.zip')),
            '1/2569', $destination->id, $this->admin->id, '127.0.0.1',
        );
        $this->assertDatabaseHas('db_'.$result['batch_key'].'_1_grade', ['std_code' => '12141200000000000001', 'sub_code' => 'TEST01', 'total' => 60, 'grade' => null]);
    }

    public function test_student_reads_updated_imported_marks_instead_of_archived_books(): void
    {
        $input = $this->prepareDirectScores();
        $this->seedLearning($this->district->id);
        $this->putJson('/api/v1/learning/scores/imported', [...$input, 'midterm_score' => 40, 'final_exam_score' => 20])->assertOk();
        Sanctum::actingAs(User::factory()->create(['role' => 'student', 'district_id' => $this->district->id, 'student_code' => '0000000001']));
        $data = $this->getJson('/api/v1/learning/scores')->assertOk()->json('data');
        $course = collect($data['courses'])->firstWhere('subject_code', 'TEST01');
        $this->assertEquals(40, $course['assignment_score']);
        $this->assertEquals(20, $course['exam_score']);
        $this->assertEquals(60, $course['total_score']);
        $this->assertNull($course['grade']);
        $this->assertSame('1/2569', $data['term']);
    }

    public function test_autosave_returns_next_token_and_finishing_persists_bulk_lock_but_allows_individual_edits(): void
    {
        $input = $this->prepareDirectScores();
        $this->getJson('/api/v1/learning/scores/imported')->assertOk()->assertJsonPath('data.can_bulk_edit', true);
        $saved = $this->putJson('/api/v1/learning/scores/imported', [...$input, 'midterm_score' => 40, 'entry_mode' => 'bulk'])
            ->assertOk()->json('data');
        $this->assertNotSame($input['edit_token'], $saved['edit_token']);
        $input = [...$input, 'midterm_score' => 40, 'edit_token' => $saved['edit_token']];
        $finish = ['batch_key' => $this->batch, 'rows' => [$input]];
        $this->postJson('/api/v1/learning/scores/imported/complete', $finish)->assertOk()->assertJsonPath('data.completed', 1);
        $this->assertDatabaseCount('imported_score_completions', 1);
        $this->assertDatabaseHas('audit_logs', ['event' => 'learning.imported_score.completed', 'user_id' => $this->admin->id]);
        $row = collect($this->getJson('/api/v1/learning/scores/imported')->assertOk()->json('data.rows'))->firstWhere('subject_code', 'TEST01');
        $this->assertTrue($row['entry_completed']);
        $this->putJson('/api/v1/learning/scores/imported', [...$input, 'final_exam_score' => 20, 'entry_mode' => 'bulk'])->assertConflict();
        $this->putJson('/api/v1/learning/scores/imported', [...$input, 'final_exam_score' => 20, 'entry_mode' => 'individual'])->assertOk();
        $this->assertDatabaseHas('db_'.$this->batch.'_1_grade', ['_id' => 1, 'total' => 60]);
        $this->assertDatabaseCount('imported_score_completions', 1);
    }

    public function test_finishing_untouched_rows_keeps_original_marks_and_grade_and_rejects_stale_tokens(): void
    {
        $input = $this->prepareDirectScores();
        $request = ['batch_key' => $this->batch, 'rows' => [$input]];
        $this->postJson('/api/v1/learning/scores/imported/complete', [...$request, 'rows' => [[...$input, 'edit_token' => str_repeat('a', 64)]]])->assertConflict();
        $this->assertDatabaseCount('imported_score_completions', 0);
        $this->postJson('/api/v1/learning/scores/imported/complete', $request)->assertOk();
        $this->postJson('/api/v1/learning/scores/imported/complete', $request)->assertOk(); // Idempotent retry.
        $this->assertDatabaseCount('imported_score_completions', 1);
        $this->assertDatabaseCount('imported_score_edits', 0);
        $this->assertDatabaseHas('db_'.$this->batch.'_1_grade', ['_id' => 1, 'total' => 82, 'grade' => '4']);
    }

    public function test_finish_validates_all_rows_atomically_and_enforces_teacher_student_and_district_scope(): void
    {
        $input = $this->prepareDirectScores();
        $request = ['batch_key' => $this->batch, 'rows' => [$input, [...$input, 'student_code' => '0000000002']]];
        $this->postJson('/api/v1/learning/scores/imported/complete', $request)->assertNotFound();
        $this->assertDatabaseCount('imported_score_completions', 0);
        $request['rows'] = [$input];
        Sanctum::actingAs(User::factory()->create(['role' => 'teacher', 'district_id' => $this->district->id, 'assigned_groups' => ['OTHER']]));
        $this->postJson('/api/v1/learning/scores/imported/complete', $request)->assertNotFound();
        Sanctum::actingAs(User::factory()->create(['role' => 'student', 'district_id' => $this->district->id, 'student_code' => $input['student_code']]));
        $this->postJson('/api/v1/learning/scores/imported/complete', $request)->assertForbidden();
        $other = District::create(['name' => 'ข้ามอำเภอ', 'code' => 'completion-other']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'district_id' => $other->id]));
        $this->postJson('/api/v1/learning/scores/imported/complete', $request)->assertNotFound();
        Sanctum::actingAs(User::factory()->create(['role' => 'teacher', 'district_id' => $this->district->id, 'assigned_groups' => ['G1']]));
        $this->postJson('/api/v1/learning/scores/imported/complete', $request)->assertOk();
    }

    public function test_autosave_of_unchanged_marks_keeps_original_grade_and_does_not_create_export_edits(): void
    {
        $input = $this->prepareDirectScores();
        $this->putJson('/api/v1/learning/scores/imported', [...$input, 'entry_mode' => 'bulk'])->assertOk()
            ->assertJsonPath('data.edit_token', $input['edit_token'])->assertJsonPath('data.grade', '4')
            ->assertJsonPath('data.grade_recalculation_required', false);
        $this->assertDatabaseCount('imported_score_edits', 0);
        $this->assertDatabaseHas('db_'.$this->batch.'_1_grade', ['_id' => 1, 'grade' => '4', 'total' => 82]);
    }

    private function prepareDirectScores(bool $deletedFirst = false): array
    {
        $this->useItwArchive();
        config()->set('system_data.write_enabled', true);
        config()->set('system_data.student_enabled', true);
        $zip = new ZipArchive;
        $zip->open($this->archivePath());
        $bytes = $zip->getFromName('1/GRADE.DBF');
        if ($deletedFirst) {
            $layout = unpack('Vcount/vheader/vrecord', substr($bytes, 4, 8));
            $deleted = '*'.substr($bytes, $layout['header'] + 1, $layout['record'] - 1);
            $bytes = substr($bytes, 0, $layout['header']).$deleted.substr($bytes, $layout['header']);
            $bytes = substr_replace($bytes, pack('V', $layout['count'] + 1), 4, 4);
            $zip->close();
            $zip->open($this->archivePath());
            $zip->addFromString('1/GRADE.DBF', $bytes);
        }
        File::put($this->workspace.'/initial.dbf', $bytes);
        $zip->close();
        $reader = new VisualFoxProDbfReader($this->workspace.'/initial.dbf');
        $table = 'db_'.$this->batch.'_1_grade';
        Schema::create($table, function ($table) use ($reader): void {
            $table->increments('_id');
            foreach ($reader->fields() as $field) {
                $table->text($field['name'])->nullable();
            }
            foreach (['_perf_std10', '_perf_sub', '_perf_semestry'] as $field) {
                $table->string($field);
            }
        });
        foreach ($reader->records() as $row) {
            DB::table($table)->insert([...$row, '_perf_std10' => substr($row['std_code'], -10), '_perf_sub' => $row['sub_code'], '_perf_semestry' => $row['semestry']]);
        }
        $student = new Student(
            code: '0000000001', districtId: $this->district->id, districtName: 'ทดสอบ', prefix: 'นาย', firstName: 'ทดสอบ', lastName: 'คะแนน',
            level: 1, levelLabel: 'ประถมศึกษา', groupCode: 'G1', groupName: 'ทดสอบ', enrollmentTerm: '1/2569', currentTerm: '1/2569',
            status: 'active', statusLabel: 'กำลังศึกษา', gpax: 0, creditsEarned: 0, creditsRequired: 48, kpchHours: 0, moralResult: '',
        );
        $repository = \Mockery::mock(StudentRepository::class);
        $repository->shouldReceive('students')->andReturn([$student]);
        $repository->shouldReceive('find')->andReturn($student);
        $readGrades = function () use ($student, $table): array {
            $grades = [];
            foreach (DB::table($table)->where('_perf_std10', $student->code)->get() as $row) {
                $grades[] = new Grade(studentCode: $student->code, subjectCode: $row->sub_code, subjectName: 'ทดสอบ', credits: 2, subjectType: 'compulsory', term: '1/2569', grade: $row->grade,
                    assessmentScores: array_map(fn ($n) => $row->{'midterm'.$n} === null ? null : (float) $row->{'midterm'.$n}, range(1, 9)),
                    courseworkScore: $row->midterm === null ? null : (float) $row->midterm, finalExamScore: $row->final === null ? null : (float) $row->final,
                    totalScore: $row->total === null ? null : (float) $row->total);
            }

            return ["{$student->districtId}|1|{$student->code}" => $grades];
        };
        $repository->shouldReceive('gradesForMany')->andReturnUsing($readGrades);
        $repository->shouldReceive('gradesFor')->andReturnUsing(fn () => array_values($readGrades())[0]);

        $this->app->instance(StudentRepository::class, $repository);
        $state = ['level' => 1, 'student_code' => '0000000001', 'subject_code' => 'TEST01', 'academic_term' => '1/2569',
            'assessment_scores' => [20, 30, null, null, null, null, null, null, null], 'midterm_score' => 50, 'final_exam_score' => 32, 'total_score' => 82, 'grade' => '4'];
        Sanctum::actingAs($this->admin);

        return [...$state, 'batch_key' => $this->batch, 'edit_token' => ImportedScoreEditService::token($state, $this->batch)];
    }

    private function useItwArchive(bool $duplicateGrade = false): void
    {
        $zip = new ZipArchive;
        $zip->open($this->archivePath(), ZipArchive::OVERWRITE);
        $studentFields = [['ID', 'C', 10], ['STD_CODE', 'C', 20], ['SCHOOL', 'C', 10], ['GRP_CODE', 'C', 8], ['METHOD', 'C', 1], ['LEARNING', 'C', 1]];
        $zip->addFromString('1/STUDENT.DBF', $this->itwDbf($studentFields, [
            ['ID' => '0000000001', 'STD_CODE' => '12141200000000000001', 'SCHOOL' => '1214120000', 'GRP_CODE' => 'G1', 'METHOD' => '1', 'LEARNING' => '1'],
            ['ID' => '0000000002', 'STD_CODE' => '12141200000000000002', 'SCHOOL' => '1214120000', 'GRP_CODE' => 'G1', 'METHOD' => '1', 'LEARNING' => '1'],
        ]));
        $fields = [['STD_CODE', 'C', 20], ['SEMESTRY', 'C', 4], ['SUB_CODE', 'C', 9], ['TYP_CODE', 'N', 1],
            ['MIDTERM', 'N', 3, 0, 2], ['FINAL', 'N', 3, 0, 2], ['TOTAL', 'N', 3], ['GRADE', 'C', 3],
            ['ROOMNO', 'C', 3], ['GRP_CODE', 'C', 8], ['METHOD', 'C', 1], ['LEARNING', 'C', 1]];
        for ($i = 1; $i <= 9; $i++) {
            $fields[] = ['MIDTERM'.$i, 'N', 2];
        }
        $fields = [...$fields, ['FINAL1', 'N', 3, 0, 2], ['FINAL2', 'N', 3, 0, 2], ['DT_UPDMID', 'T', 8], ['_NullFlags', '0', 1]];
        $base = ['STD_CODE' => '12141200000000000001', 'SEMESTRY' => '69/1', 'SUB_CODE' => 'TEST01', 'TYP_CODE' => '0',
            'MIDTERM' => '50', 'FINAL' => '32', 'TOTAL' => '82', 'GRADE' => '4', 'ROOMNO' => '008', 'GRP_CODE' => 'G1',
            'METHOD' => '1', 'LEARNING' => '1', 'MIDTERM1' => '20', 'MIDTERM2' => '30', 'FINAL1' => '19', 'FINAL2' => '32', '_NullFlags' => "\0"];
        $rows = [$base, [...$base, 'STD_CODE' => '12141200000000000002'], [...$base, 'SUB_CODE' => 'OLD01', 'TOTAL' => '75', 'GRADE' => '3.5']];
        if ($duplicateGrade) {
            $rows[] = $base;
        }
        $zip->addFromString('1/GRADE.DBF', $this->itwDbf($fields, $rows));
        $zip->addFromString('1/SUBJECT.DBF', $this->itwDbf([
            ['SUB_CODE', 'C', 9], ['SUB_NAME', 'C', 80], ['SUB_CREDIT', 'N', 3, 1], ['SUB_TYPE', 'N', 1],
            ['MIDTERM', 'N', 3], ['FINAL', 'N', 3], ['DEFMIDFIN', 'L', 1], ['DEFMINFIN', 'L', 1], ['SUB_ID', 'N', 3],
        ], [['SUB_CODE' => 'TEST01', 'SUB_NAME' => 'ทดสอบวิชาบังคับ', 'SUB_CREDIT' => '2.0', 'SUB_TYPE' => '1', 'MIDTERM' => '60', 'FINAL' => '40', 'DEFMIDFIN' => 'T', 'DEFMINFIN' => 'T', 'SUB_ID' => '1']]));
        $zip->addFromString('1/GRADE.CDX', 'original-index-definitions');
        $zip->addFromString('1/STUDENT.FPT', 'original memo bytes');
        $zip->close();
    }

    private function itwDbf(array $fields, array $records): string
    {
        $headerLength = 32 + count($fields) * 32 + 1 + 263;
        $recordLength = 1 + array_sum(array_column($fields, 2));
        $bytes = chr(0x30).str_repeat("\0", 3).pack('Vvv', count($records), $headerLength, $recordLength).str_repeat("\0", 16).chr(1).chr(0x7C)."\0\0";
        foreach ($fields as $field) {
            [$name, $type, $length] = $field;
            $bytes .= str_pad($name, 11, "\0").$type.str_repeat("\0", 4).chr($length).chr($field[3] ?? 0).chr($field[4] ?? 0).str_repeat("\0", 13);
        }
        $bytes .= chr(0x0D).str_repeat("\0", 263);
        foreach ($records as $row) {
            $bytes .= ' ';
            foreach ($fields as [$name, $type, $length]) {
                $raw = (string) ($row[$name] ?? (in_array($type, ['T', '0'], true) ? str_repeat("\0", $length) : ''));
                if ($type === 'C') {
                    $raw = iconv('UTF-8', 'Windows-874', $raw);
                }
                $bytes .= str_pad($raw, $length, ' ', in_array($type, ['N', 'F'], true) ? STR_PAD_LEFT : STR_PAD_RIGHT);
            }
        }

        return $bytes.chr(0x1A);
    }

    private function openDownload(string $bytes): ZipArchive
    {
        File::put($this->workspace.'/itw-download.zip', $bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($this->workspace.'/itw-download.zip') === true);

        return $zip;
    }

    private function dbfRows(ZipArchive $zip, string $name): array
    {
        $path = $this->workspace.'/verify-'.basename($name);
        File::put($path, $zip->getFromName($name));

        return iterator_to_array((new VisualFoxProDbfReader($path))->records());
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
