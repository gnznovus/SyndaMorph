'use strict';

// Registry entries describe approved reusable blocks; they are not executable code.
class ComponentRegistry {
    constructor(entries = []) {
        this.entries = new Map();
        for (const entry of entries) this.register(entry);
    }
    register(entry) {
        if (!entry || !/^[a-z][a-z0-9-]*\/[a-z][a-z0-9-]*$/.test(entry.block || '') ||
            !Array.isArray(entry.capabilities) || entry.capabilities.length === 0 ||
            entry.capabilities.some((item) => typeof item !== 'string' || !/^[a-z][a-z0-9-]*$/.test(item))) {
            throw new Error('Invalid component registry entry.');
        }
        if (this.entries.has(entry.block)) throw new Error('Component already registered: ' + entry.block);
        this.entries.set(entry.block, Object.freeze({
            block: entry.block, capabilities: Object.freeze([...new Set(entry.capabilities)]),
            version: entry.version || '1.0.0', approved: entry.approved === true,
        }));
    }
    find(capabilities) {
        if (!Array.isArray(capabilities) || !capabilities.length) throw new Error('Capabilities required.');
        const requested = new Set(capabilities);
        return [...this.entries.values()].filter((entry) => entry.approved &&
            [...requested].every((item) => entry.capabilities.includes(item)))
            .sort((a, b) => a.capabilities.length - b.capabilities.length || a.block.localeCompare(b.block))[0] || null;
    }
    list() { return [...this.entries.values()]; }
}

module.exports = { ComponentRegistry };
