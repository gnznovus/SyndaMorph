<?php
/**
 * AI Production Team — real, brief-only streaming AI execution.
 * Real AI execution generates text/HTML for review; publishing is disabled.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function syndamorph_team_render_tab(): void {
    if ( ! current_user_can( 'manage_options' ) ) { return; }
    ?>
    <div class="wrap" id="syndamorph-production-team">
        <div class="sm-dashboard">
            <aside class="sm-sidebar" aria-label="Operations navigation">
                <div class="sm-brand">
                    <span class="sm-brand-mark" aria-hidden="true">S</span>
                    <div><div class="sm-brand-name">SyndaMorph</div><div class="sm-brand-caption">Operations</div></div>
                </div>
                <div>
                    <p class="sm-nav-label">Workspace</p>
                    <nav class="sm-nav" aria-label="SyndaMorph modules">
                        <a class="sm-nav-item" href="<?php echo esc_url( admin_url( 'admin.php?page=syndamorph' ) ); ?>"><span class="dashicons dashicons-dashboard" aria-hidden="true"></span> Overview</a>
                        <a class="sm-nav-item is-active" href="<?php echo esc_url( admin_url( 'admin.php?page=syndamorph&tab=production' ) ); ?>" aria-current="page">
                            <span class="dashicons dashicons-groups" aria-hidden="true"></span> AI Production Team
                        </a>
                        <a class="sm-nav-item" href="<?php echo esc_url( admin_url( 'admin.php?page=syndamorph&tab=ai' ) ); ?>">
                            <span class="dashicons dashicons-admin-network" aria-hidden="true"></span> AI Integration
                        </a>
                        <a class="sm-nav-item" href="<?php echo esc_url( admin_url( 'admin.php?page=syndamorph&tab=gateway' ) ); ?>">
                            <span class="dashicons dashicons-shield" aria-hidden="true"></span> Client Access Manager
                        </a>
                    </nav>
                </div>
                <div class="sm-sidebar-footer">
                    <strong>Internal MVP</strong><br>
                    AI Production is the primary development path. Deterministic conversion remains a fallback. Nothing publishes automatically.
                </div>
            </aside>
            <main class="sm-main">
                <div class="sm-topline">
                    <span class="sm-breadcrumb">Workspace / <strong>AI Production Team</strong></span>
                    <span class="sm-environment">MVP · DEVELOPMENT</span>
                </div>
                <p class="sm-kicker">Production workspace</p>
                <h1 class="sm-page-title">AI Production Team</h1>
                <p class="sm-page-description">Generate with the AI Production Team, review results and inspect saved jobs. The manual converter remains available as a no-inference fallback.</p>

                <nav class="sm-team-tabs" aria-label="Production workflow stages" role="tablist">
                    <button type="button" id="sm-team-tab-setup" role="tab" aria-controls="sm-team-panel-setup" aria-selected="true" tabindex="0" data-panel="setup">01 <strong>Setup</strong><span>Prepare job</span></button>
                    <button type="button" id="sm-team-tab-production" role="tab" aria-controls="sm-team-panel-production" aria-selected="false" tabindex="-1" data-panel="production">02 <strong>Production</strong><span>Live activity</span></button>
                    <button type="button" id="sm-team-tab-results" role="tab" aria-controls="sm-team-panel-results" aria-selected="false" tabindex="-1" data-panel="results">03 <strong>Results</strong><span>Review output</span></button>
                    <button type="button" id="sm-team-tab-history" role="tab" aria-controls="sm-team-panel-history" aria-selected="false" tabindex="-1" data-panel="history">04 <strong>History</strong><span>Saved jobs</span></button>
                </nav>

                <section id="sm-team-panel-setup" class="sm-team-panel" role="tabpanel" aria-labelledby="sm-team-tab-setup" data-panel="setup">
                    <div class="sm-team-layout sm-team-setup-grid">
                        <section class="sm-card" aria-labelledby="sm-team-source-title">
                            <div class="sm-card-header">
                                <h2 class="sm-card-title" id="sm-team-source-title">Source import</h2>
                                <p class="sm-card-caption">Select URL, HTML or ZIP. Inspect source content through the existing SyndaMorph pipeline before conversion.</p>
                            </div>
                            <div class="sm-card-body">
                                <div class="sm-field">
                                    <label class="sm-field-label" for="syndamorph-team-source-type">Source type</label>
                                    <select class="sm-control" id="syndamorph-team-source-type">
                                        <option value="url">Website URL</option>
                                        <option value="html">HTML markup</option>
                                        <option value="package">ZIP package</option>
                                    </select>
                                </div>
                                <div class="sm-field sm-team-source-input" data-source="url">
                                    <label class="sm-field-label" for="syndamorph-team-source">Page URL</label>
                                    <input class="sm-control" type="url" id="syndamorph-team-source" placeholder="https://example.com/landing-page" maxlength="2048" autocomplete="off">
                                    <label class="sm-field-label" for="syndamorph-team-scope">Conversion scope</label>
                                    <select class="sm-control" id="syndamorph-team-scope"><option value="section">Section</option><option value="page">Full Page</option></select>
                                    <div class="sm-field sm-team-capture-options"><label class="sm-field-label" for="syndamorph-team-render-mode">Rendering mode</label>
                                        <select class="sm-control" id="syndamorph-team-render-mode"><option value="auto">Automatic browser capture</option><option value="static">Static HTML only</option><option value="browser">Force Playwright</option></select></div>
                                    <p class="sm-hint">The actual capture runs through the existing SyndaMorph service.</p>
                                </div>
                                <div class="sm-field sm-team-source-input" data-source="html" hidden>
                                    <label class="sm-field-label" for="syndamorph-team-html-file">HTML file</label>
                                    <div class="sm-team-upload" data-drop-kind="html" tabindex="0" aria-label="HTML upload drop zone. Drop an HTML or HTM file, or press Enter to browse.">
                                        <input type="file" id="syndamorph-team-html-file" class="sm-team-native-file" accept=".html,.htm,text/html" aria-describedby="syndamorph-team-html-file-name">
                                        <label class="sm-team-file-button" for="syndamorph-team-html-file">Choose HTML file</label>
                                        <span class="sm-team-file-name" id="syndamorph-team-html-file-name" aria-live="polite">Drop HTML / HTM here or browse</span>
                                    </div>
                                    <p class="sm-team-upload-feedback" data-upload-feedback="html" role="status" aria-live="polite"></p>
                                    <p class="sm-team-source-divider">OR PASTE HTML</p>
                                    <label class="sm-field-label" for="syndamorph-team-html">HTML source</label>
                                    <textarea class="sm-control" id="syndamorph-team-html" rows="7" placeholder="Paste HTML markup here"></textarea>
                                    <p class="sm-hint">Small files open in the editor; large files remain selected for conversion without loading the textarea.</p>
                                    <div class="sm-team-advanced sm-team-source-option">
                                        <label for="syndamorph-team-js-html"><input type="checkbox" id="syndamorph-team-js-html"> Render inline JavaScript (experimental)</label>
                                        <p class="sm-hint">Render trusted HTML scripts using the isolated Docker renderer.</p>
                                    </div>
                                </div>
                                <div class="sm-field sm-team-source-input" data-source="package" hidden>
                                    <label class="sm-field-label" for="syndamorph-team-package">ZIP package</label>
                                    <div class="sm-team-upload" data-drop-kind="package" tabindex="0" aria-label="ZIP upload drop zone. Drop a ZIP file, or press Enter to browse.">
                                        <input type="file" id="syndamorph-team-package" class="sm-team-native-file" accept=".zip,application/zip" aria-describedby="syndamorph-team-package-name">
                                        <label class="sm-team-file-button" for="syndamorph-team-package">Choose ZIP package</label>
                                        <span class="sm-team-file-name" id="syndamorph-team-package-name" aria-live="polite">Drop ZIP here or browse</span>
                                    </div>
                                    <p class="sm-team-upload-feedback" data-upload-feedback="package" role="status" aria-live="polite"></p>
                                    <p class="sm-hint">Package files are uploaded only when you choose Scan package.</p>
                                    <div class="sm-team-advanced sm-team-source-option">
                                        <label for="syndamorph-team-js-package"><input type="checkbox" id="syndamorph-team-js-package"> Render packaged JavaScript (experimental)</label>
                                        <p class="sm-hint">For ZIPs containing JavaScript assets. Execution uses the isolated renderer; enable only for trusted packages.</p>
                                    </div>
                                </div>
                                <div class="sm-team-source-actions" id="syndamorph-team-source-actions">
                                    <button type="button" class="sm-team-secondary" id="syndamorph-team-inspect">Inspect source</button>
                                    <span id="syndamorph-team-inspect-status" role="status" aria-live="polite">Ready for inspection</span>
                                </div>
                                <div id="syndamorph-team-source-inspection" class="sm-team-inspection" hidden>
                                    <label class="sm-field-label" for="syndamorph-team-section">Detected page / section</label>
                                    <select class="sm-control" id="syndamorph-team-section"></select>
                                    <p class="sm-hint" id="syndamorph-team-source-summary"></p>
                                    <details id="syndamorph-team-source-diagnostics" hidden><summary>Capture diagnostics</summary><pre id="syndamorph-team-source-diagnostics-json"></pre></details>
                                </div>
                                <div class="sm-team-source-footer">
                                    <p class="sm-team-note">Convert &amp; Preview uses the manual converter without AI calls.</p>
                                    <button type="button" class="sm-primary" id="syndamorph-team-convert">Convert &amp; Preview <span aria-hidden="true">→</span></button>
                                    <p class="sm-team-action-feedback" id="syndamorph-team-convert-error" role="alert" hidden></p>
                                </div>
                            </div>
                        </section>
                        <section class="sm-card" aria-labelledby="sm-team-brief-title">
                            <div class="sm-card-header">
                                <h2 class="sm-card-title" id="sm-team-brief-title">Production brief</h2>
                                <p class="sm-card-caption">Boss, Designer, Developer and QA share the same budget and quality gates for Section and Full Page conversion.</p>
                            </div>
                            <div class="sm-card-body">
                                <div class="sm-field">
                                    <label class="sm-field-label" for="syndamorph-team-output-type">Output type</label><select class="sm-control" id="syndamorph-team-output-type"><option value="html">HTML / CSS</option><option value="gutenberg">Native Gutenberg</option></select></div><div class="sm-field"><label class="sm-field-label" for="syndamorph-team-brief">Task instructions</label>
                                    <textarea class="sm-control sm-team-brief" id="syndamorph-team-brief" rows="7" maxlength="2000">Create a responsive, premium hotel hero section with a welcoming headline, short introduction, and clear call-to-action. Use a self-contained layout without external assets.</textarea>
                                </div>
                                <details id="sm-ai-source" class="sm-field" hidden>
                                    <summary>Choose viewport variants manually</summary>
                                    <div class="sm-team-actions">
                                        <button type="button" class="sm-team-secondary" id="sm-ai-source-discover">Discover variants for the page URL</button>
                                    </div>
                                    <div id="sm-ai-source-variants" hidden>
                                        <label class="sm-field-label" for="sm-ai-source-1440">Desktop ? 1440 px</label>
                                        <select class="sm-control" id="sm-ai-source-1440"></select>
                                        <label class="sm-field-label" for="sm-ai-source-768">Tablet ? 768 px</label>
                                        <select class="sm-control" id="sm-ai-source-768"></select>
                                        <label class="sm-field-label" for="sm-ai-source-375">Mobile ? 375 px</label>
                                        <select class="sm-control" id="sm-ai-source-375"></select>
                                    </div>
                                    <p id="sm-ai-source-status" class="sm-hint" role="status" aria-live="polite"></p>
                                </details>
                                <div class="sm-team-actions">
                                    <button type="button" class="sm-primary" id="syndamorph-real-run">Start Production <span aria-hidden="true">→</span></button>
                                    <button type="button" class="sm-team-secondary" id="syndamorph-team-reset">Reset</button>
                                </div>
                                <p class="sm-hint" id="syndamorph-team-validation" role="status" aria-live="polite">To convert a URL with AI, select Native Gutenberg and inspect its source.</p>
                                <div id="syndamorph-team-scope-progress" hidden></div>
                            </div>
                        </section>
                    </div>
                </section>

                <section id="sm-team-panel-production" class="sm-team-panel" role="tabpanel" aria-labelledby="sm-team-tab-production" data-panel="production" hidden>
                    <div class="sm-team-layout sm-team-production-grid">
                        <section class="sm-card" aria-labelledby="sm-team-timeline-title">
                            <div class="sm-production-control" aria-label="Production control panel">
                                <div class="sm-production-control-top">
                                    <div class="sm-production-identity">
                                        <strong id="syndamorph-team-current-job">No job submitted</strong>
                                        <span id="syndamorph-team-active-agent">No active operation</span>
                                    </div>
                                    <span class="sm-status-pill" id="syndamorph-team-job-state" data-state="idle">Not started</span>
                                </div>
                                <div class="sm-production-metrics">
                                    <div><span class="sm-stat-label">Current operation</span>
                                        <strong id="syndamorph-team-operation">Standing by</strong></div>
                                    <div><span class="sm-stat-label">Estimated AI cost</span>
                                        <strong id="syndamorph-team-live-cost">$0.000000</strong></div>
                                </div>
                                <div class="sm-production-progress">
                                    <span id="syndamorph-team-handoffs">0 tasks completed</span>
                                    <span id="syndamorph-team-output-state">Not generated</span>
                                </div>
                                <div class="sm-production-actions" role="group" aria-label="Job execution controls">
                                    <button type="button" class="sm-team-secondary" id="syndamorph-team-pause" disabled>Pause</button>
                                    <button type="button" class="sm-team-secondary" id="syndamorph-team-resume" hidden disabled>Resume</button>
                                    <button type="button" class="sm-team-secondary" id="syndamorph-team-stop" disabled>Stop</button>
                                    <button type="button" class="sm-team-secondary sm-production-abort" id="syndamorph-team-abort" disabled>Abort</button>
                                </div>
                            </div>
                            <div class="sm-card-header sm-team-feed-heading">
                                <div><h2 class="sm-card-title" id="sm-team-timeline-title">Live Production Feed</h2>
                                <p class="sm-card-caption">Real agent messages and handoffs stream as they are generated.</p></div>
                                <span class="sm-status-pill" id="syndamorph-team-feed-state" data-state="idle">Idle</span>
                            </div>
                            <div class="sm-card-body">
                                <ol class="sm-team-timeline" id="syndamorph-team-timeline" aria-live="polite" aria-relevant="additions">
                                    <li class="sm-team-empty">No activity yet. Start Production from Setup.</li>
                                </ol>
                            </div>
                        </section>
                        <section class="sm-card" aria-labelledby="sm-team-roster-title">
                            <div class="sm-card-header"><h2 class="sm-card-title" id="sm-team-roster-title">Agent roster</h2><p class="sm-card-caption">Agent status follows real model generation and handoffs.</p></div>
                            <div class="sm-card-body">
                                <div class="sm-team-agent" data-agent="boss"><span class="sm-team-avatar">B</span><div><strong>PM / Boss Agent</strong><p>Classification, delegation and design approval</p></div><span class="sm-status-pill" data-state="idle">Standing by</span></div>
                                <div class="sm-team-agent" data-agent="designer"><span class="sm-team-avatar">D</span><div><strong>Designer Agent</strong><p>Design architecture and visual fidelity</p></div><span class="sm-status-pill" data-state="idle">Standing by</span></div>
                                <div class="sm-team-agent" data-agent="developer"><span class="sm-team-avatar">C</span><div><strong>Developer Agent</strong><p>Native Gutenberg blocks and targeted fixes</p></div><span class="sm-status-pill" data-state="idle">Standing by</span></div>
                                <div class="sm-team-agent" data-agent="validator"><span class="sm-team-avatar">Q</span><div><strong>QA Agent</strong><p>Independent implementation review</p></div><span class="sm-status-pill" data-state="idle">Standing by</span></div>
                                <div class="sm-team-note">Native Gutenberg includes isolated browser/vision QA and WordPress block validation; HTML-only production remains review-only. Nothing publishes automatically.</div>
                                <button type="button" class="sm-team-secondary sm-team-view-result" id="syndamorph-team-view-result" hidden>View Result</button>
                            </div>
                        </section>
                    </div>

                </section>

                <section id="sm-team-panel-results" class="sm-team-panel" role="tabpanel" aria-labelledby="sm-team-tab-results" data-panel="results" hidden>
                    <section class="sm-card" aria-labelledby="sm-team-review-title">
                        <div class="sm-card-header"><h2 class="sm-card-title" id="sm-team-review-title">Job results</h2>
                            <p class="sm-card-caption">Review AI artifacts and explicitly convert or save them. Manual conversion results remain supported.</p></div>
                        <div class="sm-card-body">
                            <h2 class="sm-team-result-title" id="syndamorph-team-result-title">No job submitted</h2>
                            <div class="sm-team-result" id="syndamorph-team-review" role="status" aria-live="polite">
                                <strong>No output available</strong>
                                <p>Start AI Production from Setup, open a historical job, or use manual conversion as a fallback.</p>
                            </div>
                            <div id="syndamorph-real-results" class="sm-team-ai-results" hidden aria-label="Real AI generation results"></div>
                            <section class="sm-team-artifacts" id="syndamorph-team-artifacts" hidden aria-label="Converted Gutenberg preview">
                                <div class="sm-team-preview-toolbar">
                                    <strong id="syndamorph-team-preview-heading">Gutenberg preview</strong>
                                    <div class="sm-team-preview-controls">
                                        <button type="button" class="sm-team-secondary" id="syndamorph-team-return-setup">Return to Setup</button>
                                        <button type="button" class="sm-team-secondary" id="syndamorph-team-reload-preview">Reload preview</button>
                                        <span class="sm-status-pill" id="syndamorph-team-preview-state" data-state="idle">Not reviewed</span>
                                    </div>
                                </div>
                                <nav class="sm-team-preview-navbar" id="syndamorph-team-preview-navbar" aria-label="Converted page previews" hidden>
                                    <div class="sm-team-preview-tab-window" id="syndamorph-team-preview-tab-window">
                                        <div class="sm-team-preview-tabs" id="syndamorph-team-preview-tabs" role="tablist" aria-label="Converted page previews"></div>
                                    </div>
                                    <div class="sm-team-preview-pager" id="syndamorph-team-preview-pager" hidden>
                                        <button type="button" class="sm-team-preview-arrow" id="syndamorph-team-preview-prev" aria-label="Previous preview" title="Previous preview">&#8249;</button>
                                        <button type="button" class="sm-team-preview-picker" id="syndamorph-team-preview-picker" aria-label="Choose preview" aria-haspopup="listbox" aria-expanded="false"><span id="syndamorph-team-preview-picker-label"></span><span aria-hidden="true">&#9662;</span></button>
                                        <button type="button" class="sm-team-preview-arrow" id="syndamorph-team-preview-next" aria-label="Next preview" title="Next preview">&#8250;</button>
                                        <div class="sm-team-preview-picker-menu" id="syndamorph-team-preview-picker-menu" role="listbox" aria-label="Jump to preview" hidden></div>
                                    </div>
                                </nav>
                                <div id="syndamorph-team-preview-warnings" class="sm-team-preview-warnings" role="status" aria-live="polite"></div>
                                <iframe id="syndamorph-team-preview-frame" title="Converted Gutenberg preview" sandbox="" referrerpolicy="no-referrer"></iframe>
                                <div class="sm-team-export-actions">
                                    <select id="syndamorph-team-output-kind" class="sm-control" aria-label="Save as"><option value="draft">Draft pages</option><option value="pattern">Unsynced patterns</option></select>
                                    <button type="button" class="sm-team-secondary" id="syndamorph-team-save" disabled>Save</button>
                                    <button type="button" class="sm-primary" id="syndamorph-team-save-open" disabled>Save &amp; Open Editor</button>
                                </div>
                                <p class="sm-hint" id="syndamorph-team-save-status" role="status" aria-live="polite">Review the converted preview before saving.</p>
                            </section>
                        </div>
                    </section>
                </section>
                <section id="sm-team-panel-history" class="sm-team-panel" role="tabpanel" aria-labelledby="sm-team-tab-history" data-panel="history" hidden>
                    <section class="sm-card">
                        <div class="sm-card-header"><h2 class="sm-card-title">Production History</h2><p class="sm-card-caption">Inspect and replay recorded jobs without new AI calls.</p></div>
                        <div class="sm-card-body">
                            <div class="sm-history-toolbar">
                                <input type="search" class="sm-control" id="sm-history-search" placeholder="Search job ID or title" aria-label="Search jobs">
                                <select class="sm-control" id="sm-history-category" aria-label="Job category"><option value="">All categories</option><option value="GEN">GEN · Without reference</option><option value="REF">REF · With reference</option></select>
                                <select class="sm-control" id="sm-history-sort" aria-label="Sort jobs"><option value="newest">Newest first</option><option value="oldest">Oldest first</option><option value="id">Job ID</option><option value="title">Job title</option><option value="status">Status</option></select>
                                <button type="button" class="sm-team-secondary" id="sm-history-refresh">Refresh</button>
                            </div>
                            <p id="sm-history-status" role="status" aria-live="polite"></p>
                            <div id="sm-history-list" role="region" aria-label="Archived production jobs" tabindex="0"></div>
                            <nav class="sm-history-pagination" aria-label="History pages">
                                <span id="sm-history-page-info" class="sm-history-page-info" aria-live="polite">Page 1 of 1</span>
                                <div class="sm-history-pagination-controls">
                                    <button type="button" class="sm-team-secondary" id="sm-history-prev" disabled>Previous</button>
                                    <div id="sm-history-page-numbers" class="sm-history-page-numbers" aria-label="Choose history page"></div>
                                    <button type="button" class="sm-team-secondary" id="sm-history-next" disabled>Next</button>
                                </div>
                            </nav>
                            <div id="sm-history-backdrop" hidden></div>
                            <aside id="sm-history-detail" role="dialog" aria-modal="true" aria-label="Archived job details" tabindex="-1" hidden></aside>
                            <section class="sm-card" aria-labelledby="sm-learning-title">
                                <div class="sm-card-header"><h3 id="sm-learning-title" class="sm-card-title">Adaptive Learning</h3><p class="sm-card-caption">Verified lessons are reused only for matching agent tasks and compiler versions.</p></div>
                                <div class="sm-card-body">
                                    <div class="sm-history-actions">
                                        <button type="button" class="sm-team-secondary" id="sm-learning-refresh">Refresh lessons</button>
                                        <button type="button" class="sm-team-secondary" id="sm-learning-bootstrap">Import verified historical corrections</button>
                                    </div>
                                    <p class="sm-hint" id="sm-learning-status" role="status" aria-live="polite"></p>
                                    <div id="sm-learning-counts"></div><div id="sm-learning-list"></div>
                                </div>
                            </section>
                        </div>
                    </section>
                </section>
            </main>
        </div>
    </div>
    <?php
}

add_action( 'admin_enqueue_scripts', static function ( string $hook ): void {
    if ( 'toplevel_page_syndamorph' !== $hook || ! current_user_can( 'manage_options' ) ) { return; }
    $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
    if ( 'ai' === $tab ) { return; }
    wp_enqueue_style( 'syndamorph-operations', plugin_dir_url( __DIR__ ) . 'assets/operations-dashboard.css', array(), (string) filemtime( plugin_dir_path( __DIR__ ) . 'assets/operations-dashboard.css' ) );
    if ( 'gateway' === $tab ) {
        wp_enqueue_style( 'syndamorph-gateway-ui', plugin_dir_url( __DIR__ ) . 'assets/gateway-admin.css', array( 'syndamorph-operations' ), (string) filemtime( plugin_dir_path( __DIR__ ) . 'assets/gateway-admin.css' ) );
        return;
    }
    wp_enqueue_style( 'syndamorph-admin', plugin_dir_url( __DIR__ ) . 'assets/admin.css', array(), (string) filemtime( plugin_dir_path( __DIR__ ) . 'assets/admin.css' ) );
    wp_enqueue_script( 'syndamorph-pattern-dialog', plugin_dir_url( __DIR__ ) . 'assets/pattern-dialog.js', array(), (string) filemtime( plugin_dir_path( __DIR__ ) . 'assets/pattern-dialog.js' ), true );
    wp_enqueue_script( 'syndamorph-import', plugin_dir_url( __DIR__ ) . 'assets/import.js', array( 'syndamorph-pattern-dialog' ), (string) filemtime( plugin_dir_path( __DIR__ ) . 'assets/import.js' ), true );
    $preview_font_family = wp_get_global_styles( array( 'typography', 'fontFamily' ), array( 'transforms' => array( 'resolve-variables' ) ) );
    wp_add_inline_script( 'syndamorph-import', 'window.SyndaMorphImport=' . wp_json_encode( array(
        'nonce' => wp_create_nonce( 'wp_rest' ),
        'editUrl' => admin_url( 'post.php' ),
        'restUrl' => rest_url( 'wp/v2/pages' ),
        'placeholderUrl' => plugin_dir_url( __DIR__ ) . 'assets/missing-image.svg',
        'previewFontFamily' => is_string( $preview_font_family ) ? $preview_font_family : '',
        'patternUrl' => rest_url( 'syndamorph/v1/patterns' ),
        'renderUrl' => rest_url( 'syndamorph/v1/render-dom' ),
        'rendererAvailable' => syndamorph_internal_configured( 'renderer' ),
    ) ) . ';', 'before' );
    wp_enqueue_script( 'syndamorph-team', plugin_dir_url( __DIR__ ) . 'assets/production-team.js', array( 'syndamorph-import' ), (string) filemtime( plugin_dir_path( __DIR__ ) . 'assets/production-team.js' ), true );
    wp_add_inline_script( 'syndamorph-team', 'window.SyndaMorphTeam=' . wp_json_encode( array(
        'nonce' => wp_create_nonce( 'wp_rest' ),
        'captureUrl' => rest_url( 'syndamorph/v1/capture-url' ),
        'productionSourceUrl' => rest_url( 'syndamorph/v1/production-source' ),
        'scopeUrl' => rest_url( 'syndamorph/v1/production-scope' ),
        'scopeBudgetUsd' => (float)(syndamorph_models_settings()['maxJobUsd']??0),
        'historyUrl' => rest_url( 'syndamorph/v1/history' ),
        'packageScanUrl' => rest_url( 'syndamorph/v1/package-scan' ),
        'packagePageUrl' => rest_url( 'syndamorph/v1/package-page' ),
        'packageCommitUrl' => rest_url( 'syndamorph/v1/package-commit' ),
    ) ) . ';', 'before' );
    wp_enqueue_script( 'syndamorph-rich-content', plugin_dir_url( __DIR__ ) . 'assets/production-rich-content.js', array(), (string) filemtime( plugin_dir_path( __DIR__ ) . 'assets/production-rich-content.js' ), true );
    wp_enqueue_script( 'wp-blocks' );
    wp_enqueue_script( 'wp-block-library' );
    wp_enqueue_script( 'syndamorph-ai-export', plugin_dir_url( __DIR__ ) . 'assets/production-ai-export.js', array( 'syndamorph-import', 'wp-blocks', 'wp-block-library', 'syndamorph-tabs-editor', 'syndamorph-header-editor', 'syndamorph-offer-editor', 'syndamorph-booking-editor', 'syndamorph-newsletter-editor' ), (string) filemtime( plugin_dir_path( __DIR__ ) . 'assets/production-ai-export.js' ), true );
    wp_enqueue_script( 'syndamorph-production-scope', plugin_dir_url( __DIR__ ) . 'assets/production-scope.js', array( 'syndamorph-team', 'syndamorph-ai-export' ), (string) filemtime( plugin_dir_path( __DIR__ ) . 'assets/production-scope.js' ), true );
    wp_enqueue_script( 'syndamorph-real-team', plugin_dir_url( __DIR__ ) . 'assets/ai-team-real.js', array( 'syndamorph-team', 'syndamorph-rich-content', 'syndamorph-ai-export', 'syndamorph-production-scope' ), (string) filemtime( plugin_dir_path( __DIR__ ) . 'assets/ai-team-real.js' ), true );
    wp_add_inline_script( 'syndamorph-real-team', 'window.SyndaMorphRealTeam=' . wp_json_encode( array(
        'nonce' => wp_create_nonce( 'wp_rest' ),
        'url' => rest_url( 'syndamorph/v1/ai/team-stream' ),
    ) ) . ';', 'before' );
    wp_enqueue_script( 'syndamorph-learning', plugin_dir_url( __DIR__ ) . 'assets/production-learning.js', array( 'syndamorph-team' ), (string) filemtime( plugin_dir_path( __DIR__ ) . 'assets/production-learning.js' ), true );
    wp_enqueue_script( 'syndamorph-history', plugin_dir_url( __DIR__ ) . 'assets/production-history.js', array( 'syndamorph-real-team' ), (string) filemtime( plugin_dir_path( __DIR__ ) . 'assets/production-history.js' ), true );
    wp_enqueue_script( 'syndamorph-team-migration', plugin_dir_url( __DIR__ ) . 'assets/production-workflow.js',
        array( 'syndamorph-team', 'syndamorph-import', 'syndamorph-production-scope' ),
        (string) filemtime( plugin_dir_path( __DIR__ ) . 'assets/production-workflow.js' ), true );
} );
