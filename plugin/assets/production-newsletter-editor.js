/* global wp */
(() => {
    'use strict';
    const { createElement: h } = wp.element;
    const { useBlockProps } = wp.blockEditor;
    const { TextControl } = wp.components;
    const fields = ['Heading','Name label','Email label','Country prefix','Telephone label','Consent text','Subscribe label'];
    wp.blocks.registerBlockType('syndamorph/recorded-newsletter', {
        apiVersion: 3,
        title: 'Recorded Newsletter',
        category: 'widgets',
        attributes: { align: { type:'string',enum:['full'],default:'full' }, profile: { type:'object',default:{} } },
        supports: { align:['full'],html:false,customClassName:false,multiple:false,inserter:false },
        edit({ attributes, setAttributes }) {
            const profile=attributes.profile;
            const props=useBlockProps();
            return h('div',props,...['desktop','compact'].map(variant=>h('fieldset',{key:variant},
                h('legend',{},variant==='desktop'?'Desktop copy':'Compact copy'),
                ...fields.map((label,i)=>h(TextControl,{
                    key:label,label,maxLength:120,value:profile[variant]?.[i]??'',
                    disabled:variant==='desktop'&&i===3,
                    onChange:value=>{
                        const copy=[...(profile[variant]??[])];
                        copy[i]=value;
                        setAttributes({profile:{...profile,[variant]:copy}});
                    },
                })))),
                h('fieldset',{},h('legend',{},'Country choices'),
                    ...(profile.choices??[]).map((choice,i)=>h(TextControl,{
                        key:i,label:'Country '+choice.label,value:choice.name,maxLength:120,
                        onChange:name=>setAttributes({profile:{...profile,choices:profile.choices.map((row,index)=>
                            index===i?{...row,name}:row)}}),
                    }))));
        },
        save() { return null; },
    });
})();
