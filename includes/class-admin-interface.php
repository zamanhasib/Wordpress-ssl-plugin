<?php
/**
 * Admin interface for Semantic Silo Pro
 */

if (!defined('ABSPATH')) {
    exit;
}

class SSP_Admin_Interface {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        
        // Add pillar page indicator to posts/pages admin lists
        add_filter('manage_posts_columns', array($this, 'add_pillar_column'));
        add_filter('manage_pages_columns', array($this, 'add_pillar_column'));
        add_action('manage_posts_custom_column', array($this, 'display_pillar_column'), 10, 2);
        add_action('manage_pages_custom_column', array($this, 'display_pillar_column'), 10, 2);
        add_action('admin_head', array($this, 'add_pillar_column_styles'));
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            'Semantic Silo Pro',
            'Silo Pro',
            'manage_options',
            'semantic-silo-pro',
            array($this, 'admin_page'),
            'dashicons-networking',
            30
        );
        
        add_submenu_page(
            'semantic-silo-pro',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'semantic-silo-pro',
            array($this, 'admin_page')
        );
        
        add_submenu_page(
            'semantic-silo-pro',
            'Settings',
            'Settings',
            'manage_options',
            'semantic-silo-pro-settings',
            array($this, 'settings_page')
        );
        
        add_submenu_page(
            'semantic-silo-pro',
            'Logs',
            'Logs',
            'manage_options',
            'semantic-silo-pro-logs',
            array($this, 'logs_page')
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        // Register settings without sanitization callback to preserve API key exactly as entered
        // We handle sanitization manually in settings_page() to avoid stripping special characters
        register_setting('ssp_settings', 'ssp_settings', array(
            'type' => 'array',
            'sanitize_callback' => null, // No automatic sanitization - we handle it manually
            'default' => array()
        ));
    }
    
    /**
     * Main admin page
     */
    public function admin_page() {
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'semantic-silo-pro'));
        }
        ?>
        <div class="wrap">
            <h1>Semantic Silo Pro Dashboard</h1>
            
            <div class="ssp-dashboard">
                <div class="ssp-dashboard-stats">
                    <?php $this->render_stats(); ?>
                </div>
                
                <div class="ssp-dashboard-content">
                    <div class="ssp-tabs">
                        <nav class="nav-tab-wrapper">
                            <a href="#create-silo" class="nav-tab nav-tab-active">Create Silo</a>
                            <a href="#manage-silos" class="nav-tab">Manage Silos</a>
                            <a href="#link-engine" class="nav-tab">Link Engine</a>
                            <a href="#anchor-report" class="nav-tab">Anchor Report</a>
                            <a href="#anchor-management" class="nav-tab">Anchor Management</a>
                            <a href="#orphan-posts" class="nav-tab">Orphan Posts</a>
                            <a href="#exclusions" class="nav-tab">Exclusions</a>
                            <a href="#troubleshoot" class="nav-tab">Troubleshoot</a>
                        </nav>
                        
                        <div class="tab-content">
                            <div id="create-silo" class="tab-pane active">
                                <?php $this->render_create_silo_form(); ?>
                            </div>
                            
                            <div id="manage-silos" class="tab-pane">
                                <?php $this->render_silos_list(); ?>
                            </div>
                            
                            <div id="link-engine" class="tab-pane">
                                <?php $this->render_link_engine(); ?>
                            </div>
                            
                            <div id="anchor-report" class="tab-pane">
                                <?php $this->render_anchor_report(); ?>
                            </div>
                            
                            <div id="anchor-management" class="tab-pane">
                                <?php $this->render_anchor_management(); ?>
                            </div>
                            
                            <div id="orphan-posts" class="tab-pane">
                                <?php $this->render_orphan_posts(); ?>
                            </div>
                            
                            <div id="exclusions" class="tab-pane">
                                <?php $this->render_exclusions(); ?>
                            </div>
                            
                            <div id="troubleshoot" class="tab-pane">
                                <?php $this->render_troubleshoot(); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Settings page
     */
    public function settings_page() {
        if (isset($_POST['submit'])) {
            // Verify nonce for security
            if (!isset($_POST['ssp_settings_nonce']) || !wp_verify_nonce($_POST['ssp_settings_nonce'], 'ssp_settings_save')) {
                echo '<div class="notice notice-error"><p>Security check failed. Please try again.</p></div>';
            } else {
                $ai_provider = sanitize_text_field($_POST['ai_provider']);
                
                // Set base URL based on provider
                $base_url = 'https://api.openai.com/v1';
                if ($ai_provider === 'openrouter') {
                    $base_url = 'https://openrouter.ai/api/v1';
                }
                
                // IMPORTANT: Don't use sanitize_text_field on API key as it might strip special characters
                // API keys can contain hyphens, underscores, etc. Just trim whitespace
                // WordPress automatically unslashes $_POST data, but be explicit for safety
                $api_key_raw = isset($_POST['openai_api_key']) ? trim(wp_unslash($_POST['openai_api_key'])) : '';
                
                // Debug: Log what we received from POST
                error_log('SSP AI Settings Save: POST openai_api_key exists: ' . (isset($_POST['openai_api_key']) ? 'YES' : 'NO'));
                error_log('SSP AI Settings Save: Raw key length: ' . strlen($api_key_raw));
                error_log('SSP AI Settings Save: Raw key first 5 chars: ' . substr($api_key_raw, 0, 5));
                
                // Normalize model against provider to avoid provider/model mismatches
                $model_raw = sanitize_text_field($_POST['ai_model']);
                $model_norm = $model_raw;
                if ($ai_provider === 'openai') {
                    // If a namespaced model (like openai/gpt-4o) was selected, strip namespace for OpenAI API
                    if (strpos($model_norm, '/') !== false) {
                        $parts = explode('/', $model_norm, 2);
                        $model_norm = end($parts);
                    }
                } elseif ($ai_provider === 'openrouter') {
                    // If a plain OpenAI-style model was selected (e.g., gpt-4o), prefix with openai/
                    if (strpos($model_norm, '/') === false) {
                        // Common OpenAI models start with gpt-
                        if (strpos($model_norm, 'gpt-') === 0 || strpos($model_norm, 'gpt4') === 0) {
                            $model_norm = 'openai/' . $model_norm;
                        }
                    }
                }
                
                $settings = array(
                    'ai_provider' => $ai_provider,
                    'ai_model' => $model_norm,
                    'openai_api_key' => $api_key_raw, // Use raw key, only trimmed
                    'ai_base_url' => $base_url,
                    'max_links_per_post' => intval($_POST['max_links_per_post']),
                    'link_placement' => sanitize_text_field($_POST['link_placement'])
                );
                
                // Debug: Log what we're about to save
                error_log('SSP AI Settings Save: About to save key with length: ' . strlen($settings['openai_api_key']));
                
                $update_result = update_option('ssp_settings', $settings);
                
                // Debug: Verify what was actually saved
                $saved_settings = get_option('ssp_settings', array());
                $saved_key = isset($saved_settings['openai_api_key']) ? trim($saved_settings['openai_api_key']) : '';
                error_log('SSP AI Settings Save: update_option result: ' . ($update_result ? 'SUCCESS' : 'NO CHANGE'));
                error_log('SSP AI Settings Save: Saved key length: ' . strlen($saved_key));
                error_log('SSP AI Settings Save: Saved key first 5 chars: ' . substr($saved_key, 0, 5));
                error_log('SSP AI Settings Save: Keys match: ' . ($api_key_raw === $saved_key ? 'YES' : 'NO'));
                
                // Reload AI integration settings immediately after saving
                $ai_integration = SSP_AI_Integration::get_instance();
                $ai_integration->reload_settings();
                
                // Debug: Log key length after save (don't log actual key for security)
                error_log('SSP AI: Settings saved. API key length: ' . strlen($api_key_raw) . ' characters');
                error_log('SSP AI: API key validation result: ' . ($ai_integration->is_api_configured() ? 'VALID' : 'INVALID'));
                
                // Show success message with API key status
                $status_message = 'Settings saved!';
                if (!empty($api_key_raw)) {
                    $status_message .= ' API key: ' . strlen($api_key_raw) . ' characters saved';
                    if ($ai_integration->is_api_configured()) {
                        $status_message .= ' - ✓ Validated successfully!';
                    } else {
                        $status_message .= ' - ⚠ Validation failed (check error logs)';
                    }
                }
                
                echo '<div class="notice notice-success"><p>' . esc_html($status_message) . '</p></div>';
            }
        }
        
        $settings = get_option('ssp_settings', array());
        ?>
        <div class="wrap">
            <h1>Semantic Silo Pro Settings</h1>
            
            <?php 
            // Debug: Check current settings before form
            $current_settings = get_option('ssp_settings', array());
            $current_key = isset($current_settings['openai_api_key']) ? trim($current_settings['openai_api_key']) : '';
            if (!empty($current_key)) {
                echo '<!-- DEBUG: Current API key length in DB: ' . strlen($current_key) . ' -->';
            }
            ?>
            <form method="post" action="" enctype="multipart/form-data">
                <?php wp_nonce_field('ssp_settings_save', 'ssp_settings_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">AI Provider</th>
                        <td>
                            <select name="ai_provider" id="ai-provider-select">
                                <option value="openai" <?php selected($settings['ai_provider'] ?? 'openai', 'openai'); ?>>OpenAI</option>
                                <option value="openrouter" <?php selected($settings['ai_provider'] ?? '', 'openrouter'); ?>>OpenRouter</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">AI Model</th>
                        <td>
                            <select name="ai_model" id="ai-model-select">
                                <optgroup label="OpenAI Models" class="openai-models">
                                    <option value="gpt-3.5-turbo" <?php selected($settings['ai_model'] ?? 'gpt-3.5-turbo', 'gpt-3.5-turbo'); ?>>GPT-3.5 Turbo (Fast & Cheap)</option>
                                    <option value="gpt-4" <?php selected($settings['ai_model'] ?? '', 'gpt-4'); ?>>GPT-4 (High Quality)</option>
                                    <option value="gpt-4-turbo" <?php selected($settings['ai_model'] ?? '', 'gpt-4-turbo'); ?>>GPT-4 Turbo (Balanced)</option>
                                    <option value="gpt-4o" <?php selected($settings['ai_model'] ?? '', 'gpt-4o'); ?>>GPT-4o (Omni - Latest)</option>
                                    <option value="gpt-4o-mini" <?php selected($settings['ai_model'] ?? '', 'gpt-4o-mini'); ?>>GPT-4o Mini (Fast & Affordable)</option>
                                    <option value="gpt-5" <?php selected($settings['ai_model'] ?? '', 'gpt-5'); ?>>GPT-5 (Next Generation - Coming Soon)</option>
                                    <option value="gpt-5-nano" <?php selected($settings['ai_model'] ?? '', 'gpt-5-nano'); ?>>GPT-5 Nano (Efficient - Coming Soon)</option>
                                </optgroup>
                                <optgroup label="OpenRouter Models" class="openrouter-models" style="display:none;">
                                    <!-- OpenAI via OpenRouter -->
                                    <option value="openai/gpt-3.5-turbo" <?php selected($settings['ai_model'] ?? '', 'openai/gpt-3.5-turbo'); ?>>GPT-3.5 Turbo</option>
                                    <option value="openai/gpt-4" <?php selected($settings['ai_model'] ?? '', 'openai/gpt-4'); ?>>GPT-4</option>
                                    <option value="openai/gpt-4-turbo" <?php selected($settings['ai_model'] ?? '', 'openai/gpt-4-turbo'); ?>>GPT-4 Turbo</option>
                                    <option value="openai/gpt-4o" <?php selected($settings['ai_model'] ?? '', 'openai/gpt-4o'); ?>>GPT-4o</option>
                                    <option value="openai/gpt-4o-mini" <?php selected($settings['ai_model'] ?? '', 'openai/gpt-4o-mini'); ?>>GPT-4o Mini</option>
                                    <!-- Anthropic Claude -->
                                    <option value="anthropic/claude-3-opus" <?php selected($settings['ai_model'] ?? '', 'anthropic/claude-3-opus'); ?>>Claude 3 Opus (Best Quality)</option>
                                    <option value="anthropic/claude-3-sonnet" <?php selected($settings['ai_model'] ?? '', 'anthropic/claude-3-sonnet'); ?>>Claude 3 Sonnet (Balanced)</option>
                                    <option value="anthropic/claude-3-haiku" <?php selected($settings['ai_model'] ?? '', 'anthropic/claude-3-haiku'); ?>>Claude 3 Haiku (Fast)</option>
                                    <option value="anthropic/claude-3.5-sonnet" <?php selected($settings['ai_model'] ?? '', 'anthropic/claude-3.5-sonnet'); ?>>Claude 3.5 Sonnet (Latest)</option>
                                    <!-- Google Gemini -->
                                    <option value="google/gemini-pro" <?php selected($settings['ai_model'] ?? '', 'google/gemini-pro'); ?>>Google Gemini Pro</option>
                                    <option value="google/gemini-pro-1.5" <?php selected($settings['ai_model'] ?? '', 'google/gemini-pro-1.5'); ?>>Google Gemini Pro 1.5</option>
                                    <option value="google/gemini-flash-1.5" <?php selected($settings['ai_model'] ?? '', 'google/gemini-flash-1.5'); ?>>Google Gemini Flash 1.5</option>
                                    <!-- Deepseek -->
                                    <option value="deepseek/deepseek-chat" <?php selected($settings['ai_model'] ?? '', 'deepseek/deepseek-chat'); ?>>Deepseek Chat</option>
                                    <option value="deepseek/deepseek-coder" <?php selected($settings['ai_model'] ?? '', 'deepseek/deepseek-coder'); ?>>Deepseek Coder</option>
                                    <!-- Meta Llama -->
                                    <option value="meta-llama/llama-3-70b" <?php selected($settings['ai_model'] ?? '', 'meta-llama/llama-3-70b'); ?>>Llama 3 70B</option>
                                    <option value="meta-llama/llama-3-8b" <?php selected($settings['ai_model'] ?? '', 'meta-llama/llama-3-8b'); ?>>Llama 3 8B</option>
                                    <option value="meta-llama/llama-3.1-405b" <?php selected($settings['ai_model'] ?? '', 'meta-llama/llama-3.1-405b'); ?>>Llama 3.1 405B (Huge)</option>
                                </optgroup>
                            </select>
                            <p class="description">Select the AI model to use for anchor text and content suggestions</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">API Key</th>
                        <td>
                            <input type="password" name="openai_api_key" value="<?php echo esc_attr($settings['openai_api_key'] ?? ''); ?>" class="regular-text" />
                            <p class="description">Enter your OpenAI or OpenRouter API key</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Max Links Per Post</th>
                        <td>
                            <input type="number" name="max_links_per_post" value="<?php echo esc_attr($settings['max_links_per_post'] ?? 3); ?>" min="1" max="10" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Link Placement</th>
                        <td>
                            <select name="link_placement">
                                <option value="inline" <?php selected($settings['link_placement'] ?? 'inline', 'inline'); ?>>Inline</option>
                                <option value="after_content" <?php selected($settings['link_placement'] ?? '', 'after_content'); ?>>After Content</option>
                                <option value="before_content" <?php selected($settings['link_placement'] ?? '', 'before_content'); ?>>Before Content</option>
                            </select>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="submit" class="button-primary" value="Save Settings" />
                    <button type="button" id="test-connection-btn" class="button">Test AI Connection</button>
                </p>
                <div id="connection-result" style="margin-top:10px;"></div>
                <div id="connection-result" aria-live="polite"></div>
            </form>
        </div>
        <?php
    }
    
    /**
     * Logs page
     */
    public function logs_page() {
        $logger = SSP_Logger::get_instance();
        $logs = $logger->get_logs(null, 50);
        ?>
        <div class="wrap">
            <h1>Plugin Logs</h1>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Level</th>
                        <th>Message</th>
                        <th>Context</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><span class="ssp-log-level ssp-log-<?php echo esc_attr($log->level); ?>"><?php echo esc_html(strtoupper($log->level)); ?></span></td>
                        <td><?php echo esc_html($log->message); ?></td>
                        <td><?php echo esc_html($log->context); ?></td>
                        <td><?php echo esc_html($log->created_at); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    /**
     * Render dashboard stats
     */
    private function render_stats() {
        global $wpdb;
        
        $total_silos = SSP_Database::get_silos_count();
        $total_links = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}ssp_links WHERE status = %s", 'active'));
        $total_orphans = SSP_Database::get_orphan_posts_count();
        
        ?>
        <div class="ssp-stats-grid">
            <div class="ssp-stat-box">
                <h3><?php echo intval($total_silos); ?></h3>
                <p>Total Silos</p>
            </div>
            <div class="ssp-stat-box">
                <h3><?php echo intval($total_links); ?></h3>
                <p>Active Links</p>
            </div>
            <div class="ssp-stat-box">
                <h3><?php echo intval($total_orphans); ?></h3>
                <p>Orphan Posts</p>
            </div>
            <div class="ssp-stat-box">
                <h3>0</h3>
                <p>AI Suggestions</p>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render create silo form
     */
    private function render_create_silo_form() {
        ?>
        <form id="ssp-create-silo-form" class="ssp-form">
            <div class="ssp-form-section">
                <h3>Setup Method</h3>
                <label><input type="radio" name="setup_method" value="ai_recommended" checked> AI Recommended</label>
                <label><input type="radio" name="setup_method" value="category_based"> Category Based</label>
                <label><input type="radio" name="setup_method" value="manual"> Manual</label>
            </div>
            
            <div class="ssp-form-section">
                <h3>Pillar Posts</h3>
                <div class="ssp-checkbox-list" style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fff;">
                    <?php
                    $all_posts = get_posts(array(
                        'post_type' => array('post', 'page'),
                        'post_status' => 'publish',
                        'numberposts' => 100,
                        'orderby' => 'title',
                        'order' => 'ASC'
                    ));
                    
                    // Separate pages and posts
                    $pages = array();
                    $posts_only = array();
                    $front_page_id = intval(get_option('page_on_front'));
                    
                    foreach ($all_posts as $post) {
                        if ($post->post_type === 'page') {
                            $pages[] = $post;
                        } else {
                            $posts_only[] = $post;
                        }
                    }
                    
                    // Display Pages section
                    if (!empty($pages)) {
                        echo '<div style="font-weight: 600; padding: 8px 0;">Pages (' . count($pages) . ')</div>';
                        foreach ($pages as $post) {
                            $is_home = ($front_page_id && $post->ID == $front_page_id);
                            echo '<label style="display: block; margin: 5px 0;">';
                            echo '<input type="radio" name="pillar_post" value="' . esc_attr($post->ID) . '" style="margin-right: 8px;">';
                            echo esc_html($post->post_title) . ($is_home ? ' (Home)' : '') . ' (ID: ' . esc_html($post->ID) . ')';
                            echo '</label>';
                        }
                    }
                    
                    // Display Posts section
                    if (!empty($posts_only)) {
                        if (!empty($pages)) {
                            echo '<div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;"></div>';
                        }
                        echo '<div style="font-weight: 600; padding: 8px 0;">Posts (' . count($posts_only) . ')</div>';
                        foreach ($posts_only as $post) {
                            echo '<label style="display: block; margin: 5px 0;">';
                            echo '<input type="radio" name="pillar_post" value="' . esc_attr($post->ID) . '" style="margin-right: 8px;">';
                            echo esc_html($post->post_title) . ' (ID: ' . esc_html($post->ID) . ')';
                            echo '</label>';
                        }
                    }
                    ?>
                </div>
                <p class="description">Select one post that will serve as the pillar post for your silo.</p>
            </div>
            
            <!-- Manual Setup - Support Posts Selection -->
            <div class="ssp-form-section ssp-method-specific" id="manual-section" style="display: none;">
                <h3>Support Posts</h3>
                <div class="ssp-checkbox-list" style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fff;">
                    <?php
                    $all_posts = get_posts(array(
                        'post_type' => array('post', 'page'),
                        'post_status' => 'publish',
                        'numberposts' => 100,
                        'orderby' => 'title',
                        'order' => 'ASC'
                    ));
                    
                    // Separate pages and posts
                    $pages = array();
                    $posts_only = array();
                    $front_page_id = intval(get_option('page_on_front'));
                    
                    foreach ($all_posts as $post) {
                        if ($post->post_type === 'page') {
                            $pages[] = $post;
                        } else {
                            $posts_only[] = $post;
                        }
                    }
                    
                    // Display Pages section
                    if (!empty($pages)) {
                        echo '<div style="font-weight: 600; padding: 8px 0;">Pages (' . count($pages) . ')</div>';
                        foreach ($pages as $post) {
                            $is_home = ($front_page_id && $post->ID == $front_page_id);
                            echo '<label style="display: block; margin: 5px 0;">';
                            echo '<input type="checkbox" name="support_posts[]" value="' . esc_attr($post->ID) . '" style="margin-right: 8px;">';
                            echo esc_html($post->post_title) . ($is_home ? ' (Home)' : '') . ' (ID: ' . esc_html($post->ID) . ')';
                            echo '</label>';
                        }
                    }
                    
                    // Display Posts section
                    if (!empty($posts_only)) {
                        if (!empty($pages)) {
                            echo '<div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;"></div>';
                        }
                        echo '<div style="font-weight: 600; padding: 8px 0;">Posts (' . count($posts_only) . ')</div>';
                        foreach ($posts_only as $post) {
                            echo '<label style="display: block; margin: 5px 0;">';
                            echo '<input type="checkbox" name="support_posts[]" value="' . esc_attr($post->ID) . '" style="margin-right: 8px;">';
                            echo esc_html($post->post_title) . ' (ID: ' . esc_html($post->ID) . ')';
                            echo '</label>';
                        }
                    }
                    ?>
                </div>
                <p class="description">Check the posts that will support your pillar post(s).</p>
            </div>
            
            <!-- Category Based Setup -->
            <div class="ssp-form-section ssp-method-specific" id="category-based-section" style="display: none;">
                <h3>Category</h3>
                <select name="category_id" class="ssp-select">
                    <option value="">Select a category</option>
                    <?php
                    $categories = get_categories();
                    foreach ($categories as $category) {
                        echo '<option value="' . esc_attr($category->term_id) . '">' . esc_html($category->name) . '</option>';
                    }
                    ?>
                </select>
                <p class="description">All posts in this category will be added to the silo.</p>
            </div>
            
            <div class="ssp-form-section">
                <h3>Linking Mode</h3>
                <label><input type="radio" name="linking_mode" value="linear" checked> Linear (Pillar → Post A → Post B → ... → Back to Pillar)</label><br>
                <label><input type="radio" name="linking_mode" value="chained"> Chained (Each post links to next and previous)</label><br>
                <label><input type="radio" name="linking_mode" value="cross_linking"> Cross-Linking (Every post links to every other post)</label><br>
                <label><input type="radio" name="linking_mode" value="star_hub"> Star/Hub (All supports link to pillar only)</label><br>
                <label><input type="radio" name="linking_mode" value="hub_chain"> Hub-Chain (Supports link to pillar + adjacent supports)</label><br>
                <label><input type="radio" name="linking_mode" value="ai_contextual"> AI-Contextual (Smart links based on content similarity)</label><br>
                <label><input type="radio" name="linking_mode" value="custom"> Custom (Define your own pattern)</label>
            </div>
            
            <!-- Custom Pattern Section -->
            <div class="ssp-form-section ssp-mode-specific" id="custom-pattern-section" style="display: none;">
                <h3>Custom Linking Pattern</h3>
                
                <!-- Load Saved Pattern -->
                <div style="margin-bottom: 20px;">
                    <label>Load Saved Pattern: 
                        <select id="load-saved-pattern" class="ssp-select">
                            <option value="">-- Select a saved pattern --</option>
                            <?php
                            $saved_patterns = get_option('ssp_saved_patterns', array());
                            foreach ($saved_patterns as $pattern_name => $pattern_data) {
                                echo '<option value="' . esc_attr($pattern_name) . '">' . esc_html($pattern_name) . '</option>';
                            }
                            ?>
                        </select>
                        <button type="button" id="load-pattern-btn" class="button">Load Pattern</button>
                    </label>
                </div>
                
                <!-- Custom Rules -->
                <div id="custom-rules-container">
                    <p>Define your custom linking rules:</p>
                    <div id="custom-rules-list">
                        <!-- Rules will be added here -->
                    </div>
                    <button type="button" class="button ssp-add-rule">+ Add Rule</button>
                </div>
                
                <!-- Save Pattern -->
                <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd;">
                    <label>Save this pattern for reuse: 
                        <input type="text" id="pattern-name-input" placeholder="Enter pattern name..." class="regular-text" style="margin-left: 10px;">
                        <button type="button" id="save-pattern-btn" class="button button-secondary">💾 Save Pattern</button>
                    </label>
                </div>
                
                <p class="description">Create custom linking rules. Use "pillar" for the pillar post, or specific post IDs.</p>
            </div>
            
            <div class="ssp-form-section">
                <h3>Options</h3>
                <label><input type="checkbox" name="use_ai_anchors" checked> Use AI for anchor text</label><br>
                <label><input type="checkbox" name="auto_link" checked> Auto-link posts</label><br>
                <label><input type="checkbox" name="auto_update"> Auto-update on new posts</label>
                <p class="description" style="margin-left: 20px; margin-top: 5px;">For Category-Based: adds new posts in same category. For AI-Recommended: adds posts with keyword overlap.</p>
                
                <label><input type="checkbox" name="auto_assign_category" id="auto-assign-category"> Auto-assign support posts to category</label><br>
                <div id="auto-assign-options" style="display: none; margin-left: 20px;">
                    <label>Category: 
                        <select name="auto_assign_category_id" class="ssp-select">
                            <option value="">Select category...</option>
                            <?php
                            $categories = get_categories();
                            foreach ($categories as $category) {
                                echo '<option value="' . esc_attr($category->term_id) . '">' . esc_html($category->name) . '</option>';
                            }
                            ?>
                        </select>
                    </label>
                    <p class="description">Automatically assigns all support posts to selected category (no AI required)</p>
                </div>
            </div>
            
            <div class="ssp-form-section">
                <h3>Link Placement</h3>
                <p class="description">Choose how links will be inserted into your content</p>
                
                <label><input type="radio" name="link_placement" value="natural" checked> Natural Link Insertion (Recommended)</label>
                <p class="description" style="margin-left: 20px; margin-top: 5px;">Finds existing text in content and converts it to links. Preserves grammar and flow.</p>
                
                <label><input type="radio" name="link_placement" value="first_paragraph"> First Paragraph Only</label>
                <p class="description" style="margin-left: 20px; margin-top: 5px;">Inserts link in the first paragraph only. Good for quick discovery.</p>
            </div>
            
            <div class="ssp-form-section">
                <h3>Pillar Link Options (Apply to All Modes)</h3>
                <p class="description">Control how pillar and support posts link to each other</p>
                
                <label><input type="checkbox" name="supports_to_pillar" id="supports-to-pillar" checked> All support posts link to pillar</label>
                <p class="description" style="margin-left: 20px; margin-top: 5px;">Each support post will include a link pointing to the pillar page</p>
                
                <label><input type="checkbox" name="pillar_to_supports" id="pillar-to-supports"> Pillar links to support posts</label><br>
                <div id="pillar-options" style="display: none; margin-left: 20px;">
                    <label>Max pillar links: <input type="number" name="max_pillar_links" value="5" min="1" max="20" style="width: 60px;"></label>
                    <p class="description">Limit how many support posts the pillar will link to (prevents pillar clutter in large silos)</p>
                </div>
                
                <div id="contextual-options" style="display: none; margin-left: 20px;">
                    <label>Max contextual links per post: <input type="number" name="max_contextual_links" value="3" min="1" max="10" style="width: 60px;"></label><br>
                </div>
            </div>
            
            <div class="ssp-form-section">
                <button type="submit" class="button button-primary">Create Silo</button>
            </div>
        </form>
        <?php
    }
    
    /**
     * Render silos list
     */
    private function render_silos_list() {
        $silos = SSP_Database::get_silos();
        
        if (empty($silos)) {
            echo '<p>No silos created yet.</p>';
            return;
        }
        
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Silo ID</th>
                    <th>Name</th>
                    <th>Pillar Post</th>
                    <th>Setup Method</th>
                    <th>Posts</th>
                    <th>Links</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($silos as $silo): ?>
                <?php
                $pillar_post = get_post($silo->pillar_post_id);
                $post_count = SSP_Database::get_silo_post_count($silo->id);
                $link_count = SSP_Database::get_silo_link_count($silo->id);
                ?>
                <tr>
                    <td><strong><?php echo esc_html($silo->id); ?></strong></td>
                    <td><?php echo esc_html($silo->name . (!$pillar_post ? ' (no pillar)' : '')); ?></td>
                    <td>
                        <?php 
                        if ($pillar_post) {
                            $fp = intval(get_option('page_on_front'));
                            $type_label = ucfirst(get_post_type($pillar_post)) ?: 'Post';
                            $home_tag = ($fp && $pillar_post->ID == $fp) ? ' (Home)' : '';
                            echo esc_html($pillar_post->post_title . " [{$type_label}{$home_tag}]");
                        } else {
                            echo 'No Pillar';
                        }
                        ?>
                    </td>
                    <td><?php echo esc_html(ucfirst(str_replace('_', ' ', $silo->setup_method))); ?></td>
                    <td><?php echo esc_html($post_count); ?></td>
                    <td><?php echo esc_html($link_count); ?></td>
                    <td><?php echo esc_html(date('Y-m-d H:i', strtotime($silo->created_at))); ?></td>
                    <td>
                        <a href="#" class="button ssp-view-silo" data-silo-id="<?php echo esc_attr($silo->id); ?>">View</a>
                        <a href="#" class="button ssp-delete-silo" data-silo-id="<?php echo esc_attr($silo->id); ?>">Delete</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
    
    /**
     * Render link engine
     */
    private function render_link_engine() {
        ?>
        <div class="ssp-link-engine">
            <h3>Link Generation</h3>
            <p>Generate internal links for your silos.</p>
            
            <div class="ssp-form-section">
                <label>Select Silo:</label>
                <select id="silo-select" class="ssp-select">
                    <option value="">Select a silo...</option>
                    <?php
                    $silos = SSP_Database::get_silos();
                    foreach ($silos as $silo) {
                        echo '<option value="' . esc_attr($silo->id) . '">' . esc_html($silo->name) . '</option>';
                    }
                    ?>
                </select>
            </div>
            
            <div class="ssp-form-section">
                <button id="generate-links-btn" class="button button-primary">Generate Links</button>
                <button id="preview-links-btn" class="button">Preview Links</button>
                <button id="remove-links-btn" class="button">Remove Links</button>
            </div>
            
            <div id="link-results" class="ssp-link-results"></div>
        </div>
        <?php
    }
    
    /**
     * Render anchor ratio report
     */
    private function render_anchor_report() {
        global $wpdb;
        
        // Get settings for anchor limits
        $settings = get_option('ssp_anchor_settings', array(
            'max_usage_per_anchor' => 10,
            'warning_threshold' => 7
        ));
        
        ?>
        <div class="ssp-anchor-report">
            <h3>Anchor Text Usage Report</h3>
            <p class="description">Track anchor text usage across all silos to prevent over-optimization and maintain natural link profiles.</p>
            
            <!-- Settings -->
            <div class="ssp-form-section" style="background: #f9f9f9; padding: 15px; margin-bottom: 20px; border-left: 3px solid #0073aa;">
                <h4>⚙️ Anchor Usage Limits</h4>
                <form id="anchor-settings-form" style="display: inline-block;">
                    <label style="margin-right: 20px;">
                        <strong>Warning Threshold:</strong>
                        <input type="number" name="warning_threshold" id="warning-threshold" value="<?php echo intval($settings['warning_threshold']); ?>" min="1" max="100" style="width: 60px;">
                        <span class="description">Show warning when anchor is used this many times</span>
                    </label>
                    
                    <label style="margin-right: 20px;">
                        <strong>Max Usage:</strong>
                        <input type="number" name="max_usage_per_anchor" id="max-usage" value="<?php echo intval($settings['max_usage_per_anchor']); ?>" min="1" max="100" style="width: 60px;">
                        <span class="description">Prevent anchors from being used more than this</span>
                    </label>
                    
                    <button type="submit" class="button button-primary">Save Limits</button>
                </form>
            </div>
            
            <!-- Filters -->
            <div class="ssp-form-section">
                <label style="margin-right: 15px;">
                    <strong>Filter by Silo:</strong>
                    <select id="anchor-silo-filter" class="ssp-select">
                        <option value="">All Silos</option>
                        <?php
                        $silos = SSP_Database::get_silos();
                        foreach ($silos as $silo) {
                            echo '<option value="' . esc_attr($silo->id) . '">' . esc_html($silo->name) . '</option>';
                        }
                        ?>
                    </select>
                </label>
                
                <label style="margin-right: 15px;">
                    <strong>Status Filter:</strong>
                    <select id="anchor-status-filter" class="ssp-select">
                        <option value="all">All Status</option>
                        <option value="good">✅ Good (Healthy)</option>
                        <option value="warning">⚠️ Warning (Moderate)</option>
                        <option value="danger">🔴 Danger (Over-used)</option>
                    </select>
                </label>
                
                <button id="refresh-anchor-report" class="button">🔄 Refresh Report</button>
                <button id="export-anchor-report" class="button">📥 Export CSV</button>
            </div>
            
            <!-- Statistics Overview -->
            <div id="anchor-stats-overview" class="ssp-stats-grid" style="margin: 20px 0;">
                <div class="ssp-stat-box">
                    <h3 id="total-anchors-count">-</h3>
                    <p>Total Unique Anchors</p>
                </div>
                <div class="ssp-stat-box" style="background: #d4edda; border-left: 3px solid #28a745;">
                    <h3 id="healthy-anchors-count">-</h3>
                    <p>✅ Healthy Anchors</p>
                </div>
                <div class="ssp-stat-box" style="background: #fff3cd; border-left: 3px solid #ffc107;">
                    <h3 id="warning-anchors-count">-</h3>
                    <p>⚠️ Warning Level</p>
                </div>
                <div class="ssp-stat-box" style="background: #f8d7da; border-left: 3px solid #dc3545;">
                    <h3 id="danger-anchors-count">-</h3>
                    <p>🔴 Over-Optimized</p>
                </div>
            </div>
            
            <!-- Anchor Usage Table -->
            <div id="anchor-report-table-container">
                <table class="wp-list-table widefat fixed striped" id="anchor-report-table">
                    <thead>
                        <tr>
                            <th style="width: 5%;">Status</th>
                            <th style="width: 30%;">Anchor Text</th>
                            <th style="width: 10%;">Usage Count</th>
                            <th style="width: 10%;">Percentage</th>
                            <th style="width: 15%;">Health Score</th>
                            <th style="width: 20%;">Used In Posts</th>
                            <th style="width: 10%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="anchor-report-tbody">
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 40px;">
                                <p>Loading anchor data...</p>
                                <p><button id="load-anchor-report" class="button button-primary">Load Anchor Report</button></p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Anchor Details Modal -->
            <div id="anchor-details-modal" class="ssp-modal-overlay" style="display: none;" role="dialog" aria-modal="true">
                <div class="ssp-modal-content" style="max-width: 900px;" tabindex="-1" aria-labelledby="anchor-modal-title">
                    <div class="ssp-modal-header">
                        <h2 id="anchor-modal-title">Anchor Details</h2>
                        <button type="button" class="ssp-modal-close" aria-label="Close">&times;</button>
                    </div>
                    <div class="ssp-modal-body" id="anchor-modal-body">
                        <!-- Details loaded via AJAX -->
                    </div>
                    <div class="ssp-modal-footer">
                        <button type="button" class="button button-primary ssp-modal-dismiss">Close</button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render exclusions
     */
    private function render_exclusions() {
        global $wpdb;
        ?>
        <div class="ssp-exclusions">
            <h3>Exclusions</h3>
            <p>Manage posts and anchor text to exclude from linking.</p>
            
            <div class="ssp-form-section">
                <h4>Excluded Posts</h4>
                <p class="description">Posts excluded from being linked to in any silo.</p>
                
                <div style="margin-bottom: 15px;">
                    <select id="exclude-post-select" class="ssp-select" style="width: 300px;">
                        <option value="">Select a post to exclude...</option>
                        <?php
                        $all_posts = get_posts(array(
                            'post_type' => array('post', 'page'),
                            'post_status' => 'publish',
                            'numberposts' => -1,
                            'orderby' => 'title',
                            'order' => 'ASC'
                        ));
                        foreach ($all_posts as $post) {
                            echo '<option value="' . esc_attr($post->ID) . '">' . esc_html($post->post_title) . '</option>';
                        }
                        ?>
                    </select>
                    <button id="add-post-exclusion" class="button button-secondary">Add Exclusion</button>
                </div>
                
                <div id="excluded-posts-list" class="exclusions-list">
                    <?php
                    $excluded_posts = $wpdb->get_results($wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}ssp_excluded_items WHERE item_type = %s ORDER BY created_at DESC",
                        'post'
                    ));
                    
                    if (!empty($excluded_posts)) {
                        echo '<table class="wp-list-table widefat">';
                        echo '<thead><tr><th>Post Title</th><th>Post ID</th><th>Action</th></tr></thead>';
                        echo '<tbody>';
                        foreach ($excluded_posts as $item) {
                            $post = get_post($item->item_value);
                            if ($post) {
                                echo '<tr>';
                                echo '<td>' . esc_html($post->post_title) . '</td>';
                                echo '<td>#' . esc_html($item->item_value) . '</td>';
                                echo '<td><button class="button button-small ssp-remove-exclusion" data-type="post" data-value="' . esc_attr($item->item_value) . '">Remove</button></td>';
                                echo '</tr>';
                            }
                        }
                        echo '</tbody></table>';
                    } else {
                        echo '<p class="description">No posts excluded yet.</p>';
                    }
                    ?>
                </div>
            </div>
            
            <div class="ssp-form-section">
                <h4>Excluded Anchor Text</h4>
                <p class="description">Phrases that will never be used as anchor text.</p>
                
                <div style="margin-bottom: 15px;">
                    <input type="text" id="exclude-anchor-input" placeholder="Enter phrase to exclude (e.g., click here, read more)" class="regular-text" style="width: 300px;">
                    <button id="add-anchor-exclusion" class="button button-secondary">Add Exclusion</button>
                </div>
                
                <div id="excluded-anchors-list" class="exclusions-list">
                    <?php
                    $excluded_anchors = $wpdb->get_results($wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}ssp_excluded_items WHERE item_type = %s ORDER BY created_at DESC",
                        'anchor'
                    ));
                    
                    if (!empty($excluded_anchors)) {
                        echo '<table class="wp-list-table widefat">';
                        echo '<thead><tr><th>Phrase</th><th>Action</th></tr></thead>';
                        echo '<tbody>';
                        foreach ($excluded_anchors as $item) {
                            echo '<tr>';
                            echo '<td>"' . esc_html($item->item_value) . '"</td>';
                            echo '<td><button class="button button-small ssp-remove-exclusion" data-type="anchor" data-value="' . esc_attr($item->item_value) . '">Remove</button></td>';
                            echo '</tr>';
                        }
                        echo '</tbody></table>';
                    } else {
                        echo '<p class="description">No anchor text excluded yet. Common exclusions: "click here", "read more", "contact"</p>';
                    }
                    ?>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render anchor management page
     */
    private function render_anchor_management() {
        ?>
        <div class="ssp-anchor-management">
            <h3>Anchor Management - Preview & Regenerate Anchors</h3>
            <p class="description">View all anchors for a silo and regenerate them using AI. Select a silo to see all links and their anchor texts.</p>
            
            <!-- Silo Selector -->
            <div class="ssp-form-section" style="margin-bottom: 20px;">
                <label style="margin-right: 15px;">
                    <strong>Select Silo:</strong>
                    <select id="anchor-mgmt-silo-select" class="ssp-select" style="min-width: 300px;">
                        <option value="">-- Select a Silo --</option>
                        <?php
                        $silos = SSP_Database::get_silos();
                        foreach ($silos as $silo) {
                            echo '<option value="' . esc_attr($silo->id) . '">' . esc_html($silo->name) . '</option>';
                        }
                        ?>
                    </select>
                </label>
                <button id="load-anchor-management" class="button button-primary" disabled>Load Anchors</button>
            </div>
            
            <!-- Loading State -->
            <div id="anchor-mgmt-loading" style="display: none; text-align: center; padding: 40px;">
                <p>Loading anchors...</p>
            </div>
            
            <!-- Anchor List Table -->
            <div id="anchor-mgmt-content" style="display: none;">
                <div class="ssp-form-section">
                    <table class="wp-list-table widefat fixed striped" id="anchor-management-table">
                        <thead>
                            <tr>
                                <th style="width: 20%;">Source Post</th>
                                <th style="width: 20%;">Target Post</th>
                                <th style="width: 25%;">Current Anchor Text</th>
                                <th style="width: 15%;">Link ID</th>
                                <th style="width: 20%;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="anchor-mgmt-tbody">
                            <!-- Content loaded via AJAX -->
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- AI Suggestions Modal -->
            <div id="anchor-suggestions-modal" class="ssp-modal-overlay" style="display: none;" role="dialog" aria-modal="true">
                <div class="ssp-modal-content" style="max-width: 700px;" tabindex="-1" aria-labelledby="suggestions-modal-title">
                    <div class="ssp-modal-header">
                        <h2 id="suggestions-modal-title">AI Anchor Suggestions</h2>
                        <button type="button" class="ssp-modal-close" aria-label="Close">&times;</button>
                    </div>
                    <div class="ssp-modal-body" id="suggestions-modal-body">
                        <p id="suggestions-loading" style="text-align: center; padding: 20px;">Generating AI suggestions...</p>
                        <div id="suggestions-list" style="display: none;">
                            <!-- AI suggestions will be shown here -->
                        </div>
                    </div>
                    <div class="ssp-modal-footer">
                        <button type="button" class="button ssp-modal-dismiss">Cancel</button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render orphan posts page
     */
    private function render_orphan_posts() {
        global $wpdb;
        
        // Get orphan posts
        $orphan_posts = SSP_Database::get_orphan_posts(100, 0);
        $total_orphans = SSP_Database::get_orphan_posts_count();
        
        // Get all silos for assignment dropdown (use high limit to get all)
        $silos = SSP_Database::get_silos(10000, 0);
        ?>
        <div class="ssp-orphan-posts">
            <h3>Orphan Posts & Pages</h3>
            <p class="description">
                Posts and pages that are not assigned to any silo. These may be posts that were recommended by AI but not selected, or posts that were never assigned to a silo.
            </p>
            
            <!-- Statistics -->
            <div class="ssp-form-section" style="background: #f9f9f9; padding: 15px; margin-bottom: 20px; border-left: 3px solid #0073aa;">
                <h4>📊 Statistics</h4>
                <p style="margin: 0;">
                    <strong>Total Orphan Posts:</strong> <span id="ssp-orphan-total"><?php echo esc_html($total_orphans); ?></span>
                    (Posts and pages not in any silo)
                </p>
            </div>
            
            <!-- Bulk Actions -->
            <div class="ssp-form-section" style="background: #fff; padding: 15px; margin-bottom: 20px; border: 1px solid #ddd;">
                <h4>⚡ Bulk Actions</h4>
                <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                    <label>
                        <strong>Assign Selected to Silo:</strong>
                        <select id="assign-orphan-silo-select" class="ssp-select" style="margin-left: 10px; width: 250px;">
                            <option value="">Select a silo...</option>
                            <?php foreach ($silos as $silo): ?>
                                <option value="<?php echo esc_attr($silo->id); ?>">
                                    <?php echo esc_html($silo->name); ?> (ID: <?php echo esc_html($silo->id); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="button" id="assign-orphans-btn" class="button button-primary" disabled>Assign Selected Posts</button>
                    <button type="button" id="select-all-orphans-btn" class="button">Select All</button>
                    <button type="button" id="deselect-all-orphans-btn" class="button">Deselect All</button>
                </div>
            </div>
            
            <!-- Orphan Posts List -->
            <div class="ssp-form-section">
                <?php if (empty($orphan_posts)): ?>
                    <div class="ssp-empty-state">
                        <p>🎉 Great! No orphan posts found. All your posts are assigned to silos.</p>
                    </div>
                <?php else: ?>
                    <?php 
                    // Separate pages and posts
                    $front_page_id = intval(get_option('page_on_front'));
                    $pages = array();
                    $posts_only = array();
                    foreach ($orphan_posts as $post) {
                        if ($post->post_type === 'page') {
                            $pages[] = $post;
                        } else {
                            $posts_only[] = $post;
                        }
                    }
                    ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width: 40px;">
                                    <input type="checkbox" id="select-all-orphans" />
                                </th>
                                <th style="width: 70px;">ID</th>
                                <th style="width: 100px;">Type</th>
                                <th style="min-width: 300px;">Title</th>
                                <th style="width: 120px;">Post Date</th>
                                <th style="width: 130px;">Author</th>
                                <th style="width: 280px; min-width: 260px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="orphan-posts-tbody">
                            <?php if (!empty($pages)): ?>
                                <!-- Pages Section Header -->
                                <tr class="ssp-section-header">
                                    <td colspan="7" style="font-weight: 600; padding: 8px 15px;">
                                        Pages (<?php echo count($pages); ?>)
                                    </td>
                                </tr>
                                <?php foreach ($pages as $post): ?>
                                    <?php
                                    $author = get_userdata($post->post_author);
                                    $post_type_obj = get_post_type_object($post->post_type);
                                    $post_type_label = ($post_type_obj && isset($post_type_obj->labels->singular_name)) 
                                        ? $post_type_obj->labels->singular_name 
                                        : ucfirst($post->post_type);
                                    $is_home = ($front_page_id && $post->ID == $front_page_id);
                                    ?>
                                    <tr data-post-id="<?php echo esc_attr($post->ID); ?>">
                                        <td>
                                            <input type="checkbox" class="orphan-post-checkbox" value="<?php echo esc_attr($post->ID); ?>" />
                                        </td>
                                        <td><strong>#<?php echo esc_html($post->ID); ?></strong></td>
                                        <td>
                                            <?php echo esc_html($post_type_label . ($is_home ? ' (Home)' : '')); ?>
                                        </td>
                                        <td>
                                            <strong>
                                                <a href="<?php echo esc_url(get_edit_post_link($post->ID)); ?>" target="_blank">
                                                    <?php echo esc_html($post->post_title ?: '(No Title)'); ?>
                                                </a>
                                            </strong>
                                            <?php if (!$post->post_title): ?>
                                                <span style="color: #999; font-style: italic;">(No Title)</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php 
                                            $post_date = !empty($post->post_date) ? strtotime($post->post_date) : false;
                                            echo $post_date ? esc_html(date('Y-m-d', $post_date)) : 'N/A';
                                        ?></td>
                                        <td><?php echo $author ? esc_html($author->display_name) : 'Unknown'; ?></td>
                                        <td>
                                            <div class="ssp-orphan-actions">
                                                <select class="assign-single-orphan-silo" data-post-id="<?php echo esc_attr($post->ID); ?>">
                                                    <option value="">Assign to silo...</option>
                                                    <?php foreach ($silos as $silo): ?>
                                                        <option value="<?php echo esc_attr($silo->id); ?>">
                                                            <?php echo esc_html($silo->name); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="button" class="button button-small assign-single-orphan-btn" 
                                                        data-post-id="<?php echo esc_attr($post->ID); ?>" 
                                                        style="display: none;">
                                                    Assign
                                                </button>
                                                <a href="<?php echo esc_url(get_permalink($post->ID)); ?>" 
                                                   target="_blank" 
                                                   class="button button-small" 
                                                   title="View Post">
                                                    👁️ View
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            
                            <?php if (!empty($posts_only)): ?>
                                <!-- Posts Section Header -->
                                <tr class="ssp-section-header">
                                    <td colspan="7" style="font-weight: 600; padding: 8px 15px;">
                                        Posts (<?php echo count($posts_only); ?>)
                                    </td>
                                </tr>
                                <?php foreach ($posts_only as $post): ?>
                                    <?php
                                    $author = get_userdata($post->post_author);
                                    $post_type_obj = get_post_type_object($post->post_type);
                                    $post_type_label = ($post_type_obj && isset($post_type_obj->labels->singular_name)) 
                                        ? $post_type_obj->labels->singular_name 
                                        : ucfirst($post->post_type);
                                    ?>
                                    <tr data-post-id="<?php echo esc_attr($post->ID); ?>">
                                        <td>
                                            <input type="checkbox" class="orphan-post-checkbox" value="<?php echo esc_attr($post->ID); ?>" />
                                        </td>
                                        <td><strong>#<?php echo esc_html($post->ID); ?></strong></td>
                                        <td>
                                            <?php echo esc_html($post_type_label); ?>
                                        </td>
                                        <td>
                                            <strong>
                                                <a href="<?php echo esc_url(get_edit_post_link($post->ID)); ?>" target="_blank">
                                                    <?php echo esc_html($post->post_title ?: '(No Title)'); ?>
                                                </a>
                                            </strong>
                                            <?php if (!$post->post_title): ?>
                                                <span style="color: #999; font-style: italic;">(No Title)</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php 
                                            $post_date = !empty($post->post_date) ? strtotime($post->post_date) : false;
                                            echo $post_date ? esc_html(date('Y-m-d', $post_date)) : 'N/A';
                                        ?></td>
                                        <td><?php echo $author ? esc_html($author->display_name) : 'Unknown'; ?></td>
                                        <td>
                                            <div class="ssp-orphan-actions">
                                                <select class="assign-single-orphan-silo" data-post-id="<?php echo esc_attr($post->ID); ?>">
                                                    <option value="">Assign to silo...</option>
                                                    <?php foreach ($silos as $silo): ?>
                                                        <option value="<?php echo esc_attr($silo->id); ?>">
                                                            <?php echo esc_html($silo->name); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="button" class="button button-small assign-single-orphan-btn" 
                                                        data-post-id="<?php echo esc_attr($post->ID); ?>" 
                                                        style="display: none;">
                                                    Assign
                                                </button>
                                                <a href="<?php echo esc_url(get_permalink($post->ID)); ?>" 
                                                   target="_blank" 
                                                   class="button button-small" 
                                                   title="View Post">
                                                    👁️ View
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    
                    <?php if ($total_orphans > 100): ?>
                        <p class="description" style="margin-top: 15px;">
                            ⚠️ Showing first 100 orphan posts. Total: <?php echo esc_html($total_orphans); ?> posts.
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render troubleshoot page
     */
    private function render_troubleshoot() {
        ?>
        <div class="ssp-troubleshoot">
            <h3>Database Troubleshooting</h3>
            <p>If you're experiencing issues with silo creation or link generation, try these troubleshooting steps:</p>
            
            <div class="ssp-form-section">
                <h4>Recreate Database Tables</h4>
                <p>This will drop and recreate all plugin database tables. <strong>Warning:</strong> This will delete all existing silos and links.</p>
                <button id="recreate-tables" class="button button-secondary">Recreate Tables</button>
                <div id="recreate-tables-result" style="margin-top: 10px;"></div>
            </div>
            
            <div class="ssp-form-section">
                <h4>Check Database Tables</h4>
                <p>Verify that all required database tables exist:</p>
                <button id="check-tables" class="button">Check Tables</button>
                <div id="check-tables-result" style="margin-top: 10px;"></div>
            </div>
            
            <div class="ssp-form-section">
                <h4>Debug Report</h4>
                <p>View recent plugin activity and error logs:</p>
                <button id="view-debug-report" class="button button-primary">📋 View Debug Report</button>
                <button id="download-debug-report" class="button">📥 Download Debug Report</button>
                <button id="clear-debug-logs" class="button button-secondary">🗑️ Clear All Logs</button>
                <div id="debug-report-container" style="margin-top: 15px; display: none;">
                    <!-- Debug report will be loaded here -->
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Add pillar page column to posts/pages admin lists
     */
    public function add_pillar_column($columns) {
        // Insert after title column
        $new_columns = array();
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'title') {
                $new_columns['ssp_pillar'] = 'Pillar Page';
            }
        }
        return $new_columns;
    }
    
    /**
     * Display pillar page indicator in admin list
     */
    public function display_pillar_column($column, $post_id) {
        if ($column === 'ssp_pillar') {
            // Optimize: Get silo info in one query instead of two
            $silo_info = SSP_Database::get_pillar_silo_info($post_id);
            if (!empty($silo_info['silos'])) {
                $tooltip = isset($silo_info['tooltip']) ? $silo_info['tooltip'] : 'Pillar page';
                echo '<span class="ssp-pillar-badge" title="' . esc_attr($tooltip) . '">';
                echo '<span class="ssp-pillar-badge-icon">📌</span>';
                echo '<span class="ssp-pillar-badge-text">Pillar Page</span>';
                echo '</span>';
            } else {
                echo '—';
            }
        }
    }
    
    /**
     * Add CSS styles for pillar page badge
     */
    public function add_pillar_column_styles() {
        $screen = get_current_screen();
        // Check for both post and page list screens (screen IDs start with 'edit-')
        if ($screen && (strpos($screen->id, 'edit-post') === 0 || strpos($screen->id, 'edit-page') === 0)) {
            ?>
            <style>
                .ssp-pillar-badge {
                    display: inline-flex;
                    align-items: center;
                    gap: 6px;
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    color: #fff;
                    padding: 4px 12px;
                    border-radius: 12px;
                    font-size: 12px;
                    font-weight: 500;
                    line-height: 1.4;
                    white-space: nowrap;
                    box-shadow: 0 2px 4px rgba(102, 126, 234, 0.2);
                }
                
                .ssp-pillar-badge-icon {
                    font-size: 14px;
                    line-height: 1;
                }
                
                .ssp-pillar-badge-text {
                    font-size: 11px;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                }
                
                .column-ssp_pillar {
                    width: 140px;
                }
                
                /* Hover effect */
                .ssp-pillar-badge:hover {
                    box-shadow: 0 3px 6px rgba(102, 126, 234, 0.3);
                    transform: translateY(-1px);
                    transition: all 0.2s ease;
                }
            </style>
            <?php
        }
    }
}