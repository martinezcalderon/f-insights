<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FI_Logger
 * Writes timestamped entries to /wp-content/fi-insights-logs/debug-YYYY-MM-DD.log
 * Levels: INFO, API, ERROR, WARN
 * Each scan gets a unique 8-char ID for end-to-end tracing.
 */
class FI_Logger {

    private static function get_log_file() {
        if ( ! file_exists( FI_LOG_DIR ) ) {
            wp_mkdir_p( FI_LOG_DIR );
            file_put_contents( FI_LOG_DIR . '.htaccess', 'Deny from all' );
        }
        return FI_LOG_DIR . 'debug-' . wp_date( 'Y-m-d' ) . '.log';
    }

    public static function log( $message, $level = 'INFO', $scan_id = '' ) {
        $prefix = '[' . wp_date( 'H:i:s' ) . '] [' . strtoupper( $level ) . ']';
        if ( $scan_id ) $prefix .= ' [' . $scan_id . ']';
        error_log( $prefix . ' ' . $message . PHP_EOL, 3, self::get_log_file() );
    }

    public static function info(  $msg, $context = [], $scan_id = '' ) { self::log( self::format( $msg, $context ), 'INFO',  $scan_id ); }
    public static function api(   $msg, $context = [], $scan_id = '' ) { self::log( self::format( $msg, $context ), 'API',   $scan_id ); }
    public static function error( $msg, $context = [], $scan_id = '' ) { self::log( self::format( $msg, $context ), 'ERROR', $scan_id ); }
    public static function warn(  $msg, $context = [], $scan_id = '' ) { self::log( self::format( $msg, $context ), 'WARN',  $scan_id ); }

    private static function format( $msg, $context ) {
        if ( empty( $context ) ) return $msg;
        return $msg . ' ' . wp_json_encode( $context );
    }

    public static function generate_scan_id() {
        return strtoupper( substr( md5( uniqid( '', true ) ), 0, 8 ) );
    }

    public static function get_logs( $lines = 200 ) {
        $file = self::get_log_file();
        if ( ! file_exists( $file ) ) return [];
        $all = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        return array_slice( array_reverse( $all ), 0, $lines );
    }

    /**
     * Rotate logs: delete files older than 30 days, truncate any file that
     * exceeds the per-file size cap (default 5 MB). Called by the daily cron.
     *
     * @param int $max_age_days  Delete log files older than this many days. Default 30.
     * @param int $max_bytes     Truncate files larger than this. Default 5 MB.
     */
    public static function rotate( int $max_age_days = 30, int $max_bytes = 5 * 1024 * 1024 ): void {
        if ( ! is_dir( FI_LOG_DIR ) ) return;

        $cutoff = time() - ( $max_age_days * DAY_IN_SECONDS );

        foreach ( glob( FI_LOG_DIR . '*.log' ) as $file ) {
            // Delete files older than the age threshold
            if ( filemtime( $file ) < $cutoff ) {
                wp_delete_file( $file );
                continue;
            }
            // Truncate oversized files — keep only the last 500 lines so recent
            // activity is preserved while the file doesn't grow without bound.
            if ( filesize( $file ) > $max_bytes ) {
                $lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
                $lines = array_slice( $lines, -500 );
                file_put_contents( $file, implode( PHP_EOL, $lines ) . PHP_EOL );
            }
        }
    }

    public static function clear_logs() {
        foreach ( glob( FI_LOG_DIR . '*.log' ) as $f ) {
            file_put_contents( $f, '' );
        }
    }

    /** Alias used by FI_Ajax::handle_clear_logs(). */
    public static function clear_all(): void {
        self::clear_logs();
    }

    public static function get_log_size() {
        $total = 0;
        if ( ! is_dir( FI_LOG_DIR ) ) return 0;
        foreach ( glob( FI_LOG_DIR . '*.log' ) as $f ) {
            $total += filesize( $f );
        }
        return $total;
    }

    public static function download_today() {
        $file = self::get_log_file();
        if ( ! file_exists( $file ) ) return;
        header( 'Content-Type: text/plain' );
        header( 'Content-Disposition: attachment; filename="fi-debug-' . wp_date( 'Y-m-d' ) . '.log"' );
        readfile( $file );
        exit;
    }
}
