<?php

namespace App\Http\Controllers\Api\Students;

use App\Domain\Students\Services\StudentDirectoryService;
use App\Http\Resources\Students\StudentDetailResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class StudentSocialProfileController extends StudentsApiController
{
    public function __construct(private readonly StudentDirectoryService $directory) {}

    public function update(Request $request, string $student): JsonResponse
    {
        $viewer = $request->user();
        $record = $this->directory->findAccessible($viewer, $student);
        abort_if($record === null, 404, 'ไม่พบข้อมูลนักศึกษาหรือไม่มีสิทธิ์เข้าถึง');

        $validated = $request->validate([
            'facebook_url' => ['nullable', 'string', 'max:500'],
            'line_id' => ['nullable', 'string', 'max:255'],
        ]);

        $facebookUrl = isset($validated['facebook_url']) && trim((string) $validated['facebook_url']) !== ''
            ? trim((string) $validated['facebook_url'])
            : null;
        $lineId = isset($validated['line_id']) && trim((string) $validated['line_id']) !== ''
            ? trim((string) $validated['line_id'])
            : null;

        DB::table('student_social_profiles')->updateOrInsert(
            [
                'district_id' => $record->districtId,
                'student_code' => $record->code,
            ],
            [
                'facebook_url' => $facebookUrl,
                'line_id' => $lineId,
                'updated_by_user_id' => $viewer->id,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('audit_logs')) {
                DB::table('audit_logs')->insert([
                    'user_id' => $viewer->id,
                    'district_id' => $record->districtId,
                    'event' => 'student.social_updated',
                    'auditable_type' => 'student',
                    'auditable_id' => $record->code,
                    'ip_address' => $request->ip(),
                    'context' => json_encode([
                        'student_code' => $record->code,
                        'facebook_url' => $facebookUrl,
                        'line_id' => $lineId,
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
            }
        } catch (\Throwable) {
            // Ignore audit log failure in legacy or test configurations
        }

        $refreshed = $this->directory->findAccessible($viewer, $student) ?? $record;

        return response()->json([
            'data' => (new StudentDetailResource($refreshed))->resolve($request),
            'meta' => $this->meta([
                'message' => 'บันทึกช่องทางติดต่อ Facebook และ LINE สำเร็จ',
            ]),
        ])->header('Cache-Control', 'private, no-store');
    }
}
