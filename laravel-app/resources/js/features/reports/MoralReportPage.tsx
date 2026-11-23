import { Heart } from '@phosphor-icons/react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { ExcelFileIcon } from '../../components/FileTypeIcon';
import { Button } from '../../components/MaterialUI';
import { PageHeader } from '../../components/PageHeader';
import { QueryError, QuerySkeleton } from '../../components/QueryState';
import { useDemoRole, type DemoRole } from '../../context/DemoRoleContext';
import { apiGet } from '../../lib/api';
import { useSelectedDistrictId } from '../../lib/districtSelection';
import { showErrorAlert } from '../../lib/feedback';
import { canExportRegistrationStatistics } from './registrationStatisticsExport';

type MoralReportData = {
    terms: string[];
    selected_term: string | null;
    rows: Array<{ level: number; label: string; counts: [number, number, number, number] | null }>;
};
type GroupOption = { value: string | number; label: string };

const results = ['ปรับปรุง', 'พอใช้', 'ดี', 'ดีมาก'];
const allStatusesLabel = 'ทุกสถานะ';
const graduatesOnlyLabel = 'เฉพาะผู้สำเร็จการศึกษา';
const graduateAssessmentRule = 'ใช้ผลในภาคเรียนที่จบ หากไม่มีให้ใช้ผลล่าสุดจากภาคเรียนก่อนจบ';
const selectClass = 'min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-900 focus:border-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-200';
const formatCount = (value: number) => value.toLocaleString('th-TH');

export function MoralReportPage() {
    const districtId = useSelectedDistrictId();
    const { role } = useDemoRole();

    return <MoralReport key={`${districtId}:${role}`} districtId={districtId} role={role} />;
}

