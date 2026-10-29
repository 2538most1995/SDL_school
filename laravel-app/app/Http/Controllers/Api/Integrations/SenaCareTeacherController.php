<?php

namespace App\Http\Controllers\Api\Integrations;

use App\Http\Controllers\Controller;
use App\Models\StudentApiClient;
use App\Models\User;
use App\Services\Learning\DistrictLearningGroupCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

final class SenaCareTeacherController extends Controller
{
    public function groups(Request $request, DistrictLearningGroupCatalog $catalog): JsonResponse
    {
        $this->authorizeClient($request, 'sena-care:teachers');

        return $this->respond(['data' => $catalog->groupsForDistrict((int) $request->attributes->get('district_id'))]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeClient($request, 'sena-care:teachers');
        $filters = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $teachers = User::query()
            ->where('district_id', $request->attributes->get('district_id'))
            ->where('role', 'teacher')
            ->whereNull('disabled_at')
            ->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 100), [
                'id', 'username', 'email', 'name', 'display_name_override', 'assigned_groups', 'district_id',
            ], 'page', (int) ($filters['page'] ?? 1));

        return $this->respond([
            'data' => $teachers->getCollection()->map(fn (User $teacher): array => $this->payload($teacher))->all(),
            'meta' => ['pagination' => [
                'current_page' => $teachers->currentPage(),
                'per_page' => $teachers->perPage(),
                'total' => $teachers->total(),
                'last_page' => $teachers->lastPage(),
            ]],
        ]);
    }

    public function authenticate(Request $request): JsonResponse
    {
        $this->authorizeClient($request, 'sena-care:authenticate');
        $credentials = $request->validate([
            'identifier' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string', 'max:200'],
        ]);
        $identifier = trim($credentials['identifier']);
        $districtId = (int) $request->attributes->get('district_id');
        $identifierKey = 'sena-care-teacher:'.hash('sha256', $districtId.'|'.mb_strtolower($identifier));
        $ipKey = 'sena-care-teacher-ip:'.hash('sha256', (string) $request->ip());

        foreach ([$identifierKey => 5, $ipKey => 60] as $key => $limit) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                return $this->respond(['message' => 'เรียก API บ่อยเกินไป กรุณาลองใหม่ภายหลัง'], 429)
                    ->header('Retry-After', (string) RateLimiter::availableIn($key));
            }
        }

        $field = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        $teacher = User::query()
            ->where('district_id', $districtId)
            ->where('role', 'teacher')
            ->whereNull('disabled_at')
            ->where($field, $identifier)
            ->first();
        // Check a dummy bcrypt hash for missing accounts to avoid a cheap enumeration path.
        $hash = $teacher?->password ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
        try {
            $valid = Hash::check($credentials['password'], $hash);
        } catch (\RuntimeException) {
            $valid = false;
        }
        if ($teacher === null || ! $valid) {
            RateLimiter::hit($identifierKey, 60);
            RateLimiter::hit($ipKey, 60);

            return $this->respond(['message' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'], 401);
        }

        RateLimiter::clear($identifierKey);

        return $this->respond(['data' => $this->payload($teacher)]);
    }

    private function authorizeClient(Request $request, string $ability): void
    {
        $client = $request->attributes->get('student_api_client');
        abort_unless($client instanceof StudentApiClient && $client->allows($ability), 403);
    }

    /** @return array<string, mixed> */
    private function payload(User $teacher): array
    {
        return [
            'id' => $teacher->id,
            'username' => $teacher->username,
            'email' => $teacher->email,
            'name' => $teacher->displayName(),
            'display_name' => $teacher->displayName(),
            'assigned_groups' => $teacher->assigned_groups ?? [],
            'district_id' => $teacher->district_id,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function respond(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)
            ->header('Cache-Control', 'private, no-store')
            ->header('Vary', 'Authorization, X-Student-Data-Token');
    }
}
