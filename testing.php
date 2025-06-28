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
        $message .= "Join Plontis platform: https://plontis.ai/licensing\n";
        
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
            'Settings',
            'Settings',
            'manage_options',
            'plontis-settings',
            [$this, 'settings_page']
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
            'Report Archive',
            'Reports',
            'manage_options',
            'plontis-reports',
            [$this, 'reports_archive_page']
        );
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

    public function handle_report_actions() {
        // Handle report generation
        if (isset($_GET['generate_report']) && wp_verify_nonce($_GET['_wpnonce'], '_wpnonce')) {
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
                'licensing_notification_threshold' => floatval($_POST['licensing_notification_threshold'] ?? 100.00)
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
                    'pages_accessed' => []
                ];
            }
            
            $company_strategies[$company]['total_accesses']++;
            $company_strategies[$company]['total_value'] += $detection['estimated_value'];
            $company_strategies[$company]['content_types'][] = $content_type;
            $company_strategies[$company]['preferred_times'][] = $hour;
            $company_strategies[$company]['content_quality_focus'] += ($detection['content_quality'] ?? 50);
            $company_strategies[$company]['pages_accessed'][] = $detection['request_uri'];
        }
        
        // Process company data
        foreach ($company_strategies as $company => &$data) {
            $data['avg_session_value'] = $data['total_value'] / max(1, $data['total_accesses']);
            $data['avg_quality_focus'] = $data['content_quality_focus'] / max(1, $data['total_accesses']);
            $data['content_type_preference'] = array_count_values($data['content_types']);
            $data['time_preference'] = array_count_values($data['preferred_times']);
            $data['unique_pages'] = count(array_unique($data['pages_accessed']));
            
            // Determine strategy
            if ($data['avg_quality_focus'] > 80) {
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
        
        foreach ($enhanced_detections as $detection) {
            $date = date('Y-m-d', strtotime($detection['detected_at']));
            $month = date('m', strtotime($detection['detected_at']));
            
            $daily_values[$date] = ($daily_values[$date] ?? 0) + $detection['estimated_value'];
            $seasonal_patterns[$month] = ($seasonal_patterns[$month] ?? 0) + $detection['estimated_value'];
        }
        
        // Calculate growth trends
        ksort($daily_values);
        $values = array_values($daily_values);
        
        if (count($values) >= 7) {
            $recent_week = array_slice($values, -7);
            $previous_week = array_slice($values, -14, 7);
            
            $recent_avg = array_sum($recent_week) / 7;
            $previous_avg = count($previous_week) > 0 ? array_sum($previous_week) / count($previous_week) : $recent_avg;
            
            $growth_rate = $previous_avg > 0 ? (($recent_avg - $previous_avg) / $previous_avg) * 100 : 0;
        } else {
            $growth_rate = 0;
            $recent_avg = count($values) > 0 ? array_sum($values) / count($values) : 0;
        }
        
        // Revenue projections
        $projections = [
            'daily_average' => $recent_avg,
            'weekly_projection' => $recent_avg * 7,
            'monthly_projection' => $recent_avg * 30,
            'annual_projection' => $recent_avg * 365,
            'growth_rate' => $growth_rate
        ];
        
        // Conservative vs optimistic scenarios
        $projections['conservative_annual'] = $projections['annual_projection'] * 0.7; // 70% of current rate
        $projections['optimistic_annual'] = $projections['annual_projection'] * 1.5;   // 150% with growth
        
        $peak_season = !empty($seasonal_patterns) ? array_search(max($seasonal_patterns), $seasonal_patterns) : date('m');
        
        return [
            'daily_values' => $daily_values,
            'seasonal_patterns' => $seasonal_patterns,
            'projections' => $projections,
            'trends' => [
                'growth_rate' => $growth_rate,
                'trend_direction' => $growth_rate > 5 ? 'Growing' : ($growth_rate < -5 ? 'Declining' : 'Stable'),
                'peak_season' => $peak_season
            ]
        ];
    }

    /**
    * 4. Licensing Strategy Recommendations
    */
    private function getLicensingStrategyRecommendations($portfolio_analysis, $competitive_intel) {
        $recommendations = [];
        $total_value = $portfolio_analysis['total_portfolio_value'];
        
        // Tier-based recommendations
        if ($total_value > 50000) {
            $recommendations[] = [
                'category' => 'Enterprise Direct Licensing',
                'priority' => 'High',
                'description' => 'Your portfolio value justifies direct enterprise negotiations with major AI companies.',
                'action_items' => [
                    'Compile comprehensive usage reports for top 3 AI companies accessing your content',
                    'Engage intellectual property attorney specializing in AI licensing',
                    'Prepare enterprise licensing packages targeting $100K+ annual deals',
                    'Document all training data usage patterns for legal leverage in negotiations'
                ],
                'potential_revenue' => $total_value * 0.4, // 40% licensing rate for enterprise
                'timeline' => '3-6 months'
            ];
        }
        
        if ($total_value > 10000) {
            $recommendations[] = [
                'category' => 'Platform-Based Licensing',
                'priority' => 'Medium',
                'description' => 'Join AI training data marketplaces for automated licensing revenue streams.',
                'action_items' => [
                    'Register with Plontis licensing platform and other AI data marketplaces',
                    'Set up automated content feeds with quality scoring',
                    'Configure dynamic pricing based on content quality metrics and demand',
                    'Monitor competitive pricing and adjust rates quarterly'
                ],
                'potential_revenue' => $total_value * 0.2, // 20% platform licensing rate
                'timeline' => '1-2 months'
            ];
        }
        
        // Company-specific strategies
        foreach ($competitive_intel as $company => $strategy) {
            if ($strategy['total_value'] > 5000) {
                $recommendations[] = [
                    'category' => "Direct Outreach - {$company}",
                    'priority' => $strategy['total_value'] > 15000 ? 'High' : 'Medium',
                    'description' => "Targeted licensing approach for {$company} based on their documented usage patterns and content preferences.",
                    'action_items' => [
                        "Document {$company}'s specific content usage patterns and peak access times",
                        "Research {$company}'s existing licensing deals and partnership models",
                        "Prepare content portfolio specifically valuable to {$company}'s AI training needs",
                        "Initiate contact through business development channels or licensing intermediaries"
                    ],
                    'potential_revenue' => $strategy['total_value'] * 0.3,
                    'timeline' => '2-4 months',
                    'strategy_insight' => $strategy['strategy']
                ];
            }
        }
        
        // Content optimization recommendations
        $recommendations[] = [
            'category' => 'Content Value Optimization',
            'priority' => 'Ongoing',
            'description' => 'Increase your content\'s licensing value through strategic improvements and AI-focused optimization.',
            'action_items' => [
                'Focus on high-value content types (research papers, technical documentation, original analysis)',
                'Improve content quality scores through better structure, depth, and multimedia integration',
                'Add interactive elements and data visualizations to increase per-access value',
                'Create exclusive, original research content that AI companies cannot find elsewhere',
                'Implement content freshness strategies to maintain high relevance scores'
            ],
            'potential_revenue' => $total_value * 0.25, // 25% value increase through optimization
            'timeline' => 'Ongoing'
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
                'low' => 1000,    // $1K annual
                'average' => 5000,    // $5K annual  
                'high' => 25000   // $25K annual
            ],
            'small_publisher' => [
                'low' => 5000,    // $5K annual
                'average' => 25000,   // $25K annual
                'high' => 100000  // $100K annual
            ],
            'medium_publisher' => [
                'low' => 25000,   // $25K annual
                'average' => 150000,  // $150K annual
                'high' => 500000  // $500K annual
            ],
            'enterprise_publisher' => [
                'low' => 500000,  // $500K annual
                'average' => 2000000, // $2M annual
                'high' => 10000000 // $10M annual
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
        fputcsv($output, ['Annual Revenue Projection: $' . number_format($portfolio_analysis['estimated_annual_revenue'], 2)]);
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
                <p>Generated by Plontis v<?php echo PLONTIS_VERSION; ?> | <a href="https://plontis.ai">plontis.ai</a></p>
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
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h2 style="margin: 0;">Portfolio Analysis - Last <?php echo $days; ?> Days</h2>
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
                            <h3>Annual Revenue Projection</h3>
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

            <div class="plontis-export-controls" style="margin: 20px 0; padding: 15px; background: #f8f9fa; border-radius: 8px; border: 1px solid #e9ecef;">
                <h3 style="margin: 0 0 15px 0; font-size: 16px;">📊 Export Report</h3>
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <span style="color: #6c757d; font-size: 14px;">Export this report in:</span>
                    
                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=plontis-valuation&plontis_export=1&format=html&days=' . $days), 'plontis_export', 'nonce'); ?>" 
                    class="button" target="_blank">
                    📄 HTML Report
                    </a>
                    
                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=plontis-valuation&plontis_export=1&format=csv&days=' . $days), 'plontis_export', 'nonce'); ?>" 
                    class="button">
                    📊 CSV Data
                    </a>
                    
                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=plontis-valuation&plontis_export=1&format=json&days=' . $days), 'plontis_export', 'nonce'); ?>" 
                    class="button">
                    🔗 JSON API
                    </a>
                    
                    <button onclick="window.print()" class="button">
                        🖨️ Print Report
                    </button>
                    
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
                                            $<?php echo number_format($data['total_value'], 2); ?> 
                                            (<?php echo $data['access_count']; ?> accesses, 
                                            <?php echo $data['company_count']; ?> companies)
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
                                        <span class="content-type-revenue">$<?php echo number_format($revenue, 2); ?></span>
                                    </div>
                                    <div class="content-type-bar">
                                        <div class="content-type-fill" style="width: <?php echo $percentage; ?>%"></div>
                                    </div>
                                    <div class="content-type-percentage"><?php echo number_format($percentage, 1); ?>%</div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Activity Patterns -->
                    <div class="analytics-card">
                        <h3>Peak Activity Insights</h3>
                        <div class="activity-insights">
                            <div class="insight-item">
                                <strong>Peak Hour:</strong> <?php echo $performance_analytics['peak_hour']; ?>:00
                                (<?php echo $performance_analytics['time_patterns']['hours'][$performance_analytics['peak_hour']]; ?> accesses)
                            </div>
                            <div class="insight-item">
                                <strong>Peak Day:</strong> <?php echo $performance_analytics['peak_day']; ?>
                                (<?php echo max($performance_analytics['time_patterns']['days']); ?> accesses)
                            </div>
                            <div class="insight-item">
                                <strong>Total Unique Pages:</strong> <?php echo count($performance_analytics['page_analytics']); ?>
                            </div>
                            <div class="insight-item">
                                <strong>Avg Value per Page:</strong> 
                                $<?php echo number_format($portfolio_analysis['total_portfolio_value'] / count($performance_analytics['page_analytics']), 2); ?>
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
                                        <span class="stat-label">Total Value:</span>
                                        <span class="stat-value">$<?php echo number_format($strategy['total_value'], 2); ?></span>
                                    </div>
                                    <div class="stat-item">
                                        <span class="stat-label">Avg Session Value:</span>
                                        <span class="stat-value">$<?php echo number_format($strategy['avg_session_value'], 2); ?></span>
                                    </div>
                                    <div class="stat-item">
                                        <span class="stat-label">Quality Focus:</span>
                                        <span class="stat-value"><?php echo number_format($strategy['avg_quality_focus'], 0); ?>/100</span>
                                    </div>
                                    <div class="stat-item">
                                        <span class="stat-label">Unique Pages:</span>
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

            <!-- Revenue Forecasting -->
            <div class="plontis-panel">
                <h2>📈 Revenue Forecasting & Trends</h2>
                
                <div class="forecasting-grid">
                    <div class="forecast-card">
                        <h3>Revenue Projections</h3>
                        <div class="projection-list">
                            <div class="projection-item">
                                <span class="projection-label">Daily Average:</span>
                                <span class="projection-value">$<?php echo number_format($revenue_forecasting['projections']['daily_average'], 2); ?></span>
                            </div>
                            <div class="projection-item">
                                <span class="projection-label">Weekly Projection:</span>
                                <span class="projection-value">$<?php echo number_format($revenue_forecasting['projections']['weekly_projection'], 2); ?></span>
                            </div>
                            <div class="projection-item">
                                <span class="projection-label">Monthly Projection:</span>
                                <span class="projection-value">$<?php echo number_format($revenue_forecasting['projections']['monthly_projection'], 2); ?></span>
                            </div>
                            <div class="projection-item highlighted">
                                <span class="projection-label">Annual Projection:</span>
                                <span class="projection-value">$<?php echo number_format($revenue_forecasting['projections']['annual_projection'], 2); ?></span>
                            </div>
                        </div>
                        
                        <div class="scenario-analysis">
                            <h4>Scenario Analysis</h4>
                            <div class="scenario-item conservative">
                                <span class="scenario-label">Conservative (70%):</span>
                                <span class="scenario-value">$<?php echo number_format($revenue_forecasting['projections']['conservative_annual'], 2); ?></span>
                            </div>
                            <div class="scenario-item optimistic">
                                <span class="scenario-label">Optimistic (150%):</span>
                                <span class="scenario-value">$<?php echo number_format($revenue_forecasting['projections']['optimistic_annual'], 2); ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="forecast-card">
                        <h3>Growth Trends</h3>
                        <div class="trend-analysis">
                            <div class="trend-item">
                                <span class="trend-label">Growth Rate:</span>
                                <span class="trend-value <?php echo $revenue_forecasting['trends']['growth_rate'] > 0 ? 'positive' : 'negative'; ?>">
                                    <?php echo number_format($revenue_forecasting['trends']['growth_rate'], 1); ?>%
                                </span>
                            </div>
                            <div class="trend-item">
                                <span class="trend-label">Trend Direction:</span>
                                <span class="trend-value"><?php echo $revenue_forecasting['trends']['trend_direction']; ?></span>
                            </div>
                            <?php if (!empty($revenue_forecasting['trends']['peak_season'])): ?>
                            <div class="trend-item">
                                <span class="trend-label">Peak Season:</span>
                                <span class="trend-value">Month <?php echo $revenue_forecasting['trends']['peak_season']; ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Licensing Strategy Recommendations -->
            <div class="plontis-panel">
                <h2>💼 Strategic Licensing Recommendations</h2>
                
                <div class="licensing-strategies">
                    <?php foreach ($licensing_strategies as $strategy): ?>
                        <div class="strategy-card priority-<?php echo strtolower($strategy['priority']); ?>">
                            <div class="strategy-header">
                                <h3><?php echo $strategy['category']; ?></h3>
                                <span class="priority-badge priority-<?php echo strtolower($strategy['priority']); ?>">
                                    <?php echo $strategy['priority']; ?> Priority
                                </span>
                            </div>
                            
                            <div class="strategy-content">
                                <p class="strategy-description"><?php echo $strategy['description']; ?></p>
                                
                                <div class="strategy-metrics">
                                    <div class="metric-item">
                                        <span class="metric-label">Potential Revenue:</span>
                                        <span class="metric-value">$<?php echo number_format($strategy['potential_revenue'], 2); ?></span>
                                    </div>
                                    <div class="metric-item">
                                        <span class="metric-label">Timeline:</span>
                                        <span class="metric-value"><?php echo $strategy['timeline']; ?></span>
                                    </div>
                                    <?php if (isset($strategy['strategy_insight'])): ?>
                                    <div class="metric-item">
                                        <span class="metric-label">Strategy Insight:</span>
                                        <span class="metric-value"><?php echo $strategy['strategy_insight']; ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="action-items">
                                    <h4>Action Items:</h4>
                                    <ul>
                                        <?php foreach ($strategy['action_items'] as $action): ?>
                                            <li><?php echo esc_html($action); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Risk Assessment -->
            <div class="plontis-panel">
                <h2>⚠️ Risk Assessment & Compliance</h2>
                
                <div class="risk-assessment-grid">
                    <?php foreach ($risk_assessment['risk_factors'] as $risk_type => $risk_data): ?>
                        <div class="risk-card risk-<?php echo strtolower($risk_data['risk_level']); ?>">
                            <h3><?php echo ucfirst(str_replace('_', ' ', $risk_type)); ?></h3>
                            <div class="risk-metrics">
                                <div class="risk-count"><?php echo $risk_data['count']; ?> instances</div>
                                <div class="risk-level"><?php echo $risk_data['risk_level']; ?> Risk</div>
                            </div>
                            <p class="risk-description"><?php echo $risk_data['description']; ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="compliance-recommendations">
                    <h3>Compliance Recommendations</h3>
                    <ul class="recommendation-list">
                        <?php foreach ($risk_assessment['recommendations'] as $recommendation): ?>
                            <li><?php echo esc_html($recommendation); ?></li>
                        <?php endforeach; ?>
                    </ul>
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
                    <p>You have <strong>$<?php echo number_format($industry_benchmarks['improvement_potential'], 2); ?></strong> 
                    in improvement potential within your current category.</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- CTA Section -->
            <div class="plontis-cta">
                <h3>Ready to Maximize Your Content Value?</h3>
                <p>Your content portfolio shows strong licensing potential. Take the next step to monetize your AI bot traffic.</p>
                <div class="cta-buttons">
                    <a href="https://plontis.ai/licensing" class="button button-primary button-hero" target="_blank">
                        Start Licensing Platform
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=plontis-settings'); ?>" class="button button-secondary">
                        Optimize Settings
                    </a>
                    <button onclick="window.print()" class="button">Export Report</button>
                </div>
            </div>
        </div>
        <?php
    }

    public function add_dashboard_widget() {
        wp_add_dashboard_widget(
            'plontis_enhanced_widget',
            'Plontis - AI Content Valuation',
            [$this, 'enhanced_dashboard_widget_content']
        );
    }

    public function enhanced_dashboard_widget_content() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'plontis_detections';
        
        // Get last 7 days of data
        $recent_detections = $wpdb->get_results(
            "SELECT * FROM $table_name WHERE detected_at > DATE_SUB(NOW(), INTERVAL 7 DAY)",
            ARRAY_A
        );
        
        if (empty($recent_detections)) {
            echo '<p>No AI bot activity detected in the last 7 days.</p>';
            echo '<p><a href="' . admin_url('admin.php?page=plontis') . '" class="button">View Dashboard</a></p>';
            return;
        }
        
        // Quick calculation
        $total_value = 0;
        $high_value_count = 0;
        
        foreach ($recent_detections as $detection) {
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
                $value = $valuation['estimated_value'];
                $total_value += $value;
                
                if ($value > 50) {
                    $high_value_count++;
                }
            } catch (Exception $e) {
                // Skip failed calculations
            }
        }
        
        ?>
        <div class="plontis-dashboard-widget">
            <div class="widget-stats">
                <div class="stat-item">
                    <strong><?php echo count($recent_detections); ?></strong>
                    <span>AI bot detections this week</span>
                </div>
                <div class="stat-item">
                    <strong>$<?php echo number_format($total_value, 2); ?></strong>
                    <span>Content value generated</span>
                </div>
                <div class="stat-item">
                    <strong><?php echo $high_value_count; ?></strong>
                    <span>High-value opportunities</span>
                </div>
            </div>
            
            <div class="widget-actions">
                <a href="<?php echo admin_url('admin.php?page=plontis-valuation'); ?>" class="button button-primary">
                    📊 View Detailed Report
                </a>
                <a href="<?php echo admin_url('admin.php?page=plontis-reports'); ?>" class="button">
                    📁 Report Archive
                </a>
                <a href="https://plontis.ai/licensing" target="_blank" class="button">
                    💰 Start Licensing
                </a>
            </div>
            
            <?php if ($total_value > 1000): ?>
            <div class="widget-alert">
                <p><strong>💡 Licensing Opportunity Detected!</strong></p>
                <p>Your content value this week suggests strong licensing potential. Consider reaching out to AI companies for revenue opportunities.</p>
            </div>
            <?php endif; ?>
        </div>
        
        
        <?php
    }
}