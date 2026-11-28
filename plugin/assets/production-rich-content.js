/* Bounded, DOM-only Markdown subset and Mermaid flowchart subset. No HTML injection. */
(() => {
    'use strict';
    const el = (tag, value) => {
        const node = document.createElement(tag);
        if (value !== undefined) node.textContent = value;
        return node;
    };
    const svgNS = 'http://www.w3.org/2000/svg';
    const svgEl = (tag, attrs) => {
        const node = document.createElementNS(svgNS, tag);
        Object.entries(attrs).forEach(([key, value]) => node.setAttribute(key, String(value)));
        return node;
    };
    function inline(node, source) {
        const pattern = /(\x60[^\x60\n]+\x60|\*\*[^*\n]+\*\*|__[^_\n]+__|\*[^*\n]+\*|\[[^\]\n]+\]\([^)\n]+\))/g;
        let offset = 0;
        for (const match of source.matchAll(pattern)) {
            node.append(document.createTextNode(source.slice(offset, match.index)));
            const token = match[0];
            if (token.startsWith('\x60')) node.append(el('code', token.slice(1, -1)));
            else if (token.startsWith('**') || token.startsWith('__')) node.append(el('strong', token.slice(2, -2)));
            else if (token.startsWith('*')) node.append(el('em', token.slice(1, -1)));
            else {
                // Model links are text, not navigable links. No remote resources or URL interpretation.
                const split = token.indexOf('](');
                node.append(el('span', token.slice(1, split) + ' (' + token.slice(split + 2, -1) + ')'));
            }
            offset = match.index + token.length;
        }
        node.append(document.createTextNode(source.slice(offset)));
        return node;
    }
    function flowchart(source) {
        const lines = source.trim().split(/\r?\n/).map(v => v.trim()).filter(v => v && !v.startsWith('%%'));
        const header = /^(?:flowchart|graph)\s+(TD|TB|LR|RL|BT)\s*;?$/i.exec(lines.shift() || '');
        if (!header || lines.length > 60) return null;
        const nodes = new Map(), edges = [];
        function parseNode(raw) {
            const m = /^([A-Za-z][\w-]{0,39})(?:\s*(?:\["?([^"\]]{1,100})"?\]|\{"?([^"}]{1,100})"?\}|\("?([^")]{1,100})"?\)))?$/.exec(raw.trim());
            if (!m) return null;
            const label = m[2] || m[3] || m[4];
            if (!nodes.has(m[1]) || label) nodes.set(m[1], {id:m[1], label:label || m[1]});
            return m[1];
        }
        for (const raw of lines) {
            const line = raw.replace(/;$/, '');
            const edge = /^(.*?)\s*(?:-->\s*(?:\|([^|]{1,60})\|\s*)?|---\s*)(.+)$/.exec(line);
            if (edge) {
                const from = parseNode(edge[1]), to = parseNode(edge[3]);
                if (!from || !to) return null;
                edges.push({from,to,label:edge[2] || ''});
            } else if (!parseNode(line)) return null;
            if (nodes.size > 24 || edges.length > 48) return null;
        }
        if (!nodes.size) return null;
        // Layer acyclic forward edges. Back edges remain visible as feedback links.
        const levels = new Map([...nodes.keys()].map(id => [id,0]));
        const order = new Map([...nodes.keys()].map((id,i) => [id,i]));
        for (const id of nodes.keys()) for (const edge of edges) {
            if (edge.from === id && order.get(edge.to) > order.get(id))
                levels.set(edge.to, Math.max(levels.get(edge.to), levels.get(id)+1));
        }
        const groups = new Map();
        for (const id of nodes.keys()) {
            const level = levels.get(id);
            if (!groups.has(level)) groups.set(level, []);
            groups.get(level).push(id);
        }
        const horizontal = /^(LR|RL)$/i.test(header[1]);
        const reversed = /^(RL|BT)$/i.test(header[1]);
        const depth = Math.max(...levels.values())+1, breadth = Math.max(...[...groups.values()].map(v=>v.length));
        const width = horizontal ? depth*220+40 : breadth*200+40;
        const height = horizontal ? breadth*100+40 : depth*120+40;
        const svg = svgEl('svg',{viewBox:'0 0 '+width+' '+height,role:'img','aria-label':'Agent flowchart'});
        svg.classList.add('sm-rich-diagram');
        const positions = new Map();
        for (const [level, ids] of groups) ids.forEach((id,index) => {
            const row = reversed ? depth-level-1 : level;
            positions.set(id, horizontal ? {x:30+row*220,y:30+index*100} : {x:30+index*200,y:30+row*120});
        });
        for (const edge of edges) {
            const a=positions.get(edge.from), b=positions.get(edge.to);
            const x1=a.x+80,y1=a.y+24,x2=b.x+80,y2=b.y+24;
            svg.append(svgEl('path',{d:'M'+x1+' '+y1+' L'+x2+' '+y2,stroke:'currentColor','stroke-opacity':'.55',fill:'none','stroke-width':1.5}));
            if(edge.label) {
                const label=svgEl('text',{x:(x1+x2)/2,y:(y1+y2)/2-5,'font-size':10,'text-anchor':'middle',fill:'currentColor'});
                label.textContent=edge.label.slice(0,60);svg.append(label);
            }
        }
        for(const [id,n] of nodes) {
            const p=positions.get(id);
            svg.append(svgEl('rect',{x:p.x,y:p.y,width:160,height:48,rx:6,fill:'#f4f7fa',stroke:'#60758a'}));
            const t=svgEl('text',{x:p.x+80,y:p.y+28,'text-anchor':'middle','font-size':12,fill:'#18283a'});
            t.textContent=n.label.length>24?n.label.slice(0,23)+'…':n.label;
            const tip=svgEl('title',{});tip.textContent=n.label;t.append(tip);svg.append(t);
        }
        return svg;
    }
    function codeBlock(source, language, complete) {
        const box = el('div'); box.className = 'sm-rich-code';
        const bar=el('div');bar.className='sm-rich-code-toolbar';
        bar.append(el('span',language || 'Text'));
        const copy=el('button','Copy');copy.type='button';
        copy.addEventListener('click',async()=>{try{await navigator.clipboard.writeText(source);copy.textContent='Copied';}catch(_){copy.textContent='Copy unavailable';}});
        bar.append(copy);box.append(bar);
        if(language.toLowerCase()==='mermaid' && complete) {
            const diagram=flowchart(source);
            if(diagram) box.append(diagram);
            else box.append(el('p','Unsupported diagram syntax; source is preserved.'));
        }
        const pre=el('pre');pre.append(el('code',source));box.append(pre);
        return box;
    }
    const cells = line => line.trim().replace(/^\||\|$/g,'').split('|').map(v=>v.trim());
    const divider = line => /^\s*\|?\s*:?-{3,}:?\s*(?:\|\s*:?-{3,}:?\s*)+\|?\s*$/.test(line || '');
    function markdown(source, complete) {
        const root=el('div'); root.className='sm-rich-markdown';
        const lines=source.split(/\r?\n/);
        let i=0;
        while(i<lines.length) {
            const line=lines[i];
            if(!line.trim()){i++;continue;}
            const fence=/^\s*\x60{3,}([\w-]*)\s*$/.exec(line);
            if(fence) {
                let j=i+1;
                while(j<lines.length && !/^\s*\x60{3,}\s*$/.test(lines[j]))j++;
                root.append(codeBlock(lines.slice(i+1,j).join('\n'),fence[1],j<lines.length));
                i=j+1;continue;
            }
            const h=/^(#{1,6})\s+(.+)$/.exec(line);
            if(h){root.append(inline(el('h'+Math.min(h[1].length+2,6)),h[2]));i++;continue;}
            if(/^\s*(?:---+|\*\*\*+)\s*$/.test(line)){root.append(el('hr'));i++;continue;}
            if(line.includes('|') && divider(lines[i+1])) {
                const wrap=el('div');wrap.className='sm-rich-table-wrap';
                const table=el('table'), head=el('thead'), hr=el('tr'), body=el('tbody');
                cells(line).forEach(v=>hr.append(inline(el('th'),v)));head.append(hr);table.append(head);
                i+=2;
                while(i<lines.length && lines[i].trim() && lines[i].includes('|')) {
                    const tr=el('tr');cells(lines[i++]).forEach(v=>tr.append(inline(el('td'),v)));body.append(tr);
                }
                table.append(body);wrap.append(table);root.append(wrap);continue;
            }
            const li=/^\s*(?:([-+*])|(\d+)\.)\s+(.+)$/.exec(line);
            if(li) {
                const list=el(li[2]?'ol':'ul');
                while(i<lines.length) {
                    const m=/^\s*(?:([-+*])|(\d+)\.)\s+(.+)$/.exec(lines[i]);
                    if(!m || Boolean(m[2])!==Boolean(li[2]))break;
                    list.append(inline(el('li'),m[3]));i++;
                }
                root.append(list);continue;
            }
            if(line.startsWith('> ')){root.append(inline(el('blockquote'),line.slice(2)));i++;continue;}
            root.append(inline(el('p'),line));i++;
        }
        return root;
    }
    function mount(parent, kind='markdown') {
        const toolbar=el('div');toolbar.className='sm-rich-toolbar';
        const toggle=el('button',(kind==='html'||kind==='json')?'Formatted':'Raw');toggle.type='button';
        const content=el('div');content.className='sm-rich-content';
        toolbar.append(toggle);parent.append(toolbar,content);
        let value='',raw=(kind==='html'||kind==='json'),done=false,timer=null;
        function paint() {
            timer=null;
            toggle.textContent=raw?'Rendered':'Raw';
            content.replaceChildren(raw?el('pre',value):(kind==='html'||kind==='json')?codeBlock(value,kind,done):markdown(value,done));
        }
        toggle.addEventListener('click',()=>{raw=!raw;if(timer!==null)clearTimeout(timer);paint();});
        return {
            update(next,complete=false) {
                value=String(next||'').slice(0,120000);done=complete;
                if(complete){if(timer!==null)clearTimeout(timer);paint();}
                else if(timer===null)timer=setTimeout(paint,100);
            },
            destroy(){if(timer!==null)clearTimeout(timer);parent.replaceChildren();}
        };
    }
    window.SyndaMorphRichContent=Object.freeze({mount});
})();
