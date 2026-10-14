<?php

namespace Tests\Feature\Learning;

use App\Models\District;
use App\Models\NnetResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class NnetScoreIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_edits_preserve_imported_subject_codes_and_reject_mismatched_score_keys(): void
    {
        $district = District::create(['name' => 'Synthetic district', 'code' => 'sena', 'is_active' => true]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'district_id' => $district->id]));
        foreach ([1 => ['111', '112'], 2 => ['211', '212'], 3 => ['401', '402']] as $level => $codes) {
            $record = NnetResult::create(['district_id' => $district->id, 'academic_year' => '2569', 'round' => 1,
                'education_level' => $level, 'education_level_label' => 'Synthetic', 'citizen_id' => '1111111111111',
                'student_name' => 'Synthetic student', 'has_score' => true, 'total_score' => 50,
                'subject_codes' => $codes, 'subject_scores' => [$codes[0] => 40], 'subject_levels' => [$codes[0] => 'old-level']]);
            $this->putJson('/api/v1/nnet/records/'.$record->id, ['subject_scores' => [$codes[0] => 70]])
                ->assertOk()->assertJsonPath('data.subject_codes', $codes)->assertJsonPath('data.subject_scores.'.$codes[0], 70)
                ->assertJsonPath('data.subject_levels', []);
            $this->putJson('/api/v1/nnet/records/'.$record->id, ['subject_scores' => ['411' => 90]])
                ->assertUnprocessable()->assertJsonValidationErrors('subject_scores');
            $this->assertSame([$codes[0] => 70], $record->fresh()->subject_scores);
        }
    }
}
