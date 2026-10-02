import assert from 'node:assert/strict';
import test from 'node:test';
import { evaluateLiveRegistrationPolicy } from '../../resources/js/features/learning/registrationCreditPolicy.ts';

const basePolicy = {
    compulsory_earned: 35,
    elective_earned: 11,
    compulsory_selected: 0,
    elective_selected: 0,
    total_selected: 0,
    projected_compulsory: 35,
    projected_elective: 11,
    compulsory_required: 36,
    elective_required: 12,
    is_credit_complete: false,
    is_potential_graduate: false,
    uses_final_term_limit: false,
    is_active_student: true,
    regular_limit: 14,
    final_term_limit: 17,
    applicable_limit: 14,
    exceeds_limit: false,
    excess_credits: 0,
};

test('a student meeting both credit groups receives the final-term limit', () => {
    const policy = evaluateLiveRegistrationPolicy(basePolicy, [
        { code: 'บังคับ-1', credits: 1, registered: true, transferred: false },
    ], [
        { code: 'เลือก-1', credits: 16, registered: true, transferred: false },
    ]);

    assert.equal(policy.is_potential_graduate, true);
    assert.equal(policy.is_credit_complete, false);
    assert.equal(policy.applicable_limit, 17);
    assert.equal(policy.total_selected, 17);
    assert.equal(policy.exceeds_limit, false);
});

test('the interface reports an over-limit final-term registration', () => {
    const policy = evaluateLiveRegistrationPolicy(basePolicy, [
        { code: 'บังคับ-1', credits: 1, registered: true, transferred: false },
    ], [
        { code: 'เลือก-1', credits: 17, registered: true, transferred: false },
    ]);

    assert.equal(policy.is_potential_graduate, true);
    assert.equal(policy.exceeds_limit, true);
    assert.equal(policy.excess_credits, 1);
});

test('meeting only compulsory credits keeps the regular-term limit', () => {
    const policy = evaluateLiveRegistrationPolicy({
        ...basePolicy,
        compulsory_earned: 36,
        elective_earned: 0,
    }, [
        { code: 'บังคับ-1', credits: 15, registered: true, transferred: false },
    ], []);

    assert.equal(policy.is_potential_graduate, false);
    assert.equal(policy.applicable_limit, 14);
    assert.equal(policy.exceeds_limit, true);
});

test('transferred subjects help the graduation projection but not term load', () => {
    const policy = evaluateLiveRegistrationPolicy(basePolicy, [
        { code: 'บังคับ-1', credits: 1, registered: false, transferred: true },
    ], [
        { code: 'เลือก-1', credits: 1, registered: false, transferred: true },
    ]);

    assert.equal(policy.is_potential_graduate, true);
    assert.equal(policy.total_selected, 0);
    assert.equal(policy.exceeds_limit, false);
});

test('completed earned credits use the double-star state instead of potential graduate', () => {
    const policy = evaluateLiveRegistrationPolicy({
        ...basePolicy,
        compulsory_earned: 36,
        elective_earned: 12,
    }, [], []);

    assert.equal(policy.is_credit_complete, true);
    assert.equal(policy.is_potential_graduate, false);
    assert.equal(policy.uses_final_term_limit, true);
    assert.equal(policy.applicable_limit, 17);
});
