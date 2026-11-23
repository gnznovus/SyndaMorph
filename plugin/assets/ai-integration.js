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

    // Provider connection is the first step; model controls remain mounted
    // while hidden so catalog refresh and saved form values are preserved.
    const settingTabs = Array.from( document.querySelectorAll( '#syndamorph-ai-integration .sm-ai-settings-tabs [role="tab"]' ) );
    const settingPanels = Array.from( document.querySelectorAll( '#syndamorph-ai-integration .sm-ai-settings-panel' ) );
    const setSettingsTab = ( name, focus = false ) => {
        for ( const tab of settingTabs ) {
            const active = tab.dataset.panel === name;
            tab.setAttribute( 'aria-selected', String( active ) );
            tab.tabIndex = active ? 0 : -1;
            if ( active && focus ) tab.focus();
        }
        for ( const panel of settingPanels ) panel.hidden = panel.id !== 'sm-ai-panel-' + name;
    };
    settingTabs.forEach( ( tab, index ) => {
        tab.addEventListener( 'click', () => setSettingsTab( tab.dataset.panel ) );
        tab.addEventListener( 'keydown', ( event ) => {
            let next = index;
            if ( event.key === 'ArrowRight' ) next = ( index + 1 ) % settingTabs.length;
            else if ( event.key === 'ArrowLeft' ) next = ( index + settingTabs.length - 1 ) % settingTabs.length;
            else if ( event.key === 'Home' ) next = 0;
            else if ( event.key === 'End' ) next = settingTabs.length - 1;
            else return;
            event.preventDefault();
            setSettingsTab( settingTabs[ next ].dataset.panel, true );
        } );
    } );
    setSettingsTab( 'provider' );

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
        if ( !isRunning ) { showStatus(); loadModels(); }
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

    fetch( SyndaMorphAI.statusUrl, {
        credentials: 'same-origin', headers: { 'X-WP-Nonce': SyndaMorphAI.nonce },
    } ).then( ( response ) => {
        if ( !response.ok ) throw new Error( 'Unable to load saved provider status.' );
        return response.json();
    } ).then( ( data ) => {
        statuses = data;
        showStatus();
        loadModels();
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
            if ( data.ok ) loadModels( true );
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

    // Model catalog and pricing are separate: provider APIs expose models but
    // not a trustworthy, complete billing tariff.
    const modelChoice = byId( 'syndamorph-model-choice' );
    const modelRefresh = byId( 'syndamorph-model-refresh' );
    const modelSave = byId( 'syndamorph-model-save' );
    const modelStatus = byId( 'syndamorph-model-status' );
    const modelSaveStatus = byId( 'syndamorph-model-save-status' );
    const cost = byId( 'syndamorph-model-cost' );
    const budget = byId( 'syndamorph-model-budget' );
    let catalogRequest = 0;
    let catalogModels = [];
    const formatUsd = amount => Number( amount ).toFixed( 2 ).replace( /\\.00$/, '' );
    const activeModel = () => catalogModels.find( entry => entry.id === modelChoice?.value );
    const estimate = () => {
        const rate = activeModel()?.pricing;
        if ( cost ) cost.textContent = rate && Number.isFinite( Number( rate.input ) ) &&
            Number.isFinite( Number( rate.output ) )
            ? '$' + ( ( 6000 * Number( rate.input ) + 4000 * Number( rate.output ) ) / 1000000 ).toFixed( 4 )
            : 'Unavailable';
    };
    const updateSelectedModel = () => {
        const entry = activeModel();
        estimate();
        if ( !entry ) return;
        if ( modelStatus ) {
            modelStatus.textContent = entry.pricing
                ? 'Pricing reference ' + entry.pricing.verifiedAt + ' · ' +
                  ( entry.pricing.note || 'Standard token rates only; other billing modifiers are excluded.' )
                : 'Pricing unavailable for this model; paid execution is disabled.';
        }
    };
    modelChoice?.addEventListener( 'change', updateSelectedModel );

    async function loadModels( force = false ) {
        if ( !modelChoice ) return;
        const seq = ++catalogRequest;
        const selectedProvider = provider.value;
        modelChoice.replaceChildren( new Option( 'Loading models…', '' ) );
        modelChoice.disabled = true;
        if ( modelStatus ) modelStatus.textContent = 'Requesting provider catalog…';
        try {
            const url = new URL( SyndaMorphAI.modelsUrl );
            url.searchParams.set( 'provider', selectedProvider );
            if ( force ) url.searchParams.set( 'refresh', '1' );
            const response = await fetch( url, {
                credentials: 'same-origin', headers: { 'X-WP-Nonce': SyndaMorphAI.nonce },
            } );
            const result = await response.json();
            if ( !response.ok ) throw new Error( result.message || 'Model catalog unavailable.' );
            if ( seq !== catalogRequest ) return;
            catalogModels = result.models || [];
            modelChoice.replaceChildren( new Option( 'Select a provider model', '' ) );
            for ( const entry of catalogModels ) {
                const price = entry.pricing;
                const suffix = price
                    ? ' ($' + formatUsd( price.input ) + ' in / $' + formatUsd( price.output ) + ' out per 1M)'
                    : ' (price unavailable)';
                modelChoice.add( new Option( entry.name + suffix, entry.id ) );
            }
            const saved = result.settings || {};
            if ( saved.provider === selectedProvider && catalogModels.some( x => x.id === saved.model ) ) {
                modelChoice.value = saved.model;
                budget.value = String( saved.maxJobUsd ?? 1 );
            } else {
                // Start the first POC with the least expensive known model.
                // Selection is local until the administrator explicitly saves it.
                const priced = catalogModels.filter( x => x.pricing &&
                    Number.isFinite( Number( x.pricing.input ) ) &&
                    Number.isFinite( Number( x.pricing.output ) ) );
                priced.sort( ( a, b ) =>
                    ( 6000 * a.pricing.input + 4000 * a.pricing.output ) -
                    ( 6000 * b.pricing.input + 4000 * b.pricing.output ) );
                if ( priced.length ) modelChoice.value = priced[0].id;
            }
            updateSelectedModel();
            modelChoice.disabled = false;
            estimate();
            if ( modelStatus ) modelStatus.textContent = result.models.length + ' live models · ' +
                ( result.stale ? 'Last-known catalog (provider unavailable)' : 'Provider catalog' ) +
                ( result.has_more ? ' · More pages available; this POC shows the first page only' : '' );
        } catch ( error ) {
            if ( seq !== catalogRequest ) return;
            modelChoice.replaceChildren( new Option( 'Unavailable', '' ) );
            if ( modelStatus ) modelStatus.textContent = error.message;
        }
    }
    modelRefresh?.addEventListener( 'click', () => loadModels( true ) );
    modelSave?.addEventListener( 'click', async () => {
        if ( !modelChoice?.value || !activeModel()?.pricing ) {
            modelSaveStatus.textContent = 'Choose a model with available reference pricing.';
            return;
        }
        modelSave.disabled = true;
        modelSaveStatus.textContent = 'Saving…';
        try {
            const response = await fetch( SyndaMorphAI.modelSettingsUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': SyndaMorphAI.nonce },
                body: JSON.stringify( {
                    provider: provider.value, model: modelChoice.value,
                    maxJobUsd: Number( budget.value ),
                } ),
            } );
            const data = await response.json();
            if ( !response.ok ) throw new Error( data.message || 'Could not save model settings.' );
            modelSaveStatus.textContent = 'Saved ' + data.model + ' with reference pricing and job budget.';
        } catch ( error ) {
            modelSaveStatus.textContent = error.message;
        } finally { modelSave.disabled = false; }
    } );
} )();
