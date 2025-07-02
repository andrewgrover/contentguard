<?php
/**
 * Plontis Core Class
 * Handles bot detection and logging functionality
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Plontis_Core {
    
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
            'risk_level' => 'high',
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

    /**
     * Enhanced value system components
     */
    private $value_calculator;
    private $content_analyzer;

    public function __construct() {
        $this->value_calculator = new PlontisValueCalculator();
        $this->content_analyzer = new PlontisContentAnalyzer();
    }

    public function init() {
        // Hook into WordPress request processing
        add_action('wp', [$this, 'detect_and_log_bots']);
    }

    public function activate() {
        $this->create_enhanced_tables();
        $this->set_default_options();
        $this->add_enhanced_sample_data();
    }

    /**
     * Create enhanced database tables with value integration support
     */
    private function create_enhanced_tables() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'plontis_detections';
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
            is_demo_data tinyint(1) DEFAULT 0,
            detected_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY ip_address (ip_address),
            KEY detected_at (detected_at),
            KEY bot_type (bot_type),
            KEY company (company),
            KEY estimated_value (estimated_value),
            KEY licensing_potential (licensing_potential),
            KEY is_demo_data (is_demo_data)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
    * Output debug messages to browser console
    */
    private function output_console_debug($messages) {
        echo '<script>';
        echo 'console.group("=== PLONTIS DATABASE DEBUG ===");';
        foreach ($messages as $message) {
            $safe_message = addslashes($message);
            echo "console.log('$safe_message');";
        }
        echo 'console.groupEnd();';
        echo '</script>';
    }

    /**
    * Check if user has real detections
    */
    public function has_real_detections() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'plontis_detections';
        
        $real_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM $table_name WHERE (is_demo_data IS NULL OR is_demo_data = 0)"
        );
        
        echo '<script>console.log("has_real_detections: found ' . $real_count . ' real detections");</script>';
        return $real_count > 0;
    }

    /**
    * Get real detections only
    */
    public function get_real_detections($limit = 20) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'plontis_detections';
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name 
            WHERE (is_demo_data IS NULL OR is_demo_data = 0)
            AND detected_at > DATE_SUB(NOW(), INTERVAL 30 DAY) 
            ORDER BY detected_at DESC 
            LIMIT %d",
            $limit
        ), ARRAY_A);
        
        echo '<script>console.log("get_real_detections: returning ' . count($results) . ' results");</script>';
        return $results;
    }

    /**
    * Get demo detections only
    */
    public function get_demo_detections() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'plontis_detections';
        
        return $wpdb->get_results(
            "SELECT * FROM $table_name 
            WHERE is_demo_data = 1
            ORDER BY detected_at DESC",
            ARRAY_A
        );
    }

    /**
    * Clear demo data
    */
    public function clear_demo_data() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'plontis_detections';
        
        $deleted = $wpdb->query("DELETE FROM $table_name WHERE is_demo_data = 1");
        echo '<script>console.log("clear_demo_data: deleted ' . $deleted . ' demo records");</script>';
        return $deleted;
    }

    private function check_high_value_alert($valuation, $detection_data) {
        $alert_enabled = get_option('plontis_value_alerts', false);
        $threshold = get_option('plontis_alert_threshold', 100);
        
        if (!$alert_enabled || $valuation['estimated_value'] < $threshold) {
            return;
        }
        
        // Throttle alerts - only send once per hour for same company
        $throttle_key = 'plontis_alert_' . $detection_data['company'];
        if (get_transient($throttle_key)) {
            return;
        }
        
        $settings = get_option('plontis_settings');
        $to = $settings['notification_email'] ?? get_option('admin_email');
        $subject = "🚨 High-Value AI Bot Alert - {$detection_data['company']} - $" . number_format($valuation['estimated_value'], 2);
        
        $message = "PLONTIS HIGH-VALUE ALERT\n";
        $message .= "========================\n\n";
        $message .= "A high-value AI bot has been detected accessing your content:\n\n";
        $message .= "Company: {$detection_data['company']}\n";
        $message .= "Content Value: $" . number_format($valuation['estimated_value'], 2) . "\n";
        $message .= "Page Accessed: {$detection_data['request_uri']}\n";
        $message .= "Risk Level: {$detection_data['risk_level']}\n";
        $message .= "Licensing Potential: {$valuation['licensing_potential']['potential']}\n";
        $message .= "Detection Time: " . current_time('Y-m-d H:i:s') . "\n\n";
        
        $message .= "IMMEDIATE ACTION RECOMMENDED:\n";
        $message .= "• Document this high-value access for licensing negotiations\n";
        $message .= "• Consider reaching out to {$detection_data['company']} for licensing opportunities\n";
        $message .= "• Review and optimize this content for increased AI training value\n\n";
        
        $message .= "View detailed analysis: " . admin_url('admin.php?page=plontis-valuation') . "\n";
        $message .= "Start licensing: https://plontis.com\n";
        
        wp_mail($to, $subject, $message);
        
        // Set throttle for 1 hour
        set_transient($throttle_key, true, HOUR_IN_SECONDS);
    }

    private function set_default_options() {
        // Check if this is a fresh install or update
        $existing_settings = get_option('plontis_settings', null);
        
        $default_settings = [
            'enable_detection' => true,
            'enable_notifications' => true,
            'notification_email' => get_option('admin_email'),
            'log_retention_days' => 90,
            'track_legitimate_bots' => false,
            'enhanced_valuation' => true,
            'valuation_version' => '2.0',
            'high_value_threshold' => 50.00,
            'licensing_notification_threshold' => 100.00,
            
            // Demo mode settings - smart defaults based on real data
            'demo_mode' => false,  // You have real data, so default to live mode
            'show_demo_notice' => false,
            'first_install' => false,
            'demo_disabled_at' => current_time('mysql'),
            'real_detections_count' => $this->has_real_detections() ? 4 : 0
        ];
        
        if ($existing_settings === null) {
            // Fresh install
            add_option('plontis_settings', $default_settings);
            add_option('plontis_installed_at', current_time('mysql'));
        } else {
            // Update existing settings with new keys only
            $updated_settings = array_merge($default_settings, $existing_settings);
            update_option('plontis_settings', $updated_settings);
        }
    }

    /**
     * Enhanced sample data using value calculation system
     */
    public function add_enhanced_sample_data() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'plontis_detections';
        
        // Check if we already have sample data
        $existing = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE ip_address = '127.0.0.1'");
        if ($existing > 0) {
            return;
        }
        
        $sample_detections = [
            [
                'user_agent' => 'Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)',
                'ip_address' => '127.0.0.1',
                'request_uri' => '/this-is-fake',
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
                'request_uri' => '/this-is-fake',
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
                'request_uri' => '/this-is-fake/demo',
                'bot_type' => 'Google',
                'company' => 'Google',
                'risk_level' => 'high',
                'confidence' => 95,
                'commercial_risk' => 1,
                'detected_at' => date('Y-m-d H:i:s', strtotime('-6 hours'))
            ]
        ];
        
        foreach ($sample_detections as $data) {
            // Analyze content using our enhanced system
            $content_metadata = $this->content_analyzer->analyzeContent($data['request_uri']);
            
            // Calculate enhanced value using our value calculator
            $detection_data = [
                'company' => $data['company'],
                'bot_type' => $data['bot_type'],
                'request_uri' => $data['request_uri'],
                'risk_level' => $data['risk_level'],
                'confidence' => $data['confidence'],
                'commercial_risk' => $data['commercial_risk']
            ];
            
            $valuation = $this->value_calculator->calculateContentValue($detection_data, $content_metadata);
            
            // Add enhanced data to sample
            $data['estimated_value'] = $valuation['estimated_value'];
            $data['content_type'] = $content_metadata['content_type'] ?? 'article';
            $data['content_quality'] = $content_metadata['quality_score'] ?? 50;
            $data['word_count'] = $content_metadata['word_count'] ?? 0;
            $data['technical_depth'] = $content_metadata['technical_depth'] ?? 'basic';
            $data['value_breakdown'] = json_encode($valuation['breakdown']);
            $data['licensing_potential'] = $valuation['licensing_potential']['potential'];
            $data['market_context'] = json_encode($valuation['market_context']);
            $data['is_demo'] = 1;
            
            $wpdb->insert($table_name, $data);
        }
    }

    /**
     * Enhanced bot detection with value calculation
     */
    public function detect_and_log_bots() {
        $settings = get_option('plontis_settings');
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
            // Log enhanced detection with value calculation
            $valuation = $this->log_enhanced_detection($user_agent, $ip_address, $request_uri, $detection);
            
            // Enhanced notification threshold based on value
            $high_value_threshold = $settings['high_value_threshold'] ?? 50.00;
            if ($valuation['estimated_value'] >= $high_value_threshold && $settings['enable_notifications']) {
                $this->send_enhanced_notification($detection, $request_uri, $valuation);
            }
        }
    }

    /**
     * Enhanced detection logging with our value calculation system
     */
    private function log_enhanced_detection($user_agent, $ip_address, $request_uri, $detection) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'plontis_detections';
        
        // Analyze content using our content analyzer
        $content_metadata = $this->content_analyzer->analyzeContent($request_uri);
        
        // Calculate value using our value calculator
        $detection_data = array_merge($detection, [
            'request_uri' => $request_uri
        ]);
        
        $valuation = $this->value_calculator->calculateContentValue($detection_data, $content_metadata);

        $this->check_high_value_alert($valuation, $detection_data);
       
        // Insert enhanced detection record
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
                'content_type' => $content_metadata['content_type'] ?? 'article',
                'content_quality' => $content_metadata['quality_score'] ?? 50,
                'word_count' => $content_metadata['word_count'] ?? 0,
                'technical_depth' => $content_metadata['technical_depth'] ?? 'basic',
                'licensing_potential' => $valuation['licensing_potential']['potential'],
                'value_breakdown' => json_encode($valuation['breakdown']),
                'market_context' => json_encode($valuation['market_context']),
                'detected_at' => current_time('mysql')
            ]
        );
        
        do_action('plontis_detection_logged', 
            array_merge($detection, ['request_uri' => $request_uri]), 
            $content_metadata, 
            $valuation
        );
        return $valuation;
    }

    /**
     * Enhanced notification system
     */
    private function send_enhanced_notification($detection, $request_uri, $valuation) {
        // Throttle notifications
        $throttle_key = "plontis_notification_{$detection['bot_type']}";
        if (get_transient($throttle_key)) {
            return;
        }

        $settings = get_option('plontis_settings');
        $to = $settings['notification_email'];
        $subject = "High-Value AI Bot Detected - {$detection['company']} - \${$valuation['estimated_value']}";
        
        $message = "Plontis has detected a high-value AI bot accessing your content:\n\n";
        $message .= "Company: {$detection['company']}\n";
        $message .= "Bot Type: {$detection['bot_type']}\n";
        $message .= "Page Accessed: {$request_uri}\n";
        $message .= "Risk Level: {$detection['risk_level']}\n";
        $message .= "Commercial Risk: " . ($detection['commercial_risk'] ? 'Yes' : 'No') . "\n\n";
        
        $message .= "ENHANCED VALUATION:\n";
        $message .= "Estimated Value: \${$valuation['estimated_value']}\n";
        $message .= "Content Type: {$valuation['breakdown']['content_type']}\n";
        $message .= "Licensing Potential: {$valuation['licensing_potential']['potential']}\n";
        $message .= "Market Context: {$valuation['market_context']['market_position']}\n\n";
        
        if (!empty($valuation['licensing_potential']['recommendation'])) {
            $message .= "LICENSING RECOMMENDATION:\n";
            $message .= "{$valuation['licensing_potential']['recommendation']}\n\n";
        }
        
        $message .= "Value Breakdown:\n";
        foreach ($valuation['breakdown'] as $factor => $value) {
            if (is_numeric($value)) {
                $message .= "- " . ucfirst(str_replace('_', ' ', $factor)) . ": \${$value}\n";
            } else {
                $message .= "- " . ucfirst(str_replace('_', ' ', $factor)) . ": {$value}\n";
            }
        }
        
        $message .= "\nTime: " . current_time('Y-m-d H:i:s') . "\n";
        $message .= "View detailed analysis: " . admin_url('admin.php?page=plontis') . "\n";
        $message .= "Join Plontis platform: https://plontis.ai/licensing";

        wp_mail($to, $subject, $message);
        set_transient($throttle_key, true, HOUR_IN_SECONDS * 4); // 4 hour throttle
    }

    public function analyze_user_agent($user_agent) {
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

    public function cleanup_old_logs() {
        global $wpdb;
        $settings = get_option('plontis_settings');
        $retention_days = $settings['log_retention_days'] ?? 90;
        
        $table_name = $wpdb->prefix . 'plontis_detections';
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table_name WHERE detected_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $retention_days
        ));
    }
}
?>