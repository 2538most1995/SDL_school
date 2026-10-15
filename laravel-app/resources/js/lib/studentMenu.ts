import {
    BookOpenText, Books, CalendarBlank, ChartBar, CheckSquare, Clock, GraduationCap,
    Heart, Newspaper, Notebook, PaintBrush, Sparkle, Student, Trophy, User,
} from '@phosphor-icons/react';

export type StudentMenuItem = {
    key: string;
    label: string;
    description: string;
    route: string;
    icon: string;
    tone: 'blue' | 'violet' | 'rose' | 'teal' | 'amber' | 'sky' | 'emerald' | 'indigo';
    visible: boolean;
};

const icons = {
    student: Student, planet: GraduationCap, assignment: BookOpenText, folder: Books,
    newspaper: Newspaper, clock: Clock, clipboard: CheckSquare, medal: Trophy,
    chart: ChartBar, sparkle: Sparkle, heart: Heart, calendar: CalendarBlank,
    notebook: Notebook, trophy: Trophy, user: User, 'paint-brush': PaintBrush,
};

export function studentMenuIcon(name: string) {
    return icons[name as keyof typeof icons] ?? Books;
}
