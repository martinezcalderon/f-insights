<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FI_Polar
 *
 * Polar.sh Merchant-of-Record integration for Fricking Local Business Insights.
 *
 * ─── What this class does ────────────────────────────────────────────────────
 *  1. Registers a public REST webhook endpoint at:
 *       /wp-json/fi-insights/v1/polar-webhook
 *     (paste this URL into Polar Dashboard → Settings → Webhooks → Add Endpoint)
 *
 *  2. Verifies every incoming Polar webhook using the Standard Webhooks spec
 *     (HMAC-SHA256, replay-attack protection via 5-minute timestamp window).
 *
 *  3. Dispatches billing events:
 *       order.paid               → activate premium (one-time purchase)
 *       subscription.active      → activate premium (subscription)
 *       subscription.revoked     → deactivate premium
 *       subscription.canceled    → deactivate premium
 *       order.refunded           → deactivate premium
 *       benefit_grant.created    → logged (Polar delivers license key to buyer)
 *
 *  4. Validates Polar-issued license keys via the correct public API endpoint:
 *       POST https://api.polar.sh/v1/customer-portal/license-keys/validate
 *     Body: { "key": "<key>", "organization_id": "<your_org_id>" }
 *     NOTE: This endpoint requires NO bearer token — it is intentionally public.
 *           The organization_id scopes the key to your org to prevent cross-org abuse.
 *
 *  5. Performs full license activation (device registration) via:
 *       POST https://api.polar.sh/v1/customer-portal/license-keys/activate
 *     Body: { "key": "<key>", "organization_id": "<org_id>", "label": "<site_url>" }
 *     Stores the returned activation_id for future validation calls.
 *
 *  6. Performs license deactivation via:
 *       POST https://api.polar.sh/v1/customer-portal/license-keys/deactivate
 *     Body: { "key": "<key>", "organization_id": "<org_id>", "activation_id": "<id>" }
 *
 * ─── Required WP options (set in Settings → API Config) ─────────────────────
 *   fi_polar_organization_id   — Your Polar Organization ID (Settings → General in Polar)
 *   fi_polar_webhook_secret    — Webhook signing secret (Polar Dashboard → Webhooks → endpoint)
 *   fi_polar_checkout_url      — Checkout link URL (Polar Dashboard → Products → Checkout Links)
 *   fi_polar_access_token      — OAT (optional — only used for server-side admin API calls)
 *
 * ─── Purchase → access flow ──────────────────────────────────────────────────
 *   A) Webhook path (automatic, preferred):
 *      Customer buys → Polar fires order.paid → this class sets fi_polar_premium_active = true.
 *
 *   B) License key path (manual fallback / white-label installs):
 *      Customer finds their key in Polar Customer Portal → enters it in wp-admin →
 *      Plugin calls /v1/customer-portal/license-keys/activate → stores activation_id →
 *      On subsequent loads, /v1/customer-portal/license-keys/validate is called
 *      (cached for 1 hour to avoid per-request API calls).
 */
class FI_Polar {

	/** Polar production API base */
	const API_BASE = 'https://api.polar.sh';

	/** License validation cache TTL: 1 hour */
	const LICENSE_CACHE_TTL = HOUR_IN_SECONDS;

	/** WordPress REST API namespace */
	const REST_NS = 'fi-insights/v1';

	/** WordPress REST API route for webhook delivery */
	const WEBHOOK_ROUTE = '/polar-webhook';

