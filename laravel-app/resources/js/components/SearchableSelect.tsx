import { Autocomplete, TextField, createFilterOptions } from '@mui/material';

type Option = { value: string; label: string };
const filterOptions = createFilterOptions<Option>({ stringify: (option) => `${option.label} ${option.value}` });

/** Search labels/codes while submitting only an existing option's value. */
export function SearchableSelect({ value, options, allLabel, onChange, 'aria-label': label }: {
    value: string;
    options: Option[];
    allLabel: string;
    onChange: (value: string) => void;
    'aria-label'?: string;
}) {
    const choices = [{ value: '', label: allLabel }, ...options];
    return <Autocomplete
        fullWidth
        options={choices}
        value={choices.find((option) => option.value === value) ?? null}
        onChange={(_, option) => onChange(option?.value ?? '')}
        getOptionLabel={(option) => option.label}
        getOptionKey={(option) => option.value}
        isOptionEqualToValue={(option, selected) => option.value === selected.value}
        filterOptions={filterOptions}
        noOptionsText="ไม่พบรายการที่ค้นหา"
        clearText="ล้างการเลือก"
        openText="เปิดรายการ"
        closeText="ปิดรายการ"
        autoHighlight
        renderInput={(params) => <TextField {...params} placeholder="พิมพ์ชื่อหรือรหัสเพื่อค้นหา" slotProps={{ ...params.slotProps, htmlInput: { ...params.slotProps.htmlInput, 'aria-label': label } }} />}
        slotProps={{ listbox: { sx: { maxHeight: 320, '& .MuiAutocomplete-option': { whiteSpace: 'normal', minHeight: 44 } } } }}
    />;
}
