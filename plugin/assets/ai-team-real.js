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
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'Real AI job failed.');
            status.textContent = data.status + ' · ' + data.model + ' · ' + data.elapsedMs + ' ms · ' +
                (data.estimatedCostUsd === null ? 'Cost unknown' : 'Estimated $' + data.estimatedCostUsd.toFixed(6));
            // Model output is untrusted. Show as text, never as executable HTML.
            output.textContent = JSON.stringify(data, null, 2);
        } catch (error) {
            status.textContent = 'Execution failed: ' + error.message;
            output.textContent = 'No content was published. Check the selected model and Anthropic credential.';
        } finally { button.disabled = false; }
    });
})();
