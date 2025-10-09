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
        }
        
        // Get pillar post
        $pillar_post = get_post($silo->pillar_post_id);
        if (!$pillar_post) {
            error_log("SSP Link Generation Failed: Pillar post {$silo->pillar_post_id} not found");
            return false;
        }
        
        $links_created = 0;
        
        switch ($silo->linking_mode) {
            case 'linear':
                $links_created = $this->create_linear_links($silo_id, $pillar_post, $silo_posts, $silo->settings);
                break;
                
            case 'chained':
                $links_created = $this->create_chained_links($silo_id, $silo_posts, $silo->settings);
                break;
                
            case 'cross_linking':
                $links_created = $this->create_cross_linking($silo_id, $pillar_post, $silo_posts, $silo->settings);
                break;
                
            case 'star_hub':
                $links_created = $this->create_star_hub_links($silo_id, $pillar_post, $silo_posts, $silo->settings);
                break;
                
            case 'ai_contextual':
                $links_created = $this->create_ai_contextual_links($silo_id, $pillar_post, $silo_posts, $silo->settings);
                break;
                
            case 'hub_chain':
                $links_created = $this->create_hub_chain_links($silo_id, $pillar_post, $silo_posts, $silo->settings);
                break;
                
            case 'custom':
                $links_created = $this->create_custom_links($silo_id, $pillar_post, $silo_posts, $silo->settings);
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
        $settings = json_decode($settings, true);
        
        // Ensure settings is an array
        if (!is_array($settings)) {
            $settings = array();
        }
        
        // Sort posts by position
        usort($silo_posts, function($a, $b) {
            return $a->position - $b->position;
        });
        
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
        
        foreach ($all_posts as $index => $current_post) {
            $next_index = ($index + 1) % count($all_posts);
            $next_post = $all_posts[$next_index];
            
            // Skip if same post
            if ($current_post->ID == $next_post->ID) {
                continue;
            }
            
            // Create link
            error_log("SSP Link Generation: Attempting to create link from post {$current_post->ID} to {$next_post->ID}");
            if ($this->create_link($silo_id, $current_post->ID, $next_post->ID, $settings)) {
                $links_created++;
                error_log("SSP Link Generation: Successfully created link from post {$current_post->ID} to {$next_post->ID}");
            } else {
                error_log("SSP Link Generation: Failed to create link from post {$current_post->ID} to {$next_post->ID}");
            }
        }
        
        return $links_created;
    }
    
    /**
     * Create chained links
     */
    private function create_chained_links($silo_id, $silo_posts, $settings) {
        $links_created = 0;
        $settings = json_decode($settings, true);
        
        // Ensure settings is an array
        if (!is_array($settings)) {
            $settings = array();
        }
        
        // Sort posts by position
        usort($silo_posts, function($a, $b) {
            return $a->position - $b->position;
        });
        
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
        }
        
        return $links_created;
    }
    
    /**
     * Create cross-linking (mesh) links
     */
    private function create_cross_linking($silo_id, $pillar_post, $silo_posts, $settings) {
        $links_created = 0;
        $settings = json_decode($settings, true);
        
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
        
        foreach ($all_posts as $source_post) {
            foreach ($all_posts as $target_post) {
                // Skip self-linking
                if ($source_post->ID == $target_post->ID) {
                    continue;
                }
                
                // Create link
                if ($this->create_link($silo_id, $source_post->ID, $target_post->ID, $settings)) {
                    $links_created++;
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
        $settings = json_decode($settings, true);
        
        // Ensure settings is an array
        if (!is_array($settings)) {
            $settings = array();
        }

        if (!isset($settings['custom_pattern'])) {
            return 0;
        }
        
        $pattern = $settings['custom_pattern'];
        
        foreach ($pattern as $link_rule) {
            $source_post_id = $link_rule['source'];
            $target_post_id = $link_rule['target'];
            
            // Handle pillar post references
            if ($source_post_id === 'pillar') {
                $source_post_id = $pillar_post->ID;
            }
            if ($target_post_id === 'pillar') {
                $target_post_id = $pillar_post->ID;
            }
            
            // Create link
            if ($this->create_link($silo_id, $source_post_id, $target_post_id, $settings)) {
                $links_created++;
            }
        }
        
        return $links_created;
    }
    
    /**
     * Create star/hub (pillar-centric) links
     */
    private function create_star_hub_links($silo_id, $pillar_post, $silo_posts, $settings) {
        $links_created = 0;
        $settings = json_decode($settings, true);
        
        // Ensure settings is an array
        if (!is_array($settings)) {
            $settings = array();
        }
        
        error_log("SSP Star/Hub Mode: Creating pillar-centric links for silo {$silo_id}");
        
        // All support posts link TO the pillar
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
        
        // Optional: Pillar links to support posts (if enabled)
        $pillar_to_supports = $settings['pillar_to_supports'] ?? false;
        if ($pillar_to_supports) {
            error_log("SSP Star/Hub Mode: Pillar→supports enabled, creating pillar links");
            
            // Limit pillar links to avoid overwhelming the pillar post
            $max_pillar_links = $settings['max_pillar_links'] ?? 5;
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
        $settings = json_decode($settings, true);
        
        // Ensure settings is an array
        if (!is_array($settings)) {
            $settings = array();
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
        
        error_log("SSP AI-Contextual Mode: Created {$links_created} total contextual links");
        return $links_created;
    }
    
    /**
     * Create hub-and-chain links (supports to pillar + adjacent supports)
     */
    private function create_hub_chain_links($silo_id, $pillar_post, $silo_posts, $settings) {
        $links_created = 0;
        $settings = json_decode($settings, true);
        
        // Ensure settings is an array
        if (!is_array($settings)) {
            $settings = array();
        }
        
        error_log("SSP Hub-Chain Mode: Creating hub-and-chain links for silo {$silo_id}");
        
        // Sort posts by position for consistent chaining
        usort($silo_posts, function($a, $b) {
            return $a->position - $b->position;
        });
        
        // Step 1: All support posts link TO the pillar (hub structure)
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
        $pillar_to_supports = $settings['pillar_to_supports'] ?? false;
        if ($pillar_to_supports) {
            error_log("SSP Hub-Chain Mode: Pillar→supports enabled, creating pillar links");
            
            $max_pillar_links = $settings['max_pillar_links'] ?? 5;
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
        // Check if link already exists
        $existing_links = SSP_Database::get_post_links($source_post_id, $silo_id);
        foreach ($existing_links as $link) {
            if ($link->target_post_id == $target_post_id) {
                error_log("SSP Link Creation Skipped: Link already exists from {$source_post_id} to {$target_post_id}");
                return false; // Link already exists
            }
        }
        
        // Check exclusions
        if ($this->is_excluded($source_post_id, $target_post_id)) {
            error_log("SSP Link Creation Skipped: Post {$target_post_id} is excluded");
            return false;
        }
        
        // Get anchor text
        $anchor_text = $this->get_anchor_text($source_post_id, $target_post_id, $settings);
        if (!$anchor_text) {
            error_log("SSP Link Creation Failed: No anchor text generated for posts {$source_post_id} -> {$target_post_id}");
            return false;
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
            'placement_type' => $settings['placement_type'] ?? 'inline',
            'ai_generated' => 0
        );
        
        $link_id = SSP_Database::save_link($link_data);
        
        if ($link_id === false) {
            error_log("SSP Link Creation Failed: Database save failed for posts {$source_post_id} -> {$target_post_id}");
            return false;
        }
        
        // Actually insert the link into the post content
        $insert_result = $this->insert_link_into_content($source_post_id, $target_post_id, $anchor_text, $insertion_point, $link_id);
        
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
    private function insert_link_into_content($source_post_id, $target_post_id, $anchor_text, $insertion_point, $link_id) {
        $post = get_post($source_post_id);
        if (!$post) {
            error_log("SSP Link Insertion Failed: Source post {$source_post_id} not found");
            return false;
        }
        
        $content = $post->post_content;
        $target_url = get_permalink($target_post_id);
        
        if (!$target_url) {
            error_log("SSP Link Insertion Failed: Could not get permalink for post {$target_post_id}");
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
        
        // NEW APPROACH: Find existing text in content and replace it with link
        $new_content = $this->find_and_link_text($content, $anchor_text, $link_html, $source_post_id, $target_post_id);
        
        if ($new_content === false) {
            error_log("SSP Link Insertion Failed: Could not find suitable text to link in post {$source_post_id}");
            return false;
        }
        
        // Update the post content
        $update_result = wp_update_post(array(
            'ID' => $source_post_id,
            'post_content' => $new_content
        ), true); // true = return WP_Error on failure
        
        if (is_wp_error($update_result)) {
            error_log("SSP Link Insertion Failed: " . $update_result->get_error_message());
            return false;
        }
        
        if ($update_result === 0) {
            error_log("SSP Link Insertion Failed: Post update returned 0 for post {$source_post_id}");
            return false;
        }
        
        error_log("SSP Link Insertion Success: Updated post {$source_post_id} content");
        
        // Clear cache
        delete_transient('ssp_post_links_' . $source_post_id);
        
        return true;
    }
    
    /**
     * Find existing text in content and replace it with a link
     */
    private function find_and_link_text($content, $anchor_text, $link_html, $source_post_id, $target_post_id) {
        // Remove any existing SSP link markers to search in clean content
        $clean_content = preg_replace('/<!--\s*' . preg_quote($this->link_marker_prefix) . '\d+\s*-->.*?<!--\s*\/' . preg_quote($this->link_marker_prefix) . '\d+\s*-->/s', '', $content);
        
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
                $new_link_html = str_replace(esc_html($anchor_text), esc_html($found_text), $link_html);
                return $this->replace_text_with_link($content, $found_text, $new_link_html, $position, strlen($found_text));
            }
        }
        
        // Strategy 4: Find keywords from target post in source content
        if ($target_post) {
            $target_words = $this->extract_meaningful_words($target_post->post_title);
            foreach ($target_words as $word) {
                if (strlen($word) < 4) continue; // Skip short words
                
                $position = $this->find_text_position($clean_content, $word, true);
                if ($position !== false) {
                    error_log("SSP Find & Link: Found keyword '{$word}' from target title");
                    $new_link_html = str_replace(esc_html($anchor_text), esc_html($word), $link_html);
                    return $this->replace_text_with_link($content, $word, $new_link_html, $position, strlen($word));
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
        $plain_content = wp_strip_all_tags($content);
        
        if ($word_boundary) {
            // Use regex to find whole word matches
            $pattern = '/\b' . preg_quote($search_text, '/') . '\b/i';
            if (preg_match($pattern, $plain_content, $matches, PREG_OFFSET_CAPTURE)) {
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
     * Replace text with link HTML at specific position
     */
    private function replace_text_with_link($content, $original_text, $link_html, $position, $length) {
        // Find the exact occurrence in the original HTML content
        // We need to preserve HTML structure while replacing text
        
        // Use case-insensitive replacement, but preserve original case
        $pattern = '/' . preg_quote($original_text, '/') . '/i';
        
        // Replace only the first occurrence
        $new_content = preg_replace($pattern, $link_html, $content, 1);
        
        if ($new_content === null || $new_content === $content) {
            error_log("SSP Find & Link: Regex replacement failed for '{$original_text}'");
            return false;
        }
        
        return $new_content;
    }
    
    /**
     * Find partial match of words in content
     */
    private function find_partial_match($content, $words) {
        $plain_content = wp_strip_all_tags($content);
        
        // Try combinations of consecutive words
        for ($length = count($words); $length >= ceil(count($words) * 0.6); $length--) {
            for ($start = 0; $start <= count($words) - $length; $start++) {
                $phrase = implode(' ', array_slice($words, $start, $length));
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
        $words = preg_split('/\s+/', strtolower($text));
        $stop_words = array('the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'is', 'are', 'was', 'were', 'be', 'been', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could', 'should', 'may', 'might', 'can');
        
        $meaningful = array();
        foreach ($words as $word) {
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
        // Try AI suggestions first if available
        if (isset($settings['use_ai_anchors']) && $settings['use_ai_anchors']) {
            $suggestions = $this->ai_integration->get_anchor_suggestions($source_post_id, $target_post_id);
            if (!empty($suggestions)) {
                return $suggestions[0];
            }
        }
        
        // Use smart anchor text generation
        return $this->generate_smart_anchor_text($source_post_id, $target_post_id, $settings);
    }
    
    /**
     * Get all anchor text variations (for preview)
     */
    public function get_anchor_variations($source_post_id, $target_post_id, $settings) {
        $variations = array();
        
        // Try AI suggestions first if available
        if (isset($settings['use_ai_anchors']) && $settings['use_ai_anchors']) {
            $ai_suggestions = $this->ai_integration->get_anchor_suggestions($source_post_id, $target_post_id);
            if (!empty($ai_suggestions) && is_array($ai_suggestions)) {
                $variations = $ai_suggestions;
            }
        }
        
        // If no AI suggestions or not using AI, generate smart variations
        if (empty($variations)) {
            $smart_anchor = $this->generate_smart_anchor_text($source_post_id, $target_post_id, $settings);
            if ($smart_anchor) {
                $variations = array($smart_anchor);
            }
        }
        
        return $variations;
    }
    
    /**
     * Generate smart anchor text using multiple strategies
     */
    private function generate_smart_anchor_text($source_post_id, $target_post_id, $settings) {
        $source_post = get_post($source_post_id);
        $target_post = get_post($target_post_id);
        
        if (!$source_post || !$target_post) {
            return false;
        }
        
        $source_content = wp_strip_all_tags(strtolower($source_post->post_content ?? ''));
        $target_title = $target_post->post_title ?? '';
        $target_content = wp_strip_all_tags(strtolower($target_post->post_content ?? ''));
        
        // Strategy 1: Exact title match in source content
        $title_lower = strtolower($target_title);
        if ($title_lower && strpos($source_content, $title_lower) !== false) {
            return $this->clean_anchor_text($target_title);
        }
        
        // Strategy 2: Keyword overlap - find common meaningful words
        $common_keywords = $this->find_common_keywords($source_content, $target_content);
        if (!empty($common_keywords)) {
            $anchor_text = $this->build_anchor_from_keywords($common_keywords, $target_title);
            if ($anchor_text) {
                return $anchor_text;
            }
        }
        
        // Strategy 3: Semantic relationships
        $semantic_anchor = $this->find_semantic_anchor($source_content, $target_content, $target_title);
        if ($semantic_anchor) {
            return $semantic_anchor;
        }
        
        // Strategy 4: Category-based matching
        $category_anchor = $this->find_category_anchor($source_post, $target_post);
        if ($category_anchor) {
            return $category_anchor;
        }
        
        // Strategy 5: Fallback to target title (shortened)
        return wp_trim_words($target_title, 4);
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
     * Find category-based anchor
     */
    private function find_category_anchor($source_post, $target_post) {
        $source_categories = wp_get_post_categories($source_post->ID);
        $target_categories = wp_get_post_categories($target_post->ID);
        
        $common_categories = array_intersect($source_categories, $target_categories);
        
        if (!empty($common_categories)) {
            $category = get_category(array_values($common_categories)[0]);
            return $category->name;
        }
        
        return false;
    }
    
    /**
     * Clean anchor text
     */
    private function clean_anchor_text($text) {
        // Remove extra whitespace and limit length
        $text = trim($text);
        return wp_trim_words($text, 6); // Limit to 6 words max
    }
    
    /**
     * Find insertion point for link using smart strategies
     */
    private function find_insertion_point($post_id, $anchor_text) {
        $post = get_post($post_id);
        if (!$post) {
            return false;
        }
        
        $content = $post->post_content;
        
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
        return intval(strlen($content) / 2);
    }
    
    /**
     * Find contextual insertion point based on content structure
     */
    private function find_contextual_insertion_point($content, $anchor_text) {
        // Look for related terms or synonyms
        $related_terms = $this->get_related_terms($anchor_text);
        
        foreach ($related_terms as $term) {
            $position = stripos($content, $term);
            if ($position !== false) {
                return $position;
            }
        }
        
        // Look for sentence endings where we can add context
        $sentences = preg_split('/[.!?]+/', $content);
        $best_position = false;
        $best_score = 0;
        
        foreach ($sentences as $index => $sentence) {
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
        // Split content into paragraphs
        $paragraphs = preg_split('/\n\s*\n/', $content);
        
        if (count($paragraphs) < 2) {
            return false;
        }
        
        // Find the best paragraph (usually 2nd or 3rd)
        $target_paragraph_index = min(2, count($paragraphs) - 1);
        
        // Calculate position of this paragraph
        $position = 0;
        for ($i = 0; $i < $target_paragraph_index; $i++) {
            $position += strlen($paragraphs[$i]) + 2; // +2 for line breaks
        }
        
        // Insert at the beginning of the paragraph
        return $position;
    }
    
    /**
     * Get related terms for anchor text
     */
    private function get_related_terms($anchor_text) {
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
        $sentence_words = explode(' ', $sentence);
        $anchor_words = explode(' ', strtolower($anchor_text));
        
        $matches = 0;
        $total_anchor_words = count($anchor_words);
        
        foreach ($anchor_words as $anchor_word) {
            foreach ($sentence_words as $sentence_word) {
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
        $sentences = preg_split('/[.!?]+/', $content);
        $position = 0;
        
        for ($i = 0; $i < $sentence_index; $i++) {
            $position += strlen($sentences[$i]) + 1; // +1 for punctuation
        }
        
        return $position;
    }
    
    /**
     * Check if link is excluded
     */
    private function is_excluded($source_post_id, $target_post_id) {
        global $wpdb;
        
        // Check excluded posts
        $excluded_posts = $wpdb->get_results($wpdb->prepare("SELECT item_value FROM {$wpdb->prefix}ssp_excluded_items WHERE item_type = %s", 'post'));
        foreach ($excluded_posts as $excluded) {
            if ($excluded->item_value == $target_post_id) {
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
            
            $url = get_permalink($target_post);
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
            
            // Insert link at position
            $content = substr_replace($content, $marked_link, $link->link_position, 0);
        }
        
        return $content;
    }
    
    /**
     * Remove existing links before re-running
     */
    public function remove_existing_links($post_id, $silo_id = null) {
        $post = get_post($post_id);
        if (!$post) {
            return false;
        }
        
        $content = $post->post_content;
        
        // Remove existing SSP links with markers
        $pattern = '/<!--\s*' . preg_quote($this->link_marker_prefix) . '\d+\s*-->.*?<!--\s*\/' . preg_quote($this->link_marker_prefix) . '\d+\s*-->/s';
        $content = preg_replace($pattern, '', $content);
        
        // Update post content
        $update_result = wp_update_post(array(
            'ID' => $post_id,
            'post_content' => $content
        ), true); // true = return WP_Error on failure
        
        if (is_wp_error($update_result)) {
            error_log("SSP Remove Links Failed: " . $update_result->get_error_message());
            return false;
        }
        
        if ($update_result === 0) {
            error_log("SSP Remove Links Failed: Post update returned 0 for post {$post_id}");
            return false;
        }
        
        // Mark links as removed in database
        SSP_Database::remove_post_links($post_id, $silo_id);
        
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
        $pillar_post = get_post($silo->pillar_post_id);
        
        // Find current post position
        $all_posts = array_merge(array($pillar_post), $silo_posts);
        $current_index = -1;
        
        foreach ($all_posts as $index => $p) {
            if ($p->ID == $post->ID) {
                $current_index = $index;
                break;
            }
        }
        
        if ($current_index === -1) {
            return $previews;
        }
        
        // Get next post in sequence
        $next_index = ($current_index + 1) % count($all_posts);
        $next_post = $all_posts[$next_index];
        
        if ($next_post->ID != $post->ID) {
            $anchor_text = $this->get_anchor_text($post->ID, $next_post->ID, $settings);
            $anchor_variations = $this->get_anchor_variations($post->ID, $next_post->ID, $settings);
            
            if ($anchor_text) {
                $previews[] = array(
                    'target_post_id' => $next_post->ID,
                    'target_title' => $next_post->post_title,
                    'anchor_text' => $anchor_text,
                    'anchor_variations' => $anchor_variations,
                    'insertion_point' => $this->find_insertion_point($post->ID, $anchor_text)
                );
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
            $next_post = get_post($silo_posts[$current_index + 1]->post_id);
            $anchor_text = $this->get_anchor_text($post->ID, $next_post->ID, $settings);
            $anchor_variations = $this->get_anchor_variations($post->ID, $next_post->ID, $settings);
            
            if ($anchor_text) {
                $previews[] = array(
                    'target_post_id' => $next_post->ID,
                    'target_title' => $next_post->post_title,
                    'anchor_text' => $anchor_text,
                    'anchor_variations' => $anchor_variations,
                    'insertion_point' => $this->find_insertion_point($post->ID, $anchor_text)
                );
            }
        }
        
        // Link to previous post
        if ($current_index > 0) {
            $prev_post = get_post($silo_posts[$current_index - 1]->post_id);
            $anchor_text = $this->get_anchor_text($post->ID, $prev_post->ID, $settings);
            $anchor_variations = $this->get_anchor_variations($post->ID, $prev_post->ID, $settings);
            
            if ($anchor_text) {
                $previews[] = array(
                    'target_post_id' => $prev_post->ID,
                    'target_title' => $prev_post->post_title,
                    'anchor_text' => $anchor_text,
                    'anchor_variations' => $anchor_variations,
                    'insertion_point' => $this->find_insertion_point($post->ID, $anchor_text)
                );
            }
        }
        
        return $previews;
    }
    
    /**
     * Preview cross-linking
     */
    private function preview_cross_linking($silo_id, $post, $silo, $silo_posts, $settings) {
        $previews = array();
        $pillar_post = get_post($silo->pillar_post_id);
        
        $all_posts = array_merge(array($pillar_post), $silo_posts);
        
        foreach ($all_posts as $target_post) {
            if ($target_post->ID == $post->ID) {
                continue;
            }
            
            $anchor_text = $this->get_anchor_text($post->ID, $target_post->ID, $settings);
            $anchor_variations = $this->get_anchor_variations($post->ID, $target_post->ID, $settings);
            
            if ($anchor_text) {
                $previews[] = array(
                    'target_post_id' => $target_post->ID,
                    'target_title' => $target_post->post_title,
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
        
        if (!isset($settings['custom_pattern'])) {
            return $previews;
        }
        
        $pattern = $settings['custom_pattern'];
        $pillar_post = get_post($silo->pillar_post_id);
        
        foreach ($pattern as $link_rule) {
            if ($link_rule['source'] != $post->ID && $link_rule['source'] != 'pillar') {
                continue;
            }
            
            $target_post_id = $link_rule['target'];
            if ($target_post_id === 'pillar') {
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
                    'target_title' => $target_post->post_title,
                    'anchor_text' => $anchor_text,
                    'anchor_variations' => $anchor_variations,
                    'insertion_point' => $this->find_insertion_point($post->ID, $anchor_text)
                );
            }
        }
        
        return $previews;
    }
}
