<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FI_Cache
 * Cache invalidation helpers for the scan DB cache.
 *
 * The primary cache is the DB table (fi_scans.expires_at). FI_Scan_Runner
 * reads it via FI_DB::get_scan_by_place_id(), which filters WHERE expires_at > NOW().
 *
 * Invalidation works by setting expires_at to the current UTC time, which makes
 * the record invisible to the cache lookup on the next scan request. The row is
 * kept in the DB so historical lead/share data tied to its scan_id remains intact.
 *
 * clear_all() hard-deletes all scan rows because it is an explicit admin action
 * ("wipe everything") — it would be confusing if "Clear Cache" left rows behind.
 */
class FI_Cache {

    /**
     * Expire the cached scan for a single place_id.
     * The next scan for this business will fetch fresh data from Google + Claude.
     *
     * @param string $place_id  Google Place ID.
     */
    public static function expire( string $place_id ): void {
        global $wpdb;
        $t = $wpdb->prefix . 'fi_scans';
        $wpdb->update(
            $t,
            [ 'expires_at' => gmdate( 'Y-m-d H:i:s' ) ],
            [ 'place_id'   => $place_id ]
        );
    }

    /**
     * Expire the cached scan by scan ID (for use when place_id is not available).
     *
     * @param int $scan_id  Primary key of the fi_scans row.
     */
    public static function expire_by_id( int $scan_id ): void {
        FI_DB::expire_scan( $scan_id );
    }

    /**
     * Clear all cached scans site-wide.
     * Used by the admin "Clear Cache" button. Permanently removes all scan rows,
     * including their report JSON. Lead and share rows that reference those scan IDs
     * are left in place (left-join safe — the DB layer handles missing scan rows).
     */
    public static function clear_all(): void {
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}fi_scans" );
    }
}
