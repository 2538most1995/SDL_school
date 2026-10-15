<?php

namespace App\Support;

use App\Models\District;
use Illuminate\Support\Facades\Schema;

final class StudentDashboardMenu
{
    private const FEATURED = [
        'my-learning' => ['ข้อมูลส่วนตัว', 'โปรไฟล์และสถานะการศึกษา', 'blue'],
        'learning-home' => ['พื้นที่การเรียนรู้', 'ภาพรวมงานและบทเรียน', 'violet'],
        'assignments' => ['งานและการส่งงาน', 'ติดตามกำหนดและส่งงาน', 'rose'],
        'resources' => ['คลังสื่อ', 'เอกสารและสื่อการเรียน', 'teal'],
        'public-relations' => ['ข่าวประชาสัมพันธ์', 'ข่าวและข้อมูลสำคัญ', 'amber'],
        'schedule' => ['ตารางสอบ', 'วัน เวลา และห้องสอบ', 'sky'],
        'exam-attendance-check' => ['เช็คชื่อเข้าสอบ', 'ตรวจสอบสถานะการเข้าสอบ', 'emerald'],
        'nnet-report' => ['รายงานผล N-NET', 'ดูผลสอบรายสาระ', 'indigo'],
    ];

    public function canSave(): bool
    {
        return Schema::hasColumn('districts', 'student_menu_preferences');
    }

    public function defaults(): array
    {
        $modules = collect(config('sena.modules'))
            ->flatMap(fn (array $group) => $group['items'])
            ->filter(fn (array $item): bool => $item['key'] !== 'dashboard' && in_array('student', $item['roles'], true))
            ->keyBy('key');
        $keys = array_unique([...array_keys(self::FEATURED), ...$modules->keys()->all()]);
        $items = [];
        foreach ($keys as $key) {
            if (! $modules->has($key)) {
                continue;
            }
            $module = $modules[$key];
            $featured = self::FEATURED[$key] ?? null;
            $items[] = [
                'key' => $key,
                'label' => $featured[0] ?? $module['label'],
                'description' => $featured[1] ?? $module['description'],
                'route' => $module['route'],
                'icon' => $module['icon'],
                'tone' => $featured[2] ?? 'blue',
                'visible' => $featured !== null,
            ];
        }

        return $items;
    }

    public function items(?int $districtId): array
    {
        $defaults = $this->defaults();
        $saved = $districtId && $this->canSave()
            ? District::query()->find($districtId)?->student_menu_preferences : null;
        if (! is_array($saved)) {
            return $defaults;
        }
        $available = collect($defaults)->keyBy('key');
        $used = [];
        $items = [];
        foreach ($saved as $item) {
            $key = is_array($item) ? ($item['key'] ?? null) : null;
            if (! is_string($key) || ! $available->has($key) || isset($used[$key])) {
                continue;
            }
            $resolved = $available[$key];
            foreach (['label' => 80, 'description' => 160] as $field => $max) {
                $text = $item[$field] ?? null;
                if (is_string($text) && mb_strlen($text) <= $max && ($field === 'description' || preg_match('/\S/u', $text))) {
                    $resolved[$field] = trim($text);
                }
            }
            if (is_bool($item['visible'] ?? null)) {
                $resolved['visible'] = $item['visible'];
            }
            $items[] = $resolved;
            $used[$key] = true;
        }
        // Newly deployed student modules remain available to administrators.
        foreach ($defaults as $item) {
            if (! isset($used[$item['key']])) {
                $items[] = $item;
            }
        }

        return $items;
    }
}
