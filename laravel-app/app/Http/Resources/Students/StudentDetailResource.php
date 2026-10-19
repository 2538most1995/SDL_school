<?php

namespace App\Http\Resources\Students;

use App\Domain\Students\Models\Student;
use App\Domain\Students\Support\StudentAccessScope;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Student */
final class StudentDetailResource extends JsonResource
{
    /** @param array<string, mixed> $profileDetails */
    public function __construct($resource, private readonly array $profileDetails = [])
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $data = $this->resource->toDetailArray();
        $viewer = $request->user();

        if ($viewer !== null && StudentAccessScope::forUser($viewer)->allows($this->resource)) {
            $data['profile'] = $this->profileDetails;
            if ($this->resource->citizenId !== null) {
                $data['demographics']['citizen_id'] = $this->resource->citizenId;
            }
            if ($this->resource->phone !== null) {
                $data['contact']['phone'] = $this->resource->phone;
            }
            if ($this->resource->registeredAddress !== null) {
                $data['contact']['registered_address'] = $this->resource->registeredAddress;
            }
            if ($this->resource->currentAddress !== null) {
                $data['contact']['current_address'] = $this->resource->currentAddress;
            }
        }

        return $data;
    }
}
