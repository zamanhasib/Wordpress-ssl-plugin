<?php
/**
 * Database management class
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class SSP_Database {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Constructor
    }
    
    /**
     * Recreate tables (for troubleshooting)
     */
    public static function recreate_tables() {
        global $wpdb;
        
        // Drop existing tables
        $tables = array(
            $wpdb->prefix . 'ssp_silo_posts',
            $wpdb->prefix . 'ssp_links', 
            $wpdb->prefix . 'ssp_ai_suggestions',
            $wpdb->prefix . 'ssp_excluded_items',
            $wpdb->prefix . 'ssp_logs',
            $wpdb->prefix . 'ssp_silos'
        );
        
        foreach ($tables as $table) {
            if ($table) {
                // Table names cannot use wpdb->prepare (only for values, not identifiers)
                // Validate table name starts with our prefix for security
                if (strpos($table, $wpdb->prefix . 'ssp_') === 0) {
                    $wpdb->query("DROP TABLE IF EXISTS `" . esc_sql($table) . "`");
                }
            }
        }
        
        // Recreate tables
        self::create_tables();
        
        error_log("SSP Database: Tables recreated successfully");
    }
    
    /**
     * Create database tables
     */
    public static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        if (!$charset_collate) {
            $charset_collate = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }
        
        // Silo table
        $silo_table = $wpdb->prefix . 'ssp_silos';
        $silo_sql = "CREATE TABLE $silo_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            pillar_post_id bigint(20) NOT NULL,
            linking_mode varchar(50) NOT NULL,
            setup_method varchar(50) NOT NULL,
            settings longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY pillar_post_id (pillar_post_id),
            KEY linking_mode (linking_mode)
        ) $charset_collate;";
        
        // Silo posts table (many-to-many relationship)
        $silo_posts_table = $wpdb->prefix . 'ssp_silo_posts';
        $silo_posts_sql = "CREATE TABLE $silo_posts_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            silo_id mediumint(9) NOT NULL,
            post_id bigint(20) NOT NULL,
            position int(11) DEFAULT 0,
            added_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY silo_post_unique (silo_id, post_id),
            KEY silo_id (silo_id),
            KEY post_id (post_id),
            FOREIGN KEY (silo_id) REFERENCES {$wpdb->prefix}ssp_silos(id) ON DELETE CASCADE
        ) $charset_collate;";
        
        // Links table
        $links_table = $wpdb->prefix . 'ssp_links';
        $links_sql = "CREATE TABLE $links_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            silo_id mediumint(9) NOT NULL,
            source_post_id bigint(20) NOT NULL,
            target_post_id bigint(20) NOT NULL,
            anchor_text varchar(500) NOT NULL,
            link_position int(11) DEFAULT 0,
            placement_type varchar(20) DEFAULT 'inline',
            ai_generated tinyint(1) DEFAULT 0,
            status varchar(20) DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY silo_id (silo_id),
            KEY source_post_id (source_post_id),
            KEY target_post_id (target_post_id),
            KEY status (status),
            FOREIGN KEY (silo_id) REFERENCES {$wpdb->prefix}ssp_silos(id) ON DELETE CASCADE
        ) $charset_collate;";
        
        // AI suggestions table
        $ai_suggestions_table = $wpdb->prefix . 'ssp_ai_suggestions';
        $ai_suggestions_sql = "CREATE TABLE $ai_suggestions_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            silo_id mediumint(9) NOT NULL,
            source_post_id bigint(20) NOT NULL,
            target_post_id bigint(20) NOT NULL,
            suggested_anchor text NOT NULL,
            suggested_text text,
            confidence_score decimal(3,2) DEFAULT 0.00,
            status varchar(20) DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY silo_id (silo_id),
            KEY source_post_id (source_post_id),
            KEY target_post_id (target_post_id),
            KEY status (status),
            FOREIGN KEY (silo_id) REFERENCES {$wpdb->prefix}ssp_silos(id) ON DELETE CASCADE
        ) $charset_collate;";
        
        // Excluded items table
        $excluded_table = $wpdb->prefix . 'ssp_excluded_items';
        $excluded_sql = "CREATE TABLE $excluded_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            item_type varchar(20) NOT NULL,
            item_value varchar(500) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY item_type (item_type),
            UNIQUE KEY item_unique (item_type, item_value)
        ) $charset_collate;";
        
        // Logs table
        $logs_table = $wpdb->prefix . 'ssp_logs';
        $logs_sql = "CREATE TABLE $logs_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            level varchar(20) NOT NULL,
            message text NOT NULL,
            context text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY level (level),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        dbDelta($silo_sql);
        dbDelta($silo_posts_sql);
        dbDelta($links_sql);
        dbDelta($ai_suggestions_sql);
        dbDelta($excluded_sql);
        dbDelta($logs_sql);
    }
    
    /**
     * Drop database tables
     */
    public static function drop_tables() {
        global $wpdb;
        
        $tables = array(
            $wpdb->prefix . 'ssp_logs',
            $wpdb->prefix . 'ssp_excluded_items',
            $wpdb->prefix . 'ssp_ai_suggestions',
            $wpdb->prefix . 'ssp_links',
            $wpdb->prefix . 'ssp_silo_posts',
            $wpdb->prefix . 'ssp_silos'
        );
        
        foreach ($tables as $table) {
            if ($table) {
                // Table names cannot use wpdb->prepare (only for values, not identifiers)
                // Validate table name starts with our prefix for security
                if (strpos($table, $wpdb->prefix . 'ssp_') === 0) {
                    $wpdb->query("DROP TABLE IF EXISTS `" . esc_sql($table) . "`");
                }
            }
        }
    }
    
    /**
     * Get silo by ID
     */
    public static function get_silo($silo_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ssp_silos';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $silo_id));
    }
    
    /**
     * Get all silos
     */
    public static function get_silos($limit = 50, $offset = 0) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ssp_silos';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $limit,
            $offset
        ));
    }
    
    /**
     * Get total count of silos
     */
    public static function get_silos_count() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ssp_silos';
        return $wpdb->get_var("SELECT COUNT(*) FROM `" . esc_sql($table) . "`");
    }
    
    /**
     * Get silos for a specific post
     */
    public static function get_silos_for_post($post_id) {
        global $wpdb;
        
        $silo_posts_table = $wpdb->prefix . 'ssp_silo_posts';
        $silos_table = $wpdb->prefix . 'ssp_silos';
        
        return $wpdb->get_col($wpdb->prepare(
            "SELECT s.id FROM $silos_table s 
             INNER JOIN $silo_posts_table sp ON s.id = sp.silo_id 
             WHERE sp.post_id = %d",
            $post_id
        ));
    }
    
    /**
     * Get posts in a silo
     */
    public static function get_silo_posts($silo_id, $limit = 50) {
        global $wpdb;
        
        $silo_posts_table = $wpdb->prefix . 'ssp_silo_posts';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $silo_posts_table WHERE silo_id = %d ORDER BY position ASC, added_at ASC LIMIT %d",
            $silo_id,
            $limit
        ));
    }
    
    /**
     * Create new silo
     */
    public static function create_silo($data) {
        global $wpdb;
        
        // Validate required fields
        if (empty($data['name']) || empty($data['pillar_post_id'])) {
            return false;
        }
        
        $table = $wpdb->prefix . 'ssp_silos';
        
        $insert_data = array(
            'name' => sanitize_text_field($data['name']),
            'pillar_post_id' => intval($data['pillar_post_id']),
            'linking_mode' => sanitize_text_field($data['linking_mode'] ?? 'linear'),
            'setup_method' => sanitize_text_field($data['setup_method'] ?? 'manual'),
            'settings' => json_encode($data['settings'] ?? array())
        );
        
        // Validate data length
        if (strlen($insert_data['name']) > 255) {
            error_log('SSP Database Error: Silo name too long');
            return false;
        }
        
        $result = $wpdb->insert($table, $insert_data);
        
        if ($result === false) {
            error_log('SSP Database Error: ' . $wpdb->last_error);
            return false;
        }
        
        return $wpdb->insert_id;
    }
    
    /**
     * Delete silo
     */
    public static function delete_silo($silo_id) {
        global $wpdb;
        
        $silo_table = $wpdb->prefix . 'ssp_silos';
        $silo_posts_table = $wpdb->prefix . 'ssp_silo_posts';
        $links_table = $wpdb->prefix . 'ssp_links';
        $ai_suggestions_table = $wpdb->prefix . 'ssp_ai_suggestions';
        
        // Get silo posts before deleting for content cleanup
        $silo_posts = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id FROM $silo_posts_table WHERE silo_id = %d",
            $silo_id
        ));
        
        // Delete related data first
        $wpdb->delete($silo_posts_table, array('silo_id' => $silo_id), array('%d'));
        $wpdb->delete($links_table, array('silo_id' => $silo_id), array('%d'));
        $wpdb->delete($ai_suggestions_table, array('silo_id' => $silo_id), array('%d'));
        
        // Clean up links from post content
        if (!empty($silo_posts)) {
            $link_engine = SSP_Link_Engine::get_instance();
            foreach ($silo_posts as $silo_post) {
                $link_engine->remove_existing_links($silo_post->post_id, $silo_id);
            }
        }
        
        // Delete the silo
        return $wpdb->delete($silo_table, array('id' => $silo_id), array('%d'));
    }
    
    /**
     * Add posts to silo
     */
    public static function add_posts_to_silo($silo_id, $post_ids, $positions = array()) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ssp_silo_posts';
        $added_count = 0;
        
        error_log("SSP Database: add_posts_to_silo called with silo_id=$silo_id, post_ids=" . json_encode($post_ids));
        
        // Check if table exists, create if not
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) == $table;
        if (!$table_exists) {
            error_log("SSP Database: Table $table does not exist, creating...");
            self::create_tables();
            
            // Verify table was created
            $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) == $table;
            if (!$table_exists) {
                error_log("SSP Database Error: Failed to create table $table");
                return false;
            }
        }
        
        // Validate silo exists
        $silo = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$wpdb->prefix}ssp_silos WHERE id = %d", $silo_id));
        if (!$silo) {
            error_log("SSP Database Error: Silo {$silo_id} does not exist");
            return false;
        }
        
        error_log("SSP Database: Adding " . count($post_ids) . " posts to silo {$silo_id}");
        
        foreach ($post_ids as $index => $post_id) {
            $position = isset($positions[$index]) ? $positions[$index] : $index;
            
            // Check if post already exists in silo
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM $table WHERE silo_id = %d AND post_id = %d",
                $silo_id, $post_id
            ));
            
            if ($existing) {
                error_log("SSP Database: Post {$post_id} already exists in silo {$silo_id}, skipping");
                $added_count++;
                continue;
            }
            
            $result = $wpdb->insert($table, array(
                'silo_id' => intval($silo_id),
                'post_id' => intval($post_id),
                'position' => intval($position)
            ));
            
            if ($result === false) {
                error_log("SSP Database Error: Failed to add post {$post_id} to silo {$silo_id} - " . $wpdb->last_error);
                error_log("SSP Database Error: Error code - " . $wpdb->last_error_no);
            } else {
                $added_count++;
                error_log("SSP Database: Successfully added post {$post_id} to silo {$silo_id} at position {$position}");
            }
        }
        
        error_log("SSP Database: Added {$added_count} posts to silo {$silo_id}");
        return $added_count;
    }
    
    
    /**
     * Save link
     */
    public static function save_link($data) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ssp_links';
        
        $insert_data = array(
            'silo_id' => intval($data['silo_id']),
            'source_post_id' => intval($data['source_post_id']),
            'target_post_id' => intval($data['target_post_id']),
            'anchor_text' => sanitize_text_field($data['anchor_text']),
            'link_position' => intval($data['link_position'] ?? 0),
            'placement_type' => sanitize_text_field($data['placement_type'] ?? 'inline'),
            'ai_generated' => intval($data['ai_generated'] ?? 0)
        );
        
        $result = $wpdb->insert($table, $insert_data);
        
        if ($result === false) {
            error_log('SSP Database Error: Failed to save link - ' . $wpdb->last_error);
            return false;
        }
        
        return $wpdb->insert_id;
    }
    
    /**
     * Get links for post
     */
    public static function get_post_links($post_id, $silo_id = null) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ssp_links';
        $where = "source_post_id = %d AND status = 'active'";
        $params = array($post_id);
        
        if ($silo_id) {
            $where .= " AND silo_id = %d";
            $params[] = $silo_id;
        }
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE $where ORDER BY link_position ASC",
            $params
        ));
    }
    
    /**
     * Get count of posts in a silo
     */
    public static function get_silo_post_count($silo_id) {
        global $wpdb;
        
        $silo_posts_table = $wpdb->prefix . 'ssp_silo_posts';
        
        return $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $silo_posts_table WHERE silo_id = %d",
            $silo_id
        ));
    }
    
    /**
     * Get count of links in a silo
     */
    public static function get_silo_link_count($silo_id) {
        global $wpdb;
        
        $links_table = $wpdb->prefix . 'ssp_links';
        
        return $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $links_table WHERE silo_id = %d AND status = 'active'",
            $silo_id
        ));
    }
    
    /**
     * Remove links for post
     */
    public static function remove_post_links($post_id, $silo_id = null) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ssp_links';
        $where = "source_post_id = %d";
        $params = array($post_id);
        
        if ($silo_id) {
            $where .= " AND silo_id = %d";
            $params[] = $silo_id;
        }
        
        return $wpdb->query($wpdb->prepare(
            "UPDATE $table SET status = 'removed' WHERE $where",
            $params
        ));
    }
}
