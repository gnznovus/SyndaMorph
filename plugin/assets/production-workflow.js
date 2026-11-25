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
    const scopeInput = $( 'syndamorph-team-scope' );
    const scopeAdapter = window.SyndaMorphScope;
    const sourceSummary = $( 'syndamorph-team-source-summary' );
    const diagnostics = $( 'syndamorph-team-source-diagnostics' );
    const diagnosticsJson = $( 'syndamorph-team-source-diagnostics-json' );
    const convertButton = $( 'syndamorph-team-convert' );
    const jsOptions = {
        html: $( 'syndamorph-team-js-html' ),
        package: $( 'syndamorph-team-js-package' ),
    };
    const convertError = $( 'syndamorph-team-convert-error' );
    const inspectActions = $( 'syndamorph-team-source-actions' );
    const artifacts = $( 'syndamorph-team-artifacts' );
    const frame = $( 'syndamorph-team-preview-frame' );
    const reloadPreview = $( 'syndamorph-team-reload-preview' );
    const returnSetup = $( 'syndamorph-team-return-setup' );
    const previewHeading = $( 'syndamorph-team-preview-heading' );
    const viewResult = $( 'syndamorph-team-view-result' );
    const previewNav = $( 'syndamorph-team-preview-navbar' );
    const tabWindow = $( 'syndamorph-team-preview-tab-window' );
    const previewTabs = $( 'syndamorph-team-preview-tabs' );
    const pager = $( 'syndamorph-team-preview-pager' );
    const prevPage = $( 'syndamorph-team-preview-prev' );
    const nextPage = $( 'syndamorph-team-preview-next' );
    const picker = $( 'syndamorph-team-preview-picker' );
    const pickerLabel = $( 'syndamorph-team-preview-picker-label' );
    const pickerMenu = $( 'syndamorph-team-preview-picker-menu' );
    const previewState = $( 'syndamorph-team-preview-state' );
    const warnings = $( 'syndamorph-team-preview-warnings' );
    const saveButton = $( 'syndamorph-team-save' );
    const saveOpenButton = $( 'syndamorph-team-save-open' );
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
    const savedModes = new Map();
    const savedDraftIds = [];
    let activeJob = null;
    let syncPreviewNav = () => {};
    let buildPreviewNav = () => {};
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
    returnSetup.addEventListener( 'click', () => {
        stage( 'setup' );
        const tab = $( 'sm-team-tab-setup' );
        tab.scrollIntoView( { behavior: 'smooth', block: 'start' } );
    } );
    const editorUrl = id => SyndaMorphImport.editUrl + '?post=' + encodeURIComponent( id ) + '&action=edit';
    const refreshSaveAction = () => {
        const mode = saveKind.value;
        const saved = savedModes.get( mode );
        const canSave = !busy && Boolean( prepared.length && activeJob ) && !saved;
        saveButton.dataset.state = busy ? 'saving' : saved ? 'saved' : 'ready';
        saveButton.disabled = !canSave;
        saveButton.textContent = saved ? ( mode === 'draft' ? 'Draft saved' : 'Pattern saved' ) : 'Save';
        const draftMode = mode === 'draft';
        saveOpenButton.hidden = !draftMode;
        saveOpenButton.dataset.state = busy ? 'saving' : saved ? 'saved' : 'ready';
        saveOpenButton.disabled = busy || !prepared.length || !activeJob || ( Boolean( saved ) && !savedDraftIds.length );
        saveOpenButton.textContent = saved ? 'Open Editor' : 'Save & Open Editor';
        if ( saved ) saveStatus.textContent = saved;
        else if ( prepared.length ) saveStatus.textContent = 'Ready to save as ' + ( draftMode ? 'draft pages' : 'unsynced patterns' ) + '.';
    };
    saveKind.addEventListener( 'change', refreshSaveAction );
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
        scopeAdapter?.clear();
        inspected = null;
        prepared = [];
        activeJob = null;
        savedModes.clear();
        savedDraftIds.length = 0;
        inspection.hidden = true;
        diagnostics.hidden = true;
        saveButton.disabled = true;
        artifacts.hidden = true;
        previewNav.hidden = true;
        viewResult.hidden = true;
        previewGeneration++;
        frame.removeAttribute( 'srcdoc' );
        inspectStatus.textContent = 'Ready for inspection';
        inspectStatus.dataset.state = 'idle';
        showError( convertError, '' );
        sourceSummary.textContent = '';
    };
    kindInput.addEventListener( 'change', () => { resetInspection(); sourceMode(); } );
    scopeInput?.addEventListener( 'change', resetInspection );
    Object.values( jsOptions ).forEach( input => input.addEventListener( 'change', resetInspection ) );
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
                if ( scopeAdapter?.usesSource() ) {
                    result = await scopeAdapter.discover( raw );
                    if ( token !== sourceGeneration ) return;
                    result.requestedUrl = raw;
                    const sections = Object.values( result.regions );
                    inspected = { kind, sections, snapshot: {scopeId: result.id}, url: raw, scopeId: result.id };
                    sourceSummary.textContent = sections.length + ' required region(s) recorded across 1440, 768 and 375 px.';
                    diagnostics.hidden = false;
                    diagnosticsJson.textContent = JSON.stringify( result.captures, null, 2 );
                } else {
                result = await request( SyndaMorphTeam.captureUrl, {
                    url: raw, mode: $( 'syndamorph-team-render-mode' ).value,
                } );
                const all = Array.isArray( result.sections ) ? result.sections : [];
                const sections = all.filter( item => item && item.html && item.bounds &&
                    ![ 'header', 'footer', 'overlay' ].includes( item.kind ) &&
                    ( item.geometryAvailable === false || item.bounds.height > 0 ) );
                if ( !sections.length ) throw new Error( 'No usable sections were detected. Try a different rendering mode.' );
                inspected = { kind, sections, diagnostics: result.diagnostics, snapshot: result, url: raw };
                sourceSummary.textContent = sections.length + ' usable section(s) of ' + all.length +
                    ' detected. Select the section to convert.';
                if ( result.diagnostics ) {
                    diagnostics.hidden = false;
                    diagnosticsJson.textContent = JSON.stringify( result.diagnostics, null, 2 );
                }
                }
            } else if ( kind === 'package' ) {
                const file = pick.file( 'package' );
                if ( !file || !/\.zip$/i.test( file.name ) ) throw new Error( 'Select or drop a ZIP package first.' );
                if ( file.size > 32 * 1024 * 1024 ) throw new Error( 'Package scan supports ZIP files up to 32 MB.' );
                const form = new FormData();
                form.append( 'package', file );
                result = await request( SyndaMorphTeam.packageScanUrl, form, true );
                if ( !Array.isArray( result.pages ) || !result.pages.length ) throw new Error( 'No HTML pages found inside the package.' );
                inspected = { kind, token: result.token, pages: result.pages, manifest: {pages:result.pages, images:result.images, assets:result.assets} };
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
            if ( inspected.kind !== 'package' ) {
                // Use a genuine single-line select. Explicit size=1 can retain
                // listbox behavior after a hidden tab becomes visible in Chromium.
                sectionSelect.multiple = false;
                sectionSelect.removeAttribute( 'size' );
            }
            sectionSelect.blur();
            sectionSelect.hidden = inspected.kind === 'url' && result?.mode === 'page';
            sectionSelect.labels[0].hidden = sectionSelect.hidden;
            inspection.hidden = false;
            if ( inspected.scopeId ) scopeAdapter.set( result );
            setStatus( 'Source inspected', sourceSummary.textContent );
        } catch ( error ) {
            if ( token === sourceGeneration ) setStatus( 'Inspection failed', error.message || String( error ), true );
        } finally {
            busy = false;
            inspectButton.disabled = false;
            convertButton.disabled = Boolean(inspected?.scopeId && scopeInput?.value === 'page');
        }
    } );

    let previewGeneration = 0;
    const previewScrollLock = (() => {
        // Keep the WordPress page stationary only while the pointer is inside
        // the iframe; the iframe itself remains independently scrollable.
        let locked = false;
        let previousOverflow = '';
        const lock = () => {
            if ( locked ) return;
            locked = true;
            previousOverflow = document.documentElement.style.overflow;
            document.documentElement.style.overflow = 'hidden';
        };
        const unlock = () => {
            if ( !locked ) return;
            locked = false;
            document.documentElement.style.overflow = previousOverflow;
        };
        frame.addEventListener( 'pointerenter', lock );
        frame.addEventListener( 'pointerleave', unlock );
        // Moving to another top-level stage or leaving the page also releases it.
        root.querySelectorAll( '.sm-team-tabs [role="tab"]' ).forEach( tab => tab.addEventListener( 'click', unlock ) );
        window.addEventListener( 'pagehide', unlock );
        document.addEventListener( 'visibilitychange', () => { if ( document.hidden ) unlock(); } );
        return { unlock };
    })();
    const paintPreview = () => {
        const item = prepared[ cursor ];
        if ( !item ) return;
        const token = ++previewGeneration;
        // Ensure scroll chain never escapes the iframe at its top/bottom edges.
        const scrollStyle = '<style>html,body{overscroll-behavior:contain}</style>';
        const previewDocument = item.preview.replace( '</head>', scrollStyle + '</head>' );
        frame.removeAttribute( 'srcdoc' );
        // Only mount srcdoc after the Results panel has become visible.
        // A hidden sandboxed iframe can otherwise initialize as a blank document.
        requestAnimationFrame( () => {
            if ( token !== previewGeneration || artifacts.hidden ) return;
            frame.srcdoc = previewDocument;
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
        syncPreviewNav();
    };
    // Responsive results navbar: only fully visible tabs; a fixed-width picker
    // appears when the complete title strip exceeds the available width.
    let firstVisible = 0;
    let tabWidths = [];
    const tabGap = 5;
    const closePicker = () => {
        pickerMenu.hidden = true;
        picker.setAttribute( 'aria-expanded', 'false' );
    };
    const fitTabsFrom = ( start, available ) => {
        let used = 0;
        let count = 0;
        for ( let i = start; i < tabWidths.length; i++ ) {
            const needed = Math.min( tabWidths[ i ], available ) + ( count ? tabGap : 0 );
            if ( count && used + needed > available + 0.5 ) break;
            used += needed;
            count++;
        }
        return Math.max( 1, count );
    };
    const showVisibleTabs = () => {
        if ( previewNav.hidden ) return;
        const tabs = Array.from( previewTabs.children );
        if ( pager.hidden ) {
            firstVisible = 0;
            tabs.forEach( tab => { tab.hidden = false; tab.style.width = ''; } );
            return;
        }
        const available = tabWindow.clientWidth;
        if ( !available ) return;
        if ( cursor < firstVisible ) firstVisible = cursor;
        while ( cursor >= firstVisible + fitTabsFrom( firstVisible, available ) && firstVisible < cursor ) {
            firstVisible++;
        }
        const count = fitTabsFrom( firstVisible, available );
        tabs.forEach( ( tab, index ) => {
            tab.hidden = index < firstVisible || index >= firstVisible + count;
            tab.style.width = !tab.hidden && tabWidths[ index ] > available ? available + 'px' : '';
        } );
    };
    const reflowPreviewNav = () => {
        if ( previewNav.hidden || prepared.length < 2 ) return;
        const fullWidth = tabWidths.reduce( ( sum, width ) => sum + width, 0 ) + ( tabWidths.length - 1 ) * tabGap;
        const overflow = fullWidth > previewNav.clientWidth + 0.5;
        pager.hidden = !overflow;
        if ( !overflow ) firstVisible = 0;
        showVisibleTabs();
    };
    syncPreviewNav = () => {
        if ( previewNav.hidden ) return;
        pickerLabel.textContent = prepared[ cursor ]?.title || '';
        pickerLabel.title = pickerLabel.textContent;
        prevPage.disabled = cursor === 0;
        nextPage.disabled = cursor === prepared.length - 1;
        Array.from( previewTabs.children ).forEach( ( tab, index ) => {
            const active = index === cursor;
            tab.setAttribute( 'aria-selected', String( active ) );
            tab.tabIndex = active ? 0 : -1;
        } );
        Array.from( pickerMenu.children ).forEach( ( option, index ) => option.setAttribute( 'aria-selected', String( index === cursor ) ) );
        closePicker();
        showVisibleTabs();
    };
    buildPreviewNav = () => {
        previewNav.hidden = prepared.length < 2;
        pickerMenu.replaceChildren();
        previewTabs.replaceChildren();
        tabWidths = [];
        firstVisible = 0;
        closePicker();
        if ( previewNav.hidden ) return;
        for ( const [ index, item ] of prepared.entries() ) {
            const tab = document.createElement( 'button' );
            tab.type = 'button';
            tab.className = 'sm-team-preview-tab';
            tab.setAttribute( 'role', 'tab' );
            tab.title = item.title;
            tab.textContent = item.title;
            tab.addEventListener( 'click', () => showPreview( index ) );
            previewTabs.append( tab );
            const option = document.createElement( 'button' );
            option.type = 'button';
            option.className = 'sm-team-preview-option';
            option.setAttribute( 'role', 'option' );
            option.title = item.title;
            option.textContent = item.title;
            option.addEventListener( 'click', () => {
                showPreview( index );
                picker.focus();
            } );
            pickerMenu.append( option );
        }
        // Measure natural tab widths before clipping. Never scroll the iframe.
        tabWidths = Array.from( previewTabs.children, tab => tab.getBoundingClientRect().width );
        reflowPreviewNav();
    };
    prevPage.addEventListener( 'click', () => { if ( cursor > 0 ) showPreview( cursor - 1 ); } );
    nextPage.addEventListener( 'click', () => { if ( cursor < prepared.length - 1 ) showPreview( cursor + 1 ); } );
    picker.addEventListener( 'click', () => {
        const opening = pickerMenu.hidden;
        pickerMenu.hidden = !opening;
        picker.setAttribute( 'aria-expanded', String( opening ) );
        if ( opening ) pickerMenu.children[ cursor ]?.scrollIntoView( { block: 'nearest' } );
    } );
    document.addEventListener( 'click', event => {
        if ( !pager.contains( event.target ) ) closePicker();
    } );
    pickerMenu.addEventListener( 'keydown', event => {
        if ( event.key === 'Escape' ) {
            closePicker();
            picker.focus();
        }
    } );
    window.addEventListener( 'keydown', event => {
        if ( event.key === 'Escape' ) closePicker();
    } );
    if ( typeof ResizeObserver !== 'undefined' ) {
        new ResizeObserver( () => reflowPreviewNav() ).observe( previewNav );
    } else {
        window.addEventListener( 'resize', reflowPreviewNav );
    }
    viewResult.addEventListener( 'click', () => {
        if ( !prepared.length || artifacts.hidden ) return;
        stage( 'results' );
        $( 'sm-team-tab-results' ).scrollIntoView( { behavior: 'smooth', block: 'start' } );
        requestAnimationFrame( reflowPreviewNav );
    } );
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
        const jsMode = Boolean( jsOptions[ kind ]?.checked );
        busy = true;
        showError( convertError, '' );
        convertButton.disabled = true;
        saveButton.disabled = true;
        artifacts.hidden = true;
        previewNav.hidden = true;
        viewResult.hidden = true;
        previewScrollLock.unlock();
        prepared = [];
        activeJob = null;
        savedModes.clear();
        savedDraftIds.length = 0;
        stage( 'production' );
        jobState.textContent = 'Converting';
        let archive = null;
        const archiveEvent = (type, data) => request(SyndaMorphTeam.historyUrl + '/' + archive.id + '/reference-event', {type,data});
        try {
            let pages = [];
            if ( kind === 'url' ) {
                const section = chosen.sections.find( item => item.id === sectionSelect.value );
                if ( !section ) throw new Error( 'Select a captured section.' );
                const sourceSection = chosen.scopeId ? await scopeAdapter.preview( section.id ) : section;
                if ( !sourceSection.html ) throw new Error( 'This section has no usable captured HTML.' );
                pages = [ { path: section.id, title: section.label || section.id, html: sourceSection.html } ];
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
            const jobTitle = pages.length === 1 ? pages[0].title + ' — Gutenberg Conversion' :
                pages.length + ' pages — Gutenberg Package Conversion';
            const reference = {kind, selected: pages.map(p => p.path), jsMode};
            if (kind === 'url') { reference.url = chosen.url; reference.capture = chosen.snapshot; }
            if (kind === 'html') { reference.filename = pages[0].path; reference.html = pages[0].html; }
            if (kind === 'package') {
                reference.manifest = chosen.manifest;
                const form = new FormData();
                form.append('title', jobTitle);
                form.append('reference', JSON.stringify(reference));
                form.append('package', pick.file('package'));
                archive = await request(SyndaMorphTeam.historyUrl + '/reference', form, true);
            } else {
                archive = await request(SyndaMorphTeam.historyUrl + '/reference', {title:jobTitle, reference});
            }
            currentJob.textContent = archive.jobId + ' : ' + archive.title;
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
                const artifact = { ...page, content: converted.content, preview: converted.preview,
                    missing: [ ...missing, ...converted.missing ], css, stylesheets: converted.stylesheets };
                await archiveEvent('reference_page', {path:page.path, title:page.title, source, artifact});
                next.push(artifact);

            }
            if ( generation !== sourceGeneration ) return;
            prepared = next;
            await archiveEvent('job_complete', {status:'converted', pageCount:next.length, aiCalls:0});
            activeJob = { id: archive.jobId, archiveId:archive.id, kind, token: chosen.token, jsMode };
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
            refreshSaveAction();
            stage( 'results' );
            buildPreviewNav();
            showPreview( 0 );
            viewResult.textContent = prepared.length > 1 ? 'View Results' : 'View Result';
            viewResult.hidden = false;
        } catch ( error ) {
            if (archive) { try { await archiveEvent('job_error', {message:error.message || String(error)}); } catch (_) {} }
            showError( convertError, 'Conversion failed: ' + ( error.message || String( error ) ) );
            jobState.textContent = 'Conversion error';
            outputState.textContent = 'Not ready';
            stage( 'setup' );
        } finally {
            busy = false;
            convertButton.disabled = false;
            refreshSaveAction();
        }
    } );

    const saveOutput = async ( openEditor = false ) => {
        const mode = saveKind.value;
        if ( busy || !prepared.length || !activeJob ) return;
        if ( savedModes.has( mode ) ) {
            if ( openEditor && mode === 'draft' && savedDraftIds.length ) window.location.assign( editorUrl( savedDraftIds[ 0 ] ) );
            return;
        }
        busy = true;
        saveButton.dataset.state = 'saving';
        saveOpenButton.dataset.state = 'saving';
        saveOpenButton.disabled = true;
        saveButton.disabled = true;
        saveKind.disabled = true;
        saveStatus.textContent = 'Saving ' + ( mode === 'draft' ? 'draft pages' : 'unsynced patterns' ) + '…';
        try {
            const kind = activeJob.kind;
            let result;
            let statusMessage;
            let createdDraftIds = [];
            if ( kind === 'package' ) {
                let token = activeJob.token;
                let items = prepared.map( item => ( {
                    path: item.path, title: item.title, content: item.content,
                } ) );
                if ( savedModes.size ) {
                    // Package commits invalidate their one-time token. A second
                    // export requires fresh signed asset URLs and block content.
                    const file = pick.file( 'package' );
                    if ( !file ) throw new Error( 'Package file is no longer selected. Select and scan it again.' );
                    const form = new FormData();
                    form.append( 'package', file );
                    const scanned = await request( SyndaMorphTeam.packageScanUrl, form, true );
                    token = scanned.token;
                    const refreshed = [];
                    for ( const item of prepared ) {
                        const page = await request( SyndaMorphTeam.packagePageUrl, {
                            token, page: item.path, js_mode: activeJob.jsMode,
                        } );
                        const converted = await SyndaMorphConverter.prepareImport( page.html, activeJob.jsMode );
                        refreshed.push( {
                            path: item.path, title: item.title, content: converted.content,
                        } );
                    }
                    items = refreshed;
                }
                result = await request( SyndaMorphTeam.packageCommitUrl, {
                    token, mode, pages: items,
                } );
                if ( mode === 'draft' ) createdDraftIds = result.created.map( entry => Number( entry.id ) ).filter( Number.isSafeInteger );
                statusMessage = result.created.length + ' ' + ( mode === 'draft' ? 'draft(s)' : 'pattern(s)' ) +
                    ' created. ' + result.uploaded_images + ' images uploaded; ' +
                    result.reused_images + ' references reused.';
            } else if ( mode === 'pattern' ) {
                result = await request( SyndaMorphImport.patternUrl, {
                    title: prepared[ 0 ].title, content: prepared[ 0 ].content,
                } );
                statusMessage = 'Pattern saved in Gutenberg → Patterns → SyndaMorph.';
            } else {
                result = await request( SyndaMorphImport.restUrl, {
                    title: prepared[ 0 ].title, content: prepared[ 0 ].content, status: 'draft',
                } );
                createdDraftIds = [ Number( result.id ) ].filter( Number.isSafeInteger );
                statusMessage = 'Draft saved. Open it through the WordPress Pages screen.';
            }
            if (activeJob.archiveId) {
                try { await request(SyndaMorphTeam.historyUrl + '/' + activeJob.archiveId + '/reference-event', {type:'reference_saved', data:{mode, result, message:statusMessage}}); }
                catch (_) { statusMessage += ' History save receipt could not be recorded.'; }
            }
            if ( mode === 'draft' ) savedDraftIds.push( ...createdDraftIds );
            savedModes.set( mode, statusMessage );
            previewState.textContent = 'Saved';
            previewState.dataset.state = 'success';
            if ( openEditor && mode === 'draft' && savedDraftIds.length ) window.location.assign( editorUrl( savedDraftIds[ 0 ] ) );
        } catch ( error ) {
            saveStatus.textContent = 'Save failed: ' + ( error.message || String( error ) );
        } finally {
            busy = false;
            saveKind.disabled = false;
            // Preserve error messages; successful saves use the per-mode state.
            if ( savedModes.has( mode ) ) refreshSaveAction();
            else {
                saveButton.dataset.state = 'ready';
                saveOpenButton.dataset.state = 'ready';
                saveButton.disabled = false;
                saveOpenButton.disabled = mode !== 'draft';
            }
        }
    };
    saveButton.addEventListener( 'click', () => saveOutput( false ) );
    saveOpenButton.addEventListener( 'click', () => saveOutput( true ) );
} )();
