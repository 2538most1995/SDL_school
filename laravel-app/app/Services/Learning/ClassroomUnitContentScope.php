<?php

namespace App\Services\Learning;

use App\Models\User;
use Illuminate\Database\Query\Builder;

/** Shared optional selector for legacy files, resources and task lists. */
final readonly class ClassroomUnitContentScope
{
    public function __construct(private ClassroomUnitService $units) {}

    public static function rules(): array
    {
        return ['nullable', 'regex:/^(legacy|[1-9][0-9]*)$/'];
    }

    public function apply(Builder $query, User $viewer, int $districtId, string $alias = '', mixed $unitId = null, ?array $catalog = null): void
    {
        $this->units->scopeQuery($query, $viewer, $districtId, $alias, $catalog);
        if ($unitId === null || $unitId === '') {
            return;
        }
        $this->units->ready();
        $column = ($alias === '' ? '' : $alias.'.').'classroom_unit_id';
        if ((string) $unitId === 'legacy') {
            $query->whereNull($column);

            return;
        }
        $this->units->authorized($viewer, $districtId, (int) $unitId, false, $catalog);
        $query->where($column, (int) $unitId);
    }
}
