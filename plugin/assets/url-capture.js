/* global SyndaMorphCapture */
( function () {
    'use strict';
    const $ = ( id ) => document.getElementById( id );
    const capture = $( 'syndamorph-url-capture' );
    if ( ! capture ) { return; }
    const input = $( 'syndamorph-url-input' );
    const mode = $( 'syndamorph-url-mode' );
    const status = $( 'syndamorph-url-status' );
    const results = $( 'syndamorph-url-results' );
    const select = $( 'syndamorph-url-sections' );
    const warning = $( 'syndamorph-url-warning' );
    const load = $( 'syndamorph-url-load' );
    let sections = [];
    let diagnosticText = '';
    const diagnosticPanel = $( 'syndamorph-url-diagnostics' );
    const diagnosticSummary = $( 'syndamorph-url-diagnostics-summary' );
    const diagnosticJSON = $( 'syndamorph-url-diagnostics-json' );
    const diagnosticCopy = $( 'syndamorph-url-copy-diagnostics' );
    diagnosticCopy?.addEventListener( 'click', async () => {
        if ( ! diagnosticText ) { return; }
        try {
            await navigator.clipboard.writeText( diagnosticText );
            diagnosticCopy.textContent = 'Copied!';
        } catch ( error ) {
            diagnosticCopy.textContent = 'Copy unavailable; select the JSON below.';
        }
    } );

    capture.addEventListener( 'click', async () => {
        results.hidden = true;
        sections = [];
        diagnosticText = '';
        diagnosticPanel.hidden = true;
        capture.disabled = true;
        status.textContent = 'Capturing the requested page…';
        try {
            const response = await fetch( SyndaMorphCapture.endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': SyndaMorphCapture.nonce,
                },
                body: JSON.stringify( { url: input.value.trim(), mode: mode.value } ),
            } );
            const payload = await response.json();
            if ( ! response.ok ) { throw new Error( payload.message || payload.error || 'Capture failed.' ); }
            if ( payload.diagnostics ) {
                diagnosticText = JSON.stringify( payload.diagnostics, null, 2 );
                diagnosticJSON.textContent = diagnosticText;
                const report = payload.diagnostics;
                const styleFailures = ( report.network?.samples || [] ).filter( ( entry ) => /stylesheet|font|css/i.test( entry.detail ) ).length;
                diagnosticSummary.textContent = 'Visible DOM elements: ' + report.visibleElements + '/' + report.totalElements +
                    ' · Semantic sections: ' + report.semanticSections +
                    ' · Headings: ' + ( report.headings || [] ).length +
                    ' · Network blocked: ' + ( report.network?.denied || 0 ) +
                    ' · Stylesheet/font failures: ' + styleFailures +
                    ' · JS errors: ' + ( report.browserErrors || [] ).length;
                diagnosticCopy.textContent = 'Copy Diagnostic JSON';
                diagnosticPanel.hidden = false;
            }
            sections = ( payload.sections || [] ).filter( ( section ) => section && section.html && section.bounds && ( section.geometryAvailable === false || section.bounds.height > 0 ) );
            const rejected = ( payload.sections || [] ).length - sections.length;
            select.replaceChildren();
            sections.forEach( ( section, index ) => {
                const option = document.createElement( 'option' );
                option.value = String( index );
                option.textContent = section.id + ': ' + section.label + ( section.geometryAvailable === false ? ' (structure only)' : ' (' + Math.round( section.bounds.height ) + 'px)' );
                select.append( option );
            } );
            warning.textContent = ( payload.warnings || [] ).join( ' ' ) + ( sections.some( ( section ) => section.geometryAvailable === false ) ? ' Some sections were identified from document structure because rendered geometry was unavailable; visual fidelity may be reduced.' : '' );
            status.textContent = sections.length + ' usable section(s) detected using ' + payload.mode + ' capture.' + ( rejected ? ' ' + rejected + ' oversized or empty section(s) omitted.' : '' );
            results.hidden = ! sections.length;
            if ( ! sections.length ) { status.textContent += ' Review the capture diagnostics for the cause.'; }
        } catch ( error ) {
            status.textContent = error.message || 'Unable to extract the page.';
        } finally { capture.disabled = false; }
    } );

    load.addEventListener( 'click', () => {
        const section = sections[ Number( select.value ) ];
        if ( ! section ) { return; }
        const textarea = $( 'syndamorph-html-input' );
        const title = $( 'syndamorph-page-title' );
        const preview = $( 'syndamorph-convert' );
        if ( ! textarea || ! preview ) { status.textContent = 'Existing HTML importer is unavailable.'; return; }
        textarea.value = section.html;
        if ( title ) { title.value = section.label || section.id; }
        $( 'syndamorph-js-render' ).checked = false;
        preview.click();
        status.textContent = 'Loaded ' + section.id + ' into the existing Gutenberg preview.';
    } );
}() );
