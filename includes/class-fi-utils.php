<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FI_Utils
 *
 * Shared utility methods used across multiple classes.
 * Centralises logic that was previously duplicated in FI_Analytics_Page,
 * FI_Email, FI_Ajax, FI_Pitch, and FI_Bulk_Scan.
 *
 * @since 1.0.12
 */
class FI_Utils {

    /**
     * Return a hex color for a given score using the canonical thresholds.
     *
     * Thresholds:
     *   >= 80  → green  (#16a34a)
     *   >= 60  → amber  (#d97706)
     *   < 60   → red    (#dc2626)
     *
     * Previously defined as a private static method in FI_Analytics_Page,
     * FI_Email, and inline in FI_Ajax::handle_view_lead_snapshot().
     *
     * @param  int    $score  0–100
     * @return string         Hex colour string, e.g. '#16a34a'
     */
    public static function score_color( int $score ): string {
        if ( $score >= 80 ) return '#16a34a';
        if ( $score >= 60 ) return '#d97706';
        return '#dc2626';
    }

    /**
     * Canonical map of report category keys to human-readable labels.
     *
     * Previously duplicated as a local array in:
     *   - FI_Ajax::handle_view_lead_snapshot()
     *   - FI_Ajax::extract_pain_points()
     *   - FI_Email::build_html()
     *   - FI_Pitch::generate()
     *   - FI_Bulk_Scan::run_single_scan()
     *
     * @return array<string, string>
     */
    public static function cat_labels(): array {
        return [
            'online_presence'      => 'Online Presence',
            'customer_reviews'     => 'Customer Reviews',
            'photos_media'         => 'Photos & Media',
            'business_information' => 'Business Information',
            'competitive_position' => 'Competitive Position',
            'website_performance'  => 'Website Performance',
            'local_seo'            => 'Local SEO',
            'pagespeed_insights'   => 'Page Speed',
        ];
    }

    /**
     * Extract pain points from a report's categories array.
     *
     * Returns up to $limit categories with score < $threshold, sorted ascending
     * by score. Falls back to the lowest-scoring categories if none fall below
     * the threshold. Includes the category headline in each entry.
     *
     * Previously duplicated (with slight differences) in:
     *   - FI_Ajax::extract_pain_points()
     *   - FI_Bulk_Scan::run_single_scan()
     *
     * @param  array $report     Full report array (must contain 'categories' key).
     * @param  int   $threshold  Score below which a category is considered a pain point (default 60).
     * @param  int   $limit      Maximum number of pain points to return (default 5).
     * @return string[]          Array of human-readable pain point strings.
     */
    public static function extract_pain_points( array $report, int $threshold = 60, int $limit = 5 ): array {
        if ( empty( $report['categories'] ) ) return [];

        $labels = self::cat_labels();

        // Build score map and sort ascending
        $scores = [];
        foreach ( $report['categories'] as $key => $cat ) {
            $scores[ $key ] = $cat['score'] ?? 100;
        }
        asort( $scores );

        $pain_points = [];
        foreach ( $scores as $key => $score ) {
            if ( $score >= $threshold ) break;
            if ( count( $pain_points ) >= $limit ) break;
            $label         = $labels[ $key ] ?? $key;
            $headline      = $report['categories'][ $key ]['headline'] ?? '';
            $pain_points[] = $label . ' (' . $score . '/100)' . ( $headline ? ': ' . $headline : '' );
        }

        // Fallback: include top-2 lowest even if none are below threshold
        if ( empty( $pain_points ) ) {
            foreach ( array_slice( $scores, 0, 2, true ) as $key => $score ) {
                $label         = $labels[ $key ] ?? $key;
                $headline      = $report['categories'][ $key ]['headline'] ?? '';
                $pain_points[] = $label . ' (' . $score . '/100)' . ( $headline ? ': ' . $headline : '' );
            }
        }

        return $pain_points;
    }
}