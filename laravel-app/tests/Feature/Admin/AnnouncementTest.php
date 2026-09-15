<?php

namespace Tests\Feature\Admin;

use App\Models\Announcement;
use App\Models\District;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AnnouncementTest extends TestCase
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
            'code' => 'sena-announcement',
            'is_active' => true,
        ]);
        $this->otherDistrict = District::query()->create([
            'name' => 'อำเภออื่น',
            'code' => 'other-announcement',
            'is_active' => true,
        ]);
        $this->admin = User::factory()->create([
            'name' => 'ผู้ดูแล เสนา',
            'role' => 'admin',
            'district_id' => $this->district->id,
        ]);
    }

    public function test_district_admin_can_create_edit_and_switch_the_single_active_announcement(): void
    {
        Sanctum::actingAs($this->admin);

        $first = $this->postJson('/api/v1/admin/announcements', [
            'title' => 'ประกาศแรก',
            'message' => "ข้อความบรรทัดแรก\nข้อความบรรทัดสอง",
            'button_label' => 'ดูรายละเอียด',
            'button_url' => 'https://example.com/first',
            'is_active' => true,
        ])->assertCreated()
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.created_by_name', 'ผู้ดูแล เสนา');

        $firstId = (int) $first->json('data.id');
        $second = $this->postJson('/api/v1/admin/announcements', [
            'title' => 'ประกาศที่สอง',
            'message' => 'รายการนี้จะแทนประกาศแรก',
            'button_label' => '',
            'button_url' => '',
            'is_active' => true,
        ])->assertCreated()->assertJsonPath('data.is_active', true);
        $secondId = (int) $second->json('data.id');

        $this->assertDatabaseHas('announcements', ['id' => $firstId, 'is_active' => false]);
        $this->assertDatabaseHas('announcements', ['id' => $secondId, 'is_active' => true]);
        $this->assertSame(1, Announcement::query()->where('district_id', $this->district->id)->where('is_active', true)->count());

        $this->patchJson("/api/v1/admin/announcements/{$secondId}", [
            'title' => 'ประกาศที่สอง (แก้ไข)',
            'message' => 'แก้ไขข้อความแล้ว',
            'button_label' => 'เปิดเอกสาร',
            'button_url' => 'https://example.com/updated',
            'is_active' => true,
        ])->assertOk()
            ->assertJsonPath('data.title', 'ประกาศที่สอง (แก้ไข)')
            ->assertJsonPath('data.button_label', 'เปิดเอกสาร');

        $this->patchJson("/api/v1/admin/announcements/{$secondId}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->getJson('/api/v1/admin/announcements')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.active_count', 0);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'admin.announcement.created',
            'auditable_type' => 'system_announcement',
            'auditable_id' => $firstId,
            'district_id' => $this->district->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'admin.announcement.deactivated',
            'auditable_id' => $secondId,
        ]);
    }

    public function test_admin_cannot_read_or_change_an_announcement_from_another_district(): void
    {
        $otherAdmin = User::factory()->create([
            'name' => 'ผู้ดูแล อื่น',
            'role' => 'admin',
            'district_id' => $this->otherDistrict->id,
        ]);
        $otherAnnouncement = Announcement::query()->create([
            'district_id' => $this->otherDistrict->id,
            'created_by' => $otherAdmin->id,
            'title' => 'ประกาศของอำเภออื่น',
            'message' => 'ข้อมูลที่ห้ามข้ามอำเภอ',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/admin/announcements')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonMissing(['title' => 'ประกาศของอำเภออื่น']);
        $this->patchJson("/api/v1/admin/announcements/{$otherAnnouncement->id}", [
            'title' => 'ห้ามแก้',
            'message' => 'ห้ามแก้',
            'is_active' => false,
        ])->assertNotFound();
        $this->patchJson("/api/v1/admin/announcements/{$otherAnnouncement->id}/status", ['is_active' => false])
            ->assertNotFound();

        $this->assertDatabaseHas('announcements', [
            'id' => $otherAnnouncement->id,
            'title' => 'ประกาศของอำเภออื่น',
            'is_active' => true,
        ]);
    }

    public function test_only_students_can_read_the_active_announcement_for_their_district(): void
    {
        Announcement::query()->create([
            'district_id' => $this->district->id,
            'created_by' => $this->admin->id,
            'title' => 'ประกาศที่ปิด',
            'message' => 'ไม่ควรแสดง',
            'is_active' => false,
        ]);
        $active = Announcement::query()->create([
            'district_id' => $this->district->id,
            'created_by' => $this->admin->id,
            'title' => 'ประกาศสำหรับนักศึกษา',
            'message' => 'แสดงเฉพาะนักศึกษาอำเภอเสนา',
            'button_label' => 'ดูข้อมูล',
            'button_url' => 'https://example.com/student',
            'is_active' => true,
        ]);

        $student = User::factory()->create(['role' => 'student', 'district_id' => $this->district->id]);
        Sanctum::actingAs($student);
        $this->getJson('/api/v1/student/announcements/active')
            ->assertOk()
            ->assertJsonPath('data.id', $active->id)
            ->assertJsonPath('data.title', 'ประกาศสำหรับนักศึกษา')
            ->assertJsonPath('data.button_url', 'https://example.com/student')
            ->assertJsonMissingPath('data.created_by_name');

        $otherStudent = User::factory()->create(['role' => 'student', 'district_id' => $this->otherDistrict->id]);
        Sanctum::actingAs($otherStudent);
        $this->getJson('/api/v1/student/announcements/active')
            ->assertOk()
            ->assertJsonPath('data', null);

        $teacher = User::factory()->create(['role' => 'teacher', 'district_id' => $this->district->id]);
        Sanctum::actingAs($teacher);
        $this->getJson('/api/v1/student/announcements/active')->assertForbidden();
        $this->getJson('/api/v1/admin/announcements')->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/student/announcements/active')->assertForbidden();

        $superAdmin = User::factory()->create(['role' => 'super_admin', 'district_id' => null]);
        Sanctum::actingAs($superAdmin);
        $this->withHeader('X-District-Id', (string) $this->district->id)
            ->getJson('/api/v1/admin/announcements')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_admin_can_delete_announcement(): void
    {
        Sanctum::actingAs($this->admin);

        $announcement = Announcement::query()->create([
            'district_id' => $this->district->id,
            'created_by' => $this->admin->id,
            'title' => 'ประกาศที่จะลบ',
            'message' => 'เนื้อหาที่จะลบ',
            'is_active' => true,
        ]);

        $this->deleteJson("/api/v1/admin/announcements/{$announcement->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $announcement->id);

        $this->assertDatabaseMissing('announcements', ['id' => $announcement->id]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'admin.announcement.deleted',
            'auditable_id' => $announcement->id,
        ]);
    }

    public function test_announcement_validation_rejects_unsafe_links_and_missing_content(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/admin/announcements', [
            'title' => '',
            'message' => '',
            'button_label' => 'คลิก',
            'button_url' => 'javascript:alert(1)',
            'is_active' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'message', 'button_url']);

        $this->postJson('/api/v1/admin/announcements', [
            'title' => 'มีลิงก์แต่ไม่มีชื่อปุ่ม',
            'message' => 'ทดสอบ validation',
            'button_label' => '',
            'button_url' => 'https://example.com',
            'is_active' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('button_label');
    }
}
