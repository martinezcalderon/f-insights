<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FI_Grader
 * Sends raw Google Places + website data to Claude and gets back
 * a fully structured, scored report.
 */
class FI_Grader {

    /**
     * Run the full AI grading pass.
     *
     * @param array  $scan_data  Merged output: place details + website health + competitors
     * @param string $scan_id    8-char scan ID for log tracing
     * @return array|WP_Error    Structured report or WP_Error
     */
    public static function grade( array $scan_data, string $scan_id = '' ) {
        $prompt = self::build_prompt( $scan_data );

        FI_Logger::api( 'Claude grade call start', [ 'business' => $scan_data['name'] ?? '' ], $scan_id );

        $raw = FI_Claude::request( $prompt, [
            'system'       => 'You are a local business intelligence analyst. Return ONLY valid JSON with no markdown, no code fences, no explanation. Your entire response must be parseable by json_decode().',
            'max_tokens'   => 6000,
            'timeout'      => 120,
            'scan_id'      => $scan_id,
            'return_usage' => true,
        ] );

        if ( is_wp_error( $raw ) ) {
            FI_Logger::error( 'Grader failed: ' . $raw->get_error_message(), [], $scan_id );
            return $raw;
        }

        // $raw is now [ 'text' => string, 'tokens' => int ] when return_usage is set.
        $tokens   = is_array( $raw ) ? (int) ( $raw['tokens'] ?? 0 ) : 0;
        $raw_text = is_array( $raw ) ? ( $raw['text'] ?? '' ) : $raw;

        $report = self::parse_response( $raw_text, $scan_id );
        if ( is_array( $report ) ) {
            $report['_tokens'] = $tokens; // carry tokens forward for bulk scan accounting
        }
        return $report;
    }

