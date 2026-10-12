import { Notebook } from '@phosphor-icons/react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { PageHeader } from '../../components/PageHeader';
import { EmptyState, QueryError, QuerySkeleton } from '../../components/QueryState';
import { getFeatureDataWithDemo } from '../api';

type Subject = { code: string; name: string; credits: number; transferred: boolean };
type Registration = {
    student: { code: string; name: string; level_label: string };
    academic_term: string;
    available_terms: string[];
    compulsory_subjects: Subject[];
    elective_subjects: Subject[];
    is_saved: boolean;
};

export function MyRegistrationPage() {
    const [term, setTerm] = useState('');
    const query = useQuery({
        queryKey: ['my-course-registration', term],
        queryFn: ({ signal }) => getFeatureDataWithDemo<Registration | null>(
            `/api/v1/learning/registration/me${term ? `?term=${encodeURIComponent(term)}` : ''}`,
            null,
            signal,
        ),
    });
    const data = query.data?.data;
    return (
        <div className="space-y-6">
            <PageHeader title="วิชาที่ลงทะเบียนเรียน" description="ตรวจสอบรายวิชาของตนเอง แยกวิชาบังคับและวิชาเลือกตามภาคเรียน" icon={Notebook} />
            {query.isPending ? <QuerySkeleton /> : query.isError ? <QueryError onRetry={() => void query.refetch()} /> : data ? <>
                <div className="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-slate-200 bg-white p-5">
                    <div><h2 className="font-bold text-slate-900">{data.student.name}</h2><p className="text-sm text-slate-600">{data.student.code} · {data.student.level_label}</p></div>
                    <label className="flex items-center gap-2 text-sm font-bold text-slate-700">
                        ภาคเรียน
                        <select value={term || data.academic_term} onChange={(event) => setTerm(event.target.value)} className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-slate-900">
                            {data.available_terms.map((value) => <option key={value} value={value}>{value}</option>)}
                        </select>
                    </label>
                </div>
                <p className="text-sm text-slate-600">{data.is_saved ? 'รายการที่ครูหรือผู้ดูแลบันทึกไว้' : 'รายการจากข้อมูลลงทะเบียนที่นำเข้า'} · ภาคเรียน {data.academic_term}</p>
                <div className="space-y-6">
                    <SubjectColumn title="วิชาบังคับ" subjects={data.compulsory_subjects} tone="blue" />
                    <SubjectColumn title="วิชาเลือก" subjects={data.elective_subjects} tone="purple" />
                </div>
            </> : <EmptyState title="ยังไม่มีข้อมูลลงทะเบียน" description="กรุณาติดต่อครูประจำกลุ่มเพื่อตรวจสอบข้อมูล" />}
        </div>
    );
}

function SubjectColumn({ title, subjects, tone }: { title: string; subjects: Subject[]; tone: 'blue' | 'purple' }) {
    const credits = subjects.filter((subject) => !subject.transferred).reduce((total, subject) => total + subject.credits, 0);
    return (
        <section className="min-w-0 overflow-hidden rounded-2xl border border-slate-200 bg-white">
            <div className={`border-b p-5 ${tone === 'blue' ? 'border-blue-100 bg-blue-50 text-blue-950' : 'border-purple-100 bg-purple-50 text-purple-950'}`}>
                <h3 className="text-lg font-bold">{title}</h3>
                <p className="mt-1 text-sm">{subjects.length} วิชา · ลงทะเบียน {credits} หน่วยกิต</p>
            </div>
            {subjects.length === 0 ? <p className="p-5 text-sm text-slate-500">ไม่มี{title}ในภาคเรียนนี้</p> : <ul className={tone === 'purple' ? 'grid items-start gap-4 p-4 md:grid-cols-2' : 'divide-y divide-slate-100'}>
                {subjects.map((subject) => <li key={subject.code} className={`flex min-w-0 items-start justify-between gap-3 p-5 ${tone === 'purple' ? 'rounded-xl border border-slate-200' : ''}`}>
                    <div className="min-w-0"><p className="break-words font-semibold text-slate-900">{subject.name}</p><p className="mt-1 text-sm text-slate-500">{subject.code}{subject.transferred && ' · เทียบโอน'}</p></div>
                    <span className="shrink-0 text-sm font-bold text-slate-700">{subject.credits} นก.</span>
                </li>)}
            </ul>}
        </section>
    );
}
