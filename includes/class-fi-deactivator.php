<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FI_Deactivator
 * Runs on plugin deactivation. Does NOT drop tables — data is preserved.
 * Tables are only removed on full uninstall (uninstall.php).
 */
class FI_Deactivator {

    public static function deactivate(): void {
        // Clear the daily cleanup cron on deactivation
        wp_clear_scheduled_hook( 'fi_daily_cleanup' );

        // Clear follow-up reminder cron hooks
        if ( class_exists( 'FI_Followup_Reminder' ) ) {
            FI_Followup_Reminder::unschedule();
        }

        // Unschedule all bulk scan tick events.
        // wp_clear_scheduled_hook without args removes ALL events for this hook
        // regardless of the job_id argument — catches both recurring 30s ticks
        // and the one-shot backoff resumption events scheduled by rate-limit handling.
        wp_clear_scheduled_hook( 'fi_bulk_scan_tick' );

        // Also explicitly unschedule per-job ticks for any jobs currently running
        // or paused, in case the hook was registered with specific job_id args that
        // wp_clear_scheduled_hook might miss depending on WP version.
        if ( class_exists( 'FI_DB' ) ) {
            global $wpdb;
            $t       = FI_DB::tables();
            $active  = $wpdb->get_col(
                "SELECT id FROM {$t['scan_jobs']} WHERE status IN ('running','paused')"
            );
            foreach ( $active as $job_id ) {
                FI_Bulk_Scan::unschedule_tick( (int) $job_id );
                // Mark as paused so it can be resumed after reactivation
                FI_DB::update_scan_job( (int) $job_id, [ 'status' => 'paused' ] );
            }
        }

        // Run one final share-link cleanup
        if ( class_exists( 'FI_DB' ) ) {
            FI_DB::delete_expired_shares();
        }

        FI_Logger::info( 'Plugin deactivated v' . FI_VERSION );
    }
}
