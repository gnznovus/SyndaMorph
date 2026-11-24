<?php
/** Private, administrator-only production history. No provider calls on reads. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__.'/production-editor-learning.php';

function syndamorph_history_tables(): array {
    global $wpdb;
    return array( $wpdb->prefix . 'sm_jobs', $wpdb->prefix . 'sm_job_events' );
}

function syndamorph_history_install(): bool {
    global $wpdb;
    if ( '1' === get_option( 'syndamorph_history_schema' ) ) { return true; }
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    list( $jobs, $events ) = syndamorph_history_tables();
    $charset = $wpdb->get_charset_collate();
    dbDelta( "CREATE TABLE $jobs (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        category varchar(3) NOT NULL,
        sequence_no bigint unsigned NOT NULL,
        title varchar(200) NOT NULL,
        status varchar(24) NOT NULL,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        context longtext NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY category_sequence (category,sequence_no),
        KEY updated_at (updated_at)
    ) $charset;" );
    dbDelta( "CREATE TABLE $events (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        job_id bigint unsigned NOT NULL,
        event_type varchar(32) NOT NULL,
        payload longtext NOT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY job_event (job_id,id)
    ) $charset;" );
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $jobs ) ) ) !== $jobs ||
         $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $events ) ) ) !== $events ) { return false; }
    update_option( 'syndamorph_history_schema', '1', false );
    return true;
}

function syndamorph_history_label( string $category, int $sequence ): string {
    return $category . '-' . str_pad( (string) $sequence, 6, '0', STR_PAD_LEFT );
}

function syndamorph_history_create( string $category, string $title, array $context ) {
    global $wpdb;
    if ( ! in_array( $category, array( 'GEN', 'REF' ), true ) || ! syndamorph_history_install() ) {
        return new WP_Error( 'sm_history_unavailable', 'Job archive unavailable; no job was started.', array( 'status' => 503 ) );
    }
    list( $jobs ) = syndamorph_history_tables();
    $encoded = wp_json_encode( $context, JSON_INVALID_UTF8_SUBSTITUTE );
    if ( ! is_string( $encoded ) || strlen( $encoded ) > 48000000 ) {
        return new WP_Error( 'sm_history_size', 'Reference exceeds the archive limit.', array( 'status' => 413 ) );
    }
    // The unique constraint arbitrates concurrent allocations; retry only the
    // local DB insert, never a provider request. No job IDs are deleted/reused.
    $title = mb_substr( sanitize_text_field( $title ), 0, 200 );
    $now = gmdate( 'Y-m-d H:i:s' );
    for ( $attempt = 0; $attempt < 8; $attempt++ ) {
        $seq = 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(sequence_no),0) FROM $jobs WHERE category=%s", $category ) );
        $old = $wpdb->suppress_errors( true );
        try {
            $ok = $wpdb->insert( $jobs, array(
                'category' => $category, 'sequence_no' => $seq, 'title' => $title ?: 'Untitled job',
                'status' => 'running', 'created_at' => $now, 'updated_at' => $now, 'context' => $encoded,
            ) );
        } finally { $wpdb->suppress_errors( $old ); }
        if ( $ok ) {
            return array( 'id' => (int) $wpdb->insert_id, 'jobId' => syndamorph_history_label( $category, $seq ), 'title' => $title ?: 'Untitled job' );
        }
    }
    return new WP_Error( 'sm_history_write', 'Could not reserve a job ID; no job was started.', array( 'status' => 503 ) );
}

function syndamorph_history_append( int $id, string $event, array $data ): void {
    global $wpdb;
    list( $jobs, $events ) = syndamorph_history_tables();
    $encoded = wp_json_encode( $data, JSON_INVALID_UTF8_SUBSTITUTE );
    if ( ! is_string( $encoded ) || strlen( $encoded ) > 48000000 ||
         ! $wpdb->insert( $events, array( 'job_id' => $id, 'event_type' => $event, 'payload' => $encoded, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) ) ) {
        throw new RuntimeException( 'History event could not be recorded.' );
    }
    $update = array( 'updated_at' => gmdate( 'Y-m-d H:i:s' ) );
    if ( 'job_complete' === $event ) { $update['status'] = 'generated' === ($data['status'] ?? '') ? 'completed' : (in_array(($data['status'] ?? ''),array('uncompleted','needs_review','failed','completed'),true) ? $data['status'] : 'needs_review'); }
    if ( 'job_error' === $event ) { $update['status'] = 'failed'; }
    if ( 'editor_validation' === $event && !empty($data['passed']) && !empty($data['completionEligible']) ) { $update['status'] = 'completed'; }
    if ( 'job_title' === $event ) { $update['title'] = mb_substr( sanitize_text_field( (string) ( $data['title'] ?? '' ) ), 0, 200 ); }
    if ( false === $wpdb->update( $jobs, $update, array( 'id' => $id ) ) ) {
        throw new RuntimeException( 'History job metadata could not be updated.' );
    }
}

function syndamorph_history_summary( array $row ): array {
    $status = $row['status'];
    if ( 'running' === $status && strtotime( $row['updated_at'] . ' UTC' ) < time() - 1500 ) { $status = 'interrupted'; }
    return array(
        'id' => (int) $row['id'], 'jobId' => syndamorph_history_label( $row['category'], (int) $row['sequence_no'] ),
        'category' => $row['category'], 'title' => $row['title'], 'status' => $status,
        'createdAt' => $row['created_at'] . 'Z', 'updatedAt' => $row['updated_at'] . 'Z',
    );
}

add_action( 'rest_api_init', static function (): void {
    $permission = static function (): bool { return current_user_can( 'manage_options' ); };
    register_rest_route( 'syndamorph/v1', '/history', array(
        'methods' => 'GET', 'permission_callback' => $permission,
        'callback' => static function ( WP_REST_Request $request ) {
            global $wpdb;
            if ( ! syndamorph_history_install() ) { return new WP_Error( 'sm_history_unavailable', 'Archive unavailable.', array( 'status' => 503 ) ); }
            list( $jobs ) = syndamorph_history_tables();
            $page = max( 1, min( 100000, (int) $request->get_param( 'page' ) ) );
            $type = (string) $request->get_param( 'category' );
            $query = mb_substr( (string) $request->get_param( 'q' ), 0, 200 );
            $sorts = array( 'newest' => 'id DESC', 'oldest' => 'id ASC', 'title' => 'title ASC,id DESC', 'id' => 'category ASC,sequence_no ASC', 'status' => 'status ASC,id DESC' );
            $order = $sorts[ (string) $request->get_param( 'sort' ) ] ?? $sorts['newest'];
            $where = '1=1';
            if ( in_array( $type, array( 'GEN', 'REF' ), true ) ) { $where .= $wpdb->prepare( ' AND category=%s', $type ); }
            if ( '' !== $query ) {
                $like = '%' . $wpdb->esc_like( $query ) . '%';
                $where .= $wpdb->prepare( " AND (title LIKE %s OR CONCAT(category,'-',LPAD(sequence_no,6,'0')) LIKE %s)", $like, $like );
            }
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,category,sequence_no,title,status,created_at,updated_at FROM $jobs WHERE $where ORDER BY $order LIMIT 51 OFFSET %d", ( $page - 1 ) * 50 ), ARRAY_A );
            if ( ! is_array( $rows ) ) { return new WP_Error( 'sm_history_read', 'Could not read history.', array( 'status' => 503 ) ); }
            return rest_ensure_response( array( 'jobs' => array_map( 'syndamorph_history_summary', array_slice( $rows, 0, 50 ) ), 'more' => count( $rows ) > 50 ) );
        },
    ) );
    // Source Import is currently deterministic. Preserve its input separately
    // from AI events; no provider is contacted by these endpoints.
    register_rest_route( 'syndamorph/v1', '/history/reference', array(
        'methods' => 'POST', 'permission_callback' => $permission,
        'callback' => static function ( WP_REST_Request $request ) {
            $context = $request->get_param( 'reference' );
            if ( is_string( $context ) ) { $context = json_decode( $context, true ); }
            if ( ! is_array( $context ) || ! in_array( $context['kind'] ?? '', array( 'url', 'html', 'package' ), true ) ) {
                return new WP_Error( 'sm_history_reference', 'Invalid reference.', array( 'status' => 400 ) );
            }
            $files = $request->get_file_params();
            // Multipart requests contain the original ZIP and JSON metadata.
            if ( 'package' === $context['kind'] ) {
                $file = $files['package'] ?? null;
                if ( ! is_array( $file ) || UPLOAD_ERR_OK !== $file['error'] || $file['size'] > 32 * 1024 * 1024 ||
                    ! is_uploaded_file( $file['tmp_name'] ) ) {
                    return new WP_Error( 'sm_history_reference', 'Original ZIP is required for archive.', array( 'status' => 400 ) );
                }
                $bytes = file_get_contents( $file['tmp_name'] );
                if ( false === $bytes ) { return new WP_Error( 'sm_history_reference', 'Cannot read ZIP.', array( 'status' => 400 ) ); }
                $context['package'] = array( 'filename' => sanitize_file_name( $file['name'] ), 'sha256' => hash( 'sha256', $bytes ), 'base64' => base64_encode( $bytes ) );
            }
            return syndamorph_history_create( 'REF', (string) $request->get_param( 'title' ), array( 'workflow' => 'source-import', 'reference' => $context ) );
        },
    ) );
    register_rest_route( 'syndamorph/v1', '/history/(?P<id>[0-9]+)/reference-event', array(
        'methods' => 'POST', 'permission_callback' => $permission,
        'callback' => static function ( WP_REST_Request $request ) {
            global $wpdb;
            list( $jobs ) = syndamorph_history_tables();
            $id = (int) $request['id'];
            $type = (string) $request->get_param( 'type' );
            $data = $request->get_param( 'data' );
            if ( ! in_array( $type, array( 'reference_page', 'job_complete', 'job_error', 'reference_saved' ), true ) || ! is_array( $data ) ||
                'REF' !== $wpdb->get_var( $wpdb->prepare( "SELECT category FROM $jobs WHERE id=%d", $id ) ) ) {
                return new WP_Error( 'sm_history_reference', 'Invalid reference event.', array( 'status' => 400 ) );
            }
            try { syndamorph_history_append( $id, $type, $data ); }
            catch ( Throwable $error ) { return new WP_Error( 'sm_history_write', 'Could not record reference event.', array( 'status' => 503 ) ); }
            return rest_ensure_response( array( 'recorded' => true ) );
        },
    ) );
    // Recompile only the archived best plan. Keep original evidence immutable.
    register_rest_route('syndamorph/v1','/history/(?P<id>[0-9]+)/recompile-native',array(
        'methods'=>'POST','permission_callback'=>$permission,
        'callback'=>static function(WP_REST_Request $request){
            global $wpdb;
            list($jobs,$events)=syndamorph_history_tables();
            $id=(int)$request['id'];
            $job=$wpdb->get_row($wpdb->prepare("SELECT category,status FROM $jobs WHERE id=%d",$id),ARRAY_A);
            if(!$job||$job['category']!=='GEN'||!in_array($job['status'],array('needs_review','uncompleted'),true))
                return new WP_Error('sm_recompile_status','Job is not eligible for recovery.',array('status'=>409));
            $raw=$wpdb->get_var($wpdb->prepare("SELECT payload FROM $events WHERE job_id=%d AND event_type='job_complete' ORDER BY id DESC LIMIT 1",$id));
            $final=is_string($raw)?json_decode($raw,true):null;
            $old=$final['artifact']['content']??null;
            $plan=$final['outputs']['developer']??null;
            $checksum=(string)$request->get_param('contentSha256');
            if(($final['outputType']??'')!=='gutenberg'||!is_string($old)||!is_string($plan)||!hash_equals(hash('sha256',$old),$checksum))
                return new WP_Error('sm_recompile_plan','Archived native plan missing or artifact changed. Reopen History.',array('status'=>409));
            require_once __DIR__.'/production-block-plan.php';
            $artifact=syndamorph_compile_block_plan($plan);
            if(is_wp_error($artifact))return $artifact;
            $artifact['contentSha256']=hash('sha256',$artifact['content']);
            if(hash_equals($checksum,$artifact['contentSha256']) && $artifact['preview']===$final['artifact']['preview'])
                return rest_ensure_response(array('artifact'=>$artifact,'changed'=>false));
            // Old QA remains historical evidence. Recovery requires fresh browser
            // inspection before completion, and never silently promotes a job.
            require_once __DIR__.'/production-collaboration.php';
            $reference=$final['referenceHtml']??null;
            if(!is_string($reference))return new WP_Error('sm_recompile_reference','Archived reference unavailable.',array('status'=>409));
            $ref=syndamorph_collab_inspect($reference);
            $native=syndamorph_collab_inspect($artifact['preview']);
            if(is_wp_error($ref)||is_wp_error($native))return new WP_Error('sm_recompile_qa','Browser reinspection unavailable.',array('status'=>503));
            $comparison=syndamorph_collab_compare($ref,$native);
            if(is_wp_error($comparison))return $comparison;
            $decoded=json_decode(syndamorph_native_json_text($plan),true);
            $issues=syndamorph_issue_merge(array_merge(syndamorph_collab_block_issues($artifact),syndamorph_collab_evidence($ref,$native),syndamorph_collab_style_findings($ref,$native,$decoded)));
            // Keep outstanding original model issues; a deterministic recompile
            // cannot assert that a previous visual judgment has been resolved.
            foreach($final['quality']['issues']??array() as $issue)if(($issue['source']??'')==='model')$issues[]=$issue;
            $issues=syndamorph_issue_merge($issues);
            $final['artifact']=$artifact;
            $final['checks']=$artifact['checks'];
            $final['quality']['issues']=$issues;
            $final['quality']['score']=syndamorph_issue_score($issues);
            $final['quality']['pixelComparison']=$comparison;
            $final['quality']['evidence']=array('reference'=>$ref,'native'=>$native);
            $final['quality']['nativeEditorVerified']=false;
            // Fresh model-based visual approval is not implied by a recompile.
            $final['quality']['visionInspected']=false;
            $final['quality']['browserInspected']=true;
            $final['quality']['terminationReason']='recompiled_requires_visual_review';
            $final['status']='needs_review';
            try{
                syndamorph_history_append($id,'native_recompiled',array('previousSha256'=>$checksum,'contentSha256'=>$artifact['contentSha256'],'aiCalls'=>0));
                syndamorph_history_append($id,'job_complete',$final);
            }catch(Throwable $error){return new WP_Error('sm_history_write','Could not archive recovered artifact.',array('status'=>503));}
            return rest_ensure_response(array('artifact'=>$artifact,'changed'=>true,'issues'=>$issues));
        },
    ));
    register_rest_route( 'syndamorph/v1', '/history/(?P<id>[0-9]+)/validate-native', array(
        'methods' => 'POST', 'permission_callback' => $permission,
        'callback' => static function ( WP_REST_Request $request ) {
            global $wpdb;
            list($jobs,$events)=syndamorph_history_tables();
            $id=(int)$request['id'];
            $checksum=(string)$request->get_param('contentSha256');
            $count=(int)$request->get_param('blockCount');
            if(false===$request->get_param('valid')){
                $job=$wpdb->get_row($wpdb->prepare("SELECT category,status FROM $jobs WHERE id=%d",$id),ARRAY_A);
                $raw=$wpdb->get_var($wpdb->prepare("SELECT payload FROM $events WHERE job_id=%d AND event_type='job_complete' ORDER BY id DESC LIMIT 1",$id));
                $final=is_string($raw)?json_decode($raw,true):null;
                if(!$job||$job['category']!=='GEN'||!is_string($final['artifact']['content']??null)||!hash_equals(hash('sha256',$final['artifact']['content']),$checksum))
                    return new WP_Error('sm_native_validation','Invalid archived artifact.',array('status'=>409));
                $kind=(string)$request->get_param('failureKind');
                if(!in_array($kind,array('registry','parser','serialization','unknown'),true))$kind='unknown';
                $block=(string)$request->get_param('blockName');
                if(!preg_match('~^core/[a-z-]+$~D',$block))$block='';
                try{syndamorph_history_append($id,'editor_validation',array('passed'=>false,'contentSha256'=>$checksum,'failureKind'=>$kind,'blockName'=>$block,'verifiedLesson'=>false));syndamorph_editor_learning_observe($id,$checksum,$kind,$block);}
                catch(Throwable $error){return new WP_Error('sm_history_write','Could not record editor failure.',array('status'=>503));}
                return rest_ensure_response(array('status'=>$job['status'],'recorded'=>true));
            }
            if(!preg_match('/^[a-f0-9]{64}$/D',$checksum) || $count<1 || $count>200 || true!==$request->get_param('valid'))
                return new WP_Error('sm_native_validation','Invalid native editor verification.',array('status'=>400));
            $job=$wpdb->get_row($wpdb->prepare("SELECT category,status FROM $jobs WHERE id=%d",$id),ARRAY_A);
            if(!$job || $job['category']!=='GEN' || !in_array($job['status'],array('needs_review','completed','uncompleted'),true))
                return new WP_Error('sm_native_validation','Job is not eligible for completed status.',array('status'=>409));
            $raw=$wpdb->get_var($wpdb->prepare("SELECT payload FROM $events WHERE job_id=%d AND event_type='job_complete' ORDER BY id DESC LIMIT 1",$id));
            $final=is_string($raw)?json_decode($raw,true):null;
            if(!is_array($final) || ($final['outputType']??'')!=='gutenberg' ||
                !is_string($final['artifact']['content']??null) ||
                !hash_equals(hash('sha256',$final['artifact']['content']),$checksum))
                return new WP_Error('sm_native_validation','Artifact or quality evidence is not eligible for completion.',array('status'=>409));
            $parsed=parse_blocks($final['artifact']['content']);
            $actual=0;
            $verify=static function(array $nodes) use (&$verify,&$actual): bool {
                foreach($nodes as $node) {
                    if(!is_string($node['blockName']??null) || !str_starts_with($node['blockName'],'core/'))return false;
                    $actual++;
                    if($actual>200 || !$verify($node['innerBlocks']??array()))return false;
                }
                return true;
            };
            if(!is_array($parsed) || !$verify($parsed) || $actual!==$count)
                return new WP_Error('sm_native_validation','Native block structure differs from the registered editor result.',array('status'=>422));
            $eligible=empty($final['quality']['issues']) && !empty($final['quality']['visionInspected']) && !empty($final['quality']['browserInspected']);
            $status=$eligible?'completed':$job['status'];
            $recoveryRaw=$wpdb->get_var($wpdb->prepare("SELECT payload FROM $events WHERE job_id=%d AND event_type='native_recompiled' ORDER BY id DESC LIMIT 1",$id));
            $recovery=is_string($recoveryRaw)?json_decode($recoveryRaw,true):null;
            try {
                syndamorph_history_append($id,'editor_validation',array('passed'=>true,'contentSha256'=>$checksum,'blockCount'=>$count,'completionEligible'=>$eligible));
                if(($recovery['contentSha256']??'')===$checksum && is_string($recovery['previousSha256']??null))
                    syndamorph_editor_learning_confirm($id,$recovery['previousSha256'],$checksum);
            }
            catch(Throwable $error){return new WP_Error('sm_history_write','Editor verification could not be archived.',array('status'=>503));}
            return rest_ensure_response(array('status'=>$status,'recorded'=>true));
        },
    ) );
    register_rest_route( 'syndamorph/v1', '/history/(?P<id>[0-9]+)/export-receipt', array(
        'methods' => 'POST', 'permission_callback' => $permission,
        'callback' => static function ( WP_REST_Request $request ) {
            global $wpdb;
            list( $jobs ) = syndamorph_history_tables();
            $url_params = $request->get_url_params();
            $id = (int) $url_params['id'];
            $mode = (string) $request->get_param( 'mode' );
            // Route ID identifies the job; use the JSON body for the saved object.
            $json = $request->get_json_params();
            $post_id = (int) ( $json['id'] ?? 0 );
            $post = get_post( $post_id );
            $job = $wpdb->get_row( $wpdb->prepare( "SELECT category,status FROM $jobs WHERE id=%d", $id ), ARRAY_A );
            if ( ! $job || 'GEN' !== $job['category'] || ! in_array( $job['status'], array( 'completed', 'needs_review', 'uncompleted' ), true ) ||
                ! in_array( $mode, array( 'draft', 'pattern' ), true ) || ! $post || ! current_user_can( 'edit_post', $post_id ) ||
                ( 'draft' === $mode && ( 'page' !== $post->post_type || 'draft' !== $post->post_status ) ) ||
                ( 'pattern' === $mode && ( 'wp_block' !== $post->post_type || 'unsynced' !== get_post_meta( $post_id, 'wp_pattern_sync_status', true ) ) ) ) {
                return new WP_Error( 'sm_history_export', 'Invalid job export receipt.', array( 'status' => 400 ) );
            }
            try { syndamorph_history_append( $id, 'export_receipt', array( 'mode' => $mode, 'id' => $post_id ) ); }
            catch ( Throwable $error ) { return new WP_Error( 'sm_history_write', 'Could not record export receipt.', array( 'status' => 503 ) ); }
            return rest_ensure_response( array( 'recorded' => true ) );
        },
    ) );
    register_rest_route( 'syndamorph/v1', '/history/(?P<id>[0-9]+)', array(
        'methods' => 'GET', 'permission_callback' => $permission,
        'callback' => static function ( WP_REST_Request $request ) {
            global $wpdb;
            list( $jobs, $events ) = syndamorph_history_tables();
            $id = (int) $request['id'];
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $jobs WHERE id=%d", $id ), ARRAY_A );
            if ( ! $row ) { return new WP_Error( 'sm_history_missing', 'Job not found.', array( 'status' => 404 ) ); }
            $records = $wpdb->get_results( $wpdb->prepare( "SELECT event_type,payload,created_at FROM $events WHERE job_id=%d ORDER BY id ASC", $id ), ARRAY_A );
            if ( ! is_array( $records ) ) { return new WP_Error( 'sm_history_read', 'Could not read job events.', array( 'status' => 503 ) ); }
            return rest_ensure_response( array_merge( syndamorph_history_summary( $row ), array(
                'context' => json_decode( $row['context'], true ),
                'events' => array_map( static fn( $e ) => array( 'type' => $e['event_type'], 'data' => json_decode( $e['payload'], true ), 'at' => $e['created_at'] . 'Z' ), $records ),
            ) ) );
        },
    ) );
} );
