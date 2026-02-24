<?php
/**
 * Encryption helper for sensitive options (API keys).
 *
 * Uses AES-256-CBC via openssl with WordPress's SECURE_AUTH_KEY as the
 * secret. Falls back gracefully when openssl is unavailable, so the plugin
 * still works — it just won't encrypt. A notice is shown in that case.
 *
 * Existing plaintext keys are detected automatically and migrated to
 * encrypted storage the first time they are re-saved via the settings form.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FI_Crypto {

    /**
     * Option names that hold encrypted API keys.
     */
    const GOOGLE_KEY_OPTION = 'fi_google_api_key';
    const CLAUDE_KEY_OPTION = 'fi_claude_api_key';

    /**
     * Prefix we prepend to every encrypted value so we can tell it apart
     * from a plaintext key that was saved before this class existed.
     */
    const ENCRYPTED_PREFIX = 'fi_enc::';

    const CIPHER = 'AES-256-CBC';

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Retrieve and decrypt an API key option.
     *
     * @param  string $option_name  e.g. FI_Crypto::GOOGLE_KEY_OPTION
     * @return string               Plaintext key, or '' if not set.
     */
    public static function get_key( string $option_name ): string {
        $raw = get_option( $option_name, '' );

        if ( $raw === '' ) {
            return '';
        }

        // If the value starts with our prefix it was encrypted by us.
        // strpos used instead of str_starts_with() to maintain PHP 7.4 compatibility.
        if ( strpos( $raw, self::ENCRYPTED_PREFIX ) === 0 ) {
            return self::decrypt( substr( $raw, strlen( self::ENCRYPTED_PREFIX ) ) );
        }

        // Legacy plaintext key — return as-is.
        // It will be encrypted the next time the admin saves settings.
        return $raw;
    }

    /**
     * Encrypt and save an API key option.
     *
     * @param string $option_name  e.g. FI_Crypto::GOOGLE_KEY_OPTION
     * @param string $plaintext    The raw API key to store.
     */
    public static function save_key( string $option_name, string $plaintext ): void {
        if ( $plaintext === '' ) {
            update_option( $option_name, '' );
            return;
        }

        if ( ! self::openssl_available() ) {
            // Store plaintext with a warning logged — better than losing the key.
            FI_Logger::warning( 'openssl not available — API key stored without encryption', [ 'option' => $option_name ] );
            update_option( $option_name, $plaintext );
            return;
        }

        $encrypted = self::encrypt( $plaintext );
        update_option( $option_name, self::ENCRYPTED_PREFIX . $encrypted );
    }

    /**
     * Returns true if openssl with our cipher is available on this server.
     */
    public static function openssl_available(): bool {
        return function_exists( 'openssl_encrypt' )
            && in_array( self::CIPHER, openssl_get_cipher_methods(), true );
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Encrypt a string. Returns base64-encoded iv::ciphertext.
     */
    private static function encrypt( string $plaintext ): string {
        $iv  = openssl_random_pseudo_bytes( openssl_cipher_iv_length( self::CIPHER ) );
        $enc = openssl_encrypt( $plaintext, self::CIPHER, self::get_secret(), 0, $iv );

        // Store iv alongside the ciphertext so we can decrypt later.
        return base64_encode( $iv . '::' . $enc );
    }

    /**
     * Decrypt a base64-encoded iv::ciphertext string.
     * Returns '' on any failure so the plugin degrades gracefully.
     *
     * Tries the current (binary) key first. If that fails, falls back to the
     * legacy hex-substring key used in versions prior to the binary key change,
     * so that any keys encrypted under the old scheme can still be read.
     */
    private static function decrypt( string $encoded ): string {
        if ( ! self::openssl_available() ) {
            return '';
        }

        $decoded = base64_decode( $encoded, true );
        if ( $decoded === false ) {
            FI_Logger::error( 'FI_Crypto: base64 decode failed — key may be corrupted' );
            return '';
        }

        $parts = explode( '::', $decoded, 2 );
        if ( count( $parts ) !== 2 ) {
            FI_Logger::error( 'FI_Crypto: unexpected ciphertext format' );
            return '';
        }

        [ $iv, $ciphertext ] = $parts;

        // Try primary (binary) key.
        $plaintext = openssl_decrypt( $ciphertext, self::CIPHER, self::get_secret(), 0, $iv );

        if ( $plaintext === false ) {
            // Primary key failed — try the legacy hex-substring key used before
            // the binary key change. This handles any keys that were encrypted
            // with the old scheme so admins don't lose their stored credentials.
            $plaintext = openssl_decrypt( $ciphertext, self::CIPHER, self::get_legacy_secret(), 0, $iv );
        }

        if ( $plaintext === false ) {
            FI_Logger::error( 'FI_Crypto: decryption failed — SECURE_AUTH_KEY may have changed' );
            return '';
        }

        return $plaintext;
    }

    /**
     * Derive a 32-byte key from WordPress's SECURE_AUTH_KEY constant.
     *
     * If the constant is not defined (rare, usually a misconfigured wp-config)
     * we fall back to a site-specific value so the key is never empty.
     */
    private static function get_secret(): string {
        $base = defined( 'SECURE_AUTH_KEY' ) && SECURE_AUTH_KEY !== ''
            ? SECURE_AUTH_KEY
            : AUTH_KEY ?? get_bloginfo( 'url' );

        // hash() with $raw_output = true returns 32 raw binary bytes for sha256,
        // which is exactly the key length AES-256 requires — no substr() needed.
        return hash( 'sha256', $base, true );
    }

    /**
     * Legacy key derivation used before the binary key change.
     * Kept only to decrypt values encrypted under the old scheme.
     * New encryptions always use get_secret() (binary output).
     */
    private static function get_legacy_secret(): string {
        $base = defined( 'SECURE_AUTH_KEY' ) && SECURE_AUTH_KEY !== ''
            ? SECURE_AUTH_KEY
            : AUTH_KEY ?? get_bloginfo( 'url' );

        return substr( hash( 'sha256', $base ), 0, 32 );
    }
}
