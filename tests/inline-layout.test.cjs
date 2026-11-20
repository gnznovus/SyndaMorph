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
    const flex = await page.evaluate( () => window.SyndaMorphConverter.prepareImport( [
        '<div style="display:flex; gap:16px"><div style="flex:1 1 0%">A</div>',
        '<div style="flex:1 1 0%;width:760px">B</div><div style="flex:1 1 0%">C</div></div>',
        '<div style="display:flex"><div style="flex:2 1 0%">Wide</div><div style="flex:1 1 0%">Narrow</div></div>',
    ].join( '' ), false ) );
    assert.doesNotMatch( flex.content, /"width":"0%"/ );
    assert.equal( [ ...flex.content.matchAll( /<!-- wp:column -->/g ) ].length, 3 );
    assert.match( flex.content, /"width":"66\.67%"[\s\S]*"width":"33\.33%"/ );
    const grid = await page.evaluate( () => window.SyndaMorphConverter.prepareImport( [
        '<div data-syndamorph-capture="browser" data-syndamorph-viewport="1440" style="padding:96px 60px;',
        'display:grid;grid-template-columns:100px 100px 100px 100px;gap:20px" data-syndamorph-tracks="repeat(4,1fr)">',
        '<div style="grid-column:1 / span 2;font-family:&quot;Local Heading&quot;,serif;font-size:56px">Lead</div>',
        '<div style="grid-column:4">First side</div>',
        '<div style="grid-column:1 / span 3">Second lead</div>',
        '<div style="grid-column:4">Second side</div></div>',
    ].join( '' ), false ) );
    assert.match( grid.content, /<!-- wp:group [^>]*"align":"full"/ );
    assert.equal( [ ...grid.content.matchAll( /<!-- wp:columns /g ) ].length, 2 );
    assert.match( grid.content, /Lead[\s\S]*First side[\s\S]*Second lead[\s\S]*Second side/ );
    assert.match( grid.content, /"fontFamily":"\\u0022Local Heading\\u0022, serif"/ );
    assert.match( grid.preview, /gap: 20px 30\.43%/ );
    const details = await page.evaluate( () => window.SyndaMorphConverter.prepareImport( [
        '<div data-syndamorph-capture="browser" data-syndamorph-notices="[&quot;One answer available.&quot;]">',
        '<div data-syndamorph-disclosure="open"><div role="button" data-syndamorph-summary>Question?</div>',
        '<p>Available answer.</p></div><p>Question without its answer?</p></div>',
    ].join( '' ), false ) );
    assert.deepEqual( details.captureNotices, [ 'One answer available.' ] );
    assert.match( details.content, /<!-- wp:details {"showContent":true} --><details class="wp-block-details" open><summary>Question\?<\/summary>/ );
    assert.equal( [ ...details.content.matchAll( /<details/g ) ].length, 1 );
    assert.match( details.content, /<\/details>[\s\S]*Question without its answer\?/ );
} );
