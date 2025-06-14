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
require_once CONTENTGUARD_PLUGIN_PATH . 'includes/class-contentguard-valuation.php';

class ContentGuardPlugin {
    
    private $bot_signatures = [
        'OpenAI' => [
            'patterns' => ['GPTBot', 'ChatGPT-User', 'OAI-SearchBot'],
            'company' => 'OpenAI',
            'purpose' => 'LLM Training & Chat Browsing',
            'risk_level' => 'high',
            'commercial' => true
        ],
        'Anthropic' => [
            'patterns' => ['ClaudeBot', 'anthropic-ai', 'Claude-Web', 'Claude-SearchBot', 'Claude-User'],
            'company' => 'Anthropic',
            'purpose' => 'Claude Training Data',
            'risk_level' => 'high',
            'commercial' => true
        ],
        'Google' => [
            'patterns' => ['Google-Extended', 'Google-CloudVertexBot', 'GoogleOther'],
            'company' => 'Google',
            'purpose' => 'Bard & Vertex AI Training',
            'risk_level' => 'high',
            'commercial' => true
        ],
        'Meta' => [
            'patterns' => ['Meta-ExternalAgent', 'Meta-ExternalFetcher', 'FacebookBot'],
            'company' => 'Meta',
            'purpose' => 'AI Model Training',
            'risk_level' => 'medium',
            'commercial' => true
        ],
        'Microsoft' => [
            'patterns' => ['Microsoft-Bing', 'BingBot-Extended', 'MSN-Bot'],
            'company' => 'Microsoft',
            'purpose' => 'Copilot & Azure AI',
            'risk_level' => 'high',
            'commercial' => true
        ],
        'CommonCrawl' => [
            'patterns' => ['CCBot'],
            'company' => 'Common Crawl',
            'purpose' => 'Dataset for AI Training',
            'risk_level' => 'high',
            'commercial' => false
        ],
        'Perplexity' => [
            'patterns' => ['PerplexityBot', 'Perplexity-User'],
            'company' => 'Perplexity',
            'purpose' => 'AI Search Engine',
            'risk_level' => 'medium',
            'commercial' => true
        ],
        'Apple' => [
            'patterns' => ['Applebot', 'Applebot-Extended'],
            'company' => 'Apple',
            'purpose' => 'AI Features Training',
            'risk_level' => 'medium',
            'commercial' => true
        ],
        'ByteDance' => [
            'patterns' => ['Bytespider'],
            'company' => 'ByteDance/TikTok',
            'purpose' => 'AI Model Training',
            'risk_level' => 'medium',
            'commercial' => true
        ],
        'Amazon' => [
            'patterns' => ['Amazonbot'],
            'company' => 'Amazon',
            'purpose' => 'Alexa & AI Services',
            'risk_level' => 'medium',
            'commercial' => true
        ],
        'Cohere' => [
            'patterns' => ['cohere-ai', 'cohere-training-data-crawler'],
            'company' => 'Cohere',
            'purpose' => 'LLM Training',
            'risk_level' => 'medium',
            'commercial' => true
        ],
        'Other' => [
            'patterns' => ['Diffbot', 'AI2Bot', 'ImagesiftBot', 'DuckAssistBot', 'Kangaroo Bot', 'PanguBot'],
            'company' => 'Various',
            'purpose' => 'Data Scraping & AI Training',
            'risk_level' => 'low',
            'commercial' => true
        ]
    ];

    private $valuation_engine;

    public function __construct() {
        add_action('init', [$this, 'init']);
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        
        // Initialize valuation engine
        $this->valuation_engine = new ContentGuardValuation();
    }

    public function ajax_test() {
        wp_send_json_success(['message' => 'Enhanced AJAX is working!', 'time' => current_time('mysql'), 'version' => CONTENTGUARD_VERSION]);
    }

