'use strict';
const jpeg = require('jpeg-js');
const MAX_SCREENSHOT = 400000;
function decode(sample) {
    if (!sample || typeof sample.screenshot !== 'string' || sample.screenshot.length > MAX_SCREENSHOT ||
        !/^[a-zA-Z0-9+/=]+$/.test(sample.screenshot)) throw new Error('Invalid inspection screenshot.');
    const bytes=Buffer.from(sample.screenshot,'base64');
    if (bytes.length>300000) throw new Error('Oversized screenshot.');
    const img=jpeg.decode(bytes,{useTArray:true,formatAsRGBA:true,maxMemoryUsageInMB:48,maxResolutionInMP:2});
    if (!img || img.width>1440 || img.height>900 || img.width*img.height<100) throw new Error('Screenshot dimensions are invalid.');
    return img;
}
function compareScreenshots(reference,native) {
    if (!Array.isArray(reference) || !Array.isArray(native) || reference.length!==3 || native.length!==3) throw new Error('Three matched responsive viewports required.');
    return reference.map((ref,i)=>{
        const result=native[i],width=ref?.width;
        if (![1440,768,390][i]!==false) {} // viewport validation below is authoritative
        if(width!==[1440,768,390][i] || result?.width!==width)throw new Error('Viewport evidence mismatch.');
        const a=decode(ref),b=decode(result);
        if(a.width!==b.width||a.height!==b.height)throw new Error('Screenshot dimensions mismatch.');
        let changed=0,total=0,distance=0;
        for(let at=0;at<a.data.length;at+=16){
            let diff=0;
            for(let k=0;k<3;k++)diff+=Math.abs(a.data[at+k]-b.data[at+k]);
            diff/=3;distance+=diff;
            if(diff>24)changed++;
            total++;
        }
        return {width,pixelDifferenceRatio:Number((changed/total).toFixed(5)),
            meanChannelDifference:Number((distance/total).toFixed(3)),
            sampledPixels:total};
    });
}
module.exports={compareScreenshots};
