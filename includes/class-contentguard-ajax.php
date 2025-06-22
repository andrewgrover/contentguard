<?php
/**
 * ContentGuard AJAX Class
 * Handles all AJAX requests for the admin interface
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class ContentGuard_AJAX {
    
    private $value_calculator;
    private $content_analyzer;

    public function __construct() {
        $this->value_calculator = new ContentGuardValueCalculator();
        $this->content_analyzer = new ContentGuardContentAnalyzer();
    }

    public function init() {
        // **CRITICAL FIX: Actually register the AJAX actions with WordPress**
        add_action('wp_ajax_contentguard_get_detections', [$this, 'ajax_get_detections']);
        add_action('wp_ajax_contentguard_get_stats', [$this, 'ajax_get_enhanced_stats']);
        add_action('wp_ajax_contentguard_test', [$this, 'ajax_test']);
        add_action('wp_ajax_contentguard_get_valuation_details', [$this, 'ajax_get_valuation_details']);
        add_action('wp_ajax_contentguard_analyze_content', [$this, 'ajax_analyze_content']);
        add_action('wp_ajax_contentguard_get_portfolio_analysis', [$this, 'ajax_get_portfolio_analysis']);
        
        // Add debugging
        add_action('wp_ajax_contentguard_debug', [$this, 'ajax_debug']);
    }

    /**
     * DEBUG: Test AJAX connectivity
     */
    public function ajax_debug() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'contentguard_detections';
        
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
        if (!check_ajax_referer('contentguard_nonce', 'nonce', false)) {
            wp_send_json_error('Security check failed');
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'contentguard_detections';
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        if (!$table_exists) {
            wp_send_json_error('Database table not found');
            return;
        }
        
        // Get detections from last 30 days for portfolio analysis
        $detections = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE detected_at > DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY detected_at DESC"
        ), ARRAY_A);
        
        // Calculate portfolio value using our value calculator
        $portfolio_analysis = $this->value_calculator->calculatePortfolioValue($detections);
        
        // Get basic stats
        $total_bots = count($detections);
        $commercial_bots = count(array_filter($detections, function($d) { return $d['commercial_risk']; }));
        $top_company = $wpdb->get_var("SELECT company FROM $table_name WHERE detected_at > DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY company ORDER BY COUNT(*) DESC LIMIT 1") ?: 'None detected';
        
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
        
        // Company breakdown with values - check if estimated_value column exists
        $company_breakdown = [];
        
        // Check if estimated_value column exists in the table
        $columns = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'estimated_value'");
        $has_estimated_value = !empty($columns);
        
        if ($has_estimated_value) {
            $companies = $wpdb->get_results("SELECT company, COUNT(*) as count, SUM(estimated_value) as total_value FROM $table_name WHERE detected_at > DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY company ORDER BY total_value DESC");
            foreach ($companies as $company) {
                $company_breakdown[] = [
                    'company' => $company->company,
                    'count' => (int)$company->count,
                    'estimated_value' => (float)$company->total_value
                ];
            }
        } else {
            // Fallback: just get company counts without estimated_value
            $companies = $wpdb->get_results("SELECT company, COUNT(*) as count FROM $table_name WHERE detected_at > DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY company ORDER BY count DESC");
            foreach ($companies as $company) {
                $company_breakdown[] = [
                    'company' => $company->company,
                    'count' => (int)$company->count,
                    'estimated_value' => 0
                ];
            }
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
            'average_value_per_detection' => $portfolio_analysis['average_value_per_access']
        ]);
    }

    public function ajax_get_detections() {
        // Better nonce handling
        if (!check_ajax_referer('contentguard_nonce', 'nonce', false)) {
            wp_send_json_error('Security check failed');
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'contentguard_detections';
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        if (!$table_exists) {
            wp_send_json_success([
                'message' => 'Database table not found. Please activate the plugin.',
                'detections' => [],
                'suggestions' => [
                    'Deactivate and reactivate the ContentGuard plugin',
                    'Check database permissions',
                    'Contact support if issue persists'
                ]
            ]);
            return;
        }
        
        $limit = intval($_POST['limit'] ?? 20);
        $offset = intval($_POST['offset'] ?? 0);
        
        // Check if estimated_value column exists
        $columns = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'estimated_value'");
        $has_estimated_value = !empty($columns);
        
        if ($has_estimated_value) {
            $detections = $wpdb->get_results($wpdb->prepare(
                "SELECT *, 
                 COALESCE(estimated_value, 0) as estimated_value,
                 COALESCE(content_type, 'article') as content_type,
                 COALESCE(licensing_potential, 'low') as licensing_potential
                 FROM $table_name 
                 WHERE detected_at > DATE_SUB(NOW(), INTERVAL 30 DAY) 
                 ORDER BY detected_at DESC LIMIT %d OFFSET %d",
                $limit, $offset
            ), ARRAY_A);
        } else {
            // Fallback for tables without enhanced columns
            $detections = $wpdb->get_results($wpdb->prepare(
                "SELECT *,
                 0 as estimated_value,
                 'article' as content_type,
                 'low' as licensing_potential
                 FROM $table_name 
                 WHERE detected_at > DATE_SUB(NOW(), INTERVAL 30 DAY) 
                 ORDER BY detected_at DESC LIMIT %d OFFSET %d",
                $limit, $offset
            ), ARRAY_A);
        }
        
        // If no detections found, provide a helpful message
        if (empty($detections)) {
            wp_send_json_success([
                'message' => 'No AI bot detections found in the last 30 days.',
                'detections' => [],
                'suggestions' => [
                    'Check that ContentGuard detection is enabled in settings',
                    'Visit your site with a bot user agent to test detection: Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)',
                    'Ensure the plugin is properly activated',
                    'Check that your site is publicly accessible to bots'
                ]
            ]);
            return;
        }
        
        wp_send_json_success($detections);
    }

    public function ajax_get_valuation_details() {
        if (!check_ajax_referer('contentguard_nonce', 'nonce', false)) {
            wp_send_json_error('Security check failed');
            return;
        }
        
        $detection_id = intval($_POST['detection_id'] ?? 0);
        
        if (!$detection_id) {
            wp_send_json_error('Invalid detection ID');
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'contentguard_detections';
        
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
            'comparable_rates' => ContentGuardLicensingMarketData::getComparableDeals(
                $content_metadata['content_type'] ?? 'article',
                $detection['company']
            )
        ]);
    }

    public function ajax_analyze_content() {
        if (!check_ajax_referer('contentguard_nonce', 'nonce', false)) {
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
        if (!check_ajax_referer('contentguard_nonce', 'nonce', false)) {
            wp_send_json_error('Security check failed');
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'contentguard_detections';
        
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
        $licensing_recommendations = ContentGuardLicensingMarketData::getLicensingRecommendations(
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
            'message' => 'Enhanced ContentGuard AJAX is working!', 
            'time' => current_time('mysql'), 
            'version' => CONTENTGUARD_VERSION,
            'value_calculator' => class_exists('ContentGuardValueCalculator'),
            'content_analyzer' => class_exists('ContentGuardContentAnalyzer'),
            'market_data' => class_exists('ContentGuardLicensingMarketData')
        ]);
    }
}