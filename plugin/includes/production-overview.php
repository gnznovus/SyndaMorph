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
                    <nav class="sm-nav" aria-label="SyndaMorph modules">
                        <a class="sm-nav-item is-active" aria-current="page" href="<?php echo esc_url( admin_url( 'admin.php?page=syndamorph' ) ); ?>"><span class="dashicons dashicons-dashboard" aria-hidden="true"></span> Overview</a>
                        <a class="sm-nav-item" href="<?php echo esc_url( admin_url( 'admin.php?page=syndamorph&tab=production' ) ); ?>"><span class="dashicons dashicons-groups" aria-hidden="true"></span> AI Production Team</a>
                        <a class="sm-nav-item" href="<?php echo esc_url( admin_url( 'admin.php?page=syndamorph&tab=ai' ) ); ?>"><span class="dashicons dashicons-admin-network" aria-hidden="true"></span> AI Integration</a>
                        <a class="sm-nav-item" href="<?php echo esc_url( admin_url( 'admin.php?page=syndamorph&tab=gateway' ) ); ?>"><span class="dashicons dashicons-shield" aria-hidden="true"></span> Client Access Manager</a>
                    </nav>
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
                        <h1 class="sm-page-title">Overview</h1>
                        <p class="sm-page-description">Production activity and outcomes from the local job archive.</p>
                    </div>
                    <div class="sm-overview-filters">
                        <label for="sm-overview-period">Reporting period</label>
                        <select id="sm-overview-period" class="sm-control">
                            <option value="today">Today</option>
                            <option value="7d" selected>Last 7 days</option>
                            <option value="30d">Last 30 days</option>
                            <option value="all">All time</option>
                        </select>
                        <button type="button" class="sm-team-secondary" id="sm-overview-refresh">Refresh</button>
                    </div>
                </div>
                <p class="sm-overview-status" role="status" aria-live="polite" id="sm-overview-status">Loading recorded activity…</p>
                <div class="sm-overview-metrics" aria-label="Production metrics">
                    <div class="sm-stat"><span class="sm-stat-label">Total jobs</span><strong class="sm-stat-value" data-overview-stat="total">—</strong></div>
                    <div class="sm-stat"><span class="sm-stat-label">Completed</span><strong class="sm-stat-value" data-overview-stat="completed">—</strong></div>
                    <div class="sm-stat"><span class="sm-stat-label">Needs attention</span><strong class="sm-stat-value" data-overview-stat="attention">—</strong></div>
                    <div class="sm-stat"><span class="sm-stat-label">Completion rate</span><strong class="sm-stat-value" data-overview-stat="rate">—</strong></div>
                </div>
                <div class="sm-overview-grid">
                    <section class="sm-card" aria-labelledby="sm-overview-pipeline-title">
                        <div class="sm-card-header"><h2 class="sm-card-title" id="sm-overview-pipeline-title">Pipeline status</h2><p class="sm-card-caption">Recorded job states, including paused and incomplete work.</p></div>
                        <div class="sm-card-body" id="sm-overview-pipeline"><p class="sm-overview-empty">Loading…</p></div>
                    </section>
                    <section class="sm-card" aria-labelledby="sm-overview-modes-title">
                        <div class="sm-card-header"><h2 class="sm-card-title" id="sm-overview-modes-title">Source modes</h2><p class="sm-card-caption">History-level REF and GEN counts. REF source subtypes are not yet tracked separately.</p></div>
                        <div class="sm-card-body" id="sm-overview-modes"><p class="sm-overview-empty">Loading…</p></div>
                    </section>
                    <section class="sm-card sm-overview-recent" aria-labelledby="sm-overview-recent-title">
                        <div class="sm-card-header"><h2 class="sm-card-title" id="sm-overview-recent-title">Recent production jobs</h2><p class="sm-card-caption">Latest archived activity within the selected period.</p></div>
                        <div class="sm-card-body"><div id="sm-overview-jobs"></div><a class="sm-overview-link" href="<?php echo esc_url( admin_url( 'admin.php?page=syndamorph&tab=production#sm-team-panel-history' ) ); ?>">Open Production History</a></div>
                    </section>
                    <section class="sm-card" aria-labelledby="sm-overview-evidence-title">
                        <div class="sm-card-header"><h2 class="sm-card-title" id="sm-overview-evidence-title">Agent performance &amp; API spending</h2><p class="sm-card-caption">Metrics require verified, job-bound usage and agent receipts.</p></div>
                        <div class="sm-card-body">
                            <p class="sm-overview-empty">Agent latency, token usage, model costs and visual QA trends are not yet aggregated by this reporting endpoint. No estimates are shown without verified evidence.</p>
                            <a class="sm-overview-link" href="<?php echo esc_url( admin_url( 'admin.php?page=syndamorph&tab=production' ) ); ?>">Open AI Production Team</a>
                        </div>
                    </section>
                </div>
            </main>
        </div>
    </div>
    <?php
}

add_action( 'admin_enqueue_scripts', static function ( string $hook ): void {
    if ( 'toplevel_page_syndamorph' !== $hook || ! current_user_can( 'manage_options' ) ) { return; }
    $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
    if ( '' !== $tab && 'overview' !== $tab ) { return; }
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
