<?php
/**
 * Simplified Admin API Integration
 * Modify your existing class-plontis-admin-api.php to work with your current structure
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Plontis_Admin_API {
    
    private $central_api;
    
    public function __construct() {
        $this->central_api = new Plontis_Central_API();
    }
    
    public function init() {
        // Add API tab to your existing admin pages
        add_action('admin_init', [$this, 'handle_api_settings']);
    }
    
    /**
     * Handle API settings form submission
     */
    public function handle_api_settings() {
        if (isset($_POST['plontis_api_settings']) && wp_verify_nonce($_POST['_wpnonce'], 'plontis_api_settings')) {
            $settings = get_option('plontis_settings', []);
            
            $settings['central_api_key'] = sanitize_text_field($_POST['central_api_key'] ?? '');
            $settings['enable_central_reporting'] = isset($_POST['enable_central_reporting']);
            
            update_option('plontis_settings', $settings);
            
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success"><p>API settings saved successfully!</p></div>';
            });
        }
    }
    
    /**
     * Render API settings form (call this from your existing admin page)
     */
    public function render_api_settings_form() {
        $settings = get_option('plontis_settings', []);
        $api_key = $settings['central_api_key'] ?? '';
        $enabled = $settings['enable_central_reporting'] ?? true;
        
        ?>
        <div class="plontis-api-settings">
            <h2>Central API Settings</h2>
            
            <div class="notice notice-info">
                <p><strong>Benefits of Central API:</strong><br>
                • Access market intelligence across all Plontis sites<br>
                • Compare your AI bot activity to industry averages<br>
                • Get licensing opportunities and revenue estimates<br>
                • Contribute to the global AI transparency movement</p>
            </div>
            
            <form method="post" action="">
                <?php wp_nonce_field('plontis_api_settings'); ?>
                <input type="hidden" name="plontis_api_settings" value="1">
                
                <table class="form-table">
                    <tr>
                        <th scope="row">API Key</th>
                        <td>
                            <input type="password" id="central_api_key" name="central_api_key" 
                                   value="<?php echo esc_attr($api_key); ?>" class="regular-text" />
                            <button type="button" class="button" onclick="toggleApiKeyVisibility()">Show/Hide</button>
                            <p class="description">Get your API key from <a href="https://plontis.ai/api" target="_blank">plontis.ai/api</a></p>
                            
                            <?php if (!empty($api_key)): ?>
                                <br><button type="button" class="button button-secondary" onclick="testApiConnection()">Test Connection</button>
                                <div id="api-test-result" style="margin-top: 10px;"></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Central Reporting</th>
                        <td>
                            <label>
                                <input type="checkbox" name="enable_central_reporting" value="1" <?php checked($enabled, true); ?> />
                                Submit detection data to central API for market intelligence
                            </label>
                            <p class="description">When enabled, detection data is anonymously shared to improve market intelligence. No personal data is transmitted.</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button('Save API Settings'); ?>
            </form>
            
            <?php if (!empty($api_key)): ?>
                <?php $this->render_api_status(); ?>
                <?php $this->render_market_intelligence_preview(); ?>
            <?php endif; ?>
        </div>
        
        <script>
        function toggleApiKeyVisibility() {
            const field = document.getElementById('central_api_key');
            field.type = field.type === 'password' ? 'text' : 'password';
        }
        
        function testApiConnection() {
            const resultDiv = document.getElementById('api-test-result');
            const button = event.target;
            
            button.disabled = true;
            button.textContent = 'Testing...';
            resultDiv.innerHTML = '<em>Testing connection...</em>';
            
            const data = {
                action: 'plontis_test_api_connection',
                nonce: '<?php echo wp_create_nonce('plontis_test_api'); ?>'
            };
            
            fetch(ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams(data)
            })
            .then(response => response.json())
            .then(result => {
                button.disabled = false;
                button.textContent = 'Test Connection';
                
                if (result.success) {
                    resultDiv.innerHTML = '<div class="notice notice-success inline"><p>✅ ' + result.data.message + '</p></div>';
                } else {
                    resultDiv.innerHTML = '<div class="notice notice-error inline"><p>❌ ' + result.data.message + '</p></div>';
                }
            })
            .catch(error => {
                button.disabled = false;
                button.textContent = 'Test Connection';
                resultDiv.innerHTML = '<div class="notice notice-error inline"><p>❌ Connection test failed</p></div>';
            });
        }
        </script>
        
        <style>
        .notice.inline {
            margin: 10px 0;
            padding: 8px 12px;
        }
        .plontis-api-settings .form-table th {
            width: 200px;
        }
        </style>
        <?php
    }
    
    /**
     * Render API connection status
     */
    private function render_api_status() {
        $settings = get_option('plontis_settings');
        $api_key = $settings['central_api_key'] ?? '';
        
        if (empty($api_key)) {
            return;
        }
        
        $last_registration = get_transient('plontis_last_registration');
        $registration_status = $last_registration ? 'Connected' : 'Not Registered';
        
        ?>
        <div style="background: #f9f9f9; padding: 15px; border-radius: 5px; margin-top: 20px;">
            <h3>API Connection Status</h3>
            <table class="form-table">
                <tr>
                    <th scope="row">Registration Status</th>
                    <td>
                        <span style="color: <?php echo $last_registration ? '#46b450' : '#dc3232'; ?>; font-weight: bold;">
                            ● <?php echo $registration_status; ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Site Hash</th>
                    <td><code><?php echo esc_html($this->get_site_hash()); ?></code></td>
                </tr>
                <tr>
                    <th scope="row">API Endpoint</th>
                    <td><code>https://0ak4j2uw02.execute-api.us-east-1.amazonaws.com/prod</code></td>
                </tr>
                <tr>
                    <th scope="row">Central Reporting</th>
                    <td><?php echo ($settings['enable_central_reporting'] ?? true) ? 'Enabled' : 'Disabled'; ?></td>
                </tr>
            </table>
        </div>
        <?php
    }
    
    /**
     * Render market intelligence preview
     */
    private function render_market_intelligence_preview() {
        $market_data = $this->central_api->get_market_intelligence();
        
        if (!$market_data) {
            echo '<div class="notice notice-error"><p>Unable to fetch market intelligence. Check your API key and connection.</p></div>';
            return;
        }
        
        ?>
        <div style="background: #f9f9f9; padding: 15px; border-radius: 5px; margin-top: 20px;">
            <h3>Market Intelligence Preview</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                <div style="background: white; padding: 15px; border-radius: 5px; text-align: center;">
                    <h4 style="margin: 0 0 10px 0; color: #666; font-size: 14px;">Total Sites</h4>
                    <p style="font-size: 24px; font-weight: bold; color: #0073aa; margin: 0;">
                        <?php echo number_format($market_data['total_sites'] ?? 0); ?>
                    </p>
                </div>
                <div style="background: white; padding: 15px; border-radius: 5px; text-align: center;">
                    <h4 style="margin: 0 0 10px 0; color: #666; font-size: 14px;">Total Detections</h4>
                    <p style="font-size: 24px; font-weight: bold; color: #0073aa; margin: 0;">
                        <?php echo number_format($market_data['total_detections'] ?? 0); ?>
                    </p>
                </div>
                <div style="background: white; padding: 15px; border-radius: 5px; text-align: center;">
                    <h4 style="margin: 0 0 10px 0; color: #666; font-size: 14px;">Avg. Value per Detection</h4>
                    <p style="font-size: 24px; font-weight: bold; color: #0073aa; margin: 0;">
                        $<?php echo number_format($market_data['average_value_per_detection'] ?? 0, 2); ?>
                    </p>
                </div>
                <div style="background: white; padding: 15px; border-radius: 5px; text-align: center;">
                    <h4 style="margin: 0 0 10px 0; color: #666; font-size: 14px;">Most Active Company</h4>
                    <p style="font-size: 24px; font-weight: bold; color: #0073aa; margin: 0;">
                        <?php echo esc_html($market_data['most_active_company'] ?? 'N/A'); ?>
                    </p>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Helper method to generate site hash
     */
    private function get_site_hash() {
        $site_url = get_site_url();
        $domain = parse_url($site_url, PHP_URL_HOST);
        return hash('sha256', $domain . '_' . get_option('db_version', 'wp_'));
    }
}

/**
 * AJAX handler for API connection testing
 */
add_action('wp_ajax_plontis_test_api_connection', 'plontis_handle_api_test');

function plontis_handle_api_test() {
    if (!wp_verify_nonce($_POST['nonce'], 'plontis_test_api')) {
        wp_die('Security check failed');
    }
    
    $central_api = new Plontis_Central_API();
    $result = $central_api->test_connection();
    
    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result);
    }
}

/**
 * Hook for central API submission
 */
add_action('plontis_detection_logged', 'plontis_submit_to_central_api', 10, 3);

function plontis_submit_to_central_api($detection_data, $content_metadata, $valuation) {
    $settings = get_option('plontis_settings');
    
    if (empty($settings['enable_central_reporting']) || empty($settings['central_api_key'])) {
        return;
    }
    
    $central_api = new Plontis_Central_API();
    $central_api->submit_detection($detection_data, $content_metadata, $valuation);
}
?>