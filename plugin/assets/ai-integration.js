/* global SyndaMorphAI */
( function () {
    'use strict';
    const provider = document.getElementById( 'syndamorph-ai-provider' );
    const input = document.getElementById( 'syndamorph-ai-key' );
    const button = document.getElementById( 'syndamorph-ai-test' );
    const preview = document.getElementById( 'syndamorph-ai-preview' );
    const output = document.getElementById( 'syndamorph-ai-output' );
    if ( !provider || !input || !button || !preview || !output ) return;
    let statuses = {};
    const showStatus = () => {
        const saved = statuses[ provider.value ];
        preview.textContent = saved && saved.configured ? 'Saved key: ' + saved.preview : 'No saved key';
        input.value = '';
    };
    provider.addEventListener( 'change', showStatus );
    fetch( SyndaMorphAI.statusUrl, {
        credentials: 'same-origin', headers: { 'X-WP-Nonce': SyndaMorphAI.nonce },
    } ).then( ( response ) => {
        if ( !response.ok ) throw new Error( 'Could not load provider configuration.' );
        return response.json();
    } ).then( ( data ) => { statuses = data; showStatus(); } ).catch( ( error ) => { output.textContent = error.message; } );
    button.addEventListener( 'click', async () => {
        const selected = provider.value;
        const key = input.value;
        button.disabled = true;
        output.textContent = 'Testing ' + selected + '…';
        try {
            const response = await fetch( SyndaMorphAI.testUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': SyndaMorphAI.nonce },
                body: JSON.stringify( { provider: selected, ...( key ? { key } : {} ) } ),
            } );
            // Never write the entered key, errors, or raw request into the debug panel.
            input.value = '';
            const data = await response.json();
            if ( !response.ok ) throw new Error( data.code || 'Request failed' );
            statuses[ selected ] = data.key;
            showStatus();
            output.textContent = JSON.stringify( {
                provider: data.provider, success: data.ok, http_status: data.http_status,
                latency_ms: data.latency_ms, result: data.result, diagnostics: data.diagnostics,
            }, null, 2 );
        } catch ( error ) {
            input.value = '';
            output.textContent = 'Connection test error: ' + error.message;
        } finally { button.disabled = false; }
    } );
} )();
