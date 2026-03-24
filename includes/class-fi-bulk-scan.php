<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FI_Bulk_Scan
 *
 * Handles job dispatch, cron processing, cost estimation,
 * and AJAX endpoints for the Bulk Scan tab.
 *
 * One queue item is processed per cron tick (fi_bulk_scan_tick),
 * scheduled every 30 seconds while a job is active.
 * Single-threaded by design — no concurrent API calls.
 */
/**
 * Thrown when Claude returns a 429 or 529 so bulk scan can distinguish
 * rate-limit pauses from hard failures and schedule a backoff resumption.
 */
class FI_Rate_Limit_Exception extends Exception {
    private int $retry_after;

    public function __construct( string $message, int $retry_after = 60 ) {
        parent::__construct( $message );
        $this->retry_after = $retry_after;
    }

    public function getRetryAfter(): int {
        return $this->retry_after;
    }
}

class FI_Bulk_Scan {

    // Tokens per scan estimated from empirical averages when no history exists
    const DEFAULT_TOKENS_PER_SCAN = 8500;

    // ── Cron ─────────────────────────────────────────────────────────────────

    /**
     * Register the custom cron interval and hook.
     * Called on plugins_loaded.
     */
    public static function init(): void {
        add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_interval' ] );
        add_action( 'fi_bulk_scan_tick', [ __CLASS__, 'process_tick' ] );
    }

    public static function add_cron_interval( array $schedules ): array {
        if ( ! isset( $schedules['fi_every_30s'] ) ) {
            $schedules['fi_every_30s'] = [
                'interval' => 30,
                'display'  => 'Every 30 seconds',
            ];
        }
        return $schedules;
    }

    /**
     * Start (or resume) the cron loop for a job.
     */
    public static function schedule_tick( int $job_id ): void {
        if ( ! wp_next_scheduled( 'fi_bulk_scan_tick', [ $job_id ] ) ) {
            wp_schedule_event( time(), 'fi_every_30s', 'fi_bulk_scan_tick', [ $job_id ] );
        }
    }

