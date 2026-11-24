<?php
/** Shared production contract: skills guide work; criteria govern review. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function syndamorph_production_standards(): array {
    return array(
        'version' => '1.1.0',
        'deliverable' => 'One independently embeddable semantic HTML section with a single scoped style element. Not a full HTML document. The host owns document language, title, metadata, viewport, page heading hierarchy and page-level structured data. Do not add document wrappers or demand them in review.',
        'skills' => array(
            'boss' => 'Requirement analysis, scope control, acceptance criteria, dependency planning and delivery contracts.',
            'designer' => 'Visual hierarchy, responsive composition, accessible typography, contrast, design tokens and interaction states.',
            'developer' => 'Semantic HTML, fluid CSS, Grid/Flexbox, responsive layout, accessibility, maintainability and native Gutenberg compatibility planning.',
            'validator' => 'Evidence-based static review, accessibility, semantic SEO, responsive CSS, security, performance and explicit verification limits.',
        ),
        'techniques' => array(
            'semantic' => 'Use semantic elements, meaningful heading order, descriptive links, readable text and truthful content; avoid fabricated schema, metadata or business claims.',
            'fluid' => 'Use relative units, clamp(), min(), max(), flexible Grid/Flexbox, min-width:0 where needed, and content-driven breakpoints; avoid rigid widths and unnecessary fixed heights. Include sensible fallbacks.',
            'tokens' => 'Use section-scoped custom properties for repeated color, spacing and type values. Scope selectors to the section; avoid global resets.',
            'accessible' => 'Target WCAG 2.2 AA: contrast, keyboard operability, visible focus, meaningful accessible names, reflow and reduced-motion preferences where applicable.',
            'performance' => 'Minimize dependencies, excessive DOM, expensive effects and unnecessary assets. Do not invent integrations or asset URLs.',
            'gutenberg' => 'Prefer structures that can map to native blocks; flag custom behavior and unsupported CSS rather than claiming actual block conversion.',
        ),
        'criteria' => array(
            'SEO-01' => 'Semantic HTML, logical heading hierarchy and descriptive links; page-level SEO belongs to host.',
            'AIO-01' => 'Clear, factual, self-contained content with meaningful structure; no invented facts or guaranteed search/AI ranking.',
            'RES-01' => 'Design for mobile, tablet and desktop (375, 768, 1440 CSS px); no unintended horizontal overflow or clipped content.',
            'FLUID-01' => 'Fluid sizing and spacing where useful, flexible layout and content-driven breakpoints; explain exceptions.',
            'A11Y-01' => 'WCAG 2.2 AA target, readable contrast, focus and semantic controls; mark browser-dependent findings unverified.',
            'PERF-01' => 'Minimal dependencies and reasonable markup/CSS; no measured performance claims without measurement.',
            'MAINT-01' => 'Scoped styles, reusable tokens, understandable structure, no unnecessary duplication.',
            'SAFE-01' => 'No executable scripts, external assets, remote URLs, forms, iframes or unrequested integrations in this review-only fragment.',
            'WP-01' => 'Prefer native-block-compatible structure where feasible; Gutenberg editability remains unverified until actual conversion and editor checks.',
        ),
    );
}

function syndamorph_production_context( string $role, string $format = 'html' ): string {
    $s = syndamorph_production_standards();
    if ($format === 'gutenberg') {
        $s['deliverable'] = 'Native editable Gutenberg block plan; no intermediate HTML or arbitrary CSS. Host owns page metadata.';
        $s['techniques']['tokens'] = 'Use supported native block style attributes, repeated literal design values and native layout containers.';
        $s['techniques']['gutenberg'] = 'Respect the versioned block-plan schema, nesting, block supports and native responsive columns. No custom HTML blocks.';
        $s['criteria']['WP-01'] = 'Only supported native blocks and schema fields; distinguish compiler structural checks from actual editor validation.';
        $s['criteria']['MAINT-01'] = 'Reusable native block structure, consistent design values and no arbitrary CSS.';
    }
    $lines = array( 'Production standard v' . $s['version'], $s['deliverable'], 'Role skills: ' . ( $s['skills'][ $role ] ?? '' ), 'Shared techniques:' );
    foreach ( $s['techniques'] as $id => $technique ) { $lines[] = $id . ': ' . $technique; }
    $lines[] = 'Mandatory acceptance criteria (apply where relevant; explain N/A):';
    foreach ( $s['criteria'] as $id => $criterion ) { $lines[] = $id . ': ' . $criterion; }
    $lines[] = 'Never claim browser, SEO ranking, accessibility compliance, performance or Gutenberg verification without actual evidence. Distinguish static findings from pending runtime tests.';
    $lines[] = 'For non-Developer roles use readable Markdown headings, short paragraphs, lists and tables. Optional diagrams use fenced mermaid blocks with simple flowchart TD or LR, node IDs and arrows. Developer returns HTML only.';
    if ($format === 'gutenberg') { $lines[count($lines)-1] = 'Use concise Markdown for non-developer reports. Developer returns JSON only. ' . syndamorph_block_plan_contract(); }
    if ($role !== 'validator') { $lines[] = 'Apply mandatory standards silently; do not repeat the full checklist. Report only role-specific decisions.'; }
    return implode( "\n", $lines );
}
