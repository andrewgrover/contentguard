<?php
/**
 * Plontis CLI Commands
 * WordPress CLI commands for Plontis
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WordPress CLI commands for Plontis
 */
if (defined('WP_CLI') && WP_CLI) {
    class Plontis_CLI_Commands {
        
        private $value_calculator;
        private $content_analyzer;
        private $core;
        
        public function __construct() {
            $this->value_calculator = new PlontisValueCalculator();
            $this->content_analyzer = new PlontisContentAnalyzer();
            $this->core = new Plontis_Core();
        }
        
        /**
         * Analyze portfolio value
         * 
         * ## OPTIONS
         * 
         * [--days=<days>]
         * : Number of days to analyze (default: 30)
         * 
         * [--format=<format>]
         * : Output format (table, json, csv)
         * 
         * ## EXAMPLES
         * 
         *     wp plontis analyze --days=30 --format=table
         */
        public function analyze($args, $assoc_args) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'plontis_detections';
            
            $days = $assoc_args['days'] ?? 30;
            $format = $assoc_args['format'] ?? 'table';
            
            $detections = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table_name WHERE detected_at > DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            ), ARRAY_A);
            
            if (empty($detections)) {
                WP_CLI::warning("No detections found in the last {$days} days.");
                return;
            }
            
            $portfolio_analysis = $this->value_calculator->calculatePortfolioValue($detections);
            
            if ($format === 'json') {
                WP_CLI::line(json_encode($portfolio_analysis, JSON_PRETTY_PRINT));
            } elseif ($format === 'csv') {
                WP_CLI::line("metric,value");
                WP_CLI::line("total_portfolio_value," . $portfolio_analysis['total_portfolio_value']);
                WP_CLI::line("detection_count," . count($detections));
                WP_CLI::line("average_value_per_access," . $portfolio_analysis['average_value_per_access']);
                WP_CLI::line("high_value_content_count," . $portfolio_analysis['high_value_content_count']);
                WP_CLI::line("licensing_candidates," . $portfolio_analysis['licensing_candidates']);
                WP_CLI::line("estimated_annual_revenue," . $portfolio_analysis['estimated_annual_revenue']);
            } else {
                WP_CLI::success("Portfolio Analysis for Last {$days} Days:");
                WP_CLI::line("Total Portfolio Value: $" . number_format($portfolio_analysis['total_portfolio_value'], 2));
                WP_CLI::line("Detection Count: " . count($detections));
                WP_CLI::line("Average Value per Detection: $" . number_format($portfolio_analysis['average_value_per_access'], 2));
                WP_CLI::line("High-Value Content: " . $portfolio_analysis['high_value_content_count']);
                WP_CLI::line("Licensing Candidates: " . $portfolio_analysis['licensing_candidates']);
                WP_CLI::line("Estimated Annual Revenue: $" . number_format($portfolio_analysis['estimated_annual_revenue'], 2));
                
                if (!empty($portfolio_analysis['recommendations'])) {
                    WP_CLI::line("\nRecommendations:");
                    foreach ($portfolio_analysis['recommendations'] as $recommendation) {
                        WP_CLI::line("- " . $recommendation);
                    }
                }
            }
        }
        
        /**
         * Clean up old detection logs
         * 
         * ## OPTIONS
         * 
         * [--days=<days>]
         * : Delete records older than this many days (default: 90)
         * 
         * [--dry-run]
         * : Show what would be deleted without actually deleting
         */
        public function cleanup($args, $assoc_args) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'plontis_detections';
            
            $days = $assoc_args['days'] ?? 90;
            $dry_run = isset($assoc_args['dry-run']);
            
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE detected_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            ));
            
            if ($count == 0) {
                WP_CLI::success("No records older than {$days} days found.");
                return;
            }
            
            if ($dry_run) {
                WP_CLI::line("Would delete {$count} records older than {$days} days.");
                return;
            }
            
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM $table_name WHERE detected_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            ));
            
            WP_CLI::success("Deleted {$deleted} old detection records.");
        }
        
        /**
         * Test bot detection
         * 
         * ## OPTIONS
         * 
         * <user_agent>
         * : User agent string to test
         * 
         * [--url=<url>]
         * : URL to analyze (default: /test-content)
         */
        public function test($args, $assoc_args) {
            $user_agent = $args[0];
            $url = $assoc_args['url'] ?? '/test-content';
            
            $detection = $this->core->analyze_user_agent($user_agent);
            
            $content_metadata = $this->content_analyzer->analyzeContent($url);
            
            $valuation = $this->value_calculator->calculateContentValue(
                array_merge($detection, ['request_uri' => $url]), 
                $content_metadata
            );
            
            WP_CLI::line("Detection Results:");
            WP_CLI::line("Is Bot: " . ($detection['is_bot'] ? 'Yes' : 'No'));
            WP_CLI::line("Company: " . ($detection['company'] ?: 'Unknown'));
            WP_CLI::line("Risk Level: " . $detection['risk_level']);
            WP_CLI::line("Commercial Risk: " . ($detection['commercial_risk'] ? 'Yes' : 'No'));
            WP_CLI::line("Confidence: " . $detection['confidence'] . '%');
            
            WP_CLI::line("\nValuation Results:");
            WP_CLI::line("Estimated Value: $" . number_format($valuation['estimated_value'], 2));
            WP_CLI::line("Content Type: " . $valuation['breakdown']['content_type']);
            WP_CLI::line("Licensing Potential: " . $valuation['licensing_potential']['potential']);
            WP_CLI::line("Market Position: " . $valuation['market_context']['market_position']);
            
            if (!empty($valuation['licensing_potential']['recommendation'])) {
                WP_CLI::line("\nRecommendation:");
                WP_CLI::line($valuation['licensing_potential']['recommendation']);
            }
        }
        
        /**
         * Export detection data
         * 
         * ## OPTIONS
         * 
         * [--days=<days>]
         * : Number of days to export (default: 30)
         * 
         * [--format=<format>]
         * : Export format (csv, json) (default: csv)
         * 
         * [--file=<file>]
         * : Output file path (optional)
         */
        public function export($args, $assoc_args) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'plontis_detections';
            
            $days = $assoc_args['days'] ?? 30;
            $format = $assoc_args['format'] ?? 'csv';
            $file = $assoc_args['file'] ?? null;
            
            $detections = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table_name WHERE detected_at > DATE_SUB(NOW(), INTERVAL %d DAY) ORDER BY detected_at DESC",
                $days
            ), ARRAY_A);
            
            if (empty($detections)) {
                WP_CLI::warning("No detections found in the last {$days} days.");
                return;
            }
            
            $output = '';
            
            if ($format === 'json') {
                $output = json_encode($detections, JSON_PRETTY_PRINT);
            } else {
                // CSV format
                $output = "Date,Company,Bot Type,Page,Risk Level,Estimated Value,Content Type,Quality Score\n";
                
                foreach ($detections as $detection) {
                    $output .= sprintf(
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
            }
            
            if ($file) {
                file_put_contents($file, $output);
                WP_CLI::success("Exported " . count($detections) . " detections to {$file}");
            } else {
                WP_CLI::line($output);
            }
        }
        
        /**
         * Generate licensing report
         * 
         * ## OPTIONS
         * 
         * [--days=<days>]
         * : Number of days to analyze (default: 30)
         * 
         * [--file=<file>]
         * : Output file path (optional)
         */
        public function report($args, $assoc_args) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'plontis_detections';
            
            $days = $assoc_args['days'] ?? 30;
            $file = $assoc_args['file'] ?? null;
            
            $detections = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table_name WHERE detected_at > DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            ), ARRAY_A);
            
            if (empty($detections)) {
                WP_CLI::warning("No detections found in the last {$days} days.");
                return;
            }
            
            $portfolio_analysis = $this->value_calculator->calculatePortfolioValue($detections);
            
            // Get licensing recommendations
            $licensing_recommendations = PlontisLicensingMarketData::getLicensingRecommendations(
                $portfolio_analysis['total_portfolio_value'],
                ['article', 'image', 'video'],
                array_keys($portfolio_analysis['top_value_companies'] ?? [])
            );
            
            $report = "Plontis Licensing Report\n";
            $report .= "Generated: " . current_time('Y-m-d H:i:s') . "\n";
            $report .= "Period: Last {$days} days\n\n";
            
            $report .= "PORTFOLIO SUMMARY\n";
            $report .= "================\n";
            $report .= "Total Portfolio Value: $" . number_format($portfolio_analysis['total_portfolio_value'], 2) . "\n";
            $report .= "Detection Count: " . count($detections) . "\n";
            $report .= "High-Value Content: " . $portfolio_analysis['high_value_content_count'] . " items\n";
            $report .= "Licensing Candidates: " . $portfolio_analysis['licensing_candidates'] . " items\n";
            $report .= "Estimated Annual Revenue: $" . number_format($portfolio_analysis['estimated_annual_revenue'], 2) . "\n\n";
            
            if (!empty($portfolio_analysis['top_value_companies'])) {
                $report .= "TOP VALUE COMPANIES\n";
                $report .= "===================\n";
                foreach ($portfolio_analysis['top_value_companies'] as $company => $value) {
                    $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE company = %s", $company));
                    $report .= sprintf("%-20s $%8.2f (%d detections)\n", $company, $value, $count);
                }
                $report .= "\n";
            }
            
            if (!empty($licensing_recommendations)) {
                $report .= "LICENSING RECOMMENDATIONS\n";
                $report .= "========================\n";
                foreach ($licensing_recommendations as $recommendation) {
                    $report .= "• " . $recommendation['type'] . "\n";
                    $report .= "  " . $recommendation['description'] . "\n";
                    if (isset($recommendation['estimated_annual'])) {
                        $report .= "  Estimated Annual Value: $" . number_format($recommendation['estimated_annual'], 2) . "\n";
                    }
                    $report .= "  Next Steps: " . $recommendation['next_steps'] . "\n\n";
                }
            }
            
            $report .= "MARKET CONTEXT\n";
            $report .= "==============\n";
            $report .= "Based on current market rates:\n";
            $report .= "• Getty Images: $130-$575 per image\n";
            $report .= "• Academic Publishing: $1,626 average APC\n";
            $report .= "• Music Licensing: $250-$2,000 annual\n";
            $report .= "• News Syndication: $35/month professional rates\n\n";
            
            $report .= "Recent AI Licensing Deals:\n";
            $report .= "• Taylor & Francis + Microsoft: $10M academic content\n";
            $report .= "• Wiley + Undisclosed: $23M academic publishing\n";
            $report .= "• Associated Press + OpenAI: Multi-year news licensing\n";
            
            if ($file) {
                file_put_contents($file, $report);
                WP_CLI::success("Generated licensing report: {$file}");
            } else {
                WP_CLI::line($report);
            }
        }
        
        /**
         * Show plugin status
         */
        public function status($args, $assoc_args) {
            $settings = get_option('plontis_settings');
            
            WP_CLI::line("Plontis Status:");
            WP_CLI::line("==================");
            WP_CLI::line("Version: " . PLONTIS_VERSION);
            WP_CLI::line("Detection Enabled: " . ($settings['enable_detection'] ? 'Yes' : 'No'));
            WP_CLI::line("Notifications Enabled: " . ($settings['enable_notifications'] ? 'Yes' : 'No'));
            WP_CLI::line("Enhanced Valuation: " . ($settings['enhanced_valuation'] ? 'Yes' : 'No'));
            WP_CLI::line("High-Value Threshold: $" . ($settings['high_value_threshold'] ?? 50.00));
            WP_CLI::line("Log Retention: " . ($settings['log_retention_days'] ?? 90) . " days");
            
            // Check components
            WP_CLI::line("\nComponent Status:");
            WP_CLI::line("Value Calculator: " . (class_exists('PlontisValueCalculator') ? 'Loaded' : 'Missing'));
            WP_CLI::line("Content Analyzer: " . (class_exists('PlontisContentAnalyzer') ? 'Loaded' : 'Missing'));
            WP_CLI::line("Market Data: " . (class_exists('PlontisLicensingMarketData') ? 'Loaded' : 'Missing'));
            
            // Database status
            global $wpdb;
            $table_name = $wpdb->prefix . 'plontis_detections';
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
            WP_CLI::line("Database Table: " . ($table_exists ? 'Exists' : 'Missing'));
            
            if ($table_exists) {
                $total_detections = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
                $recent_detections = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE detected_at > DATE_SUB(NOW(), INTERVAL 7 DAY)");
                WP_CLI::line("Total Detections: " . $total_detections);
                WP_CLI::line("Recent Detections (7 days): " . $recent_detections);
            }
        }
    }
    
    WP_CLI::add_command('plontis', 'Plontis_CLI_Commands');
}

