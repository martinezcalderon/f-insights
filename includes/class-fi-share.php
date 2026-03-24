<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class FI_Share {

    /**
     * Return an existing valid share URL for the scan, or create a new one.
     *
     * @param int    $scan_id    The scan this share is attached to.
     * @param string $source_url The page URL where the scan was run (e.g. https://example.com/free-report).
     *                           Stored on first creation so the share link always points back to the
     *                           exact page that originated it, regardless of admin slug settings.
     */
    public static function create_or_get( int $scan_id, string $source_url = '' ): string {
        $existing = FI_DB::get_share_by_scan_id( $scan_id );
        if ( $existing ) {
            // Use the stored source_url if we have it; fall back to slug-based build.
            $base = ! empty( $existing->source_url ) ? $existing->source_url : null;
            return self::build_url( $existing->token, $base );
        }

        // Share expiry must be at least as long as the scan cache duration so a
        // recipient never hits a "scan deleted" dead end while their link is still
        // technically valid. We take the longer of the two configured values.
        $share_days  = (int) get_option( 'fi_share_expiry_days', 7 );
        $cache_hours = (int) get_option( 'fi_cache_duration', 24 );
        $cache_days  = (int) ceil( $cache_hours / 24 );
        $days        = max( $share_days, $cache_days );

        $expires = gmdate( 'Y-m-d H:i:s', time() + ( $days * DAY_IN_SECONDS ) );
        $token   = FI_DB::create_share( $scan_id, $expires, $source_url );

        return self::build_url( $token, $source_url ?: null );
    }

    /**
     * Build a ?fi_report=TOKEN URL.
     *
     * Priority:
     *   1. $base_url — the exact page URL captured at scan time (cleanest, always correct).
     *   2. fi_shortcode_page_id admin setting — manual fallback for old shares or server-side calls.
     *   3. home_url('/') — last resort so the link is never broken.
     *
     * @param string      $token    Share token.
     * @param string|null $base_url Clean page URL without any query string (no ?fi_report=…).
     */
    public static function build_url( string $token, ?string $base_url = null ): string {
        if ( $base_url ) {
            // Strip any hash fragment first — fragments come after query strings in
            // valid URLs, so ?fi_report=TOKEN#fragment is fine, but a stored URL of
            // https://example.com/page#section would produce a malformed share URL
            // like https://example.com/page#section?fi_report=TOKEN that browsers ignore.
            $base_url = strtok( $base_url, '#' );

            // Strip any existing fi_report param from the stored URL so we never
            // end up with ?fi_report=OLD&fi_report=NEW double-params.
            $clean = remove_query_arg( 'fi_report', $base_url );
        } else {
            // Page-based fallback (admin setting)
            $page_id = (int) get_option( 'fi_shortcode_page_id', 0 );

            if ( $page_id ) {
                $clean = rtrim( get_permalink( $page_id ), '/' );
            } else {
                $clean = home_url( '/' );
            }
        }

        return add_query_arg( 'fi_report', $token, $clean );
    }

    /**
     * Resolve a share token to a report. Returns array with report + expiry info.
     */
    public static function resolve( string $token ): array {
        $share = FI_DB::get_share_by_token( sanitize_text_field( $token ) );

        if ( ! $share ) {
            return [ 'expired' => true, 'reason' => 'not_found' ];
        }

        if ( strtotime( $share->expires_at ) < time() ) {
            return [ 'expired' => true, 'reason' => 'expired', 'expired_at' => $share->expires_at ];
        }

        $scan = FI_DB::get_scan_by_id( $share->scan_id );
        if ( ! $scan ) {
            // The scan row was deleted (cache purge, manual clear) but the share
            // link is still within its validity window. Return enough context for
            // the frontend to offer a re-scan rather than a hard dead end.
            return [
                'expired'       => true,
                'reason'        => 'scan_deleted',
                'place_id'      => $share->place_id ?? null,
                'business_name' => null,
            ];
        }

        FI_DB::increment_share_views( $token );

        $report = json_decode( $scan->report_json, true );

        // Pull all display fields from embedded _meta (written during scan) or fall back to scan row
        $meta = $report['_meta'] ?? [];
        $report['name']        = $meta['name']        ?? $scan->business_name;
        $report['address']     = $meta['address']     ?? $scan->address;
        $report['phone']       = $meta['phone']       ?? $scan->phone;
        $report['website']     = $meta['website']     ?? $scan->website;
        $report['description'] = $meta['description'] ?? '';
        $report['category']    = $meta['category']    ?? $scan->category    ?? '';
        $report['vague_match'] = $meta['vague_match'] ?? false;
        $report['search_type'] = $meta['search_type'] ?? '';
        $report['photos']      = $meta['photos']      ?? [];
        $report['hours']       = $meta['hours']       ?? [];
        $report['price_level'] = $meta['price_level'] ?? null;
        // These fields only live in _meta — not in the DB row
        $report['pagespeed']   = $meta['pagespeed']   ?? null;
        $report['reviews_top'] = $meta['reviews_top'] ?? [];
        $report['reviews_low'] = $meta['reviews_low'] ?? [];

        $report['_scan_id'] = $scan->id;
        $report['_cached']  = true;
        $report['_share']   = [
            'token'      => $token,
            'expires_at' => $share->expires_at,
            'views'      => $share->views + 1,
        ];

        return [ 'expired' => false, 'report' => $report ];
    }

    public static function expiry_display( string $expires_at ): string {
        $diff = strtotime( $expires_at ) - time();
        if ( $diff < 0 ) return 'Expired';
        $days = floor( $diff / DAY_IN_SECONDS );
        if ( $days === 0 ) return 'Expires today';
        if ( $days === 1 ) return 'Expires tomorrow';
        return "Expires in $days days";
    }
}