    /**
     * Build the structured Claude prompt.
     * Instructs Claude to return only valid JSON — no prose wrapper.
     */
    private static function build_prompt( array $d ): string {
        $json = wp_json_encode( $d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( ! $json ) {
            $json = '{}'; // fallback — Claude will produce a degraded but non-fatal response
        }

        return <<<PROMPT
You are a local business intelligence analyst. Analyze the following Google Business Profile data and generate a complete scored diagnostic report.

## Business Data
{$json}

## Instructions

Return ONLY a valid JSON object — no explanation, no markdown, no code fences. The JSON must exactly match this structure:

{
  "overall_score": <integer 0-100>,
  "categories": {
    "online_presence": {
      "score": <integer 0-100>,
      "headline": "<one short, specific sentence summarizing the finding>",
      "analysis": "<2-4 sentences of specific, actionable analysis referencing actual data>",
      "recommendations": ["<specific action>", "<specific action>", "<specific action>"]
    },
    "customer_reviews": {
      "score": <integer 0-100>,
      "headline": "<headline>",
      "analysis": "<2-4 sentences referencing actual ratings, review count, and specific themes from the review text provided>",
      "sentiment_summary": "<1-2 sentences on overall tone — what customers consistently praise or criticize>",
      "recommendations": ["<action>", "<action>", "<action>"]
    },
    "photos_media": {
      "score": <integer 0-100>,
      "headline": "<headline>",
      "analysis": "<analysis>",
      "recommendations": ["<action>", "<action>", "<action>"]
    },
    "business_information": {
      "score": <integer 0-100>,
      "headline": "<headline>",
      "analysis": "<analysis>",
      "recommendations": ["<action>", "<action>", "<action>"]
    },
    "competitive_position": {
      "score": <integer 0-100>,
      "headline": "<headline>",
      "analysis": "<2-4 sentences using competitor names, ratings, and review counts from the data. If industry.vague_match is true, note that the business's Google category may be too generic and explain why that matters for search visibility>",
      "category_context": "<1 sentence: confirm what industry category was used for the competitor search, e.g. 'Competitors were pulled from nearby Mexican restaurants within X miles'>",
      "recommendations": ["<action>", "<action>", "<action>"]
    },
    "website_performance": {
      "score": <integer 0-100>,
      "headline": "<headline>",
      "analysis": "<analysis>",
      "recommendations": ["<action>", "<action>", "<action>"]
    },
    "local_seo": {
      "score": <integer 0-100>,
      "headline": "<headline>",
      "analysis": "<analysis>",
      "recommendations": ["<action>", "<action>", "<action>"]
    },
    "pagespeed_insights": {
      "score": <integer 0-100>,
      "headline": "<one sentence summary>",
      "analysis": "<2-3 sentences in plain language — what do the scores mean for a customer loading the page?>",
      "recommendations": ["<actionable fix>", "<actionable fix>", "<actionable fix>"]
    }
  },
  "competitive_narrative": "<2-4 sentences comparing this business to its competitors by name, rating, and review count. Be direct about where they rank. If industry.vague_match is true, include a sentence explaining that the competitors shown may not be the most relevant because the Google Business category is too generic — and what that means for local search.>",
  "priority_actions": [
    {
      "title": "<short action title>",
      "description": "<1-2 sentences explaining the action and its impact>",
      "impact": "high|medium|low",
      "effort": "high|medium|low"
    }
  ]
}

## Scoring guide
- overall_score: weighted average of all categories (reviews + online_presence weight most)
- pagespeed_insights score: average of mobile and desktop performance scores from the data, or 50 if no data
- 80-100: strong, competitive
- 60-79: average, visible gaps
- 40-59: significant problems limiting growth
- 0-39: critical issues

## Rules
- Be specific. Reference actual data: exact ratings, review counts, competitor names, website findings, actual CWV values
- priority_actions: 4-6 items, ordered by impact/effort ratio (high impact + low effort first)
- If pagespeed data is missing or null, score pagespeed_insights at 50, note data unavailable, and skip CWV references
- If website data is missing or null, score website_performance at 50 and note data unavailable
- If no competitors found, score competitive_position based on profile completeness only
- For pagespeed_insights, write for a business owner in plain language. Explain CWV values humanly e.g. "your main image takes X seconds to appear"
- For customer_reviews: quote or paraphrase specific language from reviews_top and reviews_low if present. If reviews_top is empty, note that no positive reviews were available in the sample. If reviews_low is empty, note there were no low-rated reviews in the sample — which is either genuinely positive or reflects a small sample.
- For competitive_position: the industry.search_type_used field tells you what Google category was used to find competitors. If industry.vague_match is true, explain the category optimization opportunity clearly — this is a real, actionable finding.
- Return ONLY the JSON object. Nothing before or after it.
PROMPT;
    }

    /**
     * Parse and validate Claude's JSON response.
     * Falls back gracefully if JSON is malformed.
     */
    private static function parse_response( string $raw, string $scan_id = '' ): array {
        // Strip markdown fences if present
        $clean = preg_replace( '/^```(?:json)?\s*/m', '', $raw );
        $clean = preg_replace( '/```\s*$/m', '', $clean );
        $clean = trim( $clean );

        // Extract just the JSON object in case there's any prose before/after
        if ( preg_match( '/\{.*\}/s', $clean, $m ) ) {
            $clean = $m[0];
        }

        // Replace literal control characters inside JSON strings.
        // Claude occasionally emits real newlines/tabs inside string values
        // which causes JSON_ERROR_CTRL_CHAR. Sanitize them before decoding.
        $clean = preg_replace_callback(
            '/"(?:[^"\\\\]|\\\\.)*"/s',
            function ( $match ) {
                return preg_replace( '/[\x00-\x1F\x7F]/', ' ', $match[0] );
            },
            $clean
        );

        $data = json_decode( $clean, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            FI_Logger::error( 'Claude JSON parse failed: ' . json_last_error_msg(), [ 'raw' => substr( $raw, 0, 200 ) ], $scan_id );
            return self::fallback_report();
        }

        // Validate required keys exist
        $required = [ 'overall_score', 'categories', 'competitive_narrative', 'priority_actions' ];
        foreach ( $required as $key ) {
            if ( ! isset( $data[ $key ] ) ) {
                FI_Logger::warn( "Claude response missing key: $key", [], $scan_id );
            }
        }

        // Clamp scores to 0-100
        if ( isset( $data['overall_score'] ) ) {
            $data['overall_score'] = max( 0, min( 100, (int) $data['overall_score'] ) );
        }
        if ( isset( $data['categories'] ) ) {
            foreach ( $data['categories'] as $cat => $val ) {
                if ( isset( $val['score'] ) ) {
                    $data['categories'][ $cat ]['score'] = max( 0, min( 100, (int) $val['score'] ) );
                }
            }
        }

        return $data;
    }

    /**
     * Fallback report when Claude fails or returns bad JSON.
     * Returns a neutral report so the page doesn't break.
     * Marked with _error:true so the frontend can show appropriate context.
     */
    private static function fallback_report(): array {
        $stub = [
            'score'           => 50,
            'headline'        => 'Analysis incomplete',
            'analysis'        => 'The AI analysis for this section did not complete. The raw data above (scores, metrics, competitor list) is still accurate; only the written interpretation is missing.',
            'recommendations' => [],
        ];

        $reviews_stub = array_merge( $stub, [
            'sentiment_summary' => '',
        ] );

        $competition_stub = array_merge( $stub, [
            'category_context' => '',
        ] );

        $pagespeed_stub = array_merge( $stub, [
            'headline'          => 'Page Speed data collected; interpretation incomplete',
            'analysis'          => 'The raw PageSpeed scores and Core Web Vitals above were successfully collected from Google. Only the written interpretation is missing due to an AI response error.',
            'mobile_summary'    => '',
            'desktop_summary'   => '',
            'doing_well'        => [],
            'needs_improvement' => [],
        ] );

        return [
            'overall_score'         => 50,
            'categories'            => [
                'online_presence'      => $stub,
                'customer_reviews'     => $reviews_stub,
                'photos_media'         => $stub,
                'business_information' => $stub,
                'competitive_position' => $competition_stub,
                'website_performance'  => $stub,
                'local_seo'            => $stub,
                'pagespeed_insights'   => $pagespeed_stub,
            ],
            'competitive_narrative' => '',
            'priority_actions'      => [],
            '_error'                => true,
        ];
    }

}
