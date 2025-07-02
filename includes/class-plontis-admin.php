<?php
/**
 * Plontis Admin Class
 * Handles all admin interface functionality - FIXED SCRIPT LOADING
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Plontis_Admin {
    
    private $value_calculator;
    private $content_analyzer;
    private $core;

    public function __construct() {
        $this->value_calculator = new PlontisValueCalculator();
        $this->content_analyzer = new PlontisContentAnalyzer();
        $this->core = new Plontis_Core();
        add_action('admin_init', [$this, 'handle_export_request']);
    }

    public function init() {
        // Admin interface
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'admin_scripts']);
        
        // Dashboard widget
        add_action('wp_dashboard_setup', [$this, 'add_dashboard_widget']);
        add_action('admin_init', [$this, 'handle_report_actions']);
        add_action('admin_notices', [$this, 'show_test_api_key']);
    }

    public function admin_scripts($hook) {
        // Debug what page we're on
        error_log("Plontis: admin_scripts called on hook: $hook");
        
        // Load on ALL admin pages for now to debug
        // Later we can restrict to: if (strpos($hook, 'plontis') === false) return;
        
        // Make sure we have the plugin URL constant
        if (!defined('PLONTIS_PLUGIN_URL')) {
            error_log("Plontis: PLONTIS_PLUGIN_URL not defined!");
            return;
        }
        
        $plugin_url = PLONTIS_PLUGIN_URL;
        $version = defined('PLONTIS_VERSION') ? PLONTIS_VERSION : '1.0.3';
        $css_version = $version . '-' . time(); // This forces reload
        
        // Build file paths
        $admin_js_url = $plugin_url . 'admin.js';
        $admin_css_url = $plugin_url . 'admin.css';
        $chart_js_url = 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js';
        
        // Check if files exist
        $admin_js_path = PLONTIS_PLUGIN_PATH . 'admin.js';
        $admin_css_path = PLONTIS_PLUGIN_PATH . 'admin.css';

        if (file_exists($admin_css_path)) {
            wp_enqueue_style('plontis-admin', $admin_css_url, [], $css_version);
            error_log("Plontis: admin.css enqueued successfully with version: " . $css_version);
        } else {
            error_log("Plontis: admin.css file not found at: " . $admin_css_path);
        }
        
        error_log("Plontis: Checking files...");
        error_log("  admin.js exists: " . (file_exists($admin_js_path) ? 'YES' : 'NO') . " at $admin_js_path");
        error_log("  admin.css exists: " . (file_exists($admin_css_path) ? 'YES' : 'NO') . " at $admin_css_path");
        error_log("  admin.js URL: $admin_js_url");
        
        // Enqueue Chart.js first
        wp_enqueue_script('chart-js', $chart_js_url, [], '3.9.1');
        
        // Enqueue our admin styles
        if (file_exists($admin_css_path)) {
            wp_enqueue_style('plontis-admin', $admin_css_url, [], $version);
            error_log("Plontis: admin.css enqueued successfully");
        } else {
            error_log("Plontis: admin.css file not found!");
        }
        
        // Enqueue our admin scripts
        if (file_exists($admin_js_path)) {
            wp_enqueue_script('plontis-admin', $admin_js_url, ['jquery', 'chart-js'], $version . '-' . time(), true);
            error_log("Plontis: admin.js enqueued successfully");
            
            // Localize script with AJAX data
            wp_localize_script('plontis-admin', 'plontis_ajax', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('plontis_nonce'),
                'version' => $version,
                'plugin_url' => $plugin_url,
                'settings_url' => admin_url('admin.php?page=plontis-settings'),
                'debug' => WP_DEBUG
            ]);
            error_log("Plontis: AJAX data localized");
        } else {
            error_log("Plontis: admin.js file not found at $admin_js_path");
        }
    }

    public function schedule_automated_reports() {
        // Hook into WordPress cron
        add_action('plontis_weekly_report', [$this, 'send_weekly_report']);
        add_action('plontis_monthly_report', [$this, 'send_monthly_report']);
        
        // Schedule events if not already scheduled
        if (!wp_next_scheduled('plontis_weekly_report')) {
            wp_schedule_event(time(), 'weekly', 'plontis_weekly_report');
        }
        
        if (!wp_next_scheduled('plontis_monthly_report')) {
            wp_schedule_event(time(), 'monthly', 'plontis_monthly_report');
        }
    }

    public function send_weekly_report() {
        $settings = get_option('plontis_settings');
        if (!($settings['enable_automated_reports'] ?? false)) {
            return;
        }
        
        $email = $settings['notification_email'] ?? get_option('admin_email');
        $subject = 'Plontis Weekly Valuation Report - ' . get_bloginfo('name');
        
        // Generate report data
        global $wpdb;
        $table_name = $wpdb->prefix . 'plontis_detections';
        $detections = $wpdb->get_results(
            "SELECT * FROM $table_name WHERE detected_at > DATE_SUB(NOW(), INTERVAL 7 DAY)",
            ARRAY_A
        );
        
        if (empty($detections)) {
            return; // No data to report
        }
        
        // Process detections with enhanced values
        $enhanced_detections = [];
        foreach ($detections as $detection) {
            try {
                $content_metadata = $this->content_analyzer->analyzeContent($detection['request_uri']);
                $detection_data = [
                    'company' => $detection['company'],
                    'bot_type' => $detection['bot_type'],
                    'request_uri' => $detection['request_uri'],
                    'risk_level' => $detection['risk_level'],
                    'confidence' => intval($detection['confidence'] ?? 50),
                    'commercial_risk' => $detection['commercial_risk']
                ];
                
                $valuation = $this->value_calculator->calculateContentValue($detection_data, $content_metadata);
                $detection['estimated_value'] = $valuation['estimated_value'];
                $enhanced_detections[] = $detection;
            } catch (Exception $e) {
                $detection['estimated_value'] = 0.00;
                $enhanced_detections[] = $detection;
            }
        }
        
        $portfolio_analysis = $this->value_calculator->calculatePortfolioValue($enhanced_detections);
        
        // Create email content
        $message = "Here's your weekly Plontis valuation report:\n\n";
        $message .= "=== WEEKLY SUMMARY ===\n";
        $message .= "AI Bot Detections: " . count($enhanced_detections) . "\n";
        $message .= "Total Portfolio Value: $" . number_format($portfolio_analysis['total_portfolio_value'], 2) . "\n";
        $message .= "High-Value Opportunities: " . $portfolio_analysis['licensing_candidates'] . "\n";
        $message .= "Average Value per Detection: $" . number_format($portfolio_analysis['average_value_per_access'], 2) . "\n\n";
        
        // Top companies
        if (!empty($portfolio_analysis['top_value_companies'])) {
            $message .= "=== TOP AI COMPANIES THIS WEEK ===\n";
            foreach (array_slice($portfolio_analysis['top_value_companies'], 0, 5, true) as $company => $value) {
                $message .= "• {$company}: $" . number_format($value, 2) . "\n";
            }
            $message .= "\n";
        }
        
        $message .= "=== LICENSING OPPORTUNITIES ===\n";
        if (!empty($portfolio_analysis['recommendations'])) {
            foreach ($portfolio_analysis['recommendations'] as $recommendation) {
                $message .= "• " . $recommendation . "\n";
            }
        } else {
            $message .= "Continue building high-quality content to unlock licensing opportunities.\n";
        }
        
        $message .= "\nView detailed report: " . admin_url('admin.php?page=plontis-valuation') . "\n";
        $message .= "Join Plontis platform: https://plontis.com\n";
        
        wp_mail($email, $subject, $message);
    }

    public function handle_export_request() {
        if (isset($_GET['plontis_export']) && current_user_can('manage_options')) {
            $this->export_enhanced_valuation_report();
        }
    }

    public function add_admin_menu() {
        add_menu_page(
            'Plontis',
            'Plontis',
            'manage_options',
            'plontis',
            [$this, 'admin_page'],
            'dashicons-shield-alt',
            30
        );
        
        add_submenu_page(
            'plontis',
            'Valuation Report',
            'Valuation Report',
            'manage_options',
            'plontis-valuation',
            [$this, 'valuation_page']
        );

         add_submenu_page(
            'plontis',
            'Settings',
            'Settings',
            'manage_options',
            'plontis-settings',
            [$this, 'settings_page']
        );
        /* Will fully implement at a later date, functionality not finished yet
        add_submenu_page(
            'plontis',
            'Report Archive',
            'Reports',
            'manage_options',
            'plontis-reports',
            [$this, 'reports_archive_page']
        );
        */
    }
    
    public function reports_archive_page() {
        ?>
        <div class="wrap">
            <h1>Plontis Report Archive</h1>
            
            <div class="plontis-panel">
                <h2>📁 Available Reports</h2>
                
                <div class="report-generator">
                    <h3>Generate New Report</h3>
                    <form method="get" action="">
                        <input type="hidden" name="page" value="plontis-reports">
                        <input type="hidden" name="action" value="generate">
                        
                        <table class="form-table">
                            <tr>
                                <th>Time Period</th>
                                <td>
                                    <select name="days">
                                        <option value="7">Last 7 Days</option>
                                        <option value="30" selected>Last 30 Days</option>
                                        <option value="90">Last 90 Days</option>
                                        <option value="180">Last 6 Months</option>
                                        <option value="365">Last Year</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th>Report Type</th>
                                <td>
                                    <label><input type="radio" name="report_type" value="full" checked> Full Enhanced Report</label><br>
                                    <label><input type="radio" name="report_type" value="summary"> Executive Summary</label><br>
                                    <label><input type="radio" name="report_type" value="competitive"> Competitive Analysis Only</label><br>
                                    <label><input type="radio" name="report_type" value="licensing"> Licensing Opportunities</label>
                                </td>
                            </tr>
                            <tr>
                                <th>Format</th>
                                <td>
                                    <label><input type="radio" name="format" value="html" checked> HTML (Web/Print)</label><br>
                                    <label><input type="radio" name="format" value="csv"> CSV (Data)</label><br>
                                    <label><input type="radio" name="format" value="json"> JSON (API)</label>
                                </td>
                            </tr>
                        </table>
                        
                        <?php submit_button('Generate Report', 'primary', 'generate_report'); ?>
                    </form>
                </div>
                
                <div class="report-history">
                    <h3>Recent Reports</h3>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Date Generated</th>
                                <th>Type</th>
                                <th>Period</th>
                                <th>Total Value</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Get saved reports from options table
                            $saved_reports = get_option('plontis_saved_reports', []);
                            if (!empty($saved_reports)):
                                foreach (array_slice($saved_reports, -10) as $report): ?>
                                <tr>
                                    <td><?php echo date('M j, Y H:i', strtotime($report['generated_at'])); ?></td>
                                    <td><?php echo ucfirst($report['type']); ?></td>
                                    <td><?php echo $report['days']; ?> days</td>
                                    <td>$<?php echo number_format($report['total_value'], 2); ?></td>
                                    <td>
                                        <a href="<?php echo $report['download_url']; ?>" class="button button-small">Download</a>
                                        <a href="<?php echo admin_url('admin.php?page=plontis-valuation&days=' . $report['days']); ?>" class="button button-small">View Live</a>
                                    </td>
                                </tr>
                                <?php endforeach;
                            else: ?>
                                <tr><td colspan="5">No reports generated yet. Create your first report above!</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="plontis-panel">
                <h2>📧 Report Subscriptions</h2>
                
                <form method="post" action="">
                    <?php wp_nonce_field('plontis_subscription', 'subscription_nonce'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th>Weekly Reports</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="weekly_enabled" <?php checked(get_option('plontis_weekly_reports', false)); ?> />
                                    Email me weekly valuation summaries
                                </label>
                                <p class="description">Sent every Monday with the previous week's activity</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Monthly Reports</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="monthly_enabled" <?php checked(get_option('plontis_monthly_reports', false)); ?> />
                                    Email me detailed monthly reports
                                </label>
                                <p class="description">Comprehensive analysis sent on the 1st of each month</p>
                            </td>
                        </tr>
                        <tr>
                            <th>High-Value Alerts</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="alerts_enabled" <?php checked(get_option('plontis_value_alerts', false)); ?> />
                                    Email me when high-value opportunities are detected
                                </label>
                                <p class="description">Immediate notifications for content worth $<?php echo get_option('plontis_alert_threshold', 100); ?>+</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Alert Threshold</th>
                            <td>
                                <input type="number" name="alert_threshold" value="<?php echo get_option('plontis_alert_threshold', 100); ?>" min="10" max="1000" step="10" />
                                <p class="description">Minimum content value (USD) to trigger alert emails</p>
                            </td>
                        </tr>
                    </table>
                    
                    <?php submit_button('Save Subscription Settings'); ?>
                </form>
            </div>
        </div>
        <?php
    }

    public function show_test_api_key() {
        if (!current_user_can('manage_options')) return;
        
        $site_url = get_site_url();
        $domain = parse_url($site_url, PHP_URL_HOST);
        $test_key = 'test_' . hash('sha256', $domain . '_plontis_test');
        $test_key = substr($test_key, 0, 32);
        
        ?>
        <div class="notice notice-info is-dismissible">
            <p><strong>Plontis Test API Key:</strong> <code><?php echo esc_html($test_key); ?></code></p>
            <p>Use this key to test the Central API connection.</p>
        </div>
        <?php
    }

    public function handle_report_actions() {
        // Handle report generation
        if (isset($_GET['generate_report'])) {
            $this->generate_custom_report();
        }
        
        // Handle subscription updates
        if (isset($_POST['subscription_nonce']) && wp_verify_nonce($_POST['subscription_nonce'], 'plontis_subscription')) {
            $this->update_report_subscriptions();
        }
    }

    private function generate_custom_report() {
        $days = intval($_GET['days'] ?? 30);
        $report_type = sanitize_text_field($_GET['report_type'] ?? 'full');
        $format = sanitize_text_field($_GET['format'] ?? 'html');
        
        // Generate the report based on type
        switch ($report_type) {
            case 'summary':
                $this->generate_executive_summary($days, $format);
                break;
            case 'competitive':
                $this->generate_competitive_report($days, $format);
                break;
            case 'licensing':
                $this->generate_licensing_report($days, $format);
                break;
            default:
                // Redirect to full report
                wp_redirect(admin_url("admin.php?page=plontis-valuation&days={$days}&plontis_export=1&format={$format}&nonce=" . wp_create_nonce('plontis_export')));
                exit;
        }
    }

    private function update_report_subscriptions() {
        update_option('plontis_weekly_reports', isset($_POST['weekly_enabled']));
        update_option('plontis_monthly_reports', isset($_POST['monthly_enabled']));
        update_option('plontis_value_alerts', isset($_POST['alerts_enabled']));
        update_option('plontis_alert_threshold', intval($_POST['alert_threshold'] ?? 100));
        
        // Schedule/unschedule cron jobs
        if (isset($_POST['weekly_enabled'])) {
            if (!wp_next_scheduled('plontis_weekly_report')) {
                wp_schedule_event(strtotime('next monday 9am'), 'weekly', 'plontis_weekly_report');
            }
        } else {
            wp_clear_scheduled_hook('plontis_weekly_report');
        }
        
        if (isset($_POST['monthly_enabled'])) {
            if (!wp_next_scheduled('plontis_monthly_report')) {
                wp_schedule_event(strtotime('first day of next month 9am'), 'monthly', 'plontis_monthly_report');
            }
        } else {
            wp_clear_scheduled_hook('plontis_monthly_report');
        }
        
        add_settings_error('plontis_reports', 'subscriptions_updated', 'Report subscriptions updated successfully!', 'success');
    }


    public function admin_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'plontis_detections';
        
        // ADD DEMO FILTERING - same logic as AJAX
        $real_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM $table_name WHERE (is_demo_data IS NULL OR is_demo_data = 0)"
        );
        $has_real_data = $real_count > 0;
        
        if ($has_real_data) {
            // Only real detections
            $raw_detections = $wpdb->get_results(
                "SELECT id, user_agent, ip_address, request_uri, bot_type, company, risk_level, confidence, commercial_risk, detected_at
                FROM $table_name 
                WHERE (is_demo_data IS NULL OR is_demo_data = 0)
                AND detected_at > DATE_SUB(NOW(), INTERVAL 30 DAY)",
                ARRAY_A
            );
        } else {
            // Include demo data for new users
            $raw_detections = $wpdb->get_results(
                "SELECT id, user_agent, ip_address, request_uri, bot_type, company, risk_level, confidence, commercial_risk, detected_at, is_demo_data
                FROM $table_name 
                WHERE detected_at > DATE_SUB(NOW(), INTERVAL 30 DAY)",
                ARRAY_A
            );
        }
        
        // Calculate fresh portfolio analysis
        $enhanced_detections = [];
        
        foreach ($raw_detections as $detection) {
            try {
                // Analyze content using enhanced system
                $content_metadata = $this->content_analyzer->analyzeContent($detection['request_uri']);
                
                // Calculate fresh value
                $detection_data = [
                    'company' => $detection['company'],
                    'bot_type' => $detection['bot_type'],
                    'request_uri' => $detection['request_uri'],
                    'risk_level' => $detection['risk_level'],
                    'confidence' => intval($detection['confidence'] ?? 50),
                    'commercial_risk' => $detection['commercial_risk']
                ];
                
                $valuation = $this->value_calculator->calculateContentValue($detection_data, $content_metadata);
                
                // Add enhanced data
                $detection['estimated_value'] = $valuation['estimated_value'];
                $detection['content_type'] = $content_metadata['content_type'] ?? 'article';
                $detection['licensing_potential'] = $valuation['licensing_potential']['potential'];
                
                $enhanced_detections[] = $detection;
                
            } catch (Exception $e) {
                // Fallback for failed calculations
                $detection['estimated_value'] = 0.00;
                $detection['content_type'] = 'article';
                $detection['licensing_potential'] = 'low';
                
                $enhanced_detections[] = $detection;
            }
        }
        
        // Calculate portfolio using enhanced detections
        $portfolio_analysis = $this->value_calculator->calculatePortfolioValue($enhanced_detections);
        
        ?>
            <div class="wrap plontis-admin" id="plontis-dashboard">
                        <div class="plontis-header">
                <div class="plontis-logo">
                    <div class="plontis-icon"></div>
                    <h1 class="plontis-title">PLONTIS</h1>
                </div>
                <span class="plontis-subtitle">AI Bot Detection</span>
            </div>
            
            <div class="plontis-stats-grid">
                <div class="plontis-stat-card">
                    <h3>Total AI Bots Detected</h3>
                    <div class="stat-number" id="total-bots">-</div>
                    <span class="stat-period">Last 30 days</span>
                </div>
                
                <div class="plontis-stat-card">
                    <h3>Portfolio Value</h3>
                    <div class="stat-number" id="content-value">$<?php echo number_format($portfolio_analysis['total_portfolio_value'], 2); ?></div>
                    <span class="stat-period">Industry-accurate pricing</span>
                </div>
                
                <div class="plontis-stat-card">
                    <h3>Commercial Bots</h3>
                    <div class="stat-number" id="commercial-bots">-</div>
                    <span class="stat-period">High-value opportunities</span>
                </div>
                
                <div class="plontis-stat-card">
                    <h3>Average Per Detection</h3>
                    <div class="stat-number" id="average-value">$<?php echo number_format($portfolio_analysis['average_value_per_access'], 2); ?></div>
                    <span class="stat-period">Market-based estimate</span>
                </div>
            </div>

            <div class="plontis-dashboard-grid">
                <div class="plontis-panel">
                    <h2>AI Bot Activity Trends</h2>
                    <canvas id="activity-chart" width="400" height="200"></canvas>
                </div>
                
                <div class="plontis-panel">
                    <h2>Content Value by Company</h2>
                    <canvas id="companies-chart" width="400" height="200"></canvas>
                </div>
            </div>

            <!-- Enhanced Testing Section -->
            <div class="plontis-panel">
                <h2>Test Bot Detection & Valuation</h2>
                <p>Test any user agent string to see how Plontis detects and values AI bot access.</p>
                
                <form method="post" action="">
                    <?php wp_nonce_field('plontis_test', 'test_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">User Agent String</th>
                            <td>
                                <input type="text" name="test_user_agent" class="regular-text" 
                                    placeholder="GPTBot/1.0" 
                                    style="width: 100%; margin-bottom: 10px;" 
                                    value="<?php echo isset($_POST['test_user_agent']) ? esc_attr($_POST['test_user_agent']) : ''; ?>" />
                                <br>
                                <input type="text" name="test_uri" class="regular-text" 
                                    placeholder="/your-blog-post-url (try a real page on your site)" 
                                    style="width: 100%; margin-bottom: 10px;" 
                                    value="<?php echo isset($_POST['test_uri']) ? esc_attr($_POST['test_uri']) : '/'; ?>" />
                                <br>
                                <input type="submit" name="test_detection" class="button button-primary" value="Test Detection & Valuation" />
                            </td>
                        </tr>
                    </table>
                </form>
                
                <?php
                // Enhanced test detection with accurate valuation
                if (isset($_POST['test_detection']) && wp_verify_nonce($_POST['test_nonce'], 'plontis_test')) {
                    $test_user_agent = sanitize_text_field($_POST['test_user_agent']);
                    $test_uri = sanitize_text_field($_POST['test_uri'] ?: '/');
                    
                    if ($test_user_agent) {
                        $detection = $this->core->analyze_user_agent($test_user_agent);
                        $content_metadata = $this->content_analyzer->analyzeContent($test_uri);
                        $valuation = $this->value_calculator->calculateContentValue(
                            array_merge($detection, ['request_uri' => $test_uri]), 
                            $content_metadata
                        );
                        
                        echo '<div class="notice notice-success" style="margin-top: 15px;">';
                        echo '<h4>✅ Detection & Valuation Results</h4>';
                        echo '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">';
                        
                        // Detection Results
                        echo '<div>';
                        echo '<h5>🤖 Bot Detection</h5>';
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
                        echo '<h5>💰 Content Valuation</h5>';
                        echo '<p><strong>Estimated Value:</strong> <span style="color: #28a745; font-weight: bold; font-size: 18px;">$' . number_format($valuation['estimated_value'], 2) . '</span></p>';                        
                        echo '<p><strong>Content Type:</strong> ' . esc_html($valuation['breakdown']['content_type']) . '</p>';
                        echo '<p><strong>Licensing Potential:</strong> <span class="risk-badge risk-' . strtolower($valuation['licensing_potential']['potential']) . '">' . esc_html($valuation['licensing_potential']['potential']) . '</span></p>';
                        echo '<p><strong>Market Position:</strong> ' . esc_html($valuation['market_context']['market_position']) . '</p>';
                        echo '</div>';
                        
                        echo '</div>'; // End grid
                        
                        if ($valuation['licensing_potential']['potential'] === 'High') {
                            echo '<div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 4px; margin-top: 15px;">';
                            echo '<h5>🎯 High Licensing Potential Detected!</h5>';
                            echo '<p><strong>Recommendation:</strong> ' . esc_html($valuation['licensing_potential']['recommendation']) . '</p>';
                            echo '</div>';
                        }
                        
                        echo '</div>';
                    }
                }
                ?>
            </div>
            
            <div class="plontis-panel">
                <h2>Recent AI Bot Detections</h2>
                <div id="recent-detections">
                    <div class="plontis-loading">Loading detection data...</div>
                </div>
            </div>

            <div class="plontis-cta">
                <h3>Ready to Monetize Your Content?</h3>
                <p>Your content is worth <strong>$<?php echo number_format($portfolio_analysis['estimated_annual_revenue'], 2); ?></strong> annually. Start tracking AI bot activity and explore licensing opportunities.</p>
                <a href="https://plontis.com" class="button button-primary button-hero" target="_blank">
                    Explore Licensing Platform
                </a>
            </div>
        </div>
        
        <style>
        .risk-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .risk-high {
            background: #dc3545;
            color: white;
        }
        .risk-medium {
            background: #ffc107;
            color: #333;
        }
        .risk-low {
            background: #28a745;
            color: white;
        }
        .risk-unknown {
            background: #6c757d;
            color: white;
        }
        </style>
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
                'valuation_version' => '2.0',
                'high_value_threshold' => floatval($_POST['high_value_threshold'] ?? 50.00),
                'licensing_notification_threshold' => floatval($_POST['licensing_notification_threshold'] ?? 100.00),
                'central_api_key' => sanitize_text_field($_POST['central_api_key'] ?? ''),
                'enable_central_reporting' => isset($_POST['enable_central_reporting'])
            ];
            update_option('plontis_settings', $settings);
            echo '<div class="notice notice-success"><p>Enhanced settings saved!</p></div>';
        }

        $settings = get_option('plontis_settings');
        ?>
        <div class="wrap">
            <h1>Plontis Enhanced Settings</h1>
            
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
                        <th scope="row">Automated Reports</th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox" name="enable_automated_reports" <?php checked($settings['enable_automated_reports'] ?? false); ?> />
                                    Enable weekly email reports
                                </label>
                                <br><br>
                                <label>
                                    <input type="checkbox" name="enable_monthly_reports" <?php checked($settings['enable_monthly_reports'] ?? false); ?> />
                                    Enable monthly detailed reports
                                </label>
                            </fieldset>
                            <p class="description">Automatically receive valuation reports via email</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Email Notifications</th>
                        <td>
                            <input type="checkbox" name="enable_notifications" <?php checked($settings['enable_notifications']); ?> />
                            <p class="description">Get notified when high-value AI bots are detected</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Notification Email</th>
                        <td>
                            <input type="email" name="notification_email" value="<?php echo esc_attr($settings['notification_email']); ?>" class="regular-text" />
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">High-Value Threshold</th>
                        <td>
                            <input type="number" name="high_value_threshold" value="<?php echo esc_attr($settings['high_value_threshold'] ?? 50.00); ?>" step="0.01" min="0" class="small-text" />
                            <p class="description">Minimum value (in USD) to trigger notifications</p>
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

                <h2>Central API Settings</h2>
                <?php 
                $api_admin = new Plontis_Admin_API();
                $api_admin->render_api_settings_form(); 
                ?>
                
                <?php submit_button('Save Enhanced Settings'); ?>
            </form>
        </div>
        <?php
    }

    /**
    * Enhanced Analytics Helper Methods
    * Add these methods to the Plontis_Admin class to support the enhanced valuation report
    */

    /**
    * 1. Content Performance Analytics
    */
    private function getContentPerformanceAnalytics($enhanced_detections) {
        $page_analytics = [];
        $content_types = [];
        $time_patterns = ['hours' => [], 'days' => []];
        
        foreach ($enhanced_detections as $detection) {
            $page = $detection['request_uri'];
            $hour = date('H', strtotime($detection['detected_at']));
            $day = date('w', strtotime($detection['detected_at'])); // 0=Sunday
            
            // Page-level analytics
            if (!isset($page_analytics[$page])) {
                $page_analytics[$page] = [
                    'total_value' => 0,
                    'access_count' => 0,
                    'unique_companies' => [],
                    'avg_value_per_access' => 0,
                    'content_type' => $detection['content_type'] ?? 'article',
                    'first_detected' => $detection['detected_at'],
                    'last_detected' => $detection['detected_at']
                ];
            }
            
            $page_analytics[$page]['total_value'] += $detection['estimated_value'];
            $page_analytics[$page]['access_count']++;
            $page_analytics[$page]['unique_companies'][] = $detection['company'];
            $page_analytics[$page]['last_detected'] = max($page_analytics[$page]['last_detected'], $detection['detected_at']);
            
            // Content type distribution
            $type = $detection['content_type'] ?? 'article';
            $content_types[$type] = ($content_types[$type] ?? 0) + $detection['estimated_value'];
            
            // Time pattern analysis
            $time_patterns['hours'][$hour] = ($time_patterns['hours'][$hour] ?? 0) + 1;
            $time_patterns['days'][$day] = ($time_patterns['days'][$day] ?? 0) + 1;
        }
        
        // Calculate averages and clean up data
        foreach ($page_analytics as $page => &$data) {
            $data['avg_value_per_access'] = $data['total_value'] / $data['access_count'];
            $data['unique_companies'] = array_unique($data['unique_companies']);
            $data['company_count'] = count($data['unique_companies']);
        }
        
        // Sort by total value
        uasort($page_analytics, function($a, $b) {
            return $b['total_value'] <=> $a['total_value'];
        });
        
        // Ensure we have data for peak calculations
        $peak_hour = !empty($time_patterns['hours']) ? array_search(max($time_patterns['hours']), $time_patterns['hours']) : 0;
        $peak_day_index = !empty($time_patterns['days']) ? array_search(max($time_patterns['days']), $time_patterns['days']) : 0;
        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $peak_day = $days[$peak_day_index];
        
        return [
            'page_analytics' => array_slice($page_analytics, 0, 20, true), // Top 20 pages
            'content_type_revenue' => $content_types,
            'time_patterns' => $time_patterns,
            'peak_hour' => $peak_hour,
            'peak_day' => $peak_day
        ];
    }

    /**
    * 2. Competitive Intelligence
    */
    private function getCompetitiveIntelligence($enhanced_detections) {
        $company_strategies = [];
        
        foreach ($enhanced_detections as $detection) {
            $company = $detection['company'] ?? 'Unknown';
            $content_type = $detection['content_type'] ?? 'article';
            $hour = date('H', strtotime($detection['detected_at']));
            
            // Company strategy analysis
            if (!isset($company_strategies[$company])) {
                $company_strategies[$company] = [
                    'total_accesses' => 0,
                    'total_value' => 0,
                    'content_types' => [],
                    'avg_session_value' => 0,
                    'preferred_times' => [],
                    'content_quality_focus' => 0,
                    'system_file_accesses' => 0, // Track system file access
                    'content_accesses' => 0,     // Track actual content access
                    'pages_accessed' => []
                ];
            }
            
            $company_strategies[$company]['total_accesses']++;
            $company_strategies[$company]['total_value'] += $detection['estimated_value'];
            $company_strategies[$company]['content_types'][] = $content_type;
            $company_strategies[$company]['preferred_times'][] = $hour;
            $company_strategies[$company]['pages_accessed'][] = $detection['request_uri'];
            
            // Separate system file access from content access for quality calculation
            if ($content_type === 'system_file') {
                $company_strategies[$company]['system_file_accesses']++;
                // Don't include system files in quality focus calculation
            } else {
                $company_strategies[$company]['content_accesses']++;
                $content_quality = $detection['content_quality'] ?? 50;
                $company_strategies[$company]['content_quality_focus'] += $content_quality;
            }
        }
        
        // Process company data
        foreach ($company_strategies as $company => &$data) {
            $data['avg_session_value'] = $data['total_value'] / max(1, $data['total_accesses']);
            
            // Calculate quality focus ONLY from actual content, not system files
            $content_access_count = max(1, $data['content_accesses']);
            $data['avg_quality_focus'] = $data['content_quality_focus'] / $content_access_count;
            
            // Add system file access ratio for analysis
            $data['system_file_ratio'] = $data['system_file_accesses'] / max(1, $data['total_accesses']);
            
            $data['content_type_preference'] = array_count_values($data['content_types']);
            $data['time_preference'] = array_count_values($data['preferred_times']);
            $data['unique_pages'] = count(array_unique($data['pages_accessed']));
            
            // Determine strategy - account for system file behavior
            if ($data['system_file_ratio'] > 0.5) {
                $data['strategy'] = 'Site Discovery & Mapping';
            } elseif ($data['avg_quality_focus'] > 80) {
                $data['strategy'] = 'Premium Content Focus';
            } elseif ($data['unique_pages'] > 10) {
                $data['strategy'] = 'Broad Content Harvesting';
            } elseif ($data['avg_session_value'] > 50) {
                $data['strategy'] = 'High-Value Targeting';
            } else {
                $data['strategy'] = 'General Data Collection';
            }
        }
        
        // Sort by total value
        uasort($company_strategies, function($a, $b) {
            return $b['total_value'] <=> $a['total_value'];
        });
        
        return $company_strategies;
    }


    /**
    * 3. Revenue Forecasting
    */
    private function getRevenueForecasting($enhanced_detections) {
        $daily_values = [];
        $seasonal_patterns = [];
        $weekly_patterns = [];
        
        foreach ($enhanced_detections as $detection) {
            $date = date('Y-m-d', strtotime($detection['detected_at']));
            $month = date('m', strtotime($detection['detected_at']));
            $week = date('W', strtotime($detection['detected_at']));
            
            $daily_values[$date] = ($daily_values[$date] ?? 0) + $detection['estimated_value'];
            $seasonal_patterns[$month] = ($seasonal_patterns[$month] ?? 0) + $detection['estimated_value'];
            $weekly_patterns[$week] = ($weekly_patterns[$week] ?? 0) + $detection['estimated_value'];
        }
        
        // Calculate meaningful growth trends
        ksort($daily_values);
        $values = array_values($daily_values);
        $growth_rate = 0;
        $trend_description = 'Stable';
        
        if (count($values) >= 14) {
            // Compare last 7 days to previous 7 days
            $recent_week = array_slice($values, -7);
            $previous_week = array_slice($values, -14, 7);
            
            $recent_avg = array_sum($recent_week) / 7;
            $previous_avg = array_sum($previous_week) / 7;
            
            if ($previous_avg > 0) {
                $growth_rate = (($recent_avg - $previous_avg) / $previous_avg) * 100;
                
                if ($growth_rate > 10) {
                    $trend_description = 'Strong Growth';
                } elseif ($growth_rate > 5) {
                    $trend_description = 'Growing';
                } elseif ($growth_rate < -10) {
                    $trend_description = 'Declining';
                } elseif ($growth_rate < -5) {
                    $trend_description = 'Slight Decline';
                } else {
                    $trend_description = 'Stable';
                }
            }
        } elseif (count($values) >= 7) {
            // For shorter periods, just show if increasing or decreasing
            $first_half = array_slice($values, 0, ceil(count($values)/2));
            $second_half = array_slice($values, -floor(count($values)/2));
            
            $first_avg = array_sum($first_half) / count($first_half);
            $second_avg = array_sum($second_half) / count($second_half);
            
            if ($second_avg > $first_avg * 1.1) {
                $trend_description = 'Growing';
                $growth_rate = 15; // Approximate
            } elseif ($second_avg < $first_avg * 0.9) {
                $trend_description = 'Declining';
                $growth_rate = -15; // Approximate
            }
        } else {
            $trend_description = 'Insufficient Data';
            $growth_rate = 0;
        }
        
        // Calculate more realistic projections
        $total_value = array_sum($values);
        $days_of_data = count($values);
        
        if ($days_of_data > 0) {
            $daily_average = $total_value / $days_of_data;
            
            // Apply realistic licensing conversion rates (most content doesn't get licensed)
            $licensing_conversion_rate = 0.15; // 15% of detected value might become actual licensing revenue
            
            $projections = [
                'daily_average' => $daily_average * $licensing_conversion_rate,
                'weekly_projection' => $daily_average * 7 * $licensing_conversion_rate,
                'monthly_projection' => $daily_average * 30 * $licensing_conversion_rate,
                'annual_projection' => $daily_average * 365 * $licensing_conversion_rate,
                'growth_rate' => $growth_rate
            ];
        } else {
            $projections = [
                'daily_average' => 0,
                'weekly_projection' => 0,
                'monthly_projection' => 0,
                'annual_projection' => 0,
                'growth_rate' => 0
            ];
        }
        
        // More realistic scenario analysis
        $base_annual = $projections['annual_projection'];
        $projections['conservative_annual'] = $base_annual * 0.5; // Much more conservative
        $projections['optimistic_annual'] = $base_annual * 2.0;   // Assumes good licensing success
        
        // Improved peak season analysis
        $peak_season_name = 'N/A';
        $peak_season_explanation = 'Not enough data to determine seasonal patterns';
        
        if (!empty($seasonal_patterns) && count($seasonal_patterns) >= 3) {
            $peak_month = array_search(max($seasonal_patterns), $seasonal_patterns);
            $month_names = [
                '01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April',
                '05' => 'May', '06' => 'June', '07' => 'July', '08' => 'August',
                '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'
            ];
            $peak_season_name = $month_names[str_pad($peak_month, 2, '0', STR_PAD_LEFT)] ?? 'Unknown';
            $peak_season_explanation = "Based on current data, {$peak_season_name} shows highest AI bot activity";
        }
        
        return [
            'daily_values' => $daily_values,
            'seasonal_patterns' => $seasonal_patterns,
            'projections' => $projections,
            'trends' => [
                'growth_rate' => $growth_rate,
                'trend_direction' => $trend_description,
                'peak_season' => $peak_season_name,
                'peak_season_explanation' => $peak_season_explanation,
                'data_confidence' => $days_of_data >= 30 ? 'High' : ($days_of_data >= 14 ? 'Medium' : 'Low'),
                'licensing_note' => 'Projections assume 15% licensing conversion rate based on industry averages'
            ]
        ];
    }

    /**
    * 4. Licensing Strategy Recommendations
    */
    private function getLicensingStrategyRecommendations($portfolio_analysis, $competitive_intel) {
        $recommendations = [];
        $total_value = $portfolio_analysis['total_portfolio_value'];
        
        // More realistic tier thresholds and revenue expectations
        if ($total_value > 10000) {
            $recommendations[] = [
                'category' => 'Enterprise Direct Licensing',
                'priority' => 'High',
                'description' => 'Your high-value portfolio justifies direct outreach to AI companies for licensing deals.',
                'action_items' => [
                    'Document your top 10 most valuable pages with access patterns and content quality metrics',
                    'Research AI companies that have accessed your content and their existing licensing deals',
                    'Prepare a licensing proposal with tiered pricing: $5,000-$50,000 annual for content access',
                    'Contact business development teams at OpenAI, Anthropic, Google, and Meta'
                ],
                'potential_revenue' => min($total_value * 0.20, 25000), // Cap at $25K to be realistic
                'timeline' => '3-6 months',
                'success_probability' => 'Medium (30-50%)',
                'next_action' => 'Create licensing proposal document'
            ];
        }
        
        if ($total_value > 2500) {
            $recommendations[] = [
                'category' => 'Content Marketplace Licensing',
                'priority' => 'Medium', 
                'description' => 'Join AI training data marketplaces for automated, smaller-scale licensing revenue.',
                'action_items' => [
                    'Register with Plontis licensing platform and similar services',
                    'Set up RSS feeds or API access for your highest-quality content',
                    'Price content at $0.10-$2.00 per access based on quality and uniqueness',
                    'Monitor performance and adjust pricing monthly'
                ],
                'potential_revenue' => min($total_value * 0.10, 5000), // Cap at $5K annually
                'timeline' => '1-2 months',
                'success_probability' => 'High (70-90%)',
                'next_action' => 'Research and join 2-3 content marketplaces'
            ];
        }
        
        // Company-specific strategies only for significant value
        foreach ($competitive_intel as $company => $strategy) {
            if ($strategy['total_value'] > 1000) {
                $recommendations[] = [
                    'category' => "Targeted Outreach - {$company}",
                    'priority' => $strategy['total_value'] > 5000 ? 'High' : 'Medium',
                    'description' => "{$company} has accessed \${$strategy['total_value']} worth of your content. Their {$strategy['strategy']} approach suggests licensing opportunity.",
                    'action_items' => [
                        "Research {$company}'s content licensing team and recent deals (check press releases)",
                        "Prepare usage report: {$strategy['total_accesses']} accesses across {$strategy['unique_pages']} pages",
                        "Calculate licensing proposal: {$strategy['avg_session_value']} average value per session",
                        "Send initial inquiry via official business channels or LinkedIn"
                    ],
                    'potential_revenue' => min($strategy['total_value'] * 0.25, 15000), // Realistic cap
                    'timeline' => '2-4 months',
                    'success_probability' => $strategy['total_value'] > 5000 ? 'Medium (40%)' : 'Low (20%)',
                    'next_action' => "Find {$company} business development contact"
                ];
            }
        }
        
        // Always include content optimization (most actionable)
        $recommendations[] = [
            'category' => 'Content Value Optimization',
            'priority' => 'Ongoing',
            'description' => 'Increase your content\'s AI training value and licensing appeal through strategic improvements.',
            'action_items' => [
                'Focus on creating original research, data analysis, and technical tutorials (highest AI value)',
                'Add structured data markup to help AI systems better understand your content',
                'Create content series and topic clusters to increase your authority in specific domains',
                'Improve content quality scores: aim for 1,500+ words, multiple sections, data/examples'
            ],
            'potential_revenue' => min($total_value * 0.30, 10000), // 30% improvement is realistic
            'timeline' => 'Ongoing (3-6 months to see results)',
            'success_probability' => 'High (80%+)',
            'next_action' => 'Audit your top 10 pages and identify optimization opportunities'
        ];
        
        // Add realistic expectations note
        $recommendations[] = [
            'category' => 'Realistic Expectations',
            'priority' => 'Important',
            'description' => 'Content licensing is emerging but competitive. Most creators earn $100-$5,000 annually.',
            'action_items' => [
                'Set realistic expectations: start with small wins and build relationships',
                'Track your metrics monthly and adjust strategies based on what works',
                'Join creator communities and forums to learn from others\' licensing experiences',
                'Consider this as supplementary income, not primary revenue'
            ],
            'potential_revenue' => 'Variable',
            'timeline' => 'Ongoing',
            'success_probability' => 'Depends on execution',
            'next_action' => 'Set monthly goals and tracking metrics'
        ];
        
        return $recommendations;
    }

    /**
    * 5. Risk Assessment and Compliance
    */
    private function getRiskAssessment($enhanced_detections) {
        $risk_factors = [];
        
        // Analyze risk patterns
        $high_value_without_consent = 0;
        $potential_copyright_issues = 0;
        $international_access = 0;
        
        foreach ($enhanced_detections as $detection) {
            if ($detection['estimated_value'] > 100) {
                $high_value_without_consent++;
            }
            
            if (in_array($detection['content_type'] ?? 'article', ['image', 'video', 'audio'])) {
                $potential_copyright_issues++;
            }
            
            // Basic check for non-localhost IPs (international potential)
            if ($this->isLikelyInternationalIP($detection['ip_address'])) {
                $international_access++;
            }
        }
        
        $risk_factors = [
            'high_value_unlicensed' => [
                'count' => $high_value_without_consent,
                'risk_level' => $high_value_without_consent > 50 ? 'High' : ($high_value_without_consent > 10 ? 'Medium' : 'Low'),
                'description' => 'High-value content accessed without explicit AI training consent or licensing agreements'
            ],
            'copyright_sensitive' => [
                'count' => $potential_copyright_issues,
                'risk_level' => $potential_copyright_issues > 20 ? 'High' : ($potential_copyright_issues > 5 ? 'Medium' : 'Low'),
                'description' => 'Media content that may have complex copyright considerations requiring special handling'
            ],
            'international_compliance' => [
                'count' => $international_access,
                'risk_level' => $international_access > 100 ? 'Medium' : 'Low',
                'description' => 'Cross-border data usage requiring compliance with international data protection regulations'
            ]
        ];
        
        return [
            'risk_factors' => $risk_factors,
            'recommendations' => [
                'Update terms of service to explicitly address AI training usage and data licensing',
                'Implement explicit consent mechanisms for high-value content and premium materials',
                'Document all AI bot access patterns for legal protection and licensing negotiations',
                'Consider geo-blocking or content restrictions if international compliance becomes complex',
                'Establish clear content licensing policies and rate cards for different usage types',
                'Consult with IP attorney about fair use vs. licensing requirements for AI training'
            ]
        ];
    }

    /**
    * 6. Industry Benchmarking
    */
    private function getIndustryBenchmarking($portfolio_analysis) {
        $benchmarks = [
            'content_creator' => [
                'low' => 100,    
                'average' => 500,     
                'high' => 2500   
            ],
            'small_publisher' => [
                'low' => 500,   
                'average' => 2500,   
                'high' => 10000  
            ],
            'medium_publisher' => [
                'low' => 2500,  
                'average' => 15000,  
                'high' => 50000  
            ],
            'enterprise_publisher' => [
                'low' => 50000, 
                'average' => 200000, 
                'high' => 1000000 
            ]
        ];
        
        $annual_value = $portfolio_analysis['estimated_annual_revenue'];
        $category = '';
        $percentile = '';
        
        foreach ($benchmarks as $cat => $ranges) {
            if ($annual_value <= $ranges['high']) {
                $category = $cat;
                if ($annual_value <= $ranges['low']) {
                    $percentile = 'Below Average';
                } elseif ($annual_value <= $ranges['average']) {
                    $percentile = 'Average';
                } else {
                    $percentile = 'Above Average';
                }
                break;
            }
        }
        
        if (empty($category)) {
            $category = 'enterprise_publisher';
            $percentile = 'Top Tier';
        }
        
        return [
            'category' => $category,
            'percentile' => $percentile,
            'benchmark_data' => $benchmarks[$category] ?? $benchmarks['medium_publisher'],
            'improvement_potential' => max(0, ($benchmarks[$category]['high'] ?? 500000) - $annual_value),
            'next_tier_target' => $this->getNextTierTarget($category, $benchmarks)
        ];
    }

    /**
    * Helper method to get next tier target
    */
    private function getNextTierTarget($current_category, $benchmarks) {
        $tiers = ['content_creator', 'small_publisher', 'medium_publisher', 'enterprise_publisher'];
        $current_index = array_search($current_category, $tiers);
        
        if ($current_index !== false && $current_index < count($tiers) - 1) {
            $next_tier = $tiers[$current_index + 1];
            return [
                'tier' => $next_tier,
                'target_value' => $benchmarks[$next_tier]['low']
            ];
        }
        
        return null;
    }

    /**
    * Helper method to check if IP is likely international
    */
    private function isLikelyInternationalIP($ip) {
        // Basic implementation - could be enhanced with GeoIP database
        // For now, just check if it's not localhost/private range
        if (empty($ip) || $ip === '127.0.0.1' || $ip === 'unknown') {
            return false;
        }
        
        // Check if it's a private IP range
        $private_ranges = [
            '10.0.0.0/8',
            '172.16.0.0/12', 
            '192.168.0.0/16'
        ];
        
        foreach ($private_ranges as $range) {
            if ($this->ipInRange($ip, $range)) {
                return false;
            }
        }
        
        // If it's a valid public IP, consider it potentially international
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
    * Helper method to check if IP is in range
    */
    private function ipInRange($ip, $range) {
        if (strpos($range, '/') !== false) {
            list($subnet, $bits) = explode('/', $range);
            $ip_long = ip2long($ip);
            $subnet_long = ip2long($subnet);
            $mask = -1 << (32 - $bits);
            $subnet_long &= $mask;
            return ($ip_long & $mask) == $subnet_long;
        }
        return false;
    }

    /**
    * Enhanced export functionality for the new report
    */
    public function export_enhanced_valuation_report() {
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        // Verify nonce
        if (!wp_verify_nonce($_GET['nonce'] ?? '', 'plontis_export')) {
            wp_die('Security check failed');
        }
        
        $format = sanitize_text_field($_GET['format'] ?? 'pdf');
        $days = intval($_GET['days'] ?? 30);
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'plontis_detections';
        
        // Get data
        $raw_detections = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE detected_at > DATE_SUB(NOW(), INTERVAL %d DAY) ORDER BY detected_at DESC", 
            $days
        ), ARRAY_A);
        
        if (empty($raw_detections)) {
            wp_die('No data to export');
        }
        
        // Process detections
        $enhanced_detections = [];
        foreach ($raw_detections as $detection) {
            try {
                $content_metadata = $this->content_analyzer->analyzeContent($detection['request_uri']);
                $detection_data = [
                    'company' => $detection['company'],
                    'bot_type' => $detection['bot_type'],
                    'request_uri' => $detection['request_uri'],
                    'risk_level' => $detection['risk_level'],
                    'confidence' => intval($detection['confidence'] ?? 50),
                    'commercial_risk' => $detection['commercial_risk']
                ];
                
                $valuation = $this->value_calculator->calculateContentValue($detection_data, $content_metadata);
                $detection['estimated_value'] = $valuation['estimated_value'];
                $detection['content_type'] = $content_metadata['content_type'] ?? 'article';
                $detection['licensing_potential'] = $valuation['licensing_potential']['potential'];
                
                $enhanced_detections[] = $detection;
            } catch (Exception $e) {
                $detection['estimated_value'] = 0.00;
                $detection['content_type'] = 'article';
                $detection['licensing_potential'] = 'low';
                $enhanced_detections[] = $detection;
            }
        }
        
        // Generate analytics
        $portfolio_analysis = $this->value_calculator->calculatePortfolioValue($enhanced_detections);
        $performance_analytics = $this->getContentPerformanceAnalytics($enhanced_detections);
        $competitive_intel = $this->getCompetitiveIntelligence($enhanced_detections);
        $revenue_forecasting = $this->getRevenueForecasting($enhanced_detections);
        $licensing_strategies = $this->getLicensingStrategyRecommendations($portfolio_analysis, $competitive_intel);
        $industry_benchmarks = $this->getIndustryBenchmarking($portfolio_analysis);
        
        switch ($format) {
            case 'csv':
                $this->export_csv_report($enhanced_detections, $portfolio_analysis);
                break;
            case 'json':
                $this->export_json_report([
                    'portfolio_analysis' => $portfolio_analysis,
                    'performance_analytics' => $performance_analytics,
                    'competitive_intel' => $competitive_intel,
                    'revenue_forecasting' => $revenue_forecasting,
                    'licensing_strategies' => $licensing_strategies,
                    'industry_benchmarks' => $industry_benchmarks,
                    'detections' => $enhanced_detections
                ]);
                break;
            default:
                $this->export_html_report($enhanced_detections, [
                    'portfolio_analysis' => $portfolio_analysis,
                    'performance_analytics' => $performance_analytics,
                    'competitive_intel' => $competitive_intel,
                    'revenue_forecasting' => $revenue_forecasting,
                    'licensing_strategies' => $licensing_strategies,
                    'industry_benchmarks' => $industry_benchmarks
                ]);
                break;
        }
    }

    /**
    * Export CSV report
    */
    private function export_csv_report($detections, $portfolio_analysis) {
        $filename = 'plontis-valuation-report-' . date('Y-m-d') . '.csv';
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $output = fopen('php://output', 'w');
        
        // Summary section
        fputcsv($output, ['Plontis Enhanced Valuation Report']);
        fputcsv($output, ['Generated: ' . date('Y-m-d H:i:s')]);
        fputcsv($output, ['Total Portfolio Value: $' . number_format($portfolio_analysis['total_portfolio_value'], 2)]);
        fputcsv($output, ['Portfolio Value Projection: $' . number_format($portfolio_analysis['estimated_annual_revenue'], 2)]);
        fputcsv($output, []);
        
        // Headers for detection data
        fputcsv($output, [
            'Date',
            'Company',
            'Bot Type',
            'Page',
            'Risk Level',
            'Estimated Value',
            'Content Type',
            'Content Quality',
            'Licensing Potential',
            'IP Address'
        ]);
        
        // Detection data
        foreach ($detections as $detection) {
            fputcsv($output, [
                $detection['detected_at'],
                $detection['company'] ?? 'Unknown',
                $detection['bot_type'] ?? 'Unknown',
                $detection['request_uri'],
                $detection['risk_level'] ?? 'unknown',
                '$' . number_format($detection['estimated_value'], 2),
                $detection['content_type'] ?? 'article',
                $detection['content_quality'] ?? 50,
                $detection['licensing_potential'] ?? 'low',
                $detection['ip_address']
            ]);
        }
        
        fclose($output);
        exit;
    }

    /**
    * Export JSON report
    */
    private function export_json_report($data) {
        $filename = 'plontis-valuation-report-' . date('Y-m-d') . '.json';
        
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $export_data = [
            'report_meta' => [
                'generated_at' => date('Y-m-d H:i:s'),
                'plugin_version' => PLONTIS_VERSION,
                'report_type' => 'enhanced_valuation',
                'time_period_days' => intval($_GET['days'] ?? 30)
            ],
            'analytics' => $data
        ];
        
        echo json_encode($export_data, JSON_PRETTY_PRINT);
        exit;
    }

    private function export_html_report($detections, $analytics) {
        $filename = 'plontis-valuation-report-' . date('Y-m-d') . '.html';
        
        header('Content-Type: text/html');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Plontis Enhanced Valuation Report</title>
            <meta charset="UTF-8">
        </head>
        <body>
            <div class="header">
                <div class="logo">🛡️ PLONTIS</div>
                <h1>Enhanced Valuation Report</h1>
            </div>
            
            <div class="report-meta">
                <p><strong>Generated:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
                <p><strong>Time Period:</strong> Last <?php echo intval($_GET['days'] ?? 30); ?> days</p>
                <p><strong>Total Detections:</strong> <?php echo count($detections); ?></p>
            </div>
            
            <div class="section">
                <h2>📊 Portfolio Summary</h2>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-value">$<?php echo number_format($analytics['portfolio_analysis']['total_portfolio_value'], 2); ?></div>
                        <div class="stat-label">Total Portfolio Value</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value">$<?php echo number_format($analytics['revenue_forecasting']['projections']['annual_projection'], 2); ?></div>
                        <div class="stat-label">Annual Projection</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $analytics['portfolio_analysis']['licensing_candidates']; ?></div>
                        <div class="stat-label">Licensing Opportunities</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo ucfirst(str_replace('_', ' ', $analytics['industry_benchmarks']['category'])); ?></div>
                        <div class="stat-label">Industry Category</div>
                    </div>
                </div>
            </div>
            
            <div class="section">
                <h2>🏆 Top Performing Content</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Page</th>
                            <th>Total Value</th>
                            <th>Access Count</th>
                            <th>Avg Value</th>
                            <th>Companies</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($analytics['performance_analytics']['page_analytics'], 0, 10) as $page => $data): ?>
                        <tr>
                            <td><?php echo esc_html(substr($page, 0, 50)) . (strlen($page) > 50 ? '...' : ''); ?></td>
                            <td>$<?php echo number_format($data['total_value'], 2); ?></td>
                            <td><?php echo $data['access_count']; ?></td>
                            <td>$<?php echo number_format($data['avg_value_per_access'], 2); ?></td>
                            <td><?php echo $data['company_count']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="section">
                <h2>🎯 Competitive Intelligence</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Company</th>
                            <th>Strategy</th>
                            <th>Total Value</th>
                            <th>Avg Session</th>
                            <th>Quality Focus</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($analytics['competitive_intel'] as $company => $strategy): ?>
                        <tr>
                            <td><?php echo esc_html($company); ?></td>
                            <td><?php echo esc_html($strategy['strategy']); ?></td>
                            <td>$<?php echo number_format($strategy['total_value'], 2); ?></td>
                            <td>$<?php echo number_format($strategy['avg_session_value'], 2); ?></td>
                            <td><?php echo number_format($strategy['avg_quality_focus'], 0); ?>/100</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="section">
                <h2>💼 Licensing Recommendations</h2>
                <?php foreach ($analytics['licensing_strategies'] as $strategy): ?>
                <div>
                    <h3><?php echo esc_html($strategy['category']); ?> (<?php echo $strategy['priority']; ?> Priority)</h3>
                    <p><?php echo esc_html($strategy['description']); ?></p>
                    <p><strong>Potential Revenue:</strong> $<?php echo number_format($strategy['potential_revenue'], 2); ?></p>
                    <p><strong>Timeline:</strong> <?php echo esc_html($strategy['timeline']); ?></p>
                </div>
                <?php endforeach; ?>
            </div>
            
            <div class="footer">
                <p>Generated by Plontis v<?php echo PLONTIS_VERSION; ?> | <a href="https://plontis.com">plontis.com</a></p>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    /**
    * Enhanced Valuation Page Implementation
    * Add this to class-plontis-admin.php to replace the existing valuation_page() method
    */

    public function valuation_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'plontis_detections';
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        if (!$table_exists) {
            echo '<div class="wrap"><h1>Plontis - Enhanced Valuation Report</h1>';
            echo '<div class="notice notice-error"><p>Database table not found. Please reactivate the plugin.</p></div>';
            echo '</div>';
            return;
        }
        
        // Get time range from URL parameter (default 30 days)
        $days = isset($_GET['days']) ? intval($_GET['days']) : 30;
        $days = max(7, min(365, $days)); // Limit between 7 and 365 days
        
        // Get raw detections
        $raw_detections = $wpdb->get_results($wpdb->prepare(
            "SELECT id, user_agent, ip_address, request_uri, bot_type, company, risk_level, confidence, commercial_risk, detected_at
            FROM $table_name 
            WHERE detected_at > DATE_SUB(NOW(), INTERVAL %d DAY)
            AND ip_address NOT LIKE '127.0.0.%%'
            ORDER BY detected_at DESC", 
            $days
        ), ARRAY_A);
        
        if (empty($raw_detections)) {
            echo '<div class="wrap"><h1>Plontis - Enhanced Valuation Report</h1>';
            echo '<div class="notice notice-info"><p>No AI bot detections found in the last ' . $days . ' days. Try increasing the time range or check your detection settings.</p></div>';
            echo '</div>';
            return;
        }
        
        // Calculate enhanced detections with fresh values
        $enhanced_detections = [];
        foreach ($raw_detections as $detection) {
            try {
                $content_metadata = $this->content_analyzer->analyzeContent($detection['request_uri']);
                $detection_data = [
                    'company' => $detection['company'],
                    'bot_type' => $detection['bot_type'],
                    'request_uri' => $detection['request_uri'],
                    'risk_level' => $detection['risk_level'],
                    'confidence' => intval($detection['confidence'] ?? 50),
                    'commercial_risk' => $detection['commercial_risk']
                ];
                
                $valuation = $this->value_calculator->calculateContentValue($detection_data, $content_metadata);
                
                $detection['estimated_value'] = $valuation['estimated_value'];
                $detection['content_type'] = $content_metadata['content_type'] ?? 'article';
                $detection['content_quality'] = $content_metadata['quality_score'] ?? 50;
                $detection['licensing_potential'] = $valuation['licensing_potential']['potential'];
                
                $enhanced_detections[] = $detection;
            } catch (Exception $e) {
                $detection['estimated_value'] = 0.00;
                $detection['content_type'] = 'article';
                $detection['content_quality'] = 50;
                $detection['licensing_potential'] = 'low';
                $enhanced_detections[] = $detection;
            }
        }
        
        // Calculate all analytics
        $portfolio_analysis = $this->value_calculator->calculatePortfolioValue($enhanced_detections);
        $performance_analytics = $this->getContentPerformanceAnalytics($enhanced_detections);
        $competitive_intel = $this->getCompetitiveIntelligence($enhanced_detections);
        $revenue_forecasting = $this->getRevenueForecasting($enhanced_detections);
        $licensing_strategies = $this->getLicensingStrategyRecommendations($portfolio_analysis, $competitive_intel);
        $risk_assessment = $this->getRiskAssessment($enhanced_detections);
        $industry_benchmarks = $this->getIndustryBenchmarking($portfolio_analysis);
        
        ?>
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Plontis Enhanced Valuation Report</title>
            </head>
            <body>
                <div class="wrap plontis-admin">
                    <div class="plontis-header">
                        <div class="plontis-logo">
                            <div class="plontis-icon"></div>
                            <h1 class="plontis-title">PLONTIS</h1>
                        </div>
                        <span class="plontis-subtitle">Enhanced Valuation Report</span>
                    </div>
                    
                    <!-- Time Range Selector -->
                    <div class="plontis-panel">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 20px;">
                            <h2 style="margin: 0;">📊 Portfolio Analysis - Last <?php echo $days; ?> Days</h2>
                            <div class="time-range-selector">
                                <select onchange="window.location.href='?page=plontis-valuation&days=' + this.value">
                                    <option value="7" <?php selected($days, 7); ?>>Last 7 Days</option>
                                    <option value="30" <?php selected($days, 30); ?>>Last 30 Days</option>
                                    <option value="90" <?php selected($days, 90); ?>>Last 90 Days</option>
                                    <option value="180" <?php selected($days, 180); ?>>Last 6 Months</option>
                                    <option value="365" <?php selected($days, 365); ?>>Last Year</option>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Executive Summary -->
                        <div class="executive-summary">
                            <div class="summary-stats">
                                <div class="summary-stat">
                                    <h3>Total Portfolio Value</h3>
                                   <div class="stat-value">$<?php echo number_format($portfolio_analysis['total_portfolio_value'], 2); ?></div>
                                    <div class="stat-change <?php echo $revenue_forecasting['trends']['growth_rate'] > 0 ? 'positive' : 'negative'; ?>">
                                        <?php echo $revenue_forecasting['trends']['trend_direction']; ?> 
                                        (<?php echo number_format(abs($revenue_forecasting['trends']['growth_rate']), 1); ?>%)
                                    </div>
                                </div>
                                <div class="summary-stat">
                                    <h3>Portfolio Value Projection</h3>
                                    <div class="stat-value">$<?php echo number_format($revenue_forecasting['projections']['annual_projection'], 2); ?></div>
                                    <div class="stat-range">
                                        $<?php echo number_format($revenue_forecasting['projections']['conservative_annual'], 2); ?> - 
                                        $<?php echo number_format($revenue_forecasting['projections']['optimistic_annual'], 2); ?>
                                    </div>
                                </div>
                                <div class="summary-stat">
                                    <h3>Industry Ranking</h3>
                                    <div class="stat-value"><?php echo ucfirst(str_replace('_', ' ', $industry_benchmarks['category'])); ?></div>
                                    <div class="stat-change"><?php echo $industry_benchmarks['percentile']; ?></div>
                                </div>
                                <div class="summary-stat">
                                    <h3>High-Value Opportunities</h3>
                                    <div class="stat-value"><?php echo $portfolio_analysis['licensing_candidates']; ?></div>
                                    <div class="stat-change">Ready for licensing</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="plontis-export-controls">
                        <h3>📊 Export Report</h3>
                        <div class="export-buttons">
                            <span style="color: #6c757d; font-size: 14px;">Export this report in:</span>
                            
                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=plontis-valuation&plontis_export=1&format=html&days=' . $days), 'plontis_export', 'nonce'); ?>" class="button" target="_blank">
                                📄 HTML Report
                            </a>
                            
                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=plontis-valuation&plontis_export=1&format=csv&days=' . $days), 'plontis_export', 'nonce'); ?>" class="button">
                                📊 CSV Data
                            </a>
                            
                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=plontis-valuation&plontis_export=1&format=json&days=' . $days), 'plontis_export', 'nonce'); ?>" class="button">
                                🔗 JSON
                            </a>
                            
                            <span style="color: #6c757d; font-size: 12px; margin-left: 10px;">
                                Reports include all <?php echo count($enhanced_detections); ?> detections from the last <?php echo $days; ?> days
                            </span>
                        </div>
                    </div>

                    <!-- Content Performance Analytics -->
                    <div class="plontis-panel">
                        <h2>📊 Content Performance Analytics</h2>
                        
                        <div class="analytics-grid">
                            <!-- Top Performing Pages -->
                            <div class="analytics-card">
                                <h3>Top Revenue-Generating Pages</h3>
                                <div class="performance-list">
                                    <?php foreach (array_slice($performance_analytics['page_analytics'], 0, 10) as $page => $data): ?>
                                    <div class="performance-item">
                                        <div class="page-info">
                                            <div class="page-url"><?php echo esc_html(substr($page, 0, 50)) . (strlen($page) > 50 ? '...' : ''); ?></div>
                                            <div class="page-stats">
                                                $<?php echo number_format($data['total_value'], 2); ?> (<?php echo $data['access_count']; ?> accesses, <?php echo $data['company_count']; ?> companies)
                                            </div>
                                        </div>
                                        <div class="page-value">$<?php echo number_format($data['avg_value_per_access'], 2); ?>/access</div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <!-- Content Type Revenue -->
                            <div class="analytics-card">
                                <h3>Revenue by Content Type</h3>
                                <div class="content-type-chart">
                                    <?php 
                                    $total_content_revenue = array_sum($performance_analytics['content_type_revenue']);
                                    foreach ($performance_analytics['content_type_revenue'] as $type => $revenue): 
                                        $percentage = $total_content_revenue > 0 ? ($revenue / $total_content_revenue) * 100 : 0;
                                    ?>
                                    <div class="content-type-item">
                                        <div class="content-type-info">
                                            <span class="content-type-name"><?php echo ucfirst($type); ?></span>
                                            <span class="content-type-revenue">$<?php echo number_format($revenue, 2); ?> (<?php echo number_format($percentage, 1); ?>%)</span>
                                        </div>
                                        <div class="content-type-bar">
                                            <div class="content-type-fill" style="width: <?php echo $percentage; ?>%"></div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <!-- Activity Patterns -->
                            <div class="analytics-card">
                                <h3>Peak Activity Insights</h3>
                                <div class="activity-insights">
                                    <div class="insight-item">
                                        <strong>Peak Hour:</strong> <?php echo $performance_analytics['peak_hour']; ?>:00 (<?php echo $performance_analytics['time_patterns']['hours'][$performance_analytics['peak_hour']]; ?> accesses)
                                    </div>
                                    <div class="insight-item">
                                        <strong>Peak Day:</strong> <?php echo $performance_analytics['peak_day']; ?> (<?php echo max($performance_analytics['time_patterns']['days']); ?> accesses)
                                    </div>
                                    <div class="insight-item">
                                        <strong>Total Unique Pages:</strong> <?php echo count($performance_analytics['page_analytics']); ?> pages
                                    </div>
                                    <div class="insight-item">
                                        <strong>Avg Value per Page:</strong> $<?php echo number_format($portfolio_analysis['total_portfolio_value'] / count($performance_analytics['page_analytics']), 2); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Competitive Intelligence -->
                    <div class="plontis-panel">
                        <h2>🎯 Competitive Intelligence</h2>
                        
                        <div class="competitive-grid">
                            <?php foreach ($competitive_intel as $company => $strategy): ?>
                            <div class="company-strategy-card">
                                <h3><?php echo esc_html($company); ?></h3>
                                <div class="strategy-overview">
                                    <div class="strategy-type"><?php echo $strategy['strategy']; ?></div>
                                    <div class="strategy-stats">
                                        <div class="stat-item">
                                            <span class="stat-label">Total Value</span>
                                            <span class="stat-value">$<?php echo number_format($strategy['total_value'], 2); ?></span>
                                        </div>
                                        <div class="stat-item">
                                            <span class="stat-label">Avg Session</span>
                                            <span class="stat-value">$<?php echo number_format($strategy['avg_session_value'], 2); ?></span>
                                        </div>
                                        <div class="stat-item">
                                            <span class="stat-label">Unique Pages</span>
                                            <span class="stat-value"><?php echo $strategy['unique_pages']; ?></span>
                                        </div>
                                    </div>
                                    
                                    <div class="content-preferences">
                                        <strong>Content Preferences:</strong>
                                        <?php 
                                        arsort($strategy['content_type_preference']);
                                        $top_types = array_slice($strategy['content_type_preference'], 0, 3, true);
                                        foreach ($top_types as $type => $count): ?>
                                            <span class="preference-tag"><?php echo ucfirst($type); ?> (<?php echo $count; ?>)</span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Industry Benchmarking -->
                    <div class="plontis-panel">
                        <h2>📊 Industry Benchmarking</h2>
                        
                        <div class="benchmarking-overview">
                            <div class="benchmark-card">
                                <h3>Your Current Position</h3>
                                <div class="benchmark-position">
                                    <div class="position-category"><?php echo ucfirst(str_replace('_', ' ', $industry_benchmarks['category'])); ?></div>
                                    <div class="position-percentile"><?php echo $industry_benchmarks['percentile']; ?></div>
                                    <div class="position-value">$<?php echo number_format($portfolio_analysis['estimated_annual_revenue'], 2); ?> annual</div>
                                </div>
                            </div>
                            
                            <div class="benchmark-card">
                                <h3>Industry Standards</h3>
                                <div class="benchmark-ranges">
                                    <div class="range-item">
                                        <span class="range-label">Low:</span>
                                        <span class="range-value">$<?php echo number_format($industry_benchmarks['benchmark_data']['low'], 2); ?></span>
                                    </div>
                                    <div class="range-item">
                                        <span class="range-label">Average:</span>
                                        <span class="range-value">$<?php echo number_format($industry_benchmarks['benchmark_data']['average'], 2); ?></span>
                                    </div>
                                    <div class="range-item">
                                        <span class="range-label">High:</span>
                                        <span class="range-value">$<?php echo number_format($industry_benchmarks['benchmark_data']['high'], 2); ?></span>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($industry_benchmarks['next_tier_target']): ?>
                            <div class="benchmark-card">
                                <h3>Next Tier Target</h3>
                                <div class="next-tier">
                                    <div class="tier-name"><?php echo ucfirst(str_replace('_', ' ', $industry_benchmarks['next_tier_target']['tier'])); ?></div>
                                    <div class="tier-target">$<?php echo number_format($industry_benchmarks['next_tier_target']['target_value'], 2); ?></div>
                                    <div class="tier-gap">
                                        $<?php echo number_format($industry_benchmarks['next_tier_target']['target_value'] - $portfolio_analysis['estimated_annual_revenue'], 2); ?> to reach
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($industry_benchmarks['improvement_potential'] > 0): ?>
                        <div class="improvement-potential">
                            <h3>Growth Potential</h3>
                            <p>You have <strong>$<?php echo number_format($industry_benchmarks['improvement_potential'], 2); ?></strong> in improvement potential within your current category.</p>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- CTA Section -->
                    <div class="plontis-cta">
                        <h3>Ready to Maximize Your Content Value?</h3>
                        <p>Your content portfolio shows strong licensing potential. Take the next step to monetize your AI bot traffic.</p>
                        <div class="cta-buttons">
                            <a href="https://plontis.com" class="button button-primary" target="_blank">
                                Explore Licensing Platform
                            </a>
                        </div>
                    </div>
                </div>

                <script>
                    // Add some interactive functionality
                    document.addEventListener('DOMContentLoaded', function() {
                        // Animate cards on scroll
                        const observer = new IntersectionObserver((entries) => {
                            entries.forEach(entry => {
                                if (entry.isIntersecting) {
                                    entry.target.style.opacity = '1';
                                    entry.target.style.transform = 'translateY(0)';
                                }
                            });
                        });

                        // Observe all cards
                        document.querySelectorAll('.analytics-card, .company-strategy-card, .strategy-card, .risk-card, .benchmark-card').forEach(card => {
                            card.style.opacity = '0';
                            card.style.transform = 'translateY(20px)';
                            card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
                            observer.observe(card);
                        });

                        // Animate progress bars
                        setTimeout(() => {
                            document.querySelectorAll('.content-type-fill').forEach(bar => {
                                const width = bar.style.width;
                                bar.style.width = '0%';
                                setTimeout(() => {
                                    bar.style.width = width;
                                }, 100);
                            });
                        }, 500);

                        // Add hover effects to stat cards
                        document.querySelectorAll('.summary-stat').forEach(stat => {
                            stat.addEventListener('mouseenter', function() {
                                this.style.transform = 'translateY(-8px) scale(1.02)';
                            });
                            
                            stat.addEventListener('mouseleave', function() {
                                this.style.transform = 'translateY(0) scale(1)';
                            });
                        });

                        // Smooth scrolling for internal links
                        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                            anchor.addEventListener('click', function (e) {
                                e.preventDefault();
                                const target = document.querySelector(this.getAttribute('href'));
                                if (target) {
                                    target.scrollIntoView({
                                        behavior: 'smooth',
                                        block: 'start'
                                    });
                                }
                            });
                        });
                    });
                </script>
            </body>
            </html>
        
        <?php
    }
}