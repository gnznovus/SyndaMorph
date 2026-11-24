'use strict';

const PROTOCOL = 1;
const DEFAULT_VIEWPORTS = [1440, 768, 375];
const MAX_VIEWPORTS = 5;
const HEIGHT = 900;
// 300,000 JPEG bytes become at most 400,000 base64 bytes. Reserve a
// separate per-view allowance for serialized DOM and accessibility data.
const MAX_JPEG = 300000;
const MAX_SCREENSHOT = 400000;
const MAX_METADATA = 320000;
const MAX_RESPONSE = MAX_VIEWPORTS * (MAX_SCREENSHOT + MAX_METADATA) + 2048;
const MAX_COMPARE_REQUEST = 2 * MAX_VIEWPORTS * MAX_SCREENSHOT + 4096;

class RendererError extends Error {
    constructor(code, status) {
        super(code);
        this.code = code;
        this.status = status;
    }
}

function validViewports(widths) {
    return Array.isArray(widths) && widths.length >= 1 && widths.length <= MAX_VIEWPORTS &&
        new Set(widths).size === widths.length && widths.every(width => Number.isInteger(width) && width >= 320 && width <= 1920);
}

module.exports = { PROTOCOL, DEFAULT_VIEWPORTS, HEIGHT, MAX_JPEG, MAX_SCREENSHOT, MAX_METADATA,
    MAX_RESPONSE, MAX_COMPARE_REQUEST, RendererError, validViewports };
