<?php

namespace App\Domain\Students\Services;

use App\Domain\Students\Support\AcademicTerm;
use App\Domain\Students\Support\LegacyStudentStatus;
use App\Domain\Students\Support\LegacyTableSet;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Historical reports backed directly by the latest successful legacy import.
 *
 * The regular student directory deliberately exposes only the current active
 * cohort. These reports must also include historical graduates and transfer
 * records, so they use SELECT-only queries against the district's import batch.
 */
final readonly class LegacyStudentReportService
{
    public function __construct(private DatabaseManager $database) {}

    /** @param array<string, mixed> $filters */
    public function newStudents(User $viewer, int $districtId, array $filters): array
    {
        $sets = $this->sets($districtId);
        $terms = $this->newStudentTerms($sets);
        $selectedTerm = $this->selectedTerm($filters, $terms);
        $rows = [];

        foreach ($this->filteredSets($sets, $filters) as $set) {
            [$join, $groupName] = $this->groupJoin($set, 's');
            [$scopeSql, $scopeBindings] = $this->scope($viewer, $set, 's', $join !== '');
            $conditions = [$scopeSql];
            $bindings = $scopeBindings;

            if ($selectedTerm !== null) {
                [$semester, $year] = array_map('intval', explode('/', $selectedTerm));
                $conditions[] = 's._perf_id10 LIKE ?';
                $bindings[] = str_pad((string) ($year % 100), 2, '0', STR_PAD_LEFT).$semester.'%';
            }
            $this->appendGroupAndSearchFilters($conditions, $bindings, $filters, $join !== '', 's', $groupName);

            $student = $this->identifier($set->student);
            foreach ($this->rows(
                "SELECT s._perf_id10 AS student_code, s.prename, s.name AS first_name,
                        s.surname AS last_name, s.grp_code AS group_code, {$groupName} AS group_name,
                        s.dep_sem AS raw_term, s.fin_cause, s.trn_date2 AS transfer_date
                 FROM {$student} s {$join}
                 WHERE ".implode(' AND ', array_filter($conditions)).'
                 ORDER BY s.name ASC, s.surname ASC, s._perf_id10 ASC',
                $bindings,
            ) as $row) {
                $code = trim((string) ($row['student_code'] ?? ''));
                $term = $this->enrollmentTerm($code, (string) ($row['raw_term'] ?? ''));
                if ($code === '' || $term === null || ($selectedTerm !== null && $term !== $selectedTerm)) {
                    continue;
                }
                [$status] = LegacyStudentStatus::resolve((string) ($row['fin_cause'] ?? ''), (string) ($row['transfer_date'] ?? ''));
                $rows[] = [
                    'id' => "{$set->districtId}-{$set->level}-{$code}",
                    'primary' => $this->fullName($row),
                    'secondary' => $code,
                    'group' => $this->levelLabel($set->level).' · '.$this->groupLabel($row),
                    'metric' => 'ภาคเรียน '.$term,
                    '_active' => $status === 'studying',
                ];
            }
        }

        return $this->payload($rows, $terms, $selectedTerm, static fn (array $row): bool => (bool) ($row['_active'] ?? false));
    }

    /** @param array<string, mixed> $filters */
    public function graduates(User $viewer, int $districtId, array $filters): array
    {
        $sets = $this->sets($districtId);
        $terms = $this->distinctTerms($sets, 'student', 'fin_sem', "TRIM(COALESCE(fin_cause, '')) = '1'");
        $selectedTerm = $this->selectedTerm($filters, $terms);
        $rows = [];

        foreach ($this->filteredSets($sets, $filters) as $set) {
            [$join, $groupName] = $this->groupJoin($set, 's');
            [$scopeSql, $scopeBindings] = $this->scope($viewer, $set, 's', $join !== '');
            $conditions = [$scopeSql, "TRIM(COALESCE(s.fin_cause, '')) = '1'"];
            $bindings = $scopeBindings;
            if ($selectedTerm !== null) {
                $variants = AcademicTerm::variants($selectedTerm);
                $conditions[] = 'TRIM(s.fin_sem) IN ('.implode(',', array_fill(0, count($variants), '?')).')';
                array_push($bindings, ...$variants);
            }
            $this->appendGroupAndSearchFilters($conditions, $bindings, $filters, $join !== '', 's', $groupName);

            $student = $this->identifier($set->student);
            foreach ($this->rows(
                "SELECT s._perf_id10 AS student_code, s.prename, s.name AS first_name,
                        s.surname AS last_name, s.grp_code AS group_code, {$groupName} AS group_name,
                        s.fin_sem AS raw_term, s.fin_date AS graduation_date
                 FROM {$student} s {$join}
                 WHERE ".implode(' AND ', array_filter($conditions)).'
                 ORDER BY s.name ASC, s.surname ASC, s._perf_id10 ASC',
                $bindings,
            ) as $row) {
                $code = trim((string) ($row['student_code'] ?? ''));
                $term = AcademicTerm::normalize((string) ($row['raw_term'] ?? ''));
                if ($code === '' || $term === null) {
                    continue;
                }
                $rows[] = [
                    'id' => "{$set->districtId}-{$set->level}-{$code}-{$term}",
                    'primary' => $this->fullName($row),
                    'secondary' => $code,
                    'group' => $this->levelLabel($set->level).' · '.$this->groupLabel($row),
                    'metric' => 'ภาคเรียน '.$term,
                ];
            }
        }

        return $this->payload($rows, $terms, $selectedTerm, static fn (): bool => true);
    }

    /** @param array<string, mixed> $filters */
    public function expectedGraduates(User $viewer, int $districtId, array $filters): array
    {
        try {
            $sets = $this->sets($districtId);
            $terms = $this->registeredSubjectTerms($viewer, $sets, $filters);
            $selectedTerm = $this->selectedTerm($filters, $terms);
            $selectedTermVariants = $selectedTerm !== null ? AcademicTerm::variants($selectedTerm) : [];
            $rows = [];

            foreach ($this->filteredSets($sets, $filters) as $set) {
                [$join, $groupName] = $this->groupJoin($set, 's');
                [$scopeSql, $scopeBindings] = $this->scope($viewer, $set, 's', $join !== '');
                $conditions = [$scopeSql, "TRIM(COALESCE(s.fin_cause, '')) <> '1'"];
                $bindings = $scopeBindings;
                $this->appendGroupAndSearchFilters($conditions, $bindings, $filters, $join !== '', 's', $groupName);

                $student = $this->identifier($set->student);
                $grade = $this->identifier($set->grade);
                $subject = $this->identifier($set->subject);

                [$reqTotal, $reqComp, $reqElec] = match ($set->level) {
                    1 => [48.0, 36.0, 12.0],
                    2 => [56.0, 40.0, 16.0],
                    3 => [76.0, 44.0, 32.0],
                    default => [0.0, 0.0, 0.0],
                };

                $ntCol1 = $this->firstExistingColumn($set->student, ['nt_sara1', 'nt_sem']);
                $ntCol2 = $this->firstExistingColumn($set->student, ['nt_sara2', 'nt_nosem']);
                $nnetCol = $this->firstExistingColumn($set->student, ['nnet', 'n_net', 'eexam', 'e_exam', 'nnet_stat', 'exm_status']);

                $ntSql1 = $ntCol1 !== null ? ", s.{$ntCol1} AS nt_val1" : ", '' AS nt_val1";
                $ntSql2 = $ntCol2 !== null ? ", s.{$ntCol2} AS nt_val2" : ", '' AS nt_val2";
                $nnetSql = $nnetCol !== null ? ", s.{$nnetCol} AS nnet_val" : ", '' AS nnet_val";

                $studentsSql = "SELECT s._perf_id10 AS student_code, s.prename, s.name AS first_name,
                                       s.surname AS last_name, s.grp_code AS group_code, {$groupName} AS group_name
                                       {$ntSql1} {$ntSql2} {$nnetSql}
                                FROM {$student} s {$join}
                                WHERE ".implode(' AND ', array_filter($conditions)).'
                                ORDER BY s.name ASC, s.surname ASC, s._perf_id10 ASC';

                $studentList = $this->rows($studentsSql, $bindings);
                if ($studentList === []) {
                    continue;
                }

                $studentCodes = array_values(array_unique(array_filter(array_map(
                    static fn (array $row): string => trim((string) ($row['student_code'] ?? '')),
                    $studentList,
                ))));

                if ($studentCodes === []) {
                    continue;
                }

                $placeholders = implode(',', array_fill(0, count($studentCodes), '?'));
                $academicSql = "SELECT g._perf_std10 AS student_code,
                                       g.grade,
                                       g.typ_code,
                                       g._perf_semestry AS term,
                                       sub.sub_type,
                                       sub.sub_credit,
                                       sub.sub_code,
                                       sub.sub_name
                                FROM {$grade} g
                                LEFT JOIN {$subject} sub ON sub._perf_sub = g._perf_sub
                                WHERE g._perf_std10 IN ({$placeholders})";

                $academicRows = $this->rows($academicSql, $studentCodes);
                $studentMetrics = [];
                $registeredInSelectedTerm = [];

                foreach ($academicRows as $aRow) {
                    $code = trim((string) ($aRow['student_code'] ?? ''));
                    if ($code === '') {
                        continue;
                    }
                    $studentMetrics[$code] ??= [
                        'compulsory_earned' => 0.0,
                        'elective_earned' => 0.0,
                        'compulsory_registered' => 0.0,
                        'elective_registered' => 0.0,
                        'exam_taken' => false,
                    ];

                    $gradeVal = trim((string) ($aRow['grade'] ?? ''));
                    $subType = trim((string) ($aRow['sub_type'] ?? ''));
                    $typCode = trim((string) ($aRow['typ_code'] ?? ''));
                    $subCode = strtoupper(trim((string) ($aRow['sub_code'] ?? '')));
                    $subName = strtoupper(trim((string) ($aRow['sub_name'] ?? '')));
                    $credit = (float) ($aRow['sub_credit'] ?? 0);
                    $rawTerm = (string) ($aRow['term'] ?? '');
                    $term = AcademicTerm::normalize($rawTerm);

                    $isTermMatch = $selectedTerm === null || ($term !== null && in_array($term, $selectedTermVariants, true)) || in_array(trim($rawTerm), $selectedTermVariants, true);

                    if ($isTermMatch) {
                        $registeredInSelectedTerm[$code] = true;
                    }

                    $isNumericPassed = is_numeric($gradeVal) && (float) $gradeVal >= 1.0;
                    $isExamSubject = str_contains($subCode, 'NET') || str_contains($subCode, 'EXAM') || str_contains($subName, 'N-NET') || str_contains($subName, 'E-EXAM');

                    if (($isExamSubject || in_array($typCode, ['2', '3'], true)) && ! in_array($gradeVal, ['', '-'], true)) {
                        $studentMetrics[$code]['exam_taken'] = true;
                    }

                    $isCurrentTermRegistration = $isTermMatch || in_array($gradeVal, ['', '-'], true);
                    $isElective = in_array($subType, ['2', '3'], true);

                    if ($isNumericPassed) {
                        if ($isElective) {
                            $studentMetrics[$code]['elective_earned'] += $credit;
                        } else {
                            $studentMetrics[$code]['compulsory_earned'] += $credit;
                        }
                    } elseif ($isCurrentTermRegistration) {
                        if ($isElective) {
                            $studentMetrics[$code]['elective_registered'] += $credit;
                        } else {
                            $studentMetrics[$code]['compulsory_registered'] += $credit;
                        }
                    }
                }

                foreach ($studentList as $sRow) {
                    $code = trim((string) ($sRow['student_code'] ?? ''));
                    if ($code === '') {
                        continue;
                    }

                    if ($selectedTerm !== null && empty($registeredInSelectedTerm[$code])) {
                        continue;
                    }

                    $m = $studentMetrics[$code] ?? [
                        'compulsory_earned' => 0.0,
                        'elective_earned' => 0.0,
                        'compulsory_registered' => 0.0,
                        'elective_registered' => 0.0,
                        'exam_taken' => false,
                    ];
                    $compTotal = $m['compulsory_earned'] + $m['compulsory_registered'];
                    $elecTotal = $m['elective_earned'] + $m['elective_registered'];
                    $grandTotal = $compTotal + $elecTotal;

                    $nnetVal = strtoupper(trim((string) ($sRow['nnet_val'] ?? '')));
                    $ntVal1 = trim((string) ($sRow['nt_val1'] ?? ''));
                    $ntVal2 = trim((string) ($sRow['nt_val2'] ?? ''));
                    $hasStudentFlag = in_array($nnetVal, ['1', 'Y', 'P', 'PASS', 'PASSED', 'สอบแล้ว', 'ผ่าน'], true)
                        || $ntVal1 !== '' || $ntVal2 !== '';
                    $isExamTaken = ! empty($m['exam_taken']) || $hasStudentFlag;

                    // Active student qualifies for expected graduation if total credits meet or approach graduation requirements
                    if ($grandTotal >= $reqTotal || ($compTotal >= $reqComp && $elecTotal >= $reqElec)) {
                        $rows[] = [
                            'id' => "{$set->districtId}-{$set->level}-{$code}",
                            'primary' => $this->fullName($sRow),
                            'secondary' => $code,
                            'group' => $this->levelLabel($set->level).' · '.$this->groupLabel($sRow),
                            'metric' => number_format($grandTotal, 0).'/'.number_format($reqTotal, 0).' หน่วยกิต (บังคับ '.number_format($compTotal, 0).' / เลือก '.number_format($elecTotal, 0).')',
                            'examStatus' => $isExamTaken ? 'สอบแล้ว' : 'ยังไม่ได้สอบ',
                        ];
                    }
                }
            }

            return $this->payload($rows, $terms, $selectedTerm, static fn (): bool => true);
        } catch (\Throwable) {
            return $this->payload([], [], null, static fn (): bool => true);
        }
    }

    /** @param array<string, mixed> $filters */
    public function transfers(User $viewer, int $districtId, array $filters): array
    {
        $sets = $this->sets($districtId);
        $terms = $this->distinctTerms($sets, 'grade', '_perf_semestry', "TRIM(COALESCE(typ_code, '')) = '1'");
        $selectedTerm = $this->selectedTerm($filters, $terms);
        $rows = [];
        $seen = [];

        foreach ($this->filteredSets($sets, $filters) as $set) {
            [$groupJoin, $groupName] = $this->groupJoin($set, 'st');
            [$scopeSql, $scopeBindings] = $this->scope($viewer, $set, 'st', $groupJoin !== '');
            $conditions = [$scopeSql, "TRIM(COALESCE(g.typ_code, '')) = '1'"];
            $bindings = $scopeBindings;
            if ($selectedTerm !== null) {
                $variants = AcademicTerm::variants($selectedTerm);
                $conditions[] = 'TRIM(g._perf_semestry) IN ('.implode(',', array_fill(0, count($variants), '?')).')';
                array_push($bindings, ...$variants);
            }
            $this->appendGroupAndSearchFilters($conditions, $bindings, $filters, $groupJoin !== '', 'st', $groupName, 'sub');

            $student = $this->identifier($set->student);
            $grade = $this->identifier($set->grade);
            $subject = $this->identifier($set->subject);
            foreach ($this->rows(
                "SELECT g._id AS row_id, g._perf_std10 AS student_code, g._perf_sub AS subject_code,
                        g._perf_semestry AS raw_term, sub.sub_name AS subject_name,
                        sub.sub_credit AS subject_credit, st.prename, st.name AS first_name,
                        st.surname AS last_name, st.grp_code AS group_code, {$groupName} AS group_name
                 FROM {$grade} g
                 INNER JOIN {$student} st ON st._perf_id10 = g._perf_std10
                 LEFT JOIN {$subject} sub ON sub._perf_sub = g._perf_sub
                 {$groupJoin}
                 WHERE ".implode(' AND ', array_filter($conditions)).'
                 ORDER BY g._perf_semestry DESC, g._perf_std10 ASC, g._perf_sub ASC, g._id DESC',
                $bindings,
            ) as $row) {
                $code = trim((string) ($row['student_code'] ?? ''));
                $subjectCode = trim((string) ($row['subject_code'] ?? ''));
                $term = AcademicTerm::normalize((string) ($row['raw_term'] ?? ''));
                $key = "{$set->level}|{$code}|{$term}|{$subjectCode}";
                if ($code === '' || $subjectCode === '' || $term === null || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $subjectName = trim((string) ($row['subject_name'] ?? '')) ?: 'ไม่พบชื่อรายวิชา';
                $credits = (float) ($row['subject_credit'] ?? 0);
                $rows[] = [
                    'id' => "{$set->districtId}-{$set->level}-{$code}-{$term}-{$subjectCode}",
                    'primary' => $subjectName,
                    'secondary' => $subjectCode,
                    'group' => $this->fullName($row).' · '.$code,
                    'metric' => number_format($credits, 1).' หน่วยกิต',
                ];
            }
        }

        return $this->payload($rows, $terms, $selectedTerm, static fn (): bool => true);
    }

    /**
     * Report registrations directly from the historical grade tables.
     *
     * The current student directory contains only the latest active cohort, so
     * using it as the starting point drops graduates, transfers and other
     * students who registered in an older term.  The legacy report instead
     * starts at grade and inner-joins student, matching the original system.
     *
     * @param  array<string, mixed>  $filters
     */
    public function registeredSubjects(User $viewer, int $districtId, array $filters): array
    {
        $sets = $this->filteredSets($this->sets($districtId), $filters);
        $terms = $this->registeredSubjectTerms($viewer, $sets, $filters);
        $selectedTerm = $this->selectedTerm($filters, $terms);
        $registrations = $selectedTerm === null
            ? []
            : $this->registeredSubjectRows($viewer, $sets, $filters, $selectedTerm);

        if (($filters['view'] ?? 'subject') === 'student') {
            return $this->registeredSubjectStudents($registrations, $terms, $selectedTerm);
        }

        $grouped = [];
        $studentCodes = [];
        foreach ($registrations as $registration) {
            $key = $registration['level'].'|'.$registration['subject_code'];
            $grouped[$key] ??= [
                'id' => $key,
                'primary' => $registration['subject_name'],
                'secondary' => $registration['subject_code'],
                'group' => $this->levelLabel((int) $registration['level']),
                'count' => 0,
            ];
            $grouped[$key]['count']++;
            $studentCodes[$registration['level'].'|'.$registration['student_code']] = true;
        }

        $rows = array_values(array_map(static fn (array $row): array => [
            'id' => $row['id'],
            'primary' => $row['primary'],
            'secondary' => $row['secondary'],
            'group' => $row['group'],
            'metric' => number_format((int) $row['count']).' คน',
        ], $grouped));
        usort($rows, static fn (array $left, array $right): int => [
            $left['group'], $left['secondary'],
        ] <=> [
            $right['group'], $right['secondary'],
        ]);

        return [
            'total' => count($rows),
            'active' => count($rows),
            'groups' => count(array_unique(array_column($rows, 'group'))),
            'summary' => [
                'subject_count' => count($rows),
                'unique_students' => count($studentCodes),
                'registered_records' => count($registrations),
            ],
            'terms' => $terms,
            'selected_term' => $selectedTerm,
            'rows' => $rows,
        ];
    }

    /** @param array<string, mixed> $filters */
    public function gradesAboveTwo(User $viewer, int $districtId, array $filters): array
    {
        return $this->historicalAcademicReport($viewer, $districtId, $filters, 'grade-threshold');
    }

    /** @param array<string, mixed> $filters */
    public function examAttendance(User $viewer, int $districtId, array $filters): array
    {
        return $this->historicalAcademicReport($viewer, $districtId, $filters, 'exam-attendance');
    }

    /** @return list<LegacyTableSet> */
    private function sets(int $districtId): array
    {
        $connection = $this->database->connection((string) config('legacy.connection', 'legacy'));
        $batch = $connection->selectOne(
            "SELECT ib.batch_key
             FROM import_batches ib
             INNER JOIN import_history ih ON ih.id = ib.import_history_id
                AND BINARY ih.batch_key = BINARY ib.batch_key
                AND ih.district_id = ib.district_id AND ih.status = 'success'
             WHERE ib.district_id = ?
             ORDER BY COALESCE(ib.created_at, ih.created_at) DESC, ib.batch_key DESC LIMIT 1",
            [$districtId],
        );
        $batchKey = trim((string) ($batch->batch_key ?? ''));
        if (preg_match('/^import_\d{10}_[A-Za-z0-9]+$/', $batchKey) !== 1) {
            return [];
        }

        $tableRows = $connection->select(
            'SELECT TABLE_NAME AS table_name FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?',
            ["db_{$batchKey}_%"],
        );
        $prefix = 'db_'.preg_quote($batchKey, '/').'_';
        $tables = [];
        $globalGroups = [];
        $levelGroups = [];
        foreach ($tableRows as $row) {
            $table = trim((string) ($row->table_name ?? ''));
            if (! $this->validIdentifier($table) || preg_match("/^{$prefix}/", $table) !== 1) {
                continue;
            }
            if (preg_match("/^{$prefix}([123])_(student|grade|subject)$/", $table, $matches) === 1) {
                $tables[(int) $matches[1]][$matches[2]] = $table;
            } elseif (preg_match("/^{$prefix}([123])_group$/", $table, $matches) === 1) {
                $levelGroups[(int) $matches[1]][] = $table;
            } elseif (str_ends_with($table, '_group')) {
                $globalGroups[] = $table;
            }
        }

        $sets = [];
        foreach ([1, 2, 3] as $level) {
            $levelTables = $tables[$level] ?? [];
            if (! isset($levelTables['student'], $levelTables['grade'], $levelTables['subject'])) {
                continue;
            }
            $groups = $levelGroups[$level] ?? $globalGroups;
            $sets[] = new LegacyTableSet($districtId, '', $batchKey, $level, $levelTables['student'], $levelTables['grade'], $levelTables['subject'], null, null, count($groups) === 1 ? $groups[0] : null);
        }

        return $sets;
    }

    /** @param list<LegacyTableSet> $sets @return list<LegacyTableSet> */
    private function filteredSets(array $sets, array $filters): array
    {
        return array_values(array_filter($sets, static fn (LegacyTableSet $set): bool => ! isset($filters['level']) || (int) $filters['level'] === $set->level));
    }

    /** @return array{string, string} */
    private function groupJoin(LegacyTableSet $set, string $studentAlias): array
    {
        if ($set->group === null) {
            return ['', "{$studentAlias}.grp_code"];
        }
        $group = $this->identifier($set->group);

        return ["LEFT JOIN {$group} grp ON grp._perf_grp = {$studentAlias}._perf_grp", "COALESCE(NULLIF(TRIM(grp.grp_name), ''), {$studentAlias}.grp_code)"];
    }

    /** @return array{string, list<string>} */
    private function scope(User $viewer, LegacyTableSet $set, string $studentAlias, bool $hasGroupJoin): array
    {
        if ($viewer->role !== 'teacher') {
            return ['1 = 1', []];
        }
        $groups = array_values(array_unique(array_filter(array_map(static fn (mixed $group): string => trim((string) $group), is_array($viewer->assigned_groups) ? $viewer->assigned_groups : []))));
        if ($groups === []) {
            return ['1 = 0', []];
        }
        $placeholders = implode(',', array_fill(0, count($groups), '?'));
        $clauses = ["{$studentAlias}.grp_code IN ({$placeholders})"];
        $bindings = $groups;
        if ($hasGroupJoin) {
            $clauses[] = "grp.grp_name IN ({$placeholders})";
            array_push($bindings, ...$groups);
        }

        return ['('.implode(' OR ', $clauses).')', $bindings];
    }

    /** @param list<string> $conditions @param list<mixed> $bindings */
    private function appendGroupAndSearchFilters(array &$conditions, array &$bindings, array $filters, bool $hasGroupJoin, string $studentAlias, string $groupExpression, string $subjectAlias = ''): void
    {
        if (isset($filters['group']) && trim((string) $filters['group']) !== '') {
            $conditions[] = $hasGroupJoin ? "({$studentAlias}.grp_code = ? OR {$groupExpression} = ?)" : "{$studentAlias}.grp_code = ?";
            $bindings[] = trim((string) $filters['group']);
            if ($hasGroupJoin) {
                $bindings[] = trim((string) $filters['group']);
            }
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search === '') {
            return;
        }
        $like = '%'.$search.'%';
        $searches = ["{$studentAlias}._perf_id10 LIKE ?", "{$studentAlias}.name LIKE ?", "{$studentAlias}.surname LIKE ?", "{$studentAlias}.grp_code LIKE ?"];
        $searchBindings = [$like, $like, $like, $like];
        if ($subjectAlias !== '') {
            $searches[] = "{$subjectAlias}._perf_sub LIKE ?";
            $searches[] = "{$subjectAlias}.sub_name LIKE ?";
            $searchBindings[] = $like;
            $searchBindings[] = $like;
        }
        $conditions[] = '('.implode(' OR ', $searches).')';
        array_push($bindings, ...$searchBindings);
    }

    /** @param list<LegacyTableSet> $sets @return list<string> */
    private function newStudentTerms(array $sets): array
    {
        $terms = [];
        foreach ($sets as $set) {
            $table = $this->identifier($set->student);
            foreach ($this->rows("SELECT DISTINCT LEFT(TRIM(_perf_id10), 3) AS raw_term FROM {$table} WHERE _perf_id10 REGEXP '^[0-9]{2}[12]'") as $row) {
                $raw = (string) ($row['raw_term'] ?? '');
                if (preg_match('/^(\d{2})([12])$/', $raw, $matches) === 1) {
                    $terms[] = $matches[2].'/25'.$matches[1];
                }
            }
        }

        return $this->sortTerms($terms);
    }

    /** @param list<LegacyTableSet> $sets @return list<string> */
    private function distinctTerms(array $sets, string $tableProperty, string $column, string $where): array
    {
        $terms = [];
        foreach ($sets as $set) {
            $tableName = $set->{$tableProperty};
            $table = $this->identifier($tableName);
            $quotedColumn = $this->identifier($column);
            foreach ($this->rows("SELECT DISTINCT {$quotedColumn} AS raw_term FROM {$table} WHERE {$where}") as $row) {
                $normalized = AcademicTerm::normalize((string) ($row['raw_term'] ?? ''));
                if ($normalized !== null) {
                    $terms[] = $normalized;
                }
            }
        }

        return $this->sortTerms($terms);
    }

    /**
     * Term options for registrations must use the same historical student join
     * and access scope as the rows themselves. This prevents an orphan grade or
     * an inaccessible teacher group from becoming the default term.
     *
     * @param  list<LegacyTableSet>  $sets
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    private function registeredSubjectTerms(User $viewer, array $sets, array $filters): array
    {
        $terms = [];
        foreach ($sets as $set) {
            [$groupJoin, $groupName] = $this->groupJoin($set, 'st');
            [$scopeSql, $scopeBindings] = $this->scope($viewer, $set, 'st', $groupJoin !== '');
            $conditions = [$scopeSql];
            $bindings = $scopeBindings;
            $termFilters = $filters;
            unset($termFilters['search'], $termFilters['subject']);
            $this->appendGroupAndSearchFilters($conditions, $bindings, $termFilters, $groupJoin !== '', 'st', $groupName);

            $grade = $this->identifier($set->grade);
            $student = $this->identifier($set->student);
            foreach ($this->rows(
                "SELECT DISTINCT g._perf_semestry AS raw_term
                 FROM {$grade} g
                 INNER JOIN {$student} st ON st._perf_id10 = g._perf_std10
                 {$groupJoin}
                 WHERE ".implode(' AND ', array_filter($conditions)),
                $bindings,
            ) as $row) {
                $term = AcademicTerm::normalize((string) ($row['raw_term'] ?? ''));
                if ($term !== null) {
                    $terms[] = $term;
                }
            }
        }

        return $this->sortTerms($terms);
    }

    /**
     * @param  list<LegacyTableSet>  $sets
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function registeredSubjectRows(User $viewer, array $sets, array $filters, string $selectedTerm): array
    {
        $candidates = [];
        $view = (string) ($filters['view'] ?? 'subject');

        foreach ($sets as $set) {
            [$groupJoin, $groupName] = $this->groupJoin($set, 'st');
            [$scopeSql, $scopeBindings] = $this->scope($viewer, $set, 'st', $groupJoin !== '');
            $variants = AcademicTerm::variants($selectedTerm);
            $conditions = [
                $scopeSql,
                'TRIM(g._perf_semestry) IN ('.implode(',', array_fill(0, count($variants), '?')).')',
            ];
            $bindings = [...$scopeBindings, ...$variants];

            $rowFilters = $filters;
            if ($view === 'subject') {
                unset($rowFilters['search']);
            }
            $this->appendGroupAndSearchFilters($conditions, $bindings, $rowFilters, $groupJoin !== '', 'st', $groupName);

            $subjectFilter = trim((string) ($filters['subject'] ?? ''));
            if ($subjectFilter !== '') {
                $conditions[] = 'TRIM(g._perf_sub) = ?';
                $bindings[] = $subjectFilter;
            }
            if ($view === 'subject') {
                $search = trim((string) ($filters['search'] ?? ''));
                if ($search !== '') {
                    $conditions[] = '(sub._perf_sub LIKE ? OR sub.sub_name LIKE ?)';
                    $bindings[] = '%'.$search.'%';
                    $bindings[] = '%'.$search.'%';
                }
            }

            $grade = $this->identifier($set->grade);
            $student = $this->identifier($set->student);
            $subject = $this->identifier($set->subject);
            foreach ($this->rows(
                "SELECT g._id AS row_id, g._perf_std10 AS student_code,
                        g._perf_sub AS subject_code, g._perf_semestry AS raw_term,
                        g.grade AS grade_value, g.typ_code AS typ_code,
                        sub.sub_name AS subject_name, sub.sub_credit AS subject_credit,
                        sub.sub_type AS subject_type, st.prename,
                        st.name AS first_name, st.surname AS last_name,
                        st.grp_code AS group_code, {$groupName} AS group_name
                 FROM {$grade} g
                 INNER JOIN {$student} st ON st._perf_id10 = g._perf_std10
                 LEFT JOIN {$subject} sub ON sub._perf_sub = g._perf_sub
                 {$groupJoin}
                 WHERE ".implode(' AND ', array_filter($conditions)).'
                 ORDER BY g._perf_std10 ASC, g._perf_sub ASC, g._id ASC',
                $bindings,
            ) as $row) {
                $studentCode = trim((string) ($row['student_code'] ?? ''));
                $subjectCode = trim((string) ($row['subject_code'] ?? ''));
                $term = AcademicTerm::normalize((string) ($row['raw_term'] ?? ''));
                $key = $set->level.'|'.$studentCode.'|'.$term.'|'.$subjectCode;
                if ($studentCode === '' || $subjectCode === '' || $term !== $selectedTerm) {
                    continue;
                }
                $gradeValue = trim((string) ($row['grade_value'] ?? ''));
                $candidates[$key][] = [
                    'row_id' => (int) ($row['row_id'] ?? 0),
                    'level' => $set->level,
                    'student_code' => $studentCode,
                    'student_name' => $this->fullName($row) ?: 'ไม่พบชื่อนักศึกษา',
                    'group_code' => trim((string) ($row['group_code'] ?? '')),
                    'group_name' => $this->groupLabel($row),
                    'subject_code' => $subjectCode,
                    'subject_name' => trim((string) ($row['subject_name'] ?? '')) ?: 'ไม่พบชื่อรายวิชา',
                    'subject_credit' => (float) ($row['subject_credit'] ?? 0),
                    'subject_type' => trim((string) ($row['subject_type'] ?? '')) === '1' ? 'compulsory' : 'elective',
                    'grade_value' => $gradeValue === '' || $gradeValue === '-' ? null : $gradeValue,
                    'transferred' => trim((string) ($row['typ_code'] ?? '')) === '1',
                    'exam_attended' => ! in_array($gradeValue, ['', '-', 'ข', 'ม'], true),
                ];
            }
        }

        return array_values(array_map(fn (array $rows): array => $this->preferredAcademicRegistration($rows), $candidates));
    }

    /**
     * @param  list<array<string, mixed>>  $registrations
     * @param  list<string>  $terms
     */
    private function registeredSubjectStudents(array $registrations, array $terms, string $selectedTerm): array
    {
        $students = [];
        foreach ($registrations as $registration) {
            $key = $registration['level'].'|'.$registration['student_code'];
            $students[$key] ??= [
                'student' => [
                    'code' => $registration['student_code'],
                    'full_name' => $registration['student_name'],
                    'level' => [
                        'id' => $registration['level'],
                        'label' => $this->levelLabel((int) $registration['level']),
                    ],
                    'group' => [
                        'code' => $registration['group_code'],
                        'name' => $registration['group_name'],
                    ],
                ],
                'term' => $selectedTerm,
                'registered_subjects' => 0,
                'grade_two_or_above' => 0,
                'attended_subjects' => 0,
                'absent_subjects' => 0,
                'success_rate' => 100.0,
            ];
            $students[$key]['registered_subjects']++;
        }

        $items = array_values($students);
        usort($items, static fn (array $left, array $right): int => strnatcasecmp(
            (string) $left['student']['full_name'],
            (string) $right['student']['full_name'],
        ));

        return [
            'items' => $items,
            'summary' => [
                'unique_students' => count($items),
                'registered_records' => count($registrations),
                'successful_records' => count($registrations),
                'success_rate' => $registrations === [] ? 0.0 : 100.0,
            ],
            'terms' => $terms,
            'selected_term' => $selectedTerm,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  'grade-threshold'|'exam-attendance'  $kind
     */
    private function historicalAcademicReport(User $viewer, int $districtId, array $filters, string $kind): array
    {
        $sets = $this->filteredSets($this->sets($districtId), $filters);
        $terms = $this->registeredSubjectTerms($viewer, $sets, $filters);
        $selectedTerm = $this->selectedTerm($filters, $terms);
        $registrations = $selectedTerm === null
            ? []
            : $this->registeredSubjectRows($viewer, $sets, $filters, $selectedTerm);

        if (($filters['view'] ?? 'subject') === 'student') {
            return $this->historicalAcademicStudents($registrations, $terms, $selectedTerm, $kind);
        }

        $grouped = [];
        $studentCodes = [];
        foreach ($registrations as $registration) {
            $key = $registration['level'].'|'.$registration['subject_code'];
            $grouped[$key] ??= [
                'term' => $selectedTerm,
                'level' => [
                    'id' => $registration['level'],
                    'label' => $this->levelLabel((int) $registration['level']),
                ],
                'subject' => [
                    'code' => $registration['subject_code'],
                    'name' => $registration['subject_name'],
                    'credits' => $registration['subject_credit'],
                    'type' => $registration['subject_type'],
                ],
                'registered_students' => 0,
                'grade_two_or_above' => 0,
                'attended_students' => 0,
                'absent_students' => 0,
            ];
            $grouped[$key]['registered_students']++;
            if ($this->isGradeTwoOrAbove($registration)) {
                $grouped[$key]['grade_two_or_above']++;
            }
            $grouped[$key][$registration['exam_attended'] ? 'attended_students' : 'absent_students']++;
            $studentCodes[$registration['level'].'|'.$registration['student_code']] = true;
        }

        $items = array_values(array_map(static function (array $row) use ($kind): array {
            $registered = (int) $row['registered_students'];
            if ($kind === 'grade-threshold') {
                $row['success_rate'] = $registered > 0
                    ? round(((int) $row['grade_two_or_above'] / $registered) * 100, 1)
                    : 0.0;
            } else {
                $row['attendance_rate'] = $registered > 0
                    ? round(((int) $row['attended_students'] / $registered) * 100, 1)
                    : 0.0;
            }

            return $row;
        }, $grouped));
        usort($items, static fn (array $left, array $right): int => [
            $left['level']['id'], $left['subject']['code'],
        ] <=> [
            $right['level']['id'], $right['subject']['code'],
        ]);

        $registered = count($registrations);
        $gradeTwo = array_sum(array_column($items, 'grade_two_or_above'));
        $attended = array_sum(array_column($items, 'attended_students'));
        $summary = [
            'unique_students' => count($studentCodes),
            'subject_count' => count($items),
            'registered_records' => $registered,
        ];
        if ($kind === 'grade-threshold') {
            $summary += [
                'grade_two_or_above' => $gradeTwo,
                'success_rate' => $registered > 0 ? round(($gradeTwo / $registered) * 100, 1) : 0.0,
            ];
        } else {
            $summary += [
                'attended_records' => $attended,
                'absent_records' => $registered - $attended,
                'attendance_rate' => $registered > 0 ? round(($attended / $registered) * 100, 1) : 0.0,
            ];
        }

        return [
            'items' => $items,
            'summary' => $summary,
            'terms' => $terms,
            'selected_term' => $selectedTerm,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $registrations
     * @param  list<string>  $terms
     * @param  'grade-threshold'|'exam-attendance'  $kind
     */
    private function historicalAcademicStudents(array $registrations, array $terms, string $selectedTerm, string $kind): array
    {
        $students = [];
        foreach ($registrations as $registration) {
            $key = $registration['level'].'|'.$registration['student_code'];
            $students[$key] ??= [
                'student' => [
                    'code' => $registration['student_code'],
                    'full_name' => $registration['student_name'],
                    'level' => [
                        'id' => $registration['level'],
                        'label' => $this->levelLabel((int) $registration['level']),
                    ],
                    'group' => [
                        'code' => $registration['group_code'],
                        'name' => $registration['group_name'],
                    ],
                ],
                'term' => $selectedTerm,
                'registered_subjects' => 0,
                'grade_two_or_above' => 0,
                'attended_subjects' => 0,
                'absent_subjects' => 0,
                'success_rate' => 0.0,
            ];
            $students[$key]['registered_subjects']++;
            if ($this->isGradeTwoOrAbove($registration)) {
                $students[$key]['grade_two_or_above']++;
            }
            $students[$key][$registration['exam_attended'] ? 'attended_subjects' : 'absent_subjects']++;
        }

        $registeredTotal = 0;
        $successfulTotal = 0;
        $studentsAttended = 0;
        $studentsNoAttendance = 0;
        $studentsAbsent = 0;
        $studentsComplete = 0;
        $studentsGradeTwoAll = 0;
        foreach ($students as &$student) {
            $registered = (int) $student['registered_subjects'];
            $successful = $kind === 'grade-threshold'
                ? (int) $student['grade_two_or_above']
                : (int) $student['attended_subjects'];
            $student['success_rate'] = $registered > 0 ? round(($successful / $registered) * 100, 1) : 0.0;
            $registeredTotal += $registered;
            $successfulTotal += $successful;
            if ((int) $student['attended_subjects'] > 0) {
                $studentsAttended++;
            } else {
                $studentsNoAttendance++;
            }
            if ((int) $student['absent_subjects'] > 0) {
                $studentsAbsent++;
            } else {
                $studentsComplete++;
            }
            if ((int) $student['grade_two_or_above'] === $registered) {
                $studentsGradeTwoAll++;
            }
        }
        unset($student);

        $items = array_values($students);
        usort($items, static fn (array $left, array $right): int => strnatcasecmp(
            (string) $left['student']['full_name'],
            (string) $right['student']['full_name'],
        ));

        return [
            'items' => $items,
            'summary' => [
                'unique_students' => count($items),
                'registered_records' => $registeredTotal,
                'successful_records' => $successfulTotal,
                'success_rate' => $registeredTotal > 0 ? round(($successfulTotal / $registeredTotal) * 100, 1) : 0.0,
                'students_attended' => $studentsAttended,
                'students_no_attendance' => $studentsNoAttendance,
                'students_absent' => $studentsAbsent,
                'students_complete' => $studentsComplete,
                'students_grade_two_all' => $studentsGradeTwoAll,
            ],
            'terms' => $terms,
            'selected_term' => $selectedTerm,
        ];
    }

    /** @param array<string, mixed> $registration */
    private function isGradeTwoOrAbove(array $registration): bool
    {
        $grade = $registration['grade_value'] ?? null;

        return $grade !== null && is_numeric($grade) && (float) $grade >= 2.0;
    }

    /**
     * Match the duplicate-grade preference used by LegacyStudentRepository.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function preferredAcademicRegistration(array $rows): array
    {
        $hasZero = false;
        $oneRow = null;
        $chosen = $rows[0];
        $chosenNumeric = is_numeric((string) ($chosen['grade_value'] ?? ''))
            ? (float) $chosen['grade_value']
            : null;

        foreach ($rows as $row) {
            $raw = trim((string) ($row['grade_value'] ?? ''));
            $numeric = is_numeric($raw) ? (float) $raw : null;
            $hasZero = $hasZero || $numeric === 0.0;
            if ($numeric === 1.0) {
                $oneRow = $row;
            }
            if ($numeric !== null && ($chosenNumeric === null || $numeric > $chosenNumeric)) {
                $chosen = $row;
                $chosenNumeric = $numeric;
            }
        }

        return $hasZero && $oneRow !== null ? $oneRow : $chosen;
    }

    /** @param list<string> $terms @return list<string> */
    private function sortTerms(array $terms): array
    {
        $terms = array_values(array_unique($terms));
        usort($terms, static fn (string $left, string $right): int => AcademicTerm::compare($right, $left));

        return $terms;
    }

    /** @param list<string> $candidates */
    private function firstExistingColumn(string $table, array $candidates): ?string
    {
        static $columnsByTable = [];

        if (! array_key_exists($table, $columnsByTable)) {
            $columns = [];
            foreach ($this->rows(
                'SELECT COLUMN_NAME AS column_name
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$table],
            ) as $row) {
                $column = strtolower(trim((string) ($row['column_name'] ?? '')));
                if ($column !== '') {
                    $columns[$column] = true;
                }
            }
            $columnsByTable[$table] = $columns;
        }

        foreach ($candidates as $candidate) {
            if (isset($columnsByTable[$table][strtolower($candidate)])) {
                return $candidate;
            }
        }

        return null;
    }

    /** @param list<string> $terms */
    private function selectedTerm(array $filters, array $terms): ?string
    {
        $requested = AcademicTerm::normalize((string) ($filters['term'] ?? ''));

        return $requested ?? ($terms[0] ?? null);
    }

    private function enrollmentTerm(string $studentCode, string $fallback): ?string
    {
        if (preg_match('/^(\d{2})([12])/', $studentCode, $matches) === 1) {
            return $matches[2].'/25'.$matches[1];
        }

        return AcademicTerm::normalize($fallback);
    }

    /** @param array<string, mixed> $row */
    private function fullName(array $row): string
    {
        return trim(implode(' ', array_filter([
            trim((string) ($row['prename'] ?? '')).trim((string) ($row['first_name'] ?? '')),
            trim((string) ($row['last_name'] ?? '')),
        ])));
    }

    /** @param array<string, mixed> $row */
    private function groupLabel(array $row): string
    {
        return trim((string) ($row['group_name'] ?? '')) ?: trim((string) ($row['group_code'] ?? '')) ?: 'ไม่ระบุกลุ่ม';
    }

    private function levelLabel(int $level): string
    {
        return match ($level) {
            1 => 'ประถมศึกษา', 2 => 'มัธยมศึกษาตอนต้น', 3 => 'มัธยมศึกษาตอนปลาย', default => 'ไม่ทราบระดับ',
        };
    }

    /** @param list<array<string, string>> $rows @param list<string> $terms */
    private function payload(array $rows, array $terms, ?string $selectedTerm, callable $active): array
    {
        $activeCount = count(array_filter($rows, $active));
        $rows = array_map(static function (array $row): array {
            unset($row['_active']);

            return $row;
        }, $rows);

        return [
            'total' => count($rows),
            'active' => $activeCount,
            'groups' => count(array_unique(array_column($rows, 'group'))),
            'terms' => $terms,
            'selected_term' => $selectedTerm,
            'rows' => $rows,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql, array $bindings = []): array
    {
        return array_map(static fn (object $row): array => (array) $row, $this->database->connection((string) config('legacy.connection', 'legacy'))->select($sql, $bindings));
    }

    private function identifier(string $identifier): string
    {
        if (! $this->validIdentifier($identifier)) {
            throw new InvalidArgumentException('Unsafe legacy table or column identifier.');
        }

        return '`'.$identifier.'`';
    }

    private function validIdentifier(string $identifier): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $identifier) === 1;
    }
}
