/* global SyndaMorphTeam */
( function () {
    'use strict';
    const byId = id => document.getElementById( id );
    const root = byId( 'syndamorph-production-team' );
    if ( !root ) return;

    const sourceType = byId( 'syndamorph-team-source-type' );
    const url = byId( 'syndamorph-team-source' );
    const html = byId( 'syndamorph-team-html' );
    const htmlFile = byId( 'syndamorph-team-html-file' );
    const file = byId( 'syndamorph-team-package' );
    const brief = byId( 'syndamorph-team-brief' );
    const run = byId( 'syndamorph-team-run' );
    const reset = byId( 'syndamorph-team-reset' );
    const timeline = byId( 'syndamorph-team-timeline' );
    const review = byId( 'syndamorph-team-review' );
    const resultTitle = byId( 'syndamorph-team-result-title' );
    const currentJob = byId( 'syndamorph-team-current-job' );
    const jobState = byId( 'syndamorph-team-job-state' );
    const handoffs = byId( 'syndamorph-team-handoffs' );
    const outputState = byId( 'syndamorph-team-output-state' );
    const feedState = byId( 'syndamorph-team-feed-state' );
    const notice = byId( 'syndamorph-team-validation' );
    const tabs = Array.from( root.querySelectorAll( '.sm-team-tabs [role="tab"]' ) );
    const panels = Array.from( root.querySelectorAll( '.sm-team-panel' ) );
    let activeTimer = null;
    let generation = 0;
    let current = null;

    function setText( node, text ) { if ( node ) node.textContent = text; }
    function selectTab( tab, focus = false ) {
        for ( const button of tabs ) {
            const selected = button.dataset.panel === tab;
            button.setAttribute( 'aria-selected', String( selected ) );
            button.tabIndex = selected ? 0 : -1;
            if ( selected && focus ) button.focus();
        }
        for ( const panel of panels ) panel.hidden = panel.dataset.panel !== tab;
    }
    tabs.forEach( ( button, index ) => {
        button.addEventListener( 'click', () => selectTab( button.dataset.panel ) );
        button.addEventListener( 'keydown', event => {
            let next = index;
            if ( event.key === 'ArrowRight' ) next = ( index + 1 ) % tabs.length;
            else if ( event.key === 'ArrowLeft' ) next = ( index + tabs.length - 1 ) % tabs.length;
            else if ( event.key === 'Home' ) next = 0;
            else if ( event.key === 'End' ) next = tabs.length - 1;
            else return;
            event.preventDefault();
            selectTab( tabs[ next ].dataset.panel, true );
        } );
    } );
    function showSource() {
        root.querySelectorAll( '.sm-team-source-input' ).forEach( element => {
            element.hidden = element.dataset.source !== sourceType.value;
        } );
    }
    sourceType.addEventListener( 'change', showSource );
    showSource();

    // Both picker and drop interactions use one validation gate.
    // This is client-side UX validation; real imports still require server validation.
    const accepted = { html: null, package: null };
    const uploadConfig = {
        html: { input: htmlFile, labelId: 'syndamorph-team-html-file-name', empty: 'Drop HTML / HTM here or browse', pattern: /\.html?$/i, type: 'HTML or HTM' },
        package: { input: file, labelId: 'syndamorph-team-package-name', empty: 'Drop ZIP here or browse', pattern: /\.zip$/i, type: 'ZIP' },
    };
    function uploadFeedback( kind, message, isError = false ) {
        const node = root.querySelector( '[data-upload-feedback="' + kind + '"]' );
        if ( node ) {
            node.textContent = message;
            node.dataset.state = isError ? 'error' : 'ok';
        }
    }
    function updateFileLabel( kind ) {
        const config = uploadConfig[ kind ];
        setText( byId( config.labelId ), accepted[ kind ] ? accepted[ kind ].name : config.empty );
    }
    function restorePicker( kind ) {
        const input = uploadConfig[ kind ].input;
        const previous = accepted[ kind ];
        try {
            const transfer = new DataTransfer();
            if ( previous ) transfer.items.add( previous );
            input.files = transfer.files;
        } catch ( _error ) {
            // The accepted-file state remains authoritative in environments
            // that do not allow assigning a FileList to an input.
            input.value = '';
        }
    }
    function checkFile( kind, selected ) {
        const config = uploadConfig[ kind ];
        if ( !selected || !config.pattern.test( selected.name ) ) {
            return 'Rejected: only ' + config.type + ' files are accepted here.';
        }
        return '';
    }
    let htmlReadToken = 0;
    async function acceptFile( kind, selected, fromDrop = false ) {
        // Cancel previous HTML preview reads when any new HTML selection occurs.
        const readToken = kind === 'html' ? ++htmlReadToken : 0;
        const error = checkFile( kind, selected );
        if ( error ) {
            uploadFeedback( kind, error, true );
            setText( notice, error );
            if ( !fromDrop ) restorePicker( kind );
            return;
        }

        // Large HTML files are still valid source references. Only populate the
        // textarea for small files to avoid freezing the WordPress admin UI.
        const previewLimitBytes = 2 * 1024 * 1024;
        let previewed = false;
        if ( kind === 'html' && selected.size <= previewLimitBytes ) {
            try {
                const markup = await selected.text();
                if ( readToken !== htmlReadToken ) return;
                html.value = markup;
                previewed = true;
            } catch ( _error ) {
                if ( readToken !== htmlReadToken ) return;
                // File selection is valid even if optional textarea preview fails.
            }
        }
        accepted[ kind ] = selected;
        if ( fromDrop ) restorePicker( kind );
        updateFileLabel( kind );
        const message = kind === 'package'
            ? 'Accepted · ZIP selected locally.'
            : previewed
                ? 'Accepted · HTML loaded locally into the editor.'
                : 'Accepted · HTML selected. Preview skipped to keep the editor responsive.';
        uploadFeedback( kind, message );
        setText( notice, 'File accepted locally. No upload or conversion was performed.' );
    }
    for ( const kind of Object.keys( uploadConfig ) ) {
        const config = uploadConfig[ kind ];
        const zone = root.querySelector( '[data-drop-kind="' + kind + '"]' );
        config.input.addEventListener( 'change', () => {
            const selected = config.input.files && config.input.files[ 0 ];
            if ( selected ) void acceptFile( kind, selected );
            else {
                accepted[ kind ] = null;
                if ( kind === 'html' ) ++htmlReadToken;
                updateFileLabel( kind );
                uploadFeedback( kind, '' );
            }
        } );
        if ( !zone ) continue;
        let dragDepth = 0;
        zone.addEventListener( 'dragenter', event => {
            event.preventDefault();
            dragDepth++;
            zone.classList.add( 'is-dragging' );
        } );
        zone.addEventListener( 'dragover', event => {
            event.preventDefault();
            if ( event.dataTransfer ) event.dataTransfer.dropEffect = 'copy';
        } );
        zone.addEventListener( 'dragleave', event => {
            event.preventDefault();
            dragDepth = Math.max( 0, dragDepth - 1 );
            if ( dragDepth === 0 ) zone.classList.remove( 'is-dragging' );
        } );
        zone.addEventListener( 'drop', event => {
            event.preventDefault();
            dragDepth = 0;
            zone.classList.remove( 'is-dragging' );
            const files = event.dataTransfer && event.dataTransfer.files;
            if ( !files || files.length !== 1 ) {
                uploadFeedback( kind, 'Rejected: drop exactly one ' + config.type + ' file.', true );
                return;
            }
            void acceptFile( kind, files[ 0 ], true );
        } );
        zone.addEventListener( 'keydown', event => {
            if ( event.target !== zone || ( event.key !== 'Enter' && event.key !== ' ' ) ) return;
            event.preventDefault();
            config.input.click();
        } );
    }

    // Shared, read-only selection access for the migrated deterministic workflow.
    // The existing Importer stays untouched during the parity phase.
    window.SyndaMorphTeamSelection = Object.freeze( {
        file: kind => accepted[ kind ],
        sourceType: () => sourceType.value,
        brief: () => brief.value.trim(),
        html: () => html.value,
        url: () => url.value.trim(),
    } );

    // The PM will generate job titles during real classification. UI-only names
    // are deliberately marked simulated, never represented as AI inference.
    function demoTitle( kind ) {
        return {
            url: 'Website Source — Gutenberg Conversion',
            html: 'HTML Source — Gutenberg Conversion',
            package: 'ZIP Package — Gutenberg Conversion',
        }[ kind ];
    }
    function validateSource() {
        const kind = sourceType.value;
        if ( kind === 'url' ) {
            const value = url.value.trim();
            if ( !value ) return 'Enter a source URL.';
            try {
                const parsed = new URL( value );
                if ( ![ 'http:', 'https:' ].includes( parsed.protocol ) || !parsed.hostname ) throw new Error( 'Invalid' );
            } catch ( _error ) { return 'Source must be a valid HTTP(S) URL.'; }
        } else if ( kind === 'html' ) {
            if ( !accepted.html && !html.value.trim() ) return 'Paste HTML or choose an HTML file to start the demonstration.';
        } else if ( kind === 'package' ) {
            if ( !accepted.package ) return 'Select a ZIP package.';
        }
        if ( !brief.value.trim() ) return 'Describe the task in the production brief.';
        return '';
    }
    function setAgentStates( completed ) {
        const doneAgents = new Set( stages.slice( 0, completed ).map( stage => stage.agent ) );
        root.querySelectorAll( '[data-agent]' ).forEach( node => {
            const pill = node.querySelector( '.sm-status-pill' );
            const done = doneAgents.has( node.dataset.agent );
            pill.textContent = done ? 'Acknowledged' : 'Standing by';
            pill.dataset.state = done ? 'success' : 'idle';
        } );
    }
    // These are authored intent-pool lines. No AI calls or verified actions.
    let stages = [];
    const demoCount = () => String( stages.length || window.SyndaMorphDemoDialogue.FLOW.length ).padStart( 2, '0' );
    function stopSimulation() {
        generation += 1;
        if ( activeTimer !== null ) window.clearTimeout( activeTimer );
        activeTimer = null;
    }
    function renderReview( completed ) {
        review.replaceChildren();
        const heading = document.createElement( 'strong' );
        heading.textContent = completed ? 'Simulation completed · artifacts not generated' : 'Simulation in progress';
        const detail = document.createElement( 'p' );
        detail.textContent = completed
            ? 'All authored intent-pool messages were displayed. No source capture, AI reasoning, Gutenberg patterns, custom blocks, or fidelity tests were performed.'
            : 'Authored dialogue is displayed one message at a time. This is entertainment, not production execution.';
        review.append( heading, detail );
    }
    function resetState() {
        stopSimulation();
        current = null;
        timeline.replaceChildren();
        const li = document.createElement( 'li' );
        li.className = 'sm-team-empty';
        li.textContent = 'No activity yet. Start a simulation from Setup.';
        timeline.append( li );
        setText( currentJob, 'No job submitted' );
        setText( resultTitle, 'No job submitted' );
        setText( jobState, 'Not started' );
        setText( handoffs, '00 / ' + demoCount() );
        setText( outputState, 'Not generated' );
        setText( feedState, 'Idle' );
        feedState.dataset.state = 'idle';
        setText( notice, 'UI simulation only · no production job, source capture, or AI inference.' );
        review.replaceChildren();
        const heading = document.createElement( 'strong' );
        heading.textContent = 'No output available';
        const message = document.createElement( 'p' );
        message.textContent = 'Start a simulation from Setup. No artifacts exist yet.';
        review.append( heading, message );
        setAgentStates( 0 );
    }
    function appendStage( index ) {
        const stage = stages[ index ];
        const li = document.createElement( 'li' );
        li.className = 'sm-team-step';
        const title = document.createElement( 'strong' );
        title.textContent = stage.name + ' · ' + stage.type;
        const text = document.createElement( 'p' );
        text.textContent = stage.action;
        const stamp = document.createElement( 'span' );
        stamp.className = 'sm-team-step-count';
        stamp.textContent = 'DEMO ' + String( index + 1 ).padStart( 2, '0' );
        li.append( stamp, title, text );
        timeline.append( li );
        setAgentStates( index + 1 );
        setText( handoffs, String( index + 1 ).padStart( 2, '0' ) + ' / ' + demoCount() );
        li.scrollIntoView( { block: 'nearest', behavior: 'smooth' } );
    }
    function begin() {
        const error = validateSource();
        if ( error ) { notice.textContent = error; return; }
        stopSimulation();
        stages = window.SyndaMorphDemoDialogue.buildConversation( { kind: sourceType.value, brief: brief.value.trim() } );
        const ticket = generation;
        const id = 'DEMO-' + Date.now().toString( 36 ).toUpperCase();
        current = { id, title: demoTitle( sourceType.value ), sourceType: sourceType.value };
        setText( currentJob, current.id + ' : ' + current.title );
        setText( resultTitle, current.id + ' : ' + current.title );
        setText( jobState, 'Simulating' );
        setText( outputState, 'Not generated' );
        setText( handoffs, '00 / ' + demoCount() );
        setText( feedState, 'Rule-based demo' );
        feedState.dataset.state = 'running';
        setText( notice, 'Rule-based entertainment · no AI inference or generated artifacts.' );
        timeline.replaceChildren();
        setAgentStates( 0 );
        renderReview( false );
        selectTab( 'production' );
        let index = 0;
        const tick = () => {
            if ( ticket !== generation ) return;
            appendStage( index++ );
            if ( index < stages.length ) {
                activeTimer = window.setTimeout( tick, 950 );
                return;
            }
            activeTimer = null;
            setText( jobState, 'Demo completed' );
            setText( feedState, 'Demo completed' );
            feedState.dataset.state = 'success';
            renderReview( true );
        };
        activeTimer = window.setTimeout( tick, 250 );
    }
    run.addEventListener( 'click', begin );
    reset.addEventListener( 'click', () => { resetState(); selectTab( 'setup' ); } );

    // Retain the existing live Purr POC as a separate, explicitly identified check.
    const purrRun = byId( 'syndamorph-purr-run' );
    const purrState = byId( 'syndamorph-purr-status' );
    const purrTranscript = byId( 'syndamorph-purr-transcript' );
    if ( purrRun && purrState && purrTranscript ) {
        purrRun.addEventListener( 'click', async () => {
            if ( purrRun.disabled ) return;
            const value = current ? current.title : 'Gutenberg production team communication demonstration';
            purrRun.disabled = true;
            purrState.textContent = 'Connecting';
            purrState.dataset.state = 'running';
            purrTranscript.textContent = 'Opening Purr session and exchanging four messages…';
            try {
                const response = await fetch( SyndaMorphTeam.purrPocUrl, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': SyndaMorphTeam.nonce },
                    body: JSON.stringify( { job: value } ),
                } );
                const data = await response.json();
                if ( !response.ok || !data.success || !data.session_created || data.steps_completed !== 4 ||
                     !Array.isArray( data.conversation ) ) throw new Error( 'Incomplete Purr response' );
                purrTranscript.replaceChildren();
                data.conversation.forEach( item => {
                    const row = document.createElement( 'article' );
                    row.className = 'sm-purr-message';
                    const label = document.createElement( 'strong' );
                    label.textContent = item.step + '. ' + item.agent + ' · ' + item.action;
                    const message = document.createElement( 'p' );
                    message.textContent = item.message;
                    row.append( label, message );
                    purrTranscript.append( row );
                } );
                purrState.textContent = '4 / 4 received';
                purrState.dataset.state = 'success';
            } catch ( _error ) {
                purrState.textContent = 'Failed';
                purrState.dataset.state = 'error';
                purrTranscript.textContent = 'Communication test could not complete. Check Purr configuration and Gateway availability.';
            } finally { purrRun.disabled = false; }
        } );
    }
    selectTab( 'setup' );
} )();
