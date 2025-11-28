<?php
/**
 * Link Engine class - handles link generation and insertion
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class SSP_Link_Engine {
    
    private static $instance = null;
    private $ai_integration;
    private $link_marker_prefix = 'ssp-link-';
    private $updating_post = false; // Prevent recursive updates
    private $excluded_posts_cache = null; // Cache for excluded posts
    private $excluded_anchors_cache = null; // Cache for excluded anchors
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->ai_integration = SSP_AI_Integration::get_instance();
        
        // Only add content filter on frontend for posts/pages that have silos
        if (!is_admin()) {
            add_filter('the_content', array($this, 'insert_links'), 20);
            // Add cache clearing hook
            add_action('save_post', array($this, 'clear_post_cache'), 10, 2);
        }
    }
    
    /**
     * Clear post cache when post is saved
     */
    public function clear_post_cache($post_id, $post) {
        // Skip if we're currently updating this post (prevent recursive issues)
        if ($this->updating_post) {
            return;
        }
        
        // Clear meta tags cache
        delete_transient('ssp_meta_tags_' . $post_id);
        
        // Clear any other post-related caches
        delete_transient('ssp_post_links_' . $post_id);
    }
    
    /**
     * Generate links for a silo based on linking mode
     */
    public function generate_links_for_silo($silo_id, $post_ids = array()) {
        error_log("SSP Link Generation: Starting generation for silo {$silo_id}");
        
        // Increase time limit for large silos
        if (function_exists('set_time_limit')) {
            @set_time_limit(300); // 5 minutes max
        }
        
        $silo = SSP_Database::get_silo($silo_id);
        if (!$silo) {
            error_log("SSP Link Generation Failed: Silo {$silo_id} not found");
            return false;
        }
        
        $silo_posts = SSP_Database::get_silo_posts($silo_id);
        if (empty($silo_posts)) {
            error_log("SSP Link Generation Failed: No posts found in silo {$silo_id}");
            return false;
        }
        
        error_log("SSP Link Generation: Found " . count($silo_posts) . " posts in silo {$silo_id}");
        
        // Filter posts if specific post IDs provided
        if (!empty($post_ids)) {
            $silo_posts = array_filter($silo_posts, function($post) use ($post_ids) {
                return in_array($post->post_id, $post_ids);
            });
            // Re-index array after filtering
            $silo_posts = array_values($silo_posts);
            
            // Check if any posts remain after filtering
            if (empty($silo_posts)) {
                error_log("SSP Link Generation Failed: No posts found after filtering for silo {$silo_id}");
                return 0;
            }
        }
        
        // Normalize settings once - decode JSON to array
        $settings_array = array();
        if (isset($silo->settings) && !empty($silo->settings)) {
            $decoded = json_decode($silo->settings, true);
            if (is_array($decoded)) {
                $settings_array = $decoded;
            }
        }
        
        // Debug: Log settings to verify supports_to_pillar is loaded correctly
        error_log("SSP Link Generation: Settings loaded for silo {$silo_id}. supports_to_pillar: " . (isset($settings_array['supports_to_pillar']) ? var_export($settings_array['supports_to_pillar'], true) : 'NOT SET'));
        error_log("SSP Link Generation: Full settings array: " . json_encode($settings_array));

        // Get pillar post (may be absent for no-pillar silos)
        $pillar_post = null;
        if (!empty($silo->pillar_post_id) && intval($silo->pillar_post_id) > 0) {
        $pillar_post = get_post($silo->pillar_post_id);
        if (!$pillar_post) {
                error_log("SSP Link Generation Warning: Pillar post {$silo->pillar_post_id} not found; proceeding without pillar");
            }
        }
        
        $links_created = 0;
        
        // Track used anchors in this silo to prevent duplicates
        $used_anchors = array();
        $settings_array['used_anchors'] = &$used_anchors; // Pass by reference to share across all link creation
        
        switch ($silo->linking_mode) {
            case 'linear':
                if ($pillar_post) {
                    $links_created = $this->create_linear_links($silo_id, $pillar_post, $silo_posts, $settings_array);
                } else {
                    // No pillar: ensure settings disable pillar-related behavior
                    $settings_array['supports_to_pillar'] = false;
                    $settings_array['pillar_to_supports'] = false;
                    $links_created = $this->create_linear_links_no_pillar($silo_id, $silo_posts, $settings_array);
                }
                break;
                
            case 'chained':
                if ($pillar_post) {
                    $links_created = $this->create_chained_links($silo_id, $pillar_post, $silo_posts, $settings_array);
                } else {
                    $links_created = $this->create_chained_links_no_pillar($silo_id, $silo_posts, $settings_array);
                }
                break;
                
            case 'cross_linking':
                if ($pillar_post) {
                    $links_created = $this->create_cross_linking($silo_id, $pillar_post, $silo_posts, $settings_array);
                } else {
                    $links_created = $this->create_cross_linking_no_pillar($silo_id, $silo_posts, $settings_array);
                }
                break;
                
            case 'star_hub':
                if ($pillar_post) {
                    $links_created = $this->create_star_hub_links($silo_id, $pillar_post, $silo_posts, $settings_array);
                } else {
                    // Fallback: without pillar, use chained links
                    error_log("SSP Link Generation Notice: star_hub requires a pillar; falling back to chained without pillar");
                    $links_created = $this->create_chained_links_no_pillar($silo_id, $silo_posts, $settings_array);
                }
                break;
                
            case 'ai_contextual':
                if ($pillar_post) {
                    $links_created = $this->create_ai_contextual_links($silo_id, $pillar_post, $silo_posts, $settings_array);
                } else {
                    $links_created = $this->create_ai_contextual_links_no_pillar($silo_id, $silo_posts, $settings_array);
                }
                break;
                
            case 'hub_chain':
                if ($pillar_post) {
                    $links_created = $this->create_hub_chain_links($silo_id, $pillar_post, $silo_posts, $settings_array);
                } else {
                    // Fallback: without pillar, use chained links
                    error_log("SSP Link Generation Notice: hub_chain requires a pillar; falling back to chained without pillar");
                    $links_created = $this->create_chained_links_no_pillar($silo_id, $silo_posts, $settings_array);
                }
                break;
                
            case 'custom':
                if ($pillar_post) {
                    $links_created = $this->create_custom_links($silo_id, $pillar_post, $silo_posts, $settings_array);
                } else {
                    $links_created = $this->create_custom_links_no_pillar($silo_id, $silo_posts, $settings_array);
                }
                break;
                
            default:
                error_log("SSP Link Generation Failed: Unknown linking mode '{$silo->linking_mode}'");
                return 0;
        }
        
        error_log("SSP Link Generation: Total links created: {$links_created}");
        return $links_created;
    }
    
    /**
     * Create linear (train loop) links
     */
    private function create_linear_links($silo_id, $pillar_post, $silo_posts, $settings) {
        $links_created = 0;
        
        // Ensure settings is an array
        if (!is_array($settings)) {
            $settings = array();
        }
        
        // Sort posts by position
        usort($silo_posts, function($a, $b) {
            return $a->position - $b->position;
        });
        
        // Create array with posts for linear chain
        $all_posts = array();
        
        // Add silo posts for linear chain (supports only)
        foreach ($silo_posts as $silo_post) {
            $post = get_post($silo_post->post_id);
            if ($post) {
                $all_posts[] = (object) array(
                    'ID' => $silo_post->post_id,
                    'post_title' => $post->post_title
                );
            }
        }
        
        // Check if we have enough posts to create links (need at least 1 support post)
        if (count($all_posts) < 1) {
            error_log("SSP Linear Mode: Not enough support posts to create links (need at least 1, found " . count($all_posts) . ")");
            return 0;
        }
        
        // CRITICAL: Create initial link from Pillar → First Support Post to start the linear chain
        // This is the fundamental structure of Linear mode: Pillar starts the chain
        $first_support_post = $all_posts[0];
        error_log("SSP Linear Mode: Creating initial pillar → first support link from pillar {$pillar_post->ID} to first support {$first_support_post->ID}");
        if ($this->create_link($silo_id, $pillar_post->ID, $first_support_post->ID, $settings)) {
            $links_created++;
            error_log("SSP Linear Mode: Successfully created initial pillar → first support link");
        } else {
            error_log("SSP Linear Mode: Failed to create initial pillar → first support link (check logs above for reason)");
        }
        
        // Create the linear chain between support posts (S1 → S2 → S3 → ... → SN)
        foreach ($all_posts as $index => $current_post) {
            $next_index = $index + 1;
            if ($next_index < count($all_posts)) {
                $next_post = $all_posts[$next_index];
            
            // Create link
            error_log("SSP Linear Mode: Creating link from post {$current_post->ID} to {$next_post->ID}");
            if ($this->create_link($silo_id, $current_post->ID, $next_post->ID, $settings)) {
                $links_created++;
                error_log("SSP Linear Mode: Successfully created link");
            } else {
                error_log("SSP Linear Mode: Failed to create link");
                }
            }
        }
        
        // NEW: Support posts link to pillar (if enabled) - Universal Option
        // This creates explicit support→pillar links for ALL supports
        // Normalize boolean value - check for true, 1, "1", "true"
        $supports_to_pillar_enabled = false;
        if (isset($settings['supports_to_pillar'])) {
            $value = $settings['supports_to_pillar'];
            $supports_to_pillar_enabled = ($value === true || $value === 1 || $value === '1' || $value === 'true');
        } else {
            // Default to true if not set (for backward compatibility)
            $supports_to_pillar_enabled = true;
        }
        
        error_log("SSP Linear Mode: supports_to_pillar setting: " . var_export($settings['supports_to_pillar'] ?? 'NOT SET', true) . ", enabled: " . var_export($supports_to_pillar_enabled, true));
        
        if ($supports_to_pillar_enabled) {
            foreach ($silo_posts as $silo_post) {
                $post = get_post($silo_post->post_id);
                if ($post) {
                    error_log("SSP Linear Mode: Creating support → pillar link from post {$silo_post->post_id} to pillar {$pillar_post->ID}");
                    if ($this->create_link($silo_id, $silo_post->post_id, $pillar_post->ID, $settings)) {
                        $links_created++;
                        error_log("SSP Linear Mode: Successfully created support → pillar link");
                    } else {
                        error_log("SSP Linear Mode: Failed to create support → pillar link (check logs above for reason)");
                    }
                }
            }
        } else {
            error_log("SSP Linear Mode: supports_to_pillar is disabled, skipping support→pillar links");
        }
        
        // NEW: Pillar links to supports (if enabled) - Universal Option
        // Note: For Linear mode, we already created Pillar → First Support above (line 231)
        // So if pillar_to_supports is enabled, we'll create additional links to other supports
        // Normalize boolean value
        $pillar_to_supports_enabled = false;
        if (isset($settings['pillar_to_supports'])) {
            $value = $settings['pillar_to_supports'];
            $pillar_to_supports_enabled = ($value === true || $value === 1 || $value === '1' || $value === 'true');
        }
        
        if ($pillar_to_supports_enabled) {
            $max_pillar_links = intval($settings['max_pillar_links'] ?? 5);
            $pillar_links_created = 0;
            $first_support_post_id = $first_support_post->ID; // Track first support to skip it
            
            foreach ($silo_posts as $silo_post) {
                // Skip first support since we already created Pillar → First Support above
                if ($silo_post->post_id == $first_support_post_id) {
                    continue;
                }
                
                if ($pillar_links_created >= $max_pillar_links) {
                    break;
                }
                
                if ($this->create_link($silo_id, $pillar_post->ID, $silo_post->post_id, $settings)) {
                    $links_created++;
                    $pillar_links_created++;
                    error_log("SSP Linear Mode: Added additional pillar → support link to post {$silo_post->post_id}");
                } else {
                    error_log("SSP Linear Mode: Failed to create pillar → support link to post {$silo_post->post_id}");
                }
            }
        }
        
        return $links_created;
    }

    /**
     * Create linear links without pillar: Post1 -> Post2 -> ... -> PostN (no loop)
     */
    private function create_linear_links_no_pillar($silo_id, $silo_posts, $settings) {
        $links_created = 0;
        if (!is_array($settings)) { $settings = array(); }
        
        // Check if we have enough posts to create links (need at least 2)
        if (count($silo_posts) < 2) {
            error_log("SSP Linear Mode (No Pillar): Not enough posts to create links (need at least 2, found " . count($silo_posts) . ")");
            return 0;
        }
        
        // Sort posts by position
        usort($silo_posts, function($a, $b) { return $a->position - $b->position; });
        
        for ($i = 0; $i < count($silo_posts) - 1; $i++) {
            $current = $silo_posts[$i];
            $next = $silo_posts[$i + 1];
            if ($this->create_link($silo_id, $current->post_id, $next->post_id, $settings)) {
                $links_created++;
            }
        }
        
        return $links_created;
    }
    
    /**
     * Create chained links
     */
    private function create_chained_links($silo_id, $pillar_post, $silo_posts, $settings) {
        $links_created = 0;
        
        // Ensure settings is an array
        if (!is_array($settings)) {
            $settings = array();
        }
        
        // Sort posts by position
        usort($silo_posts, function($a, $b) {
            return $a->position - $b->position;
        });
        
        // NEW: Support posts link to pillar (if enabled) - Universal Option
        // Normalize boolean value ONCE before loop (performance optimization)
        $supports_to_pillar_enabled = false;
        if (isset($settings['supports_to_pillar'])) {
            $value = $settings['supports_to_pillar'];
            $supports_to_pillar_enabled = ($value === true || $value === 1 || $value === '1' || $value === 'true');
        } else {
            $supports_to_pillar_enabled = true; // Default to true
        }
        
        // Create chain links between adjacent posts
        for ($i = 0; $i < count($silo_posts); $i++) {
            $current_post = $silo_posts[$i];
            
            // Link to next post
            if ($i < count($silo_posts) - 1) {
                $next_post = $silo_posts[$i + 1];
                if ($this->create_link($silo_id, $current_post->post_id, $next_post->post_id, $settings)) {
                    $links_created++;
                }
            }
            
            // Link to previous post
            if ($i > 0) {
                $prev_post = $silo_posts[$i - 1];
                if ($this->create_link($silo_id, $current_post->post_id, $prev_post->post_id, $settings)) {
                    $links_created++;
                }
            }
            
            // Support posts link to pillar (check done once above)
            if ($pillar_post && $supports_to_pillar_enabled) {
                if ($this->create_link($silo_id, $current_post->post_id, $pillar_post->ID, $settings)) {
                    $links_created++;
                    error_log("SSP Chained Mode: Added support → pillar link from post {$current_post->post_id}");
                } else {
                    error_log("SSP Chained Mode: Failed to create support → pillar link from post {$current_post->post_id}");
                }
            }
        }
        
        // NEW: Pillar links to supports (if enabled) - Universal Option
        // Normalize boolean value
        $pillar_to_supports_enabled = false;
        if (isset($settings['pillar_to_supports'])) {
            $value = $settings['pillar_to_supports'];
            $pillar_to_supports_enabled = ($value === true || $value === 1 || $value === '1' || $value === 'true');
        }
        
        if ($pillar_post && $pillar_to_supports_enabled) {
            $max_pillar_links = intval($settings['max_pillar_links'] ?? 5);
            $pillar_links_created = 0;
            
            foreach ($silo_posts as $silo_post) {
                if ($pillar_links_created >= $max_pillar_links) {
                    break;
                }
                
                if ($this->create_link($silo_id, $pillar_post->ID, $silo_post->post_id, $settings)) {
                    $links_created++;
                    $pillar_links_created++;
                    error_log("SSP Chained Mode: Added pillar → support link to post {$silo_post->post_id}");
                } else {
                    error_log("SSP Chained Mode: Failed to create pillar → support link to post {$silo_post->post_id}");
                }
            }
        }
        
        return $links_created;
    }

    /**
     * Create chained links without pillar: bidirectional adjacency
     */
    private function create_chained_links_no_pillar($silo_id, $silo_posts, $settings) {
        $links_created = 0;
        if (!is_array($settings)) { $settings = array(); }
        
        usort($silo_posts, function($a, $b) { return $a->position - $b->position; });
        
        for ($i = 0; $i < count($silo_posts); $i++) {
            if ($i < count($silo_posts) - 1) {
                if ($this->create_link($silo_id, $silo_posts[$i]->post_id, $silo_posts[$i + 1]->post_id, $settings)) {
                    $links_created++;
                }
            }
            if ($i > 0) {
                if ($this->create_link($silo_id, $silo_posts[$i]->post_id, $silo_posts[$i - 1]->post_id, $settings)) {
                    $links_created++;
                }
            }
        }
        return $links_created;
    }
    
    /**
     * Create cross-linking (mesh) links
     */
    private function create_cross_linking($silo_id, $pillar_post, $silo_posts, $settings) {
        $links_created = 0;
        
        // Ensure settings is an array
        if (!is_array($settings)) {
            $settings = array();
        }
        
        // Create array with pillar post and silo posts
        $all_posts = array();
        
        // Add pillar post
        $all_posts[] = (object) array(
            'ID' => $pillar_post->ID,
            'post_title' => $pillar_post->post_title
        );
        
        // Add silo posts
        foreach ($silo_posts as $silo_post) {
            $post = get_post($silo_post->post_id);
            if ($post) {
                $all_posts[] = (object) array(
                    'ID' => $silo_post->post_id,
                    'post_title' => $post->post_title
                );
            }
        }
        
        // Safety check: Cross-linking can create N*(N-1) links
        // Check if we have enough posts to create links (need at least 2)
        $post_count = count($all_posts);
        if ($post_count < 2) {
            error_log("SSP Cross-Linking Mode: Not enough posts to create links (need at least 2, found {$post_count})");
            return 0;
        }
        
        // Apply cap to prevent excessive link generation
        $max_links_per_post = isset($settings['max_cross_links_per_post']) ? intval($settings['max_cross_links_per_post']) : 5;
        
        if ($post_count > 20) {
            error_log("SSP Cross-Linking Warning: Silo has {$post_count} posts. Cross-linking will create up to " . ($post_count * min($post_count - 1, $max_links_per_post)) . " links (capped at {$max_links_per_post} per post). Consider using a different linking mode for large silos.");
        }
        
        // Create cross-linking mesh between all posts (including pillar)
        foreach ($all_posts as $source_post) {
            $created_for_source = 0;
            foreach ($all_posts as $target_post) {
                // Skip self-linking
                if ($source_post->ID == $target_post->ID) {
                    continue;
                }
                
                // Apply cap per source post
                if ($created_for_source >= $max_links_per_post) {
                    break;
                }
                
                // Create link
                if ($this->create_link($silo_id, $source_post->ID, $target_post->ID, $settings)) {
                    $links_created++;
                    $created_for_source++;
                }
            }
        }
        
        // NEW: Support posts link to pillar (if enabled) - Universal Option
        // This ensures explicit support→pillar links are ALWAYS created when enabled
        // Even if they were already created in the mesh above, create_link() checks for duplicates
        // Normalize boolean value
        $supports_to_pillar_enabled = false;
        if (isset($settings['supports_to_pillar'])) {
            $value = $settings['supports_to_pillar'];
            $supports_to_pillar_enabled = ($value === true || $value === 1 || $value === '1' || $value === 'true');
        } else {
            $supports_to_pillar_enabled = true; // Default to true
        }
        
        if ($supports_to_pillar_enabled && $pillar_post) {
            foreach ($silo_posts as $silo_post) {
                $post = get_post($silo_post->post_id);
                if ($post) {
                    error_log("SSP Cross-Linking Mode: Ensuring explicit support → pillar link from post {$silo_post->post_id} to pillar {$pillar_post->ID}");
                    if ($this->create_link($silo_id, $silo_post->post_id, $pillar_post->ID, $settings)) {
                        $links_created++;
                        error_log("SSP Cross-Linking Mode: Successfully created/verified support → pillar link");
                    } else {
                        error_log("SSP Cross-Linking Mode: Support → pillar link already exists or failed");
                    }
                }
            }
        }
        
        // NEW: Pillar links to supports (if enabled) - Universal Option
        // Normalize boolean value
        $pillar_to_supports_enabled = false;
        if (isset($settings['pillar_to_supports'])) {
            $value = $settings['pillar_to_supports'];
            $pillar_to_supports_enabled = ($value === true || $value === 1 || $value === '1' || $value === 'true');
        }
        
        if ($pillar_to_supports_enabled) {
            $max_pillar_links = intval($settings['max_pillar_links'] ?? 5);
            $pillar_links_created = 0;
            
            foreach ($silo_posts as $silo_post) {
                if ($pillar_links_created >= $max_pillar_links) {
                    break;
                }
                
                if ($this->create_link($silo_id, $pillar_post->ID, $silo_post->post_id, $settings)) {
                    $links_created++;
                    $pillar_links_created++;
                    error_log("SSP Cross-Linking Mode: Added pillar → support link to post {$silo_post->post_id}");
                } else {
                    error_log("SSP Cross-Linking Mode: Failed to create pillar → support link to post {$silo_post->post_id}");
                }
            }
        }
        
        return $links_created;
    }

    /**
     * Create cross-linking without pillar: every support post links to every other
     */
    private function create_cross_linking_no_pillar($silo_id, $silo_posts, $settings) {
        $links_created = 0;
        if (!is_array($settings)) { $settings = array(); }
        $max_per_post = isset($settings['max_cross_links_per_post']) ? intval($settings['max_cross_links_per_post']) : 5;
        
        $posts = array();
        foreach ($silo_posts as $sp) {
            $post = get_post($sp->post_id);
            if ($post) {
                $posts[] = (object) array('ID' => $sp->post_id, 'post_title' => $post->post_title ?? '');
            }
        }
        $count = count($posts);
        
        // Check if we have enough posts to create links (need at least 2)
        if ($count < 2) {
            error_log("SSP Cross-Linking Mode (No Pillar): Not enough posts to create links (need at least 2, found {$count})");
            return 0;
        }
        for ($i = 0; $i < $count; $i++) {
            $created_for_source = 0;
            for ($j = 0; $j < $count; $j++) {
                if ($i === $j) continue;
                if ($created_for_source >= $max_per_post) break;
                if ($this->create_link($silo_id, $posts[$i]->ID, $posts[$j]->ID, $settings)) {
                    $links_created++;
                    $created_for_source++;
                }
            }
        }
        return $links_created;
    }
    
    /**
     * Create custom links based on saved pattern
     */
    private function create_custom_links($silo_id, $pillar_post, $silo_posts, $settings) {
        $links_created = 0;
        
        // Ensure settings is an array
        if (!is_array($settings)) {
            $settings = array();
        }
        
        // Validate pillar post exists
        if (!$pillar_post) {
            error_log("SSP Custom Mode Failed: Pillar post not found");
            return 0;
        }

        if (!isset($settings['custom_pattern'])) {
            return 0;
        }
        
        $pattern = $settings['custom_pattern'];
        
        // Build allowed IDs set from silo (validate posts belong to silo)
        $allowed_ids = array();
        $allowed_ids[$pillar_post->ID] = true;
        foreach ($silo_posts as $sp) {
            $allowed_ids[$sp->post_id] = true;
        }
        
        foreach ($pattern as $link_rule) {
            if (!isset($link_rule['source']) || !isset($link_rule['target'])) {
                continue; // Skip invalid rules
            }
            
            $source_post_id = $link_rule['source'];
            $target_post_id = $link_rule['target'];
            
            // Handle pillar post references
            if ($source_post_id === 'pillar') {
                $source_post_id = $pillar_post->ID;
            }
            if ($target_post_id === 'pillar') {
                $target_post_id = $pillar_post->ID;
            }
            
            // Validate posts belong to silo
            $source_post_id = intval($source_post_id);
            $target_post_id = intval($target_post_id);
            if (!$source_post_id || !$target_post_id || !isset($allowed_ids[$source_post_id]) || !isset($allowed_ids[$target_post_id])) {
                error_log("SSP Custom Mode: Skipping invalid rule - posts not in silo (source: {$source_post_id}, target: {$target_post_id})");
                continue;
            }
            
            // Create link
            if ($this->create_link($silo_id, $source_post_id, $target_post_id, $settings)) {
                $links_created++;
            }
        }
        
        // NEW: Support posts link to pillar (if enabled) - Universal Option
        // Normalize boolean value
        $supports_to_pillar_enabled = false;
        if (isset($settings['supports_to_pillar'])) {
            $value = $settings['supports_to_pillar'];
            $supports_to_pillar_enabled = ($value === true || $value === 1 || $value === '1' || $value === 'true');
        } else {
            $supports_to_pillar_enabled = true; // Default to true
        }
        
        if ($supports_to_pillar_enabled) {
            foreach ($silo_posts as $silo_post) {
                if ($this->create_link($silo_id, $silo_post->post_id, $pillar_post->ID, $settings)) {
                    $links_created++;
                    error_log("SSP Custom Mode: Added support → pillar link from post {$silo_post->post_id}");
                } else {
                    error_log("SSP Custom Mode: Failed to create support → pillar link from post {$silo_post->post_id}");
                }
            }
        } else {
            error_log("SSP Custom Mode: supports_to_pillar is disabled, skipping support→pillar links");
        }
        
        // NEW: Pillar links to supports (if enabled) - Universal Option
        // Normalize boolean value
        $pillar_to_supports_enabled = false;
        if (isset($settings['pillar_to_supports'])) {
            $value = $settings['pillar_to_supports'];
            $pillar_to_supports_enabled = ($value === true || $value === 1 || $value === '1' || $value === 'true');
        }
        
        if ($pillar_to_supports_enabled) {
            $max_pillar_links = intval($settings['max_pillar_links'] ?? 5);
            $pillar_links_created = 0;
            
            foreach ($silo_posts as $silo_post) {
                if ($pillar_links_created >= $max_pillar_links) {
                    break;
                }
                
                if ($this->create_link($silo_id, $pillar_post->ID, $silo_post->post_id, $settings)) {
                    $links_created++;
                    $pillar_links_created++;
                    error_log("SSP Custom Mode: Added pillar → support link to post {$silo_post->post_id}");
                } else {
                    error_log("SSP Custom Mode: Failed to create pillar → support link to post {$silo_post->post_id}");
                }
            }
        }
        
        return $links_created;
    }
    
    /**
     * Create star/hub (pillar-centric) links
     */
    private function create_star_hub_links($silo_id, $pillar_post, $silo_posts, $settings) {
        $links_created = 0;
        
        // Ensure settings is an array
        if (!is_array($settings)) {
            $settings = array();
        }
        
        // Validate pillar post exists
        if (!$pillar_post) {
            error_log("SSP Star/Hub Mode Failed: Pillar post not found");
            return 0;
        }
        
        error_log("SSP Star/Hub Mode: Creating pillar-centric links for silo {$silo_id}");
        
        // Support posts link TO pillar (if enabled)
        // Normalize boolean value
        $supports_to_pillar_enabled = false;
        if (isset($settings['supports_to_pillar'])) {
            $value = $settings['supports_to_pillar'];
            $supports_to_pillar_enabled = ($value === true || $value === 1 || $value === '1' || $value === 'true');
        } else {
            $supports_to_pillar_enabled = true; // Default ON
        }
        
        if ($supports_to_pillar_enabled) {
            foreach ($silo_posts as $silo_post) {
                $post = get_post($silo_post->post_id);
                if ($post) {
                    error_log("SSP Star/Hub Mode: Creating link from support post {$silo_post->post_id} to pillar {$pillar_post->ID}");
                    if ($this->create_link($silo_id, $silo_post->post_id, $pillar_post->ID, $settings)) {
                        $links_created++;
                        error_log("SSP Star/Hub Mode: Successfully created support→pillar link");
                    }
                }
            }
        }
        
        // Optional: Pillar links to support posts (if enabled)
        // Normalize boolean value
        $pillar_to_supports_enabled = false;
        if (isset($settings['pillar_to_supports'])) {
            $value = $settings['pillar_to_supports'];
            $pillar_to_supports_enabled = ($value === true || $value === 1 || $value === '1' || $value === 'true');
        }
        
        if ($pillar_to_supports_enabled) {
            error_log("SSP Star/Hub Mode: Pillar→supports enabled, creating pillar links");
            
            // Limit pillar links to avoid overwhelming the pillar post
            $max_pillar_links = intval($settings['max_pillar_links'] ?? 5);
            $linked_count = 0;
            
            foreach ($silo_posts as $silo_post) {
                if ($linked_count >= $max_pillar_links) {
                    break;
                }
                
                $post = get_post($silo_post->post_id);
                if ($post) {
                    error_log("SSP Star/Hub Mode: Creating link from pillar {$pillar_post->ID} to support post {$silo_post->post_id}");
                    if ($this->create_link($silo_id, $pillar_post->ID, $silo_post->post_id, $settings)) {
                        $links_created++;
                        $linked_count++;
                        error_log("SSP Star/Hub Mode: Successfully created pillar→support link");
                    }
                }
            }
        }
        
        error_log("SSP Star/Hub Mode: Created {$links_created} total links");
        return $links_created;
    }
    
    /**
     * Create AI-contextual (smart) links
     */
    private function create_ai_contextual_links($silo_id, $pillar_post, $silo_posts, $settings) {
        $links_created = 0;
        
        // Ensure settings is an array
        if (!is_array($settings)) {
            $settings = array();
        }
        
        // Validate pillar post exists
        if (!$pillar_post) {
            error_log("SSP AI-Contextual Mode Failed: Pillar post not found");
            return 0;
        }
        
        error_log("SSP AI-Contextual Mode: Creating smart contextual links for silo {$silo_id}");
        
        // Get all posts for analysis
        $all_posts = array();
        
        // Add pillar post
        $all_posts[] = (object) array(
            'ID' => $pillar_post->ID,
            'post_title' => $pillar_post->post_title,
            'post_content' => $pillar_post->post_content,
            'is_pillar' => true
        );
        
        // Add silo posts
        foreach ($silo_posts as $silo_post) {
            $post = get_post($silo_post->post_id);
            if ($post) {
                $all_posts[] = (object) array(
                    'ID' => $silo_post->post_id,
                    'post_title' => $post->post_title,
                    'post_content' => $post->post_content,
                    'is_pillar' => false
                );
            }
        }
        
        // For each post, find 2-3 most related posts
        $max_links_per_post = $settings['max_contextual_links'] ?? 3;
        
        foreach ($all_posts as $source_post) {
            $related_posts = $this->find_most_related_posts($source_post, $all_posts, $max_links_per_post);
            
            foreach ($related_posts as $target_post) {
                // Skip self-links
                if ($source_post->ID === $target_post->ID) {
                    continue;
                }
                
                error_log("SSP AI-Contextual Mode: Creating contextual link from post {$source_post->ID} to {$target_post->ID}");
                if ($this->create_link($silo_id, $source_post->ID, $target_post->ID, $settings)) {
                    $links_created++;
                    error_log("SSP AI-Contextual Mode: Successfully created contextual link");
                }
            }
        }
        
        // NEW: Ensure support posts link to pillar if enabled (override contextual if needed)
        // Normalize boolean value
        $supports_to_pillar_enabled = false;
        if (isset($settings['supports_to_pillar'])) {
            $value = $settings['supports_to_pillar'];
            $supports_to_pillar_enabled = ($value === true || $value === 1 || $value === '1' || $value === 'true');
        } else {
            $supports_to_pillar_enabled = true; // Default to true
        }
        
        if ($supports_to_pillar_enabled) {
            foreach ($silo_posts as $silo_post) {
                $post = get_post($silo_post->post_id);
                if ($post) {
                    // Create link from support to pillar (if doesn't exist)
                    if ($this->create_link($silo_id, $silo_post->post_id, $pillar_post->ID, $settings)) {
                        $links_created++;
                        error_log("SSP AI-Contextual Mode: Added guaranteed support → pillar link from post {$silo_post->post_id}");
                    } else {
                        error_log("SSP AI-Contextual Mode: Failed to create support → pillar link from post {$silo_post->post_id}");
                    }
                }
            }
        } else {
            error_log("SSP AI-Contextual Mode: supports_to_pillar is disabled, skipping support→pillar links");
        }
        
        // NEW: Pillar links to supports (if enabled)
        // Normalize boolean value
        $pillar_to_supports_enabled = false;
        if (isset($settings['pillar_to_supports'])) {
            $value = $settings['pillar_to_supports'];
            $pillar_to_supports_enabled = ($value === true || $value === 1 || $value === '1' || $value === 'true');
        }
        
        if ($pillar_to_supports_enabled) {
            $max_pillar_links = intval($settings['max_pillar_links'] ?? 5);
            $pillar_links_created = 0;
            
            foreach ($silo_posts as $silo_post) {
                if ($pillar_links_created >= $max_pillar_links) {
                    break;
                }
                
                if ($this->create_link($silo_id, $pillar_post->ID, $silo_post->post_id, $settings)) {
                    $links_created++;
                    $pillar_links_created++;
                    error_log("SSP AI-Contextual Mode: Added pillar → support link to post {$silo_post->post_id}");
                } else {
                    error_log("SSP AI-Contextual Mode: Failed to create pillar → support link to post {$silo_post->post_id}");
                }
            }
        }
        
        error_log("SSP AI-Contextual Mode: Created {$links_created} total contextual links");
        return $links_created;
    }

    /**
     * AI-contextual without pillar: compute relatedness among support posts only
     */
    private function create_ai_contextual_links_no_pillar($silo_id, $silo_posts, $settings) {
        $links_created = 0;
        if (!is_array($settings)) { $settings = array(); }
        
        $all_posts = array();
        foreach ($silo_posts as $sp) {
            $post = get_post($sp->post_id);
            if ($post) {
                $all_posts[] = (object) array(
                    'ID' => $post->ID,
                    'post_title' => $post->post_title,
                    'post_content' => $post->post_content,
                    'is_pillar' => false
                );
            }
        }
        if (count($all_posts) < 2) { return 0; }
        $max_links_per_post = $settings['max_contextual_links'] ?? 3;
        foreach ($all_posts as $source_post) {
            $related = $this->find_most_related_posts($source_post, $all_posts, $max_links_per_post);
            foreach ($related as $target_post) {
                if ($source_post->ID === $target_post->ID) continue;
                if ($this->create_link($silo_id, $source_post->ID, $target_post->ID, $settings)) {
                    $links_created++;
                }
            }
        }
        return $links_created;
    }

    /**
     * Custom links without pillar: ignore rules that reference 'pillar'
     */
    private function create_custom_links_no_pillar($silo_id, $silo_posts, $settings) {
        $links_created = 0;
        if (!is_array($settings)) { $settings = array(); }
        if (!isset($settings['custom_pattern']) || !is_array($settings['custom_pattern'])) {
            return 0;
        }
        // Build allowed IDs set from silo
        $allowed_ids = array();
        foreach ($silo_posts as $sp) { $allowed_ids[$sp->post_id] = true; }
        foreach ($settings['custom_pattern'] as $link_rule) {
            $source_post_id = $link_rule['source'];
            $target_post_id = $link_rule['target'];
            if ($source_post_id === 'pillar' || $target_post_id === 'pillar') {
                continue; // skip pillar references
            }
            $source_post_id = intval($source_post_id);
            $target_post_id = intval($target_post_id);
            if (!$source_post_id || !$target_post_id || !isset($allowed_ids[$source_post_id]) || !isset($allowed_ids[$target_post_id])) {
                continue; // enforce silo membership
            }
            if ($this->create_link($silo_id, $source_post_id, $target_post_id, $settings)) {
                $links_created++;
            }
        }
        return $links_created;
    }
    
    /**
     * Create hub-and-chain links (supports to pillar + adjacent supports)
     */
    private function create_hub_chain_links($silo_id, $pillar_post, $silo_posts, $settings) {
        $links_created = 0;
        
        // Ensure settings is an array
        if (!is_array($settings)) {
            $settings = array();
        }
        
        // Validate pillar post exists
        if (!$pillar_post) {
            error_log("SSP Hub-Chain Mode Failed: Pillar post not found");
            return 0;
        }
        
        error_log("SSP Hub-Chain Mode: Creating hub-and-chain links for silo {$silo_id}");
        
        // Sort posts by position for consistent chaining
        usort($silo_posts, function($a, $b) {
            return $a->position - $b->position;
        });
        
        // Step 1: Support posts link TO pillar (hub structure) - if enabled
        // Normalize boolean value
        $supports_to_pillar_enabled = false;
        if (isset($settings['supports_to_pillar'])) {
            $value = $settings['supports_to_pillar'];
            $supports_to_pillar_enabled = ($value === true || $value === 1 || $value === '1' || $value === 'true');
        } else {
            $supports_to_pillar_enabled = true; // Default ON
        }
        
        if ($supports_to_pillar_enabled) {
            foreach ($silo_posts as $silo_post) {
                $post = get_post($silo_post->post_id);
                if ($post) {
                    error_log("SSP Hub-Chain Mode: Creating hub link from support post {$silo_post->post_id} to pillar {$pillar_post->ID}");
                    if ($this->create_link($silo_id, $silo_post->post_id, $pillar_post->ID, $settings)) {
                        $links_created++;
                        error_log("SSP Hub-Chain Mode: Successfully created support→pillar link");
                    }
                }
            }
        }
        
        // Step 2: Adjacent support posts link to each other (chain structure)
        for ($i = 0; $i < count($silo_posts); $i++) {
            $current_post = $silo_posts[$i];
            
            // Link to next post
            if ($i < count($silo_posts) - 1) {
                $next_post = $silo_posts[$i + 1];
                error_log("SSP Hub-Chain Mode: Creating forward chain link from post {$current_post->post_id} to {$next_post->post_id}");
                if ($this->create_link($silo_id, $current_post->post_id, $next_post->post_id, $settings)) {
                    $links_created++;
                    error_log("SSP Hub-Chain Mode: Successfully created forward chain link");
                }
            }
            
            // Link to previous post (bidirectional chaining)
            if ($i > 0) {
                $prev_post = $silo_posts[$i - 1];
                error_log("SSP Hub-Chain Mode: Creating backward chain link from post {$current_post->post_id} to {$prev_post->post_id}");
                if ($this->create_link($silo_id, $current_post->post_id, $prev_post->post_id, $settings)) {
                    $links_created++;
                    error_log("SSP Hub-Chain Mode: Successfully created backward chain link");
                }
            }
        }
        
        // Optional: Pillar links to support posts
        // Normalize boolean value
        $pillar_to_supports_enabled = false;
        if (isset($settings['pillar_to_supports'])) {
            $value = $settings['pillar_to_supports'];
            $pillar_to_supports_enabled = ($value === true || $value === 1 || $value === '1' || $value === 'true');
        }
        
        if ($pillar_to_supports_enabled) {
            error_log("SSP Hub-Chain Mode: Pillar→supports enabled, creating pillar links");
            
            $max_pillar_links = intval($settings['max_pillar_links'] ?? 5);
            $linked_count = 0;
            
            foreach ($silo_posts as $silo_post) {
                if ($linked_count >= $max_pillar_links) {
                    break;
                }
                
                $post = get_post($silo_post->post_id);
                if ($post) {
                    error_log("SSP Hub-Chain Mode: Creating link from pillar {$pillar_post->ID} to support post {$silo_post->post_id}");
                    if ($this->create_link($silo_id, $pillar_post->ID, $silo_post->post_id, $settings)) {
                        $links_created++;
                        $linked_count++;
                        error_log("SSP Hub-Chain Mode: Successfully created pillar→support link");
                    } else {
                        error_log("SSP Hub-Chain Mode: Failed to create pillar→support link to post {$silo_post->post_id}");
                    }
                }
            }
        }
        
        error_log("SSP Hub-Chain Mode: Created {$links_created} total links");
        return $links_created;
    }
    
    /**
     * Find most related posts for AI-contextual linking
     */
    private function find_most_related_posts($source_post, $all_posts, $max_links) {
        $scores = array();
        
        foreach ($all_posts as $target_post) {
            if ($source_post->ID === $target_post->ID) {
                continue; // Skip self
            }
            
            $score = $this->calculate_relatedness_score($source_post, $target_post);
            $scores[] = array(
                'post' => $target_post,
                'score' => $score
            );
        }
        
        // Sort by score (highest first)
        usort($scores, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        
        // Return top posts
        $related_posts = array();
        for ($i = 0; $i < min($max_links, count($scores)); $i++) {
            if ($scores[$i]['score'] > 0.1) { // Minimum relevance threshold
                $related_posts[] = $scores[$i]['post'];
            }
        }
        
        return $related_posts;
    }
    
    /**
     * Calculate relatedness score between two posts
     */
    private function calculate_relatedness_score($post1, $post2) {
        $score = 0;
        
        // Title similarity (30% weight)
        $title_similarity = $this->calculate_text_similarity(
            strtolower($post1->post_title ?? ''),
            strtolower($post2->post_title ?? '')
        );
        $score += $title_similarity * 0.3;
        
        // Content keyword overlap (40% weight)
        $content1 = wp_strip_all_tags($post1->post_content ?? '');
        $content2 = wp_strip_all_tags($post2->post_content ?? '');
        $keyword_overlap = $this->calculate_keyword_overlap($content1, $content2);
        $score += $keyword_overlap * 0.4;
        
        // Category similarity (20% weight)
        $category_similarity = $this->calculate_category_similarity($post1->ID, $post2->ID);
        $score += $category_similarity * 0.2;
        
        // Pillar bonus (10% weight) - only add once if either post is pillar
        if ($post1->is_pillar || $post2->is_pillar) {
            $score += 0.1;
        }
        
        return min(1.0, $score); // Cap at 1.0
    }
    
    /**
     * Calculate text similarity using simple word overlap
     */
    private function calculate_text_similarity($text1, $text2) {
        $words1 = array_unique(preg_split('/\s+/', $text1));
        $words2 = array_unique(preg_split('/\s+/', $text2));
        
        $common_words = array_intersect($words1, $words2);
        $total_words = array_unique(array_merge($words1, $words2));
        
        if (empty($total_words)) {
            return 0;
        }
        
        return count($common_words) / count($total_words);
    }
    
    /**
     * Calculate keyword overlap between two content pieces
     */
    private function calculate_keyword_overlap($content1, $content2) {
        // Extract meaningful words (length > 3, not common words)
        $stop_words = ['the', 'and', 'for', 'with', 'from', 'this', 'that', 'are', 'was', 'were', 'have', 'been', 'they', 'said', 'each', 'which', 'their', 'time', 'will', 'about', 'there', 'when', 'your', 'can', 'said', 'she', 'use', 'how', 'our', 'out', 'many', 'then', 'them', 'these', 'so', 'some', 'her', 'would', 'make', 'like', 'into', 'him', 'has', 'two', 'more', 'go', 'no', 'way', 'could', 'my', 'than', 'first', 'been', 'call', 'who', 'oil', 'its', 'now', 'find', 'long', 'down', 'day', 'did', 'get', 'come', 'made', 'may', 'part'];
        
        $words1 = array_filter(preg_split('/\s+/', strtolower($content1)), function($word) use ($stop_words) {
            return strlen($word) > 3 && !in_array($word, $stop_words);
        });
        
        $words2 = array_filter(preg_split('/\s+/', strtolower($content2)), function($word) use ($stop_words) {
            return strlen($word) > 3 && !in_array($word, $stop_words);
        });
        
        $common_words = array_intersect($words1, $words2);
        $total_words = array_unique(array_merge($words1, $words2));
        
        if (empty($total_words)) {
            return 0;
        }
        
        return count($common_words) / count($total_words);
    }
    
    /**
     * Calculate category similarity between two posts
     */
    private function calculate_category_similarity($post_id1, $post_id2) {
        $cats1 = wp_get_post_categories($post_id1);
        $cats2 = wp_get_post_categories($post_id2);
        
        if (empty($cats1) || empty($cats2)) {
            return 0;
        }
        
        $common_cats = array_intersect($cats1, $cats2);
        $total_cats = array_unique(array_merge($cats1, $cats2));
        
        if (empty($total_cats)) {
            return 0;
        }
        
        return count($common_cats) / count($total_cats);
    }
    
    /**
     * Create individual link
     */
    private function create_link($silo_id, $source_post_id, $target_post_id, $settings) {
        // Validate posts exist
        $source_post = get_post($source_post_id);
        $target_post = get_post($target_post_id);
        
        if (!$source_post || !$target_post) {
            error_log("SSP Link Creation Failed: Source or target post not found (source: {$source_post_id}, target: {$target_post_id})");
            return false;
        }
        
        // Don't link to unpublished posts
        if ($source_post->post_status !== 'publish' || $target_post->post_status !== 'publish') {
            error_log("SSP Link Creation Skipped: One or both posts are not published (source: {$source_post->post_status}, target: {$target_post->post_status})");
            return false;
        }
        
        // Check if link already exists
        $existing_links = SSP_Database::get_post_links($source_post_id, $silo_id);
        foreach ($existing_links as $link) {
            if ($link->target_post_id == $target_post_id) {
                error_log("SSP Link Creation Skipped: Link already exists from {$source_post_id} to {$target_post_id}");
                return false; // Link already exists
            }
        }
        
        // Check if target post is excluded
        if ($this->is_excluded($source_post_id, $target_post_id)) {
            error_log("SSP Link Creation Skipped: Post {$target_post_id} is excluded");
            return false;
        }
        
        // Get anchor text with duplicate prevention
        $anchor_text = $this->get_anchor_text($source_post_id, $target_post_id, $settings);
        if (empty($anchor_text) || !is_string($anchor_text)) {
            error_log("SSP Link Creation Failed: No anchor text generated for posts {$source_post_id} -> {$target_post_id}");
            return false;
        }
        
        // Validate anchor text is not just whitespace and meets goals
        $anchor_text = trim($anchor_text);
        if (empty($anchor_text) || strlen($anchor_text) < 1) {
            error_log("SSP Link Creation Failed: Empty or invalid anchor text for posts {$source_post_id} -> {$target_post_id}");
            return false;
        }
        
        // Allow single-word anchors when they are most natural
        $word_count = str_word_count($anchor_text);
        if ($word_count === false || $word_count < 1) {
            error_log("SSP Link Creation Failed: Invalid anchor text word count");
            return false;
        }
        
        // Validate anchor text length (safety limit)
        if (strlen($anchor_text) > 100) {
            error_log("SSP Link Creation Warning: Anchor text exceeds 100 characters, truncating");
            $anchor_text = substr($anchor_text, 0, 100);
            $anchor_text = rtrim($anchor_text, '.,!?;:"()[]');
        }
        
        // Check for duplicate anchors in this silo (if tracking enabled)
        $anchor_lower = strtolower($anchor_text);
        if (isset($settings['used_anchors']) && is_array($settings['used_anchors'])) {
            // Try alternative anchors if this one is already used
            $max_attempts = 10;
            $attempt = 0;
            while (in_array($anchor_lower, $settings['used_anchors']) && $attempt < $max_attempts) {
                error_log("SSP Link Creation: Anchor '{$anchor_text}' already used in silo, trying alternative");
                
                // Get alternative anchors
                $alternatives = $this->get_alternative_anchors($source_post_id, $target_post_id, $settings, $anchor_text);
                if (!empty($alternatives)) {
                    foreach ($alternatives as $alt_anchor) {
                        $alt_lower = strtolower(trim($alt_anchor));
                        if (!in_array($alt_lower, $settings['used_anchors'])) {
                            $anchor_text = $alt_anchor;
                            $anchor_lower = $alt_lower;
                            break;
                        }
                    }
                }
                $attempt++;
            }
            
            // If still duplicate after max attempts, append a number to make it unique
            // Ensure the final anchor remains multi-word and semantic
            if (in_array($anchor_lower, $settings['used_anchors'])) {
                $base_anchor = $anchor_text;
                $base_word_count = str_word_count($base_anchor);
                
                // Validate base anchor is multi-word before appending
                if ($base_word_count === false || $base_word_count < 2) {
                    error_log("SSP Link Creation Warning: Base anchor '{$base_anchor}' is not multi-word, attempting to rebuild");
                    // Try to get alternatives one more time
                    $alternatives = $this->get_alternative_anchors($source_post_id, $target_post_id, $settings, $anchor_text);
                    if (!empty($alternatives)) {
                        foreach ($alternatives as $alt_anchor) {
                            $alt_lower = strtolower(trim($alt_anchor));
                            $alt_word_count = str_word_count($alt_anchor);
                            if (!in_array($alt_lower, $settings['used_anchors']) && 
                                $alt_word_count !== false && $alt_word_count >= 2) {
                                $anchor_text = $alt_anchor;
                                $anchor_lower = $alt_lower;
                                break;
                            }
                        }
                    }
                }
                
                // If still duplicate or single-word, append number (but ensure multi-word)
                if (in_array($anchor_lower, $settings['used_anchors'])) {
                    $base_anchor = $anchor_text;
                    $counter = 1;
                    do {
                        $anchor_text = $base_anchor . ' ' . $counter;
                        $anchor_lower = strtolower(trim($anchor_text));
                        $counter++;
                    } while (in_array($anchor_lower, $settings['used_anchors']) && $counter <= 5);
                    
                    // Validate final anchor is multi-word
                    $final_word_count = str_word_count($anchor_text);
                    if ($final_word_count === false || $final_word_count < 2) {
                        error_log("SSP Link Creation Warning: Final anchor '{$anchor_text}' after numbering is not multi-word");
                    }
                }
            }
            
            // Mark this anchor as used
            $settings['used_anchors'][] = $anchor_lower;
        }
        
        // Check if anchor text is excluded
        if ($this->is_excluded($source_post_id, $target_post_id, $anchor_text)) {
            error_log("SSP Link Creation Skipped: Anchor text '{$anchor_text}' is excluded");
            return false;
        }
        
        // Check anchor usage limits
        $anchor_settings = get_option('ssp_anchor_settings', array(
            'max_usage_per_anchor' => 10,
            'warning_threshold' => 7
        ));
        
        if (SSP_Database::check_anchor_limit($anchor_text, $anchor_settings['max_usage_per_anchor'])) {
            error_log("SSP Link Creation Warning: Anchor '{$anchor_text}' has reached max usage limit ({$anchor_settings['max_usage_per_anchor']}). Trying alternatives.");
            // Try to get alternative anchor text
            $all_suggestions = $this->ai_integration->get_anchor_suggestions($source_post_id, $target_post_id);
            if (!empty($all_suggestions) && is_array($all_suggestions)) {
                $alternative_found = false;
                foreach ($all_suggestions as $alt_anchor) {
                    if (!is_string($alt_anchor) || empty(trim($alt_anchor))) {
                        continue;
                    }
                    
                    $alt_anchor = trim($alt_anchor);
                    // Validate alternative is multi-word (goal compliance)
                    $alt_word_count = str_word_count($alt_anchor);
                    if ($alt_word_count === false || $alt_word_count < 2) {
                        continue; // Skip single-word alternatives
                    }
                    
                    if (!SSP_Database::check_anchor_limit($alt_anchor, $anchor_settings['max_usage_per_anchor'])) {
                        $anchor_text = $alt_anchor;
                        error_log("SSP Link Creation: Using alternative anchor '{$anchor_text}' (words: {$alt_word_count}) instead");
                        $alternative_found = true;
                        break;
                    }
                }
                
                // If no valid alternatives found or all suggestions are over limit, skip this link
                if (!$alternative_found || SSP_Database::check_anchor_limit($anchor_text, $anchor_settings['max_usage_per_anchor'])) {
                    error_log("SSP Link Creation Skipped: All anchor suggestions for {$source_post_id} -> {$target_post_id} exceed usage limits or are not multi-word");
                    return false;
                }
            } else {
                // Try manual alternatives as last resort
                $manual_alternatives = $this->get_alternative_anchors($source_post_id, $target_post_id, $settings, $anchor_text);
                $manual_found = false;
                
                if (!empty($manual_alternatives) && is_array($manual_alternatives)) {
                    foreach ($manual_alternatives as $manual_alt) {
                        if (!is_string($manual_alt) || empty(trim($manual_alt))) {
                            continue;
                        }
                        
                        $manual_alt = trim($manual_alt);
                        $manual_word_count = str_word_count($manual_alt);
                        if ($manual_word_count === false || $manual_word_count < 2) {
                            continue;
                        }
                        
                        if (!SSP_Database::check_anchor_limit($manual_alt, $anchor_settings['max_usage_per_anchor'])) {
                            $anchor_text = $manual_alt;
                            error_log("SSP Link Creation: Using manual alternative anchor '{$anchor_text}' (words: {$manual_word_count})");
                            $manual_found = true;
                            break;
                        }
                    }
                }
                
                if (!$manual_found) {
                    error_log("SSP Link Creation Skipped: No valid alternatives available for {$source_post_id} -> {$target_post_id}");
                    return false;
                }
            }
        }
        
        // Find insertion point
        $insertion_point = $this->find_insertion_point($source_post_id, $anchor_text);
        if ($insertion_point === false) {
            error_log("SSP Link Creation Failed: No insertion point found for post {$source_post_id} with anchor '{$anchor_text}'");
            return false;
        }
        
        // Save link to database
        $link_data = array(
            'silo_id' => $silo_id,
            'source_post_id' => $source_post_id,
            'target_post_id' => $target_post_id,
            'anchor_text' => $anchor_text,
            'link_position' => $insertion_point,
            'placement_type' => $settings['placement_type'] ?? 'natural',
            'ai_generated' => 0
        );
        
        $link_id = SSP_Database::save_link($link_data);
        
        if ($link_id === false) {
            error_log("SSP Link Creation Failed: Database save failed for posts {$source_post_id} -> {$target_post_id}");
            return false;
        }
        
        // Actually insert the link into the post content
        $insert_result = $this->insert_link_into_content($source_post_id, $target_post_id, $anchor_text, $insertion_point, $link_id, $settings);
        
        if (!$insert_result) {
            error_log("SSP Link Creation Failed: Content insertion failed for posts {$source_post_id} -> {$target_post_id}");
            // Delete the database entry since content insertion failed
            global $wpdb;
            $wpdb->delete($wpdb->prefix . 'ssp_links', array('id' => $link_id), array('%d'));
            return false;
        }
        
        error_log("SSP Link Creation Success: Created link from post {$source_post_id} to {$target_post_id} with anchor '{$anchor_text}'");
        return true;
    }
    
    /**
     * Insert link into post content by finding and replacing existing text
     */
    private function insert_link_into_content($source_post_id, $target_post_id, $anchor_text, $insertion_point, $link_id, $settings) {
        $post = get_post($source_post_id);
        if (!$post) {
            error_log("SSP Link Insertion Failed: Source post {$source_post_id} not found");
            return false;
        }
        
        // Validate anchor text is not empty
        if (empty($anchor_text) || trim($anchor_text) === '') {
            error_log("SSP Link Insertion Failed: Anchor text is empty for post {$source_post_id}");
            return false;
        }
        
        $content = $post->post_content ?? '';
        
        // Get permalink - ensure it's absolute and properly formatted
        $target_url = get_permalink($target_post_id);
        
        if (!$target_url) {
            error_log("SSP Link Insertion Failed: Could not get permalink for post {$target_post_id}");
            return false;
        }
        
        // Ensure URL is absolute (if it's relative, make it absolute)
        if (strpos($target_url, 'http') !== 0) {
            $target_url = home_url($target_url);
        }
        
        // Verify the post exists and is published
        $target_post = get_post($target_post_id);
        if (!$target_post || $target_post->post_status !== 'publish') {
            error_log("SSP Link Insertion Failed: Target post {$target_post_id} is not published or doesn't exist");
            return false;
        }
        
        // Create the link HTML with marker
        $link_html = sprintf(
            '<!--%s%d--><a href="%s" class="ssp-internal-link">%s</a><!--/%s%d-->',
            $this->link_marker_prefix,
            $link_id,
            esc_url($target_url),
            esc_html($anchor_text),
            $this->link_marker_prefix,
            $link_id
        );
        
        // Check placement type
        $placement_type = $settings['placement_type'] ?? 'natural';
        
        // For first_paragraph placement, limit search to first paragraph only
        if ($placement_type === 'first_paragraph') {
            $first_para = $this->extract_first_paragraph($content);
            if ($first_para) {
                $new_content = $this->find_and_link_text($first_para, $anchor_text, $link_html, $source_post_id, $target_post_id);
                if ($new_content !== false) {
                    // Replace first paragraph in full content (only first occurrence)
                    $first_para_pos = strpos($content, $first_para);
                    if ($first_para_pos !== false && $first_para_pos < strlen($content)) {
                        $first_para_len = strlen($first_para);
                        $content = substr_replace($content, $new_content, $first_para_pos, $first_para_len);
                        $new_content = $content;
                    } else {
                        // Fallback if exact match not found
                        error_log("SSP Link Insertion Warning: Could not locate first paragraph in content, using str_replace");
                        $content = str_replace($first_para, $new_content, $content);
                        $new_content = $content;
                    }
                } else {
                    error_log("SSP Link Insertion: Could not find text in first paragraph, trying full content");
                    $new_content = $this->find_and_link_text($content, $anchor_text, $link_html, $source_post_id, $target_post_id);
                }
            } else {
                // No first paragraph found, use natural placement
                $new_content = $this->find_and_link_text($content, $anchor_text, $link_html, $source_post_id, $target_post_id);
            }
        } else {
            // Natural placement - find anywhere in content
            $new_content = $this->find_and_link_text($content, $anchor_text, $link_html, $source_post_id, $target_post_id);
        }
        
        if ($new_content === false) {
            error_log("SSP Link Insertion Failed: Could not find suitable text to link in post {$source_post_id}");
            return false;
        }
        
        // Update the post content
        error_log("SSP Link Insertion: About to update post {$source_post_id}. Content length before: " . strlen($content) . ", after: " . strlen($new_content));
        
        // Set flag to prevent recursive updates from other plugins
        $this->updating_post = true;
        
        $update_result = wp_update_post(array(
            'ID' => $source_post_id,
            'post_content' => $new_content
        ), true); // true = return WP_Error on failure
        
        $this->updating_post = false;
        
        if (is_wp_error($update_result)) {
            error_log("SSP Link Insertion Failed: " . $update_result->get_error_message());
            return false;
        }
        
        if ($update_result === 0) {
            error_log("SSP Link Insertion Failed: Post update returned 0 for post {$source_post_id}");
            return false;
        }
        
        // Verify the update by re-reading the post
        $updated_post = get_post($source_post_id);
        if ($updated_post && strpos($updated_post->post_content, $this->link_marker_prefix . $link_id) !== false) {
            error_log("SSP Link Insertion Success: Verified link marker exists in post {$source_post_id} content");
        } else {
            error_log("SSP Link Insertion Warning: Link marker NOT found in post {$source_post_id} after update!");
        }
        
        // Clear cache
        delete_transient('ssp_post_links_' . $source_post_id);
        
        return true;
    }
    
    /**
     * Extract first paragraph from content
     */
    private function extract_first_paragraph($content) {
        // Try to find first paragraph tag
        if (preg_match('/<p[^>]*>(.*?)<\/p>/is', $content, $matches)) {
            return $matches[0]; // Return full <p>...</p> tag
        }
        
        // If no <p> tags, try to get first text block before double line break
        $text_content = wp_strip_all_tags($content);
        $paragraphs = preg_split('/\n\n+/', $text_content);
        if (!empty($paragraphs[0])) {
            // Find this text in original content
            $first_text = trim($paragraphs[0]);
            if (!empty($first_text) && strlen($first_text) > 50) {
                return $first_text;
            }
        }
        
        return false;
    }
    
    /**
     * Find existing text in content and replace it with a link
     */
    private function find_and_link_text($content, $anchor_text, $link_html, $source_post_id, $target_post_id) {
        // Validate inputs
        if (empty($content) || empty($anchor_text) || empty($link_html)) {
            error_log("SSP Find & Link: Invalid parameters - empty content, anchor, or link HTML");
            return false;
        }
        
        // Remove any existing SSP link markers to search in clean content
        $clean_content = preg_replace('/<!--\s*' . preg_quote($this->link_marker_prefix, '/') . '\d+\s*-->.*?<!--\s*\/' . preg_quote($this->link_marker_prefix, '/') . '\d+\s*-->/s', '', $content);
        
        // Validate preg_replace didn't fail (returns null on error)
        if ($clean_content === null) {
            $clean_content = $content; // Fallback to original content
            error_log("SSP Find & Link: preg_replace failed, using original content");
        }
        
        // Strategy 1: Try to find exact match (case-insensitive)
        $position = $this->find_text_position($clean_content, $anchor_text, true);
        
        if ($position !== false) {
            error_log("SSP Find & Link: Found exact match for '{$anchor_text}' at position {$position}");
            return $this->replace_text_with_link($content, $anchor_text, $link_html, $position, strlen($anchor_text));
        }
        
        // Strategy 2: Try to find target post title in source content
        $target_post = get_post($target_post_id);
        if ($target_post && $target_post->post_title) {
            $title = $target_post->post_title;
            $position = $this->find_text_position($clean_content, $title, true);
            
            if ($position !== false) {
                error_log("SSP Find & Link: Found post title '{$title}' at position {$position}");
                // Create new link HTML with the found title as anchor
                $new_link_html = str_replace(esc_html($anchor_text), esc_html($title), $link_html);
                return $this->replace_text_with_link($content, $title, $new_link_html, $position, strlen($title));
            }
        }
        
        // Strategy 3: Try to find partial matches (at least 60% of words)
        $words = explode(' ', $anchor_text);
        if (count($words) > 1) {
            $found_text = $this->find_partial_match($clean_content, $words);
            if ($found_text !== false) {
                error_log("SSP Find & Link: Found partial match '{$found_text}' in content");
                $position = stripos($clean_content, $found_text);
                if ($position !== false) {
                $new_link_html = str_replace(esc_html($anchor_text), esc_html($found_text), $link_html);
                return $this->replace_text_with_link($content, $found_text, $new_link_html, $position, strlen($found_text));
                }
            }
        }
        
        // Strategy 4: Find multi-word phrases from target post title in source content (preserve case)
        $target_post = get_post($target_post_id);
        if ($target_post && !empty($target_post->post_title)) {
            $target_title = $target_post->post_title;
            
            // Try full title first
            $position = $this->find_text_position($clean_content, $target_title, false);
            if ($position !== false) {
                error_log("SSP Find & Link: Found full title '{$target_title}' from target post");
                // Find exact case in original content
                $title_in_content = $this->find_exact_case_in_content($content, $target_title, $position);
                if ($title_in_content) {
                    $new_link_html = str_replace(esc_html($anchor_text), esc_html($title_in_content), $link_html);
                    return $this->replace_text_with_link($content, $title_in_content, $new_link_html, false, 0);
                }
            }
            
            // Try 2-3 word phrases from title
            $title_words = preg_split('/\s+/', trim($target_title));
            if (is_array($title_words) && count($title_words) > 1) {
                // Try 2-word phrases
                for ($i = 0; $i < count($title_words) - 1; $i++) {
                    if (isset($title_words[$i]) && isset($title_words[$i + 1])) {
                        $phrase = trim($title_words[$i] . ' ' . $title_words[$i + 1], '.,!?;:"()[]');
                        if (strlen($phrase) >= 4) {
                            $position = $this->find_text_position($clean_content, $phrase, false);
                            if ($position !== false) {
                                $phrase_in_content = $this->find_exact_case_in_content($content, $phrase, $position);
                                if ($phrase_in_content) {
                                    error_log("SSP Find & Link: Found 2-word phrase '{$phrase_in_content}' from target title");
                                    $new_link_html = str_replace(esc_html($anchor_text), esc_html($phrase_in_content), $link_html);
                                    return $this->replace_text_with_link($content, $phrase_in_content, $new_link_html, false, 0);
                                }
                            }
                        }
                    }
                }
            }
            
            // Last resort: try single meaningful words (preserve case)
            $target_words = $this->extract_meaningful_words($target_title);
            foreach ($target_words as $word) {
                if (strlen($word) < 4) continue; // Skip short words
                
                $position = $this->find_text_position($clean_content, $word, true);
                if ($position !== false) {
                    // Find exact case in original content
                    $word_in_content = $this->find_exact_case_in_content($content, $word, $position);
                    if ($word_in_content) {
                        error_log("SSP Find & Link: Found keyword '{$word_in_content}' from target title (preserved case)");
                        $new_link_html = str_replace(esc_html($anchor_text), esc_html($word_in_content), $link_html);
                        return $this->replace_text_with_link($content, $word_in_content, $new_link_html, false, 0);
                    }
                }
            }
        }
        
        // Strategy 5: Fallback - if no text found, return false
        error_log("SSP Find & Link: Could not find any suitable text to link for anchor '{$anchor_text}'");
        return false;
    }
    
    /**
     * Find position of text in content (case-insensitive, word-boundary aware)
     */
    private function find_text_position($content, $search_text, $word_boundary = true) {
        // Strip HTML tags to search in plain text
        $plain_content = wp_strip_all_tags($content ?? '');
        
        if (empty($plain_content) || empty($search_text)) {
            return false;
        }
        
        if ($word_boundary) {
            // Use regex to find whole word matches
            $pattern = '/\b' . preg_quote($search_text, '/') . '\b/i';
            if (preg_match($pattern, $plain_content, $matches, PREG_OFFSET_CAPTURE) && isset($matches[0][1])) {
                return $matches[0][1];
            }
        } else {
            // Simple case-insensitive search
            $pos = stripos($plain_content, $search_text);
            if ($pos !== false) {
                return $pos;
            }
        }
        
        return false;
    }
    
    /**
     * Find exact case version of text in content (preserves original capitalization)
     */
    private function find_exact_case_in_content($content, $search_text, $approximate_position = false) {
        if (empty($content) || empty($search_text)) {
            return false;
        }
        
        $plain_content = wp_strip_all_tags($content);
        
        // If approximate position given, search around that area first
        if ($approximate_position !== false && $approximate_position >= 0) {
            $search_radius = 100;
            $start = max(0, $approximate_position - $search_radius);
            $end = min(strlen($plain_content), $approximate_position + strlen($search_text) + $search_radius);
            $search_area = substr($plain_content, $start, $end - $start);
            
            // Case-insensitive search in that area
            $pattern = '/' . preg_quote($search_text, '/') . '/i';
            if (preg_match($pattern, $search_area, $matches)) {
                return $matches[0]; // Return exact case from content
            }
        }
        
        // Full content search (case-insensitive, return exact match)
        $pattern = '/' . preg_quote($search_text, '/') . '/i';
        if (preg_match($pattern, $plain_content, $matches)) {
            return $matches[0]; // Return exact case from content
        }
        
        return false;
    }
    
    /**
     * Replace text with link HTML at specific position
     */
    private function replace_text_with_link($content, $original_text, $link_html, $position, $length) {
        // Validate inputs
        if (empty($content) || empty($original_text) || empty($link_html)) {
            error_log("SSP Find & Link: Invalid parameters for replace_text_with_link");
            return false;
        }
        
        // Find the exact occurrence in the original HTML content
        // We need to preserve HTML structure while replacing text
        
        // Use case-insensitive replacement, but preserve original case
        $pattern = '/' . preg_quote($original_text, '/') . '/i';
        
        // Replace only the first occurrence
        $new_content = preg_replace($pattern, $link_html, $content, 1);
        
        // Check for preg_replace errors (returns null) or no replacement (returns original)
        if ($new_content === null) {
            error_log("SSP Find & Link: Regex replacement failed (null) for '{$original_text}'");
            return false;
        }
        
        if ($new_content === $content) {
            error_log("SSP Find & Link: Regex replacement did not change content for '{$original_text}'");
            return false;
        }
        
        return $new_content;
    }
    
    /**
     * Find partial match of words in content
     */
    private function find_partial_match($content, $words) {
        if (empty($words) || !is_array($words)) {
            return false;
        }
        
        $plain_content = wp_strip_all_tags($content);
        if (empty($plain_content)) {
            return false;
        }
        
        $word_count = count($words);
        // Try combinations of consecutive words
        for ($length = $word_count; $length >= ceil($word_count * 0.6); $length--) {
            for ($start = 0; $start <= $word_count - $length; $start++) {
                $phrase = implode(' ', array_slice($words, $start, $length));
                if (empty($phrase)) {
                    continue;
                }
                $position = stripos($plain_content, $phrase);
                
                if ($position !== false) {
                    return $phrase;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Extract meaningful words from text
     */
    private function extract_meaningful_words($text) {
        if (empty($text)) {
            return array();
        }
        
        $words = preg_split('/\s+/', strtolower($text));
        if ($words === false || empty($words)) {
            return array();
        }
        
        $stop_words = array('the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'is', 'are', 'was', 'were', 'be', 'been', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could', 'should', 'may', 'might', 'can');
        
        $meaningful = array();
        foreach ($words as $word) {
            if (empty($word)) {
                continue;
            }
            $word = trim($word, '.,!?;:');
            if (strlen($word) >= 4 && !in_array($word, $stop_words)) {
                $meaningful[] = $word;
            }
        }
        
        return $meaningful;
    }
    
    /**
     * Get anchor text for link using smart strategies
     */
    private function get_anchor_text($source_post_id, $target_post_id, $settings) {
        // Validate posts exist
        $source_post = get_post($source_post_id);
        $target_post = get_post($target_post_id);
        
        if (!$source_post || !$target_post) {
            error_log("SSP Anchor Text: Source or target post not found for posts {$source_post_id} -> {$target_post_id}");
            return false;
        }
        
        // Always try AI first if configured (even if use_ai_anchors setting is not explicitly set)
        $ai_settings = get_option('ssp_settings', array());
        $api_key = $ai_settings['openai_api_key'] ?? '';
        
        // Try AI suggestions if API key exists and AI is configured
        if (!empty($api_key) && $this->ai_integration->is_api_configured()) {
            try {
            $suggestions = $this->ai_integration->get_anchor_suggestions($source_post_id, $target_post_id);
                if (!empty($suggestions) && is_array($suggestions)) {
                    // Filter out single-word suggestions, prefer multi-word semantic phrases
                    $best_suggestion = null;
                    $fallback_suggestion = null;
                    
                    foreach ($suggestions as $suggestion) {
                        if (!is_string($suggestion)) {
                            continue;
                        }
                        
                        $suggestion = trim($suggestion);
                        if (empty($suggestion) || strlen($suggestion) < 1) {
                            continue;
                        }
                        
                        // Prefer suggestions with 2+ words (semantic phrases) - GOAL: Multi-word only
                        $word_count = str_word_count($suggestion);
                        
                        // str_word_count returns false for non-strings or 0 for empty strings
                        if ($word_count === false || $word_count === 0) {
                            continue;
                        }
                        
                        if ($word_count >= 1 && $word_count <= 6) {
                            // Accept single or multi-word; earlier code preferring multi-word removed
                            $best_suggestion = $suggestion;
                            break; // Found usable suggestion
                        } elseif ($fallback_suggestion === null) {
                            // Keep as last resort fallback only if absolutely no multi-word found
                            // But we prefer to skip single-word and use manual generation instead
                            $fallback_suggestion = $suggestion;
                        }
                    }
                    
                    // Use best suggestion
                    if (!empty($best_suggestion) && strlen($best_suggestion) > 1) {
                        $final_word_count = str_word_count($best_suggestion);
                        if ($final_word_count !== false && $final_word_count >= 1) {
                            error_log("SSP Anchor Text: Using AI-generated anchor '{$best_suggestion}' (words: {$final_word_count}) for post {$source_post_id} -> {$target_post_id}");
                            $cleaned = $this->clean_anchor_text($best_suggestion);
                            if ($cleaned !== false) {
                                return $cleaned;
                            }
                        }
                    }
                    
                    // If we have a fallback single-word, use it
                    if (!empty($fallback_suggestion)) {
                        $cleaned = $this->clean_anchor_text($fallback_suggestion);
                        if ($cleaned !== false) {
                            error_log("SSP Anchor Text: Using fallback AI anchor '{$cleaned}'");
                            return $cleaned;
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("SSP Anchor Text: AI suggestion error: " . $e->getMessage());
            }
            
            if (isset($settings['use_ai_anchors']) && $settings['use_ai_anchors']) {
                error_log("SSP Anchor Text: AI suggestions unavailable for post {$source_post_id} -> {$target_post_id}, falling back to manual generation");
            }
        }
        
        // Use smart anchor text generation (manual)
        $anchor = $this->generate_smart_anchor_text($source_post_id, $target_post_id, $settings);
        
        if (empty($anchor) || !is_string($anchor)) {
            // Last resort fallback: use target title
            $target_title = $target_post->post_title ?? '';
            if (!empty($target_title)) {
                $title_word_count = str_word_count($target_title);
                
                // Use title directly when helpful
                if ($title_word_count !== false && $title_word_count >= 1) {
                    error_log("SSP Anchor Text: All strategies failed, using target title as fallback for post {$source_post_id} -> {$target_post_id}");
                    return $this->clean_anchor_text($target_title);
                } else {
                    // Single-word title: build contextual phrase
                    $contextual_prefixes = array('best', 'guide to', 'learn about', 'about');
                    foreach ($contextual_prefixes as $prefix) {
                        $phrase = $prefix . ' ' . $target_title;
                        if (strlen($phrase) <= 100) {
                            $cleaned = $this->clean_anchor_text($phrase);
                            if ($cleaned !== false) {
                                error_log("SSP Anchor Text: All strategies failed, using contextual phrase '{$cleaned}' as fallback for post {$source_post_id} -> {$target_post_id}");
                                return $cleaned;
                            }
                        }
                    }
                    
                    // Absolute last resort: use title with warning (single-word)
                    error_log("SSP Anchor Text: Using single-word title '{$target_title}' as anchor for post {$target_post_id}.");
                    return $this->clean_anchor_text($target_title);
                }
            }
            
            error_log("SSP Anchor Text: Failed to generate anchor text for posts {$source_post_id} -> {$target_post_id}");
            return false;
        }
        
        $word_count = str_word_count($anchor);
        error_log("SSP Anchor Text: Generated manual anchor '{$anchor}' ({$word_count} words) for post {$source_post_id} -> {$target_post_id}");
        return $this->clean_anchor_text($anchor);
    }
    
    /**
     * Get alternative anchor texts (for duplicate prevention)
     */
    private function get_alternative_anchors($source_post_id, $target_post_id, $settings, $original_anchor) {
        $alternatives = array();
        
        // Try AI suggestions if API key exists (like get_anchor_text does)
        $ai_settings = get_option('ssp_settings', array());
        $api_key = $ai_settings['openai_api_key'] ?? '';
        
        if (!empty($api_key) && $this->ai_integration->is_api_configured()) {
            try {
                $ai_suggestions = $this->ai_integration->get_anchor_suggestions($source_post_id, $target_post_id);
                if (!empty($ai_suggestions) && is_array($ai_suggestions)) {
                    foreach ($ai_suggestions as $suggestion) {
                        if (!is_string($suggestion)) {
                            continue;
                        }
                        $suggestion = trim($suggestion);
                        // Ensure multi-word and different from original (goal compliance)
                        $word_count = str_word_count($suggestion);
                        if (!empty($suggestion) && 
                            $word_count !== false && $word_count >= 2 &&
                            strtolower($suggestion) !== strtolower(trim($original_anchor))) {
                            $alternatives[] = $suggestion;
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("SSP Alternative Anchors: AI suggestion error: " . $e->getMessage());
            }
        }
        
        // Generate manual alternatives using different strategies
        $source_post = get_post($source_post_id);
        $target_post = get_post($target_post_id);
        
        if ($source_post && $target_post) {
            $target_title = $target_post->post_title ?? '';
            
            // Strategy 1: Use target title if multi-word and different
            if (!empty($target_title) && strtolower(trim($target_title)) !== strtolower(trim($original_anchor))) {
                $title_word_count = str_word_count($target_title);
                // Only add if title is multi-word (goal: avoid single-word anchors)
                if ($title_word_count >= 2) {
                    $alternatives[] = $target_title;
                }
            }
            
            // Strategy 2: Build contextual phrases from title words (always multi-word)
            if (!empty($target_title)) {
                $title_words = preg_split('/\s+/', trim($target_title));
                if (is_array($title_words) && count($title_words) >= 2) {
                    // Create 2-3 word phrases
                    for ($i = 0; $i < min(count($title_words) - 1, 2); $i++) {
                        if (isset($title_words[$i]) && isset($title_words[$i + 1])) {
                            $phrase = trim($title_words[$i] . ' ' . $title_words[$i + 1]);
                            if (!empty($phrase) && str_word_count($phrase) >= 2 && strtolower($phrase) !== strtolower($original_anchor)) {
                                $alternatives[] = $phrase;
                            }
                        }
                    }
                    
                    // Try 3-word phrases if available
                    if (count($title_words) >= 3) {
                        for ($i = 0; $i <= count($title_words) - 3 && $i < 2; $i++) {
                            if (isset($title_words[$i]) && isset($title_words[$i + 1]) && isset($title_words[$i + 2])) {
                                $phrase = trim($title_words[$i] . ' ' . $title_words[$i + 1] . ' ' . $title_words[$i + 2]);
                                if (!empty($phrase) && str_word_count($phrase) >= 2 && strtolower($phrase) !== strtolower($original_anchor)) {
                                    $alternatives[] = $phrase;
                                }
                            }
                        }
                    }
                } else if (count($title_words) === 1) {
                    // Single-word title: build contextual phrase
                    $single_word = trim($title_words[0], '.,!?;:"()[]');
                    if (!empty($single_word)) {
                        $contextual = array('best ' . $single_word, 'guide to ' . $single_word, 'about ' . $single_word);
                        foreach ($contextual as $ctx_phrase) {
                            if (strtolower($ctx_phrase) !== strtolower($original_anchor)) {
                                $alternatives[] = $ctx_phrase;
                                break; // Add one contextual phrase
                            }
                        }
                    }
                }
            }
        }
        
        return array_unique($alternatives);
    }
    
    /**
     * Get all anchor text variations (for preview)
     */
    public function get_anchor_variations($source_post_id, $target_post_id, $settings) {
        $variations = array();
        
        // Check if AI is configured (like get_anchor_text does)
        $ai_settings = get_option('ssp_settings', array());
        $api_key = $ai_settings['openai_api_key'] ?? '';
        
        // Try AI suggestions first if API key exists and AI is configured
        if (!empty($api_key) && $this->ai_integration->is_api_configured()) {
            try {
            $ai_suggestions = $this->ai_integration->get_anchor_suggestions($source_post_id, $target_post_id);
            if (!empty($ai_suggestions) && is_array($ai_suggestions)) {
                    // Clean and validate suggestions (ensure multi-word)
                    foreach ($ai_suggestions as $suggestion) {
                        if (!is_string($suggestion)) {
                            continue;
                        }
                        $suggestion = trim($suggestion);
                        if (empty($suggestion) || strlen($suggestion) < 2) {
                            continue;
                        }
                        // Ensure multi-word (goal compliance)
                        $word_count = str_word_count($suggestion);
                        if ($word_count !== false && $word_count >= 2) {
                            $variations[] = $suggestion;
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("SSP Anchor Variations: AI suggestion error: " . $e->getMessage());
            }
        }
        
        // If no AI suggestions or not using AI, generate smart variations
        if (empty($variations)) {
            $smart_anchor = $this->generate_smart_anchor_text($source_post_id, $target_post_id, $settings);
            if (!empty($smart_anchor) && is_string($smart_anchor)) {
                $variations[] = $smart_anchor;
            }
            
            // Fallback to target title if smart generation fails (ensure multi-word)
            if (empty($variations)) {
                $target_post = get_post($target_post_id);
                if ($target_post && !empty($target_post->post_title)) {
                    $title = $target_post->post_title;
                    $title_word_count = str_word_count($title);
                    
                    // Only add if title is multi-word (goal compliance)
                    if ($title_word_count !== false && $title_word_count >= 2) {
                        $variations[] = $title;
                    } else {
                        // Single-word title: build contextual phrase
                        $contextual_prefixes = array('best', 'guide to', 'learn about', 'about');
                        foreach ($contextual_prefixes as $prefix) {
                            $phrase = $prefix . ' ' . $title;
                            if (strlen($phrase) <= 100 && str_word_count($phrase) >= 2) {
                                $variations[] = $phrase;
                                break;
                            }
                        }
                    }
                }
            }
        }
        
        return $variations;
    }
    
    /**
     * Generate smart anchor text using multiple strategies
     * Improved to preserve proper nouns and create more contextual anchors
     */
    private function generate_smart_anchor_text($source_post_id, $target_post_id, $settings) {
        $source_post = get_post($source_post_id);
        $target_post = get_post($target_post_id);
        
        if (!$source_post || !$target_post) {
            return false;
        }
        
        // Keep original capitalization for proper noun preservation
        $source_content_original = wp_strip_all_tags($source_post->post_content ?? '');
        $source_content_lower = strtolower($source_content_original);
        $target_title = $target_post->post_title ?? '';
        $target_content_original = wp_strip_all_tags($target_post->post_content ?? '');
        $target_content_lower = strtolower($target_content_original);
        
        // Strategy 1: Exact title match in source content (preserve original capitalization)
        $title_lower = strtolower($target_title);
        if ($title_lower && strpos($source_content_lower, $title_lower) !== false) {
            // Find the exact match in original content to preserve capitalization
            $position = stripos($source_content_original, $target_title);
            if ($position !== false && $position < strlen($source_content_original)) {
                // Extract the exact text from source (preserves capitalization)
                $target_title_len = strlen($target_title);
                $max_len = strlen($source_content_original) - $position;
                $matched_text = substr($source_content_original, $position, min($target_title_len, $max_len));
                if (!empty($matched_text)) {
                    $cleaned = $this->clean_anchor_text($matched_text);
                    if ($cleaned !== false) {
                        return $cleaned;
                    }
                }
            }
            
            // Fallback to target title if exact match not found (ensure multi-word)
            $title_word_count = str_word_count($target_title);
            if ($title_word_count !== false && $title_word_count >= 2) {
            return $this->clean_anchor_text($target_title);
            } else {
                // Single-word title: build contextual phrase
                $contextual_prefixes = array('best', 'guide to', 'learn about', 'about');
                foreach ($contextual_prefixes as $prefix) {
                    $phrase = $prefix . ' ' . $target_title;
                    $cleaned = $this->clean_anchor_text($phrase);
                    if ($cleaned !== false && str_word_count($cleaned) >= 2) {
                        return $cleaned;
                    }
                }
                // Absolute last resort with warning
                error_log("SSP Anchor Text: Warning - Using single-word title '{$target_title}' as anchor (GOAL VIOLATION)");
                return $this->clean_anchor_text($target_title);
            }
        }
        
        // Strategy 2: Find descriptive phrases from target title in source content
        $phrase_anchor = $this->find_descriptive_phrase($source_content_original, $source_content_lower, $target_title, $target_content_original);
        if ($phrase_anchor) {
            return $phrase_anchor;
        }
        
        // Strategy 3: Keyword overlap - find common meaningful words (preserve capitalization)
        $common_keywords = $this->find_common_keywords_preserve_case($source_content_original, $source_content_lower, $target_content_original, $target_content_lower, $target_title);
        if (!empty($common_keywords)) {
            $anchor_text = $this->build_anchor_from_keywords_preserve_case($common_keywords, $target_title);
            if ($anchor_text) {
                return $anchor_text;
            }
        }
        
        // Strategy 4: Internal Linking relationships (preserve capitalization)
        $semantic_anchor = $this->find_semantic_anchor_preserve_case($source_content_original, $source_content_lower, $target_content_original, $target_content_lower, $target_title);
        if ($semantic_anchor) {
            return $semantic_anchor;
        }
        
        // Strategy 5: Category-based matching
        $category_anchor = $this->find_category_anchor($source_post, $target_post);
        if ($category_anchor) {
            return $category_anchor;
        }
        
        // Strategy 6: Use target post title with contextual words
        $contextual_anchor = $this->create_contextual_anchor($source_content_lower, $target_title);
        if ($contextual_anchor) {
            return $contextual_anchor;
        }
        
        // Final fallback: use target title directly (only if multi-word)
        if (!empty($target_title)) {
            $title_word_count = str_word_count($target_title);
            // Only use title directly if it's multi-word (goal: avoid single-word anchors)
            if ($title_word_count !== false && $title_word_count >= 2) {
                return $this->clean_anchor_text($target_title);
            }
            
            // Single-word title: build contextual phrase (ensures multi-word)
            $contextual_prefixes = array('best', 'guide to', 'learn about', 'about');
            foreach ($contextual_prefixes as $prefix) {
                $phrase = $prefix . ' ' . $target_title;
                if (strlen($phrase) <= 100) {
                    $phrase_word_count = str_word_count($phrase);
                    // Ensure phrase is multi-word before returning
                    if ($phrase_word_count !== false && $phrase_word_count >= 2) {
                        $cleaned = $this->clean_anchor_text($phrase);
                        if ($cleaned !== false && str_word_count($cleaned) >= 2) {
                            return $cleaned;
                        }
                    }
                }
            }
            
            // If prefix fails, use title as-is (but log warning)
            error_log("SSP Anchor Text: Warning - Using single-word title '{$target_title}' as anchor for post {$target_post_id} (GOAL VIOLATION). Consider using AI suggestions.");
            $cleaned = $this->clean_anchor_text($target_title);
            // Even with warning, ensure we return something valid
            if ($cleaned !== false) {
                return $cleaned;
            }
            // Absolute last resort
            return $target_title;
        }
        
        // Last resort: use descriptive phrase with post ID
        return "Post {$target_post_id} content";
    }
    
    /**
     * Find common keywords between source and target content
     */
    private function find_common_keywords($source_content, $target_content) {
        // Extract meaningful words (length > 3, not common words)
        $stop_words = ['the', 'and', 'for', 'with', 'from', 'this', 'that', 'are', 'was', 'were', 'have', 'been', 'they', 'said', 'each', 'which', 'their', 'time', 'will', 'about', 'there', 'when', 'your', 'can', 'said', 'she', 'use', 'how', 'our', 'out', 'many', 'then', 'them', 'these', 'so', 'some', 'her', 'would', 'make', 'like', 'into', 'him', 'has', 'two', 'more', 'go', 'no', 'way', 'could', 'my', 'than', 'first', 'been', 'call', 'who', 'oil', 'its', 'now', 'find', 'long', 'down', 'day', 'did', 'get', 'come', 'made', 'may', 'part'];
        
        $source_words = array_filter(preg_split('/\s+/', $source_content), function($word) use ($stop_words) {
            return strlen($word) > 3 && !in_array($word, $stop_words);
        });
        
        $target_words = array_filter(preg_split('/\s+/', $target_content), function($word) use ($stop_words) {
            return strlen($word) > 3 && !in_array($word, $stop_words);
        });
        
        // Find intersection
        $common_words = array_intersect($source_words, $target_words);
        
        // Return most relevant ones (limit to 5)
        return array_slice(array_unique($common_words), 0, 5);
    }
    
    /**
     * Build anchor text from common keywords
     */
    private function build_anchor_from_keywords($keywords, $target_title) {
        if (empty($keywords)) {
            return false;
        }
        
        // Try to use keywords that appear in the target title
        $title_words = explode(' ', strtolower($target_title));
        $relevant_keywords = array_intersect($keywords, $title_words);
        
        if (!empty($relevant_keywords)) {
            // Use the most relevant keyword
            $keyword = array_values($relevant_keywords)[0];
            return ucfirst($keyword);
        }
        
        // Fallback to first keyword
        return ucfirst(array_values($keywords)[0]);
    }
    
    /**
     * Find semantic anchor based on content themes
     */
    private function find_semantic_anchor($source_content, $target_content, $target_title) {
        // Define semantic patterns for common industries
        $semantic_patterns = [
            'legal' => ['law', 'legal', 'attorney', 'lawyer', 'court', 'case', 'settlement', 'injury', 'accident'],
            'health' => ['health', 'medical', 'doctor', 'treatment', 'therapy', 'care', 'wellness', 'hospital'],
            'business' => ['business', 'company', 'corporate', 'management', 'marketing', 'sales', 'finance'],
            'technology' => ['technology', 'software', 'digital', 'computer', 'tech', 'app', 'system'],
            'finance' => ['finance', 'financial', 'money', 'investment', 'bank', 'credit', 'loan'],
            'education' => ['education', 'school', 'learning', 'student', 'teacher', 'course', 'training']
        ];
        
        foreach ($semantic_patterns as $category => $terms) {
            $source_has = false;
            $target_has = false;
            
            foreach ($terms as $term) {
                if (strpos($source_content, $term) !== false) $source_has = true;
                if (strpos($target_content, $term) !== false) $target_has = true;
            }
            
            if ($source_has && $target_has) {
                // Find the best term that appears in target title
                foreach ($terms as $term) {
                    if (strpos(strtolower($target_title), $term) !== false) {
                        return ucfirst($term);
                    }
                }
                // Fallback to category name
                return ucfirst($category);
            }
        }
        
        return false;
    }
    
    /**
     * Find category-based anchor (always returns multi-word phrase)
     */
    private function find_category_anchor($source_post, $target_post) {
        $source_categories = wp_get_post_categories($source_post->ID);
        $target_categories = wp_get_post_categories($target_post->ID);
        
        $common_categories = array_intersect($source_categories, $target_categories);
        
        if (!empty($common_categories)) {
            $category = get_category(array_values($common_categories)[0]);
            if ($category && !is_wp_error($category)) {
                $category_name = trim($category->name);
                $target_title = $target_post->post_title ?? '';
                
                // Check if category name is multi-word
                $category_words = explode(' ', $category_name);
                if (count($category_words) >= 2) {
                    // Category name is already multi-word, return it
                    return $category_name;
                }
                
                // Single-word category: build phrase with target title
                if (!empty($target_title)) {
                    $title_words = preg_split('/\s+/', trim($target_title));
                    if ($title_words !== false && count($title_words) >= 1) {
                        // Build "category + meaningful word from title"
                        foreach ($title_words as $word) {
                            $word_clean = trim($word, '.,!?;:"()[]');
                            if (strlen($word_clean) > 2 && strtolower($word_clean) !== strtolower($category_name)) {
                                return $category_name . ' ' . $word_clean;
                            }
                        }
                        
                        // If no suitable word found, use generic phrase
                        return $category_name . ' guide';
                    }
                }
                
                // Last resort: generic phrase
                return $category_name . ' guide';
            }
        }
        
        return false;
    }
    
    /**
     * Find descriptive phrase from target title in source content (preserves capitalization)
     */
    private function find_descriptive_phrase($source_content_original, $source_content_lower, $target_title, $target_content_original) {
        if (empty($target_title)) {
            return false;
        }
        
        // Extract key words from target title
        $title_words = preg_split('/\s+/', trim($target_title));
        if ($title_words === false) {
            return false;
        }
        
        $title_words = array_filter($title_words, function($word) {
            $word = trim($word, '.,!?;:"()[]');
            return strlen($word) > 2;
        });
        
        // Re-index array after filtering
        $title_words = array_values($title_words);
        $title_word_count = count($title_words);
        
        // Try to find 2-3 word phrases from target title in source content
        if ($title_word_count >= 2) {
            // Try 2-word phrases
            for ($i = 0; $i < $title_word_count - 1; $i++) {
                if (!isset($title_words[$i]) || !isset($title_words[$i + 1])) {
                    continue;
                }
                $phrase_lower = strtolower($title_words[$i] . ' ' . $title_words[$i + 1]);
                $phrase_original = $title_words[$i] . ' ' . $title_words[$i + 1];
                
                if (strpos($source_content_lower, $phrase_lower) !== false) {
                    // Find exact match in original to preserve capitalization
                    $position = stripos($source_content_original, $phrase_original);
                    if ($position !== false && $position < strlen($source_content_original)) {
                        $phrase_len = strlen($phrase_original);
                        $max_len = strlen($source_content_original) - $position;
                        $matched = substr($source_content_original, $position, min($phrase_len, $max_len));
                        if (!empty($matched)) {
                            return $this->clean_anchor_text($matched);
                        }
                    }
                }
            }
            
            // Try 3-word phrases for better context
            if ($title_word_count >= 3) {
                for ($i = 0; $i < $title_word_count - 2; $i++) {
                    if (!isset($title_words[$i]) || !isset($title_words[$i + 1]) || !isset($title_words[$i + 2])) {
                        continue;
                    }
                    $phrase_lower = strtolower($title_words[$i] . ' ' . $title_words[$i + 1] . ' ' . $title_words[$i + 2]);
                    $phrase_original = $title_words[$i] . ' ' . $title_words[$i + 1] . ' ' . $title_words[$i + 2];
                    
                    if (strpos($source_content_lower, $phrase_lower) !== false) {
                        $position = stripos($source_content_original, $phrase_original);
                        if ($position !== false && $position < strlen($source_content_original)) {
                            $phrase_len = strlen($phrase_original);
                            $max_len = strlen($source_content_original) - $position;
                            $matched = substr($source_content_original, $position, min($phrase_len, $max_len));
                            if (!empty($matched)) {
                                return $this->clean_anchor_text($matched);
                            }
                        }
                    }
                }
            }
        }
        
        return false;
    }
    
    /**
     * Find common keywords preserving original capitalization
     */
    private function find_common_keywords_preserve_case($source_content_original, $source_content_lower, $target_content_original, $target_content_lower, $target_title) {
        $stop_words = ['the', 'and', 'for', 'with', 'from', 'this', 'that', 'are', 'was', 'were', 'have', 'been', 'they', 'said', 'each', 'which', 'their', 'time', 'will', 'about', 'there', 'when', 'your', 'can', 'said', 'she', 'use', 'how', 'our', 'out', 'many', 'then', 'them', 'these', 'so', 'some', 'her', 'would', 'make', 'like', 'into', 'him', 'has', 'two', 'more', 'go', 'no', 'way', 'could', 'my', 'than', 'first', 'been', 'call', 'who', 'oil', 'its', 'now', 'find', 'long', 'down', 'day', 'did', 'get', 'come', 'made', 'may', 'part'];
        
        // Extract words preserving case
        $source_words_original = preg_split('/\s+/', $source_content_original ?? '');
        $source_words_lower = preg_split('/\s+/', $source_content_lower ?? '');
        $target_words_original = preg_split('/\s+/', $target_content_original ?? '');
        $target_words_lower = preg_split('/\s+/', $target_content_lower ?? '');
        
        // Validate preg_split results
        if (!is_array($source_words_original) || !is_array($source_words_lower) || !is_array($target_words_original) || !is_array($target_words_lower)) {
            return array();
        }
        
        // Find common keywords (case-insensitive)
        $common_lower = array();
        $word_map = array(); // Maps lowercase -> original case
        
        foreach ($source_words_lower as $index => $word_lower) {
            $word_original = isset($source_words_original[$index]) ? $source_words_original[$index] : $word_lower;
            $word_lower_clean = trim(strtolower($word_original), '.,!?;:"()[]');
            if (strlen($word_lower_clean) > 3 && !in_array($word_lower_clean, $stop_words)) {
                $word_map[$word_lower_clean] = $word_original;
                $common_lower[] = $word_lower_clean;
            }
        }
        
        $target_words_lower_clean = array();
        foreach ($target_words_lower as $index => $word_lower) {
            $word_original = isset($target_words_original[$index]) ? $target_words_original[$index] : $word_lower;
            $word_lower_clean = trim(strtolower($word_original), '.,!?;:"()[]');
            if (strlen($word_lower_clean) > 3 && !in_array($word_lower_clean, $stop_words)) {
                $target_words_lower_clean[] = $word_lower_clean;
            }
        }
        
        $common_keywords_lower = array_intersect($common_lower, $target_words_lower_clean);
        
        // Convert back to original case, prioritizing words from target title
        $result = array();
        
        if (empty($target_title)) {
            return $result;
        }
        
        $title_words_split = preg_split('/\s+/', $target_title);
        if (!is_array($title_words_split) || empty($title_words_split)) {
            return $result;
        }
        
        $title_words_lower = array_map('strtolower', $title_words_split);
        $title_words_original = $title_words_split; // Cache original split to avoid re-splitting
        
        foreach ($common_keywords_lower as $keyword_lower) {
            if (empty($keyword_lower)) {
                continue;
            }
            
            // Prefer original case from target title if word appears there
            $found_in_title = false;
            foreach ($title_words_lower as $index => $title_word_lower) {
                if ($keyword_lower === trim(strtolower($title_word_lower), '.,!?;:"()[]')) {
                    // Use cached original words
                    if (isset($title_words_original[$index])) {
                        $title_word = trim($title_words_original[$index], '.,!?;:"()[]');
                        if (!empty($title_word)) {
                            $result[] = $title_word;
                            $found_in_title = true;
                            break;
                        }
                    }
                }
            }
            
            if (!$found_in_title && isset($word_map[$keyword_lower])) {
                $result[] = trim($word_map[$keyword_lower], '.,!?;:"()[]');
            }
        }
        
        return array_slice(array_unique($result), 0, 5);
    }
    
    /**
     * Build anchor from keywords preserving case (creates multi-word contextual phrases)
     */
    private function build_anchor_from_keywords_preserve_case($keywords, $target_title) {
        if (empty($keywords) || !is_array($keywords)) {
            return false;
        }
        
        // Prefer keywords that appear in target title
        $title_words = preg_split('/\s+/', $target_title);
        if ($title_words === false || empty($title_words)) {
            // Fallback: build phrase from keywords
            return $this->build_phrase_from_keywords($keywords, $target_title);
        }
        
        $title_words_lower = array_map('strtolower', $title_words);
        
        $relevant_keywords = array();
        foreach ($keywords as $keyword) {
            if (empty($keyword)) {
                continue;
            }
            $keyword_lower = strtolower($keyword);
            foreach ($title_words_lower as $index => $title_word_lower) {
                if (isset($title_words[$index]) && $keyword_lower === trim(strtolower($title_words[$index]), '.,!?;:"()[]')) {
                    $relevant_keywords[] = trim($title_words[$index], '.,!?;:"()[]');
                    break;
                }
            }
        }
        
        if (!empty($relevant_keywords)) {
            // Build a 2-3 word phrase from relevant keywords found in title
            // Prefer proper nouns (words starting with capital)
            $proper_nouns = array();
            $other_keywords = array();
            
            foreach ($relevant_keywords as $keyword) {
                if (!empty($keyword)) {
                    if (ctype_upper(substr($keyword, 0, 1))) {
                        $proper_nouns[] = $keyword;
                    } else {
                        $other_keywords[] = $keyword;
                    }
                }
            }
            
            // Combine proper nouns first, then other keywords
            $phrase_words = array_merge($proper_nouns, $other_keywords);
            
            if (count($phrase_words) >= 2) {
                // Build 2-3 word phrase
                $phrase = implode(' ', array_slice($phrase_words, 0, 3));
                if (strlen($phrase) > 3) {
                    return $phrase;
                }
            }
            
            // If we only have one relevant keyword, try to find context words from title
            if (count($phrase_words) === 1 && count($title_words) > 1) {
                $main_keyword = $phrase_words[0];
                $main_keyword_lower = strtolower($main_keyword);
                
                // Find adjacent words in title to build phrase
                foreach ($title_words_lower as $index => $title_word_lower) {
                    if (trim($title_word_lower, '.,!?;:"()[]') === $main_keyword_lower) {
                        // Found keyword in title, try to get adjacent words
                        $phrase_parts = array();
                        
                        // Get word before (if exists)
                        if ($index > 0 && isset($title_words[$index - 1])) {
                            $phrase_parts[] = trim($title_words[$index - 1], '.,!?;:"()[]');
                        }
                        
                        // Add the keyword itself
                        $phrase_parts[] = $main_keyword;
                        
                        // Get word after (if exists)
                        if ($index < count($title_words) - 1 && isset($title_words[$index + 1])) {
                            $phrase_parts[] = trim($title_words[$index + 1], '.,!?;:"()[]');
                        }
                        
                        if (count($phrase_parts) >= 2) {
                            return implode(' ', array_slice($phrase_parts, 0, 3));
                        }
                        break;
                    }
                }
                
                // Fallback: use keyword with a descriptive prefix
                return $this->build_descriptive_phrase($main_keyword, $title_words);
            }
        }
        
        // Fallback: build phrase from all keywords
        return $this->build_phrase_from_keywords($keywords, $target_title);
    }
    
    /**
     * Build phrase from keywords array
     */
    private function build_phrase_from_keywords($keywords, $target_title) {
        if (empty($keywords) || !is_array($keywords)) {
            return false;
        }
        
        // Limit to 2-4 words for semantic phrase
        $phrase_words = array_slice($keywords, 0, 3);
        
        if (count($phrase_words) >= 2) {
            return implode(' ', $phrase_words);
        }
        
        // Single keyword fallback - ALWAYS build multi-word phrase
        $single_keyword = reset($keywords);
        if (empty($single_keyword)) {
            return false;
        }
        
        // Try to build descriptive phrase from title (guaranteed multi-word)
        if (!empty($target_title)) {
            $title_words = preg_split('/\s+/', $target_title);
            if (is_array($title_words) && count($title_words) >= 1) {
                $phrase = $this->build_descriptive_phrase($single_keyword, $title_words);
                // Ensure phrase is multi-word
                if ($phrase && str_word_count($phrase) >= 2) {
                    return $phrase;
                }
            }
        }
        
        // Last resort: add descriptive prefix to make it multi-word
        $prefixes = array('best', 'top', 'guide to', 'learn about', 'about', 'explore');
        foreach ($prefixes as $prefix) {
            $phrase = $prefix . ' ' . (ctype_upper(substr($single_keyword, 0, 1)) ? $single_keyword : ucfirst($single_keyword));
            if (strlen($phrase) <= 50 && str_word_count($phrase) >= 2) {
                return $phrase;
            }
        }
        
        // If all else fails, use target title words
        if (!empty($target_title)) {
            $title_words = preg_split('/\s+/', trim($target_title));
            if (is_array($title_words) && count($title_words) >= 2) {
                return implode(' ', array_slice($title_words, 0, 2));
            }
        }
        
        // Absolute last resort: keyword + generic word (ensures multi-word)
        return (ctype_upper(substr($single_keyword, 0, 1)) ? $single_keyword : ucfirst($single_keyword)) . ' guide';
    }
    
    /**
     * Build descriptive phrase from a keyword and title words
     */
    private function build_descriptive_phrase($keyword, $title_words) {
        $keyword_lower = strtolower(trim($keyword, '.,!?;:"()[]'));
        
        // Find keyword in title to get context
        foreach ($title_words as $index => $title_word) {
            $title_word_lower = strtolower(trim($title_word, '.,!?;:"()[]'));
            if ($title_word_lower === $keyword_lower) {
                // Found keyword, build phrase with adjacent words
                $phrase_parts = array();
                
                // Try to get 1-2 words before keyword
                $start = max(0, $index - 2);
                for ($i = $start; $i < $index; $i++) {
                    if (isset($title_words[$i])) {
                        $word = trim($title_words[$i], '.,!?;:"()[]');
                        if (!empty($word) && strlen($word) > 2) {
                            $phrase_parts[] = $word;
                        }
                    }
                }
                
                // Add keyword (preserve original case from title)
                $phrase_parts[] = trim($title_word, '.,!?;:"()[]');
                
                // Try to get 1 word after keyword
                if ($index < count($title_words) - 1 && isset($title_words[$index + 1])) {
                    $word = trim($title_words[$index + 1], '.,!?;:"()[]');
                    if (!empty($word) && strlen($word) > 2) {
                        $phrase_parts[] = $word;
                    }
                }
                
                if (count($phrase_parts) >= 2) {
                    return implode(' ', array_slice($phrase_parts, -3)); // Take last 3 words max
                }
                break;
            }
        }
        
        // If keyword not found in title, prepend descriptive word (ensures multi-word)
        $prefixes = array('best', 'top', 'guide to', 'learn about', 'about', 'explore');
        foreach ($prefixes as $prefix) {
            $phrase = $prefix . ' ' . (ctype_upper(substr($keyword, 0, 1)) ? $keyword : ucfirst($keyword));
            if (strlen($phrase) <= 50 && str_word_count($phrase) >= 2) {
                return $phrase;
            }
        }
        
        // Last resort: ensure multi-word by adding generic suffix
        $base_keyword = ctype_upper(substr($keyword, 0, 1)) ? $keyword : ucfirst($keyword);
        
        // Try to get a word from title words for context
        if (is_array($title_words) && count($title_words) > 1) {
            foreach ($title_words as $title_word) {
                $title_word_clean = trim($title_word, '.,!?;:"()[]');
                $keyword_clean = trim(strtolower($keyword));
                if (strtolower($title_word_clean) !== strtolower($keyword_clean) && strlen($title_word_clean) > 2) {
                    return $base_keyword . ' ' . $title_word_clean;
                }
            }
        }
        
        // Absolute last resort: keyword + generic word
        return $base_keyword . ' guide';
    }
    
    /**
     * Find semantic anchor preserving case
     */
    private function find_semantic_anchor_preserve_case($source_content_original, $source_content_lower, $target_content_original, $target_content_lower, $target_title) {
        $semantic_patterns = [
            'legal' => ['law', 'legal', 'attorney', 'lawyer', 'court', 'case', 'settlement', 'injury', 'accident'],
            'health' => ['health', 'medical', 'doctor', 'treatment', 'therapy', 'care', 'wellness', 'hospital'],
            'business' => ['business', 'company', 'corporate', 'management', 'marketing', 'sales', 'finance'],
            'technology' => ['technology', 'software', 'digital', 'computer', 'tech', 'app', 'system'],
            'finance' => ['finance', 'financial', 'money', 'investment', 'bank', 'credit', 'loan'],
            'education' => ['education', 'school', 'learning', 'student', 'teacher', 'course', 'training']
        ];
        
        foreach ($semantic_patterns as $category => $terms) {
            $source_has = false;
            $target_has = false;
            
            foreach ($terms as $term) {
                if (strpos($source_content_lower, $term) !== false) $source_has = true;
                if (strpos($target_content_lower, $term) !== false) $target_has = true;
            }
            
            if ($source_has && $target_has) {
                // Find the best term that appears in target title (preserve case)
                if (!empty($target_title)) {
                    $title_words = preg_split('/\s+/', $target_title);
                    if ($title_words !== false && !empty($title_words)) {
                        foreach ($title_words as $index => $title_word) {
                            if (empty($title_word)) {
                                continue;
                            }
                            $title_word_clean = trim(strtolower($title_word), '.,!?;:"()[]');
                            foreach ($terms as $term) {
                                if ($title_word_clean === $term) {
                                    // Build phrase from title word and adjacent words
                                    $phrase_parts = array();
                                    
                                    // Get word before (if exists)
                                    if ($index > 0 && isset($title_words[$index - 1])) {
                                        $prev_word = trim($title_words[$index - 1], '.,!?;:"()[]');
                                        if (strlen($prev_word) > 2) {
                                            $phrase_parts[] = $prev_word;
                                        }
                                    }
                                    
                                    // Add the matched term with original case
                                    $phrase_parts[] = trim($title_word, '.,!?;:"()[]');
                                    
                                    // Get word after (if exists)
                                    if ($index < count($title_words) - 1 && isset($title_words[$index + 1])) {
                                        $next_word = trim($title_words[$index + 1], '.,!?;:"()[]');
                                        if (strlen($next_word) > 2) {
                                            $phrase_parts[] = $next_word;
                                        }
                                    }
                                    
                                    // Return 2-3 word phrase
                                    if (count($phrase_parts) >= 2) {
                                        return implode(' ', array_slice($phrase_parts, 0, 3));
                                    }
                                    
                                    // Single word fallback with descriptive prefix
                                    return $this->build_descriptive_phrase(trim($title_word, '.,!?;:"()[]'), $title_words);
                                }
                            }
                        }
                    }
                }
                
                // Build contextual phrase from category and target title
                if (!empty($target_title)) {
                    $title_words = preg_split('/\s+/', $target_title);
                    if (is_array($title_words) && !empty($title_words)) {
                        // Find a term from target title that matches semantic category
                        $found_term = '';
                        foreach ($title_words as $title_word) {
                            $title_word_clean = trim(strtolower($title_word), '.,!?;:"()[]');
                            if (in_array($title_word_clean, $terms)) {
                                $found_term = trim($title_word, '.,!?;:"()[]');
                                break;
                            }
                        }
                        
                        if (!empty($found_term) && strlen($found_term) > 2) {
                            // Build 2-3 word phrase with the term
                            $phrase_parts = array();
                            
                            // Try to get word before term
                            foreach ($title_words as $index => $word) {
                                if (trim(strtolower($word), '.,!?;:"()[]') === strtolower($found_term)) {
                                    if ($index > 0 && isset($title_words[$index - 1])) {
                                        $prev_word = trim($title_words[$index - 1], '.,!?;:"()[]');
                                        if (strlen($prev_word) > 2) {
                                            $phrase_parts[] = $prev_word;
                                        }
                                    }
                                    $phrase_parts[] = $found_term;
                                    if ($index < count($title_words) - 1 && isset($title_words[$index + 1])) {
                                        $next_word = trim($title_words[$index + 1], '.,!?;:"()[]');
                                        if (strlen($next_word) > 2) {
                                            $phrase_parts[] = $next_word;
                                        }
                                    }
                                    break;
                                }
                            }
                            
                            if (count($phrase_parts) >= 2) {
                                return implode(' ', array_slice($phrase_parts, 0, 3));
                            }
                            
                            // Single term fallback - add descriptive prefix
                            return $this->build_descriptive_phrase($found_term, $title_words);
                        }
                        
                        // Use category name + first meaningful word from title
                        foreach ($title_words as $word) {
                            $word_clean = trim($word, '.,!?;:"()[]');
                            if (strlen($word_clean) > 3) {
                                return ucfirst($category) . ' ' . $word_clean;
                            }
                        }
                    }
                }
                
                // Fallback to category name with descriptive word (ensures multi-word)
                $category_phrase = ucfirst($category) . ' guide';
                // Ensure it's multi-word
                if (str_word_count($category_phrase) >= 2) {
                    return $category_phrase;
                }
                // If category itself is multi-word, use it with context
                return ucfirst($category) . ' information';
            }
        }
        
        return false;
    }
    
    /**
     * Create contextual anchor using target title with descriptive words
     */
    private function create_contextual_anchor($source_content_lower, $target_title) {
        if (empty($target_title)) {
            return false;
        }
        
        // Extract main words from target title (limit to 3-4 words)
        $title_words = preg_split('/\s+/', trim($target_title));
        if ($title_words === false || empty($title_words)) {
            return false;
        }
        
        $title_words = array_filter($title_words, function($word) {
            $word = trim($word, '.,!?;:"()[]');
            return strlen($word) > 2;
        });
        
        // Re-index array after filtering
        $title_words = array_values($title_words);
        
        if (empty($title_words)) {
            return false;
        }
        
        // Create a contextual phrase (preserve original capitalization)
        // Ensure multi-word (goal compliance)
        if (count($title_words) >= 2) {
            if (count($title_words) <= 4) {
                $phrase = implode(' ', $title_words);
            } else {
                // Take first 4 words to keep it concise
                $phrase = implode(' ', array_slice($title_words, 0, 4));
            }
            
            // Validate phrase is multi-word before returning
            $phrase_word_count = str_word_count($phrase);
            if ($phrase_word_count !== false && $phrase_word_count >= 2) {
                return $phrase;
            }
        }
        
        // If filtering resulted in single word, build contextual phrase
        if (count($title_words) === 1 && !empty($title_words[0])) {
            $single_word = $title_words[0];
            $contextual_prefixes = array('best', 'guide to', 'learn about', 'about');
            foreach ($contextual_prefixes as $prefix) {
                $phrase = $prefix . ' ' . $single_word;
                $phrase_word_count = str_word_count($phrase);
                if ($phrase_word_count !== false && $phrase_word_count >= 2 && strlen($phrase) <= 100) {
                    return $phrase;
                }
            }
            // Last resort: single word + generic word
            return $single_word . ' guide';
        }
        
        return false;
    }
    
    /**
     * Clean anchor text
     */
    private function clean_anchor_text($text) {
        // Validate input type
        if (!is_string($text) && !is_numeric($text)) {
            return false;
        }
        
        // Convert to string if numeric
        $text = (string)$text;
        
        // Remove extra whitespace and normalize ellipses
        $text = trim($text);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = preg_replace('/^\.*\s*/', '', $text);   // remove leading dots/ellipsis
        $text = preg_replace('/\s*\.*$/', '', $text);   // remove trailing dots/ellipsis
        
        // Return false if empty
        if (empty($text) || strlen($text) < 1) {
            return false;
        }
        
        // Trim leading/trailing punctuation
        $text = trim($text, " \t\n\r\0\x0B\"'.,!?;:()[]{}<>");
        
        // Remove leading/trailing connector words (low-quality like 'and', 'or', 'to', 'of')
        $stop_edges = array('and','or','but','to','of','in','on','for','with','by','at','from','as','the','a','an');
        $words = preg_split('/\s+/', $text);
        if (is_array($words)) {
            while (!empty($words) && in_array(strtolower($words[0]), $stop_edges)) { array_shift($words); }
            while (!empty($words) && in_array(strtolower(end($words)), $stop_edges)) { array_pop($words); }
            $text = trim(implode(' ', $words));
        }
        
        // Limit to 6 words max (prevents overly long anchors)
        $cleaned = wp_trim_words($text, 6, '');
        
        // Ensure we have a valid result after trimming
        $cleaned = trim($cleaned);
        if (empty($cleaned) || strlen($cleaned) < 1) {
            return false;
        }
        
        // Validate word count after cleaning (ensure it's not empty after word limit)
        $word_count = str_word_count($cleaned);
        if ($word_count === false || $word_count === 0) {
            return false;
        }
        
        // Ensure anchor includes at least one non-stopword token (quality check)
        $stop_all = array('and','or','but','to','of','in','on','for','with','by','at','from','as','the','a','an','this','that','these','those');
        $tokens = preg_split('/\s+/', strtolower($cleaned));
        $has_meaningful = false;
        foreach ($tokens as $tok) {
            $tok = trim($tok, "\"'.,!?;:()[]{}");
            if (strlen($tok) > 2 && !in_array($tok, $stop_all)) { $has_meaningful = true; break; }
        }
        if (!$has_meaningful) {
            return false;
        }
        
        // Ensure length doesn't exceed 100 characters (safety limit)
        if (strlen($cleaned) > 100) {
            $cleaned = substr($cleaned, 0, 100);
            $cleaned = rtrim($cleaned, '.,!?;:"()[]'); // Trim punctuation at end
        }
        
        return $cleaned;
    }
    
    /**
     * Find insertion point for link using smart strategies
     */
    private function find_insertion_point($post_id, $anchor_text) {
        $post = get_post($post_id);
        if (!$post) {
            return false;
        }
        
        $content = $post->post_content ?? '';
        
        if (empty($content) || empty($anchor_text)) {
            return false;
        }
        
        // Strategy 1: Exact anchor text match
        $position = stripos($content, $anchor_text);
        if ($position !== false) {
            return $position;
        }
        
        // Strategy 2: Partial word matches
        $words = explode(' ', $anchor_text);
        foreach ($words as $word) {
            if (strlen($word) > 3) {
                $position = stripos($content, $word);
                if ($position !== false) {
                    return $position;
                }
            }
        }
        
        // Strategy 3: Find contextual insertion points
        $contextual_position = $this->find_contextual_insertion_point($content, $anchor_text);
        if ($contextual_position !== false) {
            return $contextual_position;
        }
        
        // Strategy 4: Smart paragraph insertion
        $paragraph_position = $this->find_paragraph_insertion_point($content);
        if ($paragraph_position !== false) {
            return $paragraph_position;
        }
        
        // Fallback: Use middle of content
        $content_len = strlen($content);
        return $content_len > 0 ? intval($content_len / 2) : 0;
    }
    
    /**
     * Find contextual insertion point based on content structure
     */
    private function find_contextual_insertion_point($content, $anchor_text) {
        if (empty($content) || empty($anchor_text)) {
            return false;
        }
        
        // Look for related terms or synonyms
        $related_terms = $this->get_related_terms($anchor_text);
        
        if (!empty($related_terms) && is_array($related_terms)) {
        foreach ($related_terms as $term) {
                if (!empty($term)) {
            $position = stripos($content, $term);
            if ($position !== false) {
                return $position;
                    }
                }
            }
        }
        
        // Look for sentence endings where we can add context
        $sentences = preg_split('/[.!?]+/', $content);
        if (!is_array($sentences) || empty($sentences)) {
            return false;
        }
        
        $best_position = false;
        $best_score = 0;
        
        foreach ($sentences as $index => $sentence) {
            if (empty($sentence)) {
                continue;
            }
            $sentence_lower = strtolower(trim($sentence));
            $score = $this->calculate_sentence_relevance($sentence_lower, $anchor_text);
            
            if ($score > $best_score && $score > 0.3) {
                $best_score = $score;
                // Position after this sentence
                $best_position = $this->get_sentence_position($content, $index);
            }
        }
        
        return $best_position;
    }
    
    /**
     * Find paragraph insertion point
     */
    private function find_paragraph_insertion_point($content) {
        if (empty($content)) {
            return false;
        }
        
        // Split content into paragraphs
        $paragraphs = preg_split('/\n\s*\n/', $content);
        
        if (!is_array($paragraphs) || count($paragraphs) < 2) {
            return false;
        }
        
        // Find the best paragraph (usually 2nd or 3rd)
        $target_paragraph_index = min(2, count($paragraphs) - 1);
        
        // Calculate position of this paragraph
        $position = 0;
        $max_index = min($target_paragraph_index, count($paragraphs) - 1);
        
        for ($i = 0; $i < $max_index; $i++) {
            if (isset($paragraphs[$i])) {
            $position += strlen($paragraphs[$i]) + 2; // +2 for line breaks
            }
        }
        
        // Insert at the beginning of the paragraph
        return $position;
    }
    
    /**
     * Get related terms for anchor text
     */
    private function get_related_terms($anchor_text) {
        if (empty($anchor_text)) {
            return array();
        }
        
        $anchor_lower = strtolower($anchor_text);
        
        // Simple synonym/related word mapping
        $related_words = [
            'law' => ['legal', 'attorney', 'lawyer', 'court'],
            'legal' => ['law', 'attorney', 'lawyer', 'court'],
            'attorney' => ['lawyer', 'legal', 'law'],
            'lawyer' => ['attorney', 'legal', 'law'],
            'health' => ['medical', 'wellness', 'care'],
            'medical' => ['health', 'doctor', 'treatment'],
            'business' => ['company', 'corporate', 'enterprise'],
            'company' => ['business', 'corporate', 'organization'],
            'technology' => ['tech', 'software', 'digital'],
            'software' => ['technology', 'tech', 'application'],
            'finance' => ['financial', 'money', 'investment'],
            'money' => ['finance', 'financial', 'funds']
        ];
        
        $related_terms = [];
        
        // Check for exact matches
        if (isset($related_words[$anchor_lower])) {
            $related_terms = array_merge($related_terms, $related_words[$anchor_lower]);
        }
        
        // Check for partial matches
        foreach ($related_words as $key => $terms) {
            if (strpos($anchor_lower, $key) !== false || strpos($key, $anchor_lower) !== false) {
                $related_terms = array_merge($related_terms, $terms);
            }
        }
        
        return array_unique($related_terms);
    }
    
    /**
     * Calculate sentence relevance score
     */
    private function calculate_sentence_relevance($sentence, $anchor_text) {
        if (empty($sentence) || empty($anchor_text)) {
            return 0;
        }
        
        $sentence_words = explode(' ', $sentence);
        $anchor_words = explode(' ', strtolower($anchor_text));
        
        $matches = 0;
        $total_anchor_words = count($anchor_words);
        
        // Prevent division by zero
        if (empty($total_anchor_words)) {
            return 0;
        }
        
        foreach ($anchor_words as $anchor_word) {
            if (empty($anchor_word)) {
                continue;
            }
            foreach ($sentence_words as $sentence_word) {
                if (empty($sentence_word)) {
                    continue;
                }
                if (strpos($sentence_word, $anchor_word) !== false || strpos($anchor_word, $sentence_word) !== false) {
                    $matches++;
                    break;
                }
            }
        }
        
        return $matches / $total_anchor_words;
    }
    
    /**
     * Get position of sentence in content
     */
    private function get_sentence_position($content, $sentence_index) {
        if (empty($content) || $sentence_index < 0) {
            return 0;
        }
        
        $sentences = preg_split('/[.!?]+/', $content);
        if (!is_array($sentences) || empty($sentences)) {
            return 0;
        }
        
        $position = 0;
        $max_index = min($sentence_index, count($sentences) - 1);
        
        for ($i = 0; $i < $max_index; $i++) {
            if (isset($sentences[$i])) {
            $position += strlen($sentences[$i]) + 1; // +1 for punctuation
            }
        }
        
        return $position;
    }
    
    /**
     * Check if link is excluded
     */
    private function is_excluded($source_post_id, $target_post_id, $anchor_text = null) {
        global $wpdb;
        
        // Load excluded posts into cache (one query per request)
        if ($this->excluded_posts_cache === null) {
            $excluded_posts = $wpdb->get_results($wpdb->prepare(
                "SELECT item_value FROM {$wpdb->prefix}ssp_excluded_items WHERE item_type = %s",
                'post'
            ));
            $this->excluded_posts_cache = array();
            foreach ($excluded_posts as $excluded) {
                $this->excluded_posts_cache[] = intval($excluded->item_value);
            }
        }
        
        // Check if target post is excluded
        if (in_array($target_post_id, $this->excluded_posts_cache)) {
            return true;
        }
        
        // Check if anchor text is excluded (if provided)
        if ($anchor_text !== null) {
            // Load excluded anchors into cache (one query per request)
            if ($this->excluded_anchors_cache === null) {
                $excluded_anchors = $wpdb->get_results($wpdb->prepare(
                    "SELECT item_value FROM {$wpdb->prefix}ssp_excluded_items WHERE item_type = %s",
                    'anchor'
                ));
                $this->excluded_anchors_cache = array();
                foreach ($excluded_anchors as $excluded) {
                    $this->excluded_anchors_cache[] = strtolower(trim($excluded->item_value));
                }
            }
            
            // Check if anchor is excluded (case-insensitive)
            if (in_array(strtolower(trim($anchor_text)), $this->excluded_anchors_cache)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Insert links into content
     */
    public function insert_links($content) {
        global $post;
        
        if (!$post || !$post->ID) {
            return $content;
        }
        
        // Cache links for performance
        $cache_key = 'ssp_post_links_' . $post->ID;
        $links = get_transient($cache_key);
        
        if ($links === false) {
            $links = SSP_Database::get_post_links($post->ID);
            // Cache for 1 hour
            set_transient($cache_key, $links, 3600);
        }
        
        if (empty($links)) {
            return $content;
        }
        
        // Sort links by position (descending to avoid position shifts)
        usort($links, function($a, $b) {
            return $b->link_position - $a->link_position;
        });
        
        foreach ($links as $link) {
            $target_post = get_post($link->target_post_id);
            if (!$target_post) {
                continue;
            }
            
            // Get permalink - ensure it's absolute and properly formatted
            $url = get_permalink($target_post);
            
            if (!$url) {
                error_log("SSP Link Insert: Could not get permalink for post {$target_post->ID}");
                continue;
            }
            
            // Ensure URL is absolute (if it's relative, make it absolute)
            if (strpos($url, 'http') !== 0) {
                $url = home_url($url);
            }
            
            $anchor_text = $link->anchor_text;
            
            // Check if link already exists in content
            if (strpos($content, 'href="' . esc_url($url) . '"') !== false) {
                continue;
            }
            
            // Create link with marker
            $link_html = sprintf(
                '<a href="%s" data-ssp-link-id="%d" class="ssp-internal-link">%s</a>',
                esc_url($url),
                $link->id,
                esc_html($anchor_text)
            );
            
            // Wrap with marker for safe re-runs
            $marked_link = sprintf(
                '<!-- %s%d -->%s<!-- /%s%d -->',
                $this->link_marker_prefix,
                $link->id,
                $link_html,
                $this->link_marker_prefix,
                $link->id
            );
            
            // Insert link at position (validate position is within bounds)
            $content_length = strlen($content);
            $insert_position = intval($link->link_position);
            if ($insert_position > $content_length) {
                $insert_position = $content_length; // Clamp to end of content
                error_log("SSP Link Insert: Position {$link->link_position} exceeds content length {$content_length}, clamping to end");
            } else if ($insert_position < 0) {
                $insert_position = 0; // Clamp to start
                error_log("SSP Link Insert: Position {$link->link_position} is negative, clamping to start");
            }
            $content = substr_replace($content, $marked_link, $insert_position, 0);
        }
        
        return $content;
    }
    
    /**
     * Remove existing links before re-running
     */
    public function remove_existing_links($post_id, $silo_id = null) {
        $post = get_post($post_id);
        if (!$post) {
            error_log("SSP Remove Links Failed: Post {$post_id} not found");
            return false;
        }
        
        $content = $post->post_content;
        $original_content = $content;
        
        // If silo_id is provided, only remove links belonging to that silo
        if ($silo_id !== null) {
            // Get all link IDs for this silo and post
            global $wpdb;
            $links_table = $wpdb->prefix . 'ssp_links';
            $link_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM $links_table WHERE source_post_id = %d AND silo_id = %d",
                $post_id,
                $silo_id
            ));
            
            if (!empty($link_ids)) {
                // Remove only links with specific IDs for this silo, preserving original text inside <a>
                foreach ($link_ids as $link_id) {
                    // Match marker + <a ...>inner</a> + end marker; capture inner text
                    $pattern = '/<!--\s*' . preg_quote($this->link_marker_prefix, '/') . $link_id . '\s*-->\s*<a\s+[^>]*>(.*?)<\/a>\s*<!--\s*\/' . preg_quote($this->link_marker_prefix, '/') . $link_id . '\s*-->/is';
                    $content = preg_replace_callback($pattern, function($m) {
                        return isset($m[1]) ? $m[1] : '';
                    }, $content);
                }
                
                if ($content !== $original_content) {
                    error_log("SSP Remove Links: Removed " . count($link_ids) . " links from silo {$silo_id} in post {$post_id}");
                } else {
                    error_log("SSP Remove Links: No link markers found for silo {$silo_id} in post {$post_id}");
                }
            } else {
                error_log("SSP Remove Links: No links found in database for silo {$silo_id} in post {$post_id}");
            }
        } else {
            // Remove all SSP links (no silo filter) preserving inner text
        $pattern_all = '/<!--\s*' . preg_quote($this->link_marker_prefix, '/') . '(\d+)\s*-->\s*<a\s+[^>]*>(.*?)<\/a>\s*<!--\s*\/' . preg_quote($this->link_marker_prefix, '/') . '\1\s*-->/is';
        $content = preg_replace_callback($pattern_all, function($m) {
            return isset($m[2]) ? $m[2] : '';
        }, $content);
        
        if ($content !== $original_content) {
                error_log("SSP Remove Links: Found and removed all SSP links from post {$post_id}");
        } else {
            error_log("SSP Remove Links: No SSP link markers found in post {$post_id} content");
            }
        }
        
        // If no content change, still mark links as removed in database and return
        if ($content === $original_content) {
            SSP_Database::remove_post_links($post_id, $silo_id);
            return true;
        }
        
        // Update post content
        $this->updating_post = true;
        
        $update_result = wp_update_post(array(
            'ID' => $post_id,
            'post_content' => $content
        ), true); // true = return WP_Error on failure
        
        $this->updating_post = false;
        
        if (is_wp_error($update_result)) {
            error_log("SSP Remove Links Failed: " . $update_result->get_error_message());
            return false;
        }
        
        if ($update_result === 0) {
            error_log("SSP Remove Links Failed: Post update returned 0 for post {$post_id}");
            return false;
        }
        
        error_log("SSP Remove Links Success: Updated post {$post_id} content");
        
        // Mark links as removed in database
        SSP_Database::remove_post_links($post_id, $silo_id);
        
        // Clear cache so frontend shows updated content
        delete_transient('ssp_post_links_' . $post_id);
        
        // Clear post cache
        wp_cache_delete($post_id, 'posts');
        wp_cache_delete($post_id, 'post_meta');
        
        return true;
    }
    
    /**
     * Preview links without inserting
     */
    public function preview_links($silo_id, $post_ids = array()) {
        $silo = SSP_Database::get_silo($silo_id);
        if (!$silo) {
            return false;
        }
        
        $silo_posts = SSP_Database::get_silo_posts($silo_id);
        if (empty($silo_posts)) {
            return false;
        }
        
        // Filter posts if specific post IDs provided
        if (!empty($post_ids)) {
            $silo_posts = array_filter($silo_posts, function($post) use ($post_ids) {
                return in_array($post->post_id, $post_ids);
            });
        }
        
        $previews = array();
        
        foreach ($silo_posts as $silo_post) {
            $post = get_post($silo_post->post_id);
            if (!$post) {
                continue;
            }
            
            // Generate preview links based on linking mode
            $post_previews = $this->generate_post_previews($silo_id, $post, $silo, $silo_posts);
            $previews[$post->ID] = $post_previews;
        }
        
        return $previews;
    }
    
    /**
     * Generate preview for individual post
     */
    private function generate_post_previews($silo_id, $post, $silo, $silo_posts) {
        $previews = array();
        $settings = json_decode($silo->settings, true);
        if (!is_array($settings)) {
            $settings = array();
        }
        
        // Validate post exists
        if (!$post) {
            return $previews;
        }
        
        switch ($silo->linking_mode) {
            case 'linear':
                $previews = $this->preview_linear_links($silo_id, $post, $silo, $silo_posts, $settings);
                break;
                
            case 'chained':
                $previews = $this->preview_chained_links($silo_id, $post, $silo_posts, $settings);
                break;
                
            case 'cross_linking':
                $previews = $this->preview_cross_linking($silo_id, $post, $silo, $silo_posts, $settings);
                break;
                
            case 'star_hub':
                $previews = $this->preview_star_hub_links($silo_id, $post, $silo, $silo_posts, $settings);
                break;
                
            case 'hub_chain':
                $previews = $this->preview_hub_chain_links($silo_id, $post, $silo, $silo_posts, $settings);
                break;
                
            case 'ai_contextual':
                $previews = $this->preview_ai_contextual_links($silo_id, $post, $silo, $silo_posts, $settings);
                break;
                
            case 'custom':
                $previews = $this->preview_custom_links($silo_id, $post, $silo, $silo_posts, $settings);
                break;
        }
        
        return $previews;
    }
    
    /**
     * Preview linear links
     */
    private function preview_linear_links($silo_id, $post, $silo, $silo_posts, $settings) {
        $previews = array();
        
        // Check if pillar post exists
        if (empty($silo->pillar_post_id)) {
            return $previews; // No pillar, return empty
        }
        
        $pillar_post = get_post($silo->pillar_post_id);
        if (!$pillar_post) {
            return $previews; // Pillar post not found
        }
        
        // Sort support posts by position
        usort($silo_posts, function($a, $b) {
            return $a->position - $b->position;
        });
        
        // Build array of support posts
        $support_posts = array();
        foreach ($silo_posts as $sp) {
            $p = get_post($sp->post_id);
            if ($p) {
                $support_posts[] = $p;
            }
        }
        
        // If current post is the pillar: show link to first support post
        if ($post->ID == $pillar_post->ID) {
            if (!empty($support_posts)) {
                $first_support = $support_posts[0];
                $anchor_text = $this->get_anchor_text($post->ID, $first_support->ID, $settings);
                $anchor_variations = $this->get_anchor_variations($post->ID, $first_support->ID, $settings);
                
                if ($anchor_text) {
                    $previews[] = array(
                        'target_post_id' => $first_support->ID,
                        'target_title' => $first_support->post_title ?? '',
                        'anchor_text' => $anchor_text,
                        'anchor_variations' => $anchor_variations,
                        'insertion_point' => $this->find_insertion_point($post->ID, $anchor_text)
                    );
                }
            }
        } else {
            // If current post is a support post:
            // 1. Find its position and show link to next support post
            $current_index = -1;
            foreach ($support_posts as $index => $p) {
            if ($p->ID == $post->ID) {
                $current_index = $index;
                break;
            }
        }
        
            if ($current_index !== -1) {
                // Link to next support post (linear chain)
                if ($current_index < count($support_posts) - 1) {
                    $next_support = $support_posts[$current_index + 1];
                    $anchor_text = $this->get_anchor_text($post->ID, $next_support->ID, $settings);
                    $anchor_variations = $this->get_anchor_variations($post->ID, $next_support->ID, $settings);
                    
                    if ($anchor_text) {
                        $previews[] = array(
                            'target_post_id' => $next_support->ID,
                            'target_title' => $next_support->post_title ?? '',
                            'anchor_text' => $anchor_text,
                            'anchor_variations' => $anchor_variations,
                            'insertion_point' => $this->find_insertion_point($post->ID, $anchor_text)
                        );
                    }
                }
                
                // 2. Show link to pillar (if supports_to_pillar is enabled)
                $supports_to_pillar_enabled = false;
                if (isset($settings['supports_to_pillar'])) {
                    $value = $settings['supports_to_pillar'];
                    $supports_to_pillar_enabled = ($value === true || $value === 1 || $value === '1' || $value === 'true');
                } else {
                    $supports_to_pillar_enabled = true; // Default to true
                }
                
                if ($supports_to_pillar_enabled) {
                    $anchor_text = $this->get_anchor_text($post->ID, $pillar_post->ID, $settings);
                    $anchor_variations = $this->get_anchor_variations($post->ID, $pillar_post->ID, $settings);
            
            if ($anchor_text) {
                $previews[] = array(
                            'target_post_id' => $pillar_post->ID,
                            'target_title' => $pillar_post->post_title ?? '',
                    'anchor_text' => $anchor_text,
                    'anchor_variations' => $anchor_variations,
                    'insertion_point' => $this->find_insertion_point($post->ID, $anchor_text)
                );
                    }
                }
            }
        }
        
        return $previews;
    }
    
    /**
     * Preview chained links
     */
    private function preview_chained_links($silo_id, $post, $silo_posts, $settings) {
        $previews = array();
        
        // Sort posts by position
        usort($silo_posts, function($a, $b) {
            return $a->position - $b->position;
        });
        
        // Find current post position
        $current_index = -1;
        foreach ($silo_posts as $index => $p) {
            if ($p->post_id == $post->ID) {
                $current_index = $index;
                break;
            }
        }
        
        if ($current_index === -1) {
            return $previews;
        }
        
        // Link to next post
        if ($current_index < count($silo_posts) - 1) {
            $next_sp = isset($silo_posts[$current_index + 1]) ? $silo_posts[$current_index + 1] : null;
            if ($next_sp) {
                $next_post = get_post($next_sp->post_id);
                if ($next_post) {
            $anchor_text = $this->get_anchor_text($post->ID, $next_post->ID, $settings);
            $anchor_variations = $this->get_anchor_variations($post->ID, $next_post->ID, $settings);
            
            if ($anchor_text) {
                $previews[] = array(
                    'target_post_id' => $next_post->ID,
                            'target_title' => $next_post->post_title ?? '',
                    'anchor_text' => $anchor_text,
                    'anchor_variations' => $anchor_variations,
                    'insertion_point' => $this->find_insertion_point($post->ID, $anchor_text)
                );
                    }
                }
            }
        }
        
        // Link to previous post
        if ($current_index > 0) {
            $prev_sp = isset($silo_posts[$current_index - 1]) ? $silo_posts[$current_index - 1] : null;
            if ($prev_sp) {
                $prev_post = get_post($prev_sp->post_id);
                if ($prev_post) {
            $anchor_text = $this->get_anchor_text($post->ID, $prev_post->ID, $settings);
            $anchor_variations = $this->get_anchor_variations($post->ID, $prev_post->ID, $settings);
            
            if ($anchor_text) {
                $previews[] = array(
                    'target_post_id' => $prev_post->ID,
                            'target_title' => $prev_post->post_title ?? '',
                    'anchor_text' => $anchor_text,
                    'anchor_variations' => $anchor_variations,
                    'insertion_point' => $this->find_insertion_point($post->ID, $anchor_text)
                );
                    }
                }
            }
        }
        
        return $previews;
    }
    
    /**
     * Preview cross-linking
     */
    private function preview_cross_linking($silo_id, $post, $silo, $silo_posts, $settings) {
        $previews = array();
        
        // Check if pillar post exists
        if (empty($silo->pillar_post_id)) {
            return $previews; // No pillar, return empty
        }
        
        $pillar_post = get_post($silo->pillar_post_id);
        if (!$pillar_post) {
            return $previews; // Pillar post not found
        }
        
        $all_posts = array();
        $all_posts[] = $pillar_post;
        foreach ($silo_posts as $sp) {
            $p = get_post($sp->post_id);
            if ($p) {
                $all_posts[] = $p;
            }
        }
        
        foreach ($all_posts as $target_post) {
            if (!$target_post || $target_post->ID == $post->ID) {
                continue;
            }
            
            $anchor_text = $this->get_anchor_text($post->ID, $target_post->ID, $settings);
            $anchor_variations = $this->get_anchor_variations($post->ID, $target_post->ID, $settings);
            
            if ($anchor_text) {
                $previews[] = array(
                    'target_post_id' => $target_post->ID,
                    'target_title' => $target_post->post_title ?? '',
                    'anchor_text' => $anchor_text,
                    'anchor_variations' => $anchor_variations,
                    'insertion_point' => $this->find_insertion_point($post->ID, $anchor_text)
                );
            }
        }
        
        return $previews;
    }
    
    /**
     * Preview star/hub links
     */
    private function preview_star_hub_links($silo_id, $post, $silo, $silo_posts, $settings) {
        $previews = array();
        
        // Check if pillar post exists
        if (empty($silo->pillar_post_id)) {
            return $previews; // No pillar, return empty
        }
        
        $pillar_post = get_post($silo->pillar_post_id);
        if (!$pillar_post) {
            return $previews; // Pillar post not found
        }
        
        // Check if supports should link to pillar
        $supports_to_pillar = isset($settings['supports_to_pillar']) ? $settings['supports_to_pillar'] : true;
        
        // If current post is a support post and setting is enabled, it links to pillar
        if ($post->ID != $silo->pillar_post_id && $supports_to_pillar) {
            $anchor_text = $this->get_anchor_text($post->ID, $pillar_post->ID, $settings);
            $anchor_variations = $this->get_anchor_variations($post->ID, $pillar_post->ID, $settings);
            
            if ($anchor_text) {
                $previews[] = array(
                    'target_post_id' => $pillar_post->ID,
                    'target_title' => $pillar_post->post_title ?? '',
                    'anchor_text' => $anchor_text,
                    'anchor_variations' => $anchor_variations,
                    'insertion_point' => $this->find_insertion_point($post->ID, $anchor_text)
                );
            }
        }
        
        // If current post is pillar and pillar_to_supports is enabled
        if ($post->ID == $silo->pillar_post_id && isset($settings['pillar_to_supports']) && $settings['pillar_to_supports']) {
            $max_pillar_links = intval($settings['max_pillar_links'] ?? 5);
            $links_added = 0;
            
            foreach ($silo_posts as $silo_post) {
                if ($links_added >= $max_pillar_links) {
                    break;
                }
                
                $support_post = get_post($silo_post->post_id);
                if ($support_post) {
                    $anchor_text = $this->get_anchor_text($post->ID, $support_post->ID, $settings);
                    $anchor_variations = $this->get_anchor_variations($post->ID, $support_post->ID, $settings);
                    
                    if ($anchor_text) {
                        $previews[] = array(
                            'target_post_id' => $support_post->ID,
                            'target_title' => $support_post->post_title ?? '',
                            'anchor_text' => $anchor_text,
                            'anchor_variations' => $anchor_variations,
                            'insertion_point' => $this->find_insertion_point($post->ID, $anchor_text)
                        );
                        $links_added++;
                    }
                }
            }
        }
        
        return $previews;
    }
    
    /**
     * Preview hub-chain links
     */
    private function preview_hub_chain_links($silo_id, $post, $silo, $silo_posts, $settings) {
        $previews = array();
        
        // Check if pillar post exists
        if (empty($silo->pillar_post_id)) {
            return $previews; // No pillar, return empty
        }
        
        $pillar_post = get_post($silo->pillar_post_id);
        if (!$pillar_post) {
            return $previews; // Pillar post not found
        }
        
        // Sort posts by position
        usort($silo_posts, function($a, $b) {
            return $a->position - $b->position;
        });
        
        // Check if supports should link to pillar
        $supports_to_pillar = isset($settings['supports_to_pillar']) ? $settings['supports_to_pillar'] : true;
        
        // If current post is a support post
        if ($post->ID != $silo->pillar_post_id) {
            // Link to pillar (hub structure) - if enabled
            if ($supports_to_pillar) {
                $anchor_text = $this->get_anchor_text($post->ID, $pillar_post->ID, $settings);
                $anchor_variations = $this->get_anchor_variations($post->ID, $pillar_post->ID, $settings);
                
                if ($anchor_text) {
                    $previews[] = array(
                        'target_post_id' => $pillar_post->ID,
                        'target_title' => $pillar_post->post_title ?? '',
                        'anchor_text' => $anchor_text,
                        'anchor_variations' => $anchor_variations,
                        'insertion_point' => $this->find_insertion_point($post->ID, $anchor_text)
                    );
                }
            }
            
            // Find current post position
            $current_index = -1;
            foreach ($silo_posts as $index => $sp) {
                if ($sp->post_id == $post->ID) {
                    $current_index = $index;
                    break;
                }
            }
            
            if ($current_index !== -1) {
                // Link to next post (chain structure)
                if ($current_index < count($silo_posts) - 1) {
                    $next_sp = isset($silo_posts[$current_index + 1]) ? $silo_posts[$current_index + 1] : null;
                    if ($next_sp) {
                        $next_post = get_post($next_sp->post_id);
                    if ($next_post) {
                        $anchor_text = $this->get_anchor_text($post->ID, $next_post->ID, $settings);
                        $anchor_variations = $this->get_anchor_variations($post->ID, $next_post->ID, $settings);
                        
                        if ($anchor_text) {
                            $previews[] = array(
                                'target_post_id' => $next_post->ID,
                                    'target_title' => $next_post->post_title ?? '',
                                'anchor_text' => $anchor_text,
                                'anchor_variations' => $anchor_variations,
                                'insertion_point' => $this->find_insertion_point($post->ID, $anchor_text)
                            );
                            }
                        }
                    }
                }
                
                // Link to previous post (chain structure)
                if ($current_index > 0) {
                    $prev_sp = isset($silo_posts[$current_index - 1]) ? $silo_posts[$current_index - 1] : null;
                    if ($prev_sp) {
                        $prev_post = get_post($prev_sp->post_id);
                    if ($prev_post) {
                        $anchor_text = $this->get_anchor_text($post->ID, $prev_post->ID, $settings);
                        $anchor_variations = $this->get_anchor_variations($post->ID, $prev_post->ID, $settings);
                        
                        if ($anchor_text) {
                            $previews[] = array(
                                'target_post_id' => $prev_post->ID,
                                    'target_title' => $prev_post->post_title ?? '',
                                'anchor_text' => $anchor_text,
                                'anchor_variations' => $anchor_variations,
                                'insertion_point' => $this->find_insertion_point($post->ID, $anchor_text)
                            );
                            }
                        }
                    }
                }
            }
        }
        
        return $previews;
    }
    
    /**
     * Preview AI-contextual links
     */
    private function preview_ai_contextual_links($silo_id, $post, $silo, $silo_posts, $settings) {
        $previews = array();
        
        // Check if pillar post exists
        if (empty($silo->pillar_post_id)) {
            return $previews; // No pillar, return empty
        }
        
        $pillar_post = get_post($silo->pillar_post_id);
        if (!$pillar_post) {
            return $previews; // Pillar post not found
        }
        
        // Get all posts for similarity calculation
        $all_posts = array();
        $all_posts[] = $pillar_post;
        foreach ($silo_posts as $silo_post) {
            $p = get_post($silo_post->post_id);
            if ($p) {
                $all_posts[] = $p;
            }
        }
        
        // Find most related posts for current post
        $max_links = $settings['max_contextual_links'] ?? 3;
        $related_posts = $this->find_most_related_posts($post, $all_posts, $max_links);
        
        foreach ($related_posts as $target_post) {
            if (!$target_post || $target_post->ID == $post->ID) {
                continue;
            }
            
            $anchor_text = $this->get_anchor_text($post->ID, $target_post->ID, $settings);
            $anchor_variations = $this->get_anchor_variations($post->ID, $target_post->ID, $settings);
            
            if ($anchor_text) {
                $previews[] = array(
                    'target_post_id' => $target_post->ID,
                    'target_title' => $target_post->post_title ?? '',
                    'anchor_text' => $anchor_text,
                    'anchor_variations' => $anchor_variations,
                    'insertion_point' => $this->find_insertion_point($post->ID, $anchor_text)
                );
            }
        }
        
        return $previews;
    }
    
    /**
     * Preview custom links
     */
    private function preview_custom_links($silo_id, $post, $silo, $silo_posts, $settings) {
        $previews = array();
        
        if (!isset($settings['custom_pattern']) || !is_array($settings['custom_pattern'])) {
            return $previews;
        }
        
        $pattern = $settings['custom_pattern'];
        
        // Check if pillar post exists
        $pillar_post = null;
        if (!empty($silo->pillar_post_id)) {
        $pillar_post = get_post($silo->pillar_post_id);
        }
        
        foreach ($pattern as $link_rule) {
            if (!isset($link_rule['source']) || !isset($link_rule['target'])) {
                continue; // Skip invalid rules
            }
            
            if ($link_rule['source'] != $post->ID && $link_rule['source'] != 'pillar') {
                continue;
            }
            
            $target_post_id = $link_rule['target'];
            if ($target_post_id === 'pillar') {
                if (!$pillar_post) {
                    continue; // Skip if no pillar post
                }
                $target_post_id = $pillar_post->ID;
            }
            
            $target_post = get_post($target_post_id);
            if (!$target_post) {
                continue;
            }
            
            $anchor_text = $this->get_anchor_text($post->ID, $target_post->ID, $settings);
            $anchor_variations = $this->get_anchor_variations($post->ID, $target_post->ID, $settings);
            
            if ($anchor_text) {
                $previews[] = array(
                    'target_post_id' => $target_post->ID,
                    'target_title' => $target_post->post_title ?? '',
                    'anchor_text' => $anchor_text,
                    'anchor_variations' => $anchor_variations,
                    'insertion_point' => $this->find_insertion_point($post->ID, $anchor_text)
                );
            }
        }
        
        return $previews;
    }
}


