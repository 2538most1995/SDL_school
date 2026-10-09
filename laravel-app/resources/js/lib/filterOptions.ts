export type FilterSelectOption = {
    value: string;
    label: string;
    count?: number;
};

export function retainSelectedFilterOption(
    options: readonly FilterSelectOption[],
    selectedValue: string,
    selectedLabel?: string,
): FilterSelectOption[] {
    if (!selectedValue || options.some((option) => option.value === selectedValue)) {
        return [...options];
    }

    return [
        { value: selectedValue, label: selectedLabel?.trim() || selectedValue },
        ...options,
    ];
}
