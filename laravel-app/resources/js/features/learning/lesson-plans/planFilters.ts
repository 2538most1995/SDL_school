export type PlanScope = 'subject' | 'all_subjects';
export const planScopeLabels: Record<PlanScope, string> = { subject: 'แผนรายวิชา', all_subjects: 'แผนรวมทุกวิชา' };
type FilterablePlan = { title: string; course: string; timing: string; teacher_name?: string; plan_scope?: string; raw?: { level?: string; plan_scope?: string } };

export function planScope(plan: Pick<FilterablePlan, 'plan_scope' | 'raw'>): PlanScope {
    return (plan.plan_scope ?? plan.raw?.plan_scope) === 'all_subjects' ? 'all_subjects' : 'subject';
}

export function filterLessonPlans<T extends FilterablePlan>(plans: T[], filters: { level: string; scope: PlanScope | ''; search: string }): T[] {
    const needle = filters.search.trim().toLocaleLowerCase('th');
    return plans.filter(plan => (!filters.level || String(plan.raw?.level) === filters.level)
        && (!filters.scope || planScope(plan) === filters.scope)
        && (!needle || `${plan.title} ${plan.course} ${plan.teacher_name || ''} ${plan.timing} ${planScopeLabels[planScope(plan)]}`.toLocaleLowerCase('th').includes(needle)));
}
