<?php

namespace App\Domain\Students\Support;

enum RegistrationType: string
{
    case Regular = 'regular';
    case All = 'all';
    case Transfer = 'transfer';

    public function matches(bool $transferred): bool
    {
        return match ($this) {
            self::Regular => ! $transferred,
            self::All => true,
            self::Transfer => $transferred,
        };
    }
}
