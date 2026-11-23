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
    const META = Object.freeze({
        spacing: { keywords: ['spacing', 'padding', 'margin', 'gap', 'whitespace', 'rhythm'] },
        responsive: { keywords: ['mobile', 'responsive', 'breakpoint', 'stack', 'column', 'viewport'] },
        interaction: { keywords: ['animation', 'button', 'hover', 'click', 'javascript', 'interaction', 'cta'] },
    });
    const OBJECTIONS = Object.freeze({
        spacing: [
            'One more concern: using the same gap everywhere will flatten the hierarchy. Should the inner group remain tighter?',
            'Hold on. What happens when the text wraps? Fixed heights would make that section spacing fragile.',
        ],
        responsive: [
            'One more concern: stacking alone may put the supporting image ahead of the headline. Can we commit to semantic order?',
            'Before we agree, what happens to the call-to-action when those two columns collapse?',
        ],
        interaction: [
            'One more concern: keyboard users must reach the action without depending on hover. Can we promise that?',
            'Before signing off, how will the button behave without JavaScript enabled?',
        ],
    });
    const ANSWERS = Object.freeze({
        spacing: [
            'Yes, inner content gets a smaller gap than section boundaries. No fixed heights are needed.',
            'Good catch. The spacing rule will follow content flow, so wrapped text can grow naturally.',
        ],
        responsive: [
            'Agreed. Headline and action stay first in document order; imagery follows when the layout stacks.',
            'The call-to-action stays with the main text column, before the supporting image.',
        ],
        interaction: [
            'Agreed. The native button keeps a clear focus state and works without a hover gesture.',
            'No JavaScript dependency for the primary action. The link works even when scripts are disabled.',
        ],
    });
    const CLARIFY = Object.freeze({
        spacing: 'Designer, can we express the two spacing levels as editable Group settings rather than manual offsets?',
        responsive: 'Designer, confirm the content order so Developer can use the native column stack.',
        interaction: 'Developer, confirm that a core Button meets the interaction requirement before we add custom behavior.',
    });
    const MAX_TURNS = 16;
    function pickTopic(brief, seed) {
        const words = String(brief).toLowerCase().match(/[a-z]+/g) || [];
        const scores = TOPICS.map(topic => META[topic].keywords.reduce((score, key) => score + words.filter(word => word === key).length, 0));
        const best = Math.max(...scores);
        return best ? TOPICS[scores.indexOf(best)] : TOPICS[seed % TOPICS.length];
    }
    function hashSeed(value) {
        let hash = 2166136261;
        for (const char of String(value)) hash = Math.imul(hash ^ char.charCodeAt(0), 16777619);
        return hash >>> 0;
    }
    function seededRandom(seed) {
        let state = (seed >>> 0) || 1;
        return () => {
            state ^= state << 13;
            state ^= state >>> 17;
            state ^= state << 5;
            return (state >>> 0) / 4294967296;
        };
    }
    // A session generates the NEXT response from the current branch and shared
    // topic. Randomness decides whether new objections appear, never the topic.
    function createSession({ kind = 'html', brief = '', seed, random } = {}) {
        const source = { url: 'URL section', html: 'HTML page', package: 'ZIP package' }[kind];
        if (!source) throw new TypeError('Unsupported demo source type.');
        const startSeed = seed === undefined ? hashSeed(String(brief) + Date.now() + Math.random()) : seed;
        if (!Number.isSafeInteger(startSeed) || startSeed < 0) throw new TypeError('Invalid seed.');
        const rng = random === undefined ? seededRandom(startSeed) : random;
        if (typeof rng !== 'function') throw new TypeError('Random function required.');
        const topic = pickTopic(brief, startSeed);
        const topicIndex = TOPICS.indexOf(topic);
        const state = { topic, turnCount: 0, tension: 1, objections: 0, aligned: false, resolved: false, forced: false, phase: 'intake' };
        const used = new Set();
        let pending = [];
        let last = null;
        const scripted = (agent, intent, type) => ({ agent, intent, type, action: POOLS[agent][intent][topicIndex] });
        function branch() {
            const n = Number(rng());
            const chance = Number.isFinite(n) ? Math.max(0, Math.min(0.999999, n)) : 0.5;
            // At least one objection is possible; no endless dispute.
            if (state.objections < 2 && chance < (state.objections ? 0.28 : 0.58) && state.turnCount < MAX_TURNS - 6) {
                const variant = state.objections;
                state.objections++;
                state.tension++;
                return [
                    {agent:'developer',intent:'challenge',type:'Follow-up objection',action:OBJECTIONS[topic][variant]},
                    {agent:'designer',intent:'revise',type:'Revision',action:ANSWERS[topic][variant]},
                    {agent:'boss',intent:'clarify',type:'Clarification',action:CLARIFY[topic]},
                ];
            }
            state.aligned = true;
            return [
                scripted('boss','mediate','Trade-off'),
                scripted('designer','handoff','Design handoff'),
                scripted('developer','approach','Implementation proposal'),
                scripted('developer','confirm','Acknowledgement'),
                scripted('boss','close','Peace route'),
            ];
        }
        pending = [
            scripted('boss','intake','Kickoff'),
            scripted('designer','direction','Design direction'),
            scripted('developer','feasibility','Technical challenge'),
            scripted('designer','respond','Design response'),
        ];
        function next() {
            if (state.resolved) return null;
            if (!pending.length) pending = branch();
            if (state.turnCount >= MAX_TURNS - 1 && !state.aligned) {
                pending = [{agent:'boss',intent:'close',type:'Unresolved — review required',
                    action:'We have reached the demo conversation limit without agreement. Escalating for real review; no work has been validated.'}];
                state.forced = true;
            }
            const step = pending.shift();
            if (!step) return null;
            const action = step.action.replaceAll('{source}', source);
            // A repeated clarification can be intentional, but never repeat an
            // identical line: distinguish follow-up context without inventing work.
            const utterance = used.has(action) ? 'Following up on the same concern: ' + action : action;
            used.add(action);
            const message = Object.freeze({
                agent:step.agent, name:NAMES[step.agent], intent:step.intent, type:step.type,
                topic, action:utterance,
            });
            last = message;
            state.turnCount++;
            if (step.intent === 'revise') state.tension = Math.max(0, state.tension - 1);
            if (step.intent === 'close') {
                state.resolved = true;
                state.tension = 0;
            }
            return message;
        }
        return Object.freeze({
            next,
            get state() { return Object.freeze({ ...state, lastSpeaker:last?.agent || null }); },
        });
    }
    // Convenience for deterministic testing; the UI consumes the live session
    // one turn at a time instead.
    function buildConversation(options = {}) {
        const session = createSession(options);
        const transcript = [];
        let message;
        while ((message = session.next())) transcript.push(message);
        return transcript;
    }
    return Object.freeze({ buildConversation, createSession, POOLS, TOPICS, META, MAX_TURNS });
} );