    public function init() {
        // Hook into WordPress request processing
        add_action('wp', [$this, 'detect_and_log_bots']);
        
        // Admin interface
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'admin_scripts']);
        
        // AJAX handlers - Enhanced
        add_action('wp_ajax_contentguard_get_detections', [$this, 'ajax_get_detections']);
        add_action('wp_ajax_contentguard_get_stats', [$this, 'ajax_get_enhanced_stats']); // Enhanced
        add_action('wp_ajax_contentguard_test', [$this, 'ajax_test']);
        add_action('wp_ajax_contentguard_get_valuation_details', [$this, 'ajax_get_valuation_details']); // New
        
        // Dashboard widget
        add_action('wp_dashboard_setup', [$this, 'add_dashboard_widget']);
        
        // Cron for cleanup old logs
        add_action('contentguard_cleanup_logs', [$this, 'cleanup_old_logs']);
        if (!wp_next_scheduled('contentguard_cleanup_logs')) {
            wp_schedule_event(time(), 'weekly', 'contentguard_cleanup_logs');
        }
    }

    public function activate() {
        $this->create_tables();
        $this->set_default_options();
        $this->add_sample_data();
    }

    public function deactivate() {
        wp_clear_scheduled_hook('contentguard_cleanup_logs');
    }

    private function create_tables() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'contentguard_detections';

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
            content_type varchar(50) DEFAULT 'text_article',
            licensing_potential varchar(20) DEFAULT 'low',
            detected_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY ip_address (ip_address),
            KEY detected_at (detected_at),
            KEY bot_type (bot_type),
            KEY company (company),
            KEY estimated_value (estimated_value)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Check if we need to upgrade existing table
        $this->maybe_upgrade_table();
    }
    
    /**
     * Upgrade existing table to include new valuation columns
     */
    private function maybe_upgrade_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'contentguard_detections';
        
        // Check if estimated_value column exists
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'estimated_value'");
        
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN estimated_value decimal(10,2) DEFAULT 0.00 AFTER commercial_risk");
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN content_type varchar(50) DEFAULT 'text_article' AFTER estimated_value");
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN licensing_potential varchar(20) DEFAULT 'low' AFTER content_type");
            
            // Backfill existing records with enhanced valuations
            $this->backfill_valuations();
        }
    }
    
    /**
     * Backfill existing records with enhanced valuations
     */
    private function backfill_valuations() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'contentguard_detections';
        
        $existing_records = $wpdb->get_results("SELECT * FROM $table_name WHERE estimated_value = 0.00", ARRAY_A);
        
        foreach ($existing_records as $record) {
            $detection_data = [
                'company' => $record['company'],
                'bot_type' => $record['bot_type'],
                'request_uri' => $record['request_uri'],
                'risk_level' => $record['risk_level'],
                'confidence' => $record['confidence'],
                'commercial_risk' => $record['commercial_risk']
            ];
            
            $content_metadata = $this->extract_content_metadata($record['request_uri']);
            $valuation = $this->valuation_engine->calculate_content_value($detection_data, $content_metadata);
            
            $wpdb->update(
                $table_name,
                [
                    'estimated_value' => $valuation['estimated_value'],
                    'content_type' => $valuation['breakdown']['content_type'],
                    'licensing_potential' => $valuation['licensing_potential']
                ],
                ['id' => $record['id']]
            );
        }
    }

    private function set_default_options() {
        add_option('contentguard_settings', [
            'enable_detection' => true,
            'enable_notifications' => true,
            'notification_email' => get_option('admin_email'),
            'log_retention_days' => 90,
            'track_legitimate_bots' => false,
            'enhanced_valuation' => true,
            'valuation_version' => '2.0'
        ]);
    }

    public function add_sample_data() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'contentguard_detections';
        
        // Check if we already have sample data
        $existing = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE ip_address = '127.0.0.1'");
        if ($existing > 0) {
            return; // Sample data already exists
        }
        
        // Enhanced sample data with accurate valuations
        $sample_detections = [
            [
                'user_agent' => 'Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)',
                'ip_address' => '127.0.0.1',
                'request_uri' => '/premium-tutorial-guide',
                'bot_type' => 'OpenAI',
                'company' => 'OpenAI',
                'risk_level' => 'high',
                'confidence' => 95,
                'commercial_risk' => 1,
                'detected_at' => date('Y-m-d H:i:s', strtotime('-2 hours'))
            ],
            [
                'user_agent' => 'ClaudeBot/1.0',
                'ip_address' => '127.0.0.2',
                'request_uri' => '/images/product-showcase.jpg',
                'bot_type' => 'Anthropic',
                'company' => 'Anthropic',
                'risk_level' => 'high',
                'confidence' => 95,
                'commercial_risk' => 1,
                'detected_at' => date('Y-m-d H:i:s', strtotime('-4 hours'))
            ],
            [
                'user_agent' => 'Google-Extended/1.0',
                'ip_address' => '127.0.0.3',
                'request_uri' => '/research/ai-breakthrough-study',
                'bot_type' => 'Google',
                'company' => 'Google',
                'risk_level' => 'high',
                'confidence' => 95,
                'commercial_risk' => 1,
                'detected_at' => date('Y-m-d H:i:s', strtotime('-6 hours'))
            ],
            [
                'user_agent' => 'Meta-ExternalAgent/1.0',
                'ip_address' => '127.0.0.4',
                'request_uri' => '/video/demo-presentation.mp4',
                'bot_type' => 'Meta',
                'company' => 'Meta',
                'risk_level' => 'medium',
                'confidence' => 90,
                'commercial_risk' => 1,
                'detected_at' => date('Y-m-d H:i:s', strtotime('-8 hours'))
            ],
            [
                'user_agent' => 'CCBot/2.0',
                'ip_address' => '127.0.0.5',
                'request_uri' => '/news/industry-analysis',
                'bot_type' => 'CommonCrawl',
                'company' => 'Common Crawl',
                'risk_level' => 'high',
                'confidence' => 95,
                'commercial_risk' => 0,
                'detected_at' => date('Y-m-d H:i:s', strtotime('-10 hours'))
            ]
        ];
        
        foreach ($sample_detections as $data) {
            // Calculate enhanced valuation for sample data
            $detection_data = [
                'company' => $data['company'],
                'bot_type' => $data['bot_type'],
                'request_uri' => $data['request_uri'],
                'risk_level' => $data['risk_level'],
                'confidence' => $data['confidence'],
                'commercial_risk' => $data['commercial_risk']
            ];
            
            $content_metadata = $this->extract_content_metadata($data['request_uri']);
            $valuation = $this->valuation_engine->calculate_content_value($detection_data, $content_metadata);
            
            // Add valuation data
            $data['estimated_value'] = $valuation['estimated_value'];
            $data['content_type'] = $valuation['breakdown']['content_type'];
            $data['licensing_potential'] = $valuation['licensing_potential'];
            
            $wpdb->insert($table_name, $data);
        }
    }

    public function detect_and_log_bots() {
        $settings = get_option('contentguard_settings');
        if (!$settings['enable_detection']) {
            return;
        }

        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ip_address = $this->get_client_ip();
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';

        // Skip admin pages and WordPress core requests
        if (is_admin() || strpos($request_uri, '/wp-') !== false) {
            return;
        }

        $detection = $this->analyze_user_agent($user_agent);
        
        // Only log AI bots (not legitimate search engines unless configured)
        if ($detection['is_bot'] && ($detection['commercial_risk'] || $settings['track_legitimate_bots'])) {
            $valuation = $this->log_detection_enhanced($user_agent, $ip_address, $request_uri, $detection);
            
            // Send notification for high-value detections (enhanced threshold)
            if (($detection['risk_level'] === 'high' || $valuation['estimated_value'] >= 10.00) && $settings['enable_notifications']) {
                $this->maybe_send_notification($detection, $request_uri, $valuation);
            }
        }
    }

    /**
     * Enhanced detection logging with accurate valuation
     */
    private function log_detection_enhanced($user_agent, $ip_address, $request_uri, $detection) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'contentguard_detections';
        
        // Calculate accurate valuation
        $content_metadata = $this->extract_content_metadata($request_uri);
        $valuation = $this->valuation_engine->calculate_content_value($detection, $content_metadata);
        
        $wpdb->insert(
            $table_name,
            [
                'user_agent' => $user_agent,
                'ip_address' => $ip_address,
                'request_uri' => $request_uri,
                'bot_type' => $detection['bot_type'],
                'company' => $detection['company'],
                'risk_level' => $detection['risk_level'],
                'confidence' => $detection['confidence'],
                'commercial_risk' => $detection['commercial_risk'] ? 1 : 0,
                'estimated_value' => $valuation['estimated_value'],
                'content_type' => $valuation['breakdown']['content_type'],
                'licensing_potential' => $valuation['licensing_potential'],
                'detected_at' => current_time('mysql')
            ]
        );
        
        return $valuation;
    }

    /**
     * Extract content metadata for enhanced valuation
     */
    private function extract_content_metadata($request_uri) {
        $metadata = [
            'request_uri' => $request_uri,
            'domain_authority' => 45, // Would integrate with SEO tools
            'content_category' => 'general'
        ];
        
        // Analyze URL for content hints
        $uri = strtolower($request_uri);
        
        if (strpos($uri, 'tutorial') !== false || strpos($uri, 'guide') !== false) {
            $metadata['content_category'] = 'educational';
            $metadata['temporal_value'] = 'evergreen';
        }
        
        if (strpos($uri, 'news') !== false || strpos($uri, 'press') !== false) {
            $metadata['content_category'] = 'news';
            $metadata['temporal_value'] = 'current';
        }
        
        if (strpos($uri, 'research') !== false || strpos($uri, 'study') !== false) {
            $metadata['content_category'] = 'academic';
            $metadata['temporal_value'] = 'evergreen';
        }
        
        // WordPress integration
        if (function_exists('url_to_postid')) {
            $post_id = url_to_postid(home_url($request_uri));
            if ($post_id) {
                $post = get_post($post_id);
                if ($post) {
                    $metadata['publish_date'] = $post->post_date;
                    $metadata['word_count'] = str_word_count(strip_tags($post->post_content));
                    $metadata['has_images'] = has_post_thumbnail($post_id) || strpos($post->post_content, '<img') !== false;
                    
                    // Get post categories for better content classification
                    $categories = get_the_category($post_id);
                    if ($categories) {
                        $metadata['content_category'] = $categories[0]->slug;
                    }
                }
            }
        }
        
        return $metadata;
    }

    private function analyze_user_agent($user_agent) {
        $result = [
            'is_bot' => false,
            'confidence' => 0,
            'bot_type' => null,
            'company' => null,
            'risk_level' => 'low',
            'commercial_risk' => false,
            'evidence' => []
        ];

        // Check against known AI bot signatures
        foreach ($this->bot_signatures as $bot_type => $config) {
            foreach ($config['patterns'] as $pattern) {
                if (stripos($user_agent, $pattern) !== false) {
                    $result['is_bot'] = true;
                    $result['confidence'] = 95;
                    $result['bot_type'] = $bot_type;
                    $result['company'] = $config['company'];
                    $result['risk_level'] = $config['risk_level'];
                    $result['commercial_risk'] = $config['commercial'];
                    $result['evidence'][] = "User Agent matches {$pattern}";
                    
                    return $result;
                }
            }
        }

        // Basic behavioral analysis for unknown bots
        $suspicion_score = 0;
        
        $bot_indicators = ['bot', 'crawler', 'spider', 'scraper', 'fetch'];
        foreach ($bot_indicators as $indicator) {
            if (stripos($user_agent, $indicator) !== false) {
                $suspicion_score += 30;
                $result['evidence'][] = "Contains '{$indicator}' in user agent";
            }
        }

        $browser_indicators = ['Mozilla', 'Chrome', 'Safari', 'Firefox', 'Edge'];
        $has_browser_indicator = false;
        foreach ($browser_indicators as $indicator) {
            if (stripos($user_agent, $indicator) !== false) {
                $has_browser_indicator = true;
                break;
            }
        }
        
        if (!$has_browser_indicator && !empty($user_agent)) {
            $suspicion_score += 25;
            $result['evidence'][] = "No typical browser identifiers";
        }

        if ($suspicion_score > 50) {
            $result['is_bot'] = true;
            $result['confidence'] = min($suspicion_score, 85);
            $result['bot_type'] = 'Unknown Bot';
            $result['risk_level'] = $suspicion_score > 70 ? 'medium' : 'low';
        }

        return $result;
    }

    private function maybe_send_notification($detection, $request_uri, $valuation = null) {
        // Throttle notifications
        $last_notification = get_transient("contentguard_notification_{$detection['bot_type']}");
        if ($last_notification) {
            return;
        }

        $settings = get_option('contentguard_settings');
        $to = $settings['notification_email'];
        $subject = "High-Value AI Bot Detected - {$detection['company']}";
        
        $estimated_value = $valuation ? $valuation['estimated_value'] : 'N/A';
        $content_type = $valuation ? $valuation['breakdown']['content_type'] : 'unknown';
        
        $message = "ContentGuard has detected a high-value AI bot on your website:\n\n";
        $message .= "Company: {$detection['company']}\n";
        $message .= "Bot Type: {$detection['bot_type']}\n";
        $message .= "Page Accessed: {$request_uri}\n";
        $message .= "Risk Level: {$detection['risk_level']}\n";
        $message .= "Commercial Risk: " . ($detection['commercial_risk'] ? 'Yes' : 'No') . "\n";
        $message .= "Estimated Value: \${$estimated_value}\n";
        $message .= "Content Type: {$content_type}\n";
        $message .= "Time: " . current_time('Y-m-d H:i:s') . "\n\n";
        
        if ($valuation && $valuation['licensing_potential'] === 'high') {
            $message .= "🎯 HIGH LICENSING POTENTIAL: This content represents significant revenue opportunity.\n\n";
        }
        
        $message .= "This AI company may be using your content for training their models.\n";
        $message .= "View your full ContentGuard dashboard: " . admin_url('admin.php?page=contentguard') . "\n\n";
        $message .= "Learn about licensing your content: https://contentguard.ai/licensing";

        wp_mail($to, $subject, $message);
        set_transient("contentguard_notification_{$detection['bot_type']}", true, HOUR_IN_SECONDS);
    }

    private function get_client_ip() {
        $ip_headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
        
        foreach ($ip_headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    public function add_admin_menu() {
        add_menu_page(
            'ContentGuard',
            'ContentGuard',
            'manage_options',
            'contentguard',
            [$this, 'admin_page'],
            'dashicons-shield-alt',
            30
        );

        add_submenu_page(
            'contentguard',
            'Settings',
            'Settings',
            'manage_options',
            'contentguard-settings',
            [$this, 'settings_page']
        );
        
        add_submenu_page(
            'contentguard',
            'Valuation Report',
            'Valuation Report',
            'manage_options',
            'contentguard-valuation',
            [$this, 'valuation_page']
        );
    }

    public function admin_scripts($hook) {
        if (strpos($hook, 'contentguard') === false) {
            return;
        }

        wp_enqueue_script('chart-js', 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js', [], '3.9.1');
        wp_enqueue_script('contentguard-admin', CONTENTGUARD_PLUGIN_URL . 'admin.js', ['jquery', 'chart-js'], CONTENTGUARD_VERSION);
        wp_enqueue_style('contentguard-admin', CONTENTGUARD_PLUGIN_URL . 'admin.css', [], CONTENTGUARD_VERSION);
        
        wp_localize_script('contentguard-admin', 'contentguard_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('contentguard_nonce'),
            'version' => CONTENTGUARD_VERSION
        ]);
    }

    /**
     * Enhanced AJAX stats handler
     */
    public function ajax_get_enhanced_stats() {
        check_ajax_referer('contentguard_nonce', 'nonce');
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'contentguard_detections';
        
        // Get detections from last 30 days
        $detections = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE detected_at > DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY detected_at DESC"
        ), ARRAY_A);
        
        // Calculate enhanced portfolio value
        $portfolio_analysis = $this->valuation_engine->calculate_portfolio_value($detections);
        
        // Enhanced statistics
        $total_bots = count($detections);
        $commercial_bots = count(array_filter($detections, function($d) { return $d['commercial_risk']; }));
        $top_company = $wpdb->get_var("SELECT company FROM $table_name GROUP BY company ORDER BY COUNT(*) DESC LIMIT 1") ?: 'None detected';
        
        // Use enhanced valuation
        $enhanced_value = $portfolio_analysis['total_estimated_value'];
        
        // Daily activity (last 7 days)
        $daily_activity = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE DATE(detected_at) = %s",
                $date
            )) ?: 0;
            $daily_activity[] = ['date' => $date, 'count' => (int)$count];
        }
        
        // Company breakdown with enhanced values
        $company_breakdown = [];
        foreach ($portfolio_analysis['company_breakdown'] as $company => $value) {
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE company = %s",
                $company
            )) ?: 0;
            $company_breakdown[] = [
                'company' => $company,
                'count' => (int)$count,
                'estimated_value' => $value
            ];
        }
        
        wp_send_json_success([
            'total_bots' => $total_bots,
            'commercial_bots' => $commercial_bots,
            'top_company' => $top_company,
            'content_value' => number_format($enhanced_value, 2),
            'enhanced_valuation' => $portfolio_analysis,
            'daily_activity' => $daily_activity,
            'company_breakdown' => $company_breakdown,
            'valuation_methodology' => 'Enhanced AI Content Valuation v2.0',
            'licensing_opportunities' => count($portfolio_analysis['high_value_opportunities']),
            'average_value_per_detection' => $portfolio_analysis['average_per_detection']
        ]);
    }

    /**
     * New AJAX handler for detailed valuation information
     */
    public function ajax_get_valuation_details() {
        check_ajax_referer('contentguard_nonce', 'nonce');
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'contentguard_detections';
        
        $detection_id = intval($_POST['detection_id'] ?? 0);
        
        if ($detection_id) {
            $detection = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE id = %d",
                $detection_id
            ), ARRAY_A);
            
            if ($detection) {
                $content_metadata = $this->extract_content_metadata($detection['request_uri']);
                $valuation = $this->valuation_engine->calculate_content_value($detection, $content_metadata);
                
                wp_send_json_success([
                    'detection' => $detection,
                    'valuation' => $valuation,
                    'content_metadata' => $content_metadata
                ]);
            }
        }
        
        wp_send_json_error('Detection not found');
    }

    public function ajax_get_detections() {
        check_ajax_referer('contentguard_nonce', 'nonce');
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'contentguard_detections';
        
        $limit = intval($_POST['limit'] ?? 20);
        $offset = intval($_POST['offset'] ?? 0);
        
        $detections = $wpdb->get_results($wpdb->prepare(
            "SELECT *, estimated_value, content_type, licensing_potential FROM $table_name 
             WHERE detected_at > DATE_SUB(NOW(), INTERVAL 30 DAY) 
             ORDER BY detected_at DESC LIMIT %d OFFSET %d",
            $limit, $offset
        ));
        
        wp_send_json_success($detections);
    }

    public function admin_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'contentguard_detections';
        
        // Get portfolio summary
        $detections = $wpdb->get_results("SELECT * FROM $table_name WHERE detected_at > DATE_SUB(NOW(), INTERVAL 30 DAY)", ARRAY_A);
        $portfolio_analysis = $this->valuation_engine->calculate_portfolio_value($detections);
        
        ?>
        <div class="wrap contentguard-admin">
            <h1>
                <span class="dashicons dashicons-shield-alt"></span>
                ContentGuard - Enhanced AI Bot Detection v<?php echo CONTENTGUARD_VERSION; ?>
            </h1>
            
            <div class="notice notice-info">
                <h4>🎯 Enhanced Valuation System Active</h4>
                <p><strong>Industry-Accurate Pricing:</strong> Based on Getty Images ($130-$500), Music Licensing ($250-$2,000), Academic Publishing ($200-$5,450), and News Syndication rates.</p>
                <p><strong>Total Portfolio Value:</strong> $<?php echo number_format($portfolio_analysis['total_estimated_value'], 2); ?> | 
                   <strong>Annual Projection:</strong> $<?php echo number_format($portfolio_analysis['annual_projection']['growth_adjusted'], 2); ?> |
                   <strong>High-Value Opportunities:</strong> <?php echo count($portfolio_analysis['high_value_opportunities']); ?></p>
            </div>
            
            <div class="contentguard-stats-grid">
                <div class="contentguard-stat-card">
                    <h3>Total AI Bots Detected</h3>
                    <div class="stat-number" id="total-bots">-</div>
                    <span class="stat-period">Last 30 days</span>
                </div>
                
                <div class="contentguard-stat-card">
                    <h3>Enhanced Content Value</h3>
                    <div class="stat-number" id="content-value">$-</div>
                    <span class="stat-period">Industry-accurate pricing</span>
                </div>
                
                <div class="contentguard-stat-card">
                    <h3>Licensing Opportunities</h3>
                    <div class="stat-number" id="licensing-opportunities"><?php echo count($portfolio_analysis['high_value_opportunities']); ?></div>
                    <span class="stat-period">High-value detections</span>
                </div>
                
                <div class="contentguard-stat-card">
                    <h3>Average Per Detection</h3>
                    <div class="stat-number" id="average-value">$<?php echo number_format($portfolio_analysis['average_per_detection'], 2); ?></div>
                    <span class="stat-period">Market-based estimate</span>
                </div>
            </div>

            <?php if (!empty($portfolio_analysis['licensing_recommendations'])): ?>
            <div class="contentguard-panel">
                <h2>💰 Licensing Recommendations</h2>
                <?php foreach ($portfolio_analysis['licensing_recommendations'] as $recommendation): ?>
                <div class="licensing-recommendation priority-<?php echo $recommendation['priority']; ?>">
                    <h4><?php echo isset($recommendation['company']) ? $recommendation['company'] : 'Portfolio Opportunity'; ?></h4>
                    <p><strong>Estimated Value:</strong> $<?php echo number_format($recommendation['estimated_value'], 2); ?></p>
                    <p><strong>Annual Potential:</strong> $<?php echo number_format($recommendation['potential_annual'], 2); ?></p>
                    <p><strong>Action:</strong> <?php echo $recommendation['action']; ?></p>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="contentguard-dashboard-grid">
                <div class="contentguard-panel">
                    <h2>AI Bot Activity Trends</h2>
                    <canvas id="activity-chart" width="400" height="200"></canvas>
                </div>
                
                <div class="contentguard-panel">
                    <h2>Content Value by Company</h2>
                    <canvas id="companies-chart" width="400" height="200"></canvas>
                </div>
            </div>

            <!-- Enhanced Testing Section -->
            <div class="contentguard-panel">
                <h2>Test Enhanced Bot Detection & Valuation</h2>
                <p>Test any user agent string to see accurate valuation based on industry licensing rates.</p>
                
                <form method="post" action="">
                    <?php wp_nonce_field('contentguard_test', 'test_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">User Agent String</th>
                            <td>
                                <input type="text" name="test_user_agent" class="regular-text" 
                                    placeholder="Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)" 
                                    style="width: 100%; margin-bottom: 10px;" 
                                    value="<?php echo isset($_POST['test_user_agent']) ? esc_attr($_POST['test_user_agent']) : ''; ?>" />
                                <br>
                                <input type="text" name="test_uri" class="regular-text" 
                                    placeholder="/premium-content/tutorial-guide" 
                                    style="width: 100%; margin-bottom: 10px;" 
                                    value="<?php echo isset($_POST['test_uri']) ? esc_attr($_POST['test_uri']) : '/test-content'; ?>" />
                                <br>
                                <input type="submit" name="test_detection" class="button button-primary" value="Test Enhanced Detection & Valuation" />
                            </td>
                        </tr>
                    </table>
                </form>
                
                <?php
                // Enhanced test detection with accurate valuation
                if (isset($_POST['test_detection']) && wp_verify_nonce($_POST['test_nonce'], 'contentguard_test')) {
                    $test_user_agent = sanitize_text_field($_POST['test_user_agent']);
                    $test_uri = sanitize_text_field($_POST['test_uri'] ?: '/test-content');
                    
                    if ($test_user_agent) {
                        $detection = $this->analyze_user_agent($test_user_agent);
                        $content_metadata = $this->extract_content_metadata($test_uri);
                        $valuation = $this->valuation_engine->calculate_content_value(
                            array_merge($detection, ['request_uri' => $test_uri]), 
                            $content_metadata
                        );
                        
                        // Log the test detection
                        $this->log_detection_enhanced($test_user_agent, '127.0.0.1', $test_uri, $detection);
                        
                        echo '<div class="notice notice-success" style="margin-top: 15px;">';
                        echo '<h4>✅ Enhanced Detection & Valuation Complete!</h4>';
                        echo '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">';
                        
                        // Detection Results
                        echo '<div>';
                        echo '<h5>🤖 Bot Detection Results</h5>';
                        echo '<p><strong>User Agent:</strong> <code>' . esc_html($test_user_agent) . '</code></p>';
                        echo '<p><strong>Is Bot:</strong> ' . ($detection['is_bot'] ? '<span style="color: #d63384; font-weight: bold;">Yes</span>' : '<span style="color: #198754; font-weight: bold;">No</span>') . '</p>';
                        
                        if ($detection['is_bot']) {
                            echo '<p><strong>Company:</strong> ' . esc_html($detection['company'] ?: 'Unknown') . '</p>';
                            echo '<p><strong>Bot Type:</strong> ' . esc_html($detection['bot_type'] ?: 'Unknown') . '</p>';
                            echo '<p><strong>Risk Level:</strong> <span class="risk-badge risk-' . $detection['risk_level'] . '">' . esc_html($detection['risk_level']) . '</span></p>';
                            echo '<p><strong>Commercial Risk:</strong> ' . ($detection['commercial_risk'] ? '<span style="color: #d63384; font-weight: bold;">Yes</span>' : '<span style="color: #6c757d;">No</span>') . '</p>';
                        }
                        echo '</div>';
                        
                        // Valuation Results
                        echo '<div>';
                        echo '<h5>💰 Enhanced Valuation Analysis</h5>';
                        echo '<p><strong>Estimated Value:</strong> <span style="color: #28a745; font-weight: bold; font-size: 18px;"> . number_format($valuation['estimated_value'], 2) . '</span></p>';
                        echo '<p><strong>Content Type:</strong> ' . esc_html($valuation['breakdown']['content_type']) . '</p>';
                        echo '<p><strong>Licensing Potential:</strong> <span class="risk-badge risk-' . $valuation['licensing_potential'] . '">' . esc_html($valuation['licensing_potential']) . '</span></p>';
                        echo '<p><strong>Market Position:</strong> ' . esc_html($valuation['market_comparison']['position']) . '</p>';
                        
                        // Show market comparison
                        echo '<h6>Market Comparison:</h6>';
                        echo '<ul style="margin: 5px 0; padding-left: 20px;">';
                        foreach ($valuation['market_comparison']['market_range'] as $source => $range) {
                            echo '<li>' . esc_html(ucwords(str_replace('_', ' ', $source))) . ': ' . esc_html($range) . '</li>';
                        }
                        echo '</ul>';
                        echo '</div>';
                        
                        echo '</div>'; // End grid
                        
                        if ($valuation['licensing_potential'] === 'high') {
                            echo '<div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 4px; margin-top: 15px;">';
                            echo '<h5>🎯 High Licensing Potential Detected!</h5>';
                            echo '<p>This content represents significant revenue opportunity. Annual potential: <strong> . number_format($valuation['estimated_value'] * 365, 2) . '</strong> if accessed daily.</p>';
                            echo '<p><a href="https://contentguard.ai/join" target="_blank" class="button button-primary">Join ContentGuard Platform</a></p>';
                            echo '</div>';
                        }
                        
                        echo '</div>';
                    }
                }
                ?>
            </div>
            
            <div class="contentguard-panel">
                <h2>Recent AI Bot Detections</h2>
                <div id="recent-detections">
                    <div class="contentguard-loading">Loading enhanced detection data...</div>
                </div>
            </div>

            <div class="contentguard-cta">
                <h3>Ready to Monetize Your Content?</h3>
                <p>Your content is worth <strong>$<?php echo number_format($portfolio_analysis['annual_projection']['growth_adjusted'], 2); ?></strong> annually. Join our licensing platform to start earning.</p>
                <a href="https://contentguard.ai/join" class="button button-primary button-hero" target="_blank">
                    Join ContentGuard Platform
                </a>
            </div>
        </div>
        
        <style>
        .licensing-recommendation {
            background: #f8f9fa;
            border-left: 4px solid #6c757d;
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 4px;
        }
        .licensing-recommendation.priority-high {
            border-left-color: #dc3545;
            background: #fff5f5;
        }
        .licensing-recommendation.priority-medium {
            border-left-color: #ffc107;
            background: #fffef7;
        }
        .licensing-recommendation h4 {
            margin: 0 0 10px 0;
            color: #333;
        }
        .licensing-recommendation p {
            margin: 5px 0;
            font-size: 14px;
        }
        </style>
        <?php
    }

    public function valuation_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'contentguard_detections';
        
        $detections = $wpdb->get_results("SELECT * FROM $table_name ORDER BY detected_at DESC", ARRAY_A);
        $portfolio_analysis = $this->valuation_engine->calculate_portfolio_value($detections);
        
        ?>
        <div class="wrap contentguard-admin">
            <h1>ContentGuard - Detailed Valuation Report</h1>
            
            <div class="contentguard-panel">
                <h2>Portfolio Summary</h2>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                    <div>
                        <h4>Total Portfolio Value</h4>
                        <p style="font-size: 24px; font-weight: bold; color: #28a745;">$<?php echo number_format($portfolio_analysis['total_estimated_value'], 2); ?></p>
                    </div>
                    <div>
                        <h4>Annual Projection</h4>
                        <p style="font-size: 24px; font-weight: bold; color: #0dcaf0;">$<?php echo number_format($portfolio_analysis['annual_projection']['growth_adjusted'], 2); ?></p>
                    </div>
                    <div>
                        <h4>Average Per Detection</h4>
                        <p style="font-size: 24px; font-weight: bold; color: #6f42c1;">$<?php echo number_format($portfolio_analysis['average_per_detection'], 2); ?></p>
                    </div>
                    <div>
                        <h4>High-Value Opportunities</h4>
                        <p style="font-size: 24px; font-weight: bold; color: #dc3545;"><?php echo count($portfolio_analysis['high_value_opportunities']); ?></p>
                    </div>
                </div>
            </div>
            
            <div class="contentguard-panel">
                <h2>Value Breakdown by Company</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Company</th>
                            <th>Total Value</th>
                            <th>Detection Count</th>
                            <th>Average Value</th>
                            <th>Annual Potential</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($portfolio_analysis['company_breakdown'] as $company => $value): 
                            $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE company = %s", $company));
                            $avg_value = $count > 0 ? $value / $count : 0;
                            $annual_potential = $value * 12 * 1.5; // Growth factor
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($company); ?></strong></td>
                            <td>$<?php echo number_format($value, 2); ?></td>
                            <td><?php echo $count; ?></td>
                            <td>$<?php echo number_format($avg_value, 2); ?></td>
                            <td>$<?php echo number_format($annual_potential, 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="contentguard-panel">
                <h2>Value Breakdown by Content Type</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Content Type</th>
                            <th>Total Value</th>
                            <th>Detection Count</th>
                            <th>Average Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($portfolio_analysis['content_type_breakdown'] as $content_type => $value): 
                            $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE content_type = %s", $content_type));
                            $avg_value = $count > 0 ? $value / $count : 0;
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html(ucwords(str_replace('_', ' ', $content_type))); ?></strong></td>
                            <td>$<?php echo number_format($value, 2); ?></td>
                            <td><?php echo $count; ?></td>
                            <td>$<?php echo number_format($avg_value, 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    public function settings_page() {
        if (isset($_POST['submit'])) {
            $settings = [
                'enable_detection' => isset($_POST['enable_detection']),
                'enable_notifications' => isset($_POST['enable_notifications']),
                'notification_email' => sanitize_email($_POST['notification_email']),
                'log_retention_days' => intval($_POST['log_retention_days']),
                'track_legitimate_bots' => isset($_POST['track_legitimate_bots']),
                'enhanced_valuation' => isset($_POST['enhanced_valuation']),
                'valuation_version' => '2.0'
            ];
            update_option('contentguard_settings', $settings);
            echo '<div class="notice notice-success"><p>Enhanced settings saved!</p></div>';
        }

        $settings = get_option('contentguard_settings');
        ?>
        <div class="wrap">
            <h1>ContentGuard Enhanced Settings</h1>
            
            <form method="post" action="">
                <table class="form-table">
                    <tr>
                        <th scope="row">Enable AI Bot Detection</th>
                        <td>
                            <input type="checkbox" name="enable_detection" <?php checked($settings['enable_detection']); ?> />
                            <p class="description">Monitor your website for AI bot activity</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Enhanced Valuation System</th>
                        <td>
                            <input type="checkbox" name="enhanced_valuation" <?php checked($settings['enhanced_valuation'] ?? true); ?> />
                            <p class="description">Use industry-accurate pricing based on Getty Images, music licensing, and academic publishing rates</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Email Notifications</th>
                        <td>
                            <input type="checkbox" name="enable_notifications" <?php checked($settings['enable_notifications']); ?> />
                            <p class="description">Get notified when high-value AI bots are detected (>$10 estimated value)</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Notification Email</th>
                        <td>
                            <input type="email" name="notification_email" value="<?php echo esc_attr($settings['notification_email']); ?>" class="regular-text" />
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Log Retention</th>
                        <td>
                            <input type="number" name="log_retention_days" value="<?php echo esc_attr($settings['log_retention_days']); ?>" min="7" max="365" />
                            <p class="description">Days to keep detection logs (recommended: 90)</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Track All Bots</th>
                        <td>
                            <input type="checkbox" name="track_legitimate_bots" <?php checked($settings['track_legitimate_bots']); ?> />
                            <p class="description">Also track legitimate search engine bots (increases log volume)</p>
                        </td>
                    </tr>
                </table>
                
                <div class="notice notice-info">
                    <h4>Enhanced Valuation System v2.0</h4>
                    <p><strong>Accurate Industry Pricing:</strong> Our valuation system is based on extensive research of real market rates:</p>
                    <ul style="margin-left: 20px;">
                        <li><strong>Getty Images:</strong> $130-$500 per image (royalty-free), up to $5,000 premium content</li>
                        <li><strong>Music Licensing (ASCAP/BMI):</strong> $250-$2,000/year for venues, up to $10,000+ for businesses</li>
                        <li><strong>Academic Publishing:</strong> $200-$5,450 article processing charges, $1,626 global average</li>
                        <li><strong>News Syndication:</strong> AP/Reuters content licensing based on circulation and usage</li>
                    </ul>
                    <p>This ensures your content valuations reflect real licensing opportunities in the market.</p>
                </div>
                
                <?php submit_button('Save Enhanced Settings'); ?>
            </form>
        </div>
        <?php
    }

    public function add_dashboard_widget() {
        wp_add_dashboard_widget(
            'contentguard_widget',
            'ContentGuard - Enhanced AI Bot Activity',
            [$this, 'dashboard_widget_content']
        );
    }

    public function dashboard_widget_content() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'contentguard_detections';
        
        $recent_bots = $wpdb->get_var(
            "SELECT COUNT(*) FROM $table_name WHERE detected_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        
        $high_value = $wpdb->get_var(
            "SELECT COUNT(*) FROM $table_name WHERE estimated_value >= 10.00 AND detected_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        
        $total_value = $wpdb->get_var(
            "SELECT SUM(estimated_value) FROM $table_name WHERE detected_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"
        ) ?: 0;
        
        ?>
        <div class="contentguard-widget">
            <p><strong><?php echo $recent_bots; ?></strong> AI bots detected this week</p>
            <p><strong><?php echo $high_value; ?></strong> high-value opportunities (>$10)</p>
            <p><strong>$<?php echo number_format($total_value, 2); ?></strong> total estimated value this week</p>
            <p>
                <a href="<?php echo admin_url('admin.php?page=contentguard'); ?>" class="button">
                    View Enhanced Dashboard
                </a>
                <a href="<?php echo admin_url('admin.php?page=contentguard-valuation'); ?>" class="button">
                    Valuation Report
                </a>
                <a href="https://contentguard.ai/licensing" target="_blank" class="button button-primary">
                    Start Earning
                </a>
            </p>
        </div>
        <?php
    }

    public function cleanup_old_logs() {
        global $wpdb;
        $settings = get_option('contentguard_settings');
        $retention_days = $settings['log_retention_days'] ?? 90;
        
        $table_name = $wpdb->prefix . 'contentguard_detections';
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table_name WHERE detected_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $retention_days
        ));
    }
}