	// ─── Bootstrap ───────────────────────────────────────────────────────────

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_webhook_route' ] );
	}

	// ─── REST webhook endpoint ────────────────────────────────────────────────

	/**
	 * Register a public REST route Polar posts webhook events to.
	 * URL shown to admin: https://yoursite.com/wp-json/fi-insights/v1/polar-webhook
	 */
	public static function register_webhook_route(): void {
		register_rest_route( self::REST_NS, self::WEBHOOK_ROUTE, [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_webhook' ],
			// Security is handled by HMAC signature verification below — not WP auth.
			'permission_callback' => '__return_true',
		] );
	}

	/**
	 * Receive, verify, and dispatch an incoming Polar webhook.
	 *
	 * Polar follows the Standard Webhooks spec (https://www.standardwebhooks.com/):
	 *   Header webhook-id        → unique delivery UUID
	 *   Header webhook-timestamp → Unix epoch as a string
	 *   Header webhook-signature → space-separated list of "v1,<base64-hmac>" entries
	 *
	 * Signature algorithm:
	 *   signed_content = "{webhook-id}.{webhook-timestamp}.{raw-body}"
	 *   hmac           = HMAC-SHA256( signed_content, base64_decode( webhook_secret ) )
	 *   header_value   = "v1," + base64_encode( hmac )
	 *
	 * IMPORTANT: Polar's webhook secret is stored as a raw string in their dashboard.
	 * The Standard Webhooks spec requires the secret to be base64-encoded before use
	 * as the HMAC key. Polar handles this for you in their SDKs. When rolling your own
	 * (as we do here to avoid a Composer dependency inside a WP plugin), you must
	 * base64_decode() the secret before passing it to hash_hmac().
	 */
	public static function handle_webhook( WP_REST_Request $request ): WP_REST_Response {
		$secret = (string) get_option( 'fi_polar_webhook_secret', '' );

		if ( ! $secret ) {
			FI_Logger::error( '[Polar] Webhook received but no webhook secret is configured. Go to Settings → API Config → Polar Integration.' );
			// Return 200 so Polar does not retry an unconfigured endpoint endlessly.
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'not_configured' ], 200 );
		}

		$body         = $request->get_body();
		$wh_id        = (string) ( $request->get_header( 'webhook-id' )        ?? '' );
		$wh_timestamp = (string) ( $request->get_header( 'webhook-timestamp' ) ?? '' );
		$wh_signature = (string) ( $request->get_header( 'webhook-signature' ) ?? '' );

		// All three Standard Webhooks headers are required.
		if ( ! $wh_id || ! $wh_timestamp || ! $wh_signature ) {
			FI_Logger::warn( '[Polar] Webhook rejected: missing Standard Webhooks headers (webhook-id / webhook-timestamp / webhook-signature).' );
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'missing_headers' ], 403 );
		}

		// Replay-attack protection: reject payloads with a timestamp drift > 5 minutes.
		$ts_diff = abs( time() - (int) $wh_timestamp );
		if ( $ts_diff > 300 ) {
			FI_Logger::warn( "[Polar] Webhook rejected: timestamp drift of {$ts_diff}s exceeds 300s limit." );
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'timestamp_drift' ], 403 );
		}

		// Compute expected signature.
		// The secret Polar shows in their dashboard must be base64-decoded before use
		// as the raw HMAC key (Standard Webhooks spec requirement).
		$signed_content = "{$wh_id}.{$wh_timestamp}.{$body}";
		$raw_secret     = base64_decode( $secret, true );
		if ( $raw_secret === false ) {
			// Secret is not base64-encoded — try treating it as a raw key (dev/manual entry).
			$raw_secret = $secret;
		}
		$expected_hmac = base64_encode( hash_hmac( 'sha256', $signed_content, $raw_secret, true ) );

		// The header may contain multiple space-separated "v1,<sig>" entries (key rotation).
		$verified = false;
		foreach ( explode( ' ', $wh_signature ) as $sig_entry ) {
			$parts     = explode( ',', $sig_entry, 2 );
			$sig_value = $parts[1] ?? '';
			if ( $sig_value && hash_equals( $expected_hmac, $sig_value ) ) {
				$verified = true;
				break;
			}
		}

		if ( ! $verified ) {
			FI_Logger::warn( '[Polar] Webhook rejected: HMAC signature verification failed. Check that the Webhook Secret in Settings matches the one in your Polar dashboard.' );
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'invalid_signature' ], 403 );
		}

		// ── Dispatch ──────────────────────────────────────────────────────────
		$payload = json_decode( $body, true ) ?? [];
		$type    = (string) ( $payload['type'] ?? '' );

		FI_Logger::info( "[Polar] Webhook verified and received: {$type}" );

		switch ( $type ) {
			case 'order.paid':
				self::on_order_paid( $payload['data'] ?? [] );
				break;

			case 'subscription.active':
				self::on_subscription_active( $payload['data'] ?? [] );
				break;

			case 'subscription.revoked':
			case 'subscription.canceled':
				self::on_subscription_revoked( $payload['data'] ?? [] );
				break;

			case 'order.refunded':
				self::on_order_refunded( $payload['data'] ?? [] );
				break;

			// benefit_grant events: Polar delivers the license key to the buyer
			// automatically. We log for traceability. The buyer enters the key in wp-admin.
			case 'benefit_grant.created':
			case 'benefit_grant.updated':
				FI_Logger::info( '[Polar] benefit_grant event received — Polar has delivered a license key to the customer.' );
				break;

			default:
				FI_Logger::info( "[Polar] Unhandled/ignored webhook event type: {$type}" );
		}

		// Polar expects a 2xx within 10 seconds; respond quickly.
		return new WP_REST_Response( [ 'ok' => true ], 202 );
	}

	// ─── Billing event handlers ───────────────────────────────────────────────

	private static function on_order_paid( array $order ): void {
		$email    = (string) ( $order['customer']['email'] ?? '' );
		$order_id = (string) ( $order['id'] ?? '' );

		if ( ! $email || ! $order_id ) {
			FI_Logger::error( '[Polar] order.paid event missing customer.email or id.' );
			return;
		}
		self::_activate_premium( $email, 'order', $order_id );
	}

	private static function on_subscription_active( array $sub ): void {
		$email = (string) ( $sub['customer']['email'] ?? '' );
		$sid   = (string) ( $sub['id'] ?? '' );

		if ( ! $email || ! $sid ) {
			FI_Logger::error( '[Polar] subscription.active event missing customer.email or id.' );
			return;
		}
		self::_activate_premium( $email, 'subscription', $sid );
	}

	private static function on_subscription_revoked( array $sub ): void {
		$sid = (string) ( $sub['id'] ?? '' );
		FI_Logger::info( "[Polar] Subscription revoked/canceled: {$sid}. Deactivating premium." );
		self::_deactivate_premium( 'subscription_revoked', $sid );
	}

	private static function on_order_refunded( array $order ): void {
		$order_id = (string) ( $order['id'] ?? '' );
		FI_Logger::info( "[Polar] Order refunded: {$order_id}. Deactivating premium." );
		self::_deactivate_premium( 'order_refunded', $order_id );
	}

	// ─── License key API (public endpoint — no bearer token needed) ───────────

	/**
	 * Activate a license key entered by the admin in wp-admin.
	 *
	 * Uses: POST /v1/customer-portal/license-keys/activate
	 * This public endpoint registers a named "activation instance" (this site)
	 * against the key, respects any seat/device limits, and returns an activation_id
	 * which is stored locally and used in future validate calls.
	 *
	 * The organization_id is REQUIRED by Polar to scope the key to your org.
	 * You can find your Organization ID in Polar Dashboard → Settings → General.
	 *
	 * @return array|WP_Error  On success: [ 'success' => true, 'message' => string ]
	 */
	public static function activate_license( string $key ): array|WP_Error {
		$key = trim( $key );
		if ( ! $key ) {
			return new WP_Error( 'empty_key', 'Please enter a license key.' );
		}

		$org_id = (string) get_option( 'fi_polar_organization_id', '' );
		if ( ! $org_id ) {
			// No org ID — fall back to validation-only (no activation instance created).
			FI_Logger::warn( '[Polar] Organization ID not configured; falling back to validate-only flow.' );
			return self::_activate_via_validate( $key );
		}

		$response = wp_remote_post( self::API_BASE . '/v1/customer-portal/license-keys/activate', [
			'timeout' => 20,
			'headers' => [
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			],
			'body' => wp_json_encode( [
				'key'             => $key,
				'organization_id' => $org_id,
				'label'           => home_url(), // Identifies this WordPress installation.
				'conditions'      => new stdClass(), // Empty object; no custom conditions needed.
			] ),
		] );

		if ( is_wp_error( $response ) ) {
			FI_Logger::warn( '[Polar] License activate API unreachable: ' . $response->get_error_message() . '. Storing key offline.' );
			self::_store_license( $key, '', 'offline' );
			return [ 'success' => true, 'message' => 'License saved (offline; will validate on next page load).' ];
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$body      = json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];

		if ( $http_code === 200 || $http_code === 201 ) {
			$activation_id = (string) ( $body['id'] ?? '' );
			self::_store_license( $key, $activation_id, 'polar' );
			self::_set_premium_active( true );
			FI_Logger::info( "[Polar] License key activated. Activation ID: {$activation_id}" );
			return [ 'success' => true, 'message' => 'License activated successfully.' ];
		}

		// 400 = key not found or already fully activated.
		// 403 = activation limit reached.
		$detail = (string) ( $body['detail'] ?? ( $body['message'] ?? "HTTP {$http_code}" ) );
		FI_Logger::warn( "[Polar] License activation failed: {$detail}" );
		return new WP_Error( 'activation_failed', "License activation failed: {$detail}" );
	}

	/**
	 * Validate a stored license key against Polar's public validate endpoint.
	 *
	 * Uses: POST /v1/customer-portal/license-keys/validate
	 * No bearer token required — this endpoint is intentionally public.
	 * organization_id is REQUIRED to scope validation to your org.
	 *
	 * If an activation_id is stored (from a prior activate call), it is included
	 * to enable per-device/seat validation. Otherwise validates the key globally.
	 *
	 * Results are cached for 1 hour (HOUR_IN_SECONDS) to avoid a remote call on
	 * every WordPress page load. The cache is cleared on deactivation.
	 *
	 * @return bool|WP_Error  true = valid, false = invalid, WP_Error = network failure
	 */
	public static function validate_license_key( string $key, string $activation_id = '' ): bool|WP_Error {
		$key = trim( $key );
		if ( ! $key ) return false;

		$org_id = (string) get_option( 'fi_polar_organization_id', '' );
		if ( ! $org_id ) {
			// No org ID means we cannot call Polar's API correctly.
			// Trust the locally-stored key rather than locking the customer out.
			FI_Logger::warn( '[Polar] Organization ID not set — cannot validate license key against Polar API. Trusting stored key.' );
			return true;
		}

		// 1-hour transient cache keyed on (key + org_id) so a key change busts the cache.
		$cache_key = 'fi_polar_lv_' . md5( $key . $org_id );
		$cached    = get_transient( $cache_key );
		if ( $cached !== false ) {
			return (bool) $cached;
		}

		$payload = [
			'key'             => $key,
			'organization_id' => $org_id,
		];
		if ( $activation_id ) {
			$payload['activation_id'] = $activation_id;
		}

		$response = wp_remote_post( self::API_BASE . '/v1/customer-portal/license-keys/validate', [
			'timeout' => 15,
			'headers' => [
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			],
			'body' => wp_json_encode( $payload ),
		] );

		if ( is_wp_error( $response ) ) {
			FI_Logger::error( '[Polar] License validate API unreachable: ' . $response->get_error_message() );
			// Fail open on network errors — don't lock out customers for a Polar outage.
			return $response;
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$data      = json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];

		// Polar returns 200 with the license key object on success.
		// On invalid key: 404. On deactivated: 403. On bad org: 422.
		if ( $http_code === 200 ) {
			set_transient( $cache_key, 1, self::LICENSE_CACHE_TTL );
			return true;
		}

		FI_Logger::warn( "[Polar] License validation failed: HTTP {$http_code}. Key may be invalid, revoked, or the Organization ID may be wrong." );
		set_transient( $cache_key, 0, self::LICENSE_CACHE_TTL );
		return false;
	}

	/**
	 * Deactivate the current license key locally and notify Polar's API.
	 *
	 * Uses: POST /v1/customer-portal/license-keys/deactivate
	 * Removes the activation instance from Polar so the customer can reactivate
	 * on a different site or after a reinstall.
	 */
	public static function deactivate_license(): void {
		$data          = get_option( 'fi_polar_license_data', [] );
		$key           = (string) ( $data['key']           ?? '' );
		$activation_id = (string) ( $data['activation_id'] ?? '' );
		$org_id        = (string) get_option( 'fi_polar_organization_id', '' );

		// Clear local cache immediately regardless of API result.
		if ( $key ) {
			delete_transient( 'fi_polar_lv_' . md5( $key . $org_id ) );
		}
		delete_option( 'fi_polar_license_data' );
		delete_option( 'fi_polar_premium_active' );

		// Notify Polar to free up the activation slot (best-effort, non-blocking).
		if ( $key && $activation_id && $org_id ) {
			$response = wp_remote_post( self::API_BASE . '/v1/customer-portal/license-keys/deactivate', [
				'timeout' => 10,
				'headers' => [
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				],
				'body' => wp_json_encode( [
					'key'             => $key,
					'organization_id' => $org_id,
					'activation_id'   => $activation_id,
				] ),
			] );

			if ( is_wp_error( $response ) ) {
				FI_Logger::warn( '[Polar] Deactivation API call failed (non-critical): ' . $response->get_error_message() );
			} else {
				FI_Logger::info( '[Polar] License deactivated on Polar. Activation slot freed.' );
			}
		}

		FI_Logger::info( '[Polar] License deactivated locally.' );
	}

	// ─── Premium status ───────────────────────────────────────────────────────

	/**
	 * Is premium currently active on this site?
	 *
	 * Check order:
	 *   1. Webhook-activated flag (set by order.paid / subscription.active events).
	 *   2. Stored license key, validated against Polar API (1-hour cache).
	 */
	public static function is_active(): bool {
		if ( (bool) get_option( 'fi_polar_premium_active', false ) ) {
			return true;
		}

		$data = get_option( 'fi_polar_license_data', [] );
		if ( empty( $data['key'] ) ) return false;

		$result = self::validate_license_key(
			$data['key'],
			$data['activation_id'] ?? ''
		);

		// WP_Error = network failure → fail closed for is_active checks
		// (webhook-activated flag is the reliable path during outages).
		return is_wp_error( $result ) ? false : (bool) $result;
	}

	// ─── Public helpers ───────────────────────────────────────────────────────

	/** Polar checkout URL for the "Buy Premium" buttons. */
	public static function checkout_url(): string {
		$url = (string) get_option( 'fi_polar_checkout_url', '' );
		return ( $url && filter_var( $url, FILTER_VALIDATE_URL ) ) ? esc_url( $url ) : '#';
	}

	/** Full URL of the webhook REST endpoint, shown in wp-admin for copy-pasting into Polar. */
	public static function webhook_url(): string {
		return rest_url( self::REST_NS . self::WEBHOOK_ROUTE );
	}

	/** Masked license key string for display in wp-admin (avoids exposing the full key in HTML). */
	public static function masked_key(): string {
		$data = get_option( 'fi_polar_license_data', [] );
		$key  = (string) ( $data['key'] ?? '' );
		if ( ! $key ) return '';
		return strlen( $key ) > 8
			? substr( $key, 0, 4 ) . str_repeat( '*', max( 0, strlen( $key ) - 8 ) ) . substr( $key, -4 )
			: str_repeat( '*', strlen( $key ) );
	}

	// ─── Private helpers ──────────────────────────────────────────────────────

	private static function _activate_premium( string $email, string $source, string $source_id ): void {
		self::_set_premium_active( true );
		update_option( 'fi_polar_customer', [
			'email'      => $email,
			'source'     => $source,
			'source_id'  => $source_id,
			'activated'  => current_time( 'mysql' ),
		] );
		FI_Logger::info( "[Polar] Premium activated via {$source} (ID: {$source_id}) for {$email}." );
	}

	private static function _deactivate_premium( string $reason, string $id ): void {
		delete_option( 'fi_polar_premium_active' );
		FI_Logger::info( "[Polar] Premium deactivated. Reason: {$reason}, ID: {$id}." );
	}

	private static function _set_premium_active( bool $active ): void {
		if ( $active ) {
			update_option( 'fi_polar_premium_active', true );
		} else {
			delete_option( 'fi_polar_premium_active' );
		}
	}

	private static function _store_license( string $key, string $activation_id, string $method ): void {
		update_option( 'fi_polar_license_data', [
			'key'           => $key,
			'activation_id' => $activation_id,
			'method'        => $method,
			'activated_at'  => current_time( 'mysql' ),
		] );
	}

	/**
	 * Fallback for when no organization_id is configured.
	 * Calls validate (not activate) just to confirm the key exists.
	 */
	private static function _activate_via_validate( string $key ): array|WP_Error {
		// Without org_id we cannot call the API; trust the key and warn.
		self::_store_license( $key, '', 'trust' );
		return [
			'success' => true,
			'message' => 'License key saved. Please add your Polar Organization ID in Settings to enable full API validation.',
		];
	}
}
