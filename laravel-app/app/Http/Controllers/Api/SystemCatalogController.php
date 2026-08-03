<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SystemCatalogController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $role = (string) $request->user()?->role;
        $allowedRoles = ['student', 'teacher', 'admin', 'super_admin'];

        abort_unless(in_array($role, $allowedRoles, true), 422, 'บทบาทผู้ใช้ไม่ถูกต้อง');

        $groups = collect(config('sena.modules'))
            ->map(function (array $group) use ($role): array {
                $group['items'] = collect($group['items'])
                    ->filter(fn (array $item): bool => in_array($role, $item['roles'], true))
                    ->values()
                    ->all();

                return $group;
            })
            ->filter(fn (array $group): bool => count($group['items']) > 0)
            ->values();

        return response()->json([
            'data' => [
                'role' => $role,
                'groups' => $groups,
                'capabilities' => match ($role) {
                    'super_admin' => ['districts:all', 'users:manage', 'imports:manage', 'reports:all', 'learning:manage'],
                    'admin' => ['district:own', 'users:manage', 'imports:manage', 'reports:view', 'learning:manage'],
                    'teacher' => ['groups:assigned', 'students:view', 'reports:view', 'learning:manage'],
                    default => ['student:self', 'grades:self', 'learning:self'],
                },
            ],
            'meta' => [
                'demo_mode' => (bool) config('sena.demo_mode'),
                'contains_personal_data' => false,
            ],
        ]);
    }
}
