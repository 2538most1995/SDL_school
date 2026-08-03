<?php

namespace App\Contracts;

interface LegacyIdentityProvider
{
    /** @return list<array{id: int, name: string, code: string, is_active: bool}> */
    public function districts(): array;

    /** @return array<string, mixed>|null */
    public function authenticateStaff(string $username, string $password): ?array;

    /** @return array<string, mixed>|null */
    public function authenticateStudent(string $citizenId, string $studentCode): ?array;
}
