<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FI_Taxonomy
 *
 * Maps Google Places raw `types` arrays to a normalized industry taxonomy.
 * Used for two purposes:
 *   1. Choosing the right Google type string for competitor nearbysearch
 *   2. Detecting when a business's Google category doesn't match its apparent identity
 *      (signal of poor category optimization — surfaced as a recommendation)
 *
 * Structure:
 *   parent_industry → label
 *   child_category  → [ label, search_type, google_types[] ]
 *
 * search_type: the single Google Places type string to use for competitor search
 * google_types: raw types that map INTO this child category
 *
 * When multiple google_types match, the most specific child wins.
 * If nothing matches, we fall back to 'establishment'.
 */
class FI_Taxonomy {

    /**
     * Full taxonomy.
     * Each child entry: [ 'label', 'parent', 'search_type', 'google_types' ]
     */
    private static array $taxonomy = [

        // ── Food & Drink ──────────────────────────────────────────────────────
        'mexican_restaurant'         => [ 'Mexican Restaurant',          'Food & Drink', 'restaurant',    [ 'mexican_restaurant' ] ],
        'american_restaurant'        => [ 'American Restaurant',         'Food & Drink', 'restaurant',    [ 'american_restaurant' ] ],
        'italian_restaurant'         => [ 'Italian Restaurant',          'Food & Drink', 'restaurant',    [ 'italian_restaurant' ] ],
        'chinese_restaurant'         => [ 'Chinese Restaurant',          'Food & Drink', 'restaurant',    [ 'chinese_restaurant' ] ],
        'japanese_restaurant'        => [ 'Japanese Restaurant',         'Food & Drink', 'restaurant',    [ 'japanese_restaurant', 'sushi_restaurant' ] ],
        'korean_restaurant'          => [ 'Korean Restaurant',           'Food & Drink', 'restaurant',    [ 'korean_restaurant' ] ],
        'thai_restaurant'            => [ 'Thai Restaurant',             'Food & Drink', 'restaurant',    [ 'thai_restaurant' ] ],
        'vietnamese_restaurant'      => [ 'Vietnamese Restaurant',       'Food & Drink', 'restaurant',    [ 'vietnamese_restaurant' ] ],
        'indian_restaurant'          => [ 'Indian Restaurant',           'Food & Drink', 'restaurant',    [ 'indian_restaurant' ] ],
        'mediterranean_restaurant'   => [ 'Mediterranean Restaurant',    'Food & Drink', 'restaurant',    [ 'mediterranean_restaurant', 'greek_restaurant', 'lebanese_restaurant' ] ],
        'latin_restaurant'           => [ 'Latin American Restaurant',   'Food & Drink', 'restaurant',    [ 'latin_american_restaurant', 'salvadoran_restaurant', 'colombian_restaurant', 'peruvian_restaurant', 'cuban_restaurant', 'brazilian_restaurant' ] ],
        'seafood_restaurant'         => [ 'Seafood Restaurant',          'Food & Drink', 'restaurant',    [ 'seafood_restaurant' ] ],
        'steak_restaurant'           => [ 'Steakhouse',                  'Food & Drink', 'restaurant',    [ 'steak_house' ] ],
        'pizza_restaurant'           => [ 'Pizza',                       'Food & Drink', 'restaurant',    [ 'pizza_restaurant' ] ],
        'burger_restaurant'          => [ 'Burger Restaurant',           'Food & Drink', 'restaurant',    [ 'hamburger_restaurant' ] ],
        'sandwich_restaurant'        => [ 'Sandwich Shop',               'Food & Drink', 'restaurant',    [ 'sandwich_shop', 'sub_sandwich_shop' ] ],
        'fast_food'                  => [ 'Fast Food',                   'Food & Drink', 'restaurant',    [ 'fast_food_restaurant' ] ],
        'food_truck'                 => [ 'Food Truck',                  'Food & Drink', 'restaurant',    [ 'food_truck' ] ],
        'buffet_restaurant'          => [ 'Buffet',                      'Food & Drink', 'restaurant',    [ 'buffet_restaurant' ] ],
        'cafe'                       => [ 'Café',                        'Food & Drink', 'cafe',          [ 'cafe', 'coffee_shop', 'espresso_bar' ] ],
        'bakery'                     => [ 'Bakery',                      'Food & Drink', 'bakery',        [ 'bakery' ] ],
        'ice_cream'                  => [ 'Ice Cream & Desserts',        'Food & Drink', 'bakery',        [ 'ice_cream_shop', 'dessert_restaurant' ] ],
        'bar'                        => [ 'Bar',                         'Food & Drink', 'bar',           [ 'bar', 'pub', 'cocktail_bar', 'wine_bar' ] ],
        'brewery'                    => [ 'Brewery',                     'Food & Drink', 'bar',           [ 'brewery', 'microbrewery' ] ],
        'nightclub'                  => [ 'Nightclub',                   'Food & Drink', 'night_club',    [ 'night_club', 'nightclub' ] ],
        'restaurant_generic'         => [ 'Restaurant',                  'Food & Drink', 'restaurant',    [ 'restaurant', 'food' ] ],

        // ── Health & Wellness ─────────────────────────────────────────────────
        'gym'                        => [ 'Gym & Fitness',               'Health & Wellness', 'gym',              [ 'gym', 'fitness_center', 'health_club', 'sports_complex' ] ],
        'yoga_studio'                => [ 'Yoga Studio',                 'Health & Wellness', 'gym',              [ 'yoga_studio' ] ],
        'pilates_studio'             => [ 'Pilates Studio',              'Health & Wellness', 'gym',              [ 'pilates_studio' ] ],
        'martial_arts'               => [ 'Martial Arts',                'Health & Wellness', 'gym',              [ 'martial_arts_school' ] ],
        'spa'                        => [ 'Spa',                         'Health & Wellness', 'spa',              [ 'spa', 'day_spa' ] ],
        'massage'                    => [ 'Massage',                     'Health & Wellness', 'spa',              [ 'massage_therapist' ] ],
        'doctor'                     => [ 'Medical Practice',            'Health & Wellness', 'doctor',           [ 'doctor', 'physician' ] ],
        'dentist'                    => [ 'Dentist',                     'Health & Wellness', 'dentist',          [ 'dentist' ] ],
        'optometrist'                => [ 'Eye Care',                    'Health & Wellness', 'doctor',           [ 'optometrist', 'ophthalmologist' ] ],
        'chiropractor'               => [ 'Chiropractor',                'Health & Wellness', 'doctor',           [ 'chiropractor' ] ],
        'physical_therapy'           => [ 'Physical Therapy',            'Health & Wellness', 'physiotherapist',  [ 'physiotherapist', 'physical_therapist' ] ],
        'mental_health'              => [ 'Mental Health',               'Health & Wellness', 'doctor',           [ 'mental_health_service', 'counselor', 'therapist' ] ],
        'pharmacy'                   => [ 'Pharmacy',                    'Health & Wellness', 'pharmacy',         [ 'pharmacy', 'drug_store' ] ],
        'hospital'                   => [ 'Hospital',                    'Health & Wellness', 'hospital',         [ 'hospital', 'urgent_care_facility', 'emergency_room_physician' ] ],
        'veterinarian'               => [ 'Veterinary',                  'Health & Wellness', 'veterinary_care',  [ 'veterinary_care', 'veterinarian' ] ],

        // ── Beauty & Personal Care ────────────────────────────────────────────
        'hair_salon'                 => [ 'Hair Salon',                  'Beauty & Personal Care', 'hair_care',     [ 'hair_salon', 'hair_care' ] ],
        'barbershop'                 => [ 'Barbershop',                  'Beauty & Personal Care', 'hair_care',     [ 'barber_shop' ] ],
        'nail_salon'                 => [ 'Nail Salon',                  'Beauty & Personal Care', 'beauty_salon',  [ 'nail_salon' ] ],
        'beauty_salon'               => [ 'Beauty Salon',                'Beauty & Personal Care', 'beauty_salon',  [ 'beauty_salon' ] ],
        'tattoo_shop'                => [ 'Tattoo & Piercing',           'Beauty & Personal Care', 'beauty_salon',  [ 'tattoo_shop' ] ],
        'tanning_salon'              => [ 'Tanning Salon',               'Beauty & Personal Care', 'beauty_salon',  [ 'tanning_studio' ] ],

        // ── Retail ────────────────────────────────────────────────────────────
        'clothing_store'             => [ 'Clothing & Apparel',          'Retail', 'clothing_store',       [ 'clothing_store' ] ],
        'shoe_store'                 => [ 'Shoe Store',                  'Retail', 'shoe_store',           [ 'shoe_store' ] ],
        'jewelry_store'              => [ 'Jewelry',                     'Retail', 'jewelry_store',        [ 'jewelry_store' ] ],
        'electronics_store'          => [ 'Electronics',                 'Retail', 'electronics_store',    [ 'electronics_store' ] ],
        'furniture_store'            => [ 'Furniture',                   'Retail', 'furniture_store',      [ 'furniture_store', 'home_goods_store' ] ],
        'grocery_store'              => [ 'Grocery & Supermarket',       'Retail', 'grocery_or_supermarket', [ 'grocery_or_supermarket', 'supermarket', 'food_store', 'convenience_store' ] ],
        'book_store'                 => [ 'Bookstore',                   'Retail', 'book_store',           [ 'book_store' ] ],
        'flower_shop'                => [ 'Florist',                     'Retail', 'florist',              [ 'florist' ] ],
        'gift_shop'                  => [ 'Gift Shop',                   'Retail', 'gift_shop',            [ 'gift_shop' ] ],
        'toy_store'                  => [ 'Toy Store',                   'Retail', 'toy_store',            [ 'toy_store' ] ],
        'pet_store'                  => [ 'Pet Store',                   'Retail', 'pet_store',            [ 'pet_store' ] ],
        'sporting_goods'             => [ 'Sporting Goods',              'Retail', 'sporting_goods_store', [ 'sporting_goods_store' ] ],
        'liquor_store'               => [ 'Liquor Store',                'Retail', 'liquor_store',         [ 'liquor_store' ] ],
        'hardware_store'             => [ 'Hardware Store',              'Retail', 'hardware_store',       [ 'hardware_store' ] ],
        'pharmacy_retail'            => [ 'Drugstore',                   'Retail', 'pharmacy',             [ 'drugstore' ] ],

        // ── Automotive ────────────────────────────────────────────────────────
        'car_dealer'                 => [ 'Car Dealership',              'Automotive', 'car_dealer',        [ 'car_dealer' ] ],
        'car_repair'                 => [ 'Auto Repair',                 'Automotive', 'car_repair',        [ 'car_repair', 'auto_repair' ] ],
        'car_wash'                   => [ 'Car Wash',                    'Automotive', 'car_wash',          [ 'car_wash' ] ],
        'gas_station'                => [ 'Gas Station',                 'Automotive', 'gas_station',       [ 'gas_station' ] ],
        'tire_shop'                  => [ 'Tire Shop',                   'Automotive', 'car_repair',        [ 'tire_shop' ] ],
        'auto_parts'                 => [ 'Auto Parts',                  'Automotive', 'car_repair',        [ 'auto_parts_store' ] ],
        'parking'                    => [ 'Parking',                     'Automotive', 'parking',           [ 'parking' ] ],

        // ── Home & Professional Services ──────────────────────────────────────
        'plumber'                    => [ 'Plumbing',                    'Home Services', 'plumber',         [ 'plumber' ] ],
        'electrician'                => [ 'Electrical',                  'Home Services', 'electrician',     [ 'electrician' ] ],
        'hvac'                       => [ 'HVAC',                        'Home Services', 'plumber',         [ 'heating_contractor', 'roofing_contractor' ] ],
        'locksmith'                  => [ 'Locksmith',                   'Home Services', 'locksmith',       [ 'locksmith' ] ],
        'moving_company'             => [ 'Moving Company',              'Home Services', 'moving_company',  [ 'moving_company', 'storage' ] ],
        'cleaning_service'           => [ 'Cleaning Service',            'Home Services', 'general_contractor', [ 'cleaning_service' ] ],
        'landscaping'                => [ 'Landscaping',                 'Home Services', 'general_contractor', [ 'landscaping' ] ],
        'pest_control'               => [ 'Pest Control',                'Home Services', 'general_contractor', [ 'pest_control_service' ] ],
        'contractor'                 => [ 'General Contractor',          'Home Services', 'general_contractor', [ 'general_contractor' ] ],
        'painter'                    => [ 'Painter',                     'Home Services', 'painter',         [ 'painter' ] ],
        'real_estate'                => [ 'Real Estate',                 'Home Services', 'real_estate_agency', [ 'real_estate_agency' ] ],

        // ── Professional & Financial Services ─────────────────────────────────
        'lawyer'                     => [ 'Law Firm',                    'Professional Services', 'lawyer',          [ 'lawyer' ] ],
        'accountant'                 => [ 'Accounting',                  'Professional Services', 'accounting',      [ 'accounting', 'accountant' ] ],
        'insurance'                  => [ 'Insurance',                   'Professional Services', 'insurance_agency', [ 'insurance_agency' ] ],
        'bank'                       => [ 'Bank',                        'Professional Services', 'bank',            [ 'bank' ] ],
        'atm'                        => [ 'ATM',                         'Professional Services', 'atm',             [ 'atm' ] ],
        'financial_advisor'          => [ 'Financial Services',          'Professional Services', 'finance',         [ 'finance', 'financial_advisor' ] ],
        'travel_agency'              => [ 'Travel Agency',               'Professional Services', 'travel_agency',   [ 'travel_agency' ] ],

        // ── Education ─────────────────────────────────────────────────────────
        'school'                     => [ 'School',                      'Education', 'school',             [ 'school', 'primary_school', 'secondary_school' ] ],
        'university'                 => [ 'University / College',        'Education', 'university',         [ 'university', 'college' ] ],
        'tutoring'                   => [ 'Tutoring',                    'Education', 'school',             [ 'tutoring_service' ] ],
        'driving_school'             => [ 'Driving School',              'Education', 'school',             [ 'driving_school' ] ],
        'childcare'                  => [ 'Childcare',                   'Education', 'school',             [ 'child_care_agency', 'preschool' ] ],

        // ── Entertainment & Recreation ────────────────────────────────────────
        'movie_theater'              => [ 'Movie Theater',               'Entertainment', 'movie_theater',   [ 'movie_theater' ] ],
        'museum'                     => [ 'Museum',                      'Entertainment', 'museum',          [ 'museum' ] ],
        'park'                       => [ 'Park & Recreation',           'Entertainment', 'park',            [ 'park', 'campground' ] ],
        'bowling'                    => [ 'Bowling Alley',               'Entertainment', 'bowling_alley',   [ 'bowling_alley' ] ],
        'arcade'                     => [ 'Arcade & Gaming',             'Entertainment', 'amusement_park',  [ 'amusement_park', 'game_center' ] ],
        'golf'                       => [ 'Golf',                        'Entertainment', 'golf_course',     [ 'golf_course', 'golf_club' ] ],
        'casino'                     => [ 'Casino',                      'Entertainment', 'casino',          [ 'casino' ] ],
        'event_venue'                => [ 'Event Venue',                 'Entertainment', 'event_venue',     [ 'event_venue', 'banquet_hall', 'wedding_venue' ] ],
        'library'                    => [ 'Library',                     'Entertainment', 'library',         [ 'library' ] ],

        // ── Lodging & Travel ──────────────────────────────────────────────────
        'hotel'                      => [ 'Hotel',                       'Lodging & Travel', 'lodging',     [ 'lodging', 'hotel', 'motel', 'resort_hotel' ] ],
        'hostel'                     => [ 'Hostel',                      'Lodging & Travel', 'lodging',     [ 'hostel' ] ],
        'campground'                 => [ 'Campground',                  'Lodging & Travel', 'campground',  [ 'campground', 'rv_park' ] ],
        'airport'                    => [ 'Airport',                     'Lodging & Travel', 'airport',     [ 'airport' ] ],
        'transit_station'            => [ 'Transit',                     'Lodging & Travel', 'transit_station', [ 'transit_station', 'train_station', 'bus_station' ] ],

        // ── Pet Services ──────────────────────────────────────────────────────
        'dog_grooming'               => [ 'Dog Grooming',                'Pet Services', 'veterinary_care', [ 'dog_groomer' ] ],
        'pet_boarding'               => [ 'Pet Boarding',                'Pet Services', 'veterinary_care', [ 'kennel', 'pet_boarding_service' ] ],

        // ── Technology & Printing ─────────────────────────────────────────────
        'computer_repair'            => [ 'Computer Repair',             'Technology', 'electronics_store', [ 'computer_store', 'computer_repair_service' ] ],
        'print_shop'                 => [ 'Print Shop',                  'Technology', 'store',             [ 'print_shop' ] ],
        'shipping'                   => [ 'Shipping & Courier',          'Technology', 'storage',           [ 'courier_service', 'shipping_service' ] ],
        'post_office'                => [ 'Post Office',                 'Technology', 'post_office',       [ 'post_office' ] ],

        // ── Religious & Community ─────────────────────────────────────────────
        'church'                     => [ 'Church',                      'Community', 'church',             [ 'church' ] ],
        'mosque'                     => [ 'Mosque',                      'Community', 'mosque',             [ 'mosque' ] ],
        'synagogue'                  => [ 'Synagogue',                   'Community', 'synagogue',          [ 'synagogue' ] ],
        'cemetery'                   => [ 'Cemetery',                    'Community', 'cemetery',           [ 'cemetery' ] ],
        'funeral_home'               => [ 'Funeral Home',                'Community', 'funeral_home',       [ 'funeral_home' ] ],
        'government'                 => [ 'Government Office',           'Community', 'local_government_office', [ 'local_government_office', 'city_hall', 'courthouse' ] ],
        'police'                     => [ 'Police Station',              'Community', 'police',             [ 'police' ] ],
        'fire_station'               => [ 'Fire Station',                'Community', 'fire_station',       [ 'fire_station' ] ],
        'non_profit'                 => [ 'Non-Profit',                  'Community', 'establishment',      [ 'non_profit_organization' ] ],
    ];

