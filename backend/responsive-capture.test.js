'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { reconcileResponsive } = require('./responsive-capture');

const region = (sourceRef, label, text, options = {}) => ({ sourceRef, label,
    kind: options.kind || 'content', inventory: { text: [text], anchors: options.anchors || [],
        compactNavigation: options.compactNavigation || false } });

test('keeps discovery order, matches different DOM variants, and retains unique required regions', () => {
    const desktop = [region('#header', 'Site header', 'Hotel menu', { kind: 'header' }),
        region('#special', 'Special offer', 'Special promotion', { kind: 'overlay' }),
        region('#full-rooms', 'Rooms', 'Premium Double hotel king suite sanctuary skyline'),
        region('#footer', 'Site footer', 'Contact information', { kind: 'footer' })];
    const compact = [region('#compact-menu', 'Menu', 'Menu', { compactNavigation: true }),
        region('#booking', 'Check availability', 'Guests dates stay nights'),
        region('#compact-rooms', 'Stay', 'King suite sanctuary skyline'),
        region('#compact-footer', 'Site footer', 'Contact')];
    const input = [{ width: 1440, sections: desktop }, { width: 768, sections: compact }, { width: 375, sections: compact }];
    const url = 'https://www.example.test/';
    const output = reconcileResponsive(url, input);
    assert.deepEqual(output.map(entry => entry.sourceRef), ['#header', '#special', '#booking', '#full-rooms', '#footer']);
    assert.deepEqual(Object.keys(output[1].variants), ['1440']);
    assert.deepEqual(Object.keys(output[2].variants), ['375', '768']);
    assert.equal(output[3].variants[375].sourceRef, '#compact-rooms');
    assert.deepEqual(output.map(entry => entry.id), reconcileResponsive(url, input).map(entry => entry.id));
    assert.equal(new Set(output.map(entry => entry.id)).size, output.length);
});

test('keeps ambiguous variants visible without assigning them to an unrelated desktop region', () => {
    const candidate = region('#compact', 'Similar', 'First hand authentic experience');
    const desktop = [region('#one', 'Similar', 'First hand authentic experience'),
        region('#two', 'Similar', 'First hand authentic experience')];
    const output = reconcileResponsive('https://www.example.test/',[
        { width: 1440, sections: desktop }, { width: 768, sections: [candidate] }, { width: 375, sections: [candidate] },
    ]);
    assert.equal(output.length, 3);
    assert.deepEqual(output.map(entry => Object.keys(entry.variants)), [['1440'], ['1440'], ['375', '768']]);
});
