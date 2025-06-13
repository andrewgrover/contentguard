<?php
/**
 * Plugin Name: ContentGuard - AI Bot Detection
 * Plugin URI: https://contentguard.ai
 * Description: Detect and track AI bots scraping your content. See which AI companies are using your work for training and get paid for it.
 * Version: 1.0.0
 * Author: ContentGuard
 * License: GPL v2 or later
 * Text Domain: contentguard
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('CONTENTGUARD_VERSION', '1.0.0');
define('CONTENTGUARD_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CONTENTGUARD_PLUGIN_PATH', plugin_dir_path(__FILE__));

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

    public function __construct() {
        add_action('init', [$this, 'init']);
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }

    public function ajax_test() {
        wp_send_json_success(['message' => 'AJAX is working!', 'time' => current_time('mysql')]);
    }

    public function init() {
        // Hook into WordPress request processing
        add_action('wp', [$this, 'detect_and_log_bots']);
        
        // Admin interface
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'admin_scripts']);
        
        // AJAX handlers
        add_action('wp_ajax_contentguard_get_detections', [$this, 'ajax_get_detections']);
        add_action('wp_ajax_contentguard_get_stats', [$this, 'ajax_get_stats']);
        add_action('wp_ajax_contentguard_test', [$this, 'ajax_test']);
        
        // Dashboard widget
        add_action('wp_dashboard_setup', [$this, 'add_dashboard_widget']);
        
        // Cron for cleanup old logs
        add_action('contentguard_cleanup_logs', [$this, 'cleanup_old_logs']);
        if (!wp_next_scheduled('contentguard_cleanup_logs')) {
            wp_schedule_event(time(), 'weekly', 'contentguard_cleanup_logs');
        }
    }

    public function add_sample_data() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'contentguard_detections';
        
        // Check if we already have sample data
        $existing = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE ip_address = '127.0.0.1'");
        if ($existing > 0) {
            return; // Sample data already exists
        }
        
        // Add sample detection data
        $sample_data = [
            [
                'user_agent' => 'Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)',
                'ip_address' => '127.0.0.1',
                'request_uri' => '/sample-article-1',
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
                'request_uri' => '/sample-article-2',
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
                'request_uri' => '/sample-article-3',
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
                'request_uri' => '/sample-article-4',
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
                'request_uri' => '/sample-article-5',
                'bot_type' => 'CommonCrawl',
                'company' => 'Common Crawl',
                'risk_level' => 'high',
                'confidence' => 95,
                'commercial_risk' => 0,
                'detected_at' => date('Y-m-d H:i:s', strtotime('-10 hours'))
            ]
        ];
        
        foreach ($sample_data as $data) {
            $wpdb->insert($table_name, $data);
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
            detected_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY ip_address (ip_address),
            KEY detected_at (detected_at),
            KEY bot_type (bot_type)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    private function set_default_options() {
        add_option('contentguard_settings', [
            'enable_detection' => true,
            'enable_notifications' => true,
            'notification_email' => get_option('admin_email'),
            'log_retention_days' => 90,
            'track_legitimate_bots' => false
        ]);
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
            $this->log_detection($user_agent, $ip_address, $request_uri, $detection);
            
            // Send notification for high-risk detections
            if ($detection['risk_level'] === 'high' && $settings['enable_notifications']) {
                $this->maybe_send_notification($detection, $request_uri);
            }
        }
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

        // Basic behavioral analysis
        $suspicion_score = 0;
        
        // Check for bot-like patterns in user agent
        $bot_indicators = ['bot', 'crawler', 'spider', 'scraper', 'fetch'];
        foreach ($bot_indicators as $indicator) {
            if (stripos($user_agent, $indicator) !== false) {
                $suspicion_score += 30;
                $result['evidence'][] = "Contains '{$indicator}' in user agent";
            }
        }

        // Check for missing typical browser indicators
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

    private function log_detection($user_agent, $ip_address, $request_uri, $detection) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'contentguard_detections';

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
                'detected_at' => current_time('mysql')
            ]
        );
    }

    private function maybe_send_notification($detection, $request_uri) {
        // Throttle notifications - only send once per hour for same bot type
        $last_notification = get_transient("contentguard_notification_{$detection['bot_type']}");
        if ($last_notification) {
            return;
        }

        $settings = get_option('contentguard_settings');
        $to = $settings['notification_email'];
        $subject = "AI Bot Detected on Your Website - {$detection['company']}";
        
        $message = "ContentGuard has detected a high-risk AI bot on your website:\n\n";
        $message .= "Company: {$detection['company']}\n";
        $message .= "Bot Type: {$detection['bot_type']}\n";
        $message .= "Page Accessed: {$request_uri}\n";
        $message .= "Risk Level: {$detection['risk_level']}\n";
        $message .= "Commercial Risk: " . ($detection['commercial_risk'] ? 'Yes' : 'No') . "\n";
        $message .= "Time: " . current_time('Y-m-d H:i:s') . "\n\n";
        $message .= "This AI company may be using your content for training their models.\n";
        $message .= "View your full ContentGuard dashboard: " . admin_url('admin.php?page=contentguard') . "\n\n";
        $message .= "Learn about licensing your content: https://contentguard.ai/licensing";

        wp_mail($to, $subject, $message);

        // Set notification throttle
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
            'nonce' => wp_create_nonce('contentguard_nonce')
        ]);
    }

    public function admin_page() {
        ?>
        <div class="wrap contentguard-admin">
            <?php
            // DEBUG: Check database contents
            global $wpdb;
            $table_name = $wpdb->prefix . 'contentguard_detections';
            $total_rows = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
            $recent_rows = $wpdb->get_results("SELECT * FROM $table_name ORDER BY detected_at DESC LIMIT 5");
            ?>

            <div class="notice notice-info" style="margin-bottom: 20px;">
                <h4>🔍 Debug Info:</h4>
                <p><strong>Total rows in database:</strong> <?php echo $total_rows; ?></p>
                <p><strong>Recent detections:</strong></p>
                <?php if ($recent_rows): ?>
                    <ul>
                    <?php foreach ($recent_rows as $row): ?>
                        <li><?php echo esc_html($row->company); ?> - <?php echo esc_html($row->bot_type); ?> - <?php echo esc_html($row->detected_at); ?></li>
                    <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p>No detections found in database.</p>
                <?php endif; ?>
            </div>
            <h1>
                <span class="dashicons dashicons-shield-alt"></span>
                ContentGuard - AI Bot Detection
            </h1>
            
            <div class="contentguard-stats-grid">
                <div class="contentguard-stat-card">
                    <h3>Total AI Bots Detected</h3>
                    <div class="stat-number" id="total-bots">-</div>
                    <span class="stat-period">Last 30 days</span>
                </div>
                
                <div class="contentguard-stat-card">
                    <h3>High-Risk Commercial Bots</h3>
                    <div class="stat-number" id="commercial-bots">-</div>
                    <span class="stat-period">Potential licensing value</span>
                </div>
                
                <div class="contentguard-stat-card">
                    <h3>Most Active Company</h3>
                    <div class="stat-number" id="top-company">-</div>
                    <span class="stat-period">This month</span>
                </div>
                
                <div class="contentguard-stat-card">
                    <h3>Content Value Estimate</h3>
                    <div class="stat-number" id="content-value">$-</div>
                    <span class="stat-period">Based on scraping activity</span>
                </div>
            </div>

            <div class="contentguard-dashboard-grid">
                <div class="contentguard-panel">
                    <h2>AI Bot Activity Trends</h2>
                    <canvas id="activity-chart" width="400" height="200"></canvas>
                </div>
                
                <div class="contentguard-panel">
                    <h2>AI Companies Detected</h2>
                    <canvas id="companies-chart" width="400" height="200"></canvas>
                </div>
            </div>
            <!-- Testing Section -->
            <div class="contentguard-panel">
                <h2>Test Bot Detection</h2>
                <p>Test any user agent string to see how ContentGuard would classify it. Bot detections will be logged to your database.</p>
                
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
                                <input type="submit" name="test_detection" class="button button-primary" value="Test & Log Detection" />
                            </td>
                        </tr>
                    </table>
                </form>
                
                <!-- Quick Test Forms -->
                <div style="margin-bottom: 20px;">
                    <h4>Quick Tests:</h4>
                    <form method="post" action="" style="display: inline;">
                        <?php wp_nonce_field('contentguard_test', 'test_nonce'); ?>
                        <input type="hidden" name="test_user_agent" value="Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)" />
                        <input type="submit" name="test_detection" class="button" value="Test OpenAI GPTBot" />
                    </form>
                    
                    <form method="post" action="" style="display: inline; margin-left: 10px;">
                        <?php wp_nonce_field('contentguard_test', 'test_nonce'); ?>
                        <input type="hidden" name="test_user_agent" value="ClaudeBot/1.0" />
                        <input type="submit" name="test_detection" class="button" value="Test Anthropic Claude" />
                    </form>
                    
                    <form method="post" action="" style="display: inline; margin-left: 10px;">
                        <?php wp_nonce_field('contentguard_test', 'test_nonce'); ?>
                        <input type="hidden" name="test_user_agent" value="Google-Extended/1.0" />
                        <input type="submit" name="test_detection" class="button" value="Test Google Extended" />
                    </form>
                    
                    <form method="post" action="" style="display: inline; margin-left: 10px;">
                        <?php wp_nonce_field('contentguard_test', 'test_nonce'); ?>
                        <input type="hidden" name="test_user_agent" value="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36" />
                        <input type="submit" name="test_detection" class="button" value="Test Regular Browser" />
                    </form>
                </div>
                
                <?php
                // Handle test detection and LOG TO DATABASE
                if (isset($_POST['test_detection']) && wp_verify_nonce($_POST['test_nonce'], 'contentguard_test')) {
                    $test_user_agent = sanitize_text_field($_POST['test_user_agent']);
                    if ($test_user_agent) {
                        // Analyze the user agent
                        $detection = $this->analyze_user_agent($test_user_agent);
                        
                        // ALWAYS log the test (even if not a bot) for demonstration
                        global $wpdb;
                        $table_name = $wpdb->prefix . 'contentguard_detections';
                        
                        $log_data = [
                            'user_agent' => $test_user_agent,
                            'ip_address' => '127.0.0.1', // Test IP
                            'request_uri' => '/test-detection-' . time(), // Unique test URI
                            'bot_type' => $detection['bot_type'],
                            'company' => $detection['company'],
                            'risk_level' => $detection['risk_level'],
                            'confidence' => $detection['confidence'],
                            'commercial_risk' => $detection['commercial_risk'] ? 1 : 0,
                            'detected_at' => current_time('mysql')
                        ];
                        
                        $inserted = $wpdb->insert($table_name, $log_data);
                        
                        if ($detection['is_bot']) {
                            echo '<div class="notice notice-success" style="margin-top: 15px;">';
                            echo '<h4>✅ Bot Detected and Logged!</h4>';
                        } else {
                            echo '<div class="notice notice-info" style="margin-top: 15px;">';
                            echo '<h4>ℹ️ Test Logged (Not a Bot):</h4>';
                        }
                        
                        echo '<p><strong>User Agent:</strong> <code>' . esc_html($test_user_agent) . '</code></p>';
                        echo '<p><strong>Is Bot:</strong> ' . ($detection['is_bot'] ? '<span style="color: #d63384; font-weight: bold;">Yes</span>' : '<span style="color: #198754; font-weight: bold;">No</span>') . '</p>';
                        
                        if ($detection['is_bot']) {
                            echo '<p><strong>Company:</strong> ' . esc_html($detection['company'] ?: 'Unknown') . '</p>';
                            echo '<p><strong>Bot Type:</strong> ' . esc_html($detection['bot_type'] ?: 'Unknown') . '</p>';
                            
                            $risk_color = '';
                            switch($detection['risk_level']) {
                                case 'high': $risk_color = '#dc3545'; break;
                                case 'medium': $risk_color = '#ffc107'; break;
                                case 'low': $risk_color = '#28a745'; break;
                            }
                            echo '<p><strong>Risk Level:</strong> <span style="color: ' . $risk_color . '; font-weight: bold; text-transform: uppercase;">' . esc_html($detection['risk_level']) . '</span></p>';
                            echo '<p><strong>Commercial Risk:</strong> ' . ($detection['commercial_risk'] ? '<span style="color: #d63384; font-weight: bold;">Yes - Potential licensing opportunity</span>' : '<span style="color: #6c757d;">No</span>') . '</p>';
                            echo '<p><strong>Confidence:</strong> ' . $detection['confidence'] . '%</p>';
                        }
                        
                        if ($inserted !== false) {
                            echo '<p style="background: #e7f3ff; padding: 10px; border-radius: 4px; margin-top: 10px;">';
                            echo '<strong>📊 Database Updated:</strong> This detection has been added to your database. Database ID: ' . $wpdb->insert_id;
                            echo '</p>';
                            
                            echo '<p style="margin-top: 15px;">';
                            echo '<button type="button" onclick="location.reload();" class="button button-primary">Refresh Page to See Updated Stats</button>';
                            echo '</p>';
                        } else {
                            echo '<p style="background: #f8d7da; padding: 10px; border-radius: 4px; margin-top: 10px;">';
                            echo '<strong>❌ Database Error:</strong> Failed to log detection.';
                            echo '</p>';
                        }
                        
                        if ($detection['is_bot'] && $detection['commercial_risk']) {
                            echo '<div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; border-radius: 4px; margin-top: 10px;">';
                            echo '<strong>💰 Licensing Opportunity:</strong> This bot represents potential revenue. ';
                            echo '<a href="https://contentguard.ai/join" target="_blank">Join ContentGuard platform</a> to start earning from your content.';
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
                    <div class="contentguard-loading">Loading detection data...</div>
                </div>
            </div>

            <div class="contentguard-cta">
                <h3>Ready to Get Paid for Your Content?</h3>
                <p>Your content is being used to train AI models. Join our licensing platform to start earning from your work.</p>
                <a href="https://contentguard.ai/join" class="button button-primary button-hero" target="_blank">
                    Join ContentGuard Platform
                </a>
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
                'track_legitimate_bots' => isset($_POST['track_legitimate_bots'])
            ];
            update_option('contentguard_settings', $settings);
            echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
        }

        $settings = get_option('contentguard_settings');
        ?>
        <div class="wrap">
            <h1>ContentGuard Settings</h1>
            
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
                        <th scope="row">Email Notifications</th>
                        <td>
                            <input type="checkbox" name="enable_notifications" <?php checked($settings['enable_notifications']); ?> />
                            <p class="description">Get notified when high-risk AI bots are detected</p>
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
                
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function ajax_get_detections() {
        check_ajax_referer('contentguard_nonce', 'nonce');
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'contentguard_detections';
        
        $limit = intval($_POST['limit'] ?? 20);
        $offset = intval($_POST['offset'] ?? 0);
        
        $detections = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE detected_at > DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY detected_at DESC LIMIT %d OFFSET %d",
            $limit, $offset
        ));
        
        wp_send_json_success($detections);
    }

    public function ajax_get_stats() {
        check_ajax_referer('contentguard_nonce', 'nonce');
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'contentguard_detections';
        
        // Total bots (all time for testing)
        $total_bots = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE detected_at > DATE_SUB(NOW(), INTERVAL 30 DAY)");
        
        // Commercial risk bots (all time for testing)
        $commercial_bots = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE commercial_risk = 1") ?: 0;
        
        // Top company (all time for testing)
        $top_company = $wpdb->get_var("SELECT company FROM $table_name GROUP BY company ORDER BY COUNT(*) DESC LIMIT 1") ?: 'None detected';
        
        // Estimated content value
        $content_value = $commercial_bots * 2.50;
        
        // Daily activity for chart (last 7 days) - simplified
        $daily_activity = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE DATE(detected_at) = %s",
                $date
            )) ?: 0;
            $daily_activity[] = ['date' => $date, 'count' => (int)$count];
        }
        
        // Company breakdown
        $company_breakdown = $wpdb->get_results("SELECT company, COUNT(*) as count FROM $table_name GROUP BY company ORDER BY count DESC LIMIT 10") ?: [];
        
        // Debug info
        $debug_info = [
            'table_exists' => $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name,
            'total_rows' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name"),
            'sql_errors' => $wpdb->last_error
        ];
        
        wp_send_json_success([
            'total_bots' => (int)$total_bots,
            'commercial_bots' => (int)$commercial_bots,
            'top_company' => $top_company,
            'content_value' => number_format($content_value, 2),
            'daily_activity' => $daily_activity,
            'company_breakdown' => $company_breakdown,
            'debug' => $debug_info
        ]);
    }

    public function add_dashboard_widget() {
        wp_add_dashboard_widget(
            'contentguard_widget',
            'ContentGuard - AI Bot Activity',
            [$this, 'dashboard_widget_content']
        );
    }

    public function dashboard_widget_content() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'contentguard_detections';
        
        $recent_bots = $wpdb->get_var(
            "SELECT COUNT(*) FROM $table_name WHERE detected_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        
        $high_risk = $wpdb->get_var(
            "SELECT COUNT(*) FROM $table_name WHERE risk_level = 'high' AND detected_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        
        ?>
        <div class="contentguard-widget">
            <p><strong><?php echo $recent_bots; ?></strong> AI bots detected this week</p>
            <p><strong><?php echo $high_risk; ?></strong> high-risk commercial bots</p>
            <p>
                <a href="<?php echo admin_url('admin.php?page=contentguard'); ?>" class="button">
                    View Full Report
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

// Initialize the plugin
new ContentGuardPlugin();
?>