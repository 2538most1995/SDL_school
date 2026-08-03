<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'code', 'login_title', 'login_subtitle', 'portal_name', 'welcome_message', 'primary_color', 'logo_path', 'logo_updated_at', 'login_hero_path', 'login_hero_updated_at', 'dashboard_hero_path', 'dashboard_hero_updated_at', 'is_active'])]
class District extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'logo_updated_at' => 'datetime',
            'login_hero_updated_at' => 'datetime',
            'dashboard_hero_updated_at' => 'datetime',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
