<?php

namespace App\Domain\Students\Repositories;

use App\Domain\Students\Models\Student;
use App\Domain\Students\Support\AcademicTerm;
use App\Domain\Students\Support\LegacyStudentStatus;
use App\Domain\Students\Support\LegacyTableSet;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/** Isolated directory queries; existing roster/integration consumers keep their contracts. */
trait QueriesStudentDirectory
{
    public function directoryPage(User $viewer, array $filters): array
    {
        // Recheck edits on each directory call, including long-lived workers.
        $this->scoreRevisions = [];
        [$districtId, $districtName, $sets, $terms] = $this->directoryContext($viewer);
        $this->assertDirectoryIdentity($viewer, $sets, $terms);
        $current = $terms[0] ?? null;
        $term = AcademicTerm::normalize($filters['term'] ?? null) ?? $current;
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(1000, max(1, (int) ($filters['per_page'] ?? 25)));
        $empty = ['total' => 0, 'male' => 0, 'female' => 0, 'groups' => 0, 'levels' => 0, 'average_gpax' => null];
        $options = ['levels' => [], 'groups' => [], 'terms' => array_map(static fn ($t) => ['value' => $t, 'label' => "ภาคเรียน {$t}"], $terms), 'districts' => []];
        $items = [];
        $summary = $empty;
        if ($sets !== [] && $term !== null) {
            $cohorts = $this->directoryCohorts($sets, $viewer, $term, $current, $filters);
            $scope = [$districtId, $viewer->role, $viewer->student_code ?: $viewer->username, $viewer->assigned_groups, array_map(fn ($s) => $this->aggregateCacheKey('academic', $s, $term, []), $sets)];
            $summaryFilters = array_intersect_key($filters, array_flip(['search', 'level', 'group', 'district_id', 'kpch_status']));
            $key = 'student-directory:v1:'.hash('sha256', json_encode([$scope, $term, $summaryFilters], JSON_THROW_ON_ERROR));
            $summary = $this->directoryRemember($key, function () use ($cohorts, $sets, $terms, $term, $filters, $empty) {
                [$sql, $bindings] = $this->directoryUnion($cohorts, $sets, $terms, $term, true, $filters);
                if ($sql === '') {
                    return $empty;
                }
                $groupExpression = $this->directoryExact('group_name');
                $row = $this->rows("SELECT COUNT(*) AS total, SUM(CASE WHEN UPPER(TRIM(gender)) IN ('1','M','ชาย') THEN 1 ELSE 0 END) AS male, SUM(CASE WHEN UPPER(TRIM(gender)) IN ('2','F','หญิง') THEN 1 ELSE 0 END) AS female, COUNT(DISTINCT {$groupExpression}) AS group_count, COUNT(DISTINCT level_id) AS levels, AVG(CASE WHEN gpax > 0 THEN ROUND(gpax, 2) END) AS average_gpax FROM ({$sql}) directory_summary", $bindings)[0];

                return [...array_map('intval', array_intersect_key($row, array_flip(['total', 'male', 'female', 'levels']))), 'groups' => (int) $row['group_count'], 'average_gpax' => $row['average_gpax'] === null ? null : round((float) $row['average_gpax'], 2)];
            });
            abort_if($viewer->role === 'student' && $summary['total'] > 1, 403, 'รหัสนักศึกษาซ้ำระหว่างระดับการศึกษา กรุณาติดต่อผู้ดูแลระบบ');
            $page = min($page, max(1, (int) ceil($summary['total'] / $perPage)));
            if ($summary['total'] > 0) {
                $sort = $filters['sort'] ?? 'code';
                $needsMetrics = in_array($sort, ['gpax', 'credits', 'kpch_hours'], true) || ! empty($filters['kpch_status']);
                [$sql, $bindings] = $this->directoryUnion($cohorts, $sets, $terms, $term, $needsMetrics, $filters);
                $sortColumn = ['code' => 'code', 'name' => 'full_name', 'gpax' => 'gpax', 'credits' => 'credits_earned', 'kpch_hours' => 'kpch_hours'][$sort] ?? 'code';
                $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
                $rows = $this->rows("SELECT * FROM ({$sql}) directory_page ORDER BY {$sortColumn} {$direction}, level_id ASC, code ASC LIMIT ? OFFSET ?", [...$bindings, $perPage, ($page - 1) * $perPage]);
                $items = $this->directoryHydrate($rows, $sets, $terms, $term, $districtId, $districtName);
            }
            $optionKey = 'student-directory-options:v1:'.hash('sha256', json_encode([$scope, $term], JSON_THROW_ON_ERROR));
            $basic = $this->directoryRemember($optionKey, function () use ($sets, $viewer, $term, $current) {
                $cohorts = $this->directoryCohorts($sets, $viewer, $term, $current, []);
                [$sql, $bindings] = $this->directoryUnion($cohorts, $sets, [], $term, false, []);

                $groupExpression = $this->directoryExact('group_name');

                return $sql === '' ? [] : $this->rows("SELECT DISTINCT level_id, {$groupExpression} AS group_name FROM ({$sql}) directory_options ORDER BY level_id, group_name", $bindings);
            });
            foreach ($basic as $row) {
                $options['levels'][(int) $row['level_id']] = ['value' => (int) $row['level_id'], 'label' => $this->levelLabel((int) $row['level_id'])];
                if (trim((string) $row['group_name']) !== '') {
                    $options['groups'][$row['group_name']] = ['value' => $row['group_name'], 'label' => $row['group_name']];
                }
            }
            ksort($options['groups'], SORT_NATURAL);
            $options['levels'] = array_values($options['levels']);
            $options['groups'] = array_values($options['groups']);
            if ($basic !== []) {
                $options['districts'][] = ['value' => $districtId, 'label' => $districtName];
            }
        }

        return ['items' => $items, 'meta' => [
            'pagination' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $summary['total'], 'last_page' => max(1, (int) ceil($summary['total'] / $perPage)), 'from' => $items === [] ? null : ($page - 1) * $perPage + 1, 'to' => $items === [] ? null : min($page * $perPage, $summary['total'])],
            'summary' => $summary, 'filter_options' => $options, 'current_term' => $current, 'selected_term' => $term,
            'historical' => $term !== null && $term !== $current,
            'snapshot_note' => 'รายชื่อและผลการเรียนอ้างอิงภาคเรียนที่เลือก ชื่อ ที่อยู่ กลุ่มเรียน และสถานะทะเบียนเป็นข้อมูลจากชุด ITW ล่าสุด',
            'applied_filters' => array_filter([...$filters, 'term' => $term], static fn ($v) => $v !== null && $v !== ''),
        ]];
    }

    private function directoryContext(User $viewer): array
    {
        $districtId = $viewer->relationLoaded('selectedDistrictContext') ? (int) $viewer->getRelation('selectedDistrictContext') : (int) $viewer->district_id;
        $district = $districtId > 0 ? ($this->activeDistricts([$districtId])[0] ?? null) : null;
        if ($district === null) {
            return [$districtId, '', [], []];
        }
        $sets = $this->tableSetsForDistrict($districtId, (string) $district['name']);
        $key = 'directory-terms:v1:'.hash('sha256', implode('|', array_map(static fn ($s) => $s->grade, $sets)));
        $terms = $this->directoryRemember($key, fn () => $this->availableTerms([$districtId]));

        return [$districtId, (string) $district['name'], $sets, $terms];
    }

    private function directoryRemember(string $key, callable $resolver): mixed
    {
        $seconds = max(0, (int) config('system_data.cache_seconds', 300));

        return $seconds === 0 ? $resolver() : Cache::remember($key, $seconds, $resolver);
    }

    private function assertDirectoryIdentity(User $viewer, array $sets, array $terms): void
    {
        if ($viewer->role !== 'student' || $sets === [] || $terms === []) {
            return;
        }
        $cohorts = $this->directoryCohorts($sets, $viewer, $terms[0], $terms[0], []);
        [$sql, $bindings] = $this->directoryUnion($cohorts, $sets, [], $terms[0], false, []);
        $rows = $this->rows("SELECT code FROM ({$sql}) student_identity LIMIT 2", $bindings);
        abort_if(count($rows) > 1, 403, 'รหัสนักศึกษาซ้ำระหว่างระดับการศึกษา กรุณาติดต่อผู้ดูแลระบบ');
    }

    /** Minimal authorized identities; no memo/profile columns or student hydration. */
    private function directoryCohorts(array $sets, User $viewer, string $term, ?string $current, array $filters, bool $allTerms = false): array
    {
        $cohorts = [];
        foreach ($sets as $set) {
            if (isset($filters['level']) && (int) $filters['level'] !== $set->level) {
                continue;
            }
            $table = $this->identifier($set->student);
            $grade = $this->identifier($set->grade);
            $join = '';
            $groupName = 's.grp_code';
            if ($set->group !== null) {
                $group = $this->identifier($set->group);
                $join = "LEFT JOIN (SELECT _perf_grp, MAX(grp_name) AS grp_name FROM {$group} GROUP BY _perf_grp HAVING COUNT(DISTINCT TRIM(grp_name)) = 1) grp ON grp._perf_grp = s._perf_grp";
                $groupName = "COALESCE(NULLIF(TRIM(grp.grp_name), ''), s.grp_code)";
            }
            $col = function (array $aliases) use ($set): string {
                $c = $this->firstExistingColumn($set->student, $aliases);

                return $c === null ? "''" : 's.'.$this->identifier($c);
            };
            $gender = $col(['gender', 'sex']);
            $birth = $col(['birday', 'birthday', 'birth_date']);
            $citizen = $this->citizenIdSql($set);
            $fullName = $this->directoryConcat(['s.prename', 's.name', "' '", 's.surname']);
            $conditions = ["TRIM(COALESCE(s._perf_id10,'')) <> ''", "(SELECT COUNT(*) FROM {$table} identity_row WHERE identity_row._perf_id10 = s._perf_id10) = 1"];
            $bindings = [];
            if (! $allTerms) {
                $variants = AcademicTerm::variants($term);
                $slots = implode(',', array_fill(0, count($variants), '?'));
                $recent = $term === $current ? " OR s._perf_id10 IN (SELECT code FROM (SELECT _perf_id10 AS code FROM {$table} WHERE _perf_id10 IS NOT NULL ORDER BY _perf_id10 DESC LIMIT 2) recent_students)" : '';
                $conditions[] = "(EXISTS (SELECT 1 FROM {$grade} registration WHERE registration._perf_std10 = s._perf_id10 AND registration._perf_semestry IN ({$slots})){$recent})";
                array_push($bindings, ...$variants);
            }
            if ($viewer->role === 'teacher') {
                $groups = array_values(array_unique(array_filter(array_map('trim', is_array($viewer->assigned_groups) ? $viewer->assigned_groups : []))));
                if ($groups === []) {
                    $conditions[] = '1 = 0';
                } else {
                    $slots = implode(',', array_fill(0, count($groups), '?'));
                    $conditions[] = '('.$this->directoryExact('s.grp_code')." IN ({$slots}) OR ".$this->directoryExact($groupName)." IN ({$slots}))";
                    array_push($bindings, ...$groups, ...$groups);
                }
            } elseif ($viewer->role === 'student') {
                [$identitySql, $identityBindings] = $this->directoryCodeEquals(trim((string) ($viewer->student_code ?: $viewer->username)));
                $conditions[] = $identitySql;
                array_push($bindings, ...$identityBindings);
            } elseif (! in_array($viewer->role, ['admin', 'super_admin'], true)) {
                $conditions[] = '1 = 0';
            }
            if (isset($filters['district_id']) && (int) $filters['district_id'] !== $set->districtId) {
                $conditions[] = '1 = 0';
            }
            if (! empty($filters['_code'])) {
                [$identitySql, $identityBindings] = $this->directoryCodeEquals($filters['_code']);
                $conditions[] = $identitySql;
                array_push($bindings, ...$identityBindings);
            }
            if (($filters['group'] ?? '') !== '') {
                $conditions[] = '('.$this->directoryExact('s.grp_code').' = ? OR '.$this->directoryExact($groupName).' = ?)';
                array_push($bindings, $filters['group'], $filters['group']);
            }
            if (trim((string) ($filters['search'] ?? '')) !== '') {
                $haystack = $this->directoryConcat(['s._perf_id10', "' '", $fullName, "' '", 's.grp_code', "' '", $groupName, "' '", $citizen]);
                $conditions[] = "LOWER({$haystack}) LIKE ? ESCAPE '!'";
                $bindings[] = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($filters['search']))).'%';
            }
            $cohorts[$set->level] = ['sql' => "SELECT s._perf_id10 AS code, {$set->level} AS level_id, s.prename, s.name AS first_name, s.surname AS last_name, {$fullName} AS full_name, s.grp_code AS group_code, {$groupName} AS group_name, {$gender} AS gender, {$birth} AS birth_date, {$citizen} AS citizen_id, ".$col(['dep_sem', 'depsem']).' AS enrollment_term, '.$col(['fin_cause', 'fincause']).' AS fin_cause, '.$col(['trn_date2', 'trndate2', 'trn_date'])." AS transfer_date FROM {$table} s {$join} WHERE ".implode(' AND ', $conditions), 'bindings' => $bindings];
        }

        return $cohorts;
    }

    private function directoryExact(string $expression): string
    {
        return $this->connection->getDriverName() === 'mysql' ? 'BINARY '.$expression : $expression;
    }

    private function directoryCodeEquals(string $code): array
    {
        // Keep an indexed equality candidate plus an exact case-sensitive check.
        return $this->connection->getDriverName() === 'mysql'
            ? ['(s._perf_id10 = ? AND BINARY s._perf_id10 = BINARY ?)', [$code, $code]]
            : ['s._perf_id10 = ?', [$code]];
    }

    private function directoryConcat(array $expressions): string
    {
        $parts = array_map(static fn ($s) => "COALESCE({$s}, '')", $expressions);

        return $this->connection->getDriverName() === 'sqlite' ? '('.implode(' || ', $parts).')' : 'CONCAT('.implode(', ', $parts).')';
    }

    /** Aggregate each passed subject once through the selected term. */
    private function directoryMetricQueries(LegacyTableSet $set, array $terms, string $term, ?array $codes = null, ?array $population = null): array
    {
        $asOf = array_values(array_unique(array_merge(...array_map(AcademicTerm::variants(...), array_values(array_filter($terms, static fn ($t) => AcademicTerm::compare($t, $term) <= 0))))));
        $asOf = array_values(array_unique([...$asOf, ...AcademicTerm::variants($term)]));
        $slots = implode(',', array_fill(0, count($asOf), '?'));
        $grade = $this->identifier($set->grade);
        $subject = $this->identifier($set->subject);
        $credit = "CAST(COALESCE(sub.sub_credit, '0') AS DECIMAL(10,2))";
        $selectedVariants = AcademicTerm::variants($term);
        $selectedSlots = implode(',', array_fill(0, count($selectedVariants), '?'));
        $numeric = "TRIM(COALESCE(g.grade,'')) REGEXP '^[0-9]+([.][0-9]+)?$'";
        $passed = "(({$numeric} AND CAST(g.grade AS DECIMAL(10,2)) >= 1) OR TRIM(COALESCE(g.typ_code,'')) = '1' OR TRIM(COALESCE(g.grade,'')) IN ('ผ','ผ่าน'))";
        $codeFilter = $codes === null ? '' : ' AND g._perf_std10 IN ('.implode(',', array_fill(0, count($codes), '?')).')';
        $populationBindings = $codes ?? ($population['bindings'] ?? []);
        if ($codes === null && $population !== null) {
            $codeFilter = " AND g._perf_std10 IN (SELECT code FROM ({$population['sql']}) metric_population)";
        }
        $perSubject = "SELECT g._perf_std10 AS code, g._perf_sub AS subject, MAX(CASE WHEN {$numeric} AND CAST(g.grade AS DECIMAL(10,2)) >= 1 THEN CAST(g.grade AS DECIMAL(10,2)) END) AS numeric_grade, SUM(CASE WHEN {$numeric} AND CAST(g.grade AS DECIMAL(10,2)) >= 1 THEN CAST(g.grade AS DECIMAL(10,2)) * {$credit} ELSE 0 END) AS weighted_points, SUM(CASE WHEN {$numeric} AND CAST(g.grade AS DECIMAL(10,2)) >= 1 THEN {$credit} ELSE 0 END) AS graded_credits, MAX(CASE WHEN g._perf_semestry IN ({$selectedSlots}) AND TRIM(COALESCE(g.grade,'')) IN ('','-') THEN {$credit} ELSE 0 END) AS pending, MAX(CASE WHEN {$passed} THEN {$credit} ELSE 0 END) AS earned, MAX(CASE WHEN {$passed} AND TRIM(COALESCE(sub.sub_type,'')) = '1' THEN {$credit} ELSE 0 END) AS compulsory, MAX(CASE WHEN {$passed} AND TRIM(COALESCE(sub.sub_type,'')) <> '1' THEN {$credit} ELSE 0 END) AS elective FROM {$grade} g LEFT JOIN {$subject} sub ON sub._perf_sub = g._perf_sub WHERE g._perf_semestry IN ({$slots}) {$codeFilter} GROUP BY g._perf_std10, g._perf_sub";
        $academic = "SELECT code, SUM(earned) AS credits_earned, SUM(earned + CASE WHEN earned = 0 THEN pending ELSE 0 END) AS credits_current, SUM(compulsory) AS compulsory_earned, SUM(elective) AS elective_earned, 1.0 * SUM(weighted_points) / NULLIF(SUM(graded_credits), 0) AS gpax FROM ({$perSubject}) earned_subjects GROUP BY code";
        $hours = 'SELECT NULL AS code, 0 AS hours WHERE 1 = 0';
        $hourBindings = [];
        if ($set->activity !== null) {
            $activity = $this->identifier($set->activity);
            $activityFilter = $codes === null ? '' : ' AND _perf_std10 IN ('.implode(',', array_fill(0, count($codes), '?')).')';
            if ($codes === null && $population !== null) {
                $activityFilter = " AND _perf_std10 IN (SELECT code FROM ({$population['sql']}) activity_population)";
            }
            $hours = "SELECT _perf_std10 AS code, SUM(CAST(COALESCE(hour,'0') AS DECIMAL(10,2))) AS hours FROM {$activity} WHERE _perf_semestry IN ({$slots}) {$activityFilter} GROUP BY _perf_std10";
            $hourBindings = [...$asOf, ...$populationBindings];
        }

        return [$academic, [...$selectedVariants, ...$asOf, ...$populationBindings], $hours, $hourBindings];
    }

    private function directoryUnion(array $cohorts, array $sets, array $terms, string $term, bool $metrics, array $filters): array
    {
        $queries = [];
        $bindings = [];
        foreach ($sets as $set) {
            $cohort = $cohorts[$set->level] ?? null;
            if ($cohort === null) {
                continue;
            }
            if ($metrics) {
                [$academic, $academicBindings, $hours, $hourBindings] = $this->directoryMetricQueries($set, $terms, $term, null, $cohort);
                $queries[] = "SELECT c.*, ROUND(COALESCE(a.gpax,0),2) AS gpax, COALESCE(a.credits_earned,0) AS credits_earned, COALESCE(k.hours,0) AS kpch_hours FROM ({$cohort['sql']}) c LEFT JOIN ({$academic}) a ON a.code = c.code LEFT JOIN ({$hours}) k ON k.code = c.code";
                array_push($bindings, ...$cohort['bindings'], ...$academicBindings, ...$hourBindings);
            } else {
                $queries[] = $cohort['sql'];
                array_push($bindings, ...$cohort['bindings']);
            }
        }
        $sql = implode(' UNION ALL ', $queries);
        if ($sql !== '' && ! empty($filters['kpch_status'])) {
            $comparison = $filters['kpch_status'] === 'complete' ? '>=' : '<';
            $sql = "SELECT * FROM ({$sql}) kpch_filter WHERE kpch_hours {$comparison} 200";
        }

        return [$sql, $bindings];
    }

    private function directoryHydrate(array $rows, array $sets, array $terms, string $term, int $districtId, string $districtName): array
    {
        $metrics = [];
        $kpch = [];
        foreach ($sets as $set) {
            $codes = array_values(array_unique(array_column(array_filter($rows, static fn ($r) => (int) $r['level_id'] === $set->level), 'code')));
            if ($codes === []) {
                continue;
            }
            [$sql, $bindings, $hours, $hourBindings] = $this->directoryMetricQueries($set, $terms, $term, $codes);
            foreach ($this->rows($sql, $bindings) as $row) {
                $metrics[$set->level.'|'.$row['code']] = $row;
            }
            foreach ($this->rows($hours, $hourBindings) as $row) {
                $kpch[$set->level.'|'.$row['code']] = $row['hours'];
            }
        }

        return array_map(function ($row) use ($metrics, $kpch, $term, $districtId, $districtName) {
            $level = (int) $row['level_id'];
            $key = $level.'|'.$row['code'];
            $m = $metrics[$key] ?? [];
            [$required, $comp, $elec] = $this->creditRequirements($level);
            [$status, $statusLabel] = LegacyStudentStatus::resolve((string) $row['fin_cause'], (string) $row['transfer_date']);

            return new Student(code: $row['code'], districtId: $districtId, districtName: $districtName, prefix: trim((string) $row['prename']), firstName: trim((string) $row['first_name']), lastName: trim((string) $row['last_name']), level: $level, levelLabel: $this->levelLabel($level), groupCode: trim((string) $row['group_code']), groupName: trim((string) $row['group_name']), enrollmentTerm: AcademicTerm::normalize($row['enrollment_term']) ?? '', currentTerm: $term, status: $status, statusLabel: $statusLabel, gpax: round((float) ($m['gpax'] ?? 0), 2), creditsEarned: round((float) ($m['credits_earned'] ?? 0), 1), creditsRequired: $required, creditsCurrent: (float) ($m['credits_current'] ?? $m['credits_earned'] ?? 0), kpchHours: (float) ($kpch[$key] ?? 0), moralResult: 'ดูรายละเอียดรายภาคเรียน', demographics: array_filter(['birth_date' => $this->formatThaiDate((string) $row['birth_date']), 'gender' => $this->genderLabel((string) $row['gender']), 'citizen_id_masked' => (($id = $this->validCitizenId((string) $row['citizen_id'])) === null ? null : substr($id, 0, 1).'-xxxx-xxxxx-xx-'.substr($id, -1))]), compulsoryCreditsEarned: (float) ($m['compulsory_earned'] ?? 0), compulsoryCreditsRequired: $comp, electiveCreditsEarned: (float) ($m['elective_earned'] ?? 0), electiveCreditsRequired: $elec, dataClassification: 'personal_data_sensitive', citizenId: $this->validCitizenId((string) $row['citizen_id']));
        }, $rows);
    }

    public function directoryStudent(User $viewer, string $code, ?int $level, ?string $term): ?Student
    {
        [$districtId, $districtName, $sets, $terms] = $this->directoryContext($viewer);
        $this->assertDirectoryIdentity($viewer, $sets, $terms);
        if ($sets === [] || $terms === []) {
            return null;
        }
        $selected = $term ?? $terms[0];
        $cohorts = $this->directoryCohorts($sets, $viewer, $selected, $terms[0], array_filter(['_code' => trim($code), 'level' => $level]), $term === null);
        [$sql, $bindings] = $this->directoryUnion($cohorts, $sets, [], $selected, false, []);
        if ($sql === '') {
            return null;
        }
        $rows = $this->rows("SELECT * FROM ({$sql}) directory_identity LIMIT 2", $bindings);
        if (count($rows) !== 1) {
            return null;
        }
        $record = $this->directoryHydrate($rows, $sets, $terms, $selected, $districtId, $districtName)[0];
        $set = array_values(array_filter($sets, static fn ($s) => $s->level === $record->level))[0];
        $table = $this->identifier($set->student);
        $fields = $this->studentSelectFields($set, 's.grp_code', $this->citizenIdSql($set));
        $details = $this->rows("SELECT {$fields} FROM {$table} s WHERE s._perf_id10 = ? LIMIT 2", [$record->code]);
        if (count($details) !== 1) {
            return null;
        }
        $row = $details[0];
        $phone = $this->phoneMemoValue($set, $code, 'curphone', (string) ($row['curphone'] ?? '')) ?? $this->phoneMemoValue($set, $code, 'phone', (string) ($row['phone'] ?? ''));
        $email = $this->memoValue($set, $code, 'email', (string) ($row['email'] ?? ''));
        $moral = 'ยังไม่มีผลประเมิน';
        foreach ($this->moralFor($record) as $assessment) {
            $assessmentTerm = AcademicTerm::normalize($assessment->term);
            if ($assessmentTerm !== null && AcademicTerm::compare($assessmentTerm, $selected) <= 0) {
                $moral = $assessment->result;
                break;
            }
        }

        return new Student(...[...get_object_vars($record), 'phone' => $phone, 'email' => $email, 'moralResult' => $moral, 'contact' => ['phone_masked' => $this->maskPhone($phone ?? ''), 'email_masked' => $this->maskEmail($email ?? '')], 'demographics' => [...$record->demographics, 'application_date' => $this->formatThaiDate((string) ($row['application_date'] ?? '')), 'last_updated' => $this->formatThaiDate((string) ($row['last_updated'] ?? ''))], 'registeredAddress' => $this->formatAddress($this->memoValue($set, $code, 'addr', (string) ($row['registered_address'] ?? '')) ?? '', (string) ($row['registered_area_code'] ?? ''), (string) ($row['registered_postcode'] ?? '')), 'currentAddress' => $this->formatAddress($this->memoValue($set, $code, 'curaddr', (string) ($row['current_address'] ?? '')) ?? '', (string) ($row['current_area_code'] ?? ''), (string) ($row['current_postcode'] ?? ''))]);
    }
}
