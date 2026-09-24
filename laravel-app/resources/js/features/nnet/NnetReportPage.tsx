import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    ArrowsClockwise,
    ArrowSquareOut,
    CheckCircle,
    Eye,
    FileXls,
    MagnifyingGlass,
    Medal,
    PencilSimple,
    Plus,
    Prohibit,
    Trash,
    Trophy,
    UploadSimple,
    Users,
    X,
    Warning,
    BookOpen,
    FunnelSimple,
} from '@phosphor-icons/react';
import { useState, useMemo } from 'react';
import { Link } from 'react-router-dom';
import Swal from 'sweetalert2';
import { PageHeader } from '../../components/PageHeader';
import { Panel } from '../../components/Panel';
import { Button, Card } from '../../components/MaterialUI';
import { StatusBadge } from '../../components/StatusBadge';
import { QuerySkeleton, QueryError, EmptyState } from '../../components/QueryState';
import { getFeatureData, sendFeatureData } from '../api';
import { downloadExcel } from '../../lib/excel';

export interface NnetRecord {
    id: number;
    district_id: number;
    academic_year: string;
    round: number;
    education_level: number;
    education_level_label: string;
    seat_no: string | null;
    citizen_id: string;
    student_code: string | null;
    student_name: string;
    group_code: string | null;
    group_name: string | null;
    total_score: number | null;
    has_score: boolean;
    subject_codes: string[] | null;
    subject_names: Record<string, string> | null;
    subject_scores: Record<string, number | null> | null;
    subject_levels: Record<string, string> | null;
    notes: string | null;
    created_at: string;
    updated_at: string;
}

interface NnetSummary {
    total_students: number;
    scored_students: number;
    absent_students: number;
    average_total_score: number;
    max_total_score: number;
    min_total_score: number;
    best_subject: { code: string; name: string; avg: number } | null;
    subjects: Array<{
        code: string;
        name: string;
        average: number;
        max: number;
        min: number;
        percentage: number;
    }>;
}

const LEVEL_OPTIONS = [
    { value: 0, label: 'ทุกระดับชั้น' },
    { value: 1, label: 'ประถมศึกษา' },
    { value: 2, label: 'มัธยมศึกษาตอนต้น' },
    { value: 3, label: 'มัธยมศึกษาตอนปลาย' },
];

const STANDARD_SUBJECT_NAMES = [
    'ทักษะการเรียนรู้',
    'ความรู้พื้นฐาน',
    'การประกอบอาชีพ',
    'ทักษะการดำเนินชีวิต',
    'การพัฒนาสังคม',
];

