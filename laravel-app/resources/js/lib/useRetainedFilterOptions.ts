import { useEffect, useMemo, useRef } from 'react';
import { retainSelectedFilterOption, type FilterSelectOption } from './filterOptions';

export function useRetainedFilterOptions(
    options: FilterSelectOption[],
    selectedValue: string,
): FilterSelectOption[] {
    const labels = useRef(new Map<string, string>());

    useEffect(() => {
        options.forEach((option) => labels.current.set(option.value, option.label));
    }, [options]);

    return useMemo(
        () => retainSelectedFilterOption(options, selectedValue, labels.current.get(selectedValue)),
        [options, selectedValue],
    );
}
