<?php
/**
 * Plugin Name: ContentGuard - AI Bot Detection (Enhanced)
 * Plugin URI: https://contentguard.ai
 * Description: Detect and track AI bots scraping your content with industry-accurate valuation. See which AI companies are using your work for training and get paid for it.
 * Version: 2.0.0
 * Author: ContentGuard
 * License: GPL v2 or later
 * Text Domain: contentguard
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('CONTENTGUARD_VERSION', '2.0.0');
define('CONTENTGUARD_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CONTENTGUARD_PLUGIN_PATH', plugin_dir_path(__FILE__));

// Include the enhanced valuation system
require_once CONTENTGUARD_PLUGIN_PATH . 'includes/LicensingMarketData.php';
require_once CONTENTGUARD_PLUGIN_PATH . 'includes/ContentValueCalculator.php';
require_once CONTENTGUARD_PLUGIN_PATH . 'includes/ContentAnalyzer.php';
require_once CONTENTGUARD_PLUGIN_PATH . 'includes/ContentValueIntegration.php';

// Include plugin components
require_once CONTENTGUARD_PLUGIN_PATH . 'includes/class-contentguard-core.php';
require_once CONTENTGUARD_PLUGIN_PATH . 'includes/class-contentguard-admin.php';
require_once CONTENTGUARD_PLUGIN_PATH . 'includes/class-contentguard-ajax.php';
require_once CONTENTGUARD_PLUGIN_PATH . 'includes/class-contentguard-api.php';
require_once CONTENTGUARD_PLUGIN_PATH . 'includes/class-contentguard-widgets.php';
require_once CONTENTGUARD_PLUGIN_PATH . 'includes/class-contentguard-cli.php';

class ContentGuardPlugin {
    
    /**
     * Plugin components
     */
    private $core;
    private $admin;
    private $ajax;
    private $api;
    private $widgets;

    public function __construct() {
        add_action('init', [$this, 'init']);
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }

    public function init() {
        // Initialize plugin components
        $this->core = new ContentGuard_Core();
        $this->admin = new ContentGuard_Admin();
        $this->ajax = new ContentGuard_AJAX();
        $this->api = new ContentGuard_API();
        $this->widgets = new ContentGuard_Widgets();
        
        // Initialize components
        $this->core->init();
        $this->admin->init();
        $this->ajax->init();
        $this->api->init();
        $this->widgets->init();
        
        // Cron for cleanup old logs
        add_action('contentguard_cleanup_logs', [$this->core, 'cleanup_old_logs']);
        if (!wp_next_scheduled('contentguard_cleanup_logs')) {
            wp_schedule_event(time(), 'weekly', 'contentguard_cleanup_logs');
        }
    }

    public function activate() {
        $this->core->activate();
    }

    public function deactivate() {
        wp_clear_scheduled_hook('contentguard_cleanup_logs');
    }
}

// Initialize the enhanced plugin
new ContentGuardPlugin();

/**
 * Helper functions for enhanced features using our value system
 */

/**
 * Get enhanced content valuation for any detection
 */
function contentguard_get_content_value($detection_data, $content_metadata = []) {
    static $value_calculator = null;
    static $content_analyzer = null;
    
    if ($value_calculator === null) {
        $value_calculator = new ContentGuardValueCalculator();
        $content_analyzer = new ContentGuardContentAnalyzer();
    }
    
    if (empty($content_metadata) && !empty($detection_data['request_uri'])) {
        $content_metadata = $content_analyzer->analyzeContent($detection_data['request_uri']);
    }
    
    return $value_calculator->calculateContentValue($detection_data, $content_metadata);
}

/**
 * Get portfolio analysis for all detections
 */
function contentguard_get_portfolio_analysis($detections = null) {
    static $value_calculator = null;
    
    if ($value_calculator === null) {
        $value_calculator = new ContentGuardValueCalculator();
    }
    
    if ($detections === null) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'contentguard_detections';
        $detections = $wpdb->get_results("SELECT * FROM $table_name ORDER BY detected_at DESC", ARRAY_A);
    }
    
    return $value_calculator->calculatePortfolioValue($detections);
}

/**
 * Enhanced activation message
 */
function contentguard_enhanced_activation_notice() {
    if (get_transient('contentguard_enhanced_activated')) {
        ?>
        <div class="notice notice-success is-dismissible">
            <h3>🎉 ContentGuard Enhanced v2.0 Activated!</h3>
            <p><strong>New Features:</strong></p>
            <ul style="margin-left: 20px;">
                <li>✅ Industry-accurate content valuation (Getty Images, Music, Academic, News rates)</li>
                <li>✅ Enhanced AI company detection (OpenAI, Anthropic, Google, Meta, Microsoft, etc.)</li>
                <li>✅ Content analysis and quality scoring</li>
                <li>✅ Portfolio analysis and licensing recommendations</li>
                <li>✅ Market-based pricing using real licensing data</li>
            </ul>
            <p>
                <a href="<?php echo admin_url('admin.php?page=contentguard'); ?>" class="button button-primary">
                    View Enhanced Dashboard
                </a>
                <a href="<?php echo admin_url('admin.php?page=contentguard-valuation'); ?>" class="button">
                    Valuation Report
                </a>
            </p>
        </div>
        <?php
        delete_transient('contentguard_enhanced_activated');
    }
}
add_action('admin_notices', 'contentguard_enhanced_activation_notice');

/**
 * Set activation notice on plugin activation
 */
