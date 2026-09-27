import { useQuery } from '@tanstack/react-query';
import { ArrowRight, Image as ImageIcon, Newspaper, X } from '@phosphor-icons/react';
import { useState } from 'react';
import { PageHeader } from '../../components/PageHeader';
import { QueryError } from '../../components/QueryState';
import { apiGet } from '../../lib/api';
import { withAppBasePath } from '../../lib/urls';

export type StudentPublicRelationsPost = {
    id: number;
    title: string;
    description: string;
    image_url: string | null;
    published_at: string | null;
    updated_at: string | null;
};

function formatDate(value: string | null): string {
    if (!value) return '-';
    return new Intl.DateTimeFormat('th-TH', { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(value));
}

export function PublicRelationsPage() {
    const [selected, setSelected] = useState<StudentPublicRelationsPost | null>(null);
    const posts = useQuery({
        queryKey: ['public-relations'],
        queryFn: ({ signal }) => apiGet<StudentPublicRelationsPost[]>('/api/v1/public-relations', signal).then((response) => response.data),
        staleTime: 2 * 60_000,
    });

    return (
        <div className="pb-4">
            <PageHeader
                category="ข่าวสาร"
                title="ข่าวประชาสัมพันธ์"
                description="ติดตามข่าว รูปภาพ และข้อมูลสำคัญจากศูนย์การเรียนของคุณ"
                icon={Newspaper}
            />

            {posts.isPending && (
                <div className="grid animate-pulse gap-4 md:grid-cols-2" aria-label="กำลังโหลดข่าวประชาสัมพันธ์">
                    {[1, 2, 3, 4].map((item) => <div key={item} className="h-80 rounded-3xl bg-slate-200" />)}
                </div>
            )}
            {posts.isError && <QueryError onRetry={() => posts.refetch()} />}
            {posts.data && posts.data.length === 0 && (
                <section className="rounded-3xl border border-dashed border-slate-300 bg-white px-6 py-16 text-center">
                    <span className="mx-auto grid size-16 place-items-center rounded-full bg-sky-100 text-sky-700"><Newspaper size={31} weight="duotone" /></span>
                    <h2 className="mt-5 text-lg font-black text-slate-900">ยังไม่มีข่าวประชาสัมพันธ์</h2>
                    <p className="mt-2 text-sm leading-6 text-slate-500">เมื่อผู้ดูแลเผยแพร่ข่าวใหม่ รายการจะแสดงในหน้านี้</p>
                </section>
            )}
            {posts.data && posts.data.length > 0 && (
                <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-3" aria-label="รายการข่าวประชาสัมพันธ์">
                    {posts.data.map((post, index) => (
                        <article key={post.id} className={`group overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-[0_16px_42px_rgb(30_64_175_/_0.08)] ${index === 0 ? 'md:col-span-2 xl:grid xl:grid-cols-[1.2fr_1fr]' : ''}`}>
                            {post.image_url ? (
                                <img src={withAppBasePath(post.image_url)} alt={`ภาพข่าว ${post.title}`} className={`w-full object-cover ${index === 0 ? 'h-64 xl:h-full xl:min-h-80' : 'aspect-[16/10]'}`} />
                            ) : (
                                <div className={`grid place-items-center bg-gradient-to-br from-sky-100 to-emerald-100 text-sky-700 ${index === 0 ? 'h-64 xl:h-full xl:min-h-80' : 'aspect-[16/10]'}`}><ImageIcon size={48} weight="duotone" /></div>
                            )}
                            <div className="flex min-w-0 flex-col p-5 sm:p-6">
                                <time dateTime={post.published_at ?? undefined} className="text-xs font-bold text-sky-700">{formatDate(post.published_at)}</time>
                                <h2 className="mt-2 text-xl font-black leading-8 text-slate-950">{post.title}</h2>
                                <p className="mt-2 max-h-24 overflow-hidden whitespace-pre-line text-sm leading-6 text-slate-600">{post.description}</p>
                                <button type="button" onClick={() => setSelected(post)} className="mt-5 inline-flex items-center gap-2 self-start rounded-full bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-sky-800 active:scale-[0.98]">อ่านรายละเอียด <ArrowRight size={16} weight="bold" /></button>
                            </div>
                        </article>
                    ))}
                </section>
            )}

            {selected && (
                <div className="fixed inset-0 z-[70] grid place-items-center overflow-y-auto bg-slate-950/60 p-3 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="public-relations-detail-title" onMouseDown={(event) => { if (event.target === event.currentTarget) setSelected(null); }}>
                    <article className="my-auto w-full max-w-3xl overflow-hidden rounded-3xl bg-white shadow-2xl">
                        {selected.image_url && <img src={withAppBasePath(selected.image_url)} alt={`ภาพข่าว ${selected.title}`} className="max-h-[48vh] w-full object-cover" />}
                        <div className="p-5 sm:p-7">
                            <div className="flex items-start justify-between gap-4">
                                <div>
                                    <time dateTime={selected.published_at ?? undefined} className="text-xs font-bold text-sky-700">เผยแพร่ {formatDate(selected.published_at)}</time>
                                    <h2 id="public-relations-detail-title" className="mt-2 text-2xl font-black leading-9 text-slate-950">{selected.title}</h2>
                                </div>
                                <button type="button" onClick={() => setSelected(null)} className="grid size-10 shrink-0 place-items-center rounded-full bg-slate-100 text-slate-600 hover:bg-slate-200" aria-label="ปิดรายละเอียดข่าว"><X size={20} weight="bold" /></button>
                            </div>
                            <p className="mt-5 whitespace-pre-line text-sm leading-7 text-slate-700 sm:text-base">{selected.description}</p>
                        </div>
                    </article>
                </div>
            )}
        </div>
    );
}
