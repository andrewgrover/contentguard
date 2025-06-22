<?php
/**
 * ContentGuard Admin Class
 * Handles all admin interface functionality
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class ContentGuard_Admin {
    
    private $value_calculator;
    private $content_analyzer;
    private $core;

    public function __construct() {
        $this->value_calculator = new ContentGuardValueCalculator();
        $this->content_analyzer = new ContentGuardContentAnalyzer();
        $this->core = new ContentGuard_Core();
    }

    public function init() {
        // Admin interface
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'admin_scripts']);
        
        // Dashboard widget
        add_action('wp_dashboard_setup', [$this, 'add_dashboard_widget']);
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

        add_submenu_page(
            'contentguard',
            'Licensing Report',
            'Licensing Report',
            'manage_options',
            'contentguard-licensing',
            [$this, 'licensing_page']
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
     * Enhanced admin page with our value calculation system
     */
    public function admin_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'contentguard_detections';
        
        // Get recent detections for portfolio analysis
        $detections = $wpdb->get_results("SELECT * FROM $table_name WHERE detected_at > DATE_SUB(NOW(), INTERVAL 30 DAY)", ARRAY_A);
        $portfolio_analysis = $this->value_calculator->calculatePortfolioValue($detections);
        
        ?>
        <div class="wrap contentguard-admin">
            <h1>
                <span class="dashicons dashicons-shield-alt"></span>
                ContentGuard - Enhanced AI Bot Detection v<?php echo CONTENTGUARD_VERSION; ?>
            </h1>
            
            <div class="notice notice-info">
                <h4>🚀 Enhanced Valuation System Active</h4>
                <p><strong>Industry-Accurate Pricing:</strong> Based on Getty Images ($130-$575), Academic Publishing ($1,626 avg), Music Licensing ($250-$2,000), and News Syndication rates.</p>
                <p><strong>Total Portfolio Value:</strong> $<?php echo number_format($portfolio_analysis['total_portfolio_value'], 2); ?> | 
                   <strong>High-Value Content:</strong> <?php echo $portfolio_analysis['high_value_content_count']; ?> items |
                   <strong>Licensing Candidates:</strong> <?php echo $portfolio_analysis['licensing_candidates']; ?> items</p>
            </div>
            
            <div class="contentguard-stats-grid">
                <div class="contentguard-stat-card">
                    <h3>Total AI Bots Detected</h3>
                    <div class="stat-number" id="total-bots">-</div>
                    <span class="stat-period">Last 30 days</span>
                </div>
                
                <div class="contentguard-stat-card">
                    <h3>Enhanced Portfolio Value</h3>
                    <div class="stat-number" id="content-value">$-</div>
                    <span class="stat-period">Industry-accurate pricing</span>
                </div>
                
                <div class="contentguard-stat-card">
                    <h3>Licensing Opportunities</h3>
                    <div class="stat-number" id="licensing-opportunities"><?php echo count($portfolio_analysis['recommendations'] ?? []); ?></div>
                    <span class="stat-period">High-value detections</span>
                </div>
                
                <div class="contentguard-stat-card">
                    <h3>Average Per Detection</h3>
                    <div class="stat-number" id="average-value">$<?php echo number_format($portfolio_analysis['average_value_per_access'], 2); ?></div>
                    <span class="stat-period">Market-based estimate</span>
                </div>
            </div>

            <?php if (!empty($portfolio_analysis['recommendations'])): ?>
            <div class="contentguard-panel">
                <h2>💰 Licensing Recommendations</h2>
                <?php foreach ($portfolio_analysis['recommendations'] as $recommendation): ?>
                <div class="licensing-recommendation priority-high">
                    <h4><?php echo esc_html($recommendation); ?></h4>
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
                        $detection = $this->core->analyze_user_agent($test_user_agent);
                        $content_metadata = $this->content_analyzer->analyzeContent($test_uri);
                        $valuation = $this->value_calculator->calculateContentValue(
                            array_merge($detection, ['request_uri' => $test_uri]), 
                            $content_metadata
                        );
                        
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
                        echo '<p><strong>Estimated Value:</strong> <span style="color: #28a745; font-weight: bold; font-size: 18px;">$' . number_format($valuation['estimated_value'], 2) . '</span></p>';                        
                        echo '<p><strong>Content Type:</strong> ' . esc_html($valuation['breakdown']['content_type']) . '</p>';
                        echo '<p><strong>Licensing Potential:</strong> <span class="risk-badge risk-' . $valuation['licensing_potential']['potential'] . '">' . esc_html($valuation['licensing_potential']['potential']) . '</span></p>';
                        echo '<p><strong>Market Position:</strong> ' . esc_html($valuation['market_context']['market_position']) . '</p>';
                        
                        // Show market comparison
                        echo '<h6>Market Comparison:</h6>';
                        echo '<ul style="margin: 5px 0; padding-left: 20px;">';
                        foreach ($valuation['licensing_potential']['comparable_rates'] as $source => $range) {
                            echo '<li>' . esc_html(ucwords(str_replace('_', ' ', $source))) . ': ' . esc_html($range) . '</li>';
                        }
                        echo '</ul>';
                        echo '</div>';
                        
                        echo '</div>'; // End grid
                        
                        if ($valuation['licensing_potential']['potential'] === 'High') {
                            echo '<div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 4px; margin-top: 15px;">';
                            echo '<h5>🎯 High Licensing Potential Detected!</h5>';
                            echo '<p>This content represents significant revenue opportunity. Annual potential: <strong>$' . number_format($valuation['licensing_potential']['estimated_annual_value'], 2) . '</strong></p>';
                            echo '<p><strong>Recommendation:</strong> ' . esc_html($valuation['licensing_potential']['recommendation']) . '</p>';
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
                <p>Your content is worth <strong>$<?php echo number_format($portfolio_analysis['estimated_annual_revenue'], 2); ?></strong> annually. Join our licensing platform to start earning.</p>
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
        .licensing-recommendation h4 {
            margin: 0 0 10px 0;
            color: #333;
        }
        </style>
        <?php
    }

    /**
     * Enhanced valuation page using our value calculator
     */
    public function valuation_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'contentguard_detections';
        
        $detections = $wpdb->get_results("SELECT * FROM $table_name ORDER BY detected_at DESC", ARRAY_A);
        $portfolio_analysis = $this->value_calculator->calculatePortfolioValue($detections);
        
        ?>
        <div class="wrap contentguard-admin">
            <h1>ContentGuard - Detailed Valuation Report</h1>
            
            <div class="contentguard-panel">
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
            
            <div class="contentguard-panel">
                <h2>Value Breakdown by Company</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Company</th>
                            <th>Total Value</th>
                            <th>Detection Count</th>
                            <th>Average Value</th>
                            <th>Market Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($portfolio_analysis['top_value_companies'])): ?>
                            <?php foreach ($portfolio_analysis['top_value_companies'] as $company => $value): 
                                $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE company = %s", $company));
                                $avg_value = $count > 0 ? $value / $count : 0;
                                $market_rate = ContentGuardLicensingMarketData::getMarketRate('article', $company);
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html($company); ?></strong></td>
                                <td>$<?php echo number_format($value, 2); ?></td>
                                <td><?php echo $count; ?></td>
                                <td>$<?php echo number_format($avg_value, 2); ?></td>
                                <td>$<?php echo number_format($market_rate, 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5">No company data available</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * Enhanced licensing page 
     */
    public function licensing_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'contentguard_detections';
        
        $detections = $wpdb->get_results("SELECT * FROM $table_name ORDER BY detected_at DESC", ARRAY_A);
        $portfolio_analysis = $this->value_calculator->calculatePortfolioValue($detections);
        
        // Get licensing recommendations using market data
        $licensing_recommendations = ContentGuardLicensingMarketData::getLicensingRecommendations(
            $portfolio_analysis['total_portfolio_value'],
            ['article', 'image', 'video'], // Content types
            array_keys($portfolio_analysis['top_value_companies'] ?? [])
        );
        
        ?>
        <div class="wrap contentguard-admin">
            <h1>ContentGuard - Licensing Report</h1>
            
            <div class="contentguard-panel">
                <h2>Licensing Opportunities</h2>
                
                <?php if (!empty($licensing_recommendations)): ?>
                    <?php foreach ($licensing_recommendations as $recommendation): ?>
                        <div class="licensing-opportunity">
                            <h4><?php echo esc_html($recommendation['type']); ?></h4>
                            <p><?php echo esc_html($recommendation['description']); ?></p>
                            <?php if (isset($recommendation['estimated_annual'])): ?>
                                <p><strong>Estimated Annual Value:</strong> $<?php echo number_format($recommendation['estimated_annual'], 2); ?></p>
                            <?php endif; ?>
                            <p><strong>Next Steps:</strong> <?php echo esc_html($recommendation['next_steps']); ?></p>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>Continue building your content portfolio to unlock licensing opportunities.</p>
                <?php endif; ?>
            </div>
        </div>
        
        <style>
        .licensing-opportunity {
            margin: 15px 0;
            padding: 15px;
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .licensing-opportunity h4 {
            margin: 0 0 10px 0;
            color: #007cba;
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
            "SELECT COUNT(*) FROM $table_name WHERE estimated_value >= 50.00 AND detected_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        
        $total_value = $wpdb->get_var(
            "SELECT SUM(estimated_value) FROM $table_name WHERE detected_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"
        ) ?: 0;
        
        ?>
        <div class="contentguard-widget">
            <p><strong><?php echo $recent_bots; ?></strong> AI bots detected this week</p>
            <p><strong><?php echo $high_value; ?></strong> high-value opportunities (≥$50)</p>
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
}
?>