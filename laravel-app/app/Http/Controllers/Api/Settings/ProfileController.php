<?php

namespace App\Http\Controllers\Api\Settings;

use App\Domain\Students\Services\StudentDirectoryService;
use App\Http\Controllers\Controller;
use App\Models\District;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ProfileController extends Controller
{
    public function __construct(private readonly StudentDirectoryService $students) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->payload($request), 'meta' => ['source' => 'laravel_control_plane']]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:190'],
        ]);
        $user = $request->user();
        $user->update([
            'display_name_override' => trim($validated['display_name']),
            'contact_email' => filled($validated['email'] ?? null) ? mb_strtolower(trim($validated['email'])) : null,
        ]);
        $this->audit($request, 'profile.updated', ['display_name', 'contact_email']);

        return response()->json(['data' => $this->payload($request), 'meta' => ['source' => 'laravel_control_plane']]);
    }

    public function avatar(Request $request): StreamedResponse
    {
        $user = $request->user();
        $path = (string) $user->avatar_path;

        abort_unless($this->isOwnedAvatarPath($user->id, $path) && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function updateAvatar(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'avatar' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=128,min_height=128,max_width=4000,max_height=4000'],
        ]);
        $user = $request->user();
        $file = $validated['avatar'];
        $path = $file->store("avatars/{$user->id}", 'local');

        abort_if($path === false, 500, 'ไม่สามารถบันทึกรูปโปรไฟล์ได้');

        $oldPath = (string) $user->avatar_path;
        $user->update(['avatar_path' => $path, 'avatar_updated_at' => now()]);

        if ($this->isOwnedAvatarPath($user->id, $oldPath) && $oldPath !== $path) {
            Storage::disk('local')->delete($oldPath);
        }

        $this->audit($request, 'profile.avatar_updated', ['avatar_path']);

        return response()->json(['data' => $this->payload($request), 'meta' => ['source' => 'laravel_control_plane']]);
    }

    public function destroyAvatar(Request $request): JsonResponse
    {
        $user = $request->user();
        $path = (string) $user->avatar_path;

        if ($this->isOwnedAvatarPath($user->id, $path)) {
            Storage::disk('local')->delete($path);
        }

        $user->update(['avatar_path' => null, 'avatar_updated_at' => now()]);
        $this->audit($request, 'profile.avatar_removed', ['avatar_path']);

        return response()->json(['data' => $this->payload($request), 'meta' => ['source' => 'laravel_control_plane']]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        if (! Hash::check($validated['current_password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['รหัสผ่านปัจจุบันไม่ถูกต้อง'],
            ]);
        }

        $user->update(['password' => $validated['password']]);
        $this->audit($request, 'profile.password_updated', ['password']);

        return response()->json([
            'data' => ['updated' => true],
            'meta' => ['source' => 'laravel_control_plane'],
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $user = $request->user();
        $districtId = (int) ($request->attributes->get('district_id') ?: $user->district_id);
        $student = $user->role === 'student'
            ? $this->students->findAccessible($user, (string) ($user->student_code ?: $user->username))
            : null;
        $roles = ['student' => 'นักศึกษา', 'teacher' => 'ครูผู้สอน', 'admin' => 'ผู้ดูแลอำเภอ', 'super_admin' => 'ผู้ดูแลส่วนกลาง'];

        return [
            'displayName' => $user->displayName(),
            'avatarUrl' => $user->avatarUrl(),
            'email' => (string) ($user->contact_email ?? ''),
            'phoneMasked' => (string) ($student?->contact['phone_masked'] ?? '-'),
            'studentCode' => $user->student_code,
            'roleLabel' => $roles[$user->role] ?? $user->role,
            'districtName' => (string) (District::query()->whereKey($districtId)->value('name') ?? ''),
            'canChangePassword' => true,
        ];
    }

    /** @param list<string> $changedFields */
    private function audit(Request $request, string $event, array $changedFields): void
    {
        try {
            DB::table('audit_logs')->insert([
                'user_id' => $request->user()->id,
                'district_id' => $request->attributes->get('district_id') ?: $request->user()->district_id,
                'event' => $event,
                'auditable_type' => 'user',
                'auditable_id' => $request->user()->id,
                'ip_address' => $request->ip(),
                'context' => json_encode(['changed_fields' => $changedFields], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // Ignore if audit_logs table is missing in legacy DB
        }
    }

    private function isOwnedAvatarPath(int $userId, string $path): bool
    {
        return $path !== '' && str_starts_with($path, "avatars/{$userId}/");
    }
}
