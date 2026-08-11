<?php

namespace App\Http\Controllers\Api\Learning;

use App\Http\Controllers\Controller;
use App\Services\Learning\DistrictLearningGroupCatalog;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ResourceFileController extends Controller
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly DistrictLearningGroupCatalog $groupCatalog,
    ) {}

    public function __invoke(Request $request, int $resource): StreamedResponse
    {
        $districtId = (int) $request->attributes->get('district_id');
        $viewer = $request->user();
        $query = $this->database->connection()->table('learning_resources')
            ->where('id', $resource)
            ->where('district_id', $districtId);
        $this->scopeViewer($query, $request, $districtId);

        $row = $query->first();
        abort_unless($row, 404);

        $disk = (string) ($row->storage_disk ?? '');
        $path = (string) ($row->storage_path ?? '');
        abort_unless($disk === 'local' && $this->isOwnedPath($districtId, $resource, $path), 404);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, $this->downloadFilename((string) $row->title, $path));
    }

    private function scopeViewer(Builder $query, Request $request, int $districtId): void
    {
        $viewer = $request->user();
        $groups = $this->groupCatalog->aliasesForViewer($viewer, $districtId);

        if ($viewer->role === 'student') {
            $allStudentTargets = $this->groupCatalog->legacyAllStudentTargets();
            $educationLevel = $this->groupCatalog->educationLevelForViewer($viewer, $districtId);
            $query->where(function (Builder $scope) use ($educationLevel): void {
                $scope->whereNull('education_level');
                if ($educationLevel !== null) {
                    $scope->orWhere('education_level', $educationLevel);
                }
            });
            $query->where(function (Builder $scope) use ($groups, $allStudentTargets): void {
                $scope->whereNull('target_group')->orWhere('target_group', '')->orWhereIn('target_group', $allStudentTargets);
                if ($groups !== []) {
                    $scope->orWhereIn('target_group', $groups);
                }
            });
        } elseif ($viewer->role === 'teacher') {
            $query->where(function (Builder $scope) use ($groups, $viewer): void {
                $scope->where('uploaded_by', (int) $viewer->id);
                if ($groups !== []) {
                    $scope->orWhereIn('target_group', $groups);
                }
            });
        }
    }

    private function isOwnedPath(int $districtId, int $resourceId, string $path): bool
    {
        return $path !== '' && str_starts_with($path, "learning/resources/{$districtId}/{$resourceId}/");
    }

    private function downloadFilename(string $title, string $path): string
    {
        $title = trim((string) preg_replace('~[\x00-\x1F\x7F/\\\\]+~u', ' ', $title));
        $title = $title === '' ? 'learning-resource' : mb_substr($title, 0, 120);
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return $title.($extension === '' ? '' : '.'.$extension);
    }
}
