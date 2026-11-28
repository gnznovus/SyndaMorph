'use strict';

const { compareScreenshots } = require('./compare');
const { MAX_SCREENSHOT } = require('./inspection-contract');

const requiredWidths = [1440, 768, 375];
const uses = ['fold', 'hero', 'pair'];
const text = value => typeof value === 'string' && value.length > 0 && value.length <= 4000;
const image = value => typeof value === 'string' && value.length <= MAX_SCREENSHOT &&
    /^\/9j\/[a-zA-Z0-9+/]+={0,2}$/.test(value);

function validFirstFold(input, viewports) {
    const { runtime, frames, copy, title, anchor } = input;
    if (typeof runtime !== 'string' || !runtime || Buffer.byteLength(runtime) > 24576 ||
        !frames || typeof frames !== 'object' || Array.isArray(frames) ||
        !copy || !text(copy.desktop) || !text(copy.compact) || !text(copy.intro) ||
        !text(title) || typeof anchor !== 'string' || !/^#[a-z][a-z0-9-]{0,63}$/.test(anchor)) return false;
    const widths = viewports.filter(width => requiredWidths.includes(width));
    return Object.keys(frames).length === widths.length && widths.every(width => {
        const row = frames[width];
        const names = width === 1440 ? uses : uses.slice(0, 2);
        return row && Object.keys(row).length === names.length && names.every(name => {
            const frame = row[name];
            return frame && image(frame.image) &&
                frame.extent === ({ fold:900, hero:width === 1440 ? 760 : 560, pair:1352 })[name] &&
                frame.top === (name === 'fold' ? 0 : width === 1440 ? 141 : 72);
        });
    });
}

const same = (actual, expected) => JSON.stringify(actual) === JSON.stringify(expected);

async function snapshot(page, width, frame) {
    const session = await page.context().newCDPSession(page);
    try {
        const { extent, top } = frame;
        const { data } = await session.send('Page.captureScreenshot', {
            format:'jpeg', quality:58, captureBeyondViewport:true,
            clip:{ x:0, y:top, width, height:extent, scale:Math.min(1,900/extent) },
        });
        const reference = { width, extent, screenshot:frame.image };
        return compareScreenshots([reference], [{ width, extent, screenshot:data }], extent > 900)[0].pixelDifferenceRatio;
    } finally {
        await session.detach();
    }
}

async function checkBooking(page, booking) {
    const guests = booking.getByRole('button',{ name:/Rooms & guests/i });
    const rate = booking.getByRole('button',{ name:/Rate preference/i });
    if (await guests.count() !== 1 || await rate.count() !== 1 ||
        await booking.locator('a,form,input,select').count() !== 0) return false;
    await guests.click({ timeout:2500 });
    if (await guests.getAttribute('aria-expanded') !== 'true') return false;
    for (const [key, limits] of Object.entries({ rooms:[1,9], adults:[1,8], children:[0,8] })) {
        const counter = booking.locator('[data-sm-counter="'+key+'"]');
        if (await counter.count() !== 1) return false;
        const output = counter.locator('output');
        const step = delta => counter.locator('[data-sm-step="'+delta+'"]');
        for (let i=0;i<10;i++) await step(1).evaluate(button=>button.click());
        if (await output.innerText() !== String(limits[1])) return false;
        for (let i=0;i<10;i++) await step(-1).evaluate(button=>button.click());
        if (await output.innerText() !== String(limits[0])) return false;
    }
    await page.keyboard.press('Escape');
    if (await guests.getAttribute('aria-expanded') !== 'false') return false;
    const choices = booking.locator('[data-sm-rate]');
    const rates = ['Best Available','AAA/CAA Rate','Government/Military Business Rate',
        'Member Senior Discounts','Reward Nights'];
    await rate.press('Enter',{ timeout:2500 });
    if (!same(await choices.allTextContents(),rates)) return false;
    for (let i=0;i<rates.length;i++){
        if (i) await rate.press('Enter',{ timeout:2500 });
        await choices.nth(i).click({ timeout:2500 });
        if (await rate.getAttribute('aria-expanded') !== 'false' ||
            await booking.locator('[data-sm-booking-rate]').innerText() !== rates[i]) return false;
    }
    return true;
}

async function checkFirstFold(page, width, contract) {
    const { anchor, copy, title } = contract;
    const frame = contract.frames[width];
    const desktop = width >= 1024;
    const main = page.locator('#syndamorph-page');
    const hero = main.locator(':scope > '+(desktop ? '.bkkgo-desktop-hero-lead' : '.bkkgo-compact-hero'));
    const intro = main.locator(':scope > .sm-view-desktop:not(.bkkgo-desktop-hero-lead)');
    const offer = main.locator(':scope > [data-sm-offer] .sm-offer-card');
    const result = { responsive:false, functional:false, copy:false, ratios:{} };
    if (await hero.count() !== 1 || await intro.count() !== 1 || await offer.count() !== 1 ||
        await offer.isVisible() !== desktop || await intro.isVisible() !== desktop) return result;
    if (frame) result.ratios.fold = await snapshot(page,width,frame.fold);
    if (desktop) {
        await offer.locator('.sm-offer-dismiss').click({ timeout:2500 });
        if (await offer.isVisible()) return result;
    }
    const measured = await main.evaluate((element,{ desktop, title, anchor }) => {
        const hero = element.querySelector(desktop ? '.bkkgo-desktop-hero-lead' : '.bkkgo-compact-hero');
        const intro = element.querySelector(':scope > .sm-view-desktop:not(.bkkgo-desktop-hero-lead)');
        const booking = hero.querySelector('[data-sm-booking]');
        const normalize = value => value.replace(/\s+/gu,' ').trim();
        const geometry = node => {
            const {x,y,width,height} = node.getBoundingClientRect();
            return [x,y,width,Math.round(height)];
        };
        const literal = node => {
            // Extract the authored case without CSS text-transform on visible controls.
            const walk = document.createTreeWalker(node,NodeFilter.SHOW_TEXT);
            const words = [];
            while (walk.nextNode()) {
                const parent = walk.currentNode.parentElement;
                if (parent?.checkVisibility({visibilityProperty:true}) && normalize(walk.currentNode.textContent))
                    words.push(walk.currentNode.textContent);
            }
            return normalize(words.join(' '));
        };
        const target = document.getElementById(anchor.slice(1));
        return { hero:geometry(hero), intro:geometry(intro), heroCopy:literal(hero), introCopy:literal(intro),
            titleCount:normalize(element.innerText.replaceAll('’',"'")).split(title).length-1,
            horizontal:document.documentElement.scrollWidth,
            overlap:booking ? Math.round(booking.getBoundingClientRect().bottom-intro.getBoundingClientRect().top) : 0,
            booking:booking ? geometry(booking) : null,
            anchors:target && element.contains(target) ? element.querySelectorAll(anchor).length : 0 };
    },{ desktop,title,anchor });
    const sectionWidth=desktop?Math.max(width,1280):width;
    result.responsive = same(measured.hero,[0,desktop?141:72,sectionWidth,desktop?760:560]) &&
        (desktop?same(measured.intro,[0,901,sectionWidth,592])&&measured.overlap===44&&
            same(measured.booking,[(sectionWidth-1120)/2,857,1120,88]):same(measured.intro,[0,0,0,0])) &&
        measured.horizontal===sectionWidth;
    if (!result.responsive) {
        const { hero, intro, overlap, horizontal, booking } = measured;
        result.geometry = { hero, intro, overlap, horizontal, booking };
    }
    result.copy = measured.titleCount===1 && measured.heroCopy===(desktop?copy.desktop:copy.compact) &&
        measured.introCopy===(desktop?copy.intro:'');
    if (frame) {
        result.ratios.hero = await snapshot(page,width,frame.hero);
        if (desktop) result.ratios.pair = await snapshot(page,width,frame.pair);
    }
    const links = hero.locator('a[href]');
    if (!desktop) {
        result.functional = await hero.locator('a[href],button,[role=button],form,input,select').count() === 0;
    } else if (measured.anchors === 1 && await links.count() === 1 && await links.getAttribute('href') === anchor) {
        result.functional = await checkBooking(page,hero.locator('[data-sm-booking]'));
        await links.click({ timeout:2500 });
        result.functional = result.functional && new URL(page.url()).hash === anchor;
    }
    return result;
}

module.exports = { validFirstFold, checkFirstFold };
