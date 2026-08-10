<?php

namespace App\Services\Learning;

use App\Domain\Students\Repositories\StudentRepository;
use App\Models\User;
use Illuminate\Database\DatabaseManager;

final class DistrictLearningGroupCatalog
{
    /** @var list<string> */
    private const LEGACY_ALL_STUDENT_TARGETS = ['นักศึกษา', 'นักเรียน', 'ทุกกลุ่ม', 'ทั้งหมด'];

    /** @var array<int, list<array{code: string, name: string, label: string, level: string|null}>> */
    private array $groupsByDistrict = [];

    /** @var array<string, list<string>> */
    private array $aliasesByViewer = [];

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly StudentRepository $students,
    ) {}

    /** @return list<array{code: string, name: string, label: string, level: string|null}> */
    public function groupsForDistrict(int $districtId): array
    {
        if ($districtId <= 0) {
            return [];
        }
        if (array_key_exists($districtId, $this->groupsByDistrict)) {
            return $this->groupsByDistrict[$districtId];
        }

        $connection = $this->database->connection();
        $schema = $connection->getSchemaBuilder();
        if (! $schema->hasTable('import_batches') || ! $schema->hasTable('import_history')) {
            return $this->groupsByDistrict[$districtId] = [];
        }

        $batch = $connection->table('import_batches as ib')
            ->join('import_history as ih', function ($join): void {
                $join->on('ih.id', '=', 'ib.import_history_id')
                    ->on('ih.batch_key', '=', 'ib.batch_key')
                    ->on('ih.district_id', '=', 'ib.district_id');
            })
            ->where('ih.status', 'success')
            ->where('ib.district_id', $districtId)
            ->orderByRaw('COALESCE(ib.created_at, ih.created_at) DESC')
            ->orderByDesc('ib.batch_key')
            ->select('ib.batch_key')
            ->first();
        $batchKey = trim((string) ($batch->batch_key ?? ''));
        if (preg_match('/^import_\d{10}_[A-Za-z0-9]+$/', $batchKey) !== 1) {
            return $this->groupsByDistrict[$districtId] = [];
        }

        $tables = array_values(array_filter(
            $schema->getTableListing(null, false),
            static fn (string $table): bool => str_starts_with($table, 'db_'.$batchKey.'_')
                && str_ends_with($table, '_group'),
        ));
        sort($tables, SORT_NATURAL | SORT_FLAG_CASE);
        $groups = [];
        foreach ($tables as $table) {
            if (preg_match('/^db_'.preg_quote($batchKey, '/').'_[A-Za-z0-9]+_group$/', $table) !== 1) {
                continue;
            }
            foreach ($connection->table($table)->select(['grp_code', 'grp_name', 'grp_class'])->get() as $row) {
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
                ];
            }
        }
        uasort($groups, static fn (array $left, array $right): int => strnatcasecmp($left['name'], $right['name']));

        return $this->groupsByDistrict[$districtId] = array_values($groups);
    }

    /** @return list<array{code: string, name: string, label: string, level: string|null}> */
    public function groupsForViewer(User $viewer, int $districtId): array
    {
        $groups = $this->groupsForDistrict($districtId);
        if ($viewer->role !== 'teacher') {
            return $groups;
        }

        $assigned = $this->clean((array) ($viewer->assigned_groups ?? []));

        return array_values(array_filter(
            $groups,
            static fn (array $group): bool => in_array($group['code'], $assigned, true)
                || in_array($group['name'], $assigned, true),
        ));
    }

    /**
     * Return both canonical codes and human-readable names. Older learning rows
     * could contain a typed group name while current student sessions contain a
     * group code, so both aliases are required during the compatibility period.
     *
     * @return list<string>
     */
    public function aliasesForViewer(User $viewer, int $districtId): array
    {
        $cacheKey = $viewer->role.'|'.(string) $viewer->id.'|'.$districtId.'|'.json_encode($viewer->assigned_groups ?? []);
        if (array_key_exists($cacheKey, $this->aliasesByViewer)) {
            return $this->aliasesByViewer[$cacheKey];
        }

        $aliases = $this->clean((array) ($viewer->assigned_groups ?? []));
        if ($viewer->role === 'student' && $aliases === []) {
            $studentCode = trim((string) ($viewer->student_code ?: $viewer->username));
            $matches = array_values(array_filter(
                $this->students->students([$districtId]),
                static fn ($student): bool => $student->code === $studentCode,
            ));
            if (count($matches) === 1) {
                $aliases = [...$aliases, $matches[0]->groupCode, $matches[0]->groupName];
            }
        }

        foreach ($this->groupsForDistrict($districtId) as $group) {
            if (in_array($group['code'], $aliases, true) || in_array($group['name'], $aliases, true)) {
                $aliases[] = $group['code'];
                $aliases[] = $group['name'];
            }
        }

        return $this->aliasesByViewer[$cacheKey] = $this->clean($aliases);
    }

    public function canTarget(User $viewer, int $districtId, string $target): bool
    {
        $target = trim($target);
        if ($target === '') {
            return true;
        }
        if ($viewer->role === 'teacher') {
            return in_array($target, $this->aliasesForViewer($viewer, $districtId), true);
        }

        foreach ($this->groupsForDistrict($districtId) as $group) {
            if ($target === $group['code'] || $target === $group['name']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Older free-text forms commonly stored one of these audience labels as if
     * it were a group name. They mean every student in the same district.
     *
     * @return list<string>
     */
    public function legacyAllStudentTargets(): array
    {
        return self::LEGACY_ALL_STUDENT_TARGETS;
    }

    /** @param array<int, mixed> $values
     * @return list<string>
     */
    private function clean(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values,
        ))));
    }
}
