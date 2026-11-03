/* global SyndaMorphImport */
( function () {
    'use strict';
    const $ = ( selector ) => document.querySelector( selector );
    const escapeHtml = ( value ) => String( value ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
    const escapeAttribute = escapeHtml;
    const serializeBlock = ( node ) => {
        const tag = node.tagName.toLowerCase();
        if ( /^h[1-6]$/.test( tag ) ) {
            const content = node.textContent.trim();
            if ( ! content ) { return ''; }
            const level = Number( tag[ 1 ] );
            return '<!-- wp:heading {"level":' + level + '} --><h' + level + ' class="wp-block-heading">' + escapeHtml( content ) + '</h' + level + '><!-- /wp:heading -->';
        }
        if ( tag === 'p' ) {
            const content = node.textContent.trim();
            return content ? '<!-- wp:paragraph --><p>' + escapeHtml( content ) + '</p><!-- /wp:paragraph -->' : '';
        }
        if ( tag === 'img' ) {
            const source = ( node.getAttribute( 'src' ) || '' ).trim();
            if ( ! source ) { return ''; }
            let url;
            try {
                url = new URL( source, window.location.origin );
                if ( ! [ 'https:', 'http:' ].includes( url.protocol ) ) { return ''; }
            } catch ( error ) { return ''; }
            const alt = node.getAttribute( 'alt' ) || '';
            return '<!-- wp:image {"sizeSlug":"full","linkDestination":"none"} --><figure class="wp-block-image size-full"><img src="' + escapeAttribute( url.href ) + '" alt="' + escapeAttribute( alt ) + '"/></figure><!-- /wp:image -->';
        }
        return null;
    };

    document.addEventListener( 'DOMContentLoaded', () => {
        const file = $( '#syndamorph-html-file' );
        const input = $( '#syndamorph-html-input' );
        const title = $( '#syndamorph-page-title' );
        const button = $( '#syndamorph-convert' );
        const result = $( '#syndamorph-result' );
        if ( ! file || ! input || ! title || ! button || ! result ) { return; }
        file.addEventListener( 'change', async () => {
            const selected = file.files && file.files[ 0 ];
            if ( ! selected ) { return; }
            if ( ! /\.html?$/i.test( selected.name ) || selected.size > 2 * 1024 * 1024 ) {
                result.textContent = 'Choose an HTML file smaller than 2 MB.';
                file.value = '';
                return;
            }
            input.value = await selected.text();
            if ( ! title.value.trim() || title.value === 'Imported HTML' ) {
                title.value = selected.name.replace( /\.html?$/i, '' );
            }
            result.textContent = 'HTML loaded.';
        } );

        button.addEventListener( 'click', async () => {
            if ( ! input.value.trim() ) {
                result.textContent = 'Paste HTML or select an HTML file first.';
                return;
            }
            let stage = 'parse';
            button.disabled = true;
            try {
                result.textContent = 'Parsing HTML…';
                const doc = new DOMParser().parseFromString( input.value, 'text/html' );
                doc.querySelectorAll( 'script, style, iframe, object, embed, form, template' ).forEach( ( node ) => node.remove() );
                stage = 'convert';
                result.textContent = 'Converting supported elements…';
                const blocks = [];
                const elements = doc.body.querySelectorAll( 'h1,h2,h3,h4,h5,h6,p,img' );
                if ( elements.length > 500 ) {
                    throw new Error( 'POC limit: maximum 500 supported elements.' );
                }
                elements.forEach( ( element ) => {
                    // Avoid importing content twice when supported elements nest.
                    if ( element.parentElement && element.parentElement.closest( 'h1,h2,h3,h4,h5,h6,p' ) ) { return; }
                    const block = serializeBlock( element );
                    if ( block ) { blocks.push( block ); }
                } );
                if ( ! blocks.length ) {
                    throw new Error( 'No supported heading, paragraph, or image elements found.' );
                }

                stage = 'save';
                result.textContent = 'Saving WordPress draft…';
                const response = await fetch( SyndaMorphImport.restUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': SyndaMorphImport.nonce },
                    body: JSON.stringify( {
                        title: title.value.trim() || 'Imported HTML',
                        content: blocks.join( '\n\n' ),
                        status: 'draft',
                    } ),
                } );
                const data = await response.json();
                if ( ! response.ok || ! data.id ) {
                    throw new Error( ( data && data.message ) || 'WordPress draft creation failed (HTTP ' + response.status + ').' );
                }
                stage = 'open';
                result.textContent = 'Draft created. Opening Gutenberg…';
                window.location.assign( SyndaMorphImport.editUrl + '?post=' + encodeURIComponent( data.id ) + '&action=edit' );
            } catch ( error ) {
                result.textContent = 'Import failed [' + stage + ']: ' + ( error.message || String( error ) );
                console.error( '[SyndaMorph] Stage:', stage, error );
                button.disabled = false;
            }
        } );
    } );
} )();
