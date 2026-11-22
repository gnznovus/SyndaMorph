/* global SyndaMorphTeam, SyndaMorphTeamSelection, SyndaMorphConverter, SyndaMorphImport */
( function () {
    'use strict';
    const root = document.getElementById( 'syndamorph-production-team' );
    if ( !root ) return;
    const $ = id => document.getElementById( id );
    const pick = window.SyndaMorphTeamSelection;
    const kindInput = $( 'syndamorph-team-source-type' );
    const inspectButton = $( 'syndamorph-team-inspect' );
    const inspectStatus = $( 'syndamorph-team-inspect-status' );
    const inspection = $( 'syndamorph-team-source-inspection' );
    const sectionSelect = $( 'syndamorph-team-section' );
    const sourceSummary = $( 'syndamorph-team-source-summary' );
    const diagnostics = $( 'syndamorph-team-source-diagnostics' );
    const diagnosticsJson = $( 'syndamorph-team-source-diagnostics-json' );
    const convertButton = $( 'syndamorph-team-convert' );
    const jsToggle = $( 'syndamorph-team-js-mode' );
    const convertError = $( 'syndamorph-team-convert-error' );
    const inspectActions = $( 'syndamorph-team-source-actions' );
    const artifacts = $( 'syndamorph-team-artifacts' );
    const frame = $( 'syndamorph-team-preview-frame' );
    const reloadPreview = $( 'syndamorph-team-reload-preview' );
    const previewHeading = $( 'syndamorph-team-preview-heading' );
    const previewState = $( 'syndamorph-team-preview-state' );
    const warnings = $( 'syndamorph-team-preview-warnings' );
    const saveButton = $( 'syndamorph-team-save' );
    const saveKind = $( 'syndamorph-team-output-kind' );
    const saveStatus = $( 'syndamorph-team-save-status' );
    const resultsTitle = $( 'syndamorph-team-result-title' );
    const resultsSummary = $( 'syndamorph-team-review' );
    const jobState = $( 'syndamorph-team-job-state' );
    const outputState = $( 'syndamorph-team-output-state' );
    const currentJob = $( 'syndamorph-team-current-job' );
    if ( !window.SyndaMorphConverter || !pick ) return;

    let inspected = null;
    let prepared = [];
    let cursor = 0;
    let busy = false;
    let sourceGeneration = 0;
    let approved = false;
    let activeJob = null;
    const titleFor = path => ( path.split( '/' ).pop() || 'Untitled' ).replace( /\.html?$/i, '' ).replace( /[-_]+/g, ' ' );
    const request = async ( url, body, form = false ) => {
        const headers = { 'X-WP-Nonce': SyndaMorphTeam.nonce };
        if ( !form ) headers['Content-Type'] = 'application/json';
        const response = await fetch( url, {
            method: 'POST', credentials: 'same-origin', headers,
            body: form ? body : JSON.stringify( body ),
        } );
        let data;
        try { data = await response.json(); }
        catch ( error ) { throw new Error( 'Invalid server response (HTTP ' + response.status + ').' ); }
        if ( !response.ok ) throw new Error( data.message || data.error || 'HTTP ' + response.status );
        return data;
    };
    const stage = name => {
        root.querySelectorAll( '.sm-team-tabs [role="tab"]' ).forEach( tab => {
            const active = tab.dataset.panel === name;
            tab.setAttribute( 'aria-selected', String( active ) );
            tab.tabIndex = active ? 0 : -1;
        } );
        root.querySelectorAll( '.sm-team-panel' ).forEach( panel => {
            panel.hidden = panel.dataset.panel !== name;
        } );
    };
    const showError = ( node, message ) => {
        if ( !node ) return;
        node.textContent = message;
        node.hidden = !message;
    };
    const setStatus = ( title, detail, isError = false ) => {
        inspectStatus.textContent = isError ? detail : title;
        inspectStatus.dataset.state = isError ? 'error' : 'ok';
    };
    const sourceMode = () => {
        const kind = pick.sourceType();
        inspectActions.hidden = kind === 'html';
        inspectButton.textContent = kind === 'package' ? 'Scan package' : 'Inspect source';
        if ( kind === 'html' ) inspectStatus.textContent = 'HTML can convert directly';
    };
    const resetInspection = () => {
        sourceGeneration++;
        inspected = null;
        prepared = [];
        approved = false;
        inspection.hidden = true;
        diagnostics.hidden = true;
        saveButton.disabled = true;
        artifacts.hidden = true;
        previewGeneration++;
        frame.removeAttribute( 'srcdoc' );
        inspectStatus.textContent = 'Ready for inspection';
        inspectStatus.dataset.state = 'idle';
        showError( convertError, '' );
        sourceSummary.textContent = '';
    };
    kindInput.addEventListener( 'change', () => { resetInspection(); sourceMode(); } );
    sourceMode();
    root.querySelectorAll( '#syndamorph-team-source, #syndamorph-team-render-mode, #syndamorph-team-html' )
        .forEach( control => control.addEventListener( 'change', resetInspection ) );
    root.querySelectorAll( '[data-drop-kind]' ).forEach( drop => {
        drop.addEventListener( 'drop', () => resetInspection() );
        const input = drop.querySelector( 'input[type="file"]' );
        input.addEventListener( 'change', resetInspection );
    } );

    inspectButton.addEventListener( 'click', async () => {
        if ( busy ) return;
        const kind = pick.sourceType();
        const token = ++sourceGeneration;
        busy = true;
        inspectButton.disabled = true;
        convertButton.disabled = true;
        inspection.hidden = true;
        setStatus( 'Inspecting ' + kind + ' source…', '' );
        try {
            let result;
            if ( kind === 'url' ) {
                const raw = pick.url();
                let sourceUrl;
                try { sourceUrl = new URL( raw ); }
                catch ( error ) { throw new Error( 'Enter a valid HTTP(S) page URL.' ); }
                if ( ![ 'http:', 'https:' ].includes( sourceUrl.protocol ) ) throw new Error( 'Only HTTP(S) URLs are supported.' );
                result = await request( SyndaMorphTeam.captureUrl, {
                    url: raw, mode: $( 'syndamorph-team-render-mode' ).value,
                } );
                const all = Array.isArray( result.sections ) ? result.sections : [];
                const sections = all.filter( item => item && item.html && item.bounds &&
                    ![ 'header', 'footer', 'overlay' ].includes( item.kind ) &&
                    ( item.geometryAvailable === false || item.bounds.height > 0 ) );
                if ( !sections.length ) throw new Error( 'No usable sections were detected. Try a different rendering mode.' );
                inspected = { kind, sections, diagnostics: result.diagnostics };
                sourceSummary.textContent = sections.length + ' usable section(s) of ' + all.length +
                    ' detected. Select the section to convert.';
                if ( result.diagnostics ) {
                    diagnostics.hidden = false;
                    diagnosticsJson.textContent = JSON.stringify( result.diagnostics, null, 2 );
                }
            } else if ( kind === 'package' ) {
                const file = pick.file( 'package' );
                if ( !file || !/\.zip$/i.test( file.name ) ) throw new Error( 'Select or drop a ZIP package first.' );
                if ( file.size > 32 * 1024 * 1024 ) throw new Error( 'Package scan supports ZIP files up to 32 MB.' );
                const form = new FormData();
                form.append( 'package', file );
                result = await request( SyndaMorphTeam.packageScanUrl, form, true );
                if ( !Array.isArray( result.pages ) || !result.pages.length ) throw new Error( 'No HTML pages found inside the package.' );
                inspected = { kind, token: result.token, pages: result.pages };
                sourceSummary.textContent = result.pages.length + ' HTML page(s), ' +
                    result.images + ' image(s), ' + result.assets + ' other asset(s). Package session expires in 60 minutes.';
            } else {
                const file = pick.file( 'html' );
                if ( !file && !pick.html().trim() ) throw new Error( 'Choose an HTML file or paste HTML.' );
                inspected = { kind };
                sourceSummary.textContent = file ? 'HTML file: ' + file.name : 'Pasted HTML ready for conversion.';
            }
            if ( token !== sourceGeneration ) return;
            sectionSelect.replaceChildren();
            if ( inspected.kind === 'url' ) {
                inspected.sections.forEach( item => {
                    sectionSelect.append( new Option( item.id + ': ' + item.label, item.id ) );
                } );
            } else if ( inspected.kind === 'package' ) {
                inspected.pages.forEach( page => sectionSelect.append( new Option( page, page ) ) );
                sectionSelect.multiple = true;
                sectionSelect.size = Math.min( 8, inspected.pages.length );
                Array.from( sectionSelect.options ).forEach( option => { option.selected = true; } );
            } else {
                sectionSelect.append( new Option( 'HTML document', 'html' ) );
            }
            if ( inspected.kind !== 'package' ) { sectionSelect.multiple = false; sectionSelect.size = 1; }
            inspection.hidden = false;
            setStatus( 'Source inspected', sourceSummary.textContent );
        } catch ( error ) {
            if ( token === sourceGeneration ) setStatus( 'Inspection failed', error.message || String( error ), true );
        } finally {
            busy = false;
            inspectButton.disabled = false;
            convertButton.disabled = false;
        }
    } );

    let previewGeneration = 0;
    const paintPreview = () => {
        const item = prepared[ cursor ];
        if ( !item ) return;
        const token = ++previewGeneration;
        frame.removeAttribute( 'srcdoc' );
        // Only mount srcdoc after the Results panel has become visible.
        // A hidden sandboxed iframe can otherwise initialize as a blank document.
        requestAnimationFrame( () => {
            if ( token !== previewGeneration || artifacts.hidden ) return;
            frame.srcdoc = item.preview;
        } );
    };
    reloadPreview.addEventListener( 'click', paintPreview );
    const showPreview = index => {
        cursor = index;
        const item = prepared[ cursor ];
        if ( !item ) return;
        paintPreview();
        previewHeading.textContent = item.title + ' · ' + ( cursor + 1 ) + '/' + prepared.length;
        const issues = [
            ...( item.missing || [] ).map( issue => typeof issue === 'string' ? issue : 'Image: ' + ( issue.source || 'unknown' ) ),
            ...( Array.isArray( item.css ) ? item.css : [] ).map( issue => 'CSS: ' + ( typeof issue === 'string' ? issue : JSON.stringify( issue ) ) ),
            ...( item.stylesheets?.missing || [] ).map( issue => 'Missing stylesheet: ' + issue ),
        ];
        warnings.replaceChildren();
        if ( issues.length ) {
            const heading = document.createElement( 'strong' );
            heading.textContent = issues.length + ' asset / CSS notice(s)';
            const list = document.createElement( 'ul' );
            issues.slice( 0, 50 ).forEach( issue => {
                const li = document.createElement( 'li' );
                li.textContent = issue;
                list.append( li );
            } );
            warnings.append( heading, list );
        } else warnings.textContent = 'No missing asset issues reported by the converter.';
        if ( typeof item.css === 'number' && item.css > 0 ) {
            const p = document.createElement( 'p' );
            p.textContent = item.css + ' package stylesheet(s) embedded in the converted source.';
            warnings.append( p );
        }
        previewState.textContent = 'Review required';
        previewState.dataset.state = 'running';
        if ( prepared.length > 1 ) {
            const nav = document.createElement( 'div' );
            nav.className = 'sm-team-preview-navigation';
            const prev = document.createElement( 'button' );
            prev.type = 'button';prev.className = 'sm-team-secondary';prev.textContent = 'Previous page';
            prev.disabled = cursor === 0;
            prev.addEventListener( 'click', () => showPreview( cursor - 1 ) );
            const next = document.createElement( 'button' );
            next.type = 'button';next.className = 'sm-team-secondary';next.textContent = 'Next page';
            next.disabled = cursor === prepared.length - 1;
            next.addEventListener( 'click', () => showPreview( cursor + 1 ) );
            nav.append( prev, next );
            const existing = artifacts.querySelector( '.sm-team-preview-navigation' );
            if ( existing ) existing.replaceWith( nav );
            else frame.after( nav );
        }
    };
    convertButton.addEventListener( 'click', async () => {
        if ( busy ) return;
        if ( !inspected || inspected.kind !== pick.sourceType() ) {
            if ( pick.sourceType() === 'html' && ( pick.file( 'html' ) || pick.html().trim() ) ) {
                inspected = { kind: 'html' };
            } else {
                showError( convertError, pick.sourceType() === 'package' ? 'Scan and select package pages first.' : 'Inspect the URL and choose a section before conversion.' );
                return;
            }
        }
        const chosen = inspected;
        const generation = sourceGeneration;
        const kind = chosen.kind;
        const jsMode = Boolean( jsToggle.checked );
        busy = true;
        showError( convertError, '' );
        convertButton.disabled = true;
        saveButton.disabled = true;
        artifacts.hidden = true;
        prepared = [];
        approved = false;
        stage( 'production' );
        jobState.textContent = 'Converting';
        try {
            let pages = [];
            if ( kind === 'url' ) {
                const section = chosen.sections.find( item => item.id === sectionSelect.value );
                if ( !section ) throw new Error( 'Select a captured section.' );
                pages = [ { path: section.id, title: section.label || section.id, html: section.html } ];
            } else if ( kind === 'html' ) {
                const selected = pick.file( 'html' );
                const markup = selected ? await selected.text() : pick.html();
                if ( !markup.trim() ) throw new Error( 'HTML source is empty.' );
                pages = [ { path: selected?.name || 'pasted.html', title: selected ? titleFor( selected.name ) : 'Imported HTML', html: markup } ];
            } else {
                const names = Array.from( sectionSelect.selectedOptions ).map( option => option.value );
                if ( !names.length ) throw new Error( 'Select at least one page in the inspected package.' );
                if ( names.length > 30 ) throw new Error( 'Batch import supports up to 30 selected pages.' );
                pages = names.map( path => ( { path, title: titleFor( path ) } ) );
            }
            const next = [];
            for ( const page of pages ) {
                if ( generation !== sourceGeneration ) return;
                jobState.textContent = 'Converting ' + page.title;
                let source = page.html;
                let missing = [];
                let css = [];
                if ( kind === 'package' ) {
                    const result = await request( SyndaMorphTeam.packagePageUrl,
                        { token: chosen.token, page: page.path, js_mode: jsMode } );
                    source = result.html;
                    missing = result.missing || [];
                    css = result.css || [];
                }
                const converted = await SyndaMorphConverter.prepareImport( source, jsMode );
                next.push( { ...page, content: converted.content, preview: converted.preview,
                    missing: [ ...missing, ...converted.missing ], css, stylesheets: converted.stylesheets } );

            }
            if ( generation !== sourceGeneration ) return;
            prepared = next;
            activeJob = { id: 'LOCAL-' + Date.now().toString( 36 ).toUpperCase(), kind, token: chosen.token };
            const jobTitle = pages.length === 1 ? pages[ 0 ].title + ' — Gutenberg Conversion' :
                pages.length + ' pages — Gutenberg Package Conversion';
            const label = activeJob.id + ' : ' + jobTitle;
            resultsTitle.textContent = label;
            currentJob.textContent = label;
            jobState.textContent = 'Converted';
            outputState.textContent = prepared.length + ' preview(s)';
            resultsSummary.replaceChildren();
            const heading = document.createElement( 'strong' );
            heading.textContent = prepared.length + ' page(s) converted by the existing engine';
            const paragraph = document.createElement( 'p' );
            paragraph.textContent = 'Preview each artifact below. No AI inference was called and nothing has been saved to WordPress yet.';
            resultsSummary.append( heading, paragraph );
            artifacts.hidden = false;
            saveStatus.textContent = 'Review the preview and asset warnings, then approve to save.';
            saveButton.disabled = false;
            const oldNav = artifacts.querySelector( '.sm-team-preview-navigation' );
            if ( oldNav ) oldNav.remove();
            stage( 'results' );
            showPreview( 0 );
        } catch ( error ) {
            showError( convertError, 'Conversion failed: ' + ( error.message || String( error ) ) );
            jobState.textContent = 'Conversion error';
            outputState.textContent = 'Not ready';
            stage( 'setup' );
        } finally {
            busy = false;
            convertButton.disabled = false;
        }
    } );

    saveButton.addEventListener( 'click', async () => {
        if ( busy || approved || !prepared.length || !activeJob ) return;
        busy = true;
        saveButton.disabled = true;
        saveKind.disabled = true;
        saveStatus.textContent = 'Saving approved Gutenberg output…';
        try {
            const kind = activeJob.kind;
            const mode = saveKind.value;
            let result;
            if ( kind === 'package' ) {
                result = await request( SyndaMorphTeam.packageCommitUrl, {
                    token: activeJob.token, mode,
                    pages: prepared.map( item => ( { path: item.path, title: item.title, content: item.content } ) ),
                } );
                saveStatus.textContent = result.created.length + ' ' + mode + '(s) created. ' +
                    result.uploaded_images + ' images uploaded; ' + result.reused_images + ' references reused.';
            } else if ( mode === 'pattern' ) {
                result = await request( SyndaMorphImport.patternUrl, {
                    title: prepared[ 0 ].title, content: prepared[ 0 ].content,
                } );
                saveStatus.textContent = 'Pattern saved in Gutenberg → Patterns → SyndaMorph.';
            } else {
                result = await request( SyndaMorphImport.restUrl, {
                    title: prepared[ 0 ].title, content: prepared[ 0 ].content, status: 'draft',
                } );
                saveStatus.textContent = 'Draft saved. Open it through the WordPress Pages screen.';
            }
            approved = true;
            previewState.textContent = 'Saved';
            previewState.dataset.state = 'success';

        } catch ( error ) {
            saveStatus.textContent = 'Save failed: ' + ( error.message || String( error ) );

            saveButton.disabled = false;
        } finally {
            busy = false;
            saveKind.disabled = false;
        }
    } );
} )();