export function NnetReportPage() {
    const queryClient = useQueryClient();

    // Filters
    const [level, setLevel] = useState<number>(2); // Default to ม.ต้น
    const [year, setYear] = useState<string>('2569');
    const [round, setRound] = useState<number>(1);
    const [statusFilter, setStatusFilter] = useState<string>('');
    const [group, setGroup] = useState<string>('');
    const [search, setSearch] = useState<string>('');

    // Modal States
    const [detailRecord, setDetailRecord] = useState<NnetRecord | null>(null);
    const [editingRecord, setEditingRecord] = useState<NnetRecord | null>(null);
    const [isAddModalOpen, setIsAddModalOpen] = useState<boolean>(false);

    // Form Draft for Add/Edit
    const [formDraft, setFormDraft] = useState({
        citizen_id: '',
        student_name: '',
        seat_no: '',
        student_code: '',
        group_name: '',
        education_level: 2,
        academic_year: '2569',
        round: 1,
        has_score: true,
        total_score: '',
        scores: ['', '', '', '', ''],
        notes: '',
    });

    // Queries
    const recordsQuery = useQuery({
        queryKey: ['nnet', 'records', { level, year, round, statusFilter, group, search }],
        queryFn: ({ signal }) => {
            const params = new URLSearchParams();
            if (level > 0) params.set('education_level', String(level));
            if (year) params.set('academic_year', year);
            if (round > 0) params.set('round', String(round));
            if (statusFilter) params.set('status', statusFilter);
            if (group) params.set('group', group);
            if (search.trim()) params.set('search', search.trim());
            params.set('page_size', '200');

            return getFeatureData<{
                items: NnetRecord[];
                total: number;
                available_years: string[];
                available_groups: string[];
            }>(`/api/v1/nnet/records?${params.toString()}`, signal).then((res) => res.data);
        },
    });

    const summaryQuery = useQuery({
        queryKey: ['nnet', 'summary', { level, year, round, group }],
        queryFn: ({ signal }) => {
            const params = new URLSearchParams();
            if (level > 0) params.set('education_level', String(level));
            if (year) params.set('academic_year', year);
            if (round > 0) params.set('round', String(round));
            if (group) params.set('group', group);

            return getFeatureData<NnetSummary>(`/api/v1/nnet/summary?${params.toString()}`, signal).then((res) => res.data);
        },
    });

    const items = recordsQuery.data?.items ?? [];
    const summary = summaryQuery.data;

    // Delete Mutation
    const deleteMutation = useMutation({
        mutationFn: (id: number) => sendFeatureData(`/api/v1/nnet/records/${id}`, 'DELETE'),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['nnet'] });
            Swal.fire({
                title: 'ลบข้อมูลสำเร็จ',
                icon: 'success',
                timer: 1500,
                showConfirmButton: false,
            });
        },
        onError: (err: any) => {
            Swal.fire('เกิดข้อผิดพลาด', err.message || 'ไม่สามารถลบข้อมูลได้', 'error');
        },
    });

    // Clear Mutation
    const clearMutation = useMutation({
        mutationFn: () => {
            const payload: Record<string, any> = {};
            if (level > 0) payload.education_level = level;
            if (year) payload.academic_year = year;
            if (round > 0) payload.round = round;
            return sendFeatureData('/api/v1/nnet/clear', 'POST', payload);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['nnet'] });
            Swal.fire('สำเร็จ', 'ล้างข้อมูลชุดนี้เรียบร้อยแล้ว', 'success');
        },
        onError: (err: any) => {
            Swal.fire('เกิดข้อผิดพลาด', err.message || 'ไม่สามารถล้างข้อมูลได้', 'error');
        },
    });

    const handleDeleteRecord = async (record: NnetRecord) => {
        const result = await Swal.fire({
            title: 'ยืนยันการลบ?',
            html: `ต้องการลบผลคะแนนของ <b>${record.student_name}</b> (เลขที่นั่งสอบ: ${record.seat_no || '-'}) หรือไม่?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'ลบข้อมูล',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#dc2626',
        });

        if (result.isConfirmed) {
            deleteMutation.mutate(record.id);
        }
    };

    const handleClearSet = async () => {
        const levelText = LEVEL_OPTIONS.find((l) => l.value === level)?.label ?? 'ทั้งหมด';
        const result = await Swal.fire({
            title: 'ยืนยันการล้างข้อมูลชุดนี้?',
            html: `คุณกำลังจะลบข้อมูล N-NET ทั้งหมดของ:<br/>
                ระดับชั้น: <b>${levelText}</b><br/>
                ปีการศึกษา: <b>${year || 'ทุกปี'}</b> ครั้งที่: <b>${round || 'ทุกครั้ง'}</b><br/>
                <span class="text-red-600 font-bold">การกระทำนี้ไม่สามารถย้อนกลับได้!</span>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'ยืนยันล้างข้อมูล',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#dc2626',
        });

        if (result.isConfirmed) {
            clearMutation.mutate();
        }
    };

    const handleOpenAddModal = () => {
        setFormDraft({
            citizen_id: '',
            student_name: '',
            seat_no: '',
            student_code: '',
            group_name: '',
            education_level: level > 0 ? level : 2,
            academic_year: year || '2569',
            round: round > 0 ? round : 1,
            has_score: true,
            total_score: '',
            scores: ['', '', '', '', ''],
            notes: '',
        });
        setIsAddModalOpen(true);
    };

    const handleOpenEditModal = (record: NnetRecord) => {
        setEditingRecord(record);
        const subCodes = record.subject_codes || ['411', '412', '413', '414', '415'];
        const subScores = record.subject_scores || {};

        setFormDraft({
            citizen_id: record.citizen_id,
            student_name: record.student_name,
            seat_no: record.seat_no || '',
            student_code: record.student_code || '',
            group_name: record.group_name || '',
            education_level: record.education_level,
            academic_year: record.academic_year,
            round: record.round,
            has_score: record.has_score,
            total_score: record.total_score !== null ? String(record.total_score) : '',
            scores: subCodes.map((code) => (subScores[code] !== undefined && subScores[code] !== null ? String(subScores[code]) : '')),
            notes: record.notes || '',
        });
    };

    const handleSaveForm = async () => {
        try {
            if (!formDraft.citizen_id || formDraft.citizen_id.trim().length !== 13) {
                Swal.fire('ข้อผิดพลาด', 'กรุณาระบุเลขประจำตัวประชาชน 13 หลัก', 'warning');
                return;
            }
            if (!formDraft.student_name.trim()) {
                Swal.fire('ข้อผิดพลาด', 'กรุณาระบุชื่อ-สกุลนักศึกษา', 'warning');
                return;
            }

            const subjectCodes = ['411', '412', '413', '414', '415'];
            const subjectScores: Record<string, number | null> = {};
            subjectCodes.forEach((code, idx) => {
                const val = formDraft.scores[idx];
                subjectScores[code] = val !== '' && !isNaN(Number(val)) ? Number(val) : null;
            });

            const payload: any = {
                citizen_id: formDraft.citizen_id.trim(),
                student_name: formDraft.student_name.trim(),
                seat_no: formDraft.seat_no.trim() || null,
                student_code: formDraft.student_code.trim() || null,
                group_name: formDraft.group_name.trim() || null,
                education_level: formDraft.education_level,
                academic_year: formDraft.academic_year.trim(),
                round: formDraft.round,
                has_score: formDraft.has_score,
                total_score: formDraft.has_score && formDraft.total_score !== '' ? Number(formDraft.total_score) : null,
                subject_scores: subjectScores,
                notes: formDraft.notes.trim() || null,
            };

            if (editingRecord) {
                await sendFeatureData(`/api/v1/nnet/records/${editingRecord.id}`, 'PUT', payload);
                Swal.fire('สำเร็จ', 'บันทึกการแก้ไขข้อมูลเรียบร้อยแล้ว', 'success');
                setEditingRecord(null);
            } else {
                await sendFeatureData('/api/v1/nnet/records', 'POST', payload);
                Swal.fire('สำเร็จ', 'เพิ่มข้อมูลนักศึกษาเรียบร้อยแล้ว', 'success');
                setIsAddModalOpen(false);
            }

            queryClient.invalidateQueries({ queryKey: ['nnet'] });
        } catch (err: any) {
            Swal.fire('เกิดข้อผิดพลาด', err.message || 'ไม่สามารถบันทึกข้อมูลได้', 'error');
        }
    };

    const handleExportExcel = () => {
        if (items.length === 0) {
            Swal.fire('ไม่มีข้อมูล', 'ไม่พบข้อมูลที่ตรงกับตัวกรองสำหรับการส่งออก', 'info');
            return;
        }

        const levelLabel = LEVEL_OPTIONS.find((l) => l.value === level)?.label ?? 'ทุกระดับ';
        const subHeaders = level === 0
            ? ['สาระที่ 1', 'สาระที่ 2', 'สาระที่ 3', 'สาระที่ 4', 'สาระที่ 5']
            : (items[0]?.subject_codes || ['411', '412', '413', '414', '415']);

        const columns = [
            'ลำดับ',
            'เลขที่นั่งสอบ',
            'เลขประจำตัวประชาชน',
            'รหัสนักศึกษา',
            'ชื่อ - สกุล',
            'ระดับชั้น',
            'กลุ่มเรียน',
            'คะแนนรวม',
            ...subHeaders.map((c, i) => `${c} (${STANDARD_SUBJECT_NAMES[i] ?? c})`),
            'สถานะ',
        ];

        const rows = items.map((r, idx) => {
            const subScores = r.subject_scores || {};
            return [
                idx + 1,
                r.seat_no || '',
                r.citizen_id,
                r.student_code || '',
                r.student_name,
                r.education_level_label,
                r.group_name || '',
                r.has_score && r.total_score !== null ? r.total_score : '-',
                ...[0, 1, 2, 3, 4].map((sIdx) => {
                    const code = r.subject_codes?.[sIdx];
                    const score = (code ? subScores[code] : undefined) ?? (subScores as any)[sIdx];
                    return score !== undefined && score !== null ? score : '-';
                }),
                r.has_score ? 'มีผลคะแนน' : 'ไม่มีคะแนน',
            ];
        });

        const groupSuffix = group ? `_${group}` : '';
        downloadExcel(`รายงานผล_N-NET_${levelLabel}_${year}_ครั้งที่${round}${groupSuffix}`, [
            {
                name: 'ผลการทดสอบ N-NET',
                columns,
                rows,
            },
        ]);
    };

    return (
        <div className="space-y-6">
            <PageHeader
                category="N-NET"
                title="รายงานผล N-NET"
                description="ภาพรวมผลสอบ พร้อมวิเคราะห์รายสาระและรายบุคคล"
                icon={Medal}
                actions={(
                    <div className="flex flex-wrap items-center gap-2">
                        <Link to="/n-net/import">
                            <Button appearance="subtle" icon={<UploadSimple size={18} />}>
                                นำเข้า N-NET จาก Excel
                            </Button>
                        </Link>
                        <Button
                            appearance="outline"
                            icon={<FileXls size={18} weight="bold" />}
                            onClick={handleExportExcel}
                            disabled={items.length === 0}
                        >
                            ส่งออก Excel
                        </Button>
                        <Button
                            appearance="primary"
                            icon={<Plus size={18} weight="bold" />}
                            onClick={handleOpenAddModal}
                        >
                            เพิ่มข้อมูลรายคน
                        </Button>
                    </div>
                )}
            />

            {/* Filter Bar */}
            <Panel className="p-4">
                <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6 items-end">
                    <div>
                        <label className="block text-xs font-bold text-slate-700 mb-1">ระดับชั้น</label>
                        <select
                            value={level}
                            onChange={(e) => setLevel(Number(e.target.value))}
                            className="w-full rounded-xl border border-slate-300 p-2 text-sm bg-white focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none"
                        >
                            {LEVEL_OPTIONS.map((opt) => (
                                <option key={opt.value} value={opt.value}>
                                    {opt.label}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div>
                        <label className="block text-xs font-bold text-slate-700 mb-1">ปีการศึกษา</label>
                        <input
                            type="text"
                            value={year}
                            onChange={(e) => setYear(e.target.value)}
                            placeholder="เช่น 2569"
                            className="w-full rounded-xl border border-slate-300 p-2 text-sm bg-white focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none"
                        />
                    </div>

                    <div>
                        <label className="block text-xs font-bold text-slate-700 mb-1">ครั้งที่</label>
                        <select
                            value={round}
                            onChange={(e) => setRound(Number(e.target.value))}
                            className="w-full rounded-xl border border-slate-300 p-2 text-sm bg-white focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none"
                        >
                            <option value={0}>ทุกครั้ง</option>
                            <option value={1}>ครั้งที่ 1</option>
                            <option value={2}>ครั้งที่ 2</option>
                        </select>
                    </div>

                    <div>
                        <label className="block text-xs font-bold text-slate-700 mb-1">กลุ่มเรียน</label>
                        <select
                            value={group}
                            onChange={(e) => setGroup(e.target.value)}
                            className="w-full rounded-xl border border-slate-300 p-2 text-sm bg-white focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none"
                        >
                            <option value="">ทุกกลุ่มเรียน</option>
                            {recordsQuery.data?.available_groups?.map((grp) => (
                                <option key={grp} value={grp}>
                                    {grp}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div>
                        <label className="block text-xs font-bold text-slate-700 mb-1">สถานะ</label>
                        <select
                            value={statusFilter}
                            onChange={(e) => setStatusFilter(e.target.value)}
                            className="w-full rounded-xl border border-slate-300 p-2 text-sm bg-white focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none"
                        >
                            <option value="">ทั้งหมด</option>
                            <option value="scored">มีผลคะแนน</option>
                            <option value="absent">ไม่มีคะแนน / ขาดสอบ</option>
                        </select>
                    </div>

                    <div>
                        <label className="block text-xs font-bold text-slate-700 mb-1">ค้นหาผู้เรียน</label>
                        <div className="relative">
                            <input
                                type="text"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="ชื่อ / เลขที่นั่ง / บัตร ปชช."
                                className="w-full rounded-xl border border-slate-300 p-2 pl-8 text-sm bg-white focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none"
                            />
                            <MagnifyingGlass size={16} className="absolute left-2.5 top-2.5 text-slate-400" />
                        </div>
                    </div>
                </div>
            </Panel>

            {/* Summary KPI Cards */}
            {summary && (
                <div className="grid gap-3.5 grid-cols-2 sm:grid-cols-3 lg:grid-cols-6">
                    <Card className="p-4 rounded-2xl bg-white border border-slate-200/80 shadow-sm flex flex-col justify-between">
                        <span className="text-xs font-bold text-slate-500">รายชื่อทั้งหมด</span>
                        <div className="my-2">
                            <div className="text-2xl lg:text-3xl font-black text-slate-900 font-mono">
                                {summary.total_students.toLocaleString('th-TH')}
                            </div>
                        </div>
                        <span className="text-xs text-slate-400">คน</span>
                    </Card>

                    <Card className="p-4 rounded-2xl bg-white border border-slate-200/80 shadow-sm flex flex-col justify-between">
                        <span className="text-xs font-bold text-emerald-600">มีผลคะแนน</span>
                        <div className="my-2">
                            <div className="text-2xl lg:text-3xl font-black text-emerald-600 font-mono">
                                {summary.scored_students.toLocaleString('th-TH')}
                            </div>
                        </div>
                        <span className="text-xs text-slate-400">
                            {summary.total_students > 0
                                ? `${Math.round((summary.scored_students / summary.total_students) * 100)}% ของทั้งหมด`
                                : 'คน'}
                        </span>
                    </Card>

                    <Card className="p-4 rounded-2xl bg-white border border-slate-200/80 shadow-sm flex flex-col justify-between">
                        <span className="text-xs font-bold text-amber-600">ไม่มีคะแนน</span>
                        <div className="my-2">
                            <div className="text-2xl lg:text-3xl font-black text-amber-600 font-mono">
                                {summary.absent_students.toLocaleString('th-TH')}
                            </div>
                        </div>
                        <span className="text-xs text-slate-400">คน</span>
                    </Card>

                    <Card className="p-4 rounded-2xl bg-white border border-slate-200/80 shadow-sm flex flex-col justify-between">
                        <span className="text-xs font-bold text-indigo-600">คะแนนเฉลี่ยรวม</span>
                        <div className="my-2">
                            <div className="text-2xl lg:text-3xl font-black text-indigo-600 font-mono">
                                {summary.average_total_score.toFixed(2)}
                            </div>
                        </div>
                        <span className="text-xs text-slate-400">
                            {level === 0 ? 'เฉลี่ยรวมทุกระดับชั้น' : `เฉลี่ย ${summary.subjects?.length ? `${summary.subjects.length} สาระ` : 'รายสาระ'}`}
                        </span>
                    </Card>

                    <Card className="p-4 rounded-2xl bg-white border border-slate-200/80 shadow-sm flex flex-col justify-between">
                        <span className="text-xs font-bold text-purple-600">คะแนนสูงสุด</span>
                        <div className="my-2">
                            <div className="text-2xl lg:text-3xl font-black text-purple-600 font-mono">
                                {summary.max_total_score.toFixed(2)}
                            </div>
                        </div>
                        <span className="text-xs text-slate-400">คะแนน</span>
                    </Card>

                    <Card className="p-4 rounded-2xl bg-white border border-slate-200/80 shadow-sm flex flex-col justify-between">
                        <span className="text-xs font-bold text-blue-600">สาระเฉลี่ยสูงสุด</span>
                        <div className="my-2">
                            <div className="text-2xl lg:text-3xl font-black text-blue-600 font-mono">
                                {summary.best_subject?.code ?? '-'}
                            </div>
                        </div>
                        <span className="text-xs text-slate-600 truncate block">
                            {summary.best_subject?.name ?? '-'}
                        </span>
                    </Card>
                </div>
            )}

            {/* Subject Average Breakdown */}
            {summary && summary.subjects && summary.subjects.length > 0 && (
                <Panel title="คะแนนเฉลี่ยจำแนกตามสาระการเรียนรู้" className="space-y-4">
                    <div className="grid gap-3.5 sm:grid-cols-2 lg:grid-cols-5">
                        {summary.subjects.map((sub) => (
                            <div
                                key={sub.code}
                                className="p-4 rounded-2xl bg-slate-50/70 border border-slate-200/70 hover:bg-white hover:shadow-md transition-all duration-200 flex flex-col justify-between"
                            >
                                <div>
                                    <div className="flex items-center justify-between">
                                        <span className="text-xs font-black px-2 py-0.5 rounded-md bg-indigo-100 text-indigo-700 font-mono">
                                            {sub.code}
                                        </span>
                                        <span className="text-xs text-slate-400 font-mono">
                                            สูงสุด {sub.max.toFixed(2)}
                                        </span>
                                    </div>
                                    <div className="text-sm font-bold text-slate-800 mt-2 line-clamp-1">
                                        {sub.name}
                                    </div>
                                    <div className="text-2xl font-black text-indigo-600 font-mono mt-1">
                                        {sub.average.toFixed(2)}
                                    </div>
                                </div>

                                <div className="mt-3">
                                    <div className="h-2 w-full bg-slate-200 rounded-full overflow-hidden">
                                        <div
                                            className="h-full bg-gradient-to-r from-indigo-500 to-purple-600 rounded-full transition-all duration-500"
                                            style={{ width: `${Math.max(0, Math.min(100, sub.percentage))}%` }}
                                        />
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                </Panel>
            )}

            {/* Individual Results Table Panel */}
            <Panel
                title={`ผลรายบุคคล (${items.length.toLocaleString('th-TH')} รายการ)`}
                action={(
                    <div className="flex items-center gap-2">
                        {items.length > 0 && (
                            <Button
                                appearance="subtle"
                                icon={<Trash size={16} />}
                                onClick={handleClearSet}
                                className="text-red-600 hover:text-red-700 hover:bg-red-50"
                            >
                                ล้างข้อมูลชุดนี้
                            </Button>
                        )}
                        <Button
                            appearance="subtle"
                            icon={<ArrowsClockwise size={16} />}
                            onClick={() => recordsQuery.refetch()}
                        >
                            รีเฟรช
                        </Button>
                    </div>
                )}
            >
                {recordsQuery.isLoading ? (
                    <QuerySkeleton rows={8} />
                ) : recordsQuery.isError ? (
                    <QueryError onRetry={() => recordsQuery.refetch()} />
                ) : items.length === 0 ? (
                    <EmptyState
                        title="ไม่พบข้อมูลผลการทดสอบ N-NET"
                        description="ลองปรับเงื่อนไขตัวกรอง หรือนำเข้าไฟล์ผลสอบ N-NET จากระบบ"
                        action={(
                            <Link to="/n-net/import">
                                <Button appearance="primary" icon={<UploadSimple size={18} />}>
                                    นำเข้าข้อมูล N-NET
                                </Button>
                            </Link>
                        )}
                    />
                ) : (
                    <div className="overflow-x-auto rounded-xl border border-slate-200">
                        <table className="w-full text-left text-xs whitespace-nowrap">
                            <thead>
                                <tr className="bg-slate-50 border-b border-slate-200 text-slate-700 font-bold">
                                    <th className="p-3 text-center w-12">ลำดับ</th>
                                    <th className="p-3">เลขที่นั่งสอบ</th>
                                    <th className="p-3">เลขประจำตัวประชาชน</th>
                                    <th className="p-3">รหัสนักศึกษา</th>
                                    <th className="p-3">ชื่อ - สกุล</th>
                                    <th className="p-3">กลุ่มเรียน</th>
                                    <th className="p-3 text-center font-bold text-indigo-700 bg-indigo-50/50">
                                        คะแนนรวม
                                    </th>
                                    {level === 0 ? (
                                        ['สาระที่ 1', 'สาระที่ 2', 'สาระที่ 3', 'สาระที่ 4', 'สาระที่ 5'].map((label, idx) => (
                                            <th key={label} className="p-3 text-center" title={STANDARD_SUBJECT_NAMES[idx]}>
                                                {label}
                                            </th>
                                        ))
                                    ) : (
                                        items[0]?.subject_codes?.map((code, idx) => (
                                            <th key={code} className="p-3 text-center" title={STANDARD_SUBJECT_NAMES[idx]}>
                                                {code}
                                            </th>
                                        ))
                                    )}
                                    <th className="p-3 text-center">สถานะ</th>
                                    <th className="p-3 text-center w-28">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {items.map((r, idx) => {
                                    const subScores = r.subject_scores || {};
                                    return (
                                        <tr key={r.id} className="hover:bg-slate-50/70">
                                            <td className="p-3 text-center font-mono text-slate-500">
                                                {idx + 1}
                                            </td>
                                            <td className="p-3 font-mono text-slate-700">
                                                {r.seat_no || '-'}
                                            </td>
                                            <td className="p-3 font-mono text-slate-700">
                                                {r.citizen_id}
                                            </td>
                                            <td className="p-3">
                                                {r.student_code ? (
                                                    <span className="font-mono font-bold text-indigo-700">
                                                        {r.student_code}
                                                    </span>
                                                ) : (
                                                    <span className="text-slate-400 italic">ไม่พบรหัส นศ.</span>
                                                )}
                                            </td>
                                            <td className="p-3 font-bold text-slate-900">
                                                <button
                                                    type="button"
                                                    onClick={() => setDetailRecord(r)}
                                                    className="hover:underline text-left text-slate-900 hover:text-indigo-600"
                                                >
                                                    {r.student_name}
                                                </button>
                                            </td>
                                            <td className="p-3 text-slate-600 truncate max-w-xs">
                                                {r.group_name || '-'}
                                            </td>
                                            <td className="p-3 text-center font-bold text-indigo-700 bg-indigo-50/30 font-mono text-sm">
                                                {r.has_score && r.total_score !== null
                                                    ? r.total_score.toFixed(2)
                                                    : '-'}
                                            </td>
                                            {[0, 1, 2, 3, 4].map((sIdx) => {
                                                const code = r.subject_codes?.[sIdx];
                                                const score = (code ? subScores[code] : undefined) ?? (subScores as any)[sIdx];
                                                return (
                                                    <td
                                                        key={`${r.id}-sub-${sIdx}`}
                                                        className="p-3 text-center font-mono text-slate-700"
                                                    >
                                                        {score !== undefined && score !== null
                                                            ? Number(score).toFixed(2)
                                                            : '-'}
                                                    </td>
                                                );
                                            })}
                                            <td className="p-3 text-center">
                                                {r.has_score ? (
                                                    <StatusBadge tone="success">มีผลคะแนน</StatusBadge>
                                                ) : (
                                                    <StatusBadge tone="neutral">ไม่มีคะแนน</StatusBadge>
                                                )}
                                            </td>
                                            <td className="p-3 text-center">
                                                <div className="flex items-center justify-center gap-1">
                                                    <button
                                                        type="button"
                                                        onClick={() => setDetailRecord(r)}
                                                        className="p-1.5 rounded-lg text-slate-500 hover:text-indigo-600 hover:bg-indigo-50"
                                                        title="ดูรายละเอียด"
                                                    >
                                                        <Eye size={17} />
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => handleOpenEditModal(r)}
                                                        className="p-1.5 rounded-lg text-slate-500 hover:text-blue-600 hover:bg-blue-50"
                                                        title="แก้ไขคะแนน"
                                                    >
                                                        <PencilSimple size={17} />
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => handleDeleteRecord(r)}
                                                        className="p-1.5 rounded-lg text-slate-500 hover:text-red-600 hover:bg-red-50"
                                                        title="ลบ"
                                                    >
                                                        <Trash size={17} />
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </Panel>

            {/* Modal: View Details */}
            {detailRecord && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-xs">
                    <div className="w-full max-w-xl rounded-2xl bg-white p-6 shadow-xl space-y-5 animate-in fade-in zoom-in-95 duration-150">
                        <div className="flex items-start justify-between border-b border-slate-100 pb-3">
                            <div>
                                <h3 className="text-lg font-bold text-slate-900">
                                    รายละเอียดผลการทดสอบ N-NET
                                </h3>
                                <p className="text-xs text-slate-500">
                                    {detailRecord.education_level_label} • ปีการศึกษา {detailRecord.academic_year} ครั้งที่ {detailRecord.round}
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setDetailRecord(null)}
                                className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                            >
                                <X size={20} />
                            </button>
                        </div>

                        {/* Student Info Card */}
                        <div className="p-4 rounded-xl bg-slate-50 border border-slate-200/80 space-y-2 text-xs">
                            <div className="grid grid-cols-2 gap-2">
                                <div>
                                    <span className="text-slate-500">ชื่อ - สกุล:</span>
                                    <strong className="block text-sm text-slate-900">{detailRecord.student_name}</strong>
                                </div>
                                <div>
                                    <span className="text-slate-500">เลขประจำตัวประชาชน:</span>
                                    <span className="block font-mono font-bold text-slate-800">{detailRecord.citizen_id}</span>
                                </div>
                                <div>
                                    <span className="text-slate-500">เลขที่นั่งสอบ:</span>
                                    <span className="block font-mono text-slate-800">{detailRecord.seat_no || '-'}</span>
                                </div>
                                <div>
                                    <span className="text-slate-500">รหัสนักศึกษา:</span>
                                    <span className="block font-mono font-bold text-indigo-700">{detailRecord.student_code || 'ยังไม่เชื่อมโยง'}</span>
                                </div>
                                <div className="col-span-2">
                                    <span className="text-slate-500">กลุ่มเรียน:</span>
                                    <span className="block text-slate-800">{detailRecord.group_name || '-'}</span>
                                </div>
                            </div>
                        </div>

                        {/* Scores Table */}
                        <div className="space-y-2">
                            <h4 className="text-xs font-bold text-slate-700">คะแนนรายสาระการเรียนรู้</h4>
                            <div className="overflow-hidden rounded-xl border border-slate-200">
                                <table className="w-full text-left text-xs">
                                    <thead>
                                        <tr className="bg-slate-50 border-b border-slate-200 font-bold text-slate-700">
                                            <th className="p-2.5">รหัสสาระ</th>
                                            <th className="p-2.5">ชื่อสาระ</th>
                                            <th className="p-2.5 text-center">คะแนน</th>
                                            <th className="p-2.5 text-center">ผลการประเมิน</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {detailRecord.subject_codes?.map((code, idx) => {
                                            const score = detailRecord.subject_scores?.[code];
                                            const level = detailRecord.subject_levels?.[code];
                                            const name = detailRecord.subject_names?.[code] ?? STANDARD_SUBJECT_NAMES[idx] ?? `สาระ ${code}`;
                                            return (
                                                <tr key={code}>
                                                    <td className="p-2.5 font-mono font-bold text-indigo-700">{code}</td>
                                                    <td className="p-2.5 text-slate-800">{name}</td>
                                                    <td className="p-2.5 text-center font-mono font-bold text-slate-900">
                                                        {score !== undefined && score !== null ? Number(score).toFixed(2) : '-'}
                                                    </td>
                                                    <td className="p-2.5 text-center">
                                                        {level ? (
                                                            <span className="px-2 py-0.5 rounded-full text-xs font-semibold bg-indigo-50 text-indigo-700">
                                                                {level}
                                                            </span>
                                                        ) : (
                                                            '-'
                                                        )}
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                        <tr className="bg-indigo-50/50 font-bold text-indigo-950">
                                            <td colSpan={2} className="p-2.5 text-right">คะแนนรวมทั้งหมด:</td>
                                            <td className="p-2.5 text-center font-mono text-sm text-indigo-700">
                                                {detailRecord.has_score && detailRecord.total_score !== null
                                                    ? detailRecord.total_score.toFixed(2)
                                                    : '-'}
                                            </td>
                                            <td className="p-2.5 text-center">
                                                {detailRecord.has_score ? (
                                                    <StatusBadge tone="success">มีผลคะแนน</StatusBadge>
                                                ) : (
                                                    <StatusBadge tone="neutral">ไม่มีคะแนน</StatusBadge>
                                                )}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        {detailRecord.notes && (
                            <div className="p-3 rounded-xl bg-amber-50 border border-amber-200 text-xs text-amber-900">
                                <strong>หมายเหตุ:</strong> {detailRecord.notes}
                            </div>
                        )}

                        <div className="flex justify-end pt-2">
                            <Button appearance="outline" onClick={() => setDetailRecord(null)}>
                                ปิดหน้าต่าง
                            </Button>
                        </div>
                    </div>
                </div>
            )}

            {/* Modal: Add / Edit Record */}
            {(isAddModalOpen || editingRecord) && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-xs">
                    <div className="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl space-y-4 animate-in fade-in zoom-in-95 duration-150 max-h-[90vh] overflow-y-auto">
                        <div className="flex items-start justify-between border-b border-slate-100 pb-3">
                            <h3 className="text-lg font-bold text-slate-900">
                                {editingRecord ? 'แก้ไขข้อมูลคะแนน N-NET' : 'เพิ่มข้อมูลผลสอบ N-NET'}
                            </h3>
                            <button
                                type="button"
                                onClick={() => {
                                    setIsAddModalOpen(false);
                                    setEditingRecord(null);
                                }}
                                className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                            >
                                <X size={20} />
                            </button>
                        </div>

                        <div className="space-y-3 text-xs">
                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <label className="block font-bold text-slate-700 mb-1">
                                        เลขประจำตัวประชาชน (13 หลัก) *
                                    </label>
                                    <input
                                        type="text"
                                        maxLength={13}
                                        value={formDraft.citizen_id}
                                        disabled={!!editingRecord}
                                        onChange={(e) => setFormDraft({ ...formDraft, citizen_id: e.target.value })}
                                        placeholder="xxxxxxxxxxxxx"
                                        className="w-full rounded-xl border border-slate-300 p-2 text-sm font-mono bg-white disabled:bg-slate-100 focus:border-indigo-500 outline-none"
                                    />
                                </div>
                                <div>
                                    <label className="block font-bold text-slate-700 mb-1">
                                        เลขที่นั่งสอบ
                                    </label>
                                    <input
                                        type="text"
                                        value={formDraft.seat_no}
                                        onChange={(e) => setFormDraft({ ...formDraft, seat_no: e.target.value })}
                                        placeholder="เช่น 14001471"
                                        className="w-full rounded-xl border border-slate-300 p-2 text-sm font-mono bg-white focus:border-indigo-500 outline-none"
                                    />
                                </div>
                            </div>

                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <label className="block font-bold text-slate-700 mb-1">ชื่อ - สกุล *</label>
                                    <input
                                        type="text"
                                        value={formDraft.student_name}
                                        onChange={(e) => setFormDraft({ ...formDraft, student_name: e.target.value })}
                                        placeholder="ชื่อ-สกุล นักศึกษา"
                                        className="w-full rounded-xl border border-slate-300 p-2 text-sm bg-white focus:border-indigo-500 outline-none"
                                    />
                                </div>
                                <div>
                                    <label className="block font-bold text-slate-700 mb-1">รหัสนักศึกษา (ถ้ามี)</label>
                                    <input
                                        type="text"
                                        value={formDraft.student_code}
                                        onChange={(e) => setFormDraft({ ...formDraft, student_code: e.target.value })}
                                        placeholder="เช่น 6650100001"
                                        className="w-full rounded-xl border border-slate-300 p-2 text-sm font-mono bg-white focus:border-indigo-500 outline-none"
                                    />
                                </div>
                            </div>

                            <div className="grid grid-cols-3 gap-3">
                                <div>
                                    <label className="block font-bold text-slate-700 mb-1">ระดับชั้น</label>
                                    <select
                                        value={formDraft.education_level}
                                        disabled={!!editingRecord}
                                        onChange={(e) => setFormDraft({ ...formDraft, education_level: Number(e.target.value) })}
                                        className="w-full rounded-xl border border-slate-300 p-2 text-xs bg-white disabled:bg-slate-100 outline-none"
                                    >
                                        <option value={1}>ประถมศึกษา</option>
                                        <option value={2}>มัธยมศึกษาตอนต้น</option>
                                        <option value={3}>มัธยมศึกษาตอนปลาย</option>
                                    </select>
                                </div>
                                <div>
                                    <label className="block font-bold text-slate-700 mb-1">ปีการศึกษา</label>
                                    <input
                                        type="text"
                                        value={formDraft.academic_year}
                                        disabled={!!editingRecord}
                                        onChange={(e) => setFormDraft({ ...formDraft, academic_year: e.target.value })}
                                        className="w-full rounded-xl border border-slate-300 p-2 text-xs bg-white disabled:bg-slate-100 outline-none"
                                    />
                                </div>
                                <div>
                                    <label className="block font-bold text-slate-700 mb-1">ครั้งที่</label>
                                    <select
                                        value={formDraft.round}
                                        disabled={!!editingRecord}
                                        onChange={(e) => setFormDraft({ ...formDraft, round: Number(e.target.value) })}
                                        className="w-full rounded-xl border border-slate-300 p-2 text-xs bg-white disabled:bg-slate-100 outline-none"
                                    >
                                        <option value={1}>ครั้งที่ 1</option>
                                        <option value={2}>ครั้งที่ 2</option>
                                    </select>
                                </div>
                            </div>

                            <div className="pt-2 border-t border-slate-100">
                                <label className="inline-flex items-center gap-2 cursor-pointer mb-2">
                                    <input
                                        type="checkbox"
                                        checked={formDraft.has_score}
                                        onChange={(e) => setFormDraft({ ...formDraft, has_score: e.target.checked })}
                                        className="rounded text-indigo-600 focus:ring-indigo-500"
                                    />
                                    <span className="font-bold text-slate-800">มีผลคะแนนสอบ (เข้าสอบ)</span>
                                </label>

                                {formDraft.has_score && (
                                    <div className="space-y-2 mt-2 p-3 bg-slate-50 rounded-xl border border-slate-200">
                                        <div className="font-bold text-slate-700 mb-1">คะแนน 5 สาระการเรียนรู้:</div>
                                        <div className="grid grid-cols-5 gap-2">
                                            {STANDARD_SUBJECT_NAMES.map((name, i) => (
                                                <div key={i}>
                                                    <label className="block text-[11px] text-slate-600 truncate mb-1" title={name}>
                                                        {name}
                                                    </label>
                                                    <input
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        max="100"
                                                        value={formDraft.scores[i]}
                                                        onChange={(e) => {
                                                            const newScores = [...formDraft.scores];
                                                            newScores[i] = e.target.value;
                                                            // Auto calculate sum if total is empty or sum
                                                            const sum = newScores.reduce((acc, v) => acc + (v !== '' ? Number(v) : 0), 0);
                                                            setFormDraft({
                                                                ...formDraft,
                                                                scores: newScores,
                                                                total_score: sum > 0 ? String(sum) : formDraft.total_score,
                                                            });
                                                        }}
                                                        placeholder="0.00"
                                                        className="w-full rounded-lg border border-slate-300 p-1.5 text-center text-xs font-mono bg-white outline-none"
                                                    />
                                                </div>
                                            ))}
                                        </div>

                                        <div className="pt-2 flex items-center justify-between">
                                            <span className="font-bold text-slate-700">คะแนนรวมทั้งหมด:</span>
                                            <input
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                value={formDraft.total_score}
                                                onChange={(e) => setFormDraft({ ...formDraft, total_score: e.target.value })}
                                                placeholder="คะแนนรวม"
                                                className="w-28 rounded-lg border border-slate-300 p-1.5 text-center text-xs font-bold text-indigo-700 font-mono bg-white outline-none"
                                            />
                                        </div>
                                    </div>
                                )}
                            </div>

                            <div>
                                <label className="block font-bold text-slate-700 mb-1">หมายเหตุ</label>
                                <textarea
                                    rows={2}
                                    value={formDraft.notes}
                                    onChange={(e) => setFormDraft({ ...formDraft, notes: e.target.value })}
                                    placeholder="หมายเหตุเพิ่มเติม (ถ้ามี)"
                                    className="w-full rounded-xl border border-slate-300 p-2 text-xs bg-white focus:border-indigo-500 outline-none"
                                />
                            </div>
                        </div>

                        <div className="flex justify-end gap-2 pt-3 border-t border-slate-100">
                            <Button
                                appearance="subtle"
                                onClick={() => {
                                    setIsAddModalOpen(false);
                                    setEditingRecord(null);
                                }}
                            >
                                ยกเลิก
                            </Button>
                            <Button appearance="primary" onClick={handleSaveForm}>
                                บันทึกข้อมูล
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
