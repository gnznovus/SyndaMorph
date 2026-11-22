'use strict';

// Railway Purr communication POC. All upstream credentials stay in this backend.
const roles = [
    { id: 'boss', name: 'Boss Agent', message: job => 'You are the Boss Agent. Acknowledge and delegate this demonstration job: ' + job },
    { id: 'designer', name: 'Designer Agent', message: job => 'You are the Designer Agent. Acknowledge the design assignment for: ' + job },
    { id: 'developer', name: 'Developer Agent', message: job => 'You are the Developer Agent. Acknowledge the implementation assignment for: ' + job },
    { id: 'boss', name: 'Boss Agent', message: job => 'You are the Boss Agent. Summarize that the team communication POC is complete for: ' + job },
];

function config() {
    const url = process.env.PURR_GATEWAY_URL || '';
    const token = process.env.PURR_CLIENT_TOKEN || '';
    let parsed;
    try { parsed = new URL(url); } catch { return null; }
    if (parsed.protocol !== 'https:' || parsed.username || parsed.password || parsed.search || parsed.hash ||
        parsed.pathname !== '/' || !token.trim()) return null;
    // Prevent arbitrary external destinations from being configured as the credential recipient.
    if (parsed.hostname !== 'purr-gateway-production.up.railway.app') return null;
    return { origin: parsed.origin, token };
}

async function requestPurr(settings, path, body) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 12000);
    try {
        const response = await fetch(settings.origin + path, {
            method: 'POST',
            headers: {
                'Authorization': 'Bearer ' + settings.token,
                'Content-Type': 'application/json',
                ...(body.sessionToken ? { 'X-Purr-Session': body.sessionToken } : {}),
            },
            body: JSON.stringify(body.payload),
            signal: controller.signal,
            redirect: 'error',
        });
        // Upstream response bodies and headers are never forwarded as-is to WordPress.
        if (!response.ok) {
            if (response.status === 401 || response.status === 403) throw new Error('purr_auth');
            throw new Error('purr_upstream');
        }
        const data = await response.json();
        if (!data || typeof data !== 'object') throw new Error('purr_invalid');
        return data;
    } finally {
        clearTimeout(timeout);
    }
}

async function runCommunicationPoc(job) {
    const settings = config();
    if (!settings) throw new Error('purr_not_configured');
    if (typeof job !== 'string' || job.length > 200 || !job.trim() || /[\x00-\x1f\x7f]/.test(job)) {
        throw new Error('purr_invalid_job');
    }
    const session = await requestPurr(settings, '/v1/sessions', { payload: {} });
    if (typeof session.session_token !== 'string' || !session.session_token ||
        typeof session.session_id !== 'string') throw new Error('purr_invalid');
    const transcript = [];
    for (let index = 0; index < roles.length; index++) {
        const role = roles[index];
        const reply = await requestPurr(settings, '/v1/chat', {
            sessionToken: session.session_token,
            payload: { session_id: session.session_id, message: role.message(job.trim()) },
        });
        if (typeof reply.message !== 'string') throw new Error('purr_invalid');
        transcript.push({
            step: index + 1,
            agent: role.name,
            action: index === 0 ? 'delegate' : index === 3 ? 'complete' : 'acknowledge',
            message: reply.message.slice(0, 2500),
            status: typeof reply.status === 'string' ? reply.status.slice(0, 60) : 'received',
        });
    }
    return {
        success: true,
        mode: 'deterministic communication POC (no agent reasoning verified)',
        provider: 'Purr Gateway',
        job: job.trim(),
        conversation: transcript,
        steps_completed: transcript.length,
        session_created: true,
    };
}

module.exports = { runCommunicationPoc };