function contentguard_set_activation_notice() {
    set_transient('contentguard_enhanced_activated', true, 60);
}
register_activation_hook(__FILE__, 'contentguard_set_activation_notice');

/**
 * Admin notice for high-value detections
 */
function contentguard_enhanced_admin_notice() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    $screen = get_current_screen();
    if ($screen && strpos($screen->id, 'contentguard') !== false) {
        return; // Don't show on ContentGuard pages
    }
    
    $high_value_count = get_transient('contentguard_recent_high_value');
    if ($high_value_count === false) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'contentguard_detections';
        $high_value_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM $table_name WHERE estimated_value >= 50.00 AND detected_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        
        if ($high_value_count > 0) {
            set_transient('contentguard_recent_high_value', $high_value_count, HOUR_IN_SECONDS);
            ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <strong>ContentGuard Alert:</strong> 
                    <?php echo $high_value_count; ?> high-value AI bot detections this week! 
                    Your content represents significant licensing revenue potential.
                    <a href="<?php echo admin_url('admin.php?page=contentguard'); ?>" class="button button-primary" style="margin-left: 10px;">
                        View Dashboard
                    </a>
                </p>
            </div>
            <?php
        }
    }
}
add_action('admin_notices', 'contentguard_enhanced_admin_notice');

/**
 * Database Update Function for ContentGuard
 * Run this to add missing columns to existing installations
 */

 function contentguard_update_database_schema() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'contentguard_detections';
    
    // Check if table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
    
    if (!$table_exists) {
        // Table doesn't exist, create it with all columns
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_agent text NOT NULL,
            ip_address varchar(45) NOT NULL,
            request_uri text NOT NULL,
            bot_type varchar(100),
            company varchar(100),
            risk_level varchar(20),
            confidence tinyint,
            commercial_risk tinyint(1) DEFAULT 0,
            estimated_value decimal(10,2) DEFAULT 0.00,
            content_type varchar(50) DEFAULT 'article',
            content_quality tinyint DEFAULT 50,
            word_count int DEFAULT 0,
            technical_depth varchar(20) DEFAULT 'basic',
            licensing_potential varchar(20) DEFAULT 'low',
            value_breakdown text,
            market_context text,
            detected_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY ip_address (ip_address),
            KEY detected_at (detected_at),
            KEY bot_type (bot_type),
            KEY company (company),
            KEY estimated_value (estimated_value),
            KEY licensing_potential (licensing_potential)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        return "Created new table with all columns.";
    }
    
    // Table exists, check for missing columns and add them
    $columns_to_add = [
        'estimated_value' => 'DECIMAL(10,2) DEFAULT 0.00',
        'content_type' => 'VARCHAR(50) DEFAULT "article"',
        'content_quality' => 'TINYINT DEFAULT 50',
        'word_count' => 'INT DEFAULT 0',
        'technical_depth' => 'VARCHAR(20) DEFAULT "basic"',
        'licensing_potential' => 'VARCHAR(20) DEFAULT "low"',
        'value_breakdown' => 'TEXT',
        'market_context' => 'TEXT'
    ];
    
    $added_columns = [];
    
    foreach ($columns_to_add as $column_name => $column_definition) {
        // Check if column exists
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE '$column_name'");
        
        if (empty($column_exists)) {
            // Add the missing column
            $sql = "ALTER TABLE $table_name ADD COLUMN $column_name $column_definition";
            $result = $wpdb->query($sql);
            
            if ($result !== false) {
                $added_columns[] = $column_name;
            }
        }
    }
    
    // Add indexes for new columns
    $indexes_to_add = [
        'idx_estimated_value' => 'estimated_value',
        'idx_content_type' => 'content_type',
        'idx_licensing_potential' => 'licensing_potential'
    ];
    
    foreach ($indexes_to_add as $index_name => $column_name) {
        // Check if index exists
        $index_exists = $wpdb->get_results("SHOW INDEX FROM $table_name WHERE Key_name = '$index_name'");
        
        if (empty($index_exists)) {
            $sql = "CREATE INDEX $index_name ON $table_name($column_name)";
            $wpdb->query($sql);
        }
    }
    
    if (!empty($added_columns)) {
        return "Added columns: " . implode(', ', $added_columns);
    } else {
        return "All columns already exist.";
    }
}

// Add this to WordPress admin for easy execution
function contentguard_add_db_update_admin_notice() {
    if (current_user_can('manage_options') && isset($_GET['contentguard_update_db'])) {
        $result = contentguard_update_database_schema();
        echo '<div class="notice notice-success"><p><strong>ContentGuard Database Update:</strong> ' . $result . '</p></div>';
    }
}
add_action('admin_notices', 'contentguard_add_db_update_admin_notice');

// Add update link to admin menu
function contentguard_add_update_db_link() {
    if (current_user_can('manage_options')) {
        $current_url = admin_url('admin.php?page=contentguard&contentguard_update_db=1');
        echo '<div class="notice notice-info"><p><strong>ContentGuard:</strong> Missing database columns detected. <a href="' . $current_url . '" class="button">Update Database Schema</a></p></div>';
    }
}

// Check if update is needed and show notice
function contentguard_check_db_update_needed() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'contentguard_detections';
    
    // Check if estimated_value column exists
    $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'estimated_value'");
    
    if (empty($column_exists)) {
        add_action('admin_notices', 'contentguard_add_update_db_link');
    }
}
add_action('admin_init', 'contentguard_check_db_update_needed');
?>