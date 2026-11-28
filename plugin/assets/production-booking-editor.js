/* global wp, syndamorphBookingDefaults */
(() => {
    const { createElement: h, Fragment } = wp.element;
    const { InspectorControls, useBlockProps } = wp.blockEditor;
    const { PanelBody, TextControl, ToggleControl } = wp.components;
    const fields = {
        dates: 'Dates label', arrival: 'Arrival', departure: 'Departure', guests: 'Guests label',
        rate: 'Rate label', availability: 'Availability label', rooms: 'Rooms counter',
        adults: 'Adults counter', children: 'Children counter',
    };

    wp.blocks.registerBlockType('syndamorph/booking-summary', {
        title: 'Booking Summary',
        category: 'widgets',
        icon: 'calendar-alt',
        apiVersion: 3,
        attributes: {
            copy: { type: 'object', default: {} },
            overlap: { type: 'boolean', default: false },
        },
        supports: { html: false, customClassName: false },
        edit({ attributes, setAttributes }) {
            const props = useBlockProps();
            const values = { ...syndamorphBookingDefaults, ...attributes.copy };
            for (const key of ['rates', 'notes']) {
                if (!Array.isArray(values[key]) || values[key].length !== syndamorphBookingDefaults[key].length) {
                    values[key] = syndamorphBookingDefaults[key];
                }
            }
            const update = (key, value) => setAttributes({ copy: { ...attributes.copy, [key]: value } });
            const labelField = (id, label, value, onChange) => h(TextControl, {
                key: id, label, value, onChange, maxLength: 100,
            });
            const editList = (key, label) => values[key].map((value, index) => labelField(key + index,
                label + ' ' + (index + 1), value, next => update(key, values[key].map((text, i) => i === index ? next : text))));
            return h(Fragment, null,
                h(InspectorControls, null, h(PanelBody, { title: 'Booking Summary' },
                    h(ToggleControl, { label: 'Overlap the following section', checked: attributes.overlap,
                        onChange: overlap => setAttributes({ overlap }) }),
                    ...Object.entries(fields).map(([key, label]) =>
                        labelField(key, label, values[key], value => update(key, value))),
                    ...editList('rates', 'Rate option'), ...editList('notes', 'Rate note'))),
                h('div', props,
                    h('div', { className: 'sm-booking-field' }, h('span', { className: 'sm-booking-label' }, values.dates),
                        h('span', { className: 'sm-booking-dates' }, values.arrival, ' ', values.departure)),
                    h('div', { className: 'sm-booking-field' }, h('span', { className: 'sm-booking-label' }, values.guests),
                        h('span', null, '1 room · 2 guests')),
                    h('div', { className: 'sm-booking-field' }, h('span', { className: 'sm-booking-label' }, values.rate),
                        h('span', null, values.rates[0])),
                    h('span', { className: 'sm-booking-availability' }, values.availability)));
        },
        save() { return null; },
    });
})();
