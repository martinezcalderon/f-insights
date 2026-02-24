<?php
/**
 * Google Places API Scanner
 */

if (!defined('ABSPATH')) {
    exit;
}

class FI_Scanner {
    
    private $api_key;
    private $cache_duration;
    
    public function __construct() {
        $this->api_key = FI_Crypto::get_key( FI_Crypto::GOOGLE_KEY_OPTION );
        $this->cache_duration = get_option('fi_cache_duration', 86400);
    }
    
    /**
     * Search for a business by name, optionally biased toward a lat/lng location.
     *
     * @param string $business_name  Search query.
     * @param float  $lat            User's latitude  (0 = not available).
     * @param float  $lng            User's longitude (0 = not available).
     */
    public function search_business($business_name, $lat = 0.0, $lng = 0.0) {
        FI_Logger::info('Starting business search', array(
            'query' => $business_name,
            'lat'   => $lat,
            'lng'   => $lng,
        ));

        if (empty($this->api_key)) {
            FI_Logger::error('Google API key not configured');
            return new WP_Error('no_api_key', __('Google API key not configured', 'f-insights'));
        }

        // Build a location-aware cache key.
        // Round to 2 decimal places (~1 km precision) so nearby users share cache
        // while users in different cities get distinct results.
        $location_key = ($lat != 0.0 && $lng != 0.0)
            ? round($lat, 2) . ',' . round($lng, 2)
            : 'global';
        $cache_key = 'fi_search_' . md5($business_name . $location_key);

        $cached = $this->get_cache($cache_key);
        if ($cached !== false) {
            FI_Logger::info('Returning cached search results');
            return $cached;
        }

        // Use Google Places API Text Search
        $url = 'https://places.googleapis.com/v1/places:searchText';

        $body = array(
            'textQuery'      => $business_name,
            'maxResultCount' => 8,
        );

        // Soft geo-bias: bubbles up nearby results without excluding distant ones.
        // This lets users find national chains at their local branch while still
        // returning the right business if coordinates aren't available.
        if ($lat != 0.0 && $lng != 0.0) {
            // Get admin-configured autocomplete radius in miles, default to 10
            $radius_miles = floatval(get_option('fi_autocomplete_radius_miles', 10));
            $radius_meters = $radius_miles * 1609.34; // Convert miles to meters
            
            $body['locationBias'] = array(
                'circle' => array(
                    'center' => array(
                        'latitude'  => $lat,
                        'longitude' => $lng,
                    ),
                    'radius' => $radius_meters,
                ),
            );
            FI_Logger::info('Applying geo-bias to search', array(
                'lat' => $lat, 
                'lng' => $lng, 
                'radius_miles' => $radius_miles,
                'radius_meters' => $radius_meters
            ));
        }
        
        FI_Logger::api_request('Google Places', 'searchText', $body);
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type'     => 'application/json',
                'X-Goog-Api-Key'   => $this->api_key,
                'X-Goog-FieldMask' => 'places.id,places.displayName,places.formattedAddress,places.location,places.rating,places.userRatingCount,places.types,places.websiteUri,places.regularOpeningHours,places.businessStatus',
                // Send the site URL as Referer so server-side requests satisfy
                // any HTTP referrer restriction set on the Google API key.
                'Referer'          => home_url( '/' ),
            ),
            'body'    => json_encode($body),
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            FI_Logger::error('Google API request failed', $response->get_error_message());
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        FI_Logger::api_response('Google Places', 'searchText', $response_code, substr($response_body, 0, 500));
        
        if ($response_code !== 200) {
            $error_data = json_decode($response_body, true);
            $error_message = isset($error_data['error']['message']) ? $error_data['error']['message'] : 'Unknown error';
            FI_Logger::error('Google API returned error', array('code' => $response_code, 'message' => $error_message));
            return new WP_Error('api_error', sprintf(__('Google API error: %s', 'f-insights'), $error_message));
        }
        
        $data = json_decode($response_body, true);
        
        if (empty($data['places'])) {
            FI_Logger::warning('No search results found');
            return new WP_Error('no_results', __('No businesses found', 'f-insights'));
        }
        
        $results = array();
        foreach ($data['places'] as $place) {
            // Calculate distance if user location is available
            $distance = null;
            if ($lat != 0.0 && $lng != 0.0 && isset($place['location'])) {
                $distance = $this->calculate_distance(
                    $lat,
                    $lng,
                    $place['location']['latitude'],
                    $place['location']['longitude']
                );
            }
            
            $results[] = array(
                'place_id' => $place['id'] ?? '',
                'name' => $place['displayName']['text'] ?? '',
                'address' => $place['formattedAddress'] ?? '',
                'rating' => $place['rating'] ?? 0,
                'user_ratings_total' => $place['userRatingCount'] ?? 0,
                'types' => $place['types'] ?? array(),
                'website' => $place['websiteUri'] ?? '',
                'location' => $place['location'] ?? array(),
                'business_status' => $place['businessStatus'] ?? 'OPERATIONAL',
                'distance_miles' => $distance,
            );
        }
        
        FI_Logger::info('Search completed successfully', array('result_count' => count($results)));
        
        // Cache results
        $this->set_cache($cache_key, $results);
        
