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
        
        // Anchor Management
        add_action('wp_ajax_ssp_get_silo_anchors', array($this, 'get_silo_anchors'));
        add_action('wp_ajax_ssp_get_ai_anchor_suggestions', array($this, 'get_ai_anchor_suggestions'));
        add_action('wp_ajax_ssp_update_anchor_text', array($this, 'update_anchor_text'));
        
        // Troubleshoot
        add_action('wp_ajax_ssp_check_tables', array($this, 'check_tables'));
        
        // Orphan Posts
        add_action('wp_ajax_ssp_assign_orphan_posts', array($this, 'assign_orphan_posts'));
        add_action('wp_ajax_ssp_get_orphan_posts', array($this, 'get_orphan_posts'));
        add_action('wp_ajax_ssp_get_orphan_count', array($this, 'get_orphan_count'));
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
        
        // Handle pillar post (now single radio button instead of multiple checkboxes)
        $pillar_post_ids = array();
        // First check for single radio button value
        if (isset($_POST['pillar_post']) && !empty($_POST['pillar_post'])) {
            $pillar_post_ids[] = intval($_POST['pillar_post']);
        } elseif (isset($_POST['pillar_posts']) && is_array($_POST['pillar_posts'])) {
            // Fallback for old checkbox format (backwards compatibility)
            $pillar_post_ids = array_map('intval', $_POST['pillar_posts']);
        } else {
            // Handle indexed array format from JavaScript (backwards compatibility)
            foreach ($_POST as $key => $value) {
                if (strpos($key, 'pillar_posts[') === 0 && strpos($key, ']') !== false) {
                    $pillar_post_ids[] = intval($value);
                }
            }
        }
        
        $linking_mode = sanitize_text_field($_POST['linking_mode'] ?? 'linear');
        
        // Validate required fields (allow no pillar only for manual method)
        if (empty($setup_method)) {
            wp_send_json_error('Setup method is required');
            exit;
        }
        if (empty($pillar_post_ids) && $setup_method !== 'manual') {
            wp_send_json_error('Pillar posts are required for this setup method');
            exit;
        }
        
        // Validate pillar posts exist (if provided)
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
        // Disallow pillar-dependent modes without pillar
        if (empty($pillar_post_ids) && in_array($linking_mode, ['star_hub','hub_chain'])) {
            wp_send_json_error('Selected linking mode requires a pillar post');
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
                    // Manual method can handle multiple pillar posts or none (no-pillar silo)
                    
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
                    // Require at least 2 support posts for no-pillar silos
                    if (empty($pillar_post_ids) && count($support_post_ids) < 2) {
                        wp_send_json_error('At least 2 support posts are required when no pillar is selected');
                        exit;
                    }
                    
                    $results = array();
                    if (empty($pillar_post_ids)) {
                        // Create a single silo without pillar
                        $name = 'Manual Silo (no pillar)';
                        $silo_data = array(
                            'name' => $name,
                            'pillar_post_id' => 0,
                            'linking_mode' => $linking_mode,
                            'setup_method' => 'manual',
                            'settings' => $settings
                        );
                        $silo_id = SSP_Database::create_silo($silo_data);
                        if (!$silo_id) {
                            wp_send_json_error('Failed to create silo');
                            exit;
                        }
                        $posts_added = SSP_Database::add_posts_to_silo($silo_id, $support_post_ids);
                        $results[] = array(
                            'silo_id' => $silo_id,
                            'pillar_post_id' => 0,
                            'posts_added' => $posts_added,
                            'status' => 'success'
                        );
                    } else {
                        // Create silo for each pillar post
                        foreach ($pillar_post_ids as $pillar_post_id) {
                            $result = $silo_manager->create_manual_silo($pillar_post_id, $support_post_ids, $settings);
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
        
        // Remove existing links first (this marks them as 'removed' in database)
        if (!empty($post_ids)) {
            foreach ($post_ids as $post_id) {
                $link_engine->remove_existing_links($post_id, $silo_id);
            }
        } else {
            // Remove all links for silo
            global $wpdb;
            
            // Get silo to include pillar post
            $silo = SSP_Database::get_silo($silo_id);
            $all_post_ids = array();
            
            // Add pillar post if exists
            if ($silo && !empty($silo->pillar_post_id) && intval($silo->pillar_post_id) > 0) {
                $all_post_ids[] = intval($silo->pillar_post_id);
            }
            
            // Add support posts
            $silo_posts = SSP_Database::get_silo_posts($silo_id);
            foreach ($silo_posts as $silo_post) {
                $post_id = intval($silo_post->post_id);
                if ($post_id > 0 && !in_array($post_id, $all_post_ids)) {
                    $all_post_ids[] = $post_id;
                }
            }
            
            foreach ($all_post_ids as $post_id) {
                $link_engine->remove_existing_links($post_id, $silo_id);
            }
        }
        
        // Permanently delete old 'removed' links from database to ensure clean regeneration
        global $wpdb;
        $links_table = $wpdb->prefix . 'ssp_links';
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $links_table WHERE silo_id = %d AND status = 'removed'",
            $silo_id
        ));
        
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
            
            $all_post_ids = array();
            
            // Add pillar post if exists
            if (!empty($silo->pillar_post_id) && intval($silo->pillar_post_id) > 0) {
                $all_post_ids[] = intval($silo->pillar_post_id);
            }
            
            // Add support posts
            $silo_posts = SSP_Database::get_silo_posts($silo_id);
            foreach ($silo_posts as $silo_post) {
                $post_id = intval($silo_post->post_id);
                if ($post_id > 0 && !in_array($post_id, $all_post_ids)) {
                    $all_post_ids[] = $post_id;
                }
            }
            
            // Remove links from all posts
            foreach ($all_post_ids as $post_id) {
                if ($link_engine->remove_existing_links($post_id, $silo_id)) {
                    $removed_count++;
                }
            }
        }
        
        // Permanently delete old 'removed' links from database to clean up
        global $wpdb;
        $links_table = $wpdb->prefix . 'ssp_links';
        $deleted_count = $wpdb->query($wpdb->prepare(
            "DELETE FROM $links_table WHERE silo_id = %d AND status = 'removed'",
            $silo_id
        ));
        
        wp_send_json_success(array(
            'message' => "Removed links from {$removed_count} posts" . ($deleted_count > 0 ? " and deleted {$deleted_count} old link records" : ''),
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
        
        // CRITICAL: Reload settings first in case user just saved them
        // The singleton instance persists, so it may have old settings
        $ai_integration->reload_settings();
        
        // Check if API key is configured after reloading
        if (!$ai_integration->is_api_configured()) {
            // Get settings directly from database for error message
            $settings = get_option('ssp_settings', array());
            $api_key = isset($settings['openai_api_key']) ? trim($settings['openai_api_key']) : '';
            
            // Debug logging
            error_log('SSP AI Test Connection: API key check failed');
            error_log('SSP AI Test Connection: Key in DB - length: ' . strlen($api_key) . ', empty: ' . (empty($api_key) ? 'YES' : 'NO'));
            if (!empty($api_key)) {
                $key_preview = substr($api_key, 0, 5) . '...';
                $key_length = strlen($api_key);
                error_log('SSP AI Test Connection: Key preview: ' . $key_preview . ', length: ' . $key_length);
            }
            
            if (empty($api_key)) {
                wp_send_json_error('API key is not configured. Please enter your OpenAI/OpenRouter API key in the settings and click "Save Settings" first.');
                exit;
            } else {
                // API key exists but validation failed - provide more helpful message
                $key_length = strlen($api_key);
                $key_preview = substr($api_key, 0, 5) . '...';
                $key_ends_with = substr($api_key, -5);
                
                $debug_info = 'Length: ' . $key_length . ' chars. Preview: ' . $key_preview . '...' . $key_ends_with;
                
                // Check what type of key it might be
                $key_type = 'Unknown';
                if (strpos($api_key, 'sk-') === 0) {
                    $key_type = 'OpenAI format (starts with sk-)';
                } elseif (preg_match('/^[a-zA-Z0-9_-]+$/', $api_key)) {
                    $key_type = 'OpenRouter format (alphanumeric)';
                } else {
                    $key_type = 'Contains special characters';
                }
                
                wp_send_json_error('API key format validation failed. ' . $debug_info . '. Type: ' . $key_type . '. (OpenAI keys should start with "sk-" and be 51+ chars, OpenRouter keys should be alphanumeric and 20+ chars)');
                exit;
            }
        }
        
        $result = $ai_integration->test_connection();
        
        if ($result) {
            wp_send_json_success('✓ Connection successful! AI API is working correctly.');
        } else {
            // Get more specific error information
            $error_details = $ai_integration->get_last_error();
            
            if (!empty($error_details)) {
                wp_send_json_error($error_details);
            } else {
                wp_send_json_error('API connection failed. Please check your API key, network connection, and API settings. Check error logs for detailed error messages.');
            }
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
        
        // Check if AI is configured
        $settings = get_option('ssp_settings', array());
        $api_key = $settings['openai_api_key'] ?? '';
        
        if (empty($api_key)) {
            wp_send_json_error('AI is not configured. Please add your OpenAI/OpenRouter API key in the Settings tab before using AI recommendations. Alternatively, use "Manual" or "Category Based" setup methods if you don\'t have an AI API key.');
            exit;
        }
        
        // Check if there are any posts available to recommend (excluding pillar posts)
        $available_posts = get_posts(array(
            'post_type' => array('post', 'page'),
            'post_status' => 'publish',
            'post__not_in' => $pillar_post_ids,
            'fields' => 'ids',
            'numberposts' => 1  // Just check if any exist, we don't need all of them
        ));
        
        if (empty($available_posts) || !is_array($available_posts) || count($available_posts) === 0) {
            wp_send_json_error('No other posts or pages found to recommend. Please create some published posts or pages before using AI recommendations.');
            exit;
        }
        
        $all_recommendations = array();
        $errors = array();
        
        foreach ($pillar_post_ids as $pillar_post_id) {
            $pillar_post = get_post($pillar_post_id);
            if (!$pillar_post) {
                $errors[] = "Pillar post {$pillar_post_id} not found";
                continue;
            }
            
            $recommendations = $ai_integration->get_relevant_posts($pillar_post_id, 20);
            
            // Check if we got recommendations or if it's an error
            if ($recommendations === false) {
                // False means API error (missing key, network error, etc.)
                $last_error = method_exists($ai_integration, 'get_last_error') ? $ai_integration->get_last_error() : '';
                if (!empty($last_error)) {
                    $errors[] = "AI API error for '{$pillar_post->post_title}': " . $last_error;
                } else {
                    $errors[] = "AI API error for '{$pillar_post->post_title}'. Please check your API key configuration and try again. Check error logs for details.";
                }
            } else if (is_array($recommendations) && !empty($recommendations)) {
                // Success - got recommendations
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
            } else {
                // Empty array returned - AI couldn't find relevant posts OR no posts available
                // Check if there are any posts to recommend first
                $available_posts_count = get_posts(array(
                    'post_type' => array('post', 'page'),
                    'post_status' => 'publish',
                    'post__not_in' => array($pillar_post_id),
                    'fields' => 'ids',
                    'numberposts' => 1
                ));
                
                if (empty($available_posts_count)) {
                    $errors[] = "No other posts or pages found to recommend for '{$pillar_post->post_title}'. Please create some published posts or pages first.";
                } else {
                    // AI couldn't find relevant posts
                    $errors[] = "No relevant posts found for '{$pillar_post->post_title}'. The AI may not have found any semantically similar posts. Try creating more related content or check your AI API configuration.";
                }
            }
        }
        
        if (!empty($all_recommendations)) {
            wp_send_json_success(array(
                'recommendations' => $all_recommendations,
                'warnings' => $errors
            ));
        } else {
            // Provide more detailed error message
            if (!empty($errors)) {
                $error_message = implode('. ', $errors);
            } else {
                $error_message = 'No recommendations found. ';
            }
            
            $error_message .= 'Please check: 1) Your AI API key is configured correctly in Settings, 2) The API connection is working (test it in Settings), 3) You have other published posts that could be relevant.';
            
            wp_send_json_error($error_message);
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
     * Assign orphan posts to silo
     */
    public function assign_orphan_posts() {
        $this->verify_nonce();
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            exit;
        }
        
        $silo_id = intval($_POST['silo_id'] ?? 0);
        $post_ids = isset($_POST['post_ids']) && is_array($_POST['post_ids']) 
            ? array_map('intval', $_POST['post_ids']) 
            : array();
        
        if (!$silo_id) {
            wp_send_json_error('Please select a silo');
            exit;
        }
        
        if (empty($post_ids)) {
            wp_send_json_error('Please select at least one post');
            exit;
        }
        
        // Validate silo exists
        $silo = SSP_Database::get_silo($silo_id);
        if (!$silo) {
            wp_send_json_error('Silo not found');
            exit;
        }
        
        // Get silo posts once (outside loop for efficiency)
        // Use high limit to get all posts in silo (not just 50)
        $silo_posts = SSP_Database::get_silo_posts($silo_id, 10000);
        $silo_post_ids = array_map(function($sp) { return intval($sp->post_id); }, $silo_posts);
        
        // Validate posts exist and are published
        $valid_posts = array();
        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if ($post && $post->post_status === 'publish') {
                // Check if post is already in this silo (efficient check)
                $already_in_silo = in_array($post_id, $silo_post_ids);
                
                // Also check if it's the pillar post
                if ($silo->pillar_post_id == $post_id) {
                    $already_in_silo = true;
                }
                
                if (!$already_in_silo) {
                    $valid_posts[] = $post_id;
                }
            }
        }
        
        if (empty($valid_posts)) {
            wp_send_json_error('No valid posts to assign (posts may already be in this silo)');
            exit;
        }
        
        // Add posts to silo
        $posts_added = SSP_Database::add_posts_to_silo($silo_id, $valid_posts);
        
        if ($posts_added > 0) {
            $orphan_count = SSP_Database::get_orphan_posts_count();
            wp_send_json_success(array(
                'message' => "Successfully assigned {$posts_added} post(s) to silo",
                'posts_added' => $posts_added,
                'post_ids' => $valid_posts,
                'orphan_count' => intval($orphan_count)
            ));
        } else {
            wp_send_json_error('Failed to assign posts to silo');
        }
    }
    
    /**
     * Get orphan posts (AJAX)
     */
    public function get_orphan_posts() {
        $this->verify_nonce();
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            exit;
        }
        
        $limit = intval($_POST['limit'] ?? 100);
        $offset = intval($_POST['offset'] ?? 0);
        
        $orphan_posts = SSP_Database::get_orphan_posts($limit, $offset);
        $total = SSP_Database::get_orphan_posts_count();
        
        $formatted_posts = array();
        foreach ($orphan_posts as $post) {
            if (!$post || !isset($post->ID)) {
                continue; // Skip invalid post objects
            }
            
            $author = get_userdata($post->post_author);
            $post_date = !empty($post->post_date) ? date('Y-m-d', strtotime($post->post_date)) : '';
            
            $formatted_posts[] = array(
                'id' => intval($post->ID),
                'title' => $post->post_title ?: '(No Title)',
                'type' => $post->post_type ?? 'post',
                'date' => $post_date,
                'author' => $author ? $author->display_name : 'Unknown',
                'edit_link' => get_edit_post_link($post->ID),
                'view_link' => get_permalink($post->ID)
            );
        }
        
        wp_send_json_success(array(
            'posts' => $formatted_posts,
            'total' => $total
        ));
    }
    
    /**
     * Get orphan posts count (AJAX)
     */
    public function get_orphan_count() {
        $this->verify_nonce();
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            exit;
        }
        
        $total = SSP_Database::get_orphan_posts_count();
        
        wp_send_json_success(array(
            'total' => $total
        ));
    }
    
    /**
     * Get all anchors for a silo
     */
    public function get_silo_anchors() {
        $this->verify_nonce();
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            exit;
        }
        
        $silo_id = intval($_POST['silo_id'] ?? 0);
        
        if (!$silo_id) {
            wp_send_json_error('Silo ID is required');
            exit;
        }
        
        // Validate silo exists
        $silo = SSP_Database::get_silo($silo_id);
        if (!$silo) {
            wp_send_json_error('Silo not found');
            exit;
        }
        
        // Get all links for this silo
        global $wpdb;
        $links_table = $wpdb->prefix . 'ssp_links';
        $links = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$links_table} WHERE silo_id = %d AND status = 'active' ORDER BY source_post_id, target_post_id",
            $silo_id
        ));
        
        if (!$links) {
            wp_send_json_success(array('anchors' => array(), 'message' => 'No links found for this silo'));
            exit;
        }
        
        // Format links with post information
        $formatted_anchors = array();
        foreach ($links as $link) {
            $source_post = get_post($link->source_post_id);
            $target_post = get_post($link->target_post_id);
            
            if (!$source_post || !$target_post) {
                continue; // Skip if posts don't exist
            }
            
            // Ensure anchor_text is not null and is a string
            $anchor_text = isset($link->anchor_text) ? trim($link->anchor_text) : '';
            if (empty($anchor_text)) {
                $anchor_text = '(No anchor text)'; // Fallback for empty anchors
            }
            
            $formatted_anchors[] = array(
                'link_id' => intval($link->id),
                'source_post_id' => intval($link->source_post_id),
                'source_post_title' => $source_post->post_title ?? '(No Title)',
                'source_post_edit_url' => get_edit_post_link($link->source_post_id),
                'target_post_id' => intval($link->target_post_id),
                'target_post_title' => $target_post->post_title ?? '(No Title)',
                'target_post_edit_url' => get_edit_post_link($link->target_post_id),
                'target_post_view_url' => get_permalink($link->target_post_id),
                'current_anchor_text' => $anchor_text,
                'link_position' => intval($link->link_position ?? 0),
                'placement_type' => $link->placement_type ?? 'natural',
                'ai_generated' => (bool)($link->ai_generated ?? false)
            );
        }
        
        wp_send_json_success(array('anchors' => $formatted_anchors));
    }
    
    /**
     * Get AI anchor suggestions (3 options)
     */
    public function get_ai_anchor_suggestions() {
        $this->verify_nonce();
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            exit;
        }
        
        $source_post_id = intval($_POST['source_post_id'] ?? 0);
        $target_post_id = intval($_POST['target_post_id'] ?? 0);
        
        if (!$source_post_id || !$target_post_id) {
            wp_send_json_error('Source and target post IDs are required');
            exit;
        }
        
        // Validate posts exist and have content
        $source_post = get_post($source_post_id);
        $target_post = get_post($target_post_id);
        
        if (!$source_post || !$target_post) {
            wp_send_json_error('One or both posts not found. Please verify the posts exist.');
            exit;
        }
        
        if (empty($source_post->post_content) || empty($target_post->post_content)) {
            wp_send_json_error('Both posts must have content to generate meaningful anchor suggestions.');
            exit;
        }
        
        // Check if AI is configured and available
        $settings = get_option('ssp_settings', array());
        $api_key = $settings['openai_api_key'] ?? '';
        
        if (empty($api_key)) {
            wp_send_json_error('AI is not configured. Please add your OpenAI/OpenRouter API key in the Settings tab.');
            exit;
        }
        
        $ai_integration = SSP_AI_Integration::get_instance();
        
        // Check if AI is actually configured (not just key exists)
        if (!$ai_integration->is_api_configured()) {
            wp_send_json_error('AI API key format is invalid. Please check your API key configuration.');
            exit;
        }
        
        // Get AI suggestions (already returns multiple options)
        try {
            $suggestions = $ai_integration->get_anchor_suggestions($source_post_id, $target_post_id);
            
            if (empty($suggestions) || !is_array($suggestions)) {
                // AI returned empty - try fallback but log the issue
                error_log('SSP AI Anchor Suggestions: AI returned empty suggestions for posts ' . $source_post_id . ' -> ' . $target_post_id);
                
                // Generate smart fallback suggestions based on target post
                $target_title = $target_post->post_title ?? '';
                $source_title = $source_post->post_title ?? '';
                
                if (!empty($target_title)) {
                    // Extract key words from target title for better fallbacks
                    $title_words = array_filter(explode(' ', $target_title), function($word) {
                        if (!is_string($word)) {
                            return false;
                        }
                        $word = trim($word, '.,!?;:"()[]');
                        return strlen($word) > 2 && !in_array(strtolower($word), ['the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'from']);
                    });
                    $title_words = array_values($title_words); // Re-index
                    
                    $suggestions = array();
                    
                    // Build semantic fallback suggestions
                    if (count($title_words) >= 2) {
                        // Multi-word phrases from title
                        $suggestions[] = implode(' ', array_slice($title_words, 0, 2));
                        $suggestions[] = implode(' ', array_slice($title_words, 0, 3));
                    }
                    
                    // Contextual phrases
                    if (!empty($target_title)) {
                        $suggestions[] = 'Learn about ' . $target_title;
                        $suggestions[] = 'Read more about ' . $target_title;
                    }
                    
                    // Clean and deduplicate
                    $suggestions = array_unique(array_filter(array_map('trim', $suggestions), function($s) {
                        if (!is_string($s) || empty(trim($s))) {
                            return false;
                        }
                        $s = trim($s);
                        if (strlen($s) < 1) {
                            return false;
                        }
                        return true;
                    }));
                }
                
                // Final fallback (ensure multi-word)
                if (empty($suggestions)) {
                    if (!empty($target_title)) {
                        $title_word_count = str_word_count($target_title);
                        // Only use title if multi-word (goal compliance)
                        if ($title_word_count !== false && $title_word_count >= 2) {
                            $suggestions = array($target_title);
                        } else {
                            // Single-word title: build contextual phrases
                            $suggestions = array(
                                'best ' . $target_title,
                                'guide to ' . $target_title,
                                'about ' . $target_title
                            );
                        }
                    } else {
                        // Last resort: multi-word generic fallbacks
                        $suggestions = array('Read more', 'Learn more', 'Continue reading');
                    }
                }
            }
        } catch (Exception $e) {
            error_log('SSP AI Anchor Suggestions Error: ' . $e->getMessage());
            wp_send_json_error('Failed to generate AI anchor suggestions: ' . $e->getMessage());
            exit;
        }
        
        // Clean and validate suggestions (allow single-word)
        $suggestions = array_filter(array_map('trim', $suggestions), function($s) {
            if (!is_string($s) || empty(trim($s))) {
                return false;
            }
            $s = trim($s);
            if (strlen($s) < 1 || strlen($s) > 100) {
                return false;
            }
            return true;
        });
        
        // Remove duplicates (case-insensitive)
        $unique_suggestions = array();
        $seen_lower = array();
        foreach ($suggestions as $suggestion) {
            $lower = strtolower($suggestion);
            if (!in_array($lower, $seen_lower)) {
                $unique_suggestions[] = $suggestion;
                $seen_lower[] = $lower;
            }
        }
        $suggestions = $unique_suggestions;
        
        // Quality filter: drop low-quality suggestions like only stopwords or dangling connectors
        $stop_all = array('and','or','but','to','of','in','on','for','with','by','at','from','as','the','a','an','this','that','these','those');
        $suggestions = array_values(array_filter($suggestions, function($s) use ($stop_all) {
            $s = trim($s, " \t\n\r\0\x0B\"'.,!?;:()[]{}");
            if ($s === '') return false;
            $tokens = preg_split('/\s+/', strtolower($s));
            $has_meaningful = false;
            foreach ($tokens as $tok) { if (strlen($tok) > 2 && !in_array($tok, $stop_all)) { $has_meaningful = true; break; } }
            if (!$has_meaningful) return false;
            $first = strtolower(reset($tokens));
            $last = strtolower(end($tokens));
            if (in_array($first, $stop_all) || in_array($last, $stop_all)) return false;
            return true;
        }));
        // Simple prioritization: keep order from AI
        $prioritized = $suggestions;
        
        // Limit to 3 best suggestions
        $final_suggestions = array_slice($prioritized, 0, 3);
        
        // If we have less than 3, pad with contextual fallbacks
        if (count($final_suggestions) < 3 && $target_post && !empty($target_post->post_title)) {
            $target_title = $target_post->post_title;
            $title_word_count = str_word_count($target_title);
            
            $fallback_suggestions = array();
            
            // Add title as-is if helpful
            if ($title_word_count !== false && $title_word_count >= 1) {
                $fallback_suggestions[] = $target_title;
            }
            
            // Always add contextual phrases
            $fallback_suggestions[] = 'Learn more about ' . $target_title;
            $fallback_suggestions[] = 'Read about ' . $target_title;
            
            // If title is single-word, add more contextual phrases
            if ($title_word_count === 1) {
                $fallback_suggestions[] = 'best ' . $target_title;
                $fallback_suggestions[] = 'guide to ' . $target_title;
            }
            
            foreach ($fallback_suggestions as $fallback) {
                if (count($final_suggestions) >= 3) break;
                $fallback_trimmed = trim($fallback);
                if (empty($fallback_trimmed)) { continue; }
                
                $fallback_lower = strtolower($fallback_trimmed);
                
                // Only add if not already in suggestions
                $already_exists = false;
                foreach ($final_suggestions as $existing) {
                    if (strtolower($existing) === $fallback_lower) {
                        $already_exists = true;
                        break;
                    }
                }
                
                if (!$already_exists && strlen($fallback_trimmed) <= 100) {
                    $final_suggestions[] = $fallback_trimmed;
                }
            }
        }
        
        // Ensure we have at least one suggestion (multi-word only)
        if (empty($final_suggestions)) {
            if ($target_post && !empty($target_post->post_title)) {
                $target_title = $target_post->post_title;
                $title_word_count = str_word_count($target_title);
                
                // Only use title if multi-word
                if ($title_word_count !== false && $title_word_count >= 2) {
                    $final_suggestions = array($target_title);
                } else {
                    // Single-word title: build contextual phrase
                    $final_suggestions = array('best ' . $target_title, 'guide to ' . $target_title, 'about ' . $target_title);
                }
            } else {
                // Last resort: multi-word generic fallbacks (violates goal 5 but ensures multi-word)
                $final_suggestions = array('Read more', 'Learn more', 'Continue reading');
            }
        }
        
        // Return exactly 3 suggestions (pad with contextual fallbacks if needed)
        if (count($final_suggestions) < 3 && $target_post && !empty($target_post->post_title)) {
            $target_title = $target_post->post_title;
            $title_words = explode(' ', $target_title);
            $title_words = array_filter($title_words, function($word) {
                if (!is_string($word)) {
                    return false;
                }
                $word = trim($word, '.,!?;:"()[]');
                return strlen($word) > 2;
            });
            $title_words = array_values($title_words); // Re-index
            
            $fallback_options = array();
            
            // Use target title directly ONLY if multi-word (goal compliance)
            $title_word_count = str_word_count($target_title);
            if ($title_word_count !== false && $title_word_count >= 2 &&
                !in_array(strtolower($target_title), array_map('strtolower', $final_suggestions))) {
                $fallback_options[] = $target_title;
            }
            
            // Build contextual phrases
            if (count($title_words) >= 2) {
                $phrase1 = implode(' ', array_slice($title_words, 0, 2));
                if (!in_array(strtolower($phrase1), array_map('strtolower', $final_suggestions))) {
                    $fallback_options[] = $phrase1;
                }
                
                if (count($title_words) >= 3) {
                    $phrase2 = implode(' ', array_slice($title_words, 0, 3));
                    if (!in_array(strtolower($phrase2), array_map('strtolower', $final_suggestions))) {
                        $fallback_options[] = $phrase2;
                    }
                }
            }
            
            // Generic contextual phrases
            $generic_phrases = array(
                'Learn more about ' . $target_title,
                'Read about ' . $target_title,
                'Discover ' . $target_title
            );
            
            foreach ($generic_phrases as $phrase) {
                if (count($final_suggestions) >= 3) break;
                $phrase_lower = strtolower($phrase);
                $exists = false;
                foreach ($final_suggestions as $existing) {
                    if (strtolower($existing) === $phrase_lower) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists && strlen($phrase) <= 100) {
                    $fallback_options[] = $phrase;
                }
            }
            
            // Add unique fallbacks
            foreach ($fallback_options as $fallback) {
                if (count($final_suggestions) >= 3) break;
                $final_suggestions[] = $fallback;
            }
        }
        
        // Ensure we have at least 3 (last resort)
        if (count($final_suggestions) < 3) {
            $generic_fallbacks = array('Read more', 'Learn more', 'Continue reading');
            foreach ($generic_fallbacks as $generic) {
                if (count($final_suggestions) >= 3) break;
                if (!in_array(strtolower($generic), array_map('strtolower', $final_suggestions))) {
                    $final_suggestions[] = $generic;
                }
            }
        }
        
        wp_send_json_success(array('suggestions' => array_slice($final_suggestions, 0, 3)));
    }
    
    /**
     * Update anchor text
     */
    public function update_anchor_text() {
        $this->verify_nonce();
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            exit;
        }
        
        $link_id = intval($_POST['link_id'] ?? 0);
        $new_anchor_text = isset($_POST['anchor_text']) ? sanitize_text_field($_POST['anchor_text']) : '';
        
        // Additional sanitization: remove any HTML tags that might have slipped through
        $new_anchor_text = wp_strip_all_tags($new_anchor_text);
        
        if (!$link_id) {
            wp_send_json_error('Link ID is required');
            exit;
        }
        
        if (empty($new_anchor_text)) {
            wp_send_json_error('Anchor text cannot be empty');
            exit;
        }
        
        // Validate anchor text length
        $new_anchor_text = trim($new_anchor_text);
        if (strlen($new_anchor_text) < 1 || strlen($new_anchor_text) > 100) {
            wp_send_json_error('Anchor text must be between 1 and 100 characters');
            exit;
        }
        
        // Get link from database
        global $wpdb;
        $links_table = $wpdb->prefix . 'ssp_links';
        $link = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$links_table} WHERE id = %d",
            $link_id
        ));
        
        if (!$link) {
            wp_send_json_error('Link not found');
            exit;
        }
        
        // Update anchor text in database
        $updated = $wpdb->update(
            $links_table,
            array('anchor_text' => $new_anchor_text),
            array('id' => $link_id),
            array('%s'),
            array('%d')
        );
        
        if ($updated === false) {
            wp_send_json_error('Failed to update anchor text in database: ' . $wpdb->last_error);
            exit;
        }
        
        // Check if update actually changed anything (updated === 0 means no rows changed)
        if ($updated === 0) {
            // Verify if anchor text is actually the same
            $existing_link = $wpdb->get_row($wpdb->prepare(
                "SELECT anchor_text FROM {$links_table} WHERE id = %d",
                $link_id
            ));
            if ($existing_link && $existing_link->anchor_text === $new_anchor_text) {
                // Anchor text is already the same, this is fine - continue
            } else {
                wp_send_json_error('Failed to update anchor text: No rows were affected');
                exit;
            }
        }
        
        // Update anchor text in post content
        $source_post = get_post($link->source_post_id);
        if (!$source_post) {
            wp_send_json_error('Source post not found');
            exit;
        }
        
        $content = $source_post->post_content ?? '';
        
        if (empty($content)) {
            wp_send_json_error('Source post has no content');
            exit;
        }
        
        // Find the link marker and update anchor text
        // Link marker formats:
        // Format 1: <!--ssp-link-{id}--><a>...</a><!--/ssp-link-{id}-->  (no spaces)
        // Format 2: <!-- ssp-link-{id} --><a>...</a><!-- /ssp-link-{id} -->  (with spaces)
        $link_marker_1 = '<!--ssp-link-' . $link_id . '-->';
        $link_end_marker_1 = '<!--/ssp-link-' . $link_id . '-->';
        $link_marker_2 = '<!-- ssp-link-' . $link_id . ' -->';
        $link_end_marker_2 = '<!-- /ssp-link-' . $link_id . ' -->';
        
        // Try format 1 first (no spaces)
        // Pattern matches anchor text including HTML entities, stopping at </a> or end marker
        // Uses non-greedy match and handles various spacing/formatting
        $pattern_1 = '/' . preg_quote($link_marker_1, '/') . '\s*<a\s+[^>]*href\s*=\s*["\']([^"\']*)["\'][^>]*>(.*?)</a>\s*' . preg_quote($link_end_marker_1, '/') . '/is';
        
        // Try format 2 (with spaces)
        $pattern_2 = '/' . preg_quote($link_marker_2, '/') . '\s*<a\s+[^>]*href\s*=\s*["\']([^"\']*)["\'][^>]*>(.*?)</a>\s*' . preg_quote($link_end_marker_2, '/') . '/is';
        
        $found = false;
        $url = '';
        $old_anchor = '';
        
        // Try format 1
        if (preg_match($pattern_1, $content, $matches)) {
            $found = true;
            $old_anchor = isset($matches[2]) ? html_entity_decode(strip_tags($matches[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
            $url = isset($matches[1]) ? $matches[1] : '';
            $link_marker = $link_marker_1;
            $link_end_marker = $link_end_marker_1;
            $pattern = $pattern_1;
        }
        // Try format 2 if format 1 didn't match
        else if (preg_match($pattern_2, $content, $matches)) {
            $found = true;
            $old_anchor = isset($matches[2]) ? html_entity_decode(strip_tags($matches[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
            $url = isset($matches[1]) ? $matches[1] : '';
            $link_marker = $link_marker_2;
            $link_end_marker = $link_end_marker_2;
            $pattern = $pattern_2;
        }
        
        if ($found) {
            // Replace anchor text in the link (using the matched format)
            $new_link_html = '<a href="' . esc_url($url) . '" class="ssp-internal-link">' . esc_html($new_anchor_text) . '</a>';
            $new_marked_link = $link_marker . $new_link_html . $link_end_marker;
            
            $new_content = preg_replace($pattern, $new_marked_link, $content);
            
            // Validate preg_replace result
            if ($new_content === null) {
                wp_send_json_error('Failed to update anchor text: Regex replacement error');
                exit;
            }
            
            if ($new_content === $content) {
                wp_send_json_error('Failed to update anchor text: No changes detected. Link may have been edited manually.');
                exit;
            }
            
            $content = $new_content;
            
            // Prevent recursive updates from other plugins/hooks
            $link_engine = SSP_Link_Engine::get_instance();
            // Access private property using reflection to set updating_post flag
            $reflection = new ReflectionClass($link_engine);
            $updating_property = $reflection->getProperty('updating_post');
            $updating_property->setAccessible(true);
            $updating_property->setValue($link_engine, true);
            
            // Update post content
            $update_result = wp_update_post(array(
                'ID' => $link->source_post_id,
                'post_content' => $content
            ), true);
            
            // Reset updating_post flag
            $updating_property->setValue($link_engine, false);
            
            if (is_wp_error($update_result)) {
                wp_send_json_error('Failed to update post content: ' . $update_result->get_error_message());
                exit;
            }
            
            if ($update_result === 0) {
                wp_send_json_error('Failed to update post content: Post update returned 0');
                exit;
            }
        } else {
            // Try alternative pattern without markers (legacy links)
            $target_url = get_permalink($link->target_post_id);
            if ($target_url) {
                // Escape URL for regex, handle both absolute and relative URLs
                $escaped_url = preg_quote(esc_url($target_url), '/');
                // Also try relative URL format
                $escaped_url_rel = preg_quote(parse_url($target_url, PHP_URL_PATH), '/');
                
                // Pattern 1: Absolute URL
                $url_pattern = '/<a\s+[^>]*href\s*=\s*["\']' . $escaped_url . '["\'][^>]*>(.*?)</a>/is';
                // Pattern 2: Relative URL
                $url_pattern_rel = '/<a\s+[^>]*href\s*=\s*["\']' . $escaped_url_rel . '["\'][^>]*>(.*?)</a>/is';
                
                $url_found = false;
                $url_matches = array();
                
                // Try absolute URL first
                if (preg_match($url_pattern, $content, $url_matches)) {
                    $url_found = true;
                    $url_pattern_used = $url_pattern;
                }
                // Try relative URL if absolute didn't match
                else if (preg_match($url_pattern_rel, $content, $url_matches)) {
                    $url_found = true;
                    $url_pattern_used = $url_pattern_rel;
                    $target_url = $escaped_url_rel; // Use relative URL for replacement
                }
                
                if ($url_found) {
                    // Use the original target_url for href (not escaped relative URL)
                    $original_target_url = get_permalink($link->target_post_id);
                    $new_content_url = preg_replace($url_pattern_used, '<a href="' . esc_url($original_target_url) . '" class="ssp-internal-link">' . esc_html($new_anchor_text) . '</a>', $content, 1);
                    
                    // Validate preg_replace result
                    if ($new_content_url === null) {
                        wp_send_json_error('Failed to update anchor text: Regex replacement error');
                        exit;
                    }
                    
                    if ($new_content_url === $content) {
                        wp_send_json_error('Failed to update anchor text: No changes detected');
                        exit;
                    }
                    
                    $content = $new_content_url;
                    
                    // Prevent recursive updates
                    $link_engine = SSP_Link_Engine::get_instance();
                    $reflection = new ReflectionClass($link_engine);
                    $updating_property = $reflection->getProperty('updating_post');
                    $updating_property->setAccessible(true);
                    $updating_property->setValue($link_engine, true);
                    
                    $update_result = wp_update_post(array(
                        'ID' => $link->source_post_id,
                        'post_content' => $content
                    ), true);
                    
                    $updating_property->setValue($link_engine, false);
                    
                    if (is_wp_error($update_result)) {
                        wp_send_json_error('Failed to update post content: ' . $update_result->get_error_message());
                        exit;
                    }
                    
                    if ($update_result === 0) {
                        wp_send_json_error('Failed to update post content: Post update returned 0');
                        exit;
                    }
                } else {
                    // Last resort: try finding any link to the target URL (might be a different format)
                    // Use original target URL, not escaped relative
                    $original_target_url = get_permalink($link->target_post_id);
                    $escaped_target_url = preg_quote($original_target_url, '/');
                    $url_pattern_all = '/(<a\s+[^>]*href=["\']' . $escaped_target_url . '["\'][^>]*>)([^<]+)(<\/a>)/is';
                    
                    if (preg_match($url_pattern_all, $content, $url_matches_all)) {
                        $new_content_all = preg_replace($url_pattern_all, '$1' . esc_html($new_anchor_text) . '$3', $content, 1);
                        
                        // Validate preg_replace result
                        if ($new_content_all === null) {
                            wp_send_json_error('Failed to update anchor text: Regex replacement error');
                            exit;
                        }
                        
                        if ($new_content_all === $content) {
                            wp_send_json_error('Failed to update anchor text: No changes detected');
                            exit;
                        }
                        
                        $content = $new_content_all;
                        
                        // Prevent recursive updates
                        $link_engine = SSP_Link_Engine::get_instance();
                        $reflection = new ReflectionClass($link_engine);
                        $updating_property = $reflection->getProperty('updating_post');
                        $updating_property->setAccessible(true);
                        $updating_property->setValue($link_engine, true);
                        
                        $update_result = wp_update_post(array(
                            'ID' => $link->source_post_id,
                            'post_content' => $content
                        ), true);
                        
                        $updating_property->setValue($link_engine, false);
                        
                        if (is_wp_error($update_result)) {
                            wp_send_json_error('Failed to update post content: ' . $update_result->get_error_message());
                            exit;
                        }
                        
                        if ($update_result === 0) {
                            wp_send_json_error('Failed to update post content: Post update returned 0');
                            exit;
                        }
                    } else {
                        wp_send_json_error('Link not found in post content. The link marker may have been removed or the content was edited manually.');
                        exit;
                    }
                }
            } else {
                wp_send_json_error('Could not get target post URL');
                exit;
            }
        }
        
        // Clear cache
        delete_transient('ssp_post_links_' . $link->source_post_id);
        
        wp_send_json_success(array(
            'message' => 'Anchor text updated successfully',
            'new_anchor_text' => $new_anchor_text
        ));
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