    /**
     * Stop the cron loop for a job.
     */
    public static function unschedule_tick( int $job_id ): void {
        $timestamp = wp_next_scheduled( 'fi_bulk_scan_tick', [ $job_id ] );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'fi_bulk_scan_tick', [ $job_id ] );
        }
    }

    /**
     * Process a single queue item.
     * Called by WP Cron every 30 seconds while job is running.
     */
    /**
     * Auto-kill any queue items that have been in 'scanning' status for more
     * than 10 minutes. Called by fi_bulk_scan_tick at priority 5, before
     * process_tick (priority 10), so the queue is always unblocked first.
     *
     * A scan should complete in under 3 minutes even on a slow connection.
     * Anything older than 10 minutes is definitively stuck — the cron that
     * started it either died mid-execution or the remote API timed out silently.
     */
    public static function auto_kill_stuck( int $job_id ): void {
        global $wpdb;
        $t       = FI_DB::tables();
        $cutoff  = gmdate( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS );

        $stuck = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$t['scan_queue']}
             WHERE job_id = %d AND status = 'scanning' AND scan_started_at < %s",
            $job_id,
            $cutoff
        ) );

        if ( $stuck === 0 ) return;

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$t['scan_queue']}
             SET status = 'failed',
                 error_message = 'Auto-killed: stuck scanning for over 10 minutes'
             WHERE job_id = %d AND status = 'scanning' AND scan_started_at < %s",
            $job_id,
            $cutoff
        ) );

        FI_DB::bulk_increment_job_counter( $job_id, 'failed', $stuck );
        FI_Logger::warn( "Bulk scan job #{$job_id}: auto-killed {$stuck} stuck item(s)." );
    }

    public static function process_tick( int $job_id ): void {
        $job = FI_DB::get_scan_job( $job_id );
        if ( ! $job || in_array( $job->status, [ 'complete', 'cancelled', 'paused' ], true ) ) {
            self::unschedule_tick( $job_id );
            return;
        }

        // Mark job as running on first tick
        if ( $job->status === 'pending' ) {
            FI_DB::update_scan_job( $job_id, [
                'status'     => 'running',
                'started_at' => gmdate( 'Y-m-d H:i:s' ),
            ] );
        }

        $item = FI_DB::get_next_queued_item( $job_id );

        if ( ! $item ) {
            // Nothing left — job is done
            FI_DB::update_scan_job( $job_id, [
                'status'       => 'complete',
                'completed_at' => gmdate( 'Y-m-d H:i:s' ),
            ] );
            self::unschedule_tick( $job_id );
            FI_Logger::info( "Bulk scan job #{$job_id} complete." );
            return;
        }

        // Mark item as scanning
        FI_DB::update_queue_item( $item->id, [
            'status'          => 'scanning',
            'scan_started_at' => gmdate( 'Y-m-d H:i:s' ),
        ] );

        $start_ms = (int) round( microtime( true ) * 1000 );

        try {
            $result = self::run_single_scan( $item );

            $duration_ms = (int) round( microtime( true ) * 1000 ) - $start_ms;
            $tokens      = (int) ( $result['tokens'] ?? 0 );

            FI_DB::update_queue_item( $item->id, [
                'status'      => 'complete',
                'place_id'    => $result['place_id'] ?? null,
                'scan_id'     => $result['scan_id']  ?? null,
                'tokens_used' => $tokens,
                'duration_ms' => $duration_ms,
            ] );

            FI_DB::increment_job_counters( $job_id, 'completed', $tokens );

        } catch ( FI_Rate_Limit_Exception $e ) {
            // Anthropic returned 429 or 529 — put the item back to queued and
            // pause the job for the recommended backoff duration. The cron will
            // reschedule after the pause expires.
            $retry_after = max( 30, (int) $e->getRetryAfter() );

            FI_DB::update_queue_item( $item->id, [
                'status'          => 'queued',
                'error_message'   => null,
                'scan_started_at' => null,
                'duration_ms'     => 0,
            ] );

            FI_DB::update_scan_job( $job_id, [
                'status'     => 'paused',
                'error_note' => "Rate limited by Claude API. Auto-resuming in {$retry_after}s.",
            ] );

            // Schedule a one-shot resumption after the backoff window
            self::unschedule_tick( $job_id );
            wp_schedule_single_event( time() + $retry_after, 'fi_bulk_scan_tick', [ $job_id ] );

            FI_Logger::warn( "Bulk scan job #{$job_id} paused for {$retry_after}s — Claude rate limit." );
            return; // skip the completion check below

        } catch ( Exception $e ) {
            $duration_ms = (int) round( microtime( true ) * 1000 ) - $start_ms;

            FI_DB::update_queue_item( $item->id, [
                'status'       => 'failed',
                'error_message'=> substr( $e->getMessage(), 0, 500 ),
                'duration_ms'  => $duration_ms,
            ] );

            FI_DB::increment_job_counters( $job_id, 'failed' );
            FI_Logger::error( "Bulk scan job #{$job_id} item #{$item->id} failed: " . $e->getMessage() );
        }

        // Re-check: if no more queued items, finalize
        $next = FI_DB::get_next_queued_item( $job_id );
        if ( ! $next ) {
            FI_DB::update_scan_job( $job_id, [
                'status'       => 'complete',
                'completed_at' => gmdate( 'Y-m-d H:i:s' ),
            ] );
            self::unschedule_tick( $job_id );
            FI_Logger::info( "Bulk scan job #{$job_id} complete." );
        }
    }

    /**
     * Run FI_Scan_Runner for a single queue item.
     * Returns [ 'place_id' => string, 'scan_id' => int, 'tokens' => int ].
     *
     * @throws Exception on Place lookup failure or scan failure.
     */
    private static function run_single_scan( object $item ): array {
        $scan_trace = FI_Logger::generate_scan_id();

        // Resolve Place ID if not already stored on the item.
        // Combine name + address for a better textQuery match — especially
        // important for common names like "Joe's Pizza" across multiple cities.
        $place_id = $item->place_id ?: '';
        if ( ! $place_id ) {
            $query    = trim( $item->input_name . ( $item->input_address ? ', ' . $item->input_address : '' ) );
            $place_id = FI_Google::search( $query, $scan_trace );
            if ( ! $place_id ) {
                throw new Exception( 'Place not found: ' . $query );
            }
        }

        // FI_Scan_Runner handles cache lookup, full data fetch, Claude grading,
        // and DB persistence. Pass the resolved place_id to skip its own lookup.
        $result = FI_Scan_Runner::run( $place_id, $item->input_name, '', $scan_trace );

        if ( is_wp_error( $result ) ) {
            $code = $result->get_error_code();
            // Propagate rate-limit errors as FI_Rate_Limit_Exception so process_tick
            // can pause the job with backoff rather than permanently failing the item.
            if ( in_array( $code, [ 'claude_rate_limited', 'claude_overloaded' ], true ) ) {
                $data        = $result->get_error_data( $code );
                $retry_after = (int) ( is_array( $data ) ? ( $data['retry_after'] ?? 60 ) : 60 );
                throw new FI_Rate_Limit_Exception( $result->get_error_message(), $retry_after );
            }
            throw new Exception( $result->get_error_message() );
        }

        if ( empty( $result['scan']['id'] ) ) {
            throw new Exception( 'Scan returned no result.' );
        }

        $scan_id = (int) $result['scan']['id'];

        // Read actual token usage from the _tokens key embedded by FI_Grader.
        // Falls back to 0 for cache hits (no Claude call was made).
        $tokens = (int) ( $result['report']['_tokens'] ?? 0 );

        // Auto-create a prospect record — bulk scans are outbound targets,
        // not self-identified leads. No email, starts at 'uncontacted'.
        // Only insert if one doesn't already exist for this scan.
        global $wpdb;
        $t        = FI_DB::tables();
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$t['leads']} WHERE scan_id = %d AND type = 'prospect' LIMIT 1",
            $scan_id
        ) );

        if ( ! $existing ) {
            // Extract pain points via shared utility (R3 fix)
            $report      = $result['report'] ?? [];
            $pain_points = implode( ', ', FI_Utils::extract_pain_points( $report ) );

            FI_DB::insert_lead( [
                'scan_id'       => $scan_id,
                'email'         => '',
                'business_name' => $result['scan']['business_name'] ?? $item->input_name,
                'overall_score' => (int) ( $result['scan']['overall_score'] ?? 0 ),
                'pain_points'   => $pain_points,
                'status'        => 'uncontacted',
                'type'          => 'prospect',
                'source'        => 'bulk',
                'created_at'    => gmdate( 'Y-m-d H:i:s' ),
            ] );
        }

        return [
            'place_id' => $place_id,
            'scan_id'  => $scan_id,
            'tokens'   => $tokens,
        ];
    }

    // ── Cost Estimation ───────────────────────────────────────────────────────

    /**
     * Return estimated token cost for N scans based on recent scan history.
     * Falls back to DEFAULT_TOKENS_PER_SCAN if no history.
     */
    public static function estimate_tokens( int $count ): array {
        global $wpdb;
        $t = FI_DB::tables();

        $avg = (float) $wpdb->get_var(
            "SELECT AVG(tokens_input + tokens_output)
             FROM {$t['scans']}
             WHERE tokens_input > 0
             ORDER BY scanned_at DESC
             LIMIT 30"
        );

        $per_scan = $avg > 0 ? (int) round( $avg ) : self::DEFAULT_TOKENS_PER_SCAN;
        $total    = $per_scan * $count;

        // Claude pricing per million tokens (as of 2025)
        $prices = [
            'claude-haiku-4-5-20251001'  => [ 'input' => 0.80,  'output' => 4.00  ],
            'claude-sonnet-4-5-20251015' => [ 'input' => 3.00,  'output' => 15.00 ],
            'claude-opus-4-5'            => [ 'input' => 15.00, 'output' => 75.00 ],
        ];

        // Assume roughly 75/25 input/output split
        $input_tokens  = (int) round( $total * 0.75 );
        $output_tokens = (int) round( $total * 0.25 );

        $costs = [];
        foreach ( $prices as $model => $p ) {
            $costs[ $model ] = round(
                ( $input_tokens / 1_000_000 ) * $p['input'] +
                ( $output_tokens / 1_000_000 ) * $p['output'],
                4
            );
        }

        return [
            'per_scan'      => $per_scan,
            'total_tokens'  => $total,
            'input_tokens'  => $input_tokens,
            'output_tokens' => $output_tokens,
            'costs'         => $costs,
            'from_history'  => $avg > 0,
        ];
    }

    // ── AJAX Handlers ─────────────────────────────────────────────────────────

    /**
     * Estimate cost before job is created.
     * Expects: count (int), businesses (JSON array of {name, address}).
     */
    public static function ajax_estimate(): void {
        check_ajax_referer( 'fi_bulk_scan', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $count = max( 1, (int) ( $_POST['count'] ?? 0 ) );
        wp_send_json_success( self::estimate_tokens( $count ) );
    }

    /**
     * Validate the pasted/uploaded business list and check for duplicates.
     * Expects: businesses (JSON array of {name, address}).
     */
    public static function ajax_validate(): void {
        check_ajax_referer( 'fi_bulk_scan', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $raw = json_decode( stripslashes( $_POST['businesses'] ?? '[]' ), true );
        if ( ! is_array( $raw ) || empty( $raw ) ) {
            wp_send_json_error( 'No businesses provided.' );
        }

        $validated  = [];
        $duplicates = [];

        foreach ( $raw as $item ) {
            $name    = sanitize_text_field( $item['name']    ?? '' );
            $address = sanitize_text_field( $item['address'] ?? '' );
            if ( ! $name ) continue;

            $existing = FI_DB::find_existing_scan_for_queue( $name, $address );
            if ( $existing ) {
                $duplicates[] = [
                    'name'       => $name,
                    'address'    => $address,
                    'score'      => (int) $existing->overall_score,
                    'expires_at' => $existing->expires_at,
                ];
            } else {
                $validated[] = [ 'name' => $name, 'address' => $address ];
            }
        }

        wp_send_json_success( [
            'valid'      => $validated,
            'duplicates' => $duplicates,
            'estimate'   => self::estimate_tokens( count( $validated ) ),
        ] );
    }

    /**
     * Create job and queue items, then start cron.
     * Expects: businesses (JSON array), force_rescan (0|1), confirmed (0|1).
     */
    public static function ajax_start(): void {
        check_ajax_referer( 'fi_bulk_scan', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $raw          = json_decode( stripslashes( $_POST['businesses'] ?? '[]' ), true );
        $force_rescan = ! empty( $_POST['force_rescan'] );
        // 'confirmed' must be explicitly set to 1 when the UI shows a large-job warning
        // and the user clicks through it. Prevents accidental 500-item jobs.
        $confirmed    = ! empty( $_POST['confirmed'] );

        if ( ! is_array( $raw ) || empty( $raw ) ) {
            wp_send_json_error( 'No businesses provided.' );
        }

        // ── Hard cap: absolute maximum regardless of confirmation ─────────────
        // Filterable so power users can raise it, but 500 is the sane default.
        // At ~35s/scan a 500-item job runs ~5 hours. Beyond that, WP Cron
        // reliability becomes the bottleneck and cost overruns are meaningful.
        $hard_cap = (int) apply_filters( 'fi_bulk_scan_hard_cap', 500 );
        if ( count( $raw ) > $hard_cap ) {
            wp_send_json_error( sprintf(
                'Job exceeds the maximum allowed size of %d businesses. Split your list into smaller batches.',
                $hard_cap
            ) );
        }

        $model = get_option( 'fi_claude_model', 'claude-haiku-4-5-20251001' );
        $items = [];

        foreach ( $raw as $item ) {
            $name    = sanitize_text_field( $item['name']    ?? '' );
            $address = sanitize_text_field( $item['address'] ?? '' );
            if ( ! $name ) continue;

            if ( ! $force_rescan ) {
                $existing = FI_DB::find_existing_scan_for_queue( $name, $address );
                if ( $existing ) continue; // skip duplicate
            }

            $items[] = [ 'input_name' => $name, 'input_address' => $address ];
        }

        if ( empty( $items ) ) {
            wp_send_json_error( 'All businesses are already in the cache. Enable "Force rescan" to re-scan them.' );
        }

        // ── Soft warning threshold: require explicit confirmation ─────────────
        // Filterable. Default: warn above 100 items so the admin sees the cost
        // estimate one more time before committing a large job.
        $warn_threshold = (int) apply_filters( 'fi_bulk_scan_warn_threshold', 100 );
        if ( count( $items ) > $warn_threshold && ! $confirmed ) {
            $estimate = self::estimate_tokens( count( $items ) );
            $model_key = get_option( 'fi_claude_model', 'claude-haiku-4-5-20251001' );
            $cost = $estimate['costs'][ $model_key ] ?? array_values( $estimate['costs'] )[0] ?? 0;
            wp_send_json_error( [
                'code'     => 'confirm_required',
                'count'    => count( $items ),
                'cost_est' => $cost,
                'message'  => sprintf(
                    'This job will scan %d businesses (est. $%.2f in Claude costs). Send confirmed=1 to proceed.',
                    count( $items ),
                    $cost
                ),
            ] );
        }

        // ── Pre-flight: verify both API keys are configured before creating
        // the job. A missing key causes every item to fail immediately. Better
        // to reject here with a clear message than to watch 100 items fail.
        if ( ! get_option( 'fi_claude_api_key', '' ) ) {
            wp_send_json_error( 'Claude API key is not configured. Add it in Settings → API Config before running a bulk scan.' );
        }
        if ( ! get_option( 'fi_google_api_key', '' ) ) {
            wp_send_json_error( 'Google API key is not configured. Add it in Settings → API Config before running a bulk scan.' );
        }

        $job_id = FI_DB::create_scan_job( $model );
        FI_DB::insert_queue_items( $job_id, $items );
        FI_DB::update_scan_job( $job_id, [ 'total' => count( $items ) ] );
        self::schedule_tick( $job_id );
        spawn_cron();

        wp_send_json_success( [ 'job_id' => $job_id, 'total' => count( $items ) ] );
    }

    /**
     * Pause a running job.
     */
    public static function ajax_pause(): void {
        check_ajax_referer( 'fi_bulk_scan', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $job_id = (int) ( $_POST['job_id'] ?? 0 );
        $job    = FI_DB::get_scan_job( $job_id );
        if ( ! $job || $job->status !== 'running' ) wp_send_json_error( 'Job not running.' );

        self::unschedule_tick( $job_id );
        FI_DB::update_scan_job( $job_id, [ 'status' => 'paused' ] );
        wp_send_json_success();
    }

    /**
     * Resume a paused job.
     */
    public static function ajax_resume(): void {
        check_ajax_referer( 'fi_bulk_scan', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $job_id = (int) ( $_POST['job_id'] ?? 0 );
        $job    = FI_DB::get_scan_job( $job_id );
        if ( ! $job || $job->status !== 'paused' ) wp_send_json_error( 'Job not paused.' );

        FI_DB::update_scan_job( $job_id, [ 'status' => 'running' ] );
        self::schedule_tick( $job_id );
        spawn_cron();
        wp_send_json_success();
    }

    /**
     * Cancel a job — marks it cancelled and stops cron.
     */
    public static function ajax_cancel(): void {
        check_ajax_referer( 'fi_bulk_scan', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $job_id = (int) ( $_POST['job_id'] ?? 0 );
        $job    = FI_DB::get_scan_job( $job_id );
        if ( ! $job ) wp_send_json_error( 'Job not found.' );

        self::unschedule_tick( $job_id );
        FI_DB::update_scan_job( $job_id, [
            'status'       => 'cancelled',
            'completed_at' => gmdate( 'Y-m-d H:i:s' ),
        ] );
        wp_send_json_success();
    }

    /**
     * Retry a single failed queue item.
     */
    public static function ajax_retry_item(): void {
        check_ajax_referer( 'fi_bulk_scan', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $item_id = (int) ( $_POST['item_id'] ?? 0 );
        global $wpdb;
        $t    = FI_DB::tables();
        $item = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$t['scan_queue']} WHERE id = %d LIMIT 1",
            $item_id
        ) );

        if ( ! $item || $item->status !== 'failed' ) {
            wp_send_json_error( 'Item not found or not failed.' );
        }

        // Reset to queued and resume the job's cron if needed
        FI_DB::update_queue_item( $item_id, [
            'status'          => 'queued',
            'error_message'   => null,
            'scan_started_at' => null,
            'duration_ms'     => 0,
        ] );

        $job = FI_DB::get_scan_job( (int) $item->job_id );
        if ( $job && in_array( $job->status, [ 'complete', 'paused' ], true ) ) {
            FI_DB::update_scan_job( (int) $item->job_id, [ 'status' => 'running' ] );
            self::schedule_tick( (int) $item->job_id );
            spawn_cron();
        }

        wp_send_json_success();
    }

    /**
     * Kill a single stuck/scanning item — mark it failed immediately.
     */
    public static function ajax_kill_item(): void {
        check_ajax_referer( 'fi_bulk_scan', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $item_id = (int) ( $_POST['item_id'] ?? 0 );
        global $wpdb;
        $t    = FI_DB::tables();
        $item = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$t['scan_queue']} WHERE id = %d LIMIT 1", $item_id
        ) );

        if ( ! $item ) wp_send_json_error( 'Item not found.' );

        FI_DB::update_queue_item( $item_id, [
            'status'        => 'failed',
            'error_message' => 'Manually killed by admin.',
            'duration_ms'   => 0,
        ] );
        FI_DB::increment_job_counters( (int) $item->job_id, 'failed' );
        wp_send_json_success();
    }

    /**
     * Attempt to manually trigger WP Cron for any running bulk scan job.
     * Used by the cron health banner when DISABLE_WP_CRON is detected or
     * a tick is missing for a running job.
     */
    public static function ajax_respawn_cron(): void {
        check_ajax_referer( 'fi_bulk_scan', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        // Find any running job and re-schedule its tick if missing
        global $wpdb;
        $t       = FI_DB::tables();
        $running = $wpdb->get_results(
            "SELECT id FROM {$t['scan_jobs']} WHERE status = 'running' ORDER BY id DESC LIMIT 5"
        );

        $rescheduled = 0;
        foreach ( $running as $job ) {
            $job_id = (int) $job->id;
            if ( ! wp_next_scheduled( 'fi_bulk_scan_tick', [ $job_id ] ) ) {
                wp_schedule_event( time(), 'fi_every_30s', 'fi_bulk_scan_tick', [ $job_id ] );
                $rescheduled++;
            }
        }

        // Force an immediate cron pass
        spawn_cron();

        wp_send_json_success( [ 'rescheduled' => $rescheduled ] );
    }

    /**
     * Export a completed job's queue items as a CSV download.
     * Columns: position, business name, address, status, score, tokens, time (s), error.
     *
     * Called via GET: admin-ajax.php?action=fi_bulk_export_csv&job_id=N&nonce=X
     */
    public static function ajax_export_csv(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $nonce = sanitize_text_field( $_GET['nonce'] ?? '' );
        if ( ! wp_verify_nonce( $nonce, 'fi_bulk_scan' ) ) wp_die( 'Invalid nonce' );

        $job_id = (int) ( $_GET['job_id'] ?? 0 );
        if ( ! $job_id ) wp_die( 'Missing job ID.' );

        $job   = FI_DB::get_scan_job( $job_id );
        $items = $job ? FI_DB::get_queue_items( $job_id ) : [];

        if ( ! $job || empty( $items ) ) {
            wp_die( 'Job not found or has no items.' );
        }

        // Pull overall_score for completed items by joining on scan_id → fi_scans
        $scores = [];
        global $wpdb;
        $t = FI_DB::tables();
        foreach ( $items as $item ) {
            if ( $item->status === 'complete' && $item->scan_id ) {
                $score = $wpdb->get_var( $wpdb->prepare(
                    "SELECT overall_score FROM {$t['scans']} WHERE id = %d LIMIT 1",
                    (int) $item->scan_id
                ) );
                $scores[ $item->id ] = $score !== null ? (int) $score : '';
            }
        }

        $filename = 'bulk-scan-job-' . $job_id . '-' . wp_date( 'Y-m-d' ) . '.csv';

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, [ '#', 'Business Name', 'Address', 'Status', 'Score', 'Tokens Used', 'Scan Time (s)', 'Error' ] );

        foreach ( $items as $item ) {
            fputcsv( $out, [
                (int) $item->position,
                $item->input_name,
                $item->input_address,
                $item->status,
                $scores[ $item->id ] ?? '',
                (int) $item->tokens_used,
                $item->duration_ms > 0 ? round( $item->duration_ms / 1000, 1 ) : '',
                $item->error_message ?? '',
            ] );
        }

        fclose( $out );
        exit;
    }

    /**
     * Kill all currently-scanning items in a job — resets them to failed.
     * Useful when multiple items are stuck and the admin wants to unblock the queue.
     */
    public static function ajax_kill_stuck(): void {
        check_ajax_referer( 'fi_bulk_scan', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $job_id = (int) ( $_POST['job_id'] ?? 0 );
        if ( ! $job_id ) wp_send_json_error( 'Missing job ID.' );

        global $wpdb;
        $t = FI_DB::tables();

        // Count how many are stuck
        $stuck = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$t['scan_queue']} WHERE job_id = %d AND status = 'scanning'",
            $job_id
        ) );

        if ( $stuck === 0 ) wp_send_json_error( 'No stuck items found.' );

        // Fail them all
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$t['scan_queue']}
             SET status = 'failed', error_message = 'Killed by admin; was stuck scanning'
             WHERE job_id = %d AND status = 'scanning'",
            $job_id
        ) );

        // Update job failed counter in one query
        FI_DB::bulk_increment_job_counter( $job_id, 'failed', $stuck );

        // If no queued items remain, mark job complete
        $remaining = FI_DB::get_next_queued_item( $job_id );
        if ( ! $remaining ) {
            FI_DB::update_scan_job( $job_id, [
                'status'       => 'complete',
                'completed_at' => gmdate( 'Y-m-d H:i:s' ),
            ] );
            self::unschedule_tick( $job_id );
        }

        wp_send_json_success( [ 'killed' => $stuck ] );
    }

    /**
     * Poll endpoint — returns current job state + all queue items.
     * Called every 10 seconds by the monitor UI.
     */
    public static function ajax_poll(): void {
        check_ajax_referer( 'fi_bulk_scan', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $job_id = (int) ( $_POST['job_id'] ?? 0 );
        $job    = FI_DB::get_scan_job( $job_id );
        if ( ! $job ) wp_send_json_error( 'Job not found.' );

        $items  = FI_DB::get_queue_items( $job_id );
        $counts = FI_DB::get_queue_status_counts( $job_id );

        wp_send_json_success( [
            'job'    => $job,
            'items'  => $items,
            'counts' => $counts,
        ] );
    }
}
