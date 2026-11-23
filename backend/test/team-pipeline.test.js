'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { runTeamPipeline } = require('../mvp/team-pipeline');

const job = { brief: 'Convert the hero to editable native blocks.',
    source: { kind: 'html', sectionId: 'hero', label: 'Hero' } };
const layoutPlan = { schemaVersion: 1, sectionId: 'hero',
    components: [{ id: 'headline', kind: 'native', block: 'core/heading', contentRefs: ['hero-title'] }],
    viewports: { desktop: { layout: 'stack', order: ['headline'] },
        mobile: { layout: 'stack', order: ['headline'] } } };

function fixture(overrides = {}) {
    return {
        job,
        agents: {
            pm: { plan: async () => ({ schemaVersion: 1, sectionId: 'hero', jobTitle: 'Hero Conversion',
                complexity: 'simple', tasks: [
                    { role: 'designer', instruction: 'Plan hero.' },
                    { role: 'developer', instruction: 'Implement hero.' },
                ], acceptanceCriteria: ['One editable heading'] }) },
            designer: { design: async () => ({ layoutPlan, rationale: ['Use native heading'] }) },
            developer: { implement: async () => ({
                layoutPlan, implementationNotes: ['Native block only'], customBlockRequests: [],
            }) },
        },
        compile: async plan => ({ blocks: plan.components.map(component => component.block) }),
        validate: async candidate => ({
            score: 100, failures: [], passed: candidate.blocks.includes('core/heading'),
        }),
        ...overrides,
    };
}

test('fixture adapters produce validated but unpublished output', async () => {
    const result = await runTeamPipeline(fixture());
    assert.equal(result.status, 'passed');
    assert.equal(result.accepted, true);
    assert.equal(result.published, false);
    assert.equal(result.jobTitle, 'Hero Conversion');
    assert.deepEqual(result.events.map(event => event.role), ['pm', 'designer', 'developer', 'validator']);
});

test('failed validation never silently accepts or publishes', async () => {
    const result = await runTeamPipeline(fixture({
        validate: async () => ({ score: 95, failures: ['Missing source text'], passed: false }),
    }));
    assert.equal(result.status, 'needs-review');
    assert.equal(result.accepted, false);
    assert.equal(result.published, false);
});

test('invalid PM task assignments stop before Designer', async () => {
    const args = fixture();
    let called = false;
    args.agents.pm.plan = async () => ({ schemaVersion: 1, sectionId: 'hero', jobTitle: 'Hero',
        complexity: 'simple', tasks: [
            { role: 'developer', instruction: 'Wrong order' },
            { role: 'designer', instruction: 'Wrong order' },
        ], acceptanceCriteria: ['Native'] });
    args.agents.designer.design = async () => { called = true; return {}; };
    await assert.rejects(runTeamPipeline(args), /PM assignments/);
    assert.equal(called, false);
});

test('source identity mismatch in handoffs is rejected', async () => {
    const args = fixture();
    args.agents.designer.design = async () => ({
        layoutPlan: { ...layoutPlan, sectionId: 'another' }, rationale: ['Changed'],
    });
    await assert.rejects(runTeamPipeline(args), /Design section identity mismatch/);
    const args2 = fixture();
    args2.agents.developer.implement = async () => ({
        layoutPlan: { ...layoutPlan, sectionId: 'another' }, implementationNotes: ['Changed'],
    });
    await assert.rejects(runTeamPipeline(args2), /Developer section identity mismatch/);
});

test('without injected adapters, do not manufacture AI results', async () => {
    await assert.rejects(runTeamPipeline({ job }), /Missing team adapters/);
});

test('bounded repair is delegated to existing fidelity loop', async () => {
    let count = 0;
    const result = await runTeamPipeline(fixture({
        validate: async () => ++count === 1
            ? { score: 25, failures: ['Layout mismatch'], passed: false }
            : { score: 99, failures: [], passed: true },
        repair: async ({ plan }) => plan, maxRepairs: 1,
    }));
    assert.equal(count, 2);
    assert.equal(result.status, 'passed');
    assert.equal(result.validation.history.length, 2);
});
