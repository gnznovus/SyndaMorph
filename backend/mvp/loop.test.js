'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { assertPlan } = require('./plan');
const { ComponentRegistry } = require('./registry');
const { runFidelityLoop } = require('./loop');

const fixture = () => ({
    schemaVersion: 1,
    sectionId: 'hotel-hero',
    components: [{ id: 'hero-title', kind: 'native', block: 'core/heading', contentRefs: ['main h1'] }],
    viewports: {
        desktop: { layout: 'stack', order: ['hero-title'] },
        mobile: { layout: 'stack', order: ['hero-title'] },
    },
});

test('layout plans require stable source-linked blocks and explicit mobile composition', () => {
    assert.equal(assertPlan(fixture()).sectionId, 'hotel-hero');
    const plan = fixture(); delete plan.viewports.mobile;
    assert.throws(() => assertPlan(plan), /mobile/);
    const raw = fixture(); raw.components[0].javascript = 'alert(1)';
    assert.throws(() => assertPlan(raw), /JavaScript/);
    const duplicate = fixture(); duplicate.components.push({ ...duplicate.components[0] });
    assert.throws(() => assertPlan(duplicate), /duplicate/);
    const invalidOrder = fixture(); invalidOrder.viewports.mobile.order = ['missing'];
    assert.throws(() => assertPlan(invalidOrder), /mobile/);
});

test('registry reuses an approved block with minimum extra capabilities', () => {
    const registry = new ComponentRegistry([
        { block: 'syndamorph/carousel', capabilities: ['carousel', 'navigation'], approved: true },
        { block: 'syndamorph/mega-carousel', capabilities: ['carousel', 'navigation', 'autoplay'], approved: true },
        { block: 'syndamorph/unreviewed', capabilities: ['booking'], approved: false },
    ]);
    assert.equal(registry.find(['carousel', 'navigation']).block, 'syndamorph/carousel');
    assert.equal(registry.find(['booking']), null);
    assert.equal(registry.find(['payment']), null);
    assert.throws(() => registry.register({ block: 'syndamorph/carousel', capabilities: ['carousel'] }), /already/);
});

test('repair loop stops on independent validator acceptance', async () => {
    const output = await runFidelityLoop({
        reference: { fixture: 'hero' }, initialPlan: fixture(),
        compile: async (plan) => ({ width: plan.width || 50 }),
        validate: async (candidate) => ({
            score: candidate.width, passed: candidate.width >= 90,
            failures: candidate.width >= 90 ? [] : ['Width differs'],
        }),
        repair: async ({ plan }) => ({ ...plan, width: 95 }),
    });
    assert.equal(output.status, 'passed');
    assert.equal(output.history.length, 2);
    assert.equal(output.candidate.width, 95);
});

test('loop preserves best candidate when later repairs regress', async () => {
    let iteration = 0;
    const output = await runFidelityLoop({
        reference: {}, initialPlan: fixture(), maxRepairs: 2,
        compile: async () => ({ revision: iteration++ }),
        validate: async ({ revision }) => ({
            score: [65, 85, 30][revision], passed: false, failures: ['Visual deviation'],
        }),
        repair: async ({ plan }) => plan,
    });
    assert.equal(output.status, 'needs-review');
    assert.equal(output.score, 85);
    assert.equal(output.candidate.revision, 1);
    assert.equal(output.history.length, 3);
});

test('failed validation never passes on score alone', async () => {
    const output = await runFidelityLoop({
        reference: {}, initialPlan: fixture(), maxRepairs: 0,
        compile: async () => ({}),
        validate: async () => ({ score: 100, passed: true, failures: ['Invalid Gutenberg block'] }),
    });
    assert.equal(output.status, 'needs-review');
    assert.equal(output.accepted, false);
});

test('repair output cannot inject unvalidated blocks or raw executable content', async () => {
    await assert.rejects(() => runFidelityLoop({
        reference: {}, initialPlan: fixture(),
        compile: async () => ({}),
        validate: async () => ({ score: 10, passed: false, failures: ['Mismatch'] }),
        repair: async () => ({ ...fixture(), components: [{
            id: 'script', kind: 'custom', block: 'syndamorph/script',
            contentRefs: [], javascript: 'document.cookie',
        }] }),
    }), /JavaScript/);
});
