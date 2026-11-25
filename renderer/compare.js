'use strict';
const jpeg = require('jpeg-js');
const { validViewports, MAX_SCREENSHOT, MAX_JPEG, HEIGHT, RendererError } = require('./inspection-contract');
function decode(sample) {
    if (!sample || typeof sample.screenshot !== 'string' || sample.screenshot.length > MAX_SCREENSHOT ||
        !/^[a-zA-Z0-9+/=]+$/.test(sample.screenshot)) throw new Error('Invalid inspection screenshot.');
    const bytes=Buffer.from(sample.screenshot,'base64');
    if (bytes.length>MAX_JPEG) throw new Error('Oversized screenshot.');
    const img=jpeg.decode(bytes,{useTArray:true,formatAsRGBA:true,maxMemoryUsageInMB:48,maxResolutionInMP:2});
    if (!img || img.width>1920 || img.height>HEIGHT || img.width*img.height<100) throw new Error('Screenshot dimensions are invalid.');
    return img;
}
/** Visual extent labels are geometry, not claims about element semantics. */
function overlapEvidence(a,b,width,beforeHeight,afterHeight) {
    const maxHeight=Math.max(beforeHeight,afterHeight);
    const scale=Math.min(1,320/width,420/maxHeight);
    const cols=Math.max(1,Math.round(width*scale));
    const rows=Math.max(1,Math.round(maxHeight*scale));
    const data=Buffer.alloc(cols*rows*4);
    const counts={ matching:0,sourceOnlyExtent:0,designerOnlyExtent:0,unresolvedOverlap:0 };
    const palette={ matching:[220,220,220],sourceOnlyExtent:[233,158,34],
        designerOnlyExtent:[36,146,202],unresolvedOverlap:[181,28,96] };
    for(let y=0;y<rows;y++){
        const logicalY=(y+0.5)*maxHeight/rows;
        const before=logicalY<beforeHeight;
        const after=logicalY<afterHeight;
        for(let x=0;x<cols;x++){
            let label;
            if(!before)label='designerOnlyExtent';
            else if(!after)label='sourceOnlyExtent';
            else {
                const pixel=(image,height)=>(Math.floor(logicalY*image.height/height)*image.width+
                    Math.floor(x*image.width/cols))*4;
                const at=pixel(a,beforeHeight),bt=pixel(b,afterHeight);
                let delta=0;
                for(let channel=0;channel<3;channel++)delta+=Math.abs(a.data[at+channel]-b.data[bt+channel]);
                label=delta>72?'unresolvedOverlap':'matching';
            }
            counts[label]++;
            const offset=(y*cols+x)*4;
            for(let channel=0;channel<3;channel++)data[offset+channel]=palette[label][channel];
            data[offset+3]=255;
        }
    }
    return { width:cols,height:rows,counts,media_type:'image/jpeg',
        data:jpeg.encode({data,width:cols,height:rows},75).data.toString('base64') };
}

function compareScreenshots(reference,native,fitExtent=false,includeOverlay=false) {
    if (!Array.isArray(reference) || !validViewports(reference.map(sample => sample?.width)) ||
        !Array.isArray(native) || native.length !== reference.length) throw new Error('Matched responsive viewports required.');
    return reference.map((ref,i)=>{
        const result=native[i],width=ref?.width;
        if(result?.width!==width)throw new Error('Viewport evidence mismatch.');
        const a=decode(ref),b=decode(result);
        const fits = (image, extent) => Number.isInteger(extent) && extent > 0 && extent <= 36000 &&
            Math.abs(image.width - width * Math.min(1, HEIGHT / extent)) <= 2 && Math.abs(image.height - Math.min(extent, HEIGHT)) <= 2;
        if(fitExtent ? !fits(a,ref.extent)||!fits(b,result.extent) : a.width!==width||a.width!==b.width||a.height!==b.height)
            throw new Error('Screenshot dimensions mismatch.');
        let changed=0,total=0,distance=0;
        const cols=fitExtent?Math.min(a.width,b.width,320):a.width;
        const rows=fitExtent?Math.min(a.height,b.height,HEIGHT):a.height;
        for(let index=0;index<cols*rows;index+=4){
            const x=index%cols,y=Math.floor(index/cols);
            const at=fitExtent?(Math.floor(y*a.height/rows)*a.width+Math.floor(x*a.width/cols))*4:index*4;
            const bt=fitExtent?(Math.floor(y*b.height/rows)*b.width+Math.floor(x*b.width/cols))*4:at;
            let diff=0;
            for(let k=0;k<3;k++)diff+=Math.abs(a.data[at+k]-b.data[bt+k]);
            diff/=3;distance+=diff;
            if(diff>24)changed++;
            total++;
        }
        return {width,pixelDifferenceRatio:Number((changed/total).toFixed(5)),
            meanChannelDifference:Number((distance/total).toFixed(3)),
            sampledPixels:total,...(includeOverlay?{ overlap:overlapEvidence(
                a,b,width,fitExtent?ref.extent:a.height,fitExtent?result.extent:b.height) }:{})};
    });
}
function cropScreenshot(sample,rect) {
    const img=decode(sample);
    if(!Array.isArray(rect)||rect.length!==4||rect.some(x=>!Number.isFinite(x)))throw Error('Invalid crop rectangle.');
    const x=Math.max(0,Math.floor(rect[0]-12)),y=Math.max(0,Math.floor(rect[1]-12));
    const right=Math.min(img.width,Math.ceil(rect[0]+rect[2]+12)),bottom=Math.min(img.height,Math.ceil(rect[1]+rect[3]+12));
    if(right-x<2||bottom-y<2)throw Error('Component outside screenshot.');
    const width=right-x,height=bottom-y,data=Buffer.alloc(width*height*4);
    for(let row=0;row<height;row++) Buffer.from(img.data.buffer,img.data.byteOffset+((y+row)*img.width+x)*4,width*4).copy(data,row*width*4);
    for (const quality of [70, 50, 30, 15]) {
        const bytes = jpeg.encode({data,width,height},quality).data;
        if (bytes.length <= MAX_JPEG) return bytes.toString('base64');
    }
    throw new RendererError('screenshot_limit', 507);
}
function cropScreenshots(items) {
    if(!Array.isArray(items)||items.length<1||items.length>4)throw Error('Expected one to four crops.');
    return items.map(item=>({media_type:'image/jpeg',data:cropScreenshot(item,item.rect)}));
}
module.exports={compareScreenshots,cropScreenshots,validViewports};
