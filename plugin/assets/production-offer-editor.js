/* global wp */
(() => {
    const { createElement: h } = wp.element;
    const { useBlockProps } = wp.blockEditor;
    const { TextControl, TextareaControl } = wp.components;
    const fields = [
        ['placeholder', 'Placeholder label', 40],
        ['eyebrow', 'Offer category', 60],
        ['title', 'Offer title', 100],
        ['description', 'Description', 220],
        ['offerLabel', 'Promotional label', 80],
        ['dismissLabel', 'Dismiss button', 80],
    ];

    wp.blocks.registerBlockType('syndamorph/desktop-offer', {
        apiVersion: 3,
        title: 'Desktop Offer',
        category: 'widgets',
        icon: 'welcome-widgets-menus',
        attributes: { copy: { type: 'object', default: {} } },
        supports: { html: false, customClassName: false, multiple: false },
        edit({ attributes, setAttributes }) {
            const props = useBlockProps();
            const copy = attributes.copy;
            return h('div', props, ...fields.map(([key, label, maxLength]) =>
                h(key === 'description' ? TextareaControl : TextControl, {
                    key, label, maxLength, value: copy[key] ?? '',
                    onChange: value => setAttributes({ copy: { ...copy, [key]: value } }),
                })));
        },
        save() { return null; },
    });
})();
