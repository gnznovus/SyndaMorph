/* global wp */
(() => {
    const { createElement: h } = wp.element;
    const { decodeEntities } = wp.htmlEntities;
    const { useBlockProps, MediaUpload, MediaUploadCheck } = wp.blockEditor;
    const { Button, TextControl, ToggleControl } = wp.components;
    const lists = [
        ['utility', 'Desktop utility navigation', true], ['primary', 'Desktop primary navigation', true],
        ['compactPrimary', 'Compact primary rows', false], ['compactUtility', 'Compact utility rows', false],
    ];
    const field = (key, label, value, onChange, maxLength = 120) =>
        h(TextControl, { key, label, value: decodeEntities(value ?? ''), onChange, maxLength });
    const updateEntry = (items, index, value) => items.map((item, i) => i === index ? value : item);

    function rows(items, onChange, linked, nested) {
        const controls = items.map((item, i) => {
            const update = (key, value) => {
                const next = { ...item, [key]: value };
                if (!linked && key === 'href' && !value) delete next.href;
                if ((key === 'children' && !value.length) || (key === 'panelWidth' && value === null)) delete next.panelWidth;
                onChange(updateEntry(items, i, next));
            };
            return h('fieldset', { key: i },
                field('label', 'Label', item.label, value => update('label', value)),
                field('href', linked ? 'HTTPS destination' : 'HTTPS destination (optional)', item.href,
                    value => update('href', value), 2048),
                nested && !linked && h(ToggleControl, { label: 'Emphasize divider', checked: !!item.emphasis,
                    onChange: value => update('emphasis', value) }),
                nested && linked && item.children?.length > 0 && h(TextControl, { label: 'Panel width in pixels',
                    type: 'number', min: 180, max: 380, value: item.panelWidth ?? '',
                    onChange: value => update('panelWidth', value ? Number(value) : null) }),
                !nested && linked && h(ToggleControl, { label: 'Separator before row', checked: !!item.separatorBefore,
                    onChange: value => update('separatorBefore', value) }),
                nested && h('details', null, h('summary', null, 'Child rows'),
                    rows(item.children ?? [], value => update('children', value), linked, false)),
                h(Button, { variant: 'tertiary', isDestructive: true,
                    onClick: () => onChange(items.filter((_, index) => index !== i)) }, 'Remove row'));
        });
        return h('div', null, ...controls, h(Button, { variant: 'secondary', disabled: items.length >= 16,
            onClick: () => onChange([...items, { label: '', ...(linked ? { href: '' } : {}) }]) }, 'Add row'));
    }

    wp.blocks.registerBlockType('syndamorph/recorded-header', {
        apiVersion: 3,
        title: 'Recorded Header',
        category: 'design',
        icon: 'menu',
        attributes: { navigation: { type: 'object', default: {} } },
        supports: { html: false, multiple: false, customClassName: false },
        edit({ attributes, setAttributes }) {
            const props = useBlockProps();
            const data = attributes.navigation;
            const set = (key, value) => setAttributes({ navigation: { ...data, [key]: value } });
            const languages = data.languages ?? [];
            return h('div', props,
                h(MediaUploadCheck, null, h(MediaUpload, { allowedTypes: ['image'], value: data.logoId,
                    onSelect: image => setAttributes({ navigation: { ...data, logoId: image.id, logoAlt: image.alt } }),
                    render: ({ open }) => h(Button, { variant: 'secondary', onClick: open },
                        data.logoId ? 'Replace header logo' : 'Choose header logo') })),
                field('logoAlt', 'Logo alt text', data.logoAlt, value => set('logoAlt', value)),
                field('homeUrl', 'Logo destination', data.homeUrl, value => set('homeUrl', value), 2048),
                field('rateUrl', 'Check rate destination', data.rateUrl, value => set('rateUrl', value), 2048),
                field('rateLabel', 'Check rate label', data.rateLabel, value => set('rateLabel', value)),
                field('bookLabel', 'Compact booking display label', data.bookLabel, value => set('bookLabel', value)),
                field('languageLabel', 'Current language label', data.languageLabel, value => set('languageLabel', value)),
                h('details', null, h('summary', null, 'Language labels'),
                    ...languages.map((name, i) => h('div', { key: i },
                        field(i, 'Language ' + (i + 1), name, value => set('languages', updateEntry(languages, i, value))),
                        h(Button, { variant: 'tertiary', isDestructive: true,
                            onClick: () => set('languages', languages.filter((_, index) => index !== i)) }, 'Remove language'))),
                    h(Button, { variant: 'secondary', disabled: languages.length >= 8,
                        onClick: () => set('languages', [...languages, '']) }, 'Add language')),
                ...lists.map(([key, label, linked]) => h('details', { key }, h('summary', null, label),
                    rows(data[key] ?? [], value => set(key, value), linked, true))));
        },
        save() { return null; },
    });
})();
