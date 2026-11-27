'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { readdirSync, readFileSync } = require('node:fs');
const { createRendererServer, runBrowserWorker } = require('./server');
const { MAX_RESPONSE, MAX_COMPARE_REQUEST } = require('./inspection-contract');
const token = 'renderer-transport-http-test-token-v1';
const auth = { 'x-syndamorph-renderer-token': token };

const inspect = (url, widths = [1440, 768, 375], protocol = 2) => fetch(url + '/inspect', {
    method: 'POST', headers: { 'content-type': 'application/json', ...auth },
    body: JSON.stringify({ html: '<section>fixture</section>', viewports: widths, inspectionProtocol: protocol }),
});

async function serve(t, worker) {
    const server = createRendererServer(worker, token);
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    t.after(() => new Promise(resolve => server.close(resolve)));
    return 'http://127.0.0.1:' + server.address().port;
}

test('renderer authenticates every job before parsing or running Chromium', async t => {
    assert.throws(() => createRendererServer(undefined, ''), /private renderer token/);
    assert.throws(() => createRendererServer(undefined, token + '\n'), /private renderer token/);
    let launches = 0;
    const url = await serve(t, async () => { launches++; return []; });
    const routes = ['render','inspect','compare','crop','check-tabs','check-scroll-strips','check-disclosures',
        'check-image-strip','check-reveal-cards','check-rooms','check-compact-booking'];
    for (const path of routes) {
        for (const headers of [{}, {'x-syndamorph-renderer-token': 'x'.repeat(token.length)}]) {
            const refused = await fetch(url + '/' + path, { method: 'POST', headers, body: 'not JSON' });
            assert.equal(refused.status, 401);
            assert.equal(refused.headers.get('x-syndamorph-inspection-protocol'), null);
            assert.equal(refused.headers.get('x-syndamorph-renderer-build'), null);
            assert.deepEqual(await refused.json(), {error:'unauthorized'});
        }
    }
    assert.equal(launches, 0);
    const health = await fetch(url + '/healthz');
    assert.equal(health.headers.get('x-syndamorph-inspection-protocol'), null);
    assert.equal(health.headers.get('x-syndamorph-renderer-build'), null);
    assert.deepEqual(await health.json(), {status:'ok'});
    const wrongHealth = await fetch(url + '/healthz', {headers:{'x-syndamorph-renderer-token':'wrong'}});
    assert.equal(wrongHealth.status, 401);
    assert.equal(wrongHealth.headers.get('x-syndamorph-renderer-build'), null);
    const unknown = await fetch(url + '/unknown');
    assert.equal(unknown.status, 404);
    assert.equal(unknown.headers.get('x-syndamorph-renderer-build'), null);
});

test('runtime handshake and viewport request validation happen before Chromium', async t => {
    let launches = 0;
    const url = await serve(t, async (_html, widths) => {
        launches++;
        return widths.map(width => ({ width }));
    });
    const health = await fetch(url + '/healthz', {headers:auth});
    const info = await health.json();
    assert.equal(health.headers.get('x-syndamorph-inspection-protocol'), '2');
    assert.match(info.build, /^[a-f0-9]{64}$/);
    assert.equal(info.maxResponseBytes, MAX_RESPONSE);
    const stale = await inspect(url, [375], 999);
    assert.equal(stale.status, 409);
    assert.equal((await stale.json()).error, 'protocol_mismatch');
    for (const invalid of [[375,375], ['375'], null, [1930]]) {
        const response = await inspect(url, invalid);
        assert.equal(response.status, 400);
        assert.equal((await response.json()).error, 'invalid_viewports');
    }
    assert.equal(launches, 0);
    const explicit = await inspect(url, [375, 1680, 820]);
    assert.equal(explicit.headers.get('x-syndamorph-inspection-protocol'), '2');
    assert.equal(explicit.headers.get('x-syndamorph-renderer-build'), info.build);
    const raw = await explicit.text();
    assert.equal(Buffer.byteLength(raw), Number(explicit.headers.get('content-length')));
    assert.deepEqual(JSON.parse(raw).evidence.map(item => item.width), [375,1680,820]);
});

test('concurrent inspection receives a bounded busy response and the slot is released', async t => {
    let release, entered;
    const started = new Promise(resolve => { entered = resolve; });
    const gate = new Promise(resolve => { release = resolve; });
    const url = await serve(t, async (_html, widths) => {
        entered();
        await gate;
        return widths.map(width => ({ width }));
    });
    const first = inspect(url);
    await started;
    try {
        const busy = await inspect(url);
        assert.equal(busy.status, 429);
        assert.equal(busy.headers.get('retry-after'), '1');
        assert.deepEqual(await busy.json(), { error: 'renderer_busy' });
        assert.equal((await fetch(url + '/healthz')).status, 200);
    } finally { release(); }
    assert.equal((await first).status, 200);
    assert.equal((await inspect(url)).status, 200);
});

