<?php
/**
 * Business Grading Engine using Claude AI
 */

if (!defined('ABSPATH')) {
    exit;
}

class FI_Grader {
    
    private $api_key;
    private $model;

    /**
     * @param string $context  'scan' (public shortcode), 'internal' (admin preview), or 'intel' (market intelligence).
     *                         Determines which saved model option to use.
     */
    public function __construct( $context = 'scan' ) {
        $this->api_key = FI_Crypto::get_key( FI_Crypto::CLAUDE_KEY_OPTION );
        $defaults = array(
            'scan'     => 'claude-haiku-4-5-20251001',
            'internal' => 'claude-sonnet-4-20250514',
            'intel'    => 'claude-sonnet-4-20250514',
        );
        $option_keys = array(
            'scan'     => 'fi_claude_model_scan',
            'internal' => 'fi_claude_model_internal',
            'intel'    => 'fi_claude_model_intel',
        );
        $option_key  = $option_keys[ $context ] ?? 'fi_claude_model_scan';
        $default     = $defaults[ $context ]    ?? 'claude-haiku-4-5-20251001';
        // Fall back to legacy fi_claude_model if per-context option not yet saved.
        $this->model = get_option( $option_key, get_option( 'fi_claude_model', $default ) );
    }
    
    /**
     * Grade a business based on collected data
     */
    public function grade_business($business_data, $website_analysis) {
        if (empty($this->api_key)) {
            return new WP_Error('no_api_key', __('Claude API key not configured', 'f-insights'));
        }
        
        // Prepare data for Claude
        $prompt = $this->build_analysis_prompt($business_data, $website_analysis);
        
        // Call Claude API
        $response = $this->call_claude_api($prompt);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        // Parse Claude's response
        $analysis = $this->parse_claude_response($response);
        
        if (is_wp_error($analysis)) {
            return $analysis;
        }
        
        // Add raw scores
        $analysis['raw_scores'] = $this->calculate_raw_scores($business_data, $website_analysis);
        
        return $analysis;
    }
    
    /**
     * Build the analysis prompt for Claude
     */
    private function build_analysis_prompt($business_data, $website_analysis) {
        // Separate competitor data from business data so it can be summarised
        // cleanly for Claude without dumping the full nested array into the prompt.
        $competitors    = $business_data['competitors'] ?? array();
        $business_clean = $business_data;
        unset($business_clean['competitors']); // keep prompt focused

        $business_json = json_encode($business_clean, JSON_PRETTY_PRINT);
        $website_json  = json_encode($website_analysis, JSON_PRETTY_PRINT);

        // Build a compact competitor summary for the prompt
        $comp_lines = array();
        foreach ($competitors as $i => $comp) {
            $idx       = $i + 1;
            $name      = $comp['name'] ?? 'Unknown';
            $rating    = $comp['rating'] ?? 'N/A';
            $reviews   = isset($comp['user_ratings_total']) ? number_format($comp['user_ratings_total']) . ' reviews' : 'no review count';
            $distance  = isset($comp['distance_miles']) ? round($comp['distance_miles'], 1) . ' mi away' : '';
            $comp_lines[] = "{$idx}. {$name} — {$rating}★ ({$reviews})" . ($distance ? ", {$distance}" : '');
        }
        $comp_summary = empty($comp_lines)
            ? 'No nearby competitors were found.'
            : implode("\n", $comp_lines);

        $business_rating  = $business_clean['rating'] ?? 'N/A';
        $business_reviews = isset($business_clean['user_ratings_total'])
            ? number_format($business_clean['user_ratings_total'])
            : '0';
        $business_name    = $business_clean['name'] ?? 'This business';

        return <<<PROMPT
You are an expert business consultant analyzing a local business's online presence. Review the following data and provide actionable insights.

BUSINESS DATA:
{$business_json}

WEBSITE ANALYSIS:
{$website_json}

NEARBY COMPETITORS (same cuisine/category, within 5 miles):
{$comp_summary}

The subject business is {$business_name} with a rating of {$business_rating}★ ({$business_reviews} reviews).

Please analyze this business and provide a comprehensive report in JSON format with the following structure:

{
  "overall_score": <number 0-100>,
  "category": "<primary business category>",
  "competitive_narrative": "<2-3 plain-English sentences that explain what the competitive landscape actually means for this specific business. Reference the competitors by name. Be direct and specific — e.g. 'You're up against Tamashaa (4.8★, 1.4k reviews) and Bombay Street Food (4.3★). Your 3.9★ puts you behind both on rating, but your 936 reviews show real community engagement — the gap is closeable.' Speak directly to the owner.>",
  "insights": {
    "online_presence": {
      "score": <number 0-100>,
      "status": "<good|warning|alert>",
      "headline": "<short headline>",
      "summary": "<2-3 sentence analysis>",
      "recommendations": ["<actionable recommendation 1>", "<actionable recommendation 2>"]
    },
    "customer_reviews": {
      "score": <number 0-100>,
      "status": "<good|warning|alert>",
      "headline": "<short headline>",
      "summary": "<2-3 sentence analysis>",
      "recommendations": ["<actionable recommendation 1>", "<actionable recommendation 2>"]
    },
    "photos_media": {
      "score": <number 0-100>,
      "status": "<good|warning|alert>",
      "headline": "<short headline>",
      "summary": "<2-3 sentence analysis>",
      "recommendations": ["<actionable recommendation 1>", "<actionable recommendation 2>"]
    },
    "business_information": {
      "score": <number 0-100>,
      "status": "<good|warning|alert>",
      "headline": "<short headline>",
      "summary": "<2-3 sentence analysis>",
      "recommendations": ["<actionable recommendation 1>", "<actionable recommendation 2>"]
    },
    "competitive_position": {
      "score": <number 0-100>,
      "status": "<good|warning|alert>",
      "headline": "<short headline>",
      "summary": "<2-3 sentence analysis>",
      "recommendations": ["<actionable recommendation 1>", "<actionable recommendation 2>"]
    },
    "website_performance": {
      "score": <number 0-100>,
      "status": "<good|warning|alert>",
      "headline": "<short headline>",
      "summary": "<2-3 sentence analysis>",
      "recommendations": ["<actionable recommendation 1>", "<actionable recommendation 2>"]
    }
  },
  "priority_actions": [
    {
      "title": "<action title>",
      "description": "<what to do>",
      "impact": "<high|medium|low>",
      "effort": "<low|medium|high>"
    }
  ],
  "strengths": ["<strength 1>", "<strength 2>", "<strength 3>"],
  "sentiment_analysis": {
    "overall_sentiment": "<positive|neutral|negative>",
    "common_themes": ["<theme 1>", "<theme 2>"],
    "customer_pain_points": ["<pain point 1>", "<pain point 2>"]
  }
}

GRADING CRITERIA:
- Overall Score: Weighted average of all category scores
- Status levels: "good" (80-100), "warning" (60-79), "alert" (0-59)
- competitive_narrative: must reference real competitor names and real numbers from the data; no generic filler
- Be specific, actionable, and humanized in your recommendations
- Focus on practical improvements the business can implement
- Consider industry benchmarks and local competition
- Analyze review sentiment and themes
- Evaluate completeness of business information
- Assess visual appeal and quantity of photos
- Speak in the first-person as if you're speaking one-on-one

Return ONLY the JSON object, no additional text.
PROMPT;
    }
    
