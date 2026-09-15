<?php

namespace App\Http\Controllers\Api\Students;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Support\ApplicationBasePath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

final class StudentAnnouncementController extends Controller
{
    public function active(Request $request): JsonResponse
    {
        $announcement = Announcement::query()
            ->where('district_id', (int) $request->attributes->get('district_id'))
            ->where('is_active', true)
            ->latest('updated_at')
            ->first(['id', 'title', 'message', 'button_label', 'button_url', 'image_path', 'show_exam_link', 'updated_at']);

        return response()->json([
            'data' => $announcement === null ? null : $this->payload($announcement, $request),
            'meta' => ['source' => 'system_database'],
        ]);
    }

    public function image(Request $request): Response
    {
        $announcement = Announcement::query()
            ->where('district_id', (int) $request->attributes->get('district_id'))
            ->where('is_active', true)
            ->latest('updated_at')
            ->first(['id', 'image_path']);

        abort_if(
            $announcement === null || blank($announcement->image_path) || ! Storage::disk('local')->exists($announcement->image_path),
            404,
            'ไม่พบรูปภาพประกาศ',
        );

        $mime = Storage::disk('local')->mimeType($announcement->image_path) ?: 'image/jpeg';

        return response(Storage::disk('local')->get($announcement->image_path), 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(Announcement $announcement, Request $request): array
    {
        $basePath = ApplicationBasePath::resolve($request);
        $imageUrl = filled($announcement->image_path)
            ? "{$basePath}/api/v1/student/announcements/image"
            : null;

        $examScheduleUrl = null;
        if ($announcement->show_exam_link) {
            $examScheduleUrl = $this->buildExamScheduleUrl($request);
        }

        return [
            'id' => (int) $announcement->id,
            'title' => (string) $announcement->title,
            'message' => (string) $announcement->message,
            'button_label' => filled($announcement->button_label) ? (string) $announcement->button_label : null,
            'button_url' => filled($announcement->button_url) ? (string) $announcement->button_url : null,
            'image_url' => $imageUrl,
            'show_exam_link' => (bool) $announcement->show_exam_link,
            'exam_schedule_url' => $examScheduleUrl,
            'updated_at' => $announcement->updated_at?->toIso8601String(),
        ];
    }

    private function buildExamScheduleUrl(Request $request): ?string
    {
        $user = $request->user();
        if ($user === null || $user->role !== 'student') {
            return null;
        }

        $studentCode = $user->student_code ?: $user->username;
        if (blank($studentCode)) {
            return null;
        }

        $districtId = (string) $request->attributes->get('district_id');
        $basePath = ApplicationBasePath::resolve($request);
        $expires = (string) now()->addHours(24)->timestamp;

        $params = array_filter([
            'scope' => 'student',
            'student' => $studentCode,
            'disposition' => 'inline',
            'user_id' => (string) $user->id,
            'district_id' => $districtId,
            'openExternalBrowser' => '1',
            'expires' => $expires,
        ], static fn ($v) => $v !== null && $v !== '');

        $params['signature'] = $this->signatureFor($params);

        return "{$basePath}/api/v1/learning/exam-schedule/view?" . http_build_query($params);
    }

    /** @param array<string, mixed> $query */
    private function signatureFor(array $query): string
    {
        $payload = [];
        foreach (['scope', 'student', 'group', 'level', 'disposition', 'user_id', 'district_id', 'expires', 'openExternalBrowser'] as $key) {
            if (isset($query[$key]) && (string) $query[$key] !== '') {
                $payload[$key] = (string) $query[$key];
            }
        }
        ksort($payload);

        return hash_hmac(
            'sha256',
            'exam-schedule:' . http_build_query($payload),
            (string) config('app.key'),
        );
    }
}
