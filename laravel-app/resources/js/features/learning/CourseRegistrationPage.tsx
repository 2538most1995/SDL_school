import {
    ArrowLeft,
    CaretDown,
    CaretUp,
    Check,
    CheckCircle,
    FilePdf,
    FloppyDisk,
    GraduationCap,
    MagnifyingGlass,
    Notebook,
    PencilSimple,
    Plus,
    Printer,
    Student as StudentIcon,
    Trash,
    Users,
    Warning,
    WarningCircle,
} from '@phosphor-icons/react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useMemo, useState } from 'react';
import { DataTable } from '../../components/DataTable';
import { Button } from '../../components/MaterialUI';
import { PageHeader } from '../../components/PageHeader';
import { Panel } from '../../components/Panel';
import { QueryError, QuerySkeleton } from '../../components/QueryState';
import { StatGrid } from '../../components/StatGrid';
import { StatTile } from '../../components/StatTile';
import { StatusBadge } from '../../components/StatusBadge';
import { showErrorAlert, showSuccessAlert } from '../../lib/feedback';
import { getFeatureDataWithDemo, sendFeatureData } from '../api';

export interface CourseStatus {
    status: 'passed' | 'transferred' | 'pending_grade' | 'failed' | 'absent_exam' | 'not_taken';
    status_label: string;
    status_badge: string;
    status_color: 'emerald' | 'purple' | 'amber' | 'rose' | 'orange' | 'blue';
    has_grade: boolean;
    is_pending: boolean;
    is_transferred: boolean;
    grade: string | null;
    term: string | null;
    warning: string | null;
}

type WorkspaceItem = {
    code: string;
    name: string;
    level: number;
    level_label: string;
    group_code: string;
    group_name: string;
    credits_earned: number;
    credits_required: number;
    registration: {
        is_saved: boolean;
        compulsory_count: number;
        elective_count: number;
        transferred_count: number;
        total_count: number;
        updated_at: string | null;
    };
};

type WorkspaceData = {
    term: string;
    terms: string[];
    groups: Array<{ value: string; label: string }>;
    total_students: number;
    items: WorkspaceItem[];
};

type SubjectRow = {
    code: string;
    name: string;
    credits: number;
    registered: boolean;
    transferred: boolean;
    remark: string;
    is_passed?: boolean;
    passed_grade?: string | null;
    passed_term?: string | null;
    course_status?: CourseStatus;
};

type CommonElective = {
    code: string;
    name: string;
    credits: number;
    course_status?: CourseStatus;
};

type StudentInfoForm = {
    name: string;
    phone: string;
    facebook: string;
    line_id: string;
    house_no: string;
    moo: string;
    subdistrict: string;
    district: string;
    province: string;
    citizen_id: string;
    code: string;
    group: string;
    box_subdistrict: string;
    teacher_name?: string;
    compulsory_earned: number | string;
    elective_earned: number | string;
    compulsory_remaining: number | string;
    elective_remaining: number | string;
    term_no: string;
    term_year: string;
};

type StudentRegistrationData = {
    student: {
        code: string;
        name: string;
        prefix: string;
        first_name: string;
        last_name: string;
        citizen_id: string;
        level: number;
        level_label: string;
        group_code: string;
        group_name: string;
        district_name: string;
        phone: string;
        facebook: string;
        line_id: string;
        address: string;
    };
    student_info?: StudentInfoForm;
    academic_term: string;
    available_terms?: string[];
    requirements: {
        compulsory_required: number;
        compulsory_earned: number;
        compulsory_remaining: number;
        elective_required: number;
        elective_earned: number;
        elective_remaining: number;
        total_required: number;
        total_earned: number;
    };
    compulsory_subjects: SubjectRow[];
    elective_subjects: SubjectRow[];
    common_electives: CommonElective[];
    notes: string;
    is_saved: boolean;
};

const emptyWorkspace: WorkspaceData = {
    term: '1/2569',
    terms: ['1/2569'],
    groups: [],
    total_students: 0,
    items: [],
};

