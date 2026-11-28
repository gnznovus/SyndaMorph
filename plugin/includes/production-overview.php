<?php
/** Read-only SyndaMorph Operations overview, backed by recorded History jobs. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function syndamorph_overview_render_tab(): void {
    if ( ! current_user_can( 'manage_options' ) ) { return; }
    ?>
    <div class="wrap" id="syndamorph-overview">
        <div class="sm-dashboard">
            <aside class="sm-sidebar" aria-label="Operations navigation">
                <div class="sm-brand">
                    <span class="sm-brand-mark" aria-hidden="true">S</span>
                    <div><div class="sm-brand-name">SyndaMorph</div><div class="sm-brand-caption">Operations</div></div>
                </div>
                <div>
                    <p class="sm-nav-label">Workspace</p>
                    <?php syndamorph_operations_navigation( 'overview' ); ?>
                </div>
                <div class="sm-sidebar-footer"><strong>Internal MVP</strong><br>Read-only operational reporting from saved production jobs. No AI calls are made by this dashboard.</div>
            </aside>
            <main class="sm-main">
                <div class="sm-topline">
                    <span class="sm-breadcrumb">Workspace / <strong>Overview</strong></span>
                    <span class="sm-environment">MVP · DEVELOPMENT</span>
                </div>
                <div class="sm-overview-heading">
                    <div>
                        <p class="sm-kicker">Operations workspace</p>
                        <h1 class="sm-page-title">Operations Overview</h1>
                        <p class="sm-page-description">Manage production workflows, agent performance, and model operations.</p>
                    </div>
                </div>
                <div class="sm-overview-headline">
                    <h2>Operations Overview</h2>
                    <div class="sm-overview-filters" role="group" aria-label="Reporting period">
                        <button type="button" data-range="today" aria-pressed="true" class="is-active">Today</button>
                        <button type="button" data-range="7d" aria-pressed="false">7 days</button>
                        <button type="button" data-range="30d" aria-pressed="false">30 days</button>
                        <button type="button" data-range="all" aria-pressed="false">All time</button>
                    </div>
                </div>
                <p class="sm-overview-status" id="sm-overview-status" role="status" aria-live="polite">Loading recorded activity…</p>
                <div class="sm-overview-metrics" aria-label="Production summary">
                    <div class="sm-card sm-overview-metric"><div class="sm-overview-metric-label">Total Jobs <span aria-hidden="true">▤</span></div><strong class="sm-overview-metric-value" data-overview-stat="total">—</strong><div class="sm-overview-metric-foot">Recorded production jobs</div></div>
                    <div class="sm-card sm-overview-metric"><div class="sm-overview-metric-label">Completed <span aria-hidden="true">✓</span></div><strong class="sm-overview-metric-value" data-overview-stat="completed">—</strong><div class="sm-overview-metric-foot"><span class="sm-overview-positive" data-overview-stat="rate">—</span> completion rate</div></div>
                    <div class="sm-card sm-overview-metric"><div class="sm-overview-metric-label">Pending / Blocked <span aria-hidden="true">◷</span></div><strong class="sm-overview-metric-value" data-overview-stat="attention">—</strong><div class="sm-overview-metric-foot">Requires verification or repair</div></div>
                    <div class="sm-card sm-overview-metric"><div class="sm-overview-metric-label">AI Usage Cost <span aria-hidden="true">$</span></div><strong class="sm-overview-metric-value" data-overview-stat="cost">—</strong><div class="sm-overview-metric-foot">Awaiting verified usage aggregation</div></div>
                </div>
                <div class="sm-overview-main-grid">
                    <section class="sm-card" aria-labelledby="sm-overview-pipeline-title">
                        <div class="sm-overview-card-header">
                            <div><h3 id="sm-overview-pipeline-title">Production pipeline</h3><small>Work in progress by stage</small></div>
                            <span class="sm-overview-chip">Stage receipts pending</span>
                        </div>
                        <div class="sm-overview-stage-grid">
                            <div class="sm-overview-stage"><span>Bossy</span><strong data-overview-stage="boss">—</strong><small>Classification</small></div>
                            <div class="sm-overview-stage"><span>Designer</span><strong data-overview-stage="designer">—</strong><small>HTML + surgery</small></div>
                            <div class="sm-overview-stage"><span>Review</span><strong data-overview-stage="review">—</strong><small>Bossy validation</small></div>
                            <div class="sm-overview-stage"><span>Developer</span><strong data-overview-stage="developer">—</strong><small>Native blocks</small></div>
                            <div class="sm-overview-stage"><span>QA</span><strong data-overview-stage="qa">—</strong><small>Independent proof</small></div>
                        </div>
                        <p class="sm-overview-pipeline-note">Stage counters require verified, currently active checkpoint receipts. No stages are inferred from historical job status.</p>
                        <div class="sm-overview-progress-label"><span>Recorded completion rate</span><strong data-overview-stat="acceptance">—</strong></div>
                        <div class="sm-overview-progress-track"><div class="sm-overview-progress-fill" data-overview-acceptance-bar></div></div>
                        <div class="sm-overview-legend"><span><i class="accepted"></i>Accepted</span><span><i class="pending"></i>Pending</span><span><i class="blocked"></i>Blocked</span></div>
                    </section>
                    <section class="sm-card" aria-labelledby="sm-overview-recent-title">
                        <div class="sm-overview-card-header"><div><h3 id="sm-overview-recent-title">Recent production activity</h3><small>Recorded jobs and checkpoints</small></div></div>
                        <div class="sm-overview-job-list" id="sm-overview-jobs"></div>
                        <a class="sm-overview-link sm-overview-recent-link" href="<?php echo esc_url( admin_url( 'admin.php?page=syndamorph&tab=production&view=history' ) ); ?>">Open Production History</a>
                    </section>
                </div>
                <div class="sm-overview-bottom-grid">
                    <section class="sm-card" aria-labelledby="sm-overview-agents-title">
                        <div class="sm-overview-card-header"><div><h3 id="sm-overview-agents-title">Agent workload</h3><small>Share of verified model activity</small></div></div>
                        <div class="sm-overview-bars">
                            <?php foreach ( array( 'Bossy', 'Designer', 'Developer', 'QA' ) as $role ) : ?>
                                <div class="sm-overview-bar-row"><span><?php echo esc_html( $role ); ?></span><div class="sm-overview-bar-track"><div class="sm-overview-bar-fill"></div></div><strong>—</strong></div>
                            <?php endforeach; ?>
                        </div>
                        <p class="sm-overview-evidence-note">Awaiting per-agent usage receipts.</p>
                    </section>
                    <section class="sm-card" aria-labelledby="sm-overview-quality-title">
                        <div class="sm-overview-card-header"><div><h3 id="sm-overview-quality-title">Quality &amp; recovery</h3><small>Verified acceptance metrics</small></div></div>
                        <div class="sm-overview-qa-grid">
                            <div class="sm-overview-qa-item"><strong>—</strong><small>Functional QA pass rate</small></div>
                            <div class="sm-overview-qa-item"><strong>—</strong><small>Visual QA pass rate</small></div>
                            <div class="sm-overview-qa-item"><strong>—</strong><small>Responsive QA pass rate</small></div>
                            <div class="sm-overview-qa-item"><strong>—</strong><small>Surgical recovery success</small></div>
                        </div>
                        <p class="sm-overview-evidence-note">Awaiting independently verified QA and repair aggregations.</p>
                    </section>
                </div>
                <p class="sm-overview-footnote">Operational values use the archived History source of truth. Stage, agent, and quality metrics appear only when supporting receipts are available; no demo data is presented as live.</p>
            </main>
        </div>
    </div>
    <?php
}

add_action( 'admin_enqueue_scripts', static function ( string $hook ): void {
    if ( 'toplevel_page_syndamorph' !== $hook || ! current_user_can( 'manage_options' ) ) { return; }
    $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
    if ( in_array( $tab, array( 'production', 'team', 'ai', 'gateway' ), true ) ) { return; }
    $base = plugin_dir_path( __DIR__ ) . 'assets/';
    $url = plugin_dir_url( __DIR__ ) . 'assets/';
    wp_enqueue_style( 'syndamorph-operations', $url . 'operations-dashboard.css', array(), (string) filemtime( $base . 'operations-dashboard.css' ) );
    wp_enqueue_script( 'syndamorph-overview', $url . 'production-overview.js', array(), (string) filemtime( $base . 'production-overview.js' ), true );
    wp_add_inline_script( 'syndamorph-overview', 'window.SyndaMorphOverview=' . wp_json_encode( array(
        'url' => rest_url( 'syndamorph/v1/overview' ),
        'nonce' => wp_create_nonce( 'wp_rest' ),
    ) ) . ';', 'before' );
} );

add_action( 'rest_api_init', static function (): void {
    register_rest_route( 'syndamorph/v1', '/overview', array(
        'methods' => 'GET',
        'permission_callback' => static function (): bool { return current_user_can( 'manage_options' ); },
        'callback' => static function ( WP_REST_Request $request ) {
            global $wpdb;
            if ( ! syndamorph_history_install() ) {
                return new WP_Error( 'sm_overview_unavailable', 'History archive unavailable.', array( 'status' => 503 ) );
            }
            list( $jobs ) = syndamorph_history_tables();
            $period = (string) $request->get_param( 'period' );
            if ( ! in_array( $period, array( 'today', '7d', '30d', 'all' ), true ) ) { $period = '7d'; }
            $where = '1=1';
            if ( 'all' !== $period ) {
                $now = new DateTimeImmutable( 'now', wp_timezone() );
                if ( 'today' === $period ) { $since = $now->setTime( 0, 0 ); }
                else { $since = $now->modify( '7d' === $period ? '-7 days' : '-30 days' ); }
                $utc = $since->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
                $where .= $wpdb->prepare( ' AND created_at >= %s', $utc );
            }
            $states = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM $jobs WHERE $where GROUP BY status", ARRAY_A );
            $categories = $wpdb->get_results( "SELECT category, COUNT(*) AS total FROM $jobs WHERE $where GROUP BY category", ARRAY_A );
            $recent = $wpdb->get_results( "SELECT id,category,sequence_no,title,status,created_at,updated_at FROM $jobs WHERE $where ORDER BY id DESC LIMIT 8", ARRAY_A );
            if ( ! is_array( $states ) || ! is_array( $categories ) || ! is_array( $recent ) ) {
                return new WP_Error( 'sm_overview_read', 'Could not read production summary.', array( 'status' => 503 ) );
            }
            $statusCounts = array();
            foreach ( $states as $row ) { $statusCounts[ (string) $row['status'] ] = (int) $row['total']; }
            $sourceCounts = array( 'REF' => 0, 'GEN' => 0 );
            foreach ( $categories as $row ) {
                if ( isset( $sourceCounts[ $row['category'] ] ) ) { $sourceCounts[ $row['category'] ] = (int) $row['total']; }
            }
            $total = array_sum( $statusCounts );
            $completed = $statusCounts['completed'] ?? 0;
            $attention = 0;
            foreach ( array( 'failed', 'needs_review', 'uncompleted', 'stopped', 'aborted', 'paused' ) as $state ) {
                $attention += $statusCounts[$state] ?? 0;
            }
            return rest_ensure_response( array(
                'period' => $period,
                'total' => $total,
                'completed' => $completed,
                'attention' => $attention,
                'completionRate' => $total ? round( 100 * $completed / $total, 1 ) : null,
                'statuses' => $statusCounts,
                'categories' => $sourceCounts,
                'recent' => array_map( 'syndamorph_history_summary', $recent ),
            ) );
        },
    ) );
} );
