<?php

namespace App\Domain\Students\Models;

use App\Domain\Students\Support\StudentAge;

final readonly class Student
{
    /**
     * @param  array<string, mixed>  $contact
     * @param  array<string, mixed>  $guardian
     */
    public function __construct(
        public string $code,
        public int $districtId,
        public string $districtName,
        public string $prefix,
        public string $firstName,
        public string $lastName,
        public int $level,
        public string $levelLabel,
        public string $groupCode,
        public string $groupName,
        public string $enrollmentTerm,
        public string $currentTerm,
        public string $status,
        public string $statusLabel,
        public float $gpax,
        public float $creditsEarned,
        public float $creditsRequired,
        public float $kpchHours,
        public string $moralResult,
        public array $contact = [],
        public array $guardian = [],
        public array $demographics = [],
        public float $creditsCurrent = 0.0,
        public float $compulsoryCreditsEarned = 0.0,
        public float $compulsoryCreditsRequired = 0.0,
        public float $electiveCreditsEarned = 0.0,
        public float $electiveCreditsRequired = 0.0,
        public string $dataClassification = 'synthetic_demo',
        public ?string $citizenId = null,
        public ?string $phone = null,
        public ?string $registeredAddress = null,
        public ?string $currentAddress = null,
        public ?string $facebookUrl = null,
        public ?string $lineId = null,
    ) {}

    public function fullName(): string
    {
        return trim("{$this->prefix}{$this->firstName} {$this->lastName}");
    }

    public function lineUrl(): ?string
    {
        if ($this->lineId === null || trim($this->lineId) === '') {
            return null;
        }
        $line = trim($this->lineId);
        if (str_starts_with($line, 'http://') || str_starts_with($line, 'https://')) {
            return $line;
        }
        $cleanId = ltrim($line, '@~');

        return "https://line.me/ti/p/~{$cleanId}";
    }

    public function facebookUrlFormatted(): ?string
    {
        if ($this->facebookUrl === null || trim($this->facebookUrl) === '') {
            return null;
        }
        $fb = trim($this->facebookUrl);
        if (str_starts_with($fb, 'http://') || str_starts_with($fb, 'https://')) {
            return $fb;
        }
        if (str_starts_with($fb, 'facebook.com/') || str_starts_with($fb, 'www.facebook.com/') || str_starts_with($fb, 'fb.com/')) {
            return "https://{$fb}";
        }

        return "https://facebook.com/{$fb}";
    }

    /** @return array<string, mixed> */
    public function toSummaryArray(): array
    {
        $demographics = $this->demographics;
        $currentAge = StudentAge::fromBirthDate(
            isset($demographics['birth_date']) ? (string) $demographics['birth_date'] : null,
        );
        if ($currentAge === null) {
            unset($demographics['age']);
        } else {
            $demographics['age'] = $currentAge;
        }

        $social = [
            'facebook_url' => $this->facebookUrlFormatted(),
            'facebook_raw' => $this->facebookUrl,
            'line_id' => $this->lineId,
            'line_url' => $this->lineUrl(),
        ];

        return [
            'code' => $this->code,
            'full_name' => $this->fullName(),
            'district' => [
                'id' => $this->districtId,
                'name' => $this->districtName,
            ],
            'level' => [
                'id' => $this->level,
                'label' => $this->levelLabel,
            ],
            'group' => [
                'code' => $this->groupCode,
                'name' => $this->groupName,
            ],
            'current_term' => $this->currentTerm,
            'academic' => [
                'gpax' => $this->gpax,
                'credits_earned' => $this->creditsEarned,
                'credits_current' => $this->creditsCurrent > 0 ? $this->creditsCurrent : $this->creditsEarned,
                'credits_required' => $this->creditsRequired,
                'credit_progress_percent' => $this->creditsRequired > 0
                    ? round(min(100, ($this->creditsEarned / $this->creditsRequired) * 100), 1)
                    : 0.0,
                'compulsory' => [
                    'earned' => $this->compulsoryCreditsEarned,
                    'required' => $this->compulsoryCreditsRequired,
                    'remaining' => max(0, round($this->compulsoryCreditsRequired - $this->compulsoryCreditsEarned, 1)),
                ],
                'elective' => [
                    'earned' => $this->electiveCreditsEarned,
                    'required' => $this->electiveCreditsRequired,
                    'remaining' => max(0, round($this->electiveCreditsRequired - $this->electiveCreditsEarned, 1)),
                ],
                'kpch_hours' => $this->kpchHours,
                'moral_result' => $this->moralResult,
            ],
            'demographics' => $demographics,
            'social' => $social,
        ];
    }

    /** @return array<string, mixed> */
    public function toDetailArray(): array
    {
        $social = [
            'facebook_url' => $this->facebookUrlFormatted(),
            'facebook_raw' => $this->facebookUrl,
            'line_id' => $this->lineId,
            'line_url' => $this->lineUrl(),
        ];

        return [
            ...$this->toSummaryArray(),
            'name' => [
                'prefix' => $this->prefix,
                'first_name' => $this->firstName,
                'last_name' => $this->lastName,
                'full_name' => $this->fullName(),
            ],
            'enrollment_term' => $this->enrollmentTerm,
            'contact' => [
                ...$this->contact,
                ...$social,
            ],
            'social' => $social,
            'guardian' => $this->guardian,
            'data_classification' => $this->dataClassification,
        ];
    }
}
