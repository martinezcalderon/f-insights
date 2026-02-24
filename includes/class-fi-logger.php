<?php
/**
 * Debug Logger for F Insights
 */

if (!defined('ABSPATH')) {
    exit;
}

class FI_Logger {
    
    private static $log_file = null;
    
    /**
     * Initialize logger
     */
    public static function init() {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/f-insights-logs';
        
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
            // Add index.php for security
            file_put_contents($log_dir . '/index.php', '<?php // Silence is golden.');
        }
        
        self::$log_file = $log_dir . '/debug-' . current_time('Y-m-d') . '.log';
    }
    
    /**
     * Log debug message
     */
    public static function debug($message, $data = null) {
        self::log('DEBUG', $message, $data);
    }
    
    /**
     * Log info message
     */
    public static function info($message, $data = null) {
        self::log('INFO', $message, $data);
    }
    
    /**
     * Log warning
     */
    public static function warning($message, $data = null) {
        self::log('WARNING', $message, $data);
    }
    
    /**
     * Log error
     */
    public static function error($message, $data = null) {
        self::log('ERROR', $message, $data);
    }
    
    /**
     * Log API request
     */
    public static function api_request($service, $endpoint, $params = null) {
        self::log('API_REQUEST', "[$service] $endpoint", $params);
    }
    
    /**
     * Log API response
     */
    public static function api_response($service, $endpoint, $response_code, $data = null) {
        self::log('API_RESPONSE', "[$service] $endpoint - HTTP $response_code", $data);
    }
    
    /**
     * Write to log file
     */
    private static function log($level, $message, $data = null) {
        if (self::$log_file === null) {
            self::init();
        }
        
        $timestamp = current_time('mysql');
        $log_entry = "[$timestamp] [$level] $message";
        
        if ($data !== null) {
            if (is_array($data) || is_object($data)) {
                $log_entry .= "\n" . print_r($data, true);
            } else {
                $log_entry .= " - " . $data;
            }
        }
        
        $log_entry .= "\n" . str_repeat('-', 80) . "\n";
        
        error_log($log_entry, 3, self::$log_file);
        
        // Also log to WordPress debug log if enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[F Insights] [$level] $message");
        }
    }
    
    /**
     * Get log file path
     */
    public static function get_log_file() {
        if (self::$log_file === null) {
            self::init();
        }
        return self::$log_file;
    }
    
    /**
     * Get recent logs
     */
    public static function get_recent_logs($lines = 100) {
        if (self::$log_file === null) {
            self::init();
        }
        
        if (!file_exists(self::$log_file)) {
            return array();
        }
        
        $file = new SplFileObject(self::$log_file, 'r');
        $file->seek(PHP_INT_MAX);
        $total_lines = $file->key();
        
        $start_line = max(0, $total_lines - $lines);
        
        $logs = array();
        $file->seek($start_line);
        
        while (!$file->eof()) {
            $line = $file->current();
            if (!empty(trim($line))) {
                $logs[] = $line;
            }
            $file->next();
        }
        
        return $logs;
    }
    
    /**
     * Clear old logs
     */
    public static function cleanup_old_logs($days = 7) {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/f-insights-logs';
        
        if (!file_exists($log_dir)) {
            return;
        }
        
        $files = glob($log_dir . '/debug-*.log');
        $cutoff = time() - ($days * 24 * 60 * 60);
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
            }
        }
    }
}

// Initialize logger
FI_Logger::init();
