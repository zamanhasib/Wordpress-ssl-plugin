<?php
/**
 * Plugin Name: Semantic Silo Pro
 * Plugin URI: https://yoursite.com/semantic-silo-pro
 * Description: Build SEO-friendly content silos by connecting pillar pages with related posts using AI-powered recommendations and automated linking.
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL v2 or later
 * Text Domain: semantic-silo-pro
 * Domain Path: /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('SSP_VERSION', '1.0.0');
define('SSP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SSP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SSP_PLUGIN_FILE', __FILE__);

// Include required files
require_once SSP_PLUGIN_DIR . 'includes/class-database.php';
require_once SSP_PLUGIN_DIR . 'includes/class-ai-integration.php';
require_once SSP_PLUGIN_DIR . 'includes/class-silo-manager.php';
require_once SSP_PLUGIN_DIR . 'includes/class-link-engine.php';
require_once SSP_PLUGIN_DIR . 'includes/class-admin-interface.php';
require_once SSP_PLUGIN_DIR . 'includes/class-ajax-handler.php';
require_once SSP_PLUGIN_DIR . 'includes/class-logger.php';

/**
 * Main plugin class
 */
class Semantic_Silo_Pro {
    
    /**
     * Single instance of the plugin
     */
    private static $instance = null;
    
    /**
     * Get single instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        add_action('init', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        register_uninstall_hook(__FILE__, array('Semantic_Silo_Pro', 'uninstall'));
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        // Load text domain
        load_plugin_textdomain('semantic-silo-pro', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
        // Initialize components
        $this->init_components();
    }
    
    /**
     * Initialize plugin components
     */
    private function init_components() {
        // Initialize database
        SSP_Database::get_instance();
        
        // Initialize logger
        $logger = SSP_Logger::get_instance();
        
        // Initialize AI integration
        SSP_AI_Integration::get_instance();
        
        // Initialize silo manager
        SSP_Silo_Manager::get_instance();
        
        // Initialize link engine
        SSP_Link_Engine::get_instance();
        
        // Initialize admin interface
        if (is_admin()) {
            SSP_Admin_Interface::get_instance();
            SSP_Ajax_Handler::get_instance();
        }
        
        // Log plugin initialization
        if ($logger && method_exists($logger, 'info')) {
            $logger->info('Semantic Silo Pro initialized', array(
                'version' => SSP_VERSION,
                'wp_version' => get_bloginfo('version')
            ));
        }
        
        // Add WordPress hooks
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_head', array($this, 'add_meta_tags'));
        
        // Add multisite support
        if (is_multisite()) {
            add_action('wpmu_new_blog', array($this, 'activate_new_site'));
        }
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Create database tables
        SSP_Database::create_tables();
        
        // Set default options
        $this->set_default_options();
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin uninstall
     */
    public static function uninstall() {
        // Drop database tables
        SSP_Database::drop_tables();
        
        // Remove plugin options
        delete_option('ssp_settings');
        
        // Clear any transients
        global $wpdb;
        if ($wpdb->options) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_ssp_%'));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_timeout_ssp_%'));
        }
    }
    
    /**
     * Enqueue frontend scripts
     */
    public function enqueue_frontend_scripts() {
        // Only enqueue if needed
        if (is_singular()) {
            wp_enqueue_script('ssp-frontend', SSP_PLUGIN_URL . 'assets/js/frontend.js', array('jquery'), SSP_VERSION, true);
            wp_enqueue_style('ssp-frontend', SSP_PLUGIN_URL . 'assets/css/frontend.css', array(), SSP_VERSION);
            
            // Localize frontend script
            wp_localize_script('ssp-frontend', 'ssp_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('ssp_nonce'),
                'post_id' => get_the_ID()
            ));
        }
    }
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        // Only enqueue on plugin pages
        if (strpos($hook, 'semantic-silo-pro') !== false) {
            wp_enqueue_script('ssp-admin', SSP_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), SSP_VERSION, true);
            wp_enqueue_style('ssp-admin', SSP_PLUGIN_URL . 'assets/css/admin.css', array(), SSP_VERSION);
            
            wp_localize_script('ssp-admin', 'ssp_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('ssp_nonce'),
                'strings' => array(
                    'processing' => __('Processing...', 'semantic-silo-pro'),
                    'error' => __('An error occurred', 'semantic-silo-pro'),
                    'success' => __('Operation completed successfully', 'semantic-silo-pro')
                )
            ));
        }
    }
    
    /**
     * Add meta tags for SEO (cached for performance)
     */
    public function add_meta_tags() {
        if (!is_singular()) {
            return;
        }
        
        global $post;
        if (!$post || !$post->ID) {
            return;
        }
        
        // Cache meta tags for performance
        $cache_key = 'ssp_meta_tags_' . $post->ID;
        $cached_meta = get_transient($cache_key);
        
        if ($cached_meta !== false) {
            echo $cached_meta;
            return;
        }
        
        $silos = SSP_Database::get_silos_for_post($post->ID);
        
        if (!empty($silos)) {
            $meta_tag = '<meta name="semantic-silo" content="' . esc_attr(implode(',', $silos)) . '">' . "\n";
            echo $meta_tag;
            
            // Cache for 1 hour
            set_transient($cache_key, $meta_tag, 3600);
        }
    }
    
    /**
     * Activate plugin on new multisite blog
     */
    public function activate_new_site($blog_id) {
        if (is_plugin_active_for_network(plugin_basename(__FILE__))) {
            switch_to_blog($blog_id);
            $this->activate();
            restore_current_blog();
        }
    }
    
    /**
     * Set default plugin options
     */
    private function set_default_options() {
        $defaults = array(
            'ai_provider' => 'openai',
            'ai_model' => 'gpt-3.5-turbo',
            'max_links_per_post' => 3,
            'link_placement' => 'inline',
            'auto_assign_category' => false,
            'excluded_posts' => array(),
            'excluded_anchors' => array('click here', 'read more', 'contact'),
            'supports_to_pillar' => true,
            'pillar_to_supports' => false
        );
        
        add_option('ssp_settings', $defaults);
    }
}

// Initialize the plugin
function semantic_silo_pro() {
    return Semantic_Silo_Pro::get_instance();
}

// Start the plugin
semantic_silo_pro();