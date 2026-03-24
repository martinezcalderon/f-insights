<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FI_Leads
 * Thin wrapper over FI_DB for lead operations.
 * Direct DB calls go through FI_DB; this class adds business logic on top.
 */
class FI_Leads {

    const STATUSES = [ 'uncontacted', 'new', 'contacted', 'qualified', 'closed', 'lost' ];

    /**
     * Save a new lead when a visitor requests their report by email.
     * $report_json is the full report at capture time — stored as a snapshot
     * so the admin always sees the exact data that went to the lead's inbox.
     */
    public static function create( int $scan_id, string $email, string $business_name,
                                   int $overall_score, string $pain_points,
                                   array $extra = [], string $report_json = '' ): int {
        return (int) FI_DB::insert_lead( [
            'scan_id'         => $scan_id,
            'email'           => sanitize_email( $email ),
            'business_name'   => sanitize_text_field( $business_name ),
            'overall_score'   => $overall_score,
            'pain_points'     => sanitize_textarea_field( $pain_points ),
            'status'          => 'new',
            'type'            => 'lead',
            'source'          => 'organic',
            'report_snapshot' => $report_json ?: null,
            'notes'           => ! empty( $extra ) ? wp_json_encode( $extra ) : '',
            'created_at'      => gmdate( 'Y-m-d H:i:s' ),
        ] );
    }

    /**
     * Fetch leads with optional filtering, search, and pagination.
     * Passes through to FI_DB::get_leads() which has the full implementation.
     */
    public static function get_all( array $args = [] ): array {
        return FI_DB::get_leads( $args );
    }

    /**
     * Get a single lead by ID.
     */
    public static function get( int $id ): ?object {
        $results = FI_DB::get_leads( [ 'id' => $id, 'limit' => 1 ] );
        return $results[0] ?? null;
    }

    /**
     * Update lead status and/or notes.
     */
    public static function update( int $id, array $data ): bool {
        $allowed = array_intersect_key( $data, array_flip( [ 'status', 'notes' ] ) );
        if ( empty( $allowed ) ) return false;

        if ( isset( $allowed['status'] ) && ! in_array( $allowed['status'], self::STATUSES, true ) ) {
            return false;
        }

        return (bool) FI_DB::update_lead( $id, $allowed );
    }

    /**
     * Pipeline counts per status.
     */
    public static function pipeline_counts(): array {
        $rows   = FI_DB::leads_by_status();
        $counts = array_fill_keys( self::STATUSES, 0 );
        foreach ( $rows as $row ) {
            if ( isset( $counts[ $row->status ] ) ) {
                $counts[ $row->status ] = (int) $row->count;
            }
        }
        return $counts;
    }

    /**
     * Export all leads as a CSV string.
     * Caller is responsible for streaming the response.
     */
    public static function export_csv(): string {
        $leads = FI_DB::get_leads( [ 'limit' => 9999 ] );

        $cols = [ 'Business', 'Category', 'Score', 'Email', 'Status', 'Pain Points', 'Notes', 'Date' ];

        // Use a temp stream + fputcsv so newlines, quotes, and multibyte
        // characters inside field values are handled correctly.
        $stream = fopen( 'php://temp', 'r+' );
        fputcsv( $stream, $cols );

        foreach ( $leads as $l ) {
            fputcsv( $stream, [
                $l->business_name,
                $l->category     ?? '',
                $l->overall_score,
                $l->email,
                $l->status,
                $l->pain_points  ?? '',
                $l->notes        ?? '',
                $l->created_at,
            ] );
        }

        rewind( $stream );
        $csv = stream_get_contents( $stream );
        fclose( $stream );

        return (string) $csv;
    }
}
