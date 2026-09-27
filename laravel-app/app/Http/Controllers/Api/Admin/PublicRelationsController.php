<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PublicRelationsPost;
use App\Support\ApplicationBasePath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class PublicRelationsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $posts = PublicRelationsPost::query()
            ->with('creator:id,name,first_name,last_name')
            ->where('district_id', $this->districtId($request))
            ->orderByDesc('is_published')
            ->orderByDesc('published_at')
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get()
            ->map(fn (PublicRelationsPost $post): array => $this->payload($post, $request))
            ->values();

        return response()->json([
            'data' => $posts,
            'meta' => [
                'source' => 'system_database',
                'published_count' => $posts->where('is_published', true)->count(),
                'limit' => 100,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $districtId = $this->districtId($request);
        $validated = $this->validatedPayload($request);
        $imagePath = $this->storeImage($request, $districtId);

        try {
            $post = DB::transaction(function () use ($districtId, $imagePath, $request, $validated): PublicRelationsPost {
                $this->lockDistrict($districtId);
                $post = PublicRelationsPost::query()->create([
                    'district_id' => $districtId,
                    'created_by' => (int) $request->user()->id,
                    'image_path' => $imagePath,
                    'published_at' => $validated['is_published'] ? now() : null,
                    ...$validated,
                ]);
                $this->audit($request, $post, 'admin.public_relations.created');

                return $post;
            });
        } catch (Throwable $error) {
            $this->deleteImagePath($imagePath);
            throw $error;
        }

        return response()->json([
            'data' => $this->payload($post->load('creator:id,name,first_name,last_name'), $request),
            'meta' => ['source' => 'system_database'],
        ], 201);
    }

    public function update(Request $request, int $post): JsonResponse
    {
        $districtId = $this->districtId($request);
        $validated = $this->validatedPayload($request);
        $removeImage = filter_var($request->input('remove_image'), FILTER_VALIDATE_BOOLEAN);
        $newImagePath = $this->storeImage($request, $districtId);
        $oldImagePath = null;

        try {
            $updated = DB::transaction(function () use ($districtId, $newImagePath, $post, $removeImage, $request, $validated, &$oldImagePath): PublicRelationsPost {
                $this->lockDistrict($districtId);
                $model = $this->scopedPost($districtId, $post, true);
                $before = $this->auditPayload($model);
                $oldImagePath = $model->image_path;

                $model->fill([
                    ...$validated,
                    'published_at' => $validated['is_published']
                        ? ($model->published_at ?? now())
                        : null,
                ]);

                if ($newImagePath !== null) {
                    $model->image_path = $newImagePath;
                } elseif ($removeImage) {
                    $model->image_path = null;
                }

                $model->save();
                $this->audit($request, $model, 'admin.public_relations.updated', $before);

                return $model;
            });
        } catch (Throwable $error) {
            $this->deleteImagePath($newImagePath);
            throw $error;
        }

        if (($newImagePath !== null || $removeImage) && $oldImagePath !== $newImagePath) {
            $this->deleteImagePath($oldImagePath);
        }

        return response()->json([
            'data' => $this->payload($updated->load('creator:id,name,first_name,last_name'), $request),
            'meta' => ['source' => 'system_database'],
        ]);
    }

    public function updateStatus(Request $request, int $post): JsonResponse
    {
        $districtId = $this->districtId($request);
        $validated = $request->validate([
            'is_published' => ['required', 'boolean'],
        ]);

        $updated = DB::transaction(function () use ($districtId, $post, $request, $validated): PublicRelationsPost {
            $this->lockDistrict($districtId);
            $model = $this->scopedPost($districtId, $post, true);
            $before = $this->auditPayload($model);
            $isPublished = (bool) $validated['is_published'];

            $model->update([
                'is_published' => $isPublished,
                'published_at' => $isPublished ? now() : null,
            ]);
            $this->audit(
                $request,
                $model,
                $isPublished ? 'admin.public_relations.published' : 'admin.public_relations.unpublished',
                $before,
            );

            return $model;
        });

        return response()->json([
            'data' => $this->payload($updated->load('creator:id,name,first_name,last_name'), $request),
            'meta' => ['source' => 'system_database'],
        ]);
    }

    public function destroy(Request $request, int $post): JsonResponse
    {
        $districtId = $this->districtId($request);

        $deleted = DB::transaction(function () use ($districtId, $post, $request): PublicRelationsPost {
            $this->lockDistrict($districtId);
            $model = $this->scopedPost($districtId, $post, true);
            $before = $this->auditPayload($model);

            $model->delete();
            $this->audit($request, $model, 'admin.public_relations.deleted', $before);

            return $model;
        });

        $this->deleteImagePath($deleted->image_path);

        return response()->json([
            'data' => ['id' => (int) $deleted->id],
            'meta' => ['source' => 'system_database'],
        ]);
    }

    public function image(Request $request, int $post): Response
    {
        return $this->imageResponse($this->scopedPost($this->districtId($request), $post));
    }

    /** @return array{title: string, description: string, is_published: bool} */
    private function validatedPayload(Request $request): array
    {
        $request->merge([
            'title' => trim((string) $request->input('title')),
            'description' => trim((string) $request->input('description')),
        ]);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['required', 'string', 'max:8000'],
            'is_published' => ['sometimes', 'boolean'],
        ], [
            'title.required' => 'กรุณาระบุหัวข้อข่าวประชาสัมพันธ์',
            'description.required' => 'กรุณาระบุรายละเอียดข่าวประชาสัมพันธ์',
        ]);

        return [
            'title' => (string) $validated['title'],
            'description' => (string) $validated['description'],
            'is_published' => (bool) ($validated['is_published'] ?? false),
        ];
    }

    private function storeImage(Request $request, int $districtId): ?string
    {
        if (! $request->hasFile('image')) {
            return null;
        }

        $validated = $request->validate([
            'image' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=320,min_height=180,max_width=6000,max_height=6000'],
        ], [
            'image.image' => 'ไฟล์ต้องเป็นรูปภาพเท่านั้น',
            'image.mimes' => 'รองรับเฉพาะไฟล์ JPG, PNG หรือ WebP',
            'image.max' => 'ขนาดรูปภาพต้องไม่เกิน 5 MB',
            'image.dimensions' => 'ขนาดรูปภาพต้องไม่ต่ำกว่า 320×180 px และไม่เกิน 6000×6000 px',
        ]);

        /** @var UploadedFile $image */
        $image = $validated['image'];
        $path = $image->store("public-relations/districts/{$districtId}", 'local');
        abort_if($path === false, 500, 'ไม่สามารถบันทึกรูปข่าวประชาสัมพันธ์ได้');

        return $path;
    }

    private function imageResponse(PublicRelationsPost $post): Response
    {
        abort_unless(filled($post->image_path) && Storage::disk('local')->exists($post->image_path), 404, 'ไม่พบรูปข่าวประชาสัมพันธ์');
        $mime = Storage::disk('local')->mimeType($post->image_path) ?: 'image/jpeg';

        return response(Storage::disk('local')->get($post->image_path), 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function deleteImagePath(?string $path): void
    {
        if (blank($path) || ! str_starts_with((string) $path, 'public-relations/')) {
            return;
        }

        Storage::disk('local')->delete((string) $path);
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

    private function scopedPost(int $districtId, int $postId, bool $lock = false): PublicRelationsPost
    {
        $query = PublicRelationsPost::query()
            ->where('district_id', $districtId)
            ->whereKey($postId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function payload(PublicRelationsPost $post, Request $request): array
    {
        $imageUrl = filled($post->image_path)
            ? ApplicationBasePath::resolve($request)."/api/v1/admin/public-relations/{$post->id}/image?v=".($post->updated_at?->timestamp ?? 0)
            : null;

        return [
            'id' => (int) $post->id,
            'title' => (string) $post->title,
            'description' => (string) $post->description,
            'image_url' => $imageUrl,
            'is_published' => (bool) $post->is_published,
            'published_at' => $post->published_at?->toIso8601String(),
            'created_by_name' => $post->creator?->displayName(),
            'created_at' => $post->created_at?->toIso8601String(),
            'updated_at' => $post->updated_at?->toIso8601String(),
        ];
    }

    /** @param array<string, mixed>|null $before */
    private function audit(Request $request, PublicRelationsPost $post, string $event, ?array $before = null): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => (int) $request->user()->id,
            'district_id' => (int) $post->district_id,
            'event' => $event,
            'auditable_type' => 'public_relations_post',
            'auditable_id' => (int) $post->id,
            'ip_address' => $request->ip(),
            'before' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'after' => json_encode($this->auditPayload($post), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function auditPayload(PublicRelationsPost $post): array
    {
        return [
            'id' => (int) $post->id,
            'title' => (string) $post->title,
            'description' => (string) $post->description,
            'image_path' => filled($post->image_path) ? (string) $post->image_path : null,
            'is_published' => (bool) $post->is_published,
            'published_at' => $post->published_at?->toIso8601String(),
        ];
    }
}
