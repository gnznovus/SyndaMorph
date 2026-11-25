'use strict';

const dns = require('node:dns').promises;
const http = require('node:http');
const https = require('node:https');
const zlib = require('node:zlib');

const RESOURCE_LIMIT = 6_000_000;
const FORWARD_HEADERS = ['accept', 'accept-language', 'user-agent', 'origin', 'referer'];

// The archive browser has a dead proxy. The service supplies only individually
// checked, public, allowlisted, credential-free responses to intercepted reads.
async function referenceFetch(target, request, isPublic) {
    const records = await dns.resolve4(target.hostname);
    if (!records.length || !records.every(isPublic)) throw new Error('Non-public source host.');
    const address = records[0];
    const headers = Object.fromEntries(FORWARD_HEADERS.flatMap(name => {
        const value = request.headers()[name];
        return value ? [[name, value]] : [];
    }));
    const driver = target.protocol === 'https:' ? https : http;
    return new Promise((resolve, reject) => {
        const options = { method: request.method(), headers, agent: false,
            lookup: (_hostname, opts, done) => opts.all ? done(null, [{ address, family: 4 }]) : done(null, address, 4) };
        const connection = driver.request(target, options, incoming => {
            const status = incoming.statusCode;
            if (status >= 300 && status < 400) {
                incoming.resume();
                reject(new Error('Upstream redirects are not followed by reference capture.'));
                return;
            }
            let total = 0;
            const parts = [];
            incoming.on('data', part => {
                total += part.length;
                if (total > RESOURCE_LIMIT) {
                    incoming.destroy(new Error('Capture resource exceeds the byte limit.'));
                } else parts.push(part);
            });
            incoming.on('error', reject);
            incoming.on('end', () => {
                try {
                    let body = Buffer.concat(parts);
                    const encoding = incoming.headers['content-encoding'];
                    const limit = { maxOutputLength: RESOURCE_LIMIT };
                    if (body.length && encoding === 'gzip') body = zlib.gunzipSync(body, limit);
                    else if (body.length && encoding === 'br') body = zlib.brotliDecompressSync(body, limit);
                    else if (body.length && encoding === 'deflate') body = zlib.inflateSync(body, limit);
                    else if (body.length && encoding && encoding !== 'identity') throw new Error('Unsupported source encoding.');
                    const responseHeaders = { ...incoming.headers };
                    for (const name of ['set-cookie','connection','keep-alive','transfer-encoding','content-encoding','content-length',
                        'alt-svc','report-to','nel','clear-site-data']) delete responseHeaders[name];
                    resolve({ status, headers: responseHeaders, body });
                } catch (error) { reject(error); }
            });
        });
        connection.on('error', reject);
        const timer = setTimeout(() => connection.destroy(new Error('Capture resource exceeded its time limit.')), 8500);
        connection.once('close', () => clearTimeout(timer));
        connection.end();
    });
}

module.exports = { referenceFetch };
