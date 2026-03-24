<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FI_Reviews
 *
 * Business logic for the Reviews page (Market Leads > Reviews).
 *
 * A Reviews record is created when an admin marks a lead or prospect as
 * Closed and clicks "Set Up Reviews". It is never created automatically.
 * Scan data (Place ID, business name, review URL) is pre-filled from the
 * fi_scans row tied to the lead; the admin configures the rest.
 *
 * One snippet is generated per record. The snippet is a hosted JS tag that
 * reads the record's enabled features and renders accordingly. Validation
 * (license + domain) happens on each snippet load server-side.
 *
 * Analytics (views vs clicks per tracking surface) are stored locally on
 * the client's own WordPress install. fricking.website only receives
 * analytics for snippets it directly attributed (direct inquiries and
 * fallback/lapsed states).
 */
class FI_Reviews {

    // -------------------------------------------------------------------------
    // Feature flag keys — each maps to a column in fi_review_records
    // Adding a new toggle = add it here and to create_table()
    // -------------------------------------------------------------------------
    const FEATURES = [
        'feature_review_button',
        'feature_qr_display',
        'feature_multi_location',
        'feature_display_widget',
        'feature_attribution',
    ];

    const LAYOUTS = [ 'list', 'grid' ];
    const SORTS   = [ 'newest', 'highest', 'relevant' ];

