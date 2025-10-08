<?php
/**
 * Logger class for Semantic Silo Pro
 */

if (!defined('ABSPATH')) {
    exit;
}

class SSP_Logger {
    
    private static $instance = null;
    private $log_table;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        global $wpdb;
        $this->log_table = $wpdb->prefix . 'ssp_logs';
    }
    
    /**
     * Log a message
     */
    public function log($level, $message, $context = array()) {
        global $wpdb;
        
        // Check if log table exists before trying to insert
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $this->log_table)) != $this->log_table) {
            // Table doesn't exist yet, just log to error log
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("SSP [{$level}]: {$message} " . json_encode($context));
            }
            return;
        }
        
        $log_data = array(
            'level' => $level,
            'message' => $message,
            'context' => json_encode($context),
            'created_at' => current_time('mysql')
        );
        
        $result = $wpdb->insert($this->log_table, $log_data);
        
        // Log database errors
        if ($result === false) {
            error_log('SSP Logger: Database error - ' . $wpdb->last_error);
        }
        
        // Also log to WordPress error log for debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("SSP [{$level}]: {$message} " . json_encode($context));
        }
    }
    
    /**
     * Log info message
     */
    public function info($message, $context = array()) {
        $this->log('info', $message, $context);
    }
    
    /**
     * Log warning message
     */
    public function warning($message, $context = array()) {
        $this->log('warning', $message, $context);
    }
    
    /**
     * Log error message
     */
    public function error($message, $context = array()) {
        $this->log('error', $message, $context);
    }
    
    /**
     * Log debug message
     */
    public function debug($message, $context = array()) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $this->log('debug', $message, $context);
        }
    }
    
    /**
     * Get logs with pagination
     */
    public function get_logs($level = null, $limit = 50, $offset = 0) {
        global $wpdb;
        
        $where = '';
        $params = array();
        
        if ($level) {
            $where = 'WHERE level = %s';
            $params[] = $level;
        }
        
        $sql = "SELECT * FROM {$this->log_table} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;
        
        if ($level) {
            return $wpdb->get_results($wpdb->prepare($sql, $params));
        } else {
            return $wpdb->get_results($wpdb->prepare($sql, $params));
        }
    }
    
    /**
     * Clear old logs
     */
    public function clear_old_logs($days = 30) {
        global $wpdb;
        
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->log_table} WHERE created_at < %s",
            date('Y-m-d H:i:s', strtotime("-{$days} days"))
        ));
    }
    
    /**
     * Create log table
     */
    public function create_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        if (!$charset_collate) {
            $charset_collate = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }
        
        $sql = "CREATE TABLE {$this->log_table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            level varchar(20) NOT NULL,
            message text NOT NULL,
            context text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY level (level),
            KEY created_at (created_at)
        ) {$charset_collate};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Drop log table
     */
    public function drop_table() {
        global $wpdb;
        
        $wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS %s", $this->log_table));
    }
}
