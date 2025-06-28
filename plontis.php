<?php
/**
 * Plugin Name: Plontis - AI Bot Detection
 * Plugin URI: https://plontis.com
 * Description: Detect and track AI bots scraping your content with industry-accurate valuation.
 * Version: 1.0.3
 * Author: Plontis
 * License: GPL v2 or later
 * Text Domain: plontis
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('PLONTIS_VERSION', '2.0.0');
define('PLONTIS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PLONTIS_PLUGIN_PATH', plugin_dir_path(__FILE__));

// Force debug logging
error_log("=== Plontis Debug: Starting plugin load ===");

// Function to safely test and include files
function plontis_test_and_include($file) {
    $file_path = PLONTIS_PLUGIN_PATH . $file;
    
    error_log("Plontis: Testing file $file");
    
    if (!file_exists($file_path)) {
        error_log("Plontis: File MISSING: $file_path");
        return false;
    }
    
    // Test file syntax by checking for basic PHP structure
    $content = file_get_contents($file_path);
    
    // Check for opening PHP tag
    if (strpos($content, '<?php') === false) {
        error_log("Plontis: File $file missing opening <?php tag");
        return false;
    }
    
    // Check for problematic closing tag
    if (substr(trim($content), -2) === '?>') {
        error_log("Plontis: WARNING - File $file has closing ?> tag (should be removed)");
    }
    
    // Check for class definitions to avoid redeclaration
    if (preg_match('/class\s+(\w+)/', $content, $matches)) {
        $class_name = $matches[1];
        if (class_exists($class_name)) {
            error_log("Plontis: ERROR - Class $class_name already exists (from $file)");
            return false;
        }
    }
    
    try {
        require_once $file_path;
        error_log("Plontis: Successfully included $file");
        return true;
    } catch (ParseError $e) {
        error_log("Plontis: PARSE ERROR in $file: " . $e->getMessage());
        return false;
    } catch (Error $e) {
        error_log("Plontis: FATAL ERROR in $file: " . $e->getMessage());
        return false;
    } catch (Exception $e) {
        error_log("Plontis: EXCEPTION in $file: " . $e->getMessage());
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
    'includes/class-plontis-core.php',
    'includes/class-plontis-admin.php',
    'includes/class-plontis-ajax.php',
    'includes/class-plontis-api.php',
    'includes/class-plontis-widgets.php',
    'includes/class-plontis-cli.php'
];

$successful_includes = [];
$failed_includes = [];

foreach ($files_to_test as $file) {
    if (plontis_test_and_include($file)) {
        $successful_includes[] = $file;
    } else {
        $failed_includes[] = $file;
        error_log("Plontis: STOPPING - Failed to include $file");
        break; // Stop at first failure
    }
}

error_log("Plontis: Successful includes: " . implode(', ', $successful_includes));
if (!empty($failed_includes)) {
    error_log("Plontis: Failed includes: " . implode(', ', $failed_includes));
}

// Only initialize if we have the minimum required files
if (in_array('includes/class-plontis-core.php', $successful_includes) && 
    in_array('includes/class-plontis-admin.php', $successful_includes)) {
    
    class PlontisPlugin {
        
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
                error_log("Plontis: Initializing plugin components");
                
                // Initialize only what we have
                if (class_exists('Plontis_Core')) {
                    $this->core = new Plontis_Core();
                    $this->core->init();
                    error_log("Plontis: Core initialized");
                }
                
                if (class_exists('Plontis_Admin')) {
                    $this->admin = new Plontis_Admin();
                    $this->admin->init();
                    error_log("Plontis: Admin initialized");
                }
                
                if (class_exists('Plontis_AJAX')) {
                    $this->ajax = new Plontis_AJAX();
                    $this->ajax->init();
                    error_log("Plontis: AJAX initialized");
                }
                
                if (class_exists('Plontis_API')) {
                    $this->api = new Plontis_API();
                    $this->api->init();
                    error_log("Plontis: API initialized");
                }
                
                if (class_exists('Plontis_Widgets')) {
                    $this->widgets = new Plontis_Widgets();
                    $this->widgets->init();
                    error_log("Plontis: Widgets initialized");
                }
                
                error_log("Plontis: Plugin initialization completed successfully");
                
            } catch (Exception $e) {
                error_log("Plontis: Initialization error: " . $e->getMessage());
                add_action('admin_notices', function() use ($e) {
                    echo '<div class="notice notice-error"><p><strong>Plontis Initialization Error:</strong> ' . esc_html($e->getMessage()) . '</p></div>';
                });
            }
        }

        public function activate() {
            global $wpdb;
            
            // Create Plontis detection table
            $table_name = $wpdb->prefix . 'plontis_detections';
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
            
            // Set default settings if they don't exist
            if (!get_option('plontis_settings')) {
                $default_settings = [
                    'enable_detection' => true,
                    'enable_notifications' => true,
                    'notification_email' => get_option('admin_email'),
                    'log_retention_days' => 90,
                    'track_legitimate_bots' => false,
                    'high_value_threshold' => 50.00,
                    'licensing_notification_threshold' => 100.00
                ];
                update_option('plontis_settings', $default_settings);
            }
            
            // Add sample data if table is empty (for demo purposes)
            $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
            if ($count == 0) {
                $this->add_sample_data($table_name);
            }
        }

        public function deactivate() {
            wp_clear_scheduled_hook('plontis_cleanup_logs');
        }
    }

    // Initialize the plugin
    new PlontisPlugin();
    
} else {
    error_log("Plontis: Cannot initialize - missing required files");
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p><strong>Plontis:</strong> Cannot initialize due to missing required files. Check debug log for details.</p></div>';
    });
}

error_log("=== Plontis Debug: Plugin load completed ===");
?>