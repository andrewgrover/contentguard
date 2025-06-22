<?php
/**
 * ContentGuard API Class
 * Handles REST API endpoints for external integrations
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class ContentGuard_API {
    
    private $value_calculator;
    private $content_analyzer;

    public function __construct() {
        $this->value_calculator = new ContentGuardValueCalculator();
        $this->content_analyzer = new ContentGuardContentAnalyzer();
    }

    public function init() {
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    /**
     * REST API endpoints for external integrations
     */
    public function register_rest_routes() {
        register_rest_route('contentguard/v2', '/valuation/(?P<detection_id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_get_valuation'],
            'permission_callback' => [$this, 'check_permissions']
        ]);
        
        register_rest_route('contentguard/v2', '/portfolio', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_get_portfolio'],
            'permission_callback' => [$this, 'check_permissions']
        ]);
        
        register_rest_route('contentguard/v2', '/analyze', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_analyze_content'],
            'permission_callback' => [$this, 'check_permissions']
        ]);

        register_rest_route('contentguard/v2', '/stats', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_get_stats'],
            'permission_callback' => [$this, 'check_permissions']
        ]);
    }

    public function check_permissions() {
        return current_user_can('manage_options');
    }

    public function rest_get_valuation($request) {
        $detection_id = $request['detection_id'];
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'contentguard_detections';
        $detection = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $detection_id
        ), ARRAY_A);
        
        if (!$detection) {
            return new WP_Error('not_found', 'Detection not found', ['status' => 404]);
        }
        
        $content_metadata = $this->content_analyzer->analyzeContent($detection['request_uri']);
        $valuation = $this->value_calculator->calculateContentValue($detection, $content_metadata);
        
        return rest_ensure_response([
            'detection' => $detection,
            'valuation' => $valuation,
            'content_metadata' => $content_metadata,
            'api_version' => '2.0'
        ]);
    }

    public function rest_get_portfolio($request) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'contentguard_detections';
        
        $days = $request->get_param('days') ?: 30;
        $detections = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE detected_at > DATE_SUB(NOW(), INTERVAL %d DAY) ORDER BY detected_at DESC",
            $days
        ), ARRAY_A);
        
        $portfolio_analysis = $this->value_calculator->calculatePortfolioValue($detections);
        
        return rest_ensure_response([
            'portfolio_analysis' => $portfolio_analysis,
            'detection_count' => count($detections),
            'period_days' => $days,
            'api_version' => '2.0'
        ]);
    }

    public function rest_analyze_content($request) {
        $url = $request->get_param('url');
        
        if (empty($url)) {
            return new WP_Error('invalid_url', 'URL parameter is required', ['status' => 400]);
        }
        
        $analysis = $this->content_analyzer->analyzeContent($url);
        
        return rest_ensure_response([
            'analysis' => $analysis,
            'url' => $url,
            'api_version' => '2.0'
        ]);
    }

    public function rest_get_stats($request) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'contentguard_detections';
        
        $days = $request->get_param('days') ?: 30;
        
        // Get basic stats
        $total_detections = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE detected_at > DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
        
        $commercial_detections = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE commercial_risk = 1 AND detected_at > DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
        
        $total_value = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(estimated_value) FROM $table_name WHERE detected_at > DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        )) ?: 0;
        
        $high_value_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE estimated_value >= 50.00 AND detected_at > DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
        
        // Top companies
        $top_companies = $wpdb->get_results($wpdb->prepare(
            "SELECT company, COUNT(*) as count, SUM(estimated_value) as total_value 
             FROM $table_name 
             WHERE detected_at > DATE_SUB(NOW(), INTERVAL %d DAY) 
             GROUP BY company 
             ORDER BY total_value DESC 
             LIMIT 5",
            $days
        ), ARRAY_A);
        
        return rest_ensure_response([
            'stats' => [
                'total_detections' => (int)$total_detections,
                'commercial_detections' => (int)$commercial_detections,
                'total_portfolio_value' => (float)$total_value,
                'high_value_detections' => (int)$high_value_count,
                'average_value_per_detection' => $total_detections > 0 ? round($total_value / $total_detections, 2) : 0
            ],
            'top_companies' => $top_companies,
            'period_days' => $days,
            'api_version' => '2.0'
        ]);
    }
}
?>