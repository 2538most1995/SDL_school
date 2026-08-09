<?php

namespace Tests\Feature;

use App\Domain\Students\Repositories\StudentRepository;
use App\Models\District;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class LegacyProductionReadIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_routes_read_the_real_legacy_database_with_district_scope(): void
    {
        if (! filter_var(env('LEGACY_PORTAL_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set LEGACY_PORTAL_INTEGRATION=true to run the read-only production route smoke test.');
        }

        config()->set('legacy.enabled', true);
        config()->set('legacy.student_enabled', true);
        $district = District::create(['id' => 1, 'name' => 'อำเภอทดสอบ', 'code' => 'integration-district']);
        $user = User::factory()->create([
            'role' => 'admin',
            'district_id' => $district->id,
            'auth_source' => 'legacy',
            'legacy_user_id' => 1,
        ]);
        Sanctum::actingAs($user);

        $directoryResponse = $this->getJson('/api/v1/students?per_page=100')
            ->assertOk()
            ->assertJsonPath('meta.mode', 'production')
            ->assertJsonPath('meta.source', 'legacy_read_only');
        $this->assertTrue(collect($directoryResponse->json('data'))->contains(
            static fn (array $student): bool => preg_match('/^\d{13}$/', (string) data_get($student, 'demographics.citizen_id')) === 1,
        ));
        $this->getJson('/api/v1/portal')->assertOk()->assertJsonPath('data.mode', 'production');
        foreach ([
            '/api/v1/learning',
            '/api/v1/learning/assignments',
            '/api/v1/learning/resources',
            '/api/v1/learning/lesson-plans',
            '/api/v1/learning/calendar',
            '/api/v1/learning/schedule',
            '/api/v1/learning/scores',
        ] as $endpoint) {
            $this->getJson($endpoint)->assertOk();
        }
        foreach ([
            '/api/v1/reports/new-students',
            '/api/v1/reports/graduates',
            '/api/v1/reports/transfers',
            '/api/v1/reports/registered-subjects',
            '/api/v1/reports/students/grades-above-two',
            '/api/v1/reports/students/exam-attendance',
        ] as $endpoint) {
            $this->getJson($endpoint)->assertOk();
        }
        $this->getJson('/api/v1/admin/users')->assertOk()->assertJsonPath('meta.source', 'system_database');
        $this->getJson('/api/v1/admin/imports')->assertOk()->assertJsonPath('meta.read_only', true);
        $this->getJson('/api/v1/admin/imports/safety')->assertOk()->assertJsonPath('meta.read_only', true);
        $this->getJson('/api/v1/admin/exam-rooms')->assertOk()->assertJsonPath('meta.sync_enabled', false);

        $record = collect(app(StudentRepository::class)->students([1]))->first(
            static fn ($student): bool => $student->citizenId !== null
                && $student->phone !== null
                && ($student->registeredAddress !== null || $student->currentAddress !== null)
                && $student->gpax > 0
                && $student->kpchHours > 0
                && $student->moralResult !== 'ยังไม่มีผลประเมิน',
        );
        $this->assertNotNull($record);
        $studentUser = User::factory()->create([
            'role' => 'student',
            'district_id' => $district->id,
            'username' => $record->code,
            'student_code' => $record->code,
            'auth_source' => 'legacy',
        ]);
        Sanctum::actingAs($studentUser);

        $detailResponse = $this->getJson('/api/v1/students/'.urlencode($record->code))
            ->assertOk()
            ->assertJsonStructure(['data' => ['contact' => ['phone']]]);
        $this->assertMatchesRegularExpression(
            '/^0[0-9]{8,9}$/',
            (string) data_get($detailResponse->json(), 'data.contact.phone'),
            'The legacy FPT phone memo should be decoded to a complete Thai phone number.',
        );
        $this->assertMatchesRegularExpression(
            '/^\d{2}\/\d{2}\/\d{4}$/',
            (string) data_get($detailResponse->json(), 'data.demographics.last_updated'),
            'The Visual FoxPro datetime should be decoded instead of rendered as pointer bytes.',
        );
        $this->assertTrue(
            data_get($detailResponse->json(), 'data.contact.registered_address') !== null
            || data_get($detailResponse->json(), 'data.contact.current_address') !== null,
        );
        $resolvedAddress = (string) (
            data_get($detailResponse->json(), 'data.contact.registered_address')
            ?? data_get($detailResponse->json(), 'data.contact.current_address')
        );
        $this->assertTrue(
            str_contains($resolvedAddress, 'ตำบล') && str_contains($resolvedAddress, 'จังหวัด'),
            'The legacy address should be resolved from its administrative area code.',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/[๐-๙]/u',
            $resolvedAddress,
            'Raw memo-pointer bytes must never be shown as Thai numerals in an address.',
        );
        $this->getJson('/api/v1/my-learning')->assertOk()->assertJsonPath('data.code', $record->code);
        $this->getJson('/api/v1/grades')->assertOk()->assertJsonCount(count(app(StudentRepository::class)->gradesFor($record)), 'data');
        $gradeDetailResponse = $this->getJson('/api/v1/students/'.urlencode($record->code).'/grades')
            ->assertOk()
            ->assertJsonStructure(['data' => ['summary' => ['gpax', 'earned_credits', 'compulsory_credits', 'elective_credits', 'graded_credits', 'registered_subjects', 'passed_subjects']]]);
        $this->assertGreaterThan(0, (float) data_get($gradeDetailResponse->json(), 'data.summary.gpax'));
        $this->getJson('/api/v1/kpch')->assertOk()->assertJsonStructure(['data' => [['term', 'item', 'result', 'hours', 'category']]]);
        $moralResponse = $this->getJson('/api/v1/moral')
            ->assertOk()
            ->assertJsonStructure(['data' => [['term', 'group', 'item', 'result', 'note', 'score']]]);
        $this->assertGreaterThanOrEqual(11, count($moralResponse->json('data')));
        $this->assertSame(0, count($moralResponse->json('data')) % 11);
    }
}
