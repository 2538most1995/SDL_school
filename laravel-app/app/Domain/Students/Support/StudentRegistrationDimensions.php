<?php

namespace App\Domain\Students\Support;

final class StudentRegistrationDimensions
{
    /** @return array{subject_code: string, subject_name: string, learning_method: string} */
    public static function entry(string $code, string $name, mixed $method): array
    {
        $method = trim((string) $method);

        return [
            'subject_code' => trim($code),
            'subject_name' => trim($name),
            'learning_method' => match ($method) {
                '' => 'ไม่ระบุวิธีเรียน',
                '1' => 'พบกลุ่ม', '2' => 'ทางไกล', '3' => 'ทางไกลพิเศษ',
                default => $method,
            },
        ];
    }

    /** Attach only the requested report population, keyed by district + level + code. */
    public static function attach(array $data, array $byStudent, int $districtId): array
    {
        foreach (['rows', 'items'] as $collection) {
            if (! isset($data[$collection]) || ! is_array($data[$collection])) {
                continue;
            }
            $data[$collection] = array_map(static function (array $row) use ($byStudent, $districtId): array {
                $key = $row['entity_key'] ?? $row['id'] ?? '';
                if (isset($row['student']['code'], $row['student']['level']['id'])) {
                    $key = $districtId.'-'.$row['student']['level']['id'].'-'.$row['student']['code'];
                }
                $dimensions = $byStudent[$key] ?? [];
                // A transfer row represents one transferred subject, not the
                // student's other registrations in the same semester.
                if (isset($row['subject_code'])) {
                    $dimensions = array_values(array_filter($dimensions, static fn (array $entry): bool => $entry['subject_code'] === $row['subject_code']));
                }
                $row['registration_dimensions'] = $dimensions;

                return $row;
            }, $data[$collection]);
        }

        return $data;
    }
}
