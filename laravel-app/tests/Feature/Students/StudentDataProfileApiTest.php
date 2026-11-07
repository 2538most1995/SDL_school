<?php

namespace Tests\Feature\Students;

use App\Domain\Students\Models\Student;
use App\Domain\Students\Repositories\StudentProfileRepository;
use App\Domain\Students\Repositories\StudentRepository;
use App\Http\Resources\Students\StudentProfileIntegrationResource;
use App\Models\District;
use App\Models\StudentApiClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Fakes\PiiStudentRepository;
use Tests\TestCase;

final class StudentDataProfileApiTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/integrations/student-data/students/STU001/profile';

    private District $district;

    private District $otherDistrict;

    private User $admin;

    private StudentRepository&MockInterface $repository;

    /** @var list<Student> */
    private array $students;

    /** @var array<string, mixed> */
    private array $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-07 12:00:00', 'Asia/Bangkok'));
        $this->district = District::query()->create(['name' => 'อำเภอทดสอบ', 'code' => 'profile-api', 'is_active' => true]);
        $this->otherDistrict = District::query()->create(['name' => 'อำเภออื่น', 'code' => 'profile-api-other', 'is_active' => true]);
        $this->admin = User::factory()->create(['role' => 'admin', 'district_id' => $this->district->id, 'disabled_at' => null]);
        StudentApiClient::query()->create([
            'name' => 'profile-test', 'user_id' => $this->admin->id, 'district_id' => $this->district->id,
            'token_hash' => hash('sha256', 'profile-token'), 'abilities' => ['student-data:read'], 'expires_at' => now()->addHour(),
        ]);
        $this->students = (new PiiStudentRepository($this->district->id, $this->district->name, $this->otherDistrict->id))->students();
        $this->students[0] = new Student(...[
            ...get_object_vars($this->students[0]),
            'demographics' => [
                'birth_date' => '01/01/2550', 'age' => 99, 'gender' => 'ชาย', 'application_date' => '01/05/2568',
                'last_updated' => '01/11/2569', 'citizen_id_masked' => '1-xxxx-xxxxx-xx-1', 'citizen_id' => '2222222222222',
                'raw_source' => ['cardid' => '1111111111111'],
            ],
            'contact' => ['email' => 'student@example.test', 'email_masked' => 's***@example.test', 'cardid' => '1111111111111'],
            'email' => 'original@example.test',
            'guardian' => ['name' => 'ผู้ปกครองลับ', 'phone_masked' => '09x-xxx-1234', 'citizen_id' => '1111111111111'],
        ]);
        $this->profile = [
            'personal' => [
                'nationality_code' => '099', 'religion_code' => '01', 'occupation_code' => '01',
                'target_group_code' => '01', 'height_cm' => '170', 'weight_kg' => '60', 'marital_status_code' => '1',
                'citizen_id' => '1111111111111', 'cardid' => '1111111111111',
            ],
            'family' => [
                'father' => ['name' => 'บิดาทดสอบ', 'occupation_code' => '01', 'income' => '0', 'citizen_id' => '1111111111111'],
                'mother' => ['name' => 'มารดาทดสอบ', 'status_code' => '01', 'raw_source' => ['cardid' => '1111111111111']],
                'guardian' => ['name' => 'ผู้ปกครองทดสอบ', 'national_id' => '1111111111111'],
                'siblings' => '0', 'studying_siblings' => '0',
            ],
            'prior_education' => ['qualification_code' => '02', 'graduation_year' => '2566', 'school' => 'โรงเรียนทดสอบ', 'province' => '14', 'cardid' => '1111111111111'],
            'raw_source' => ['cardid' => '1111111111111'],
        ];
        $this->repository = Mockery::mock(StudentRepository::class, StudentProfileRepository::class);
        $this->repository->shouldReceive('students')->andReturnUsing(fn (?array $districts = null): array => array_values(array_filter(
            $this->students, static fn (Student $student): bool => $districts === null || in_array($student->districtId, $districts, true),
        )));
        $this->repository->shouldReceive('find')->andReturnUsing(function (string $code, ?int $district = null, ?int $level = null): ?Student {
            $matches = array_values(array_filter($this->students, static fn (Student $student): bool => $student->code === $code
                && ($district === null || $student->districtId === $district) && ($level === null || $student->level === $level)));

            return count($matches) === 1 ? $matches[0] : null;
        });
        $this->repository->shouldReceive('profileFor')->andReturnUsing(fn (): array => $this->profile);
        $this->app->instance(StudentRepository::class, $this->repository);
    }

    public function test_profile_exposes_canonical_student_citizen_id_with_personal_details(): void
    {
        $response = $this->withToken('profile-token')->getJson(self::ENDPOINT)->assertOk()
            ->assertJsonPath('data.code', 'STU001')->assertJsonPath('data.demographics.birth_date', '01/01/2550')
            ->assertJsonPath('data.demographics.citizen_id', '1111111111111')
            ->assertJsonPath('data.demographics.age', 19)->assertJsonPath('data.contact.phone', '0899999999')
            ->assertJsonPath('data.contact.email', 'original@example.test')
            ->assertJsonPath('data.contact.registered_address', 'บ้านเลขที่ 99 ที่อยู่ทะเบียนลับ')
            ->assertJsonPath('data.contact.current_address', 'บ้านเลขที่ 100 ที่อยู่ปัจจุบันลับ')
            ->assertJsonPath('data.social.line_id', 'private-line')
            ->assertJsonPath('data.profile.personal.religion_label', 'พุทธ')
            ->assertJsonPath('data.profile.personal.height_cm', '170')
            ->assertJsonPath('data.profile.family.father.name', 'บิดาทดสอบ')
            ->assertJsonPath('data.profile.family.father.income', '0')
            ->assertJsonPath('data.profile.family.siblings', '0')
            ->assertJsonPath('data.profile.family.marital_status_label', 'อยู่ด้วยกัน')
            ->assertJsonPath('data.profile.prior_education.school', 'โรงเรียนทดสอบ')
            ->assertJsonPath('data.profile.prior_education.province_label', 'พระนครศรีอยุธยา')
            ->assertJsonPath('meta.data_classification', 'personal_data_sensitive')
            ->assertJsonPath('meta.citizen_id_included', true)->assertJsonPath('meta.profile_included', true)
            ->assertJsonPath('meta.read_only', true)->assertHeader('Vary', 'Authorization');
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        self::assertStringContainsString('private', $response->headers->get('Cache-Control'));
        self::assertSame(1, substr_count($response->getContent(), '1111111111111'));
        foreach (['2222222222222', '1-xxxx-xxxxx-xx-1', 'citizen_id_masked', 'cardid', 'national_id', 'raw_source', 'password', 'token_hash'] as $value) {
            self::assertStringNotContainsString($value, $response->getContent());
        }
    }

    public function test_existing_student_endpoint_keeps_its_original_minimal_payload(): void
    {
        $this->withToken('profile-token')->getJson('/api/v1/integrations/student-data/students/STU001')
            ->assertOk()->assertJsonMissingPath('data.profile')->assertJsonMissingPath('data.contact')
            ->assertJsonMissingPath('data.demographics')->assertJsonPath('meta.sensitive_identifiers_included', false);
        $this->repository->shouldNotHaveReceived('profileFor');
    }

    public function test_email_is_preserved_when_saved_social_profiles_enrich_the_student(): void
    {
        DB::table('student_social_profiles')->insert([
            'district_id' => $this->district->id, 'student_code' => 'STU001', 'line_id' => 'updated-line',
        ]);
        $this->withToken('profile-token')->getJson(self::ENDPOINT)->assertOk()
            ->assertJsonPath('data.social.line_id', 'updated-line')
            ->assertJsonPath('data.contact.email', 'original@example.test');
    }

    public function test_profile_requires_token_and_rejects_revoked_expired_or_wrong_ability_clients(): void
    {
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
        $this->actingAs($this->admin)->getJson(self::ENDPOINT)->assertUnauthorized();
        $this->withToken('incorrect')->getJson(self::ENDPOINT)->assertUnauthorized();
        $client = StudentApiClient::query()->firstOrFail();
        $client->update(['revoked_at' => now()]);
        $this->withToken('profile-token')->getJson(self::ENDPOINT)->assertUnauthorized();
        $client->update(['revoked_at' => null, 'expires_at' => now()->subMinute()]);
        $this->withToken('profile-token')->getJson(self::ENDPOINT)->assertUnauthorized();
        $client->update(['expires_at' => null, 'abilities' => ['other:read']]);
        $this->withToken('profile-token')->getJson(self::ENDPOINT)->assertUnauthorized();
        $this->repository->shouldNotHaveReceived('profileFor');
    }

    public function test_profile_rejects_other_district_records_even_for_super_admin_tokens(): void
    {
        $endpoint = '/api/v1/integrations/student-data/students/OTHER001/profile';
        $this->withToken('profile-token')->getJson($endpoint)->assertNotFound();
        $this->admin->update(['role' => 'super_admin', 'district_id' => null]);
        $this->withToken('profile-token')->getJson($endpoint)->assertNotFound();
        $this->repository->shouldNotHaveReceived('find', ['OTHER001', null, null]);
        $this->withToken('profile-token')->getJson(self::ENDPOINT.'?district_id='.$this->otherDistrict->id)->assertUnprocessable();
        $this->withToken('profile-token')->withHeader('X-District-Id', (string) $this->otherDistrict->id)->getJson(self::ENDPOINT)->assertUnprocessable();
        $this->repository->shouldNotHaveReceived('profileFor');
    }

    public function test_level_selects_ambiguous_codes_and_invalid_or_missing_students_are_rejected(): void
    {
        $this->students[] = new Student(...[...get_object_vars($this->students[0]), 'level' => 3, 'levelLabel' => 'มัธยมศึกษาตอนปลาย']);
        $this->withToken('profile-token')->getJson(self::ENDPOINT)->assertNotFound();
        $this->withToken('profile-token')->getJson(self::ENDPOINT.'?level=3')->assertOk()->assertJsonPath('data.level.id', 3);
        $this->withToken('profile-token')->getJson(self::ENDPOINT.'?level=4')->assertUnprocessable();
        $this->withToken('profile-token')->getJson('/api/v1/integrations/student-data/students/UNKNOWN/profile')->assertNotFound();
    }

    public function test_missing_fields_are_null_and_current_address_is_not_replaced(): void
    {
        $this->profile = [];
        $this->students[0] = new Student(...[
            ...get_object_vars($this->students[0]), 'demographics' => [], 'contact' => [], 'guardian' => [],
            'phone' => null, 'currentAddress' => null, 'facebookUrl' => null, 'lineId' => null, 'email' => null, 'citizenId' => null,
        ]);
        $this->withToken('profile-token')->getJson(self::ENDPOINT)->assertOk()
            ->assertJsonPath('data.demographics.birth_date', null)->assertJsonPath('data.demographics.age', null)
            ->assertJsonPath('data.demographics.citizen_id', null)
            ->assertJsonPath('data.contact.phone', null)->assertJsonPath('data.contact.current_address', null)
            ->assertJsonPath('data.profile.family.father.name', null)->assertJsonPath('data.profile.prior_education.school', null);
    }

    public function test_origin_rate_limit_proxy_transport_and_read_only_rules_apply(): void
    {
        $this->withToken('profile-token')->withHeader('Origin', 'https://partner.example.test')->getJson(self::ENDPOINT)->assertForbidden();
        $this->flushHeaders();
        config(['system_data.student_api_rate_limit_per_minute' => 1]);
        $this->withHeader('X-Student-Data-Token', 'profile-token')->getJson(self::ENDPOINT)->assertOk();
        $this->getJson(self::ENDPOINT)->assertTooManyRequests()->assertHeader('Retry-After');
        foreach (['postJson', 'patchJson', 'deleteJson'] as $method) {
            $this->{$method}(self::ENDPOINT)->assertMethodNotAllowed();
        }
    }

    public function test_resource_rechecks_district_before_serializing_personal_fields(): void
    {
        $this->admin->update(['role' => 'super_admin', 'district_id' => null]);
        $this->admin->setRelation('selectedDistrictContext', $this->district->id);
        $request = Request::create(self::ENDPOINT);
        $request->attributes->set('district_id', $this->district->id);
        $request->setUserResolver(fn (): User => $this->admin);
        $this->expectException(HttpException::class);
        (new StudentProfileIntegrationResource($this->students[2], $this->profile))->resolve($request);
    }
}
