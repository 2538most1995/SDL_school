<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Learning\DemoLearningPortal;
use App\Domain\Learning\DemoResponseMeta;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

final class UserController extends Controller
{
    public function __construct(private readonly DatabaseManager $database) {}

    public function __invoke(Request $request, DemoLearningPortal $demo): JsonResponse
    {
        return $this->index($request, $demo);
    }

    public function index(Request $request, DemoLearningPortal $demo): JsonResponse
    {
        $filters = $request->validate([
            'role' => ['nullable', Rule::in(config('legacy.enabled') ? ['teacher', 'admin', 'super_admin'] : ['student', 'teacher', 'admin', 'super_admin'])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        if (! (bool) config('legacy.enabled')) {
            $items = array_map(static function (array $item): array {
                [$firstName, $lastName] = array_pad(explode(' ', (string) $item['display_name'], 2), 2, '');

                return [...$item, 'first_name' => $firstName, 'last_name' => $lastName, 'assigned_groups' => $item['group'] ? [$item['group']] : [], 'can_edit' => false];
            }, $demo->users($filters['role'] ?? null, $filters['search'] ?? null));

            return response()->json(['data' => $items, 'meta' => [
                ...DemoResponseMeta::collection(count($items), $filters),
                'read_only' => true,
                'allowed_roles' => ['teacher', 'admin'],
                'available_groups' => [],
            ]]);
        }
        $districtId = $this->districtId($request);
        $viewer = $request->user();
        $availableGroups = $this->availableGroups($districtId);
        $groupNames = collect($availableGroups)->pluck('label', 'code')->all();
        $query = $this->userDirectoryConnection()->table('users as user')
            ->leftJoin('districts as district', 'district.id', '=', 'user.district_id')
            ->whereIn('user.role', ['teacher', 'admin', 'super_admin'])
            ->where(function (Builder $scope) use ($districtId, $viewer): void {
                $scope->where('user.district_id', $districtId);
                if ($viewer->role === 'super_admin') {
                    $scope->orWhere('user.role', 'super_admin');
                }
            });

        if (isset($filters['role'])) {
            $query->where('user.role', $filters['role']);
        }
        if (filled($filters['search'] ?? null)) {
            $needle = '%'.trim((string) $filters['search']).'%';
            $query->where(fn (Builder $part) => $part
                ->where('user.username', 'like', $needle)
                ->orWhere('user.first_name', 'like', $needle)
                ->orWhere('user.last_name', 'like', $needle));
        }

        $items = $query->select([
            'user.id', 'user.username', 'user.first_name', 'user.last_name', 'user.role',
            'user.district_id', 'user.assigned_groups', 'user.created_at', 'district.name as district_name',
        ])->orderBy('user.role')->orderBy('user.first_name')->limit(500)->get()
            ->map(fn (object $row): array => $this->payload($row, $viewer->role === 'super_admin' || $row->role !== 'super_admin', $groupNames))
            ->values()->all();

        return response()->json(['data' => $items, 'meta' => [
            'source' => 'legacy_controlled_write',
            'read_only' => ! $this->writeEnabled(),
            'district_id' => $districtId,
            'allowed_roles' => $viewer->role === 'super_admin'
                ? ['teacher', 'admin', 'super_admin']
                : ['teacher', 'admin'],
            'available_groups' => $availableGroups,
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertWriteEnabled();
        $validated = $this->validated($request, true);
        $districtId = $this->targetDistrictId($request, $validated['role'], $validated['district_id'] ?? null);
        $this->assertUsernameAvailable($validated['username']);

        $id = $this->write()->table('users')->insertGetId([
            'username' => trim($validated['username']),
            'password' => password_hash($validated['password'], PASSWORD_BCRYPT),
            'first_name' => trim($validated['first_name']),
            'last_name' => trim($validated['last_name']),
            'role' => $validated['role'],
            'district_id' => $districtId,
            'assigned_groups' => json_encode($validated['assigned_groups'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
        $row = $this->userRow($request, $id, true);
        $this->audit($request, 'admin.user.created', $id, null, $this->auditPayload($row));

        return response()->json(['data' => $this->payload($row, true, $this->groupNames($this->districtId($request)))], 201);
    }

    public function update(Request $request, int $legacyUser): JsonResponse
    {
        $this->assertWriteEnabled();
        $before = $this->userRow($request, $legacyUser, true);
        $validated = $this->validated($request, false);
        $districtId = $this->targetDistrictId($request, $validated['role'], $validated['district_id'] ?? $before->district_id);
        $this->assertUsernameAvailable($validated['username'], $legacyUser);

        if ((int) $request->user()->legacy_user_id === $legacyUser
            && ($validated['role'] !== $before->role || $districtId !== ($before->district_id === null ? null : (int) $before->district_id))) {
            throw ValidationException::withMessages(['role' => ['ไม่สามารถเปลี่ยนสิทธิ์หรืออำเภอของบัญชีที่กำลังใช้งาน']]);
        }

        $changes = [
            'username' => trim($validated['username']),
            'first_name' => trim($validated['first_name']),
            'last_name' => trim($validated['last_name']),
            'role' => $validated['role'],
            'district_id' => $districtId,
            'assigned_groups' => json_encode($validated['assigned_groups'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];
        if (filled($validated['password'] ?? null)) {
            $changes['password'] = password_hash($validated['password'], PASSWORD_BCRYPT);
        }
        $this->write()->table('users')->where('id', $legacyUser)->update($changes);
        $after = $this->userRow($request, $legacyUser, true);
        $this->syncShadowUser($legacyUser, $after);
        $this->audit($request, 'admin.user.updated', $legacyUser, $this->auditPayload($before), $this->auditPayload($after));

        return response()->json(['data' => $this->payload($after, true, $this->groupNames($this->districtId($request)))]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $roles = $request->user()->role === 'super_admin'
            ? ['teacher', 'admin', 'super_admin']
            : ['teacher', 'admin'];

        return $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9._@-]+$/'],
            'password' => [$creating ? 'required' : 'nullable', 'string', 'min:8', 'max:72'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'role' => ['required', Rule::in($roles)],
            'district_id' => ['nullable', 'integer', Rule::exists('districts', 'id')->where('is_active', true)],
            'assigned_groups' => ['sometimes', 'array', 'max:200'],
            'assigned_groups.*' => ['string', 'max:64'],
        ]);
    }

    private function targetDistrictId(Request $request, string $role, mixed $requested): ?int
    {
        if ($role === 'super_admin') {
            abort_unless($request->user()->role === 'super_admin', 403);

            return null;
        }

        $context = $this->districtId($request);
        if ($request->user()->role !== 'super_admin') {
            return $context;
        }

        $target = (int) ($requested ?: $context);
        abort_unless($target === $context, 422, 'กรุณาเลือกอำเภอเป้าหมายจากเมนูด้านบนก่อนบันทึก');

        return $target;
    }

    private function userRow(Request $request, int $id, bool $forWrite = false): object
    {
        $districtId = $this->districtId($request);
        $viewer = $request->user();
        $connection = $forWrite ? $this->write() : $this->read();
        $row = $connection->table('users as user')
            ->leftJoin('districts as district', 'district.id', '=', 'user.district_id')
            ->select('user.*', 'district.name as district_name')
            ->where('user.id', $id)
            ->where(function (Builder $scope) use ($districtId, $viewer): void {
                $scope->where('user.district_id', $districtId);
                if ($viewer->role === 'super_admin') {
                    $scope->orWhere('user.role', 'super_admin');
                }
            })->first();

        abort_unless($row, 404);
        abort_if($viewer->role !== 'super_admin' && $row->role === 'super_admin', 403);

        return $row;
    }

    private function assertUsernameAvailable(string $username, ?int $except = null): void
    {
        $query = $this->write()->table('users')->where('username', trim($username));
        if ($except !== null) {
            $query->where('id', '!=', $except);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['username' => ['ชื่อผู้ใช้นี้มีอยู่ในระบบแล้ว']]);
        }
    }

    private function syncShadowUser(int $legacyId, object $row): void
    {
        try {
            $shadow = new User;
            if (! $shadow->getConnection()->getSchemaBuilder()->hasColumn($shadow->getTable(), 'legacy_key')) {
                Log::warning('admin.user.shadow_sync_skipped', [
                    'legacy_user_id' => $legacyId,
                    'reason' => 'missing_legacy_key_column',
                ]);

                return;
            }

            User::query()->where('legacy_key', "staff:{$legacyId}")->update([
                'name' => trim((string) $row->first_name.' '.(string) $row->last_name),
                'username' => (string) $row->username,
                'role' => (string) $row->role,
                'district_id' => $row->district_id,
                'assigned_groups' => json_encode($this->groups($row->assigned_groups), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
        } catch (Throwable $exception) {
            // Some compatibility deployments authenticate directly against the
            // legacy users table and do not have Laravel shadow-user columns.
            // The authoritative legacy update has already succeeded, so a
            // shadow sync failure must not turn that success into HTTP 500.
            Log::warning('admin.user.shadow_sync_skipped', [
                'legacy_user_id' => $legacyId,
                'exception' => $exception::class,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function payload(object $row, bool $canEdit, array $groupNames = []): array
    {
        $groups = $this->groups($row->assigned_groups);

        return [
            'id' => (int) $row->id,
            'display_name' => trim((string) $row->first_name.' '.(string) $row->last_name),
            'first_name' => (string) $row->first_name,
            'last_name' => (string) $row->last_name,
            'username' => (string) $row->username,
            'role' => (string) $row->role,
            'district_id' => $row->district_id === null ? null : (int) $row->district_id,
            'district_name' => (string) ($row->district_name ?? 'ทุกอำเภอ'),
            'assigned_groups' => $groups,
            'assigned_group_names' => array_map(static fn (string $code): string => (string) ($groupNames[$code] ?? $code), $groups),
            'group' => implode(', ', array_map(static fn (string $code): string => (string) ($groupNames[$code] ?? $code), $groups)) ?: null,
            'status' => 'active',
            'can_edit' => $canEdit,
        ];
    }

    /** @return list<string> */
    private function groups(mixed $value): array
    {
        $groups = is_array($value) ? $value : json_decode((string) ($value ?? '[]'), true);

        return is_array($groups) ? array_values(array_unique(array_filter(array_map('strval', $groups)))) : [];
    }

    /** @return list<array{code: string, name: string, label: string, level: string|null, advisor: string|null, meeting_place: string|null}> */
    private function availableGroups(int $districtId): array
    {
        $connection = $this->read();
        if (! $connection->getSchemaBuilder()->hasTable('import_batches')
            || ! $connection->getSchemaBuilder()->hasTable('import_history')) {
            return [];
        }

        $batch = $connection->selectOne(
            "SELECT ib.batch_key
             FROM import_batches ib
             INNER JOIN import_history ih
                ON ih.id = ib.import_history_id
               AND BINARY ih.batch_key = BINARY ib.batch_key
               AND ih.district_id = ib.district_id
               AND ih.status = 'success'
             WHERE ib.district_id = ?
             ORDER BY COALESCE(ib.created_at, ih.created_at) DESC, ib.batch_key DESC
             LIMIT 1",
            [$districtId],
            true,
        );
        $batchKey = trim((string) ($batch->batch_key ?? ''));
        if (preg_match('/^import_\d{10}_[A-Za-z0-9]+$/', $batchKey) !== 1) {
            return [];
        }

        $tables = $connection->select(
            'SELECT table_name
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name LIKE ?
             ORDER BY table_name',
            ['db_'.$batchKey.'_%_group'],
            true,
        );
        $groups = [];
        foreach ($tables as $candidate) {
            $table = (string) ($candidate->TABLE_NAME ?? $candidate->table_name ?? '');
            if (preg_match('/^db_'.preg_quote($batchKey, '/').'_[A-Za-z0-9]+_group$/', $table) !== 1) {
                continue;
            }
            foreach ($connection->table($table)->select(['grp_code', 'grp_name', 'grp_class', 'grp_advis', 'grp_meet'])->get() as $row) {
                $code = trim((string) $row->grp_code);
                if ($code === '') {
                    continue;
                }
                $name = trim((string) $row->grp_name) ?: $code;
                $level = match (trim((string) $row->grp_class)) {
                    '1' => 'ประถมศึกษา',
                    '2' => 'มัธยมศึกษาตอนต้น',
                    '3' => 'มัธยมศึกษาตอนปลาย',
                    default => null,
                };
                $groups[$code] = [
                    'code' => $code,
                    'name' => $name,
                    'label' => $level === null ? $name : $level.' · '.$name,
                    'level' => $level,
                    'advisor' => trim((string) $row->grp_advis) ?: null,
                    'meeting_place' => trim((string) $row->grp_meet) ?: null,
                ];
            }
        }
        uasort($groups, static fn (array $left, array $right): int => strnatcasecmp($left['name'], $right['name']));

        return array_values($groups);
    }

    /** @return array<string, string> */
    private function groupNames(int $districtId): array
    {
        return collect($this->availableGroups($districtId))->pluck('label', 'code')->all();
    }

    /** @return array<string, mixed> */
    private function auditPayload(object $row): array
    {
        return array_intersect_key($this->payload($row, true), array_flip([
            'id', 'username', 'first_name', 'last_name', 'role', 'district_id', 'assigned_groups',
        ]));
    }

    private function audit(Request $request, string $event, int $id, ?array $before, array $after): void
    {
        $entry = [
            'user_id' => $request->user()->id,
            'district_id' => $this->districtId($request),
            'event' => $event,
            'auditable_type' => 'legacy_user',
            'auditable_id' => $id,
            'ip_address' => $request->ip(),
            'before' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'after' => json_encode($after, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ];

        try {
            $connection = DB::connection();
            if (! $connection->getSchemaBuilder()->hasTable('audit_logs')) {
                Log::warning('admin.user.audit_fallback', [
                    ...$entry,
                    'reason' => 'missing_audit_logs_table',
                ]);

                return;
            }

            $connection->table('audit_logs')->insert($entry);
        } catch (Throwable $exception) {
            // Keep an audit trail in the application log when the optional
            // control-plane audit table has not been deployed yet. The legacy
            // user write is authoritative and must not be reported as failed
            // after it has already committed.
            Log::warning('admin.user.audit_fallback', [
                ...$entry,
                'exception' => $exception::class,
            ]);
        }
    }

    private function districtId(Request $request): int
    {
        return (int) $request->attributes->get('district_id');
    }

    private function writeEnabled(): bool
    {
        return (bool) config('legacy.write_enabled');
    }

    private function assertWriteEnabled(): void
    {
        abort_unless($this->writeEnabled(), 503, 'ระบบเขียนข้อมูลยังไม่เปิดใช้งาน');
    }

    private function read()
    {
        return $this->database->connection((string) config('legacy.connection'));
    }

    private function userDirectoryConnection()
    {
        return $this->writeEnabled() ? $this->write() : $this->read();
    }

    private function write()
    {
        return $this->database->connection((string) config('legacy.write_connection'));
    }
}
