/* global SyndaMorphImport */
( function () {
    'use strict';

    const $ = ( selector ) => document.querySelector( selector );
    const escapeHtml = ( value ) => String( value ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
    const encodeAttributes = ( attributes ) => Object.keys( attributes ).length ? ' ' + JSON.stringify( attributes ) : '';
    const block = ( name, attributes, html ) => '<!-- wp:' + name + encodeAttributes( attributes ) + ' -->' + html + '<!-- /wp:' + name + ' -->';
    const allowedColor = ( value ) => {
        const color = ( value || '' ).trim().toLowerCase();
        if ( /^#[\da-f]{3}(?:[\da-f]{3})?$/i.test( color ) ) { return color; }
        // CSSOM often serializes #rrggbb as rgb(r, g, b).
        const rgb = color.match( /^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})(?:\s*,\s*([\d.]+))?\s*\)$/ );
        if ( ! rgb || ( rgb[ 4 ] !== undefined && Number( rgb[ 4 ] ) !== 1 ) ) { return ''; }
        const channels = rgb.slice( 1, 4 ).map( Number );
        if ( channels.some( ( channel ) => channel > 255 ) ) { return ''; }
        return '#' + channels.map( ( channel ) => channel.toString( 16 ).padStart( 2, '0' ) ).join( '' );
    };
    const acceptedAlign = ( value ) => [ 'left', 'center', 'right', 'justify' ].includes( value ) ? value : '';
    const childBlocks = ( node, context ) => Array.from( node.children ).map( ( child ) => convert( child, context ) ).filter( Boolean ).join( '\n\n' );
    const countBlock = ( context ) => {
        context.count++;
        if ( context.count > 500 ) { throw new Error( 'POC limit: maximum 500 Gutenberg blocks.' ); }
    };

    // Intentionally supports only simple element, class and ID selectors.
    // @media, pseudo-selectors, CSS variables and external stylesheets are not imported.
    const extractStyles = ( doc ) => {
        const rules = [];
        doc.querySelectorAll( 'style' ).forEach( ( styleNode ) => {
            const css = styleNode.textContent.replace( /\/\*[\s\S]*?\*\//g, '' );
            const matcher = /([^{}]+)\{([^{}]*)\}/g;
            let match;
            while ( ( match = matcher.exec( css ) ) !== null ) {
                const selector = match[ 1 ].trim();
                if ( ! selector || selector.startsWith( '@' ) ) { continue; }
                if ( ! /^[\w\s.#>,+~-]+$/.test( selector ) ) { continue; }
                rules.push( { selector, declaration: match[ 2 ] } );
                if ( rules.length >= 200 ) { break; }
            }
        } );
        return rules;
    };

    const readStyles = ( node, context ) => {
        const style = document.createElement( 'div' ).style;
        context.rules.forEach( ( rule ) => {
            try {
                if ( node.matches( rule.selector ) ) {
                    style.cssText += ';' + rule.declaration;
                }
            } catch ( error ) { /* Ignore invalid CSS selectors. */ }
        } );
        style.cssText += ';' + ( node.getAttribute( 'style' ) || '' );
        return {
            display: style.display,
            textAlign: acceptedAlign( style.textAlign ),
            color: allowedColor( style.color ),
            background: allowedColor( style.backgroundColor ),
        };
    };

    const textAppearance = ( styles ) => {
        const attrs = {};
        const classes = [];
        const inline = [];
        if ( styles.textAlign ) {
            attrs.align = styles.textAlign;
            classes.push( 'has-text-align-' + styles.textAlign );
        }
        if ( styles.color ) {
            attrs.style = { color: { text: styles.color } };
            classes.push( 'has-text-color' );
            inline.push( 'color:' + styles.color );
        }
        return { attrs, className: classes.join( ' ' ), inlineStyle: inline.join( ';' ) };
    };
    const htmlAttributes = ( className, inlineStyle ) => ( className ? ' class="' + escapeHtml( className ) + '"' : '' ) +
        ( inlineStyle ? ' style="' + escapeHtml( inlineStyle ) + '"' : '' );

    const safeUrl = ( raw, context ) => {
        if ( ! raw || ! raw.trim() ) { return ''; }
        try {
            const url = new URL( raw.trim(), context.baseUrl );
            return [ 'https:', 'http:' ].includes( url.protocol ) ? url.href : '';
        } catch ( error ) { return ''; }
    };

    const convert = ( node, context ) => {
        const tag = node.tagName.toLowerCase();
        const styles = readStyles( node, context );
        let markup = '';

        if ( /^h[1-6]$/.test( tag ) || tag === 'p' ) {
            const value = node.textContent.trim();
            if ( ! value ) { return ''; }
            const heading = tag !== 'p';
            const appearance = textAppearance( styles );
            const attributes = { ...appearance.attrs };
            if ( heading ) { attributes.level = Number( tag[ 1 ] ); }
            const className = ( heading ? 'wp-block-heading ' : '' ) + appearance.className;
            const html = '<' + tag + htmlAttributes( className.trim(), appearance.inlineStyle ) + '>' + escapeHtml( value ) + '</' + tag + '>';
            markup = block( heading ? 'heading' : 'paragraph', attributes, html );
        } else if ( tag === 'img' ) {
            const src = safeUrl( node.getAttribute( 'src' ), context );
            if ( ! src ) { return ''; }
            const alt = escapeHtml( node.getAttribute( 'alt' ) || '' );
            markup = block( 'image', { sizeSlug: 'full', linkDestination: 'none' },
                '<figure class="wp-block-image size-full"><img src="' + escapeHtml( src ) + '" alt="' + alt + '"/></figure>' );
        } else if ( tag === 'a' && ! node.querySelector( 'h1,h2,h3,h4,h5,h6,p,img' ) ) {
            const url = safeUrl( node.getAttribute( 'href' ), context );
            const value = node.textContent.trim();
            if ( value ) {
                const content = url ? '<a href="' + escapeHtml( url ) + '">' + escapeHtml( value ) + '</a>' : escapeHtml( value );
                markup = block( 'paragraph', {}, '<p>' + content + '</p>' );
            }
        } else if ( [ 'header', 'footer', 'nav', 'main', 'section', 'article', 'div' ].includes( tag ) ) {
            const children = Array.from( node.children );
            const isColumns = ( styles.display === 'flex' || styles.display === 'grid' ) &&
                children.length >= 2 && children.length <= 4;
            if ( isColumns ) {
                const cols = children.map( ( child ) => {
                    const inner = convert( child, context );
                    if ( ! inner ) { return ''; }
                    countBlock( context );
                    return block( 'column', {}, '<div class="wp-block-column">' + inner + '</div>' );
                } ).filter( Boolean ).join( '\n\n' );
                if ( cols ) {
                    markup = block( 'columns', {}, '<div class="wp-block-columns">' + cols + '</div>' );
                }
            } else {
                const inner = childBlocks( node, context );
                if ( ! inner ) { return ''; }
                const attrs = { layout: { type: 'default' } };
                if ( tag !== 'div' ) { attrs.tagName = tag; }
                const element = tag === 'div' ? 'div' : tag;
                const classes = [ 'wp-block-group' ];
                let inline = '';
                if ( styles.background ) {
                    attrs.style = { color: { background: styles.background } };
                    classes.push( 'has-background' );
                    inline = 'background-color:' + styles.background;
                }
                markup = block( 'group', attrs, '<' + element + htmlAttributes( classes.join( ' ' ), inline ) + '>' + inner + '</' + element + '>' );
            }
        } else {
            // Unknown containers are transparent, not Custom HTML blocks.
            return childBlocks( node, context );
        }

        if ( markup ) { countBlock( context ); }
        return markup;
    };

    const prepareImport = ( source ) => {
        const doc = new DOMParser().parseFromString( source, 'text/html' );
        const rules = extractStyles( doc );
        // No source scripts or active embeds are allowed in the preview or block output.
        doc.querySelectorAll( 'script, iframe, object, embed, form, template, svg, link, base' ).forEach( ( node ) => node.remove() );
        doc.querySelectorAll( '*' ).forEach( ( node ) => {
            Array.from( node.attributes ).forEach( ( attribute ) => {
                if ( /^on/i.test( attribute.name ) ||
                    ( /^(src|href|xlink:href|formaction)$/i.test( attribute.name ) &&
                        /^\s*(?:javascript|data|vbscript):/i.test( attribute.value ) ) ) {
                    node.removeAttribute( attribute.name );
                }
            } );
        } );
        const context = { count: 0, rules, baseUrl: window.location.origin };
        const content = childBlocks( doc.body, context );
        if ( ! content ) { throw new Error( 'No supported editable blocks found.' ); }

        // Preview the same converted markup that will be saved to WordPress.
        // Strip Gutenberg block delimiters only for rendering in the sandboxed iframe.
        const rendered = content.replace( /<!--\\s*\\/?wp:[\\s\\S]*?-->/g, '' );
        const previewStyles = `
            *, *::before, *::after { box-sizing: border-box; }
            body { margin: 0; padding: 20px; background: #fff; color: #1e1e1e; font-family: system-ui, sans-serif; line-height: 1.6; }
            .wp-block-group { min-width: 0; }
            .wp-block-group.has-background { padding: 1.5em 2em; }
            .wp-block-columns { display: flex; flex-wrap: nowrap; align-items: normal; gap: 2em; margin-bottom: 1.75em; }
            .wp-block-column { flex-basis: 0; flex-grow: 1; min-width: 0; overflow-wrap: break-word; }
            .wp-block-image { margin: 0 0 1em; }
            .wp-block-image img { display: block; max-width: 100%; height: auto; }
            .has-text-align-left { text-align: left; }
            .has-text-align-center { text-align: center; }
            .has-text-align-right { text-align: right; }
            .has-text-align-justify { text-align: justify; }
            @media (max-width: 600px) {
                .wp-block-columns { flex-wrap: wrap; }
                .wp-block-column { flex-basis: 100%; }
            }
        `;
        const preview = '<!doctype html><html lang="en"><head><meta charset="utf-8">' +
            '<meta name="viewport" content="width=device-width, initial-scale=1">' +
            '<style>' + previewStyles + '</style></head><body>' + rendered + '</body></html>';
        return { content, preview };
    };

    document.addEventListener( 'DOMContentLoaded', () => {
        const file = $( '#syndamorph-html-file' );
        const input = $( '#syndamorph-html-input' );
        const title = $( '#syndamorph-page-title' );
        const previewButton = $( '#syndamorph-convert' );
        const createButton = $( '#syndamorph-create-draft' );
        const card = $( '#syndamorph-preview' );
        const closeButton = $( '#syndamorph-close-preview' );
        const cancelButton = $( '#syndamorph-cancel-preview' );
        const frame = $( '#syndamorph-preview-frame' );
        const result = $( '#syndamorph-result' );
        if ( ! file || ! input || ! title || ! previewButton || ! createButton || ! card || ! frame || ! result || ! closeButton || ! cancelButton ) { return; }

        let prepared = null;
        let preparedSource = '';
        let saving = false;
        let returnFocus = null;
        const closePreview = () => {
            if ( saving ) { return; }
            card.hidden = true;
            document.body.classList.remove( 'syndamorph-modal-open' );
            if ( returnFocus ) { returnFocus.focus(); }
        };
        const invalidatePreview = () => {
            closePreview();
            prepared = null;
            preparedSource = '';
            frame.removeAttribute( 'srcdoc' );
            createButton.disabled = true;
        };
        [ closeButton, cancelButton ].forEach( ( control ) => control.addEventListener( 'click', closePreview ) );
        card.addEventListener( 'click', ( event ) => {
            if ( event.target === card ) { closePreview(); }
        } );
        card.addEventListener( 'keydown', ( event ) => {
            if ( event.key === 'Escape' ) {
                event.preventDefault();
                closePreview();
            } else if ( event.key === 'Tab' ) {
                const controls = [ closeButton, cancelButton, createButton ].filter( ( control ) => ! control.disabled );
                const first = controls[ 0 ];
                const last = controls[ controls.length - 1 ];
                if ( event.shiftKey && document.activeElement === first ) {
                    event.preventDefault();
                    last.focus();
                } else if ( ! event.shiftKey && document.activeElement === last ) {
                    event.preventDefault();
                    first.focus();
                }
            }
        } );
        input.addEventListener( 'input', invalidatePreview );

        file.addEventListener( 'change', async () => {
            invalidatePreview();
            const selected = file.files && file.files[ 0 ];
            if ( ! selected ) { return; }
            if ( ! /\.html?$/i.test( selected.name ) || selected.size > 2 * 1024 * 1024 ) {
                result.textContent = 'Choose an HTML file smaller than 2 MB.';
                file.value = '';
                return;
            }
            try {
                input.value = await selected.text();
                if ( ! title.value.trim() || title.value === 'Imported HTML' ) {
                    title.value = selected.name.replace( /\.html?$/i, '' );
                }
                result.textContent = 'HTML loaded. Select Preview Import.';
            } catch ( error ) {
                result.textContent = 'Unable to read HTML file: ' + ( error.message || String( error ) );
            }
        } );

        previewButton.addEventListener( 'click', () => {
            invalidatePreview();
            const source = input.value;
            if ( ! source.trim() ) {
                result.textContent = 'Paste HTML or select an HTML file first.';
                return;
            }
            previewButton.disabled = true;
            try {
                result.textContent = 'Preparing import preview…';
                prepared = prepareImport( source );
                preparedSource = source;
                frame.srcdoc = prepared.preview;
                returnFocus = previewButton;
                card.hidden = false;
                document.body.classList.add( 'syndamorph-modal-open' );
                createButton.disabled = false;
                closeButton.focus();
                result.textContent = 'Converted block preview ready. Review before creating a draft.';
            } catch ( error ) {
                result.textContent = 'Preview failed: ' + ( error.message || String( error ) );
                console.error( '[SyndaMorph] Preview:', error );
            } finally {
                previewButton.disabled = false;
            }
        } );

        createButton.addEventListener( 'click', async () => {
            if ( ! prepared || preparedSource !== input.value ) {
                invalidatePreview();
                result.textContent = 'HTML changed. Preview the import again before creating a draft.';
                return;
            }
            createButton.disabled = true;
            previewButton.disabled = true;
            saving = true;
            try {
                result.textContent = 'Saving approved WordPress draft…';
                const response = await fetch( SyndaMorphImport.restUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': SyndaMorphImport.nonce },
                    body: JSON.stringify( {
                        title: title.value.trim() || 'Imported HTML',
                        content: prepared.content,
                        status: 'draft',
                    } ),
                } );
                const data = await response.json();
                if ( ! response.ok || ! data.id ) {
                    throw new Error( ( data && data.message ) || 'WordPress draft creation failed (HTTP ' + response.status + ').' );
                }
                result.textContent = 'Draft created. Opening Gutenberg…';
                window.location.assign( SyndaMorphImport.editUrl + '?post=' + encodeURIComponent( data.id ) + '&action=edit' );
            } catch ( error ) {
                result.textContent = 'Draft creation failed: ' + ( error.message || String( error ) );
                console.error( '[SyndaMorph] Draft:', error );
                createButton.disabled = false;
                previewButton.disabled = false;
            } finally {
                saving = false;
            }
        } );
    } );
} )();
