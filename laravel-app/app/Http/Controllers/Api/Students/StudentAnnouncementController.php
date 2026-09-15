<?php

namespace App\Http\Controllers\Api\Students;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StudentAnnouncementController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $announcement = Announcement::query()
            ->where('district_id', (int) $request->attributes->get('district_id'))
            ->where('is_active', true)
            ->latest('updated_at')
            ->first(['id', 'title', 'message', 'button_label', 'button_url', 'updated_at']);

        return response()->json([
            'data' => $announcement === null ? null : [
                'id' => (int) $announcement->id,
                'title' => (string) $announcement->title,
                'message' => (string) $announcement->message,
                'button_label' => filled($announcement->button_label) ? (string) $announcement->button_label : null,
                'button_url' => filled($announcement->button_url) ? (string) $announcement->button_url : null,
                'updated_at' => $announcement->updated_at?->toIso8601String(),
            ],
            'meta' => ['source' => 'system_database'],
        ]);
    }
}
