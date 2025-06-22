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
        // Enhanced AJAX handlers using our value calculation system
        add_action('wp_ajax_contentguard_get_detections', [$this, 'ajax_get_detections']);
        add_action('wp_ajax_contentguard_get_stats', [$this, 'ajax_get_enhanced_stats']);
        add_action('wp_ajax_contentguard_test', [$this, 'ajax_test']);
        add_action('wp_ajax_contentguard_get_valuation_details', [$this, 'ajax_get_valuation_details']);
        add_action('wp_ajax_contentguard_analyze_content', [$this, 'ajax_analyze_content']);
        add_action('wp_ajax_contentguard_get_portfolio_analysis', [$this, 'ajax_get_portfolio_analysis']);
    }

    /**
     * Enhanced AJAX handlers using our value calculation system
     */
    public function ajax_get_enhanced_stats() {
        check_ajax_referer('contentguard_nonce', 'nonce');
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'contentguard_detections';
        
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
        check_ajax_referer('contentguard_nonce', 'nonce');
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'contentguard_detections';
        
        $limit = intval($_POST['limit'] ?? 20);
        $offset = intval($_POST['offset'] ?? 0);
        
        $detections = $wpdb->get_results($wpdb->prepare(
            "SELECT *, 
             COALESCE(estimated_value, 0) as estimated_value,
             COALESCE(content_type, 'article') as content_type,
             COALESCE(licensing_potential, 'low') as licensing_potential
             FROM $table_name 
             WHERE detected_at > DATE_SUB(NOW(), INTERVAL 30 DAY) 
             ORDER BY detected_at DESC LIMIT %d OFFSET %d",
            $limit, $offset
        ));
        
        // If no detections found, provide a helpful message
        if (empty($detections)) {
            wp_send_json_success([
                'message' => 'No AI bot detections found in the last 30 days.',
                'detections' => [],
                'suggestions' => [
                    'Check that ContentGuard detection is enabled in settings',
                    'Visit your site with a bot user agent to test detection',
                    'Ensure the plugin is properly activated'
                ]
            ]);
            return;
        }
        
        wp_send_json_success($detections);
    }

    public function ajax_get_valuation_details() {
        check_ajax_referer('contentguard_nonce', 'nonce');
        
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
        check_ajax_referer('contentguard_nonce', 'nonce');
        
        $url = sanitize_url($_POST['url'] ?? '');
        
        if (!$url) {
            wp_send_json_error('Invalid URL');
        }
        
        $analysis = $this->content_analyzer->analyzeContent($url);
        
        wp_send_json_success($analysis);
    }

    public function ajax_get_portfolio_analysis() {
        check_ajax_referer('contentguard_nonce', 'nonce');
        
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
?>