import { useId, useState, type ReactNode } from 'react';
import Tabs from '@mui/material/Tabs';
import { GraduationCap, IdentificationCard, MapPin, UsersThree } from '@phosphor-icons/react';
import { Tab } from '../../components/MaterialUI';
import { Panel } from '../../components/Panel';

type ProfileValue = string | number | null;
type FamilyMember = { name?: ProfileValue; occupation_code?: ProfileValue; occupation_label?: ProfileValue; income?: ProfileValue; status_code?: ProfileValue; status_label?: ProfileValue };
export type StudentProfileDetails = {
    personal?: Partial<Record<'nationality_code' | 'nationality_label' | 'religion_code' | 'religion_label' | 'occupation_code' | 'occupation_label' | 'target_group_code' | 'target_group_label' | 'height_cm' | 'weight_kg' | 'marital_status_code', ProfileValue>>;
    family?: { marital_status_label?: ProfileValue; father?: FamilyMember; mother?: FamilyMember; guardian?: FamilyMember; siblings?: ProfileValue; studying_siblings?: ProfileValue };
    prior_education?: Partial<Record<'qualification_code' | 'qualification_label' | 'graduation_year' | 'school' | 'province' | 'province_label', ProfileValue>>;
};

type ProfileStudent = {
    code: string; name: string; citizenIdMasked: string; birthDate: string; gender: string; age: string;
    level: string; group: string; groupCode: string; currentTerm: string; enrollmentTerm: string;
    district: string; applicationDate: string; lastUpdated: string;
    gpax: number; creditsEarned: number; creditsRequired: number; activityHours: number; moralResult: string;
    guardian?: { name?: string; phone_masked?: string };
    profile?: StudentProfileDetails;
};

type DetailField = readonly [label: string, value: ProfileValue | undefined, unit?: string];

function ProfileFields({ fields }: { fields: DetailField[] }) {
    return <dl className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        {fields.map(([label, value, unit]) => {
            const hasValue = value !== null && value !== undefined && String(value).trim() !== '' && value !== '-';
            return <div key={label} className="min-w-0 rounded-xl border p-4" style={{ borderColor: 'var(--ui-border)', background: 'var(--ui-surface-muted)' }}>
                <dt className="text-xs font-semibold leading-5" style={{ color: 'var(--ui-text-muted)' }}>{label}</dt>
                <dd className={`mt-1 whitespace-pre-wrap break-words text-sm leading-7 ${hasValue ? 'font-semibold' : 'font-normal'}`} style={{ color: hasValue ? 'var(--ui-text)' : 'var(--ui-text-muted)' }}>
                    {hasValue ? `${value}${unit ? ` ${unit}` : ''}` : 'ยังไม่มีข้อมูล'}
                </dd>
            </div>;
        })}
    </dl>;
}

const profileTabs = [
    { value: 'personal', label: 'ข้อมูลส่วนตัว', icon: IdentificationCard },
    { value: 'address', label: 'ที่อยู่', icon: MapPin },
    { value: 'family', label: 'ครอบครัว', icon: UsersThree },
    { value: 'education', label: 'วุฒิการศึกษาเดิม', icon: GraduationCap },
] as const;

