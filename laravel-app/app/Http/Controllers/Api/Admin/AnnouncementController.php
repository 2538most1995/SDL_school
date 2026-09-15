<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class AnnouncementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $districtId = $this->districtId($request);
        $announcements = Announcement::query()
            ->with('creator:id,name')
            ->where('district_id', $districtId)
            ->orderByDesc('is_active')
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get()
            ->map(fn (Announcement $announcement): array => $this->payload($announcement, $request))
            ->values();

        return response()->json([
            'data' => $announcements,
            'meta' => [
                'source' => 'system_database',
                'active_count' => $announcements->where('is_active', true)->count(),
                'limit' => 100,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $districtId = $this->districtId($request);
        $validated = $this->validatedPayload($request);
        $imageFile = $this->validatedImage($request);

        $announcement = DB::transaction(function () use ($districtId, $request, $validated): Announcement {
            $this->lockDistrict($districtId);

            if ($validated['is_active']) {
                Announcement::query()
                    ->where('district_id', $districtId)
                    ->where('is_active', true)
                    ->update(['is_active' => false, 'updated_at' => now()]);
            }

            $announcement = Announcement::query()->create([
                'district_id' => $districtId,
                'created_by' => (int) $request->user()->id,
                ...$validated,
            ]);
            $this->audit($request, $announcement, 'admin.announcement.created');

            return $announcement;
        });

        if ($imageFile !== null) {
            $this->storeImage($announcement, $imageFile);
        }

        return response()->json([
            'data' => $this->payload($announcement->load('creator:id,name'), $request),
            'meta' => ['source' => 'system_database'],
        ], 201);
    }

    public function update(Request $request, int $announcement): JsonResponse
    {
        $districtId = $this->districtId($request);
        $validated = $this->validatedPayload($request);
        $imageFile = $this->validatedImage($request);
        $removeImage = filter_var($request->input('remove_image'), FILTER_VALIDATE_BOOLEAN);

        $updated = DB::transaction(function () use ($announcement, $districtId, $request, $validated): Announcement {
            $this->lockDistrict($districtId);
            $model = $this->scopedAnnouncement($districtId, $announcement, true);
            $before = $this->auditPayload($model);

            if ($validated['is_active']) {
                Announcement::query()
                    ->where('district_id', $districtId)
                    ->whereKeyNot($model->id)
                    ->where('is_active', true)
                    ->update(['is_active' => false, 'updated_at' => now()]);
            }

            $model->fill($validated)->save();
            $this->audit($request, $model, 'admin.announcement.updated', $before);

            return $model;
        });

        if ($removeImage && ! $imageFile) {
            $this->deleteImage($updated);
        }

        if ($imageFile !== null) {
            $this->deleteImage($updated);
            $this->storeImage($updated, $imageFile);
        }

        return response()->json([
            'data' => $this->payload($updated->load('creator:id,name'), $request),
            'meta' => ['source' => 'system_database'],
        ]);
    }

    public function updateStatus(Request $request, int $announcement): JsonResponse
    {
        $districtId = $this->districtId($request);
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $updated = DB::transaction(function () use ($announcement, $districtId, $request, $validated): Announcement {
            $this->lockDistrict($districtId);
            $model = $this->scopedAnnouncement($districtId, $announcement, true);
            $before = $this->auditPayload($model);
            $isActive = (bool) $validated['is_active'];

            if ($isActive) {
                Announcement::query()
                    ->where('district_id', $districtId)
                    ->whereKeyNot($model->id)
                    ->where('is_active', true)
                    ->update(['is_active' => false, 'updated_at' => now()]);
            }

            $model->update(['is_active' => $isActive]);
            $this->audit(
                $request,
                $model,
                $isActive ? 'admin.announcement.activated' : 'admin.announcement.deactivated',
                $before,
            );

            return $model;
        });

        return response()->json([
            'data' => $this->payload($updated->load('creator:id,name'), $request),
            'meta' => ['source' => 'system_database'],
        ]);
    }

    public function destroy(Request $request, int $announcement): JsonResponse
    {
        $districtId = $this->districtId($request);

        $deleted = DB::transaction(function () use ($announcement, $districtId, $request): Announcement {
            $this->lockDistrict($districtId);
            $model = $this->scopedAnnouncement($districtId, $announcement, true);
            $before = $this->auditPayload($model);

            $model->delete();
            $this->audit($request, $model, 'admin.announcement.deleted', $before);

            return $model;
        });

        $this->deleteImage($deleted);

        return response()->json([
            'data' => ['id' => (int) $deleted->id],
            'meta' => ['source' => 'system_database'],
        ]);
    }

    public function image(Request $request, int $announcement): Response
    {
        $districtId = $this->districtId($request);
        $model = $this->scopedAnnouncement($districtId, $announcement);

        abort_unless(filled($model->image_path) && Storage::disk('local')->exists($model->image_path), 404, 'ไม่พบรูปภาพประกาศ');

        $mime = Storage::disk('local')->mimeType($model->image_path) ?: 'image/jpeg';

        return response(Storage::disk('local')->get($model->image_path), 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array{title: string, message: string, button_label: string|null, button_url: string|null, show_exam_link: bool, is_active: bool} */
    private function validatedPayload(Request $request): array
    {
        $request->merge([
            'title' => trim((string) $request->input('title')),
            'message' => trim((string) $request->input('message')),
            'button_label' => trim((string) $request->input('button_label')),
            'button_url' => trim((string) $request->input('button_url')),
        ]);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'message' => ['required', 'string', 'max:4000'],
            'button_label' => ['nullable', 'string', 'max:60', 'required_with:button_url'],
            'button_url' => ['nullable', 'string', 'max:2048', 'url:http,https'],
            'show_exam_link' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'title.required' => 'กรุณาระบุหัวข้อประกาศ',
            'message.required' => 'กรุณาระบุข้อความประกาศ',
            'button_label.required_with' => 'กรุณาระบุข้อความบนปุ่มลิงก์',
            'button_url.url' => 'ลิงก์ต้องขึ้นต้นด้วย http:// หรือ https:// เท่านั้น',
        ]);

        $buttonUrl = filled($validated['button_url'] ?? null) ? (string) $validated['button_url'] : null;

        return [
            'title' => (string) $validated['title'],
            'message' => (string) $validated['message'],
            'button_label' => $buttonUrl === null ? null : (string) $validated['button_label'],
            'button_url' => $buttonUrl,
            'show_exam_link' => (bool) ($validated['show_exam_link'] ?? false),
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ];
    }

    /** @return \Illuminate\Http\UploadedFile|null */
    private function validatedImage(Request $request): ?\Illuminate\Http\UploadedFile
    {
        if (! $request->hasFile('image')) {
            return null;
        }

        $validated = $request->validate([
            'image' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:min_width=200,min_height=100,max_width=6000,max_height=6000'],
        ], [
            'image.image' => 'ไฟล์ต้องเป็นรูปภาพเท่านั้น',
            'image.mimes' => 'รองรับเฉพาะไฟล์ JPG, PNG หรือ WebP',
            'image.max' => 'ขนาดรูปภาพต้องไม่เกิน 4 MB',
            'image.dimensions' => 'ขนาดรูปภาพต้องไม่ต่ำกว่า 200×100 px และไม่เกิน 6000×6000 px',
        ]);

        return $validated['image'];
    }

    private function storeImage(Announcement $announcement, \Illuminate\Http\UploadedFile $file): void
    {
        $path = $file->store("announcements/districts/{$announcement->district_id}", 'local');

        abort_if($path === false, 500, 'ไม่สามารถบันทึกรูปภาพประกาศได้');

        $announcement->update(['image_path' => $path]);
    }

    private function deleteImage(Announcement $announcement): void
    {
        $path = (string) $announcement->image_path;

        if ($path === '' || ! str_starts_with($path, 'announcements/')) {
            return;
        }

        Storage::disk('local')->delete($path);
        $announcement->updateQuietly(['image_path' => null]);
    }

    private function districtId(Request $request): int
    {
        return (int) $request->attributes->get('district_id');
    }

    private function lockDistrict(int $districtId): void
    {
        abort_unless(
            DB::table('districts')->where('id', $districtId)->lockForUpdate()->first(['id']) !== null,
            404,
            'ไม่พบอำเภอที่เปิดใช้งาน',
        );
    }

    private function scopedAnnouncement(int $districtId, int $announcementId, bool $lock = false): Announcement
    {
        $query = Announcement::query()
            ->where('district_id', $districtId)
            ->whereKey($announcementId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    /** @return array{id: int, title: string, message: string, button_label: string|null, button_url: string|null, image_url: string|null, show_exam_link: bool, is_active: bool, created_by_name: string|null, created_at: string|null, updated_at: string|null} */
    private function payload(Announcement $announcement, Request $request): array
    {
        $imageUrl = null;
        if (filled($announcement->image_path)) {
            $basePath = rtrim((string) $request->getBasePath(), '/');
            $imageUrl = "{$basePath}/api/v1/admin/announcements/{$announcement->id}/image";
        }

        return [
            'id' => (int) $announcement->id,
            'title' => (string) $announcement->title,
            'message' => (string) $announcement->message,
            'button_label' => filled($announcement->button_label) ? (string) $announcement->button_label : null,
            'button_url' => filled($announcement->button_url) ? (string) $announcement->button_url : null,
            'image_url' => $imageUrl,
            'show_exam_link' => (bool) $announcement->show_exam_link,
            'is_active' => (bool) $announcement->is_active,
            'created_by_name' => $announcement->creator?->displayName(),
            'created_at' => $announcement->created_at?->toIso8601String(),
            'updated_at' => $announcement->updated_at?->toIso8601String(),
        ];
    }

    /** @param array<string, mixed>|null $before */
    private function audit(Request $request, Announcement $announcement, string $event, ?array $before = null): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => (int) $request->user()->id,
            'district_id' => (int) $announcement->district_id,
            'event' => $event,
            'auditable_type' => 'system_announcement',
            'auditable_id' => (int) $announcement->id,
            'ip_address' => $request->ip(),
            'before' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'after' => json_encode($this->auditPayload($announcement), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function auditPayload(Announcement $announcement): array
    {
        return [
            'id' => (int) $announcement->id,
            'title' => (string) $announcement->title,
            'message' => (string) $announcement->message,
            'button_label' => filled($announcement->button_label) ? (string) $announcement->button_label : null,
            'button_url' => filled($announcement->button_url) ? (string) $announcement->button_url : null,
            'image_path' => filled($announcement->image_path) ? (string) $announcement->image_path : null,
            'show_exam_link' => (bool) $announcement->show_exam_link,
            'is_active' => (bool) $announcement->is_active,
        ];
    }
}
