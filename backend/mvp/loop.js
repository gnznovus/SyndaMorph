'use strict';
const { assertPlan } = require('./plan');

const MAX_REPAIRS = 5;
function normalizeValidation(result) {
    if (!result || typeof result !== 'object' || !Number.isFinite(result.score) ||
        result.score < 0 || result.score > 100 || !Array.isArray(result.failures) ||
        result.failures.some((item) => typeof item !== 'string')) {
        throw new Error('Validator must return a score (0-100) and failure list.');
    }
    return { score: result.score, failures: [...result.failures], passed: result.passed === true };
}

/**
 * A model never publishes or executes code here. All candidate plans are
 * schema-checked, compiled by a trusted adapter, and evaluated independently.
 *
 * Adapters: compile(plan) => candidate, validate(candidate, reference) =>
 * {score, failures, passed}, repair({plan, failures, attempt}) => new plan.
 */
async function runFidelityLoop({ reference, initialPlan, compile, validate, repair = null, maxRepairs = MAX_REPAIRS }) {
    if (!reference || typeof compile !== 'function' || typeof validate !== 'function' ||
        (repair !== null && typeof repair !== 'function')) throw new TypeError('Missing pipeline adapter.');
    if (!Number.isSafeInteger(maxRepairs) || maxRepairs < 0 || maxRepairs > MAX_REPAIRS) {
        throw new RangeError('Repair budget must be between 0 and 5.');
    }
    let plan = structuredClone(assertPlan(initialPlan));
    let best = null;
    const history = [];
    for (let attempt = 0; attempt <= maxRepairs; attempt++) {
        const candidate = await compile(structuredClone(plan));
        const verdict = normalizeValidation(await validate(candidate, reference));
        const accepted = verdict.passed && verdict.failures.length === 0;
        history.push({ attempt, score: verdict.score, accepted, failures: verdict.failures });
        // Validity is stronger than score: never return an unaccepted candidate
        // over an accepted one, and preserve the best score within each class.
        if (!best || (accepted && !best.accepted) ||
            (accepted === best.accepted && verdict.score > best.score)) {
            best = { plan: structuredClone(plan), candidate, score: verdict.score, accepted };
        }
        if (accepted) return { status: 'passed', ...best, history };
        if (attempt === maxRepairs || !repair) break;
        const next = await repair({
            plan: structuredClone(plan), failures: [...verdict.failures], attempt: attempt + 1,
            score: verdict.score,
        });
        if (!next) break;
        plan = structuredClone(assertPlan(next));
    }
    return { status: 'needs-review', ...best, history };
}

module.exports = { MAX_REPAIRS, runFidelityLoop };
