// Install Playwright with npm --prefix renderer install --no-package-lock,
// then run node --test tests/inline-layout.test.cjs. Use the Playwright Chromium
// install or set PLAYWRIGHT_CHROMIUM_EXECUTABLE to an existing Chrome executable.
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs/promises' );
const { createRequire } = require( 'node:module' );
const path = require( 'node:path' );
const test = require( 'node:test' );
const { chromium } = createRequire( path.resolve( __dirname, '../renderer/package.json' ) )( 'playwright' );

test( 'direct text, Flex and Grid labels stay readable and ordered in the preview and native markup', async ( t ) => {
    const browser = await chromium.launch( {
        executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,
    } );
    t.after( () => browser.close() );
    const page = await browser.newPage();
    const origin = 'http://127.0.0.1';
    await page.route( '**/*', ( route ) => route.request().url() === origin + '/'
        ? route.fulfill( { contentType: 'text/html', body: '<!doctype html><body></body>' } )
        : route.abort() );
    await page.goto( origin );
    await page.evaluate( () => { window.SyndaMorphImport = {}; } );
    await page.addScriptTag( { path: path.join( __dirname, '../plugin/assets/import.js' ) } );
    const html = await fs.readFile( path.join( __dirname, 'fixtures/direct-div-text.html' ), 'utf8' );
    const converted = await page.evaluate( ( source ) => window.SyndaMorphConverter.prepareImport( source, false ), html );
    const expected = [
        /Live Thonglor's rhythm<br>from sunrise to skyline\./,
        /Before <strong>the nested card<\/strong>\.[\s\S]*Inside the card\.[\s\S]*After <em>the nested card<\/em>\./,
        /2 Nights <strong>Fri<\/strong> <em>04 Dec<\/em>/,
        /Room facts:\s+Room Size 23 sq\. m\.[\s\S]*Inside the room card\.[\s\S]*Beds 2 twins/,
        /Reserve<strong>,<\/strong> please\. Room42/,
    ];
    for ( const output of [ converted.content, converted.preview ] ) {
        for ( const pattern of expected ) {
            assert.match( output, pattern );
        }
    }
    assert.match( converted.content, /<!-- wp:columns / );
} );
