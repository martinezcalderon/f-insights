<?php
/**
 * Rate limiting to prevent abuse
 */

if (!defined('ABSPATH')) {
    exit;
}

class FI_Rate_Limiter {
    
    /**
     * Check if the current IP is allowed to scan, and if so, consume one slot.
     *
     * Uses an atomic INSERT … ON DUPLICATE KEY UPDATE so the read, check, and
     * increment happen in a single round-trip. This eliminates the race condition
     * present in the old read-check-write pattern, where two concurrent requests
     * from the same IP could both read scan_count = N, both pass the limit check,
     * and both increment — effectively granting an extra scan for free.
     *
     * Flow:
     *  1. If the IP has no row yet, insert one with scan_count = 1.
     *  2. If the row exists but the window has expired, reset it to scan_count = 1.
     *  3. If the row exists and the window is still open, increment scan_count
     *     only when it is still below the limit (scan_count < max_scans).
     *     The WHERE clause means the UPDATE is a no-op when the limit is already
     *     reached, so affected_rows = 0 signals "limit exceeded".
     *
     * @return true|WP_Error  true if the scan is allowed, WP_Error if blocked.
     */
    public static function check_limit() {
        $enabled = get_option( 'fi_rate_limit_enabled', '1' );

        if ( $enabled !== '1' ) {
            return true;
        }

        $ip        = self::get_client_ip();
        $max_scans = intval( get_option( 'fi_rate_limit_per_ip', 3 ) );
        $window    = intval( get_option( 'fi_rate_limit_window', 3600 ) );
        $reset_at  = gmdate( 'Y-m-d H:i:s', time() + $window );
        $now       = gmdate( 'Y-m-d H:i:s' );

        global $wpdb;
        $table = $wpdb->prefix . 'fi_rate_limits';

        // ── Step 1: Upsert — insert a new row or reset an expired window ─────
        // ON DUPLICATE KEY UPDATE fires when ip_address (UNIQUE) already exists.
        // If reset_time has passed we treat this as a fresh window: reset the
        // counter to 1 and push the reset_time forward. If the window is still
        // open we leave the row alone here and handle it in Step 2.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO $table (ip_address, scan_count, reset_time)
                 VALUES (%s, 1, %s)
                 ON DUPLICATE KEY UPDATE
                     scan_count = IF(reset_time < %s, 1,        scan_count),
                     reset_time = IF(reset_time < %s, %s, reset_time)",
                $ip, $reset_at,   // INSERT values
                $now, $now,       // IF conditions
                $reset_at         // new reset_time when window expired
            )
        );

        // ── Step 2: Atomic increment within the limit ─────────────────────────
        // Only increments if scan_count < max_scans AND the window is still open.
        // If either condition fails, affected_rows = 0 and we know to block.
        // This single UPDATE replaces the old read → compare → write trio.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $incremented = $wpdb->query(
            $wpdb->prepare(
                "UPDATE $table
                    SET scan_count = scan_count + 1
                  WHERE ip_address = %s
                    AND scan_count < %d
                    AND reset_time >= %s",
                $ip, $max_scans, $now
            )
        );

        if ( $incremented ) {
            return true; // Slot consumed — scan is allowed.
        }

        // ── Step 3: Limit reached — calculate time remaining ─────────────────
        // Re-read only to get reset_time for the error message. By this point
        // the row is guaranteed to exist so get_var is safe.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $reset_time = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT reset_time FROM $table WHERE ip_address = %s",
                $ip
            )
        );

        $time_remaining = $reset_time ? max( 0, strtotime( $reset_time ) - time() ) : $window;
        $minutes        = (int) ceil( $time_remaining / 60 );

        return new WP_Error(
            'rate_limit_exceeded',
            sprintf(
                __( 'Rate limit exceeded. Please try again in %d minutes.', 'f-insights' ),
                $minutes
            )
        );
    }
    
    /**
     * Get client IP address for rate-limiting purposes.
     *
     * REMOTE_ADDR is the only header that cannot be spoofed by the client because
     * it is set by the TCP connection, not by the HTTP request. HTTP_CLIENT_IP and
     * HTTP_X_FORWARDED_FOR are user-controlled headers; relying on them would allow
     * anyone to bypass the rate limiter by sending a fake IP in every request.
     *
     * If your site sits behind a trusted proxy (e.g. Cloudflare, a load balancer),
     * configure the proxy to overwrite REMOTE_ADDR at the network layer instead of
     * adding a forwarded header, or validate forwarded headers against a hard-coded
     * list of proxy CIDR ranges before trusting them.
     */
    private static function get_client_ip() {
        return sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');
    }
    
    /**
     * Clean up old rate limit records.
     *
     * Uses a transient mutex to prevent concurrent WP-Cron invocations (possible
     * on high-traffic sites) from running duplicate DELETE statements simultaneously.
     */
    public static function cleanup() {
        // Acquire a 5-minute lock — skip if another process is already cleaning.
        if ( get_transient( 'fi_rate_limit_cleanup_lock' ) ) {
            return;
        }
        set_transient( 'fi_rate_limit_cleanup_lock', 1, 5 * MINUTE_IN_SECONDS );

        global $wpdb;
        $table = $wpdb->prefix . 'fi_rate_limits';

        // Use gmdate() here to match the UTC timestamps written by check_limit().
        // current_time('mysql') returns WordPress local time, which diverges from
        // UTC on sites where the WP timezone setting is not UTC — causing cleanup
        // to delete rows too early or leave expired rows indefinitely.
        $deleted = $wpdb->query( $wpdb->prepare(
            "DELETE FROM $table WHERE reset_time < %s",
            gmdate( 'Y-m-d H:i:s' )
        ) );
        FI_Logger::info( 'Rate limit cleanup complete', array( 'deleted' => $deleted ) );

        delete_transient( 'fi_rate_limit_cleanup_lock' );
    }
}