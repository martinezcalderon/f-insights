<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FI_Google
 * All outbound calls to Google APIs.
 *
 * API surface used:
 *   Places API (New) — places.googleapis.com/v1
 *     POST /places:searchText     → search()
 *     GET  /places/{id}           → details(), geocode()
 *     POST /places:searchNearby   → competitors()
 *     POST /places:autocomplete   → autocomplete()
 *
 *   PageSpeed Insights API v5 — www.googleapis.com/pagespeedonline/v5
 *     (unchanged — same key, separate product)
 *
 *   Website health — raw wp_remote_get to the business's own URL
 *
 * Places API (New) vs legacy key differences:
 *   Base URL  : places.googleapis.com/v1  (not maps.googleapis.com/maps/api/place)
 *   Auth      : X-Goog-Api-Key header
 *   Fields    : X-Goog-FieldMask header   (replaces ?fields= query param)
 *   Place IDs : same format; resource name = "places/ChIJ..." — strip prefix for bare ID
 *   Responses : different shapes — see normalise_place() for full field-by-field mapping
 *
 * Google Cloud Console: enable "Places API (New)" — NOT the legacy "Places API".
 */
class FI_Google {

    private const PLACES_BASE = 'https://places.googleapis.com/v1/places';

    private const DETAILS_FIELDS_BASIC = [
        'id', 'displayName', 'formattedAddress', 'nationalPhoneNumber',
        'internationalPhoneNumber', 'websiteUri', 'businessStatus', 'types',
        'primaryType', 'location', 'viewport', 'iconMaskBaseUri',
    ];
    private const DETAILS_FIELDS_ADVANCED = [
        'rating', 'userRatingCount', 'currentOpeningHours',
        'regularOpeningHours', 'editorialSummary',
    ];
    private const DETAILS_FIELDS_PREFERRED = [ 'reviews', 'photos' ];
    private const DETAILS_FIELDS_ATMOSPHERE = [
        'priceLevel', 'accessibilityOptions', 'goodForChildren', 'goodForGroups',
        'allowsDogs', 'curbsidePickup', 'delivery', 'dineIn', 'reservable',
        'servesBeer', 'servesBreakfast', 'servesBrunch', 'servesDinner',
        'servesLunch', 'servesVegetarianFood', 'servesWine', 'takeout',
    ];

    // ── Internal helpers ──────────────────────────────────────────────────────

    private static function key(): string {
        return (string) get_option( 'fi_google_api_key', '' );
    }

