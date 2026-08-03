<?php

namespace App\Http\Controllers\Auth;

use App\Contracts\LegacyIdentityProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\District;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class LoginController extends Controller
{
    public function __construct(private readonly LegacyIdentityProvider $legacy) {}

    public function store(LoginRequest $request): JsonResponse
    {
        if ((bool) config('legacy.enabled')) {
            return $this->legacyLogin($request);
        }

        $field = filter_var($request->string('identifier')->toString(), FILTER_VALIDATE_EMAIL)
            ? 'email'
            : 'username';

        $authenticated = Auth::attempt([
            $field => $request->string('identifier')->toString(),
            'password' => $request->string('password')->toString(),
            'disabled_at' => null,
        ], $request->boolean('remember'));

        if (! $authenticated) {
            throw ValidationException::withMessages([
                'identifier' => ['ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'],
            ]);
        }

        $request->session()->regenerate();

        $user = $request->user();

        return response()->json(['data' => $this->userPayload($user)]);
    }

    private function legacyLogin(LoginRequest $request): JsonResponse
    {
        $identifier = $request->string('identifier')->toString();
        $loginType = $request->string('login_type')->toString();
        $isStudent = $loginType === 'student' || ($loginType === '' && preg_match('/^\d{13}$/', preg_replace('/\D+/', '', $identifier) ?? '') === 1);

        $identity = $isStudent
            ? $this->legacy->authenticateStudent($identifier, $request->string('password')->toString())
            : $this->legacy->authenticateStaff($identifier, $request->string('password')->toString());

        if ($identity === null) {
            throw ValidationException::withMessages([
                'identifier' => ['ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'],
            ]);
        }

        $this->syncLegacyDistricts();
        $user = $this->syncShadowUser($identity);
        Auth::guard('web')->login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return response()->json(['data' => $this->userPayload($user)]);
    }

    /** @param array<string, mixed> $identity */
    private function syncShadowUser(array $identity): User
    {
        $user = User::query()->firstOrNew(['legacy_key' => $identity['legacy_key']]);
        $user->fill([
            'name' => $identity['name'],
            'email' => 'shadow+'.hash('sha256', (string) $identity['legacy_key']).'@identity.invalid',
            'username' => $identity['username'],
            'role' => $identity['role'],
            'district_id' => $identity['district_id'],
            'assigned_groups' => $identity['assigned_groups'],
            'legacy_user_id' => $identity['legacy_user_id'],
            'student_code' => $identity['student_code'],
            'auth_source' => 'legacy',
            'disabled_at' => null,
        ]);
        if (! $user->exists) {
            $user->password = Hash::make(Str::random(64));
        }
        $user->save();

        return $user;
    }

    private function syncLegacyDistricts(): void
    {
        foreach ($this->legacy->districts() as $legacyDistrict) {
            District::query()->updateOrCreate(
                ['id' => $legacyDistrict['id']],
                [
                    'name' => $legacyDistrict['name'],
                    'code' => $legacyDistrict['code'],
                    'is_active' => $legacyDistrict['is_active'],
                ],
            );
        }
    }

    /** @return array<string, mixed> */
    private function userPayload(User $user): array
    {
        $districts = District::query()
            ->where('is_active', true)
            ->when($user->role !== 'super_admin', fn ($query) => $query->whereKey($user->district_id))
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        return [
            'id' => $user->id,
            'name' => $user->displayName(),
            'avatar_url' => $user->avatarUrl(),
            'username' => $user->student_code ?: $user->username,
            'role' => $user->role,
            'district_id' => $user->district_id,
            'assigned_groups' => $user->assigned_groups ?? [],
            'auth_source' => $user->auth_source ?? 'local',
            'districts' => $districts,
        ];
    }

    public function destroy(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['data' => ['logged_out' => true]]);
    }
}
