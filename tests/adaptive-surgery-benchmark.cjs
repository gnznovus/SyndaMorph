'use strict';
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const { join } = require('node:path');

const renderer = process.env.SYNDA_RENDER_TEST_URL || 'http://127.0.0.1:3999';
const rendererToken = process.env.SYNDAMORPH_RENDERER_TOKEN;
const php = process.env.SYNDA_PHP || 'php';
const phpArgs = JSON.parse(process.env.SYNDA_PHP_ARGS || '[]');
const trials = Number(process.env.SYNDA_BENCHMARK_TRIALS || 2);

function runPhp(mode, data) {
    const result = spawnSync(php, [...phpArgs, join(__dirname, 'adaptive-surgery-benchmark.php'), mode], {
        input: data === undefined ? undefined : JSON.stringify(data),
        encoding: 'utf8',
        maxBuffer: 16 * 1024 * 1024,
    });
    if (result.error) throw result.error;
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return JSON.parse(result.stdout);
}

async function request(route, data) {
    const response = await fetch(renderer + route, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-SyndaMorph-Renderer-Token': rendererToken },
        body: JSON.stringify(data), signal: AbortSignal.timeout(60000),
    });
    const output = await response.json();
    assert.equal(response.status, 200, JSON.stringify(output));
    return output;
}

(async () => {
    assert.ok(rendererToken, 'Set SYNDAMORPH_RENDERER_TOKEN to the local renderer fixture credential');
    assert.ok(Number.isInteger(trials) && trials >= 1 && trials <= 5, 'Expected one to five trials');
    const fixtures = runPhp('prepare');
    let previous;
    for (let trial = 1; trial <= trials; trial++) {
        const data = {};
        for (const [name, fixture] of Object.entries(fixtures)) {
            const scan = html => request('/inspect', { html, viewports: fixture.widths }).then(r => r.evidence);
            const reference = await scan(fixture.reference);
            const before = await scan(fixture.before);
            const after = await scan(fixture.after);
            const compare = native => request('/compare', { reference, native }).then(r => r.comparison);
            const withoutImages = samples => samples.map(({ screenshot, ...evidence }) => evidence);
            data[name] = { reference: withoutImages(reference), before: withoutImages(before), after: withoutImages(after),
                beforePixels: await compare(before), afterPixels: await compare(after) };
        }
        const result = runPhp('score', data);
        console.log(JSON.stringify({ trial, modelCalls: 0, results: result }));
        for (const [name, value] of Object.entries(result)) {
            assert.ok(value.accepted, name + ': ' + value.reason);
            assert.ok(value.retainedImprovement >= 0.15, name + ' failed the 15% retained improvement target');
        }
        if (previous) assert.deepEqual(result, previous, 'Deterministic benchmark changed between trials');
        previous = result;
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
