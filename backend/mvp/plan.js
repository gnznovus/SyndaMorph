'use strict';

const VERSION = 1;
const LAYOUTS = new Set(['stack', 'columns', 'grid', 'overlay']);
const ID = /^[a-z][a-z0-9-]{0,79}$/;
const BLOCK = /^[a-z][a-z0-9-]*\/[a-z][a-z0-9-]*$/;

function assertPlan(plan) {
    if (!plan || typeof plan !== 'object' || Array.isArray(plan)) throw new TypeError('Layout plan must be an object.');
    if (plan.schemaVersion !== VERSION) throw new Error('Unsupported layout plan version.');
    if (!ID.test(plan.sectionId || '')) throw new Error('Invalid sectionId.');
    if (!Array.isArray(plan.components) || !plan.components.length || plan.components.length > 100) throw new Error('Invalid components.');
    if (!plan.viewports || typeof plan.viewports !== 'object') throw new Error('Missing viewports.');
    for (const viewport of ['desktop', 'mobile']) {
        const item = plan.viewports[viewport];
        if (!item || !LAYOUTS.has(item.layout) || !Array.isArray(item.order)) throw new Error('Invalid ' + viewport + ' layout.');
    }
    const ids = new Set();
    for (const component of plan.components) {
        if (!component || !ID.test(component.id || '') || ids.has(component.id)) throw new Error('Invalid or duplicate component ID.');
        ids.add(component.id);
        if (!['native', 'custom'].includes(component.kind)) throw new Error('Unsupported component kind.');
        if (!BLOCK.test(component.block || '')) throw new Error('Invalid registered block identifier.');
        if (component.kind === 'native' && !component.block.startsWith('core/')) throw new Error('Native components must reference core blocks.');
        if (component.kind === 'custom' && component.block.startsWith('core/')) throw new Error('Custom components must use a custom namespace.');
        if (!Array.isArray(component.contentRefs) || component.contentRefs.some((ref) => typeof ref !== 'string' || ref.length > 256)) {
            throw new Error('Invalid source content references.');
        }
        if (Object.hasOwn(component, 'html') || Object.hasOwn(component, 'javascript')) {
            throw new Error('Raw HTML or executable JavaScript is not allowed in layout plans.');
        }
    }
    for (const viewport of ['desktop', 'mobile']) {
        const order = plan.viewports[viewport].order;
        if (order.length > ids.size || new Set(order).size !== order.length || order.some((id) => !ids.has(id))) {
            throw new Error('Invalid ' + viewport + ' component order.');
        }
    }
    return plan;
}

module.exports = { VERSION, assertPlan };
