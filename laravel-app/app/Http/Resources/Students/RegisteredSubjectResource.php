<?php

namespace App\Http\Resources\Students;

use App\Domain\Students\Models\RegisteredSubject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RegisteredSubject */
final class RegisteredSubjectResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
