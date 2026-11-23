/* Independent real-agent experiment. Never inject generated HTML into the DOM. */
(() => {
    'use strict';
    const cfg = window.SyndaMorphRealTeam;
    const button = document.getElementById('syndamorph-real-run');
    const brief = document.getElementById('syndamorph-real-brief');
    const output = document.getElementById('syndamorph-real-output');
    const status = document.getElementById('syndamorph-real-status');
    if (!cfg || !button || !brief || !output || !status) return;
    button.addEventListener('click', async () => {
        const text = brief.value.trim();
        if (text.length < 12) { status.textContent = 'Enter a production brief of at least 12 characters.'; return; }
        if (!window.confirm('Run four paid Anthropic calls using the selected model? No content will be published.')) return;
        button.disabled = true;
        status.textContent = 'Executing Boss → Designer → Developer → Validator. This is a synchronous POC.';
        output.textContent = 'Working…';
        try {
            const response = await fetch(cfg.url, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
                body: JSON.stringify({ brief: text }),
            });
            const raw = await response.text();
            let data;
            try { data = JSON.parse(raw); } catch (_) {
                throw new Error('WordPress returned a non-JSON response (HTTP ' + response.status +
                    '). This may be a PHP/server timeout; inspect the WordPress error log.');
            }
            if (!response.ok) {
                const details = data.data && typeof data.data === 'object' ? data.data : {};
                const role = typeof details.role === 'string' ? ' · Agent: ' + details.role : '';
                const completed = Number.isInteger(details.completedCalls) ? ' · Completed calls: ' + details.completedCalls : '';
                const stop = typeof details.stopReason === 'string' ? ' · Stop reason: ' + details.stopReason : '';
                const tokens = Number.isInteger(details.outputTokens) ? ' · Output tokens: ' + details.outputTokens : '';
                throw new Error((data.message || 'WordPress rejected this request.') +
                    ' [HTTP ' + response.status + ' / ' + (data.code || 'unknown') + ']' + role + completed + stop + tokens);
            }
            status.textContent = data.status + ' · ' + data.model + ' · ' + data.elapsedMs + ' ms · ' +
                (data.estimatedCostUsd === null ? 'Cost unknown' : 'Estimated $' + data.estimatedCostUsd.toFixed(6));
            // Model output is untrusted. Show as text, never as executable HTML.
            output.textContent = JSON.stringify(data, null, 2);
        } catch (error) {
            status.textContent = 'Execution failed: ' + error.message;
            output.textContent = 'Execution error: ' + error.message +
                '\\nNo content was published. Do not rerun until the cause is identified; prior API calls may have been billed.';
        } finally { button.disabled = false; }
    });
})();
