<?php

namespace App\Http\Controllers\Api\Integrations;

use App\Domain\Students\Services\StudentAcademicService;
use App\Domain\Students\Services\StudentDirectoryService;
use App\Http\Controllers\Api\Students\StudentsApiController;
use App\Http\Resources\Students\GradeResource;
use App\Http\Resources\Students\KpchActivityResource;
use App\Http\Resources\Students\MoralAssessmentResource;
use App\Http\Resources\Students\RegisteredSubjectResource;
use App\Http\Resources\Students\StudentDataIntegrationResource;
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

    public function show(Request $request, string $student): JsonResponse
    {
        $record = $this->directory->findAccessible($request->user(), $student);
        abort_if($record === null, 404, 'ไม่พบข้อมูลนักศึกษา');

        return $this->respond([
            'data' => (new StudentDataIntegrationResource($record))->resolve($request),
            'meta' => $this->integrationMeta(),
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
