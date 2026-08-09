<?php

namespace App\Domain\Students\Repositories;

use App\Domain\Students\Models\Grade;
use App\Domain\Students\Models\KpchActivity;
use App\Domain\Students\Models\MoralAssessment;
use App\Domain\Students\Models\RegisteredSubject;
use App\Domain\Students\Models\Student;
use App\Domain\Students\Support\AcademicTerm;
use App\Domain\Students\Support\LegacyStudentStatus;
use App\Domain\Students\Support\LegacyTableSet;
use App\Support\LegacyFptMemoReader;
use App\Support\ThaiAdministrativeAreaLookup;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Read-only adapter for the legacy DBF import database.
 *
 * Every query uses ConnectionInterface::select() and a trusted, registry-resolved
 * physical table identifier. The configured `legacy` database user must also have
 * SELECT-only grants as defence in depth.
 */
final class LegacyStudentRepository implements StudentRepository
{
    /** @var array<int, list<LegacyTableSet>> */
    private array $setsByDistrict = [];

    /** @var array<string, list<Student>> */
    private array $studentCache = [];

    /** @var array<string, array<string, true>> */
    private array $columnsByTable = [];

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly ?ThaiAdministrativeAreaLookup $areaLookup = null,
        private readonly ?LegacyFptMemoReader $memoReader = null,
    ) {}

    /** @param list<int>|null $districtIds
     * @return list<Student>
     */
    public function students(?array $districtIds = null): array
    {
        if ($districtIds !== null) {
            $districtIds = array_values(array_unique(array_filter(array_map('intval', $districtIds), static fn (int $id): bool => $id > 0)));
            sort($districtIds);
        }
        $cacheKey = $districtIds === null ? '*' : implode(',', $districtIds);
        if (array_key_exists($cacheKey, $this->studentCache)) {
            return $this->studentCache[$cacheKey];
        }

        $records = [];
        $ambiguous = [];

        foreach ($this->activeDistricts($districtIds) as $district) {
            $sets = $this->tableSetsForDistrict((int) $district['id'], (string) $district['name']);
            $latestTerm = $this->latestTerm($sets);

            // A batch without a usable academic term is not an active student
            // source. Never fall back to another district or another batch.
            if ($sets === [] || $latestTerm === null) {
                continue;
            }

            foreach ($sets as $set) {
                $studentRows = $this->activeStudentRows($set, $latestTerm);
                $studentCodes = array_values(array_unique(array_filter(array_map(
                    static fn (array $row): string => trim((string) ($row['code'] ?? '')),
                    $studentRows,
                ))));
                sort($studentCodes, SORT_STRING);

                // The directory only displays the active cohort. Restrict the
                // expensive historical aggregates to those students instead of
                // scanning every grade in the district on every request.
                $academic = $this->academicAggregates($set, $latestTerm, $studentCodes);
                $kpch = $this->kpchAggregates($set, $studentCodes);
                $moral = $this->moralAggregates($set, $studentCodes);

                foreach ($studentRows as $row) {
                    $code = trim((string) ($row['code'] ?? ''));
                    if ($code === '') {
                        continue;
                    }

                    $identity = "{$set->districtId}|{$set->level}|{$code}";
                    if (isset($records[$identity]) || isset($ambiguous[$identity])) {
                        unset($records[$identity]);
                        $ambiguous[$identity] = true;

                        continue;
                    }

                    $metrics = $academic[$code] ?? [];
                    [$creditsRequired, $compulsoryRequired, $electiveRequired] = $this->creditRequirements($set->level);
                    $contactPhone = $this->phoneMemoValue(
                        $set,
                        $code,
                        'curphone',
                        (string) ($row['curphone'] ?? ''),
                    ) ?? $this->phoneMemoValue(
                        $set,
                        $code,
                        'phone',
                        (string) ($row['phone'] ?? ''),
                    ) ?? '';
                    $email = $this->memoValue($set, $code, 'email', (string) ($row['email'] ?? '')) ?? '';
                    [$status, $statusLabel] = LegacyStudentStatus::resolve(
                        (string) ($row['fin_cause'] ?? ''),
                        (string) ($row['transfer_date'] ?? ''),
                    );

                    $records[$identity] = new Student(
                        code: $code,
                        districtId: $set->districtId,
                        districtName: $set->districtName,
                        prefix: trim((string) ($row['prename'] ?? '')),
                        firstName: trim((string) ($row['first_name'] ?? '')),
                        lastName: trim((string) ($row['last_name'] ?? '')),
                        level: $set->level,
                        levelLabel: $this->levelLabel($set->level),
                        groupCode: trim((string) ($row['group_code'] ?? '')),
                        groupName: trim((string) (($row['group_name'] ?? '') ?: ($row['group_code'] ?? ''))),
                        enrollmentTerm: AcademicTerm::normalize((string) ($row['enrollment_term'] ?? ''))
                            ?? trim((string) ($row['enrollment_term'] ?? '')),
                        currentTerm: $latestTerm,
                        status: $status,
                        statusLabel: $statusLabel,
                        gpax: round((float) ($metrics['gpax'] ?? $row['gpasem'] ?? 0), 2),
                        creditsEarned: round((float) ($metrics['credits_earned'] ?? 0), 1),
                        creditsRequired: $creditsRequired,
                        kpchHours: round((float) ($kpch[$code] ?? 0), 1),
                        moralResult: (string) ($moral[$code]['result'] ?? 'ยังไม่มีผลประเมิน'),
                        contact: array_filter([
                            'phone_masked' => $this->maskPhone($contactPhone),
                            'email_masked' => $this->maskEmail($email),
                        ], static fn (?string $value): bool => $value !== null),
                        guardian: [],
                        demographics: array_filter([
                            'citizen_id_masked' => $row['citizen_id_masked'] ?? null,
                            'birth_date' => $this->formatThaiDate((string) ($row['birth_date'] ?? '')),
                            'gender' => $this->genderLabel((string) ($row['gender'] ?? '')),
                            'age' => $this->positiveInteger($row['age'] ?? null),
                            'application_date' => $this->formatThaiDate((string) ($row['application_date'] ?? '')),
                            'last_updated' => $this->formatThaiDate(
                                $this->legacyDateValue($set, $code, 'lastupdate', (string) ($row['last_updated'] ?? '')) ?? '',
                            ),
                        ], static fn (mixed $value): bool => $value !== null && $value !== ''),
                        creditsCurrent: round((float) ($metrics['credits_current'] ?? $metrics['credits_earned'] ?? 0), 1),
                        compulsoryCreditsEarned: round((float) ($metrics['compulsory_earned'] ?? 0), 1),
                        compulsoryCreditsRequired: $compulsoryRequired,
                        electiveCreditsEarned: round((float) ($metrics['elective_earned'] ?? 0), 1),
                        electiveCreditsRequired: $electiveRequired,
                        dataClassification: 'personal_data_sensitive',
                        citizenId: $this->validCitizenId((string) ($row['citizen_id'] ?? '')),
                        phone: $contactPhone === '' ? null : $contactPhone,
                        registeredAddress: $this->formatAddress(
                            $this->memoValue($set, $code, 'addr', (string) ($row['registered_address'] ?? '')) ?? '',
                            (string) ($row['registered_area_code'] ?? ''),
                            (string) ($row['registered_postcode'] ?? ''),
                        ),
                        currentAddress: $this->formatAddress(
                            $this->memoValue($set, $code, 'curaddr', (string) ($row['current_address'] ?? '')) ?? '',
                            (string) ($row['current_area_code'] ?? ''),
                            (string) ($row['current_postcode'] ?? ''),
                        ),
                    );
                }
            }
        }

        return $this->studentCache[$cacheKey] = array_values($records);
    }

    public function find(string $code, ?int $districtId = null, ?int $level = null): ?Student
    {
        $matches = array_values(array_filter(
            $this->students($districtId === null ? null : [$districtId]),
            static fn (Student $student): bool => hash_equals($student->code, trim($code))
                && ($districtId === null || $student->districtId === $districtId)
                && ($level === null || $student->level === $level),
        ));

        return count($matches) === 1 ? $matches[0] : null;
    }

    /** @return list<Grade> */
    public function gradesFor(Student $student): array
    {
        return $this->gradesForMany([$student])[$this->studentKey($student)] ?? [];
    }

    /** @param list<Student> $students @return array<string, list<Grade>> */
    public function gradesForMany(array $students): array
    {
        $results = [];
        $plans = [];

        foreach ($students as $student) {
            $studentKey = $this->studentKey($student);
            $results[$studentKey] = [];
            $set = $this->tableSetFor($student);
            if ($set === null) {
                continue;
            }

            $plans[$set->grade] ??= ['set' => $set, 'students' => []];
            $plans[$set->grade]['students'][$studentKey] = $student;
        }

        foreach ($plans as $plan) {
            /** @var LegacyTableSet $set */
            $set = $plan['set'];
            /** @var array<string, Student> $studentsByKey */
            $studentsByKey = $plan['students'];
            $codes = array_values(array_unique(array_map(
                static fn (Student $student): string => $student->code,
                array_values($studentsByKey),
            )));
            sort($codes, SORT_STRING);
            if ($codes === []) {
                continue;
            }

            $gradeTable = $this->identifier($set->grade);
            $subjectTable = $this->identifier($set->subject);
            $placeholders = implode(',', array_fill(0, count($codes), '?'));
            $rows = $this->rows(
                "SELECT g._perf_std10 AS student_code,
                        g._id AS row_id,
                        g._perf_sub AS subject_code,
                        g.grade AS grade_value,
                        g._perf_semestry AS raw_term,
                        g.typ_code AS typ_code,
                        s.sub_name AS subject_name,
                        s.sub_credit AS subject_credit,
                        s.sub_type AS subject_type
                 FROM {$gradeTable} g
                 LEFT JOIN {$subjectTable} s ON s._perf_sub = g._perf_sub
                 WHERE g._perf_std10 IN ({$placeholders})
                 ORDER BY g._perf_std10 ASC, g._perf_semestry DESC, g._perf_sub ASC, g._id ASC",
                $codes,
            );
            $rowsByCode = [];
            foreach ($rows as $row) {
                $code = trim((string) ($row['student_code'] ?? ''));
                if ($code !== '') {
                    $rowsByCode[$code][] = $row;
                }
            }

            foreach ($studentsByKey as $studentKey => $student) {
                $results[$studentKey] = $this->hydrateGrades($student, $rowsByCode[$student->code] ?? []);
            }
        }

        return $results;
    }

    /** @return list<RegisteredSubject> */
    public function subjectsFor(Student $student): array
    {
        return array_map(
            static fn (Grade $grade): RegisteredSubject => new RegisteredSubject(
                studentCode: $grade->studentCode,
                code: $grade->subjectCode,
                name: $grade->subjectName,
                credits: $grade->credits,
                type: $grade->subjectType,
                term: $grade->term,
                registrationStatus: match (true) {
                    $grade->transferred => 'transferred',
                    $grade->grade === null => 'registered',
                    $grade->isPassed() => 'passed',
                    default => 'needs_improvement',
                },
                transferred: $grade->transferred,
                grade: $grade->grade,
                examAttended: $grade->examAttended,
            ),
            $this->gradesFor($student),
        );
    }

    /** @return list<KpchActivity> */
    public function kpchFor(Student $student): array
    {
        $set = $this->tableSetFor($student);
        if ($set?->activity === null) {
            return [];
        }

        $table = $this->identifier($set->activity);
        $rows = $this->rows(
            "SELECT _id AS row_id, activity, hour, _perf_semestry AS raw_term, transfer, trntype
             FROM {$table}
             WHERE _perf_std10 = ?
             ORDER BY _perf_semestry DESC, _id ASC",
            [$student->code],
        );

        return array_values(array_map(
            fn (array $row): KpchActivity => new KpchActivity(
                studentCode: $student->code,
                id: "legacy-{$set->districtId}-{$set->level}-".(int) ($row['row_id'] ?? 0),
                name: trim((string) ($row['activity'] ?? '')),
                term: AcademicTerm::normalize((string) ($row['raw_term'] ?? ''))
                    ?? trim((string) ($row['raw_term'] ?? '')),
                hours: $this->decimal($row['hour'] ?? 0),
                category: trim((string) ($row['transfer'] ?? '')) !== '' ? 'transfer' : 'activity',
                completedOn: null,
            ),
            $rows,
        ));
    }

    /** @return list<MoralAssessment> */
    public function moralFor(Student $student): array
    {
        $set = $this->tableSetFor($student);
        if ($set?->virtue === null) {
            return [];
        }

        $table = $this->identifier($set->virtue);
        $scoreColumns = implode(', ', array_map(
            static fn (string $column): string => $column,
            $this->moralScoreColumns(),
        ));
        $rows = $this->rows(
            "SELECT _perf_semester AS raw_term, {$scoreColumns}
             FROM {$table}
             WHERE _perf_std10 = ?",
            [$student->code],
        );
        $assessments = [];

        foreach ($rows as $row) {
            $categories = [
                $this->moralCategory('พัฒนาตนเอง', ['score1_1' => 'สะอาด', 'score1_2' => 'สุภาพ', 'score1_3' => 'กตัญญูกตเวที'], $row),
                $this->moralCategory('พัฒนาการทำงาน', ['score2_1' => 'ขยัน', 'score2_2' => 'ประหยัด', 'score2_3' => 'ซื่อสัตย์สุจริต'], $row),
                $this->moralCategory('อยู่ร่วมกันในสังคม', ['score3_1' => 'สามัคคี', 'score3_2' => 'มีน้ำใจ', 'score3_3' => 'มีวินัย'], $row),
                $this->moralCategory('พัฒนาประเทศชาติ', ['score4_1' => 'รักชาติ ศาสน์ กษัตริย์ และความเป็นไทย', 'score4_2' => 'ยึดมั่นประชาธิปไตย'], $row),
            ];
            $scores = [];
            foreach ($this->moralScoreColumns() as $column) {
                if (isset($row[$column]) && trim((string) $row[$column]) !== '') {
                    $scores[] = $this->decimal($row[$column]);
                }
            }
            if ($scores === []) {
                continue;
            }
            $average = array_sum($scores) / count($scores);

            $assessments[] = new MoralAssessment(
                studentCode: $student->code,
                term: AcademicTerm::normalize((string) ($row['raw_term'] ?? ''))
                    ?? trim((string) ($row['raw_term'] ?? '')),
                categories: $categories,
                score: round($average, 2),
                maximumScore: 100,
                result: $this->moralResult($average),
                assessedOn: null,
            );
        }

        usort($assessments, static function (MoralAssessment $left, MoralAssessment $right): int {
            $leftTerm = AcademicTerm::normalize($left->term);
            $rightTerm = AcademicTerm::normalize($right->term);

            return ($leftTerm !== null && $rightTerm !== null)
                ? AcademicTerm::compare($rightTerm, $leftTerm)
                : strcmp($right->term, $left->term);
        });

        return $assessments;
    }

    /** @param list<int>|null $districtIds
     * @return list<array{id: int, name: string}>
     */
    private function activeDistricts(?array $districtIds): array
    {
        if ($districtIds === []) {
            return [];
        }

        if ($districtIds === null) {
            return $this->rows('SELECT id, name FROM districts WHERE is_active = 1 ORDER BY id ASC');
        }

        $districtIds = array_values(array_unique(array_filter(array_map('intval', $districtIds), static fn (int $id): bool => $id > 0)));
        if ($districtIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($districtIds), '?'));

        return $this->rows(
            "SELECT id, name FROM districts WHERE is_active = 1 AND id IN ({$placeholders}) ORDER BY id ASC",
            $districtIds,
        );
    }

    /** @return list<LegacyTableSet> */
    private function tableSetsForDistrict(int $districtId, string $districtName): array
    {
        if (array_key_exists($districtId, $this->setsByDistrict)) {
            return $this->setsByDistrict[$districtId];
        }

        // Mocked connections used by contract tests do not necessarily expose
        // the concrete connection methods. Keep the production-specific binary
        // comparison when the driver is available, while remaining portable in
        // SQLite/unit-test contexts.
        $mysql = method_exists($this->connection, 'getDriverName')
            && $this->connection->getDriverName() === 'mysql';
        $binaryClause = $mysql ? 'BINARY ih.batch_key = BINARY ib.batch_key' : 'ih.batch_key = ib.batch_key';

        $batches = $this->rows(
            "SELECT ib.batch_key
             FROM import_batches ib
             INNER JOIN import_history ih
                ON ih.id = ib.import_history_id
               AND {$binaryClause}
               AND ih.district_id = ib.district_id
               AND ih.status = 'success'
             WHERE ib.district_id = ?
             ORDER BY COALESCE(ib.created_at, ih.created_at) DESC, ib.batch_key DESC
             LIMIT 1",
            [$districtId],
        );
        $batchKey = trim((string) ($batches[0]['batch_key'] ?? ''));
        if (preg_match('/^import_\d{10}_[A-Za-z0-9]+$/', $batchKey) !== 1) {
            return $this->setsByDistrict[$districtId] = [];
        }

        $tableRows = $this->rows(
            'SELECT TABLE_NAME AS table_name
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?',
            ["db_{$batchKey}_%"],
        );
        $prefix = 'db_'.preg_quote($batchKey, '/').'_';
        $tables = [];
        $globalGroups = [];
        $levelGroups = [];

        foreach ($tableRows as $row) {
            $table = trim((string) ($row['table_name'] ?? ''));
            if (! $this->validIdentifier($table) || preg_match("/^{$prefix}/", $table) !== 1) {
                continue;
            }

            if (preg_match("/^{$prefix}([123])_(student|grade|subject|activity|virtue)$/", $table, $matches) === 1) {
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

            $groupCandidates = $levelGroups[$level] ?? $globalGroups;
            $group = count($groupCandidates) === 1 ? $groupCandidates[0] : null;
            $sets[] = new LegacyTableSet(
                districtId: $districtId,
                districtName: $districtName,
                batchKey: $batchKey,
                level: $level,
                student: $levelTables['student'],
                grade: $levelTables['grade'],
                subject: $levelTables['subject'],
                activity: $levelTables['activity'] ?? null,
                virtue: $levelTables['virtue'] ?? null,
                group: $group,
            );
        }

        return $this->setsByDistrict[$districtId] = $sets;
    }

    /** @param list<LegacyTableSet> $sets */
    private function latestTerm(array $sets): ?string
    {
        $terms = [];
        foreach ($sets as $set) {
            $table = $this->identifier($set->grade);
            foreach ($this->rows("SELECT DISTINCT _perf_semestry AS term FROM {$table} WHERE _perf_semestry IS NOT NULL") as $row) {
                $terms[] = $row['term'] ?? null;
            }
        }

        $latest = AcademicTerm::latest($terms);
        if ($latest !== null) {
            return $latest;
        }

        foreach ($sets as $set) {
            $table = $this->identifier($set->student);
            foreach ($this->rows("SELECT DISTINCT _perf_expsem AS term FROM {$table} WHERE _perf_expsem IS NOT NULL") as $row) {
                $terms[] = $row['term'] ?? null;
            }
        }

        return AcademicTerm::latest($terms);
    }

    /** @return list<array<string, mixed>> */
    private function activeStudentRows(LegacyTableSet $set, string $latestTerm): array
    {
        $student = $this->identifier($set->student);
        $grade = $this->identifier($set->grade);
        $citizenIdColumn = $this->firstExistingColumn($set->student, ['_perf_cardid', 'cardid']);
        $citizenId = $citizenIdColumn === null ? 'NULL' : 's.'.$this->identifier($citizenIdColumn);
        $variants = AcademicTerm::variants($latestTerm);
        if ($variants === []) {
            return [];
        }

        $bindings = $variants;
        $placeholders = implode(',', array_fill(0, count($variants), '?'));
        $groupJoin = '';
        $groupName = 's.grp_code';
        if ($set->group !== null) {
            $group = $this->identifier($set->group);
            $groupJoin = " LEFT JOIN {$group} grp ON grp._perf_grp = s._perf_grp ";
            $groupName = "COALESCE(NULLIF(TRIM(grp.grp_name), ''), s.grp_code)";
        }

        return $this->rows(
            "SELECT s._perf_id10 AS code,
                    s.prename AS prename,
                    s.name AS first_name,
                    s.surname AS last_name,
                    s.grp_code AS group_code,
                    {$groupName} AS group_name,
                    s.dep_sem AS enrollment_term,
                    s.fin_cause AS fin_cause,
                    s.trn_date2 AS transfer_date,
                    s.gpasem AS gpasem,
                    CASE
                        WHEN CHAR_LENGTH(TRIM(COALESCE({$citizenId}, ''))) = 13
                        THEN CONCAT(LEFT(TRIM({$citizenId}), 1), '-xxxx-xxxxx-xx-', RIGHT(TRIM({$citizenId}), 1))
                        ELSE NULL
                    END AS citizen_id_masked,
                    {$citizenId} AS citizen_id,
                    s.gender AS gender,
                    s.birday AS birth_date,
                    s.age AS age,
                    s.app_date AS application_date,
                    s.lastupdate AS last_updated,
                    s.phone AS phone,
                    s.curphone AS curphone,
                    s.email AS email,
                    s.addr AS registered_address,
                    s.tambonid AS registered_area_code,
                    s.zipcode AS registered_postcode,
                    s.curaddr AS current_address,
                    s.ctambonid AS current_area_code,
                    s.czipcode AS current_postcode
             FROM {$student} s
             {$groupJoin}
             WHERE s._perf_id10 IS NOT NULL
               AND (EXISTS (
                    SELECT 1 FROM {$grade} active_grade
                    WHERE active_grade._perf_std10 = s._perf_id10
                      AND active_grade._perf_semestry IN ({$placeholders})
               ) OR EXISTS (
                    SELECT 1 FROM (
                        SELECT recent_student._perf_id10
                        FROM {$student} recent_student
                        WHERE recent_student._perf_id10 IS NOT NULL
                        ORDER BY recent_student._perf_id10 DESC
                        LIMIT 2
                    ) latest_students
                    WHERE latest_students._perf_id10 = s._perf_id10
               ))
             ORDER BY s._perf_id10 ASC",
            $bindings,
        );
    }

    /** @param list<string> $candidates */
    private function firstExistingColumn(string $table, array $candidates): ?string
    {
        if (! array_key_exists($table, $this->columnsByTable)) {
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
            $this->columnsByTable[$table] = $columns;
        }

        foreach ($candidates as $candidate) {
            if (isset($this->columnsByTable[$table][strtolower($candidate)])) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return array<string, array{gpax: float, credits_earned: float, credits_current: float, compulsory_earned: float, elective_earned: float}> */
    private function academicAggregates(LegacyTableSet $set, string $latestTerm, array $studentCodes): array
    {
        if ($studentCodes === []) {
            return [];
        }

        $grade = $this->identifier($set->grade);
        $subject = $this->identifier($set->subject);
        $termVariants = AcademicTerm::variants($latestTerm);
        $termPlaceholders = implode(',', array_fill(0, count($termVariants), '?'));
        $studentPlaceholders = implode(',', array_fill(0, count($studentCodes), '?'));
        $cacheKey = $this->aggregateCacheKey('academic', $set, $latestTerm, $studentCodes);

        return $this->rememberAggregate($cacheKey, function () use ($grade, $subject, $termPlaceholders, $studentPlaceholders, $termVariants, $studentCodes): array {
            $rows = $this->rows(
                "SELECT g._perf_std10 AS code,
                    SUM(CASE
                        WHEN TRIM(COALESCE(g.grade, '')) REGEXP '^[0-9]+([.][0-9]+)?$'
                         AND CAST(g.grade AS DECIMAL(10,2)) >= 1
                        THEN CAST(COALESCE(s.sub_credit, '0') AS DECIMAL(10,2)) ELSE 0 END
                    ) AS credits_earned,
                    SUM(CASE
                        WHEN TRIM(COALESCE(g.grade, '')) REGEXP '^[0-9]+([.][0-9]+)?$'
                         AND CAST(g.grade AS DECIMAL(10,2)) >= 1
                        THEN CAST(g.grade AS DECIMAL(10,2)) * CAST(COALESCE(s.sub_credit, '0') AS DECIMAL(10,2)) ELSE 0 END
                    ) / NULLIF(SUM(CASE
                        WHEN TRIM(COALESCE(g.grade, '')) REGEXP '^[0-9]+([.][0-9]+)?$'
                         AND CAST(g.grade AS DECIMAL(10,2)) >= 1
                        THEN CAST(COALESCE(s.sub_credit, '0') AS DECIMAL(10,2)) ELSE 0 END
                    ), 0) AS gpax
                    ,SUM(CASE
                        WHEN TRIM(COALESCE(g.grade, '')) REGEXP '^[0-9]+([.][0-9]+)?$'
                         AND CAST(g.grade AS DECIMAL(10,2)) >= 1
                         AND TRIM(COALESCE(s.sub_type, '')) = '1'
                        THEN CAST(COALESCE(s.sub_credit, '0') AS DECIMAL(10,2)) ELSE 0 END
                    ) AS compulsory_earned
                    ,SUM(CASE
                        WHEN TRIM(COALESCE(g.grade, '')) REGEXP '^[0-9]+([.][0-9]+)?$'
                         AND CAST(g.grade AS DECIMAL(10,2)) >= 1
                         AND TRIM(COALESCE(s.sub_type, '')) <> '1'
                        THEN CAST(COALESCE(s.sub_credit, '0') AS DECIMAL(10,2)) ELSE 0 END
                    ) AS elective_earned
                    ,SUM(CASE
                        WHEN TRIM(COALESCE(g.grade, '')) REGEXP '^[0-9]+([.][0-9]+)?$'
                         AND CAST(g.grade AS DECIMAL(10,2)) >= 1
                        THEN CAST(COALESCE(s.sub_credit, '0') AS DECIMAL(10,2)) ELSE 0 END
                    ) + SUM(CASE
                        WHEN g._perf_semestry IN ({$termPlaceholders})
                         AND TRIM(COALESCE(g.grade, '')) IN ('', '-')
                        THEN CAST(COALESCE(s.sub_credit, '0') AS DECIMAL(10,2)) ELSE 0 END
                    ) AS credits_current
             FROM {$grade} g
             INNER JOIN {$subject} s ON s._perf_sub = g._perf_sub
             WHERE g._perf_std10 IN ({$studentPlaceholders})
             GROUP BY g._perf_std10",
                [...$termVariants, ...$studentCodes],
            );

            $map = [];
            foreach ($rows as $row) {
                $code = trim((string) ($row['code'] ?? ''));
                if ($code !== '') {
                    $map[$code] = [
                        'gpax' => (float) ($row['gpax'] ?? 0),
                        'credits_earned' => (float) ($row['credits_earned'] ?? 0),
                        'credits_current' => (float) ($row['credits_current'] ?? 0),
                        'compulsory_earned' => (float) ($row['compulsory_earned'] ?? 0),
                        'elective_earned' => (float) ($row['elective_earned'] ?? 0),
                    ];
                }
            }

            return $map;
        });
    }

    /** @return array<string, float> */
    private function kpchAggregates(LegacyTableSet $set, array $studentCodes): array
    {
        if ($set->activity === null || $studentCodes === []) {
            return [];
        }
        $activity = $this->identifier($set->activity);
        $studentPlaceholders = implode(',', array_fill(0, count($studentCodes), '?'));
        $cacheKey = $this->aggregateCacheKey('kpch', $set, '', $studentCodes);

        return $this->rememberAggregate($cacheKey, function () use ($activity, $studentPlaceholders, $studentCodes): array {
            $rows = $this->rows(
                "SELECT _perf_std10 AS code, SUM(CAST(COALESCE(hour, '0') AS DECIMAL(10,2))) AS hours
                 FROM {$activity}
                 WHERE _perf_std10 IN ({$studentPlaceholders})
                 GROUP BY _perf_std10",
                $studentCodes,
            );
            $map = [];
            foreach ($rows as $row) {
                $code = trim((string) ($row['code'] ?? ''));
                if ($code !== '') {
                    $map[$code] = (float) ($row['hours'] ?? 0);
                }
            }

            return $map;
        });
    }

    /** @return array<string, array{term: string, result: string, score: float}> */
    private function moralAggregates(LegacyTableSet $set, array $studentCodes): array
    {
        if ($set->virtue === null || $studentCodes === []) {
            return [];
        }

        $virtue = $this->identifier($set->virtue);
        $scoreColumns = implode(', ', $this->moralScoreColumns());
        $studentPlaceholders = implode(',', array_fill(0, count($studentCodes), '?'));
        $cacheKey = $this->aggregateCacheKey('moral', $set, '', $studentCodes);

        return $this->rememberAggregate($cacheKey, function () use ($virtue, $scoreColumns, $studentPlaceholders, $studentCodes): array {
            $rows = $this->rows(
                "SELECT _perf_std10 AS code, _perf_semester AS raw_term, {$scoreColumns}
                 FROM {$virtue}
                 WHERE _perf_std10 IN ({$studentPlaceholders})",
                $studentCodes,
            );
            $latest = [];

            foreach ($rows as $row) {
                $code = trim((string) ($row['code'] ?? ''));
                $term = AcademicTerm::normalize((string) ($row['raw_term'] ?? ''))
                    ?? trim((string) ($row['raw_term'] ?? ''));
                if ($code === '' || $term === '') {
                    continue;
                }

                $scores = [];
                foreach ($this->moralScoreColumns() as $column) {
                    if (isset($row[$column]) && trim((string) $row[$column]) !== '') {
                        $scores[] = $this->decimal($row[$column]);
                    }
                }
                if ($scores === []) {
                    continue;
                }

                $previousTerm = (string) ($latest[$code]['term'] ?? '');
                $normalizedPrevious = AcademicTerm::normalize($previousTerm);
                $normalizedTerm = AcademicTerm::normalize($term);
                $isNewer = $previousTerm === ''
                    || ($normalizedPrevious !== null && $normalizedTerm !== null
                        ? AcademicTerm::compare($normalizedTerm, $normalizedPrevious) > 0
                        : strcmp($term, $previousTerm) > 0);

                if ($isNewer) {
                    $average = round(array_sum($scores) / count($scores), 2);
                    $latest[$code] = [
                        'term' => $term,
                        'result' => $this->moralResult($average),
                        'score' => $average,
                    ];
                }
            }

            return $latest;
        });
    }

    /** @param list<string> $studentCodes */
    private function aggregateCacheKey(string $kind, LegacyTableSet $set, string $term, array $studentCodes): string
    {
        return 'legacy-student-aggregate:v2:'.$kind.':'.$set->districtId.':'.$set->batchKey.':'.$set->level.':'
            .hash('sha256', $term."\0".implode("\0", $studentCodes));
    }

    /** @template TValue @param callable(): TValue $resolver @return TValue */
    private function rememberAggregate(string $key, callable $resolver): mixed
    {
        $seconds = max(0, (int) config('legacy.cache_seconds', 300));

        return $seconds === 0 ? $resolver() : Cache::remember($key, $seconds, $resolver);
    }

    private function tableSetFor(Student $student): ?LegacyTableSet
    {
        $sets = $this->setsByDistrict[$student->districtId]
            ?? $this->tableSetsForDistrict($student->districtId, $student->districtName);

        foreach ($sets as $set) {
            if ($set->level === $student->level) {
                return $set;
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $rows @return list<Grade> */
    private function hydrateGrades(Student $student, array $rows): array
    {
        $groups = [];
        foreach ($rows as $index => $row) {
            $term = AcademicTerm::normalize((string) ($row['raw_term'] ?? ''))
                ?? trim((string) ($row['raw_term'] ?? ''));
            $subjectCode = trim((string) ($row['subject_code'] ?? ''));
            if ($subjectCode === '') {
                continue;
            }
            $groups["{$term}|{$subjectCode}"][] = ['index' => $index, 'term' => $term, ...$row];
        }

        $grades = [];
        foreach ($groups as $group) {
            $chosen = $this->preferredGradeRow($group);
            $gradeValue = trim((string) ($chosen['grade_value'] ?? ''));
            $grades[] = new Grade(
                studentCode: $student->code,
                subjectCode: trim((string) $chosen['subject_code']),
                subjectName: trim((string) ($chosen['subject_name'] ?? '')),
                credits: $this->decimal($chosen['subject_credit'] ?? 0),
                subjectType: trim((string) ($chosen['subject_type'] ?? '')) === '1' ? 'compulsory' : 'elective',
                term: (string) $chosen['term'],
                grade: $gradeValue === '' || $gradeValue === '-' ? null : $gradeValue,
                transferred: trim((string) ($chosen['typ_code'] ?? '')) === '1',
                // Legacy has no attendance field. Preserve the established
                // report convention while keeping the limitation explicit.
                examAttended: ! in_array($gradeValue, ['', '-', 'ข', 'ม'], true),
            );
        }

        usort($grades, static function (Grade $left, Grade $right): int {
            $leftTerm = AcademicTerm::normalize($left->term);
            $rightTerm = AcademicTerm::normalize($right->term);
            $termOrder = ($leftTerm !== null && $rightTerm !== null)
                ? AcademicTerm::compare($rightTerm, $leftTerm)
                : strcmp($right->term, $left->term);

            return $termOrder !== 0 ? $termOrder : strcmp($left->subjectCode, $right->subjectCode);
        });

        return $grades;
    }

    private function studentKey(Student $student): string
    {
        return "{$student->districtId}|{$student->level}|{$student->code}";
    }

    /** @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function preferredGradeRow(array $rows): array
    {
        $hasZero = false;
        $oneRow = null;
        $chosen = $rows[0];
        $chosenNumeric = is_numeric(trim((string) ($chosen['grade_value'] ?? '')))
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

    /**
     * @param  array<string, string>  $labels
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function moralCategory(string $name, array $labels, array $row): array
    {
        $items = [];
        foreach ($labels as $column => $label) {
            $score = trim((string) ($row[$column] ?? '')) === '' ? null : $this->decimal($row[$column]);
            $items[] = ['label' => $label, 'score' => $score, 'maximum_score' => 100];
        }
        $present = array_values(array_filter(array_column($items, 'score'), static fn ($score): bool => $score !== null));

        return [
            'name' => $name,
            'items' => $items,
            'score' => $present === [] ? null : round(array_sum($present) / count($present), 2),
            'maximum_score' => 100,
        ];
    }

    /** @return list<string> */
    private function moralScoreColumns(): array
    {
        return [
            'score1_1', 'score1_2', 'score1_3',
            'score2_1', 'score2_2', 'score2_3',
            'score3_1', 'score3_2', 'score3_3',
            'score4_1', 'score4_2',
        ];
    }

    private function moralResult(float $average): string
    {
        return match (true) {
            $average >= 90 => 'ดีมาก',
            $average >= 70 => 'ดี',
            $average >= 50 => 'พอใช้',
            default => 'ปรับปรุง',
        };
    }

    private function levelLabel(int $level): string
    {
        return match ($level) {
            1 => 'ประถมศึกษา',
            2 => 'มัธยมศึกษาตอนต้น',
            3 => 'มัธยมศึกษาตอนปลาย',
            default => 'ไม่ทราบระดับ',
        };
    }

    /** @return array{float, float, float} */
    private function creditRequirements(int $level): array
    {
        return match ($level) {
            1 => [48.0, 36.0, 12.0],
            2 => [56.0, 40.0, 16.0],
            3 => [76.0, 44.0, 32.0],
            default => [0.0, 0.0, 0.0],
        };
    }

    private function genderLabel(string $value): ?string
    {
        return match (mb_strtoupper(trim($value))) {
            '1', 'M', 'ช', 'ชาย' => 'ชาย',
            '2', 'F', 'ญ', 'หญิง' => 'หญิง',
            default => null,
        };
    }

    private function positiveInteger(mixed $value): ?int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT);

        return $number !== false && $number > 0 ? $number : null;
    }

    private function validCitizenId(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', trim($value)) ?? '';

        return strlen($digits) === 13 ? $digits : null;
    }

    private function cleanSensitiveText(string $value): ?string
    {
        $cleaned = preg_replace('/\s+/u', ' ', trim($value)) ?? '';

        return $cleaned === '' ? null : $cleaned;
    }

    private function memoValue(LegacyTableSet $set, string $studentCode, string $field, string $storedValue): ?string
    {
        if ($this->memoReader === null) {
            return $this->cleanSensitiveText($storedValue);
        }

        return $this->memoReader->readStudentMemo(
            $set->batchKey,
            $set->level,
            $storedValue,
            $studentCode,
            $field,
        );
    }

    private function phoneMemoValue(LegacyTableSet $set, string $studentCode, string $field, string $storedValue): ?string
    {
        $decoded = $this->memoValue($set, $studentCode, $field, $storedValue);
        $phone = $this->validPhone($decoded ?? '');
        if ($phone !== null) {
            return $phone;
        }

        // Some production imports persist the decoded memo text directly in
        // MySQL. Use it only when it is a valid Thai phone number; raw four-byte
        // Visual FoxPro memo pointers continue to fail closed.
        return $this->validPhone($storedValue);
    }

    private function legacyDateValue(LegacyTableSet $set, string $studentCode, string $field, string $storedValue): ?string
    {
        $decoded = $this->memoReader?->readStudentDate($set->batchKey, $set->level, $studentCode, $field);
        if ($decoded !== null) {
            return $decoded;
        }

        $raw = trim($storedValue);

        return preg_match('/^(?:\d{8}|\d{4}[-\/]\d{2}[-\/]\d{2}|\d{1,2}[-\/]\d{1,2}[-\/]\d{4})$/', $raw) === 1
            ? $raw
            : null;
    }

    private function validPhone(string $value): ?string
    {
        $normalized = strtr($value, [
            '๐' => '0', '๑' => '1', '๒' => '2', '๓' => '3', '๔' => '4',
            '๕' => '5', '๖' => '6', '๗' => '7', '๘' => '8', '๙' => '9',
        ]);
        if (preg_match('/(?<!\d)0(?:[\s().\/-]*\d){8,9}(?!\d)/u', $normalized, $matches) !== 1) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $matches[0]) ?? '';

        return in_array(strlen($digits), [9, 10], true) ? $digits : null;
    }

    private function formatAddress(string $houseNumber, string $areaCode, string $postcode): ?string
    {
        $house = $this->cleanSensitiveText($houseNumber);
        $areaDigits = preg_replace('/\D+/', '', $areaCode) ?? '';
        $postcodeDigits = preg_replace('/\D+/', '', $postcode) ?? '';
        $area = $this->areaLookup?->resolve($areaDigits);
        $parts = [];

        if ($house !== null) {
            $parts[] = 'บ้านเลขที่ '.$house;
        }
        if ($area !== null) {
            if ($area['subdistrict'] !== '') {
                $parts[] = 'ตำบล'.$area['subdistrict'];
            }
            if ($area['district'] !== '') {
                $parts[] = 'อำเภอ'.$area['district'];
            }
            if ($area['province'] !== '') {
                $parts[] = 'จังหวัด'.$area['province'];
            }
        } elseif (strlen($areaDigits) === 6) {
            $parts[] = 'รหัสตำบล '.$areaDigits;
        }
        if (strlen($postcodeDigits) === 5) {
            $parts[] = 'รหัสไปรษณีย์ '.$postcodeDigits;
        }

        return $parts === [] ? null : implode(' ', $parts);
    }

    private function formatThaiDate(string $value): ?string
    {
        $raw = trim($value);
        if ($raw === '' || $raw === '00000000' || $raw === '0000-00-00') {
            return null;
        }

        $day = null;
        $month = null;
        $year = null;

        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $raw, $matches) === 1
            || preg_match('/^(\d{4})[-\/]([0-1]\d)[-\/]([0-3]\d)/', $raw, $matches) === 1) {
            [, $year, $month, $day] = $matches;
        } elseif (preg_match('/^([0-3]?\d)[-\/]([0-1]?\d)[-\/](\d{4})$/', $raw, $matches) === 1) {
            [, $day, $month, $year] = $matches;
        }

        if ($day === null || $month === null || $year === null) {
            return $raw;
        }

        $numericYear = (int) $year;
        if ($numericYear > 0 && $numericYear < 2400) {
            $numericYear += 543;
        }

        return sprintf('%02d/%02d/%04d', (int) $day, (int) $month, $numericYear);
    }

    private function maskPhone(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) < 7) {
            return null;
        }

        return substr($digits, 0, 2).'x-xxx-'.substr($digits, -4);
    }

    private function maskEmail(string $value): ?string
    {
        $email = trim($value);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).'***@'.$domain;
    }

    private function decimal(mixed $value): float
    {
        return (float) str_replace(',', '.', trim((string) $value));
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $query, array $bindings = []): array
    {
        return array_map(
            static fn (object|array $row): array => (array) $row,
            $this->connection->select($query, $bindings, true),
        );
    }

    private function identifier(string $identifier): string
    {
        if (! $this->validIdentifier($identifier)) {
            throw new InvalidArgumentException('Invalid legacy database identifier.');
        }

        return "`{$identifier}`";
    }

    private function validIdentifier(string $identifier): bool
    {
        return strlen($identifier) <= 64 && preg_match('/^[A-Za-z0-9_]+$/', $identifier) === 1;
    }
}
