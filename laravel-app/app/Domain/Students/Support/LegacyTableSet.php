<?php

namespace App\Domain\Students\Support;

final readonly class LegacyTableSet
{
    public function __construct(
        public int $districtId,
        public string $districtName,
        public string $batchKey,
        public int $level,
        public string $student,
        public string $grade,
        public string $subject,
        public ?string $activity,
        public ?string $virtue,
        public ?string $group,
    ) {}
}
