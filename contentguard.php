<?php
/**
 * Plugin Name: ContentGuard - AI Bot Detection (Enhanced)
 * Plugin URI: https://contentguard.ai
 * Description: Detect and track AI bots scraping your content with industry-accurate valuation.
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

// Force debug logging
error_log("=== ContentGuard Debug: Starting plugin load ===");

// Function to safely test and include files
function contentguard_test_and_include($file) {
    $file_path = CONTENTGUARD_PLUGIN_PATH . $file;
    
    error_log("ContentGuard: Testing file $file");
    
    if (!file_exists($file_path)) {
        error_log("ContentGuard: File MISSING: $file_path");
        return false;
    }
    
    // Test file syntax by checking for basic PHP structure
    $content = file_get_contents($file_path);
    
    // Check for opening PHP tag
    if (strpos($content, '<?php') === false) {
        error_log("ContentGuard: File $file missing opening <?php tag");
        return false;
    }
    
    // Check for problematic closing tag
    if (substr(trim($content), -2) === '?>') {
        error_log("ContentGuard: WARNING - File $file has closing ?> tag (should be removed)");
    }
    
    // Check for class definitions to avoid redeclaration
    if (preg_match('/class\s+(\w+)/', $content, $matches)) {
        $class_name = $matches[1];
        if (class_exists($class_name)) {
            error_log("ContentGuard: ERROR - Class $class_name already exists (from $file)");
            return false;
        }
    }
    
    try {
        require_once $file_path;
        error_log("ContentGuard: Successfully included $file");
        return true;
    } catch (ParseError $e) {
        error_log("ContentGuard: PARSE ERROR in $file: " . $e->getMessage());
        return false;
    } catch (Error $e) {
        error_log("ContentGuard: FATAL ERROR in $file: " . $e->getMessage());
        return false;
    } catch (Exception $e) {
        error_log("ContentGuard: EXCEPTION in $file: " . $e->getMessage());
        return false;
    }
}

// Test files in order of dependency
$files_to_test = [
    // Core data files first
    'includes/LicensingMarketData.php',
    'includes/ContentValueCalculator.php',
    'includes/ContentAnalyzer.php',
    
    // Integration after core
    'includes/ContentValueIntegration.php',
    
    // WordPress classes
    'includes/class-contentguard-core.php',
    'includes/class-contentguard-admin.php',
    'includes/class-contentguard-ajax.php',
    'includes/class-contentguard-api.php',
    'includes/class-contentguard-widgets.php',
    'includes/class-contentguard-cli.php'
];

$successful_includes = [];
$failed_includes = [];

foreach ($files_to_test as $file) {
    if (contentguard_test_and_include($file)) {
        $successful_includes[] = $file;
    } else {
        $failed_includes[] = $file;
        error_log("ContentGuard: STOPPING - Failed to include $file");
        break; // Stop at first failure
    }
}

error_log("ContentGuard: Successful includes: " . implode(', ', $successful_includes));
if (!empty($failed_includes)) {
    error_log("ContentGuard: Failed includes: " . implode(', ', $failed_includes));
}

// Only initialize if we have the minimum required files
if (in_array('includes/class-contentguard-core.php', $successful_includes) && 
    in_array('includes/class-contentguard-admin.php', $successful_includes)) {
    
    class ContentGuardPlugin {
        
        private $core;
        private $admin;
        private $ajax;
        private $api;
        private $widgets;

        public function __construct() {
            add_action('plugins_loaded', [$this, 'init']);
            register_activation_hook(__FILE__, [$this, 'activate']);
            register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        }

        public function init() {
            try {
                error_log("ContentGuard: Initializing plugin components");
                
                // Initialize only what we have
                if (class_exists('ContentGuard_Core')) {
                    $this->core = new ContentGuard_Core();
                    $this->core->init();
                    error_log("ContentGuard: Core initialized");
                }
                
                if (class_exists('ContentGuard_Admin')) {
                    $this->admin = new ContentGuard_Admin();
                    $this->admin->init();
                    error_log("ContentGuard: Admin initialized");
                }
                
                if (class_exists('ContentGuard_AJAX')) {
                    $this->ajax = new ContentGuard_AJAX();
                    $this->ajax->init();
                    error_log("ContentGuard: AJAX initialized");
                }
                
                if (class_exists('ContentGuard_API')) {
                    $this->api = new ContentGuard_API();
                    $this->api->init();
                    error_log("ContentGuard: API initialized");
                }
                
                if (class_exists('ContentGuard_Widgets')) {
                    $this->widgets = new ContentGuard_Widgets();
                    $this->widgets->init();
                    error_log("ContentGuard: Widgets initialized");
                }
                
                error_log("ContentGuard: Plugin initialization completed successfully");
                
            } catch (Exception $e) {
                error_log("ContentGuard: Initialization error: " . $e->getMessage());
                add_action('admin_notices', function() use ($e) {
                    echo '<div class="notice notice-error"><p><strong>ContentGuard Initialization Error:</strong> ' . esc_html($e->getMessage()) . '</p></div>';
                });
            }
        }

        public function activate() {
            // Simple activation - create table
            global $wpdb;
            $table_name = $wpdb->prefix . 'contentguard_detections';
            $charset_collate = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE IF NOT EXISTS $table_name (
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
                PRIMARY KEY (id)
            ) $charset_collate;";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
            
            // Add some sample data if table is empty
            $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
            if ($count == 0 && class_exists('ContentGuard_Core')) {
                $core = new ContentGuard_Core();
                if (method_exists($core, 'add_enhanced_sample_data')) {
                    $core->add_enhanced_sample_data();
                }
            }
        }

        public function deactivate() {
            wp_clear_scheduled_hook('contentguard_cleanup_logs');
        }
    }

    // Initialize the plugin
    new ContentGuardPlugin();
    
} else {
    error_log("ContentGuard: Cannot initialize - missing required files");
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p><strong>ContentGuard:</strong> Cannot initialize due to missing required files. Check debug log for details.</p></div>';
    });
}

error_log("=== ContentGuard Debug: Plugin load completed ===");
?>