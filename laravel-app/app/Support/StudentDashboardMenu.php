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
                'custom' => false,
                'external' => false,
            ];
        }

        return $items;
    }

    public function internalRoutes(): array
    {
        return collect(config('sena.modules'))->flatMap(fn (array $group) => $group['items'])
            ->filter(fn (array $item): bool => in_array('student', $item['roles'], true))
            ->map(fn (array $item): array => ['label' => $item['label'], 'route' => $item['route']])
            ->unique('route')->values()->all();
    }

    public function isCustomKey(mixed $key): bool
    {
        return is_string($key) && preg_match('/^custom-[a-z0-9-]{1,64}$/D', $key) === 1;
    }

    public function customItem(array $item): ?array
    {
        if (! $this->isCustomKey($item['key'] ?? null)
            || ! is_string($item['label'] ?? null) || ! preg_match('/\S/u', $item['label']) || mb_strlen($item['label']) > 80
            || ! is_string($item['description'] ?? null) || mb_strlen($item['description']) > 160
            || ! is_bool($item['visible'] ?? null)
            || ! is_string($item['url'] ?? null) || mb_strlen($item['url']) > 2048
            || ! in_array($item['link_type'] ?? null, ['internal', 'external'], true)) {
            return null;
        }
        $url = $item['url'];
        $external = $item['link_type'] === 'external';
        if ($external ? ! $this->isExternalUrl($url) : ! in_array($url, array_column($this->internalRoutes(), 'route'), true)) {
            return null;
        }

        return [
            'key' => $item['key'], 'label' => trim($item['label']), 'description' => trim($item['description']),
            'visible' => $item['visible'], 'route' => $url, 'icon' => 'link', 'tone' => 'blue',
            'custom' => true, 'external' => $external, 'link_type' => $item['link_type'], 'url' => $url,
        ];
    }

    private function isExternalUrl(string $url): bool
    {
        if (preg_match('/[\\x00-\\x20\\x7f\\\\\\p{Z}]/u', $url)
            || preg_match('/%(?![a-f0-9]{2})/i', $url)
            || preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|7f)/i', $url)
            || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && filled($parts['host'] ?? null) && ! array_key_exists('user', $parts) && ! array_key_exists('pass', $parts);
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
        $customCount = 0;
        foreach ($saved as $item) {
            $key = is_array($item) ? ($item['key'] ?? null) : null;
            if (! is_string($key) || isset($used[$key])) {
                continue;
            }
            if (! $available->has($key)) {
                $custom = $customCount < 50 ? $this->customItem($item) : null;
                if ($custom !== null) {
                    $items[] = $custom;
                    $used[$key] = true;
                    $customCount++;
                }

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
