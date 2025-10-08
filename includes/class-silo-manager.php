<?php
/**
 * Silo Manager class - handles silo creation and management
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class SSP_Silo_Manager {
    
    private static $instance = null;
    private $ai_integration;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->ai_integration = SSP_AI_Integration::get_instance();
        add_action('wp_insert_post', array($this, 'handle_new_post'), 10, 3);
    }
    
    /**
     * Create silo using AI recommendations
     */
    public function create_ai_silo($pillar_post_ids, $settings = array()) {
        $results = array();
        
        // Validate AI integration is available
        if (!$this->ai_integration || !method_exists($this->ai_integration, 'get_relevant_posts')) {
            return array(
                'status' => 'error',
                'message' => 'AI integration not available'
            );
        }
        
        foreach ($pillar_post_ids as $pillar_post_id) {
            $pillar_post = get_post($pillar_post_id);
            if (!$pillar_post) {
                $results[] = array(
                    'pillar_post_id' => $pillar_post_id,
                    'status' => 'error',
                    'message' => 'Pillar post not found'
                );
                continue;
            }
            
            // Get AI recommendations
            $recommended_posts = $this->ai_integration->get_relevant_posts($pillar_post_id, 20);
            
            if (empty($recommended_posts)) {
                continue;
            }
            
            // Create silo
            $silo_data = array(
                'name' => $pillar_post->post_title . ' - AI Silo',
                'pillar_post_id' => $pillar_post_id,
                'linking_mode' => $settings['linking_mode'] ?? 'linear',
                'setup_method' => 'ai_recommended',
                'settings' => $settings
            );
            
            $silo_id = SSP_Database::create_silo($silo_data);
            
            if ($silo_id) {
                // Add recommended posts to silo
                $post_ids = array_map(function($post) {
                    return $post->ID;
                }, $recommended_posts);
                
                $posts_added = SSP_Database::add_posts_to_silo($silo_id, $post_ids);
                
                $results[] = array(
                    'silo_id' => $silo_id,
                    'pillar_post_id' => $pillar_post_id,
                    'recommended_posts' => $recommended_posts,
                    'posts_added' => $posts_added,
                    'status' => 'success'
                );
            } else {
                $results[] = array(
                    'pillar_post_id' => $pillar_post_id,
                    'status' => 'error',
                    'message' => 'Failed to create silo'
                );
            }
        }
        
        return $results;
    }
    
    /**
     * Create silo using category-based selection
     */
    public function create_category_silo($pillar_post_ids, $category_id, $settings = array()) {
        $results = array();
        
        // Get posts from category
        $category_posts = get_posts(array(
            'post_type' => array('post', 'page'),
            'post_status' => 'publish',
            'cat' => intval($category_id),
            'numberposts' => -1
        ));
        
        if (empty($category_posts)) {
            return array('error' => 'No posts found in selected category');
        }
        
        foreach ($pillar_post_ids as $pillar_post_id) {
            $pillar_post = get_post($pillar_post_id);
            if (!$pillar_post) {
                continue;
            }
            
            // Filter out the pillar post itself
            $support_posts = array_filter($category_posts, function($post) use ($pillar_post_id) {
                return $post->ID != $pillar_post_id;
            });
            
            // Create silo
            $silo_data = array(
                'name' => $pillar_post->post_title . ' - Category Silo',
                'pillar_post_id' => $pillar_post_id,
                'linking_mode' => $settings['linking_mode'] ?? 'linear',
                'setup_method' => 'category_based',
                'settings' => array_merge($settings, array('category_id' => $category_id))
            );
            
            $silo_id = SSP_Database::create_silo($silo_data);
            
            if ($silo_id) {
                // Add category posts to silo
                $post_ids = array_map(function($post) {
                    return $post->ID;
                }, $support_posts);
                
                $posts_added = SSP_Database::add_posts_to_silo($silo_id, $post_ids);
                if ($posts_added === 0 || $posts_added === false) {
                    error_log("SSP Silo Manager Error: Failed to add posts to category silo {$silo_id}");
                }
                
                $results[] = array(
                    'silo_id' => $silo_id,
                    'pillar_post_id' => $pillar_post_id,
                    'support_posts' => $support_posts,
                    'status' => 'success'
                );
            }
        }
        
        return $results;
    }
    
    /**
     * Create silo using manual selection
     */
    public function create_manual_silo($pillar_post_id, $support_post_ids, $settings = array()) {
        error_log("SSP Silo Manager: Creating manual silo for pillar post {$pillar_post_id} with " . count($support_post_ids) . " support posts");
        
        $pillar_post = get_post($pillar_post_id);
        if (!$pillar_post) {
            error_log("SSP Silo Manager Error: Pillar post {$pillar_post_id} not found");
            return array('error' => 'Pillar post not found');
        }
        
            // Validate support posts
            $valid_support_posts = array();
            foreach ($support_post_ids as $post_id) {
                $post_id = intval($post_id);
                if ($post_id <= 0) {
                    error_log("SSP Silo Manager Warning: Invalid post ID: {$post_id}");
                    continue;
                }
                
                $post = get_post($post_id);
                if ($post && $post->post_status === 'publish') {
                    $valid_support_posts[] = $post;
                    error_log("SSP Silo Manager: Valid support post found - ID: {$post_id}, Title: {$post->post_title}");
                } else {
                    error_log("SSP Silo Manager Warning: Invalid support post - ID: {$post_id}");
                }
            }
        
        if (empty($valid_support_posts)) {
            error_log("SSP Silo Manager Error: No valid support posts selected");
            return array('error' => 'No valid support posts selected');
        }
        
        error_log("SSP Silo Manager: Found " . count($valid_support_posts) . " valid support posts");
        
        // Create silo
        $silo_data = array(
            'name' => $pillar_post->post_title . ' - Manual Silo',
            'pillar_post_id' => $pillar_post_id,
            'linking_mode' => $settings['linking_mode'] ?? 'linear',
            'setup_method' => 'manual',
            'settings' => $settings
        );
        
        error_log("SSP Silo Manager: Creating silo with data: " . json_encode($silo_data));
        
        $silo_id = SSP_Database::create_silo($silo_data);
        
        if (!$silo_id) {
            error_log("SSP Silo Manager Error: Failed to create silo");
            return array('error' => 'Failed to create silo');
        }
        
        error_log("SSP Silo Manager: Silo created successfully with ID: {$silo_id}");
        
        // Add support posts to silo
        $post_ids = array_map(function($post) {
            return $post->ID;
        }, $valid_support_posts);
        
        error_log("SSP Silo Manager: Adding " . count($post_ids) . " posts to silo {$silo_id}");
        
        $posts_added = SSP_Database::add_posts_to_silo($silo_id, $post_ids);
        
        if ($posts_added === 0 || $posts_added === false) {
            error_log("SSP Silo Manager Error: Failed to add any posts to silo {$silo_id}");
            return array('error' => 'Failed to add posts to silo');
        }
        
        error_log("SSP Silo Manager: Successfully added {$posts_added} posts to silo {$silo_id}");
        
        return array(
            'silo_id' => $silo_id,
            'pillar_post_id' => $pillar_post_id,
            'support_posts' => $valid_support_posts,
            'status' => 'success'
        );
    }
    
    /**
     * Get silo details with posts
     */
    public function get_silo_details($silo_id) {
        $silo = SSP_Database::get_silo($silo_id);
        if (!$silo) {
            return false;
        }
        
        $silo_posts = SSP_Database::get_silo_posts($silo_id);
        $silo->settings = json_decode($silo->settings, true);
        $silo->posts = $silo_posts;
        
        return $silo;
    }
    
    /**
     * Delete silo and cleanup
     */
    public function delete_silo($silo_id) {
        global $wpdb;
        
        // Remove links first
        $wpdb->delete($wpdb->prefix . 'ssp_links', array('silo_id' => $silo_id));
        
        // Remove silo posts
        $wpdb->delete($wpdb->prefix . 'ssp_silo_posts', array('silo_id' => $silo_id));
        
        // Remove AI suggestions
        $wpdb->delete($wpdb->prefix . 'ssp_ai_suggestions', array('silo_id' => $silo_id));
        
        // Remove silo
        $result = $wpdb->delete($wpdb->prefix . 'ssp_silos', array('id' => $silo_id));
        
        return $result !== false;
    }
    
    /**
     * Add posts to existing silo
     */
    public function add_posts_to_silo($silo_id, $post_ids) {
        $silo = SSP_Database::get_silo($silo_id);
        if (!$silo) {
            return false;
        }
        
        // Validate posts
        $valid_posts = array();
        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if ($post && $post->post_status === 'publish') {
                $valid_posts[] = $post_id;
            }
        }
        
        if (empty($valid_posts)) {
            return false;
        }
        
        // Add to silo
        SSP_Database::add_posts_to_silo($silo_id, $valid_posts);
        
        return true;
    }
    
    /**
     * Remove posts from silo
     */
    public function remove_posts_from_silo($silo_id, $post_ids) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ssp_silo_posts';
        
        foreach ($post_ids as $post_id) {
            $wpdb->delete($table, array(
                'silo_id' => $silo_id,
                'post_id' => $post_id
            ));
        }
        
        return true;
    }
    
    /**
     * Handle new post creation - auto-add to matching silos
     */
    public function handle_new_post($post_id, $post, $update) {
        // Only process new posts, not updates
        if ($update) {
            return;
        }
        
        // Only process published posts
        if ($post->post_status !== 'publish') {
            return;
        }
        
        // Get all silos that support auto-updates
        $silos = SSP_Database::get_silos(100, 0);
        
        foreach ($silos as $silo) {
            $settings = json_decode($silo->settings, true);
            
            // Check if this silo supports auto-updates
            if (!isset($settings['auto_update']) || !$settings['auto_update']) {
                continue;
            }
            
            // Skip if pillar post
            if ($silo->pillar_post_id == $post_id) {
                continue;
            }
            
            // Check if post matches silo criteria
            if ($this->post_matches_silo_criteria($post_id, $silo)) {
                SSP_Database::add_posts_to_silo($silo->id, array($post_id));
                
                // Auto-link if enabled
                if (isset($settings['auto_link']) && $settings['auto_link']) {
                    $link_engine = SSP_Link_Engine::get_instance();
                    $link_engine->generate_links_for_silo($silo->id, array($post_id));
                }
            }
        }
    }
    
    /**
     * Check if post matches silo criteria for auto-updates
     */
    private function post_matches_silo_criteria($post_id, $silo) {
        $post = get_post($post_id);
        $settings = json_decode($silo->settings, true);
        
        // Category-based silos
        if ($silo->setup_method === 'category_based' && isset($settings['category_id'])) {
            $post_categories = wp_get_post_categories($post_id);
            return in_array($settings['category_id'], $post_categories);
        }
        
        // AI-based silos - use simple keyword matching for now
        if ($silo->setup_method === 'ai_recommended') {
            $pillar_post = get_post($silo->pillar_post_id);
            if (!$pillar_post) {
                return false;
            }
            
            // Simple keyword overlap check
            $pillar_keywords = $this->extract_keywords($pillar_post->post_title . ' ' . $pillar_post->post_content);
            $post_keywords = $this->extract_keywords($post->post_title . ' ' . $post->post_content);
            
            $overlap = array_intersect($pillar_keywords, $post_keywords);
            return count($overlap) >= 2; // At least 2 keyword overlap
        }
        
        return false;
    }
    
    /**
     * Extract keywords from text
     */
    private function extract_keywords($text) {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z\s]/', ' ', $text);
        $words = array_filter(explode(' ', $text));
        
        // Remove common stop words
        $stop_words = array('the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'is', 'are', 'was', 'were', 'be', 'been', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could', 'should', 'may', 'might', 'can', 'this', 'that', 'these', 'those');
        
        $keywords = array();
        foreach ($words as $word) {
            if (strlen($word) > 3 && !in_array($word, $stop_words)) {
                $keywords[] = $word;
            }
        }
        
        return array_unique($keywords);
    }
    
    /**
     * Get silo statistics
     */
    public function get_silo_stats($silo_id) {
        global $wpdb;
        
        $silo_posts = SSP_Database::get_silo_posts($silo_id);
        $links = $wpdb->get_results($wpdb->prepare(
            "SELECT COUNT(*) as link_count FROM {$wpdb->prefix}ssp_links WHERE silo_id = %d AND status = 'active'",
            $silo_id
        ));
        
        return array(
            'total_posts' => count($silo_posts),
            'total_links' => $links[0]->link_count ?? 0,
            'posts_with_links' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT source_post_id) FROM {$wpdb->prefix}ssp_links WHERE silo_id = %d AND status = 'active'",
                $silo_id
            ))
        );
    }
    
    /**
     * Bulk create silos
     */
    public function bulk_create_silos($pillar_post_ids, $method, $options = array()) {
        $results = array();
        
        switch ($method) {
            case 'ai_recommended':
                $results = $this->create_ai_silo($pillar_post_ids, $options);
                break;
                
            case 'category_based':
                if (isset($options['category_id'])) {
                    $results = $this->create_category_silo($pillar_post_ids, $options['category_id'], $options);
                }
                break;
                
            case 'manual':
                // Manual method doesn't support bulk creation
                $results = array('error' => 'Manual method does not support bulk creation');
                break;
        }
        
        return $results;
    }
}
