<?php

namespace Tests\Feature\Learning;

use App\Models\District;
use App\Models\User;
use App\Services\Learning\ClassroomLibraryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ClassroomLibraryTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private District $district;

    private const BASE = '/api/v1/learning/classroom';

    protected function setUp(): void
    {
        parent::setUp();
        config(['system_data.enabled' => true, 'system_data.student_enabled' => false, 'system_data.write_enabled' => true]);
        Storage::fake('local');
        $this->district = District::create(['name' => 'Synthetic district', 'code' => 'sena', 'is_active' => true]);
        $this->teacher = User::factory()->create(['role' => 'teacher', 'district_id' => $this->district->id, 'assigned_groups' => ['SENA-M3-A']]);
        $this->actingAs($this->teacher);
    }

    private function oldUnit(): int
    {
        return DB::table('learning_classroom_units')->insertGetId(['district_id' => $this->district->id, 'created_by' => $this->teacher->id,
            'academic_term' => '1/2560', 'subject_code' => 'OLD-NOT-REGISTERED', 'subject_name' => 'Old course', 'education_level' => 3,
            'target_group' => 'SENA-M3-A', 'audience_groups' => '["SENA-M3-A"]', 'title' => 'Reusable chapter', 'description' => 'Keep this', 'status' => 'published']);
    }

    public function test_historical_lesson_snapshot_restores_content_and_independent_files_without_student_evidence(): void
    {
        $unit = $this->oldUnit();
        $activity = DB::table('learning_classroom_activities')->insertGetId(['district_id' => $this->district->id, 'created_by' => $this->teacher->id,
            'academic_term' => '1/2560', 'subject_code' => 'OLD-NOT-REGISTERED', 'subject_name' => 'Old course', 'education_level' => 3,
            'classroom_unit_id' => $unit, 'target_group' => 'SENA-M3-A', 'audience_groups' => '["SENA-M3-A"]', 'title' => 'Old quiz', 'type' => 'quiz', 'links' => '[]', 'questions' => '[]', 'status' => 'published', 'due_at' => now()->subYear()]);
        Storage::disk('local')->put('original.png', 'image-fixture');
        $image = DB::table('learning_classroom_images')->insertGetId(['district_id' => $this->district->id, 'created_by' => $this->teacher->id, 'activity_id' => $activity, 'path' => 'original.png', 'mime' => 'image/png']);
        DB::table('learning_classroom_activities')->where('id', $activity)->update(['questions' => json_encode([['id' => 'q1', 'type' => 'true_false', 'prompt' => 'True?', 'answer' => 'true', 'points' => 1, 'options' => [], 'image_id' => (string) $image]])]);
        $assignment = DB::table('learning_assignments')->insertGetId(['district_id' => $this->district->id, 'created_by' => $this->teacher->id, 'title' => 'File worksheet', 'subject_code' => 'OLD-NOT-REGISTERED', 'education_level' => 3, 'classroom_unit_id' => $unit,
            'target_type' => 'group', 'target_value' => 'SENA-M3-A', 'material_path' => 'original.png', 'material_disk' => 'local', 'status' => 'open']);
        DB::table('learning_resources')->insert(['district_id' => $this->district->id, 'uploaded_by' => $this->teacher->id, 'title' => 'Document', 'subject_code' => 'OLD-NOT-REGISTERED', 'education_level' => 3,
            'classroom_unit_id' => $unit, 'target_group' => 'SENA-M3-A', 'storage_path' => 'original.png', 'storage_disk' => 'local', 'resource_type' => 'file']);
        $library = app(ClassroomLibraryService::class);
        $saved = $library->save($this->teacher, $this->district->id, $unit);
        $this->assertSame(3, DB::table('learning_classroom_templates')->where('id', $saved)->value('content_count'));
        $this->assertCount(1, $library->listing($this->teacher, $this->district->id, [], true)['units']);
        Storage::disk('local')->delete('original.png');
        DB::table('learning_classroom_activities')->where('id', $activity)->delete();
        DB::table('learning_classroom_images')->where('id', $image)->delete();
        DB::table('learning_assignments')->where('id', $assignment)->delete();
        $restored = $library->restore($this->teacher, $this->district->id, $saved, ['subject_code' => 'พว31001', 'education_level' => 3, 'target_group' => 'SENA-M3-A']);
        $newUnit = DB::table('learning_classroom_units')->where('id', $restored)->first();
        $this->assertSame('draft', $newUnit->status);
        $this->assertNotSame('1/2560', $newUnit->academic_term);
        $new = DB::table('learning_classroom_activities')->where('classroom_unit_id', $restored)->first();
        $this->assertSame('พว31001', $new->subject_code);
        $this->assertSame('draft', $new->status);
        $this->assertNull($new->due_at);
        $question = json_decode($new->questions, true)[0];
        $this->assertArrayNotHasKey('library_image', $question);
        $this->assertNotSame((string) $image, $question['image_id']);
        $newImage = DB::table('learning_classroom_images')->where('id', $question['image_id'])->first();
        $this->assertSame('image-fixture', Storage::disk('local')->get($newImage->path));
        $newAssignment = DB::table('learning_assignments')->where('classroom_unit_id', $restored)->first();
        $this->assertSame('draft', $newAssignment->status);
        $this->assertSame('image-fixture', Storage::disk('local')->get($newAssignment->material_path));
        $newResource = DB::table('learning_resources')->where('classroom_unit_id', $restored)->first();
        $this->assertSame('image-fixture', Storage::disk('local')->get($newResource->storage_path));
        $library->delete($this->teacher, $this->district->id, $saved);
        Storage::disk('local')->assertExists($newImage->path);
        Storage::disk('local')->assertExists($newAssignment->material_path);
        $this->get('/api/v1/learning/resources/'.$newResource->id.'/file')->assertOk();
        $this->get('/api/v1/learning/assignments/'.$newAssignment->id.'/material')->assertOk();
        $this->get(self::BASE.'/images/'.$newImage->id)->assertOk();
        $this->assertDatabaseCount('learning_classroom_submissions', 0);
        $this->assertDatabaseCount('learning_classroom_tasks', 0);
    }

    public function test_cross_owner_student_and_district_access_are_denied_and_restore_requires_registered_course(): void
    {
        $library = app(ClassroomLibraryService::class);
        $unit = $this->oldUnit();
        $template = $library->save($this->teacher, $this->district->id, $unit);
        $other = User::factory()->create(['role' => 'teacher', 'district_id' => $this->district->id, 'assigned_groups' => ['SENA-M3-A']]);
        $this->actingAs($other);
        $this->getJson(self::BASE.'/library')->assertOk()->assertJsonCount(0, 'data.templates');
        $this->postJson(self::BASE.'/library', ['unit_id' => $unit])->assertNotFound();
        $this->deleteJson(self::BASE.'/library/'.$template)->assertNotFound();
        $this->postJson(self::BASE.'/library/'.$template.'/restore', ['subject_code' => 'พว31001', 'education_level' => 3, 'target_group' => 'SENA-M3-A'])->assertNotFound();
        $this->actingAs($this->teacher);
        $this->postJson(self::BASE.'/library/'.$template.'/restore', ['subject_code' => 'NOT-REGISTERED', 'education_level' => 3, 'target_group' => 'SENA-M3-A'])->assertNotFound();
        $this->teacher->assigned_groups = ['SENA-M3-B'];
        $this->teacher->save();
        $this->getJson(self::BASE.'/library/sources')->assertOk()->assertJsonCount(0, 'data.units');
        $this->postJson(self::BASE.'/library', ['unit_id' => $unit])->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'student', 'district_id' => $this->district->id, 'student_code' => '6650300005']));
        $this->getJson(self::BASE.'/library')->assertForbidden();
        $this->postJson(self::BASE.'/library', ['unit_id' => $unit])->assertForbidden();
        $otherDistrict = District::create(['name' => 'Other', 'code' => 'other', 'is_active' => true]);
        $this->actingAs(User::factory()->create(['role' => 'admin', 'district_id' => $otherDistrict->id]));
        $this->getJson(self::BASE.'/library')->assertOk()->assertJsonCount(0, 'data.templates');
        $this->deleteJson(self::BASE.'/library/'.$template)->assertNotFound();
    }

    public function test_missing_source_file_rolls_back_snapshot_and_copies(): void
    {
        $unit = $this->oldUnit();
        DB::table('learning_resources')->insert(['district_id' => $this->district->id, 'uploaded_by' => $this->teacher->id, 'title' => 'Missing file', 'subject_code' => 'OLD-NOT-REGISTERED', 'education_level' => 3,
            'classroom_unit_id' => $unit, 'target_group' => 'SENA-M3-A', 'storage_path' => 'missing.pdf', 'storage_disk' => 'local', 'resource_type' => 'file']);
        $this->postJson(self::BASE.'/library', ['unit_id' => $unit])->assertUnprocessable();
        $this->assertDatabaseCount('learning_classroom_templates', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('classroom-library'));
    }

    public function test_read_only_blocks_writes_and_listing_never_discloses_snapshot(): void
    {
        $unit = $this->oldUnit();
        $template = app(ClassroomLibraryService::class)->save($this->teacher, $this->district->id, $unit);
        $this->getJson(self::BASE.'/library')->assertOk()->assertJsonMissingPath('data.templates.0.snapshot');
        config(['system_data.write_enabled' => false]);
        $this->postJson(self::BASE.'/library', ['unit_id' => $unit])->assertStatus(503);
        $this->deleteJson(self::BASE.'/library/'.$template)->assertStatus(503);
        $this->postJson(self::BASE.'/library/'.$template.'/restore', ['subject_code' => 'พว31001', 'education_level' => 3, 'target_group' => 'SENA-M3-A'])->assertStatus(503);
        $this->assertDatabaseCount('learning_classroom_templates', 1);
    }

    public function test_restore_missing_file_rolls_back_new_chapter_and_migration_is_repeatable(): void
    {
        $unit = $this->oldUnit();
        Storage::disk('local')->put('file.pdf', 'pdf-fixture');
        DB::table('learning_resources')->insert(['district_id' => $this->district->id, 'uploaded_by' => $this->teacher->id, 'title' => 'File', 'subject_code' => 'OLD-NOT-REGISTERED', 'education_level' => 3,
            'classroom_unit_id' => $unit, 'target_group' => 'SENA-M3-A', 'storage_path' => 'file.pdf', 'storage_disk' => 'local', 'resource_type' => 'file']);
        $template = app(ClassroomLibraryService::class)->save($this->teacher, $this->district->id, $unit);
        $snapshot = json_decode(DB::table('learning_classroom_templates')->where('id', $template)->value('snapshot'), true);
        Storage::disk('local')->delete($snapshot['resources'][0]['storage_path']);
        $this->postJson(self::BASE.'/library/'.$template.'/restore', ['subject_code' => 'พว31001', 'education_level' => 3, 'target_group' => 'SENA-M3-A'])->assertUnprocessable();
        $this->assertDatabaseCount('learning_classroom_units', 1);
        $this->assertDatabaseCount('learning_resources', 1);
        $this->assertSame([], Storage::disk('local')->allFiles('classroom-restored'));
        $migration = require database_path('migrations/2026_11_15_000045_create_classroom_library.php');
        $migration->up();
        $migration->down();
        $this->assertDatabaseCount('learning_classroom_templates', 1);
    }

    public function test_virtual_legacy_sources_save_old_unbound_content_without_current_registration(): void
    {
        $activity = ['district_id' => $this->district->id, 'created_by' => $this->teacher->id, 'academic_term' => '1/2560',
            'subject_code' => 'OLD-NOT-REGISTERED', 'subject_name' => 'Legacy course', 'education_level' => 3,
            'target_group' => 'SENA-M3-A', 'audience_groups' => '["SENA-M3-A"]', 'title' => 'Legacy video', 'type' => 'video',
            'links' => '[{"title":"Video","url":"https://example.test/old"}]', 'questions' => '[]', 'status' => 'published'];
        DB::table('learning_classroom_activities')->insert($activity);
        DB::table('learning_classroom_activities')->insert([...$activity, 'target_group' => 'SENA-M3-B']);
        DB::table('learning_classroom_activities')->insert([...$activity, 'created_by' => $this->teacher->id + 100]);
        DB::table('learning_classroom_activities')->insert([...$activity, 'district_id' => $this->district->id + 100]);
        DB::table('learning_assignments')->insert(['district_id' => $this->district->id, 'created_by' => $this->teacher->id,
            'academic_term' => '1/2560', 'subject_code' => 'OLD-NOT-REGISTERED', 'subject_name' => 'Legacy course', 'education_level' => 3,
            'title' => 'Legacy worksheet', 'target_type' => 'group', 'target_value' => 'SENA-M3-A', 'status' => 'open']);
        $source = $this->getJson(self::BASE.'/library/sources?search=OLD-NOT-REGISTERED')->assertOk()
            ->assertJsonCount(1, 'data.units')->json('data.units.0.id');
        $this->assertStringStartsWith('legacy:', $source);
        $forged = 'legacy:'.rtrim(strtr(base64_encode(json_encode(['1/2560', 'OLD-NOT-REGISTERED', 3, 'SENA-M3-B'])), '+/', '-_'), '=');
        $this->postJson(self::BASE.'/library', ['unit_id' => $forged])->assertNotFound();
        $template = $this->postJson(self::BASE.'/library', ['unit_id' => $source])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('learning_classroom_templates', ['id' => $template, 'content_count' => 2]);
        $this->postJson(self::BASE.'/library/'.$template.'/restore', ['subject_code' => 'พว31001', 'education_level' => 3, 'target_group' => 'SENA-M3-A'])->assertCreated();
        $this->assertDatabaseCount('learning_classroom_submissions', 0);
        $this->assertDatabaseCount('learning_classroom_tasks', 0);
        $this->teacher->update(['assigned_groups' => ['SENA-M3-B']]);
        $this->postJson(self::BASE.'/library', ['unit_id' => $source])->assertNotFound();
        $this->postJson(self::BASE.'/library', ['unit_id' => 'legacy:notvalid'])->assertNotFound();
    }

    public function test_media_only_unbound_course_has_a_reusable_source_with_independent_private_file(): void
    {
        Storage::disk('local')->put('old-resource.pdf', 'only-media');
        $resource = ['district_id' => $this->district->id, 'uploaded_by' => $this->teacher->id, 'title' => 'Old independent PDF',
            'subject_code' => 'MEDIA-ONLY', 'education_level' => 3, 'target_group' => 'SENA-M3-A',
            'resource_type' => 'file', 'storage_path' => 'old-resource.pdf', 'storage_disk' => 'local'];
        DB::table('learning_resources')->insert($resource);
        DB::table('learning_resources')->insert([...$resource, 'uploaded_by' => User::factory()->create(['role' => 'teacher', 'district_id' => $this->district->id])->id]);
        $source = $this->getJson(self::BASE.'/library/sources?search=MEDIA-ONLY')->assertOk()
            ->assertJsonCount(1, 'data.units')->json('data.units.0.id');
        $template = $this->postJson(self::BASE.'/library', ['unit_id' => $source])->assertCreated()->json('data.id');
        Storage::disk('local')->delete('old-resource.pdf');
        $unit = $this->postJson(self::BASE.'/library/'.$template.'/restore', ['subject_code' => 'พว31001', 'education_level' => 3, 'target_group' => 'SENA-M3-A'])
            ->assertCreated()->json('data.unit_id');
        $this->assertDatabaseHas('learning_classroom_templates', ['id' => $template, 'content_count' => 1]);
        $restored = DB::table('learning_resources')->where('classroom_unit_id', $unit)->first();
        $this->get('/api/v1/learning/resources/'.$restored->id.'/file')->assertOk();
        $this->assertSame('only-media', Storage::disk('local')->get($restored->storage_path));
    }

    public function test_numeric_unit_ids_accept_both_json_numbers_and_strings_above_1024(): void
    {
        $unit = $this->oldUnit();
        DB::table('learning_classroom_units')->where('id', $unit)->update(['id' => 12000]);
        $this->postJson(self::BASE.'/library', ['unit_id' => 12000])->assertCreated();
        $this->postJson(self::BASE.'/library', ['unit_id' => '12000'])->assertCreated();
    }
}
