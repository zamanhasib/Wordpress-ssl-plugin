<?php
/**
 * AI Integration class for OpenAI/OpenRouter
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class SSP_AI_Integration {
    
    private static $instance = null;
    private $api_key = '';
    private $api_base_url = '';
    private $model = 'gpt-3.5-turbo';
    private $cache_duration = 3600; // 1 hour
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->load_settings();
    }
    
    /**
     * Load AI settings
     */
    private function load_settings() {
        $settings = get_option('ssp_settings', array());
        
        $this->api_key = $settings['openai_api_key'] ?? '';
        $this->api_base_url = $settings['ai_base_url'] ?? 'https://api.openai.com/v1';
        $this->model = $settings['ai_model'] ?? 'gpt-3.5-turbo';
        
        // Validate API key format
        if (!empty($this->api_key) && !$this->validate_api_key()) {
            $this->api_key = '';
            error_log('SSP AI: Invalid API key format detected');
        }
    }
    
    /**
     * Validate API key format
     */
    private function validate_api_key() {
        if (empty($this->api_key)) {
            return false;
        }
        
        // Basic validation for OpenAI API key format
        if (strpos($this->api_key, 'sk-') === 0 && strlen($this->api_key) > 20) {
            return true;
        }
        
        // For OpenRouter, check if it looks like a valid key
        if (strlen($this->api_key) > 20 && preg_match('/^[a-zA-Z0-9_-]+$/', $this->api_key)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Get anchor text suggestions for a post pair
     */
    public function get_anchor_suggestions($source_post_id, $target_post_id, $context = '') {
        try {
            $cache_key = 'ssp_anchor_' . md5($source_post_id . '_' . $target_post_id . '_' . $context);
            
            // Check cache first
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                return $cached;
            }
            
            $source_post = get_post($source_post_id);
            $target_post = get_post($target_post_id);
            
            if (!$source_post || !$target_post) {
                return $this->get_fallback_anchor_suggestions($target_post_id);
            }
            
            $prompt = $this->build_anchor_prompt($source_post, $target_post, $context);
            $response = $this->make_api_request($prompt);
            
            if ($response) {
                $suggestions = $this->parse_anchor_response($response);
                if (!empty($suggestions)) {
                    set_transient($cache_key, $suggestions, $this->cache_duration);
                    return $suggestions;
                }
            }
            
            // Return fallback suggestions if AI fails
            return $this->get_fallback_anchor_suggestions($target_post_id);
            
        } catch (Exception $e) {
            error_log('SSP AI Error: ' . $e->getMessage());
            return $this->get_fallback_anchor_suggestions($target_post_id);
        }
    }
    
    /**
     * Get text rewrite suggestions
     */
    public function get_rewrite_suggestions($original_text, $target_post_id, $anchor_text = '') {
        $cache_key = 'ssp_rewrite_' . md5($original_text . '_' . $target_post_id . '_' . $anchor_text);
        
        // Check cache first
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }
        
        $target_post = get_post($target_post_id);
        if (!$target_post) {
            return false;
        }
        
        $prompt = $this->build_rewrite_prompt($original_text, $target_post, $anchor_text);
        $response = $this->make_api_request($prompt);
        
        if ($response) {
            $suggestions = $this->parse_rewrite_response($response);
            set_transient($cache_key, $suggestions, $this->cache_duration);
            return $suggestions;
        }
        
        return false;
    }
    
    /**
     * Get relevant posts for a pillar page
     */
    public function get_relevant_posts($pillar_post_id, $limit = 20) {
        $cache_key = 'ssp_relevant_' . $pillar_post_id . '_' . $limit;
        
        // Check cache first
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }
        
        $pillar_post = get_post($pillar_post_id);
        if (!$pillar_post) {
            return false;
        }
        
        // Get all published posts except the pillar
        $posts = get_posts(array(
            'post_type' => array('post', 'page'),
            'post_status' => 'publish',
            'exclude' => array($pillar_post_id),
            'numberposts' => 100, // Get more for better AI analysis
            'orderby' => 'date',
            'order' => 'DESC'
        ));
        
        if (empty($posts)) {
            return array();
        }
        
        $prompt = $this->build_relevance_prompt($pillar_post, $posts);
        $response = $this->make_api_request($prompt);
        
        if ($response) {
            $relevant_posts = $this->parse_relevance_response($response, $posts);
            set_transient($cache_key, $relevant_posts, $this->cache_duration);
            return $relevant_posts;
        }
        
        return array();
    }
    
    /**
     * Build anchor text prompt
     */
    private function build_anchor_prompt($source_post, $target_post, $context = '') {
        $source_content = wp_strip_all_tags($source_post->post_content);
        $source_content = wp_trim_words($source_content, 100);
        
        $target_content = wp_strip_all_tags($target_post->post_content);
        $target_content = wp_trim_words($target_content, 100);
        
        $prompt = "You are an SEO expert creating internal links for a WordPress website.

SOURCE POST:
Title: {$source_post->post_title}
Content: {$source_content}

TARGET POST:
Title: {$target_post->post_title}
Content: {$target_content}

Context: {$context}

Create 3 different anchor text variations that would naturally link from the source post to the target post. The anchor text should:
1. Be 2-5 words long
2. Be relevant to the target post content
3. Sound natural in context
4. Include relevant keywords when appropriate
5. Be different from each other

Return only a JSON array of 3 anchor text options:
[\"anchor text 1\", \"anchor text 2\", \"anchor text 3\"]";

        return $prompt;
    }
    
    /**
     * Build rewrite prompt
     */
    private function build_rewrite_prompt($original_text, $target_post, $anchor_text = '') {
        $target_content = wp_strip_all_tags($target_post->post_content);
        $target_content = wp_trim_words($target_content, 100);
        
        $prompt = "You are an SEO expert rewriting content to include natural internal links.

ORIGINAL TEXT:
{$original_text}

TARGET POST TO LINK TO:
Title: {$target_post->post_title}
Content: {$target_content}

ANCHOR TEXT TO USE: {$anchor_text}

Rewrite the original text to naturally include a link to the target post using the specified anchor text. The rewrite should:
1. Sound natural and maintain the original meaning
2. Include the anchor text in context
3. Be similar length to the original
4. Flow well with surrounding content

Return only the rewritten text:";

        return $prompt;
    }
    
    /**
     * Build relevance prompt
     */
    private function build_relevance_prompt($pillar_post, $posts) {
        $pillar_content = wp_strip_all_tags($pillar_post->post_content);
        $pillar_content = wp_trim_words($pillar_content, 200);
        
        $posts_data = array();
        foreach (array_slice($posts, 0, 50) as $post) { // Limit for token constraints
            $posts_data[] = array(
                'id' => $post->ID,
                'title' => $post->post_title,
                'content' => wp_trim_words(wp_strip_all_tags($post->post_content), 50)
            );
        }
        
        $prompt = "You are an SEO expert analyzing content relevance for internal linking.

PILLAR POST (main topic):
Title: {$pillar_post->post_title}
Content: {$pillar_content}

AVAILABLE POSTS TO LINK:
" . json_encode($posts_data, JSON_PRETTY_PRINT) . "

Analyze which posts are most semantically relevant to the pillar post. Consider:
1. Topic similarity
2. Keyword overlap
3. Content complementarity
4. User journey relevance

Return a JSON array with post IDs ranked by relevance (most relevant first):
[{\"post_id\": 123, \"relevance_score\": 0.95, \"reason\": \"explanation\"}, ...]

Include only the top 20 most relevant posts.";

        return $prompt;
    }
    
    /**
     * Make API request to OpenAI/OpenRouter
     */
    private function make_api_request($prompt) {
        // Rate limiting check
        if (!$this->check_rate_limit()) {
            error_log('SSP AI: Rate limit exceeded');
            return false;
        }
        
        if (empty($this->api_key) || !$this->validate_api_key()) {
            error_log('SSP AI: Invalid or missing API key');
            return false;
        }
        
        $headers = array(
            'Authorization' => 'Bearer ' . sanitize_text_field($this->api_key),
            'Content-Type' => 'application/json'
        );
        
        $body = array(
            'model' => $this->model,
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $prompt
                )
            ),
            'max_tokens' => 1000,
            'temperature' => 0.7
        );
        
        $args = array(
            'headers' => $headers,
            'body' => json_encode($body),
            'timeout' => 30
        );
        
        $response = wp_remote_post(esc_url_raw($this->api_base_url . '/chat/completions'), $args);
        
        if (is_wp_error($response)) {
            error_log('SSP AI API Error: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            error_log('SSP AI API HTTP Error: ' . $response_code);
            return false;
        }
        
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('SSP AI: Invalid JSON response from API');
            return false;
        }
        
        if (isset($data['choices'][0]['message']['content'])) {
            // Log API usage
            $this->log_api_usage();
            return $data['choices'][0]['message']['content'];
        }
        
        return false;
    }
    
    /**
     * Parse anchor response
     */
    private function parse_anchor_response($response) {
        // Try to parse as JSON first
        $decoded = json_decode($response, true);
        if (is_array($decoded)) {
            return array_map('trim', $decoded);
        }
        
        // Fallback: extract anchor texts from response
        preg_match_all('/"([^"]+)"/', $response, $matches);
        if (!empty($matches[1])) {
            return array_map('trim', $matches[1]);
        }
        
        // Last fallback: split by lines
        $lines = array_filter(array_map('trim', explode("\n", $response)));
        return array_slice($lines, 0, 3);
    }
    
    /**
     * Parse rewrite response
     */
    private function parse_rewrite_response($response) {
        return trim($response);
    }
    
    /**
     * Parse relevance response
     */
    private function parse_relevance_response($response, $posts) {
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return array();
        }
        
        $relevant_posts = array();
        $post_lookup = array();
        
        foreach ($posts as $post) {
            $post_lookup[$post->ID] = $post;
        }
        
        foreach ($decoded as $item) {
            if (isset($item['post_id']) && isset($post_lookup[$item['post_id']])) {
                $relevant_posts[] = $post_lookup[$item['post_id']];
            }
        }
        
        return $relevant_posts;
    }
    
    /**
     * Test API connection
     */
    public function test_connection() {
        $prompt = "Hello, this is a test message. Please respond with 'API connection successful'.";
        $response = $this->make_api_request($prompt);
        
        return !empty($response);
    }
    
    /**
     * Get fallback anchor suggestions when AI fails
     */
    private function get_fallback_anchor_suggestions($target_post_id) {
        $target_post = get_post($target_post_id);
        if (!$target_post) {
            return array('Read more', 'Learn more');
        }
        
        $title = $target_post->post_title;
        $words = explode(' ', $title);
        
        $suggestions = array(
            $title,
            'Learn more about ' . $title,
            'Read about ' . $title,
            'Discover ' . $title
        );
        
        // Add word-based suggestions
        if (count($words) > 1) {
            $suggestions[] = $words[0];
            $suggestions[] = $words[0] . ' ' . $words[1];
        }
        
        return array_slice($suggestions, 0, 5);
    }
    
    /**
     * Check rate limit for API calls
     */
    private function check_rate_limit() {
        $current_hour = date('Y-m-d-H');
        $usage_key = 'ssp_ai_usage_' . $current_hour;
        $usage = get_transient($usage_key);
        
        if ($usage === false) {
            $usage = 0;
        }
        
        $max_requests_per_hour = 100; // Configurable limit
        
        if ($usage >= $max_requests_per_hour) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Log API usage for rate limiting
     */
    private function log_api_usage() {
        $current_hour = date('Y-m-d-H');
        $usage_key = 'ssp_ai_usage_' . $current_hour;
        $usage = get_transient($usage_key);
        
        if ($usage === false) {
            $usage = 0;
        }
        
        $usage++;
        set_transient($usage_key, $usage, 3600); // 1 hour
    }
    
    /**
     * Clear cache
     */
    public function clear_cache() {
        global $wpdb;
        
        if ($wpdb->options) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_ssp_%'));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_timeout_ssp_%'));
        }
    }
}
