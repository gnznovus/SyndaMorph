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

                <div class="sm-stats" aria-label="Simulation overview">
                    <div class="sm-stat">
                        <span class="sm-stat-label">Current job</span>
                        <span class="sm-stat-value sm-stat-small" id="syndamorph-team-job-state">Not started</span>
                    </div>
                    <div class="sm-stat">
                        <span class="sm-stat-label">Team handoffs</span>
                        <span class="sm-stat-value" id="syndamorph-team-handoffs">00 / 04</span>
                    </div>
                    <div class="sm-stat">
                        <span class="sm-stat-label">Output readiness</span>
                        <span class="sm-stat-value sm-stat-small" id="syndamorph-team-output-state">Not generated</span>
                    </div>
                </div>

                <div class="sm-team-layout">
                    <section class="sm-card" aria-labelledby="sm-team-job-title">
                        <div class="sm-card-header">
                            <h2 class="sm-card-title" id="sm-team-job-title">Job briefing</h2>
                            <p class="sm-card-caption">This simulation does not submit a production job or fetch source content.</p>
                        </div>
                        <div class="sm-card-body">
                            <div class="sm-field">
                                <label class="sm-field-label" for="syndamorph-team-job">Job title</label>
                                <input class="sm-control" type="text" id="syndamorph-team-job" maxlength="200" value="Hotel Indigo landing page" autocomplete="off">
                            </div>
                            <div class="sm-field">
                                <label class="sm-field-label" for="syndamorph-team-source">Source reference (optional)</label>
                                <input class="sm-control" type="url" id="syndamorph-team-source" placeholder="https://example.com/wireframe" maxlength="2048" autocomplete="off">
                                <p class="sm-hint">Reference only. No capture or external access in simulation mode.</p>
                            </div>
                            <div class="sm-field">
                                <label class="sm-field-label" for="syndamorph-team-brief">Production brief</label>
                                <textarea class="sm-control sm-team-brief" id="syndamorph-team-brief" rows="4" maxlength="1200">Demonstrate how the Boss, Designer, and Developer coordinate a Gutenberg production job.</textarea>
                            </div>
                            <div class="sm-team-actions">
                                <button type="button" class="sm-primary" id="syndamorph-team-run">Run simulation</button>
                                <button type="button" class="sm-team-secondary" id="syndamorph-team-reset">Reset</button>
                            </div>
                            <p class="sm-hint" id="syndamorph-team-validation" role="status" aria-live="polite">Local simulation only · no AI tokens consumed.</p>
                        </div>
                    </section>
                    <section class="sm-card" aria-labelledby="sm-team-roster-title">
                        <div class="sm-card-header">
                            <h2 class="sm-card-title" id="sm-team-roster-title">Agent roster</h2>
                            <p class="sm-card-caption">Defined roles; simulated status indicators.</p>
                        </div>
                        <div class="sm-card-body">
                            <div class="sm-team-agent" data-agent="boss"><span class="sm-team-avatar">B</span><div><strong>Boss Agent</strong><p>Classification, delegation, approval</p></div><span class="sm-status-pill" data-state="idle">Standing by</span></div>
                            <div class="sm-team-agent" data-agent="designer"><span class="sm-team-avatar">D</span><div><strong>Designer Agent</strong><p>Design planning and section structure</p></div><span class="sm-status-pill" data-state="idle">Standing by</span></div>
                            <div class="sm-team-agent" data-agent="developer"><span class="sm-team-avatar">D</span><div><strong>Developer Agent</strong><p>Block and interaction implementation</p></div><span class="sm-status-pill" data-state="idle">Standing by</span></div>
                            <div class="sm-team-note">Freelancer allocation, real model calls, and automated repair are reserved for a later phase.</div>
                        </div>
                    </section>
                </div>

                <div class="sm-team-layout sm-team-bottom">
                    <section class="sm-card" aria-labelledby="sm-team-timeline-title">
                        <div class="sm-card-header">
                            <h2 class="sm-card-title" id="sm-team-timeline-title">Production timeline</h2>
                            <p class="sm-card-caption">Deterministic stages, shown in order. No fabricated AI responses.</p>
                        </div>
                        <div class="sm-card-body">
                            <ol class="sm-team-timeline" id="syndamorph-team-timeline" aria-live="polite">
                                <li class="sm-team-empty">No activity yet. Run a simulation to view agent handoffs.</li>
                            </ol>
                        </div>
                    </section>
                    <section class="sm-card" aria-labelledby="sm-team-review-title">
                        <div class="sm-card-header">
                            <h2 class="sm-card-title" id="sm-team-review-title">Result review</h2>
                            <p class="sm-card-caption">Distinguishes workflow completion from deliverable readiness.</p>
                        </div>
                        <div class="sm-card-body">
                            <div class="sm-team-result" id="syndamorph-team-review" role="status" aria-live="polite">
                                <strong>No output available</strong>
                                <p>Complete a simulation to review its handoff summary. Gutenberg patterns, HTML, and custom blocks are not generated by this mode.</p>
                            </div>
                            <a class="sm-team-link" href="<?php echo esc_url( admin_url( 'tools.php?page=syndamorph' ) ); ?>">Open Source Importer <span aria-hidden="true">→</span></a>
                        </div>
                    </section>
                </div>

                <section class="sm-card sm-team-purr" aria-labelledby="sm-team-purr-title">
                    <div class="sm-card-header">
                        <h2 class="sm-card-title" id="sm-team-purr-title">Purr Gateway — Online Communication POC</h2>
                        <p class="sm-card-caption">Optional live integration check: requests reach Railway and receive four replies. Purr's deterministic product-guidance backend does not produce role-specific agent reasoning.</p>
                    </div>
                    <div class="sm-card-body">
                        <div class="sm-team-purr-toolbar">
                            <span id="syndamorph-purr-status" class="sm-status-pill" data-state="idle" role="status" aria-live="polite">Not tested</span>
                            <button type="button" id="syndamorph-purr-run" class="sm-primary">Test Purr communication</button>
                        </div>
                        <div id="syndamorph-purr-transcript" class="sm-purr-transcript" aria-live="polite">
                            <p class="sm-hint">No Gateway requests sent yet. Run this separate online test to verify transport and the predefined four-step sequence.</p>
                        </div>
                    </div>
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
