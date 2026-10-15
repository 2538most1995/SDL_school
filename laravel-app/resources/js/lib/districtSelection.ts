import { useSyncExternalStore } from 'react';

const districtChanged = 'sena:district-changed';

export function selectDistrict(id: string) {
    if (id) window.localStorage.setItem('sena-district-id', id);
    else window.localStorage.removeItem('sena-district-id');
    window.dispatchEvent(new Event(districtChanged));
}

function subscribe(onChange: () => void) {
    window.addEventListener(districtChanged, onChange);
    window.addEventListener('storage', onChange);
    return () => {
        window.removeEventListener(districtChanged, onChange);
        window.removeEventListener('storage', onChange);
    };
}

export function useSelectedDistrictId() {
    return useSyncExternalStore(subscribe, () => window.localStorage.getItem('sena-district-id'));
}
