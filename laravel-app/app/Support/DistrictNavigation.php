<?php

namespace App\Support;

use App\Models\District;
use Illuminate\Support\Facades\Schema;

final class DistrictNavigation
{
    public function groups(?int $districtId): array
    {
        $defaults = config('sena.modules');
        $saved = $districtId && Schema::hasColumn('districts', 'navigation_preferences')
            ? District::query()->find($districtId)?->navigation_preferences : null;
        if (! is_array($saved)) {
            return $defaults;
        }
        $items = collect($defaults)->flatMap(fn (array $group) => $group['items'])->keyBy('key');
        $used = [];
        $groups = [];
        foreach ($saved as $group) {
            $resolved = [];
            foreach ($group['items'] ?? [] as $key) {
                if ($items->has($key) && ! isset($used[$key])) {
                    $resolved[] = $items[$key];
                    $used[$key] = true;
                }
            }
            $groups[] = ['key' => $group['key'], 'label' => $group['label'], 'color' => 'blue', 'items' => $resolved];
        }
        // New application modules remain reachable after a deployment.
        foreach ($defaults as $default) {
            $remaining = array_values(array_filter($default['items'], fn (array $item) => ! isset($used[$item['key']])));
            if ($remaining === []) {
                continue;
            }
            $index = array_search($default['key'], array_column($groups, 'key'), true);
            if ($index === false) {
                $groups[] = [...$default, 'items' => $remaining];
            } else {
                $groups[$index]['items'] = [...$groups[$index]['items'], ...$remaining];
            }
        }

        return $groups;
    }
}
