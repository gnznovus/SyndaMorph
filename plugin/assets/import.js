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
                const rules = extractStyles( doc );
                doc.querySelectorAll( 'script, style, iframe, object, embed, form, template, svg' ).forEach( ( node ) => node.remove() );
                stage = 'convert';
                result.textContent = 'Converting content and layout…';
                const context = {
                    count: 0,
                    rules,
                    baseUrl: window.location.origin,
                };
                const content = childBlocks( doc.body, context );
                if ( ! content ) { throw new Error( 'No supported editable blocks found.' ); }

                stage = 'save';
                result.textContent = 'Saving WordPress draft…';
                const response = await fetch( SyndaMorphImport.restUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': SyndaMorphImport.nonce },
                    body: JSON.stringify( {
                        title: title.value.trim() || 'Imported HTML',
                        content,
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
