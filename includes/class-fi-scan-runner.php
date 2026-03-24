<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FI_Scan_Runner
 * Orchestrates the full scan pipeline:
 *   1. Cache lookup
 *   2. Google Place details + website health + competitors
 *   3. Claude grading via FI_Grader
 *   4. DB persistence via FI_DB
 *   5. Share link creation via FI_Share
 *
 * FI_Ajax::handle_scan() calls FI_Scan_Runner::run() and gets back a
 * standardised result array, keeping the AJAX handler thin.
 *
 * To add a new data source (e.g. Yelp):
 *   - Add a fetch step in _fetch_external_data().
 *   - Merge the result into $scan_data before passing to FI_Grader.
 */
class FI_Scan_Runner {

    /**
     * Run the full scan pipeline.
     *
     * @param string $place_id   Google Place ID (may be empty if only name given)
     * @param string $place_name Human-readable business name
     * @param string $ip         Caller's IP for rate limiting
     * @param string $scan_id    8-char log trace ID
     *
     * @return array|WP_Error  On success: [ 'report'=>[], 'scan'=>object, 'share_url'=>string ]
     */
    public static function run(
        string $place_id,
        string $place_name,
        string $ip,
        string $scan_id
    ) {
        // ── 1. Resolve place_id from name if needed ───────────────────────
        if ( ! $place_id && $place_name ) {
            $place_id = FI_Google::search( $place_name, $scan_id );
            if ( ! $place_id ) {
                FI_Logger::error( "Could not resolve place_id for: $place_name", [], $scan_id );
                return new WP_Error( 'not_found', 'Business not found. Try a more specific name.' );
            }
        }

        // ── 2. Cache hit ──────────────────────────────────────────────────
        $cached = FI_DB::get_scan_by_place_id( $place_id );
        if ( $cached ) {
            FI_Logger::info( 'Cache hit, returning stored report', [], $scan_id );
            $report    = json_decode( $cached->report_json, true );
            $share_url = FI_Share::create_or_get( $cached->id );
            FI_Rate_Limiter::increment( $ip );
            return self::format_result( $report, $cached, $share_url );
        }

        // ── 3. Fetch external data ────────────────────────────────────────
        $external = self::fetch_external_data( $place_id, $scan_id );
        if ( is_wp_error( $external ) ) {
            return $external;
        }
        [ $details, $website_data, $competitors, $pagespeed_data ] = $external;

        // ── 4. Assemble scan data package for Claude ─────────────────────
        $scan_data = self::build_scan_data( $details, $place_name, $website_data, $competitors, $pagespeed_data );

        // ── 5. Grade with Claude ──────────────────────────────────────────
        $report = FI_Grader::grade( $scan_data, $scan_id );
        if ( is_wp_error( $report ) ) {
            FI_Logger::error( 'Grader failed: ' . $report->get_error_message(), [], $scan_id );
            return $report;
        }

        // ── 6. Embed display meta into report JSON (cache hits are self-contained) ──
        $taxonomy = FI_Taxonomy::resolve( $details['types'] ?? [] );
        $report['_meta'] = [
            'name'              => $scan_data['name'],
            'address'           => $scan_data['address'],
            'phone'             => $scan_data['phone'],
            'website'           => $scan_data['website'],
            'description'       => $scan_data['editorial_summary'] ?? '',
            'category'          => $taxonomy['label'],
            'parent'      => $taxonomy['parent'],
            'vague_match' => FI_Taxonomy::is_vague_match( $taxonomy ),
            'search_type' => $taxonomy['search_type'],
            'photos'      => array_slice( $details['photos'] ?? [], 0, 10 ),
            'hours'       => $details['opening_hours']['weekday_text'] ?? [],
            'price_level' => $details['price_level'] ?? null,
            'pagespeed'   => $pagespeed_data,
            'reviews_top' => $scan_data['reviews_top'],
            'reviews_low' => $scan_data['reviews_low'],
        ];

        // ── 7. Persist to DB — only if Claude returned a real report ─────────
        // If the grader hit a fallback (_error:true), do NOT cache it.
        // Caching a broken report would serve bad data on every future request
        // for this business until the cache is manually cleared.
        if ( ! empty( $report['_error'] ) ) {
            FI_Logger::warn( 'Grader returned fallback report — skipping DB cache to allow retry', [], $scan_id );
            // Still return a result so the frontend can show partial data,
            // but use a temporary scan object that won't persist.
            $temp_scan = (object) [
                'id'            => 0,
                'business_name' => $scan_data['name'],
                'address'       => $scan_data['address'],
                'phone'         => $scan_data['phone'],
                'website'       => $scan_data['website'],
                'description'   => $scan_data['editorial_summary'] ?? '',
                'overall_score' => 0,
                'category'      => $taxonomy['label'],
                'parent'        => $taxonomy['parent'],
                'vague_match'   => FI_Taxonomy::is_vague_match( $taxonomy ),
                'search_type'   => $taxonomy['search_type'],
                'photos'        => array_slice( $details['photos'] ?? [], 0, 10 ),
                'hours'         => $details['opening_hours']['weekday_text'] ?? [],
                'price_level'   => $details['price_level'] ?? null,
                'pagespeed'     => $pagespeed_data,
                'reviews_top'   => $scan_data['reviews_top'],
                'reviews_low'   => $scan_data['reviews_low'],
            ];
            FI_Rate_Limiter::increment( $ip );
            return self::format_result( $report, $temp_scan, '' );
        }

        $hours      = (int) get_option( 'fi_cache_duration', 24 );
        $expires    = gmdate( 'Y-m-d H:i:s', time() + ( $hours * HOUR_IN_SECONDS ) );

        // Strip internal accounting keys before persisting — they are only needed
        // in memory during the current request and must not leak to the frontend
        // via report_json or bloat the stored payload.
        $report_to_store = $report;
        unset( $report_to_store['_tokens'] );

        // Strip high-churn subkeys from _meta before persisting. Photos use
        // Google CDN references that expire, pagespeed scores change over time,
        // and raw reviews are large. The scan row already stores structured data
        // separately; these _meta keys are only needed to render the immediate
        // AJAX response and should not bloat report_json long-term.
        if ( isset( $report_to_store['_meta'] ) ) {
            unset(
                $report_to_store['_meta']['photos'],
                $report_to_store['_meta']['pagespeed'],
                $report_to_store['_meta']['reviews_top'],
                $report_to_store['_meta']['reviews_low']
            );
        }

        $scan_db_id = FI_DB::upsert_scan( [
            'place_id'      => $place_id,
            'business_name' => $scan_data['name'],
            'category'      => $taxonomy['label'],
            'address'       => $scan_data['address'],
            'website'       => $scan_data['website'],
            'phone'         => $scan_data['phone'],
            'overall_score' => $report['overall_score'] ?? 0,
            'report_json'   => wp_json_encode( $report_to_store ),
            'scanned_at'    => gmdate( 'Y-m-d H:i:s' ),
            'expires_at'    => $expires,
        ] );

        // ── 8. Share link ─────────────────────────────────────────────────
        $share_url = FI_Share::create_or_get( $scan_db_id );

        FI_Rate_Limiter::increment( $ip );
        FI_Logger::info( 'Scan complete. Score: ' . ( $report['overall_score'] ?? 0 ), [], $scan_id );

        $scan_obj = (object) [
            'id'            => $scan_db_id,
            'business_name' => $scan_data['name'],
            'address'       => $scan_data['address'],
            'phone'         => $scan_data['phone'],
            'website'       => $scan_data['website'],
            'description'   => $scan_data['editorial_summary'] ?? '',
            'overall_score' => $report['overall_score'] ?? 0,
            'category'      => $taxonomy['label'],
            'parent'        => $taxonomy['parent'],
            'vague_match'   => FI_Taxonomy::is_vague_match( $taxonomy ),
            'search_type'   => $taxonomy['search_type'],
            'photos'        => array_slice( $details['photos'] ?? [], 0, 10 ),
            'hours'         => $details['opening_hours']['weekday_text'] ?? [],
            'price_level'   => $details['price_level'] ?? null,
        ];

        return self::format_result( $report, $scan_obj, $share_url );
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Fetch all external data for a place: details, website health, PageSpeed, competitors.
     * Returns [ $details, $website_data, $competitors, $pagespeed_data ] or WP_Error.
     */
    private static function fetch_external_data( string $place_id, string $scan_id ): array|WP_Error {
        $details = FI_Google::details( $place_id, $scan_id );
        if ( ! $details ) {
            FI_Logger::error( "Google details failed for: $place_id", [], $scan_id );
            return new WP_Error( 'google_failed', 'Could not retrieve business data. Check your Google API key.' );
        }

        $website_data   = null;
        $pagespeed_data = null;
        if ( ! empty( $details['website'] ) ) {
            $website_data   = FI_Google::website_health( $details['website'], $scan_id );
            $pagespeed_data = FI_Google::pagespeed( $details['website'], $scan_id );
        }

        $competitors = [];
        $geo = $details['geometry']['location'] ?? null;
        if ( $geo ) {
            $resolved    = FI_Taxonomy::resolve( $details['types'] ?? [] );
            $search_type = $resolved['search_type'];
            $competitors = FI_Google::competitors( $geo['lat'], $geo['lng'], $search_type, $scan_id );
        }

        return [ $details, $website_data, $competitors, $pagespeed_data ];
    }

    /**
     * Build the normalised scan-data array passed to FI_Grader.
     * Add new data sources here.
     */
    private static function build_scan_data(
        array $details,
        string $fallback_name,
        ?array $website_data,
        array $competitors,
        ?array $pagespeed_data
    ): array {
        // ── Taxonomy resolution ───────────────────────────────────────────────
        $raw_types = $details['types'] ?? [];
        $taxonomy  = FI_Taxonomy::resolve( $raw_types );

        // ── Review sorting ────────────────────────────────────────────────────
        // Google returns up to 5 reviews sorted by relevance (their algorithm).
        // We sort by rating so Claude receives best and worst clearly labelled.
        $all_reviews = $details['reviews'] ?? [];
        usort( $all_reviews, fn( $a, $b ) => ( $b['rating'] ?? 0 ) <=> ( $a['rating'] ?? 0 ) );

        $top_reviews = array_slice( $all_reviews, 0, 3 );

        // Only show reviews with rating <= 3 as "lowest-rated".
        // Google often returns only 4-5 star reviews, so reversing the sorted
        // list would show 4-star reviews in the "worst" slot — misleading.
        $low_reviews = array_values( array_filter(
            $all_reviews,
            fn( $r ) => ( $r['rating'] ?? 5 ) <= 3
        ) );

        // Remove duplicates between top and low (tiny review samples can overlap).
        // Use author_name — the normalised shape doesn't include author_url.
        $top_names  = array_column( array_map(
            fn( $r ) => [ 'author_name' => $r['author_name'] ?? '' ],
            $top_reviews
        ), 'author_name' );
        $low_reviews = array_values( array_filter(
            $low_reviews,
            fn( $r ) => ! in_array( $r['author_name'] ?? '', $top_names, true )
        ) );

        // Normalise review shape for Claude — only what it needs
        $fmt_review = fn( $r ) => [
            'author'    => $r['author_name']               ?? 'Anonymous',
            'rating'    => $r['rating']                    ?? null,
            'text'      => mb_strimwidth( $r['text'] ?? '', 0, 280, '…' ),
            'time_ago'  => $r['relative_time_description'] ?? '',
        ];

        return [
            'name'               => $details['name'] ?? $fallback_name,
            'address'            => $details['formatted_address'] ?? '',
            'phone'              => $details['formatted_phone_number'] ?? '',
            'website'            => $details['website'] ?? '',
            'rating'             => $details['rating'] ?? null,
            'reviews'            => $details['user_ratings_total'] ?? 0,
            'price_level'        => $details['price_level'] ?? null,
            'types'              => $raw_types,
            'industry'           => [
                'category'        => $taxonomy['label'],
                'parent'          => $taxonomy['parent'],
                'search_type_used'=> $taxonomy['search_type'],
                'vague_match'     => FI_Taxonomy::is_vague_match( $taxonomy ),
            ],
            'hours'              => $details['opening_hours'] ?? [],
            'reviews_top'        => array_map( $fmt_review, $top_reviews ),
            'reviews_low'        => array_map( $fmt_review, $low_reviews ),
            'photos_count'       => count( array_slice( $details['photos'] ?? [], 0, 10 ) ),
            'attributes'         => self::extract_attributes( $details ),
            'editorial_summary'  => $details['editorial_summary']['overview'] ?? '',
            'website_health'     => $website_data,
            'pagespeed'          => $pagespeed_data,
            'competitors'        => array_map( fn( $c ) => [
                'name'        => $c['name']                 ?? '',
                'rating'      => $c['rating']               ?? null,
                'reviews'     => $c['user_ratings_total']   ?? 0,
                'address'     => $c['vicinity']             ?? '',
                'price_level' => $c['price_level']          ?? null,
                'open_now'    => $c['opening_hours']['open_now'] ?? null,
                'types'       => array_slice( $c['types']   ?? [], 0, 3 ),
            ], $competitors ),
        ];
    }

    /**
     * Extract boolean attribute flags from a Google Place details array.
     */
    private static function extract_attributes( array $details ): array {
        $flags = [
            'wheelchair_accessible_entrance' => 'Wheelchair accessible',
            'delivery'                       => 'Delivery',
            'takeout'                        => 'Takeout',
            'dine_in'                        => 'Dine-in',
            'reservable'                     => 'Reservations',
            'serves_beer'                    => 'Beer',
            'serves_wine'                    => 'Wine',
            'serves_breakfast'               => 'Breakfast',
            'serves_brunch'                  => 'Brunch',
            'serves_lunch'                   => 'Lunch',
            'serves_dinner'                  => 'Dinner',
            'serves_vegetarian_food'         => 'Vegetarian options',
            'curbside_pickup'                => 'Curbside pickup',
        ];

        $attrs = [];
        foreach ( $flags as $key => $label ) {
            if ( ! empty( $details[ $key ] ) ) {
                $attrs[] = $label;
            }
        }
        return $attrs;
    }

    /**
     * Standardise the return shape for both cache-hit and fresh-scan paths.
     */
    private static function format_result( array $report, object $scan, string $share_url ): array {
        $meta = $report['_meta'] ?? [];

        // Remove internal accounting keys — these are only meaningful server-side
        // and must never reach the frontend or be stored in cached responses.
        unset( $report['_tokens'] );

        return [
            'report'    => $report,
            'scan'      => [
                'id'            => $scan->id,
                'business_name' => $meta['name']         ?? $scan->business_name,
                'address'       => $meta['address']      ?? $scan->address       ?? '',
                'phone'         => $meta['phone']        ?? $scan->phone         ?? '',
                'website'       => $meta['website']      ?? $scan->website       ?? '',
                'description'   => $meta['description']  ?? $scan->description   ?? '',
                'overall_score' => $scan->overall_score,
                'category'      => $meta['category']     ?? $scan->category      ?? '',
                'parent'        => $meta['parent']       ?? $scan->parent        ?? '',
                'vague_match'   => $meta['vague_match']  ?? $scan->vague_match   ?? false,
                'search_type'   => $meta['search_type']  ?? $scan->search_type   ?? '',
                'photos'        => $meta['photos']       ?? $scan->photos        ?? [],
                'hours'         => $meta['hours']        ?? $scan->hours         ?? [],
                'price_level'   => $meta['price_level']  ?? $scan->price_level   ?? null,
                'pagespeed'     => $meta['pagespeed']    ?? null,
                'reviews_top'   => $meta['reviews_top']  ?? [],
                'reviews_low'   => $meta['reviews_low']  ?? [],
            ],
            'share_url' => $share_url,
        ];
    }
}
