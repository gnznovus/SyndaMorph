'use strict';

const { assertPlan } = require('./plan');
const ROLES = Object.freeze(['designer', 'developer']);
const ID = /^[a-z][a-z0-9-]{0,79}$/;

function text(value, label, max = 240) {
    if (typeof value !== 'string' || !value.trim() || value.length > max || /[\x00-\x08\x0b\x0c\x0e-\x1f]/.test(value)) {
        throw new TypeError('Invalid ' + label + '.');
    }
    return value.trim();
}

function strings(items, label, min = 1, max = 12) {
    if (!Array.isArray(items) || items.length < min || items.length > max) throw new TypeError('Invalid ' + label + '.');
    return items.map(item => text(item, label + ' entry'));
}

function assertJob(input) {
    if (!input || typeof input !== 'object' || Array.isArray(input)) throw new TypeError('Job required.');
    const source = input.source;
    if (!source || !['url', 'html', 'package'].includes(source.kind) || !ID.test(source.sectionId || '')) {
        throw new TypeError('Invalid source reference.');
    }
    return { brief: text(input.brief, 'production brief', 4000),
        source: { kind: source.kind, sectionId: source.sectionId, label: text(source.label, 'source label', 200) } };
}

function assertPmPlan(value, job) {
    if (!value || typeof value !== 'object' || Array.isArray(value) || value.schemaVersion !== 1 ||
        value.sectionId !== job.source.sectionId || !['simple', 'moderate', 'complex'].includes(value.complexity)) {
        throw new TypeError('Invalid PM plan.');
    }
    if (!Array.isArray(value.tasks) || value.tasks.length !== ROLES.length ||
        value.tasks.some((task, index) => !task || task.role !== ROLES[index] ||
            typeof task.instruction !== 'string' || !task.instruction.trim() || task.instruction.length > 1000)) {
        throw new TypeError('Invalid PM assignments.');
    }
    return { schemaVersion: 1, sectionId: job.source.sectionId, jobTitle: text(value.jobTitle, 'job title', 120),
        complexity: value.complexity,
        tasks: value.tasks.map(task => ({ role: task.role, instruction: task.instruction.trim() })),
        acceptanceCriteria: strings(value.acceptanceCriteria, 'acceptance criteria') };
}

function assertDesign(value, job) {
    if (!value || typeof value !== 'object' || Array.isArray(value)) throw new TypeError('Invalid design handoff.');
    const layoutPlan = structuredClone(assertPlan(value.layoutPlan));
    if (layoutPlan.sectionId !== job.source.sectionId) throw new Error('Design section identity mismatch.');
    return { layoutPlan, rationale: strings(value.rationale, 'design rationale', 1, 10) };
}

function assertImplementation(value, job) {
    if (!value || typeof value !== 'object' || Array.isArray(value)) throw new TypeError('Invalid developer handoff.');
    const layoutPlan = structuredClone(assertPlan(value.layoutPlan));
    if (layoutPlan.sectionId !== job.source.sectionId) throw new Error('Developer section identity mismatch.');
    // Custom block requests remain descriptions; never execute model-generated code.
    return { layoutPlan, implementationNotes: strings(value.implementationNotes, 'implementation notes'),
        customBlockRequests: strings(value.customBlockRequests || [], 'custom block requests', 0, 10) };
}

module.exports = { assertJob, assertPmPlan, assertDesign, assertImplementation };
