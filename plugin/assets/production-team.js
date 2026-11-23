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

    function resetState() {
        root.dispatchEvent(new Event('syndamorph-team-reset'));
        if (timeline) timeline.replaceChildren();
        const empty = document.createElement('li');
        empty.className = 'sm-team-empty';
        empty.textContent = 'No activity yet. Start Production from Setup.';
        if (timeline) timeline.append(empty);
        setText(currentJob, 'No job submitted');
        setText(resultTitle, 'No job submitted');
        setText(jobState, 'Not started');
        setText(handoffs, '0 / 4 completed');
        setText(outputState, 'Not generated');
        setText(feedState, 'Idle');
        if (feedState) feedState.dataset.state = 'idle';
        setText(notice, 'Enter a production brief and select Start Production.');
        if (review) {
            review.replaceChildren();
            const title = document.createElement('strong');
            title.textContent = 'No output available';
            review.append(title);
        }
        root.querySelectorAll('[data-agent] .sm-status-pill').forEach(pill => {
            pill.textContent = 'Standing by';
            pill.dataset.state = 'idle';
        });
    }
    reset.addEventListener('click', () => { resetState(); selectTab('setup'); });
    selectTab( 'setup' );
} )();