    // -------------------------------------------------------------------------
    // Table name helper
    // -------------------------------------------------------------------------
    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'fi_review_records';
    }

    public static function table_tracking(): string {
        global $wpdb;
        return $wpdb->prefix . 'fi_review_tracking';
    }

    // -------------------------------------------------------------------------
    // Schema — called from FI_DB::create_tables() on version bump
    // -------------------------------------------------------------------------
    public static function create_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $rt      = self::table();
        $tt      = self::table_tracking();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // One row per local business client relationship
        dbDelta( "CREATE TABLE {$rt} (
            id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            lead_id                 BIGINT UNSIGNED NOT NULL,
            place_id                VARCHAR(255)    NOT NULL DEFAULT '',
            business_name           VARCHAR(255)    NOT NULL DEFAULT '',
            domain                  VARCHAR(500)    NOT NULL DEFAULT '',
            label                   VARCHAR(255)    NOT NULL DEFAULT '',
            notes                   TEXT            NULL,
            review_url              VARCHAR(2048)   NOT NULL DEFAULT '',
            snippet_token           VARCHAR(64)     NOT NULL DEFAULT '',

            -- Feature toggles
            feature_review_button   TINYINT(1)      NOT NULL DEFAULT 1,
            feature_qr_display      TINYINT(1)      NOT NULL DEFAULT 0,
            feature_multi_location  TINYINT(1)      NOT NULL DEFAULT 0,
            feature_display_widget  TINYINT(1)      NOT NULL DEFAULT 0,
            feature_attribution     TINYINT(1)      NOT NULL DEFAULT 1,

            -- Display widget config
            display_count           TINYINT UNSIGNED NOT NULL DEFAULT 5,
            display_layout          ENUM('list','grid') NOT NULL DEFAULT 'list',
            display_min_stars       TINYINT UNSIGNED NOT NULL DEFAULT 1,
            display_sort            ENUM('newest','highest','relevant') NOT NULL DEFAULT 'newest',

            -- Branding overrides (NULL = inherit from White Label settings)
            attribution_text        VARCHAR(255)    NULL,
            attribution_url         VARCHAR(2048)   NULL,
            button_text             VARCHAR(255)    NULL,
            button_color            VARCHAR(32)     NULL,

            -- Multi-location JSON: [{place_id, name, review_url}]
            locations_json          TEXT            NULL,

            -- Status
            status                  ENUM('active','archived') NOT NULL DEFAULT 'active',
            last_seen_at            DATETIME        NULL,

            created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY  (id),
            UNIQUE KEY   snippet_token (snippet_token),
            KEY          lead_id (lead_id),
            KEY          place_id (place_id),
            KEY          status (status),
            KEY          domain (domain(191))
        ) {$charset};" );

        // One row per tracking surface per record
        // surface examples: 'website_button', 'qr_display', 'email_template', 'qr_print'
        dbDelta( "CREATE TABLE {$tt} (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            record_id   BIGINT UNSIGNED NOT NULL,
            surface     VARCHAR(100)    NOT NULL DEFAULT '',
            label       VARCHAR(255)    NOT NULL DEFAULT '',
            param       VARCHAR(100)    NOT NULL DEFAULT '',
            views       BIGINT UNSIGNED NOT NULL DEFAULT 0,
            clicks      BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            KEY record_id (record_id),
            UNIQUE KEY record_surface (record_id, param)
        ) {$charset};" );
    }

    // -------------------------------------------------------------------------
    // Create a record from a closed lead
    // -------------------------------------------------------------------------
    public static function create_from_lead( int $lead_id ): int {
        global $wpdb;

        // Bail if already exists for this lead
        $existing = $wpdb->get_var( $wpdb->prepare(
            'SELECT id FROM ' . self::table() . ' WHERE lead_id = %d LIMIT 1',
            $lead_id
        ) );
        if ( $existing ) return (int) $existing;

        // Pull lead and its scan
        $lead = FI_DB::get_leads( [ 'id' => $lead_id, 'limit' => 1 ] )[0] ?? null;
        if ( ! $lead ) return 0;

        $scan = $lead->scan_id ? FI_DB::get_scan_by_id( (int) $lead->scan_id ) : null;

        $place_id    = $scan->place_id    ?? '';
        $review_url  = self::build_review_url( $place_id );
        $domain      = $scan->website ?? '';
        if ( $domain ) {
            $parsed = wp_parse_url( $domain );
            $domain = $parsed['host'] ?? $domain;
        }

        $token = self::generate_token();

        $result = $wpdb->insert(
            self::table(),
            [
                'lead_id'      => $lead_id,
                'place_id'     => $place_id,
                'business_name'=> sanitize_text_field( $lead->business_name ),
                'domain'       => sanitize_text_field( $domain ),
                'label'        => sanitize_text_field( $lead->business_name ),
                'review_url'   => esc_url_raw( $review_url ),
                'snippet_token'=> $token,
                'created_at'   => gmdate( 'Y-m-d H:i:s' ),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( ! $result ) return 0;

        // Auto-create default tracking surfaces
        $record_id = (int) $wpdb->insert_id;
        self::add_tracking_surface( $record_id, 'Website button',  'website_btn' );
        self::add_tracking_surface( $record_id, 'Email template',  'email_tpl' );

        return $record_id;
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------
    public static function get( int $id ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE id = %d LIMIT 1',
            $id
        ) );
    }

    public static function get_by_token( string $token ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE snippet_token = %s LIMIT 1',
            $token
        ) );
    }

    public static function get_by_lead( int $lead_id ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE lead_id = %d LIMIT 1',
            $lead_id
        ) );
    }

    /**
     * Get all active records with optional search/filter.
     */
    public static function get_all( array $args = [] ): array {
        global $wpdb;
        $t = self::table();

        $where  = [ '1=1' ];
        $values = [];

        $status = $args['status'] ?? 'active';
        $where[] = 'status = %s';
        $values[] = $status;

        if ( ! empty( $args['search'] ) ) {
            $like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[]  = '(business_name LIKE %s OR domain LIKE %s OR label LIKE %s)';
            $values[] = $like;
            $values[] = $like;
            $values[] = $like;
        }

        $limit  = (int) ( $args['limit']  ?? 50 );
        $offset = (int) ( $args['offset'] ?? 0 );

        $sql = "SELECT * FROM {$t} WHERE " . implode( ' AND ', $where )
             . " ORDER BY created_at DESC LIMIT %d OFFSET %d";

        $values[] = $limit;
        $values[] = $offset;

        return (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$values ) );
    }

    public static function count( string $status = 'active' ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::table() . ' WHERE status = %s',
            $status
        ) );
    }

    // -------------------------------------------------------------------------
    // Update
    // -------------------------------------------------------------------------
    public static function update( int $id, array $data ): bool {
        global $wpdb;

        $allowed = [
            'domain', 'label', 'notes', 'review_url',
            'feature_review_button', 'feature_qr_display',
            'feature_multi_location', 'feature_display_widget', 'feature_attribution',
            'display_count', 'display_layout', 'display_min_stars', 'display_sort',
            'attribution_text', 'attribution_url',
            'button_text', 'button_color',
            'locations_json', 'status',
        ];

        $clean = [];
        foreach ( $allowed as $key ) {
            if ( ! array_key_exists( $key, $data ) ) continue;
            $val = $data[ $key ];

            // Sanitize by type
            if ( str_starts_with( $key, 'feature_' ) ) {
                $clean[ $key ] = $val ? 1 : 0;
            } elseif ( in_array( $key, [ 'display_count', 'display_min_stars' ], true ) ) {
                $clean[ $key ] = max( 1, min( 100, (int) $val ) );
            } elseif ( $key === 'display_layout' ) {
                $clean[ $key ] = in_array( $val, self::LAYOUTS, true ) ? $val : 'list';
            } elseif ( $key === 'display_sort' ) {
                $clean[ $key ] = in_array( $val, self::SORTS, true ) ? $val : 'newest';
            } elseif ( in_array( $key, [ 'review_url', 'attribution_url' ], true ) ) {
                $clean[ $key ] = esc_url_raw( $val );
            } elseif ( $key === 'locations_json' ) {
                // Validate JSON array structure before storing
                $decoded = json_decode( $val, true );
                $clean[ $key ] = is_array( $decoded ) ? wp_json_encode( $decoded ) : null;
            } elseif ( $key === 'status' ) {
                $clean[ $key ] = in_array( $val, [ 'active', 'archived' ], true ) ? $val : 'active';
            } else {
                $clean[ $key ] = sanitize_text_field( $val );
            }
        }

        if ( empty( $clean ) ) return false;

        return (bool) $wpdb->update( self::table(), $clean, [ 'id' => $id ], null, [ '%d' ] );
    }

    // -------------------------------------------------------------------------
    // Archive (soft delete — snippet fires fallback, data retained)
    // -------------------------------------------------------------------------
    public static function archive( int $id ): bool {
        return self::update( $id, [ 'status' => 'archived' ] );
    }

    public static function restore( int $id ): bool {
        return self::update( $id, [ 'status' => 'active' ] );
    }

    // -------------------------------------------------------------------------
    // Tracking surfaces
    // -------------------------------------------------------------------------
    public static function add_tracking_surface( int $record_id, string $label, string $param ): int {
        global $wpdb;

        // Sanitize param to URL-safe alphanumeric + underscore
        $param = preg_replace( '/[^a-z0-9_]/', '_', strtolower( $param ) );
        $param = substr( $param, 0, 100 );

        $result = $wpdb->insert(
            self::table_tracking(),
            [
                'record_id'  => $record_id,
                'surface'    => sanitize_key( $param ),
                'label'      => sanitize_text_field( $label ),
                'param'      => $param,
                'created_at' => gmdate( 'Y-m-d H:i:s' ),
            ],
            [ '%d', '%s', '%s', '%s', '%s' ]
        );

        return $result ? (int) $wpdb->insert_id : 0;
    }

    public static function get_tracking_surfaces( int $record_id ): array {
        global $wpdb;
        return (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . self::table_tracking() . ' WHERE record_id = %d ORDER BY id ASC',
            $record_id
        ) );
    }

    public static function delete_tracking_surface( int $surface_id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( self::table_tracking(), [ 'id' => $surface_id ], [ '%d' ] );
    }

    /**
     * Record a view or click for a tracking surface.
     * Respects the IP exclusion list — excluded IPs are not counted.
     */
    public static function record_event( string $token, string $param, string $event ): bool {
        global $wpdb;

        if ( ! in_array( $event, [ 'view', 'click' ], true ) ) return false;
        if ( self::is_excluded_ip() ) return false;

        $record = self::get_by_token( $token );
        if ( ! $record || $record->status !== 'active' ) return false;

        $col = $event === 'view' ? 'views' : 'clicks';

        // $col is a hardcoded value from the ternary above — never user input.
        // Use two separate prepare() calls: one for the WHERE clause params,
        // one structural query for the column increment to keep intent explicit.
        $tt = self::table_tracking();
        if ( $col === 'views' ) {
            $rows = $wpdb->query( $wpdb->prepare(
                "UPDATE {$tt} SET views = views + 1 WHERE record_id = %d AND param = %s",
                (int) $record->id,
                sanitize_key( $param )
            ) );
        } else {
            $rows = $wpdb->query( $wpdb->prepare(
                "UPDATE {$tt} SET clicks = clicks + 1 WHERE record_id = %d AND param = %s",
                (int) $record->id,
                sanitize_key( $param )
            ) );
        }

        // Touch last_seen_at on any event
        if ( $rows ) {
            $wpdb->update(
                self::table(),
                [ 'last_seen_at' => gmdate( 'Y-m-d H:i:s' ) ],
                [ 'id' => (int) $record->id ],
                [ '%s' ],
                [ '%d' ]
            );
        }

        return (bool) $rows;
    }

    // -------------------------------------------------------------------------
    // Snippet generation
    // -------------------------------------------------------------------------

    /**
     * Build the script tag your client pastes onto the local business site.
     * fricking.website validates license + domain and serves the widget JS.
     */
    public static function generate_snippet( object $record ): string {
        $base = 'https://fricking.website/reviews/snippet.js';
        $src  = add_query_arg( 'token', rawurlencode( $record->snippet_token ), $base );
        return '<script src="' . esc_attr( $src ) . '" defer></script>';
    }

    /**
     * Build the direct Google review URL from a Place ID.
     * Format: https://search.google.com/local/writereview?placeid=PLACE_ID
     */
    public static function build_review_url( string $place_id ): string {
        if ( ! $place_id ) return '';
        return 'https://search.google.com/local/writereview?placeid=' . rawurlencode( $place_id );
    }

    /**
     * Build a tracking-tagged review URL for a specific surface.
     */
    public static function build_tracked_url( object $record, string $param ): string {
        if ( ! $record->review_url ) return '';
        return add_query_arg( 'fi_src', rawurlencode( $param ), $record->review_url );
    }

    // -------------------------------------------------------------------------
    // QR code — generated client-side via qrcode.js (MIT, loaded in admin)
    // Returns the review URL for use as a data attribute on the QR container.
    // The JS renders the actual QR into a canvas, then offers PNG download.
    // -------------------------------------------------------------------------
    public static function qr_review_url( object $record ): string {
        return $record->review_url ?? '';
    }

    // -------------------------------------------------------------------------
    // Email template
    // -------------------------------------------------------------------------
    public static function email_template( object $record ): string {
        $name = $record->business_name;
        $url  = $record->review_url;

        return "Subject: How was your experience with {$name}?\n\n"
             . "Hi [Customer First Name],\n\n"
             . "Thank you for choosing {$name}. We would love to hear about your experience.\n\n"
             . "If you have a moment, leaving us a Google review helps other customers find us "
             . "and means a great deal to our team.\n\n"
             . "Leave a review here:\n{$url}\n\n"
             . "It only takes a minute, and we read every one.\n\n"
             . "Thank you,\n"
             . "The {$name} Team";
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------
    private static function generate_token(): string {
        return bin2hex( random_bytes( 24 ) );
    }

    private static function is_excluded_ip(): bool {
        $excluded_raw = get_option( 'fi_excluded_ips', '' );
        if ( ! $excluded_raw ) return false;

        $excluded = array_filter( array_map( 'trim', explode( "\n", $excluded_raw ) ) );
        if ( empty( $excluded ) ) return false;

        $visitor_ip = sanitize_text_field(
            $_SERVER['HTTP_CF_CONNECTING_IP']
            ?? $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? ''
        );

        // Handle comma-separated forwarded IPs — take first
        if ( str_contains( $visitor_ip, ',' ) ) {
            $visitor_ip = trim( explode( ',', $visitor_ip )[0] );
        }

        return in_array( $visitor_ip, $excluded, true );
    }
}
