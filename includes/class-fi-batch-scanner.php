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

        $is_premium   = FI_Premium::is_active();
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

        // ── Industry → Business Category map (sourced from category-map.php) ─
        $industry_map = array(
            'Food & Beverage' => array(
                'american_restaurant'      => 'American Restaurant',
                'burger_restaurant'        => 'Burger Restaurant',
                'bbq_restaurant'           => 'BBQ & Smokehouse',
                'southern_restaurant'      => 'Southern & Soul Food Restaurant',
                'tex_mex_restaurant'       => 'Tex-Mex Restaurant',
                'cajun_restaurant'         => 'Cajun & Creole Restaurant',
                'diner'                    => 'Diner',
                'brunch_restaurant'        => 'Brunch Restaurant',
                'steakhouse'               => 'Steakhouse',
                'seafood_restaurant'       => 'Seafood Restaurant',
                'oyster_bar'               => 'Oyster Bar',
                'lobster_shack'            => 'Lobster Shack & Seafood Shack',
                'new_american_restaurant'  => 'New American Restaurant',
                'farm_to_table_restaurant' => 'Farm-to-Table Restaurant',
                'mexican_restaurant'       => 'Mexican Restaurant',
                'taqueria'                 => 'Taqueria & Taco Shop',
                'argentinian_restaurant'   => 'Argentinian Restaurant',
                'brazilian_restaurant'     => 'Brazilian Restaurant',
                'churrascaria'             => 'Brazilian Steakhouse (Churrascaria)',
                'colombian_restaurant'     => 'Colombian Restaurant',
                'peruvian_restaurant'      => 'Peruvian Restaurant',
                'venezuelan_restaurant'    => 'Venezuelan Restaurant',
                'cuban_restaurant'         => 'Cuban Restaurant',
                'salvadoran_restaurant'    => 'Salvadoran Restaurant',
                'caribbean_restaurant'     => 'Caribbean Restaurant',
                'haitian_restaurant'       => 'Haitian Restaurant',
                'jamaican_restaurant'      => 'Jamaican Restaurant',
                'italian_restaurant'       => 'Italian Restaurant',
                'pizza_restaurant'         => 'Pizza Restaurant & Pizzeria',
                'french_restaurant'        => 'French Restaurant',
                'spanish_restaurant'       => 'Spanish Restaurant',
                'tapas_restaurant'         => 'Tapas Bar & Restaurant',
                'greek_restaurant'         => 'Greek Restaurant',
                'mediterranean_restaurant' => 'Mediterranean Restaurant',
                'portuguese_restaurant'    => 'Portuguese Restaurant',
                'german_restaurant'        => 'German Restaurant',
                'british_restaurant'       => 'British & Irish Restaurant',
                'irish_pub'                => 'Irish Pub & Restaurant',
                'polish_restaurant'        => 'Polish Restaurant',
                'turkish_restaurant'       => 'Turkish Restaurant',
                'lebanese_restaurant'      => 'Lebanese Restaurant',
                'persian_restaurant'       => 'Persian & Iranian Restaurant',
                'israeli_restaurant'       => 'Israeli Restaurant',
                'moroccan_restaurant'      => 'Moroccan Restaurant',
                'egyptian_restaurant'      => 'Egyptian Restaurant',
                'afghan_restaurant'        => 'Afghan Restaurant',
                'falafel_restaurant'       => 'Falafel & Shawarma Shop',
                'kebab_restaurant'         => 'Kebab Restaurant',
                'indian_restaurant'        => 'Indian Restaurant',
                'north_indian_restaurant'  => 'North Indian Restaurant',
                'south_indian_restaurant'  => 'South Indian Restaurant',
                'pakistani_restaurant'     => 'Pakistani Restaurant',
                'bangladeshi_restaurant'   => 'Bangladeshi Restaurant',
                'nepalese_restaurant'      => 'Nepalese Restaurant',
                'chinese_restaurant'       => 'Chinese Restaurant',
                'cantonese_restaurant'     => 'Cantonese Restaurant',
                'szechuan_restaurant'      => 'Szechuan Restaurant',
                'dim_sum_restaurant'       => 'Dim Sum Restaurant',
                'japanese_restaurant'      => 'Japanese Restaurant',
                'sushi_restaurant'         => 'Sushi Restaurant',
                'ramen_restaurant'         => 'Ramen Restaurant',
                'izakaya'                  => 'Izakaya & Japanese Pub',
                'teppanyaki'               => 'Teppanyaki & Hibachi Restaurant',
                'korean_restaurant'        => 'Korean Restaurant',
                'korean_bbq_restaurant'    => 'Korean BBQ Restaurant',
                'mongolian_restaurant'     => 'Mongolian Restaurant',
                'taiwanese_restaurant'     => 'Taiwanese Restaurant',
                'thai_restaurant'          => 'Thai Restaurant',
                'vietnamese_restaurant'    => 'Vietnamese Restaurant',
                'pho_restaurant'           => 'Pho Restaurant',
                'filipino_restaurant'      => 'Filipino Restaurant',
                'indonesian_restaurant'    => 'Indonesian Restaurant',
                'malaysian_restaurant'     => 'Malaysian Restaurant',
                'singaporean_restaurant'   => 'Singaporean Restaurant',
                'burmese_restaurant'       => 'Burmese Restaurant',
                'cambodian_restaurant'     => 'Cambodian Restaurant',
                'ethiopian_restaurant'     => 'Ethiopian Restaurant',
                'eritrean_restaurant'      => 'Eritrean Restaurant',
                'nigerian_restaurant'      => 'Nigerian Restaurant',
                'west_african_restaurant'  => 'West African Restaurant',
                'east_african_restaurant'  => 'East African Restaurant',
                'ghanaian_restaurant'      => 'Ghanaian Restaurant',
                'vegetarian_restaurant'    => 'Vegetarian Restaurant',
                'vegan_restaurant'         => 'Vegan Restaurant',
                'raw_food_restaurant'      => 'Raw Food Restaurant',
                'gluten_free_restaurant'   => 'Gluten-Free Restaurant',
                'halal_restaurant'         => 'Halal Restaurant',
                'kosher_restaurant'        => 'Kosher Restaurant',
                'buffet_restaurant'        => 'Buffet Restaurant',
                'fondue_restaurant'        => 'Fondue Restaurant',
                'hot_pot_restaurant'       => 'Hot Pot Restaurant',
                'omakase_restaurant'       => 'Omakase & Fine Dining',
                'fine_dining_restaurant'   => 'Fine Dining Restaurant',
                'gastropub'                => 'Gastropub',
                'supper_club'              => 'Supper Club & Private Dining',
                'food_hall'                => 'Food Hall',
                'ghost_kitchen'            => 'Ghost Kitchen & Virtual Restaurant',
                'restaurant'               => 'Restaurant',
                'cafe'                     => 'Cafe & Coffee Shop',
                'specialty_coffee_shop'    => 'Specialty Coffee Shop',
                'espresso_bar'             => 'Espresso Bar',
                'bubble_tea_shop'          => 'Bubble Tea & Boba Shop',
                'tea_house'                => 'Tea House & Tea Room',
                'juice_bar'                => 'Juice Bar & Smoothie Shop',
                'bakery'                   => 'Bakery',
                'patisserie'               => 'Patisserie & French Bakery',
                'bagel_shop'               => 'Bagel Shop',
                'donut_shop'               => 'Donut Shop',
                'ice_cream_shop'           => 'Ice Cream Shop',
                'gelato_shop'              => 'Gelato & Sorbet Shop',
                'creperie'                 => 'Creperie',
                'waffle_house'             => 'Waffle & Pancake House',
                'chocolate_shop'           => 'Chocolate & Confectionery Shop',
                'candy_store'              => 'Candy & Sweets Shop',
                'popcorn_shop'             => 'Gourmet Popcorn & Snack Shop',
                'bar'                      => 'Bar & Lounge',
                'sports_bar'               => 'Sports Bar',
                'cocktail_bar'             => 'Cocktail Bar',
                'wine_bar'                 => 'Wine Bar',
                'craft_beer_bar'           => 'Craft Beer Bar & Taproom',
                'dive_bar'                 => 'Dive Bar',
                'night_club'               => 'Nightclub',
                'rooftop_bar'              => 'Rooftop Bar',
                'brewery'                  => 'Brewery & Taproom',
                'winery'                   => 'Winery & Tasting Room',
                'distillery'               => 'Distillery & Tasting Room',
                'cidery'                   => 'Cidery',
                'mead_hall'                => 'Meadery & Mead Hall',
                'hookah_lounge'            => 'Hookah Lounge',
                'karaoke_bar'              => 'Karaoke Bar',
                'jazz_club'                => 'Jazz Club & Live Music Venue',
                'meal_takeaway'            => 'Fast Food & Takeaway',
                'food_truck'               => 'Food Truck',
                'hot_dog_stand'            => 'Hot Dog & Street Food Stand',
            ),
            'Retail' => array(
                'clothing_store'           => 'Clothing & Apparel Store',
                'womens_clothing_store'    => "Women's Clothing Boutique",
                'mens_clothing_store'      => "Men's Clothing Store",
                'childrens_clothing_store' => "Children's Clothing Store",
                'maternity_store'          => 'Maternity & Nursing Clothing',
                'plus_size_clothing'       => 'Plus-Size Clothing Store',
                'activewear_store'         => 'Activewear & Athleisure Store',
                'swimwear_store'           => 'Swimwear & Beachwear Store',
                'lingerie_store'           => 'Lingerie & Intimates Store',
                'formal_wear_store'        => 'Formal Wear & Tuxedo Rentals',
                'bridal_shop'              => 'Bridal & Wedding Dress Shop',
                'costume_shop'             => 'Costume & Halloween Shop',
                'uniform_store'            => 'Uniforms & Workwear',
                'shoe_store'               => 'Shoe Store',
                'sneaker_store'            => 'Sneaker & Streetwear Store',
                'boot_store'               => 'Boot & Western Wear Store',
                'children_shoe_store'      => "Children's Shoe Store",
                'jewelry_store'            => 'Jewelry Store',
                'fine_jewelry_store'       => 'Fine Jewelry & Diamond Store',
                'costume_jewelry_store'    => 'Fashion Jewelry & Accessories',
                'watch_store'              => 'Watch Store',
                'sunglasses_store'         => 'Sunglasses & Eyewear Store',
                'handbag_store'            => 'Handbag & Leather Goods Store',
                'hat_store'                => 'Hat Shop',
                'thrift_store'             => 'Thrift Store',
                'consignment_shop'         => 'Consignment Shop',
                'vintage_clothing_store'   => 'Vintage Clothing Store',
                'tailor'                   => 'Tailor & Alterations',
                'embroidery_shop'          => 'Embroidery & Screen Printing',
                'furniture_store'          => 'Furniture Store',
                'mattress_store'           => 'Mattress & Bedding Store',
                'home_goods_store'         => 'Home Goods & Decor Store',
                'kitchen_supply_store'     => 'Kitchen & Cookware Store',
                'bath_store'               => 'Bath & Body Store',
                'candle_store'             => 'Candle & Fragrance Store',
                'lighting_store'           => 'Lighting & Lamp Store',
                'flooring_store'           => 'Flooring & Carpet Store',
                'wallpaper_store'          => 'Paint, Wallpaper & Tile Store',
                'hardware_store'           => 'Hardware Store',
                'appliance_store'          => 'Appliance Store',
                'garden_center'            => 'Garden Center & Nursery',
                'pool_supply_store'        => 'Pool & Spa Supply Store',
                'antique_shop'             => 'Antique Shop',
                'frame_shop'               => 'Picture Framing Shop',
                'rug_store'                => 'Rug & Carpet Store',
                'electronics_store'        => 'Electronics Store',
                'computer_store'           => 'Computer & Laptop Store',
                'mobile_phone_store'       => 'Mobile Phone Store',
                'camera_store'             => 'Camera & Photography Store',
                'audio_store'              => 'Audio & Hi-Fi Store',
                'video_game_store'         => 'Video Game Store',
                'drone_store'              => 'Drone & RC Hobby Store',
                'home_theater_store'       => 'Home Theater & AV Store',
                'telecom_store'            => 'Telecom & Wireless Store',
                'book_store'               => 'Bookstore',
                'comic_book_store'         => 'Comic Book & Graphic Novel Store',
                'music_store'              => 'Music Store',
                'instrument_store'         => 'Musical Instrument Store',
                'record_store'             => 'Record & Vinyl Store',
                'toy_store'                => 'Toy Store',
                'hobby_store'              => 'Hobby & Model Store',
                'board_game_store'         => 'Board Game & Tabletop Game Store',
                'craft_store'              => 'Craft & Fabric Store',
                'yarn_store'               => 'Yarn & Knitting Store',
                'art_supply_store'         => 'Art Supply Store',
                'sporting_goods_store'     => 'Sporting Goods Store',
                'golf_store'               => 'Golf Equipment & Apparel Store',
                'ski_snowboard_shop'       => 'Ski & Snowboard Shop',
                'surf_shop'                => 'Surf & Watersports Shop',
                'outdoor_store'            => 'Outdoor & Camping Gear Store',
                'bicycle_store'            => 'Bicycle Shop',
                'fishing_tackle_shop'      => 'Fishing Tackle & Supplies',
                'hunting_store'            => 'Hunting & Outdoor Sports Store',
                'gun_shop'                 => 'Gun Shop & Firearms Store',
                'tobacco_shop'             => 'Tobacco & Cigar Shop',
                'vape_store'               => 'Vape & E-Cigarette Shop',
                'cannabis_dispensary'      => 'Cannabis Dispensary',
                'party_supply_store'       => 'Party Supply Store',
                'office_supply_store'      => 'Office Supply Store',
                'new_age_store'            => 'New Age, Crystal & Metaphysical Shop',
                'religious_goods_store'    => 'Religious Goods & Church Supply',
                'coin_shop'                => 'Coin, Stamp & Collectibles Shop',
                'pawn_shop'                => 'Pawn Shop',
                'trophy_awards_shop'       => 'Trophy, Award & Engraving Shop',
                'magic_shop'               => 'Magic & Novelty Shop',
                'supermarket'              => 'Supermarket & Grocery Store',
                'convenience_store'        => 'Convenience Store',
                'natural_food_store'       => 'Natural & Organic Food Store',
                'specialty_food_store'     => 'Specialty & Gourmet Food Store',
                'farmers_market'           => "Farmers' Market",
                'asian_grocery'            => 'Asian Grocery Store',
                'hispanic_grocery'         => 'Hispanic & Latin Grocery Store',
                'middle_eastern_grocery'   => 'Middle Eastern & Mediterranean Grocery',
                'indian_grocery'           => 'Indian & South Asian Grocery Store',
                'african_grocery'          => 'African & Caribbean Grocery Store',
                'european_deli'            => 'European Deli & Specialty Import',
                'jewish_deli'              => 'Jewish Deli & Kosher Market',
                'italian_deli'             => 'Italian Deli & Gourmet Market',
                'butcher_shop'             => 'Butcher Shop & Meat Market',
                'fish_market'              => 'Seafood & Fish Market',
                'cheese_shop'              => 'Cheese Shop & Fromagerie',
                'deli'                     => 'Deli & Sandwich Shop',
                'liquor_store'             => 'Liquor Store',
                'wine_shop'                => 'Wine Shop & Wine Merchant',
                'craft_beer_store'         => 'Craft Beer & Bottle Shop',
                'pharmacy'                 => 'Pharmacy & Drug Store',
                'compounding_pharmacy'     => 'Compounding Pharmacy',
                'vitamin_supplement_shop'  => 'Vitamin & Supplement Store',
                'medical_supply_store'     => 'Medical Supply Store',
                'optical_store'            => 'Optical & Eyewear Store',
                'hearing_aid_store'        => 'Hearing Aid Center',
                'orthopedic_supply_store'  => 'Orthopedic & Mobility Supply Store',
                'florist'                  => 'Florist',
                'gift_shop'                => 'Gift Shop',
                'souvenir_store'           => 'Souvenir Shop',
                'pet_store'                => 'Pet Store',
                'pet_supply_store'         => 'Pet Supply Store',
                'aquarium_pet_store'       => 'Aquarium & Exotic Pet Store',
            ),
            'Health & Medical' => array(
                'doctor'                   => 'Medical Office',
                'family_practice'          => 'Family Practice & Primary Care',
                'pediatrician'             => "Pediatrician & Children's Health",
                'ob_gyn'                   => "OB-GYN & Women's Health",
                'dermatologist'            => 'Dermatologist & Skin Care Clinic',
                'cardiologist'             => 'Cardiologist & Heart Clinic',
                'orthopedic_surgeon'       => 'Orthopedic Surgeon & Sports Medicine',
                'neurologist'              => 'Neurologist',
                'gastroenterologist'       => 'Gastroenterologist & Digestive Health',
                'urologist'                => 'Urologist',
                'endocrinologist'          => 'Endocrinologist & Diabetes Care',
                'allergist'                => 'Allergist & Immunologist',
                'oncologist'               => 'Oncologist & Cancer Center',
                'psychiatrist'             => 'Psychiatrist',
                'dentist'                  => 'Dental Office',
                'orthodontist'             => 'Orthodontist & Braces',
                'periodontist'             => 'Periodontist & Gum Specialist',
                'oral_surgeon'             => 'Oral Surgeon',
                'pediatric_dentist'        => 'Pediatric Dentist',
                'cosmetic_dentist'         => 'Cosmetic Dentist & Teeth Whitening',
                'hospital'                 => 'Hospital',
                'urgent_care'              => 'Urgent Care Clinic',
                'physiotherapist'          => 'Physical Therapy Clinic',
                'chiropractor'             => 'Chiropractic Office',
                'optometrist'              => 'Optometrist & Eye Care',
                'mental_health'            => 'Mental Health & Therapy Practice',
                'acupuncturist'            => 'Acupuncture & Oriental Medicine',
                'naturopath'               => 'Naturopath & Holistic Health',
                'dietitian'                => 'Registered Dietitian & Nutrition',
                'audiologist'              => 'Audiologist & Hearing Clinic',
                'speech_therapist'         => 'Speech-Language Pathology',
                'occupational_therapist'   => 'Occupational Therapy',
                'plastic_surgeon'          => 'Plastic Surgeon & Cosmetic Surgery',
                'fertility_clinic'         => 'Fertility Clinic & Reproductive Health',
                'dialysis_center'          => 'Dialysis Center',
                'medical_lab'              => 'Medical Laboratory & Diagnostics',
                'sleep_clinic'             => 'Sleep Clinic & Sleep Medicine',
                'pain_management'          => 'Pain Management Clinic',
                'iv_therapy'               => 'IV Therapy & Hydration Clinic',
                'functional_medicine'      => 'Functional Medicine Practice',
                'drug_rehab'               => 'Drug & Alcohol Rehabilitation Center',
                'blood_bank'               => 'Blood Bank & Donation Center',
            ),
            'Wellness & Beauty' => array(
                'spa'                      => 'Day Spa',
                'med_spa'                  => 'Medical Spa (MedSpa)',
                'beauty_salon'             => 'Beauty Salon',
                'hair_care'                => 'Hair Salon',
                'barber'                   => 'Barbershop',
                'nail_salon'               => 'Nail Salon',
                'tanning_salon'            => 'Tanning Salon',
                'tattoo_parlor'            => 'Tattoo Studio',
                'piercing_studio'          => 'Body Piercing Studio',
                'microblading_studio'      => 'Microblading & Permanent Makeup Studio',
                'lash_studio'              => 'Lash Extension Studio',
                'brow_bar'                 => 'Brow Bar & Threading Studio',
                'waxing_studio'            => 'Waxing & Hair Removal Studio',
                'laser_hair_removal'       => 'Laser Hair Removal & Skin Clinic',
                'massage'                  => 'Massage Therapy Studio',
                'reflexology'              => 'Reflexology & Foot Massage',
                'float_spa'                => 'Float Tank & Sensory Deprivation Spa',
                'infrared_sauna'           => 'Infrared Sauna Studio',
                'cryotherapy'              => 'Cryotherapy & Recovery Center',
                'gym'                      => 'Gym & Fitness Center',
                'yoga_studio'              => 'Yoga Studio',
                'pilates_studio'           => 'Pilates Studio',
                'barre_studio'             => 'Barre Studio',
                'crossfit'                 => 'CrossFit Gym',
                'personal_trainer'         => 'Personal Training Studio',
                'boxing_gym'               => 'Boxing & Kickboxing Gym',
                'martial_arts_school'      => 'Martial Arts School',
                'dance_studio'             => 'Dance Studio',
                'cycling_studio'           => 'Indoor Cycling & Spin Studio',
                'swimming_pool'            => 'Swimming Pool & Aquatic Center',
                'weight_loss_center'       => 'Weight Loss Center',
                'wellness_center'          => 'Holistic Wellness Center',
            ),
            'Automotive' => array(
                'car_dealer'               => 'Car Dealership',
                'used_car_dealer'          => 'Used Car Dealership',
                'luxury_car_dealer'        => 'Luxury Car Dealership',
                'electric_vehicle_dealer'  => 'Electric Vehicle Dealership',
                'motorcycle_dealer'        => 'Motorcycle & Powersports Dealership',
                'rv_dealer'                => 'RV & Camper Dealership',
                'boat_dealer'              => 'Boat & Marine Dealership',
                'car_repair'               => 'Auto Repair Shop',
                'oil_change'               => 'Oil Change & Lube Center',
                'tire_shop'                => 'Tire Shop',
                'brake_service'            => 'Brake & Exhaust Service',
                'transmission_repair'      => 'Transmission Repair',
                'collision_repair'         => 'Collision Repair & Body Shop',
                'auto_glass_repair'        => 'Auto Glass & Windshield Repair',
                'car_audio_shop'           => 'Car Audio & Electronics Shop',
                'vehicle_wrap'             => 'Vehicle Wrap & Graphics',
                'car_wash'                 => 'Car Wash',
                'auto_detailing'           => 'Auto Detailing Studio',
                'gas_station'              => 'Gas Station',
                'auto_parts_store'         => 'Auto Parts Store',
                'towing_service'           => 'Towing & Roadside Assistance',
                'driving_school'           => 'Driving School',
                'smog_check'               => 'Smog Check & Emissions Testing',
            ),
            'Professional & Financial Services' => array(
                'lawyer'                   => 'Law Office',
                'family_lawyer'            => 'Family Law Attorney',
                'criminal_lawyer'          => 'Criminal Defense Attorney',
                'immigration_lawyer'       => 'Immigration Attorney',
                'personal_injury_lawyer'   => 'Personal Injury Attorney',
                'real_estate_lawyer'       => 'Real Estate Attorney',
                'estate_planning_lawyer'   => 'Estate Planning & Probate Attorney',
                'corporate_lawyer'         => 'Business & Corporate Attorney',
                'accounting'               => 'Accounting & Bookkeeping',
                'cpa_firm'                 => 'CPA Firm',
                'tax_preparation'          => 'Tax Preparation Service',
                'real_estate_agency'       => 'Real Estate Agency',
                'property_management'      => 'Property Management Company',
                'mortgage_broker'          => 'Mortgage Broker & Lender',
                'insurance_agency'         => 'Insurance Agency',
                'travel_agency'            => 'Travel Agency',
                'financial_advisor'        => 'Financial Advisor & Planner',
                'bank'                     => 'Bank',
                'credit_union'             => 'Credit Union',
                'currency_exchange'        => 'Currency Exchange',
                'check_cashing'            => 'Check Cashing & Payday Loans',
                'notary'                   => 'Notary Public',
                'hr_consulting'            => 'HR & Staffing Agency',
                'it_consulting'            => 'IT Consulting & Managed Services',
                'business_consulting'      => 'Business Consulting',
                'marketing_agency'         => 'Marketing & Advertising Agency',
                'printing_shop'            => 'Print & Copy Shop',
                'signage_company'          => 'Sign & Signage Company',
                'coworking_space'          => 'Coworking Space',
                'moving_company'           => 'Moving & Relocation Company',
            ),
            'Home & Trade Services' => array(
                'plumber'                  => 'Plumbing Service',
                'electrician'              => 'Electrician',
                'hvac'                     => 'HVAC & Heating & Cooling',
                'roofing_contractor'       => 'Roofing Contractor',
                'general_contractor'       => 'General Contractor',
                'remodeling_contractor'    => 'Kitchen & Bathroom Remodeler',
                'painting_contractor'      => 'Painting Contractor',
                'flooring_installer'       => 'Flooring Installer',
                'landscaping'              => 'Landscaping & Lawn Care',
                'tree_service'             => 'Tree Service & Arborist',
                'cleaning_service'         => 'House Cleaning Service',
                'commercial_cleaning'      => 'Commercial Cleaning & Janitorial',
                'carpet_cleaning'          => 'Carpet & Upholstery Cleaning',
                'pressure_washing'         => 'Pressure Washing Service',
                'pest_control'             => 'Pest Control Service',
                'security_system'          => 'Security & Smart Home Installer',
                'solar_installer'          => 'Solar Panel Installer',
                'pool_service'             => 'Pool & Spa Service',
                'handyman'                 => 'Handyman Service',
                'locksmith'                => 'Locksmith',
                'garage_door_service'      => 'Garage Door Service & Repair',
                'masonry'                  => 'Masonry & Concrete Contractor',
                'fencing_contractor'       => 'Fencing Contractor',
                'interior_designer'        => 'Interior Designer & Decorator',
                'architect'                => 'Architect',
            ),
            'Personal & Local Services' => array(
                'laundry'                  => 'Laundromat',
                'dry_cleaning'             => 'Dry Cleaner',
                'shoe_repair'              => 'Shoe Repair Shop',
                'watch_repair'             => 'Watch & Jewelry Repair',
                'electronics_repair'       => 'Electronics Repair Shop',
                'phone_repair'             => 'Phone & Tablet Repair',
                'computer_repair'          => 'Computer Repair Shop',
                'appliance_repair'         => 'Appliance Repair Service',
                'bicycle_repair'           => 'Bicycle Repair Shop',
                'photography'              => 'Photography Studio',
                'photo_printing'           => 'Photo Printing & Gifts',
                'post_office'              => 'Post Office',
                'mailbox_store'            => 'Mailbox & Shipping Center',
                'storage_facility'         => 'Self-Storage Facility',
                'funeral_home'             => 'Funeral Home & Cremation Services',
                'wedding_planner'          => 'Wedding Planner & Coordinator',
                'event_planning'           => 'Event Planning & Party Rentals',
                'alterations_shop'         => 'Clothing Alterations & Tailoring',
            ),
            'Education & Childcare' => array(
                'school'                   => 'K-12 School',
                'university'               => 'College & University',
                'library'                  => 'Library',
                'tutoring'                 => 'Tutoring & Test Prep',
                'language_school'          => 'Language School & ESL',
                'music_school'             => 'Music Lessons & School',
                'art_school'               => 'Art Classes & School',
                'cooking_school'           => 'Cooking School & Culinary Classes',
                'dance_school'             => 'Dance School',
                'coding_school'            => 'Coding Bootcamp & STEM School',
                'vocational_school'        => 'Vocational & Trade School',
                'child_care'               => 'Childcare Center & Daycare',
                'preschool'                => 'Preschool & Pre-K',
                'after_school'             => 'After-School Program',
                'swim_school'              => 'Swim School & Lessons',
                'gymnastics_school'        => 'Gymnastics & Cheer School',
            ),
            'Entertainment & Recreation' => array(
                'movie_theater'            => 'Movie Theater & Cinema',
                'drive_in_theater'         => 'Drive-In Theater',
                'museum'                   => 'Museum',
                'history_museum'           => 'History Museum',
                'science_museum'           => 'Science Center & Planetarium',
                'childrens_museum'         => "Children's Museum",
                'art_gallery'              => 'Art Gallery & Exhibition Space',
                'amusement_park'           => 'Amusement Park & Theme Park',
                'water_park'               => 'Water Park',
                'bowling_alley'            => 'Bowling Alley',
                'casino'                   => 'Casino',
                'escape_room'              => 'Escape Room',
                'arcade'                   => 'Arcade & Family Entertainment Center',
                'trampoline_park'          => 'Trampoline Park',
                'laser_tag'                => 'Laser Tag Center',
                'go_kart'                  => 'Go-Kart Track',
                'mini_golf'                => 'Mini Golf & Putt-Putt',
                'axe_throwing'             => 'Axe Throwing Venue',
                'virtual_reality_arcade'   => 'Virtual Reality Arcade',
                'billiards'                => 'Billiards & Pool Hall',
                'bingo_hall'               => 'Bingo Hall',
                'paintball'                => 'Paintball & Airsoft Field',
                'live_music_venue'         => 'Live Music Venue',
                'performing_arts_theater'  => 'Performing Arts Theater',
                'opera_house'              => 'Opera House & Concert Hall',
                'comedy_club'              => 'Comedy Club',
                'aquarium'                 => 'Aquarium',
                'zoo'                      => 'Zoo & Wildlife Park',
                'botanical_garden'         => 'Botanical Garden',
            ),
            'Sports & Fitness Facilities' => array(
                'stadium'                  => 'Stadium & Arena',
                'sports_complex'           => 'Sports Complex & Recreation Center',
                'golf_course'              => 'Golf Course & Country Club',
                'golf_driving_range'       => 'Golf Driving Range',
                'tennis_club'              => 'Tennis Club & Courts',
                'ice_rink'                 => 'Ice Skating & Hockey Rink',
                'skate_park'               => 'Skate Park',
                'rock_climbing'            => 'Rock Climbing Gym',
                'shooting_range'           => 'Shooting Range',
                'equestrian'               => 'Equestrian Center & Horse Riding',
                'surf_school'              => 'Surf School & Watersports',
                'ski_resort'               => 'Ski Resort & Snow Sports',
                'batting_cage'             => 'Batting Cage & Sports Training',
                'soccer_complex'           => 'Soccer Complex & Futsal',
                'swim_center'              => 'Swim Center & Lap Pool',
            ),
            'Hospitality & Lodging' => array(
                'hotel'                    => 'Hotel',
                'luxury_hotel'             => 'Luxury Hotel & Resort',
                'boutique_hotel'           => 'Boutique Hotel',
                'budget_hotel'             => 'Budget Hotel & Motel',
                'extended_stay_hotel'      => 'Extended Stay Hotel',
                'bed_and_breakfast'        => 'Bed & Breakfast',
                'hostel'                   => 'Hostel',
                'vacation_rental'          => 'Vacation Rental',
                'glamping'                 => 'Glamping & Eco Retreat',
                'campground'               => 'Campground & RV Park',
                'conference_center'        => 'Conference & Meeting Center',
                'wedding_venue'            => 'Wedding Venue',
                'banquet_hall'             => 'Banquet Hall & Event Venue',
            ),
            'Pets & Animals' => array(
                'veterinary_care'          => 'Veterinary Clinic',
                'animal_hospital'          => 'Animal Hospital & Emergency Vet',
                'exotic_vet'               => 'Exotic Animal Veterinarian',
                'holistic_vet'             => 'Holistic & Integrative Veterinarian',
                'pet_groomer'              => 'Pet Groomer',
                'pet_boarding'             => 'Pet Boarding & Kennel',
                'dog_daycare'              => 'Dog Daycare & Boarding',
                'dog_training'             => 'Dog Training & Behavior School',
            ),
            'Religious & Community' => array(
                'church'                   => 'Church & Christian Center',
                'catholic_church'          => 'Catholic Church & Parish',
                'evangelical_church'       => 'Evangelical & Pentecostal Church',
                'orthodox_church'          => 'Orthodox Church',
                'mosque'                   => 'Mosque & Islamic Center',
                'synagogue'                => 'Synagogue & Jewish Center',
                'hindu_temple'             => 'Hindu Temple',
                'sikh_gurdwara'            => 'Sikh Gurdwara',
                'buddhist_temple'          => 'Buddhist Temple & Meditation Center',
                'community_center'         => 'Community Center',
                'nonprofit'                => 'Nonprofit & Charity',
                'senior_center'            => 'Senior Center & Adult Day Program',
            ),
        );

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
