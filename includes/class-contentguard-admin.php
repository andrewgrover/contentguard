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
    }

    public function init() {
        // Admin interface
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'admin_scripts']);
        
        // Dashboard widget
        add_action('wp_dashboard_setup', [$this, 'add_dashboard_widget']);
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
        $version = defined('PLONTIS_VERSION') ? PLONTIS_VERSION : '2.0.0';
        
        // Build file paths
        $admin_js_url = $plugin_url . 'admin.js';
        $admin_css_url = $plugin_url . 'admin.css';
        $chart_js_url = 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js';
        
        // Check if files exist
        $admin_js_path = PLONTIS_PLUGIN_PATH . 'admin.js';
        $admin_css_path = PLONTIS_PLUGIN_PATH . 'admin.css';
        
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
    }

    public function admin_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'plontis_detections';
        
        // Get raw detections (no old estimated_value) - same as AJAX methods
        $raw_detections = $wpdb->get_results(
            "SELECT id, user_agent, ip_address, request_uri, bot_type, company, risk_level, confidence, commercial_risk, detected_at
             FROM $table_name 
             WHERE detected_at > DATE_SUB(NOW(), INTERVAL 30 DAY)", 
            ARRAY_A
        );
        
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
            <h1>
                <span class="dashicons dashicons-shield-alt"></span>
                Plontis - AI Bot Detection v<?php echo PLONTIS_VERSION; ?>
            </h1>
            
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
                                    placeholder="Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)" 
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
                <a href="https://plontis.ai/join" class="button button-primary button-hero" target="_blank">
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
                            <input type="checkbox" name="enable_detection" <?php checked($settings['enable_detection'] ?? true); ?> />
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
                            <input type="checkbox" name="enable_notifications" <?php checked($settings['enable_notifications'] ?? true); ?> />
                            <p class="description">Get notified when high-value AI bots are detected</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Notification Email</th>
                        <td>
                            <input type="email" name="notification_email" value="<?php echo esc_attr($settings['notification_email'] ?? true); ?>" class="regular-text" />
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
                            <input type="number" name="log_retention_days" value="<?php echo esc_attr($settings['log_retention_days'] ?? true); ?>" min="7" max="365" />
                            <p class="description">Days to keep detection logs (recommended: 90)</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Track All Bots</th>
                        <td>
                            <input type="checkbox" name="track_legitimate_bots" <?php checked($settings['track_legitimate_bots'] ?? true); ?> />
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
     * Enhanced valuation page using our value calculator
     */
    public function valuation_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'plontis_detections';
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        if (!$table_exists) {
            echo '<div class="wrap"><h1>Plontis - Valuation Report</h1>';
            echo '<div class="notice notice-error"><p>Database table not found. Please reactivate the plugin.</p></div>';
            echo '</div>';
            return;
        }
        
        // Get raw detections (no old estimated_value) - same as other methods
        $raw_detections = $wpdb->get_results(
            "SELECT id, user_agent, ip_address, request_uri, bot_type, company, risk_level, confidence, commercial_risk, detected_at
             FROM $table_name 
             ORDER BY detected_at DESC", 
            ARRAY_A
        );
        
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
        
        $portfolio_analysis = $this->value_calculator->calculatePortfolioValue($enhanced_detections);
        
        ?>
        <div class="wrap plontis-admin">
            <h1>Plontis - Detailed Valuation Report</h1>
            
            <div class="plontis-panel">
                <h2>Portfolio Summary</h2>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                    <div>
                        <h4>Total Portfolio Value</h4>
                        <p style="font-size: 24px; font-weight: bold; color: #28a745;">$<?php echo number_format($portfolio_analysis['total_portfolio_value'], 2); ?></p>
                    </div>
                    <div>
                        <h4>Estimated Annual Revenue</h4>
                        <p style="font-size: 24px; font-weight: bold; color: #0dcaf0;">$<?php echo number_format($portfolio_analysis['estimated_annual_revenue'], 2); ?></p>
                    </div>
                    <div>
                        <h4>Average Per Detection</h4>
                        <p style="font-size: 24px; font-weight: bold; color: #6f42c1;">$<?php echo number_format($portfolio_analysis['average_value_per_access'], 2); ?></p>
                    </div>
                    <div>
                        <h4>High-Value Content</h4>
                        <p style="font-size: 24px; font-weight: bold; color: #dc3545;"><?php echo $portfolio_analysis['high_value_content_count']; ?></p>
                    </div>
                </div>
            </div>
            
            <div class="plontis-panel">
                <h2>Value Breakdown by Company</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Company</th>
                            <th>Total Value</th>
                            <th>Detection Count</th>
                            <th>Average Value</th>
                            <th>Content Types</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        // Group detections by company
                        $company_data = [];
                        foreach ($enhanced_detections as $detection) {
                            $company = $detection['company'] ?? 'Unknown';
                            if (!isset($company_data[$company])) {
                                $company_data[$company] = [
                                    'total_value' => 0,
                                    'count' => 0,
                                    'content_types' => []
                                ];
                            }
                            $company_data[$company]['total_value'] += $detection['estimated_value'];
                            $company_data[$company]['count']++;
                            $company_data[$company]['content_types'][] = $detection['content_type'] ?? 'article';
                        }
                        
                        // Sort by total value (descending)
                        uasort($company_data, function($a, $b) {
                            return $b['total_value'] <=> $a['total_value'];
                        });
                        
                        if (!empty($company_data)): ?>
                            <?php foreach ($company_data as $company => $data): 
                                $avg_value = $data['count'] > 0 ? $data['total_value'] / $data['count'] : 0;
                                $unique_types = array_unique($data['content_types']);
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html($company); ?></strong></td>
                                <td>$<?php echo number_format($data['total_value'], 2); ?></td>
                                <td><?php echo $data['count']; ?></td>
                                <td>$<?php echo number_format($avg_value, 2); ?></td>
                                <td><?php echo esc_html(implode(', ', $unique_types)); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5">No detection data available</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="plontis-panel">
                <h2>Recent High-Value Detections</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Company</th>
                            <th>Content</th>
                            <th>Value</th>
                            <th>Licensing Potential</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        // Get top 20 highest value detections
                        usort($enhanced_detections, function($a, $b) {
                            return $b['estimated_value'] <=> $a['estimated_value'];
                        });
                        $top_detections = array_slice($enhanced_detections, 0, 20);
                        
                        if (!empty($top_detections)): ?>
                            <?php foreach ($top_detections as $detection): ?>
                            <tr>
                                <td><?php echo date('M j, Y', strtotime($detection['detected_at'])); ?></td>
                                <td><?php echo esc_html($detection['company'] ?? 'Unknown'); ?></td>
                                <td><?php echo esc_html(substr($detection['request_uri'], 0, 50)) . (strlen($detection['request_uri']) > 50 ? '...' : ''); ?></td>
                                <td><strong>$<?php echo number_format($detection['estimated_value'], 2); ?></strong></td>
                                <td>
                                    <span class="risk-badge risk-<?php echo strtolower($detection['licensing_potential'] ?? 'low'); ?>">
                                        <?php echo ucfirst($detection['licensing_potential'] ?? 'Low'); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5">No high-value detections found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="plontis-panel">
                <h2>Licensing Recommendations</h2>
                <?php if (!empty($portfolio_analysis['recommendations'])): ?>
                    <?php foreach ($portfolio_analysis['recommendations'] as $recommendation): ?>
                        <div class="licensing-recommendation priority-high">
                            <h4><?php echo esc_html($recommendation); ?></h4>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>Continue building your content portfolio to unlock licensing opportunities.</p>
                    <div class="licensing-recommendation">
                        <h4>Build Your Content Value</h4>
                        <p>Create high-quality, technical content that AI companies find valuable. Focus on:</p>
                        <ul>
                            <li>In-depth tutorials and guides</li>
                            <li>Technical documentation</li>
                            <li>Industry research and analysis</li>
                            <li>Original data and insights</li>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="plontis-cta">
                <h3>Ready to Monetize Your Content?</h3>
                <p>Your content portfolio is worth <strong>$<?php echo number_format($portfolio_analysis['total_portfolio_value'], 2); ?></strong> with an estimated annual revenue potential of <strong>$<?php echo number_format($portfolio_analysis['estimated_annual_revenue'], 2); ?></strong>.</p>
                <a href="https://plontis.ai/licensing" class="button button-primary button-hero" target="_blank">
                    Start Licensing Your Content
                </a>
            </div>
        </div>
        
        <style>
        .licensing-recommendation {
            margin: 15px 0;
            padding: 15px;
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .licensing-recommendation.priority-high {
            border-left: 4px solid #dc3545;
            background: #fff5f5;
        }
        .licensing-recommendation h4 {
            margin: 0 0 10px 0;
            color: #007cba;
        }
        .risk-badge {
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: bold;
        }
        .risk-badge.risk-high {
            background: #dc3545;
            color: white;
        }
        .risk-badge.risk-medium {
            background: #ffc107;
            color: black;
        }
        .risk-badge.risk-low {
            background: #6c757d;
            color: white;
        }
        </style>
        <?php
    }

    public function add_dashboard_widget() {
        wp_add_dashboard_widget(
            'plontis_widget',
            'Plontis - Enhanced AI Bot Activity',
            [$this, 'dashboard_widget_content']
        );
    }

    public function dashboard_widget_content() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'plontis_detections';
        
        $recent_bots = $wpdb->get_var(
            "SELECT COUNT(*) FROM $table_name WHERE detected_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        
        $high_value = $wpdb->get_var(
            "SELECT COUNT(*) FROM $table_name WHERE estimated_value >= 50.00 AND detected_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        
        $total_value = $wpdb->get_var(
            "SELECT SUM(estimated_value) FROM $table_name WHERE detected_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"
        ) ?: 0;
        
        ?>
        <div class="plontis-widget">
            <p><strong><?php echo $recent_bots; ?></strong> AI bots detected this week</p>
            <p><strong><?php echo $high_value; ?></strong> high-value opportunities (≥$50)</p>
            <p><strong>$<?php echo number_format($total_value, 2); ?></strong> total estimated value this week</p>
            <p>
                <a href="<?php echo admin_url('admin.php?page=plontis'); ?>" class="button">
                    View Enhanced Dashboard
                </a>
                <a href="<?php echo admin_url('admin.php?page=plontis-valuation'); ?>" class="button">
                    Valuation Report
                </a>
                <a href="https://plontis.ai/licensing" target="_blank" class="button button-primary">
                    Start Earning
                </a>
            </p>
        </div>
        <?php
    }
}