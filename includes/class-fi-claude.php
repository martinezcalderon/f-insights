<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FI_Claude
 * Central wrapper for all Anthropic API calls.
 *
 * Every Claude call in the plugin goes through FI_Claude::request().
 * This keeps API version headers, error handling, JSON parsing, and
 * token tracking in one place. Changing the API version, adding a beta
 * header, or modifying cost tracking only ever requires touching this file.
 *
 * Usage:
 *   $result = FI_Claude::request( $prompt, [
 *       'system'     => 'You are a ...', // optional system prompt
 *       'max_tokens' => 3000,            // default 1000
 *       'timeout'    => 90,              // default 60
 *       'scan_id'    => $scan_id,        // for log tracing
 *   ] );
 *
 *   On success: returns the text string from content[0].text
 *   On failure: returns WP_Error
 */
class FI_Claude {

    /**
     * Send a single-turn request to the Anthropic Messages API.
     *
     * @param string $prompt   The user message content.
     * @param array  $options  Optional overrides: system, max_tokens, timeout, scan_id.
     * @return string|WP_Error  Text response or WP_Error on failure.
     */
    public static function request( string $prompt, array $options = [] ) {
        $key = get_option( 'fi_claude_api_key', '' );

        if ( ! $key ) {
            return new WP_Error( 'no_key', 'Claude API key not configured.' );
        }

        $scan_id    = $options['scan_id']    ?? '';
        $max_tokens = $options['max_tokens'] ?? 1000;
        $timeout    = $options['timeout']    ?? 60;
        $system     = $options['system']     ?? '';

        // Callers can pass an explicit model string (e.g. admin calls pass the
        // admin model option). Falls back to the report model, then the legacy
        // fi_claude_model option for installs that pre-date the split selector.
        $model = $options['model']
            ?? get_option( 'fi_claude_model_report', get_option( 'fi_claude_model', 'claude-haiku-4-5-20251001' ) );

        $body = [
            'model'      => $model,
            'max_tokens' => $max_tokens,
            'messages'   => [ [ 'role' => 'user', 'content' => $prompt ] ],
        ];
        if ( $system ) {
            $body['system'] = $system;
        }

        FI_Logger::api( 'Claude request start', [ 'model' => $model, 'max_tokens' => $max_tokens ], $scan_id );

        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
            'timeout' => $timeout,
            'headers' => [
                'x-api-key'         => $key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            FI_Logger::error( 'Claude request failed: ' . $response->get_error_message(), [], $scan_id );
            return $response;
        }

        $code    = wp_remote_retrieve_response_code( $response );
        $payload = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            $msg        = $payload['error']['message'] ?? "HTTP $code";
            $error_type = $payload['error']['type']    ?? '';

            // 429 = Anthropic rate limit. Propagate a tagged WP_Error so callers
            // (bulk scan runner) can distinguish this from a hard failure and
            // implement backoff + retry rather than permanently failing the item.
            if ( $code === 429 ) {
                // Honour the Retry-After header if present (Anthropic returns this).
                $retry_after = (int) ( wp_remote_retrieve_header( $response, 'retry-after' ) ?: 60 );
                FI_Logger::warn( "Claude rate limited (429). Retry-After: {$retry_after}s", [], $scan_id );
                return new WP_Error( 'claude_rate_limited', $msg, [ 'retry_after' => $retry_after ] );
            }

            // 529 = Anthropic overloaded. Treat like a soft rate limit.
            if ( $code === 529 || $code === 503 ) {
                FI_Logger::warn( "Claude overloaded ($code). Will retry.", [], $scan_id );
                return new WP_Error( 'claude_overloaded', $msg, [ 'retry_after' => 30 ] );
            }

            FI_Logger::error( "Claude HTTP $code: $msg", [ 'type' => $error_type ], $scan_id );
            return new WP_Error( 'claude_error', $msg );
        }

        $text  = $payload['content'][0]['text'] ?? '';
        $usage = $payload['usage'] ?? [];

        FI_Logger::api( 'Claude request complete', [ 'tokens' => $usage ], $scan_id );
        self::track_usage( $usage );

        // Allow callers to retrieve token counts alongside the text response by
        // passing 'return_usage' => true in $options. Returns [ 'text' => string,
        // 'tokens' => int ] instead of a plain string. Opt-in to stay backwards
        // compatible — all existing callers that expect a string are unaffected.
        if ( ! empty( $options['return_usage'] ) ) {
            $total_tokens = (int) ( $usage['input_tokens'] ?? 0 ) + (int) ( $usage['output_tokens'] ?? 0 );
            return [ 'text' => $text, 'tokens' => $total_tokens ];
        }

        return $text;
    }

    /**
     * Atomically increment token and call counters.
     *
     * Uses direct SQL UPDATE with arithmetic so concurrent requests
     * can't overwrite each other's counts (read-modify-write race condition).
     *
     * @param array $usage  Anthropic usage object: { input_tokens, output_tokens }
     */
    public static function track_usage( array $usage ): void {
        global $wpdb;

        $in  = (int) ( $usage['input_tokens']  ?? 0 );
        $out = (int) ( $usage['output_tokens'] ?? 0 );

        if ( ! $in && ! $out ) return;

        // Ensure the options exist before incrementing (avoids creating them as autoloaded)
        foreach ( [ 'fi_tokens_input', 'fi_tokens_output', 'fi_api_calls' ] as $key ) {
            if ( get_option( $key ) === false ) {
                add_option( $key, 0, '', 'no' );
            }
        }

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->options}
             SET option_value = CASE
                 WHEN option_name = 'fi_tokens_input'  THEN option_value + %d
                 WHEN option_name = 'fi_tokens_output' THEN option_value + %d
                 WHEN option_name = 'fi_api_calls'     THEN option_value + 1
             END
             WHERE option_name IN ('fi_tokens_input','fi_tokens_output','fi_api_calls')",
            $in,
            $out
        ) );

        update_option( 'fi_tokens_updated', current_time( 'mysql' ) );
    }

    /**
     * Test a key with a minimal API call. Does not track usage.
     *
     * @param string $key  The API key to test.
     * @return array  [ 'ok' => bool, 'message' => string ]
     */
    public static function test_key( string $key ): array {
        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
            'timeout' => 15,
            'headers' => [
                'x-api-key'         => $key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ],
            'body' => wp_json_encode( [
                'model'      => 'claude-haiku-4-5-20251001',
                'max_tokens' => 10,
                'messages'   => [ [ 'role' => 'user', 'content' => 'Say OK' ] ],
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'ok' => false, 'message' => $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 200 ) {
            return [ 'ok' => true, 'message' => 'Connected' ];
        }

        return [ 'ok' => false, 'message' => $body['error']['message'] ?? "HTTP $code" ];
    }

    /**
     * Alias used by FI_Ajax::handle_test_claude().
     */
    public static function test_connection( string $key ): array {
        return self::test_key( $key );
    }
}
