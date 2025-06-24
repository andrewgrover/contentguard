<?php
/**
 * Plontis AJAX Class
 * Handles all AJAX requests for the admin interface
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Plontis_AJAX {
    
    private $value_calculator;
    private $content_analyzer;

    public function __construct() {
        $this->value_calculator = new PlontisValueCalculator();
        $this->content_analyzer = new PlontisContentAnalyzer();
    }

    public function init() {
        // **CRITICAL FIX: Actually register the AJAX actions with WordPress**
        add_action('wp_ajax_plontis_get_detections', [$this, 'ajax_get_detections']);
        add_action('wp_ajax_plontis_get_stats', [$this, 'ajax_get_enhanced_stats']);
        add_action('wp_ajax_plontis_test', [$this, 'ajax_test']);
        add_action('wp_ajax_plontis_get_valuation_details', [$this, 'ajax_get_valuation_details']);
        add_action('wp_ajax_plontis_analyze_content', [$this, 'ajax_analyze_content']);
        add_action('wp_ajax_plontis_get_portfolio_analysis', [$this, 'ajax_get_portfolio_analysis']);
        
        // Add debugging
        add_action('wp_ajax_plontis_debug', [$this, 'ajax_debug']);
    }

    /**
     * DEBUG: Test AJAX connectivity
     */
    public function ajax_debug() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'plontis_detections';
        
        $debug_info = [
            'timestamp' => current_time('mysql'),
            'table_exists' => $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name,
            'total_detections' => 0,
            'sample_detection' => null
        ];
        
        if ($debug_info['table_exists']) {
            $debug_info['total_detections'] = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
            $debug_info['sample_detection'] = $wpdb->get_row("SELECT * FROM $table_name LIMIT 1", ARRAY_A);
            $debug_info['recent_detections'] = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE detected_at > DATE_SUB(NOW(), INTERVAL 30 DAY)");
        }
        
        wp_send_json_success($debug_info);
    }

    /**
     * Enhanced AJAX handlers using our value calculation system
     */
    public function ajax_get_enhanced_stats() {
        // Add nonce check but with better error handling
        if (!check_ajax_referer('plontis_nonce', 'nonce', false)) {
            wp_send_json_error('Security check failed');
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'plontis_detections';
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        if (!$table_exists) {
            wp_send_json_error('Database table not found');
            return;
        }
        
        // Get raw detections - DON'T pull old estimated_value from database
        // This matches exactly what ajax_get_detections() does
        $detections = $wpdb->get_results($wpdb->prepare(
            "SELECT id, user_agent, ip_address, request_uri, bot_type, company, risk_level, confidence, commercial_risk, detected_at
             FROM $table_name 
             WHERE detected_at > DATE_SUB(NOW(), INTERVAL 30 DAY) 
             ORDER BY detected_at DESC"
        ), ARRAY_A);
        
        error_log("Plontis Stats: Retrieved " . count($detections) . " raw detections for portfolio calculation");
        
        // Calculate enhanced valuations for each detection - SAME AS DETECTIONS METHOD
        $enhanced_detections = [];
        
        foreach ($detections as $detection) {
            try {
                // Analyze content using current enhanced system
                $content_metadata = $this->content_analyzer->analyzeContent($detection['request_uri']);
                
                // Calculate value using current enhanced calculator
                $detection_data = [
                    'company' => $detection['company'],
                    'bot_type' => $detection['bot_type'],
                    'request_uri' => $detection['request_uri'],
                    'risk_level' => $detection['risk_level'],
                    'confidence' => intval($detection['confidence'] ?? 50),
                    'commercial_risk' => $detection['commercial_risk']
                ];
                
                $valuation = $this->value_calculator->calculateContentValue($detection_data, $content_metadata);
                
                // Add enhanced data to detection
                $detection['estimated_value'] = $valuation['estimated_value'];
                $detection['content_type'] = $content_metadata['content_type'] ?? 'article';
                $detection['content_quality'] = $content_metadata['quality_score'] ?? 50;
                $detection['licensing_potential'] = $valuation['licensing_potential']['potential'];
                
                $enhanced_detections[] = $detection;
                
                error_log("Plontis Stats: Calculated fresh value " . $valuation['estimated_value'] . " for detection " . $detection['id']);
                
            } catch (Exception $e) {
                error_log("Plontis Stats: Error calculating value for detection " . $detection['id'] . ": " . $e->getMessage());
                
                // Use fallback but make it obvious
                $detection['estimated_value'] = 0.00;
                $detection['content_type'] = 'article';
                $detection['content_quality'] = 50;
                $detection['licensing_potential'] = 'low';
                
                $enhanced_detections[] = $detection;
            }
        }
        
        // NOW calculate portfolio analysis using the enhanced detections
        $portfolio_analysis = $this->value_calculator->calculatePortfolioValue($enhanced_detections);
        
        error_log("Plontis Stats: Portfolio analysis total value: " . $portfolio_analysis['total_portfolio_value']);
        
        // Get basic stats from enhanced detections
        $total_bots = count($enhanced_detections);
        $commercial_bots = count(array_filter($enhanced_detections, function($d) { return $d['commercial_risk']; }));
        
        // Get top company from enhanced detections
        $company_counts = [];
        foreach ($enhanced_detections as $detection) {
            $company = $detection['company'] ?? 'Unknown';
            $company_counts[$company] = ($company_counts[$company] ?? 0) + 1;
        }
        arsort($company_counts);
        $top_company = !empty($company_counts) ? array_key_first($company_counts) : 'None detected';
        
        // Daily activity (last 7 days) - this can stay the same
        $daily_activity = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE DATE(detected_at) = %s",
                $date
            )) ?: 0;
            $daily_activity[] = ['date' => $date, 'count' => (int)$count];
        }
        
        // Company breakdown with FRESH VALUES
        $company_breakdown = [];
        $company_values = [];
        
        foreach ($enhanced_detections as $detection) {
            $company = $detection['company'];
            if (!isset($company_values[$company])) {
                $company_values[$company] = ['count' => 0, 'total_value' => 0];
            }
            $company_values[$company]['count']++;
            $company_values[$company]['total_value'] += $detection['estimated_value'];
        }
        
        // Sort by total value (descending)
        uasort($company_values, function($a, $b) {
            return $b['total_value'] <=> $a['total_value'];
        });
        
        foreach ($company_values as $company => $data) {
            $company_breakdown[] = [
                'company' => $company,
                'count' => $data['count'],
                'estimated_value' => $data['total_value']
            ];
        }
        
        wp_send_json_success([
            'total_bots' => $total_bots,
            'commercial_bots' => $commercial_bots,
            'top_company' => $top_company,
            'content_value' => number_format($portfolio_analysis['total_portfolio_value'], 2),
            'portfolio_analysis' => $portfolio_analysis,
            'daily_activity' => $daily_activity,
            'company_breakdown' => $company_breakdown,
            'licensing_opportunities' => count($portfolio_analysis['recommendations'] ?? []),
            'high_value_detections' => $portfolio_analysis['high_value_content_count'],
            'average_value_per_detection' => $portfolio_analysis['average_value_per_access'],
            '_debug' => [
                'method' => 'fresh_calculation',
                'detections_processed' => count($enhanced_detections),
                'calculation_source' => 'enhanced_realtime'
            ]
        ]);
    }

    public function ajax_get_detections() {
        // Better nonce handling
        if (!check_ajax_referer('plontis_nonce', 'nonce', false)) {
            wp_send_json_error('Security check failed');
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'plontis_detections';
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        if (!$table_exists) {
            wp_send_json_success([
                'message' => 'Database table not found. Please activate the plugin.',
                'detections' => [],
                'suggestions' => [
                    'Deactivate and reactivate the Plontis plugin',
                    'Check database permissions',
                    'Contact support if issue persists'
                ]
            ]);
            return;
        }
        
        $limit = intval($_POST['limit'] ?? 20);
        $offset = intval($_POST['offset'] ?? 0);
        
        // Get raw detections - DON'T pull old estimated_value from database
        $detections = $wpdb->get_results($wpdb->prepare(
            "SELECT id, user_agent, ip_address, request_uri, bot_type, company, risk_level, confidence, commercial_risk, detected_at
             FROM $table_name 
             WHERE detected_at > DATE_SUB(NOW(), INTERVAL 30 DAY) 
             ORDER BY detected_at DESC LIMIT %d OFFSET %d",
            $limit, $offset
        ), ARRAY_A);
        
        // DEBUG: Log what we got from database
        error_log("Plontis AJAX: Retrieved " . count($detections) . " detections from database");
        
        // If no detections found, provide a helpful message
        if (empty($detections)) {
            wp_send_json_success([
                'message' => 'No AI bot detections found in the last 30 days.',
                'detections' => [],
                'suggestions' => [
                    'Check that Plontis detection is enabled in settings',
                    'Visit your site with a bot user agent to test detection: Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)',
                    'Ensure the plugin is properly activated',
                    'Check that your site is publicly accessible to bots'
                ]
            ]);
            return;
        }
        
        // Calculate enhanced valuations for each detection using current system
        $enhanced_detections = [];
        
        foreach ($detections as $detection) {
            try {
                error_log("Plontis AJAX: Processing detection ID " . $detection['id'] . " for company " . $detection['company']);
                
                // Check if we have the required classes
                if (!$this->content_analyzer || !$this->value_calculator) {
                    error_log("Plontis AJAX: Missing calculator classes!");
                    throw new Exception("Value calculator not available");
                }
                
                // Analyze content using current enhanced system
                $content_metadata = $this->content_analyzer->analyzeContent($detection['request_uri']);
                error_log("Plontis AJAX: Content analysis completed for " . $detection['request_uri']);
                
                // Calculate value using current enhanced calculator
                $detection_data = [
                    'company' => $detection['company'],
                    'bot_type' => $detection['bot_type'],
                    'request_uri' => $detection['request_uri'],
                    'risk_level' => $detection['risk_level'],
                    'confidence' => intval($detection['confidence'] ?? 50),
                    'commercial_risk' => $detection['commercial_risk']
                ];
                
                $valuation = $this->value_calculator->calculateContentValue($detection_data, $content_metadata);
                error_log("Plontis AJAX: Calculated value " . $valuation['estimated_value'] . " for detection " . $detection['id']);
                
                // Add enhanced data to detection (OVERRIDE any old database values)
                $detection['estimated_value'] = $valuation['estimated_value'];
                $detection['content_type'] = $content_metadata['content_type'] ?? 'article';
                $detection['content_quality'] = $content_metadata['quality_score'] ?? 50;
                $detection['licensing_potential'] = $valuation['licensing_potential']['potential'];
                
                // Add debug info that will be visible in browser console
                $detection['_debug'] = [
                    'calculated_fresh' => true,
                    'original_value' => 'CALCULATED_FRESH',
                    'new_value' => $valuation['estimated_value'],
                    'company' => $detection['company'],
                    'content_type' => $content_metadata['content_type'] ?? 'article',
                    'calculation_method' => 'enhanced_2024'
                ];
                
                $enhanced_detections[] = $detection;
                
            } catch (Exception $e) {
                error_log("Plontis AJAX: Error calculating value for detection " . $detection['id'] . ": " . $e->getMessage());
                
                // If valuation fails, use fallback values but make them obvious
                $detection['estimated_value'] = 99.99; // Obvious fallback value
                $detection['content_type'] = 'article';
                $detection['content_quality'] = 50;
                $detection['licensing_potential'] = 'medium';
                $detection['_debug'] = [
                    'calculated_fresh' => false,
                    'error' => $e->getMessage(),
                    'fallback_used' => true
                ];
                
                $enhanced_detections[] = $detection;
            }
        }
        
        error_log("Plontis AJAX: Sending " . count($enhanced_detections) . " enhanced detections to frontend");
        
        // Send enhanced detections
        wp_send_json_success($enhanced_detections);
    }

    public function ajax_get_valuation_details() {
        if (!check_ajax_referer('plontis_nonce', 'nonce', false)) {
            wp_send_json_error('Security check failed');
            return;
        }
        
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
        
        // Analyze content and recalculate value for fresh data
        $content_metadata = $this->content_analyzer->analyzeContent($detection['request_uri']);
        $valuation = $this->value_calculator->calculateContentValue($detection, $content_metadata);
        
        wp_send_json_success([
            'detection' => $detection,
            'valuation' => $valuation,
            'content_metadata' => $content_metadata,
            'comparable_rates' => PlontisLicensingMarketData::getComparableDeals(
                $content_metadata['content_type'] ?? 'article',
                $detection['company']
            )
        ]);
    }

    public function ajax_analyze_content() {
        if (!check_ajax_referer('plontis_nonce', 'nonce', false)) {
            wp_send_json_error('Security check failed');
            return;
        }
        
        $url = sanitize_url($_POST['url'] ?? '');
        
        if (!$url) {
            wp_send_json_error('Invalid URL');
        }
        
        $analysis = $this->content_analyzer->analyzeContent($url);
        
        wp_send_json_success($analysis);
    }

    public function ajax_get_portfolio_analysis() {
        if (!check_ajax_referer('plontis_nonce', 'nonce', false)) {
            wp_send_json_error('Security check failed');
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'plontis_detections';
        
        $days = intval($_POST['days'] ?? 30);
        
        // Get detections for the specified period
        $detections = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE detected_at > DATE_SUB(NOW(), INTERVAL %d DAY) ORDER BY detected_at DESC",
            $days
        ), ARRAY_A);
        
        // Calculate portfolio analysis using our value calculator
        $portfolio_analysis = $this->value_calculator->calculatePortfolioValue($detections);
        
        // Add content analysis summary
        $content_analyses = [];
        foreach ($detections as $detection) {
            if (!empty($detection['value_breakdown'])) {
                $breakdown = json_decode($detection['value_breakdown'], true);
                $content_analyses[] = [
                    'content_type' => $detection['content_type'],
                    'quality_score' => $detection['content_quality'],
                    'technical_depth' => $detection['technical_depth'],
                    'estimated_value' => $detection['estimated_value']
                ];
            }
        }
        
        // Get licensing recommendations using market data
        $licensing_recommendations = PlontisLicensingMarketData::getLicensingRecommendations(
            $portfolio_analysis['total_portfolio_value'],
            array_unique(array_column($content_analyses, 'content_type')),
            array_keys($portfolio_analysis['top_value_companies'] ?? [])
        );
        
        $portfolio_analysis['licensing_recommendations'] = $licensing_recommendations;
        $portfolio_analysis['content_summary'] = $this->content_analyzer->getAnalysisSummary($content_analyses);
        $portfolio_analysis['period_days'] = $days;
        
        wp_send_json_success($portfolio_analysis);
    }

    public function ajax_test() {
        wp_send_json_success([
            'message' => 'Enhanced Plontis AJAX is working!', 
            'time' => current_time('mysql'), 
            'version' => PLONTIS_VERSION,
            'value_calculator' => class_exists('PlontisValueCalculator'),
            'content_analyzer' => class_exists('PlontisContentAnalyzer'),
            'market_data' => class_exists('PlontisLicensingMarketData')
        ]);
    }
}