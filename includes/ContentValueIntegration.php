<?php
/**
 * Plontis Value Integration
 * Integrates the advanced value calculation system with the main plugin
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Include the value calculation classes with correct paths
require_once PLONTIS_PLUGIN_PATH . 'includes/ContentValueCalculator.php';
require_once PLONTIS_PLUGIN_PATH . 'includes/ContentAnalyzer.php';
require_once PLONTIS_PLUGIN_PATH . 'includes/LicensingMarketData.php';

class PlontisValueIntegration {
    
    private $value_calculator;
    private $content_analyzer;
    
    public function __construct() {
        $this->value_calculator = new PlontisValueCalculator();
        $this->content_analyzer = new PlontisContentAnalyzer();
        
        // Hook into the main plugin
        add_action('plontis_bot_detected', [$this, 'handleBotDetection'], 10, 2);
        add_filter('plontis_calculate_value', [$this, 'calculateAdvancedValue'], 10, 2);
        add_action('wp_ajax_plontis_get_value_breakdown', [$this, 'ajaxGetValueBreakdown']);
        add_action('wp_ajax_plontis_analyze_content', [$this, 'ajaxAnalyzeContent']);
    }
    
    /**
     * Handle bot detection and calculate accurate value
     */
    public function handleBotDetection($detection_data, $request_uri) {
        // Analyze the content that was accessed
        $content_metadata = $this->content_analyzer->analyzeContent($request_uri);
        
        // Calculate comprehensive value
        $value_data = $this->value_calculator->calculateContentValue($detection_data, $content_metadata);
        
        // Store enhanced detection data
        $this->storeEnhancedDetection($detection_data, $value_data, $content_metadata);
        
        // Trigger notifications for high-value detections
        if ($value_data['estimated_value'] > 100) {
            $this->triggerHighValueNotification($detection_data, $value_data);
        }
        
        return $value_data;
    }
    
    /**
     * Calculate advanced value using the comprehensive system
     */
    public function calculateAdvancedValue($basic_value, $detection_data) {
        $request_uri = $detection_data['request_uri'] ?? '';
        
        // Analyze content
        $content_metadata = $this->content_analyzer->analyzeContent($request_uri);
        
        // Calculate advanced value
        $value_data = $this->value_calculator->calculateContentValue($detection_data, $content_metadata);
        
        return $value_data['estimated_value'];
    }
    
    /**
     * Store enhanced detection data with value analysis
     */
    private function storeEnhancedDetection($detection_data, $value_data, $content_metadata) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'plontis_detections';
        
        // Update the detection record with enhanced data
        $detection_id = $detection_data['id'] ?? null;
        
        if ($detection_id) {
            $wpdb->update(
                $table_name,
                [
                    'estimated_value' => $value_data['estimated_value'],
                    'content_type' => $content_metadata['content_type'] ?? 'unknown',
                    'content_quality' => $content_metadata['quality_score'] ?? 50,
                    'word_count' => $content_metadata['word_count'] ?? 0,
                    'technical_depth' => $content_metadata['technical_depth'] ?? 'basic',
                    'value_breakdown' => json_encode($value_data['breakdown']),
                    'licensing_potential' => $value_data['licensing_potential']['potential'] ?? 'low'
                ],
                ['id' => $detection_id]
            );
        } else {
            // Store as new enhanced detection
            $wpdb->insert(
                $table_name,
                array_merge($detection_data, [
                    'estimated_value' => $value_data['estimated_value'],
                    'content_type' => $content_metadata['content_type'] ?? 'unknown',
                    'content_quality' => $content_metadata['quality_score'] ?? 50,
                    'word_count' => $content_metadata['word_count'] ?? 0,
                    'technical_depth' => $content_metadata['technical_depth'] ?? 'basic',
                    'value_breakdown' => json_encode($value_data['breakdown']),
                    'licensing_potential' => $value_data['licensing_potential']['potential'] ?? 'low'
                ])
            );
        }
    }
    
    /**
     * Trigger high-value detection notifications
     */
    private function triggerHighValueNotification($detection_data, $value_data) {
        $settings = get_option('plontis_settings', []);
        
        if (!($settings['enable_notifications'] ?? false)) {
            return;
        }
        
        $company = $detection_data['company'] ?? 'Unknown';
        $estimated_value = $value_data['estimated_value'];
        $licensing_potential = $value_data['licensing_potential']['potential'];
        
        // Throttle notifications - only send once per day for same company
        $throttle_key = "plontis_high_value_notification_{$company}";
        if (get_transient($throttle_key)) {
            return;
        }
        
        $to = $settings['notification_email'] ?? get_option('admin_email');
        $subject = "High-Value AI Bot Detection: {$company} - {$estimated_value}";
        
        $message = "Plontis has detected high-value AI bot activity:\n\n";
        $message .= "Company: {$company}\n";
        $message .= "Estimated Content Value: {$estimated_value}\n";
        $message .= "Licensing Potential: {$licensing_potential}\n";
        $message .= "Page Accessed: {$detection_data['request_uri']}\n";
        $message .= "Detection Time: " . current_time('Y-m-d H:i:s') . "\n\n";
        
        $message .= "Value Breakdown:\n";
        foreach ($value_data['breakdown'] as $factor => $value) {
            $message .= "- " . ucfirst(str_replace('_', ' ', $factor)) . ": {$value}\n";
        }
        
        $message .= "\nMarket Context:\n";
        $message .= $value_data['market_context']['market_position'] . "\n\n";
        
        $message .= "Licensing Recommendation:\n";
        $message .= $value_data['licensing_potential']['recommendation'] . "\n\n";
        
        $message .= "View detailed analysis: " . admin_url('admin.php?page=plontis&detection_id=' . ($detection_data['id'] ?? '')) . "\n";
        $message .= "Join Plontis platform: https://plontis.ai/join\n";
        
        wp_mail($to, $subject, $message);
        
        // Set throttle for 24 hours
        set_transient($throttle_key, true, DAY_IN_SECONDS);
    }
    
    /**
     * AJAX handler for getting value breakdown
     */
    public function ajaxGetValueBreakdown() {
        check_ajax_referer('plontis_nonce', 'nonce');
        
        $detection_id = intval($_POST['detection_id'] ?? 0);
        
        if (!$detection_id) {
            wp_send_json_error('Invalid detection ID');
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'plontis_detections';
        
        $detection = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $detection_id
        ), ARRAY_A);
        
        if (!$detection) {
            wp_send_json_error('Detection not found');
        }
        
        // Get fresh value calculation
        $content_metadata = $this->content_analyzer->analyzeContent($detection['request_uri']);
        $value_data = $this->value_calculator->calculateContentValue($detection, $content_metadata);
        
        wp_send_json_success([
            'detection' => $detection,
            'value_data' => $value_data,
            'content_metadata' => $content_metadata,
            'comparable_rates' => PlontisLicensingMarketData::getComparableDeals(
                $content_metadata['content_type'] ?? 'article',
                $detection['company']
            )
        ]);
    }
    
    /**
     * AJAX handler for analyzing content
     */
    public function ajaxAnalyzeContent() {
        check_ajax_referer('plontis_nonce', 'nonce');
        
        $url = sanitize_url($_POST['url'] ?? '');
        
        if (!$url) {
            wp_send_json_error('Invalid URL');
        }
        
        $analysis = $this->content_analyzer->analyzeContent($url);
        
        wp_send_json_success($analysis);
    }
    
    /**
     * Get portfolio value analysis
     */
    public function getPortfolioAnalysis($days = 30) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'plontis_detections';
        
        // Get recent detections
        $detections = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE detected_at > DATE_SUB(NOW(), INTERVAL %d DAY) ORDER BY detected_at DESC",
            $days
        ), ARRAY_A);
        
        if (empty($detections)) {
            return [
                'total_value' => 0,
                'detection_count' => 0,
                'message' => 'No AI bot detections found in the selected period'
            ];
        }
        
        // Calculate portfolio value
        $portfolio_data = $this->value_calculator->calculatePortfolioValue($detections);
        
        // Add content analysis summary
        $content_analyses = [];
        foreach ($detections as $detection) {
            $content_analyses[] = $this->content_analyzer->analyzeContent($detection['request_uri']);
        }
        
        $content_summary = $this->content_analyzer->getAnalysisSummary($content_analyses);
        
        return array_merge($portfolio_data, [
            'content_summary' => $content_summary,
            'period_days' => $days,
            'detection_count' => count($detections),
            'licensing_recommendations' => PlontisLicensingMarketData::getLicensingRecommendations(
                $portfolio_data['total_portfolio_value'],
                array_keys($content_summary['content_types']),
                array_keys($portfolio_data['top_value_companies'] ?? [])
            )
        ]);
    }
    
    /**
     * Generate licensing report
     */
    public function generateLicensingReport($format = 'html') {
        $portfolio = $this->getPortfolioAnalysis(30);
        
        if ($format === 'html') {
            return $this->generateHTMLReport($portfolio);
        } elseif ($format === 'csv') {
            return $this->generateCSVReport($portfolio);
        }
        
        return $portfolio;
    }
    
    /**
     * Generate HTML licensing report
     */
    private function generateHTMLReport($portfolio) {
        ob_start();
        ?>
        <div class="plontis-licensing-report">
            <h2>Plontis Licensing Report</h2>
            <p><strong>Report Period:</strong> Last <?php echo $portfolio['period_days']; ?> days</p>
            <p><strong>Generated:</strong> <?php echo current_time('Y-m-d H:i:s'); ?></p>
            
            <div class="report-summary">
                <h3>Portfolio Summary</h3>
                <ul>
                    <li><strong>Total Portfolio Value:</strong> $<?php echo number_format($portfolio['total_portfolio_value'], 2); ?></li>
                    <li><strong>AI Bot Detections:</strong> <?php echo $portfolio['detection_count']; ?></li>
                    <li><strong>High-Value Content:</strong> <?php echo $portfolio['high_value_content_count']; ?> items</li>
                    <li><strong>Licensing Candidates:</strong> <?php echo $portfolio['licensing_candidates']; ?> items</li>
                    <li><strong>Estimated Annual Revenue:</strong> $<?php echo number_format($portfolio['estimated_annual_revenue'], 2); ?></li>
                </ul>
            </div>
            
            <div class="content-breakdown">
                <h3>Content Analysis</h3>
                <ul>
                    <li><strong>Average Quality Score:</strong> <?php echo $portfolio['content_summary']['avg_quality_score']; ?>/100</li>
                    <li><strong>High-Quality Content:</strong> <?php echo $portfolio['content_summary']['high_value_content']; ?> items</li>
                    <li><strong>Technical Content:</strong> <?php echo $portfolio['content_summary']['technical_content']; ?> items</li>
                    <li><strong>Research Content:</strong> <?php echo $portfolio['content_summary']['research_content']; ?> items</li>
                </ul>
                
                <h4>Content Types</h4>
                <ul>
                    <?php foreach ($portfolio['content_summary']['content_types'] as $type => $count): ?>
                        <li><?php echo ucfirst($type); ?>: <?php echo $count; ?> items</li>
                    <?php endforeach; ?>
                </ul>
            </div>
            
            <div class="licensing-recommendations">
                <h3>Licensing Recommendations</h3>
                <?php if (!empty($portfolio['licensing_recommendations'])): ?>
                    <?php foreach ($portfolio['licensing_recommendations'] as $recommendation): ?>
                        <div class="recommendation">
                            <h4><?php echo esc_html($recommendation['type']); ?></h4>
                            <p><?php echo esc_html($recommendation['description']); ?></p>
                            <?php if (isset($recommendation['estimated_annual'])): ?>
                                <p><strong>Estimated Annual Value:</strong> $<?php echo number_format($recommendation['estimated_annual'], 2); ?></p>
                            <?php endif; ?>
                            <p><strong>Next Steps:</strong> <?php echo esc_html($recommendation['next_steps']); ?></p>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>Continue monitoring AI bot activity to build licensing opportunities.</p>
                <?php endif; ?>
            </div>
            
            <div class="market-context">
                <h3>Market Context</h3>
                <p>Based on current market rates from Getty Images ($130-$575 per image), academic publishing ($1,626 average APC), and news syndication ($35/month professional rates), your content represents significant licensing value in the growing AI training data market.</p>
                
                <p><strong>Recent AI Licensing Deals:</strong></p>
                <ul>
                    <li>Taylor & Francis + Microsoft: $10M academic content</li>
                    <li>Wiley + Undisclosed: $23M academic publishing</li>
                    <li>Associated Press + OpenAI: Multi-year news licensing</li>
                </ul>
            </div>
        </div>
        
        <style>
        .plontis-licensing-report {
            max-width: 800px;
            margin: 20px 0;
            font-family: Arial, sans-serif;
        }
        .report-summary, .content-breakdown, .licensing-recommendations, .market-context {
            margin: 20px 0;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        .recommendation {
            margin: 10px 0;
            padding: 10px;
            background: #f9f9f9;
            border-left: 4px solid #007cba;
        }
        </style>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Generate CSV export of detection data
     */
    private function generateCSVReport($portfolio) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'plontis_detections';
        
        $detections = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE detected_at > DATE_SUB(NOW(), INTERVAL %d DAY) ORDER BY detected_at DESC",
            $portfolio['period_days']
        ), ARRAY_A);
        
        $csv_data = "Date,Company,Bot Type,Page,Risk Level,Estimated Value,Content Type,Quality Score\n";
        
        foreach ($detections as $detection) {
            $csv_data .= sprintf(
                "%s,%s,%s,%s,%s,$%.2f,%s,%d\n",
                $detection['detected_at'],
                $detection['company'] ?? 'Unknown',
                $detection['bot_type'] ?? 'Unknown',
                $detection['request_uri'],
                $detection['risk_level'] ?? 'unknown',
                $detection['estimated_value'] ?? 0,
                $detection['content_type'] ?? 'unknown',
                $detection['content_quality'] ?? 50
            );
        }
        
        return $csv_data;
    }
    
    /**
     * Update database schema to support enhanced value tracking
     */
    public function updateDatabaseSchema() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'plontis_detections';
        
        // Add new columns for enhanced value tracking
        $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN IF NOT EXISTS estimated_value DECIMAL(10,2) DEFAULT 0.00");
        $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN IF NOT EXISTS content_type VARCHAR(50) DEFAULT 'unknown'");
        $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN IF NOT EXISTS content_quality TINYINT DEFAULT 50");
        $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN IF NOT EXISTS word_count INT DEFAULT 0");
        $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN IF NOT EXISTS technical_depth VARCHAR(20) DEFAULT 'basic'");
        $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN IF NOT EXISTS value_breakdown TEXT");
        $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN IF NOT EXISTS licensing_potential VARCHAR(20) DEFAULT 'low'");
        
        // Add indexes for performance
        $wpdb->query("CREATE INDEX IF NOT EXISTS idx_estimated_value ON {$table_name}(estimated_value)");
        $wpdb->query("CREATE INDEX IF NOT EXISTS idx_content_type ON {$table_name}(content_type)");
        $wpdb->query("CREATE INDEX IF NOT EXISTS idx_licensing_potential ON {$table_name}(licensing_potential)");
    }
}
?>