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
            'pillar_to_supports' => isset($_POST['pillar_to_supports']),
            'max_pillar_links' => intval($_POST['max_pillar_links'] ?? 5),
            'max_contextual_links' => intval($_POST['max_contextual_links'] ?? 3),
            'placement_type' => get_option('ssp_settings')['link_placement'] ?? 'inline',
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
            
            if (!empty($results) && !isset($results['error'])) {
                $silo_id = $results['silo_id'] ?? null;
                $pillar_post_id = $results['pillar_post_id'] ?? null;
                $support_posts_count = count($results['support_posts'] ?? []);
                
                wp_send_json_success(array(
                    'message' => 'Silo created successfully!',
                    'silo_id' => $silo_id,
                    'pillar_post_id' => $pillar_post_id,
                    'support_posts_count' => $support_posts_count
                ));
            } else {
                wp_send_json_error($results['error'] ?? 'Failed to create silo');
            }
            
        } catch (Exception $e) {
            wp_send_json_error('Error: ' . esc_html($e->getMessage()));
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
            // Remove all links for silo
            global $wpdb;
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
     * Verify nonce
     */
    private function verify_nonce() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ssp_nonce')) {
            wp_send_json_error('Security check failed');
            exit;
        }
    }
}
