/* SyndaMorph entertainment-only team banter. No inference, validation or artifacts. */
( function ( root, factory ) {
    'use strict';
    const api = factory();
    if ( typeof module === 'object' && module.exports ) module.exports = api;
    else root.SyndaMorphDemoDialogue = api;
} )( typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    const NAMES = Object.freeze({
        boss: 'PM / Boss Agent',
        designer: 'Designer Agent',
        developer: 'Developer Agent',
    });
    // Three intent pools per role. An intent describes the conversation purpose;
    // each pool has alternative authored lines for repeat demonstrations.
    // Each index is one shared conversation scenario. All roles use the SAME
    // scenario index, so the discussion develops one topic instead of randomly
    // switching subjects at every turn.
    const TOPICS = Object.freeze(['spacing', 'responsive', 'interaction']);
    const POOLS = Object.freeze({
        boss: {
            intake: [
                'For this {source}, let us settle section spacing first. Designer, propose a rhythm; Developer, check whether Gutenberg can keep it editable.',
                'For this {source}, our question is the two-column layout on mobile. Designer, propose the stacking order; Developer, challenge any fragile behavior.',
                'For this {source}, we need to decide how to handle an interactive call-to-action. Designer, describe the interaction; Developer, propose the smallest maintainable implementation.',
            ],
            mediate: [
                'So we agree on breathing room, but not arbitrary pixel offsets. Designer, give Developer a consistent spacing rule.',
                'The mobile order concern is fair. Designer, lock the content priority; Developer can map it to native columns that stack.',
                'We can keep the interaction without shipping unnecessary JavaScript. Designer, confirm the simpler behavior before handoff.',
            ],
            close: [
                'Spacing decision recorded for this demo: consistent section rhythm using editable block settings. No source inspection or validation occurred.',
                'Responsive decision recorded for this demo: prioritize reading order and native stacking. No mobile viewport was actually tested.',
                'Interaction decision recorded for this demo: use native behavior before custom scripts. No working interaction was implemented.',
            ],
        },
        designer: {
            direction: [
                'I suggest generous gaps between sections and tighter gaps inside content groups. That gives the page rhythm without making every element float.',
                'I want the headline and key message first on small screens, with supporting imagery after them. Desktop can remain two columns.',
                'I pictured a button with a subtle animated entrance, but the real requirement is simply a clear, accessible call-to-action.',
            ],
            respond: [
                'Agreed: no magic offsets. I will define one section gap and one inner-content gap instead of styling every block separately.',
                'Agreed. The content should read in the same meaningful order on mobile; we can drop the decorative column trick.',
                'Fair. The animation is optional, so I will keep the call-to-action visible and usable without it.',
            ],
            handoff: [
                'Developer, use the shared section and inner-group spacing rules. Preserve those two levels of hierarchy in editable block controls.',
                'Developer, keep the text before imagery in the document order, then let native columns stack at smaller widths.',
                'Developer, build the call-to-action as a native button and leave animation out unless the brief explicitly requires it.',
            ],
        },
        developer: {
            feasibility: [
                'I can represent both gaps with native Group spacing. I would avoid absolute positioning because an editor could break the layout later.',
                'Native Columns will stack, but the document order matters. Please confirm that the text comes before the image in the source structure.',
                'A native Button covers the click target and link. Animation would require extra behavior, so I would not add it without a requirement.',
            ],
            approach: [
                'I would map section gaps to Group margins and inner gaps to block spacing. Both stay editable without custom scripts.',
                'I would use Columns and preserve the agreed reading order. That is simpler than custom breakpoint JavaScript.',
                'I would use a core Button with the required destination and visible focus behavior. No custom script needed for this proposal.',
            ],
            confirm: [
                'Spacing handoff acknowledged: two gap levels, both editable. No Gutenberg blocks have actually been generated.',
                'Responsive handoff acknowledged: text first, imagery second. No responsive screenshot or actual block build exists yet.',
                'Interaction handoff acknowledged: native button, no optional animation. No link or behavior has actually been tested.',
            ],
        },
    });
    const FLOW = Object.freeze([
        ['boss', 'intake', 'Kickoff'],
        ['designer', 'direction', 'Design direction'],
        ['developer', 'feasibility', 'Technical challenge'],
        ['designer', 'respond', 'Design response'],
        ['boss', 'mediate', 'Trade-off'],
        ['designer', 'handoff', 'Design handoff'],
        ['developer', 'approach', 'Implementation proposal'],
        ['developer', 'confirm', 'Acknowledgement'],
        ['boss', 'close', 'Wrap-up'],
    ]);
    function buildConversation( { kind = 'html', brief = '' } = {} ) {
        const source = { url: 'URL section', html: 'HTML page', package: 'ZIP package' }[ kind ];
        if ( !source ) throw new TypeError( 'Unsupported demo source type.' );
        const seed = String( brief ).length + kind.length;
        const scenario = seed % TOPICS.length;
        return FLOW.map( ( [ agent, intent, type ] ) => {
            const lines = POOLS[ agent ][ intent ];
            const chosen = lines[ scenario ];
            return Object.freeze( {
                agent, name: NAMES[ agent ], intent, type, topic: TOPICS[ scenario ],
                action: chosen.replaceAll( '{source}', source ),
            } );
        } );
    }
    return Object.freeze( { buildConversation, POOLS, FLOW, TOPICS } );
} );
