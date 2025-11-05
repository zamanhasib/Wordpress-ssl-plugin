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
            placement_type varchar(20) DEFAULT 'natural',
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
        
        // Validate required fields - allow pillar_post_id = 0 for no-pillar silos
        if (empty($data['name']) || !isset($data['pillar_post_id'])) {
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
        
        // Get silo details before deletion for content cleanup
        $silo = $wpdb->get_row($wpdb->prepare("SELECT pillar_post_id FROM $silo_table WHERE id = %d", $silo_id));
        
        // Get silo posts before deleting for content cleanup
        $silo_posts = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id FROM $silo_posts_table WHERE silo_id = %d",
            $silo_id
        ));
        
        // Collect all post IDs (pillar + support posts)
        $all_post_ids = array();
        if ($silo && !empty($silo->pillar_post_id) && intval($silo->pillar_post_id) > 0) {
            $all_post_ids[] = intval($silo->pillar_post_id);
        }
        foreach ($silo_posts as $silo_post) {
            $post_id = intval($silo_post->post_id);
            if ($post_id > 0 && !in_array($post_id, $all_post_ids)) {
                $all_post_ids[] = $post_id;
            }
        }
        
        // Remove links from post content BEFORE database deletion
        if (!empty($all_post_ids)) {
            $link_engine = SSP_Link_Engine::get_instance();
            foreach ($all_post_ids as $post_id) {
                $link_engine->remove_existing_links($post_id, $silo_id);
            }
        }
        
        // Delete related data from database
        $wpdb->delete($silo_posts_table, array('silo_id' => $silo_id), array('%d'));
        $wpdb->delete($links_table, array('silo_id' => $silo_id), array('%d'));
        $wpdb->delete($ai_suggestions_table, array('silo_id' => $silo_id), array('%d'));
        
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
            'placement_type' => sanitize_text_field($data['placement_type'] ?? 'natural'),
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
    
    /**
     * Get anchor text statistics
     */
    public static function get_anchor_statistics($silo_id = null) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ssp_links';
        
        $where = "status = 'active'";
        $params = array();
        
        if ($silo_id) {
            $where .= " AND silo_id = %d";
            $params[] = $silo_id;
        }
        
        $query = "SELECT 
            anchor_text,
            COUNT(*) as usage_count,
            GROUP_CONCAT(DISTINCT source_post_id ORDER BY source_post_id) as post_ids,
            MIN(created_at) as first_used,
            MAX(created_at) as last_used
        FROM $table 
        WHERE $where
        GROUP BY anchor_text
        ORDER BY usage_count DESC";
        
        if (!empty($params)) {
            $results = $wpdb->get_results($wpdb->prepare($query, $params));
        } else {
            $results = $wpdb->get_results($query);
        }
        
        return $results;
    }
    
    /**
     * Get total link count for percentage calculations
     */
    public static function get_total_links_count($silo_id = null) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ssp_links';
        
        if ($silo_id) {
            return $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE status = 'active' AND silo_id = %d",
                $silo_id
            ));
        }
        
        return $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'active'");
    }
    
    /**
     * Get posts where anchor is used
     */
    public static function get_anchor_usage_details($anchor_text, $silo_id = null) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ssp_links';
        
        $where = "anchor_text = %s AND status = 'active'";
        $params = array($anchor_text);
        
        if ($silo_id) {
            $where .= " AND silo_id = %d";
            $params[] = $silo_id;
        }
        
        $query = "SELECT 
            l.id,
            l.source_post_id,
            l.target_post_id,
            l.silo_id,
            l.created_at,
            s.name as silo_name
        FROM $table l
        LEFT JOIN {$wpdb->prefix}ssp_silos s ON l.silo_id = s.id
        WHERE $where
        ORDER BY l.created_at DESC";
        
        return $wpdb->get_results($wpdb->prepare($query, $params));
    }
    
    /**
     * Check if anchor exceeds usage limit
     */
    public static function check_anchor_limit($anchor_text, $max_usage) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ssp_links';
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE anchor_text = %s AND status = 'active'",
            $anchor_text
        ));
        
        return intval($count) >= intval($max_usage);
    }
    
    /**
     * Get orphan posts (posts not in any silo)
     */
    public static function get_orphan_posts($limit = 100, $offset = 0) {
        global $wpdb;
        $silo_posts_table = $wpdb->prefix . 'ssp_silo_posts';
        $silos_table = $wpdb->prefix . 'ssp_silos';
        $posts_in_silos_sql = "SELECT DISTINCT post_id FROM `" . esc_sql($silo_posts_table) . "`
                               UNION
                               SELECT DISTINCT pillar_post_id FROM `" . esc_sql($silos_table) . "`";
        $posts_in_silos = $wpdb->get_col($posts_in_silos_sql);
        $posts_in_silos = array_filter(array_map('intval', $posts_in_silos));

        if (empty($posts_in_silos)) {
            $args = array(
                'post_type' => array('post', 'page'),
                'post_status' => 'publish',
                'numberposts' => $limit > 0 ? $limit : -1,
                'offset' => $offset,
                'orderby' => 'post_date',
                'order' => 'DESC'
            );
            $orphan_posts = get_posts($args);
        } else {
            $posts_in_silos_int = array_map('intval', $posts_in_silos);
            $posts_in_silos_int = array_filter($posts_in_silos_int, function($id) { return $id > 0; });
            
            if (empty($posts_in_silos_int)) {
                $args = array(
                    'post_type' => array('post', 'page'),
                    'post_status' => 'publish',
                    'numberposts' => $limit > 0 ? $limit : -1,
                    'offset' => $offset,
                    'orderby' => 'post_date',
                    'order' => 'DESC'
                );
                return get_posts($args);
            }
            $placeholders = implode(',', array_fill(0, count($posts_in_silos_int), '%d'));
            $query = $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} 
                WHERE post_type IN ('post', 'page') 
                AND post_status = 'publish'
                AND ID NOT IN ($placeholders)
                ORDER BY post_date DESC
                LIMIT %d OFFSET %d",
                array_merge($posts_in_silos_int, array($limit, $offset))
            );
            $orphan_post_ids = $wpdb->get_col($query);
            if (empty($orphan_post_ids)) { return array(); }
            $orphan_posts = array();
            foreach ($orphan_post_ids as $post_id) {
                $post = get_post($post_id);
                if ($post) { $orphan_posts[] = $post; }
            }
        }
        return $orphan_posts;
    }
    
    /**
     * Get count of orphan posts
     */
    public static function get_orphan_posts_count() {
        global $wpdb;
        $silo_posts_table = $wpdb->prefix . 'ssp_silo_posts';
        $silos_table = $wpdb->prefix . 'ssp_silos';
        
        // Get all post IDs that are in silos (either as support posts or pillar posts)
        $posts_in_silos_sql = "SELECT DISTINCT post_id FROM `" . esc_sql($silo_posts_table) . "`
                               UNION
                               SELECT DISTINCT pillar_post_id FROM `" . esc_sql($silos_table) . "`";
        $posts_in_silos = $wpdb->get_col($posts_in_silos_sql);
        $posts_in_silos = array_filter(array_map('intval', $posts_in_silos));

        if (empty($posts_in_silos)) {
            // No posts in silos, so all published posts/pages are orphans
            $count = $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} 
                WHERE post_type IN ('post', 'page') 
                AND post_status = 'publish'"
            );
            return intval($count);
        } else {
            $posts_in_silos_int = array_map('intval', $posts_in_silos);
            $posts_in_silos_int = array_filter($posts_in_silos_int, function($id) { return $id > 0; });
            
            if (empty($posts_in_silos_int)) {
                $count = $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$wpdb->posts} 
                    WHERE post_type IN ('post', 'page') 
                    AND post_status = 'publish'"
                );
                return intval($count);
            }
            
            $placeholders = implode(',', array_fill(0, count($posts_in_silos_int), '%d'));
            $query = $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} 
                WHERE post_type IN ('post', 'page') 
                AND post_status = 'publish'
                AND ID NOT IN ($placeholders)",
                $posts_in_silos_int
            );
            $count = $wpdb->get_var($query);
            return intval($count);
        }
    }
    
    /**
     * Check if a post is a pillar post
     */
    public static function is_pillar_post($post_id) {
        global $wpdb;
        
        $silos_table = $wpdb->prefix . 'ssp_silos';
        
        // Validate table name for security
        if (strpos($silos_table, $wpdb->prefix . 'ssp_') !== 0) {
            return false;
        }
        
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `" . esc_sql($silos_table) . "` WHERE pillar_post_id = %d",
            intval($post_id)
        ));
        
        return intval($result) > 0;
    }
    
    /**
     * Get silo information for a pillar post
     */
    public static function get_pillar_silo_info($post_id) {
        global $wpdb;
        
        $post_id = intval($post_id);
        if ($post_id <= 0) {
            return array(
                'tooltip' => 'Not a pillar page',
                'silos' => array()
            );
        }
        
        $silos_table = $wpdb->prefix . 'ssp_silos';
        
        // Validate table name for security
        if (strpos($silos_table, $wpdb->prefix . 'ssp_') !== 0) {
            return array(
                'tooltip' => 'Not a pillar page',
                'silos' => array()
            );
        }
        
        $silos = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name FROM `" . esc_sql($silos_table) . "` WHERE pillar_post_id = %d",
            $post_id
        ));
        
        if (empty($silos) || !is_array($silos)) {
            return array(
                'tooltip' => 'Not a pillar page',
                'silos' => array()
            );
        }
        
        $silo_names = array();
        foreach ($silos as $silo) {
            if (isset($silo->name) && !empty($silo->name)) {
                // Sanitize silo name for safe display (will be escaped again in display function)
                $silo_names[] = sanitize_text_field($silo->name);
            }
        }
        
        if (empty($silo_names)) {
            return array(
                'tooltip' => 'Not a pillar page',
                'silos' => array()
            );
        }
        
        $tooltip = 'Pillar page for: ' . implode(', ', $silo_names);
        if (count($silos) > 1) {
            $tooltip .= ' (' . count($silos) . ' silos)';
        }
        
        // Tooltip will be escaped when used in esc_attr() in the display function
        // We sanitize above, but don't HTML escape here to avoid double-escaping
        
        return array(
            'tooltip' => $tooltip,
            'silos' => $silos
        );
    }
}

