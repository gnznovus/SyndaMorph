'use strict';
const http = require('node:http');
const { timingSafeEqual } = require('node:crypto');
const { capture, captureResponsive } = require('./capture');
const { productionSource } = require('./production-source');
const { captureOwnedRegion, captureSettledPair } = require('./responsive-capture');
const { enabled: referenceEnabled,readReference,recordedPng } = require('./production-reference');
const { validateTouchActions,inspectTouch,disclosureRef,inspectDisclosure,inspectImageStrip,inspectLinkedImage,inspectRevealCards,inspectRooms,inspectRelocatedCopy } = require('./production-touch');
const { createAnalyzeHandler } = require('./mvp/endpoint');
const { runCommunicationPoc } = require('./purr-poc');

const TOKEN = process.env.SYNDAMORPH_BACKEND_TOKEN;
if (!TOKEN || !process.env.SYNDAMORPH_CAPTURE_HOSTS) throw new Error('Backend token and approved host list are required.');
const backendToken = Buffer.from(TOKEN);
const analyze = createAnalyzeHandler({ capture });
let responsiveInProgress = false;

const respond = (res, status, value) => {
    res.writeHead(status, { 'content-type': 'application/json; charset=utf-8', 'cache-control': 'no-store' });
    res.end(JSON.stringify(value));
};
http.createServer(async (req, res) => {
    const health = req.method === 'GET' && req.url === '/healthz';
    if (!health && (!['/capture','/production-source','/production-touch','/mvp/analyze','/purr/communication-poc'].includes(req.url) || req.method !== 'POST')) {
        return respond(res, 404, { error: 'Not found.' });
    }
    const supplied = req.headers['x-syndamorph-token'];
    if (typeof supplied !== 'string' || Buffer.byteLength(supplied) !== backendToken.length ||
        !timingSafeEqual(backendToken, Buffer.from(supplied))) return respond(res, 403, { error: 'Forbidden.' });
    if (health) return respond(res, 200, { service: 'syndamorph-backend', protocol: 1 });
    let total = 0;
    const parts = [];
    try {
        for await (const part of req) {
            total += part.length;
            if (total > 8192) return respond(res, 413, { error: 'Request exceeds limit.' });
            parts.push(part);
        }
        const input = JSON.parse(Buffer.concat(parts).toString('utf8'));
        if (req.url === '/purr/communication-poc') {
            if (typeof input.job !== 'string' || input.job.length > 200) return respond(res, 400, { error: 'Invalid POC job.' });
            const result = await runCommunicationPoc(input.job);
            return respond(res, 200, result);
        }
        if(req.url==='/production-touch'){
            if(!referenceEnabled())return respond(res,503,{ error:'Touch requires a verified source archive.' });
            if(responsiveInProgress)return respond(res,429,{ error:'Responsive capture is already running.' });
            const disclosure=input.kind==='disclosure-group';
            const imageStrip=input.kind==='single-image-strip';
            const linkedImage=input.kind==='linked-image';
            const revealCards=input.kind==='reveal-cards';
            const rooms=input.kind==='room-showcase';
            const relocation=input.kind==='responsive-copy-placement';
            const settledPair=input.kind==='settled-content-pair';
            const scoped=disclosure||imageStrip||linkedImage||revealCards||rooms||relocation||settledPair;
            if(scoped&&!disclosureRef(input.sourceRef))return respond(res,400,{error:'Invalid recorded region target.'});
            if(relocation&&(!disclosureRef(input.destinationRef)||input.sourceRef===input.destinationRef||
                typeof input.copy!=='string'||input.copy.length<3||input.copy.length>160))
                return respond(res,400,{error:'Invalid responsive placement target.'});
            if(settledPair&&(input.width!==1440||
                ![input.adjacentRef,input.leadRef,input.overlayRef].every(disclosureRef)||
                new Set([input.sourceRef,input.adjacentRef,input.leadRef,input.overlayRef]).size!==4||
                typeof input.dismissTargetId!=='string'||!/^[a-f0-9]{24}$/.test(input.dismissTargetId)||
                typeof input.pinnedHeroSha256!=='string'||!/^[a-f0-9]{64}$/.test(input.pinnedHeroSha256)))
                return respond(res,400,{error:'Invalid settled capture identity.'});
            const crop=input.ownedCrop;
            if(crop!==undefined&&(!relocation||input.width!==1440||!disclosureRef(crop?.overlapRef)||
                typeof crop.compositedSha256!=='string'||!/^[a-f0-9]{64}$/.test(crop.compositedSha256)))
                return respond(res,400,{error:'Invalid owned desktop crop.'});
            const actions=scoped?[]:validateTouchActions(input.actions);
            responsiveInProgress=true;
            try {
                const pinned=await readReference(input.archiveId,input.width);
                if(!pinned.runtime.size)return respond(res,200,{ version:1,archiveId:input.archiveId,status:'unknown',
                    ...(disclosure?{width:input.width,sourceRef:input.sourceRef}:{}),
                    reason:'runtime_not_recorded',initial:{ width:input.width,targets:[] },scenarios:[] });
                const captured=await capture(pinned.sourceUrl,'browser',{ width:input.width,touch:true,
                    recordedResponses:pinned.runtime,
                    inspect:(page,_regions,_diagnostics,_archive,seal)=>disclosure?
                        inspectDisclosure(page,{width:input.width,sourceRef:input.sourceRef,copySha256:pinned.copySha256},seal):
                        imageStrip?inspectImageStrip(page,{width:input.width,sourceRef:input.sourceRef,copySha256:pinned.copySha256},seal):
                        linkedImage?inspectLinkedImage(page,{width:input.width,sourceRef:input.sourceRef,copySha256:pinned.copySha256},seal):
                        revealCards?inspectRevealCards(page,{width:input.width,sourceRef:input.sourceRef,copySha256:pinned.copySha256},seal):
                        rooms?inspectRooms(page,{width:input.width,sourceRef:input.sourceRef,copySha256:pinned.copySha256},seal):
                        settledPair?captureSettledPair(page,_regions,{
                            width:input.width,ownerRef:input.sourceRef,adjacentRef:input.adjacentRef,leadRef:input.leadRef,
                            overlayRef:input.overlayRef,dismissTargetId:input.dismissTargetId,
                            pinnedHeroSha256:input.pinnedHeroSha256,copySha256:pinned.copySha256,
                        },seal):
                        relocation?(async()=>{
                            const placement=await inspectRelocatedCopy(page,{width:input.width,sourceRef:input.sourceRef,
                                destinationRef:input.destinationRef,copy:input.copy,copySha256:pinned.copySha256},seal);
                            if(!crop||placement.status!=='observed')return placement;
                            let ownedCrop;
                            try{
                                ownedCrop=await captureOwnedRegion(page,_regions,_diagnostics,{width:input.width,sourceRef:input.sourceRef,
                                    destinationRef:input.destinationRef,overlapRef:crop.overlapRef,compositedSha256:crop.compositedSha256});
                            }catch{
                                return {...placement,ownedCrop:{status:'pending',reason:'owned_crop_capture_failed'}};
                            }
                            return ownedCrop&&seal().length===0?{...placement,ownedCrop}:
                                {...placement,ownedCrop:{status:'pending',reason:'owned_crop_unverified'}};
                        })():
                        inspectTouch(page,{width:input.width,actions,copySha256:pinned.copySha256},seal) });
                let receipt=captured;
                const artwork=imageStrip?receipt.initial:linkedImage?receipt.image:null;
                if(artwork&&receipt.status==='observed'){
                    const image=recordedPng(pinned.runtime,artwork.url);
                    if(!image||image.pixels.some((value,index)=>value!==artwork.pixels[index]))
                        return respond(res,200,{archiveId:input.archiveId,mode:'recorded',status:'unknown',reason:'archived_image_unverified'});
                    const {body,...metadata}=image;
                    receipt={...receipt,asset:metadata};
                    if(linkedImage||input.width===1440)receipt.assetBase64=body.toString('base64');
                }
                return respond(res,200,{ archiveId:input.archiveId,mode:'recorded',...receipt });
            } finally { responsiveInProgress=false; }
        }
        if (typeof input.url !== 'string' || input.url.length > 2048) return respond(res, 400, { error: 'Invalid URL.' });
        if (req.url === '/production-source') {
            const archives=referenceEnabled() && (input.sectionId!=null || input.variants!=null);
            if(archives && responsiveInProgress)return respond(res,429,{ error:'Responsive capture is already running.' });
            if(archives)responsiveInProgress=true;
            try { return respond(res,200,await productionSource(input.url,input.sectionId,input.variants)); }
            finally { if(archives)responsiveInProgress=false; }
        }
        if (req.url === '/mvp/analyze') {
            const result = await analyze(input);
            return respond(res, 200, result);
        }
        if (input.responsive === true) {
            if (responsiveInProgress) return respond(res, 429, { error: 'Responsive capture is already running.' });
            responsiveInProgress = true;
            try {
                const result = await captureResponsive(input.url);
                const body = JSON.stringify(result);
                if (Buffer.byteLength(body) > 24000000) return respond(res, 413, { error: 'Responsive capture exceeds the archive limit.' });
                res.writeHead(200, { 'content-type': 'application/json; charset=utf-8', 'cache-control': 'no-store' });
                return res.end(body);
            } catch (error) {
                if (error.code === 'sm_reference_unverified')
                    return respond(res, 422, { error: 'Rendered reference verification is incomplete.' });
                return respond(res, 422, { error: error.message === 'URL host is not approved for this POC.'
                    ? error.message : 'Responsive page capture failed. Verify all three viewport renders.' });
            } finally { responsiveInProgress = false; }
        }
        const result = await capture(input.url, input.mode || 'auto');
        return respond(res, 200, result);
    } catch (error) {
        // Never include API credentials, upstream response body, or source HTML in errors.
        const message = String(error.message || '');
        const allowed = /^(MVP AI analysis is disabled\.|ANTHROPIC_API_KEY is not configured\.|Invalid MVP analysis request\.|Requested section is not available in the capture\.|Claude API request failed \(HTTP \d+\)\.|Claude response incomplete or interrupted\.|Claude returned invalid JSON\.|Claude returned no bounded JSON output\.|Claude changed section identity\.)$/;
        if (req.url === '/purr/communication-poc') {
            const errorTypes = {
                purr_not_configured: [503, 'Purr Gateway is not configured in the backend.'],
                purr_auth: [502, 'Purr Gateway denied the client credentials.'],
                purr_invalid_job: [400, 'Invalid POC job.'],
                purr_invalid: [502, 'Purr Gateway returned an unexpected response.'],
            };
            const [code, publicMessage] = errorTypes[message] || [502, 'Purr Gateway request failed or timed out.'];
            return respond(res, code, { error: publicMessage });
        }
        if(req.url==='/production-touch')return respond(res,422,{ error:'Touch inspection evidence is unavailable.' });
        if (req.url === '/mvp/analyze' && !allowed.test(message)) {
            return respond(res, 422, { error: 'MVP analysis failed; inspect server diagnostics.' });
        }
        return respond(res, 422, { error: message });
    }
}).listen(3100, '0.0.0.0');
