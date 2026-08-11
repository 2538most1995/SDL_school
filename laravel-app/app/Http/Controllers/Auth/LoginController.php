<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Students\Services\SystemStudentAuthenticator;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\District;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final class LoginController extends Controller
{
    public function __construct(private readonly SystemStudentAuthenticator $studentAuthenticator) {}

    public function store(LoginRequest $request): JsonResponse
    {
        $studentLogin = $request->string('login_type')->toString() === 'student'
            && (bool) config('system_data.student_enabled');

        if ($studentLogin) {
            $user = $this->studentAuthenticator->authenticate(
                $request->string('identifier')->toString(),
            );
            $authenticated = $user !== null;

            if ($user !== null) {
                Auth::guard('web')->login($user, $request->boolean('remember'));
            }
        } else {
            $field = filter_var($request->string('identifier')->toString(), FILTER_VALIDATE_EMAIL)
                ? 'email'
                : 'username';

            $authenticated = Auth::attempt([
                $field => $request->string('identifier')->toString(),
                'password' => $request->string('password')->toString(),
                'disabled_at' => null,
            ], $request->boolean('remember'));
        }

        if (! $authenticated) {
            throw ValidationException::withMessages([
                'identifier' => [$studentLogin
                    ? 'เลขบัตรประชาชนไม่ถูกต้อง'
                    : 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'],
            ]);
        }

        $request->session()->regenerate();

        $user = $request->user();

        return response()->json(['data' => $this->userPayload($user)]);
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
