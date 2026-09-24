<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'district_id',
    'academic_year',
    'round',
    'education_level',
    'education_level_label',
    'seat_no',
    'citizen_id',
    'student_code',
    'student_name',
    'group_code',
    'group_name',
    'total_score',
    'has_score',
    'subject_codes',
    'subject_names',
    'subject_scores',
    'subject_levels',
    'created_by',
    'notes',
])]
final class NnetResult extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'round' => 'integer',
            'education_level' => 'integer',
            'total_score' => 'float',
            'has_score' => 'boolean',
            'subject_codes' => 'array',
            'subject_names' => 'array',
            'subject_scores' => 'array',
            'subject_levels' => 'array',
        ];
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
