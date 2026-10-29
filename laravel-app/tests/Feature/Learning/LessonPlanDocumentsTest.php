<?php

namespace Tests\Feature\Learning;

use App\Models\District;
use App\Models\User;
use App\Services\Learning\LearningSchemaReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class LessonPlanDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private District $district;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        config(['system_data.enabled' => true, 'system_data.student_enabled' => false, 'system_data.write_enabled' => true]);
        Storage::fake('local');
        $this->district = District::create(['name' => 'เสนา', 'code' => 'sena', 'is_active' => true]);
        $this->teacher = User::factory()->create(['role' => 'teacher', 'district_id' => $this->district->id]);
        Sanctum::actingAs($this->teacher);
    }

    private function metadata(array $extra = []): array
    {
        return ['title' => 'แผนการสอนวิทยาศาสตร์', 'subject' => 'พว31001', 'level' => '3', 'semester' => '2/2569', 'objectives' => 'เรียนรู้จากการทดลอง', ...$extra];
    }

    private function createPdf(array $extra = []): int
    {
        return (int) $this->post('/api/v1/learning/lesson-plans', $this->metadata([
            'source_type' => 'pdf', 'pdf' => UploadedFile::fake()->create('แผนการสอน.pdf', 10, 'application/pdf'),
            'cover_image' => UploadedFile::fake()->image('cover.jpg', 160, 220), 'page_count' => 12, ...$extra,
        ]))->assertCreated()->json('data.id');
    }

    private function adoptLegacyPlanTable(): void
    {
        // Reproduce the deployment-owned schema inspected in phpMyAdmin.
        Schema::drop('learning_lesson_plans');
        DB::statement("CREATE TABLE learning_lesson_plans (
            id INTEGER PRIMARY KEY AUTOINCREMENT, district_id INTEGER NOT NULL,
            title VARCHAR(180) NOT NULL, subject VARCHAR(150) NOT NULL,
            level VARCHAR(80) NOT NULL DEFAULT '', semester VARCHAR(60) NOT NULL DEFAULT '',
            objectives TEXT NULL, activities TEXT NULL, assessment TEXT NULL,
            file_url VARCHAR(500) NOT NULL DEFAULT '', created_by INTEGER NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        app(LearningSchemaReadiness::class)->ensure();
        (require database_path('migrations/2026_10_29_000038_add_lesson_plan_documents.php'))->up();
        (require database_path('migrations/2026_10_29_000039_add_scope_to_lesson_plans.php'))->up();
        (require database_path('migrations/2026_10_29_000040_add_word_documents_to_lesson_plans.php'))->up();
    }

    public function test_combined_links_save_on_the_deployed_legacy_schema_with_empty_optional_text(): void
    {
        $this->adoptLegacyPlanTable();
        $url = 'https://1drv.ms/b/c/example/IQC_Demo-Plan_For-All-Subjects?';
        foreach (['1', '2', '3'] as $level) {
            $payload = $this->metadata([
                'plan_scope' => 'all_subjects', 'level' => $level, 'subject' => '',
                'source_type' => 'link', 'external_url' => $url,
                'objectives' => '', 'activities' => '', 'assessment' => '',
            ]);
            $id = $this->post('/api/v1/learning/lesson-plans', $payload)->assertCreated()->json('data.id');
            $this->assertDatabaseHas('learning_lesson_plans', [
                'id' => $id, 'subject' => '', 'subject_code' => '', 'level' => $level,
                'education_level' => (int) $level, 'semester' => '2/2569', 'academic_term' => '2/2569',
                'created_by' => $this->teacher->id, 'teacher_id' => $this->teacher->id,
                'external_url' => $url, 'file_url' => $url, 'objectives' => null,
            ]);
        }
        $this->getJson('/api/v1/learning/lesson-plans?plan_scope=all_subjects')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_legacy_alias_updates_preserve_owner_and_long_links_and_private_pdf_access(): void
    {
        $this->adoptLegacyPlanTable();
        $id = $this->createPdf();
        $this->assertDatabaseHas('learning_lesson_plans', ['id' => $id, 'subject' => 'พว31001', 'level' => '3', 'file_url' => '']);
        $other = User::factory()->create(['role' => 'teacher', 'district_id' => $this->district->id]);
        Sanctum::actingAs($other);
        $this->patchJson("/api/v1/learning/lesson-plans/{$id}", ['title' => 'เปลี่ยนเจ้าของไม่ได้'])->assertNotFound();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'district_id' => $this->district->id]));
        $url = 'https://tenant.sharepoint.com/plan.pdf?token='.str_repeat('a', 600);
        $this->patchJson("/api/v1/learning/lesson-plans/{$id}", [
            'plan_scope' => 'all_subjects', 'level' => '2', 'semester' => '1/2570',
            'source_type' => 'link', 'external_url' => $url,
        ])->assertOk();
        $this->assertDatabaseHas('learning_lesson_plans', [
            'id' => $id, 'subject' => '', 'subject_code' => '', 'level' => '2', 'semester' => '1/2570',
            'external_url' => $url, 'file_url' => '', 'created_by' => $this->teacher->id, 'teacher_id' => $this->teacher->id,
        ]);
        $this->patchJson("/api/v1/learning/lesson-plans/{$id}", ['title' => 'แก้ชื่อเท่านั้น'])->assertOk();
        $this->assertDatabaseHas('learning_lesson_plans', ['id' => $id, 'external_url' => $url]);
        $this->get("/api/v1/learning/lesson-plans/{$id}/file")->assertNotFound();
        $this->patchJson("/api/v1/learning/lesson-plans/{$id}", ['source_type' => 'link', 'external_url' => 'javascript:alert(1)'])->assertUnprocessable();
    }

    public function test_legacy_title_capacity_returns_validation_instead_of_a_database_failure(): void
    {
        $this->adoptLegacyPlanTable();
        $this->postJson('/api/v1/learning/lesson-plans', $this->metadata(['title' => str_repeat('ก', 181)]))
            ->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->postJson('/api/v1/learning/lesson-plans', $this->metadata(['title' => str_repeat('ก', 180)]))->assertCreated();
        $this->assertDatabaseCount('learning_lesson_plans', 1);
    }

    public function test_teacher_uploads_pdf_and_cover_and_reads_only_authorized_private_endpoints(): void
    {
        $id = $this->createPdf();
        $row = DB::table('learning_lesson_plans')->find($id);
        Storage::disk('local')->assertExists([$row->pdf_path, $row->cover_path]);
        $response = $this->getJson('/api/v1/learning/lesson-plans')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.source_type', 'pdf')->assertJsonPath('data.0.page_count', 12)
            ->assertJsonPath('data.0.teacher_name', $this->teacher->name)->assertJsonPath('data.0.filename', 'แผนการสอน.pdf');
        $this->assertStringNotContainsString('learning/lesson-plans/', json_encode($response->json('data')));
        $this->get("/api/v1/learning/lesson-plans/{$id}/file")->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $download = $this->get("/api/v1/learning/lesson-plans/{$id}/file?download=1")->assertOk();
        $this->assertStringContainsString('attachment; filename=lesson-plan.pdf', $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString(rawurlencode('แผนการสอน.pdf'), $download->headers->get('Content-Disposition'));
        $cover = $this->get("/api/v1/learning/lesson-plans/{$id}/cover")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertStringContainsString('no-store', $cover->headers->get('Cache-Control'));
        $policy = $cover->headers->get('Content-Security-Policy');
        foreach (['https://drive.google.com', 'https://docs.google.com', 'https://onedrive.live.com', 'https://*.sharepoint.com', "worker-src 'self' blob:", "'wasm-unsafe-eval'"] as $allowed) {
            $this->assertStringContainsString($allowed, $policy);
        }
        $this->assertStringNotContainsString('connect-src *', $policy);
        $this->assertDatabaseHas('audit_logs', ['event' => 'learning.lesson-plans.created', 'auditable_id' => $id]);
    }

    public function test_pdf_range_and_head_requests_only_send_requested_bytes_and_allow_same_origin_readers(): void
    {
        $id = $this->createPdf();
        $row = DB::table('learning_lesson_plans')->find($id);
        $bytes = "%PDF-1.4\n".str_repeat('0123456789', 100000)."\n%%EOF";
        Storage::disk('local')->put($row->pdf_path, $bytes);
        $path = "/api/v1/learning/lesson-plans/{$id}/file?district_id={$this->district->id}";
        $range = $this->get($path, ['Range' => 'bytes=0-63'])->assertStatus(206)
            ->assertHeader('Accept-Ranges', 'bytes')->assertHeader('Content-Length', '64')
            ->assertHeader('Content-Range', 'bytes 0-63/'.strlen($bytes))->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $this->assertStringContainsString("frame-ancestors 'self'", $range->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('no-store', $range->headers->get('Cache-Control'));
        ob_start();
        $range->baseResponse->sendContent();
        $this->assertSame(substr($bytes, 0, 64), ob_get_clean());
        $suffix = $this->get($path, ['Range' => 'bytes=-32'])->assertStatus(206)->assertHeader('Content-Length', '32');
        ob_start();
        $suffix->baseResponse->sendContent();
        $this->assertSame(substr($bytes, -32), ob_get_clean());
        $this->get($path, ['Range' => 'bytes=2000000-'])->assertStatus(416)->assertHeader('Content-Range', 'bytes */'.strlen($bytes));
        $this->head($path)->assertOk()->assertHeader('Content-Length', (string) strlen($bytes));
        $this->get($path.'&download=1')->assertOk();
        $this->get("/api/v1/learning/lesson-plans/{$id}/cover")->assertOk()->assertHeader('X-Frame-Options', 'DENY');
        $page = $this->getJson('/api/v1/learning/lesson-plans')->assertOk()->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringContainsString('img-src \'self\' data: blob: https://drive.google.com https://lh3.googleusercontent.com;', $page->headers->get('Content-Security-Policy'));
    }

    public function test_native_document_district_context_keeps_super_admin_and_teacher_boundaries(): void
    {
        $id = $this->createPdf();
        $path = "/api/v1/learning/lesson-plans/{$id}/file";
        Sanctum::actingAs(User::factory()->create(['role' => 'super_admin', 'district_id' => null]));
        $this->get($path)->assertUnprocessable();
        $this->get($path.'?district_id='.$this->district->id)->assertOk();
        $this->getJson('/api/v1/learning/lesson-plans?district_id='.$this->district->id)->assertUnprocessable();
        foreach (['bad', '0', '-1', '1.5', '1e2'] as $district) {
            $this->get($path.'?district_id='.$district)->assertUnprocessable();
        }
        $this->get($path.'?district_id[]=1')->assertUnprocessable();
        $other = District::create(['name' => 'อื่น', 'code' => 'range-other', 'is_active' => true]);
        $this->get($path.'?district_id='.$other->id)->assertNotFound();
        $this->get($path.'?district_id='.$other->id, ['X-District-Id' => (string) $this->district->id])->assertOk();
        Sanctum::actingAs(User::factory()->create(['role' => 'teacher', 'district_id' => $this->district->id]));
        $this->get($path.'?district_id='.$this->district->id, ['Range' => 'bytes=0-63'])->assertNotFound();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'district_id' => $other->id]));
        $this->get($path.'?district_id='.$this->district->id)->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'student', 'district_id' => $this->district->id]));
        $this->get($path.'?district_id='.$this->district->id)->assertForbidden();
    }

    public function test_cloud_and_generic_links_are_stored_without_server_fetching_and_can_have_pdf_companions(): void
    {
        foreach (['https://drive.google.com/file/d/example/view?usp=sharing', 'https://1drv.ms/b/s!Example?e=demo', 'https://tenant.sharepoint.com/:b:/s/classroom/example', 'https://school.example.org/plan.pdf'] as $url) {
            $this->postJson('/api/v1/learning/lesson-plans', $this->metadata(['source_type' => 'link', 'external_url' => $url]))->assertCreated();
            $this->assertDatabaseHas('learning_lesson_plans', ['external_url' => $url, 'pdf_path' => null]);
        }
        $id = $this->createPdf(['source_type' => 'link', 'external_url' => 'https://onedrive.live.com/?id=example']);
        $this->getJson('/api/v1/learning/lesson-plans')->assertOk()->assertJsonPath('data.0.source_type', 'link');
        $this->get("/api/v1/learning/lesson-plans/{$id}/file")->assertOk();
        config(['system_data.enabled' => false]);
        $this->getJson('/api/v1/learning/lesson-plans')->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.source', 'system_database');
    }

    public function test_metadata_edits_preserve_documents_replacement_versions_and_source_switch_deletes_old_files(): void
    {
        $id = $this->createPdf();
        $old = DB::table('learning_lesson_plans')->find($id);
        $oldCoverUrl = $this->getJson('/api/v1/learning/lesson-plans')->json('data.0.cover_url');
        $this->patchJson("/api/v1/learning/lesson-plans/{$id}", ['title' => 'เปลี่ยนชื่อเท่านั้น'])->assertOk();
        $same = DB::table('learning_lesson_plans')->find($id);
        $this->assertSame($old->pdf_path, $same->pdf_path);
        $this->assertSame($old->cover_path, $same->cover_path);
        $this->assertSame(12, $same->page_count);
        $this->post("/api/v1/learning/lesson-plans/{$id}", [
            '_method' => 'PATCH', 'source_type' => 'pdf', 'pdf' => UploadedFile::fake()->create('replacement.pdf', 20, 'application/pdf'),
            'cover_image' => UploadedFile::fake()->image('replacement.png', 160, 220), 'page_count' => 20,
        ])->assertOk();
        $new = DB::table('learning_lesson_plans')->find($id);
        Storage::disk('local')->assertMissing([$old->pdf_path, $old->cover_path]);
        Storage::disk('local')->assertExists([$new->pdf_path, $new->cover_path]);
        $this->assertNotSame($oldCoverUrl, $this->getJson('/api/v1/learning/lesson-plans')->json('data.0.cover_url'));
        $this->patchJson("/api/v1/learning/lesson-plans/{$id}", ['source_type' => 'link', 'external_url' => 'https://drive.google.com/file/d/new/view'])->assertOk();
        Storage::disk('local')->assertMissing([$new->pdf_path, $new->cover_path]);
        $this->assertDatabaseHas('learning_lesson_plans', ['id' => $id, 'pdf_path' => null, 'cover_path' => null, 'page_count' => null]);
        $this->get("/api/v1/learning/lesson-plans/{$id}/file")->assertNotFound();
        $this->get("/api/v1/learning/lesson-plans/{$id}/cover")->assertNotFound();
    }

    public function test_change_of_link_clears_old_cover_and_delete_removes_companion_files(): void
    {
        $id = $this->createPdf(['source_type' => 'link', 'external_url' => 'https://school.example.org/one.pdf']);
        $old = DB::table('learning_lesson_plans')->find($id);
        $this->patchJson("/api/v1/learning/lesson-plans/{$id}", ['external_url' => 'https://school.example.org/two.pdf'])->assertOk();
        Storage::disk('local')->assertMissing([$old->pdf_path, $old->cover_path]);
        $id = $this->createPdf();
        $row = DB::table('learning_lesson_plans')->find($id);
        $this->deleteJson("/api/v1/learning/lesson-plans/{$id}")->assertOk();
        Storage::disk('local')->assertMissing([$row->pdf_path, $row->cover_path]);
        $this->assertDatabaseMissing('learning_lesson_plans', ['id' => $id]);
    }

    public function test_teacher_student_and_district_boundaries_cover_read_write_and_private_files(): void
    {
        $id = $this->createPdf();
        $otherTeacher = User::factory()->create(['role' => 'teacher', 'district_id' => $this->district->id]);
        Sanctum::actingAs($otherTeacher);
        $this->getJson('/api/v1/learning/lesson-plans')->assertOk()->assertJsonCount(0, 'data');
        foreach (['file', 'cover'] as $kind) {
            $this->get("/api/v1/learning/lesson-plans/{$id}/{$kind}")->assertNotFound();
        }
        $this->patchJson("/api/v1/learning/lesson-plans/{$id}", ['title' => 'ขโมยแก้'])->assertNotFound();
        $this->deleteJson("/api/v1/learning/lesson-plans/{$id}")->assertNotFound();
        $otherDistrict = District::create(['name' => 'อื่น', 'code' => 'other', 'is_active' => true]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'district_id' => $otherDistrict->id]));
        $this->get("/api/v1/learning/lesson-plans/{$id}/file")->assertNotFound();
        Sanctum::actingAs(User::factory()->create(['role' => 'student', 'district_id' => $this->district->id]));
        $this->getJson('/api/v1/learning/lesson-plans')->assertForbidden();
        $this->get("/api/v1/learning/lesson-plans/{$id}/cover")->assertForbidden();
        $this->postJson('/api/v1/learning/lesson-plans', $this->metadata())->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'district_id' => $this->district->id]));
        $this->get("/api/v1/learning/lesson-plans/{$id}/file")->assertOk();
        $this->patchJson("/api/v1/learning/lesson-plans/{$id}", ['title' => 'ผู้ดูแลแก้ได้'])->assertOk();
    }

    public function test_validation_rejects_unsafe_urls_non_pdf_oversized_cover_and_incomplete_sources(): void
    {
        foreach (['javascript:alert(1)', 'https://user:password@example.org/plan', 'https://example.org/a\\b', 'https://example.org/%0aHeader', 'https://example.org/%GG'] as $url) {
            $this->postJson('/api/v1/learning/lesson-plans', $this->metadata(['source_type' => 'link', 'external_url' => $url]))->assertUnprocessable();
        }
        $this->postJson('/api/v1/learning/lesson-plans', $this->metadata(['source_type' => 'link']))->assertUnprocessable();
        $this->postJson('/api/v1/learning/lesson-plans', $this->metadata(['source_type' => 'pdf']))->assertUnprocessable();
        $this->post('/api/v1/learning/lesson-plans', $this->metadata(['source_type' => 'pdf', 'pdf' => UploadedFile::fake()->create('bad.pdf', 1, 'text/plain')]))->assertUnprocessable();
        $this->post('/api/v1/learning/lesson-plans', $this->metadata(['source_type' => 'pdf', 'pdf' => UploadedFile::fake()->create('large.pdf', 20481, 'application/pdf')]))->assertUnprocessable();
        $this->post('/api/v1/learning/lesson-plans', $this->metadata(['source_type' => 'link', 'external_url' => 'https://example.org', 'cover_image' => UploadedFile::fake()->image('large.jpg', 2001, 100)]))->assertUnprocessable();
        $this->assertSame(0, DB::table('learning_lesson_plans')->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_legacy_metadata_remains_editable_and_missing_document_migration_is_actionable(): void
    {
        $id = $this->postJson('/api/v1/learning/lesson-plans', $this->metadata())->assertCreated()->json('data.id');
        $this->getJson('/api/v1/learning/lesson-plans')->assertOk()->assertJsonPath('data.0.source_type', 'legacy');
        $migration = require database_path('migrations/2026_10_29_000038_add_lesson_plan_documents.php');
        $migration->up();
        $migration->up();
        $this->assertSame(1, DB::table('learning_lesson_plans')->count());
        Schema::table('learning_lesson_plans', fn ($table) => $table->dropColumn(['external_url', 'pdf_path', 'pdf_original_name', 'pdf_size', 'cover_path', 'page_count']));
        $this->getJson('/api/v1/learning/lesson-plans')->assertOk()->assertJsonPath('data.0.file_url', null);
        $this->postJson('/api/v1/learning/lesson-plans', $this->metadata(['source_type' => 'link', 'external_url' => 'https://example.org']))->assertStatus(503)->assertSee('migrate');
        $this->assertDatabaseHas('learning_lesson_plans', ['id' => $id]);
    }

    public function test_read_only_mode_preserves_existing_pdf_and_forbids_writes(): void
    {
        $id = $this->createPdf();
        config(['system_data.write_enabled' => false]);
        $this->get("/api/v1/learning/lesson-plans/{$id}/file")->assertOk();
        $this->getJson('/api/v1/learning/lesson-plans')->assertOk()->assertJsonPath('meta.read_only', true);
        $this->patchJson("/api/v1/learning/lesson-plans/{$id}", ['title' => 'ห้ามแก้'])->assertStatus(503);
        $this->deleteJson("/api/v1/learning/lesson-plans/{$id}")->assertStatus(503);
        $this->postJson('/api/v1/learning/lesson-plans', $this->metadata())->assertStatus(503);
    }

    public function test_database_failure_rolls_back_metadata_and_new_private_files_without_removing_old_files(): void
    {
        $id = $this->createPdf();
        $old = DB::table('learning_lesson_plans')->find($id);
        DB::statement("CREATE TRIGGER reject_lesson_audit BEFORE INSERT ON audit_logs WHEN NEW.event = 'learning.lesson-plans.updated' BEGIN SELECT RAISE(FAIL, 'synthetic audit failure'); END");
        $this->post("/api/v1/learning/lesson-plans/{$id}", [
            '_method' => 'PATCH', 'title' => 'ไม่ควรบันทึก', 'pdf' => UploadedFile::fake()->create('new.pdf', 10, 'application/pdf'),
            'cover_image' => UploadedFile::fake()->image('new.jpg', 160, 220),
        ])->assertStatus(500);
        $row = DB::table('learning_lesson_plans')->find($id);
        $this->assertSame($old->title, $row->title);
        $this->assertSame($old->pdf_path, $row->pdf_path);
        $this->assertSame($old->cover_path, $row->cover_path);
        $this->assertEqualsCanonicalizing([$old->pdf_path, $old->cover_path], Storage::disk('local')->allFiles());
    }

    public function test_file_endpoints_reject_paths_owned_by_another_plan_even_with_forged_database_metadata(): void
    {
        $first = $this->createPdf();
        $second = $this->createPdf();
        $secondRow = DB::table('learning_lesson_plans')->find($second);
        DB::table('learning_lesson_plans')->where('id', $first)->update(['pdf_path' => $secondRow->pdf_path, 'cover_path' => $secondRow->cover_path]);
        $this->get("/api/v1/learning/lesson-plans/{$first}/file")->assertNotFound();
        $this->get("/api/v1/learning/lesson-plans/{$first}/cover")->assertNotFound();
        $this->deleteJson("/api/v1/learning/lesson-plans/{$first}")->assertOk();
        Storage::disk('local')->assertExists([$secondRow->pdf_path, $secondRow->cover_path]);
    }

    public function test_combined_pdf_plans_can_be_uploaded_without_a_subject_for_each_education_level(): void
    {
        foreach (['1', '2', '3'] as $level) {
            $values = $this->metadata([
                'plan_scope' => 'all_subjects', 'level' => $level, 'source_type' => 'pdf',
                'pdf' => UploadedFile::fake()->create('รวมทุกวิชา.pdf', 10, 'application/pdf'),
                'cover_image' => UploadedFile::fake()->image('cover.jpg', 160, 220), 'page_count' => 100,
            ]);
            unset($values['subject']);
            $id = $this->post('/api/v1/learning/lesson-plans', $values)->assertCreated()->json('data.id');
            $this->assertDatabaseHas('learning_lesson_plans', ['id' => $id, 'plan_scope' => 'all_subjects', 'subject_code' => '', 'education_level' => (int) $level]);
            $this->getJson('/api/v1/learning/lesson-plans?plan_scope=all_subjects&education_level='.$level)
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.plan_scope', 'all_subjects')
                ->assertJsonPath('data.0.course', 'รวมทุกวิชา')->assertJsonPath('data.0.raw.level', $level)
                ->assertJsonPath('data.0.page_count', 100);
            $this->get("/api/v1/learning/lesson-plans/{$id}/file")->assertOk();
            $this->get("/api/v1/learning/lesson-plans/{$id}/cover")->assertOk();
        }
        $this->getJson('/api/v1/learning/lesson-plans')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_combined_scope_survives_edits_and_scope_changes_preserve_the_document(): void
    {
        $id = $this->createPdf(['plan_scope' => 'all_subjects', 'subject' => 'ignored', 'level' => '2']);
        $old = DB::table('learning_lesson_plans')->find($id);
        $this->assertSame('', $old->subject_code);
        $this->patchJson("/api/v1/learning/lesson-plans/{$id}", ['title' => 'แผนรวม ม.ต้น'])->assertOk();
        $this->assertDatabaseHas('learning_lesson_plans', ['id' => $id, 'plan_scope' => 'all_subjects', 'pdf_path' => $old->pdf_path, 'cover_path' => $old->cover_path]);
        $this->patchJson("/api/v1/learning/lesson-plans/{$id}", ['plan_scope' => 'subject'])->assertUnprocessable()->assertJsonValidationErrors('subject');
        $this->patchJson("/api/v1/learning/lesson-plans/{$id}", ['plan_scope' => 'subject', 'subject' => 'พว21001'])->assertOk();
        $this->assertDatabaseHas('learning_lesson_plans', ['id' => $id, 'plan_scope' => 'subject', 'subject_code' => 'พว21001', 'pdf_path' => $old->pdf_path]);
        $this->patchJson("/api/v1/learning/lesson-plans/{$id}", ['plan_scope' => 'all_subjects'])->assertOk();
        $this->assertDatabaseHas('learning_lesson_plans', ['id' => $id, 'subject_code' => '', 'cover_path' => $old->cover_path]);
        $this->postJson('/api/v1/learning/lesson-plans', $this->metadata(['subject' => '']))->assertUnprocessable();
        foreach ([null, '', 'unknown'] as $invalidScope) {
            $this->postJson('/api/v1/learning/lesson-plans', $this->metadata(['plan_scope' => $invalidScope]))->assertUnprocessable();
            $this->patchJson("/api/v1/learning/lesson-plans/{$id}", ['plan_scope' => $invalidScope])->assertUnprocessable();
        }
        foreach (['level', 'semester', 'title'] as $field) {
            foreach ([null, ''] as $empty) {
                $this->patchJson("/api/v1/learning/lesson-plans/{$id}", [$field => $empty])->assertUnprocessable();
            }
        }
        $this->postJson('/api/v1/learning/lesson-plans', $this->metadata(['plan_scope' => 'all_subjects', 'level' => '4']))->assertUnprocessable();
        $this->getJson('/api/v1/learning/lesson-plans?plan_scope=unknown')->assertUnprocessable();
        $this->getJson('/api/v1/learning/lesson-plans?education_level=4')->assertUnprocessable();
        $link = $this->metadata(['plan_scope' => 'all_subjects', 'source_type' => 'link', 'external_url' => 'https://1drv.ms/b/s!AllSubjects']);
        unset($link['subject']);
        $this->postJson('/api/v1/learning/lesson-plans', $link)->assertCreated();
        Storage::disk('local')->assertExists([$old->pdf_path, $old->cover_path]);
    }

    public function test_scope_and_level_filter_before_the_limit_without_expanding_teacher_or_district_access(): void
    {
        $id = $this->createPdf(['plan_scope' => 'all_subjects', 'level' => '2']);
        DB::table('learning_lesson_plans')->where('id', $id)->update(['updated_at' => '2000-01-01 00:00:00']);
        $record = ['district_id' => $this->district->id, 'teacher_id' => $this->teacher->id, 'plan_scope' => 'subject', 'subject_code' => 'พว21001', 'academic_term' => '2/2569', 'education_level' => 2, 'title' => 'รายวิชาใหม่', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()];
        DB::table('learning_lesson_plans')->insert(array_fill(0, 251, $record));
        $otherTeacher = User::factory()->create(['role' => 'teacher', 'district_id' => $this->district->id]);
        DB::table('learning_lesson_plans')->insert([...$record, 'teacher_id' => $otherTeacher->id, 'plan_scope' => 'all_subjects']);
        $otherDistrict = District::create(['name' => 'อื่น', 'code' => 'other', 'is_active' => true]);
        DB::table('learning_lesson_plans')->insert([...$record, 'district_id' => $otherDistrict->id, 'plan_scope' => 'all_subjects']);
        $this->getJson('/api/v1/learning/lesson-plans?plan_scope=all_subjects&education_level=2')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', (string) $id);
        $this->getJson('/api/v1/learning/lesson-plans?plan_scope=all_subjects&education_level=1')->assertOk()->assertJsonCount(0, 'data');
        Sanctum::actingAs($otherTeacher);
        $this->get("/api/v1/learning/lesson-plans/{$id}/file")->assertNotFound();
        $this->patchJson("/api/v1/learning/lesson-plans/{$id}", ['plan_scope' => 'all_subjects'])->assertNotFound();
    }

    public function test_scope_migration_can_be_repeated_and_preserves_existing_subject_plans(): void
    {
        $id = $this->createPdf();
        Schema::table('learning_lesson_plans', fn ($table) => $table->dropColumn('plan_scope'));
        $this->getJson('/api/v1/learning/lesson-plans')->assertOk()->assertJsonPath('data.0.plan_scope', 'subject');
        $this->getJson('/api/v1/learning/lesson-plans?plan_scope=all_subjects')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson("/api/v1/learning/lesson-plans/{$id}", ['plan_scope' => 'all_subjects'])->assertStatus(503)->assertSee('migrate');
        $migration = require database_path('migrations/2026_10_29_000039_add_scope_to_lesson_plans.php');
        $migration->up();
        $migration->up();
        $migration->down();
        $this->assertDatabaseHas('learning_lesson_plans', ['id' => $id, 'plan_scope' => 'subject', 'subject_code' => 'พว31001']);
        $this->get("/api/v1/learning/lesson-plans/{$id}/file")->assertOk();
    }

    public function test_link_plan_saves_cover_image_without_pdf_and_clear_pdf_deletes_disk_file(): void
    {
        $cover = UploadedFile::fake()->image('cover.jpg', 600, 848);
        $payload = $this->metadata([
            'source_type' => 'link',
            'external_url' => 'https://drive.google.com/file/d/test1234/view',
            'cover_image' => $cover,
            'page_count' => 222,
        ]);
        $response = $this->post('/api/v1/learning/lesson-plans', $payload)->assertCreated();
        $id = $response->json('data.id');

        $row = DB::table('learning_lesson_plans')->find($id);
        $this->assertNull($row->pdf_path);
        $this->assertNotNull($row->cover_path);
        $this->assertSame(222, (int) $row->page_count);
        $this->get("/api/v1/learning/lesson-plans/{$id}/cover")->assertOk();
        $this->get("/api/v1/learning/lesson-plans/{$id}/file")->assertNotFound();

        // Now add a PDF with cover to test clear_pdf
        $pdfFile = UploadedFile::fake()->create('plan.pdf', 500, 'application/pdf');
        $this->post("/api/v1/learning/lesson-plans/{$id}", [
            '_method' => 'PATCH',
            'pdf' => $pdfFile,
            'cover_image' => UploadedFile::fake()->image('cover.jpg', 600, 848),
        ])->assertOk();
        $withPdf = DB::table('learning_lesson_plans')->find($id);
        $this->assertNotNull($withPdf->pdf_path);
        $this->assertNotNull($withPdf->cover_path);
        Storage::disk('local')->assertExists($withPdf->pdf_path);

        // Clear PDF to free disk space
        $this->post("/api/v1/learning/lesson-plans/{$id}", [
            '_method' => 'PATCH',
            'clear_pdf' => '1',
        ])->assertOk();
        $cleared = DB::table('learning_lesson_plans')->find($id);
        $this->assertNull($cleared->pdf_path);
        $this->assertNotNull($cleared->cover_path);
        Storage::disk('local')->assertMissing($withPdf->pdf_path);
    }

    public function test_plan_supports_word_upload_and_download_alongside_pdf(): void
    {
        $docxFile = UploadedFile::fake()->create('sample-lesson.docx', 350, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $pdfFile = UploadedFile::fake()->create('sample-lesson.pdf', 200, 'application/pdf');

        $payload = $this->metadata([
            'source_type' => 'pdf',
            'pdf' => $pdfFile,
            'word' => $docxFile,
        ]);
        $response = $this->post('/api/v1/learning/lesson-plans', $payload)->assertCreated();
        $id = $response->json('data.id');

        $row = DB::table('learning_lesson_plans')->find($id);
        $this->assertNotNull($row->pdf_path);
        $this->assertNotNull($row->word_path);
        $this->assertSame('sample-lesson.docx', $row->word_original_name);
        Storage::disk('local')->assertExists([$row->pdf_path, $row->word_path]);

        // Verify index response includes word metadata
        $index = $this->getJson('/api/v1/learning/lesson-plans')->assertOk();
        $item = collect($index->json('data'))->firstWhere('id', (string) $id);
        $this->assertNotNull($item['word_url']);
        $this->assertSame('sample-lesson.docx', $item['word_filename']);

        // Test downloading word file
        $wordDownload = $this->get("/api/v1/learning/lesson-plans/{$id}/word")->assertOk();
        $this->assertStringContainsString('sample-lesson.docx', $wordDownload->headers->get('Content-Disposition') ?? '');

        // Test clearing word file
        $this->post("/api/v1/learning/lesson-plans/{$id}", [
            '_method' => 'PATCH',
            'clear_word' => '1',
        ])->assertOk();

        $cleared = DB::table('learning_lesson_plans')->find($id);
        $this->assertNull($cleared->word_path);
        $this->assertNotNull($cleared->pdf_path);
        Storage::disk('local')->assertMissing($row->word_path);
        Storage::disk('local')->assertExists($row->pdf_path);
    }
}