    /**
     * Resolve a Google types array to our normalized category.
     *
     * Returns array:
     *   'key'          => our taxonomy key (e.g. 'mexican_restaurant')
     *   'label'        => display label (e.g. 'Mexican Restaurant')
     *   'parent'       => parent industry (e.g. 'Food & Drink')
     *   'search_type'  => Google type string for competitor search
     *   'matched_type' => the raw Google type that triggered the match (for mismatch detection)
     *   'all_types'    => original Google types array (for mismatch analysis)
     */
    public static function resolve( array $google_types ): array {
        // Build a reverse lookup: google_type_string → taxonomy_key
        // More specific keys (longer google_type strings) win ties.
        $best_key        = null;
        $best_specificity = -1;
        $matched_type    = null;

        foreach ( $google_types as $raw_type ) {
            foreach ( self::$taxonomy as $key => $entry ) {
                if ( in_array( $raw_type, $entry[3], true ) ) {
                    // Specificity = length of the matching google type string
                    // longer = more specific (e.g. 'mexican_restaurant' > 'restaurant')
                    $specificity = strlen( $raw_type );
                    if ( $specificity > $best_specificity ) {
                        $best_specificity = $specificity;
                        $best_key         = $key;
                        $matched_type     = $raw_type;
                    }
                }
            }
        }

        if ( $best_key ) {
            $entry = self::$taxonomy[ $best_key ];
            return [
                'key'          => $best_key,
                'label'        => $entry[0],
                'parent'       => $entry[1],
                'search_type'  => $entry[2],
                'matched_type' => $matched_type,
                'all_types'    => $google_types,
            ];
        }

        // No match — fall back to establishment
        return [
            'key'          => 'unknown',
            'label'        => 'Business',
            'parent'       => 'General',
            'search_type'  => 'establishment',
            'matched_type' => null,
            'all_types'    => $google_types,
        ];
    }

    /**
     * Returns true if the resolved category seems mismatched with the raw types.
     * Example: business has 'restaurant' types but matched only on generic 'food' —
     * suggests their primary category in Google isn't set to a specific cuisine type.
     *
     * Used to surface a "your Google category may not be specific enough" recommendation.
     */
    public static function is_vague_match( array $resolved ): bool {
        $vague_types = [ 'establishment', 'food', 'store', 'point_of_interest', 'health' ];
        return $resolved['key'] === 'unknown'
            || in_array( $resolved['matched_type'], $vague_types, true );
    }

    /**
     * Return all parent industry labels (for display/admin purposes).
     */
    public static function parents(): array {
        $parents = [];
        foreach ( self::$taxonomy as $entry ) {
            $parents[ $entry[1] ] = true;
        }
        return array_keys( $parents );
    }
}