    /**
     * Call Claude API with exponential backoff retry on transient errors.
     *
     * Retries up to 3 times (delays: 2 s, 4 s, 8 s) for:
     *   - WordPress HTTP transport errors (connection timeout, DNS failure, etc.)
     *   - HTTP 429 Too Many Requests
     *   - HTTP 500 / 502 / 503 / 529 server-side errors
     *
     * Does NOT retry on:
     *   - HTTP 400 Bad Request  (our prompt is malformed)
     *   - HTTP 401 / 403        (bad API key — retrying won't help)
     *
     * @param  string $prompt
     * @param  int    $attempt  Zero-based attempt counter (internal, for recursion).
     * @return string|WP_Error  Raw response text or WP_Error on failure.
     */
    private function call_claude_api( $prompt, $attempt = 0 ) {
        $url = 'https://api.anthropic.com/v1/messages';

        $body = array(
            'model' => $this->model,
            'max_tokens' => 4096,
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $prompt,
                )
            ),
        );

        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-api-key' => $this->api_key,
                'anthropic-version' => '2023-06-01',
            ),
            'body' => json_encode($body),
            'timeout' => 60,
        ));

        $max_attempts = 3; // up to 3 retries (4 total attempts)

        if ( is_wp_error( $response ) ) {
            // Network / transport failure — retry with backoff.
            if ( $attempt < $max_attempts ) {
                $delay = pow( 2, $attempt + 1 ); // 2, 4, 8 seconds
                FI_Logger::warning( 'Claude API transport error, retrying', array(
                    'attempt' => $attempt + 1,
                    'delay_s' => $delay,
                    'error'   => $response->get_error_message(),
                ) );
                sleep( $delay );
                return $this->call_claude_api( $prompt, $attempt + 1 );
            }
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);

        // Retryable HTTP status codes (rate-limited or transient server errors).
        $retryable_codes = array( 429, 500, 502, 503, 529 );
        if ( in_array( $response_code, $retryable_codes, true ) && $attempt < $max_attempts ) {
            $delay = pow( 2, $attempt + 1 ); // 2, 4, 8 seconds
            FI_Logger::warning( 'Claude API retryable error, retrying', array(
                'attempt'       => $attempt + 1,
                'delay_s'       => $delay,
                'response_code' => $response_code,
            ) );
            sleep( $delay );
            return $this->call_claude_api( $prompt, $attempt + 1 );
        }

        if ($response_code !== 200) {
            $error_body = wp_remote_retrieve_body($response);
            FI_Logger::error('Claude API error', array('code' => $response_code, 'body' => substr($error_body, 0, 500)));
            return new WP_Error('claude_api_error', __('Claude API returned an error', 'f-insights'));
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (empty($data['content'][0]['text'])) {
            FI_Logger::error('Claude API returned empty response', array('data' => $data));
            return new WP_Error('no_response', __('No response from Claude API', 'f-insights'));
        }

        // Log token usage for cost visibility. The usage object is always present
        // on successful responses. We store it as a transient-like option so the
        // Analytics dashboard can surface estimated monthly cost without a schema change.
        $input_tokens  = intval( $data['usage']['input_tokens']  ?? 0 );
        $output_tokens = intval( $data['usage']['output_tokens'] ?? 0 );
        if ( $input_tokens > 0 || $output_tokens > 0 ) {
            FI_Logger::info( 'Claude token usage', array(
                'model'         => $this->model,
                'input_tokens'  => $input_tokens,
                'output_tokens' => $output_tokens,
            ) );
            // Accumulate a running monthly total (YYYY-MM key) for the analytics dashboard.
            $month_key = 'fi_token_usage_' . gmdate( 'Y_m' );
            $current   = get_option( $month_key, array( 'input' => 0, 'output' => 0, 'scans' => 0, 'by_model' => array() ) );
            // Accumulate per-model buckets so the Analytics dashboard can compute
            // accurate costs using each model's actual pricing rather than a
            // hardcoded Sonnet baseline.
            $model_slug = $this->model ?? 'unknown';
            $by_model   = isset( $current['by_model'] ) ? $current['by_model'] : array();
            if ( ! isset( $by_model[ $model_slug ] ) ) {
                $by_model[ $model_slug ] = array( 'input' => 0, 'output' => 0, 'scans' => 0 );
            }
            $by_model[ $model_slug ]['input']  += $input_tokens;
            $by_model[ $model_slug ]['output'] += $output_tokens;
            $by_model[ $model_slug ]['scans']  += 1;
            update_option( $month_key, array(
                'input'    => $current['input']  + $input_tokens,
                'output'   => $current['output'] + $output_tokens,
                'scans'    => $current['scans']  + 1,
                'by_model' => $by_model,
            ), false ); // false = don't autoload every page load
        }

        return $data['content'][0]['text'];
    }
    
    /**
     * Parse Claude's JSON response
     */
    private function parse_claude_response($response) {
        // Remove any markdown code blocks if present
        $response = preg_replace('/```json\s*|\s*```/', '', $response);
        $response = trim($response);
        
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            FI_Logger::error('Claude JSON parse error', array('error' => json_last_error_msg(), 'response_preview' => substr($response, 0, 300)));
            return new WP_Error('parse_error', __('Could not parse AI response', 'f-insights'));
        }
        
        return $data;
    }
    
    /**
     * Calculate raw scores based on business data
     */
    private function calculate_raw_scores($business_data, $website_analysis) {
        $scores = array();
        
        // Reviews score
        $review_count = $business_data['user_ratings_total'] ?? 0;
        $rating = $business_data['rating'] ?? 0;
        
        if ($review_count >= 100 && $rating >= 4.5) {
            $scores['reviews'] = 100;
        } elseif ($review_count >= 50 && $rating >= 4.0) {
            $scores['reviews'] = 80;
        } elseif ($review_count >= 20 && $rating >= 3.5) {
            $scores['reviews'] = 60;
        } elseif ($review_count >= 5) {
            $scores['reviews'] = 40;
        } else {
            $scores['reviews'] = 20;
        }
        
        // Photos score
        $photo_count = count($business_data['photos'] ?? array());
        if ($photo_count >= 20) {
            $scores['photos'] = 100;
        } elseif ($photo_count >= 10) {
            $scores['photos'] = 75;
        } elseif ($photo_count >= 5) {
            $scores['photos'] = 50;
        } else {
            $scores['photos'] = 25;
        }
        
        // Business info completeness
        $completeness = 0;
        $fields = array('name', 'address', 'phone', 'website', 'opening_hours');
        foreach ($fields as $field) {
            if (!empty($business_data[$field])) {
                $completeness += 20;
            }
        }
        $scores['business_info'] = $completeness;
        
        // Website score
        if (!empty($business_data['website']) && !empty($website_analysis['accessible'])) {
            $website_score = 50; // Base score for having a working website
            if ($website_analysis['has_ssl']) $website_score += 20;
            if ($website_analysis['has_mobile_viewport']) $website_score += 20;
            if ($website_analysis['load_time'] < 3) $website_score += 10;
            $scores['website'] = min(100, $website_score);
        } else {
            $scores['website'] = empty($business_data['website']) ? 0 : 25;
        }
        
        return $scores;
    }
    
    /**
     * Determine business category from types
     */
    public function categorize_business($types) {
        // Map Google Place types to our categories
        $category_map = include FI_PLUGIN_DIR . 'includes/category-map.php';
        
        foreach ($types as $type) {
            if (isset($category_map[$type])) {
                return $category_map[$type];
            }
        }
        
        return 'General Business';
    }
}
