<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FI_Rate_Limiter
 * Fixed-window rate limiting per IP using WordPress transients.
 * Settings: fi_rate_limit_enabled, fi_rate_limit_max, fi_rate_limit_window (seconds)
 *
 * Fixed window (not sliding): once a visitor starts their window,
 * the counter resets only after the full window expires — not on each scan.
 */
class FI_Rate_Limiter {

    public static function is_limited( string $ip ): bool {
        if ( ! get_option( 'fi_rate_limit_enabled', false ) ) return false;
        if ( self::is_excluded( $ip ) ) return false;

        $max   = (int) get_option( 'fi_rate_limit_max', 3 );
        $count = (int) get_transient( self::transient_key( $ip ) );

        return $count >= $max;
    }

    public static function increment( string $ip ): void {
        if ( self::is_excluded( $ip ) ) return;

        $window  = (int) get_option( 'fi_rate_limit_window', 3600 );
        $tkey    = self::transient_key( $ip );
        $count   = (int) get_transient( $tkey );

        if ( $count === 0 ) {
            // First scan in this window — establish it with the full TTL
            set_transient( $tkey, 1, $window );
            return;
        }

        // Increment while preserving the remaining TTL (fixed window)
        $timeout_key   = '_transient_timeout_' . $tkey;
        $expires_at    = (int) get_option( $timeout_key );
        $remaining_ttl = max( 1, $expires_at - time() );

        set_transient( $tkey, $count + 1, $remaining_ttl );
    }

    public static function is_excluded( string $ip ): bool {
        // Cache the list for the lifetime of this request — is_excluded() is
        // called twice per scan (once in is_limited(), once in increment()), and
        // get_option() on a non-autoloaded option hits the DB each time.
        static $list = null;
        if ( $list === null ) {
            $excluded = get_option( 'fi_excluded_ips', '' );
            $list     = array_filter( array_map( 'trim', explode( "\n", $excluded ) ) );
        }
        return in_array( $ip, $list, true );
    }

    public static function get_client_ip(): string {
        // Trust proxy/CDN forwarding headers when either:
        //   (a) FI_TRUST_PROXY_HEADERS constant is true in wp-config.php, or
        //   (b) the admin has enabled "Trust proxy headers" in Settings → Rate Limiting.
        //
        // Only enable when the server sits behind a trusted reverse proxy (Cloudflare,
        // load balancer) that strips and rewrites these headers before forwarding.
        // Enabling on an unprotected server lets visitors spoof any IP.
        $trust = ( defined( 'FI_TRUST_PROXY_HEADERS' ) && FI_TRUST_PROXY_HEADERS )
              || (bool) get_option( 'fi_trust_proxy_headers', false );

        if ( $trust ) {
            $headers = [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP' ];
            foreach ( $headers as $h ) {
                if ( ! empty( $_SERVER[ $h ] ) ) {
                    $ip = trim( explode( ',', $_SERVER[ $h ] )[0] );
                    if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                        return $ip;
                    }
                }
            }
        }

        // Fall back to REMOTE_ADDR without private range check (handles localhost dev)
        return trim( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
    }

    public static function get_remaining( string $ip ): int {
        $max   = (int) get_option( 'fi_rate_limit_max', 3 );
        $count = (int) get_transient( self::transient_key( $ip ) );
        return max( 0, $max - $count );
    }

    private static function transient_key( string $ip ): string {
        return 'fi_rl_' . md5( $ip );
    }
}