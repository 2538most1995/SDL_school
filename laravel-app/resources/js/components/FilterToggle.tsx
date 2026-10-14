import { Funnel } from '@phosphor-icons/react';
import { Button } from './MaterialUI';

export function FilterToggle({ expanded, controls, activeCount, onToggle }: {
    expanded: boolean;
    controls: string;
    activeCount: number;
    onToggle: () => void;
}) {
    return (
        <Button appearance="outline" icon={<Funnel size={17} aria-hidden="true" />} onClick={onToggle} aria-expanded={expanded} aria-controls={controls}>
            ตัวกรอง
            {activeCount > 0 && <span className="ui-filter-count" aria-label={`${activeCount} เงื่อนไขที่ใช้อยู่`}>{activeCount}</span>}
        </Button>
    );
}
