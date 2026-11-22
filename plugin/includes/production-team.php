<?php
/**
 * AI Production Team — UI-first job simulation workspace.
 * No production execution or Gutenberg artifact generation occurs on this tab.
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
                        <a class="sm-nav-item" href="<?php echo esc_url( admin_url( 'tools.php?page=syndamorph' ) ); ?>">
                            <span class="dashicons dashicons-upload" aria-hidden="true"></span> Source Importer
                        </a>
                        <a class="sm-nav-item is-active" href="<?php echo esc_url( admin_url( 'tools.php?page=syndamorph&tab=team' ) ); ?>" aria-current="page">
                            <span class="dashicons dashicons-groups" aria-hidden="true"></span> AI Production Team
                        </a>
                        <a class="sm-nav-item" href="<?php echo esc_url( admin_url( 'tools.php?page=syndamorph&tab=ai' ) ); ?>">
                            <span class="dashicons dashicons-admin-network" aria-hidden="true"></span> AI Integration
                        </a>
                    </nav>
                </div>
                <div class="sm-sidebar-footer">
                    <strong>UI-first MVP</strong><br>
                    Demonstration-only jobs. Production orchestration and artifact generation are not connected yet.
                </div>
            </aside>
            <main class="sm-main">
                <div class="sm-topline">
                    <span class="sm-breadcrumb">Workspace / <strong>AI Production Team</strong></span>
                    <span class="sm-environment">MVP · SIMULATION</span>
                </div>
                <p class="sm-kicker">Production workspace</p>
                <h1 class="sm-page-title">AI Production Team</h1>
                <p class="sm-page-description">Prepare a job, simulate agent handoffs, and inspect the production timeline. This workspace is UI-only until the execution pipeline is connected.</p>

                <nav class="sm-team-tabs" aria-label="Production workflow stages" role="tablist">
                    <button type="button" id="sm-team-tab-setup" role="tab" aria-controls="sm-team-panel-setup" aria-selected="true" tabindex="0" data-panel="setup">01 <strong>Setup</strong><span>Prepare job</span></button>
                    <button type="button" id="sm-team-tab-production" role="tab" aria-controls="sm-team-panel-production" aria-selected="false" tabindex="-1" data-panel="production">02 <strong>Production</strong><span>Live activity</span></button>
                    <button type="button" id="sm-team-tab-results" role="tab" aria-controls="sm-team-panel-results" aria-selected="false" tabindex="-1" data-panel="results">03 <strong>Results</strong><span>Review output</span></button>
                </nav>

                <section id="sm-team-panel-setup" class="sm-team-panel" role="tabpanel" aria-labelledby="sm-team-tab-setup" data-panel="setup">
                    <div class="sm-team-layout sm-team-setup-grid">
                        <section class="sm-card" aria-labelledby="sm-team-source-title">
                            <div class="sm-card-header">
                                <h2 class="sm-card-title" id="sm-team-source-title">Source import</h2>
                                <p class="sm-card-caption">Choose what the team will work with. Source processing is not connected in simulation mode.</p>
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
                                    <p class="sm-hint">Reference only. The simulation will not fetch this URL.</p>
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
                                    <p class="sm-hint">Smaller files are shown in the editor; larger files remain selected without rendering them in the textarea. No upload or conversion occurs in simulation mode.</p>
                                </div>
                                <div class="sm-field sm-team-source-input" data-source="package" hidden>
                                    <label class="sm-field-label" for="syndamorph-team-package">ZIP package</label>
                                    <div class="sm-team-upload" data-drop-kind="package" tabindex="0" aria-label="ZIP upload drop zone. Drop a ZIP file, or press Enter to browse.">
                                        <input type="file" id="syndamorph-team-package" class="sm-team-native-file" accept=".zip,application/zip" aria-describedby="syndamorph-team-package-name">
                                        <label class="sm-team-file-button" for="syndamorph-team-package">Choose ZIP package</label>
                                        <span class="sm-team-file-name" id="syndamorph-team-package-name" aria-live="polite">Drop ZIP here or browse</span>
                                    </div>
                                    <p class="sm-team-upload-feedback" data-upload-feedback="package" role="status" aria-live="polite"></p>
                                    <p class="sm-hint">Selected filename only; the package is not uploaded in simulation mode.</p>
                                </div>
                                <div class="sm-team-note">In the final pipeline, the PM will assign the job title during classification. There is intentionally no manual title field or separate title-generation call.</div>
                            </div>
                        </section>
                        <section class="sm-card" aria-labelledby="sm-team-brief-title">
                            <div class="sm-card-header">
                                <h2 class="sm-card-title" id="sm-team-brief-title">Production brief</h2>
                                <p class="sm-card-caption">Describe the outcome; the PM owns task classification and delegation.</p>
                            </div>
                            <div class="sm-card-body">
                                <div class="sm-field">
                                    <label class="sm-field-label" for="syndamorph-team-brief">Task instructions</label>
                                    <textarea class="sm-control sm-team-brief" id="syndamorph-team-brief" rows="7" maxlength="1200">Convert the supplied source into independently editable Gutenberg sections, preserving layout fidelity.</textarea>
                                </div>
                                <div class="sm-team-actions">
                                    <button type="button" class="sm-primary" id="syndamorph-team-run">Start simulation <span aria-hidden="true">→</span></button>
                                    <button type="button" class="sm-team-secondary" id="syndamorph-team-reset">Reset</button>
                                </div>
                                <p class="sm-hint" id="syndamorph-team-validation" role="status" aria-live="polite">UI simulation only · no production job, source capture, or AI inference.</p>
                            </div>
                        </section>
                    </div>
                </section>

                <section id="sm-team-panel-production" class="sm-team-panel" role="tabpanel" aria-labelledby="sm-team-tab-production" data-panel="production" hidden>
                    <div class="sm-stats" aria-label="Simulation overview">
                        <div class="sm-stat"><span class="sm-stat-label">Current job</span><span class="sm-stat-value sm-stat-small" id="syndamorph-team-job-state">Not started</span></div>
                        <div class="sm-stat"><span class="sm-stat-label">Team handoffs</span><span class="sm-stat-value" id="syndamorph-team-handoffs">00 / 04</span></div>
                        <div class="sm-stat"><span class="sm-stat-label">Output readiness</span><span class="sm-stat-value sm-stat-small" id="syndamorph-team-output-state">Not generated</span></div>
                    </div>
                    <div class="sm-team-layout sm-team-production-grid">
                        <section class="sm-card" aria-labelledby="sm-team-timeline-title">
                            <div class="sm-card-header sm-team-feed-heading">
                                <div><h2 class="sm-card-title" id="sm-team-timeline-title">Live Production Feed</h2>
                                <p class="sm-card-caption">Simulated streaming decisions, actions and handoffs. Real agent reasoning and execution are not connected.</p></div>
                                <span class="sm-status-pill" id="syndamorph-team-feed-state" data-state="idle">Idle</span>
                            </div>
                            <div class="sm-card-body">
                                <p class="sm-team-job-header" id="syndamorph-team-current-job">No job submitted</p>
                                <ol class="sm-team-timeline" id="syndamorph-team-timeline" aria-live="polite" aria-relevant="additions">
                                    <li class="sm-team-empty">No activity yet. Start a simulation from Setup.</li>
                                </ol>
                            </div>
                        </section>
                        <section class="sm-card" aria-labelledby="sm-team-roster-title">
                            <div class="sm-card-header"><h2 class="sm-card-title" id="sm-team-roster-title">Agent roster</h2><p class="sm-card-caption">Assigned roles and simulated activity.</p></div>
                            <div class="sm-card-body">
                                <div class="sm-team-agent" data-agent="boss"><span class="sm-team-avatar">B</span><div><strong>PM / Boss Agent</strong><p>Job classification, delegation and approval</p></div><span class="sm-status-pill" data-state="idle">Standing by</span></div>
                                <div class="sm-team-agent" data-agent="designer"><span class="sm-team-avatar">D</span><div><strong>Designer Agent</strong><p>Design architecture and visual fidelity</p></div><span class="sm-status-pill" data-state="idle">Standing by</span></div>
                                <div class="sm-team-agent" data-agent="developer"><span class="sm-team-avatar">C</span><div><strong>Developer Agent</strong><p>Gutenberg blocks and interactions</p></div><span class="sm-status-pill" data-state="idle">Standing by</span></div>
                                <div class="sm-team-note">Actual disagreements, corrections and evidence will appear here after real orchestration is connected. The demo does not invent validation outcomes.</div>
                            </div>
                        </section>
                    </div>
                    <section class="sm-card sm-team-purr" aria-labelledby="sm-team-purr-title">
                        <div class="sm-card-header">
                            <h2 class="sm-card-title" id="sm-team-purr-title">Purr Gateway — Online Communication POC</h2>
                            <p class="sm-card-caption">Optional real Railway test: four deterministic Gateway replies. Separate from the local streaming simulation.</p>
                        </div>
                        <div class="sm-card-body">
                            <div class="sm-team-purr-toolbar">
                                <span id="syndamorph-purr-status" class="sm-status-pill" data-state="idle" role="status" aria-live="polite">Not tested</span>
                                <button type="button" id="syndamorph-purr-run" class="sm-primary">Test Purr communication</button>
                            </div>
                            <div id="syndamorph-purr-transcript" class="sm-purr-transcript" aria-live="polite">
                                <p class="sm-hint">No Gateway requests sent yet.</p>
                            </div>
                        </div>
                    </section>
                </section>

                <section id="sm-team-panel-results" class="sm-team-panel" role="tabpanel" aria-labelledby="sm-team-tab-results" data-panel="results" hidden>
                    <section class="sm-card" aria-labelledby="sm-team-review-title">
                        <div class="sm-card-header"><h2 class="sm-card-title" id="sm-team-review-title">Job results</h2>
                            <p class="sm-card-caption">Job identity and outcome. A completed conversation is not a generated Gutenberg artifact.</p></div>
                        <div class="sm-card-body">
                            <h2 class="sm-team-result-title" id="syndamorph-team-result-title">No job submitted</h2>
                            <div class="sm-team-result" id="syndamorph-team-review" role="status" aria-live="polite">
                                <strong>No output available</strong>
                                <p>Start a simulation from Setup. In the real pipeline, this page will contain generated sections, evidence and review controls.</p>
                            </div>
                            <a class="sm-team-link" href="<?php echo esc_url( admin_url( 'tools.php?page=syndamorph' ) ); ?>">Open manual Source Importer <span aria-hidden="true">→</span></a>
                        </div>
                    </section>
                </section>
            </main>
        </div>
    </div>
    <?php
}

add_action( 'admin_enqueue_scripts', static function ( string $hook ): void {
    if ( 'tools_page_syndamorph' !== $hook || ! current_user_can( 'manage_options' ) ||
         ! isset( $_GET['tab'] ) || 'team' !== sanitize_key( wp_unslash( $_GET['tab'] ) ) ) { return; }

    wp_enqueue_style( 'syndamorph-operations', plugin_dir_url( __DIR__ ) . 'assets/operations-dashboard.css', array(), (string) filemtime( plugin_dir_path( __DIR__ ) . 'assets/operations-dashboard.css' ) );
    wp_enqueue_script( 'syndamorph-team', plugin_dir_url( __DIR__ ) . 'assets/production-team.js', array(), (string) filemtime( plugin_dir_path( __DIR__ ) . 'assets/production-team.js' ), true );
    wp_add_inline_script( 'syndamorph-team', 'window.SyndaMorphTeam=' . wp_json_encode( array(
        'nonce' => wp_create_nonce( 'wp_rest' ),
        'purrPocUrl' => rest_url( 'syndamorph/v1/purr/communication-poc' ),
    ) ) . ';', 'before' );
} );