export function StudentProfileTabs({ student, addressContent }: { student: ProfileStudent; addressContent: ReactNode }) {
    const [activeTab, setActiveTab] = useState('personal');
    const id = useId();
    const personal = student.profile?.personal;
    const family = student.profile?.family;
    const education = student.profile?.prior_education;

    return <div className="min-w-0 space-y-4">
        <div className="min-w-0 rounded-2xl border px-2 pt-1" style={{ borderColor: 'var(--ui-border)', background: 'var(--ui-surface)' }}>
            <Tabs value={activeTab} onChange={(_, value: string) => setActiveTab(value)} variant="scrollable" scrollButtons="auto" allowScrollButtonsMobile selectionFollowsFocus aria-label="หมวดข้อมูลนักศึกษา"
                sx={{ '& .MuiTab-root': { minHeight: 56, minWidth: { xs: 130, sm: 160 }, gap: 0.75, color: 'var(--ui-text-muted)' }, '& .MuiTab-root.Mui-selected': { color: 'var(--ui-text)', backgroundColor: 'var(--ui-surface-muted)', borderRadius: '12px 12px 0 0' }, '& .MuiTabs-indicator': { backgroundColor: 'var(--ui-accent-500)', transition: 'none' } }}>
                {profileTabs.map(({ value, label, icon: Icon }) => <Tab key={value} value={value} disableRipple sx={{ '&.Mui-focusVisible': { outline: '2px solid var(--ui-accent-500)', outlineOffset: -3, borderRadius: '12px 12px 0 0' } }} id={`${id}-tab-${value}`} aria-controls={`${id}-panel-${value}`} icon={<Icon size={19} aria-hidden="true" />}>{label}</Tab>)}
            </Tabs>
        </div>
        {profileTabs.map(({ value, label }) => <div key={value} role="tabpanel" id={`${id}-panel-${value}`} aria-labelledby={`${id}-tab-${value}`} hidden={activeTab !== value} tabIndex={0} className="rounded-2xl focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand-600">
            {value === 'personal' && <div className="space-y-4">
                <Panel title={label} icon={IdentificationCard} description="ข้อมูลประจำตัวและรายละเอียดของนักศึกษา">
                    <ProfileFields fields={[
                        ['รหัสนักศึกษา', student.code], ['ชื่อ - สกุล', student.name], ['เลขบัตรประชาชน', student.citizenIdMasked],
                        ['วันเกิด', student.birthDate], ['เพศ', student.gender], ['อายุ', student.age, 'ปี'],
                        ['สัญชาติ', personal?.nationality_label], ['ศาสนา', personal?.religion_label],
                        ['อาชีพ', personal?.occupation_label], ['กลุ่มเป้าหมาย', personal?.target_group_label],
                        ['ส่วนสูง', personal?.height_cm, 'ซม.'], ['น้ำหนัก', personal?.weight_kg, 'กก.'],
                    ]} />
                </Panel>
                <Panel title="ข้อมูลการศึกษาปัจจุบัน" icon={GraduationCap}>
                    <ProfileFields fields={[
                        ['ระดับการศึกษา', student.level], ['กลุ่มเรียน', student.group], ['รหัสกลุ่มเรียน', student.groupCode],
                        ['ภาคเรียนปัจจุบัน', student.currentTerm], ['ภาคเรียนที่เข้าเรียน', student.enrollmentTerm], ['พื้นที่', student.district],
                        ['GPAX สะสม', student.gpax], ['หน่วยกิตสะสม', student.creditsEarned, 'หน่วยกิต'], ['หน่วยกิตที่ต้องเรียน', student.creditsRequired, 'หน่วยกิต'],
                        ['กิจกรรม กพช.', student.activityHours, 'ชั่วโมง'], ['ผลประเมินคุณธรรม', student.moralResult],
                        ['วันที่สมัคร', student.applicationDate], ['ปรับปรุงข้อมูลล่าสุด', student.lastUpdated],
                    ]} />
                </Panel>
            </div>}
            {value === 'address' && addressContent}
            {value === 'family' && <Panel title={label} icon={UsersThree} description="ข้อมูลบิดา มารดา และผู้ปกครองที่มีในทะเบียนนักศึกษา">
                <div className="space-y-6">
                    <ProfileFields fields={[['สถานภาพสมรสของบิดา–มารดา', family?.marital_status_label]]} />
                    {(['father', 'mother', 'guardian'] as const).map((key) => {
                        const member = family?.[key];
                        return <section key={key} aria-label={{ father: 'บิดา', mother: 'มารดา', guardian: 'ผู้ปกครอง' }[key]}>
                            <h3 className="mb-3 text-sm font-bold" style={{ color: 'var(--ui-text)' }}>{{ father: 'บิดา', mother: 'มารดา', guardian: 'ผู้ปกครอง' }[key]}</h3>
                            <ProfileFields fields={[
                                ['ชื่อ - สกุล', member?.name ?? (key === 'guardian' ? student.guardian?.name : null)],
                                ['อาชีพ', member?.occupation_label], ['รายได้ตามทะเบียน', member?.income, 'บาท'],
                                ...(key !== 'guardian' ? [['สถานภาพ', member?.status_label] as DetailField] : []),
                                ...(key === 'guardian' && student.guardian?.phone_masked ? [['โทรศัพท์', student.guardian.phone_masked] as DetailField] : []),
                            ]} />
                        </section>;
                    })}
                    <section aria-label="พี่น้อง">
                        <h3 className="mb-3 text-sm font-bold" style={{ color: 'var(--ui-text)' }}>พี่น้อง</h3>
                        <ProfileFields fields={[
                            ['จำนวนพี่น้องตามทะเบียน', family?.siblings, 'คน'], ['พี่น้องที่กำลังศึกษา', family?.studying_siblings, 'คน'],
                        ]} />
                    </section>
                </div>
            </Panel>}
            {value === 'education' && <Panel title={label} icon={GraduationCap} description="ประวัติการศึกษาก่อนเข้าศึกษาในระดับปัจจุบัน">
                <ProfileFields fields={[
                    ['วุฒิการศึกษาเดิม', education?.qualification_label], ['ปีที่จบการศึกษา', education?.graduation_year],
                    ['สถานศึกษาเดิม', education?.school], ['จังหวัดของสถานศึกษาเดิม', education?.province_label],
                ]} />
            </Panel>}
        </div>)}
    </div>;
}
