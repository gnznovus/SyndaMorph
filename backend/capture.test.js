'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
process.env.SYNDAMORPH_CAPTURE_HOSTS = 'www.syndacast.com';
const { validateURL, publicIPv4 } = require('./capture');
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