    private static function post( string $endpoint, array $body, string $mask, string $scan_id = '' ): ?array {
        $key = self::key();
        if ( ! $key ) return null;

        $url = self::PLACES_BASE . $endpoint;
        FI_Logger::api( 'Google POST ' . $url . ' mask=' . $mask, [ 'body_keys' => array_keys( $body ) ], $scan_id );

        $response = wp_remote_post( $url, [
            'timeout' => 15,
            'headers' => [
                'Content-Type'     => 'application/json',
                'X-Goog-Api-Key'   => $key,
                'X-Goog-FieldMask' => $mask,
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            FI_Logger::error( 'Google POST failed: ' . $response->get_error_message(), [], $scan_id );
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        FI_Logger::api( "Google POST response HTTP $code", [], $scan_id );

        if ( $code !== 200 ) {
            FI_Logger::error( "Google POST HTTP $code", $data ?? [], $scan_id );
            return null;
        }

        return $data;
    }

    private static function get( string $place_id, string $mask, string $scan_id = '' ): ?array {
        $key = self::key();
        if ( ! $key ) return null;

        $url = self::PLACES_BASE . '/' . rawurlencode( $place_id );
        FI_Logger::api( 'Google GET ' . $url, [], $scan_id );

        $response = wp_remote_get( $url, [
            'timeout' => 15,
            'headers' => [
                'X-Goog-Api-Key'   => $key,
                'X-Goog-FieldMask' => $mask,
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            FI_Logger::error( 'Google GET failed: ' . $response->get_error_message(), [], $scan_id );
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        FI_Logger::api( "Google GET response HTTP $code", [], $scan_id );

        if ( $code !== 200 ) {
            FI_Logger::error( "Google GET HTTP $code", $data ?? [], $scan_id );
            return null;
        }

        return $data;
    }

    private static function extract_place_id( string $resource_name ): string {
        return str_starts_with( $resource_name, 'places/' )
            ? substr( $resource_name, strlen( 'places/' ) )
            : $resource_name;
    }

    /**
     * Normalise a Places API (New) place object to the legacy-compatible shape.
     *
     * Legacy key                 → New API field
     * -------------------------------------------
     * place_id                   → id (or extracted from "name" resource path)
     * name                       → displayName.text
     * formatted_address          → formattedAddress
     * formatted_phone_number     → nationalPhoneNumber
     * website                    → websiteUri
     * rating                     → rating
     * user_ratings_total         → userRatingCount
     * reviews[]                  → reviews[] (shape normalised below)
     * photos[]                   → photos[]  (shape normalised below)
     * opening_hours              → currentOpeningHours / regularOpeningHours
     * price_level (int 0–4)      → priceLevel (string enum → int via map)
     * types[]                    → types[]
     * business_status            → businessStatus
     * editorial_summary.overview → editorialSummary.text
     * geometry.location.lat/lng  → location.latitude / location.longitude
     * wheelchair_accessible…     → accessibilityOptions.wheelchairAccessibleEntrance
     */
    private static function normalise_place( array $p ): array {

        $place_id = $p['id'] ?? '';
        if ( empty( $place_id ) && ! empty( $p['name'] ) ) {
            $place_id = self::extract_place_id( (string) $p['name'] );
        }

        // Photos: legacy photo_reference = new name (resource path)
        $photos = [];
        foreach ( $p['photos'] ?? [] as $ph ) {
            $photos[] = [
                'photo_reference' => $ph['name']     ?? '',
                'height'          => $ph['heightPx'] ?? 0,
                'width'           => $ph['widthPx']  ?? 0,
            ];
        }

        // Reviews: author name, text, and time live in nested objects in the new API
        $reviews = [];
        foreach ( $p['reviews'] ?? [] as $r ) {
            $reviews[] = [
                'author_name'               => $r['authorAttribution']['displayName'] ?? '',
                'rating'                    => $r['rating'] ?? 0,
                'text'                      => $r['text']['text'] ?? '',
                'relative_time_description' => $r['relativePublishTimeDescription'] ?? '',
                'time'                      => isset( $r['publishTime'] ) ? strtotime( $r['publishTime'] ) : 0,
            ];
        }

        // Opening hours: openNow and weekdayDescriptions
        $opening_hours = null;
        $hours_src     = $p['currentOpeningHours'] ?? $p['regularOpeningHours'] ?? null;
        if ( $hours_src ) {
            $opening_hours = [
                'open_now'     => $hours_src['openNow']             ?? null,
                'weekday_text' => $hours_src['weekdayDescriptions'] ?? [],
            ];
        }

        // Price level: string enum → int
        static $price_map = [
            'PRICE_LEVEL_FREE'           => 0,
            'PRICE_LEVEL_INEXPENSIVE'    => 1,
            'PRICE_LEVEL_MODERATE'       => 2,
            'PRICE_LEVEL_EXPENSIVE'      => 3,
            'PRICE_LEVEL_VERY_EXPENSIVE' => 4,
        ];
        $price_level = isset( $p['priceLevel'] ) ? ( $price_map[ $p['priceLevel'] ] ?? null ) : null;

        // Geometry: latitude/longitude → legacy lat/lng
        $geometry = null;
        if ( ! empty( $p['location'] ) ) {
            $geometry = [
                'location' => [
                    'lat' => (float) ( $p['location']['latitude']  ?? 0 ),
                    'lng' => (float) ( $p['location']['longitude'] ?? 0 ),
                ],
            ];
        }

        return [
            'place_id'                       => $place_id,
            'name'                           => $p['displayName']['text']           ?? '',
            'formatted_address'              => $p['formattedAddress']              ?? '',
            'formatted_phone_number'         => $p['nationalPhoneNumber']           ?? ( $p['internationalPhoneNumber'] ?? '' ),
            'website'                        => $p['websiteUri']                    ?? '',
            'rating'                         => $p['rating']                        ?? null,
            'user_ratings_total'             => $p['userRatingCount']               ?? 0,
            'reviews'                        => $reviews,
            'photos'                         => $photos,
            'opening_hours'                  => $opening_hours,
            'price_level'                    => $price_level,
            'types'                          => $p['types']                         ?? [],
            'business_status'                => $p['businessStatus']                ?? '',
            'editorial_summary'              => [ 'overview' => $p['editorialSummary']['text'] ?? '' ],
            'geometry'                       => $geometry,
            'wheelchair_accessible_entrance' => $p['accessibilityOptions']['wheelchairAccessibleEntrance'] ?? null,
            'serves_beer'            => $p['servesBeer']           ?? null,
            'serves_breakfast'       => $p['servesBreakfast']      ?? null,
            'serves_brunch'          => $p['servesBrunch']         ?? null,
            'serves_dinner'          => $p['servesDinner']         ?? null,
            'serves_lunch'           => $p['servesLunch']          ?? null,
            'serves_vegetarian_food' => $p['servesVegetarianFood'] ?? null,
            'serves_wine'            => $p['servesWine']           ?? null,
            'takeout'                => $p['takeout']              ?? null,
            'delivery'               => $p['delivery']             ?? null,
            'dine_in'                => $p['dineIn']               ?? null,
            'reservable'             => $p['reservable']           ?? null,
            'curbside_pickup'        => $p['curbsidePickup']       ?? null,
        ];
    }

    // ── Public API methods ────────────────────────────────────────────────────

    /**
     * Text search — find the best matching Place ID for a business name.
     * POST /places:searchText
     */
    public static function search( string $name, string $scan_id = '' ): ?string {
        $data = self::post(
            ':searchText',
            [ 'textQuery' => $name, 'maxResultCount' => 1 ],
            'places.id',
            $scan_id
        );

        return $data['places'][0]['id'] ?? null;
    }

    /**
     * Place details — full profile for a Place ID.
     * GET /places/{id}
     * Returns a normalised legacy-compatible array.
     */
    public static function details( string $place_id, string $scan_id = '' ): ?array {
        $mask = implode( ',', array_unique( array_merge(
            self::DETAILS_FIELDS_BASIC,
            self::DETAILS_FIELDS_ADVANCED,
            self::DETAILS_FIELDS_PREFERRED,
            self::DETAILS_FIELDS_ATMOSPHERE
        ) ) );

        $data = self::get( $place_id, $mask, $scan_id );
        return $data ? self::normalise_place( $data ) : null;
    }

    /**
     * Nearby competitors — same primary type within configured radius.
     * POST /places:searchNearby
     */
    public static function competitors( float $lat, float $lng, string $type, string $scan_id = '' ): array {
        $radius = (int) get_option( 'fi_competitor_radius', 5 );
        $meters = (float) ( $radius * 1609 );

        $data = self::post(
            ':searchNearby',
            [
                'includedTypes'  => [ $type ],
                'maxResultCount' => 6,
                'locationRestriction' => [
                    'circle' => [
                        'center' => [ 'latitude' => $lat, 'longitude' => $lng ],
                        'radius' => $meters,
                    ],
                ],
            ],
            'places.id,places.displayName,places.formattedAddress,places.rating,places.userRatingCount,places.types,places.businessStatus,places.priceLevel',
            $scan_id
        );

        if ( empty( $data['places'] ) ) return [];

        return array_slice(
            array_map( [ __CLASS__, 'normalise_place' ], $data['places'] ),
            0, 5
        );
    }

    /**
     * Autocomplete — suggest businesses matching a partial name query.
     * POST /places:autocomplete
     *
     * Purpose-built for type-ahead UX, replacing the previous
     * textsearch-as-autocomplete workaround. Key improvements:
     *   - Optimised for partial queries; lower latency
     *   - Returns ranked suggestions, not full search result pages
     *   - Soft location bias (doesn't hard-filter by radius)
     *   - Restricted to establishments only (no address/geocode noise)
     */
    public static function autocomplete( string $query, float $lat = 0, float $lng = 0, string $scan_id = '' ): array {
        if ( strlen( $query ) < 2 ) return [];

        $radius = (int) get_option( 'fi_autocomplete_radius', 10 );
        $meters = (float) ( $radius * 1609 );

        $body = [
            'input'                => $query,
            'includedPrimaryTypes' => [ 'establishment' ],
        ];

        if ( $lat && $lng ) {
            $body['locationBias'] = [
                'circle' => [
                    'center' => [ 'latitude' => $lat, 'longitude' => $lng ],
                    'radius' => $meters,
                ],
            ];
        }

        // Autocomplete endpoint ignores X-Goog-FieldMask; '*' is required by the API.
        $data = self::post( ':autocomplete', $body, '*', $scan_id );

        if ( empty( $data['suggestions'] ) ) return [];

        $results = [];
        foreach ( array_slice( $data['suggestions'], 0, 6 ) as $s ) {
            $pred = $s['placePrediction'] ?? null;
            if ( ! $pred ) continue;

            $place_id = ! empty( $pred['placeId'] )
                ? $pred['placeId']
                : self::extract_place_id( (string) ( $pred['place'] ?? '' ) );

            if ( ! $place_id ) continue;

            // structuredFormat gives a clean split: main text = business name,
            // secondary text = address/city
            $name    = $pred['structuredFormat']['mainText']['text']      ?? ( $pred['text']['text'] ?? '' );
            $address = $pred['structuredFormat']['secondaryText']['text'] ?? '';

            $results[] = compact( 'place_id', 'name', 'address' );
        }

        return $results;
    }

    /**
     * Geocode a Place ID to get lat/lng.
     * GET /places/{id} with location field only.
     */
    public static function geocode( string $place_id, string $scan_id = '' ): ?array {
        $data = self::get( $place_id, 'location', $scan_id );

        if ( empty( $data['location'] ) ) return null;

        return [
            'lat' => (float) ( $data['location']['latitude']  ?? 0 ),
            'lng' => (float) ( $data['location']['longitude'] ?? 0 ),
        ];
    }

    /**
     * Website health check — fetch the business's own URL and inspect headers + HTML.
     * No Google API involved; unchanged from previous version.
     */
    public static function website_health( string $url, string $scan_id = '' ): ?array {
        if ( ! $url ) return null;

        FI_Logger::api( "Website health check: $url", [], $scan_id );

        $start    = microtime( true );
        $response = wp_remote_get( $url, [
            'timeout'    => 10,
            'user-agent' => 'Mozilla/5.0 (compatible; FInsights/2.0)',
            'sslverify'  => (bool) apply_filters( 'fi_website_check_sslverify', true ),
        ] );
        $load_time = round( ( microtime( true ) - $start ) * 1000 );

        if ( is_wp_error( $response ) ) return null;

        $code    = wp_remote_retrieve_response_code( $response );
        $headers = wp_remote_retrieve_headers( $response );
        $body    = wp_remote_retrieve_body( $response );

        return [
            'load_time_ms'      => $load_time,
            'status_code'       => $code,
            'has_ssl'           => str_starts_with( $url, 'https://' ),
            'has_compression'   => ! empty( $headers['content-encoding'] ),
            'has_cache_control' => ! empty( $headers['cache-control'] ),
            'has_hsts'          => ! empty( $headers['strict-transport-security'] ),
            'has_x_frame'       => ! empty( $headers['x-frame-options'] ),
            'has_csp'           => ! empty( $headers['content-security-policy'] ),
            'has_viewport'      => (bool) preg_match( '/name=["\']viewport["\']/', $body ),
            'has_h1'            => (bool) preg_match( '/<h1[\s>]/i', $body ),
            'has_meta_desc'     => (bool) preg_match( '/name=["\']description["\'].*content=/i', $body ),
            'has_og_tags'       => (bool) preg_match( '/property=["\']og:/i', $body ),
            'has_schema'        => (bool) preg_match( '/application\/ld\+json/i', $body ),
            'has_lazy_loading'  => (bool) preg_match( '/loading=["\']lazy["\']/', $body ),
            'has_modern_images' => (bool) preg_match( '/\.(webp|avif)/i', $body ),
        ];
    }

    /**
     * PageSpeed Insights — Core Web Vitals + Lighthouse scores for mobile + desktop.
     * Uses PageSpeed Insights API v5 (unchanged — same key, separate product).
     */
    public static function pagespeed( string $url, string $scan_id = '' ): ?array {
        $key = self::key();
        if ( ! $key || ! $url ) return null;

        FI_Logger::api( "PageSpeed fetch: $url", [], $scan_id );

        // These two calls are sequential — PHP cannot parallelize them.
        // Timeout is 20s each (total worst-case 40s, down from 60s).
        // Mobile is fetched first; if it fails we skip desktop — no point
        // spending another 20s when the API or the target site is unresponsive.
        $strategies = [ 'mobile', 'desktop' ];
        $results    = [];

        foreach ( $strategies as $strategy ) {
            $api_url = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed'
                . '?url='      . urlencode( $url )
                . '&strategy=' . $strategy
                . '&category=performance'
                . '&category=accessibility'
                . '&category=best-practices'
                . '&category=seo'
                . '&key='      . urlencode( $key );

            $response = wp_remote_get( $api_url, [ 'timeout' => 20 ] );

            if ( is_wp_error( $response ) ) {
                FI_Logger::warn( "PageSpeed $strategy failed: " . $response->get_error_message(), [], $scan_id );
                // If mobile fails (first), skip desktop — the site or API is unreachable.
                break;
            }

            $code = wp_remote_retrieve_response_code( $response );
            $body = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( $code !== 200 || empty( $body['lighthouseResult']['categories'] ) ) {
                FI_Logger::warn( "PageSpeed $strategy HTTP $code", [], $scan_id );
                // On mobile failure, no useful signal; skip desktop.
                if ( $strategy === 'mobile' ) break;
                continue;
            }

            $cats   = $body['lighthouseResult']['categories'];
            $audits = $body['lighthouseResult']['audits'] ?? [];

            $results[ $strategy ] = [
                'performance'    => isset( $cats['performance']['score']    ) ? round( $cats['performance']['score']    * 100 ) : null,
                'accessibility'  => isset( $cats['accessibility']['score']  ) ? round( $cats['accessibility']['score']  * 100 ) : null,
                'best_practices' => isset( $cats['best-practices']['score'] ) ? round( $cats['best-practices']['score'] * 100 ) : null,
                'seo'            => isset( $cats['seo']['score']            ) ? round( $cats['seo']['score']            * 100 ) : null,
                'cwv'            => [
                    'fcp' => $audits['first-contentful-paint']['displayValue']   ?? null,
                    'lcp' => $audits['largest-contentful-paint']['displayValue'] ?? null,
                    'tbt' => $audits['total-blocking-time']['displayValue']      ?? null,
                    'cls' => $audits['cumulative-layout-shift']['displayValue']  ?? null,
                    'si'  => $audits['speed-index']['displayValue']              ?? null,
                    'tti' => $audits['interactive']['displayValue']              ?? null,
                ],
            ];
        }

        if ( empty( $results ) ) return null;

        FI_Logger::api( 'PageSpeed complete', [ 'strategies' => array_keys( $results ) ], $scan_id );
        return $results;
    }

    /**
     * Test a Google API key using a minimal Text Search.
     * POST /places:searchText — uses Places API (New).
     *
     * @return array [ 'ok' => bool, 'message' => string ]
     */
    public static function test_connection( string $key ): array {
        if ( ! $key ) return [ 'ok' => false, 'message' => 'No key provided.' ];

        $response = wp_remote_post( self::PLACES_BASE . ':searchText', [
            'timeout' => 10,
            'headers' => [
                'Content-Type'     => 'application/json',
                'X-Goog-Api-Key'   => $key,
                'X-Goog-FieldMask' => 'places.id',
            ],
            'body' => wp_json_encode( [ 'textQuery' => 'test', 'maxResultCount' => 1 ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'ok' => false, 'message' => $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        // 200 with any body = key is valid and Places API (New) is enabled.
        if ( $code === 200 ) {
            return [ 'ok' => true, 'message' => 'Connected; Places API (New) verified.' ];
        }

        $msg = $body['error']['message'] ?? ( $body['error']['status'] ?? "HTTP $code" );
        return [ 'ok' => false, 'message' => $msg ];
    }
}
