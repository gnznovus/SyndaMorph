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
    // A div can contain meaningful text directly (including <br> and inline
    // markup). Element-only traversal silently discarded that content.
    const inlineTags = new Set( [ 'br', 'span', 'strong', 'b', 'em', 'i', 'code', 'sup', 'sub', 'a', 'small', 'mark', 'u', 's' ] );
    const isInlineNode = ( part ) => part.nodeType === 3 ||
        ( part.nodeType === 1 && inlineTags.has( part.tagName.toLowerCase() ) &&
            ! part.querySelector( 'p,h1,h2,h3,h4,h5,h6,div,section,article,img,ul,ol,table' ) );
    const childBlocks = ( node, context, textTag = 'p' ) => {
        const output = [];
        let inline = [];
        const flushInline = () => {
            if ( ! inline.some( ( part ) => part.textContent.trim() ) ) { inline = []; return; }
            const styles = readStyles( node, context );
            let markup = simpleInline( { childNodes: inline }, context, styles.display );
            const imageLink = node.closest( 'a[href]' );
            const href = imageLink?.querySelector( 'img' ) && safeUrl( imageLink.getAttribute( 'href' ), context );
            if ( href && ! /<a(?:\s|>)/i.test( markup ) ) {
                markup = '<a href="' + escapeHtml( href ) + '">' + markup + '</a>';
            }
            output.push( textBlock( textTag, styles, markup ) );
            countBlock( context );
            inline = [];
        };
        Array.from( node.childNodes ).forEach( ( part ) => {
            if ( isInlineNode( part ) ) { inline.push( part ); return; }
            flushInline();
            if ( part.nodeType === 1 ) {
                const converted = convert( part, context );
                if ( converted ) { output.push( converted ); }
            }
        } );
        flushInline();
        return output.join( '\n\n' );
    };
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

    // Only explicit, non-negative CSS lengths can be mapped to native block
    // settings. Unsupported units/functions remain intentionally unconverted.
    const allowedLength = ( value ) => {
        const raw = ( value || '' ).trim().toLowerCase();
        if ( raw === '0' ) { return '0px'; }
        const match = raw.match( /^(\d+(?:\.\d+)?)(px|rem|em|%)$/ );
        if ( ! match || Number( match[ 1 ] ) > 400 ) { return ''; }
        return raw;
    };
    const spacing = ( style, prefix ) => {
        const keys = [ 'Top', 'Right', 'Bottom', 'Left' ];
        return Object.fromEntries( keys.map( ( key ) => [ key.toLowerCase(), allowedLength( style[ prefix + key ] ) ] ).filter( ( pair ) => pair[ 1 ] ) );
    };
    const cssSpacing = ( kind, values ) => [ 'top', 'right', 'bottom', 'left' ]
        .filter( ( side ) => values[ side ] )
        .map( ( side ) => kind + '-' + side + ':' + values[ side ] ).join( ';' );
    const applySpacing = ( attrs, inline, styles, includeMargin = true ) => {
        const space = {};
        if ( Object.keys( styles.padding ).length ) { space.padding = styles.padding; }
        if ( includeMargin && Object.keys( styles.margin ).length ) { space.margin = styles.margin; }
        if ( Object.keys( space ).length ) {
            attrs.style = { ...( attrs.style || {} ), spacing: { ...( attrs.style?.spacing || {} ), ...space } };
        }
        const extra = [ cssSpacing( 'padding', styles.padding ) ];
        if ( includeMargin ) { extra.push( cssSpacing( 'margin', styles.margin ) ); }
        return [ inline, ...extra ].filter( Boolean ).join( ';' );
    };

    const cssImageUrl = ( raw, context ) => {
        const match = ( raw || '' ).trim().match( /^url\(\s*(?:"([^"]+)"|'([^']+)'|([^)'"]+))\s*\)$/i );
        if ( ! match ) { return ''; }
        return safeUrl( match[1] || match[2] || match[3], context );
    };
    const validFontWeight = ( value ) => /^(?:normal|bold|[1-9]00)$/.test( value || '' ) ? value : '';
    const validLineHeight = ( value ) => {
        const v = ( value || '' ).trim().toLowerCase();
        if ( /^\d+(?:\.\d+)?$/.test( v ) && Number( v ) <= 4 ) { return v; }
        return allowedLength( v );
    };

    // Map simple grid track fractions to native percentage Column widths.
    // No layout engine: minmax(), auto, repeat(), subgrid and responsive queries
    // remain unsupported instead of producing misleading proportions.
    const gridTrackWidths = ( value, count ) => {
        const tracks = ( value || '' ).trim().split( /\s+/ ).filter( Boolean );
        if ( tracks.length !== count || tracks.length < 2 || tracks.length > 6 ) { return []; }
        const parsed = tracks.map( ( track ) => {
            const match = track.match( /^(\d+(?:\.\d+)?)(fr|%)$/ );
            return match && Number( match[1] ) > 0 ? { amount: Number( match[1] ), unit: match[2] } : null;
        } );
        if ( parsed.some( ( track ) => ! track ) ||
            parsed.some( ( track ) => track.unit !== parsed[0].unit ) ) { return []; }
        const total = parsed.reduce( ( sum, track ) => sum + track.amount, 0 );
        return parsed.map( ( track ) => ( Math.round( track.amount / total * 10000 ) / 100 ) + '%' );
    };
    const verticalAlign = ( value ) => ( { start: 'top', center: 'center', end: 'bottom' }[ value ] || '' );

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
            flexDirection: style.flexDirection,
            flexWrap: style.flexWrap,
            gridTemplateColumns: style.gridTemplateColumns,
            alignItems: style.alignItems,
            justifyContent: style.justifyContent,
            textAlign: acceptedAlign( style.textAlign ),
            color: allowedColor( style.color ),
            background: allowedColor( style.backgroundColor ),
            backgroundImage: cssImageUrl( style.backgroundImage, context ),
            backgroundSize: [ 'cover', 'contain' ].includes( style.backgroundSize ) ? style.backgroundSize : '',
            fontWeight: validFontWeight( style.fontWeight ),
            lineHeight: validLineHeight( style.lineHeight ),
            padding: spacing( style, 'padding' ),
            margin: spacing( style, 'margin' ),
            gap: allowedLength( style.columnGap || style.gap ),
            width: allowedLength( style.width ),
            flexBasis: allowedLength( style.flexBasis ),
            fontSize: allowedLength( style.fontSize ),
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
        const typography = {};
        if ( styles.fontSize ) { typography.fontSize = styles.fontSize; inline.push( 'font-size:' + styles.fontSize ); }
        if ( styles.fontWeight ) { typography.fontWeight = styles.fontWeight; inline.push( 'font-weight:' + styles.fontWeight ); }
        if ( styles.lineHeight ) { typography.lineHeight = styles.lineHeight; inline.push( 'line-height:' + styles.lineHeight ); }
        if ( Object.keys( typography ).length ) {
            attrs.style = { ...( attrs.style || {} ), typography: { ...( attrs.style?.typography || {} ), ...typography } };
        }
        return { attrs, className: classes.join( ' ' ), inlineStyle: applySpacing( attrs, inline.join( ';' ), styles ) };
    };
    const htmlAttributes = ( className, inlineStyle ) => ( className ? ' class="' + escapeHtml( className ) + '"' : '' ) +
        ( inlineStyle ? ' style="' + escapeHtml( inlineStyle ) + '"' : '' );

    const textBlock = ( tag, styles, content ) => {
        const { attrs, className, inlineStyle } = textAppearance( styles );
        const heading = tag !== 'p';
        if ( heading ) { attrs.level = Number( tag[1] ); }
        const classes = ( ( heading ? 'wp-block-heading ' : '' ) + className ).trim();
        return block( heading ? 'heading' : 'paragraph', attrs,
            '<' + tag + htmlAttributes( classes, inlineStyle ) + '>' + content + '</' + tag + '>' );
    };

    const safeUrl = ( raw, context, protocols = [ 'https:', 'http:' ] ) => {
        if ( ! raw || ! raw.trim() ) { return ''; }
        try {
            const url = new URL( raw.trim(), context.baseUrl );
            return protocols.includes( url.protocol ) ? url.href : '';
        } catch ( error ) { return ''; }
    };

    const imageBlock = ( node, context ) => {
        const src = safeUrl( node.getAttribute( 'src' ), context );
        if ( ! src ) { return ''; }
        const anchor = node.closest( 'a[href]' );
        const href = anchor?.getAttribute( 'href' ).trim();
        const linked = Boolean( safeUrl( href, context, [ 'https:', 'http:', 'mailto:', 'tel:' ] ) );
        const alt = node.getAttribute( 'alt' ) || ( linked ? anchor.getAttribute( 'aria-label' ) : '' ) || '';
        let image = '<img src="' + escapeHtml( src ) + '" alt="' + escapeHtml( alt ) + '"/>';
        if ( linked ) {
            const target = anchor.getAttribute( 'target' )?.trim();
            let rel = ( anchor.getAttribute( 'rel' ) || '' ).trim().split( /\s+/ ).filter( Boolean );
            if ( target?.toLowerCase() === '_blank' ) {
                rel = rel.filter( ( token ) => token.toLowerCase() !== 'opener' );
                if ( ! rel.some( ( token ) => token.toLowerCase() === 'noopener' ) ) { rel.push( 'noopener' ); }
            }
            // Core Image reads these attributes from the figure's direct anchor.
            image = '<a href="' + escapeHtml( href ) + '"' +
                ( target ? ' target="' + escapeHtml( target ) + '"' : '' ) +
                ( rel.length ? ' rel="' + escapeHtml( rel.join( ' ' ) ) + '"' : '' ) + '>' + image + '</a>';
        }
        return block( 'image', { sizeSlug: 'full', linkDestination: linked ? 'custom' : 'none' },
            '<figure class="wp-block-image size-full">' + image + '</figure>' );
    };

    const inlinePart = ( part, context ) => {
        if ( part.nodeType === 3 ) { return escapeHtml( part.textContent ); }
        if ( part.nodeType !== 1 ) { return ''; }
        const tag = part.tagName.toLowerCase();
        if ( tag === 'br' ) { return '<br>'; }
        if ( [ 'strong', 'b', 'em', 'i', 'code', 'sup', 'sub' ].includes( tag ) ) {
            return '<' + tag + '>' + simpleInline( part, context ) + '</' + tag + '>';
        }
        if ( tag === 'a' ) {
            const href = safeUrl( part.getAttribute( 'href' ), context );
            return href ? '<a href="' + escapeHtml( href ) + '">' + simpleInline( part, context ) + '</a>' : simpleInline( part, context );
        }
        return simpleInline( part, context );
    };

    const simpleInline = ( node, context, display ) => {
        const parts = Array.from( node.childNodes, ( part ) => inlinePart( part, context ) );
        const items = parts.filter( ( part ) => part.trim() );
        // Flex and Grid separate sibling items visually even when the HTML has
        // no whitespace between them. Preserve that boundary in native text.
        if ( items.length > 1 && /^(?:inline-)?(?:flex|grid)$/.test( display ?? readStyles( node, context ).display ) ) {
            return items.join( ' ' );
        }
        return parts.join( '' );
    };

    const asButton = ( label, href ) => {
        const anchor = '<a class="wp-block-button__link wp-element-button"' +
            ( href ? ' href="' + escapeHtml( href ) + '"' : '' ) + '>' + escapeHtml( label ) + '</a>';
        const inner = block( 'button', {}, '<div class="wp-block-button">' + anchor + '</div>' );
        return block( 'buttons', {}, '<div class="wp-block-buttons">' + inner + '</div>' );
    };

    const asList = ( node, context ) => {
        const ordered = node.tagName.toLowerCase() === 'ol';
        const tag = ordered ? 'ol' : 'ul';
        const items = Array.from( node.children ).filter( ( child ) => child.tagName.toLowerCase() === 'li' )
            .map( ( item ) => {
                countBlock( context );
                return block( 'list-item', {}, '<li>' + simpleInline( item, context ) + '</li>' );
            } ).join( '' );
        if ( ! items ) { return ''; }
        return block( 'list', ordered ? { ordered: true } : {}, '<' + tag + ' class="wp-block-list">' + items + '</' + tag + '>' );
    };

    const asTable = ( node ) => {
        const sections = Array.from( node.children ).filter( ( section ) => [ 'thead', 'tbody', 'tfoot', 'tr' ].includes( section.tagName.toLowerCase() ) );
        const cells = ( row ) => '<tr>' + Array.from( row.children ).filter( ( c ) => [ 'td', 'th' ].includes( c.tagName.toLowerCase() ) )
            .map( ( c ) => '<' + c.tagName.toLowerCase() + '>' + escapeHtml( c.textContent.trim() ) + '</' + c.tagName.toLowerCase() + '>' ).join( '' ) + '</tr>';
        const html = sections.map( ( section ) => {
            const tag = section.tagName.toLowerCase();
            if ( tag === 'tr' ) { return cells( section ); }
            return '<' + tag + '>' + Array.from( section.children ).filter( ( c ) => c.tagName.toLowerCase() === 'tr' )
                .map( cells ).join( '' ) + '</' + tag + '>';
        } ).join( '' );
        if ( ! html ) { return ''; }
        const normalized = sections.every( ( section ) => section.tagName.toLowerCase() === 'tr' ) ? '<tbody>' + html + '</tbody>' : html;
        return block( 'table', {}, '<figure class="wp-block-table"><table class="has-fixed-layout">' + normalized + '</table></figure>' );
    };

    const convert = ( node, context ) => {
        const tag = node.tagName.toLowerCase();
        const styles = readStyles( node, context );
        const isTextBlock = /^h[1-6]$/.test( tag ) || tag === 'p';
        let markup = '';

        if ( isTextBlock && ( node.querySelector( 'a img' ) || ( node.closest( 'a' ) && node.querySelector( 'img' ) ) ) ) {
            // A native Image is its own block, so split it from the surrounding text.
            return childBlocks( node, context, tag );
        } else if ( tag === 'p' && node.children.length === 1 && node.firstElementChild.tagName.toLowerCase() === 'a' &&
            /(?:^|\s)(?:btn|button|cta)(?:$|\s)/i.test( node.firstElementChild.className || '' ) ) {
            const anchor = node.firstElementChild;
            markup = asButton( anchor.textContent.trim(), safeUrl( anchor.getAttribute( 'href' ), context ) );
        } else if ( isTextBlock ) {
            if ( ! node.textContent.trim() ) { return ''; }
            markup = textBlock( tag, styles, simpleInline( node, context, styles.display ) );
        } else if ( tag === 'button' || ( tag === 'a' && ! node.querySelector( 'img' ) &&
            /(?:^|\s)(?:btn|button|cta)(?:$|\s)/i.test( node.className || '' ) ) ) {
            const value = node.textContent.trim();
            if ( value ) { markup = asButton( value, tag === 'a' ? safeUrl( node.getAttribute( 'href' ), context ) : '' ); }
        } else if ( tag === 'ul' || tag === 'ol' ) {
            markup = asList( node, context );
        } else if ( tag === 'table' ) {
            markup = asTable( node );
        } else if ( tag === 'hr' ) {
            markup = block( 'separator', {}, '<hr class="wp-block-separator has-alpha-channel-opacity"/>' );
        } else if ( tag === 'img' ) {
            markup = imageBlock( node, context );
        } else if ( tag === 'a' && ! node.querySelector( 'h1,h2,h3,h4,h5,h6,p,img' ) ) {
            const url = safeUrl( node.getAttribute( 'href' ), context );
            const value = node.textContent.trim();
            if ( value ) {
                const content = url ? '<a href="' + escapeHtml( url ) + '">' + escapeHtml( value ) + '</a>' : escapeHtml( value );
                markup = block( 'paragraph', {}, '<p>' + content + '</p>' );
            }
        } else if ( [ 'header', 'footer', 'nav', 'main', 'section', 'article', 'div' ].includes( tag ) ) {
            const children = Array.from( node.children );
            const isHorizontalFlex = styles.display === 'flex' &&
                ( ! styles.flexDirection || styles.flexDirection === 'row' ) &&
                styles.flexWrap !== 'wrap-reverse';
            const isGrid = styles.display === 'grid';
            const tracks = isGrid ? gridTrackWidths( styles.gridTemplateColumns, children.length ) : [];
            const isColumns = ( isHorizontalFlex || isGrid ) &&
                !Array.from( node.childNodes ).some( ( part ) => isInlineNode( part ) && part.textContent.trim() ) &&
                children.length >= 2 && children.length <= 6 &&
                ( ! isGrid || tracks.length > 0 || ! styles.gridTemplateColumns );
            if ( isColumns ) {
                const cols = children.map( ( child, index ) => {
                    const inner = convert( child, context );
                    if ( ! inner ) { return ''; }
                    const childStyles = readStyles( child, context );
                    const width = childStyles.flexBasis || childStyles.width;
                    // Gutenberg Column width uses a percentage, not an arbitrary CSS length.
                    const columnWidth = width.endsWith( '%' ) ? width : ( tracks[index] || '' );
                    const alignment = verticalAlign( styles.alignItems );
                    const columnAttrs = { ...( columnWidth ? { width: columnWidth } : {} ),
                        ...( alignment ? { verticalAlignment: alignment } : {} ) };
                    const columnCss = columnWidth ? 'flex-basis:' + columnWidth : '';
                    const columnClasses = 'wp-block-column' +
                        ( alignment ? ' is-vertically-aligned-' + alignment : '' );
                    countBlock( context );
                    return block( 'column', columnAttrs, '<div' + htmlAttributes( columnClasses, columnCss ) + '>' + inner + '</div>' );
                } ).filter( Boolean ).join( '\n\n' );
                if ( cols ) {
                    const attrs = {};
                    let inline = '';
                    if ( styles.gap ) {
                        // Columns serialize blockGap as a layout attribute only.
                        // Gutenberg generates its layout CSS outside the saved HTML.
                        attrs.style = { spacing: { blockGap: styles.gap } };
                    }
                    inline = applySpacing( attrs, inline, styles );
                    markup = block( 'columns', attrs, '<div' + htmlAttributes( 'wp-block-columns', inline ) + '>' + cols + '</div>' );
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
                if ( styles.backgroundImage ) {
                    attrs.style = {
                        ...( attrs.style || {} ),
                        background: {
                            backgroundImage: { url: styles.backgroundImage },
                            ...( styles.backgroundSize ? { backgroundSize: styles.backgroundSize } : {} ),
                        },
                    };
                    // Core Group backgrounds are stored in block attributes.
                    // WordPress generates their CSS dynamically; do not put it
                    // in serialized HTML or Gutenberg will invalidate the block.
                }
                inline = applySpacing( attrs, inline, styles );
                markup = block( 'group', attrs, '<' + element + htmlAttributes( classes.join( ' ' ), inline ) + '>' + inner + '</' + element + '>' );
            }
        } else {
            // Unknown containers are transparent, not Custom HTML blocks.
            return childBlocks( node, context );
        }

        if ( markup ) { countBlock( context ); }
        return markup;
    };

    // Browser image loading checks work for cross-origin images without CORS headers.
    // A failed or timed-out load is treated as unavailable for this import.
    const checkImage = ( url ) => new Promise( ( resolve ) => {
        const image = new Image();
        let settled = false;
        const finish = ( available ) => {
            if ( settled ) { return; }
            settled = true;
            clearTimeout( timer );
            image.onload = null;
            image.onerror = null;
            resolve( available );
        };
        const timer = setTimeout( () => finish( false ), 5000 );
        image.onload = () => finish( image.naturalWidth > 0 );
        image.onerror = () => finish( false );
        image.src = url;
    } );

    const resolveImages = async ( doc ) => {
        const nodes = Array.from( doc.querySelectorAll( 'img' ) );
        const missing = [];
        const cache = new Map();
        const baseUrl = window.location.origin;
        let cursor = 0;
        const worker = async () => {
            while ( cursor < nodes.length ) {
                const index = cursor++;
                const node = nodes[ index ];
                const original = ( node.getAttribute( 'src' ) || '' ).trim();
                let url = '';
                try {
                    const parsed = new URL( original, baseUrl );
                    if ( original && [ 'http:', 'https:' ].includes( parsed.protocol ) ) {
                        url = parsed.href;
                    }
                } catch ( error ) { /* Unsupported or malformed source. */ }

                let available = false;
                if ( url ) {
                    if ( ! cache.has( url ) ) { cache.set( url, checkImage( url ) ); }
                    available = await cache.get( url );
                }
                // srcset and sizes can override src, including in Gutenberg previews.
                node.removeAttribute( 'srcset' );
                node.removeAttribute( 'sizes' );
                if ( available ) {
                    node.setAttribute( 'src', url );
                } else {
                    node.setAttribute( 'src', SyndaMorphImport.placeholderUrl );
                    node.setAttribute( 'alt', 'Missing image — ' + ( node.getAttribute( 'alt' ) || original || 'source not provided' ).slice( 0, 180 ) );
                    missing.push( { index: index + 1, source: original || '(no source)' } );
                }
            }
        };
        await Promise.all( Array.from( { length: Math.min( 6, nodes.length ) }, worker ) );
        missing.sort( ( a, b ) => a.index - b.index );
        return missing;
    };

    // A 404 on a same-origin stylesheet is definitive. Cross-origin requests can be
    // opaque or CORS-blocked, so they are reported as unverified, not falsely missing.
    const inspectStylesheets = async ( doc ) => {
        const links = Array.from( doc.querySelectorAll( 'link[rel~="stylesheet"]' ) );
        const missing = [];
        const unverified = [];
        await Promise.all( links.map( async ( link ) => {
            const source = ( link.getAttribute( 'href' ) || '' ).trim();
            if ( ! source ) {
                missing.push( '(empty stylesheet href)' );
                return;
            }
            let url;
            try {
                url = new URL( source, window.location.href );
            } catch ( error ) {
                missing.push( source );
                return;
            }
            if ( ! [ 'http:', 'https:' ].includes( url.protocol ) ) {
                missing.push( source );
                return;
            }
            if ( url.origin !== window.location.origin ) {
                unverified.push( source );
                return;
            }
            const controller = new AbortController();
            const timeout = setTimeout( () => controller.abort(), 4000 );
            try {
                const response = await fetch( url.href, {
                    method: 'GET',
                    credentials: 'same-origin',
                    signal: controller.signal,
                } );
                if ( ! response.ok ) { missing.push( source ); }
            } catch ( error ) {
                unverified.push( source );
            } finally {
                clearTimeout( timeout );
            }
        } ) );
        return { missing, unverified };
    };

    const makeCssRepairPrompt = ( html, missing ) => [
        'Repair the following standalone HTML document whose linked stylesheets are unavailable.',
        'Missing stylesheet references: ' + missing.join( ', ' ),
        'Reconstruct a reasonable layout based on the HTML structure, class names and content. The original design cannot be recovered exactly.',
        'Put the replacement CSS in a single <style> element inside <head>.',
        'Remove references to the unavailable stylesheets. Preserve content, semantic elements, image URLs, alt text, IDs and classes.',
        'Use responsive CSS where appropriate. Do not add JavaScript or third-party dependencies.',
        'Return only one complete HTML document, without explanation or Markdown fences.',
        '',
        'HTML:',
        html,
    ].join( '\n' );

    const renderInlineJs = async ( html ) => {
        const response = await fetch( SyndaMorphImport.renderUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': SyndaMorphImport.nonce },
            body: JSON.stringify( { html } ),
        } );
        const data = await response.json();
        if ( ! response.ok || typeof data.html !== 'string' ) {
            throw new Error( data.message || 'JavaScript rendering service failed (HTTP ' + response.status + ').' );
        }
        return data.html;
    };

    const prepareImport = async ( source, jsMode = false ) => {
        // JS runs only in the separate Docker renderer; the WP admin never
        // evaluates source scripts. Both modes use the same converter below.
        const html = jsMode ? await renderInlineJs( source ) : source;
        const doc = new DOMParser().parseFromString( html, 'text/html' );
        const stylesheets = await inspectStylesheets( doc );
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
        const missing = await resolveImages( doc );
        const context = { count: 0, rules, baseUrl: window.location.origin };
        const content = childBlocks( doc.body, context );
        if ( ! content ) { throw new Error( 'No supported editable blocks found.' ); }

        // Reconstruct attribute-only styles in the preview DOM without changing saved markup.
        // An explicit body keeps the first block comment inside the tree we walk.
        const previewDocument = new DOMParser().parseFromString( '<body>' + content, 'text/html' );
        const comments = previewDocument.createTreeWalker( previewDocument.body, NodeFilter.SHOW_COMMENT );
        let comment;
        while ( ( comment = comments.nextNode() ) ) {
            const match = comment.data.match( /^\s*wp:(columns|group)\s+(\{[\s\S]*\})\s*$/ );
            if ( ! match ) { continue; }
            try {
                const styles = JSON.parse( match[ 2 ] )?.style;
                let wrapper = comment.nextSibling;
                while ( wrapper && wrapper.nodeType === Node.TEXT_NODE && ! wrapper.textContent.trim() ) {
                    wrapper = wrapper.nextSibling;
                }
                if ( ! wrapper || wrapper.nodeType !== Node.ELEMENT_NODE ) { continue; }
                if ( match[ 1 ] === 'columns' ) {
                    const gap = allowedLength( styles?.spacing?.blockGap );
                    if ( gap ) { wrapper.style.gap = gap; }
                    continue;
                }
                const bg = styles?.background;
                const imageUrl = bg?.backgroundImage?.url;
                if ( ! imageUrl || ! safeUrl( imageUrl, { baseUrl: window.location.origin } ) ) { continue; }
                wrapper.style.backgroundImage = 'url(' + JSON.stringify( imageUrl ) + ')';
                if ( [ 'cover', 'contain' ].includes( bg.backgroundSize ) ) {
                    wrapper.style.backgroundSize = bg.backgroundSize;
                }
            } catch ( error ) { /* Invalid block data must never break preview. */ }
        }
        const rendered = previewDocument.body.innerHTML.replace( /<!--\s*\/?wp:[\s\S]*?-->/g, '' );
        const themeFont = typeof SyndaMorphImport.previewFontFamily === 'string'
            ? SyndaMorphImport.previewFontFamily : '';
        const safeThemeFont = /^[\w\s,"'-]{1,180}$/.test( themeFont ) ? themeFont : 'system-ui, sans-serif';
        const previewStyles = `
            *, *::before, *::after { box-sizing: border-box; }
            body { margin: 0; padding: 20px; background: #fff; color: #1e1e1e; font-family: ${ safeThemeFont }; line-height: 1.6; }
            /* Use the modal's available width; do not impose the editor's narrow content width. */
            .syndamorph-preview-content { width: 100%; margin-inline: auto; }
            .wp-block-group { min-width: 0; }
            .wp-block-group > .wp-block-image,
            .wp-block-group > .wp-block-columns { margin-inline: auto; }
            /* Gutenberg does not inject padding into a Group merely because it has a background. */
            .wp-block-group.has-background { padding: 0; }
            .wp-block-group.has-background > p { margin-block: 0; }
            .wp-block-columns { display: flex; flex-wrap: nowrap; align-items: normal; gap: 2em; margin-bottom: 1.75em; }
            .wp-block-column { flex: 1 1 0; min-width: 0; overflow-wrap: break-word; }
            .wp-block-column[style*="flex-basis"] { flex-grow: 0; flex-shrink: 1; }
            .wp-block-column.is-vertically-aligned-top { align-self: flex-start; }
            .wp-block-column.is-vertically-aligned-center { align-self: center; }
            .wp-block-column.is-vertically-aligned-bottom { align-self: flex-end; }
            /* A figure's intrinsic image width must not expand beyond its column. */
            .wp-block-image { display: block; width: 100%; max-width: 100%; min-width: 0; margin: 0 0 1em; }
            .wp-block-image img { display: block; width: 100%; max-width: 100%; height: auto; }
            .wp-block-buttons { display: flex; gap: .5em; margin-bottom: 1em; }
            .wp-block-button__link { display: inline-block; padding: .65em 1.2em; border-radius: 4px; background: #32373c; color: #fff; text-decoration: none; }
            .wp-block-table { width: 100%; overflow-x: auto; }
            .wp-block-table table { width: 100%; border-collapse: collapse; }
            .wp-block-table td, .wp-block-table th { padding: .55em; border: 1px solid #aab0b6; }
            .wp-block-separator { border: 0; border-top: 1px solid #aab0b6; margin-block: 1.5em; }
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
            '<style>' + previewStyles + '</style></head><body><div class="syndamorph-preview-content">' + rendered + '</div></body></html>';
        return { content, preview, missing, stylesheets };
    };

    window.SyndaMorphConverter = Object.freeze( { prepareImport } );

    document.addEventListener( 'DOMContentLoaded', () => {
        const file = $( '#syndamorph-html-file' );
        const input = $( '#syndamorph-html-input' );
        const title = $( '#syndamorph-page-title' );
        const jsToggle = $( '#syndamorph-js-render' );
        const previewButton = $( '#syndamorph-convert' );
        const createButton = $( '#syndamorph-create-draft' );
        const patternButton = $( '#syndamorph-save-pattern' );
        const card = $( '#syndamorph-preview' );
        const closeButton = $( '#syndamorph-close-preview' );
        const cancelButton = $( '#syndamorph-cancel-preview' );
        const frame = $( '#syndamorph-preview-frame' );
        const assetReport = $( '#syndamorph-asset-report' );
        const cssReport = $( '#syndamorph-css-report' );
        const cssList = $( '#syndamorph-css-list' );
        const cssTitle = $( '#syndamorph-css-report-title' );
        const fixButton = $( '#syndamorph-quick-fix' );
        const fixStatus = $( '#syndamorph-css-fix-status' );
        const result = $( '#syndamorph-result' );
        if ( ! file || ! input || ! title || ! jsToggle || ! previewButton || ! createButton || ! patternButton || ! card || ! frame || ! assetReport || ! cssReport || ! cssList || ! cssTitle || ! fixButton || ! fixStatus || ! result || ! closeButton || ! cancelButton ) { return; }

        let prepared = null;
        let preparedSource = '';
        let preparedMode = false;
        let saving = false;
        let returnFocus = null;
        let previewGeneration = 0;
        const closePreview = () => {
            if ( saving ) { return; }
            card.hidden = true;
            document.body.classList.remove( 'syndamorph-modal-open' );
            if ( returnFocus ) { returnFocus.focus(); }
        };
        const invalidatePreview = () => {
            previewGeneration++;
            closePreview();
            prepared = null;
            preparedSource = '';
            preparedMode = false;
            frame.removeAttribute( 'srcdoc' );
            assetReport.hidden = true;
            assetReport.replaceChildren();
            cssReport.hidden = true;
            cssList.replaceChildren();
            fixStatus.textContent = '';
            createButton.disabled = true;
            patternButton.disabled = true;
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
                const controls = [ closeButton, ...( cssReport.hidden ? [] : [ fixButton ] ), cancelButton, patternButton, createButton ].filter( ( control ) => ! control.disabled );
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
        fixButton.addEventListener( 'click', async () => {
            if ( ! prepared || ! prepared.stylesheets.missing.length || preparedSource !== input.value || preparedMode !== jsToggle.checked ) { return; }
            // Open from the user's direct click so popup blockers do not discard the tab.
            const tab = window.open( 'https://chatgpt.com/', '_blank' );
            if ( tab ) { tab.opener = null; }
            const prompt = makeCssRepairPrompt( preparedSource, prepared.stylesheets.missing );
            try {
                await navigator.clipboard.writeText( prompt );
                fixStatus.textContent = 'Copied! Paste into ChatGPT (Ctrl+V), then reimport the repaired HTML here.';
            } catch ( error ) {
                fixStatus.textContent = 'Clipboard permission denied. Copy your HTML manually and ask ChatGPT to reconstruct missing CSS, then reimport.';
            }
            if ( ! tab ) {
                fixStatus.textContent += ' Your browser blocked the new tab: open https://chatgpt.com manually.';
            }
        } );
        input.addEventListener( 'input', invalidatePreview );
        jsToggle.addEventListener( 'change', invalidatePreview );
        if ( ! SyndaMorphImport.rendererAvailable ) {
            jsToggle.disabled = true;
            jsToggle.title = 'Start the Docker renderer service to enable JavaScript rendering.';
        }

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

        previewButton.addEventListener( 'click', async () => {
            invalidatePreview();
            const source = input.value;
            if ( ! source.trim() ) {
                result.textContent = 'Paste HTML or select an HTML file first.';
                return;
            }
            const version = previewGeneration;
            const jsMode = jsToggle.checked;
            previewButton.disabled = true;
            try {
                result.textContent = jsMode ? 'Rendering inline JavaScript in isolated browser…' : 'Checking image assets and preparing preview…';
                const next = await prepareImport( source, jsMode );
                if ( version !== previewGeneration || source !== input.value || jsMode !== jsToggle.checked ) { return; }
                prepared = next;
                preparedSource = source;
                preparedMode = jsMode;
                if ( prepared.missing.length ) {
                    const heading = document.createElement( 'strong' );
                    heading.textContent = prepared.missing.length + ' missing image(s) — replace these in Gutenberg after creating the draft.';
                    const list = document.createElement( 'ul' );
                    prepared.missing.forEach( ( item ) => {
                        const entry = document.createElement( 'li' );
                        entry.textContent = 'Image ' + item.index + ': ' + item.source;
                        list.appendChild( entry );
                    } );
                    assetReport.replaceChildren( heading, list );
                    assetReport.hidden = false;
                }
                if ( prepared.stylesheets.missing.length || prepared.stylesheets.unverified.length ) {
                    const missing = prepared.stylesheets.missing;
                    const unverified = prepared.stylesheets.unverified;
                    cssTitle.textContent = missing.length
                        ? missing.length + ' missing stylesheet(s) detected'
                        : 'External stylesheet(s) could not be verified';
                    const entries = [
                        ...missing.map( ( href ) => 'Missing: ' + href ),
                        ...unverified.map( ( href ) => 'Unverified (external): ' + href ),
                    ];
                    entries.forEach( ( value ) => {
                        const item = document.createElement( 'li' );
                        item.textContent = value;
                        cssList.appendChild( item );
                    } );
                    fixButton.hidden = ! missing.length;
                    cssReport.hidden = false;
                }
                frame.srcdoc = prepared.preview;
                returnFocus = previewButton;
                card.hidden = false;
                document.body.classList.add( 'syndamorph-modal-open' );
                createButton.disabled = false;
                patternButton.disabled = false;
                closeButton.focus();
                result.textContent = 'Converted block preview ready. Review before creating a draft.';
            } catch ( error ) {
                result.textContent = 'Preview failed: ' + ( error.message || String( error ) );
                console.error( '[SyndaMorph] Preview:', error );
            } finally {
                previewButton.disabled = false;
            }
        } );

        patternButton.addEventListener( 'click', async () => {
            if ( ! prepared || preparedSource !== input.value || preparedMode !== jsToggle.checked ) {
                invalidatePreview();
                result.textContent = 'HTML changed. Preview it again before saving a pattern.';
                return;
            }
            const name = await window.SyndaMorphPatternDialog.open( title.value.trim() || 'Imported Pattern' );
            if ( name === null ) { return; }
            saving = true;
            patternButton.disabled = true;
            createButton.disabled = true;
            previewButton.disabled = true;
            try {
                const response = await fetch( SyndaMorphImport.patternUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': SyndaMorphImport.nonce },
                    body: JSON.stringify( { title: name.trim(), content: prepared.content } ),
                } );
                const data = await response.json();
                if ( ! response.ok || ! data.id ) {
                    throw new Error( data.message || 'Failed to save pattern (HTTP ' + response.status + ').' );
                }
                result.textContent = 'Pattern saved: ' + name.trim() + '. Find it in Gutenberg → Patterns → SyndaMorph.';
                closePreview();
                card.hidden = true;
                document.body.classList.remove( 'syndamorph-modal-open' );
                previewButton.focus();
            } catch ( error ) {
                result.textContent = 'Save pattern failed: ' + ( error.message || String( error ) );
                console.error( '[SyndaMorph] Pattern:', error );
            } finally {
                saving = false;
                patternButton.disabled = false;
                createButton.disabled = false;
                previewButton.disabled = false;
            }
        } );

        createButton.addEventListener( 'click', async () => {
            if ( ! prepared || preparedSource !== input.value || preparedMode !== jsToggle.checked ) {
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
