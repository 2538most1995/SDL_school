export type RegistrationCreditPolicy = {
    compulsory_earned: number;
    elective_earned: number;
    compulsory_selected: number;
    elective_selected: number;
    total_selected: number;
    projected_compulsory: number;
    projected_elective: number;
    compulsory_required: number;
    elective_required: number;
    is_credit_complete: boolean;
    is_potential_graduate: boolean;
    can_complete_with_new_registration?: boolean;
    new_registration_plan?: {
        compulsory_subjects: Array<{ code: string; name: string; credits: number }>;
        compulsory_credits: number;
        elective_credits_needed: number;
        total_credits_needed: number;
    } | null;
    uses_final_term_limit: boolean;
    is_active_student: boolean;
    regular_limit: number;
    final_term_limit: number;
    applicable_limit: number;
    exceeds_limit: boolean;
    excess_credits: number;
};

export type RegistrationCreditSubject = {
    code: string;
    credits: number;
    registered: boolean;
    transferred: boolean;
    course_status?: { status?: string };
};

function sumUnique(
    subjects: RegistrationCreditSubject[],
    include: (subject: RegistrationCreditSubject) => boolean,
): number {
    const seen = new Set<string>();

    return Math.round(subjects.reduce((total, subject) => {
        const code = subject.code.trim();
        if (!code || seen.has(code) || !include(subject)) return total;
        seen.add(code);
        return total + Math.max(0, Number(subject.credits) || 0);
    }, 0) * 100) / 100;
}

function isAlreadyCompleted(subject: RegistrationCreditSubject): boolean {
    return subject.course_status?.status === 'passed' || subject.course_status?.status === 'transferred';
}

export function evaluateLiveRegistrationPolicy(
    policy: RegistrationCreditPolicy,
    compulsorySubjects: RegistrationCreditSubject[],
    electiveSubjects: RegistrationCreditSubject[],
): RegistrationCreditPolicy {
    const termLoad = (subjects: RegistrationCreditSubject[]) => sumUnique(
        subjects,
        (subject) => subject.registered && !subject.transferred,
    );
    const projectedAddition = (subjects: RegistrationCreditSubject[]) => sumUnique(
        subjects,
        (subject) => (subject.registered || subject.transferred) && !isAlreadyCompleted(subject),
    );

    const compulsorySelected = termLoad(compulsorySubjects);
    const electiveSelected = termLoad(electiveSubjects);
    const totalSelected = Math.round((compulsorySelected + electiveSelected) * 100) / 100;
    const projectedCompulsory = Math.round((policy.compulsory_earned + projectedAddition(compulsorySubjects)) * 100) / 100;
    const projectedElective = Math.round((policy.elective_earned + projectedAddition(electiveSubjects)) * 100) / 100;
    const compEarned = Number(policy.compulsory_earned) || 0;
    const compRequired = Number(policy.compulsory_required) || 0;
    const elecEarned = Number(policy.elective_earned) || 0;
    const elecRequired = Number(policy.elective_required) || 0;

    const isCreditComplete = compEarned >= compRequired && elecEarned >= elecRequired;
    const isPotentialGraduate = !isCreditComplete
        && (policy.can_complete_with_new_registration === true
            || (projectedCompulsory >= compRequired
                && projectedElective >= elecRequired));
    const usesFinalTermLimit = isCreditComplete || isPotentialGraduate;
    const applicableLimit = usesFinalTermLimit ? policy.final_term_limit : policy.regular_limit;
    const excessCredits = Math.round(Math.max(0, totalSelected - applicableLimit) * 100) / 100;

    return {
        ...policy,
        compulsory_selected: compulsorySelected,
        elective_selected: electiveSelected,
        total_selected: totalSelected,
        projected_compulsory: projectedCompulsory,
        projected_elective: projectedElective,
        is_credit_complete: isCreditComplete,
        is_potential_graduate: isPotentialGraduate,
        uses_final_term_limit: usesFinalTermLimit,
        applicable_limit: applicableLimit,
        exceeds_limit: excessCredits > 0,
        excess_credits: excessCredits,
    };
}
