<?php
/**
 * Premium feature gate.
 *
 * Single source of truth for whether the current license is premium.
 * Every class that needs to check premium status calls FI_Premium::is_active().
 *
 * Freemius is integrated via fi_fs() — bootstrapped in f-insights.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FI_Premium {

    /**
     * Returns true if a premium license is active, false on the free tier.
     * True when a paid licence is active OR when the site is in a free trial.
     * can_use_premium_code() covers both cases: is_paying() || is_trial().
     *
     * @return bool
     */
    public static function is_active(): bool {
        // Freemius SDK integration — true when a paid licence is active OR in a trial.
        // can_use_premium_code() covers both cases (is_paying() || is_trial()).
        return function_exists( 'fi_fs' ) && fi_fs()->can_use_premium_code();
    }
}