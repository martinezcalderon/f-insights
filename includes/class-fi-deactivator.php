<?php
/**
 * Fired during plugin deactivation.
 *
 * Handles all cleanup that should run when the plugin is deactivated but NOT
 * uninstalled. We deliberately leave the database tables and saved options
 * intact so that re-activating the plugin restores everything exactly as the
 * user left it — leads, analytics, and settings are preserved.
 *
 * If you need to wipe data on full removal, add an uninstall.php file.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FI_Deactivator {

    /**
     * Run all deactivation routines.
     *
     * Called by register_deactivation_hook() in the main plugin file.
     * Keep this method fast — it runs synchronously during the admin
     * page load when the user clicks "Deactivate".
     */
    public static function deactivate(): void {

        // Unschedule the daily rate-limit cleanup cron before anything else.
        // If we leave it registered it will fire against a plugin that is no
        // longer loaded, which generates PHP fatal errors in the WP cron log.
        self::unschedule_cron_events();

        // Remove expired cache rows so the table is clean if the plugin is
        // re-activated later. We only delete expired rows — valid cached data
        // is left alone in case the admin is doing a quick deactivate/reactivate
        // cycle (e.g. during troubleshooting).
        self::cleanup_expired_cache();

        // Flush rewrite rules last. This ensures WordPress rebuilds its URL
        // routing table without any rules this plugin may have registered.
        flush_rewrite_rules();

        FI_Logger::info( 'Plugin deactivated — cron unscheduled, expired cache cleared.' );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Remove all WP-Cron events registered by this plugin.
     *
     * wp_next_scheduled() returns the timestamp of the next scheduled run,
     * or false if the event is not in the queue. We check before calling
     * wp_unschedule_event() so we never pass false as a timestamp, which
     * would silently fail or log a PHP warning depending on the WP version.
     */
    private static function unschedule_cron_events(): void {
        $cron_hooks = array(
            'fi_rate_limit_cleanup',
            'fi_shared_report_cleanup',
        );

        foreach ( $cron_hooks as $hook ) {
            $timestamp = wp_next_scheduled( $hook );
            if ( $timestamp ) {
                wp_unschedule_event( $timestamp, $hook );
            }
        }
    }

    /**
     * Delete cache rows whose expiry timestamp has already passed.
     *
     * We leave unexpired rows in place. If the admin reactivates the plugin
     * within the cache window those results are still valid and will be served,
     * saving unnecessary API calls to Google and Claude.
     *
     * Uses gmdate() / UTC for the comparison timestamp to stay consistent with
     * how expires_at is written in FI_Scanner (which also uses gmdate/UTC).
     */
    private static function cleanup_expired_cache(): void {
        global $wpdb;

        $cache_table = $wpdb->prefix . 'fi_cache';

        // Guard: if the table doesn't exist (e.g. interrupted activation),
        // skip silently rather than generating a DB error in the admin UI.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
        $table_exists = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $cache_table )
        );

        if ( $table_exists !== $cache_table ) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
        $deleted = $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "DELETE FROM $cache_table WHERE expires_at < %s",
                gmdate( 'Y-m-d H:i:s' )
            )
        );

        if ( $deleted === false ) {
            FI_Logger::warning(
                'Deactivation cache cleanup query failed.',
                array( 'last_error' => $wpdb->last_error )
            );
        }
    }
}