// Initialize the enhanced plugin
new ContentGuardPlugin();

/**
 * Helper functions for enhanced features
 */

/**
 * Get enhanced content valuation for any detection
 */
function contentguard_get_content_value($detection_data, $content_metadata = []) {
    static $valuation_engine = null;
    
    if ($valuation_engine === null) {
        $valuation_engine = new ContentGuardValuation();
    }
    
    return $valuation_engine->calculate_content_value($detection_data, $content_metadata);
}

/**
 * Get portfolio analysis for all detections
 */
function contentguard_get_portfolio_analysis($detections = null) {
    static $valuation_engine = null;
    
    if ($valuation_engine === null) {
        $valuation_engine = new ContentGuardValuation();
    }
    
    if ($detections === null) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'contentguard_detections';
        $detections = $wpdb->get_results("SELECT * FROM $table_name ORDER BY detected_at DESC", ARRAY_A);
    }
    
    return $valuation_engine->calculate_portfolio_value($detections);
}

/**
 * Admin notice for enhanced features
 */
function contentguard_enhanced_admin_notice() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    $screen = get_current_screen();
    if ($screen && strpos($screen->id, 'contentguard') !== false) {
        return; // Don't show on ContentGuard pages
    }
    
    $detections = get_transient('contentguard_recent_high_value');
    if ($detections === false) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'contentguard_detections';
        $high_value_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM $table_name WHERE estimated_value >= 10.00 AND detected_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        
        if ($high_value_count > 0) {
            set_transient('contentguard_recent_high_value', $high_value_count, HOUR_IN_SECONDS);
            ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <strong>ContentGuard Alert:</strong> 
                    <?php echo $high_value_count; ?> high-value AI bot detections this week! 
                    Your content is worth significant licensing revenue.
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
                <li>✅ Licensing opportunity assessment</li>
                <li>✅ Portfolio analysis and annual projections</li>
                <li>✅ Detailed valuation breakdowns</li>
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
 * REST API endpoint for external integrations
 */
function contentguard_register_rest_routes() {
    register_rest_route('contentguard/v2', '/valuation/(?P<detection_id>\d+)', [
        'methods' => 'GET',
        'callback' => 'contentguard_rest_get_valuation',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ]);
    
    register_rest_route('contentguard/v2', '/portfolio', [
        'methods' => 'GET',
        'callback' => 'contentguard_rest_get_portfolio',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ]);
}
add_action('rest_api_init', 'contentguard_register_rest_routes');

function contentguard_rest_get_valuation($request) {
    $detection_id = $request['detection_id'];
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'contentguard_detections';
    $detection = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE id = %d",
        $detection_id
    ), ARRAY_A);
    
    if (!$detection) {
        return new WP_Error('not_found', 'Detection not found', ['status' => 404]);
    }
    
    $valuation_engine = new ContentGuardValuation();
    $valuation = $valuation_engine->calculate_content_value($detection);
    
    return rest_ensure_response([
        'detection' => $detection,
        'valuation' => $valuation,
        'api_version' => '2.0'
    ]);
}