test('worker timeout, process exit, output cap and malformed evidence are distinguished', async t => {
    const cases = [
        ["setInterval(()=>{},1000)", 'browser_timeout', 504, 150],
        ["process.stderr.write('private fixture');process.exit(2)", 'worker_crash', 503],
        ["process.stdout.write('{')", 'worker_invalid_json', 502],
        ["process.stdout.write(JSON.stringify({evidence:[]}))", 'missing_evidence', 502],
        ["process.stdout.write(JSON.stringify({evidence:[{width:390}]}))", 'viewport_mismatch', 502],
        ["process.stdout.write('x'.repeat("+ (MAX_RESPONSE+1) +"))", 'evidence_limit', 507],
    ];
    for (const [script, category, status, timeout] of cases) {
        await t.test(category, async sub => {
            const url = await serve(sub, (html,widths,signal) => runBrowserWorker(html,widths,signal,
                {workerArgs:['-e',script],timeoutMs:timeout??5000}));
            const response = await inspect(url,[375]);
            assert.equal(response.status,status);
            assert.deepEqual(await response.json(),{error:category});
            assert.equal((await fetch(url+'/healthz')).status,200);
        });
    }
});

test('comparison has its own bounded upload limit and rejects oversized requests', async t => {
    const url = await serve(t);
    const response = await fetch(url + '/compare', {
        method:'POST',headers:auth,body:' '.repeat(MAX_COMPARE_REQUEST+1),
    });
    assert.equal(response.status, 413);
    assert.deepEqual(await response.json(), {error:'request_limit'});
});

test('worker can return verified-width subsets for client recovery', async t => {
    const url = await serve(t,(html,widths,signal)=>runBrowserWorker(html,widths,signal,{
        workerArgs:['-e','console.log(JSON.stringify({evidence:[{width:768},{width:1440}]}))'],
    }));
    const response = await inspect(url);
    assert.equal(response.status,200);
    assert.deepEqual((await response.json()).evidence.map(sample=>sample.width),[768,1440]);
});

test('the renderer credential is not inherited by the browser worker', async t => {
    const previous = process.env.SYNDAMORPH_RENDERER_TOKEN;
    process.env.SYNDAMORPH_RENDERER_TOKEN = token;
    t.after(() => {
        if (previous === undefined) delete process.env.SYNDAMORPH_RENDERER_TOKEN;
        else process.env.SYNDAMORPH_RENDERER_TOKEN = previous;
    });
    const url = await serve(t, (html, widths, signal) => runBrowserWorker(html, widths, signal, {
        workerArgs:['-e', 'console.log(JSON.stringify({evidence:[{width:375,hasToken:"SYNDAMORPH_RENDERER_TOKEN" in process.env}]}))'],
    }));
    const result = await inspect(url, [375]);
    assert.equal(result.status, 200);
    assert.deepEqual((await result.json()).evidence, [{width:375,hasToken:false}]);
});

test('aborting a real Chromium inspection releases its browser processes', {skip:process.platform!=='linux'}, async () => {
    const browsers = () => readdirSync('/proc').filter(name => /^\d+$/.test(name)).filter(pid => {
        try { return /^(?:chrome|chromium)/.test(readFileSync('/proc/'+pid+'/comm','utf8')); }
        catch (_) { return false; }
    });
    const baseline = browsers();
    const controller = new AbortController();
    const result = runBrowserWorker('<section>Cancellation fixture</section>', [1440,768,375],controller.signal)
        .catch(error => error);
    let launched = false;
    try {
        for (let i=0;i<80;i++) {
            if (browsers().some(pid => !baseline.includes(pid))) { launched = true; break; }
            await new Promise(resolve => setTimeout(resolve,25));
        }
        assert.ok(launched, 'The test must abort after launching Chromium');
    } finally { controller.abort(); }
    assert.equal((await result).code,'request_aborted');
    for (let i=0;i<20&&browsers().some(pid=>!baseline.includes(pid));i++)
        await new Promise(resolve => setTimeout(resolve,50));
    assert.deepEqual(browsers().filter(pid=>!baseline.includes(pid)),[], 'Chromium processes leaked after cancellation');
});
