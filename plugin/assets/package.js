/* global SyndaMorphPackage, SyndaMorphConverter */
( function () {
    'use strict';
    const $ = ( selector ) => document.querySelector( selector );
    const endpoint = async ( url, payload, file = false ) => {
        const headers = { 'X-WP-Nonce': SyndaMorphPackage.nonce };
        if ( ! file ) { headers['Content-Type'] = 'application/json'; }
        const response = await fetch( url, {
            method: 'POST',
            credentials: 'same-origin',
            headers,
            body: file ? payload : JSON.stringify( payload ),
        } );
        let result;
        try { result = await response.json(); }
        catch ( error ) { throw new Error( 'Server returned an invalid response (HTTP ' + response.status + ').' ); }
        if ( ! response.ok ) { throw new Error( result.message || result.error || 'HTTP ' + response.status ); }
        return result;
    };
    const titleFor = ( path ) => path.split( '/' ).pop().replace( /\.html?$/i, '' ).replace( /[-_]+/g, ' ' );

    document.addEventListener( 'DOMContentLoaded', () => {
        const file = $( '#syndamorph-package-file' );
        const scan = $( '#syndamorph-package-scan' );
        const status = $( '#syndamorph-package-status' );
        const results = $( '#syndamorph-package-results' );
        const summary = $( '#syndamorph-package-summary' );
        const pagesElement = $( '#syndamorph-package-pages' );
        const preview = $( '#syndamorph-package-preview' );
        const modal = $( '#syndamorph-package-modal' );
        const modalTitle = $( '#syndamorph-package-modal-title' );
        const progress = $( '#syndamorph-package-progress' );
        const frame = $( '#syndamorph-package-frame' );
        const warnings = $( '#syndamorph-package-warnings' );
        const previous = $( '#syndamorph-package-prev' );
        const next = $( '#syndamorph-package-next' );
        const close = $( '#syndamorph-package-close' );
        const mode = $( '#syndamorph-package-mode' );
        const approve = $( '#syndamorph-package-approve' );
        const jsMode = $( '#syndamorph-js-render' );
        if ( ! file || ! scan || ! status || ! results || ! summary || ! pagesElement ||
             ! preview || ! modal || ! frame || ! warnings || ! approve ) { return; }

        let packageInfo = null;
        let selected = [];
        let prepared = new Map();
        let cursor = 0;
        let busy = false;
        let open = false;

        const clearStage = () => {
            packageInfo = null;
            selected = [];
            prepared.clear();
            results.hidden = true;
            closeModal();
        };
        const closeModal = () => {
            if ( busy ) { return; }
            open = false;
            modal.hidden = true;
            frame.removeAttribute( 'srcdoc' );
            document.body.classList.remove( 'syndamorph-modal-open' );
            preview.focus();
        };
        const refreshSelected = () => {
            if ( ! packageInfo ) { return; }
            selected = Array.from( pagesElement.querySelectorAll( 'input[type="checkbox"]:checked' ) )
                .map( ( box ) => box.value );
            prepared.clear();
            approve.disabled = true;
            preview.disabled = selected.length === 0;
            status.textContent = selected.length + ' of ' + packageInfo.pages.length + ' pages selected.';
        };
        const updateNavigation = () => {
            const complete = selected.every( ( page ) => prepared.has( page ) );
            previous.disabled = busy || cursor === 0;
            next.disabled = busy || cursor === selected.length - 1;
            approve.disabled = busy || ! complete || ! open;
            progress.textContent = 'Page ' + ( cursor + 1 ) + ' of ' + selected.length +
                ' · ' + prepared.size + ' reviewed';
        };
        const showPage = async ( index ) => {
            if ( busy || ! open || index < 0 || index >= selected.length ) { return; }
            busy = true;
            cursor = index;
            const page = selected[ index ];
            modalTitle.textContent = titleFor( page );
            progress.textContent = 'Preparing ' + ( index + 1 ) + ' / ' + selected.length + '…';
            frame.removeAttribute( 'srcdoc' );
            warnings.hidden = true;
            updateNavigation();
            try {
                if ( ! prepared.has( page ) ) {
                    const result = await endpoint( SyndaMorphPackage.page, {
                        token: packageInfo.token, page, js_mode: Boolean( jsMode?.checked ),
                    } );
                    const converted = await SyndaMorphConverter.prepareImport( result.html, Boolean( jsMode?.checked ) );
                    prepared.set( page, {
                        content: converted.content, preview: converted.preview,
                        missing: [ ...result.missing, ...converted.missing.map( ( i ) => 'Image: ' + i.source ) ],
                        css: result.css,
                    } );
                }
                const current = prepared.get( page );
                frame.srcdoc = current.preview;
                if ( current.missing.length ) {
                    warnings.textContent = current.missing.length + ' unresolved asset(s): ' + current.missing.join( ' · ' );
                    warnings.hidden = false;
                }
                status.textContent = 'Reviewed: ' + page + ' (' + current.css + ' package stylesheet(s) loaded).';
            } catch ( error ) {
                status.textContent = 'Preview failed for ' + page + ': ' + ( error.message || error );
                warnings.textContent = status.textContent;
                warnings.hidden = false;
            } finally {
                busy = false;
                updateNavigation();
            }
        };

        file.addEventListener( 'change', clearStage );
        scan.addEventListener( 'click', async () => {
            if ( busy ) { return; }
            if ( ! file.files?.length ) { status.textContent = 'Choose a ZIP file first.'; return; }
            const chosen = file.files[ 0 ];
            if ( ! /\.zip$/i.test( chosen.name ) || chosen.size > 32 * 1024 * 1024 ) {
                status.textContent = 'Choose a .zip file under 32 MB.'; return;
            }
            clearStage();
            busy = true;
            scan.disabled = true;
            status.textContent = 'Scanning ZIP package…';
            try {
                const form = new FormData();
                form.append( 'package', chosen );
                packageInfo = await endpoint( SyndaMorphPackage.scan, form, true );
                summary.textContent = packageInfo.pages.length + ' HTML page(s), ' +
                    packageInfo.images + ' images, ' + packageInfo.assets +
                    ' other package assets · valid for 60 minutes';
                pagesElement.replaceChildren();
                packageInfo.pages.forEach( ( page ) => {
                    const row = document.createElement( 'label' );
                    row.className = 'syndamorph-package-page';
                    const checkbox = document.createElement( 'input' );
                    checkbox.type = 'checkbox';
                    checkbox.value = page;
                    checkbox.checked = true;
                    const text = document.createElement( 'span' );
                    text.textContent = page;
                    row.append( checkbox, text );
                    pagesElement.appendChild( row );
                    checkbox.addEventListener( 'change', refreshSelected );
                } );
                results.hidden = false;
                refreshSelected();
                status.textContent = 'Package scanned. Select pages and preview before import.';
            } catch ( error ) {
                status.textContent = 'Package scan failed: ' + ( error.message || error );
            } finally {
                busy = false;
                scan.disabled = false;
            }
        } );

        preview.addEventListener( 'click', () => {
            if ( ! packageInfo || ! selected.length || busy ) { return; }
            prepared.clear();
            cursor = 0;
            open = true;
            modal.hidden = false;
            document.body.classList.add( 'syndamorph-modal-open' );
            close.focus();
            showPage( 0 );
        } );
        previous.addEventListener( 'click', () => showPage( cursor - 1 ) );
        next.addEventListener( 'click', () => showPage( cursor + 1 ) );
        close.addEventListener( 'click', closeModal );
        modal.addEventListener( 'click', ( event ) => { if ( event.target === modal ) { closeModal(); } } );
        modal.addEventListener( 'keydown', ( event ) => {
            if ( event.key === 'Escape' && ! busy ) { event.preventDefault(); closeModal(); }
        } );
        if ( jsMode ) { jsMode.addEventListener( 'change', () => { prepared.clear(); if ( open && ! busy ) { showPage( cursor ); } } ); }

        approve.addEventListener( 'click', async () => {
            if ( ! packageInfo || busy || ! selected.length || ! selected.every( ( p ) => prepared.has( p ) ) ) { return; }
            busy = true;
            approve.disabled = true;
            previous.disabled = true;
            next.disabled = true;
            close.disabled = true;
            status.textContent = 'Importing approved pages and referenced images…';
            try {
                const response = await endpoint( SyndaMorphPackage.commit, {
                    token: packageInfo.token, mode: mode.value,
                    pages: selected.map( ( path ) => ( {
                        path,
                        title: titleFor( path ),
                        content: prepared.get( path ).content,
                    } ) ),
                } );
                close.disabled = false;
                busy = false;
                closeModal();
                const list = response.created.map( ( item ) => item.title ).join( ', ' );
                status.textContent = response.created.length + ' ' + ( mode.value === 'draft' ? 'drafts' : 'patterns' ) +
                    ' created: ' + list + '. ' + response.uploaded_images +
                    ' images uploaded; ' + response.reused_images + ' image references reused.';
                packageInfo = null;
                results.hidden = true;
                file.value = '';
            } catch ( error ) {
                status.textContent = 'Batch import failed: ' + ( error.message || error ) +
                    '. Any newly created pages/media in this request were rolled back where possible.';
                busy = false;
                close.disabled = false;
                updateNavigation();
            }
        } );
    } );
} )();
