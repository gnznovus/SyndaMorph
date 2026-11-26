'use strict';

const assert=require('node:assert/strict');
const test=require('node:test');
const jpeg=require('jpeg-js');
const {compareScreenshots}=require('./compare');

function screenshot(height,color,patch=null) {
    const width=375;
    const data=Buffer.alloc(width*height*4);
    data.fill(Buffer.from([...color,255]));
    if(patch){
        for(let y=patch.top;y<height;y++)
            data.fill(Buffer.from([...patch.color,255]),(y*width+patch.left)*4,(y+1)*width*4);
    }
    return {width,extent:height,screenshot:jpeg.encode({width,height,data},85).data.toString('base64')};
}

test('overlap marks unmatched extent and keeps overlapping paint disagreements unresolved',()=>{
    const source=screenshot(160,[20,40,70]);
    const samePaint=screenshot(140,[20,40,70]);
    const changedPaint=screenshot(140,[220,200,170]);
    const inspected=compareScreenshots([source],[samePaint],true,true)[0];
    assert.ok(inspected.overlap.counts.matching>0);
    assert.ok(inspected.overlap.counts.sourceOnlyExtent>0);
    assert.equal(inspected.overlap.counts.designerOnlyExtent,0);
    const changed=compareScreenshots([samePaint],[source],true,true)[0];
    assert.ok(changed.overlap.counts.designerOnlyExtent>0);
    const disagreement=compareScreenshots([source],[changedPaint],true,true)[0];
    assert.ok(disagreement.overlap.counts.unresolvedOverlap>0);
    const ordinary=compareScreenshots([source],[changedPaint],true)[0];
    assert.equal(ordinary.overlap,undefined);
    assert.equal(disagreement.pixelDifferenceRatio,ordinary.pixelDifferenceRatio);
});

test('extent comparison localizes a mismatch without changing the gate measurements',()=>{
    const source=screenshot(160,[20,40,70]);
    const candidate=screenshot(160,[20,40,70],{top:141,left:251,color:[220,200,170]});
    const ordinary=compareScreenshots([source],[candidate],true)[0];
    const localized=compareScreenshots([source],[candidate],true,false,true)[0];
    const {differenceRegions,...comparison}=localized;
    assert.deepEqual(comparison,ordinary);
    assert.equal(differenceRegions.columns,3);
    assert.equal(differenceRegions.rows,8);
    assert.equal(differenceRegions.regions[0].row,7);
    assert.equal(differenceRegions.regions[0].column,2);
    assert.ok(differenceRegions.regions[0].changed>0);
    assert.deepEqual(compareScreenshots([source],[source],true,false,true)[0].differenceRegions.regions,[]);
});