function MoralReport({ districtId, role }: { districtId: string | null; role: DemoRole }) {
    const [term, setTerm] = useState('');
    const [group, setGroup] = useState('');
    const [graduatedOnly, setGraduatedOnly] = useState(false);
    const groups = useQuery({
        queryKey: ['moral-report-groups', districtId, role],
        queryFn: ({ signal }) => apiGet<unknown[]>('/api/v1/students?per_page=1', signal),
        staleTime: 60_000,
    });
    const report = useQuery({
        queryKey: ['moral-report', districtId, role, term, group, graduatedOnly],
        queryFn: ({ signal }) => {
            const query = new URLSearchParams();
            if (term) query.set('term', term);
            if (group) query.set('group', group);
            if (graduatedOnly) query.set('graduated_only', '1');
            return apiGet<MoralReportData>(`/api/v1/reports/moral?${query}`, signal).then((response) => response.data);
        },
    });
    const data = report.data;
    const options = (groups.data?.meta as { filter_options?: { groups?: GroupOption[] } } | undefined)?.filter_options?.groups ?? [];
    const groupLabel = options.find((option) => String(option.value) === group)?.label;
    const terms = [...new Set([...(data?.terms ?? []), ...(term ? [term] : [])])];
    const totals = results.map((_, index) => data?.rows.reduce((sum, row) => sum + (row.counts?.[index] ?? 0), 0) ?? 0);
    const assessed = totals.reduce((sum, count) => sum + count, 0);
    const hasSource = data?.rows.some((row) => row.counts !== null);
    const termLabel = graduatedOnly ? 'ภาคเรียนที่จบ' : 'ภาคเรียน';

    async function exportExcel() {
        if (!data?.selected_term) return;
        try {
            const { downloadExcel } = await import('../../lib/excel');
            downloadExcel(`รายงานคุณธรรม-${data.selected_term.replace('/', '-')}`, [
                {
                    name: 'รายงานคุณธรรม',
                    columns: ['ระดับชั้น', ...results],
                    rows: [
                        ...data.rows.map((row) => row.counts ? [row.label, ...row.counts] : [`${row.label} (ไม่มีข้อมูลต้นทาง)`, null, null, null, null]),
                        ['รวม', ...totals],
                    ],
                },
                {
                    name: 'เงื่อนไขรายงาน',
                    columns: ['รายการ', 'ค่าที่ใช้'],
                    rows: [
                        [termLabel, data.selected_term],
                        ['กลุ่มเรียน', groupLabel ?? (role === 'teacher' ? 'กลุ่มที่รับผิดชอบ' : 'ทุกกลุ่มเรียน')],
                        ['สถานะนักศึกษา', graduatedOnly ? graduatesOnlyLabel : allStatusesLabel],
                        ...(graduatedOnly ? [['วิธีเลือกผลประเมิน', graduateAssessmentRule]] : []),
                    ],
                },
            ]);
        } catch (error) {
            showErrorAlert(error instanceof Error ? error.message : 'ไม่สามารถจัดทำไฟล์ Excel ได้');
        }
    }

    return <div className="space-y-4">
        <PageHeader title="รายงานคุณธรรม" category="สถิติ" description="จำนวนผู้เรียนตามผลประเมินคุณธรรมจาก ITW51 แยกตามระดับชั้นและภาคเรียน" icon={Heart}
            actions={canExportRegistrationStatistics(role) && <Button icon={<ExcelFileIcon size={18} />} disabled={!hasSource || !data?.selected_term || report.isFetching} onClick={() => void exportExcel()}>บันทึกเป็น Excel</Button>} />
        <section className="space-y-4 rounded-2xl border border-slate-200 bg-white p-4 sm:p-5" aria-label="สรุปผลประเมินคุณธรรม">
            <div className="grid gap-3 sm:grid-cols-2 lg:max-w-5xl lg:grid-cols-3">
                <label className="grid gap-2 text-sm font-bold text-slate-800">{termLabel}
                    <select value={term} onChange={(event) => setTerm(event.target.value)} className={selectClass}>
                        <option value="">ล่าสุด{!term && data?.selected_term ? ` (${data.selected_term})` : ''}</option>
                        {terms.map((option) => <option key={option} value={option}>{option}</option>)}
                    </select>
                </label>
                <label className="grid gap-2 text-sm font-bold text-slate-800">กลุ่มเรียน
                    <select value={group} onChange={(event) => setGroup(event.target.value)} className={selectClass} disabled={groups.isPending || groups.isError}>
                        <option value="">{role === 'teacher' ? 'กลุ่มที่รับผิดชอบ' : 'ทุกกลุ่มเรียน'}</option>
                        {options.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                    </select>
                </label>
                <label className="grid gap-2 text-sm font-bold text-slate-800">สถานะนักศึกษา
                    <select value={graduatedOnly ? '1' : '0'} onChange={(event) => setGraduatedOnly(event.target.value === '1')} className={selectClass}>
                        <option value="0">{allStatusesLabel}</option>
                        <option value="1">{graduatesOnlyLabel}</option>
                    </select>
                </label>
            </div>
            {groups.isError && <QueryError onRetry={() => void groups.refetch()} message="โหลดตัวเลือกกลุ่มเรียนไม่สำเร็จ" />}
            {report.isPending && <QuerySkeleton rows={4} />}
            {report.isError && <QueryError onRetry={() => void report.refetch()} />}
            {data && !report.isError && <>
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 className="font-bold text-slate-950">{data.selected_term ? `${graduatedOnly ? 'ผู้สำเร็จการศึกษาภาคเรียน' : 'ผลประเมินภาคเรียน'} ${data.selected_term}` : 'ผลประเมินตามระดับชั้น'}</h2>
                    {hasSource && <p className="text-sm tabular-nums text-slate-600">ผู้มีผลประเมิน {formatCount(assessed)} คน</p>}
                </div>
                <div role="region" aria-label="ตารางผลประเมินคุณธรรม" tabIndex={0} className="overflow-x-auto rounded-xl border border-slate-200">
                    <table className="w-full min-w-[34rem] border-collapse text-sm">
                        <thead className="border-b border-slate-200 bg-slate-50 text-slate-700">
                            <tr>
                                <th scope="col" className="px-4 py-3 text-left">ระดับชั้น</th>
                                {results.map((result) => <th scope="col" key={result} className="px-4 py-3 text-center">{result}</th>)}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-200">
                            {data.rows.map((row) => <tr key={row.level} className="odd:bg-white even:bg-slate-50/70">
                                <th scope="row" className="px-4 py-4 text-left font-semibold text-slate-800">{row.label}{!row.counts && <span className="block text-xs font-normal text-slate-500">ไม่มีข้อมูลต้นทาง</span>}</th>
                                {results.map((result, index) => <td key={result} className="border-l border-slate-200 px-4 py-4 text-center tabular-nums text-slate-800">{row.counts ? formatCount(row.counts[index]) : '—'}</td>)}
                            </tr>)}
                        </tbody>
                        <tfoot className="border-t-2 border-brand-200 bg-brand-50 font-bold text-brand-900">
                            <tr><th scope="row" className="px-4 py-3 text-left">รวม</th>{totals.map((total, index) => <td key={results[index]} className="px-4 py-3 text-center tabular-nums">{hasSource ? formatCount(total) : '—'}</td>)}</tr>
                        </tfoot>
                    </table>
                </div>
                {graduatedOnly && hasSource && <p className="text-sm text-slate-600">{graduateAssessmentRule}</p>}
                {Boolean(hasSource) && assessed === 0 && <p className="text-sm text-slate-600">ไม่พบผลประเมินตามเงื่อนไขที่เลือก</p>}
            </>}
        </section>
    </div>;
}
