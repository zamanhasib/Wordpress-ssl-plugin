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
    private $last_error = ''; // Store last error message for detailed feedback
    
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
        
        // Debug: Log what we got from database
        $raw_key = isset($settings['openai_api_key']) ? $settings['openai_api_key'] : '';
        error_log('SSP AI: Loading settings from DB. Raw key type: ' . gettype($raw_key));
        error_log('SSP AI: Loading settings from DB. Raw key length: ' . strlen($raw_key));
        if (!empty($raw_key)) {
            error_log('SSP AI: Loading settings from DB. Raw key first 5 chars: ' . substr($raw_key, 0, 5));
        }
        
        $this->api_key = isset($settings['openai_api_key']) ? trim($settings['openai_api_key']) : '';
        $this->api_base_url = $settings['ai_base_url'] ?? 'https://api.openai.com/v1';
        $this->model = $settings['ai_model'] ?? 'gpt-3.5-turbo';
        
        // Debug: Log key status after trimming
        $key_length = strlen($this->api_key);
        error_log('SSP AI: Loading settings. Trimmed API key length: ' . $key_length . ' characters');
        
        // Validate API key format (but don't clear it - let validation happen on-demand)
        if (!empty($this->api_key)) {
            $is_valid = $this->validate_api_key();
            if (!$is_valid) {
                error_log('SSP AI: Invalid API key format detected. Key length: ' . $key_length . ', First 5 chars: ' . substr($this->api_key, 0, 5));
                // Keep the key even if validation fails - user might be using a custom format
                // Validation will be checked again when actually using the API
            } else {
                error_log('SSP AI: API key format validated successfully');
            }
        } else {
            error_log('SSP AI: No API key found in settings');
        }
    }
    
    /**
     * Reload settings (useful when settings change)
     */
    public function reload_settings() {
        $this->load_settings();
    }
    
    /**
     * Check if API key is configured and valid
     */
    public function is_api_configured() {
        // First check if key exists and is not empty
        if (empty($this->api_key)) {
            return false;
        }
        
        // Trim and validate format
        return $this->validate_api_key();
    }
    
    /**
     * Validate API key format
     */
    private function validate_api_key() {
        // Check if key exists
        if (empty($this->api_key)) {
            return false;
        }
        
        // Trim whitespace
        $key = trim($this->api_key);
        if (empty($key)) {
            return false;
        }
        
        $key_length = strlen($key);
        
        // Relaxed validation for OpenRouter keys: accept any non-whitespace key with length >= 20
        // This prevents false negatives for valid OpenRouter keys that include characters outside [A-Za-z0-9_-]
        if (!empty($this->api_base_url) && strpos($this->api_base_url, 'openrouter.ai') !== false) {
            if ($key_length >= 20 && preg_match('/^\S+$/', $key)) {
                return true;
            }
        }
        
        // Basic validation for OpenAI API key format
        // OpenAI keys start with "sk-" (regular) or "sk-proj-" (project keys)
        // Both are valid and can vary in length
        if ((strpos($key, 'sk-') === 0 || strpos($key, 'sk-proj-') === 0) && $key_length >= 20) {
            return true;
        }
        
        // For generic providers (fallback), accept alphanumeric with hyphens/underscores and length >= 20
        if ($key_length >= 20 && preg_match('/^[a-zA-Z0-9_-]+$/', $key)) {
            return true;
        }
        
        // Also accept keys that might have been modified but are still reasonable length
        // This is a fallback for edge cases
        if ($key_length >= 30 && !empty($key)) {
            // Log this case for debugging
            error_log('SSP AI: API key accepted by fallback validation. Length: ' . $key_length);
            return true;
        }
        
        return false;
    }
    
    /**
     * Get anchor text suggestions for a post pair
     */
    public function get_anchor_suggestions($source_post_id, $target_post_id, $context = '') {
        try {
            // Reload settings in case they were just updated (singleton pattern means instance persists)
            $this->reload_settings();
            
            // Early validation: check if API is configured before proceeding
            if (!$this->is_api_configured()) {
                error_log('SSP AI Anchor Suggestions: API not configured, returning fallback suggestions');
                return $this->get_fallback_anchor_suggestions($target_post_id);
            }
            
            // Normalize context for cache key (handle null, empty, or whitespace-only)
            $normalized_context = is_string($context) ? trim($context) : '';
            $cache_key = 'ssp_anchor_' . md5($source_post_id . '_' . $target_post_id . '_' . $normalized_context);
            
            // Check cache first
            $cached = get_transient($cache_key);
            // Validate cached value is a non-empty array of strings
            if ($cached !== false && is_array($cached) && !empty($cached) && count($cached) > 0) {
                // Validate each cached suggestion is a valid string (1-100 chars)
                $valid_cached = true;
                foreach ($cached as $cached_suggestion) {
                    if (!is_string($cached_suggestion) || trim($cached_suggestion) === '' || strlen($cached_suggestion) > 100) {
                        $valid_cached = false;
                        break;
                    }
                }
                if ($valid_cached) {
                    error_log('SSP AI Anchor Suggestions: Using cached suggestions for posts ' . $source_post_id . ' -> ' . $target_post_id);
                return $cached;
                } else {
                    // Cache validation failed - regenerate
                    error_log('SSP AI Anchor Suggestions: Cached suggestions failed validation, generating new suggestions');
                    delete_transient($cache_key); // Clear invalid cache
                }
            }
            
            $source_post = get_post($source_post_id);
            $target_post = get_post($target_post_id);
            
            if (!$source_post || !$target_post) {
                error_log('SSP AI Anchor Suggestions: Source or target post not found for posts ' . $source_post_id . ' -> ' . $target_post_id);
                return $this->get_fallback_anchor_suggestions($target_post_id);
            }
            
            // Validate posts have content
            if (empty($source_post->post_content) || empty($target_post->post_content)) {
                error_log('SSP AI Anchor Suggestions: Source or target post has no content for posts ' . $source_post_id . ' -> ' . $target_post_id);
                return $this->get_fallback_anchor_suggestions($target_post_id);
            }
            
            $prompt = $this->build_anchor_prompt($source_post, $target_post, $context);
            $response = $this->make_api_request($prompt);
            
            if ($response !== false && !empty($response)) {
                $suggestions = $this->parse_anchor_response($response);
                if (!empty($suggestions) && is_array($suggestions) && count($suggestions) > 0) {
                    // Clean and validate suggestions before caching
                    $validated_suggestions = array();
                    foreach ($suggestions as $suggestion) {
                        if (!is_string($suggestion)) {
                            continue;
                        }
                        $suggestion = $this->sanitize_anchor_candidate($suggestion);
                        if (empty($suggestion) || strlen($suggestion) < 1 || strlen($suggestion) > 100) {
                            continue;
                        }
                        $validated_suggestions[] = $suggestion;
                    }
                    
                    if (!empty($validated_suggestions)) {
                        // Limit to 3 suggestions and cache them
                        $final_suggestions = array_slice($validated_suggestions, 0, 3);
                        
                        // Note: We cache AI suggestions even if fewer than 3, as long as they're valid
                        // The caller can pad with fallbacks if needed, but we cache what AI provided
                        set_transient($cache_key, $final_suggestions, $this->cache_duration);
                        error_log('SSP AI Anchor Suggestions: Successfully generated ' . count($final_suggestions) . ' AI suggestions for posts ' . $source_post_id . ' -> ' . $target_post_id);
                        return $final_suggestions;
                    } else {
                        error_log('SSP AI Anchor Suggestions: All AI suggestions were invalid, using fallback');
                    }
                } else {
                    error_log('SSP AI Anchor Suggestions: AI response parsing failed or returned empty suggestions');
                }
            } else {
                error_log('SSP AI Anchor Suggestions: API request failed or returned false, using fallback');
            }
            
            // Return fallback suggestions if AI fails
            // Don't cache fallbacks to avoid caching bad suggestions
            return $this->get_fallback_anchor_suggestions($target_post_id);
            
        } catch (Exception $e) {
            error_log('SSP AI Anchor Suggestions Error: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
            return $this->get_fallback_anchor_suggestions($target_post_id);
        }
    }

    /**
     * Sanitize a raw anchor candidate by trimming punctuation and stopwords at edges
     */
    private function sanitize_anchor_candidate($text) {
        if (!is_string($text) && !is_numeric($text)) {
            return '';
        }
        $text = (string)$text;
        $text = trim($text);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = preg_replace('/^\.*\s*/', '', $text);
        $text = preg_replace('/\s*\.*$/', '', $text);
        $text = trim($text, " \t\n\r\0\x0B\"'.,!?;:()[]{}<>");
        $stop_edges = array('and','or','but','to','of','in','on','for','with','by','at','from','as','the','a','an');
        $words = preg_split('/\s+/', $text);
        if (is_array($words)) {
            while (!empty($words) && in_array(strtolower($words[0]), $stop_edges)) { array_shift($words); }
            while (!empty($words) && in_array(strtolower(end($words)), $stop_edges)) { array_pop($words); }
            $text = trim(implode(' ', $words));
        }
        return $text;
    }
    
    /**
     * Get text rewrite suggestions
     */
    public function get_rewrite_suggestions($original_text, $target_post_id, $anchor_text = '') {
        // Reload settings in case they were just updated
        $this->reload_settings();
        
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
     * Returns: array of posts on success, false on error, empty array if no posts available
     */
    public function get_relevant_posts($pillar_post_id, $limit = 20) {
        // Reload settings in case they were just updated
        $this->reload_settings();
        
        // Check API key first before doing expensive operations
        if (!$this->is_api_configured()) {
            error_log('SSP AI: Cannot get relevant posts - API key not configured');
            return false; // Return false to indicate API error
        }
        
        $cache_key = 'ssp_relevant_' . $pillar_post_id . '_' . $limit;
        
        // Check cache first
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }
        
        $pillar_post = get_post($pillar_post_id);
        if (!$pillar_post) {
            error_log('SSP AI: Pillar post not found: ' . $pillar_post_id);
            return false;
        }
        
        // Get all published posts except the pillar
        $posts = get_posts(array(
            'post_type' => array('post', 'page'),
            'post_status' => 'publish',
            'exclude' => array($pillar_post_id),
            'numberposts' => 40, // Get more for better AI analysis
            'orderby' => 'date',
            'order' => 'DESC'
        ));
        
        if (empty($posts)) {
            error_log('SSP AI: No posts available for relevance analysis');
            return array(); // Empty array means no posts available (not an error)
        }
        
        $prompt = $this->build_relevance_prompt($pillar_post, $posts);
        $response = $this->make_api_request($prompt, 4000); // Increase token limit for large responses
        
        if ($response === false && strpos($this->get_last_error(), 'max_output_tokens') !== false) {
            // Retry with even higher limit
            $response = $this->make_api_request($prompt, 8000);
        }
        
        if ($response) {
            $relevant_posts = $this->parse_relevance_response($response, $posts);
            if (!empty($relevant_posts)) {
            set_transient($cache_key, $relevant_posts, $this->cache_duration);
            return $relevant_posts;
            } else {
                error_log('SSP AI: Failed to parse relevance response or no relevant posts found. Response: ' . substr($response, 0, 500));
                // Return empty array if parsing succeeded but no posts matched
                return array();
            }
        }
        
        error_log('SSP AI: Unexpected state - response is truthy but not processed');
        return false;
    }
    
    /**
     * Build anchor text prompt
     */
    private function build_anchor_prompt($source_post, $target_post, $context = '') {
        // Get more content for better context (up to 200 words instead of 100)
        $source_content = wp_strip_all_tags($source_post->post_content ?? '');
        $source_content = wp_trim_words($source_content, 200);
        
        $target_content = wp_strip_all_tags($target_post->post_content ?? '');
        $target_content = wp_trim_words($target_content, 200);
        
        // Get post excerpts if available
        $source_excerpt = !empty($source_post->post_excerpt) ? wp_strip_all_tags($source_post->post_excerpt) : '';
        $target_excerpt = !empty($target_post->post_excerpt) ? wp_strip_all_tags($target_post->post_excerpt) : '';
        
        // Sanitize post titles (handle empty titles)
        $source_title = !empty($source_post->post_title) ? trim($source_post->post_title) : '(Untitled Post #' . $source_post->ID . ')';
        $target_title = !empty($target_post->post_title) ? trim($target_post->post_title) : '(Untitled Post #' . $target_post->ID . ')';
        
        // Ensure content is not empty
        if (empty($source_content)) {
            $source_content = 'No content available';
        }
        if (empty($target_content)) {
            $target_content = 'No content available';
        }
        
        // Build a more detailed prompt with better context
        $prompt = "You are an SEO expert creating internal links for a WordPress website.

SOURCE POST (where the link will be placed):
Title: {$source_title}
" . (!empty($source_excerpt) ? "Excerpt: " . wp_strip_all_tags($source_excerpt) . "\n" : "") . "
Content: {$source_content}

TARGET POST (the page being linked to):
Title: {$target_title}
" . (!empty($target_excerpt) ? "Excerpt: " . wp_strip_all_tags($target_excerpt) . "\n" : "") . "
Content: {$target_content}
" . (!empty($context) && is_string($context) ? "\nAdditional Context: " . trim($context) . "\n" : "") . "
TASK:
Create exactly 3 different anchor text variations that would naturally link from the source post to the target post.

REQUIREMENTS FOR EACH ANCHOR TEXT:
1. SHOULD be 1-4 words long (allow single keyword or short phrase if most natural)
2. MUST be relevant to the target post's main topic and keywords
3. MUST sound natural and contextual within the source post; prefer terms already present in the source content
4. MUST use proper capitalization (preserve proper nouns like city names, brand names)
5. MUST be semantically different from each other
6. MUST include relevant keywords from the target post when appropriate
7. MUST avoid generic phrases like 'click here', 'read more', 'this page' unless contextually appropriate

EXAMPLES OF GOOD ANCHOR TEXT:
- \"Citrus\" (1 word keyword when it matches context)
- \"Citrus city\" (2 words, preserves capitalization)
- \"best citrus fruits\" (3 words, semantic)
- \"guide to citrus\" (3 words, contextual)
- \"Citrus County information\" (3 words, descriptive)

EXAMPLES OF BAD ANCHOR TEXT:
- \"click here\" (generic, not contextual)
- \"read more\" (too generic)
- \"this page\" (not descriptive)

Return ONLY a valid JSON array with exactly 3 anchor text strings:
[\"first anchor text\", \"second anchor text\", \"third anchor text\"]";

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
	 * Make API request to OpenAI/OpenRouter (GPT-3.5 → GPT-5 compatible)
	 */
	private function make_api_request($prompt, $max_tokens = 1000) {

		// Reset last error
		$this->last_error = '';

		// Reload settings
		$this->reload_settings();

		// Basic validation
		if (empty($this->api_key)) {
			$this->last_error = "API key missing.";
			return false;
		}

		$api_key = trim($this->api_key);
		$base = rtrim($this->api_base_url, "/");

		/** 
		 * 🔍 Detect GPT-5
		 */
		$model_lower = strtolower($this->model);
		$is_gpt5 = (
			strpos($model_lower, 'gpt-5') === 0 ||
			strpos($model_lower, 'gpt5') === 0
		);

		/**
		 * 🚀 Route endpoint depending on model family
		 */
		if ($is_gpt5) {
			$url = $base . "/responses";
		} else {
			$url = $base . "/chat/completions";
		}

		/**
		 * 🧠 Build request body depending on model family
		 */
		if ($is_gpt5) {
			// GPT-5+ schema
			$body = array(
				"model" => $this->model,
				"input" => $prompt,
				"max_output_tokens" => $max_tokens,
				"temperature" => 1
			);
		} else {
			// GPT-3.5 / GPT-4 / GPT-4o schema
			$body = array(
				"model" => $this->model,
				"messages" => array(
					array(
						"role" => "user",
						"content" => $prompt
					)
				),
				"max_tokens" => $max_tokens,
				"temperature" => 1
			);
		}

		/**
		 * Headers
		 */
		$headers = array(
			"Authorization" => "Bearer {$api_key}",
			"Content-Type"  => "application/json"
		);

		/**
		 * Execute request
		 */
        @set_time_limit(120);

		$response = wp_remote_post($url, array(
			"headers" => $headers,
			"body" => json_encode($body),
			"timeout" => 90,
            "redirection" => 5,
		));

		if (is_wp_error($response)) {
			$this->last_error = "Network error: " . $response->get_error_message();
			return false;
		}

		$status = wp_remote_retrieve_response_code($response);
		$raw = wp_remote_retrieve_body($response);

		if ($status !== 200) {
			$this->last_error = "API Error {$status}: " . $raw;
			return false;
		}

		/**
		 * Decode JSON
		 */
		$data = json_decode($raw, true);
		if (!$data) {
			$this->last_error = "Invalid JSON received.";
			return false;
		}

		/**
		 * GPT-5 extraction — supports ALL formats including nano/mini/pro
		 */
		if ($is_gpt5) {

			// --- Format 1: Standard GPT-5 ---
			if (isset($data['output_text'][0])) {
				return trim($data['output_text'][0]);
			}

			if (isset($data['output_text']) && is_string($data['output_text'])) {
				return trim($data['output_text']);
			}

			// --- Format 2: output → content → text (gpt-5-mini & gpt-5-pro) ---
			if (isset($data['output'][0]['content'][0]['text'])) {
				return trim($data['output'][0]['content'][0]['text']);
			}

			// --- Format 3: content[] (gpt-5.1 & gpt-5-mini alt format) ---
			if (isset($data['content'][0]['text'])) {
				return trim($data['content'][0]['text']);
			}

			// --- Format 4: GPT-5-nano → output[] blocks with "type":"message" ---
			if (isset($data['output']) && is_array($data['output'])) {
				foreach ($data['output'] as $block) {
					if (isset($block['type']) && $block['type'] === 'message') {
						if (isset($block['content'][0]['text'])) {
							return trim($block['content'][0]['text']);
						}
					}
				}
			}

			// --- Format 5: outputs[] array (gpt-5.1 older beta) ---
			if (isset($data['outputs'][0])) {
				return trim($data['outputs'][0]);
			}

			// --- Format 6: single-string fallback ---
			if (isset($data['response']) && is_string($data['response'])) {
				return trim($data['response']);
			}

			// --- No known formats matched ---
			$this->last_error = "Unexpected GPT-5 format. Raw: " . substr(json_encode($data), 0, 500);
			error_log("GPT-5 RAW (UNPARSED): " . print_r($data, true));
			return false;
		}

		/**
		 * 🎯 ChatGPT (3.5/4/4o) extraction
		 */
		if (isset($data['choices'][0]['message']['content'])) {
			return trim($data['choices'][0]['message']['content']);
		}

		$this->last_error = "Unexpected response format.";
		return false;
	}

    /**
     * Parse anchor response
     */
    private function parse_anchor_response($response) {
        if (empty($response)) {
            error_log('SSP AI: Empty response from API');
            return array();
        }
        
        // Clean response - remove markdown code blocks if present
        $response = preg_replace('/```json\s*/i', '', $response);
        $response = preg_replace('/```\s*/i', '', $response);
        $response = trim($response);
        
        // Try to parse as JSON first
        $decoded = json_decode($response, true);
        if (is_array($decoded) && !empty($decoded)) {
            $suggestions = array_map('trim', $decoded);
            // Filter out empty suggestions (allow single-word)
            $suggestions = array_filter($suggestions, function($s) {
                if (!is_string($s) || empty(trim($s))) {
                    return false;
                }
                $s = trim($s);
                if (strlen($s) < 1 || strlen($s) > 100) {
                    return false;
                }
                return true;
            });
            if (!empty($suggestions)) {
                error_log('SSP AI: Successfully parsed ' . count($suggestions) . ' anchor suggestions from JSON');
                return array_values($suggestions); // Re-index array
            }
        }
        
        // Fallback 1: Extract quoted strings (handles various JSON formats)
        preg_match_all('/["\']([^"\']{1,100})["\']/', $response, $matches);
        if (!empty($matches[1])) {
            $suggestions = array_unique(array_map('trim', $matches[1]));
            $suggestions = array_filter($suggestions, function($s) {
                if (!is_string($s) || empty(trim($s))) {
                    return false;
                }
                $s = trim($s);
                if (strlen($s) < 1 || strlen($s) > 100) {
                    return false;
                }
                return true;
            });
            if (!empty($suggestions)) {
                error_log('SSP AI: Extracted ' . count($suggestions) . ' anchor suggestions from quoted strings');
                return array_slice(array_values($suggestions), 0, 3);
            }
        }
        
        // Fallback 2: Extract text between brackets (JSON array format)
        if (preg_match('/\[(.*?)\]/s', $response, $bracket_match)) {
            $inside_brackets = $bracket_match[1] ?? '';
            preg_match_all('/["\']([^"\']{1,100})["\']/', $inside_brackets, $bracket_matches);
            if (!empty($bracket_matches[1])) {
                $suggestions = array_unique(array_map('trim', $bracket_matches[1]));
                $suggestions = array_filter($suggestions, function($s) {
                    if (!is_string($s) || empty(trim($s))) {
                        return false;
                    }
                    $s = trim($s);
                    if (strlen($s) < 1 || strlen($s) > 100) {
                        return false;
                    }
                    return true;
                });
                if (!empty($suggestions)) {
                    error_log('SSP AI: Extracted ' . count($suggestions) . ' anchor suggestions from bracket format');
                    return array_slice(array_values($suggestions), 0, 3);
                }
            }
        }
        
        // Fallback 3: Split by lines and extract meaningful phrases
        $lines = array_filter(array_map('trim', explode("\n", $response)));
        $suggestions = array();
        foreach ($lines as $line) {
            // Remove numbering (1., 2., etc.)
            $line = preg_replace('/^\d+[\.\)]\s*/', '', $line);
            // Remove markdown formatting
            $line = preg_replace('/[*_`]/', '', $line);
            $line = trim($line);
            
            // Only keep lines that look like anchor text (1-100 chars, not too generic)
            if (!empty($line) && 
                strlen($line) >= 1 && 
                strlen($line) <= 100) {
                if (!in_array(strtolower($line), ['anchor text', 'suggestion', 'option', 'answer'])) {
                    $suggestions[] = $line;
                }
            }
            
            if (count($suggestions) >= 3) break;
        }
        
        if (!empty($suggestions)) {
            error_log('SSP AI: Extracted ' . count($suggestions) . ' anchor suggestions from line-by-line parsing');
            return array_slice(array_values($suggestions), 0, 3);
        }
        
        // Last fallback: log the raw response for debugging
        error_log('SSP AI: Failed to parse anchor response. Raw response: ' . substr($response, 0, 500));
        return array();
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
        // 1) Try JSON decode directly
        $decoded = json_decode($response, true);

        $post_lookup = [];
        foreach ($posts as $p) {
            $post_lookup[$p->ID] = $p;
        }

        $results = [];

        /* ------------------------------
        * CASE 1: VALID JSON ARRAY
        * ------------------------------ */
        if (is_array($decoded)) {

            // CASE 1A: Array of objects with post_id or id
            if (isset($decoded[0]) && is_array($decoded[0])) {
                foreach ($decoded as $item) {

                    $id = null;

                    if (isset($item['post_id'])) {
                        $id = intval($item['post_id']);
                    } elseif (isset($item['id'])) {
                        $id = intval($item['id']);
                    } elseif (isset($item['post']['id'])) {
                        $id = intval($item['post']['id']);
                    }

                    if ($id && isset($post_lookup[$id])) {
                        $results[] = $post_lookup[$id];
                    }
                }

                if (!empty($results)) {
                    return $results;
                }
            }

            // CASE 1B: Nested formats like {"results":[{ "id": 123 }]}
            $possible_keys = ['results','ranked','top_posts','data'];
            foreach ($possible_keys as $key) {
                if (isset($decoded[$key]) && is_array($decoded[$key])) {

                    foreach ($decoded[$key] as $item) {

                        $id = null;

                        if (isset($item['post_id']))  $id = intval($item['post_id']);
                        if (isset($item['id']))       $id = intval($item['id']);
                        if (isset($item['post']['id'])) $id = intval($item['post']['id']);

                        if ($id && isset($post_lookup[$id])) {
                            $results[] = $post_lookup[$id];
                        }
                    }

                    if (!empty($results)) {
                        return $results;
                    }
                }
            }
        }

        /* -----------------------------------
        * CASE 2: RAW GPT-5-NANO TEXT OUTPUT
        * Extract numbers like:
        *  - "123"
        *  - "1. 123"
        *  - "IDs: 123, 456, 789"
        * ----------------------------------- */

        // Find ANY 2–5 digit post IDs
        preg_match_all('/\b(\d{1,6})\b/', $response, $matches);

        if (!empty($matches[1])) {
            $ids = array_map('intval', $matches[1]);
            $ids = array_unique($ids);

            foreach ($ids as $id) {
                if (isset($post_lookup[$id])) {
                    $results[] = $post_lookup[$id];
                }
            }

            if (!empty($results)) {
                return $results;
            }
        }

        /* ------------------------------------
        * CASE 3: Nothing worked → return empty
        * ------------------------------------ */
        error_log("SSP AI: Relevance parse failed for GPT-5-nano output: " . substr($response, 0, 300));

        return [];
    }
    
    /**
     * Test API connection
     */
    public function test_connection() {
        // Reload settings in case they were just changed
        $this->reload_settings();
        
        // Debug: Log before validation check
        error_log('SSP AI Test Connection: Starting test. API key length: ' . strlen($this->api_key));
        error_log('SSP AI Test Connection: API key first 5 chars: ' . substr($this->api_key, 0, 5));
        error_log('SSP AI Test Connection: API base URL: ' . $this->api_base_url);
        error_log('SSP AI Test Connection: Model: ' . $this->model);
        
        if (!$this->is_api_configured()) {
            $this->last_error = 'API key validation failed. Please check your API key format in Settings.';
            error_log('SSP AI Test: API key not configured or validation failed');
            return false;
        }
        
        error_log('SSP AI Test Connection: API key validation passed, making API request...');
        
        // Use minimal prompt and tokens for test connection to avoid quota issues
        $prompt = "Say: OK";
        $response = $this->make_api_request($prompt, 300); // Use only 200 max_tokens for test
        
        if ($response === false) {
            error_log('SSP AI Test: API request failed - check error logs above for details');
            return false;
        }
        
        error_log('SSP AI Test Connection: API request successful! Response length: ' . strlen($response));
        return !empty($response);
    }
    
    /**
     * Get last error message
     */
    public function get_last_error() {
        return $this->last_error;
    }
    
    /**
     * Get fallback anchor suggestions when AI fails
     */
    private function get_fallback_anchor_suggestions($target_post_id) {
        $target_post = get_post($target_post_id);
        // Don't return generic fallbacks immediately - try to build contextual ones
        if (!$target_post || empty($target_post->post_title)) {
            // Last resort: return multi-word generic fallbacks (violates goal 5 but ensures multi-word)
            return array('Read more', 'Learn more', 'Continue reading');
        }
        
        $title = trim($target_post->post_title);
        if (empty($title)) {
            // Last resort: return multi-word generic fallbacks
            return array('Read more', 'Learn more', 'Continue reading');
        }
        
        // Extract meaningful words (filter stop words)
        $words = array_filter(explode(' ', $title), function($word) {
            if (!is_string($word)) {
                return false;
            }
            $word = trim($word, '.,!?;:"()[]');
            return strlen($word) > 2 && !in_array(strtolower($word), ['the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'from', 'is', 'are', 'was', 'were', 'be', 'been']);
        });
        $words = array_values($words); // Re-index
        
        // Validate we have words to work with
        if (empty($words) || !is_array($words) || count($words) === 0) {
            // Fallback if no meaningful words extracted - try without stop word filtering
            $all_words = array_filter(explode(' ', $title), function($word) {
                if (!is_string($word)) {
                    return false;
                }
                $word = trim($word, '.,!?;:"()[]');
                return !empty($word) && strlen($word) > 0; // Only filter out truly empty strings
            });
            $words = array_slice(array_values($all_words), 0, 3); // Re-index and limit
            if (empty($words) || count($words) === 0) {
                // Last resort: use title as single word (will be used to build phrases later)
                $words = array(trim($title));
            }
        }
        
        $suggestions = array();
        
        // Priority 1: Use full title ONLY if multi-word (goal: semantic phrases)
        if (!empty($title) && strlen($title) <= 100) {
            $title_word_count = str_word_count($title);
            if ($title_word_count !== false && $title_word_count >= 2) {
                $suggestions[] = $title;
            }
        }
        
        // Priority 2: Multi-word phrases from title (2-3 words) - ALWAYS ensures multi-word
        if (count($words) >= 2) {
            $two_word = implode(' ', array_slice($words, 0, 2));
            $two_word_count = str_word_count($two_word);
            if ($two_word_count !== false && $two_word_count >= 2 && 
                !in_array(strtolower($two_word), array_map('strtolower', $suggestions)) && 
                strlen($two_word) <= 100) {
                $suggestions[] = $two_word;
            }
            
            if (count($words) >= 3) {
                $three_word = implode(' ', array_slice($words, 0, 3));
                $three_word_count = str_word_count($three_word);
                if ($three_word_count !== false && $three_word_count >= 2 &&
                    !in_array(strtolower($three_word), array_map('strtolower', $suggestions)) && 
                    strlen($three_word) <= 100) {
                    $suggestions[] = $three_word;
                }
            }
        }
        
        // Priority 3: Contextual phrases (only if we don't have 3 yet) - ensures multi-word
        if (count($suggestions) < 3 && !empty($title)) {
            // For single-word titles, build contextual phrases
            $title_word_count = str_word_count($title);
            if ($title_word_count !== false && $title_word_count === 1) {
                $contextual_phrases = array(
                    'Learn more about ' . $title,
                    'Read about ' . $title,
                    'Discover ' . $title,
                    'best ' . $title,
                    'guide to ' . $title
                );
            } else {
                // Multi-word title: build simpler contextual phrases
                $contextual_phrases = array(
            'Learn more about ' . $title,
            'Read about ' . $title,
            'Discover ' . $title
        );
            }
        
            foreach ($contextual_phrases as $phrase) {
                if (count($suggestions) >= 3) break;
                $phrase_lower = strtolower($phrase);
                $phrase_word_count = str_word_count($phrase);
                if ($phrase_word_count !== false && $phrase_word_count >= 2) {
                    $exists = false;
                    foreach ($suggestions as $existing) {
                        if (strtolower($existing) === $phrase_lower) {
                            $exists = true;
                            break;
                        }
                    }
                    if (!$exists && strlen($phrase) <= 100) {
                        $suggestions[] = $phrase;
                    }
                }
            }
        }
        
        // Priority 4: If title is single-word, build more contextual phrases (avoid generic fallbacks)
        if (count($suggestions) < 3 && !empty($title)) {
            $title_word_count = str_word_count($title);
            if ($title_word_count !== false && $title_word_count === 1) {
                $more_contextual = array(
                    'best ' . $title . ' guide',
                    'top ' . $title . ' information',
                    'about ' . $title . ' content'
                );
                
                foreach ($more_contextual as $phrase) {
                    if (count($suggestions) >= 3) break;
                    $phrase_word_count = str_word_count($phrase);
                    if ($phrase_word_count !== false && $phrase_word_count >= 2 &&
                        !in_array(strtolower($phrase), array_map('strtolower', $suggestions)) &&
                        strlen($phrase) <= 100) {
                        $suggestions[] = $phrase;
                    }
                }
            }
        }
        
        // Priority 5: Generic fallbacks ONLY as absolute last resort (violates goal 5 but ensures 3 suggestions)
        // This attempts to always return 3 multi-word suggestions, but may return fewer if all are duplicates
        while (count($suggestions) < 3) {
            $generic = array('Read more', 'Learn more', 'Continue reading');
            $added = false;
            foreach ($generic as $fallback) {
                if (count($suggestions) >= 3) break;
                $fallback_lower = strtolower(trim($fallback));
                $already_exists = false;
                foreach ($suggestions as $existing) {
                    if (strtolower(trim($existing)) === $fallback_lower) {
                        $already_exists = true;
                        break;
                    }
                }
                if (!$already_exists) {
                    $fallback_word_count = str_word_count($fallback);
                    if ($fallback_word_count !== false && $fallback_word_count >= 2) {
                        $suggestions[] = $fallback;
                        $added = true;
                    }
                }
            }
            // If we couldn't add any more (all duplicates), break to avoid infinite loop
            if (!$added) break;
        }
        
        // Return up to 3 suggestions, prioritizing multi-word semantic phrases
        return array_slice($suggestions, 0, 3);
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
