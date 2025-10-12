<?php
/**
 * AJAX Handler class - handles all AJAX requests
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class SSP_Ajax_Handler {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Admin AJAX actions
        add_action('wp_ajax_ssp_create_silo', array($this, 'create_silo'));
        add_action('wp_ajax_ssp_delete_silo', array($this, 'delete_silo'));
        add_action('wp_ajax_ssp_generate_links', array($this, 'generate_links'));
        add_action('wp_ajax_ssp_preview_links', array($this, 'preview_links'));
        add_action('wp_ajax_ssp_remove_links', array($this, 'remove_links'));
        add_action('wp_ajax_ssp_test_connection', array($this, 'test_connection'));
        add_action('wp_ajax_ssp_add_exclusion', array($this, 'add_exclusion'));
        add_action('wp_ajax_ssp_remove_exclusion', array($this, 'remove_exclusion'));
        add_action('wp_ajax_ssp_get_posts', array($this, 'get_posts'));
        
        // Frontend AJAX
        add_action('wp_ajax_ssp_track_link_click', array($this, 'track_link_click'));
        add_action('wp_ajax_nopriv_ssp_track_link_click', array($this, 'track_link_click'));
        add_action('wp_ajax_ssp_get_related_content', array($this, 'get_related_content'));
        add_action('wp_ajax_nopriv_ssp_get_related_content', array($this, 'get_related_content'));
        add_action('wp_ajax_ssp_get_silo_details', array($this, 'get_silo_details'));
        add_action('wp_ajax_ssp_recreate_tables', array($this, 'recreate_tables'));
        add_action('wp_ajax_ssp_get_ai_recommendations', array($this, 'get_ai_recommendations'));
        add_action('wp_ajax_ssp_get_rewrite_suggestion', array($this, 'get_rewrite_suggestion'));
        add_action('wp_ajax_ssp_save_pattern', array($this, 'save_pattern'));
        add_action('wp_ajax_ssp_load_pattern', array($this, 'load_pattern'));
        add_action('wp_ajax_ssp_delete_pattern', array($this, 'delete_pattern'));
        add_action('wp_ajax_ssp_get_debug_report', array($this, 'get_debug_report'));
        add_action('wp_ajax_ssp_download_debug_report', array($this, 'download_debug_report'));
        add_action('wp_ajax_ssp_clear_debug_logs', array($this, 'clear_debug_logs'));
        
        // Anchor Report
        add_action('wp_ajax_ssp_get_anchor_report', array($this, 'get_anchor_report'));
        add_action('wp_ajax_ssp_get_anchor_details', array($this, 'get_anchor_details'));
        add_action('wp_ajax_ssp_save_anchor_settings', array($this, 'save_anchor_settings'));
        add_action('wp_ajax_ssp_export_anchor_report', array($this, 'export_anchor_report'));
        
        // Troubleshoot
        add_action('wp_ajax_ssp_check_tables', array($this, 'check_tables'));
    }
    
    /**
     * Create silo
     */
    public function create_silo() {
        $this->verify_nonce();
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            exit;
        }
        
        $setup_method = sanitize_text_field($_POST['setup_method'] ?? '');
        
        // Handle pillar posts array properly
        $pillar_post_ids = array();
        if (isset($_POST['pillar_posts']) && is_array($_POST['pillar_posts'])) {
            $pillar_post_ids = array_map('intval', $_POST['pillar_posts']);
        } else {
            // Handle indexed array format from JavaScript
            foreach ($_POST as $key => $value) {
                if (strpos($key, 'pillar_posts[') === 0 && strpos($key, ']') !== false) {
                    $pillar_post_ids[] = intval($value);
                }
            }
        }
        
        $linking_mode = sanitize_text_field($_POST['linking_mode'] ?? 'linear');
        
        // Validate required fields
        if (empty($setup_method) || empty($pillar_post_ids)) {
            wp_send_json_error('Setup method and pillar posts are required');
            exit;
        }
        
        // Validate pillar posts exist
        foreach ($pillar_post_ids as $post_id) {
            if (!get_post($post_id)) {
                wp_send_json_error('Invalid pillar post ID: ' . esc_html($post_id));
                exit;
            }
        }
        
        // Validate setup method
        if (!in_array($setup_method, ['ai_recommended', 'category_based', 'manual'])) {
            wp_send_json_error('Invalid setup method');
            exit;
        }
        
        // Validate linking mode
        if (!in_array($linking_mode, ['linear', 'chained', 'cross_linking', 'star_hub', 'ai_contextual', 'hub_chain', 'custom'])) {
            wp_send_json_error('Invalid linking mode');
            exit;
        }
        
        
        $settings = array(
            'linking_mode' => $linking_mode,
            'use_ai_anchors' => isset($_POST['use_ai_anchors']),
            'auto_link' => isset($_POST['auto_link']),
            'auto_update' => isset($_POST['auto_update']),
            'supports_to_pillar' => isset($_POST['supports_to_pillar']),
            'pillar_to_supports' => isset($_POST['pillar_to_supports']),
            'max_pillar_links' => intval($_POST['max_pillar_links'] ?? 5),
            'max_contextual_links' => intval($_POST['max_contextual_links'] ?? 3),
            'placement_type' => sanitize_text_field($_POST['link_placement'] ?? 'natural'),
            'auto_assign_category' => isset($_POST['auto_assign_category']),
            'auto_assign_category_id' => intval($_POST['auto_assign_category_id'] ?? 0)
        );
        
        // Handle custom pattern for custom linking mode
        if ($linking_mode === 'custom' && isset($_POST['custom_source']) && isset($_POST['custom_target'])) {
            $custom_pattern = array();
            $sources = $_POST['custom_source'];
            $targets = $_POST['custom_target'];
            
            for ($i = 0; $i < count($sources); $i++) {
                if (!empty($sources[$i]) && !empty($targets[$i])) {
                    $custom_pattern[] = array(
                        'source' => sanitize_text_field($sources[$i]),
                        'target' => sanitize_text_field($targets[$i])
                    );
                }
            }
            
            $settings['custom_pattern'] = $custom_pattern;
        }
        
        $silo_manager = SSP_Silo_Manager::get_instance();
        $results = array();
        
        try {
            switch ($setup_method) {
                case 'ai_recommended':
                    // Check if we have approved recommendations from modal
                    if (isset($_POST['approved_recommendations'])) {
                        $approved_recs = json_decode(stripslashes($_POST['approved_recommendations']), true);
                        if (is_array($approved_recs)) {
                            $results = $silo_manager->create_ai_silo_with_approved_posts($pillar_post_ids, $approved_recs, $settings);
                        } else {
                            $results = $silo_manager->create_ai_silo($pillar_post_ids, $settings);
                        }
                    } else {
                        $results = $silo_manager->create_ai_silo($pillar_post_ids, $settings);
                    }
                    break;
                    
                case 'category_based':
                    $category_id = intval($_POST['category_id'] ?? 0);
                    if (!$category_id) {
                        wp_send_json_error('No category selected');
                        exit;
                    }
                    $results = $silo_manager->create_category_silo($pillar_post_ids, $category_id, $settings);
                    break;
                    
                case 'manual':
                    // Manual method can handle multiple pillar posts
                    
                    // Handle support posts array properly
                    $support_post_ids = array();
                    if (isset($_POST['support_posts']) && is_array($_POST['support_posts'])) {
                        $support_post_ids = array_map('intval', $_POST['support_posts']);
                    } else {
                        // Handle indexed array format from JavaScript
                        foreach ($_POST as $key => $value) {
                            if (strpos($key, 'support_posts[') === 0 && strpos($key, ']') !== false) {
                                $support_post_ids[] = intval($value);
                            }
                        }
                    }
                    if (empty($support_post_ids)) {
                        wp_send_json_error('No support posts selected');
                        exit;
                    }
                    
                    // Create silo for each pillar post
                    $results = array();
                    foreach ($pillar_post_ids as $pillar_post_id) {
                        $result = $silo_manager->create_manual_silo($pillar_post_id, $support_post_ids, $settings);
                        if (isset($result['error'])) {
                            $results[] = $result;
                        } else {
                            $results[] = $result;
                        }
                    }
                    break;
                    
                default:
                    wp_send_json_error('Invalid setup method');
                    exit;
            }
            
            // Handle results based on setup method
            if ($setup_method === 'manual') {
                // Manual returns array of results (one per pillar)
                $success_count = 0;
                $total_support_posts = 0;
                $silo_ids = array();
                
                foreach ($results as $result) {
                    if (isset($result['silo_id'])) {
                        $success_count++;
                        $total_support_posts += count($result['support_posts'] ?? []);
                        $silo_ids[] = $result['silo_id'];
                    }
                }
                
                if ($success_count > 0) {
                    wp_send_json_success(array(
                        'message' => "Created {$success_count} silo(s) successfully!",
                        'silos_created' => $success_count,
                        'silo_ids' => $silo_ids,
                        'total_support_posts' => $total_support_posts
                    ));
                } else {
                    wp_send_json_error('Failed to create silos');
                    exit;
                }
            } else {
                // AI and Category methods also return array of results
                if (!empty($results) && is_array($results)) {
                    $first_result = $results[0] ?? array();
                    
                    if (isset($first_result['error'])) {
                        wp_send_json_error($first_result['error']);
                        exit;
                    } elseif (isset($first_result['silo_id'])) {
                        $success_count = count(array_filter($results, function($r) { return isset($r['silo_id']); }));
                        wp_send_json_success(array(
                            'message' => "Created {$success_count} silo(s) successfully!",
                            'silos_created' => $success_count,
                            'results' => $results
                        ));
                        exit;
                    } else {
                        wp_send_json_error('Failed to create silo');
                        exit;
                    }
                } else {
                    wp_send_json_error('No results returned');
                    exit;
                }
            }
            
        } catch (Exception $e) {
            wp_send_json_error('Error: ' . esc_html($e->getMessage()));
            exit;
        }
    }
    
    /**
     * Delete silo
     */
    public function delete_silo() {
        $this->verify_nonce();
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            exit;
        }
        
        $silo_id = intval($_POST['silo_id'] ?? 0);
        
        if (!$silo_id) {
            wp_send_json_error('Invalid silo ID');
        }
        
        $silo_manager = SSP_Silo_Manager::get_instance();
        $result = $silo_manager->delete_silo($silo_id);
        
        if ($result) {
            wp_send_json_success('Silo deleted successfully');
        } else {
            wp_send_json_error('Failed to delete silo');
        }
    }
    
    /**
     * Generate links for silo
     */
    public function generate_links() {
        $this->verify_nonce();
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            exit;
        }
        
        $silo_id = intval($_POST['silo_id'] ?? 0);
        $post_ids = array_map('intval', $_POST['post_ids'] ?? array());
        
        if (!$silo_id) {
            wp_send_json_error('Invalid silo ID');
            exit;
        }
        
        $link_engine = SSP_Link_Engine::get_instance();
        
        // Remove existing links first
        if (!empty($post_ids)) {
            foreach ($post_ids as $post_id) {
                $link_engine->remove_existing_links($post_id, $silo_id);
            }
        } else {
            // Remove all links for silo
            global $wpdb;
            $silo_posts = SSP_Database::get_silo_posts($silo_id);
            foreach ($silo_posts as $silo_post) {
                $link_engine->remove_existing_links($silo_post->post_id, $silo_id);
            }
        }
        
        // Generate new links
        $links_created = $link_engine->generate_links_for_silo($silo_id, $post_ids);
        
        if ($links_created > 0) {
            wp_send_json_success(array(
                'message' => "Successfully created {$links_created} links",
                'links_created' => $links_created
            ));
        } else {
            wp_send_json_error('No links were created');
        }
    }
    
    /**
     * Preview links
     */
    public function preview_links() {
        $this->verify_nonce();
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            exit;
        }
        
        $silo_id = intval($_POST['silo_id'] ?? 0);
        $post_ids = array_map('intval', $_POST['post_ids'] ?? array());
        
        if (!$silo_id) {
            wp_send_json_error('Invalid silo ID');
            exit;
        }
        
        $link_engine = SSP_Link_Engine::get_instance();
        $previews = $link_engine->preview_links($silo_id, $post_ids);
        
        if (!empty($previews)) {
            wp_send_json_success($previews);
        } else {
            wp_send_json_error('No preview data available');
        }
    }
    
    /**
     * Remove links
     */
    public function remove_links() {
        $this->verify_nonce();
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            exit;
        }
        
        $silo_id = intval($_POST['silo_id'] ?? 0);
        $post_ids = array_map('intval', $_POST['post_ids'] ?? array());
        
        if (!$silo_id) {
            wp_send_json_error('Invalid silo ID');
            exit;
        }
        
        $link_engine = SSP_Link_Engine::get_instance();
        $removed_count = 0;
        
        if (!empty($post_ids)) {
            foreach ($post_ids as $post_id) {
                if ($link_engine->remove_existing_links($post_id, $silo_id)) {
                    $removed_count++;
                }
            }
        } else {
            // Remove all links for silo (from support posts AND pillar post)
            global $wpdb;
            
            // Get silo details to get pillar post ID
            $silo = SSP_Database::get_silo($silo_id);
            if (!$silo) {
                wp_send_json_error('Silo not found');
                exit;
            }
            
            // Remove links from pillar post first
            if ($link_engine->remove_existing_links($silo->pillar_post_id, $silo_id)) {
                $removed_count++;
            }
            
            // Remove links from all support posts
            $silo_posts = SSP_Database::get_silo_posts($silo_id);
            foreach ($silo_posts as $silo_post) {
                if ($link_engine->remove_existing_links($silo_post->post_id, $silo_id)) {
                    $removed_count++;
                }
            }
        }
        
        wp_send_json_success(array(
            'message' => "Removed links from {$removed_count} posts",
            'removed_count' => $removed_count
        ));
    }
    
    /**
     * Test API connection
     */
    public function test_connection() {
        $this->verify_nonce();
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            exit;
        }
        
        $ai_integration = SSP_AI_Integration::get_instance();
        $result = $ai_integration->test_connection();
        
        if ($result) {
            wp_send_json_success('API connection successful');
        } else {
            wp_send_json_error('API connection failed. Please check your API key and settings.');
        }
    }
    
    /**
     * Add exclusion
     */
    public function add_exclusion() {
        $this->verify_nonce();
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            exit;
        }
        
        $item_type = sanitize_text_field($_POST['item_type'] ?? '');
        $item_value = sanitize_text_field($_POST['item_value'] ?? '');
        
        if (empty($item_type) || empty($item_value)) {
            wp_send_json_error('Missing required parameters');
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'ssp_excluded_items';
        
        $result = $wpdb->insert($table, array(
            'item_type' => $item_type,
            'item_value' => $item_value
        ));
        
        if ($result === false) {
            wp_send_json_error('Database error: ' . esc_html($wpdb->last_error));
        } elseif ($result > 0) {
            wp_send_json_success('Exclusion added successfully');
        } else {
            wp_send_json_error('Failed to add exclusion');
        }
    }
    
    /**
     * Remove exclusion
     */
    public function remove_exclusion() {
        $this->verify_nonce();
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            exit;
        }
        
        $item_type = sanitize_text_field($_POST['item_type'] ?? '');
        $item_value = sanitize_text_field($_POST['item_value'] ?? '');
        
        if (empty($item_type) || empty($item_value)) {
            wp_send_json_error('Missing required parameters');
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'ssp_excluded_items';
        
        $result = $wpdb->delete($table, array(
            'item_type' => $item_type,
            'item_value' => $item_value
        ));
        
        if ($result) {
            wp_send_json_success('Exclusion removed successfully');
        } else {
            wp_send_json_error('Failed to remove exclusion');
        }
    }
    
    /**
     * Get posts for dropdown
     */
    public function get_posts() {
        $this->verify_nonce();
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            exit;
        }
        
        $post_type = sanitize_text_field($_POST['post_type'] ?? 'post');
        $search = sanitize_text_field($_POST['search'] ?? '');
        
        $args = array(
            'post_type' => $post_type,
            'post_status' => 'publish',
            'numberposts' => 50,
            'orderby' => 'title',
            'order' => 'ASC'
        );
        
        if (!empty($search)) {
            $args['s'] = $search;
        }
        
        $posts = get_posts($args);
        $results = array();
        
        foreach ($posts as $post) {
            $results[] = array(
                'id' => $post->ID,
                'title' => $post->post_title,
                'url' => get_permalink($post)
            );
        }
        
        wp_send_json_success($results);
    }
    
    /**
     * Get silo details
     */
    public function get_silo_details() {
        $this->verify_nonce();
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            exit;
        }
        
        $silo_id = intval($_POST['silo_id'] ?? 0);
        
        if (!$silo_id) {
            wp_send_json_error('Invalid silo ID');
        }
        
        $silo_manager = SSP_Silo_Manager::get_instance();
        $silo_details = $silo_manager->get_silo_details($silo_id);
        
        if ($silo_details) {
            wp_send_json_success($silo_details);
        } else {
            wp_send_json_error('Silo not found');
        }
    }
    
    /**
     * Track link click (frontend)
     */
    public function track_link_click() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ssp_nonce')) {
            wp_send_json_error('Security check failed');
            exit;
        }
        
        $link_url = sanitize_url($_POST['link_url'] ?? '');
        $source_url = sanitize_url($_POST['source_url'] ?? '');
        
        if (empty($link_url) || empty($source_url)) {
            wp_send_json_error('Missing required data');
            exit;
        }
        
        // Log the click
        $logger = SSP_Logger::get_instance();
        $logger->info('Link clicked', array(
            'link_url' => $link_url,
            'source_url' => $source_url,
            'user_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ));
        
        wp_send_json_success();
    }
    
    /**
     * Get related content (frontend)
     */
    public function get_related_content() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ssp_nonce')) {
            wp_send_json_error('Security check failed');
            exit;
        }
        
        $silo_ids = sanitize_text_field($_POST['silo_ids'] ?? '');
        $post_id = intval($_POST['post_id'] ?? 0);
        
        if (empty($silo_ids) || empty($post_id)) {
            wp_send_json_error('Missing required data');
            exit;
        }
        
        $silo_ids_array = explode(',', $silo_ids);
        $related_posts = array();
        
        foreach ($silo_ids_array as $silo_id) {
            $silo_id = intval(trim($silo_id));
            $posts = SSP_Database::get_silo_posts($silo_id, 3);
            
            foreach ($posts as $post) {
                if ($post->post_id != $post_id) {
                    $related_posts[] = array(
                        'id' => $post->post_id,
                        'title' => get_the_title($post->post_id),
                        'url' => get_permalink($post->post_id)
                    );
                }
            }
        }
        
        // Remove duplicates and limit to 5 posts
        $related_posts = array_unique($related_posts, SORT_REGULAR);
        $related_posts = array_slice($related_posts, 0, 5);
        
        wp_send_json_success($related_posts);
    }
    
    /**
     * Recreate database tables (for troubleshooting)
     */
    public function recreate_tables() {
        $this->verify_nonce();
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            exit;
        }
        
        try {
            SSP_Database::recreate_tables();
            wp_send_json_success('Database tables recreated successfully');
        } catch (Exception $e) {
            wp_send_json_error('Failed to recreate tables: ' . esc_html($e->getMessage()));
        }
    }
    
    /**
     * Check database tables
     */
    public function check_tables() {
        $this->verify_nonce();
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            exit;
        }
        
        global $wpdb;
        
        $required_tables = array(
            'ssp_silos',
            'ssp_silo_posts',
            'ssp_links',
            'ssp_ai_suggestions',
            'ssp_excluded_items',
            'ssp_logs'
        );
        
        $results = array();
        $all_exist = true;
        
        foreach ($required_tables as $table_suffix) {
            $table_name = $wpdb->prefix . $table_suffix;
            $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name;
            
            $results[] = array(
                'table' => $table_name,
                'exists' => $table_exists,
                'status' => $table_exists ? 'OK' : 'MISSING'
            );
            
            if (!$table_exists) {
                $all_exist = false;
            }
        }
        
        if ($all_exist) {
            wp_send_json_success(array(
                'message' => '✅ All database tables exist and are properly configured!',
                'tables' => $results,
                'all_exist' => true
            ));
        } else {
            wp_send_json_success(array(
                'message' => '⚠️ Some tables are missing. Click "Recreate Tables" to fix.',
                'tables' => $results,
                'all_exist' => false
            ));
        }
    }
    
    /**
     * Get AI recommendations for pillar posts (without creating silo)
     */
    public function get_ai_recommendations() {
        $this->verify_nonce();
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            exit;
        }
        
        $pillar_post_ids = array();
        if (isset($_POST['pillar_post_ids']) && is_array($_POST['pillar_post_ids'])) {
            $pillar_post_ids = array_map('intval', $_POST['pillar_post_ids']);
        } else {
            // Handle indexed array format
            foreach ($_POST as $key => $value) {
                if (strpos($key, 'pillar_post_ids[') === 0) {
                    $pillar_post_ids[] = intval($value);
                }
            }
        }
        
        if (empty($pillar_post_ids)) {
            wp_send_json_error('No pillar posts provided');
            exit;
        }
        
        $ai_integration = SSP_AI_Integration::get_instance();
        $all_recommendations = array();
        
        foreach ($pillar_post_ids as $pillar_post_id) {
            $pillar_post = get_post($pillar_post_id);
            if (!$pillar_post) {
                continue;
            }
            
            $recommendations = $ai_integration->get_relevant_posts($pillar_post_id, 20);
            
            if ($recommendations) {
                $all_recommendations[] = array(
                    'pillar_id' => $pillar_post_id,
                    'pillar_title' => $pillar_post->post_title,
                    'recommendations' => array_map(function($post) {
                        return array(
                            'id' => $post->ID,
                            'title' => $post->post_title,
                            'relevance' => isset($post->relevance_score) ? $post->relevance_score : 0.8,
                            'excerpt' => wp_trim_words(get_the_excerpt($post->ID), 20)
                        );
                    }, $recommendations)
                );
            }
        }
        
        if (!empty($all_recommendations)) {
            wp_send_json_success(array(
                'recommendations' => $all_recommendations
            ));
        } else {
            wp_send_json_error('No recommendations found. Check if AI is configured correctly.');
        }
    }
    
    /**
     * Get AI rewrite suggestion for content
     */
    public function get_rewrite_suggestion() {
        $this->verify_nonce();
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            exit;
        }
        
        $original_text = sanitize_textarea_field($_POST['original_text'] ?? '');
        $target_post_id = intval($_POST['target_post_id'] ?? 0);
        $anchor_text = sanitize_text_field($_POST['anchor_text'] ?? '');
        
        if (empty($original_text) || empty($target_post_id)) {
            wp_send_json_error('Missing required parameters');
            exit;
        }
        
        $ai_integration = SSP_AI_Integration::get_instance();
        $rewrite = $ai_integration->get_rewrite_suggestions($original_text, $target_post_id, $anchor_text);
        
        if ($rewrite) {
            wp_send_json_success(array(
                'rewrite' => $rewrite,
                'original' => $original_text
            ));
        } else {
            wp_send_json_error('Failed to generate rewrite. Check AI configuration.');
        }
    }
    
    /**
     * Save custom linking pattern
     */
    public function save_pattern() {
        $this->verify_nonce();
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            exit;
        }
        
        $pattern_name = sanitize_text_field($_POST['pattern_name'] ?? '');
        $pattern_rules = $_POST['pattern_rules'] ?? '';
        
        if (empty($pattern_name)) {
            wp_send_json_error('Pattern name is required');
            exit;
        }
        
        // Decode and validate pattern rules
        $rules = json_decode(stripslashes($pattern_rules), true);
        if (!is_array($rules)) {
            wp_send_json_error('Invalid pattern rules');
            exit;
        }
        
        // Get existing patterns
        $saved_patterns = get_option('ssp_saved_patterns', array());
        
        // Add or update pattern
        $saved_patterns[$pattern_name] = array(
            'rules' => $rules,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        );
        
        // Save to database
        update_option('ssp_saved_patterns', $saved_patterns);
        
        wp_send_json_success(array(
            'message' => 'Pattern saved successfully',
            'pattern_name' => $pattern_name
        ));
    }
    
    /**
     * Load custom linking pattern
     */
    public function load_pattern() {
        $this->verify_nonce();
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            exit;
        }
        
        $pattern_name = sanitize_text_field($_POST['pattern_name'] ?? '');
        
        if (empty($pattern_name)) {
            wp_send_json_error('Pattern name is required');
            exit;
        }
        
        // Get saved patterns
        $saved_patterns = get_option('ssp_saved_patterns', array());
        
        if (!isset($saved_patterns[$pattern_name])) {
            wp_send_json_error('Pattern not found');
            exit;
        }
        
        wp_send_json_success(array(
            'pattern' => $saved_patterns[$pattern_name],
            'pattern_name' => $pattern_name
        ));
    }
    
    /**
     * Delete custom linking pattern
     */
    public function delete_pattern() {
        $this->verify_nonce();
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            exit;
        }
        
        $pattern_name = sanitize_text_field($_POST['pattern_name'] ?? '');
        
        if (empty($pattern_name)) {
            wp_send_json_error('Pattern name is required');
            exit;
        }
        
        // Get saved patterns
        $saved_patterns = get_option('ssp_saved_patterns', array());
        
        if (!isset($saved_patterns[$pattern_name])) {
            wp_send_json_error('Pattern not found');
            exit;
        }
        
        // Remove pattern
        unset($saved_patterns[$pattern_name]);
        update_option('ssp_saved_patterns', $saved_patterns);
        
        wp_send_json_success('Pattern deleted successfully');
    }
    
    /**
     * Get debug report
     */
    public function get_debug_report() {
        $this->verify_nonce();
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            exit;
        }
        
        global $wpdb;
        $logs_table = $wpdb->prefix . 'ssp_logs';
        
        // Get last 100 log entries
        $logs = $wpdb->get_results(
            "SELECT * FROM `{$logs_table}` ORDER BY created_at DESC LIMIT 100",
            ARRAY_A
        );
        
        if ($logs) {
            wp_send_json_success(array('logs' => $logs));
        } else {
            wp_send_json_success(array('logs' => array(), 'message' => 'No logs found'));
        }
    }
    
    /**
     * Download debug report as text file
     */
    public function download_debug_report() {
        $this->verify_nonce();
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        global $wpdb;
        $logs_table = $wpdb->prefix . 'ssp_logs';
        
        // Get all logs
        $logs = $wpdb->get_results(
            "SELECT * FROM `{$logs_table}` ORDER BY created_at DESC",
            ARRAY_A
        );
        
        // Generate report text
        $report = "SEMANTIC SILO PRO - DEBUG REPORT\n";
        $report .= "Generated: " . current_time('mysql') . "\n";
        $report .= "WordPress Version: " . get_bloginfo('version') . "\n";
        $report .= "Plugin Version: " . SSP_VERSION . "\n";
        $report .= "PHP Version: " . PHP_VERSION . "\n";
        $report .= "=" . str_repeat("=", 70) . "\n\n";
        
        if (!empty($logs)) {
            foreach ($logs as $log) {
                $report .= "[" . $log['created_at'] . "] ";
                $report .= strtoupper($log['level']) . ": ";
                $report .= $log['message'];
                if (!empty($log['context'])) {
                    $report .= " | Context: " . $log['context'];
                }
                $report .= "\n";
            }
        } else {
            $report .= "No logs found.\n";
        }
        
        // Send as downloadable file
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="semantic-silo-pro-debug-' . date('Y-m-d-His') . '.txt"');
        header('Content-Length: ' . strlen($report));
        echo $report;
        exit;
    }
    
    /**
     * Clear all debug logs
     */
    public function clear_debug_logs() {
        $this->verify_nonce();
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            exit;
        }
        
        global $wpdb;
        $logs_table = $wpdb->prefix . 'ssp_logs';
        
        $result = $wpdb->query("TRUNCATE TABLE `{$logs_table}`");
        
        if ($result !== false) {
            wp_send_json_success('Debug logs cleared successfully');
        } else {
            wp_send_json_error('Failed to clear logs');
        }
    }
    
    /**
     * Get anchor report data
     */
    public function get_anchor_report() {
        $this->verify_nonce();
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            exit;
        }
        
        $silo_id = !empty($_POST['silo_id']) ? intval($_POST['silo_id']) : null;
        $status_filter = isset($_POST['status_filter']) ? sanitize_text_field($_POST['status_filter']) : 'all';
        
        // Get anchor statistics
        $anchors = SSP_Database::get_anchor_statistics($silo_id);
        $total_links = SSP_Database::get_total_links_count($silo_id);
        
        // Get settings
        $settings = get_option('ssp_anchor_settings', array(
            'max_usage_per_anchor' => 10,
            'warning_threshold' => 7
        ));
        
        // Handle empty results
        if (empty($anchors)) {
            wp_send_json_success(array(
                'anchors' => array(),
                'stats' => array(
                    'total' => 0,
                    'healthy' => 0,
                    'warning' => 0,
                    'danger' => 0
                ),
                'total_links' => 0,
                'settings' => $settings
            ));
            return;
        }
        
        $processed_anchors = array();
        $stats = array(
            'total' => 0,
            'healthy' => 0,
            'warning' => 0,
            'danger' => 0
        );
        
        foreach ($anchors as $anchor) {
            $usage_count = intval($anchor->usage_count);
            $percentage = $total_links > 0 ? ($usage_count / $total_links) * 100 : 0;
            
            // Determine status
            if ($usage_count >= intval($settings['max_usage_per_anchor'])) {
                $status = 'danger';
                $status_label = '🔴 Over-used';
                $stats['danger']++;
            } elseif ($usage_count >= intval($settings['warning_threshold'])) {
                $status = 'warning';
                $status_label = '⚠️ Warning';
                $stats['warning']++;
            } else {
                $status = 'good';
                $status_label = '✅ Healthy';
                $stats['healthy']++;
            }
            
            $stats['total']++;
            
            // Apply status filter
            if ($status_filter !== 'all' && $status !== $status_filter) {
                continue;
            }
            
            // Calculate health score (0-100)
            $max_usage = intval($settings['max_usage_per_anchor']);
            if ($max_usage > 0) {
                $health_score = max(0, 100 - (($usage_count / $max_usage) * 100));
            } else {
                $health_score = 100; // If no limit set, consider healthy
            }
            
            // Get post IDs
            $post_ids = !empty($anchor->post_ids) ? explode(',', $anchor->post_ids) : array();
            
            $processed_anchors[] = array(
                'anchor_text' => $anchor->anchor_text,
                'usage_count' => $usage_count,
                'percentage' => round($percentage, 2),
                'status' => $status,
                'status_label' => $status_label,
                'health_score' => round($health_score, 0),
                'post_ids' => !empty($post_ids) ? array_map('intval', $post_ids) : array(),
                'post_count' => count($post_ids),
                'first_used' => $anchor->first_used,
                'last_used' => $anchor->last_used
            );
        }
        
        wp_send_json_success(array(
            'anchors' => $processed_anchors,
            'stats' => $stats,
            'total_links' => $total_links,
            'settings' => $settings
        ));
    }
    
    /**
     * Get anchor details
     */
    public function get_anchor_details() {
        $this->verify_nonce();
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            exit;
        }
        
        $anchor_text = isset($_POST['anchor_text']) ? sanitize_text_field($_POST['anchor_text']) : '';
        $silo_id = !empty($_POST['silo_id']) ? intval($_POST['silo_id']) : null;
        
        if (empty($anchor_text)) {
            wp_send_json_error('Anchor text is required');
            exit;
        }
        
        $details = SSP_Database::get_anchor_usage_details($anchor_text, $silo_id);
        
        $formatted_details = array();
        foreach ($details as $detail) {
            $source_post = get_post($detail->source_post_id);
            $target_post = get_post($detail->target_post_id);
            
            $formatted_details[] = array(
                'id' => $detail->id,
                'source_post_id' => $detail->source_post_id,
                'source_post_title' => $source_post ? $source_post->post_title : 'Unknown',
                'source_post_url' => $source_post ? get_permalink($source_post) : '',
                'target_post_id' => $detail->target_post_id,
                'target_post_title' => $target_post ? $target_post->post_title : 'Unknown',
                'target_post_url' => $target_post ? get_permalink($target_post) : '',
                'silo_name' => $detail->silo_name,
                'created_at' => $detail->created_at
            );
        }
        
        wp_send_json_success(array(
            'anchor_text' => $anchor_text,
            'details' => $formatted_details,
            'total' => count($formatted_details)
        ));
    }
    
    /**
     * Save anchor settings
     */
    public function save_anchor_settings() {
        $this->verify_nonce();
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            exit;
        }
        
        $max_usage = intval($_POST['max_usage_per_anchor'] ?? 10);
        $warning_threshold = intval($_POST['warning_threshold'] ?? 7);
        
        // Validate settings
        if ($max_usage < 1) {
            wp_send_json_error('Max usage must be at least 1');
            exit;
        }
        
        if ($warning_threshold < 1) {
            wp_send_json_error('Warning threshold must be at least 1');
            exit;
        }
        
        if ($warning_threshold > $max_usage) {
            wp_send_json_error('Warning threshold cannot exceed max usage');
            exit;
        }
        
        $settings = array(
            'max_usage_per_anchor' => $max_usage,
            'warning_threshold' => $warning_threshold
        );
        
        update_option('ssp_anchor_settings', $settings);
        
        wp_send_json_success('Settings saved successfully');
    }
    
    /**
     * Export anchor report as CSV
     */
    public function export_anchor_report() {
        $this->verify_nonce();
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            exit;
        }
        
        $silo_id = !empty($_POST['silo_id']) ? intval($_POST['silo_id']) : null;
        
        $anchors = SSP_Database::get_anchor_statistics($silo_id);
        $total_links = SSP_Database::get_total_links_count($silo_id);
        
        $settings = get_option('ssp_anchor_settings', array(
            'max_usage_per_anchor' => 10,
            'warning_threshold' => 7
        ));
        
        $csv_data = array();
        $csv_data[] = array('Anchor Text', 'Usage Count', 'Percentage', 'Status', 'Health Score', 'First Used', 'Last Used');
        
        foreach ($anchors as $anchor) {
            $usage_count = intval($anchor->usage_count);
            $percentage = $total_links > 0 ? ($usage_count / $total_links) * 100 : 0;
            
            if ($usage_count >= intval($settings['max_usage_per_anchor'])) {
                $status = 'Over-used';
            } elseif ($usage_count >= intval($settings['warning_threshold'])) {
                $status = 'Warning';
            } else {
                $status = 'Healthy';
            }
            
            // Calculate health score with division by zero protection
            $max_usage = intval($settings['max_usage_per_anchor']);
            if ($max_usage > 0) {
                $health_score = max(0, 100 - (($usage_count / $max_usage) * 100));
            } else {
                $health_score = 100;
            }
            
            $csv_data[] = array(
                $anchor->anchor_text,
                $usage_count,
                round($percentage, 2) . '%',
                $status,
                round($health_score, 0) . '%',
                $anchor->first_used,
                $anchor->last_used
            );
        }
        
        wp_send_json_success(array('csv_data' => $csv_data));
    }
    
    /**
     * Verify nonce
     */
    private function verify_nonce() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ssp_nonce')) {
            wp_send_json_error('Security check failed');
            exit;
        }
    }
}
