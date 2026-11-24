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
function cropScreenshot(sample,rect) {
    const img=decode(sample);
    if(!Array.isArray(rect)||rect.length!==4||rect.some(x=>!Number.isFinite(x)))throw Error('Invalid crop rectangle.');
    const x=Math.max(0,Math.floor(rect[0]-12)),y=Math.max(0,Math.floor(rect[1]-12));
    const right=Math.min(img.width,Math.ceil(rect[0]+rect[2]+12)),bottom=Math.min(img.height,Math.ceil(rect[1]+rect[3]+12));
    if(right-x<2||bottom-y<2)throw Error('Component outside screenshot.');
    const width=right-x,height=bottom-y,data=Buffer.alloc(width*height*4);
    for(let row=0;row<height;row++) Buffer.from(img.data.buffer,img.data.byteOffset+((y+row)*img.width+x)*4,width*4).copy(data,row*width*4);
    return jpeg.encode({data,width,height},70).data.toString('base64');
}
function cropScreenshots(items) {
    if(!Array.isArray(items)||items.length<1||items.length>4)throw Error('Expected one to four crops.');
    return items.map(item=>({media_type:'image/jpeg',data:cropScreenshot(item,item.rect)}));
}
module.exports={compareScreenshots,cropScreenshots};
