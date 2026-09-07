<?php

namespace App\Http\Resources\Students;

use App\Domain\Students\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Student */
final class StudentDataIntegrationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->resource->code,
            'name' => [
                'prefix' => $this->resource->prefix,
                'first_name' => $this->resource->firstName,
                'last_name' => $this->resource->lastName,
                'full_name' => $this->resource->fullName(),
            ],
            'district' => [
                'id' => $this->resource->districtId,
                'name' => $this->resource->districtName,
            ],
            'level' => [
                'id' => $this->resource->level,
                'label' => $this->resource->levelLabel,
            ],
            'group' => [
                'code' => $this->resource->groupCode,
                'name' => $this->resource->groupName,
            ],
            'enrollment_term' => $this->resource->enrollmentTerm,
            'current_term' => $this->resource->currentTerm,
            'status' => [
                'code' => $this->resource->status,
                'label' => $this->resource->statusLabel,
            ],
            'academic' => [
                'gpax' => $this->resource->gpax,
                'credits_earned' => $this->resource->creditsEarned,
                'credits_current' => $this->resource->creditsCurrent > 0
                    ? $this->resource->creditsCurrent
                    : $this->resource->creditsEarned,
                'credits_required' => $this->resource->creditsRequired,
                'compulsory_credits_earned' => $this->resource->compulsoryCreditsEarned,
                'compulsory_credits_required' => $this->resource->compulsoryCreditsRequired,
                'elective_credits_earned' => $this->resource->electiveCreditsEarned,
                'elective_credits_required' => $this->resource->electiveCreditsRequired,
                'kpch_hours' => $this->resource->kpchHours,
                'moral_result' => $this->resource->moralResult,
            ],
        ];
    }
}
