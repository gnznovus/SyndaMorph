/* global SyndaMorphTeam */
( function () {
    'use strict';
    const byId = id => document.getElementById( id );
    const root = byId( 'syndamorph-production-team' );
    if ( !root ) return;

    const sourceType = byId( 'syndamorph-team-source-type' );
    const url = byId( 'syndamorph-team-source' );
    const html = byId( 'syndamorph-team-html' );
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
            if ( !html.value.trim() ) return 'Paste HTML source to start the demonstration.';
        } else if ( kind === 'package' ) {
            if ( !file.files || !file.files[ 0 ] || !/\.zip$/i.test( file.files[ 0 ].name ) ) return 'Select a ZIP package.';
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
    // These are authored demonstration updates, not model thoughts or verified actions.
    const stages = [
        { agent: 'boss', name: 'PM / Boss Agent', type: 'Planning summary', action: 'Classified the source and assigned design planning. Demo job title assigned; genuine PM classification is pending.' },
        { agent: 'designer', name: 'Designer Agent', type: 'Handoff', action: 'Acknowledged design responsibility. No actual source layout has been analyzed.' },
        { agent: 'developer', name: 'Developer Agent', type: 'Handoff', action: 'Acknowledged Gutenberg implementation responsibility. No blocks or scripts were generated.' },
        { agent: 'boss', name: 'PM / Boss Agent', type: 'Review', action: 'Confirmed four simulated communication stages. No artifacts or visual validations exist yet.' },
    ];
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
            ? 'All four predefined handoffs were displayed. No source capture, AI reasoning, Gutenberg patterns, custom blocks, or fidelity tests were performed.'
            : 'Handoffs are being displayed one at a time. This is a UI animation, not production execution.';
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
        setText( handoffs, '00 / 04' );
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
        stamp.textContent = 'DEMO 0' + ( index + 1 );
        li.append( stamp, title, text );
        timeline.append( li );
        setAgentStates( index + 1 );
        setText( handoffs, String( index + 1 ).padStart( 2, '0' ) + ' / 04' );
        li.scrollIntoView( { block: 'nearest', behavior: 'smooth' } );
    }
    function begin() {
        const error = validateSource();
        if ( error ) { notice.textContent = error; return; }
        stopSimulation();
        const ticket = generation;
        const id = 'DEMO-' + Date.now().toString( 36 ).toUpperCase();
        current = { id, title: demoTitle( sourceType.value ), sourceType: sourceType.value };
        setText( currentJob, current.id + ' : ' + current.title );
        setText( resultTitle, current.id + ' : ' + current.title );
        setText( jobState, 'Simulating' );
        setText( outputState, 'Not generated' );
        setText( handoffs, '00 / 04' );
        setText( feedState, 'Streaming demo' );
        feedState.dataset.state = 'running';
        setText( notice, 'Demo accepted · title is simulated, not AI-generated.' );
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