function CourseStatusBadge({ status }: { status?: CourseStatus }) {
    if (!status) return null;

    let badgeClass = 'bg-slate-100 text-slate-700 border-slate-200';
    let dotClass = 'bg-slate-400';

    if (status.status === 'passed') {
        badgeClass = 'bg-emerald-50 text-emerald-800 border-emerald-200';
        dotClass = 'bg-emerald-500';
    } else if (status.status === 'transferred') {
        badgeClass = 'bg-purple-50 text-purple-800 border-purple-200';
        dotClass = 'bg-purple-500';
    } else if (status.status === 'pending_grade') {
        badgeClass = 'bg-amber-50 text-amber-800 border-amber-300';
        dotClass = 'bg-amber-500';
    } else if (status.status === 'absent_exam') {
        badgeClass = 'bg-orange-50 text-orange-800 border-orange-300';
        dotClass = 'bg-orange-500';
    } else if (status.status === 'failed') {
        badgeClass = 'bg-rose-50 text-rose-800 border-rose-200';
        dotClass = 'bg-rose-500';
    } else if (status.status === 'not_taken') {
        badgeClass = 'bg-sky-50 text-sky-800 border-sky-200';
        dotClass = 'bg-sky-500';
    }

    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded-md border px-2 py-0.5 text-[11px] font-bold shrink-0 ${badgeClass}`}
            title={status.status_label}
        >
            <span className={`inline-block size-1.5 rounded-full ${dotClass}`} />
            <span>{status.status_badge}</span>
        </span>
    );
}

export function CourseRegistrationPage() {
    const queryClient = useQueryClient();
    const [selectedStudentCode, setSelectedStudentCode] = useState<string | null>(null);
    const [term, setTerm] = useState('');
    const [group, setGroup] = useState('');
    const [level, setLevel] = useState('');
    const [search, setSearch] = useState('');
    const [blankLevel, setBlankLevel] = useState('3');
    const [showBlankModal, setShowBlankModal] = useState(false);

    // Form editing state
    const [compulsoryRows, setCompulsoryRows] = useState<SubjectRow[]>([]);
    const [electiveRows, setElectiveRows] = useState<SubjectRow[]>([]);
    const [studentInfo, setStudentInfo] = useState<StudentInfoForm | null>(null);
    const [showEditInfo, setShowEditInfo] = useState(false);
    const [notes, setNotes] = useState('');
    const [isFormDirty, setIsFormDirty] = useState(false);

    const handleStudentInfoChange = (field: keyof StudentInfoForm, value: string | number) => {
        setStudentInfo((prev) => {
            if (!prev) return null;
            return {
                ...prev,
                [field]: value,
            };
        });
        setIsFormDirty(true);
    };

    const workspaceParams = useMemo(() => {
        const query = new URLSearchParams();
        if (term) query.set('term', term);
        if (group) query.set('group', group);
        if (level) query.set('level', level);
        if (search) query.set('search', search);
        return query.toString();
    }, [group, level, search, term]);

    const workspaceQuery = useQuery({
        queryKey: ['course-registration-workspace', workspaceParams],
        queryFn: ({ signal }) =>
            getFeatureDataWithDemo<WorkspaceData>(
                `/api/v1/learning/registration/workspace?${workspaceParams}`,
                emptyWorkspace,
                signal,
            ),
    });

    const workspaceData = workspaceQuery.data?.data ?? emptyWorkspace;

    // Student detail query
    const studentDetailQuery = useQuery({
        queryKey: ['course-registration-student', selectedStudentCode, term || workspaceData.term],
        queryFn: ({ signal }) => {
            if (!selectedStudentCode) return Promise.resolve(null);
            const q = new URLSearchParams();
            if (term || workspaceData.term) q.set('term', term || workspaceData.term);
            return getFeatureDataWithDemo<StudentRegistrationData>(
                `/api/v1/learning/registration/student/${encodeURIComponent(selectedStudentCode)}?${q.toString()}`,
                null as unknown as StudentRegistrationData,
                signal,
            );
        },
        enabled: !!selectedStudentCode,
    });

    const studentDetail = studentDetailQuery.data?.data;

    // Sync form state when student data loads
    const loadStudentDataIntoForm = (data: StudentRegistrationData) => {
        setCompulsoryRows(data.compulsory_subjects ?? []);
        setElectiveRows(data.elective_subjects ?? []);
        setNotes(data.notes ?? '');
        setStudentInfo(
            data.student_info ?? {
                name: data.student.name,
                phone: data.student.phone || '',
                facebook: data.student.facebook || '',
                line_id: data.student.line_id || '',
                house_no: '',
                moo: '',
                subdistrict: '',
                district: '',
                province: '',
                citizen_id: data.student.citizen_id || '',
                code: data.student.code,
                group: data.student.group_name || data.student.group_code || '',
                box_subdistrict: '',
                teacher_name: '',
                compulsory_earned: data.requirements.compulsory_earned,
                elective_earned: data.requirements.elective_earned,
                compulsory_remaining: data.requirements.compulsory_remaining,
                elective_remaining: data.requirements.elective_remaining,
                term_no: data.academic_term.split('/')[0] || '',
                term_year: data.academic_term.split('/')[1] || '',
            },
        );
        setIsFormDirty(false);
    };

    const handleSelectStudent = (code: string) => {
        setSelectedStudentCode(code);
        setIsFormDirty(false);
    };

    const handleBackToList = () => {
        if (isFormDirty && !window.confirm('คุณมีข้อมูลที่ยังไม่ได้บันทึก ต้องการออกจากหน้านี้หรือไม่?')) {
            return;
        }
        setSelectedStudentCode(null);
        setIsFormDirty(false);
    };

    // Calculate term totals
    const termCompulsoryCredits = useMemo(
        () => compulsoryRows.filter((r) => r.registered).reduce((acc, cur) => acc + (cur.credits || 0), 0),
        [compulsoryRows],
    );
    const termElectiveCredits = useMemo(
        () => electiveRows.filter((r) => r.registered).reduce((acc, cur) => acc + (cur.credits || 0), 0),
        [electiveRows],
    );
    const termTotalCredits = termCompulsoryCredits + termElectiveCredits;

    // Save mutation
    const saveMutation = useMutation({
        mutationFn: async () => {
            if (!selectedStudentCode || !studentDetail) return;
            const payload = {
                academic_term: studentDetail.academic_term,
                student_info: studentInfo,
                compulsory_subjects: compulsoryRows,
                elective_subjects: electiveRows,
                notes,
            };
            return sendFeatureData(
                `/api/v1/learning/registration/student/${encodeURIComponent(selectedStudentCode)}`,
                'POST',
                payload,
            );
        },
        onSuccess: () => {
            showSuccessAlert('บันทึกการลงทะเบียนเรียนเรียบร้อยแล้ว');
            setIsFormDirty(false);
            queryClient.invalidateQueries({ queryKey: ['course-registration-workspace'] });
            queryClient.invalidateQueries({ queryKey: ['course-registration-student', selectedStudentCode] });
        },
        onError: (err: any) => {
            showErrorAlert(err?.message || 'ไม่สามารถบันทึกข้อมูลได้ กรุณาลองใหม่อีกครั้ง');
        },
    });

    // PDF generation trigger
    const openPrintDocument = async (options: { scope: 'student' | 'group' | 'blank'; student?: string; level?: string }) => {
        try {
            const q = new URLSearchParams({ scope: options.scope });
            if (options.scope === 'student' && options.student) {
                q.set('student', options.student);
                const activeTerm =
                    studentDetail && selectedStudentCode === options.student
                        ? studentDetail.academic_term
                        : term || workspaceData.term;
                q.set('term', activeTerm);
            } else if (options.scope === 'group' && group) {
                q.set('group', group);
                if (level) q.set('level', level);
                q.set('term', term || workspaceData.term);
            } else if (options.scope === 'blank') {
                q.set('level', options.level || blankLevel);
                q.set('term', term || workspaceData.term);
            }
            const res = await getFeatureDataWithDemo<{ url: string; pdf_url: string }>(
                `/api/v1/learning/registration/signed-url?${q.toString()}`,
                { url: '', pdf_url: '' },
            );
            if (res.data?.url) {
                window.open(res.data.url, '_blank');
            } else {
                throw new Error('ไม่สามารถเตรียมเอกสาร PDF ได้');
            }
        } catch (err: any) {
            showErrorAlert(err?.message || 'เกิดข้อผิดพลาดในการเปิดเอกสาร');
        }
    };

    // Helper to resolve status for any code based on catalog in studentDetail
    const getCourseStatusForCode = (code: string): CourseStatus | undefined => {
        const trimmed = code.trim();
        if (!trimmed || !studentDetail) return undefined;
        const foundElective = studentDetail.common_electives?.find((c) => c.code.trim() === trimmed);
        if (foundElective?.course_status) return foundElective.course_status;
        const foundCompulsory = studentDetail.compulsory_subjects?.find((c) => c.code.trim() === trimmed);
        if (foundCompulsory?.course_status) return foundCompulsory.course_status;
        return undefined;
    };

    // Table columns in list view
    const columns = useMemo(
        () => [
            {
                accessorKey: 'code',
                header: 'รหัสนักศึกษา',
                size: 130,
                cell: ({ getValue }: any) => <span className="font-mono font-bold text-slate-800">{getValue()}</span>,
            },
            {
                accessorKey: 'name',
                header: 'ชื่อ - นามสกุล',
                size: 220,
                cell: ({ row }: any) => (
                    <button
                        type="button"
                        onClick={() => handleSelectStudent(row.original.code)}
                        className="text-left font-bold text-brand-700 hover:underline"
                    >
                        {row.original.name}
                    </button>
                ),
            },
            {
                accessorKey: 'level_label',
                header: 'ระดับการศึกษา',
                size: 150,
                cell: ({ getValue }: any) => <span className="text-slate-700">{getValue()}</span>,
            },
            {
                accessorKey: 'group_name',
                header: 'กลุ่มเรียน',
                size: 180,
                cell: ({ row }: any) => (
                    <span className="text-xs text-slate-600">
                        {row.original.group_name} ({row.original.group_code})
                    </span>
                ),
            },
            {
                id: 'registration_status',
                header: 'สถานะการลงทะเบียน',
                size: 170,
                cell: ({ row }: any) => {
                    const reg = row.original.registration;
                    if (reg.is_saved) {
                        return (
                            <StatusBadge tone="success">
                                บันทึกแล้ว ({reg.total_count} วิชา)
                            </StatusBadge>
                        );
                    }
                    return <StatusBadge tone="neutral">ยังไม่บันทึก</StatusBadge>;
                },
            },
            {
                id: 'actions',
                header: 'การจัดการ',
                size: 200,
                cell: ({ row }: any) => (
                    <div className="flex items-center gap-2">
                        <Button
                            size="small"
                            appearance="outline"
                            onClick={() => handleSelectStudent(row.original.code)}
                        >
                            ลงทะเบียน
                        </Button>
                        <Button
                            size="small"
                            appearance="subtle"
                            icon={<Printer size={15} />}
                            onClick={() => openPrintDocument({ scope: 'student', student: row.original.code })}
                            title="พิมพ์ใบลงทะเบียน (PDF)"
                        >
                            พิมพ์
                        </Button>
                    </div>
                ),
            },
        ],
        [workspaceData.term, term],
    );

    // If studentDetail loads and form is clean, initialize form state
    useEffect(() => {
        if (studentDetail) {
            loadStudentDataIntoForm(studentDetail);
        }
    }, [studentDetail]);

    // Count statistics
    const totalCount = workspaceData.items.length;
    const registeredCount = workspaceData.items.filter((i) => i.registration.is_saved).length;
    const pendingCount = totalCount - registeredCount;

    return (
        <div className="space-y-6">
            <PageHeader
                category="พื้นที่การเรียนรู้"
                title="ลงทะเบียนเรียน"
                description="ระบบเลือกลงทะเบียนรายวิชาสำหรับครูประจำกลุ่ม พร้อมออกใบลงทะเบียนเรียน (PDF) ครอบคลุมระดับประถม ม.ต้น ม.ปลาย"
                icon={Notebook}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            appearance="outline"
                            icon={<Printer size={18} />}
                            onClick={() => setShowBlankModal(true)}
                        >
                            แบบฟอร์มเปล่า
                        </Button>
                        {group && (
                            <Button
                                appearance="outline"
                                icon={<FilePdf size={18} />}
                                onClick={() => openPrintDocument({ scope: 'group' })}
                            >
                                พิมพ์ทั้งกลุ่ม (PDF)
                            </Button>
                        )}
                    </div>
                }
            />

            {/* Blank Form Modal */}
            {showBlankModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4 backdrop-blur-sm">
                    <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl space-y-4">
                        <h3 className="text-lg font-bold text-slate-900">พิมพ์แบบฟอร์มเปล่า (Blank Template)</h3>
                        <p className="text-sm text-slate-600">
                            เลือกแบบฟอร์มระดับชั้นที่ต้องการพิมพ์เพื่อนำไปให้นักศึกษาเขียนด้วยลายมือ
                        </p>
                        <div>
                            <label className="block text-xs font-bold text-slate-700 mb-1">ระดับการศึกษา</label>
                            <select
                                value={blankLevel}
                                onChange={(e) => setBlankLevel(e.target.value)}
                                className="w-full rounded-xl border border-slate-300 p-2.5 text-sm"
                            >
                                <option value="1">ระดับประถมศึกษา (36 หน่วยกิต)</option>
                                <option value="2">ระดับมัธยมศึกษาตอนต้น (40 หน่วยกิต)</option>
                                <option value="3">ระดับมัธยมศึกษาตอนปลาย (44 หน่วยกิต)</option>
                            </select>
                        </div>
                        <div className="flex justify-end gap-2 pt-2">
                            <Button appearance="subtle" onClick={() => setShowBlankModal(false)}>
                                ยกเลิก
                            </Button>
                            <Button
                                appearance="primary"
                                icon={<Printer size={16} />}
                                onClick={() => {
                                    setShowBlankModal(false);
                                    openPrintDocument({ scope: 'blank', level: blankLevel });
                                }}
                            >
                                พิมพ์แบบฟอร์ม
                            </Button>
                        </div>
                    </div>
                </div>
            )}

            {/* List View */}
            {!selectedStudentCode && (
                <>
                    {/* Stat Tiles */}
                    <StatGrid columns={3}>
                        <StatTile
                            label="นักศึกษาทั้งหมด"
                            value={totalCount}
                            detail="ตามตัวกรองปัจจุบัน"
                            icon={StudentIcon}
                            tone="sky"
                        />
                        <StatTile
                            label="บันทึกแล้ว"
                            value={registeredCount}
                            detail="มีข้อมูลลงทะเบียนในระบบ"
                            icon={CheckCircle}
                            tone="emerald"
                        />
                        <StatTile
                            label="ยังไม่บันทึก"
                            value={pendingCount}
                            detail="รอดำเนินการลงทะเบียน"
                            icon={Notebook}
                            tone="amber"
                        />
                    </StatGrid>

                    {/* Filter Bar */}
                    <Panel title="รายชื่อนักศึกษาสำหรับการลงทะเบียน">
                        <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <label className="block text-xs font-bold text-slate-700 mb-1">ภาคเรียน</label>
                                <select
                                    value={term || workspaceData.term}
                                    onChange={(e) => setTerm(e.target.value)}
                                    className="w-full rounded-xl border border-slate-300 bg-white p-2.5 text-sm"
                                >
                                    {(workspaceData.terms || []).map((t) => (
                                        <option key={t} value={t}>
                                            ภาคเรียนที่ {t}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div>
                                <label className="block text-xs font-bold text-slate-700 mb-1">ระดับการศึกษา</label>
                                <select
                                    value={level}
                                    onChange={(e) => setLevel(e.target.value)}
                                    className="w-full rounded-xl border border-slate-300 bg-white p-2.5 text-sm"
                                >
                                    <option value="">ทุกระดับชั้น</option>
                                    <option value="1">ประถมศึกษา</option>
                                    <option value="2">มัธยมศึกษาตอนต้น</option>
                                    <option value="3">มัธยมศึกษาตอนปลาย</option>
                                </select>
                            </div>

                            <div>
                                <label className="block text-xs font-bold text-slate-700 mb-1">กลุ่มเรียน</label>
                                <select
                                    value={group}
                                    onChange={(e) => setGroup(e.target.value)}
                                    className="w-full rounded-xl border border-slate-300 bg-white p-2.5 text-sm"
                                >
                                    <option value="">ทุกกลุ่มเรียน</option>
                                    {(workspaceData.groups || []).map((g) => (
                                        <option key={g.value} value={g.value}>
                                            {g.label}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div>
                                <label className="block text-xs font-bold text-slate-700 mb-1">ค้นหา</label>
                                <div className="relative">
                                    <input
                                        type="text"
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                        placeholder="ชื่อ หรือ รหัส นศ."
                                        className="w-full rounded-xl border border-slate-300 p-2.5 pl-9 text-sm"
                                    />
                                    <MagnifyingGlass
                                        size={18}
                                        className="absolute left-3 top-3 text-slate-400"
                                    />
                                </div>
                            </div>
                        </div>

                        {workspaceQuery.isLoading ? (
                            <QuerySkeleton rows={6} />
                        ) : workspaceQuery.isError ? (
                            <QueryError onRetry={() => workspaceQuery.refetch()} />
                        ) : (
                            <DataTable
                                data={workspaceData.items}
                                columns={columns}
                                emptyTitle="ไม่พบข้อมูลนักศึกษา"
                                emptyDescription="ลองปรับเงื่อนไขตัวกรอง ภาคเรียน ระดับ หรือกลุ่มเรียน"
                            />
                        )}
                    </Panel>
                </>
            )}

            {/* Registration Form View */}
            {selectedStudentCode && (
                <div className="space-y-6">
                    {/* Header bar */}
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <Button
                            appearance="subtle"
                            icon={<ArrowLeft size={18} />}
                            onClick={handleBackToList}
                        >
                            กลับหน้ารายชื่อ
                        </Button>
                        <div className="flex flex-wrap items-center gap-2">
                            {/* Academic Term Selector for Student */}
                            <div className="flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-2.5 py-1.5 shadow-sm">
                                <label htmlFor="student-term-select" className="text-xs font-bold text-slate-600">
                                    ภาคเรียน:
                                </label>
                                <select
                                    id="student-term-select"
                                    value={term || studentDetail?.academic_term || workspaceData.term}
                                    onChange={(e) => {
                                        if (isFormDirty && !window.confirm('คุณมีข้อมูลที่ยังไม่ได้บันทึก หากเปลี่ยนภาคเรียนข้อมูลที่แก้ไขจะหายไป ต้องการเปลี่ยนหรือไม่?')) {
                                            return;
                                        }
                                        setTerm(e.target.value);
                                        setIsFormDirty(false);
                                    }}
                                    className="rounded-lg border-0 bg-transparent py-0 pl-1 pr-6 text-xs font-black text-brand-700 focus:ring-0 cursor-pointer"
                                >
                                    {(studentDetail?.available_terms || workspaceData.terms || []).map((t) => (
                                        <option key={t} value={t}>
                                            ภาคเรียนที่ {t}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <Button
                                appearance="outline"
                                icon={<Printer size={18} />}
                                onClick={() =>
                                    openPrintDocument({ scope: 'student', student: selectedStudentCode })
                                }
                            >
                                พิมพ์ใบลงทะเบียน (PDF)
                            </Button>
                            <Button
                                appearance="primary"
                                icon={<FloppyDisk size={18} />}
                                onClick={() => saveMutation.mutate()}
                                disabled={saveMutation.isPending}
                            >
                                {saveMutation.isPending ? 'กำลังบันทึก...' : 'บันทึกการลงทะเบียน'}
                            </Button>
                        </div>
                    </div>

                    {studentDetailQuery.isLoading ? (
                        <QuerySkeleton rows={8} />
                    ) : studentDetailQuery.isError || !studentDetail ? (
                        <QueryError onRetry={() => studentDetailQuery.refetch()} />
                    ) : (
                        <>
                            {/* Student Info Card */}
                            <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm space-y-4">
                                <div className="flex flex-wrap items-start justify-between gap-4 border-b border-slate-100 pb-4">
                                    <div>
                                        <div className="flex items-center gap-2">
                                            <h2 className="text-xl font-black text-slate-900">
                                                {studentInfo?.name || studentDetail.student.name}
                                            </h2>
                                            <StatusBadge tone="brand">
                                                {studentDetail.student.level_label}
                                            </StatusBadge>
                                        </div>
                                        <p className="mt-1 font-mono text-sm text-slate-500">
                                            รหัสนักศึกษา: {studentInfo?.code || studentDetail.student.code} | เลขบัตร ปชช: {studentInfo?.citizen_id || studentDetail.student.citizen_id}
                                        </p>
                                    </div>
                                    <div className="flex flex-col items-end gap-1.5">
                                        <div className="flex items-center gap-2">
                                            <span className="inline-block rounded-xl bg-brand-50 border border-brand-200 px-3 py-1.5 text-xs font-bold text-brand-800">
                                                ภาคเรียนที่ {studentDetail.academic_term}
                                            </span>
                                            <button
                                                type="button"
                                                onClick={() => setShowEditInfo(!showEditInfo)}
                                                className="inline-flex items-center gap-1.5 rounded-xl border border-brand-300 bg-brand-50 px-3 py-1.5 text-xs font-bold text-brand-700 hover:bg-brand-100 transition-colors shadow-sm"
                                            >
                                                <PencilSimple size={15} />
                                                {showEditInfo ? 'ซ่อนการแก้ไขข้อมูล' : 'แก้ไขข้อมูลส่วนตัว'}
                                            </button>
                                        </div>
                                        <p className="text-xs text-slate-500">
                                            กลุ่ม: {studentInfo?.group || studentDetail.student.group_name || studentDetail.student.group_code}
                                        </p>
                                    </div>
                                </div>

                                {/* Editable Student Info Form */}
                                {showEditInfo ? (
                                    <div className="rounded-xl border border-brand-200 bg-brand-50/40 p-4 space-y-4">
                                        <div className="flex items-center justify-between border-b border-brand-100 pb-2">
                                            <h4 className="text-xs font-bold text-brand-900 uppercase tracking-wider flex items-center gap-1.5">
                                                <PencilSimple size={15} /> แก้ไขข้อมูลส่วนตัวบนใบลงทะเบียนเรียน
                                            </h4>
                                            <span className="text-[11px] text-brand-600">
                                                * ข้อมูลที่แก้ไขจะถูกบันทึกและนำไปพิมพ์ลงบนใบลงทะเบียนเรียน (PDF)
                                            </span>
                                        </div>

                                        {/* Row 1: ชื่อ, เลข ปชช, รหัส นศ */}
                                        <div className="grid gap-3 sm:grid-cols-3">
                                            <div>
                                                <label className="block text-[11px] font-bold text-slate-700 mb-1">ชื่อ - สกุล</label>
                                                <input
                                                    type="text"
                                                    value={studentInfo?.name || ''}
                                                    onChange={(e) => handleStudentInfoChange('name', e.target.value)}
                                                    className="w-full rounded-lg border border-slate-300 bg-white p-2 text-xs font-medium focus:border-brand-500 focus:outline-none"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-[11px] font-bold text-slate-700 mb-1">เลขประจำตัวประชาชน (13 หลัก)</label>
                                                <input
                                                    type="text"
                                                    maxLength={13}
                                                    value={studentInfo?.citizen_id || ''}
                                                    onChange={(e) => handleStudentInfoChange('citizen_id', e.target.value)}
                                                    className="w-full rounded-lg border border-slate-300 bg-white p-2 text-xs font-mono font-medium focus:border-brand-500 focus:outline-none"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-[11px] font-bold text-slate-700 mb-1">รหัสประจำตัวนักศึกษา (10 หลัก)</label>
                                                <input
                                                    type="text"
                                                    maxLength={10}
                                                    value={studentInfo?.code || ''}
                                                    onChange={(e) => handleStudentInfoChange('code', e.target.value)}
                                                    className="w-full rounded-lg border border-slate-300 bg-white p-2 text-xs font-mono font-medium focus:border-brand-500 focus:outline-none"
                                                />
                                            </div>
                                        </div>

                                        {/* Row 2: ติดต่อ */}
                                        <div className="grid gap-3 sm:grid-cols-3">
                                            <div>
                                                <label className="block text-[11px] font-bold text-slate-700 mb-1">เบอร์โทรศัพท์</label>
                                                <input
                                                    type="text"
                                                    value={studentInfo?.phone || ''}
                                                    onChange={(e) => handleStudentInfoChange('phone', e.target.value)}
                                                    className="w-full rounded-lg border border-slate-300 bg-white p-2 text-xs focus:border-brand-500 focus:outline-none"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-[11px] font-bold text-slate-700 mb-1">Facebook</label>
                                                <input
                                                    type="text"
                                                    value={studentInfo?.facebook || ''}
                                                    onChange={(e) => handleStudentInfoChange('facebook', e.target.value)}
                                                    className="w-full rounded-lg border border-slate-300 bg-white p-2 text-xs focus:border-brand-500 focus:outline-none"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-[11px] font-bold text-slate-700 mb-1">ID Line</label>
                                                <input
                                                    type="text"
                                                    value={studentInfo?.line_id || ''}
                                                    onChange={(e) => handleStudentInfoChange('line_id', e.target.value)}
                                                    className="w-full rounded-lg border border-slate-300 bg-white p-2 text-xs focus:border-brand-500 focus:outline-none"
                                                />
                                            </div>
                                        </div>

                                        {/* Row 3: ที่อยู่ */}
                                        <div className="grid gap-2 sm:grid-cols-5">
                                            <div>
                                                <label className="block text-[11px] font-bold text-slate-700 mb-1">บ้านเลขที่</label>
                                                <input
                                                    type="text"
                                                    value={studentInfo?.house_no || ''}
                                                    onChange={(e) => handleStudentInfoChange('house_no', e.target.value)}
                                                    className="w-full rounded-lg border border-slate-300 bg-white p-2 text-xs focus:border-brand-500 focus:outline-none"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-[11px] font-bold text-slate-700 mb-1">หมู่ที่</label>
                                                <input
                                                    type="text"
                                                    value={studentInfo?.moo || ''}
                                                    onChange={(e) => handleStudentInfoChange('moo', e.target.value)}
                                                    className="w-full rounded-lg border border-slate-300 bg-white p-2 text-xs focus:border-brand-500 focus:outline-none"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-[11px] font-bold text-slate-700 mb-1">ตำบล</label>
                                                <input
                                                    type="text"
                                                    value={studentInfo?.subdistrict || ''}
                                                    onChange={(e) => handleStudentInfoChange('subdistrict', e.target.value)}
                                                    className="w-full rounded-lg border border-slate-300 bg-white p-2 text-xs focus:border-brand-500 focus:outline-none"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-[11px] font-bold text-slate-700 mb-1">อำเภอ</label>
                                                <input
                                                    type="text"
                                                    value={studentInfo?.district || ''}
                                                    onChange={(e) => handleStudentInfoChange('district', e.target.value)}
                                                    className="w-full rounded-lg border border-slate-300 bg-white p-2 text-xs focus:border-brand-500 focus:outline-none"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-[11px] font-bold text-slate-700 mb-1">จังหวัด</label>
                                                <input
                                                    type="text"
                                                    value={studentInfo?.province || ''}
                                                    onChange={(e) => handleStudentInfoChange('province', e.target.value)}
                                                    className="w-full rounded-lg border border-slate-300 bg-white p-2 text-xs focus:border-brand-500 focus:outline-none"
                                                />
                                            </div>
                                        </div>

                                        {/* Row 4: ข้อมูลสังกัดบนใบลงทะเบียน (กล่องขวา) & ภาคเรียน */}
                                        <div className="grid gap-3 sm:grid-cols-4">
                                            <div>
                                                <label className="block text-[11px] font-bold text-slate-700 mb-1">กลุ่ม (กล่องขวา)</label>
                                                <input
                                                    type="text"
                                                    value={studentInfo?.group || ''}
                                                    onChange={(e) => handleStudentInfoChange('group', e.target.value)}
                                                    className="w-full rounded-lg border border-slate-300 bg-white p-2 text-xs focus:border-brand-500 focus:outline-none"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-[11px] font-bold text-slate-700 mb-1">ตำบล (กล่องขวา)</label>
                                                <input
                                                    type="text"
                                                    value={studentInfo?.box_subdistrict || ''}
                                                    onChange={(e) => handleStudentInfoChange('box_subdistrict', e.target.value)}
                                                    className="w-full rounded-lg border border-slate-300 bg-white p-2 text-xs focus:border-brand-500 focus:outline-none"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-[11px] font-bold text-slate-700 mb-1">ภาคเรียนที่</label>
                                                <input
                                                    type="text"
                                                    value={studentInfo?.term_no || ''}
                                                    onChange={(e) => handleStudentInfoChange('term_no', e.target.value)}
                                                    placeholder="1"
                                                    className="w-full rounded-lg border border-slate-300 bg-white p-2 text-xs focus:border-brand-500 focus:outline-none"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-[11px] font-bold text-slate-700 mb-1">ปีการศึกษา</label>
                                                <input
                                                    type="text"
                                                    value={studentInfo?.term_year || ''}
                                                    onChange={(e) => handleStudentInfoChange('term_year', e.target.value)}
                                                    placeholder="2569"
                                                    className="w-full rounded-lg border border-slate-300 bg-white p-2 text-xs focus:border-brand-500 focus:outline-none"
                                                />
                                            </div>
                                        </div>

                                        {/* Row 4.5: ครูประจำกลุ่ม */}
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <div>
                                                <div className="flex items-center justify-between mb-1">
                                                    <label className="block text-[11px] font-bold text-slate-700">
                                                        ชื่อครูประจำกลุ่ม (ผู้ลงชื่อในเอกสาร)
                                                    </label>
                                                    {/* Quick prefix buttons */}
                                                    <div className="flex items-center gap-1">
                                                        <span className="text-[10px] text-slate-500">คำนำหน้า:</span>
                                                        {['นาย', 'นาง', 'นางสาว'].map((pfx) => (
                                                            <button
                                                                key={pfx}
                                                                type="button"
                                                                onClick={() => {
                                                                    const cur = (studentInfo?.teacher_name || '').trim();
                                                                    const cleaned = cur.replace(/^(นางสาว|นาย|นาง|ครู)\s*/, '');
                                                                    handleStudentInfoChange('teacher_name', `${pfx}${cleaned}`);
                                                                }}
                                                                className="rounded border border-slate-300 bg-white px-1.5 py-0.5 text-[10px] font-bold text-slate-700 hover:bg-brand-50 hover:text-brand-700 hover:border-brand-300 transition"
                                                            >
                                                                +{pfx}
                                                            </button>
                                                        ))}
                                                    </div>
                                                </div>
                                                <input
                                                    type="text"
                                                    value={studentInfo?.teacher_name || ''}
                                                    onChange={(e) => handleStudentInfoChange('teacher_name', e.target.value)}
                                                    placeholder="เช่น นางสาวสุธาทิพย์ ดีจุ่น หรือ นายสมชาย ใจดี"
                                                    className="w-full rounded-lg border border-slate-300 bg-white p-2 text-xs focus:border-brand-500 focus:outline-none font-medium"
                                                />
                                                <p className="mt-1 text-[10px] text-slate-500">
                                                    * ระบุคำนำหน้าชื่อให้ครบถ้วน (เช่น นางสาว หรือ นาย) เพื่อให้ชื่อตรงกึ่งกลางช่องลงชื่อบนใบลงทะเบียนเรียน
                                                </p>
                                            </div>
                                        </div>

                                        {/* Row 5: หน่วยกิตสะสมและคงเหลือ */}
                                        <div className="grid gap-2 sm:grid-cols-4 pt-1">
                                            <div>
                                                <label className="block text-[11px] font-bold text-slate-700 mb-1">บังคับได้สะสม (นก.)</label>
                                                <input
                                                    type="number"
                                                    step="0.5"
                                                    value={studentInfo?.compulsory_earned ?? ''}
                                                    onChange={(e) => handleStudentInfoChange('compulsory_earned', e.target.value)}
                                                    className="w-full rounded-lg border border-slate-300 bg-white p-2 text-xs focus:border-brand-500 focus:outline-none"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-[11px] font-bold text-slate-700 mb-1">เลือกได้สะสม (นก.)</label>
                                                <input
                                                    type="number"
                                                    step="0.5"
                                                    value={studentInfo?.elective_earned ?? ''}
                                                    onChange={(e) => handleStudentInfoChange('elective_earned', e.target.value)}
                                                    className="w-full rounded-lg border border-slate-300 bg-white p-2 text-xs focus:border-brand-500 focus:outline-none"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-[11px] font-bold text-slate-700 mb-1">บังคับคงเหลือ (นก.)</label>
                                                <input
                                                    type="number"
                                                    step="0.5"
                                                    value={studentInfo?.compulsory_remaining ?? ''}
                                                    onChange={(e) => handleStudentInfoChange('compulsory_remaining', e.target.value)}
                                                    className="w-full rounded-lg border border-slate-300 bg-white p-2 text-xs focus:border-brand-500 focus:outline-none"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-[11px] font-bold text-slate-700 mb-1">เลือกคงเหลือ (นก.)</label>
                                                <input
                                                    type="number"
                                                    step="0.5"
                                                    value={studentInfo?.elective_remaining ?? ''}
                                                    onChange={(e) => handleStudentInfoChange('elective_remaining', e.target.value)}
                                                    className="w-full rounded-lg border border-slate-300 bg-white p-2 text-xs focus:border-brand-500 focus:outline-none"
                                                />
                                            </div>
                                        </div>
                                    </div>
                                ) : (
                                    <div className="grid gap-3 text-xs sm:grid-cols-3">
                                        <div>
                                            <span className="text-slate-400">เบอร์โทรศัพท์:</span>{' '}
                                            <span className="font-bold text-slate-700">{studentInfo?.phone || studentDetail.student.phone || '-'}</span>
                                        </div>
                                        <div>
                                            <span className="text-slate-400">Facebook:</span>{' '}
                                            <span className="font-bold text-slate-700">{studentInfo?.facebook || studentDetail.student.facebook || '-'}</span>
                                        </div>
                                        <div>
                                            <span className="text-slate-400">LINE ID:</span>{' '}
                                            <span className="font-bold text-slate-700">{studentInfo?.line_id || studentDetail.student.line_id || '-'}</span>
                                        </div>
                                        <div>
                                            <span className="text-slate-400">กลุ่ม / ตำบล:</span>{' '}
                                            <span className="font-bold text-slate-700">{studentInfo?.group || studentDetail.student.group_name || '-'} {studentInfo?.box_subdistrict ? `(ต.${studentInfo.box_subdistrict})` : ''}</span>
                                        </div>
                                        <div>
                                            <span className="text-slate-400">ครูประจำกลุ่ม:</span>{' '}
                                            <span className="font-bold text-slate-700">{studentInfo?.teacher_name || '-'}</span>
                                        </div>
                                        <div className="sm:col-span-3">
                                            <span className="text-slate-400">ที่อยู่บนใบลงทะเบียน:</span>{' '}
                                            <span className="font-medium text-slate-700">
                                                {studentInfo?.house_no
                                                    ? `${studentInfo.house_no} ${studentInfo.moo ? `หมู่ ${studentInfo.moo}` : ''} ต.${studentInfo.subdistrict} อ.${studentInfo.district} จ.${studentInfo.province}`
                                                    : (studentDetail.student.address || '-')}
                                            </span>
                                        </div>
                                    </div>
                                )}

                                {/* Credit Requirements Summary */}
                                <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 pt-2 border-t border-slate-100">
                                    <div className="rounded-xl bg-blue-50 p-2.5">
                                        <div className="text-[11px] font-bold text-blue-700">วิชาบังคับสะสม</div>
                                        <div className="mt-0.5 text-lg font-black text-blue-950">
                                            {studentDetail.requirements.compulsory_earned} / {studentDetail.requirements.compulsory_required}
                                        </div>
                                        <div className="text-[10px] text-blue-600">
                                            เหลือ {studentDetail.requirements.compulsory_remaining} นก.
                                        </div>
                                    </div>
                                    <div className="rounded-xl bg-purple-50 p-2.5">
                                        <div className="text-[11px] font-bold text-purple-700">วิชาเลือกสะสม</div>
                                        <div className="mt-0.5 text-lg font-black text-purple-950">
                                            {studentDetail.requirements.elective_earned} / {studentDetail.requirements.elective_required}
                                        </div>
                                        <div className="text-[10px] text-purple-600">
                                            เหลือ {studentDetail.requirements.elective_remaining} นก.
                                        </div>
                                    </div>
                                    <div className="rounded-xl bg-emerald-50 p-2.5">
                                        <div className="text-[11px] font-bold text-emerald-700">เลือกลงเทอมนี้</div>
                                        <div className="mt-0.5 text-lg font-black text-emerald-950">
                                            {termTotalCredits} นก.
                                        </div>
                                        <div className="text-[10px] text-emerald-600">
                                            (บังคับ {termCompulsoryCredits} + เลือก {termElectiveCredits})
                                        </div>
                                    </div>
                                    <div className="rounded-xl bg-amber-50 p-2.5">
                                        <div className="text-[11px] font-bold text-amber-700">สถานะบันทึก</div>
                                        <div className="mt-1 font-bold">
                                            {isFormDirty ? (
                                                <span className="text-amber-800">ยังไม่บันทึกการแก้ไข</span>
                                            ) : studentDetail.is_saved ? (
                                                <span className="text-emerald-700">บันทึกเรียบร้อย</span>
                                            ) : (
                                                <span className="text-slate-500">ยังไม่เคยบันทึก</span>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {/* Status Badges Legend */}
                            <div className="flex flex-wrap items-center gap-2 rounded-2xl bg-white border border-slate-200/80 p-3 shadow-2xs text-xs">
                                <span className="font-bold text-slate-700 text-xs mr-1">สัญลักษณ์สถานะรายวิชา:</span>
                                <span className="inline-flex items-center gap-1.5 rounded-md border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[11px] font-bold text-emerald-800">
                                    <span className="size-1.5 rounded-full bg-emerald-500" /> มีเกรดแล้ว
                                </span>
                                <span className="inline-flex items-center gap-1.5 rounded-md border border-purple-200 bg-purple-50 px-2 py-0.5 text-[11px] font-bold text-purple-800">
                                    <span className="size-1.5 rounded-full bg-purple-500" /> เทียบโอนแล้ว
                                </span>
                                <span className="inline-flex items-center gap-1.5 rounded-md border border-amber-300 bg-amber-50 px-2 py-0.5 text-[11px] font-bold text-amber-800">
                                    <span className="size-1.5 rounded-full bg-amber-500" /> รอเกรด
                                </span>
                                <span className="inline-flex items-center gap-1.5 rounded-md border border-orange-300 bg-orange-50 px-2 py-0.5 text-[11px] font-bold text-orange-800">
                                    <span className="size-1.5 rounded-full bg-orange-500" /> เกรด "ข" ขาดสอบ
                                </span>
                                <span className="inline-flex items-center gap-1.5 rounded-md border border-rose-200 bg-rose-50 px-2 py-0.5 text-[11px] font-bold text-rose-800">
                                    <span className="size-1.5 rounded-full bg-rose-500" /> เกรด 0 (ลงแก้ตัวได้)
                                </span>
                                <span className="inline-flex items-center gap-1.5 rounded-md border border-sky-200 bg-sky-50 px-2 py-0.5 text-[11px] font-bold text-sky-800">
                                    <span className="size-1.5 rounded-full bg-sky-500" /> ยังไม่ได้เรียน (แนะนำ)
                                </span>
                            </div>

                            {/* Section 1: Compulsory Subjects */}
                            <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm space-y-4">
                                <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                                    <div>
                                        <h3 className="text-base font-bold text-slate-900">
                                            รายวิชาบังคับ ({studentDetail.requirements.compulsory_required} หน่วยกิต)
                                        </h3>
                                        <p className="text-xs text-slate-500">
                                            วิชามาตรฐานตามหลักสูตร กศน./สกร. 2551 ประจำระดับชั้น
                                        </p>
                                    </div>
                                    <span className="text-xs font-bold text-blue-700">
                                        ลงทะเบียน {termCompulsoryCredits} นก.
                                    </span>
                                </div>

                                <div className="overflow-x-auto">
                                    <table className="w-full text-left text-sm">
                                        <thead>
                                            <tr className="border-b border-slate-200 bg-slate-50 text-xs font-bold text-slate-700">
                                                <th className="p-3">สาระการเรียนรู้</th>
                                                <th className="p-3 w-28 text-center">รหัสวิชา</th>
                                                <th className="p-3 w-24 text-center">หน่วยกิต</th>
                                                <th className="p-3 w-28 text-center">ลงทะเบียน</th>
                                                <th className="p-3 w-28 text-center">เทียบโอน</th>
                                                <th className="p-3 w-40">หมายเหตุ</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {compulsoryRows.map((row, idx) => (
                                                <tr
                                                    key={row.code}
                                                    className={`hover:bg-slate-50/80 ${row.registered ? 'bg-blue-50/30' : ''}`}
                                                >
                                                    <td className="p-3">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <span className="font-bold text-slate-900">{row.name}</span>
                                                            <CourseStatusBadge status={row.course_status} />
                                                        </div>
                                                        {row.registered && row.course_status?.warning && (
                                                            <div className={`mt-1 flex items-center gap-1.5 rounded-md px-2 py-0.5 text-[11px] font-medium border ${
                                                                row.course_status.status === 'absent_exam'
                                                                    ? 'bg-orange-50 text-orange-950 border-orange-200'
                                                                    : row.course_status.status === 'failed'
                                                                    ? 'bg-rose-50 text-rose-950 border-rose-200'
                                                                    : 'bg-amber-50 text-amber-950 border-amber-200'
                                                            }`}>
                                                                <Warning size={13} className={`shrink-0 ${
                                                                    row.course_status.status === 'absent_exam'
                                                                        ? 'text-orange-600'
                                                                        : row.course_status.status === 'failed'
                                                                        ? 'text-rose-600'
                                                                        : 'text-amber-600'
                                                                }`} />
                                                                <span>{row.course_status.warning}</span>
                                                            </div>
                                                        )}
                                                    </td>
                                                    <td className="p-3 text-center font-mono text-xs font-bold text-slate-700">
                                                        {row.code}
                                                    </td>
                                                    <td className="p-3 text-center tabular-nums text-slate-700">
                                                        {row.credits}
                                                    </td>
                                                    <td className="p-3 text-center">
                                                        <input
                                                            type="checkbox"
                                                            checked={row.registered}
                                                            onChange={(e) => {
                                                                const updated = [...compulsoryRows];
                                                                updated[idx].registered = e.target.checked;
                                                                if (e.target.checked) updated[idx].transferred = false;
                                                                setCompulsoryRows(updated);
                                                                setIsFormDirty(true);
                                                            }}
                                                            className="size-4.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                                                        />
                                                    </td>
                                                    <td className="p-3 text-center">
                                                        <input
                                                            type="checkbox"
                                                            checked={row.transferred}
                                                            onChange={(e) => {
                                                                const updated = [...compulsoryRows];
                                                                updated[idx].transferred = e.target.checked;
                                                                if (e.target.checked) updated[idx].registered = false;
                                                                setCompulsoryRows(updated);
                                                                setIsFormDirty(true);
                                                            }}
                                                            className="size-4.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                                                        />
                                                    </td>
                                                    <td className="p-3">
                                                        <input
                                                            type="text"
                                                            value={row.remark}
                                                            placeholder="หมายเหตุ..."
                                                            onChange={(e) => {
                                                                const updated = [...compulsoryRows];
                                                                updated[idx].remark = e.target.value;
                                                                setCompulsoryRows(updated);
                                                                setIsFormDirty(true);
                                                            }}
                                                            className="w-full rounded-lg border border-slate-200 px-2 py-1 text-xs"
                                                        />
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            {/* Section 2: Elective Subjects */}
                            <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm space-y-4">
                                <div className="flex flex-wrap items-center justify-between border-b border-slate-100 pb-3 gap-2">
                                    <div>
                                        <h3 className="text-base font-bold text-slate-900">
                                            รายวิชาเลือก ({studentDetail.requirements.elective_required} หน่วยกิต)
                                        </h3>
                                        <p className="text-xs text-slate-500">
                                            สามารถเลือกจากวิชาแนะนำ หรือพิมพ์รหัส/ชื่อวิชาเลือกเพิ่มเติมได้
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <span className="text-xs font-bold text-purple-700 mr-2">
                                            ลงทะเบียน {termElectiveCredits} นก.
                                        </span>
                                        <Button
                                            size="small"
                                            appearance="primary"
                                            icon={<Plus size={14} />}
                                            onClick={() => {
                                                setElectiveRows([
                                                    ...electiveRows,
                                                    {
                                                        code: '',
                                                        name: '',
                                                        credits: 2.0,
                                                        registered: true,
                                                        transferred: false,
                                                        remark: '',
                                                    },
                                                ]);
                                                setIsFormDirty(true);
                                            }}
                                        >
                                            เพิ่มวิชาเลือก
                                        </Button>
                                    </div>
                                </div>

                                {/* Common Electives Quick Add Chips */}
                                {studentDetail.common_electives?.length > 0 && (
                                    <div className="rounded-xl bg-slate-50 p-3 space-y-2">
                                        <div className="flex items-center justify-between">
                                            <span className="text-xs font-bold text-slate-700">
                                                วิชาเลือกแนะนำสำหรับระดับนี้ (คลิกเพื่อเพิ่ม พร้อมตรวจสอบสถานะเกรด):
                                            </span>
                                            <span className="text-[11px] text-slate-500">
                                                * คลิกวิชาเพื่อเพิ่มในรายการลงทะเบียน
                                            </span>
                                        </div>
                                        <div className="flex flex-wrap gap-2">
                                            {studentDetail.common_electives.map((ce) => {
                                                const isAlreadyInList = electiveRows.some((r) => r.code === ce.code);
                                                return (
                                                    <button
                                                        key={ce.code}
                                                        type="button"
                                                        onClick={() => {
                                                            if (isAlreadyInList) {
                                                                showErrorAlert(`วิชา ${ce.code} มีอยู่ในรายการแล้ว`);
                                                                return;
                                                            }
                                                            if (ce.course_status?.status === 'passed') {
                                                                if (!window.confirm(`คำเตือน: วิชา ${ce.code} มีผลการเรียนแล้ว (${ce.course_status.status_label}) คุณต้องการเลือกลงทะเบียนซ้ำหรือไม่?`)) {
                                                                    return;
                                                                }
                                                            } else if (ce.course_status?.status === 'pending_grade') {
                                                                if (!window.confirm(`คำเตือน: วิชา ${ce.code} อยู่ระหว่างรอผลการเรียน (${ce.course_status.status_label}) คุณต้องการเลือกลงทะเบียนซ้ำหรือไม่?`)) {
                                                                    return;
                                                                }
                                                            }
                                                            setElectiveRows([
                                                                ...electiveRows,
                                                                {
                                                                    code: ce.code,
                                                                    name: ce.name,
                                                                    credits: ce.credits,
                                                                    registered: true,
                                                                    transferred: false,
                                                                    remark: '',
                                                                    course_status: ce.course_status,
                                                                },
                                                            ]);
                                                            setIsFormDirty(true);
                                                        }}
                                                        className={`inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-semibold shadow-2xs transition ${
                                                            isAlreadyInList
                                                                ? 'border-slate-200 bg-slate-100 text-slate-400 cursor-not-allowed'
                                                                : ce.course_status?.status === 'not_taken'
                                                                ? 'border-sky-300 bg-sky-50/60 text-sky-900 hover:bg-sky-100 hover:border-sky-400'
                                                                : ce.course_status?.status === 'absent_exam'
                                                                ? 'border-orange-300 bg-orange-50/80 text-orange-950 hover:bg-orange-100 hover:border-orange-400'
                                                                : ce.course_status?.status === 'failed'
                                                                ? 'border-rose-300 bg-rose-50/80 text-rose-950 hover:bg-rose-100 hover:border-rose-400'
                                                                : 'border-slate-200 bg-white text-slate-700 hover:border-brand-500 hover:bg-brand-50'
                                                        }`}
                                                    >
                                                        <Plus size={13} className="shrink-0" />
                                                        <span>
                                                            {ce.code} {ce.name} ({ce.credits} นก.)
                                                        </span>
                                                        <CourseStatusBadge status={ce.course_status} />
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </div>
                                )}

                                <div className="overflow-x-auto">
                                    <table className="w-full text-left text-sm">
                                        <thead>
                                            <tr className="border-b border-slate-200 bg-slate-50 text-xs font-bold text-slate-700">
                                                <th className="p-3">สาระการเรียนรู้</th>
                                                <th className="p-3 w-32">รหัสวิชา</th>
                                                <th className="p-3 w-24 text-center">หน่วยกิต</th>
                                                <th className="p-3 w-28 text-center">ลงทะเบียน</th>
                                                <th className="p-3 w-28 text-center">เทียบโอน</th>
                                                <th className="p-3 w-36">หมายเหตุ</th>
                                                <th className="p-3 w-16 text-center">ลบ</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {electiveRows.length === 0 ? (
                                                <tr>
                                                    <td colSpan={7} className="p-4 text-center text-xs text-slate-400">
                                                        ยังไม่มีการเพิ่มวิชาเลือก (สามารถกดปุ่ม "เพิ่มวิชาเลือก" ด้านบน)
                                                    </td>
                                                </tr>
                                            ) : (
                                                electiveRows.map((row, idx) => (
                                                    <tr key={idx} className="hover:bg-slate-50/80">
                                                        <td className="p-3">
                                                            <div className="space-y-1">
                                                                <input
                                                                    type="text"
                                                                    value={row.name}
                                                                    placeholder="ชื่อวิชาเลือก..."
                                                                    onChange={(e) => {
                                                                        const updated = [...electiveRows];
                                                                        updated[idx].name = e.target.value;
                                                                        setElectiveRows(updated);
                                                                        setIsFormDirty(true);
                                                                    }}
                                                                    className="w-full rounded-lg border border-slate-200 px-2 py-1 text-sm font-bold text-slate-800"
                                                                />
                                                                <div className="flex flex-wrap items-center gap-1.5">
                                                                    <CourseStatusBadge status={row.course_status || getCourseStatusForCode(row.code)} />
                                                                    {row.registered && (row.course_status?.warning || getCourseStatusForCode(row.code)?.warning) && (
                                                                        <span className={`flex items-center gap-1 text-[11px] font-medium px-1.5 py-0.5 rounded border ${
                                                                            (row.course_status?.status || getCourseStatusForCode(row.code)?.status) === 'absent_exam'
                                                                                ? 'text-orange-950 bg-orange-50 border-orange-200'
                                                                                : (row.course_status?.status || getCourseStatusForCode(row.code)?.status) === 'failed'
                                                                                ? 'text-rose-950 bg-rose-50 border-rose-200'
                                                                                : 'text-amber-950 bg-amber-50 border-amber-200'
                                                                        }`}>
                                                                            <Warning size={12} className={`shrink-0 ${
                                                                                (row.course_status?.status || getCourseStatusForCode(row.code)?.status) === 'absent_exam'
                                                                                    ? 'text-orange-600'
                                                                                    : (row.course_status?.status || getCourseStatusForCode(row.code)?.status) === 'failed'
                                                                                    ? 'text-rose-600'
                                                                                    : 'text-amber-600'
                                                                            }`} />
                                                                            <span>{row.course_status?.warning || getCourseStatusForCode(row.code)?.warning}</span>
                                                                        </span>
                                                                    )}
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td className="p-3">
                                                            <input
                                                                type="text"
                                                                value={row.code}
                                                                placeholder="รหัสวิชา"
                                                                onChange={(e) => {
                                                                    const updated = [...electiveRows];
                                                                    const val = e.target.value;
                                                                    updated[idx].code = val;
                                                                    const resolved = getCourseStatusForCode(val);
                                                                    if (resolved) {
                                                                        updated[idx].course_status = resolved;
                                                                    }
                                                                    setElectiveRows(updated);
                                                                    setIsFormDirty(true);
                                                                }}
                                                                className="w-full rounded-lg border border-slate-200 px-2 py-1 font-mono text-xs font-bold"
                                                            />
                                                        </td>
                                                        <td className="p-3 text-center">
                                                            <input
                                                                type="number"
                                                                step="0.5"
                                                                min="0.5"
                                                                max="10"
                                                                value={row.credits}
                                                                onChange={(e) => {
                                                                    const updated = [...electiveRows];
                                                                    updated[idx].credits = parseFloat(e.target.value) || 0;
                                                                    setElectiveRows(updated);
                                                                    setIsFormDirty(true);
                                                                }}
                                                                className="w-16 rounded-lg border border-slate-200 p-1 text-center font-bold text-xs"
                                                            />
                                                        </td>
                                                        <td className="p-3 text-center">
                                                            <input
                                                                type="checkbox"
                                                                checked={row.registered}
                                                                onChange={(e) => {
                                                                    const updated = [...electiveRows];
                                                                    updated[idx].registered = e.target.checked;
                                                                    if (e.target.checked) updated[idx].transferred = false;
                                                                    setElectiveRows(updated);
                                                                    setIsFormDirty(true);
                                                                }}
                                                                className="size-4.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                                                            />
                                                        </td>
                                                        <td className="p-3 text-center">
                                                            <input
                                                                type="checkbox"
                                                                checked={row.transferred}
                                                                onChange={(e) => {
                                                                    const updated = [...electiveRows];
                                                                    updated[idx].transferred = e.target.checked;
                                                                    if (e.target.checked) updated[idx].registered = false;
                                                                    setElectiveRows(updated);
                                                                    setIsFormDirty(true);
                                                                }}
                                                                className="size-4.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                                                            />
                                                        </td>
                                                        <td className="p-3">
                                                            <input
                                                                type="text"
                                                                value={row.remark}
                                                                placeholder="หมายเหตุ..."
                                                                onChange={(e) => {
                                                                    const updated = [...electiveRows];
                                                                    updated[idx].remark = e.target.value;
                                                                    setElectiveRows(updated);
                                                                    setIsFormDirty(true);
                                                                }}
                                                                className="w-full rounded-lg border border-slate-200 px-2 py-1 text-xs"
                                                            />
                                                        </td>
                                                        <td className="p-3 text-center">
                                                            <button
                                                                type="button"
                                                                onClick={() => {
                                                                    setElectiveRows(electiveRows.filter((_, i) => i !== idx));
                                                                    setIsFormDirty(true);
                                                                }}
                                                                className="text-slate-400 hover:text-rose-600 transition"
                                                                title="ลบวิชานี้"
                                                            >
                                                                <Trash size={16} />
                                                            </button>
                                                        </td>
                                                    </tr>
                                                ))
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            {/* Additional Notes */}
                            <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm space-y-2">
                                <label className="block text-sm font-bold text-slate-800">
                                    บันทึกเพิ่มเติม / หมายเหตุท้ายใบลงทะเบียน
                                </label>
                                <textarea
                                    value={notes}
                                    onChange={(e) => {
                                        setNotes(e.target.value);
                                        setIsFormDirty(true);
                                    }}
                                    placeholder="ระบุข้อความหรือหมายเหตุเพิ่มเติม (ถ้ามี)"
                                    rows={2}
                                    className="w-full rounded-xl border border-slate-200 p-3 text-sm focus:border-brand-500 focus:outline-none"
                                />
                            </div>

                            {/* Bottom Fixed Action Bar */}
                            <div className="flex flex-wrap items-center justify-between gap-4 rounded-2xl bg-slate-900 p-4 text-white shadow-xl">
                                <div>
                                    <div className="text-xs text-slate-400">สรุปการลงทะเบียนในเทอมนี้</div>
                                    <div className="text-base font-black">
                                        วิชาบังคับ {termCompulsoryCredits} นก. + วิชาเลือก {termElectiveCredits} นก. = รวม {termTotalCredits} หน่วยกิต
                                    </div>
                                </div>
                                <div className="flex items-center gap-2">
                                    <Button
                                        appearance="outline"
                                        className="!text-white !border-white/30 hover:!bg-white/10"
                                        icon={<Printer size={18} />}
                                        onClick={() =>
                                            openPrintDocument({ scope: 'student', student: selectedStudentCode })
                                        }
                                    >
                                        พิมพ์ใบลงทะเบียน (PDF)
                                    </Button>
                                    <Button
                                        appearance="primary"
                                        icon={<FloppyDisk size={18} />}
                                        onClick={() => saveMutation.mutate()}
                                        disabled={saveMutation.isPending}
                                    >
                                        {saveMutation.isPending ? 'กำลังบันทึก...' : 'บันทึกการลงทะเบียน'}
                                    </Button>
                                </div>
                            </div>
                        </>
                    )}
                </div>
            )}
        </div>
    );
}
