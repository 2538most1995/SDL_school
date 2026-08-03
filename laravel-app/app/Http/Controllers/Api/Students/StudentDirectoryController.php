<?php

namespace App\Http\Controllers\Api\Students;

use App\Domain\Students\Services\StudentDirectoryService;
use App\Http\Resources\Students\StudentDetailResource;
use App\Http\Resources\Students\StudentSummaryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class StudentDirectoryController extends StudentsApiController
{
    public function __construct(private readonly StudentDirectoryService $directory) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'district_id' => ['nullable', 'integer', 'min:1'],
            'level' => ['nullable', 'integer', Rule::in([1, 2, 3])],
            'group' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['studying', 'graduated', 'transferred', 'inactive'])],
            'term' => ['nullable', 'regex:/^[12]\/25\d{2}$/'],
            'sort' => ['nullable', Rule::in(['name', 'code', 'gpax', 'credits', 'kpch_hours'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);
        $result = $this->directory->paginate($request->user(), $filters);

        return response()->json([
            'data' => array_map(
                static fn ($student): array => (new StudentSummaryResource($student))->resolve($request),
                $result['items'],
            ),
            'meta' => $this->meta($result['meta']),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $student): JsonResponse
    {
        $record = $this->directory->findAccessible($request->user(), $student);
        abort_if($record === null, 404, 'ไม่พบข้อมูลนักศึกษา');

        return response()->json([
            'data' => (new StudentDetailResource($record))->resolve($request),
            'meta' => $this->meta(),
        ])->header('Cache-Control', 'private, no-store');
    }
}
