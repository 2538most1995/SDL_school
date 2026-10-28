export type QuestionType = 'single_choice' | 'multiple_choice' | 'true_false' | 'short_answer' | 'essay';
export type Question = { id: string; type: QuestionType; prompt: string; image_id: string | null; image_url?: string | null; options: string[]; points: number; answer?: string | string[] };
export type Submission = { id: string; student_code: string; student_name: string; answers: Record<string, string | string[]>; auto_score: number; score: number | null; max_score: number; status: 'submitted' | 'reviewed'; feedback: string | null; submitted_at: string };
export type ActivityKind = 'knowledge' | 'video' | 'worksheet' | 'quiz';
export type Activity = { id: string; title: string; type: ActivityKind; content: string; links: { title: string; url: string }[]; questions: Question[]; status: 'draft' | 'published'; due_at: string | null; target_group: string; subject_code: string; subject_name: string; education_level: number; max_score: number; can_edit: boolean; has_submissions: boolean; submission_count: number; submission: Submission | null };
export type Subject = { code: string; name: string; level: number; level_label: string; student_count: number; groups: { code: string; name: string }[] };
export type PageInfo = { current_page: number; last_page: number; total: number };
export type Workspace = { term: string | null; subjects: Subject[]; selected_subject: Subject | null; activities: Activity[]; selected_activity: Activity | null; submissions: Submission[]; submission_pagination: PageInfo | null; activity_pagination: PageInfo | null; activity_counts: Record<ActivityKind | 'all', number> };
export const kindLabels: Record<ActivityKind, string> = { video: 'คลิปวิดีโอ', knowledge: 'ใบความรู้', worksheet: 'ใบงานออนไลน์', quiz: 'แบบทดสอบ' };
export const questionLabels: Record<QuestionType, string> = { single_choice: 'เลือกตอบ 1 ข้อ', multiple_choice: 'เลือกหลายคำตอบ', true_false: 'ถูก / ผิด', short_answer: 'เติมคำ', essay: 'อัตนัย' };

export function safeClassroomUrl(value: string): string | null {
    try {
        const url = new URL(value);
        return ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password && !/[\s\\\u0000-\u001f\u007f]/u.test(value) ? url.href : null;
    } catch { return null; }
}

export function youtubeEmbed(value: string): string | null {
    const safe = safeClassroomUrl(value);
    if (!safe) return null;
    const url = new URL(safe);
    const host = url.hostname.toLowerCase();
    let id: string | null = null;
    if (host === 'youtu.be') id = url.pathname.slice(1);
    if (['youtube.com', 'www.youtube.com', 'm.youtube.com', 'www.youtube-nocookie.com'].includes(host)) {
        id = url.pathname === '/watch' ? url.searchParams.get('v') : /^\/(?:embed|shorts|live)\/([^/]+)$/.exec(url.pathname)?.[1] ?? null;
    }
    return id && /^[A-Za-z0-9_-]{11}$/.test(id) ? `https://www.youtube-nocookie.com/embed/${id}` : null;
}
