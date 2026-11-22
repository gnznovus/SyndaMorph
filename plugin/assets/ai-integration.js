/* global SyndaMorphAI */
( function () {
    'use strict';
    const byId = ( id ) => document.getElementById( id );
    const provider = byId( 'syndamorph-ai-provider' );
    const input = byId( 'syndamorph-ai-key' );
    const button = byId( 'syndamorph-ai-test' );
    const buttonLabel = byId( 'syndamorph-ai-test-label' );
    const preview = byId( 'syndamorph-ai-preview' );
    const output = byId( 'syndamorph-ai-output' );
    const configuredCount = byId( 'syndamorph-ai-configured' );
    const mockHint = byId( 'syndamorph-ai-mock-hint' );
    const keyHint = byId( 'syndamorph-ai-key-hint' );
    const lastTest = byId( 'syndamorph-ai-last-test' );
    const state = byId( 'syndamorph-ai-state' );
    const toggle = byId( 'syndamorph-ai-toggle-key' );
    const copyButton = byId( 'syndamorph-ai-copy' );
    const deleteButton = byId( 'syndamorph-ai-delete-key' );
    if ( !provider || !input || !button || !preview || !output ) return;

    let statuses = {};
    let isRunning = false;
    const setState = ( label, kind ) => {
        if ( state ) {
            state.textContent = label;
            state.dataset.state = kind;
        }
    };
    const refreshSummary = () => {
        if ( configuredCount ) {
            const realProviders = Object.entries( statuses ).filter( ( [ id ] ) => id !== 'mock' );
            const configured = realProviders.filter( ( [ , item ] ) => item && item.configured ).length;
            configuredCount.textContent = String( configured ).padStart( 2, '0' ) + ' / ' + String( realProviders.length ).padStart( 2, '0' );
        }
    };
    const showStatus = () => {
        const saved = statuses[ provider.value ];
        const isMock = provider.value === 'mock';
        preview.textContent = saved && saved.configured ? 'Saved key  ·  ' + saved.preview : 'No saved credential for this provider';
        if ( deleteButton ) deleteButton.hidden = !( saved && saved.configured );
        if ( mockHint ) mockHint.hidden = !isMock;
        if ( keyHint ) keyHint.hidden = isMock;
        input.value = '';
        input.classList.add( 'sm-masked-key' );
        if ( toggle ) {
            toggle.setAttribute( 'aria-pressed', 'false' );
            toggle.setAttribute( 'aria-label', 'Show API key' );
        }
        refreshSummary();
    };
    provider.addEventListener( 'change', () => {
        if ( !isRunning ) showStatus();
    } );
    if ( toggle ) {
        toggle.addEventListener( 'click', () => {
            const showing = input.classList.contains( 'sm-masked-key' );
            input.classList.toggle( 'sm-masked-key', !showing );
            toggle.setAttribute( 'aria-pressed', String( showing ) );
            toggle.setAttribute( 'aria-label', showing ? 'Hide API key' : 'Show API key' );
        } );
    }
    if ( deleteButton ) {
        deleteButton.addEventListener( 'click', async () => {
            if ( isRunning || !statuses[ provider.value ]?.configured ) return;
            const selected = provider.value;
            if ( !window.confirm( 'Permanently delete the saved ' + selected + ' API key from this WordPress site?' ) ) return;
            isRunning = true;
            deleteButton.disabled = true;
            button.disabled = true;
            provider.disabled = true;
            setState( 'Deleting', 'running' );
            try {
                const response = await fetch( SyndaMorphAI.deleteBaseUrl + encodeURIComponent( selected ), {
                    method: 'DELETE', credentials: 'same-origin',
                    headers: { 'X-WP-Nonce': SyndaMorphAI.nonce },
                } );
                const data = await response.json();
                if ( !response.ok || !data.deleted || data.key?.configured ) {
                    throw new Error( 'Unable to confirm server-side credential deletion.' );
                }
                statuses[ selected ] = data.key;
                showStatus();
                setState( 'Deleted', 'idle' );
                output.textContent = '[deleted] Saved ' + selected + ' credential removed from WordPress storage.\n[verified] Provider status reports no saved key.';
                if ( lastTest ) lastTest.textContent = 'Not tested';
            } catch ( _error ) {
                setState( 'Error', 'error' );
                output.textContent = '[error] Could not verify credential deletion. Check the server before retrying.';
            } finally {
                isRunning = false;
                deleteButton.disabled = false;
                button.disabled = false;
                provider.disabled = false;
            }
        } );
    }
    if ( copyButton ) {
        copyButton.addEventListener( 'click', async () => {
            try {
                await navigator.clipboard.writeText( output.textContent );
                copyButton.textContent = 'Copied';
                copyButton.blur();
            } catch ( _error ) {
                copyButton.textContent = 'Copy unavailable';
            }
        } );
    }

    // Real Gateway calls with synthetic role messages; no provider secrets reach the browser.
    const purrRun = byId( 'syndamorph-purr-run' );
    const purrJob = byId( 'syndamorph-purr-job' );
    const purrState = byId( 'syndamorph-purr-status' );
    const purrTranscript = byId( 'syndamorph-purr-transcript' );
    if ( purrRun && purrJob && purrState && purrTranscript ) {
        purrRun.addEventListener( 'click', async () => {
            if ( purrRun.disabled ) return;
            purrRun.disabled = true;
            purrState.textContent = 'Connecting';
            purrState.dataset.state = 'running';
            purrTranscript.textContent = 'Opening Purr session and exchanging four messages…';
            try {
                const response = await fetch( SyndaMorphAI.purrPocUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': SyndaMorphAI.nonce },
                    body: JSON.stringify( { job: purrJob.value.trim() } ),
                } );
                const data = await response.json();
                if ( !response.ok || !data.success || !data.session_created || data.steps_completed !== 4 ) {
                    throw new Error( 'Gateway or workflow verification failed' );
                }
                purrTranscript.replaceChildren();
                for ( const item of data.conversation ) {
                    const row = document.createElement( 'article' );
                    row.className = 'sm-purr-message';
                    const title = document.createElement( 'strong' );
                    title.textContent = item.step + '. ' + item.agent + ' · ' + item.action;
                    const message = document.createElement( 'p' );
                    message.textContent = item.message;
                    row.append( title, message );
                    purrTranscript.append( row );
                }
                purrState.textContent = '4 / 4 received';
                purrState.dataset.state = 'success';
            } catch ( _error ) {
                purrState.textContent = 'Failed';
                purrState.dataset.state = 'error';
                purrTranscript.textContent = 'Communication test could not complete. Verify Purr configuration, container restart, and Gateway availability.';
            } finally {
                purrRun.disabled = false;
            }
        } );
    }

    fetch( SyndaMorphAI.statusUrl, {
        credentials: 'same-origin', headers: { 'X-WP-Nonce': SyndaMorphAI.nonce },
    } ).then( ( response ) => {
        if ( !response.ok ) throw new Error( 'Unable to load saved provider status.' );
        return response.json();
    } ).then( ( data ) => {
        statuses = data;
        showStatus();
    } ).catch( () => {
        preview.textContent = 'Saved credential status unavailable';
        output.textContent = '[error] Could not load provider configuration.';
        setState( 'Unavailable', 'error' );
    } );
    button.addEventListener( 'click', async () => {
        if ( isRunning ) return;
        const selected = provider.value;
        const key = input.value;
        isRunning = true;
        button.disabled = true;
        provider.disabled = true;
        setState( 'Testing', 'running' );
        if ( buttonLabel ) buttonLabel.textContent = 'Testing connection…';
        output.textContent = '[start] Testing ' + selected + ' connectivity…\n[secure] Secrets and upstream response bodies are not logged.';
        try {
            const response = await fetch( SyndaMorphAI.testUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': SyndaMorphAI.nonce },
                body: JSON.stringify( { provider: selected, ...( key ? { key } : {} ) } ),
            } );
            input.value = '';
            const data = await response.json();
            if ( !response.ok ) throw new Error( data.code || 'Request failed' );
            statuses[ selected ] = data.key;
            showStatus();
            refreshSummary();
            setState( data.ok ? 'Connected' : 'Failed', data.ok ? 'success' : 'error' );
            if ( lastTest ) lastTest.textContent = data.ok ? 'Successful' : 'Failed';
            output.textContent = JSON.stringify( {
                provider: data.provider,
                success: data.ok,
                http_status: data.http_status,
                latency_ms: data.latency_ms,
                result: data.result,
                diagnostics: data.diagnostics,
            }, null, 2 );
        } catch ( error ) {
            input.value = '';
            setState( 'Error', 'error' );
            if ( lastTest ) lastTest.textContent = 'Error';
            // Only local error codes are rendered, never request objects or response bodies.
            output.textContent = '[error] Connection test failed: ' + error.message;
        } finally {
            isRunning = false;
            button.disabled = false;
            provider.disabled = false;
            if ( buttonLabel ) buttonLabel.textContent = 'Run connection test';
        }
    } );
} )();
