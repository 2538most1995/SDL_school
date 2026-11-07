<?php

namespace App\Http\Resources\Students;

use App\Domain\Students\Models\Student;
use App\Domain\Students\Support\StudentAccessScope;
use App\Domain\Students\Support\StudentAge;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Student */
final class StudentProfileIntegrationResource extends JsonResource
{
    /** @param array<string, mixed> $profileDetails */
    public function __construct($resource, private readonly array $profileDetails = [])
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        abort_unless(
            $viewer !== null
                && (int) $request->attributes->get('district_id') === $this->resource->districtId
                && StudentAccessScope::forUser($viewer)->allows($this->resource),
            404,
            'ไม่พบข้อมูลนักศึกษา',
        );

        // Explicit projections at every level exclude full/masked citizen IDs,
        // arbitrary imported columns and future internal profile additions.
        $demographics = $this->fields($this->resource->demographics, ['birth_date', 'gender', 'application_date', 'last_updated']);
        $demographics['age'] = StudentAge::fromBirthDate($demographics['birth_date']);
        $contact = $this->fields($this->resource->contact, ['email', 'email_masked', 'phone_masked']);
        $contact['email'] = $this->resource->email ?? $contact['email'];
        $family = $this->profileDetails['family'] ?? [];
        $members = [];
        foreach (['father', 'mother', 'guardian'] as $member) {
            $members[$member] = $this->fields($family[$member] ?? [], [
                'name', 'occupation_code', 'occupation_label', 'income', 'status_code', 'status_label',
            ]);
        }

        return [
            ...(new StudentDataIntegrationResource($this->resource))->resolve($request),
            'demographics' => $demographics,
            'contact' => [
                ...$contact,
                'phone' => $this->resource->phone,
                'registered_address' => $this->resource->registeredAddress,
                'current_address' => $this->resource->currentAddress,
            ],
            'social' => [
                'facebook_url' => $this->resource->facebookUrlFormatted(),
                'line_id' => $this->resource->lineId,
                'line_url' => $this->resource->lineUrl(),
            ],
            'guardian' => $this->fields($this->resource->guardian, ['name', 'phone', 'phone_masked', 'relationship']),
            'profile' => [
                'personal' => $this->fields($this->profileDetails['personal'] ?? [], [
                    'nationality_code', 'nationality_label', 'religion_code', 'religion_label',
                    'occupation_code', 'occupation_label', 'target_group_code', 'target_group_label',
                    'height_cm', 'weight_kg',
                ]),
                'family' => [
                    ...$members,
                    ...$this->fields($family, ['siblings', 'studying_siblings', 'marital_status_label']),
                ],
                'prior_education' => $this->fields($this->profileDetails['prior_education'] ?? [], [
                    'qualification_code', 'qualification_label', 'graduation_year', 'school', 'province', 'province_label',
                ]),
            ],
        ];
    }

    /** @param array<string, mixed> $data
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function fields(array $data, array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = is_scalar($data[$key] ?? null) ? $data[$key] : null;
        }

        return $result;
    }
}
