<?php

namespace Tests\Feature\Admin;

use App\Models\District;
use App\Models\PublicRelationsPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class PublicRelationsTest extends TestCase
{
    use RefreshDatabase;

    private District $district;

    private District $otherDistrict;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->district = District::query()->create([
            'name' => 'อำเภอเสนา',
            'code' => 'sena-public-relations',
            'is_active' => true,
        ]);
        $this->otherDistrict = District::query()->create([
            'name' => 'อำเภออื่น',
            'code' => 'other-public-relations',
            'is_active' => true,
        ]);
        $this->admin = User::factory()->create([
            'name' => 'ผู้ดูแล เสนา',
            'role' => 'admin',
            'district_id' => $this->district->id,
        ]);
    }

    public function test_admin_can_create_update_publish_and_delete_news_with_private_image(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);

        $created = $this->post('/api/v1/admin/public-relations', [
            'title' => 'เปิดรับสมัครนักศึกษาใหม่',
            'description' => 'สมัครได้ที่ศูนย์การเรียนประจำอำเภอ',
            'is_published' => '1',
            'image' => UploadedFile::fake()->image('news.jpg', 1280, 720)->size(1500),
        ], ['Accept' => 'application/json']);

        $created->assertCreated()
            ->assertJsonPath('data.title', 'เปิดรับสมัครนักศึกษาใหม่')
            ->assertJsonPath('data.is_published', true);

        $postId = (int) $created->json('data.id');
        $post = PublicRelationsPost::query()->findOrFail($postId);
        $this->assertNotNull($post->published_at);
        $this->assertNotNull($post->image_path);
        Storage::disk('local')->assertExists($post->image_path);

        $this->get("/api/v1/admin/public-relations/{$postId}/image", ['Accept' => 'image/jpeg'])
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        $originalImagePath = (string) $post->image_path;
        $this->post("/api/v1/admin/public-relations/{$postId}", [
            '_method' => 'PATCH',
            'title' => 'เปิดรับสมัครนักศึกษาใหม่ พร้อมกำหนดการ',
            'description' => 'อัปเดตรูปประกอบและรายละเอียดการสมัครแล้ว',
            'is_published' => '1',
            'image' => UploadedFile::fake()->image('updated-news.webp', 1280, 720)->size(1200),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.title', 'เปิดรับสมัครนักศึกษาใหม่ พร้อมกำหนดการ');

        $post->refresh();
        $this->assertNotSame($originalImagePath, $post->image_path);
        Storage::disk('local')->assertMissing($originalImagePath);
        Storage::disk('local')->assertExists((string) $post->image_path);

        $this->patchJson("/api/v1/admin/public-relations/{$postId}/status", ['is_published' => false])
            ->assertOk()
            ->assertJsonPath('data.is_published', false);

        $this->patchJson("/api/v1/admin/public-relations/{$postId}", [
            'title' => 'เปิดรับสมัครนักศึกษาใหม่ รอบเพิ่มเติม',
            'description' => 'อัปเดตรายละเอียดการสมัครแล้ว',
            'is_published' => true,
        ])->assertOk()
            ->assertJsonPath('data.title', 'เปิดรับสมัครนักศึกษาใหม่ รอบเพิ่มเติม')
            ->assertJsonPath('data.is_published', true);

        $imagePath = (string) $post->fresh()->image_path;
        $this->deleteJson("/api/v1/admin/public-relations/{$postId}")
            ->assertOk()
            ->assertJsonPath('data.id', $postId);

        $this->assertDatabaseMissing('public_relations_posts', ['id' => $postId]);
        Storage::disk('local')->assertMissing($imagePath);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'admin.public_relations.deleted',
            'auditable_type' => 'public_relations_post',
            'auditable_id' => $postId,
            'district_id' => $this->district->id,
        ]);
    }

    public function test_students_only_see_published_news_from_their_district(): void
    {
        $published = $this->createPost($this->district, true, 'ข่าวที่เผยแพร่');
        $this->createPost($this->district, false, 'ฉบับร่าง');
        $this->createPost($this->otherDistrict, true, 'ข่าวต่างอำเภอ');

        $student = User::factory()->create(['role' => 'student', 'district_id' => $this->district->id]);
        Sanctum::actingAs($student);

        $this->getJson('/api/v1/public-relations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $published->id)
            ->assertJsonMissing(['title' => 'ฉบับร่าง'])
            ->assertJsonMissing(['title' => 'ข่าวต่างอำเภอ']);

        $teacher = User::factory()->create(['role' => 'teacher', 'district_id' => $this->district->id]);
        Sanctum::actingAs($teacher);
        $this->getJson('/api/v1/public-relations')->assertForbidden();
        $this->getJson('/api/v1/admin/public-relations')->assertForbidden();
    }

    public function test_admin_cannot_read_or_change_news_from_another_district(): void
    {
        $otherPost = $this->createPost($this->otherDistrict, true, 'ข่าวต่างอำเภอ');
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/admin/public-relations')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->patchJson("/api/v1/admin/public-relations/{$otherPost->id}/status", ['is_published' => false])
            ->assertNotFound();
        $this->deleteJson("/api/v1/admin/public-relations/{$otherPost->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('public_relations_posts', [
            'id' => $otherPost->id,
            'is_published' => true,
        ]);
    }

    public function test_news_validation_rejects_missing_content_and_unsafe_images(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/admin/public-relations', [
            'title' => '',
            'description' => '',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'description']);

        $this->post('/api/v1/admin/public-relations', [
            'title' => 'ไฟล์ไม่ถูกต้อง',
            'description' => 'ระบบต้องปฏิเสธไฟล์ข้อความ',
            'image' => UploadedFile::fake()->create('payload.txt', 20, 'text/plain'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');
    }

    private function createPost(District $district, bool $published, string $title): PublicRelationsPost
    {
        $creator = User::factory()->create([
            'role' => 'admin',
            'district_id' => $district->id,
        ]);

        return PublicRelationsPost::query()->create([
            'district_id' => $district->id,
            'created_by' => $creator->id,
            'title' => $title,
            'description' => "รายละเอียด {$title}",
            'is_published' => $published,
            'published_at' => $published ? now() : null,
        ]);
    }
}
