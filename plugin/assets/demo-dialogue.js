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
    const POOLS = Object.freeze({
        boss: {
            intake: [
                'New {source} brief received. Designer, define the visual direction first; Developer, prepare the Gutenberg reality check.',
                'Team, we have a {source} request. I want the layout idea and its implementation concerns on the table before we pretend to celebrate.',
                '{source} is on the board. Designer takes composition, Developer takes feasibility. Please keep the dramatic sighing under budget.',
            ],
            mediate: [
                'Designer wants the polish; Developer wants sensible blocks. Fair. Keep the appearance, simplify what cannot be maintained.',
                'I hear the visual ambition and the implementation caution. Find a middle ground that would not frighten the next editor.',
                'Both concerns noted. Prefer editable structure over clever tricks; no one gets bonus points for mystery CSS.',
            ],
            close: [
                'Demo meeting adjourned. We exchanged opinions, not actual design files, compiled blocks, or validation results.',
                'That is a simulated handoff, team. No pixels were inspected and no Gutenberg blocks were harmed.',
                'Nice imaginary teamwork. Actual source analysis and fidelity approval remain future jobs for real adapters.',
            ],
        },
        designer: {
            direction: [
                'For {source}, I would start with clear hierarchy, a strong hero, and breathing room. Purely a design suggestion.',
                'My imaginary mood board says consistent spacing and deliberate typography. No screenshot measurements were taken.',
                'I propose a simple visual rhythm: headline, supporting content, then supporting sections. This is a mock direction.',
            ],
            respond: [
                'Fine, Developer. I will trade the fancy motion for clean native structure. But the spacing stays.',
                'Fair point about maintenance. I can keep the composition without relying on twenty layers of absolute positioning.',
                'I heard the block limitations. Let us keep the layout modular and avoid decoration that cannot be edited.',
            ],
            handoff: [
                'Design handoff suggestion: group related content, keep readable widths, and let the editor own the text.',
                'Visual note for implementation: preserve hierarchy first, and use a reusable pattern where the structure repeats.',
                'I am handing over an idea, not an asset. Please do not mark it as a completed design.',
            ],
        },
        developer: {
            feasibility: [
                'Reality check: native Gutenberg blocks first. Custom JavaScript only if an actual requirement justifies it.',
                'I can work with a clean grid and groups. A thousand nested wrappers? I would like to file a complaint.',
                'Before anything gets built, we should verify which interaction really needs a custom block.',
            ],
            approach: [
                'Proposed implementation path: semantic groups, native headings, and reusable components. No code has been generated.',
                'I would split the structure into independently editable sections and avoid fragile CSS dependencies.',
                'The hypothetical build would favor core blocks, with custom behavior isolated behind an explicit interface.',
            ],
            confirm: [
                'Acknowledged. This is still a rehearsal; the compile queue is empty and there is nothing to deploy.',
                'Copy that. I have zero generated blocks to report, which is excellent for our imaginary bug count.',
                'Received. Waiting for actual analysis before anyone calls this implementation complete.',
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
        return FLOW.map( ( [ agent, intent, type ], index ) => {
            const lines = POOLS[ agent ][ intent ];
            const chosen = lines[ ( seed + index ) % lines.length ];
            return Object.freeze( {
                agent, name: NAMES[ agent ], intent, type,
                action: chosen.replaceAll( '{source}', source ),
            } );
        } );
    }
    return Object.freeze( { buildConversation, POOLS, FLOW } );
} );