function contentguard_rest_get_portfolio($request) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'contentguard_detections';
    
    $days = $request->get_param('days') ?: 30;
    $detections = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_name WHERE detected_at > DATE_SUB(NOW(), INTERVAL %d DAY) ORDER BY detected_at DESC",
        $days
    ), ARRAY_A);
    
    $portfolio_analysis = contentguard_get_portfolio_analysis($detections);
    
    return rest_ensure_response([
        'portfolio_analysis' => $portfolio_analysis,
        'detection_count' => count($detections),
        'period_days' => $days,
        'api_version' => '2.0'
    ]);
}

/**
 * Enhanced debugging and logging
 */
if (defined('WP_DEBUG') && WP_DEBUG) {
    function contentguard_debug_log($message, $data = null) {
        $log_message = '[ContentGuard Enhanced] ' . $message;
        if ($data) {
            $log_message .= ' | Data: ' . json_encode($data);
        }
        error_log($log_message);
    }
    
    function contentguard_test_enhanced_system() {
        $valuation_engine = new ContentGuardValuation();
        
        $test_cases = [
            [
                'name' => 'OpenAI Premium Content',
                'detection' => [
                    'company' => 'OpenAI',
                    'bot_type' => 'GPTBot',
                    'request_uri' => '/premium-tutorial-guide',
                    'risk_level' => 'high',
                    'confidence' => 95,
                    'commercial_risk' => true
                ],
                'metadata' => [
                    'content_category' => 'educational',
                    'temporal_value' => 'evergreen',
                    'domain_authority' => 65
                ]
            ],
            [
                'name' => 'Anthropic Image Content',
                'detection' => [
                    'company' => 'Anthropic',
                    'bot_type' => 'ClaudeBot',
                    'request_uri' => '/images/product-showcase.jpg',
                    'risk_level' => 'high',
                    'confidence' => 92,
                    'commercial_risk' => true
                ],
                'metadata' => [
                    'content_category' => 'commercial',
                    'domain_authority' => 55
                ]
            ]
        ];
        
        foreach ($test_cases as $test) {
            $valuation = $valuation_engine->calculate_content_value($test['detection'], $test['metadata']);
            contentguard_debug_log("Test Case: {$test['name']}", [
                'estimated_value' => $valuation['estimated_value'],
                'content_type' => $valuation['breakdown']['content_type'],
                'licensing_potential' => $valuation['licensing_potential'],
                'market_position' => $valuation['market_comparison']['position']
            ]);
        }
    }
    
    add_action('wp_loaded', 'contentguard_test_enhanced_system');
}

?>