'use strict';

const { assertJob, assertPmPlan, assertDesign, assertImplementation } = require('./team-contract');
const { runFidelityLoop } = require('./loop');

/**
 * Backend-only scaffold. Agent adapters are injected explicitly.
 * compile / validate must be independently trusted adapters.
 * No provider calls, code execution, publishing, or WP changes are built in.
 */
async function runTeamPipeline({ job: input, agents, compile, validate, repair = null, maxRepairs = 0 }) {
    const job = assertJob(input);
    if (!agents || typeof agents.pm?.plan !== 'function' || typeof agents.designer?.design !== 'function' ||
        typeof agents.developer?.implement !== 'function' || typeof compile !== 'function' ||
        typeof validate !== 'function') throw new TypeError('Missing team adapters.');
    const events = [];
    const emit = (role, action) => events.push({ step: events.length + 1, role, action });

    const pm = assertPmPlan(await agents.pm.plan(structuredClone(job)), job);
    emit('pm', 'classified');
    const design = assertDesign(await agents.designer.design({
        job: structuredClone(job), assignment: pm.tasks[0].instruction,
        acceptanceCriteria: [...pm.acceptanceCriteria],
    }), job);
    emit('designer', 'handoff');
    const implementation = assertImplementation(await agents.developer.implement({
        job: structuredClone(job), assignment: pm.tasks[1].instruction,
        design: structuredClone(design), acceptanceCriteria: [...pm.acceptanceCriteria],
    }), job);
    emit('developer', 'handoff');
    const checkedRepair = repair === null ? null : details =>
        repair({ ...details, job: structuredClone(job), pm: structuredClone(pm) });

    const verdict = await runFidelityLoop({
        reference: structuredClone(job.source), initialPlan: implementation.layoutPlan,
        compile, validate, repair: checkedRepair, maxRepairs,
    });
    emit('validator', verdict.status);
    return {
        schemaVersion: 1, mode: 'injected-adapters', jobTitle: pm.jobTitle,
        status: verdict.status, plan: structuredClone(verdict.plan),
        candidate: verdict.candidate, accepted: verdict.accepted,
        validation: { score: verdict.score, history: verdict.history },
        handoffs: { pm, design, implementation }, events,
        published: false,
    };
}

module.exports = { runTeamPipeline };
