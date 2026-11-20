'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
process.env.SYNDAMORPH_CAPTURE_HOSTS = 'www.syndacast.com';
process.env.SYNDAMORPH_RESOURCE_HOSTS = 'unpkg.com,cdn.jsdelivr.net,fonts.googleapis.com,fonts.gstatic.com';
const { validateURL, validateResourceURL, publicIPv4 } = require('./capture');
test('allowlisted public URL only', () => {
    assert.equal(validateURL('https://www.syndacast.com/2026/BKKGOPrototype1/index.html').hostname, 'www.syndacast.com');
    for (const url of ['file:///etc/passwd', 'http://127.0.0.1/', 'https://localhost/', 'https://user:pass@www.syndacast.com/', 'https://evil.com/']) {
        assert.throws(() => validateURL(url));
    }
});
test('deny private IPv4 ranges', () => {
    for (const ip of ['127.0.0.1', '10.1.2.3', '192.168.1.1', '169.254.169.254', '172.16.0.1', '100.64.1.1']) assert.equal(publicIPv4(ip), false);
    assert.equal(publicIPv4('8.8.8.8'), true);
});

test('CDN assets may load but do not authorize page navigation', () => {
    assert.equal(validateResourceURL('https://unpkg.com/react@18.3.1/umd/react.production.min.js').hostname, 'unpkg.com');
    assert.equal(validateResourceURL('https://fonts.gstatic.com/font.woff2').hostname, 'fonts.gstatic.com');
    assert.throws(() => validateURL('https://unpkg.com/react@18.3.1/umd/react.production.min.js'));
    for (const url of ['http://169.254.169.254/latest/meta-data', 'https://evil.invalid/script.js', 'file:///tmp/a.js']) {
        assert.throws(() => validateResourceURL(url));
    }
});
