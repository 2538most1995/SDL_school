<?php

namespace App\Http\Resources\Students;

use App\Domain\Students\Models\Student;
use App\Domain\Students\Support\StudentAccessScope;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Student */
final class StudentSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $data = $this->resource->toSummaryArray();
        $viewer = $request->user();

        if ($viewer !== null
            && $this->resource->citizenId !== null
            && StudentAccessScope::forUser($viewer)->allows($this->resource)) {
            $data['demographics']['citizen_id'] = $this->resource->citizenId;
        }

        return $data;
    }
}
