<?php

namespace App\Http\Controllers\Api\Integrations;

use App\Domain\Students\Services\LegacyStudentReportService;
use App\Domain\Students\Services\StudentAcademicService;
use App\Domain\Students\Services\StudentDirectoryService;
use App\Domain\Students\Services\StudentReportService;
use App\Http\Controllers\Api\Students\StudentsApiController;
use App\Http\Resources\Students\GradeResource;
use App\Http\Resources\Students\KpchActivityResource;
use App\Http\Resources\Students\MoralAssessmentResource;
use App\Http\Resources\Students\RegisteredSubjectResource;
use App\Http\Resources\Students\StudentDataIntegrationResource;
use App\Http\Resources\Students\StudentProfileIntegrationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class StudentDataController extends StudentsApiController
{
    public function __construct(
        private readonly StudentDirectoryService $directory,
        private readonly StudentAcademicService $academics,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'level' => ['nullable', 'integer', Rule::in([1, 2, 3])],
            'group' => ['nullable', 'string', 'max:120'],
            'term' => ['nullable', 'regex:/^[12]\/25\d{2}$/'],
            'sort' => ['nullable', Rule::in(['name', 'code', 'gpax', 'credits', 'kpch_hours'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $result = $this->directory->paginate(
            $request->user(),
            $filters,
            includeCitizenIdInSearch: false,
            clampPage: false,
        );

        return $this->respond([
            'data' => array_map(
                static fn ($student): array => (new StudentDataIntegrationResource($student))->resolve($request),
                $result['items'],
            ),
            'meta' => $this->integrationMeta(array_intersect_key(
                $result['meta'],
                array_flip(['pagination', 'filter_options', 'summary']),
            )),
        ]);
    }

    public function graduates(Request $request, LegacyStudentReportService $legacyReports, StudentReportService $reports): JsonResponse
    {
        $filters = $request->validate([
            'term' => ['nullable', 'regex:/^[12]\/25\d{2}$/'],
            'level' => ['nullable', 'integer', Rule::in([1, 2, 3])],
            'group' => ['nullable', 'string', 'max:80'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if (config('system_data.student_enabled')) {
            $result = $legacyReports->paginateGraduates($request->user(), (int) $request->attributes->get('district_id'), $filters);
        } else {
            // Demo data has no imported tables; keep its existing report source.
            $report = $reports->graduates($request->user(), $filters, includeCitizenIdInSearch: false);
            $selectedTerm = $report['selected_term'];
            $rows = array_values(array_filter($report['rows'], static fn (array $row): bool => $row['metric'] === $selectedTerm));
            $page = (int) ($filters['page'] ?? 1);
            $perPage = (int) ($filters['per_page'] ?? 25);
            $total = count($rows);
            $items = $page > max(1, (int) ceil($total / $perPage)) ? [] : array_slice($rows, ($page - 1) * $perPage, $perPage);
            $result = [
                'items' => array_map(static fn (array $row): array => [
                    'code' => $row['student_id'],
                    'name' => ['full_name' => $row['name']],
                    'level' => ['id' => $row['level_id'], 'label' => $row['level']],
                    'group' => ['code' => $row['group_code'], 'name' => $row['group_label']],
                    'graduation_term' => $selectedTerm,
                ], $items),
                'terms' => $report['terms'],
                'selected_term' => $selectedTerm,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => max(1, (int) ceil($total / $perPage)),
                    'from' => $items === [] ? null : (($page - 1) * $perPage) + 1,
                    'to' => $items === [] ? null : min($page * $perPage, $total),
                ],
            ];
        }

        return $this->respond([
            'data' => $result['items'],
            'meta' => $this->integrationMeta([
                'terms' => $result['terms'],
                'selected_term' => $result['selected_term'],
                'pagination' => $result['pagination'],
            ]),
        ]);
    }

    public function show(Request $request, string $student): JsonResponse
    {
        $record = $this->directory->findAccessible($request->user(), $student);
        abort_if($record === null, 404, 'ไม่พบข้อมูลนักศึกษา');

        return $this->respond([
            'data' => (new StudentDataIntegrationResource($record))->resolve($request),
            'meta' => $this->integrationMeta(),
        ]);
    }

    public function profile(Request $request, string $student): JsonResponse
    {
        $filters = $request->validate([
            'level' => ['nullable', 'integer', Rule::in([1, 2, 3])],
        ]);
        $record = $this->directory->findAccessible($request->user(), $student, $filters['level'] ?? null, allowCrossDistrictFallback: false);
        abort_if(
            $record === null || $record->districtId !== (int) $request->attributes->get('district_id'),
            404,
            'ไม่พบข้อมูลนักศึกษา',
        );

        return $this->respond([
            'data' => (new StudentProfileIntegrationResource(
                $record,
                $this->directory->profileDetails($request->user(), $record),
            ))->resolve($request),
            'meta' => $this->integrationMeta([
                'data_classification' => 'personal_data_sensitive',
                'sensitive_identifiers_included' => true,
                'citizen_id_included' => false,
                'profile_included' => true,
            ]),
        ]);
    }

    public function grades(Request $request, string $student): JsonResponse
    {
        return $this->academicResponse($request, $student, 'grades', GradeResource::class);
    }

    public function kpch(Request $request, string $student): JsonResponse
    {
        return $this->academicResponse($request, $student, 'kpch', KpchActivityResource::class);
    }

    public function moral(Request $request, string $student): JsonResponse
    {
        return $this->academicResponse($request, $student, 'moral', MoralAssessmentResource::class);
    }

    public function subjects(Request $request, string $student): JsonResponse
    {
        return $this->academicResponse($request, $student, 'subjects', RegisteredSubjectResource::class);
    }

    public function subjectCatalog(Request $request): JsonResponse
    {
        $filters = $this->catalogFilters($request);
        $catalog = $this->academics->registrationCatalog($request->user(), $filters['term'] ?? null);

        return $this->respond([
            'data' => $catalog['subjects'],
            'meta' => $this->integrationMeta([
                'term' => $filters['term'] ?? 'all',
                'total' => count($catalog['subjects']),
            ]),
        ]);
    }

    public function subjectClassGroups(Request $request, string $subject): JsonResponse
    {
        $filters = $this->catalogFilters($request);
        $catalog = $this->academics->registrationCatalog($request->user(), $filters['term'] ?? null);
        $groups = $catalog['groups'][$subject] ?? [];

        return $this->respond([
            'data' => $groups,
            'meta' => $this->integrationMeta([
                'term' => $filters['term'] ?? 'all',
                'subject_code' => $subject,
                'total' => count($groups),
            ]),
        ]);
    }

    public function classGroupStudents(Request $request, string $group): JsonResponse
    {
        $filters = $request->validate([
            'term' => ['nullable', 'regex:/^[12]\/25\d{2}$/'],
            'subject_code' => ['required', 'string', 'max:64'],
        ]);
        $subjectCode = (string) $filters['subject_code'];
        $catalog = $this->academics->registrationCatalog($request->user(), $filters['term'] ?? null);
        $students = $catalog['rosters'][$subjectCode][$group] ?? [];

        return $this->respond([
            'data' => $students,
            'meta' => $this->integrationMeta([
                'term' => $filters['term'] ?? 'all',
                'subject_code' => $subjectCode,
                'group_code' => $group,
                'total' => count($students),
            ]),
        ]);
    }

    public function subjectStudents(Request $request, string $subject): JsonResponse
    {
        $filters = $this->catalogFilters($request);
        $catalog = $this->academics->registrationCatalog($request->user(), $filters['term'] ?? null);
        $groups = [];
        foreach ($catalog['groups'][$subject] ?? [] as $group) {
            $groups[(string) $group['id']] = $group;
        }

        $students = [];
        foreach ($catalog['rosters'][$subject] ?? [] as $groupId => $roster) {
            $group = $groups[(string) $groupId] ?? [
                'id' => (string) $groupId,
                'code' => (string) $groupId,
                'name' => (string) $groupId,
            ];
            foreach ($roster as $student) {
                $students[] = [
                    ...$student,
                    'group_id' => (string) $group['id'],
                    'group_code' => (string) $group['code'],
                    'group_name' => (string) $group['name'],
                ];
            }
        }

        usort($students, static fn (array $left, array $right): int => [
            $left['group_name'],
            $left['code'],
        ] <=> [
            $right['group_name'],
            $right['code'],
        ]);

        return $this->respond([
            'data' => $students,
            'meta' => $this->integrationMeta([
                'term' => $filters['term'] ?? 'all',
                'subject_code' => $subject,
                'total' => count($students),
                'groups' => count($groups),
            ]),
        ]);
    }

    /** @return array{term?: string} */
    private function catalogFilters(Request $request): array
    {
        return $request->validate([
            'term' => ['nullable', 'regex:/^[12]\/25\d{2}$/'],
        ]);
    }

    /** @param class-string $resourceClass */
    private function academicResponse(
        Request $request,
        string $student,
        string $dataset,
        string $resourceClass,
    ): JsonResponse {
        $filters = $request->validate([
            'term' => ['nullable', 'regex:/^[12]\/25\d{2}$/'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);
        $term = $filters['term'] ?? null;
        $result = match ($dataset) {
            'grades' => $this->academics->grades($request->user(), $student, $term),
            'kpch' => $this->academics->kpch($request->user(), $student, $term),
            'moral' => $this->academics->moral($request->user(), $student, $term),
            'subjects' => $this->academics->subjects($request->user(), $student, $term),
        };
        abort_if($result === null, 404, 'ไม่พบข้อมูลนักศึกษา');
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 100);
        $total = count($result['items']);
        $items = array_values(array_slice($result['items'], ($page - 1) * $perPage, $perPage));

        return $this->respond([
            'data' => [
                'student' => (new StudentDataIntegrationResource($result['student']))->resolve($request),
                'items' => array_map(
                    static fn ($item): array => (new $resourceClass($item))->resolve($request),
                    $items,
                ),
                'summary' => $result['summary'],
            ],
            'meta' => $this->integrationMeta([
                'term' => $term ?? 'all',
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => max(1, (int) ceil($total / $perPage)),
                    'from' => $items === [] ? null : (($page - 1) * $perPage) + 1,
                    'to' => $items === [] ? null : min($page * $perPage, $total),
                ],
            ]),
        ]);
    }

    /** @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function integrationMeta(array $extra = []): array
    {
        return $this->meta([
            'api_contract' => 'student-data-v1',
            'data_classification' => 'personal_educational_data',
            'sensitive_identifiers_included' => false,
            'authentication' => 'bearer_token',
            'read_only' => true,
            ...$extra,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function respond(array $payload): JsonResponse
    {
        return response()->json($payload)
            ->header('Cache-Control', 'private, no-store')
            ->header('Vary', 'Authorization');
    }
}
