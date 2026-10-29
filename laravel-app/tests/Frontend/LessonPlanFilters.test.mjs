import test from 'node:test';
import assert from 'node:assert/strict';
import { filterLessonPlans, planScope } from '../../resources/js/features/learning/lesson-plans/planFilters.ts';

const plans = [1, 2, 3].flatMap(level => ['all_subjects', 'subject'].map(scope => ({
    id: `${level}:${scope}`, title: scope === 'all_subjects' ? `เอกสารรวม ระดับ ${level}` : 'วิทยาศาสตร์',
    course: scope === 'all_subjects' ? 'รวมทุกวิชา' : `SCI${level}`, teacher_name: 'ครูทดสอบ',
    timing: '2/2569', plan_scope: scope, raw: { level: String(level) },
})));

test('combined plans filter independently by education level without pulling in subject plans', () => {
    for (const level of ['1', '2', '3']) {
        assert.deepEqual(filterLessonPlans(plans, { level, scope: 'all_subjects', search: '' }).map(plan => plan.id), [`${level}:all_subjects`]);
    }
    assert.equal(filterLessonPlans(plans, { level: '', scope: 'all_subjects', search: '' }).length, 3);
});

test('legacy plans remain subject plans and filters do not hide them by default', () => {
    const legacy = { id: 'legacy', title: 'แผนเดิม', course: 'SCI1', timing: '1/2569', raw: { level: '1' } };
    assert.equal(planScope(legacy), 'subject');
    assert.equal(planScope({ raw: { plan_scope: 'all_subjects' } }), 'all_subjects');
    assert.equal(filterLessonPlans([...plans, legacy], { level: '', scope: '', search: '' }).length, 7);
    assert.deepEqual(filterLessonPlans([...plans, legacy], { level: '1', scope: 'subject', search: 'แผนเดิม' }).map(plan => plan.id), ['legacy']);
});

test('search combines plan scope with course, term and author metadata', () => {
    assert.equal(filterLessonPlans(plans, { level: '', scope: '', search: 'แผนรวม' }).length, 3);
    assert.deepEqual(filterLessonPlans(plans, { level: '2', scope: '', search: 'sci2' }).map(plan => plan.id), ['2:subject']);
    assert.equal(filterLessonPlans(plans, { level: '3', scope: 'all_subjects', search: 'ครูทดสอบ' }).length, 1);
    assert.equal(filterLessonPlans(plans, { level: '3', scope: 'all_subjects', search: '1/2569' }).length, 0);
});
