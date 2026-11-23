'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { buildConversation, createSession, POOLS, TOPICS, META, MAX_TURNS } = require('../../plugin/assets/demo-dialogue');

test('three agent pools remain available', () => {
    assert.deepEqual(Object.keys(POOLS).sort(), ['boss', 'designer', 'developer']);
    assert.ok(Object.values(POOLS).every(pool => Object.keys(pool).length === 3));
    assert.deepEqual(TOPICS, ['spacing', 'responsive', 'interaction']);
});

test('keyword matching selects a coherent shared conversation topic', () => {
    const choices = [
        ['Please preserve padding and spacing', 'spacing'],
        ['Our mobile breakpoint must stack columns', 'responsive'],
        ['Button click and hover animation', 'interaction'],
    ];
    for (const [brief, topic] of choices) {
        const messages = buildConversation({ kind: 'html', brief, seed: 11 });
        assert.ok(messages.length >= 9 && messages.length <= MAX_TURNS);
        assert.ok(messages.every(item => item.topic === topic));
        assert.ok(messages[0].action.includes('HTML page'));
        assert.equal(messages.at(-1).intent, 'close');
    }
});

test('short peaceful route terminates based on alignment rather than fixed number', () => {
    const session = createSession({kind:'url', brief:'mobile stacking', seed:10, random: () => .95});
    const messages = [];
    let message;
    while ((message = session.next())) messages.push(message);
    assert.equal(messages.length, 9);
    assert.equal(session.state.resolved, true);
    assert.equal(session.state.aligned, true);
    assert.equal(session.state.forced, false);
    assert.equal(session.next(), null);
});

test('disagreements extend conversation and retain the same topic', () => {
    const session = createSession({kind:'package',brief:'padding gap',seed:10,random:() => .01});
    const messages = [];
    let message;
    while ((message=session.next())) messages.push(message);
    assert.ok(messages.length > 9 && messages.length <= MAX_TURNS);
    assert.ok(messages.some(item=>item.intent === 'challenge'));
    assert.ok(messages.some(item=>item.intent === 'revise'));
    assert.ok(messages.every(item=>item.topic === 'spacing'));
    assert.equal(session.state.resolved,true);
    assert.equal(session.state.forced,false);
    assert.equal(new Set(messages.map(x=>x.action)).size,messages.length);
});

test('seed reproduces branch decisions without paid inference', () => {
    const a=buildConversation({kind:'html',brief:'mobile',seed:42});
    const b=buildConversation({kind:'html',brief:'mobile',seed:42});
    assert.deepEqual(a,b);
});

test('unsupported source, invalid seed and invalid random adapters fail', () => {
    assert.throws(()=>createSession({kind:'other'}),/Unsupported/);
    assert.throws(()=>createSession({seed:-5}),/Invalid seed/);
    assert.throws(()=>createSession({random:4}),/Random function required/);
});

test('selected topic has keyword metadata', () => {
    for (const topic of TOPICS) assert.ok(META[topic].keywords.length >= 4);
});
