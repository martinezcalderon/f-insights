<?php
/**
 * Batch Prospect Scanner  (v2.2.0)
 *
 * Allows admins to search for multiple businesses by category + location and
 * generate F! Insights reports for each, with configurable guardrails to
 * prevent runaway API costs.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FI_Batch_Scanner {

    // ── Constants ─────────────────────────────────────────────────────────────

    /** Minimum interval (seconds) between individual Claude API calls within a batch. */
    const INTER_SCAN_DELAY = 2;

    /** Hard ceiling: even if the admin sets a higher limit, we cap here. */
    const MAX_BATCH_SIZE_HARD_CAP = 25;

    // ── AJAX registration ─────────────────────────────────────────────────────

    public static function register_ajax_hooks() {
        add_action( 'wp_ajax_fi_batch_find_prospects', array( __CLASS__, 'ajax_find_prospects' ) );
        add_action( 'wp_ajax_fi_batch_scan_prospect',  array( __CLASS__, 'ajax_scan_prospect' ) );
    }

    // =========================================================================
    // AJAX: Find prospects (Google Places text search by category + location)
    // =========================================================================

    /**
     * Step 1 of the batch workflow: search Google Places for businesses matching
     * a category + location query and return a list of prospects (no Claude yet).
     *
     * POST params:
     *   nonce     string  fi_admin_nonce
     *   category  string  Business category / type (e.g. "HVAC contractors")
     *   location  string  City / region (e.g. "Austin, TX")
     *   max_count int     Number of results (capped to admin setting)
     */
    public static function ajax_find_prospects() {
        check_ajax_referer( 'fi_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'f-insights' ) ) );
        }

        $category  = sanitize_text_field( wp_unslash( $_POST['category']  ?? '' ) );
        $location  = sanitize_text_field( wp_unslash( $_POST['location']  ?? '' ) );
        $max_count = absint( wp_unslash( $_POST['max_count'] ?? 5 ) );

        if ( empty( $category ) || empty( $location ) ) {
            wp_send_json_error( array( 'message' => __( 'Category and location are required.', 'f-insights' ) ) );
        }

        // Enforce the admin-configurable batch size limit.
        $admin_max  = absint( get_option( 'fi_batch_max_size', 10 ) );
        $admin_max  = min( $admin_max, self::MAX_BATCH_SIZE_HARD_CAP );
        $max_count  = min( $max_count, $admin_max );

        // Check daily batch quota before touching the API.
        $quota_error = self::check_daily_quota( $max_count );
        if ( is_wp_error( $quota_error ) ) {
            wp_send_json_error( array( 'message' => $quota_error->get_error_message() ) );
        }

        // Build a combined query: "<category> in <location>"
        $query   = trim( $category ) . ' in ' . trim( $location );
        $scanner = new FI_Scanner();

        // Use the existing search_business() which calls Google Places TextSearch.
        // We search with maxResultCount up to 20 so we can trim to $max_count.
        $results = $scanner->search_business( $query );

        if ( is_wp_error( $results ) ) {
            wp_send_json_error( array( 'message' => $results->get_error_message() ) );
        }

        // Trim to requested count.
        $prospects = array_slice( $results, 0, $max_count );

        FI_Logger::info( 'Batch prospect search', array(
            'query'     => $query,
            'found'     => count( $results ),
            'returning' => count( $prospects ),
        ) );

        wp_send_json_success( array(
            'prospects'  => $prospects,
            'query'      => $query,
            'quota_used' => self::get_daily_quota_used(),
            'quota_max'  => absint( get_option( 'fi_batch_daily_quota', 50 ) ),
        ) );
    }

    // =========================================================================
    // AJAX: Scan a single prospect (Claude analysis)
    // =========================================================================

    /**
     * Step 2 of the batch workflow: run a full F! Insights analysis on one
     * prospect (identified by place_id). Called per-item from the frontend JS
     * so progress can be shown incrementally without a single long-running request.
     *
     * POST params:
     *   nonce     string  fi_admin_nonce
     *   place_id  string  Google Place ID
     *   name      string  Business name (for logging)
     */
    public static function ajax_scan_prospect() {
        check_ajax_referer( 'fi_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'f-insights' ) ) );
        }

        $place_id = sanitize_text_field( wp_unslash( $_POST['place_id'] ?? '' ) );
        $name     = sanitize_text_field( wp_unslash( $_POST['name']     ?? '' ) );

        if ( empty( $place_id ) ) {
            wp_send_json_error( array( 'message' => __( 'place_id is required.', 'f-insights' ) ) );
        }

        // Consume one slot from the daily quota.
        $quota_error = self::check_daily_quota( 1 );
        if ( is_wp_error( $quota_error ) ) {
            wp_send_json_error( array( 'message' => $quota_error->get_error_message() ) );
        }
        self::increment_daily_quota( 1 );

        // Fetch full business details from Google Places.
        $scanner  = new FI_Scanner();
        $business = $scanner->get_business_details( $place_id );

        if ( is_wp_error( $business ) ) {
            wp_send_json_error( array( 'message' => $business->get_error_message() ) );
        }

        // Run Claude analysis (same pipeline as a regular scan).
        $grader = new FI_Grader( 'scan' );
        $report = $grader->grade_business( $business );

        if ( is_wp_error( $report ) ) {
            wp_send_json_error( array(
                'message'   => $report->get_error_message(),
                'place_id'  => $place_id,
                'name'      => $name,
            ) );
        }

        // Track analytics (premium only — mirrors single-scan behaviour).
        FI_Analytics::track_scan( $business, $report );

        FI_Logger::info( 'Batch prospect scanned', array( 'place_id' => $place_id, 'name' => $name ) );

        wp_send_json_success( array(
            'place_id'  => $place_id,
            'name'      => $name,
            'report'    => $report,
            'business'  => $business,
            'quota_used'=> self::get_daily_quota_used(),
        ) );
    }

    // =========================================================================
    // Daily quota helpers
    // =========================================================================

    /**
     * Returns the number of batch scans run today (UTC day, stored as a transient).
     */
    public static function get_daily_quota_used() {
        return (int) get_transient( self::quota_transient_key() );
    }

    /**
     * Check whether running $count more scans would exceed today's daily limit.
     *
     * @return true|WP_Error  true if OK, WP_Error if quota exceeded.
     */
    private static function check_daily_quota( $count ) {
        $daily_max = absint( get_option( 'fi_batch_daily_quota', 50 ) );
        if ( $daily_max < 1 ) {
            return true; // 0 = unlimited (not recommended but valid admin choice)
        }
        $used = self::get_daily_quota_used();
        if ( $used + $count > $daily_max ) {
            return new WP_Error(
                'batch_quota_exceeded',
                sprintf(
                    /* translators: 1: scans used today, 2: daily maximum */
                    __( 'Daily batch quota reached (%1$d of %2$d scans used today). Resets at midnight UTC.', 'f-insights' ),
                    $used,
                    $daily_max
                )
            );
        }
        return true;
    }

    /**
     * Increment today's quota counter by $count.
     * The transient expires at the next UTC midnight automatically.
     */
    private static function increment_daily_quota( $count ) {
        $key  = self::quota_transient_key();
        $used = (int) get_transient( $key );
        // Calculate seconds until next UTC midnight for transient TTL.
        $seconds_until_midnight = strtotime( 'tomorrow midnight UTC' ) - time();
        set_transient( $key, $used + $count, max( 1, $seconds_until_midnight ) );
    }

    /** Generates a UTC-day-specific transient key for the quota counter. */
    private static function quota_transient_key() {
        return 'fi_batch_quota_' . gmdate( 'Y_m_d' );
    }

    // =========================================================================
    // Admin page renderer
    // =========================================================================

    /**
     * Render the Batch Prospect Scanner admin page.
     */
    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $is_premium   = FI_License::is_active();
        $admin_max    = min( absint( get_option( 'fi_batch_max_size', 10 ) ), self::MAX_BATCH_SIZE_HARD_CAP );
        $daily_quota  = absint( get_option( 'fi_batch_daily_quota', 50 ) );
        $quota_used   = self::get_daily_quota_used();
        $quota_remain = max( 0, $daily_quota - $quota_used );

        // Model cost reference for the UI hint.
        $scan_model   = get_option( 'fi_claude_model_scan', 'claude-haiku-4-5-20251001' );
        $cost_map     = array(
            'claude-haiku-4-5-20251001' => '$0.01–$0.02',
            'claude-sonnet-4-20250514'  => '$0.03–$0.06',
            'claude-opus-4-20250514'    => '$0.12–$0.25',
        );
        $cost_hint    = $cost_map[ $scan_model ] ?? '~$0.03–$0.06';

        // ── Load labels from the single-source-of-truth category library ────────
        $category_map = require plugin_dir_path( __FILE__ ) . 'category-map.php';

        // ── Industry groupings: keys must exist in category-map.php ───────────
        $industry_groups = array(
            'Food & Beverage' => array(
                'american_restaurant', 'burger_restaurant', 'bbq_restaurant', 'southern_restaurant',
                'tex_mex_restaurant', 'cajun_restaurant', 'diner', 'brunch_restaurant', 'steakhouse',
                'seafood_restaurant', 'oyster_bar', 'lobster_shack', 'new_american_restaurant',
                'farm_to_table_restaurant', 'mexican_restaurant', 'taqueria', 'argentinian_restaurant',
                'brazilian_restaurant', 'churrascaria', 'colombian_restaurant', 'peruvian_restaurant',
                'venezuelan_restaurant', 'cuban_restaurant', 'salvadoran_restaurant',
                'caribbean_restaurant', 'haitian_restaurant', 'jamaican_restaurant',
                'italian_restaurant', 'pizza_restaurant', 'french_restaurant', 'spanish_restaurant',
                'tapas_restaurant', 'greek_restaurant', 'mediterranean_restaurant',
                'portuguese_restaurant', 'german_restaurant', 'british_restaurant', 'irish_pub',
                'polish_restaurant', 'turkish_restaurant', 'lebanese_restaurant', 'persian_restaurant',
                'israeli_restaurant', 'moroccan_restaurant', 'egyptian_restaurant', 'afghan_restaurant',
                'falafel_restaurant', 'kebab_restaurant', 'indian_restaurant', 'north_indian_restaurant',
                'south_indian_restaurant', 'pakistani_restaurant', 'bangladeshi_restaurant',
                'nepalese_restaurant', 'chinese_restaurant', 'cantonese_restaurant',
                'szechuan_restaurant', 'dim_sum_restaurant', 'japanese_restaurant', 'sushi_restaurant',
                'ramen_restaurant', 'izakaya', 'teppanyaki', 'korean_restaurant',
                'korean_bbq_restaurant', 'mongolian_restaurant', 'taiwanese_restaurant',
                'thai_restaurant', 'vietnamese_restaurant', 'pho_restaurant', 'filipino_restaurant',
                'indonesian_restaurant', 'malaysian_restaurant', 'singaporean_restaurant',
                'burmese_restaurant', 'cambodian_restaurant', 'ethiopian_restaurant',
                'eritrean_restaurant', 'nigerian_restaurant', 'west_african_restaurant',
                'east_african_restaurant', 'ghanaian_restaurant', 'vegetarian_restaurant',
                'vegan_restaurant', 'raw_food_restaurant', 'gluten_free_restaurant', 'halal_restaurant',
                'kosher_restaurant', 'buffet_restaurant', 'fondue_restaurant', 'hot_pot_restaurant',
                'omakase_restaurant', 'fine_dining_restaurant', 'gastropub', 'supper_club',
                'food_hall', 'ghost_kitchen', 'restaurant', 'cafe', 'specialty_coffee_shop',
                'espresso_bar', 'bubble_tea_shop', 'tea_house', 'juice_bar', 'bakery', 'patisserie',
                'bagel_shop', 'donut_shop', 'ice_cream_shop', 'gelato_shop', 'creperie',
                'waffle_house', 'chocolate_shop', 'candy_store', 'popcorn_shop', 'bar', 'sports_bar',
                'cocktail_bar', 'wine_bar', 'craft_beer_bar', 'dive_bar', 'night_club', 'rooftop_bar',
                'brewery', 'winery', 'distillery', 'cidery', 'mead_hall', 'hookah_lounge',
                'karaoke_bar', 'jazz_club', 'meal_takeaway', 'food_truck', 'hot_dog_stand',
            ),
            'Retail' => array(
                'clothing_store', 'womens_clothing_store', 'mens_clothing_store',
                'childrens_clothing_store', 'maternity_store', 'plus_size_clothing',
                'activewear_store', 'swimwear_store', 'lingerie_store', 'formal_wear_store',
                'bridal_shop', 'costume_shop', 'uniform_store', 'shoe_store', 'sneaker_store',
                'boot_store', 'children_shoe_store', 'jewelry_store', 'fine_jewelry_store',
                'costume_jewelry_store', 'watch_store', 'sunglasses_store', 'handbag_store',
                'hat_store', 'thrift_store', 'consignment_shop', 'vintage_clothing_store', 'tailor',
                'embroidery_shop', 'furniture_store', 'mattress_store', 'home_goods_store',
                'kitchen_supply_store', 'bath_store', 'candle_store', 'lighting_store',
                'flooring_store', 'wallpaper_store', 'hardware_store', 'appliance_store',
                'garden_center', 'pool_supply_store', 'antique_shop', 'frame_shop', 'rug_store',
                'electronics_store', 'computer_store', 'mobile_phone_store', 'camera_store',
                'audio_store', 'video_game_store', 'drone_store', 'home_theater_store',
                'telecom_store', 'book_store', 'comic_book_store', 'music_store', 'instrument_store',
                'record_store', 'toy_store', 'hobby_store', 'board_game_store', 'craft_store',
                'yarn_store', 'art_supply_store', 'sporting_goods_store', 'golf_store',
                'ski_snowboard_shop', 'surf_shop', 'outdoor_store', 'bicycle_store',
                'fishing_tackle_shop', 'hunting_store', 'gun_shop', 'tobacco_shop', 'vape_store',
                'cannabis_dispensary', 'party_supply_store', 'office_supply_store', 'new_age_store',
                'religious_goods_store', 'coin_shop', 'pawn_shop', 'trophy_awards_shop', 'magic_shop',
                'supermarket', 'convenience_store', 'natural_food_store', 'specialty_food_store',
                'farmers_market', 'asian_grocery', 'hispanic_grocery', 'middle_eastern_grocery',
                'indian_grocery', 'african_grocery', 'european_deli', 'jewish_deli', 'italian_deli',
                'butcher_shop', 'fish_market', 'cheese_shop', 'deli', 'liquor_store', 'wine_shop',
                'craft_beer_store', 'pharmacy', 'compounding_pharmacy', 'vitamin_supplement_shop',
                'medical_supply_store', 'optical_store', 'hearing_aid_store',
                'orthopedic_supply_store', 'florist', 'gift_shop', 'souvenir_store', 'pet_store',
                'pet_supply_store', 'aquarium_pet_store',
            ),
            'Health & Medical' => array(
                'doctor', 'family_practice', 'pediatrician', 'ob_gyn', 'dermatologist',
                'cardiologist', 'orthopedic_surgeon', 'neurologist', 'gastroenterologist',
                'urologist', 'endocrinologist', 'allergist', 'oncologist', 'psychiatrist', 'dentist',
                'orthodontist', 'periodontist', 'oral_surgeon', 'pediatric_dentist',
                'cosmetic_dentist', 'hospital', 'urgent_care', 'physiotherapist', 'chiropractor',
                'optometrist', 'mental_health', 'acupuncturist', 'naturopath', 'dietitian',
                'audiologist', 'speech_therapist', 'occupational_therapist', 'plastic_surgeon',
                'fertility_clinic', 'dialysis_center', 'medical_lab', 'sleep_clinic',
                'pain_management', 'iv_therapy', 'functional_medicine', 'drug_rehab', 'blood_bank',
            ),
            'Wellness & Beauty' => array(
                'spa', 'med_spa', 'beauty_salon', 'hair_care', 'barber', 'nail_salon',
                'tanning_salon', 'tattoo_parlor', 'piercing_studio', 'microblading_studio',
                'lash_studio', 'brow_bar', 'waxing_studio', 'laser_hair_removal', 'massage',
                'reflexology', 'float_spa', 'infrared_sauna', 'cryotherapy', 'gym', 'yoga_studio',
                'pilates_studio', 'barre_studio', 'crossfit', 'personal_trainer', 'boxing_gym',
                'martial_arts_school', 'dance_studio', 'cycling_studio', 'swimming_pool',
                'weight_loss_center', 'wellness_center',
            ),
            'Automotive' => array(
                'car_dealer', 'used_car_dealer', 'luxury_car_dealer', 'electric_vehicle_dealer',
                'motorcycle_dealer', 'rv_dealer', 'boat_dealer', 'car_repair', 'oil_change',
                'tire_shop', 'brake_service', 'transmission_repair', 'collision_repair',
                'auto_glass_repair', 'car_audio_shop', 'vehicle_wrap', 'car_wash', 'auto_detailing',
                'gas_station', 'auto_parts_store', 'towing_service', 'driving_school', 'smog_check',
            ),
            'Professional & Financial Services' => array(
                'lawyer', 'family_lawyer', 'criminal_lawyer', 'immigration_lawyer',
                'personal_injury_lawyer', 'real_estate_lawyer', 'estate_planning_lawyer',
                'corporate_lawyer', 'accounting', 'cpa_firm', 'tax_preparation', 'real_estate_agency',
                'property_management', 'mortgage_broker', 'insurance_agency', 'travel_agency',
                'financial_advisor', 'bank', 'credit_union', 'currency_exchange', 'check_cashing',
                'notary', 'hr_consulting', 'it_consulting', 'business_consulting', 'marketing_agency',
                'printing_shop', 'signage_company', 'coworking_space', 'moving_company',
            ),
            'Home & Trade Services' => array(
                'plumber', 'electrician', 'hvac', 'roofing_contractor', 'general_contractor',
                'remodeling_contractor', 'painting_contractor', 'flooring_installer', 'landscaping',
                'tree_service', 'cleaning_service', 'commercial_cleaning', 'carpet_cleaning',
                'pressure_washing', 'pest_control', 'security_system', 'solar_installer',
                'pool_service', 'handyman', 'locksmith', 'garage_door_service', 'masonry',
                'fencing_contractor', 'interior_designer', 'architect',
            ),
            'Personal & Local Services' => array(
                'laundry', 'dry_cleaning', 'shoe_repair', 'watch_repair', 'electronics_repair',
                'phone_repair', 'computer_repair', 'appliance_repair', 'bicycle_repair',
                'photography', 'photo_printing', 'post_office', 'mailbox_store', 'storage_facility',
                'funeral_home', 'wedding_planner', 'event_planning', 'alterations_shop',
            ),
            'Education & Childcare' => array(
                'school', 'university', 'library', 'tutoring', 'language_school', 'music_school',
                'art_school', 'cooking_school', 'dance_school', 'coding_school', 'vocational_school',
                'child_care', 'preschool', 'after_school', 'swim_school', 'gymnastics_school',
            ),
            'Entertainment & Recreation' => array(
                'movie_theater', 'drive_in_theater', 'museum', 'history_museum', 'science_museum',
                'childrens_museum', 'art_gallery', 'amusement_park', 'water_park', 'bowling_alley',
                'casino', 'escape_room', 'arcade', 'trampoline_park', 'laser_tag', 'go_kart',
                'mini_golf', 'axe_throwing', 'virtual_reality_arcade', 'billiards', 'bingo_hall',
                'paintball', 'live_music_venue', 'performing_arts_theater', 'opera_house',
                'comedy_club', 'aquarium', 'zoo', 'botanical_garden',
            ),
            'Sports & Fitness Facilities' => array(
                'stadium', 'sports_complex', 'golf_course', 'golf_driving_range', 'tennis_club',
                'ice_rink', 'skate_park', 'rock_climbing', 'shooting_range', 'equestrian',
                'surf_school', 'ski_resort', 'batting_cage', 'soccer_complex', 'swim_center',
            ),
            'Hospitality & Lodging' => array(
                'hotel', 'luxury_hotel', 'boutique_hotel', 'budget_hotel', 'extended_stay_hotel',
                'bed_and_breakfast', 'hostel', 'vacation_rental', 'glamping', 'campground',
                'conference_center', 'wedding_venue', 'banquet_hall',
            ),
            'Pets & Animals' => array(
                'veterinary_care', 'animal_hospital', 'exotic_vet', 'holistic_vet', 'pet_groomer',
                'pet_boarding', 'dog_daycare', 'dog_training',
            ),
            'Religious & Community' => array(
                'church', 'catholic_church', 'evangelical_church', 'orthodox_church', 'mosque',
                'synagogue', 'hindu_temple', 'sikh_gurdwara', 'buddhist_temple', 'community_center',
                'nonprofit', 'senior_center',
            ),
        );

        // Build the labelled map by looking up each key in the category library.
        $industry_map = array();
        foreach ( $industry_groups as $industry => $keys ) {
            $industry_map[ $industry ] = array();
            foreach ( $keys as $key ) {
                if ( isset( $category_map[ $key ] ) ) {
                    $industry_map[ $industry ][ $key ] = $category_map[ $key ];
                }
            }
        }

        // ── US States ─────────────────────────────────────────────────────────
        $us_states = array(
            'AL' => 'Alabama',        'AK' => 'Alaska',         'AZ' => 'Arizona',
            'AR' => 'Arkansas',       'CA' => 'California',     'CO' => 'Colorado',
            'CT' => 'Connecticut',    'DE' => 'Delaware',       'FL' => 'Florida',
            'GA' => 'Georgia',        'HI' => 'Hawaii',         'ID' => 'Idaho',
            'IL' => 'Illinois',       'IN' => 'Indiana',        'IA' => 'Iowa',
            'KS' => 'Kansas',         'KY' => 'Kentucky',       'LA' => 'Louisiana',
            'ME' => 'Maine',          'MD' => 'Maryland',       'MA' => 'Massachusetts',
            'MI' => 'Michigan',       'MN' => 'Minnesota',      'MS' => 'Mississippi',
            'MO' => 'Missouri',       'MT' => 'Montana',        'NE' => 'Nebraska',
            'NV' => 'Nevada',         'NH' => 'New Hampshire',  'NJ' => 'New Jersey',
            'NM' => 'New Mexico',     'NY' => 'New York',       'NC' => 'North Carolina',
            'ND' => 'North Dakota',   'OH' => 'Ohio',           'OK' => 'Oklahoma',
            'OR' => 'Oregon',         'PA' => 'Pennsylvania',   'RI' => 'Rhode Island',
            'SC' => 'South Carolina', 'SD' => 'South Dakota',   'TN' => 'Tennessee',
            'TX' => 'Texas',          'UT' => 'Utah',           'VT' => 'Vermont',
            'VA' => 'Virginia',       'WA' => 'Washington',     'WV' => 'West Virginia',
            'WI' => 'Wisconsin',      'WY' => 'Wyoming',        'DC' => 'District of Columbia',
        );
        ?>
        <div class="wrap fi-admin-wrap">
            <h1><?php _e( 'Batch Prospect Scanner', 'f-insights' ); ?></h1>
            <p class="description">
                <?php _e( 'Search for a category of businesses in a location and generate F! Insights reports for each prospect. Reports are saved to your leads and analytics tables — no user email required.', 'f-insights' ); ?>
            </p>

            <?php if ( ! $is_premium ) : ?>
                <div class="notice notice-warning inline" style="margin:16px 0;">
                    <p><?php _e( '<strong>Premium feature.</strong> Upgrade to use the Batch Prospect Scanner.', 'f-insights' ); ?></p>
                </div>
            <?php else : ?>

            <!-- ── Quota status bar ────────────────────────────────────────── -->
            <div style="background:#f6f7f7;border:1px solid #ddd;border-radius:6px;padding:12px 16px;margin-bottom:20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                <span style="font-size:13px;">
                    <strong><?php _e( 'Today\'s quota:', 'f-insights' ); ?></strong>
                    <?php echo esc_html( $quota_used ); ?> / <?php echo esc_html( $daily_quota ); ?> scans used
                    &nbsp;·&nbsp;
                    <span style="color:<?php echo $quota_remain > 0 ? '#00a32a' : '#b32d2e'; ?>">
                        <?php echo esc_html( $quota_remain ); ?> remaining
                    </span>
                    &nbsp;·&nbsp;
                    <?php _e( 'Resets at midnight UTC', 'f-insights' ); ?>
                </span>
                <span style="font-size:12px;color:#646970;">
                    <?php
                    printf(
                        /* translators: 1: cost estimate per scan, 2: Claude model name */
                        esc_html__( 'Est. Claude cost per scan: %1$s (%2$s)', 'f-insights' ),
                        esc_html( $cost_hint ),
                        esc_html( $scan_model )
                    );
                    ?>
                </span>
            </div>

            <!-- ── Batch search form ────────────────────────────────────────── -->
            <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px;margin-bottom:20px;">
                <table class="form-table" style="max-width:680px;">
                    <tr>
                        <th scope="row"><label for="fi-batch-industry"><?php _e( 'Industry', 'f-insights' ); ?></label></th>
                        <td>
                            <select id="fi-batch-industry" class="regular-text">
                                <option value=""><?php _e( '— Select an Industry —', 'f-insights' ); ?></option>
                                <?php foreach ( array_keys( $industry_map ) as $industry_name ) : ?>
                                    <option value="<?php echo esc_attr( $industry_name ); ?>"><?php echo esc_html( $industry_name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="fi-batch-category"><?php _e( 'Business Category', 'f-insights' ); ?></label></th>
                        <td>
                            <select id="fi-batch-category" class="regular-text" disabled>
                                <option value=""><?php _e( '— Select an Industry first —', 'f-insights' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="fi-batch-state"><?php _e( 'State', 'f-insights' ); ?></label></th>
                        <td>
                            <select id="fi-batch-state" class="regular-text">
                                <option value=""><?php _e( '— Select a State —', 'f-insights' ); ?></option>
                                <?php foreach ( $us_states as $abbr => $state_name ) : ?>
                                    <option value="<?php echo esc_attr( $state_name ); ?>"><?php echo esc_html( $state_name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="fi-batch-city"><?php _e( 'City', 'f-insights' ); ?></label></th>
                        <td>
                            <input type="text" id="fi-batch-city" class="regular-text"
                                   placeholder="<?php esc_attr_e( 'e.g. Austin', 'f-insights' ); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="fi-batch-count"><?php _e( 'Max Results', 'f-insights' ); ?></label></th>
                        <td>
                            <input type="number" id="fi-batch-count" class="small-text"
                                   value="5" min="1" max="<?php echo esc_attr( $admin_max ); ?>" step="1" />
                            <p class="description">
                                <?php printf(
                                    /* translators: %d: max batch size configured by admin */
                                    esc_html__( 'Maximum %d (set in Batch Scanner Settings). Each result costs one Claude API call (%s).', 'f-insights' ),
                                    $admin_max,
                                    esc_html( $cost_hint )
                                ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:8px;">
                    <button type="button" id="fi-batch-find-btn" class="button button-primary">
                        <?php _e( '🔍 Find Prospects', 'f-insights' ); ?>
                    </button>
                    <span id="fi-batch-find-status" style="font-size:13px;color:#646970;display:none;"></span>
                </div>
            </div>

            <!-- ── Prospect list + scan progress ──────────────────────────── -->
            <div id="fi-batch-results" style="display:none;">
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
                    <h3 style="margin:0;" id="fi-batch-results-heading"></h3>
                    <button type="button" id="fi-batch-scan-all-btn" class="button button-primary">
                        <?php _e( '▶ Scan All Prospects', 'f-insights' ); ?>
                    </button>
                </div>
                <p class="description" id="fi-batch-scan-note" style="margin-bottom:12px;">
                    <?php _e( 'Each prospect is scanned sequentially. You can close this page after starting — results are saved automatically.', 'f-insights' ); ?>
                </p>
                <div id="fi-batch-progress-bar-wrap" style="display:none;background:#f0f0f1;border-radius:4px;height:8px;margin-bottom:16px;">
                    <div id="fi-batch-progress-bar" style="background:#2271b1;height:8px;border-radius:4px;width:0%;transition:width .4s;"></div>
                </div>
                <table class="widefat fi-batch-table" style="border-radius:6px;overflow:hidden;">
                    <thead>
                        <tr>
                            <th><?php _e( 'Business', 'f-insights' ); ?></th>
                            <th><?php _e( 'Category', 'f-insights' ); ?></th>
                            <th><?php _e( 'Rating', 'f-insights' ); ?></th>
                            <th><?php _e( 'Score', 'f-insights' ); ?></th>
                            <th><?php _e( 'Status', 'f-insights' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="fi-batch-tbody"></tbody>
                </table>
            </div>

            <?php endif; // is_premium ?>
        </div>

        <?php if ( $is_premium ) : ?>
        <script>
        (function($){
            'use strict';

            var prospects    = [];
            var scanQueue    = [];
            var scanningIdx  = -1;
            var totalToScan  = 0;

            // ── Industry map passed from PHP ───────────────────────────────────
            var industryMap = <?php echo wp_json_encode( $industry_map ); ?>;

            // ── Populate Business Category when Industry changes ───────────────
            $('#fi-batch-industry').on('change', function() {
                var industry = $(this).val();
                var $cat     = $('#fi-batch-category');
                $cat.empty();
                if ( ! industry || ! industryMap[ industry ] ) {
                    $cat.append('<option value="">— Select an Industry first —</option>').prop('disabled', true);
                    return;
                }
                $cat.append('<option value="">— Select a Business Category —</option>');
                $.each( industryMap[ industry ], function( key, label ) {
                    $cat.append('<option value="' + escHtml(label) + '">' + escHtml(label) + '</option>');
                });
                $cat.prop('disabled', false);
            });

            // ── Find Prospects ────────────────────────────────────────────────
            $('#fi-batch-find-btn').on('click', function() {
                var category = $('#fi-batch-category').val();
                var state    = $('#fi-batch-state').val();
                var city     = $('#fi-batch-city').val().trim();
                var count    = parseInt($('#fi-batch-count').val(), 10) || 5;
                var $btn     = $(this);
                var $status  = $('#fi-batch-find-status');

                if (!category) {
                    $status.css('color','#b32d2e').text('Please select an Industry and Business Category.').show();
                    return;
                }
                if (!state) {
                    $status.css('color','#b32d2e').text('Please select a State.').show();
                    return;
                }
                if (!city) {
                    $status.css('color','#b32d2e').text('Please enter a City.').show();
                    return;
                }

                var location = city + ', ' + state;

                $btn.prop('disabled', true).text('Searching…');
                $status.css('color','#646970').text('Searching Google Places…').show();
                $('#fi-batch-results').hide();

                $.post(fiAdmin.ajaxUrl, {
                    action:    'fi_batch_find_prospects',
                    nonce:     fiAdmin.nonce,
                    category:  category,
                    location:  location,
                    max_count: count
                }, function(response) {
                    if (!response.success) {
                        $status.css('color','#b32d2e').text('✗ ' + (response.data.message || 'Search failed.'));
                        return;
                    }
                    prospects = response.data.prospects || [];
                    if (prospects.length === 0) {
                        $status.css('color','#b32d2e').text('No businesses found for that query. Try a different category or city.');
                        return;
                    }
                    $status.css('color','#00a32a').text('✓ Found ' + prospects.length + ' prospect(s).');
                    renderProspectTable(prospects);
                    $('#fi-batch-results').show();
                    $('#fi-batch-results-heading').text('Prospects (' + prospects.length + ')');
                }).fail(function() {
                    $status.css('color','#b32d2e').text('✗ Request failed.');
                }).always(function() {
                    $btn.prop('disabled', false).text('🔍 Find Prospects');
                });
            });

            // ── Render prospect table rows (pre-scan state) ───────────────────
            function renderProspectTable(list) {
                var html = '';
                list.forEach(function(p, idx) {
                    html += '<tr id="fi-batch-row-' + idx + '">'
                          + '<td><strong>' + escHtml(p.name) + '</strong>'
                          + (p.address ? '<br><small style="color:#666;">' + escHtml(p.address) + '</small>' : '')
                          + '</td>'
                          + '<td>' + escHtml(p.primary_type || '—') + '</td>'
                          + '<td>' + (p.rating ? '⭐ ' + p.rating + ' (' + (p.user_ratings_total||0) + ')' : '—') + '</td>'
                          + '<td id="fi-batch-score-' + idx + '" style="font-weight:600;">—</td>'
                          + '<td id="fi-batch-status-' + idx + '"><span style="color:#646970;">Pending</span></td>'
                          + '</tr>';
                });
                $('#fi-batch-tbody').html(html);
            }

            // ── Scan All ──────────────────────────────────────────────────────
            $('#fi-batch-scan-all-btn').on('click', function() {
                if (scanningIdx >= 0) { return; } // already running
                var $btn = $(this).prop('disabled', true).text('Scanning…');
                scanQueue    = prospects.map(function(p, idx) { return idx; });
                totalToScan  = scanQueue.length;
                scanningIdx  = 0;
                $('#fi-batch-progress-bar-wrap').show();
                scanNext($btn);
            });

            function scanNext($btn) {
                if (scanQueue.length === 0) {
                    $btn.prop('disabled', false).text('▶ Scan All Prospects');
                    scanningIdx = -1;
                    updateProgress(totalToScan, totalToScan);
                    return;
                }
                var idx      = scanQueue.shift();
                var prospect = prospects[idx];
                scanningIdx  = idx;

                $('#fi-batch-status-' + idx).html('<span style="color:#2271b1;">⟳ Scanning…</span>');

                $.ajax({
                    url:     fiAdmin.ajaxUrl,
                    type:    'POST',
                    timeout: 90000, // 90 s — Claude can be slow
                    data: {
                        action:   'fi_batch_scan_prospect',
                        nonce:    fiAdmin.nonce,
                        place_id: prospect.place_id,
                        name:     prospect.name
                    },
                    success: function(response) {
                        if (response.success) {
                            var score = response.data.report && response.data.report.overall_score
                                ? response.data.report.overall_score : '—';
                            var scoreColor = score >= 80 ? '#00a32a' : score >= 60 ? '#f0b429' : '#dc3232';
                            $('#fi-batch-score-' + idx).html('<span style="color:' + scoreColor + ';">' + score + '</span>');
                            $('#fi-batch-status-' + idx).html('<span style="color:#00a32a;">✓ Done</span>');
                        } else {
                            $('#fi-batch-status-' + idx).html('<span style="color:#b32d2e;" title="' + escHtml(response.data.message || '') + '">✗ Failed</span>');
                        }
                    },
                    error: function() {
                        $('#fi-batch-status-' + idx).html('<span style="color:#b32d2e;">✗ Timeout</span>');
                    },
                    complete: function() {
                        var done = totalToScan - scanQueue.length;
                        updateProgress(done, totalToScan);
                        // Small delay between calls to avoid rate-limiting.
                        setTimeout(function() { scanNext($btn); }, <?php echo (int) self::INTER_SCAN_DELAY * 1000; ?>);
                    }
                });
            }

            function updateProgress(done, total) {
                var pct = total > 0 ? Math.round((done / total) * 100) : 0;
                $('#fi-batch-progress-bar').css('width', pct + '%');
            }

            function escHtml(str) {
                return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            }

        })(jQuery);
        </script>
        <?php endif; ?>
        <?php
    }
}
