<?php
/**
 * ContentGuard Content Value Calculator
 * Advanced content valuation system based on real-world licensing rates
 * 
 * Based on 2024-2025 research data from:
 * - Getty Images: $130-$575 per image
 * - ASCAP/BMI: $250-$2,000 annual licensing + $2-$3 per day
 * - Academic Publishers: $1,626 average APC, $20-$60 per article access
 * - News Syndication: $35/month subscription models, enterprise rates
 * - AI Training Data: $10M+ deals for major publishers
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class ContentGuardValueCalculator {
    
    /**
     * Content type multipliers based on market research
     */
    private $content_type_rates = [
        // High-value content types
        'article' => [
            'base_rate' => 25.00,      // Based on academic article access rates ($20-60)
            'word_rate' => 0.008,      // $8 per 1000 words for quality content
            'research_multiplier' => 3.5, // Research articles worth more
            'news_multiplier' => 2.8,     // News articles valuable for training
            'evergreen_multiplier' => 2.2 // Evergreen content retains value
        ],
        'image' => [
            'base_rate' => 145.00,     // Getty Images range $130-$575, using conservative avg
            'resolution_multiplier' => [
                'low' => 0.6,          // Web resolution
                'medium' => 1.0,       // Standard
                'high' => 1.8,         // Professional quality
                'vector' => 2.5        // Vector/scalable
            ],
            'commercial_multiplier' => 3.2 // Commercial use premium
        ],
        'video' => [
            'base_rate' => 320.00,     // Video typically 2-3x image rates
            'duration_rate' => 12.50,  // Per minute
            'quality_multiplier' => [
                '720p' => 0.8,
                '1080p' => 1.0,
                '4k' => 2.4,
                '8k' => 4.2
            ]
        ],
        'audio' => [
            'base_rate' => 85.00,      // Music licensing rates
            'duration_rate' => 8.75,   // Per minute
            'music_multiplier' => 1.8,  // Music vs voice content
            'voice_multiplier' => 1.2   // Voice content
        ],
        'code' => [
            'base_rate' => 95.00,      // Code snippets for AI training
            'line_rate' => 0.35,       // Per line of code
            'complexity_multiplier' => [
                'basic' => 0.7,
                'intermediate' => 1.0,
                'advanced' => 2.1,
                'expert' => 3.8
            ]
        ],
        'data' => [
            'base_rate' => 15.00,      // Structured data
            'record_rate' => 0.05,     // Per data record/row
            'quality_multiplier' => 2.4 // Clean, structured data premium
        ]
    ];

    /**
     * Company risk and value multipliers based on commercial use
     */
    private $company_multipliers = [
        // Tier 1: Major commercial AI companies
        'OpenAI' => [
            'multiplier' => 4.2,
            'base_value' => 45.00,
            'reason' => 'ChatGPT commercial leader, premium licensing rates'
        ],
        'Anthropic' => [
            'multiplier' => 3.8,
            'base_value' => 42.00,
            'reason' => 'Claude enterprise focus, high-value use cases'
        ],
        'Google' => [
            'multiplier' => 4.5,
            'base_value' => 48.00,
            'reason' => 'Largest search/AI revenue, Bard/Gemini training'
        ],
        'Meta' => [
            'multiplier' => 3.2,
            'base_value' => 35.00,
            'reason' => 'Llama models, social media integration'
        ],
        
        // Tier 2: Medium commercial risk
        'Perplexity' => [
            'multiplier' => 2.8,
            'base_value' => 28.00,
            'reason' => 'AI search engine, growing user base'
        ],
        'Apple' => [
            'multiplier' => 3.5,
            'base_value' => 38.00,
            'reason' => 'iOS AI features, premium market'
        ],
        'Amazon' => [
            'multiplier' => 3.1,
            'base_value' => 32.00,
            'reason' => 'Alexa, AWS AI services'
        ],
        'Cohere' => [
            'multiplier' => 2.5,
            'base_value' => 24.00,
            'reason' => 'Enterprise AI, B2B focus'
        ],
        'ByteDance' => [
            'multiplier' => 2.9,
            'base_value' => 26.00,
            'reason' => 'TikTok AI, global reach'
        ],
        
        // Tier 3: Research/Academic (lower commercial risk)
        'Common Crawl' => [
            'multiplier' => 1.2,
            'base_value' => 8.00,
            'reason' => 'Non-profit, research dataset'
        ],
        'Academic' => [
            'multiplier' => 0.8,
            'base_value' => 5.00,
            'reason' => 'Research use, limited commercial value'
        ],
        
        // Default for unknown companies
        'Unknown' => [
            'multiplier' => 1.5,
            'base_value' => 15.00,
            'reason' => 'Unknown commercial intent'
        ]
    ];

    /**
     * Content characteristics that affect value
     */
    private $content_characteristics = [
        // Quality indicators
        'original_research' => 2.8,
        'exclusive_content' => 3.2,
        'evergreen_content' => 1.8,
        'trending_topic' => 2.1,
        'technical_depth' => 2.4,
        'multimedia_rich' => 1.9,
        'high_engagement' => 2.2,
        'authoritative_source' => 2.6,
        
        // Content age factors
        'age_multipliers' => [
            'days_0_30' => 1.0,      // Fresh content full value
            'days_31_90' => 0.85,    // Recent content
            'days_91_365' => 0.70,   // Older content
            'days_366_plus' => 0.55  // Archive content
        ],
        
        // Content length/depth
        'length_multipliers' => [
            'short' => 0.7,          // < 500 words
            'medium' => 1.0,         // 500-2000 words
            'long' => 1.4,           // 2000-5000 words
            'comprehensive' => 1.8    // 5000+ words
        ]
    ];

    /**
     * Market factors affecting overall valuation
     */
    private $market_factors = [
        'ai_market_growth' => 1.15,    // 15% annual AI market growth
        'content_scarcity' => 1.08,    // Quality content becoming scarcer
        'legal_risk' => 1.12,          // Legal scrutiny increasing value
        'competition' => 1.06,         // Competition for training data
        'regulatory' => 1.04           // Regulatory compliance costs
    ];

    /**
     * Calculate comprehensive content value for AI bot detection
     */
    public function calculateContentValue($detection_data, $content_metadata = []) {
        // Extract basic detection info
        $company = $detection_data['company'] ?? 'Unknown';
        $bot_type = $detection_data['bot_type'] ?? 'Unknown';
        $risk_level = $detection_data['risk_level'] ?? 'low';
        $commercial_risk = $detection_data['commercial_risk'] ?? false;
        $confidence = $detection_data['confidence'] ?? 50;
        $page_url = $detection_data['request_uri'] ?? '';
        
        // Analyze content type from URL and metadata
        $content_analysis = $this->analyzeContentType($page_url, $content_metadata);
        
        // Calculate base value
        $base_value = $this->calculateBaseValue($content_analysis, $company);
        
        // Apply content characteristics
        $characteristic_multiplier = $this->calculateCharacteristicMultiplier($content_metadata);
        
        // Apply market factors
        $market_multiplier = $this->calculateMarketMultiplier();
        
        // Apply confidence and risk adjustments
        $confidence_multiplier = $confidence / 100;
        $risk_multiplier = $this->getRiskMultiplier($risk_level, $commercial_risk);
        
        // Calculate final value
        $estimated_value = $base_value * 
                          $characteristic_multiplier * 
                          $market_multiplier * 
                          $confidence_multiplier * 
                          $risk_multiplier;
        
        // REMOVED: Cap maximum value - let it calculate realistic values
        // $estimated_value = min($estimated_value, 2500.00);
        
        // Apply reasonable bounds instead
        $estimated_value = max(0.50, $estimated_value); // Minimum 50 cents
        $estimated_value = min($estimated_value, 500.00); // Maximum $500 per access
        
        // Debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("ContentGuard Value Calculation Debug:");
            error_log("  Company: $company");
            error_log("  Base Value: $base_value");
            error_log("  Characteristic Multiplier: $characteristic_multiplier");
            error_log("  Market Multiplier: $market_multiplier");
            error_log("  Confidence Multiplier: $confidence_multiplier");
            error_log("  Risk Multiplier: $risk_multiplier");
            error_log("  Final Estimated Value: $estimated_value");
        }
        
        return [
            'estimated_value' => round($estimated_value, 2),
            'breakdown' => [
                'base_value' => round($base_value, 2),
                'content_type' => $content_analysis['type'],
                'company_multiplier' => $this->company_multipliers[$company]['multiplier'] ?? 1.5,
                'characteristic_multiplier' => round($characteristic_multiplier, 2),
                'market_multiplier' => round($market_multiplier, 2),
                'confidence_factor' => $confidence_multiplier,
                'risk_factor' => round($risk_multiplier, 2)
            ],
            'market_context' => $this->getMarketContext($company, $content_analysis['type']),
            'licensing_potential' => $this->assessLicensingPotential($estimated_value, $company, $commercial_risk)
        ];
    }

    /**
     * Analyze content type from URL and metadata
     */
    private function analyzeContentType($url, $metadata) {
        $content_type = 'article'; // Default
        $characteristics = [];
        
        // Analyze URL patterns
        if (preg_match('/\.(jpg|jpeg|png|gif|svg|webp)$/i', $url)) {
            $content_type = 'image';
        } elseif (preg_match('/\.(mp4|avi|mov|wmv|flv|webm)$/i', $url)) {
            $content_type = 'video';
        } elseif (preg_match('/\.(mp3|wav|flac|aac|ogg)$/i', $url)) {
            $content_type = 'audio';
        } elseif (preg_match('/\/(api|data|json|xml|csv)/', $url)) {
            $content_type = 'data';
        } elseif (preg_match('/\/(code|github|programming|dev)/', $url)) {
            $content_type = 'code';
        }
        
        // Analyze URL for content characteristics
        if (preg_match('/\/(blog|article|post|news)/', $url)) {
            $characteristics[] = 'article_content';
        }
        if (preg_match('/\/(research|study|paper|academic)/', $url)) {
            $characteristics[] = 'original_research';
        }
        if (preg_match('/\/(breaking|latest|trending)/', $url)) {
            $characteristics[] = 'trending_topic';
        }
        
        // Use metadata if provided
        if (!empty($metadata['content_type'])) {
            $content_type = $metadata['content_type'];
        }
        if (!empty($metadata['word_count'])) {
            $word_count = $metadata['word_count'];
            if ($word_count < 500) {
                $characteristics[] = 'short_content';
            } elseif ($word_count > 5000) {
                $characteristics[] = 'comprehensive_content';
            }
        }
        
        return [
            'type' => $content_type,
            'characteristics' => $characteristics,
            'metadata' => $metadata
        ];
    }

    /**
     * Calculate base value for content type and company
     */
    private function calculateBaseValue($content_analysis, $company) {
        $content_type = $content_analysis['type'];
        $base_rate = $this->content_type_rates[$content_type]['base_rate'] ?? 25.00;
        
        // Get company multiplier
        $company_data = $this->company_multipliers[$company] ?? $this->company_multipliers['Unknown'];
        $company_base = $company_data['base_value'];
        $company_multiplier = $company_data['multiplier'];
        
        // Combine content base rate with company value
        return ($base_rate + $company_base) * $company_multiplier;
    }

    /**
     * Calculate multiplier based on content characteristics
     */
    private function calculateCharacteristicMultiplier($metadata) {
        $multiplier = 1.0;
        
        // Apply quality characteristics
        if (!empty($metadata['original_research'])) {
            $multiplier *= $this->content_characteristics['original_research'];
        }
        if (!empty($metadata['exclusive_content'])) {
            $multiplier *= $this->content_characteristics['exclusive_content'];
        }
        if (!empty($metadata['technical_depth'])) {
            $multiplier *= $this->content_characteristics['technical_depth'];
        }
        if (!empty($metadata['high_engagement'])) {
            $multiplier *= $this->content_characteristics['high_engagement'];
        }
        
        // Apply age factor
        if (!empty($metadata['publish_date'])) {
            $age_days = (time() - strtotime($metadata['publish_date'])) / (24 * 3600);
            if ($age_days <= 30) {
                $multiplier *= $this->content_characteristics['age_multipliers']['days_0_30'];
            } elseif ($age_days <= 90) {
                $multiplier *= $this->content_characteristics['age_multipliers']['days_31_90'];
            } elseif ($age_days <= 365) {
                $multiplier *= $this->content_characteristics['age_multipliers']['days_91_365'];
            } else {
                $multiplier *= $this->content_characteristics['age_multipliers']['days_366_plus'];
            }
        }
        
        // Apply length factor
        if (!empty($metadata['word_count'])) {
            $word_count = $metadata['word_count'];
            if ($word_count < 500) {
                $multiplier *= $this->content_characteristics['length_multipliers']['short'];
            } elseif ($word_count <= 2000) {
                $multiplier *= $this->content_characteristics['length_multipliers']['medium'];
            } elseif ($word_count <= 5000) {
                $multiplier *= $this->content_characteristics['length_multipliers']['long'];
            } else {
                $multiplier *= $this->content_characteristics['length_multipliers']['comprehensive'];
            }
        }
        
        return $multiplier;
    }

    /**
     * Calculate market factors multiplier
     */
    private function calculateMarketMultiplier() {
        $multiplier = 1.0;
        
        foreach ($this->market_factors as $factor => $value) {
            $multiplier *= $value;
        }
        
        return $multiplier;
    }

    /**
     * Get risk level multiplier
     */
    private function getRiskMultiplier($risk_level, $commercial_risk) {
        $base_multiplier = 1.0;
        
        switch ($risk_level) {
            case 'high':
                $base_multiplier = 1.8;
                break;
            case 'medium':
                $base_multiplier = 1.3;
                break;
            case 'low':
                $base_multiplier = 0.9;
                break;
        }
        
        // Commercial risk adds significant value
        if ($commercial_risk) {
            $base_multiplier *= 2.2;
        }
        
        return $base_multiplier;
    }

    /**
     * Get market context for the valuation
     */
    private function getMarketContext($company, $content_type) {
        $company_data = $this->company_multipliers[$company] ?? $this->company_multipliers['Unknown'];
        
        return [
            'company_tier' => $this->getCompanyTier($company),
            'market_position' => $company_data['reason'],
            'content_demand' => $this->getContentDemand($content_type),
            'licensing_precedent' => $this->getLicensingPrecedent($company),
            'market_trends' => [
                'ai_training_demand' => 'High - AI companies increasingly paying for quality data',
                'content_scarcity' => 'Growing - Quality content becoming more valuable',
                'legal_landscape' => 'Evolving - More licensing deals being struck',
                'regulatory_impact' => 'Increasing - Compliance driving up values'
            ]
        ];
    }

    /**
     * Assess licensing potential
     */
    private function assessLicensingPotential($estimated_value, $company, $commercial_risk) {
        $potential = 'Low';
        $recommendation = 'Monitor for patterns';
        
        if ($estimated_value > 100 && $commercial_risk) {
            $potential = 'High';
            $recommendation = 'Strong candidate for licensing negotiation';
        } elseif ($estimated_value > 50) {
            $potential = 'Medium';
            $recommendation = 'Consider bulk licensing for multiple assets';
        }
        
        return [
            'potential' => $potential,
            'recommendation' => $recommendation,
            'estimated_annual_value' => $this->calculateAnnualValue($estimated_value),
            'comparable_rates' => $this->getComparableRates($company)
        ];
    }

    /**
     * Helper methods for market context
     */
    private function getCompanyTier($company) {
        $tier1 = ['OpenAI', 'Google', 'Anthropic', 'Meta'];
        $tier2 = ['Apple', 'Amazon', 'Perplexity', 'ByteDance', 'Cohere'];
        
        if (in_array($company, $tier1)) return 'Tier 1 - Major AI Companies';
        if (in_array($company, $tier2)) return 'Tier 2 - Commercial AI Companies';
        return 'Tier 3 - Emerging/Unknown';
    }

    private function getContentDemand($content_type) {
        $demand_levels = [
            'article' => 'Very High - Core training data for language models',
            'image' => 'High - Visual AI training and multimodal models',
            'video' => 'Growing - Video AI and multimedia training',
            'audio' => 'Moderate - Voice and audio AI applications',
            'code' => 'High - Code generation and programming AI',
            'data' => 'High - Structured data for AI training'
        ];
        
        return $demand_levels[$content_type] ?? 'Moderate';
    }

    private function getLicensingPrecedent($company) {
        $precedents = [
            'OpenAI' => 'Associated Press deal, multiple publisher agreements',
            'Google' => 'News licensing deals, YouTube creator payments',
            'Meta' => 'Music licensing, news partnerships',
            'Anthropic' => 'Constitutional AI training, ethical data sourcing'
        ];
        
        return $precedents[$company] ?? 'Limited public precedent';
    }

    private function calculateAnnualValue($per_access_value) {
        // Estimate annual value based on typical access patterns
        $estimated_annual_accesses = max(1, round($per_access_value / 10)); // Higher value content accessed less frequently
        return $per_access_value * $estimated_annual_accesses;
    }

    private function getComparableRates($company) {
        return [
            'getty_images' => '$130-$575 per image',
            'academic_papers' => '$20-$60 per access',
            'news_syndication' => '$35/month subscriptions',
            'music_licensing' => '$250-$2000 annual',
            'ai_training_deals' => '$10M+ for major publishers'
        ];
    }

    /**
     * Get summary statistics for multiple detections
     */
    public function calculatePortfolioValue($detections) {
        $total_value = 0;
        $high_value_content = 0;
        $licensing_candidates = 0;
        $company_breakdown = [];
        
        foreach ($detections as $detection) {
            $value_data = $this->calculateContentValue($detection);
            $value = $value_data['estimated_value'];
            $total_value += $value;
            
            if ($value > 100) {
                $high_value_content++;
            }
            
            if ($value_data['licensing_potential']['potential'] === 'High') {
                $licensing_candidates++;
            }
            
            $company = $detection['company'] ?? 'Unknown';
            $company_breakdown[$company] = ($company_breakdown[$company] ?? 0) + $value;
        }
        
        // Sort company breakdown by value (arsort modifies array in place and returns boolean)
        arsort($company_breakdown);
        $top_value_companies = array_slice($company_breakdown, 0, 5, true);
        
        return [
            'total_portfolio_value' => round($total_value, 2),
            'average_value_per_access' => round($total_value / max(1, count($detections)), 2),
            'high_value_content_count' => $high_value_content,
            'licensing_candidates' => $licensing_candidates,
            'top_value_companies' => $top_value_companies,
            'estimated_annual_revenue' => round($total_value * 0.15, 2), // Conservative 15% licensing rate
            'recommendations' => $this->generatePortfolioRecommendations($total_value, $licensing_candidates)
        ];
    }

    private function generatePortfolioRecommendations($total_value, $licensing_candidates) {
        $recommendations = [];
        
        if ($total_value > 10000) {
            $recommendations[] = 'High-value portfolio - Consider professional licensing consultation';
        }
        
        if ($licensing_candidates > 10) {
            $recommendations[] = 'Multiple licensing opportunities - Explore bulk licensing deals';
        }
        
        if ($total_value > 1000) {
            $recommendations[] = 'Significant content value - Document all AI bot activity for licensing negotiations';
        }
        
        $recommendations[] = 'Join ContentGuard platform to connect with AI companies seeking licensed content';
        
        return $recommendations;
    }
}
?>