<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class FI_DB {

    public static function tables() {
        global $wpdb;
        return [
            'scans'        => $wpdb->prefix . 'fi_scans',
            'leads'        => $wpdb->prefix . 'fi_leads',
            'shares'       => $wpdb->prefix . 'fi_shares',
            'scan_jobs'    => $wpdb->prefix . 'fi_scan_jobs',
            'scan_queue'   => $wpdb->prefix . 'fi_scan_queue',
            'intel_assets' => $wpdb->prefix . 'fi_intel_assets',
        ];
    }

    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $t       = self::tables();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // ── Explicit column migrations ─────────────────────────────────────
        // dbDelta creates missing tables but does NOT add new columns to
        // existing tables. Any column added after the initial schema must be
        // migrated here with ADD COLUMN IF NOT EXISTS.
        $wpdb->query( "ALTER TABLE {$t['leads']}
            ADD COLUMN IF NOT EXISTS type           ENUM('lead','prospect') NOT NULL DEFAULT 'lead',
            ADD COLUMN IF NOT EXISTS source         ENUM('organic','bulk')  NOT NULL DEFAULT 'organic',
            ADD COLUMN IF NOT EXISTS follow_up_date DATE     NULL DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS reminded_at    DATETIME NULL DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS report_snapshot LONGTEXT NULL DEFAULT NULL" );

        // Expand status ENUM to include 'uncontacted' (for prospects)
        $wpdb->query( "ALTER TABLE {$t['leads']}
            MODIFY COLUMN status ENUM('uncontacted','new','contacted','qualified','closed','lost') DEFAULT 'new'" );

        // Scan jobs — error_note stores transient status messages (e.g. rate-limit backoff reason)
        $wpdb->query( "ALTER TABLE {$t['scan_jobs']}
            ADD COLUMN IF NOT EXISTS error_note VARCHAR(500) NULL DEFAULT NULL" );

        // Shares — place_id allows re-scan when the original scan row has been deleted
        $wpdb->query( "ALTER TABLE {$t['shares']}
            ADD COLUMN IF NOT EXISTS place_id VARCHAR(255) NULL DEFAULT NULL" );

        // Scan queue — index on scan_started_at for auto-kill-stuck queries
        // Uses IF NOT EXISTS syntax via a conditional check since MySQL < 8.0
        // doesn't support ADD INDEX IF NOT EXISTS directly.
        $existing_idx = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name   = %s
               AND index_name   = 'scan_started_at'",
            $t['scan_queue']
        ) );
        if ( ! $existing_idx ) {
            $wpdb->query( "ALTER TABLE {$t['scan_queue']} ADD INDEX scan_started_at (scan_started_at)" );
        }

        // Scans — every business scan, cached result, full report JSON
        dbDelta( "CREATE TABLE {$t['scans']} (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            place_id      VARCHAR(255)    NOT NULL,
            business_name VARCHAR(255)    NOT NULL,
            category      VARCHAR(255)    DEFAULT '',
            address       VARCHAR(500)    DEFAULT '',
            website       VARCHAR(500)    DEFAULT '',
            phone         VARCHAR(50)     DEFAULT '',
            overall_score TINYINT UNSIGNED DEFAULT 0,
            report_json   LONGTEXT        NOT NULL,
            scanned_at    DATETIME        NOT NULL,
            expires_at    DATETIME        NOT NULL,
            scan_count    INT UNSIGNED    DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY place_id (place_id),
            KEY overall_score (overall_score),
            KEY scanned_at (scanned_at)
        ) $charset;" );

        // Leads — email capture events tied to a scan
        dbDelta( "CREATE TABLE {$t['leads']} (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            scan_id       BIGINT UNSIGNED NOT NULL,
            email         VARCHAR(255)    NOT NULL DEFAULT '',
            business_name VARCHAR(255)    NOT NULL,
            overall_score TINYINT UNSIGNED DEFAULT 0,
            pain_points   TEXT            DEFAULT '',
            status         ENUM('uncontacted','new','contacted','qualified','closed','lost') DEFAULT 'new',
            type           ENUM('lead','prospect') NOT NULL DEFAULT 'lead',
            source         ENUM('organic','bulk')  NOT NULL DEFAULT 'organic',
            notes          TEXT            DEFAULT '',
            follow_up_date DATE            NULL DEFAULT NULL,
            reminded_at    DATETIME        NULL DEFAULT NULL,
            report_snapshot LONGTEXT       NULL DEFAULT NULL,
            created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY scan_id (scan_id),
            KEY status (status),
            KEY type (type),
            KEY source (source),
            KEY follow_up_date (follow_up_date),
            KEY created_at (created_at)
        ) $charset;" );

        // Bulk scan jobs — one row per import batch
        dbDelta( "CREATE TABLE {$t['scan_jobs']} (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            status       ENUM('pending','running','paused','complete','cancelled') NOT NULL DEFAULT 'pending',
            total        INT UNSIGNED    NOT NULL DEFAULT 0,
            completed    INT UNSIGNED    NOT NULL DEFAULT 0,
            failed       INT UNSIGNED    NOT NULL DEFAULT 0,
            skipped      INT UNSIGNED    NOT NULL DEFAULT 0,
            tokens_used  INT UNSIGNED    NOT NULL DEFAULT 0,
            model        VARCHAR(100)    NOT NULL DEFAULT '',
            created_at   DATETIME        NOT NULL,
            started_at   DATETIME        NULL,
            completed_at DATETIME        NULL,
            PRIMARY KEY (id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset;" );

        // Bulk scan queue — one row per business in a job
        dbDelta( "CREATE TABLE {$t['scan_queue']} (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            job_id        BIGINT UNSIGNED NOT NULL,
            input_name    VARCHAR(255)    NOT NULL DEFAULT '',
            input_address VARCHAR(500)    NOT NULL DEFAULT '',
            place_id      VARCHAR(255)    NULL,
            scan_id       BIGINT UNSIGNED NULL,
            status        ENUM('queued','scanning','complete','failed','skipped') NOT NULL DEFAULT 'queued',
            error_message VARCHAR(500)    NULL,
            tokens_used   INT UNSIGNED    NOT NULL DEFAULT 0,
            duration_ms   INT UNSIGNED    NOT NULL DEFAULT 0,
            position      INT UNSIGNED    NOT NULL DEFAULT 0,
            scan_started_at DATETIME      NULL,
            created_at    DATETIME        NOT NULL,
            PRIMARY KEY (id),
            KEY job_id (job_id),
            KEY status (status),
            KEY position (position),
            KEY scan_started_at (scan_started_at)
        ) $charset;" );

        // Shares — shareable report links with expiry
        dbDelta( "CREATE TABLE {$t['shares']} (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            token      VARCHAR(64)     NOT NULL,
            scan_id    BIGINT UNSIGNED NOT NULL,
            source_url VARCHAR(2048)   DEFAULT '',
            expires_at DATETIME        NOT NULL,
            views      INT UNSIGNED    DEFAULT 0,
            created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY token (token),
            KEY scan_id (scan_id),
            KEY expires_at (expires_at)
        ) $charset;" );

        // Market Intel saved assets — one row per action_slug + industry pair
        dbDelta( "CREATE TABLE {$t['intel_assets']} (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            action_slug  VARCHAR(80)     NOT NULL,
            industry     VARCHAR(80)     NOT NULL DEFAULT 'all',
            content_md   LONGTEXT        NOT NULL,
            scan_count   INT UNSIGNED    NOT NULL DEFAULT 0,
            generated_at DATETIME        NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY   action_industry (action_slug, industry)
        ) $charset;" );

        // F! Reviews tables — separate class, called here so a single
        // version bump triggers all schema work in one activation pass.
        if ( class_exists( 'FI_Reviews' ) ) {
            FI_Reviews::create_tables();
        }
    }

    // ---------- Scans ----------

    public static function get_scan_by_place_id( $place_id ) {
        global $wpdb;
        $t = self::tables();
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$t['scans']} WHERE place_id = %s AND expires_at > NOW() LIMIT 1",
            $place_id
        ) );
    }

    public static function upsert_scan( array $data ): int {
        global $wpdb;
        $t = self::tables();

        // Build the SET clause for the ON DUPLICATE KEY UPDATE branch.
        // All columns except place_id (the unique key) get refreshed.
        $update_cols = array_diff( array_keys( $data ), [ 'place_id' ] );
        $update_sql  = implode( ', ', array_map(
            fn( $col ) => "`{$col}` = VALUES(`{$col}`)",
            $update_cols
        ) );

        // Atomically increment scan_count regardless of insert vs update.
        $update_sql .= ', `scan_count` = `scan_count` + 1';

        // Build column/value lists for the INSERT clause.
        $cols        = implode( ', ', array_map( fn( $c ) => "`{$c}`", array_keys( $data ) ) );
        $placeholders = implode( ', ', array_fill( 0, count( $data ), '%s' ) );

        $sql = "INSERT INTO {$t['scans']} ({$cols}) VALUES ({$placeholders})
                ON DUPLICATE KEY UPDATE {$update_sql}";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared — built from trusted internal data only
        $wpdb->query( $wpdb->prepare( $sql, ...array_values( $data ) ) );

        // insert_id is populated for both INSERT (new row) and UPDATE (0 means update fired).
        // For updates, retrieve the existing id.
        if ( $wpdb->insert_id ) {
            return (int) $wpdb->insert_id;
        }

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$t['scans']} WHERE place_id = %s LIMIT 1",
            $data['place_id']
        ) );
    }

    public static function get_recent_scans( $limit = 50 ) {
        global $wpdb;
        $t = self::tables();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, business_name, category, overall_score, scanned_at FROM {$t['scans']} ORDER BY scanned_at DESC LIMIT %d",
            $limit
        ) );
    }

    /**
     * Returns scans enriched with first-captured lead pain points for the analytics table.
     */
    public static function get_scans_for_table( $limit = 200 ) {
        global $wpdb;
        $t = self::tables();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT s.id, s.business_name, s.category, s.address, s.overall_score, s.scanned_at,
                    ( SELECT l.pain_points FROM {$t['leads']} l WHERE l.scan_id = s.id ORDER BY l.created_at ASC LIMIT 1 ) AS pain_points
             FROM {$t['scans']} s
             ORDER BY s.scanned_at DESC
             LIMIT %d",
            $limit
        ) );
    }

    public static function get_scan_by_id( $id ) {
        global $wpdb;
        $t = self::tables();
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$t['scans']} WHERE id = %d LIMIT 1",
            $id
        ) );
    }

    public static function expire_scan( int $id ): void {
        global $wpdb;
        $t = self::tables();
        // expires_at is compared against MySQL NOW() (UTC) — write UTC here to match.
        $wpdb->update( $t['scans'], [ 'expires_at' => gmdate( 'Y-m-d H:i:s' ) ], [ 'id' => $id ] );
    }

    // ── Filtered aggregate helpers ────────────────────────────────────────────
    // All accept a $filters array: [ 'category' => string, 'date_range' => string ]
    // date_range values: 'all' | '30' | '90' | '180'  (days)

    /**
     * Build WHERE clause fragments from a filters array.
     * Returns [ 'where' => string, 'params' => array ]
     */
    private static function build_filter_where( array $filters ): array {
        global $wpdb;
        $clauses = [ '1=1' ];
        $params  = [];

        $category = trim( $filters['category'] ?? '' );
        if ( $category && $category !== 'all' ) {
            $clauses[] = 'category = %s';
            $params[]  = $category;
        }

        $days = (int) ( $filters['date_range'] ?? 0 );
        if ( $days > 0 ) {
            $clauses[] = 'scanned_at >= DATE_SUB(NOW(), INTERVAL %d DAY)';
            $params[]  = $days;
        }

        return [
            'where'  => implode( ' AND ', $clauses ),
            'params' => $params,
        ];
    }

    public static function filtered_total_scans( array $filters ): int {
        global $wpdb;
        $t  = self::tables();
        $fw = self::build_filter_where( $filters );
        $sql = "SELECT COUNT(*) FROM {$t['scans']} WHERE {$fw['where']}";
        return (int) ( empty( $fw['params'] )
            ? $wpdb->get_var( $sql )
            : $wpdb->get_var( $wpdb->prepare( $sql, ...$fw['params'] ) ) );
    }

    public static function filtered_average_score( array $filters ): float {
        global $wpdb;
        $t  = self::tables();
        $fw = self::build_filter_where( $filters );
        $sql = "SELECT ROUND(AVG(overall_score),1) FROM {$t['scans']} WHERE {$fw['where']}";
        return round( (float) ( empty( $fw['params'] )
            ? $wpdb->get_var( $sql )
            : $wpdb->get_var( $wpdb->prepare( $sql, ...$fw['params'] ) ) ), 1 );
    }

    public static function filtered_high_need_count( array $filters, int $threshold = 60 ): int {
        global $wpdb;
        $t  = self::tables();
        $fw = self::build_filter_where( $filters );
        $fw['params'][] = $threshold;
        $sql = "SELECT COUNT(*) FROM {$t['scans']} WHERE {$fw['where']} AND overall_score <= %d";
        return (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$fw['params'] ) );
    }

    public static function filtered_top_industries( array $filters, int $limit = 10 ): array {
        global $wpdb;
        $t  = self::tables();
        $fw = self::build_filter_where( $filters );
        $fw['params'][] = $limit;
        $sql = "SELECT category, COUNT(*) as scans, ROUND(AVG(overall_score),1) as avg_score
                FROM {$t['scans']} WHERE {$fw['where']} AND category != ''
                GROUP BY category ORDER BY scans DESC LIMIT %d";
        return $wpdb->get_results( $wpdb->prepare( $sql, ...$fw['params'] ) );
    }

    public static function filtered_weakest_industries( array $filters, int $min_scans = 2, int $limit = 5 ): array {
        global $wpdb;
        $t  = self::tables();
        $fw = self::build_filter_where( $filters );
        $fw['params'][] = $min_scans;
        $fw['params'][] = $limit;
        $sql = "SELECT category, COUNT(*) as scans, ROUND(AVG(overall_score),1) as avg_score
                FROM {$t['scans']} WHERE {$fw['where']} AND category != ''
                GROUP BY category HAVING scans >= %d ORDER BY avg_score ASC LIMIT %d";
        return $wpdb->get_results( $wpdb->prepare( $sql, ...$fw['params'] ) );
    }

    public static function filtered_top_pain_points( array $filters ): array {
        global $wpdb;
        $t  = self::tables();
        $fw = self::build_filter_where( $filters );

        // Join leads to scans so filters apply
        $cat_clause  = '';
        $date_clause = '';
        $params      = [];

        $category = trim( $filters['category'] ?? '' );
        if ( $category && $category !== 'all' ) {
            $cat_clause = $wpdb->prepare( 'AND s.category = %s', $category );
        }
        $days = (int) ( $filters['date_range'] ?? 0 );
        if ( $days > 0 ) {
            $date_clause = $wpdb->prepare( 'AND s.scanned_at >= DATE_SUB(NOW(), INTERVAL %d DAY)', $days );
        }

        $rows = $wpdb->get_results(
            "SELECT l.pain_points FROM {$t['leads']} l
             JOIN {$t['scans']} s ON l.scan_id = s.id
             WHERE l.pain_points != '' {$cat_clause} {$date_clause}"
        );

        $counts = [];
        foreach ( $rows as $row ) {
            foreach ( array_filter( array_map( 'trim', explode( ',', $row->pain_points ) ) ) as $p ) {
                $counts[ $p ] = ( $counts[ $p ] ?? 0 ) + 1;
            }
        }
        arsort( $counts );
        return array_slice( $counts, 0, 10, true );
    }

    public static function filtered_top_cities( array $filters, int $limit = 5 ): array {
        global $wpdb;
        $t  = self::tables();
        $fw = self::build_filter_where( $filters );
        $sql = "SELECT address, COUNT(*) as scans, ROUND(AVG(overall_score),1) as avg_score
                FROM {$t['scans']} WHERE {$fw['where']} AND address != ''
                GROUP BY address";
        $rows = empty( $fw['params'] )
            ? $wpdb->get_results( $sql )
            : $wpdb->get_results( $wpdb->prepare( $sql, ...$fw['params'] ) );

        $cities = [];
        foreach ( $rows as $row ) {
            $parts = array_map( 'trim', explode( ',', $row->address ) );
            $city  = count( $parts ) >= 3 ? $parts[ count( $parts ) - 3 ] : ( $parts[1] ?? '' );
            $city  = trim( preg_replace( '/\s*\d{5}(-\d{4})?$/', '', $city ) );
            if ( ! $city ) continue;
            if ( ! isset( $cities[ $city ] ) ) $cities[ $city ] = [ 'scans' => 0, 'score_sum' => 0 ];
            $cities[ $city ]['scans']     += (int) $row->scans;
            $cities[ $city ]['score_sum'] += $row->avg_score * $row->scans;
        }
        uasort( $cities, fn( $a, $b ) => $b['scans'] <=> $a['scans'] );
        $cities = array_slice( $cities, 0, $limit, true );
        $result = [];
        foreach ( $cities as $name => $data ) {
            $result[] = (object) [
                'city'      => $name,
                'scans'     => $data['scans'],
                'avg_score' => $data['scans'] ? round( $data['score_sum'] / $data['scans'], 1 ) : 0,
            ];
        }
        return $result;
    }

    /**
     * All distinct categories that have at least $min_scans scans.
     * Used to populate the Industry filter dropdown.
     */
    public static function all_categories( int $min_scans = 1 ): array {
        global $wpdb;
        $t = self::tables();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT category, COUNT(*) as scans, ROUND(AVG(overall_score),1) as avg_score
             FROM {$t['scans']} WHERE category != ''
             GROUP BY category HAVING scans >= %d ORDER BY scans DESC",
            $min_scans
        ) );
    }

    public static function total_scans() {
        global $wpdb;
        $t = self::tables();
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['scans']}" );
    }

    public static function scans_this_month() {
        global $wpdb;
        $t = self::tables();
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['scans']} WHERE MONTH(scanned_at) = MONTH(NOW()) AND YEAR(scanned_at) = YEAR(NOW())" );
    }

    public static function average_score() {
        global $wpdb;
        $t = self::tables();
        return round( (float) $wpdb->get_var( "SELECT AVG(overall_score) FROM {$t['scans']}" ), 1 );
    }

    public static function high_need_count( $threshold = 60 ) {
        global $wpdb;
        $t = self::tables();
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$t['scans']} WHERE overall_score <= %d",
            $threshold
        ) );
    }

    public static function top_industries( $limit = 10 ) {
        global $wpdb;
        $t = self::tables();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT category, COUNT(*) as scans, ROUND(AVG(overall_score),1) as avg_score FROM {$t['scans']} WHERE category != '' GROUP BY category ORDER BY scans DESC LIMIT %d",
            $limit
        ) );
    }

    public static function score_distribution() {
        global $wpdb;
        $t = self::tables();
        return $wpdb->get_results(
            "SELECT 
                SUM(CASE WHEN overall_score >= 80 THEN 1 ELSE 0 END) as strong,
                SUM(CASE WHEN overall_score >= 60 AND overall_score < 80 THEN 1 ELSE 0 END) as average,
                SUM(CASE WHEN overall_score < 60 THEN 1 ELSE 0 END) as weak
            FROM {$t['scans']}"
        );
    }

    // ---------- Leads ----------

    public static function insert_lead( $data ) {
        global $wpdb;
        $t = self::tables();
        $wpdb->insert( $t['leads'], $data );
        return $wpdb->insert_id;
    }

    public static function get_leads( $args = [] ) {
        global $wpdb;
        $t      = self::tables();
        $where  = '1=1';
        $params = [];

        if ( ! empty( $args['id'] ) ) {
            $where   .= ' AND l.id = %d';
            $params[] = (int) $args['id'];
        }

        // type filter: 'lead', 'prospect', or omitted for all
        if ( ! empty( $args['type'] ) ) {
            $where   .= ' AND l.type = %s';
            $params[] = $args['type'];
        }

        if ( ! empty( $args['status'] ) && $args['status'] !== 'all' ) {
            $where   .= ' AND l.status = %s';
            $params[] = $args['status'];
        }

        if ( ! empty( $args['search'] ) ) {
            $where   .= ' AND (l.business_name LIKE %s OR l.email LIKE %s)';
            $like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $limit  = isset( $args['limit'] ) ? (int) $args['limit'] : 50;
        $offset = isset( $args['offset'] ) ? (int) $args['offset'] : 0;

        $sql = "SELECT l.*, s.category FROM {$t['leads']} l LEFT JOIN {$t['scans']} s ON l.scan_id = s.id WHERE $where ORDER BY l.created_at DESC LIMIT %d OFFSET %d";

        $params[] = $limit;
        $params[] = $offset;

        return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );
    }

    public static function total_leads() {
        global $wpdb;
        $t = self::tables();
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['leads']} WHERE type = 'lead'" );
    }

    /**
     * Count leads matching the same filters passed to get_leads().
     * Used for pagination in the leads tab.
     */
    public static function count_leads( array $args = [] ): int {
        global $wpdb;
        $t      = self::tables();
        $where  = '1=1';
        $params = [];

        if ( ! empty( $args['type'] ) ) {
            $where   .= ' AND l.type = %s';
            $params[] = $args['type'];
        }
        // Must mirror get_leads() — if a status filter is active the count must
        // match the number of rows actually returned, otherwise pagination breaks.
        if ( ! empty( $args['status'] ) && $args['status'] !== 'all' ) {
            $where   .= ' AND l.status = %s';
            $params[] = $args['status'];
        }
        if ( ! empty( $args['search'] ) ) {
            $where   .= ' AND (l.business_name LIKE %s OR l.email LIKE %s)';
            $like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $sql = "SELECT COUNT(*) FROM {$t['leads']} l WHERE $where";
        return (int) ( $params
            ? $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) )
            : $wpdb->get_var( $sql )
        );
    }

    public static function total_prospects() {
        global $wpdb;
        $t = self::tables();
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['leads']} WHERE type = 'prospect'" );
    }

    public static function leads_this_month() {
        global $wpdb;
        $t = self::tables();
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['leads']} WHERE type = 'lead' AND MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())" );
    }

    public static function leads_by_status() {
        global $wpdb;
        $t = self::tables();
        return $wpdb->get_results( "SELECT status, COUNT(*) as count FROM {$t['leads']} WHERE type = 'lead' GROUP BY status" );
    }

    public static function prospects_by_status() {
        global $wpdb;
        $t = self::tables();
        return $wpdb->get_results( "SELECT status, COUNT(*) as count FROM {$t['leads']} WHERE type = 'prospect' GROUP BY status" );
    }

    public static function update_lead( $id, $data ) {
        global $wpdb;
        $t = self::tables();
        return $wpdb->update( $t['leads'], $data, [ 'id' => (int) $id ] );
    }

    /**
     * Fetch all leads/prospects that are due for a follow-up reminder.
     * "Due" = follow_up_date <= $as_of_date AND reminded_at IS NULL
     * AND status is not closed/lost (no point reminding on dead records).
     *
     * @param  string $as_of_date  Y-m-d date string (today in site timezone)
     * @return object[]
     */
    public static function get_reminder_due( string $as_of_date ): array {
        global $wpdb;
        $t = self::tables();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT l.*, s.phone, s.website
             FROM {$t['leads']} l
             LEFT JOIN {$t['scans']} s ON l.scan_id = s.id
             WHERE l.follow_up_date IS NOT NULL
               AND l.follow_up_date <= %s
               AND l.reminded_at IS NULL
               AND l.status NOT IN ('closed','lost')
             ORDER BY l.overall_score DESC, l.follow_up_date ASC",
            $as_of_date
        ) );
    }

    /**
     * Stamp reminded_at on a set of lead IDs.
     *
     * @param int[] $ids
     */
    public static function mark_reminded( array $ids ): void {
        if ( empty( $ids ) ) return;
        global $wpdb;
        $t   = self::tables();
        $in  = implode( ',', array_map( 'intval', $ids ) ); // IDs are intval-cast — safe for IN clause
        $now = gmdate( 'Y-m-d H:i:s' );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in contains only intval-cast integers
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$t['leads']} SET reminded_at = %s WHERE id IN ({$in})",
            $now
        ) );
    }

    /**
     * Clear reminded_at for a lead — called when the user changes follow_up_date,
     * so the next cron run will send a fresh reminder on the new date.
     *
     * @param int $id
     */
    public static function clear_reminded( int $id ): void {
        global $wpdb;
        $t = self::tables();
        $wpdb->update( $t['leads'], [ 'reminded_at' => null ], [ 'id' => $id ] );
    }

    public static function get_top_pain_points() {
        global $wpdb;
        $t = self::tables();
        $leads = $wpdb->get_results( "SELECT pain_points FROM {$t['leads']} WHERE pain_points != ''" );

        $counts = [];
        foreach ( $leads as $lead ) {
            $points = array_filter( array_map( 'trim', explode( ',', $lead->pain_points ) ) );
            foreach ( $points as $p ) {
                $counts[ $p ] = ( $counts[ $p ] ?? 0 ) + 1;
            }
        }

        arsort( $counts );
        return $counts;
    }

    public static function scan_to_lead_rate() {
        global $wpdb;
        $t      = self::tables();
        $scans  = self::total_scans();
        $leads  = self::total_leads();
        if ( ! $scans ) return 0;
        return round( ( $leads / $scans ) * 100, 1 );
    }

    // ---------- Shares ----------

    public static function create_share( $scan_id, $expires_at, string $source_url = '' ) {
        global $wpdb;
        $t     = self::tables();
        $token = bin2hex( random_bytes( 16 ) );

        // Grab place_id from the scan row so we can offer a re-scan if the
        // scan is later purged while the share link is still valid.
        $place_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT place_id FROM {$t['scans']} WHERE id = %d LIMIT 1",
            (int) $scan_id
        ) );

        $wpdb->insert( $t['shares'], [
            'token'      => $token,
            'scan_id'    => (int) $scan_id,
            'place_id'   => $place_id ?: null,
            'source_url' => $source_url,
            'expires_at' => $expires_at,
        ] );
        return $token;
    }

    public static function get_share_by_token( $token ) {
        global $wpdb;
        $t = self::tables();
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$t['shares']} WHERE token = %s LIMIT 1",
            $token
        ) );
    }

    public static function increment_share_views( $token ) {
        global $wpdb;
        $t = self::tables();
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$t['shares']} SET views = views + 1 WHERE token = %s",
            $token
        ) );
    }

    public static function get_share_by_scan_id( $scan_id ) {
        global $wpdb;
        $t = self::tables();
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$t['shares']} WHERE scan_id = %d AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1",
            $scan_id
        ) );
    }

    public static function delete_expired_shares() {
        global $wpdb;
        $t = self::tables();
        $wpdb->query( "DELETE FROM {$t['shares']} WHERE expires_at < NOW()" );
    }

    // ---------- Analytics page helpers (moved from inline SQL) ----------

    /**
     * Fetch a keyed map of scans by ID (used for batch-loading in leads table).
     *
     * @param  int[]  $ids
     * @return array<int, object>
     */
    public static function get_scans_by_ids( array $ids ): array {
        if ( empty( $ids ) ) return [];
        global $wpdb;
        $t   = self::tables();
        $in  = implode( ',', array_map( 'intval', $ids ) ); // IDs are intval-cast — safe for IN clause
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in contains only intval-cast integers
        $rows = $wpdb->get_results( "SELECT id, website, phone FROM {$t['scans']} WHERE id IN ($in)" );
        $map  = [];
        foreach ( $rows as $row ) $map[ $row->id ] = $row;
        return $map;
    }

    /**
     * Fetch a keyed map of active share URLs by scan ID (used for batch-loading).
     *
     * @param  int[]  $scan_ids
     * @return array<int, string>  scan_id → full share URL
     */
    public static function get_active_shares_by_scan_ids( array $scan_ids ): array {
        if ( empty( $scan_ids ) ) return [];
        global $wpdb;
        $t   = self::tables();
        $in  = implode( ',', array_map( 'intval', $scan_ids ) ); // IDs are intval-cast — safe for IN clause
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in contains only intval-cast integers
        $rows = $wpdb->get_results(
            "SELECT scan_id, token FROM {$t['shares']} WHERE scan_id IN ($in) AND expires_at > NOW()"
        );
        $map = [];
        foreach ( $rows as $row ) $map[ $row->scan_id ] = FI_Share::build_url( $row->token );
        return $map;
    }

    // ── Interpretive analytics ───────────────────────────────────────────────

    /**
     * Industries sorted by avg score ASC — lowest-scoring = highest opportunity.
     */
    public static function weakest_industries( int $min_scans = 2, int $limit = 5 ): array {
        global $wpdb;
        $t = self::tables();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT category, COUNT(*) as scans, ROUND(AVG(overall_score),1) as avg_score
             FROM {$t['scans']}
             WHERE category != ''
             GROUP BY category
             HAVING scans >= %d
             ORDER BY avg_score ASC
             LIMIT %d",
            $min_scans, $limit
        ) );
    }

    /**
     * Industries sorted by lead conversion rate DESC — most likely to convert.
     */
    public static function highest_converting_industries( int $limit = 5 ): array {
        global $wpdb;
        $t = self::tables();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT s.category,
                    COUNT(DISTINCT s.id) as scans,
                    COUNT(DISTINCT l.id) as leads,
                    ROUND( COUNT(DISTINCT l.id) / COUNT(DISTINCT s.id) * 100, 1 ) as conversion_rate
             FROM {$t['scans']} s
             LEFT JOIN {$t['leads']} l ON l.scan_id = s.id
             WHERE s.category != ''
             GROUP BY s.category
             HAVING scans >= 2
             ORDER BY conversion_rate DESC
             LIMIT %d",
            $limit
        ) );
    }

    /**
     * Extract city/region from stored address strings.
     * Addresses are like "123 Main St, Miami, FL 33101, USA" — take the
     * second-to-last comma-delimited segment as city approximation.
     */
    public static function top_cities( int $limit = 8 ): array {
        global $wpdb;
        $t    = self::tables();
        $rows = $wpdb->get_results(
            "SELECT address, COUNT(*) as scans, ROUND(AVG(overall_score),1) as avg_score
             FROM {$t['scans']}
             WHERE address != ''
             GROUP BY address"
        );

        $cities = [];
        foreach ( $rows as $row ) {
            $parts = array_map( 'trim', explode( ',', $row->address ) );
            // Google address format ends with country — city is typically 3rd from end
            $city = count( $parts ) >= 3 ? $parts[ count( $parts ) - 3 ] : ( $parts[1] ?? '' );
            // Strip zip codes mixed into city segment
            $city = trim( preg_replace( '/\s*\d{5}(-\d{4})?$/', '', $city ) );
            if ( ! $city ) continue;
            if ( ! isset( $cities[ $city ] ) ) {
                $cities[ $city ] = [ 'scans' => 0, 'score_sum' => 0 ];
            }
            $cities[ $city ]['scans']     += (int) $row->scans;
            $cities[ $city ]['score_sum'] += $row->avg_score * $row->scans;
        }

        uasort( $cities, fn( $a, $b ) => $b['scans'] <=> $a['scans'] );
        $cities = array_slice( $cities, 0, $limit, true );

        $result = [];
        foreach ( $cities as $name => $data ) {
            $result[] = (object) [
                'city'      => $name,
                'scans'     => $data['scans'],
                'avg_score' => $data['scans'] ? round( $data['score_sum'] / $data['scans'], 1 ) : 0,
            ];
        }
        return $result;
    }

    /**
     * Pain points broken down by industry category.
     */
    public static function pain_points_by_industry(): array {
        global $wpdb;
        $t     = self::tables();
        $leads = $wpdb->get_results(
            "SELECT l.pain_points, s.category
             FROM {$t['leads']} l
             JOIN {$t['scans']} s ON l.scan_id = s.id
             WHERE l.pain_points != '' AND s.category != ''"
        );

        $map = [];
        foreach ( $leads as $row ) {
            $cat    = $row->category;
            $points = array_filter( array_map( 'trim', explode( ',', $row->pain_points ) ) );
            foreach ( $points as $p ) {
                // Strip the score suffix e.g. "Customer Reviews (48/100): ..."
                $label = trim( explode( '(', $p )[0] );
                if ( ! $label ) continue;
                $map[ $cat ][ $label ] = ( $map[ $cat ][ $label ] ?? 0 ) + 1;
            }
        }

        // Sort each industry's pain points by count DESC
        foreach ( $map as $cat => &$points ) {
            arsort( $points );
            $points = array_slice( $points, 0, 3, true );
        }
        return $map;
    }

    /**
     * Count of scans in the last N days for velocity calculation.
     */
    public static function scans_in_last_days( int $days ): int {
        global $wpdb;
        $t = self::tables();
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$t['scans']} WHERE scanned_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ) );
    }

    // ── Bulk Scan Jobs ────────────────────────────────────────────────────────

    public static function create_scan_job( string $model ): int {
        global $wpdb;
        $t = self::tables();
        $wpdb->insert( $t['scan_jobs'], [
            'status'     => 'pending',
            'model'      => $model,
            'created_at' => gmdate( 'Y-m-d H:i:s' ),
        ] );
        return (int) $wpdb->insert_id;
    }

    public static function get_scan_job( int $job_id ): ?object {
        global $wpdb;
        $t = self::tables();
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$t['scan_jobs']} WHERE id = %d LIMIT 1",
            $job_id
        ) ) ?: null;
    }

    public static function get_scan_jobs( int $limit = 20 ): array {
        global $wpdb;
        $t = self::tables();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$t['scan_jobs']} ORDER BY created_at DESC LIMIT %d",
            $limit
        ) );
    }

    public static function update_scan_job( int $job_id, array $data ): void {
        global $wpdb;
        $t = self::tables();
        $wpdb->update( $t['scan_jobs'], $data, [ 'id' => $job_id ] );
    }

    /**
     * Atomically increment job counters. Use CASE arithmetic to avoid
     * read-modify-write races when cron fires rapidly.
     */
    public static function increment_job_counters( int $job_id, string $field, int $tokens = 0 ): void {
        global $wpdb;
        $t = self::tables();
        $allowed = [ 'completed', 'failed', 'skipped' ];
        if ( ! in_array( $field, $allowed, true ) ) return;

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$t['scan_jobs']}
             SET {$field} = {$field} + 1,
                 tokens_used = tokens_used + %d
             WHERE id = %d",
            $tokens,
            $job_id
        ) );
    }

    /**
     * Increment a job counter by an arbitrary amount (used for bulk kills).
     */
    public static function bulk_increment_job_counter( int $job_id, string $field, int $amount ): void {
        if ( $amount <= 0 ) return;
        global $wpdb;
        $t = self::tables();
        $allowed = [ 'completed', 'failed', 'skipped' ];
        if ( ! in_array( $field, $allowed, true ) ) return;

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$t['scan_jobs']}
             SET {$field} = {$field} + %d
             WHERE id = %d",
            $amount,
            $job_id
        ) );
    }

    // ── Bulk Scan Queue ───────────────────────────────────────────────────────

    /**
     * Insert a batch of queue items for a job.
     * @param int   $job_id
     * @param array $items  Each: [ 'input_name' => string, 'input_address' => string ]
     */
    public static function insert_queue_items( int $job_id, array $items ): void {
        global $wpdb;
        $t   = self::tables();
        $now = gmdate( 'Y-m-d H:i:s' );

        foreach ( $items as $i => $item ) {
            $wpdb->insert( $t['scan_queue'], [
                'job_id'        => $job_id,
                'input_name'    => sanitize_text_field( $item['input_name'] ),
                'input_address' => sanitize_text_field( $item['input_address'] ?? '' ),
                'status'        => 'queued',
                'position'      => $i + 1,
                'created_at'    => $now,
            ] );
        }
    }

    public static function get_next_queued_item( int $job_id ): ?object {
        global $wpdb;
        $t = self::tables();

        // Recover items stuck in 'scanning' for more than 3 minutes
        // (cron process died mid-scan). Use scan_started_at if set, else created_at.
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$t['scan_queue']}
             SET status = 'queued', error_message = 'Recovered from stuck scan; retrying'
             WHERE job_id = %d
               AND status = 'scanning'
               AND COALESCE(scan_started_at, created_at) < DATE_SUB(NOW(), INTERVAL 3 MINUTE)",
            $job_id
        ) );

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$t['scan_queue']}
             WHERE job_id = %d AND status = 'queued'
             ORDER BY position ASC LIMIT 1",
            $job_id
        ) ) ?: null;
    }

    public static function update_queue_item( int $item_id, array $data ): void {
        global $wpdb;
        $t = self::tables();
        $wpdb->update( $t['scan_queue'], $data, [ 'id' => $item_id ] );
    }

    public static function get_queue_items( int $job_id ): array {
        global $wpdb;
        $t = self::tables();
        // Join fi_scans for score and fi_leads for a direct lead link — both are
        // optional (LEFT JOIN) so incomplete / failed items still appear in the table.
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT q.*,
                    s.overall_score,
                    l.id AS lead_id
             FROM {$t['scan_queue']} q
             LEFT JOIN {$t['scans']} s  ON s.id = q.scan_id
             LEFT JOIN {$t['leads']} l  ON l.scan_id = q.scan_id AND l.type = 'prospect'
             WHERE q.job_id = %d
             ORDER BY q.position ASC",
            $job_id
        ) );
    }

    /**
     * Check whether a business is already cached in fi_scans and not yet expired.
     * Used for duplicate detection at queue-build time.
     *
     * Matching strategy (most-specific first):
     *   1. Exact name + address match — catches same chain in same location.
     *   2. Name-only match with no address provided — preserves original behaviour
     *      when the CSV has no address column.
     *
     * "Joe's Pizza" in Chicago and "Joe's Pizza" in Denver are treated as
     * different businesses when addresses are supplied, preventing silent data loss
     * for agencies scanning overlapping metros or franchise territories.
     *
     * @param string $name    Business name from the CSV.
     * @param string $address Combined address string (may be empty).
     */
    public static function find_existing_scan_for_queue( string $name, string $address = '' ): ?object {
        global $wpdb;
        $t = self::tables();

        // When an address is provided, require both name and address to match.
        // We compare the first ~80 chars of the stored address (which is a full
        // formatted address) against the incoming address fragment — good enough
        // to distinguish cities without needing an exact full-string match.
        if ( $address !== '' ) {
            // Normalise: lowercase, strip punctuation, collapse whitespace
            $norm = strtolower( preg_replace( '/[^a-z0-9 ]/i', '', $address ) );
            $norm = preg_replace( '/\s+/', ' ', trim( $norm ) );

            // Single query — get_results returns [] when no rows, so the foreach below
            // is a no-op and we fall through to "return null" cleanly.
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, place_id, business_name, overall_score, expires_at, address
                 FROM {$t['scans']}
                 WHERE business_name = %s
                   AND expires_at > NOW()
                 ORDER BY scanned_at DESC
                 LIMIT 10",
                $name
            ) );

            foreach ( $rows as $candidate ) {
                $stored_norm = strtolower( preg_replace( '/[^a-z0-9 ]/i', '', $candidate->address ?? '' ) );
                $stored_norm = preg_replace( '/\s+/', ' ', trim( $stored_norm ) );
                // Consider it a match if the incoming address is a substring of the
                // stored formatted address (e.g. "Chicago IL" appears in
                // "123 Main St, Chicago, IL 60601, USA")
                if ( $stored_norm !== '' && str_contains( $stored_norm, $norm ) ) {
                    return $candidate;
                }
            }

            // Address provided but no matching location found — not a duplicate
            return null;
        }

        // No address provided — fall back to name-only match (original behaviour)
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT id, place_id, business_name, overall_score, expires_at
             FROM {$t['scans']}
             WHERE business_name = %s AND expires_at > NOW()
             LIMIT 1",
            $name
        ) ) ?: null;
    }

    /**
     * Count items in each status for a job — used by the progress poller.
     */
    public static function get_queue_status_counts( int $job_id ): array {
        global $wpdb;
        $t    = self::tables();
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT status, COUNT(*) as count FROM {$t['scan_queue']}
             WHERE job_id = %d GROUP BY status",
            $job_id
        ) );
        $counts = [ 'queued' => 0, 'scanning' => 0, 'complete' => 0, 'failed' => 0, 'skipped' => 0 ];
        foreach ( $rows as $row ) {
            if ( isset( $counts[ $row->status ] ) ) {
                $counts[ $row->status ] = (int) $row->count;
            }
        }
        return $counts;
    }

    // ── Market Intel Saved Assets ─────────────────────────────────────────────

    /**
     * Upsert a saved Market Intel asset.
     * Unique key is (action_slug, industry) — regenerating overwrites the previous row.
     *
     * @return int  The id of the saved row (0 on failure).
     */
    public static function save_intel_asset( string $action_slug, string $industry, string $content_md, int $scan_count ): int {
        global $wpdb;
        $t   = self::tables();
        $now = gmdate( 'Y-m-d H:i:s' );

        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$t['intel_assets']} (action_slug, industry, content_md, scan_count, generated_at)
             VALUES (%s, %s, %s, %d, %s)
             ON DUPLICATE KEY UPDATE
                 content_md   = VALUES(content_md),
                 scan_count   = VALUES(scan_count),
                 generated_at = VALUES(generated_at)",
            $action_slug,
            $industry,
            $content_md,
            $scan_count,
            $now
        ) );

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$t['intel_assets']} WHERE action_slug = %s AND industry = %s",
            $action_slug,
            $industry
        ) );
    }

    /**
     * Load a single saved asset by primary key (content included).
     */
    public static function get_intel_asset( int $id ): ?object {
        global $wpdb;
        $t = self::tables();
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$t['intel_assets']} WHERE id = %d",
            $id
        ) ) ?: null;
    }

    /**
     * Return the lightweight index (no content) for a given industry filter.
     * Used to hydrate card saved-states on page load without fetching LONGTEXT.
     *
     * @return object[]  Each row: id, action_slug, industry, scan_count, generated_at
     */
    public static function get_intel_asset_index( string $industry ): array {
        global $wpdb;
        $t = self::tables();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, action_slug, industry, scan_count, generated_at
             FROM {$t['intel_assets']}
             WHERE industry = %s
             ORDER BY generated_at DESC",
            $industry
        ) ) ?: [];
    }

    /**
     * Delete a saved asset by primary key.
     */
    public static function delete_intel_asset( int $id ): void {
        global $wpdb;
        $t = self::tables();
        $wpdb->delete( $t['intel_assets'], [ 'id' => $id ], [ '%d' ] );
    }

}
