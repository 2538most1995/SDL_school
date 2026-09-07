<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'user_id', 'district_id', 'token_hash', 'abilities', 'expires_at', 'revoked_at', 'last_used_at'])]
#[Hidden(['token_hash'])]
final class StudentApiClient extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function allows(string $ability): bool
    {
        return in_array($ability, $this->abilities ?? [], true);
    }
}
