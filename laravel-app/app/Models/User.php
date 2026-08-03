<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'username', 'password', 'role', 'district_id', 'assigned_groups', 'legacy_key', 'legacy_user_id', 'student_code', 'auth_source', 'display_name_override', 'contact_email', 'avatar_path', 'avatar_updated_at', 'theme', 'color_scheme', 'font_size', 'density'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'assigned_groups' => 'array',
            'disabled_at' => 'datetime',
            'contact_email' => 'encrypted',
            'avatar_updated_at' => 'datetime',
        ];
    }

    public function displayName(): string
    {
        return (string) ($this->display_name_override ?: $this->name);
    }

    public function avatarUrl(): ?string
    {
        if (! filled($this->avatar_path)) {
            return null;
        }

        $version = substr(hash('sha256', (string) $this->avatar_path), 0, 12);

        return "/api/v1/settings/profile/avatar?v={$version}";
    }
}