        return $results;
    }
    
    /**
     * Get detailed business information
     */
    public function get_business_details($place_id) {
        FI_Logger::info('Fetching business details', array('place_id' => $place_id));
        
        if (empty($this->api_key)) {
            FI_Logger::error('Google API key not configured');
            return new WP_Error('no_api_key', __('Google API key not configured', 'f-insights'));
        }
        
        // Check cache
        $cache_key = 'fi_details_' . md5($place_id);
        $cached = $this->get_cache($cache_key);
        if ($cached !== false) {
            FI_Logger::info('Returning cached business details - CACHE HIT', array(
                'has_competitors' => isset($cached['competitors']),
                'competitor_count' => isset($cached['competitors']) ? count($cached['competitors']) : 0,
                'has_location' => isset($cached['location'])
            ));
            return $cached;
        }
        
        FI_Logger::info('No cache found - fetching fresh data from API');
        
        // Get place details using the new Places API
        // Place ID must be prefixed with "places/" per the Places API (New) resource format
        $url = 'https://places.googleapis.com/v1/places/' . $place_id;
        
        FI_Logger::api_request('Google Places', 'getPlace/' . $place_id);
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Content-Type'     => 'application/json',
                'X-Goog-Api-Key'   => $this->api_key,
                'X-Goog-FieldMask' => 'id,displayName,formattedAddress,location,internationalPhoneNumber,websiteUri,rating,userRatingCount,reviews,photos,regularOpeningHours,types,businessStatus,priceLevel,editorialSummary',
                'Referer'          => home_url( '/' ),
            ),
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            FI_Logger::error('Google API request failed', $response->get_error_message());
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        FI_Logger::api_response('Google Places', 'getPlace', $response_code, substr($response_body, 0, 500));
        
        if ($response_code !== 200) {
            $error_data = json_decode($response_body, true);
            $error_message = isset($error_data['error']['message']) ? $error_data['error']['message'] : 'Unknown error';
            FI_Logger::error('Google API returned error', array('code' => $response_code, 'message' => $error_message, 'body' => $response_body));
            return new WP_Error('api_error', sprintf(__('Could not fetch business details: %s', 'f-insights'), $error_message));
        }
        
        $place = json_decode($response_body, true);
        
        if (empty($place['id'])) {
            FI_Logger::error('Invalid response structure', $place);
            return new WP_Error('no_details', __('Could not fetch business details', 'f-insights'));
        }
        
        $details = array(
            'place_id' => $place['id'] ?? '',
            'name' => $place['displayName']['text'] ?? '',
            'address' => $place['formattedAddress'] ?? '',
            'location' => $place['location'] ?? null,
            'phone' => $place['internationalPhoneNumber'] ?? '',
            'website' => $place['websiteUri'] ?? '',
            'rating' => $place['rating'] ?? 0,
            'user_ratings_total' => $place['userRatingCount'] ?? 0,
            'reviews' => $this->parse_reviews($place['reviews'] ?? array()),
            'photos' => $this->parse_photos($place['photos'] ?? array()),
            'opening_hours' => $this->parse_opening_hours($place['regularOpeningHours'] ?? array()),
            'types' => $place['types'] ?? array(),
            'business_status' => $place['businessStatus'] ?? 'OPERATIONAL',
            'price_level' => $place['priceLevel'] ?? null,
            'editorial_summary' => $place['editorialSummary']['text'] ?? '',
        );
        
        FI_Logger::info('Business details fetched successfully', array(
            'has_location' => isset($details['location']),
            'location' => $details['location']
        ));
        
        // Get nearby competitors — pass the location we already fetched above so
        // get_nearby_competitors() doesn't need to make a second API call for it.
        $details['competitors'] = $this->get_nearby_competitors(
            $place_id,
            $place['types']          ?? array(),
            $place['location']       ?? null,
            $place['priceLevel']     ?? null,
            $place['userRatingCount'] ?? 0,
            $place['displayName']['text'] ?? ''
        );
        
        FI_Logger::info('Competitor fetch completed', array(
            'competitor_count' => count($details['competitors'])
        ));
        
        // Cache details
        $this->set_cache($cache_key, $details);
        
        return $details;
    }
    
    /**
     * Parse reviews from API response
     */
    private function parse_reviews($reviews) {
        $parsed = array();
        
        foreach (array_slice($reviews, 0, 10) as $review) {
            $parsed[] = array(
                'author' => $review['authorAttribution']['displayName'] ?? 'Anonymous',
                'rating' => $review['rating'] ?? 0,
                'text' => $review['text']['text'] ?? '',
                'time' => $review['publishTime'] ?? '',
                'relative_time' => $review['relativePublishTimeDescription'] ?? '',
            );
        }
        
        return $parsed;
    }
    
    /**
     * Parse photos from API response
     */
    private function parse_photos($photos) {
        $parsed = array();
        
        foreach (array_slice($photos, 0, 20) as $photo) {
            $parsed[] = array(
                'name' => $photo['name'] ?? '',
                'width' => $photo['widthPx'] ?? 0,
                'height' => $photo['heightPx'] ?? 0,
            );
        }
        
        return $parsed;
    }
    
    /**
     * Parse opening hours
     */
    private function parse_opening_hours($hours) {
        if (empty($hours)) {
            return array(
                'open_now' => null,
                'weekday_text' => array(),
            );
        }
        
        return array(
            'open_now' => $hours['openNow'] ?? null,
            'weekday_text' => $hours['weekdayDescriptions'] ?? array(),
        );
    }
    
    /**
     * Business category family groups — used to broaden the competitor search when
     * an exact-type search returns too few results.
     *
     * Covers all 562 business types from category-map.php across 30+ industries.
     *
     * The strategy has three tiers (attempted in order):
     *   1. Exact type search  — e.g. "yoga_studio"
     *   2. Family type search — all sibling types in the same business category
     *      (e.g. yoga_studio + pilates + barre + dance_studio + …)
     *      Results are filtered: a result is kept only if it shares at least
     *      one family type with the subject business (category affinity filter).
     *   3. Generic category fallback — last resort; affinity filter still applies.
     */
    private function get_business_category_family($specific_types) {
        $families = array(
            
            // ═══════════════════════════════════════════════════════════════════
            // FOOD & BEVERAGE
            // ═══════════════════════════════════════════════════════════════════
            
            // Latin American
            'latin_american' => array(
                'mexican_restaurant', 'taqueria', 'tex_mex_restaurant',
                'salvadoran_restaurant', 'guatemalan_restaurant', 'honduran_restaurant',
                'colombian_restaurant', 'venezuelan_restaurant', 'peruvian_restaurant',
                'ecuadorian_restaurant', 'bolivian_restaurant', 'chilean_restaurant',
                'argentinian_restaurant', 'cuban_restaurant', 'caribbean_restaurant',
                'haitian_restaurant', 'jamaican_restaurant', 'brazilian_restaurant',
                'churrascaria',
            ),
            
            // South Asian
            'south_asian' => array(
                'indian_restaurant', 'north_indian_restaurant', 'south_indian_restaurant',
                'pakistani_restaurant', 'bangladeshi_restaurant', 'nepali_restaurant',
                'sri_lankan_restaurant', 'buffet_indian',
            ),
            
            // East Asian
            'east_asian' => array(
                'chinese_restaurant', 'cantonese_restaurant', 'szechuan_restaurant', 'dim_sum_restaurant',
                'japanese_restaurant', 'sushi_restaurant', 'ramen_restaurant', 'izakaya', 'teppanyaki',
                'korean_restaurant', 'korean_bbq_restaurant',
                'mongolian_restaurant', 'taiwanese_restaurant', 'hong_kong_restaurant',
            ),
            
            // Southeast Asian
            'southeast_asian' => array(
                'thai_restaurant', 'vietnamese_restaurant', 'pho_restaurant',
                'filipino_restaurant', 'indonesian_restaurant', 'malaysian_restaurant',
                'singaporean_restaurant', 'burmese_restaurant', 'cambodian_restaurant', 'laotian_restaurant',
            ),
            
            // Middle Eastern & North African
            'middle_eastern' => array(
                'lebanese_restaurant', 'persian_restaurant', 'israeli_restaurant',
                'moroccan_restaurant', 'egyptian_restaurant', 'syrian_restaurant',
                'iraqi_restaurant', 'yemeni_restaurant', 'afghan_restaurant',
                'georgian_restaurant', 'armenian_restaurant',
                'falafel_restaurant', 'kebab_restaurant', 'mediterranean_restaurant',
                'middle_eastern_restaurant', 'shawarma_restaurant',
            ),
            
            // American
            'american' => array(
                'american_restaurant', 'burger_restaurant', 'bbq_restaurant',
                'southern_restaurant', 'diner', 'brunch_restaurant',
                'new_american_restaurant', 'farm_to_table_restaurant', 'steakhouse',
                'seafood_restaurant', 'oyster_bar', 'lobster_shack', 'cajun_restaurant',
            ),
            
            // Italian
            'italian' => array(
                'italian_restaurant', 'pizza_restaurant', 'trattoria', 'pizzeria',
            ),
            
            // European
            'european' => array(
                'french_restaurant', 'spanish_restaurant', 'tapas_restaurant',
                'greek_restaurant', 'portuguese_restaurant', 'german_restaurant',
                'british_restaurant', 'irish_pub', 'polish_restaurant', 'ukrainian_restaurant',
                'russian_restaurant', 'hungarian_restaurant', 'czech_restaurant',
                'scandinavian_restaurant', 'swiss_restaurant', 'austrian_restaurant',
                'belgian_restaurant', 'dutch_restaurant', 'romanian_restaurant', 'turkish_restaurant',
            ),
            
            // African
            'african' => array(
                'ethiopian_restaurant', 'eritrean_restaurant', 'nigerian_restaurant',
                'west_african_restaurant', 'east_african_restaurant', 'south_african_restaurant',
                'senegalese_restaurant', 'ghanaian_restaurant', 'african_restaurant',
            ),
            
            // Specialty Diets
            'specialty_diet' => array(
                'vegetarian_restaurant', 'vegan_restaurant', 'raw_food_restaurant',
                'gluten_free_restaurant', 'halal_restaurant', 'kosher_restaurant',
                'organic_restaurant', 'paleo_restaurant', 'keto_restaurant',
            ),
            
            // Restaurant Formats
            'restaurant_formats' => array(
                'buffet_restaurant', 'food_truck', 'pop_up_restaurant',
                'ghost_kitchen', 'fine_dining', 'fast_casual', 'counter_service',
            ),
            
            // Bars & Nightlife
            'bars_nightlife' => array(
                'bar', 'sports_bar', 'cocktail_bar', 'wine_bar', 'craft_beer_bar',
                'dive_bar', 'rooftop_bar', 'karaoke_bar', 'gastropub',
                'nightclub', 'music_bar', 'lounge', 'pub', 'beer_garden',
                'brewery', 'winery', 'distillery', 'tasting_room', 'brewpub',
            ),
            
            // Coffee & Tea
            'coffee_tea' => array(
                'coffee_shop', 'cafe', 'tea_house', 'specialty_coffee',
                'bakery_cafe', 'internet_cafe', 'cat_cafe',
            ),
            
            // Bakery & Sweets
            'bakery_sweets' => array(
                'bakery', 'patisserie', 'donut_shop', 'cupcake_shop',
                'macaron_shop', 'cookie_shop', 'cake_shop', 'dessert_shop',
                'ice_cream_shop', 'gelato_shop', 'frozen_yogurt_shop',
                'candy_store', 'chocolate_shop', 'crepe_shop', 'waffle_shop',
            ),
            
            // Fast Food
            'fast_food' => array(
                'fast_food', 'sandwich_shop', 'deli', 'sub_shop',
                'hot_dog_stand', 'food_court', 'food_hall',
            ),
            
            // ═══════════════════════════════════════════════════════════════════
            // RETAIL
            // ═══════════════════════════════════════════════════════════════════
            
            // Apparel - General
            'apparel' => array(
                'clothing_store', 'boutique', 'fashion_boutique',
                'womens_clothing_store', 'mens_clothing_store', 'childrens_clothing_store',
                'maternity_store', 'plus_size_clothing',
            ),
            
            // Apparel - Specialty
            'apparel_specialty' => array(
                'activewear_store', 'swimwear_store', 'lingerie_store',
                'formal_wear_store', 'bridal_shop', 'costume_shop', 'uniform_store',
            ),
            
            // Footwear
            'footwear' => array(
                'shoe_store', 'sneaker_store', 'boot_store', 'children_shoe_store',
            ),
            
            // Accessories & Jewelry
            'accessories_jewelry' => array(
                'jewelry_store', 'fine_jewelry_store', 'costume_jewelry_store',
                'watch_store', 'sunglasses_store', 'handbag_store', 'hat_store',
            ),
            
            // Secondhand Fashion
            'secondhand_fashion' => array(
                'thrift_store', 'consignment_shop', 'vintage_clothing_store',
                'pawn_shop', 'antique_shop',
            ),
            
            // Tailoring & Alterations
            'tailoring' => array(
                'tailor', 'alterations', 'dry_cleaning', 'embroidery_shop',
            ),
            
            // Home & Furniture
            'home_furniture' => array(
                'furniture_store', 'mattress_store', 'home_goods_store',
                'kitchen_supply_store', 'bath_store', 'candle_store',
                'lighting_store', 'rug_store', 'frame_shop',
            ),
            
            // Home Improvement
            'home_improvement' => array(
                'hardware_store', 'flooring_store', 'wallpaper_store',
                'appliance_store', 'garden_center', 'pool_supply_store',
            ),
            
            // Electronics & Tech
            'electronics' => array(
                'electronics_store', 'computer_store', 'mobile_phone_store',
                'camera_store', 'audio_store', 'video_game_store',
                'drone_store', 'home_theater_store', 'telecom_store',
            ),
            
            // Books & Media
            'books_media' => array(
                'book_store', 'comic_book_store', 'music_store',
                'instrument_store', 'record_store',
            ),
            
            // Toys & Games
            'toys_games' => array(
                'toy_store', 'hobby_store', 'board_game_store',
            ),
            
            // Crafts & Arts
            'crafts_arts' => array(
                'craft_store', 'yarn_store', 'art_supply_store',
            ),
            
            // Sporting Goods
            'sporting_goods' => array(
                'sporting_goods_store', 'golf_store', 'ski_snowboard_shop',
                'surf_shop', 'outdoor_store', 'bicycle_store',
                'fishing_tackle_shop', 'hunting_store', 'gun_shop',
            ),
            
            // Specialty Shops
            'specialty_shops' => array(
                'tobacco_shop', 'vape_store', 'cannabis_dispensary',
                'party_supply_store', 'office_supply_store',
                'new_age_store', 'religious_goods_store',
            ),
            
            // Grocery & Food Retail
            'grocery' => array(
                'grocery_store', 'supermarket', 'convenience_store',
                'international_grocery', 'health_food_store', 'organic_grocery',
                'butcher', 'seafood_market', 'farmers_market',
                'gourmet_food_store', 'cheese_shop', 'wine_shop', 'liquor_store',
            ),
            
            // Pharmacy & Health Retail
            'pharmacy_health_retail' => array(
                'pharmacy', 'drugstore', 'supplement_store', 'vitamin_shop',
                'medical_supply_store',
            ),
            
            // Gifts & Souvenirs
            'gifts_souvenirs' => array(
                'gift_shop', 'souvenir_shop', 'flower_shop', 'florist',
                'card_store', 'stationery_store', 'balloon_shop',
            ),
            
            // ═══════════════════════════════════════════════════════════════════
            // HEALTH & MEDICAL
            // ═══════════════════════════════════════════════════════════════════
            
            // Primary Care
            'primary_care' => array(
                'doctor', 'primary_care_physician', 'family_medicine',
                'internal_medicine', 'pediatrician', 'geriatrician',
            ),
            
            // Urgent & Emergency
            'urgent_emergency_care' => array(
                'urgent_care', 'walk_in_clinic', 'emergency_room',
                'medical_clinic', 'community_health_center',
            ),
            
            // Dental
            'dental' => array(
                'dentist', 'orthodontist', 'cosmetic_dentist',
                'pediatric_dentist', 'oral_surgeon', 'dental_implants',
                'endodontist', 'periodontist', 'dental_hygienist',
            ),
            
            // Vision
            'vision' => array(
                'optometrist', 'ophthalmologist', 'optical_store',
                'eye_care', 'contact_lens_store', 'lasik_center',
            ),
            
            // Medical Specialists
            'medical_specialists' => array(
                'dermatologist', 'cardiologist', 'orthopedist',
                'ent_doctor', 'allergist', 'gastroenterologist',
                'neurologist', 'pulmonologist', 'endocrinologist',
                'rheumatologist', 'oncologist', 'urologist',
            ),
            
            // Mental Health
            'mental_health' => array(
                'psychiatrist', 'psychologist', 'therapist',
                'counselor', 'marriage_counselor', 'addiction_treatment',
                'behavioral_health', 'mental_health_clinic',
            ),
            
            // Women's Health
            'womens_health' => array(
                'obgyn', 'midwife', 'fertility_clinic',
                'womens_health_clinic', 'lactation_consultant',
            ),
            
            // Alternative Medicine
            'alternative_medicine' => array(
                'acupuncture', 'chiropractor', 'naturopath',
                'homeopath', 'herbalist', 'holistic_medicine',
                'traditional_chinese_medicine', 'ayurveda',
            ),
            
            // Therapy & Rehabilitation
            'therapy_rehab' => array(
                'physical_therapy', 'occupational_therapy', 'speech_therapy',
                'sports_medicine', 'rehabilitation_center',
                'pain_management', 'massage_therapy',
            ),
            
            // Diagnostic & Testing
            'diagnostic' => array(
                'laboratory', 'imaging_center', 'radiology',
                'mri_center', 'blood_lab', 'diagnostic_center',
            ),
            
            // ═══════════════════════════════════════════════════════════════════
            // WELLNESS & BEAUTY
            // ═══════════════════════════════════════════════════════════════════
            
            // Spas & Wellness
            'spa_wellness' => array(
                'spa', 'day_spa', 'med_spa', 'wellness_center',
                'float_therapy', 'sauna', 'cryotherapy', 'iv_therapy',
                'infrared_sauna', 'salt_cave', 'hammam',
            ),
            
            // Massage
            'massage' => array(
                'massage_therapy', 'thai_massage', 'reflexology',
                'deep_tissue_massage', 'sports_massage', 'prenatal_massage',
            ),
            
            // Hair Services
            'hair_services' => array(
                'hair_salon', 'barbershop', 'hair_stylist',
                'hair_extension_salon', 'blowout_bar', 'hair_color_specialist',
                'mens_salon', 'kids_haircut',
            ),
            
            // Nails & Beauty
            'nails_beauty' => array(
                'nail_salon', 'nail_spa', 'eyelash_salon',
                'eyebrow_threading', 'waxing_salon', 'tanning_salon',
                'spray_tan', 'makeup_artist', 'permanent_makeup', 'microblading',
            ),
            
            // Skin & Body
            'skin_body' => array(
                'skincare_clinic', 'laser_hair_removal', 'botox_clinic',
                'cosmetic_surgery', 'plastic_surgeon', 'aesthetic_clinic',
            ),
            
            // ═══════════════════════════════════════════════════════════════════
            // AUTOMOTIVE
            // ═══════════════════════════════════════════════════════════════════
            
            // Auto Repair
            'auto_repair' => array(
                'auto_repair_shop', 'mechanic', 'transmission_repair',
                'brake_service', 'oil_change', 'tire_shop',
                'muffler_shop', 'alignment_shop', 'radiator_repair',
                'engine_repair', 'electrical_repair',
            ),
            
            // Auto Body
            'auto_body' => array(
                'auto_body_shop', 'collision_repair', 'dent_removal',
                'auto_painting', 'windshield_repair',
            ),
            
            // Auto Care & Detailing
            'auto_care' => array(
                'car_wash', 'auto_detailing', 'mobile_detailing',
                'ceramic_coating', 'window_tinting',
            ),
            
            // Auto Sales & Services
            'auto_sales_services' => array(
                'car_dealership', 'used_car_dealer', 'auto_parts_store',
                'accessories_shop', 'car_stereo_shop', 'smog_check',
            ),
            
            // ═══════════════════════════════════════════════════════════════════
            // PROFESSIONAL & FINANCIAL SERVICES
            // ═══════════════════════════════════════════════════════════════════
            
            // Legal
            'legal' => array(
                'lawyer', 'attorney', 'law_firm',
                'immigration_lawyer', 'family_lawyer', 'criminal_defense_lawyer',
                'personal_injury_lawyer', 'estate_planning_lawyer', 'tax_attorney',
                'notary_public', 'paralegal',
            ),
            
            // Financial
            'financial' => array(
                'accountant', 'tax_preparation', 'financial_advisor',
                'investment_advisor', 'wealth_management', 'cpa',
                'bookkeeping', 'payroll_service',
            ),
            
            // Insurance
            'insurance' => array(
                'insurance_agency', 'life_insurance', 'health_insurance',
                'auto_insurance', 'home_insurance', 'insurance_broker',
            ),
            
            // Real Estate
            'real_estate' => array(
                'real_estate_agency', 'real_estate_agent', 'property_management',
                'real_estate_broker', 'apartment_rental', 'commercial_real_estate',
            ),
            
            // Marketing & Creative
            'marketing_creative' => array(
                'marketing_agency', 'advertising_agency', 'graphic_design',
                'web_design', 'photography_studio', 'videographer',
                'printing_service', 'signage_shop',
            ),
            
            // Business Services
            'business_services' => array(
                'consulting', 'business_consultant', 'hr_consulting',
                'it_services', 'software_development', 'data_recovery',
                'computer_repair', 'office_equipment', 'copy_center',
            ),
            
            // ═══════════════════════════════════════════════════════════════════
            // HOME & TRADE SERVICES
            // ═══════════════════════════════════════════════════════════════════
            
            // Contractors & Repair
            'contractors_repair' => array(
                'general_contractor', 'remodeling', 'renovation',
                'handyman', 'carpenter', 'drywall', 'framing',
            ),
            
            // Plumbing & HVAC
            'plumbing_hvac' => array(
                'plumber', 'drain_cleaning', 'water_heater_repair',
                'hvac', 'air_conditioning', 'heating', 'furnace_repair',
            ),
            
            // Electrical
            'electrical' => array(
                'electrician', 'electrical_contractor', 'lighting_installation',
                'generator_installation',
            ),
            
            // Exterior Services
            'exterior_services' => array(
                'roofer', 'roofing_contractor', 'gutter_cleaning',
                'siding', 'window_installation', 'door_installation',
                'fence_installation', 'deck_builder',
            ),
            
            // Interior Services
            'interior_services' => array(
                'painter', 'painting_contractor', 'wallpaper_installer',
                'flooring_installer', 'tile_installation', 'cabinet_installer',
            ),
            
            // Landscaping & Outdoor
            'landscaping' => array(
                'landscaping', 'lawn_care', 'tree_service',
                'lawn_mowing', 'irrigation', 'hardscaping',
                'snow_removal', 'pest_control', 'exterminator',
            ),
            
            // Cleaning
            'cleaning' => array(
                'cleaning_service', 'maid_service', 'house_cleaning',
                'carpet_cleaning', 'pressure_washing', 'window_cleaning',
                'junk_removal', 'hoarding_cleanup',
            ),
            
            // Moving & Storage
            'moving_storage' => array(
                'moving_company', 'storage_facility', 'self_storage',
                'packing_service', 'furniture_delivery',
            ),
            
            // ═══════════════════════════════════════════════════════════════════
            // PERSONAL & LOCAL SERVICES
            // ═══════════════════════════════════════════════════════════════════
            
            // Personal Care Services
            'personal_care_services' => array(
                'dry_cleaning', 'laundromat', 'tailor', 'alterations',
                'shoe_repair', 'watch_repair', 'jewelry_repair',
            ),
            
            // Event Services
            'event_services' => array(
                'event_planner', 'wedding_planner', 'catering',
                'dj_service', 'live_band', 'photographer', 'videographer',
                'party_rental', 'photo_booth', 'florist',
            ),
            
            // Travel Services
            'travel_services' => array(
                'travel_agency', 'tour_operator', 'cruise_agency',
                'passport_photo', 'visa_service',
            ),
            
            // ═══════════════════════════════════════════════════════════════════
            // FITNESS & SPORTS
            // ═══════════════════════════════════════════════════════════════════
            
            // Gyms & Fitness Centers
            'gyms_fitness' => array(
                'gym', 'fitness_center', 'health_club',
                'crossfit_gym', 'boxing_gym', 'kickboxing_gym',
                'martial_arts_school', 'karate', 'jiu_jitsu', 'taekwondo',
                'personal_training', 'bootcamp_fitness',
            ),
            
            // Studio Fitness
            'studio_fitness' => array(
                'yoga_studio', 'pilates_studio', 'barre_studio',
                'cycling_studio', 'spin_class', 'dance_studio',
                'pole_fitness', 'aerial_yoga',
            ),
            
            // Sports Facilities
            'sports_facilities' => array(
                'tennis_club', 'tennis_courts', 'racquetball',
                'basketball_court', 'volleyball_court',
                'ice_rink', 'skating_rink', 'roller_skating',
                'skate_park', 'rock_climbing', 'bouldering_gym',
            ),
            
            // Aquatics
            'aquatics' => array(
                'swim_school', 'swim_lessons', 'swim_center',
                'lap_pool', 'diving_school',
            ),
            
            // Specialty Sports
            'specialty_sports' => array(
                'golf_course', 'driving_range', 'mini_golf',
                'batting_cage', 'soccer_complex', 'futsal',
                'shooting_range', 'archery', 'fencing',
                'equestrian', 'horseback_riding',
            ),
            
            // Outdoor Recreation
            'outdoor_recreation' => array(
                'ski_resort', 'snowboarding', 'tubing',
                'surf_school', 'kayaking', 'paddle_boarding',
                'zip_line', 'ropes_course', 'adventure_park',
            ),
            
            // ═══════════════════════════════════════════════════════════════════
            // EDUCATION & CHILDCARE
            // ═══════════════════════════════════════════════════════════════════
            
            // Childcare
            'childcare' => array(
                'child_care', 'daycare', 'preschool', 'pre_k',
                'after_school', 'babysitting', 'nanny_service',
            ),
            
            // K-12 Education
            'k12_education' => array(
                'private_school', 'elementary_school', 'middle_school',
                'high_school', 'charter_school', 'montessori_school',
            ),
            
            // Tutoring & Test Prep
            'tutoring' => array(
                'tutoring', 'test_prep', 'sat_prep', 'act_prep',
                'academic_tutoring', 'math_tutor', 'reading_tutor',
            ),
            
            // Language Learning
            'language_learning' => array(
                'language_school', 'esl', 'spanish_classes',
                'foreign_language', 'translation_service',
            ),
            
            // Arts & Music Education
            'arts_music_education' => array(
                'music_school', 'music_lessons', 'piano_lessons',
                'guitar_lessons', 'voice_lessons', 'drum_lessons',
                'art_school', 'art_classes', 'painting_classes',
                'pottery_classes', 'ceramics_studio',
            ),
            
            // Specialty Education
            'specialty_education' => array(
                'cooking_school', 'culinary_classes',
                'dance_school', 'ballet', 'ballroom_dancing',
                'coding_school', 'coding_bootcamp', 'stem_education',
                'vocational_school', 'trade_school', 'driving_school',
            ),
            
            // ═══════════════════════════════════════════════════════════════════
            // ENTERTAINMENT & RECREATION
            // ═══════════════════════════════════════════════════════════════════
            
            // Cinemas & Theaters
            'cinema_theater' => array(
                'movie_theater', 'cinema', 'imax',
                'drive_in_theater', 'outdoor_cinema',
            ),
            
            // Performing Arts
            'performing_arts' => array(
                'performing_arts_theater', 'theater', 'playhouse',
                'opera_house', 'concert_hall', 'symphony',
                'comedy_club', 'improv_theater',
            ),
            
            // Museums & Galleries
            'museums_galleries' => array(
                'museum', 'history_museum', 'science_museum',
                'art_museum', 'childrens_museum', 'planetarium',
                'art_gallery', 'exhibition_space',
            ),
            
            // Amusement & Attractions
            'amusement_attractions' => array(
                'amusement_park', 'theme_park', 'water_park',
                'aquarium', 'zoo', 'wildlife_park',
                'botanical_garden', 'observatory',
            ),
            
            // Gaming & Entertainment
            'gaming_entertainment' => array(
                'arcade', 'video_arcade', 'family_entertainment_center',
                'bowling_alley', 'billiards', 'pool_hall',
                'escape_room', 'laser_tag', 'paintball',
                'virtual_reality_arcade', 'axe_throwing', 'rage_room',
            ),
            
            // Casual Recreation
            'casual_recreation' => array(
                'mini_golf', 'putt_putt', 'go_kart',
                'trampoline_park', 'bingo_hall', 'casino',
            ),
            
            // Music & Nightlife Venues
            'music_nightlife_venues' => array(
                'live_music_venue', 'music_venue', 'concert_venue',
                'jazz_club', 'blues_club', 'karaoke_bar',
            ),
            
            // ═══════════════════════════════════════════════════════════════════
            // HOSPITALITY & LODGING
            // ═══════════════════════════════════════════════════════════════════
            
            // Hotels
            'hotels' => array(
                'hotel', 'luxury_hotel', 'resort',
                'boutique_hotel', 'budget_hotel', 'motel',
                'extended_stay_hotel', 'airport_hotel',
            ),
            
            // Alternative Lodging
            'alternative_lodging' => array(
                'bed_and_breakfast', 'inn', 'hostel',
                'vacation_rental', 'airbnb', 'glamping',
                'campground', 'rv_park',
            ),
            
            // Event Venues
            'event_venues' => array(
                'conference_center', 'convention_center',
                'wedding_venue', 'banquet_hall', 'event_venue',
                'meeting_room', 'coworking_space',
            ),
            
            // ═══════════════════════════════════════════════════════════════════
            // PETS & ANIMALS
            // ═══════════════════════════════════════════════════════════════════
            
            // Veterinary Care
            'veterinary' => array(
                'veterinary_care', 'veterinarian', 'vet_clinic',
                'animal_hospital', 'emergency_vet',
                'exotic_vet', 'holistic_vet', 'mobile_vet',
            ),
            
            // Pet Grooming & Care
            'pet_grooming_care' => array(
                'pet_groomer', 'dog_grooming', 'mobile_grooming',
                'pet_boarding', 'dog_boarding', 'kennel',
                'dog_daycare', 'cat_boarding',
            ),
            
            // Pet Training
            'pet_training' => array(
                'dog_training', 'puppy_training', 'obedience_school',
                'service_dog_training', 'dog_behavior',
            ),
            
            // Pet Retail
            'pet_retail' => array(
                'pet_store', 'pet_shop', 'aquarium_store',
                'reptile_store', 'bird_store',
            ),
            
            // ═══════════════════════════════════════════════════════════════════
            // RELIGIOUS & COMMUNITY
            // ═══════════════════════════════════════════════════════════════════
            
            // Religious Institutions
            'religious' => array(
                'church', 'catholic_church', 'evangelical_church', 'orthodox_church',
                'mosque', 'islamic_center', 'synagogue', 'jewish_center',
                'hindu_temple', 'buddhist_temple', 'sikh_gurdwara',
                'place_of_worship',
            ),
            
            // Community Services
            'community_services' => array(
                'community_center', 'nonprofit', 'charity',
                'senior_center', 'youth_center', 'food_bank',
                'homeless_shelter', 'social_services',
            ),
            
            // ═══════════════════════════════════════════════════════════════════
            // GOVERNMENT & PUBLIC SERVICES
            // ═══════════════════════════════════════════════════════════════════
            
            // Government Offices
            'government' => array(
                'city_hall', 'courthouse', 'government_office',
                'local_government_office', 'dmv', 'post_office',
                'embassy', 'consulate', 'passport_office',
            ),
            
            // Public Safety
            'public_safety' => array(
                'police', 'police_station', 'fire_station',
                'sheriff', 'emergency_services',
            ),
            
            // ═══════════════════════════════════════════════════════════════════
            // TRANSPORTATION
            // ═══════════════════════════════════════════════════════════════════
            
            // Transit & Parking
            'transit_parking' => array(
                'parking', 'parking_garage', 'parking_lot',
                'bus_station', 'train_station', 'subway_station',
                'airport', 'car_rental', 'taxi_service', 'shuttle_service',
            ),
        );

        $matched_family = null;
        foreach ($families as $family_name => $family_types) {
            foreach ($specific_types as $t) {
                if (in_array($t, $family_types)) {
                    $matched_family = $family_name;
                    break 2;
                }
            }
        }

        if ($matched_family === null) {
            return array('family' => null, 'types' => array());
        }

        return array('family' => $matched_family, 'types' => $families[$matched_family]);
    }

    /**
     * Get nearby competitors.
     *
     * Search strategy (tiered, stops as soon as 5 results are found):
     *   Tier 1 — Exact type at 5 mi
     *   Tier 2 — Exact type at 10 mi
     *   Tier 3 — All sibling types in the same business category family at 5 mi,
     *             with category-affinity filter on results
     *   Tier 4 — Category family at 10 mi
     *   Tier 5 — Generic fallback at 5 mi (restaurant/bar for food businesses, 
     *             establishment for others), affinity filter applied
     *   Tier 6 — Generic fallback at 10 mi, affinity filter applied
     *
     * @param string     $place_id      Google Place ID (used to exclude self).
     * @param array      $types         Place types from the already-fetched place details.
     * @param array|null $location      {latitude, longitude} — passed from get_business_details()
     *                                  to avoid a redundant extra API call.
     * @param string|null $price_level  priceLevel string from the Places API v1.
     * @param int        $review_count  Number of reviews for the subject business.
     * @param string     $business_name Name of the subject business for logging.
     */
    private function get_nearby_competitors($place_id, $types, $location = null, $price_level = null, $review_count = 0, $business_name = '') {
        FI_Logger::info('Getting nearby competitors', array(
            'place_id'     => $place_id,
            'types'        => $types,
            'price_level'  => $price_level,
            'review_count' => $review_count,
        ));

        if (empty($location['latitude']) || empty($location['longitude'])) {
            FI_Logger::warning('No location data available for competitor search', array('place_id' => $place_id));
            return array();
        }

        $lat = $location['latitude'];
        $lng = $location['longitude'];

        FI_Logger::info('Using business location for competitor search', array('lat' => $lat, 'lng' => $lng));

        // ── 1. Determine specific types from the category map ──────────────────
        $category_map  = include FI_PLUGIN_DIR . 'includes/category-map.php';
        // 'bar' is intentionally NOT listed here — it's a real specific type in the
        // category map and a valid primary identity for businesses like Barrilito.
        $generic_types = array('establishment', 'point_of_interest', 'food', 'store', 'restaurant');

        $specific_types = array();
        foreach ($types as $type) {
            if (isset($category_map[$type]) && ! in_array($type, $generic_types)) {
                $specific_types[] = $type;
            }
        }
        // If nothing in the map, grab the first non-generic type anyway
        if (empty($specific_types)) {
            foreach ($types as $type) {
                if (! in_array($type, $generic_types)) {
                    $specific_types[] = $type;
                    break;
                }
            }
        }
        if (empty($specific_types) && ! empty($types)) {
            $specific_types[] = $types[0];
        }
        if (empty($specific_types)) {
            $specific_types = array('establishment');
        }

        $primary_type = $specific_types[0];

        // ── 2. Build category-family context ────────────────────────────────────
        $family_data         = $this->get_business_category_family($specific_types);
        $family_types        = $family_data['types']; // sibling category types
        $has_category_family = ! empty($family_types);

        FI_Logger::info('Competitor search parameters', array(
            'primary_type'     => $primary_type,
            'category_family'  => $family_data['family'],
            'family_type_count' => count($family_types),
        ));

        // ── 3. Affinity filter helper ──────────────────────────────────────────
        // Returns true when $comp_types (from a search result) overlaps with the
        // subject business's category family, or when no family is known.
        $affinity_ok = function(array $comp_types) use ($family_types, $has_category_family) {
            if (! $has_category_family) {
                return true; // no family data — accept everything
            }
            foreach ($comp_types as $ct) {
                if (in_array($ct, $family_types)) {
                    return true;
                }
            }
            return false;
        };

        // ── 4. Define the search tiers ─────────────────────────────────────────
        // Each entry: [search_types_array, radius_miles, use_affinity_filter]
        // The base radius comes from the admin setting (default 5 miles).
        // A wider fallback tier at 2× the base radius is added automatically.
        // Affinity filter is ALWAYS enabled — even the exact-type tiers use it
        // because Google's type tagging is loose and we never want to cross
        // unrelated business categories.
        $base_radius    = max( 0.1, floatval( get_option( 'fi_competitor_radius_miles', 5 ) ) );
        $wide_radius    = min( 25.0, $base_radius * 2 );

        $search_tiers = array(
            array(array($primary_type), $base_radius, true), // exact type, base radius
            array(array($primary_type), $wide_radius, true), // exact type, 2× radius
        );

        // If we have family siblings, add a family-wide search tier
        if ($has_category_family && count($family_types) > 1) {
            // The Places API v1 accepts up to 50 includedTypes; send all siblings
            $search_tiers[] = array($family_types, $base_radius, true); // family, base radius
            $search_tiers[] = array($family_types, $wide_radius, true); // family, 2× radius
        }

        // Last resort: broad fallback based on business category
        // For food/drink businesses, fall back to restaurant/bar
        // For other businesses, use generic 'establishment' type with affinity filter
        $food_drink_types = array('restaurant', 'bar', 'cafe', 'food', 'bakery');
        $is_food_business = false;
        foreach ($specific_types as $type) {
            foreach ($food_drink_types as $food_type) {
                if (strpos($type, $food_type) !== false) {
                    $is_food_business = true;
                    break 2;
                }
            }
        }

        if ($is_food_business) {
            $search_tiers[] = array(array('restaurant', 'bar'), $base_radius, true);
            $search_tiers[] = array(array('restaurant', 'bar'), $wide_radius, true);
        } else {
            // For non-food businesses, use 'establishment' which is broader
            // The affinity filter will still ensure relevance to the business category
            $search_tiers[] = array(array('establishment'), $base_radius, true);
            $search_tiers[] = array(array('establishment'), $wide_radius, true);
        }

        // ── 5. Execute tiers until we have 5 competitors ──────────────────────
        $competitors = array();
        $seen_ids    = array($place_id); // exclude self from all tiers

        foreach ($search_tiers as $tier_index => $tier) {
            list($search_type_list, $radius_miles, $use_affinity) = $tier;

            if (count($competitors) >= 5) {
                break;
            }

            $radius_meters = $radius_miles * 1609.34;
            $search_url    = 'https://places.googleapis.com/v1/places:searchNearby';

            $body = array(
                'locationRestriction' => array(
                    'circle' => array(
                        'center' => array(
                            'latitude'  => $lat,
                            'longitude' => $lng,
                        ),
                        'radius' => $radius_meters,
                    ),
                ),
                'includedTypes'  => $search_type_list,
                'maxResultCount' => 20,
            );

            FI_Logger::info('Competitor search tier ' . ($tier_index + 1), array(
                'types'         => $search_type_list,
                'radius_miles'  => $radius_miles,
                'affinity_filter' => $use_affinity,
            ));
            FI_Logger::api_request('Google Places', 'searchNearby (tier ' . ($tier_index + 1) . ')', $body);

            $nearby_response = wp_remote_post($search_url, array(
                'headers' => array(
                    'Content-Type'     => 'application/json',
                    'X-Goog-Api-Key'   => $this->api_key,
                    // Fetch places.types so the affinity filter can inspect them
                    'X-Goog-FieldMask'  => 'places.id,places.types,places.displayName,places.rating,places.userRatingCount,places.formattedAddress,places.location,places.websiteUri,places.priceLevel',
                    'Referer'           => home_url( '/' ),
                ),
                'body'    => json_encode($body),
                'timeout' => 15,
            ));

            if (is_wp_error($nearby_response)) {
                FI_Logger::error('Nearby search API request failed', $nearby_response->get_error_message());
                continue; // try next tier
            }

            $nearby_code = wp_remote_retrieve_response_code($nearby_response);
            $nearby_body = wp_remote_retrieve_body($nearby_response);

            FI_Logger::api_response('Google Places', 'searchNearby tier ' . ($tier_index + 1), $nearby_code, substr($nearby_body, 0, 1000));

            if ($nearby_code !== 200) {
                FI_Logger::error('Nearby search non-200', array('code' => $nearby_code));
                continue;
            }

            $nearby_data = json_decode($nearby_body, true);

            if (empty($nearby_data['places'])) {
                FI_Logger::info('No results for tier ' . ($tier_index + 1), array('radius_miles' => $radius_miles));
                continue;
            }

            foreach ($nearby_data['places'] as $competitor) {
                if (count($competitors) >= 5) {
                    break;
                }

                $competitor_id = $competitor['id'] ?? '';

                // Skip self and places already added from an earlier tier
                if (in_array($competitor_id, $seen_ids)) {
                    continue;
                }

                $comp_types = $competitor['types'] ?? array();

                // ── Category affinity filter ────────────────────────────────────
                if ($use_affinity && ! $affinity_ok($comp_types)) {
                    FI_Logger::info('Skipping competitor — wrong business category', array(
                        'competitor'   => $competitor['displayName']['text'] ?? 'Unknown',
                        'comp_types'   => $comp_types,
                        'family_types' => $family_types,
                    ));
                    continue;
                }

                // ── Distance ──────────────────────────────────────────────────
                $distance = null;
                if (isset($competitor['location']['latitude'], $competitor['location']['longitude'])) {
                    $distance = $this->calculate_distance(
                        $lat, $lng,
                        $competitor['location']['latitude'],
                        $competitor['location']['longitude']
                    );
                }

                // ── Price filter ───────────────────────────────────────────────
                $comp_price  = $competitor['priceLevel'] ?? null;
                $price_match = true;

                if ($price_level !== null && $comp_price !== null) {
                    $price_diff  = abs(
                        $this->price_level_to_int($price_level) -
                        $this->price_level_to_int($comp_price)
                    );
                    $price_match = ($price_diff <= 1);

                    if (! $price_match) {
                        FI_Logger::info('Skipping competitor — price mismatch', array(
                            'competitor'       => $competitor['displayName']['text'] ?? 'Unknown',
                            'business_price'   => $price_level,
                            'competitor_price' => $comp_price,
                        ));
                        continue;
                    }
                }
                
                // ── Review volume filter (tier signal) ────────────────────────
                $comp_reviews = $competitor['userRatingCount'] ?? 0;
                if ($review_count > 0 && $comp_reviews > 0) {
                    $review_ratio = max($comp_reviews, $review_count) / min($comp_reviews, $review_count);
                    // If one business has 10x+ more reviews and we have no price data to confirm same tier, skip
                    if ($review_ratio > 10 && $price_level === null && $comp_price === null) {
                        FI_Logger::info('Skipping competitor — review volume mismatch (likely different tier)', array(
                            'competitor'      => $competitor['displayName']['text'] ?? 'Unknown',
                            'comp_reviews'    => $comp_reviews,
                            'subject_reviews' => $review_count,
                            'review_ratio'    => round($review_ratio, 1),
                        ));
                        continue;
                    }
                }

                // ── Build competitor_reason ────────────────────────────────────
                $reason_parts = array();
                
                // Shared type / cuisine
                $shared_types = array_intersect($comp_types, $specific_types);
                if (!empty($shared_types)) {
                    $type_label = ucwords(str_replace('_', ' ', reset($shared_types)));
                    $reason_parts[] = "Same business type ({$type_label})";
                } elseif (!empty($shared_family = array_intersect($comp_types, $family_types))) {
                    $type_label = ucwords(str_replace('_', ' ', reset($shared_family)));
                    $reason_parts[] = "Same category ({$type_label})";
                }
                
                // Distance context
                if ($distance !== null) {
                    if ($distance < 0.5) {
                        $reason_parts[] = "less than half a mile away";
                    } elseif ($distance < 2.0) {
                        $reason_parts[] = round($distance, 1) . " mi away — same neighborhood draw";
                    } else {
                        $reason_parts[] = round($distance, 1) . " mi away";
                    }
                }
                
                // Price tier alignment
                if ($price_level !== null && $comp_price !== null) {
                    $price_diff = abs($this->price_level_to_int($price_level) - $this->price_level_to_int($comp_price));
                    if ($price_diff === 0) {
                        $reason_parts[] = "same price tier";
                    } elseif ($price_diff === 1) {
                        $reason_parts[] = "similar price range";
                    }
                }
                
                // Review count + rating
                if ($comp_reviews > 0) {
                    $comp_rating = $competitor['rating'] ?? 0;
                    if ($comp_rating > 0) {
                        $reason_parts[] = number_format($comp_reviews) . " reviews, {$comp_rating}★ rating";
                    }
                }
                
                $competitor_reason = implode('; ', $reason_parts);
                if (empty($competitor_reason)) {
                    $competitor_reason = "Nearby business in the same category";
                }

                $seen_ids[]    = $competitor_id;
                $competitors[] = array(
                    'name'               => $competitor['displayName']['text'] ?? '',
                    'rating'             => $competitor['rating'] ?? 0,
                    'user_ratings_total' => $comp_reviews,
                    'address'            => $competitor['formattedAddress'] ?? '',
                    'location'           => $competitor['location'] ?? null,
                    'distance_miles'     => $distance,
                    'website'            => $competitor['websiteUri'] ?? null,
                    'price_level'        => $comp_price,
                    'competitor_reason'  => $competitor_reason,
                    'types'              => $comp_types,
                );
            }
        }

        // Sort by distance (closest first), then return top 5
        usort($competitors, function ($a, $b) {
            return ($a['distance_miles'] ?? 999) <=> ($b['distance_miles'] ?? 999);
        });
        $competitors = array_slice($competitors, 0, 5);

        FI_Logger::info('Final competitors found', array(
            'count'       => count($competitors),
            'competitors' => $competitors,
        ));

        return $competitors;
    }
    
    /**
     * Get website analysis data — basic connectivity + deep audit metrics
     */
    /**
     * Returns true only if $url is a safe, publicly-reachable HTTP/HTTPS URL.
     *
     * Blocks:
     *  - Non-http(s) schemes (file://, ftp://, gopher://, etc.)
     *  - Private RFC-1918 ranges (10.x, 172.16-31.x, 192.168.x)
     *  - Loopback (127.x, ::1)
     *  - Link-local / cloud-metadata (169.254.x — AWS/GCP/Azure IMDS)
     *  - Unresolvable hostnames
     *
     * @param string $url URL to validate.
     * @return bool
     */
    private function is_safe_external_url( string $url ): bool {
        $parsed = wp_parse_url( $url );
        if ( empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
            return false;
        }
        if ( ! in_array( strtolower( $parsed['scheme'] ), array( 'http', 'https' ), true ) ) {
            return false;
        }
        $host = $parsed['host'];
        // Strip IPv6 brackets
        if ( $host[0] === '[' ) {
            $host = trim( $host, '[]' );
        }
        // Resolve hostname to IP (returns false if unresolvable)
        $ip = gethostbyname( $host );
        if ( $ip === $host && ! filter_var( $host, FILTER_VALIDATE_IP ) ) {
            // gethostbyname returns the input unchanged when it can't resolve
            return false;
        }
        // Validate the resolved IP
        $ip_to_check = filter_var( $host, FILTER_VALIDATE_IP ) ? $host : $ip;
        // Block private, loopback, link-local, and reserved ranges
        if ( ! filter_var(
            $ip_to_check,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) ) {
            return false;
        }
        return true;
    }

    public function analyze_website($url) {
        if (empty($url)) {
            return array(
                'accessible' => false,
                'has_mobile_viewport' => false,
                'has_ssl' => false,
                'load_time' => 0,
            );
        }

        // Block SSRF: reject non-public or non-HTTP(S) URLs before fetching.
        if ( ! $this->is_safe_external_url( $url ) ) {
            FI_Logger::warning( 'analyze_website: blocked unsafe URL', array( 'url' => $url ) );
            return array(
                'accessible'          => false,
                'has_mobile_viewport' => false,
                'has_ssl'             => false,
                'load_time'           => 0,
            );
        }

        // Check cache
        $cache_key = 'fi_website_' . md5($url);
        $cached    = $this->get_cache($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        $start_time = microtime(true);
        $response   = wp_remote_get($url, array(
            'timeout'    => 15,
            'sslverify'  => false,
            'user-agent' => 'Mozilla/5.0 (compatible; FInsights/1.0)',
        ));
        $load_time = round(microtime(true) - $start_time, 2);
        $has_ssl   = strpos($url, 'https://') === 0;

        $analysis = array(
            'accessible'          => ! is_wp_error($response),
            'has_ssl'             => $has_ssl,
            'load_time'           => $load_time,
            'has_mobile_viewport' => false,
            'response_code'       => is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response),
        );

        if (! is_wp_error($response)) {
            $body    = wp_remote_retrieve_body($response);
            $headers = wp_remote_retrieve_headers($response);

            // Viewport
            $has_viewport = (bool) preg_match('/<meta[^>]+name=["\']viewport["\'][^>]*>/i', $body);
            $analysis['has_mobile_viewport'] = $has_viewport;

            // HTML size
            $html_bytes = strlen($body);

            // Security headers
            $has_hsts            = ! empty($headers['strict-transport-security']);
            $has_csp             = ! empty($headers['content-security-policy']);
            $has_x_frame         = ! empty($headers['x-frame-options']);
            $has_x_content_type  = ! empty($headers['x-content-type-options']);
            $has_referrer_policy = ! empty($headers['referrer-policy']);

            // SEO signals
            $has_meta_description = (bool) preg_match('/<meta[^>]+name=["\']description["\'][^>]*>/i', $body);
            $has_canonical        = (bool) preg_match('/<link[^>]+rel=["\']canonical["\'][^>]*>/i', $body);
            $has_og_tags          = (bool) preg_match('/<meta[^>]+property=["\']og:/i', $body);
            $has_structured_data  = (bool) preg_match('/application\/ld\+json/i', $body);
            $has_robots_meta      = (bool) preg_match('/<meta[^>]+name=["\']robots["\'][^>]*>/i', $body);
            $h1_count             = preg_match_all('/<h1[\s>]/i', $body, $m_h1);
            $has_single_h1        = ($h1_count === 1);
            $has_alt_texts        = ! (bool) preg_match('/<img(?![^>]*alt=)[^>]*>/i', $body);
            $has_lang_attr        = (bool) preg_match('/<html[^>]+lang=["\'][^"\']+["\']/i', $body);
            $has_title_tag        = (bool) preg_match('/<title>[^<]+<\/title>/i', $body);

            // Performance signals
            $has_lazy_loading      = (bool) preg_match('/loading=["\']lazy["\']/i', $body);
            $resource_hints        = (bool) preg_match('/<link[^>]+rel=["\'](?:preload|prefetch|preconnect)["\'][^>]*>/i', $body);
            $has_minified_html     = ($html_bytes > 0 && substr_count($body, "\n") < ($html_bytes / 200));
            $cache_control         = isset($headers['cache-control']) ? $headers['cache-control'] : '';
            $has_caching           = ! empty($cache_control) && (strpos($cache_control, 'max-age') !== false || strpos($cache_control, 'public') !== false);
            $gzip_encoding         = isset($headers['content-encoding']) ? $headers['content-encoding'] : '';
            $has_compression       = (strpos($gzip_encoding, 'gzip') !== false || strpos($gzip_encoding, 'br') !== false || strpos($gzip_encoding, 'zstd') !== false);
            $external_script_count = preg_match_all('/<script[^>]+src=["\'][^"\']*\/\//i', $body, $m_scripts);
            $modern_image_formats  = (bool) preg_match('/\.(webp|avif)/i', $body);

            // Accessibility signals
            $has_skip_link    = (bool) preg_match('/href=["\']#(?:main|content|skip)/i', $body);
            $has_aria         = (bool) preg_match('/aria-(?:label|labelledby|describedby|role)=/i', $body);
            $has_form_labels  = ! (bool) preg_match('/<input(?![^>]*(?:type=["\'](?:hidden|submit|button|reset)["\']|aria-label=|id=))[^>]*>/i', $body);
            $has_focus_styles = (bool) preg_match('/:focus\s*\{/i', $body);

            // ----------------------------------------------------------------
            // Score each category 0–100
            // ----------------------------------------------------------------

            // Performance
            if ($load_time < 1)     $perf_base = 95;
            elseif ($load_time < 2) $perf_base = 85;
            elseif ($load_time < 3) $perf_base = 72;
            elseif ($load_time < 5) $perf_base = 55;
            else                    $perf_base = 35;

            $perf_bonus = 0;
            if ($has_compression)    $perf_bonus += 5;
            if ($has_caching)        $perf_bonus += 5;
            if ($has_lazy_loading)   $perf_bonus += 3;
            if ($resource_hints)     $perf_bonus += 3;
            if ($modern_image_formats) $perf_bonus += 2;
            if ($has_minified_html)  $perf_bonus += 2;

            $desktop_perf = min(100, $perf_base + $perf_bonus);
            $mobile_perf  = min(100, max(0, $desktop_perf - ($has_viewport ? 5 : 20) - ($load_time > 2 ? 8 : 0)));

            // SEO
            $seo_score = 0;
            if ($has_title_tag)        $seo_score += 20;
            if ($has_meta_description) $seo_score += 18;
            if ($has_single_h1)        $seo_score += 12;
            if ($has_canonical)        $seo_score += 10;
            if ($has_lang_attr)        $seo_score += 8;
            if ($has_alt_texts)        $seo_score += 10;
            if ($has_og_tags)          $seo_score += 8;
            if ($has_structured_data)  $seo_score += 8;
            if (! $has_robots_meta)    $seo_score += 6;

            $desktop_seo = min(100, $seo_score);
            $mobile_seo  = $desktop_seo;

            // Best Practices
            $bp_score = 0;
            if ($has_ssl)                          $bp_score += 25;
            if ($has_hsts)                         $bp_score += 12;
            if ($has_x_frame)                      $bp_score += 10;
            if ($has_x_content_type)               $bp_score += 10;
            if ($has_csp)                          $bp_score += 10;
            if ($has_referrer_policy)              $bp_score += 8;
            if ($external_script_count <= 5)       $bp_score += 10;
            if ($has_compression)                  $bp_score += 8;
            if ($analysis['response_code'] === 200) $bp_score += 7;

            $desktop_bp = min(100, $bp_score);
            $mobile_bp  = $desktop_bp;

            // Accessibility
            $acc_score = 0;
            if ($has_lang_attr)   $acc_score += 20;
            if ($has_alt_texts)   $acc_score += 20;
            if ($has_aria)        $acc_score += 18;
            if ($has_skip_link)   $acc_score += 12;
            if ($has_form_labels) $acc_score += 12;
            if ($has_viewport)    $acc_score += 10;
            if ($has_focus_styles) $acc_score += 8;

            $desktop_acc = min(100, $acc_score);
            $mobile_acc  = min(100, max(0, $acc_score - ($has_viewport ? 0 : 15)));

            // ----------------------------------------------------------------
            // Build metric detail arrays
            // ----------------------------------------------------------------
            $analysis['audit'] = array(
                'desktop' => array(
                    'performance' => array(
                        'score'   => $desktop_perf,
                        'metrics' => array(
                            array('name' => 'Page Load Time',       'value' => $load_time . 's',                          'pass' => $load_time < 3,        'keep' => $load_time < 2,        'description' => 'Total time to load the page over the network.'),
                            array('name' => 'Response Compression', 'value' => $has_compression ? 'Enabled' : 'Disabled', 'pass' => $has_compression,      'keep' => $has_compression,      'description' => 'Gzip/Brotli compression reduces transfer size significantly.'),
                            array('name' => 'Browser Caching',      'value' => $has_caching ? 'Configured' : 'Missing',   'pass' => $has_caching,          'keep' => $has_caching,          'description' => 'Cache-Control headers let browsers reuse assets on return visits.'),
                            array('name' => 'Lazy Loading',         'value' => $has_lazy_loading ? 'Used' : 'Not used',   'pass' => $has_lazy_loading,     'keep' => $has_lazy_loading,     'description' => 'Defers off-screen images to speed up initial paint.'),
                            array('name' => 'Resource Hints',       'value' => $resource_hints ? 'Present' : 'Missing',   'pass' => $resource_hints,       'keep' => $resource_hints,       'description' => 'Preload/preconnect hints help fetch critical assets earlier.'),
                            array('name' => 'Modern Image Formats', 'value' => $modern_image_formats ? 'WebP/AVIF' : 'Legacy formats', 'pass' => $modern_image_formats, 'keep' => $modern_image_formats, 'description' => 'WebP and AVIF are 25–50% smaller than JPEG/PNG.'),
                        ),
                    ),
                    'seo' => array(
                        'score'   => $desktop_seo,
                        'metrics' => array(
                            array('name' => 'Title Tag',          'value' => $has_title_tag ? 'Present' : 'Missing',        'pass' => $has_title_tag,        'keep' => $has_title_tag,        'description' => 'The page title shown in search results and browser tabs.'),
                            array('name' => 'Meta Description',   'value' => $has_meta_description ? 'Present' : 'Missing', 'pass' => $has_meta_description, 'keep' => $has_meta_description, 'description' => 'The snippet displayed in search results below the title.'),
                            array('name' => 'H1 Heading',         'value' => $h1_count === 1 ? 'Single H1' : ($h1_count === 0 ? 'Missing' : 'Multiple H1s'), 'pass' => $has_single_h1, 'keep' => $has_single_h1, 'description' => 'Exactly one H1 per page helps search engines understand the topic.'),
                            array('name' => 'Canonical Tag',      'value' => $has_canonical ? 'Present' : 'Missing',        'pass' => $has_canonical,        'keep' => $has_canonical,        'description' => 'Prevents duplicate content issues across similar URLs.'),
                            array('name' => 'Image Alt Text',     'value' => $has_alt_texts ? 'All present' : 'Some missing','pass' => $has_alt_texts,       'keep' => $has_alt_texts,        'description' => 'Alt attributes help search engines understand image content.'),
                            array('name' => 'Structured Data',    'value' => $has_structured_data ? 'Present' : 'Missing',  'pass' => $has_structured_data,  'keep' => $has_structured_data,  'description' => 'JSON-LD markup enables rich results in Google Search.'),
                            array('name' => 'Open Graph Tags',    'value' => $has_og_tags ? 'Present' : 'Missing',          'pass' => $has_og_tags,          'keep' => $has_og_tags,          'description' => 'Controls how the page appears when shared on social media.'),
                            array('name' => 'Language Attribute', 'value' => $has_lang_attr ? 'Set' : 'Missing',            'pass' => $has_lang_attr,        'keep' => $has_lang_attr,        'description' => 'The lang attribute on the html element aids search and screen readers.'),
                        ),
                    ),
                    'best_practices' => array(
                        'score'   => $desktop_bp,
                        'metrics' => array(
                            array('name' => 'HTTPS / SSL',             'value' => $has_ssl ? 'Enabled' : 'Not enabled',         'pass' => $has_ssl,           'keep' => $has_ssl,           'description' => 'Encrypts data in transit; required for browser trust and ranking.'),
                            array('name' => 'HSTS Header',             'value' => $has_hsts ? 'Present' : 'Missing',            'pass' => $has_hsts,          'keep' => $has_hsts,          'description' => 'Forces HTTPS and prevents protocol downgrade attacks.'),
                            array('name' => 'X-Frame-Options',         'value' => $has_x_frame ? 'Set' : 'Missing',             'pass' => $has_x_frame,       'keep' => $has_x_frame,       'description' => 'Prevents the page from being embedded in iframes (clickjacking).'),
                            array('name' => 'X-Content-Type-Options',  'value' => $has_x_content_type ? 'Set' : 'Missing',      'pass' => $has_x_content_type,'keep' => $has_x_content_type,'description' => 'Stops browsers from MIME-sniffing responses.'),
                            array('name' => 'Content Security Policy', 'value' => $has_csp ? 'Configured' : 'Missing',          'pass' => $has_csp,           'keep' => $has_csp,           'description' => 'Restricts which scripts and resources the page may load.'),
                            array('name' => 'Referrer Policy',         'value' => $has_referrer_policy ? 'Set' : 'Missing',     'pass' => $has_referrer_policy,'keep' => $has_referrer_policy,'description' => 'Controls how much referrer info is shared on outbound links.'),
                            array('name' => 'Third-party Scripts',     'value' => $external_script_count . ' external scripts', 'pass' => $external_script_count <= 5, 'keep' => $external_script_count <= 3, 'description' => 'Excessive third-party scripts slow loading and increase attack surface.'),
                        ),
                    ),
                    'accessibility' => array(
                        'score'   => $desktop_acc,
                        'metrics' => array(
                            array('name' => 'Language Attribute', 'value' => $has_lang_attr ? 'Set' : 'Missing',              'pass' => $has_lang_attr,   'keep' => $has_lang_attr,   'description' => 'Helps screen readers use the correct language and pronunciation.'),
                            array('name' => 'Image Alt Text',     'value' => $has_alt_texts ? 'All present' : 'Some missing', 'pass' => $has_alt_texts,  'keep' => $has_alt_texts,   'description' => 'Essential for users who rely on screen readers.'),
                            array('name' => 'ARIA Attributes',    'value' => $has_aria ? 'Used' : 'Not detected',             'pass' => $has_aria,        'keep' => $has_aria,        'description' => 'ARIA roles and labels improve screen reader navigation.'),
                            array('name' => 'Skip Navigation',    'value' => $has_skip_link ? 'Present' : 'Missing',          'pass' => $has_skip_link,   'keep' => $has_skip_link,   'description' => 'Lets keyboard users jump directly to main content.'),
                            array('name' => 'Form Labels',        'value' => $has_form_labels ? 'All labelled' : 'Unlabelled inputs', 'pass' => $has_form_labels, 'keep' => $has_form_labels, 'description' => 'Labelled inputs let screen readers describe each field.'),
                            array('name' => 'Focus Styles',       'value' => $has_focus_styles ? 'Defined' : 'Not detected',  'pass' => $has_focus_styles,'keep' => $has_focus_styles,'description' => 'Visible focus rings are critical for keyboard-only navigation.'),
                        ),
                    ),
                ),
                'mobile' => array(
                    'performance' => array(
                        'score'   => $mobile_perf,
                        'metrics' => array(
                            array('name' => 'Viewport Meta Tag',    'value' => $has_viewport ? 'Present' : 'Missing',         'pass' => $has_viewport,      'keep' => $has_viewport,      'description' => 'Required for correct scaling on mobile screens.'),
                            array('name' => 'Page Load Time',       'value' => $load_time . 's (mobile est. +30%)',            'pass' => $load_time < 2.5,   'keep' => $load_time < 1.5,   'description' => 'Mobile networks are slower; pages should load under 3 s on 4G.'),
                            array('name' => 'Response Compression', 'value' => $has_compression ? 'Enabled' : 'Disabled',     'pass' => $has_compression,   'keep' => $has_compression,   'description' => 'Critical on mobile — reduces data usage and speeds loading.'),
                            array('name' => 'Lazy Loading',         'value' => $has_lazy_loading ? 'Used' : 'Not used',        'pass' => $has_lazy_loading,  'keep' => $has_lazy_loading,  'description' => 'Especially impactful on mobile to avoid loading off-screen images.'),
                            array('name' => 'Modern Image Formats', 'value' => $modern_image_formats ? 'WebP/AVIF' : 'Legacy', 'pass' => $modern_image_formats,'keep' => $modern_image_formats,'description' => 'Smaller modern formats save mobile data and reduce load time.'),
                        ),
                    ),
                    'seo' => array(
                        'score'   => $mobile_seo,
                        'metrics' => array(
                            array('name' => 'Mobile Viewport',    'value' => $has_viewport ? 'Configured' : 'Missing',       'pass' => $has_viewport,         'keep' => $has_viewport,         'description' => 'Google uses mobile-first indexing; the viewport meta tag is required.'),
                            array('name' => 'Title Tag',          'value' => $has_title_tag ? 'Present' : 'Missing',         'pass' => $has_title_tag,        'keep' => $has_title_tag,        'description' => 'Title is used in mobile search result snippets.'),
                            array('name' => 'Meta Description',   'value' => $has_meta_description ? 'Present' : 'Missing',  'pass' => $has_meta_description, 'keep' => $has_meta_description, 'description' => 'Improves click-through rate on mobile search results.'),
                            array('name' => 'Structured Data',    'value' => $has_structured_data ? 'Present' : 'Missing',   'pass' => $has_structured_data,  'keep' => $has_structured_data,  'description' => 'Enables rich snippets which appear prominently in mobile search.'),
                            array('name' => 'Image Alt Text',     'value' => $has_alt_texts ? 'All present' : 'Some missing','pass' => $has_alt_texts,        'keep' => $has_alt_texts,        'description' => 'Important for mobile image search indexing.'),
                            array('name' => 'Open Graph Tags',    'value' => $has_og_tags ? 'Present' : 'Missing',           'pass' => $has_og_tags,          'keep' => $has_og_tags,          'description' => 'Social shares from mobile devices rely on OG tags for previews.'),
                        ),
                    ),
                    'best_practices' => array(
                        'score'   => $mobile_bp,
                        'metrics' => array(
                            array('name' => 'HTTPS / SSL',            'value' => $has_ssl ? 'Enabled' : 'Not enabled',         'pass' => $has_ssl,            'keep' => $has_ssl,            'description' => 'Mobile browsers prominently warn users about insecure sites.'),
                            array('name' => 'Viewport Meta Tag',      'value' => $has_viewport ? 'Present' : 'Missing',        'pass' => $has_viewport,       'keep' => $has_viewport,       'description' => 'Prevents the mobile browser from rendering a desktop-sized page.'),
                            array('name' => 'HSTS Header',            'value' => $has_hsts ? 'Present' : 'Missing',            'pass' => $has_hsts,           'keep' => $has_hsts,           'description' => 'Forces HTTPS on repeat visits, including on mobile networks.'),
                            array('name' => 'X-Content-Type-Options', 'value' => $has_x_content_type ? 'Set' : 'Missing',      'pass' => $has_x_content_type, 'keep' => $has_x_content_type, 'description' => 'Prevents MIME sniffing on mobile browsers.'),
                            array('name' => 'Third-party Scripts',    'value' => $external_script_count . ' external scripts', 'pass' => $external_script_count <= 5, 'keep' => $external_script_count <= 3, 'description' => 'Third-party scripts block rendering and drain mobile battery.'),
                        ),
                    ),
                    'accessibility' => array(
                        'score'   => $mobile_acc,
                        'metrics' => array(
                            array('name' => 'Viewport Meta Tag',  'value' => $has_viewport ? 'Present' : 'Missing',           'pass' => $has_viewport,   'keep' => $has_viewport,   'description' => 'Without viewport, content is tiny and touch targets become unusable.'),
                            array('name' => 'Language Attribute', 'value' => $has_lang_attr ? 'Set' : 'Missing',              'pass' => $has_lang_attr,  'keep' => $has_lang_attr,  'description' => 'Mobile screen readers rely on this to select the correct voice.'),
                            array('name' => 'Image Alt Text',     'value' => $has_alt_texts ? 'All present' : 'Some missing', 'pass' => $has_alt_texts, 'keep' => $has_alt_texts,  'description' => 'Critical when images fail to load on slow mobile connections.'),
                            array('name' => 'ARIA Attributes',    'value' => $has_aria ? 'Used' : 'Not detected',             'pass' => $has_aria,       'keep' => $has_aria,       'description' => 'Improves navigation for mobile screen reader users.'),
                            array('name' => 'Skip Navigation',    'value' => $has_skip_link ? 'Present' : 'Missing',          'pass' => $has_skip_link,  'keep' => $has_skip_link,  'description' => 'Helps keyboard and switch-access users avoid repetitive mobile navigation.'),
                        ),
                    ),
                ),
            );
        }

        // Cache for 24 hours
        $this->set_cache($cache_key, $analysis);

        return $analysis;
    }
    
    /**
     * Get from cache
     */
    private function get_cache($key) {
        if ($this->cache_duration <= 0) {
            return false;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'fi_cache';
        
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT cache_value, expires_at FROM $table WHERE cache_key = %s",
            $key
        ));
        
        if (!$result) {
            return false;
        }
        
        // Check if expired
        if (strtotime($result->expires_at) < time()) {
            $wpdb->delete($table, array('cache_key' => $key));
            return false;
        }
        
        return json_decode($result->cache_value, true);
    }
    
    /**
     * Set cache
     */
    private function set_cache($key, $value) {
        if ($this->cache_duration <= 0) {
            return;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'fi_cache';
        
        $wpdb->replace(
            $table,
            array(
                'cache_key' => $key,
                'cache_value' => json_encode($value),
                'expires_at' => gmdate('Y-m-d H:i:s', time() + $this->cache_duration),
                'created_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s')
        );
    }
    
    /**
     * Normalise a Places API v1 priceLevel string to an integer (0–4).
     * The API returns strings like "PRICE_LEVEL_MODERATE"; older integer
     * values (0–4) are passed through unchanged for backwards compatibility.
     */
    private function price_level_to_int($price_level) {
        $map = array(
            'PRICE_LEVEL_FREE'         => 0,
            'PRICE_LEVEL_INEXPENSIVE'  => 1,
            'PRICE_LEVEL_MODERATE'     => 2,
            'PRICE_LEVEL_EXPENSIVE'    => 3,
            'PRICE_LEVEL_VERY_EXPENSIVE' => 4,
            'PRICE_LEVEL_UNSPECIFIED'  => 2, // treat unknown as moderate
        );

        if (is_string($price_level)) {
            return $map[$price_level] ?? 2;
        }

        // Already an integer (legacy / test data)
        return (int) $price_level;
    }

    /**
     * Calculate distance between two lat/lng coordinates in miles
     * Using the Haversine formula
     */
    private function calculate_distance($lat1, $lon1, $lat2, $lon2) {
        $earth_radius = 3959; // Earth's radius in miles
        
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        $distance = $earth_radius * $c;
        
        return round($distance, 1);
    }
    
    /**
     * Extract business email from website HTML
     * Returns the first valid email found, or null if none found
     * 
     * @param string $url Website URL to scrape
     * @return string|null Email address or null
     */
    public function extract_business_email($url) {
        if (empty($url)) {
            return null;
        }

        // Block SSRF: only fetch from safe, public HTTP/HTTPS URLs.
        if ( ! $this->is_safe_external_url( $url ) ) {
            FI_Logger::warning( 'extract_business_email: blocked unsafe URL', array( 'url' => $url ) );
            return null;
        }
        
        FI_Logger::info('Extracting business email from website', array('url' => $url));
        
        // Try to fetch website HTML
        $response = wp_remote_get($url, array(
            'timeout'    => 10,
            'sslverify'  => false,
            'user-agent' => 'Mozilla/5.0 (compatible; FInsights/1.6)',
        ));
        
        if (is_wp_error($response)) {
            FI_Logger::info('Could not fetch website for email extraction', array('error' => $response->get_error_message()));
            return null;
        }
        
        $html = wp_remote_retrieve_body($response);
        if (empty($html)) {
            return null;
        }
        
        // Regex pattern for email addresses
        // Matches: user@domain.com, user.name@domain.co.uk, etc.
        preg_match_all('/\b[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}\b/', $html, $matches);
        
        if (empty($matches[0])) {
            FI_Logger::info('No email addresses found on website');
            return null;
        }
        
        // Filter out common noise patterns (noreply, example, test, etc.)
        $ignore_patterns = array('noreply@', 'no-reply@', 'example@', 'test@', 'email@', 'your@', 'youremail@', 'sampleemail@', 'user@');
        
        foreach ($matches[0] as $email) {
            $email = strtolower(trim($email));
            
            // Skip ignored patterns
            $should_skip = false;
            foreach ($ignore_patterns as $pattern) {
                if (stripos($email, $pattern) === 0) {
                    $should_skip = true;
                    break;
                }
            }
            
            if ($should_skip) {
                continue;
            }
            
            // Prefer common business email prefixes
            $preferred_patterns = array('info@', 'contact@', 'hello@', 'support@', 'admin@', 'sales@');
            foreach ($preferred_patterns as $pattern) {
                if (stripos($email, $pattern) === 0) {
                    FI_Logger::info('Business email found', array('email' => $email));
                    return $email;
                }
            }
        }
        
        // If no preferred email found, return the first valid one
        foreach ($matches[0] as $email) {
            $email = strtolower(trim($email));
            
            // Skip ignored patterns
            $should_skip = false;
            foreach ($ignore_patterns as $pattern) {
                if (stripos($email, $pattern) === 0) {
                    $should_skip = true;
                    break;
                }
            }
            
            if (!$should_skip && !empty($email)) {
                FI_Logger::info('Business email found', array('email' => $email));
                return $email;
            }
        }
        
        FI_Logger::info('No valid business email found');
        return null;
    }
}