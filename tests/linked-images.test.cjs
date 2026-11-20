// Install Playwright in renderer/ and Chromium, or set PLAYWRIGHT_CHROMIUM_EXECUTABLE.
// Run with node --test tests/linked-images.test.cjs.
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs/promises' );
const { createRequire } = require( 'node:module' );
const path = require( 'node:path' );
const test = require( 'node:test' );
const { chromium } = createRequire( path.resolve( __dirname, '../renderer/package.json' ) )( 'playwright' );

test( 'linked images and logos use one native Image link and retain safe destinations', async ( t ) => {
    const browser = await chromium.launch( { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE } );
    t.after( () => browser.close() );
    const page = await browser.newPage();
    const origin = 'http://127.0.0.1';
    await page.route( '**/*', ( route ) => {
        const url = new URL( route.request().url() );
        if ( url.origin !== origin ) { return route.abort(); }
        if ( url.pathname === '/' ) { return route.fulfill( { contentType: 'text/html', body: '<!doctype html>' } ); }
        return route.fulfill( {
            contentType: 'image/svg+xml',
            body: '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><rect width="1" height="1"/></svg>',
        } );
    } );
    await page.goto( origin );
    await page.evaluate( () => { window.SyndaMorphImport = {}; } );
    await page.addScriptTag( { path: path.join( __dirname, '../plugin/assets/import.js' ) } );
    const fixture = await fs.readFile( path.join( __dirname, 'fixtures/linked-images.html' ), 'utf8' );
    const converted = await page.evaluate( ( html ) => window.SyndaMorphConverter.prepareImport( html, false ), fixture );
    const expected = [
        [ 'Hotel logo', 'https://example.com/hotel/', null, null ],
        [ 'Gallery image', 'https://example.com/gallery/?view=full&id=7#photo', '_blank', 'nofollow noreferrer noopener' ],
        [ 'Room icon', './rooms.html', null, null ],
        [ 'Go to the map', '#map', null, null ],
        [ 'Email reservations', 'mailto:stay@example.com', null, null ],
        [ 'Unsafe link image', null, null, null ],
        [ 'Unlinked image', null, null, null ],
    ];
    for ( const markup of [ converted.content, converted.preview ] ) {
        const figures = await page.evaluate( ( html ) => {
            const doc = new DOMParser().parseFromString( html, 'text/html' );
            return Array.from( doc.querySelectorAll( 'figure.wp-block-image' ), ( figure ) => {
                const img = figure.querySelector( 'img' );
                const link = figure.querySelector( ':scope > a' );
                if ( img.parentElement !== ( link || figure ) || figure.querySelectorAll( 'a' ).length !== Number( Boolean( link ) ) ) {
                    throw new Error( 'Image is not a direct child of its figure or its single link.' );
                }
                return [ img.alt, link?.getAttribute( 'href' ) ?? null, link?.getAttribute( 'target' ) ?? null,
                    link?.getAttribute( 'rel' ) ?? null ];
            } );
        }, markup );
        assert.deepEqual( figures, expected );
        assert.match( markup, /Before the icon\.[\s\S]*<a [^>]*>Tour rooms<\/a>[\s\S]*alt="Room icon"[\s\S]*After the icon\./ );
    }
    assert.equal( ( converted.content.match( /"linkDestination":"custom"/g ) || [] ).length, 5 );
    assert.equal( ( converted.content.match( /"linkDestination":"none"/g ) || [] ).length, 2 );
} );
