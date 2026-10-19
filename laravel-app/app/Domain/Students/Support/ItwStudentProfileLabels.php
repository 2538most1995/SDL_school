<?php

namespace App\Domain\Students\Support;

final class ItwStudentProfileLabels
{
    // DOLE ITW51 2.0 manual (10 Apr 2024), printed p.25, figure 3.27.
    private const RELIGIONS = ['1' => 'พุทธ', '2' => 'คริสต์', '3' => 'อิสลาม', '4' => 'ฮินดู', '5' => 'ซิกข์', '6' => 'อื่น ๆ'];

    // Printed p.46, figure 5.4 explicitly shows these defaults. Other values
    // remain unresolved until their own ITW dictionary is available.
    private const FAMILY_STATUS = ['1' => 'อยู่ด้วยกัน'];

    /** @param array<string, mixed> $profile
     * @param  array<string, array<string, string>|null>  $dictionaries
     * @return array<string, mixed>
     */
    public static function apply(array $profile, array $dictionaries = []): array
    {
        if ($profile === []) {
            return [];
        }
        $personal = $profile['personal'] ?? [];
        foreach (['nationality' => 'nation', 'occupation' => 'occupat', 'target_group' => 'occtyp'] as $field => $table) {
            $code = $personal[$field.'_code'] ?? null;
            $profile['personal'][$field.'_label'] ??= array_key_exists($table, $dictionaries) && $dictionaries[$table] !== null
                ? self::label($code, $dictionaries[$table])
                : self::existingLabel($field, $code);
        }
        $profile['personal']['religion_label'] ??= self::label($personal['religion_code'] ?? null, $dictionaries['religion'] ?? self::RELIGIONS);
        $profile['family']['marital_status_label'] ??= self::label($personal['marital_status_code'] ?? null, self::FAMILY_STATUS);
        foreach (['father', 'mother', 'guardian'] as $member) {
            $data = $profile['family'][$member] ?? [];
            $profile['family'][$member]['occupation_label'] ??= isset($dictionaries['occupat'])
                ? self::label($data['occupation_code'] ?? null, $dictionaries['occupat'])
                : self::existingLabel('occupation', $data['occupation_code'] ?? null);
            if ($member !== 'guardian') {
                $profile['family'][$member]['status_label'] ??= self::label($data['status_code'] ?? null, self::FAMILY_STATUS);
            }
        }
        $profile['prior_education']['qualification_label'] ??= self::label($profile['prior_education']['qualification_code'] ?? null, $dictionaries['s_grade'] ?? []);

        return $profile;
    }

    /** @param array<string, string> $dictionary */
    private static function label(mixed $value, array $dictionary): ?string
    {
        $code = trim((string) $value);
        if ($code === '') {
            return null;
        }
        $key = ctype_digit($code) ? (ltrim($code, '0') ?: '0') : $code;
        if (isset($dictionary[$key])) {
            return $dictionary[$key];
        }

        return preg_match('/\p{Thai}/u', $code) === 1 ? $code : 'ยังไม่พบคำอธิบายข้อมูล';
    }

    private static function existingLabel(string $category, mixed $value): ?string
    {
        if (trim((string) $value) === '') {
            return null;
        }
        $label = RegistrationStatistics::itemLabel($category, (string) $value);

        return str_contains($label, '(รหัส') ? 'ยังไม่พบคำอธิบายข้อมูล' : $label;
    }
}
