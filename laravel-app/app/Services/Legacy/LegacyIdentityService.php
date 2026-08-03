<?php

namespace App\Services\Legacy;

use App\Contracts\LegacyIdentityProvider;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;

final readonly class LegacyIdentityService implements LegacyIdentityProvider
{
    public function __construct(private DatabaseManager $database) {}

    public function districts(): array
    {
        return $this->connection()
            ->table('districts')
            ->select(['id', 'name', 'code', 'is_active'])
            ->where('is_active', 1)
            ->orderBy('name')
            ->get()
            ->map(static fn (object $district): array => [
                'id' => (int) $district->id,
                'name' => trim((string) $district->name),
                'code' => trim((string) ($district->code ?: "legacy-{$district->id}")),
                'is_active' => (bool) $district->is_active,
            ])
            ->values()
            ->all();
    }

    public function authenticateStaff(string $username, string $password): ?array
    {
        $username = trim($username);
        if ($username === '' || $password === '') {
            return null;
        }

        $row = $this->connection()
            ->table('users')
            ->select(['id', 'username', 'password', 'first_name', 'last_name', 'role', 'district_id', 'assigned_groups'])
            ->where('username', $username)
            ->first();

        if ($row === null
            || ! in_array($row->role, ['super_admin', 'admin', 'teacher'], true)
            || ! password_verify($password, (string) $row->password)) {
            return null;
        }

        $districtId = $row->role === 'super_admin' ? null : (int) $row->district_id;
        if ($row->role !== 'super_admin' && ! $this->districtIsActive($districtId)) {
            return null;
        }

        $groups = json_decode((string) ($row->assigned_groups ?? '[]'), true);

        return [
            'legacy_key' => 'staff:'.(int) $row->id,
            'legacy_user_id' => (int) $row->id,
            'username' => trim((string) $row->username),
            'display_username' => trim((string) $row->username),
            'student_code' => null,
            'name' => trim((string) $row->first_name.' '.(string) $row->last_name),
            'role' => (string) $row->role,
            'district_id' => $districtId,
            'assigned_groups' => is_array($groups) ? array_values(array_filter(array_map('strval', $groups))) : [],
            'auth_source' => 'legacy',
        ];
    }

    public function authenticateStudent(string $citizenId, string $studentCode): ?array
    {
        $citizenId = preg_replace('/\D+/', '', $citizenId) ?? '';
        $studentCode = trim($studentCode);
        if (strlen($citizenId) !== 13 || $studentCode === '' || strlen($studentCode) > 32) {
            return null;
        }

        $matches = [];
        foreach ($this->districts() as $district) {
            $identity = $this->studentIdentityInDistrict($citizenId, $studentCode, (int) $district['id']);
            if ($identity !== null) {
                $matches[] = $identity;
            }
        }

        if ($matches === []) {
            return null;
        }

        usort($matches, static fn (array $left, array $right): int => [
            $right['_matched_batch_at'],
            $right['_matched_batch_key'],
        ] <=> [
            $left['_matched_batch_at'],
            $left['_matched_batch_key'],
        ]);

        $identity = $matches[0];
        unset($identity['_matched_batch_at'], $identity['_matched_batch_key']);

        return $identity;
    }

    /** @return array<string, mixed>|null */
    private function studentIdentityInDistrict(string $citizenId, string $studentCode, int $districtId): ?array
    {
        $batch = $this->connection()->selectOne(
            "SELECT ib.batch_key, COALESCE(ib.created_at, ih.created_at) AS matched_batch_at
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
        if ($batch === null || ! preg_match('/^import_\d{10}_[A-Za-z0-9]+$/', (string) $batch->batch_key)) {
            return null;
        }

        $prefix = 'db_'.$batch->batch_key.'_';
        $studentKey = substr($studentCode, -10);

        foreach ([1, 2, 3] as $level) {
            $table = $prefix.$level.'_student';
            if (! $this->trustedTableExists($table)) {
                continue;
            }

            $student = $this->connection()
                ->table($table)
                ->selectRaw('ID AS student_code, PRENAME AS prename, NAME AS first_name, SURNAME AS surname, GRP_CODE AS group_code')
                ->where('_perf_cardid', $citizenId)
                ->where('_perf_id10', $studentKey)
                ->first();
            if ($student === null) {
                continue;
            }

            $code = trim((string) $student->student_code);

            return [
                'legacy_key' => "student:{$districtId}:{$level}:{$studentKey}",
                'legacy_user_id' => null,
                'username' => "student:{$districtId}:{$level}:{$studentKey}",
                'display_username' => $code,
                'student_code' => $code,
                'name' => trim((string) $student->prename.(string) $student->first_name.' '.(string) $student->surname),
                'role' => 'student',
                'district_id' => $districtId,
                'assigned_groups' => array_values(array_filter([trim((string) $student->group_code)])),
                'auth_source' => 'legacy',
                'education_level' => $level,
                '_matched_batch_at' => (string) ($batch->matched_batch_at ?? ''),
                '_matched_batch_key' => (string) $batch->batch_key,
            ];
        }

        return null;
    }

    private function connection(): ConnectionInterface
    {
        return $this->database->connection((string) config('legacy.connection'));
    }

    private function districtIsActive(int $districtId): bool
    {
        return $districtId > 0 && $this->connection()
            ->table('districts')
            ->where('id', $districtId)
            ->where('is_active', 1)
            ->exists();
    }

    private function trustedTableExists(string $table): bool
    {
        if (! preg_match('/^db_import_\d{10}_[A-Za-z0-9]+_[123]_student$/', $table)) {
            return false;
        }

        return $this->connection()
            ->table('information_schema.tables')
            ->where('table_schema', $this->database->connection((string) config('legacy.connection'))->getDatabaseName())
            ->where('table_name', $table)
            ->exists();
    }
}
