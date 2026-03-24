<?php
/**
 * License & trial gate — single source of truth for feature access.
 *
 * Access model
 * ─────────────────────────────────────────────────────────────────
 *  free       Standard shortcode scanner only. No account required.
 *  trial      Unlocked automatically after 10 organic shortcode scans.
 *             Lasts 30 days. No card required, no checkout to complete.
 *  active     Paid annual subscription (Solo / Agency / Enterprise).
 *  lapsed     Subscription lapsed. Premium features remain fully accessible;
 *             updates and support are paused until renewal.
 *             Renewal price is locked at the rate paid when subscribing.
 *  cancelled  Subscription cancelled. Reverts to free tier.
 *             All data is preserved — it just isn't visible in the admin
 *             until a new subscription is started.
 *
 * Usage
 * ─────────────────────────────────────────────────────────────────
 *  FI_License::is_active()         → bool   (gate for premium UI/features)
 *  FI_License::get_status()        → string (one of the STATUS_* constants)
 *  FI_License::get_plan()          → string (one of the PLAN_* constants)
 *  FI_License::get_scan_count()    → int
 *  FI_License::scans_until_trial() → int    (0 once threshold is reached)
 *  FI_License::maybe_unlock_trial()         (call after every shortcode scan)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FI_License {

    // ── Status constants ──────────────────────────────────────────────────────

    const STATUS_FREE      = 'free';
    const STATUS_TRIAL     = 'trial';
    const STATUS_ACTIVE    = 'active';
    const STATUS_LAPSED    = 'lapsed';
    const STATUS_CANCELLED = 'cancelled';

    // ── Plan constants ────────────────────────────────────────────────────────

    const PLAN_FREE       = 'free';
    const PLAN_SOLO       = 'solo';       // 1 site
    const PLAN_AGENCY     = 'agency';     // 3 sites
    const PLAN_ENTERPRISE = 'enterprise'; // 10 sites (unlisted / direct link)

    // ── Trial parameters ──────────────────────────────────────────────────────

    const TRIAL_SCAN_THRESHOLD = 10; // organic shortcode scans needed to unlock
    const TRIAL_DURATION_DAYS  = 30;

    // ─────────────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Whether premium features are currently accessible.
     *
     * True for: active trial, paid-active, and paid-lapsed (grace access).
     * False for: free tier and cancelled subscriptions.
     *
     * NOTE: Returns true unconditionally during development while the
     *       license server is being built. Flip the constant below to
     *       switch to real logic once the server is live.
     *
     * @return bool
     */
    public static function is_active(): bool {
        // ── DEV MODE ─────────────────────────────────────────────────────────
        // Set to false and uncomment the production block below once the
        // license API endpoint is operational.
        return true;

        // ── PRODUCTION ───────────────────────────────────────────────────────
        // $status = self::get_status();
        // return in_array( $status, [ self::STATUS_TRIAL, self::STATUS_ACTIVE, self::STATUS_LAPSED ], true );
    }

    /**
     * Resolve the current license / trial status for this install.
     *
     * Precedence: paid license > trial > free.
     *
     * @return string One of the STATUS_* constants.
     */
    public static function get_status(): string {
        // ── Paid license ──────────────────────────────────────────────────────
        $license_status = get_option( 'fi_license_status', '' );
        if ( in_array( $license_status, [ self::STATUS_ACTIVE, self::STATUS_LAPSED, self::STATUS_CANCELLED ], true ) ) {
            return $license_status;
        }

        // ── Trial ─────────────────────────────────────────────────────────────
        $trial_status = get_option( 'fi_trial_status', '' );

        if ( $trial_status === 'active' ) {
            $expires_at = get_option( 'fi_trial_expires_at', '' );

            if ( $expires_at && strtotime( $expires_at ) > time() ) {
                return self::STATUS_TRIAL;
            }

            // Trial has expired since last check — write the lapsed flag once.
            update_option( 'fi_trial_status', 'lapsed', false );
            return self::STATUS_FREE;
        }

        return self::STATUS_FREE;
    }

    /**
     * The plan tier associated with the active subscription.
     * Returns PLAN_FREE for trials and unlicensed installs.
     *
     * @return string One of the PLAN_* constants.
     */
    public static function get_plan(): string {
        return get_option( 'fi_license_plan', self::PLAN_FREE );
    }

    /**
     * Total organic shortcode scans recorded on this install.
     * Reuses the existing fi_free_scan_count option (incremented by
     * FI_Analytics::track_scan() on the free tier).
     *
     * @return int
     */
    public static function get_scan_count(): int {
        return (int) get_option( 'fi_free_scan_count', 0 );
    }

    /**
     * How many more shortcode scans are needed to unlock the trial.
     * Returns 0 once the threshold is reached or a trial/license already exists.
     *
     * @return int
     */
    public static function scans_until_trial(): int {
        // Already has a trial or paid plan — nothing left to unlock.
        if ( get_option( 'fi_trial_status', '' ) !== '' ) {
            return 0;
        }
        if ( get_option( 'fi_license_status', '' ) !== '' ) {
            return 0;
        }

        return max( 0, self::TRIAL_SCAN_THRESHOLD - self::get_scan_count() );
    }

    /**
     * Call this after every organic shortcode scan (free tier only).
     * Activates the 30-day trial the moment the threshold is hit — once, ever.
     *
     * @return void
     */
    public static function maybe_unlock_trial(): void {
        // No-op if a trial has already been started or a paid plan is active.
        if ( get_option( 'fi_trial_status', '' ) !== '' ) {
            return;
        }
        if ( get_option( 'fi_license_status', '' ) !== '' ) {
            return;
        }

        if ( self::get_scan_count() >= self::TRIAL_SCAN_THRESHOLD ) {
            self::activate_trial();
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Write trial options and log the activation event.
     *
     * @return void
     */
    private static function activate_trial(): void {
        $started_at = gmdate( 'Y-m-d H:i:s' );
        $expires_at = gmdate( 'Y-m-d H:i:s', time() + ( self::TRIAL_DURATION_DAYS * DAY_IN_SECONDS ) );

        update_option( 'fi_trial_status',     'active',     false );
        update_option( 'fi_trial_started_at', $started_at,  false );
        update_option( 'fi_trial_expires_at', $expires_at,  false );

        FI_Logger::info( 'Trial activated', [
            'scan_count' => self::get_scan_count(),
            'started_at' => $started_at,
            'expires_at' => $expires_at,
        ] );
    }
}
