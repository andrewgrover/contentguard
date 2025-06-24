<?php
/**
 * Plontis Content Analyzer
 * Analyzes webpage content to extract metadata for accurate value estimation
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PlontisContentAnalyzer {
    
    /**
     * Cache for analyzed content to avoid re-processing
     */
    private $analysis_cache = [];
    
    /**
     * Content quality indicators
     */
    private $quality_indicators = [
        'research_keywords' => [
            'study', 'research', 'analysis', 'methodology', 'findings', 
            'conclusion', 'abstract', 'peer-reviewed', 'citation', 'bibliography',
            'experiment', 'hypothesis', 'data', 'results', 'statistical'
        ],
        'technical_keywords' => [
            'algorithm', 'implementation', 'architecture', 'framework',
            'optimization', 'performance', 'scalability', 'api', 'database',
            'security', 'encryption', 'authentication', 'protocol'
        ],
        'news_keywords' => [
            'breaking', 'exclusive', 'developing', 'latest', 'update',
            'investigation', 'report', 'sources', 'confirmed', 'statement'
        ],
        'evergreen_keywords' => [
            'guide', 'tutorial', 'how-to', 'tips', 'best practices',
            'comprehensive', 'ultimate', 'complete', 'definitive'
        ]
    ];
    
    /**
     * High-value content patterns
     */
    private $high_value_patterns = [
        'original_research' => [
            'patterns' => [
                '/\b(our study|our research|we found|we analyzed)\b/i',
                '/\b(methodology|participants|sample size|statistical significance)\b/i',
                '/\b(peer.?review|journal|publication|doi:)\b/i'
            ],
            'multiplier' => 2.8
        ],
        'exclusive_content' => [
            'patterns' => [
                '/\b(exclusive|first.?time|never.?before|breaking)\b/i',
                '/\b(interview|investigation|expose|reveal)\b/i'
            ],
            'multiplier' => 3.2
        ],
        'technical_depth' => [
            'patterns' => [
                '/\b(algorithm|implementation|architecture|framework)\b/i',
                '/\b(code|programming|development|software)\b/i',
                '/\b(api|database|server|infrastructure)\b/i'
            ],
            'multiplier' => 2.4
        ],
        'multimedia_rich' => [
            'patterns' => [
                '/<img[^>]+>/i',
                '/<video[^>]+>/i',
                '/<audio[^>]+>/i',
                '/\b(chart|graph|diagram|infographic)\b/i'
            ],
            'multiplier' => 1.9
        ]
    ];
    
    /**
     * Analyze content from URL or cached page data
     */
    public function analyzeContent($url, $force_refresh = false) {
        // Check cache first
        $cache_key = md5($url);
        if (!$force_refresh && isset($this->analysis_cache[$cache_key])) {
            return $this->analysis_cache[$cache_key];
        }
        
        // Try to get content from WordPress if it's a local URL
        $content_data = $this->getWordPressContent($url);
        
        // If not WordPress content, try to fetch remotely (with caution)
        if (empty($content_data)) {
            $content_data = $this->getRemoteContent($url);
        }
        
        // Analyze the content
        $analysis = $this->performContentAnalysis($content_data, $url);
        
        // Cache the result
        $this->analysis_cache[$cache_key] = $analysis;
        
        return $analysis;
    }
    
    /**
     * Get content from WordPress if it's a local URL
     */
    private function getWordPressContent($url) {
        // Parse URL to get the path
        $parsed_url = parse_url($url);
        $path = $parsed_url['path'] ?? '';
        
        // Skip if it's not a content page
        if (empty($path) || $path === '/') {
            return null;
        }
        
        // Try to get WordPress post by URL
        $post_id = url_to_postid($url);
        if ($post_id) {
            $post = get_post($post_id);
            if ($post) {
                return [
                    'title' => $post->post_title,
                    'content' => $post->post_content,
                    'excerpt' => $post->post_excerpt,
                    'publish_date' => $post->post_date,
                    'post_type' => $post->post_type,
                    'author' => get_the_author_meta('display_name', $post->post_author),
                    'categories' => wp_get_post_categories($post_id, ['fields' => 'names']),
                    'tags' => wp_get_post_tags($post_id, ['fields' => 'names']),
                    'word_count' => str_word_count(strip_tags($post->post_content)),
                    'is_local' => true
                ];
            }
        }
        
        return null;
    }
    
    /**
     * Get content from remote URL (basic analysis only)
     */
    private function getRemoteContent($url) {
        // For security, only analyze URL patterns, not fetch remote content
        return [
            'url' => $url,
            'is_local' => false,
            'url_analysis' => $this->analyzeUrl($url)
        ];
    }
    
    /**
     * Analyze URL patterns for content type hints
     */
    private function analyzeUrl($url) {
        $analysis = [
            'content_type' => 'unknown',
            'characteristics' => [],
            'quality_indicators' => []
        ];
        
        // Content type from URL
        if (preg_match('/\.(jpg|jpeg|png|gif|svg|webp)$/i', $url)) {
            $analysis['content_type'] = 'image';
        } elseif (preg_match('/\.(mp4|avi|mov|wmv|webm)$/i', $url)) {
            $analysis['content_type'] = 'video';
        } elseif (preg_match('/\.(mp3|wav|flac|aac)$/i', $url)) {
            $analysis['content_type'] = 'audio';
        } elseif (preg_match('/\/(blog|article|post|news)/', $url)) {
            $analysis['content_type'] = 'article';
        } elseif (preg_match('/\/(research|study|paper|academic)/', $url)) {
            $analysis['content_type'] = 'article';
            $analysis['characteristics'][] = 'research';
        } elseif (preg_match('/\/(api|data|json|xml)/', $url)) {
            $analysis['content_type'] = 'data';
        } elseif (preg_match('/\/(code|github|programming)/', $url)) {
            $analysis['content_type'] = 'code';
        }
        
        // Quality indicators from URL
        if (preg_match('/\/(breaking|exclusive|investigation)/', $url)) {
            $analysis['quality_indicators'][] = 'high_value';
        }
        if (preg_match('/\/(tutorial|guide|how-to)/', $url)) {
            $analysis['quality_indicators'][] = 'evergreen';
        }
        if (preg_match('/\/(review|analysis|comparison)/', $url)) {
            $analysis['quality_indicators'][] = 'analytical';
        }
        
        return $analysis;
    }
    
    /**
     * Perform comprehensive content analysis
     */
    private function performContentAnalysis($content_data, $url) {
        if (empty($content_data)) {
            return $this->getBasicAnalysis($url);
        }
        
        $analysis = [
            'content_type' => 'article',
            'word_count' => 0,
            'characteristics' => [],
            'quality_score' => 0,
            'estimated_read_time' => 0,
            'technical_depth' => 'basic',
            'commercial_value' => 'medium',
            'metadata' => []
        ];
        
        // Basic content metrics
        if (isset($content_data['content'])) {
            $content = $content_data['content'];
            $analysis['word_count'] = str_word_count(strip_tags($content));
            $analysis['estimated_read_time'] = ceil($analysis['word_count'] / 200); // 200 words per minute
            
            // Analyze content quality
            $analysis['quality_score'] = $this->calculateQualityScore($content);
            $analysis['technical_depth'] = $this->assessTechnicalDepth($content);
            $analysis['characteristics'] = $this->extractCharacteristics($content);
        }
        
        // WordPress-specific analysis
        if ($content_data['is_local'] ?? false) {
            $analysis = array_merge($analysis, [
                'title' => $content_data['title'] ?? '',
                'author' => $content_data['author'] ?? '',
                'publish_date' => $content_data['publish_date'] ?? '',
                'categories' => $content_data['categories'] ?? [],
                'tags' => $content_data['tags'] ?? [],
                'post_type' => $content_data['post_type'] ?? 'post'
            ]);
            
            // Enhanced analysis for local content
            $analysis['seo_optimized'] = $this->checkSEOOptimization($content_data);
            $analysis['engagement_potential'] = $this->assessEngagementPotential($content_data);
        }
        
        // URL-based analysis
        $url_analysis = $this->analyzeUrl($url);
        if ($url_analysis['content_type'] !== 'unknown') {
            $analysis['content_type'] = $url_analysis['content_type'];
        }
        $analysis['characteristics'] = array_merge(
            $analysis['characteristics'], 
            $url_analysis['characteristics']
        );
        
        return $analysis;
    }
    
    /**
     * Calculate content quality score (0-100)
     */
    private function calculateQualityScore($content) {
        $score = 50; // Base score
        $content_lower = strtolower($content);
        
        // Research quality indicators
        foreach ($this->quality_indicators['research_keywords'] as $keyword) {
            if (strpos($content_lower, $keyword) !== false) {
                $score += 3;
            }
        }
        
        // Technical quality indicators
        foreach ($this->quality_indicators['technical_keywords'] as $keyword) {
            if (strpos($content_lower, $keyword) !== false) {
                $score += 2;
            }
        }
        
        // Structure indicators
        if (preg_match('/<h[1-6][^>]*>/i', $content)) {
            $score += 5; // Has headings
        }
        if (preg_match('/<ul|<ol/i', $content)) {
            $score += 3; // Has lists
        }
        if (preg_match('/<img[^>]+alt=/i', $content)) {
            $score += 4; // Has images with alt text
        }
        
        // Links and references
        $external_links = preg_match_all('/<a[^>]+href=["\']https?:\/\/[^"\']+/i', $content);
        if ($external_links > 0) {
            $score += min($external_links * 2, 10); // Max 10 points for links
        }
        
        // Length bonus
        $word_count = str_word_count(strip_tags($content));
        if ($word_count > 1000) $score += 5;
        if ($word_count > 2000) $score += 5;
        if ($word_count > 5000) $score += 10;
        
        return min($score, 100);
    }
    
    /**
     * Assess technical depth level
     */
    private function assessTechnicalDepth($content) {
        $content_lower = strtolower($content);
        $technical_score = 0;
        
        // Technical terms
        $advanced_terms = [
            'algorithm', 'implementation', 'architecture', 'scalability',
            'optimization', 'performance', 'security', 'encryption',
            'machine learning', 'artificial intelligence', 'neural network',
            'api', 'rest', 'graphql', 'microservices', 'containerization'
        ];
        
        foreach ($advanced_terms as $term) {
            if (strpos($content_lower, $term) !== false) {
                $technical_score += 2;
            }
        }
        
        // Code blocks
        if (preg_match('/<code|<pre|```/i', $content)) {
            $technical_score += 10;
        }
        
        // Mathematical expressions
        if (preg_match('/\$.*\$|\\\[.*\\\]/', $content)) {
            $technical_score += 8;
        }
        
        if ($technical_score >= 20) return 'expert';
        if ($technical_score >= 12) return 'advanced';
        if ($technical_score >= 6) return 'intermediate';
        return 'basic';
    }
    
    /**
     * Extract content characteristics
     */
    private function extractCharacteristics($content) {
        $characteristics = [];
        
        foreach ($this->high_value_patterns as $type => $pattern_data) {
            foreach ($pattern_data['patterns'] as $pattern) {
                if (preg_match($pattern, $content)) {
                    $characteristics[] = $type;
                    break; // Only add once per type
                }
            }
        }
        
        return array_unique($characteristics);
    }
    
    /**
     * Check SEO optimization
     */
    private function checkSEOOptimization($content_data) {
        $score = 0;
        
        // Title optimization
        $title = $content_data['title'] ?? '';
        if (strlen($title) >= 30 && strlen($title) <= 60) {
            $score += 20;
        }
        
        // Content length
        $word_count = $content_data['word_count'] ?? 0;
        if ($word_count >= 300) {
            $score += 20;
        }
        
        // Has categories/tags
        if (!empty($content_data['categories']) || !empty($content_data['tags'])) {
            $score += 15;
        }
        
        // Has excerpt
        if (!empty($content_data['excerpt'])) {
            $score += 10;
        }
        
        return $score >= 40 ? 'optimized' : 'basic';
    }
    
    /**
     * Assess engagement potential
     */
    private function assessEngagementPotential($content_data) {
        $score = 0;
        $content = $content_data['content'] ?? '';
        
        // Interactive elements
        if (strpos($content, 'comment') !== false) $score += 10;
        if (strpos($content, 'share') !== false) $score += 10;
        if (preg_match('/<form/i', $content)) $score += 15;
        
        // Media richness
        if (preg_match('/<img/i', $content)) $score += 10;
        if (preg_match('/<video/i', $content)) $score += 20;
        
        // Content structure
        if (preg_match('/<h[1-6]/i', $content)) $score += 10;
        if (preg_match('/<ul|<ol/i', $content)) $score += 5;
        
        if ($score >= 40) return 'high';
        if ($score >= 20) return 'medium';
        return 'low';
    }
    
    /**
     * Get basic analysis for unknown content
     */
    private function getBasicAnalysis($url) {
        $url_analysis = $this->analyzeUrl($url);
        
        return [
            'content_type' => $url_analysis['content_type'],
            'word_count' => 500, // Estimated
            'characteristics' => $url_analysis['characteristics'],
            'quality_score' => 50, // Default
            'technical_depth' => 'basic',
            'commercial_value' => 'medium',
            'metadata' => [
                'analysis_method' => 'url_only',
                'confidence' => 'low'
            ]
        ];
    }
    
    /**
     * Batch analyze multiple URLs
     */
    public function batchAnalyze($urls) {
        $results = [];
        
        foreach ($urls as $url) {
            $results[$url] = $this->analyzeContent($url);
        }
        
        return $results;
    }
    
    /**
     * Get content analysis summary for dashboard
     */
    public function getAnalysisSummary($analyses) {
        $summary = [
            'total_analyzed' => count($analyses),
            'content_types' => [],
            'avg_quality_score' => 0,
            'high_value_content' => 0,
            'technical_content' => 0,
            'research_content' => 0
        ];
        
        $total_quality = 0;
        
        foreach ($analyses as $analysis) {
            // Content type distribution
            $type = $analysis['content_type'] ?? 'unknown';
            $summary['content_types'][$type] = ($summary['content_types'][$type] ?? 0) + 1;
            
            // Quality metrics
            $quality = $analysis['quality_score'] ?? 50;
            $total_quality += $quality;
            
            if ($quality >= 80) {
                $summary['high_value_content']++;
            }
            
            if (in_array($analysis['technical_depth'] ?? 'basic', ['advanced', 'expert'])) {
                $summary['technical_content']++;
            }
            
            if (in_array('original_research', $analysis['characteristics'] ?? [])) {
                $summary['research_content']++;
            }
        }
        
        $summary['avg_quality_score'] = $summary['total_analyzed'] > 0 
            ? round($total_quality / $summary['total_analyzed'], 1) 
            : 0;
        
        return $summary;
    }
    
    /**
     * Clear analysis cache
     */
    public function clearCache() {
        $this->analysis_cache = [];
    }
}
?>