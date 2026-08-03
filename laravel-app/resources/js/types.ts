export type SummaryItem = {
    label: string;
    value: string;
    hint: string;
};

export type UpcomingItem = {
    id: number;
    day: string;
    month: string;
    title: string;
    meta: string;
};

export type PortalModule = {
    name: string;
    status: 'foundation' | 'mapped' | 'security-review';
    route: string;
};

export type AnalyticsDatum = {
    label: string;
    value: number;
};

export type PortalAnalytics = {
    totals: {
        students: number;
        groups: number;
        new_students: number;
    };
    averages: {
        gpax: number | null;
        credits_earned: number | null;
        credits_required: number | null;
        credit_progress_percent: number | null;
        kpch_hours: number | null;
    };
    current_term: string | null;
    by_level: AnalyticsDatum[];
    by_gender: AnalyticsDatum[];
    by_group: AnalyticsDatum[];
    moral: AnalyticsDatum[];
};

export type PortalData = {
    mode: 'demo' | 'production';
    viewer: {
        name: string;
        role: 'student' | 'teacher' | 'admin' | 'super_admin';
        district: string;
    };
    summary: SummaryItem[];
    analytics: PortalAnalytics;
    upcoming: UpcomingItem[];
    modules: PortalModule[];
};

export type ApiResponse<T> = {
    data: T;
    meta: Record<string, unknown>;
};
