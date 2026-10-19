<?php

namespace Tests\Unit;

use App\Domain\Students\Support\ItwStudentProfileLabels;
use PHPUnit\Framework\TestCase;

final class ItwStudentProfileLabelsTest extends TestCase
{
    public function test_manual_verified_religions_and_family_status_are_text(): void
    {
        foreach (['01' => 'พุทธ', '02' => 'คริสต์', '03' => 'อิสลาม', '04' => 'ฮินดู', '05' => 'ซิกข์', '06' => 'อื่น ๆ'] as $code => $label) {
            $profile = ItwStudentProfileLabels::apply([
                'personal' => ['religion_code' => $code, 'marital_status_code' => '1'],
                'family' => ['father' => ['status_code' => '01']],
            ]);
            $this->assertSame($label, $profile['personal']['religion_label']);
            $this->assertSame('อยู่ด้วยกัน', $profile['family']['marital_status_label']);
            $this->assertSame('อยู่ด้วยกัน', $profile['family']['father']['status_label']);
        }
    }

    public function test_supplied_itw_dictionaries_override_defaults_and_survive_service_enrichment(): void
    {
        $profile = ItwStudentProfileLabels::apply([
            'personal' => ['religion_code' => '01', 'occupation_code' => '05'],
            'prior_education' => ['qualification_code' => '02'],
        ], ['religion' => ['1' => 'ชื่อศาสนาเฉพาะสถานศึกษา'], 'occupat' => ['5' => 'ชื่ออาชีพจาก ITW'], 's_grade' => ['2' => 'ป.6']]);
        $this->assertSame('ชื่อศาสนาเฉพาะสถานศึกษา', $profile['personal']['religion_label']);
        $this->assertSame('ชื่ออาชีพจาก ITW', $profile['personal']['occupation_label']);
        $this->assertSame('ป.6', $profile['prior_education']['qualification_label']);
        $this->assertSame($profile, ItwStudentProfileLabels::apply($profile));
    }

    public function test_unknown_values_never_become_raw_codes_or_guessed_statuses(): void
    {
        $profile = ItwStudentProfileLabels::apply([
            'personal' => ['religion_code' => '98', 'marital_status_code' => '7', 'nationality_code' => '999'],
            'family' => ['father' => ['status_code' => '99', 'occupation_code' => '99']],
            'prior_education' => ['qualification_code' => '02'],
        ]);
        $this->assertSame('ยังไม่พบคำอธิบายข้อมูล', $profile['personal']['religion_label']);
        $this->assertSame('ยังไม่พบคำอธิบายข้อมูล', $profile['personal']['nationality_label']);
        $this->assertSame('ยังไม่พบคำอธิบายข้อมูล', $profile['family']['marital_status_label']);
        $this->assertSame('ยังไม่พบคำอธิบายข้อมูล', $profile['family']['father']['status_label']);
        $this->assertSame('ยังไม่พบคำอธิบายข้อมูล', $profile['family']['father']['occupation_label']);
        $this->assertSame('ยังไม่พบคำอธิบายข้อมูล', $profile['prior_education']['qualification_label']);
        $this->assertNull($profile['family']['mother']['status_label']);
        $this->assertSame([], ItwStudentProfileLabels::apply([]));
        $this->assertSame('01', ItwStudentProfileLabels::apply(['personal' => ['religion_code' => '01']])['personal']['religion_code']);
    }
}
