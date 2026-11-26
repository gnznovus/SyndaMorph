/* global wp */
(() => {
    const { registerBlockType } = wp.blocks;
    const { createElement: h, Fragment } = wp.element;
    const { Button, TextControl, TextareaControl } = wp.components;
    const { useBlockProps } = wp.blockEditor;
    const encode = panels => btoa(Array.from(new TextEncoder().encode(JSON.stringify(panels)),
        byte => String.fromCharCode(byte)).join(''));
    const decode = value => {
        try {
            const panels = JSON.parse(new TextDecoder().decode(Uint8Array.from(atob(value), char => char.charCodeAt(0))));
            return Array.isArray(panels) ? panels : [];
        } catch (_) {
            return [];
        }
    };
    const initial = [
        { id: 'panel-1', label: 'First tab', heading: 'First section', body: 'Add your first section’s content.' },
        { id: 'panel-2', label: 'Second tab', heading: 'Second section', body: 'Add your second section’s content.' },
    ];
    registerBlockType('syndamorph/tabs', {
        title: 'Content Tabs',
        category: 'design',
        apiVersion: 3,
        icon: 'index-card',
        attributes: {
            panelData: { type: 'string', default: '' },
            component: { type: 'string' },
            appearance: { type: 'string', enum: ['standard'] },
        },
        supports: { html: false },
        edit({ attributes, setAttributes }) {
            const blockProps = useBlockProps();
            const panels = decode(attributes.panelData);
            const update = next => setAttributes({ panelData: encode(next) });
            const change = (index, key, value, max) => update(
                panels.map((panel, i) => i === index ? { ...panel, [key]: Array.from(value).slice(0, max).join('') } : panel));
            const add = () => {
                let index = 1;
                while (panels.some(panel => panel.id === 'panel-' + index)) index++;
                update([...panels, {
                    id: 'panel-' + index, label: 'New tab', heading: 'New section', body: 'Add your section’s content.',
                }]);
            };
            if (!panels.length) {
                return h('div', blockProps,
                    h(Button, { variant: 'primary', onClick: () => update(initial) }, 'Set up tabs'));
            }
            return h('div', blockProps,
                panels.map((panel, index) => h(Fragment, { key: panel.id },
                    h('fieldset', { className: 'sm-tabs-editor-fields' },
                        h('legend', null, 'Tab ' + (index + 1)),
                        h(TextControl, { label: 'Tab label', value: panel.label, onChange: value => change(index, 'label', value, 80) }),
                        h(TextControl, { label: 'Panel heading', value: panel.heading, onChange: value => change(index, 'heading', value, 160) }),
                        h(TextareaControl, { label: 'Panel text', value: panel.body, onChange: value => change(index, 'body', value, 1600) }),
                        panels.length > 2 ? h(Button, { isDestructive: true, onClick: () =>
                            update(panels.filter((_, i) => i !== index)) }, 'Remove tab') : null))),
                panels.length < 6 ? h(Button, { variant: 'secondary', onClick: add }, 'Add tab') : null);
        },
        save() { return null; },
    });
})();
