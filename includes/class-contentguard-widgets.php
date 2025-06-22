<?php
/**
 * ContentGuard Widgets Class
 * Handles shortcodes and widgets for frontend display
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class ContentGuard_Widgets {
    
    private $value_calculator;

    public function __construct() {
        $this->value_calculator = new ContentGuardValueCalculator();
    }

    public function init() {
        // Register shortcodes
        add_shortcode('contentguard_stats', [$this, 'stats_shortcode']);
        
        // Register widgets
        add_action('widgets_init', [$this, 'register_widgets']);
    }

    /**
     * Shortcode for displaying ContentGuard stats
     */
    public function stats_shortcode($atts) {
        $atts = shortcode_atts([
            'days' => 30,
            'show' => 'summary' // summary, value, detections, companies
        ], $atts);
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'contentguard_detections';
        
        $detections = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE detected_at > DATE_SUB(NOW(), INTERVAL %d DAY)",
            $atts['days']
        ), ARRAY_A);
        
        if (empty($detections)) {
            return '<p>No AI bot detections found in the last ' . $atts['days'] . ' days.</p>';
        }
        
        $portfolio_analysis = $this->value_calculator->calculatePortfolioValue($detections);
        
        ob_start();
        
        switch ($atts['show']) {
            case 'value':
                ?>
                <div class="contentguard-stats-widget">
                    <h4>Content Portfolio Value</h4>
                    <p class="portfolio-value">$<?php echo number_format($portfolio_analysis['total_portfolio_value'], 2); ?></p>
                    <p class="portfolio-subtitle">Based on <?php echo count($detections); ?> AI bot detections</p>
                </div>
                <?php
                break;
                
            case 'detections':
                ?>
                <div class="contentguard-stats-widget">
                    <h4>AI Bot Activity</h4>
                    <p><strong><?php echo count($detections); ?></strong> total detections</p>
                    <p><strong><?php echo $portfolio_analysis['high_value_content_count']; ?></strong> high-value opportunities</p>
                    <p><strong><?php echo $portfolio_analysis['licensing_candidates']; ?></strong> licensing candidates</p>
                </div>
                <?php
                break;
                
            case 'companies':
                ?>
                <div class="contentguard-stats-widget">
                    <h4>Top AI Companies</h4>
                    <?php if (!empty($portfolio_analysis['top_value_companies'])): ?>
                        <ul>
                            <?php foreach (array_slice($portfolio_analysis['top_value_companies'], 0, 5, true) as $company => $value): ?>
                                <li><?php echo esc_html($company); ?>: $<?php echo number_format($value, 2); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p>No company data available.</p>
                    <?php endif; ?>
                </div>
                <?php
                break;
                
            default: // summary
                ?>
                <div class="contentguard-stats-widget">
                    <h4>ContentGuard Summary</h4>
                    <div class="stats-grid">
                        <div class="stat-item">
                            <span class="stat-number"><?php echo count($detections); ?></span>
                            <span class="stat-label">AI Bot Detections</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-number">$<?php echo number_format($portfolio_analysis['total_portfolio_value'], 2); ?></span>
                            <span class="stat-label">Portfolio Value</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-number"><?php echo $portfolio_analysis['licensing_candidates']; ?></span>
                            <span class="stat-label">Licensing Opportunities</span>
                        </div>
                    </div>
                    <p class="stats-period">Last <?php echo $atts['days']; ?> days</p>
                </div>
                
                <style>
                .contentguard-stats-widget {
                    border: 1px solid #ddd;
                    padding: 20px;
                    border-radius: 5px;
                    background: #f9f9f9;
                    margin: 15px 0;
                }
                .stats-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                    gap: 15px;
                    margin: 15px 0;
                }
                .stat-item {
                    text-align: center;
                }
                .stat-number {
                    display: block;
                    font-size: 24px;
                    font-weight: bold;
                    color: #2271b1;
                }
                .stat-label {
                    display: block;
                    font-size: 12px;
                    color: #666;
                    margin-top: 5px;
                }
                .portfolio-value {
                    font-size: 32px;
                    font-weight: bold;
                    color: #28a745;
                    margin: 10px 0 5px 0;
                }
                .portfolio-subtitle {
                    color: #666;
                    font-size: 14px;
                    margin: 0;
                }
                .stats-period {
                    text-align: center;
                    color: #888;
                    font-size: 12px;
                    margin: 10px 0 0 0;
                }
                </style>
                <?php
                break;
        }
        
        return ob_get_clean();
    }

    public function register_widgets() {
        register_widget('ContentGuard_Stats_Widget');
    }
}

/**
 * Widget for displaying ContentGuard stats
 */
class ContentGuard_Stats_Widget extends WP_Widget {
    
    public function __construct() {
        parent::__construct(
            'contentguard_stats_widget',
            'ContentGuard Stats',
            ['description' => 'Display AI bot detection statistics']
        );
    }
    
    public function widget($args, $instance) {
        $title = apply_filters('widget_title', $instance['title']);
        $days = $instance['days'] ?? 30;
        $show_type = $instance['show_type'] ?? 'summary';
        
        echo $args['before_widget'];
        
        if (!empty($title)) {
            echo $args['before_title'] . $title . $args['after_title'];
        }
        
        $widgets = new ContentGuard_Widgets();
        echo $widgets->stats_shortcode([
            'days' => $days,
            'show' => $show_type
        ]);
        
        echo $args['after_widget'];
    }
    
    public function form($instance) {
        $title = $instance['title'] ?? 'AI Bot Activity';
        $days = $instance['days'] ?? 30;
        $show_type = $instance['show_type'] ?? 'summary';
        ?>
        <p>
            <label for="<?php echo $this->get_field_id('title'); ?>">Title:</label>
            <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo esc_attr($title); ?>" />
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('days'); ?>">Days:</label>
            <input class="widefat" id="<?php echo $this->get_field_id('days'); ?>" name="<?php echo $this->get_field_name('days'); ?>" type="number" value="<?php echo esc_attr($days); ?>" min="1" max="365" />
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('show_type'); ?>">Display:</label>
            <select class="widefat" id="<?php echo $this->get_field_id('show_type'); ?>" name="<?php echo $this->get_field_name('show_type'); ?>">
                <option value="summary"<?php selected($show_type, 'summary'); ?>>Summary</option>
                <option value="value"<?php selected($show_type, 'value'); ?>>Portfolio Value</option>
                <option value="detections"<?php selected($show_type, 'detections'); ?>>Detection Stats</option>
                <option value="companies"<?php selected($show_type, 'companies'); ?>>Top Companies</option>
            </select>
        </p>
        <?php
    }
    
    public function update($new_instance, $old_instance) {
        $instance = [];
        $instance['title'] = (!empty($new_instance['title'])) ? sanitize_text_field($new_instance['title']) : '';
        $instance['days'] = (!empty($new_instance['days'])) ? absint($new_instance['days']) : 30;
        $instance['show_type'] = (!empty($new_instance['show_type'])) ? sanitize_text_field($new_instance['show_type']) : 'summary';
        
        return $instance;
    }
}
?>