<?php
/**
 * Fixed Plontis Central API Connector
 * Fixes the ArgumentCountError in make_api_request method
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Plontis_Central_API {
    
    private $api_endpoint;
    private $api_key;
    private $site_hash;
    
    public function __construct() {
        $this->api_endpoint = 'https://0ak4j2uw02.execute-api.us-east-1.amazonaws.com/prod';
        
        // Load API settings
        $settings = get_option('plontis_settings', []);
        $this->api_key = $settings['central_api_key'] ?? '';
        $this->site_hash = $this->generate_site_hash();
        
        // Register the site if API key is configured
        if (!empty($this->api_key)) {
            add_action('init', [$this, 'register_site'], 5);
        }
    }
    
    /**
     * Generate unique hash for this site
     */
    public function generate_site_hash() {
        $site_url = get_site_url();
        $domain = parse_url($site_url, PHP_URL_HOST);
        return hash('sha256', $domain . '_' . get_option('db_version', 'wp_'));
    }
    
    /**
     * Register this WordPress site with the central API
     */
    public function register_site() {
        // Check if already registered recently
        $last_registration = get_transient('plontis_last_registration');
        if ($last_registration) {
            return;
        }
        
        global $wp_version;
        
        // Get plugin version safely
        $plugin_version = '1.0.0'; // Default version
        
        // Try to get version from plugin data
        if (defined('PLONTIS_VERSION')) {
            $plugin_version = PLONTIS_VERSION;
        } else {
            // Try to read from main plugin file
            $plugin_file = WP_PLUGIN_DIR . '/plontis/plontis.php';
            if (file_exists($plugin_file)) {
                $plugin_data = get_file_data($plugin_file, ['Version' => 'Version']);
                if (!empty($plugin_data['Version'])) {
                    $plugin_version = $plugin_data['Version'];
                }
            }
        }
        
        $registration_data = [
            'api_key' => $this->api_key,
            'site_hash' => $this->site_hash,
            'site_url_hash' => hash('sha256', get_site_url()),
            'wordpress_version' => $wp_version,
            'plugin_version' => $plugin_version,
            'registered_at' => current_time('c')
        ];
        
        $response = $this->make_api_request('/v1/register', $registration_data, 'POST');
        
        if ($response && isset($response['success']) && $response['success']) {
            // Cache successful registration for 1 day
            set_transient('plontis_last_registration', true, DAY_IN_SECONDS);
            error_log('Plontis: Successfully registered with central API');
        } else {
            error_log('Plontis: Failed to register with central API');
        }
    }
    
    /**
     * Submit detection to central API
     */
    public function submit_detection($detection_data, $content_metadata, $valuation) {
        // Skip if no API key configured
        if (empty($this->api_key)) {
            return false;
        }
        
        // Prepare data for central API
        $api_data = [
            'site_hash' => $this->site_hash,
            'site_category' => $this->get_site_category(),
            'site_region' => $this->get_site_region(),
            'company' => $detection_data['company'] ?? 'Unknown',
            'bot_type' => $detection_data['bot_type'] ?? 'Unknown',
            'content_type' => $content_metadata['content_type'] ?? 'article',
            'content_quality' => $content_metadata['quality_score'] ?? 50,
            'estimated_value' => $valuation['estimated_value'] ?? 0,
            'risk_level' => $detection_data['risk_level'] ?? 'medium',
            'commercial_risk' => $detection_data['commercial_risk'] ?? false,
            'detected_at' => current_time('c'),
            
            // Additional context for central analysis
            'request_uri' => $detection_data['request_uri'] ?? '',
            'confidence' => $detection_data['confidence'] ?? 0,
            'word_count' => $content_metadata['word_count'] ?? 0,
            'technical_depth' => $content_metadata['technical_depth'] ?? 'basic',
            'licensing_potential' => isset($valuation['licensing_potential']['potential']) ? $valuation['licensing_potential']['potential'] : 'low'
        ];
        
        // Submit to central API asynchronously
        $this->submit_async($api_data);
        
        return true;
    }
    
    /**
     * Make asynchronous API submission to avoid blocking page loads
     */
    private function submit_async($data) {
        // Use WordPress HTTP API with timeout and async handling
        wp_remote_post($this->api_endpoint . '/v1/detections', [
            'timeout' => 5,
            'blocking' => false, // Don't wait for response
            'headers' => [
                'Content-Type' => 'application/json',
                'X-API-Key' => $this->api_key
            ],
            'body' => json_encode($data)
        ]);
    }
    
    /**
     * Make synchronous API request with proper error handling
     * FIXED: Made all parameters optional with defaults
     */
    private function make_api_request($endpoint, $data = null, $method = 'GET') {
        $url = $this->api_endpoint . $endpoint;
        
        error_log("Plontis: Making API request to: $url");
        if ($data) {
            error_log("Plontis: With data: " . print_r($data, true));
        }
        
        $args = [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-API-Key' => $this->api_key
            ]
        ];
        
        if ($method === 'POST' && $data) {
            $args['body'] = json_encode($data);
            $response = wp_remote_post($url, $args);
        } else {
            $response = wp_remote_get($url, $args);
        }
        
        if (is_wp_error($response)) {
            error_log('Plontis API Error: ' . $response->get_error_message());
            return false;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        error_log("Plontis: API response status: $status_code");
        error_log("Plontis: API response body: $body");
        
        if ($status_code >= 200 && $status_code < 300) {
            return json_decode($body, true);
        } else {
            error_log("Plontis API Error: HTTP {$status_code} - {$body}");
            return false;
        }
    }
    
    /**
     * Get site category based on content analysis
     */
    private function get_site_category() {
        // Analyze site content to categorize
        $categories = ['blog', 'news', 'ecommerce', 'portfolio', 'business', 'education', 'tech'];
        
        // Simple heuristic based on active plugins and content
        if (function_exists('is_plugin_active') && is_plugin_active('woocommerce/woocommerce.php')) {
            return 'ecommerce';
        }
        
        // Check for common education/tech indicators
        $site_description = get_bloginfo('description');
        $site_title = get_bloginfo('name');
        $content = strtolower($site_description . ' ' . $site_title);
        
        if (strpos($content, 'tech') !== false || strpos($content, 'developer') !== false) {
            return 'tech';
        }
        if (strpos($content, 'education') !== false || strpos($content, 'learn') !== false) {
            return 'education';
        }
        if (strpos($content, 'news') !== false) {
            return 'news';
        }
        
        return 'blog'; // Default category
    }
    
    /**
     * Get site region based on settings or site language
     */
    private function get_site_region() {
        // Try to determine region from locale
        $locale = get_locale();
        
        $region_map = [
            'en_US' => 'US',
            'en_GB' => 'UK', 
            'en_CA' => 'CA',
            'en_AU' => 'AU',
            'de_DE' => 'DE',
            'fr_FR' => 'FR',
            'es_ES' => 'ES',
            'it_IT' => 'IT',
            'ja' => 'JP',
            'zh_CN' => 'CN',
            'ko_KR' => 'KR'
        ];
        
        foreach ($region_map as $wp_locale => $region) {
            if (strpos($locale, $wp_locale) === 0) {
                return $region;
            }
        }
        
        return 'US'; // Default region
    }
    
    /**
     * Get market intelligence from central API
     * FIXED: Added default empty data parameter
     */
    public function get_market_intelligence() {
        if (empty($this->api_key)) {
            return false;
        }
        $cache_buster = '?v=' . time();
        return $this->make_api_request('/v1/market-intelligence' . $cache_buster);
        return $this->make_api_request('/v1/market-intelligence');
    }
    
    /**
     * Get site-specific insights from central API
     * FIXED: Added default empty data parameter
     */
    public function get_site_insights() {
        if (empty($this->api_key)) {
            return false;
        }
        
        return $this->make_api_request('/v1/site-insights?site_hash=' . $this->site_hash);
    }
    
    /**
     * Test API connection
     * FIXED: Added default empty data parameter
     */
    public function test_connection() {
        if (empty($this->api_key)) {
            return ['success' => false, 'message' => 'No API key configured'];
        }
        
        $response = $this->make_api_request('/');
        
        if ($response) {
            return ['success' => true, 'message' => 'Connected successfully', 'data' => $response];
        } else {
            return ['success' => false, 'message' => 'Failed to connect to central API'];
        }
    }
}
?>