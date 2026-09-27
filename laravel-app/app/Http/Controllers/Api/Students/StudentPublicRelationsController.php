<?php

namespace App\Http\Controllers\Api\Students;

use App\Http\Controllers\Controller;
use App\Models\PublicRelationsPost;
use App\Support\ApplicationBasePath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

final class StudentPublicRelationsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $posts = PublicRelationsPost::query()
            ->where('district_id', $this->districtId($request))
            ->where('is_published', true)
            ->whereNotNull('published_at')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'title', 'description', 'image_path', 'published_at', 'updated_at'])
            ->map(fn (PublicRelationsPost $post): array => $this->payload($post, $request))
            ->values();

        return response()->json([
            'data' => $posts,
            'meta' => ['source' => 'system_database', 'limit' => 50],
        ]);
    }

    public function image(Request $request, int $post): Response
    {
        $model = PublicRelationsPost::query()
            ->where('district_id', $this->districtId($request))
            ->where('is_published', true)
            ->whereKey($post)
            ->firstOrFail(['id', 'image_path']);

        abort_unless(filled($model->image_path) && Storage::disk('local')->exists($model->image_path), 404, 'ไม่พบรูปข่าวประชาสัมพันธ์');
        $mime = Storage::disk('local')->mimeType($model->image_path) ?: 'image/jpeg';

        return response(Storage::disk('local')->get($model->image_path), 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function districtId(Request $request): int
    {
        return (int) $request->attributes->get('district_id');
    }

    /** @return array<string, mixed> */
    private function payload(PublicRelationsPost $post, Request $request): array
    {
        $imageUrl = filled($post->image_path)
            ? ApplicationBasePath::resolve($request)."/api/v1/public-relations/{$post->id}/image?v=".($post->updated_at?->timestamp ?? 0)
            : null;

        return [
            'id' => (int) $post->id,
            'title' => (string) $post->title,
            'description' => (string) $post->description,
            'image_url' => $imageUrl,
            'published_at' => $post->published_at?->toIso8601String(),
            'updated_at' => $post->updated_at?->toIso8601String(),
        ];
    }
}