/**
 * Enhanced debugging and logging for development
 */
if (defined('WP_DEBUG') && WP_DEBUG) {
    function plontis_debug_log($message, $data = null) {
        $log_message = '[Plontis Enhanced] ' . $message;
        if ($data) {
            $log_message .= ' | Data: ' . json_encode($data);
        }
        error_log($log_message);
    }
    
    function plontis_test_enhanced_system() {
        try {
            $value_calculator = new PlontisValueCalculator();
            $content_analyzer = new PlontisContentAnalyzer();
            
            $test_cases = [
                [
                    'name' => 'OpenAI Premium Content',
                    'detection' => [
                        'company' => 'OpenAI',
                        'bot_type' => 'GPTBot',
                        'request_uri' => '/premium-tutorial-guide',
                        'risk_level' => 'high',
                        'confidence' => 95,
                        'commercial_risk' => true
                    ],
                    'metadata' => [
                        'content_type' => 'article',
                        'quality_score' => 85,
                        'word_count' => 2500,
                        'technical_depth' => 'advanced'
                    ]
                ],
                [
                    'name' => 'Anthropic Research Content',
                    'detection' => [
                        'company' => 'Anthropic',
                        'bot_type' => 'ClaudeBot',
                        'request_uri' => '/research/ai-safety-paper',
                        'risk_level' => 'high',
                        'confidence' => 92,
                        'commercial_risk' => true
                    ],
                    'metadata' => [
                        'content_type' => 'article',
                        'quality_score' => 95,
                        'word_count' => 5000,
                        'technical_depth' => 'expert'
                    ]
                ]
            ];
            
            foreach ($test_cases as $test) {
                $valuation = $value_calculator->calculateContentValue($test['detection'], $test['metadata']);
                plontis_debug_log("Test Case: {$test['name']}", [
                    'estimated_value' => $valuation['estimated_value'],
                    'content_type' => $valuation['breakdown']['content_type'],
                    'licensing_potential' => $valuation['licensing_potential']['potential'],
                    'market_position' => $valuation['market_context']['market_position']
                ]);
            }
            
            plontis_debug_log("Enhanced system test completed successfully");
        } catch (Exception $e) {
            plontis_debug_log("Enhanced system test failed: " . $e->getMessage());
        }
    }
    
    add_action('wp_loaded', 'plontis_test_enhanced_system');
}
?>