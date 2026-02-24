<?php
/**
 * Map Google Place types to business categories
 * Optimized for brick-and-mortar businesses with foot traffic + online presence
 * Granular, niche-specific categories — avoids generic buckets
 */
if (!defined('ABSPATH')) {
    exit;
}
return array(

    // ═══════════════════════════════════════════════════════════════════════
    // FOOD & BEVERAGE — Restaurants by Cuisine
    // ═══════════════════════════════════════════════════════════════════════

    // American
    'american_restaurant'           => 'American Restaurant',
    'burger_restaurant'             => 'Burger Restaurant',
    'bbq_restaurant'                => 'BBQ & Smokehouse',
    'southern_restaurant'           => 'Southern & Soul Food Restaurant',
    'tex_mex_restaurant'            => 'Tex-Mex Restaurant',
    'cajun_restaurant'              => 'Cajun & Creole Restaurant',
    'diner'                         => 'Diner',
    'brunch_restaurant'             => 'Brunch Restaurant',
    'steakhouse'                    => 'Steakhouse',
    'seafood_restaurant'            => 'Seafood Restaurant',
    'oyster_bar'                    => 'Oyster Bar',
    'lobster_shack'                 => 'Lobster Shack & Seafood Shack',
    'new_american_restaurant'       => 'New American Restaurant',
    'farm_to_table_restaurant'      => 'Farm-to-Table Restaurant',

    // Latin American
    'mexican_restaurant'            => 'Mexican Restaurant',
    'taqueria'                      => 'Taqueria & Taco Shop',
    'argentinian_restaurant'        => 'Argentinian Restaurant',
    'brazilian_restaurant'          => 'Brazilian Restaurant',
    'churrascaria'                  => 'Brazilian Steakhouse (Churrascaria)',
    'colombian_restaurant'          => 'Colombian Restaurant',
    'peruvian_restaurant'           => 'Peruvian Restaurant',
    'venezuelan_restaurant'         => 'Venezuelan Restaurant',
    'cuban_restaurant'              => 'Cuban Restaurant',
    'salvadoran_restaurant'         => 'Salvadoran Restaurant',
    'guatemalan_restaurant'         => 'Guatemalan Restaurant',
    'honduran_restaurant'           => 'Honduran Restaurant',
    'bolivian_restaurant'           => 'Bolivian Restaurant',
    'chilean_restaurant'            => 'Chilean Restaurant',
    'ecuadorian_restaurant'         => 'Ecuadorian Restaurant',
    'caribbean_restaurant'          => 'Caribbean Restaurant',
    'haitian_restaurant'            => 'Haitian Restaurant',
    'jamaican_restaurant'           => 'Jamaican Restaurant',

    // European
    'italian_restaurant'            => 'Italian Restaurant',
    'pizza_restaurant'              => 'Pizza Restaurant & Pizzeria',
    'french_restaurant'             => 'French Restaurant',
    'spanish_restaurant'            => 'Spanish Restaurant',
    'tapas_restaurant'              => 'Tapas Bar & Restaurant',
    'greek_restaurant'              => 'Greek Restaurant',
    'mediterranean_restaurant'      => 'Mediterranean Restaurant',
    'portuguese_restaurant'         => 'Portuguese Restaurant',
    'german_restaurant'             => 'German Restaurant',
    'british_restaurant'            => 'British & Irish Restaurant',
    'irish_pub'                     => 'Irish Pub & Restaurant',
    'polish_restaurant'             => 'Polish Restaurant',
    'ukrainian_restaurant'          => 'Ukrainian Restaurant',
    'russian_restaurant'            => 'Russian Restaurant',
    'hungarian_restaurant'          => 'Hungarian Restaurant',
    'czech_restaurant'              => 'Czech & Slovak Restaurant',
    'scandinavian_restaurant'       => 'Scandinavian Restaurant',
    'swiss_restaurant'              => 'Swiss Restaurant',
    'austrian_restaurant'           => 'Austrian Restaurant',
    'belgian_restaurant'            => 'Belgian Restaurant',
    'dutch_restaurant'              => 'Dutch Restaurant',
    'romanian_restaurant'           => 'Romanian Restaurant',
    'turkish_restaurant'            => 'Turkish Restaurant',

    // Middle Eastern & North African
    'lebanese_restaurant'           => 'Lebanese Restaurant',
    'persian_restaurant'            => 'Persian & Iranian Restaurant',
    'israeli_restaurant'            => 'Israeli Restaurant',
    'moroccan_restaurant'           => 'Moroccan Restaurant',
    'egyptian_restaurant'           => 'Egyptian Restaurant',
    'syrian_restaurant'             => 'Syrian Restaurant',
    'iraqi_restaurant'              => 'Iraqi Restaurant',
    'yemeni_restaurant'             => 'Yemeni Restaurant',
    'afghan_restaurant'             => 'Afghan Restaurant',
    'georgian_restaurant'           => 'Georgian Restaurant',
    'armenian_restaurant'           => 'Armenian Restaurant',
    'falafel_restaurant'            => 'Falafel & Shawarma Shop',
    'kebab_restaurant'              => 'Kebab Restaurant',

    // South Asian
    'indian_restaurant'             => 'Indian Restaurant',
    'north_indian_restaurant'       => 'North Indian Restaurant',
    'south_indian_restaurant'       => 'South Indian Restaurant',
    'pakistani_restaurant'          => 'Pakistani Restaurant',
    'bangladeshi_restaurant'        => 'Bangladeshi Restaurant',
    'sri_lankan_restaurant'         => 'Sri Lankan Restaurant',
    'nepalese_restaurant'           => 'Nepalese Restaurant',
    'buffet_indian'                 => 'Indian Buffet',

    // East Asian
    'chinese_restaurant'            => 'Chinese Restaurant',
    'cantonese_restaurant'          => 'Cantonese Restaurant',
    'szechuan_restaurant'           => 'Szechuan Restaurant',
    'dim_sum_restaurant'            => 'Dim Sum Restaurant',
    'japanese_restaurant'           => 'Japanese Restaurant',
    'sushi_restaurant'              => 'Sushi Restaurant',
    'ramen_restaurant'              => 'Ramen Restaurant',
    'izakaya'                       => 'Izakaya & Japanese Pub',
    'teppanyaki'                    => 'Teppanyaki & Hibachi Restaurant',
    'korean_restaurant'             => 'Korean Restaurant',
    'korean_bbq_restaurant'         => 'Korean BBQ Restaurant',
    'mongolian_restaurant'          => 'Mongolian Restaurant',
    'taiwanese_restaurant'          => 'Taiwanese Restaurant',
    'hong_kong_restaurant'          => 'Hong Kong-Style Restaurant',

    // Southeast Asian
    'thai_restaurant'               => 'Thai Restaurant',
    'vietnamese_restaurant'         => 'Vietnamese Restaurant',
    'pho_restaurant'                => 'Pho Restaurant',
    'filipino_restaurant'           => 'Filipino Restaurant',
    'indonesian_restaurant'         => 'Indonesian Restaurant',
    'malaysian_restaurant'          => 'Malaysian Restaurant',
    'singaporean_restaurant'        => 'Singaporean Restaurant',
    'burmese_restaurant'            => 'Burmese Restaurant',
    'cambodian_restaurant'          => 'Cambodian Restaurant',
    'laotian_restaurant'            => 'Laotian Restaurant',

    // African
    'ethiopian_restaurant'          => 'Ethiopian Restaurant',
    'eritrean_restaurant'           => 'Eritrean Restaurant',
    'nigerian_restaurant'           => 'Nigerian Restaurant',
    'west_african_restaurant'       => 'West African Restaurant',
    'east_african_restaurant'       => 'East African Restaurant',
    'south_african_restaurant'      => 'South African Restaurant',
    'senegalese_restaurant'         => 'Senegalese Restaurant',
    'ghanaian_restaurant'           => 'Ghanaian Restaurant',

    // Specialty & Format
    'vegetarian_restaurant'         => 'Vegetarian Restaurant',
    'vegan_restaurant'              => 'Vegan Restaurant',
    'raw_food_restaurant'           => 'Raw Food Restaurant',
    'gluten_free_restaurant'        => 'Gluten-Free Restaurant',
    'halal_restaurant'              => 'Halal Restaurant',
    'kosher_restaurant'             => 'Kosher Restaurant',
    'buffet_restaurant'             => 'Buffet Restaurant',
    'fondue_restaurant'             => 'Fondue Restaurant',
    'hot_pot_restaurant'            => 'Hot Pot Restaurant',
    'omakase_restaurant'            => 'Omakase & Fine Dining',
    'fine_dining_restaurant'        => 'Fine Dining Restaurant',
    'gastropub'                     => 'Gastropub',
    'supper_club'                   => 'Supper Club & Private Dining',
    'food_hall'                     => 'Food Hall',
    'ghost_kitchen'                 => 'Ghost Kitchen & Virtual Restaurant',
    'restaurant'                    => 'Restaurant',

    // ═══════════════════════════════════════════════════════════════════════
    // FOOD & BEVERAGE — Cafes, Bars & Specialty Drinks
    // ═══════════════════════════════════════════════════════════════════════

    'cafe'                          => 'Cafe & Coffee Shop',
    'specialty_coffee_shop'         => 'Specialty Coffee Shop',
    'espresso_bar'                  => 'Espresso Bar',
    'bubble_tea_shop'               => 'Bubble Tea & Boba Shop',
    'tea_house'                     => 'Tea House & Tea Room',
    'juice_bar'                     => 'Juice Bar & Smoothie Shop',
    'bakery'                        => 'Bakery',
    'patisserie'                    => 'Patisserie & French Bakery',
    'bagel_shop'                    => 'Bagel Shop',
    'donut_shop'                    => 'Donut Shop',
    'ice_cream_shop'                => 'Ice Cream Shop',
    'gelato_shop'                   => 'Gelato & Sorbet Shop',
    'creperie'                      => 'Creperie',
    'waffle_house'                  => 'Waffle & Pancake House',
    'chocolate_shop'                => 'Chocolate & Confectionery Shop',
    'candy_store'                   => 'Candy & Sweets Shop',
    'popcorn_shop'                  => 'Gourmet Popcorn & Snack Shop',
    'bar'                           => 'Bar & Lounge',
    'sports_bar'                    => 'Sports Bar',
    'cocktail_bar'                  => 'Cocktail Bar',
    'wine_bar'                      => 'Wine Bar',
    'craft_beer_bar'                => 'Craft Beer Bar & Taproom',
    'dive_bar'                      => 'Dive Bar',
    'night_club'                    => 'Nightclub',
    'rooftop_bar'                   => 'Rooftop Bar',
    'brewery'                       => 'Brewery & Taproom',
    'winery'                        => 'Winery & Tasting Room',
    'distillery'                    => 'Distillery & Tasting Room',
    'cidery'                        => 'Cidery',
    'mead_hall'                     => 'Meadery & Mead Hall',
    'hookah_lounge'                 => 'Hookah Lounge',
    'karaoke_bar'                   => 'Karaoke Bar',
    'jazz_club'                     => 'Jazz Club & Live Music Venue',
    'meal_takeaway'                 => 'Fast Food & Takeaway',
    'meal_delivery'                 => 'Food Delivery Service',
    'food_truck'                    => 'Food Truck',
    'hot_dog_stand'                 => 'Hot Dog & Street Food Stand',
    'food'                          => 'Food Service',

    // ═══════════════════════════════════════════════════════════════════════
    // RETAIL — Apparel & Accessories
    // ═══════════════════════════════════════════════════════════════════════

    'clothing_store'                => 'Clothing & Apparel Store',
    'womens_clothing_store'         => "Women's Clothing Boutique",
    'mens_clothing_store'           => "Men's Clothing Store",
    'childrens_clothing_store'      => "Children's Clothing Store",
    'maternity_store'               => 'Maternity & Nursing Clothing',
    'plus_size_clothing'            => 'Plus-Size Clothing Store',
    'activewear_store'              => 'Activewear & Athleisure Store',
    'swimwear_store'                => 'Swimwear & Beachwear Store',
    'lingerie_store'                => 'Lingerie & Intimates Store',
    'formal_wear_store'             => 'Formal Wear & Tuxedo Rentals',
    'bridal_shop'                   => 'Bridal & Wedding Dress Shop',
    'costume_shop'                  => 'Costume & Halloween Shop',
    'uniform_store'                 => 'Uniforms & Workwear',
    'shoe_store'                    => 'Shoe Store',
    'sneaker_store'                 => 'Sneaker & Streetwear Store',
    'boot_store'                    => 'Boot & Western Wear Store',
    'children_shoe_store'           => "Children's Shoe Store",
    'jewelry_store'                 => 'Jewelry Store',
    'fine_jewelry_store'            => 'Fine Jewelry & Diamond Store',
    'costume_jewelry_store'         => 'Fashion Jewelry & Accessories',
    'watch_store'                   => 'Watch Store',
    'sunglasses_store'              => 'Sunglasses & Eyewear Store',
    'handbag_store'                 => 'Handbag & Leather Goods Store',
    'hat_store'                     => 'Hat Shop',
    'thrift_store'                  => 'Thrift Store',
    'consignment_shop'              => 'Consignment Shop',
    'vintage_clothing_store'        => 'Vintage Clothing Store',
    'tailor'                        => 'Tailor & Alterations',
    'embroidery_shop'               => 'Embroidery & Screen Printing',

    // ═══════════════════════════════════════════════════════════════════════
    // RETAIL — Home & Lifestyle
    // ═══════════════════════════════════════════════════════════════════════

    'furniture_store'               => 'Furniture Store',
    'mattress_store'                => 'Mattress & Bedding Store',
    'home_goods_store'              => 'Home Goods & Decor Store',
    'kitchen_supply_store'          => 'Kitchen & Cookware Store',
    'bath_store'                    => 'Bath & Body Store',
    'candle_store'                  => 'Candle & Fragrance Store',
    'lighting_store'                => 'Lighting & Lamp Store',
    'flooring_store'                => 'Flooring & Carpet Store',
    'wallpaper_store'               => 'Paint, Wallpaper & Tile Store',
    'hardware_store'                => 'Hardware Store',
    'appliance_store'               => 'Appliance Store',
    'garden_center'                 => 'Garden Center & Nursery',
    'pool_supply_store'             => 'Pool & Spa Supply Store',
    'antique_shop'                  => 'Antique Shop',
    'frame_shop'                    => 'Picture Framing Shop',
    'rug_store'                     => 'Rug & Carpet Store',

    // ═══════════════════════════════════════════════════════════════════════
    // RETAIL — Electronics & Technology
    // ═══════════════════════════════════════════════════════════════════════

    'electronics_store'             => 'Electronics Store',
    'computer_store'                => 'Computer & Laptop Store',
    'mobile_phone_store'            => 'Mobile Phone Store',
    'camera_store'                  => 'Camera & Photography Store',
    'audio_store'                   => 'Audio & Hi-Fi Store',
    'video_game_store'              => 'Video Game Store',
    'drone_store'                   => 'Drone & RC Hobby Store',
    'home_theater_store'            => 'Home Theater & AV Store',
    'telecom_store'                 => 'Telecom & Wireless Store',

    // ═══════════════════════════════════════════════════════════════════════
    // RETAIL — Specialty & Hobby
    // ═══════════════════════════════════════════════════════════════════════

    'book_store'                    => 'Bookstore',
    'comic_book_store'              => 'Comic Book & Graphic Novel Store',
    'music_store'                   => 'Music Store',
    'instrument_store'              => 'Musical Instrument Store',
    'record_store'                  => 'Record & Vinyl Store',
    'toy_store'                     => 'Toy Store',
    'hobby_store'                   => 'Hobby & Model Store',
    'board_game_store'              => 'Board Game & Tabletop Game Store',
    'craft_store'                   => 'Craft & Fabric Store',
    'yarn_store'                    => 'Yarn & Knitting Store',
    'art_supply_store'              => 'Art Supply Store',
    'sporting_goods_store'          => 'Sporting Goods Store',
    'golf_store'                    => 'Golf Equipment & Apparel Store',
    'ski_snowboard_shop'            => 'Ski & Snowboard Shop',
    'surf_shop'                     => 'Surf & Watersports Shop',
    'outdoor_store'                 => 'Outdoor & Camping Gear Store',
    'bicycle_store'                 => 'Bicycle Shop',
    'fishing_tackle_shop'           => 'Fishing Tackle & Supplies',
    'hunting_store'                 => 'Hunting & Outdoor Sports Store',
    'gun_shop'                      => 'Gun Shop & Firearms Store',
    'tobacco_shop'                  => 'Tobacco & Cigar Shop',
    'vape_store'                    => 'Vape & E-Cigarette Shop',
    'cannabis_dispensary'           => 'Cannabis Dispensary',
    'party_supply_store'            => 'Party Supply Store',
    'office_supply_store'           => 'Office Supply Store',
    'new_age_store'                 => 'New Age, Crystal & Metaphysical Shop',
    'religious_goods_store'         => 'Religious Goods & Church Supply',
    'coin_shop'                     => 'Coin, Stamp & Collectibles Shop',
    'auction_house'                 => 'Auction House',
    'pawn_shop'                     => 'Pawn Shop',
    'trophy_awards_shop'            => 'Trophy, Award & Engraving Shop',
    'magic_shop'                    => 'Magic & Novelty Shop',

    // ═══════════════════════════════════════════════════════════════════════
    // RETAIL — Grocery & Food Retail
    // ═══════════════════════════════════════════════════════════════════════

    'supermarket'                   => 'Supermarket & Grocery Store',
    'convenience_store'             => 'Convenience Store',
    'natural_food_store'            => 'Natural & Organic Food Store',
    'specialty_food_store'          => 'Specialty & Gourmet Food Store',
    'farmers_market'                => "Farmers' Market",
    'asian_grocery'                 => 'Asian Grocery Store',
    'hispanic_grocery'              => 'Hispanic & Latin Grocery Store',
    'middle_eastern_grocery'        => 'Middle Eastern & Mediterranean Grocery',
    'indian_grocery'                => 'Indian & South Asian Grocery Store',
    'african_grocery'               => 'African & Caribbean Grocery Store',
    'european_deli'                 => 'European Deli & Specialty Import',
    'jewish_deli'                   => 'Jewish Deli & Kosher Market',
    'italian_deli'                  => 'Italian Deli & Gourmet Market',
    'warehouse_store'               => 'Warehouse & Bulk Retailer',
    'butcher_shop'                  => 'Butcher Shop & Meat Market',
    'fish_market'                   => 'Seafood & Fish Market',
    'cheese_shop'                   => 'Cheese Shop & Fromagerie',
    'deli'                          => 'Deli & Sandwich Shop',
    'liquor_store'                  => 'Liquor Store',
    'wine_shop'                     => 'Wine Shop & Wine Merchant',
    'craft_beer_store'              => 'Craft Beer & Bottle Shop',

    // ═══════════════════════════════════════════════════════════════════════
    // RETAIL — Pharmacy & Health Retail
    // ═══════════════════════════════════════════════════════════════════════

    'pharmacy'                      => 'Pharmacy & Drug Store',
    'compounding_pharmacy'          => 'Compounding Pharmacy',
    'vitamin_supplement_shop'       => 'Vitamin & Supplement Store',
    'medical_supply_store'          => 'Medical Supply Store',
    'optical_store'                 => 'Optical & Eyewear Store',
    'hearing_aid_store'             => 'Hearing Aid Center',
    'orthopedic_supply_store'       => 'Orthopedic & Mobility Supply Store',

    // ═══════════════════════════════════════════════════════════════════════
    // RETAIL — Gift, Souvenir & Misc
    // ═══════════════════════════════════════════════════════════════════════

    'florist'                       => 'Florist',
    'gift_shop'                     => 'Gift Shop',
    'souvenir_store'                => 'Souvenir Shop',
    'pet_store'                     => 'Pet Store',
    'pet_supply_store'              => 'Pet Supply Store',
    'aquarium_pet_store'            => 'Aquarium & Exotic Pet Store',
    'store'                         => 'Retail Store',

    // ═══════════════════════════════════════════════════════════════════════
    // HEALTH & MEDICAL
    // ═══════════════════════════════════════════════════════════════════════

    'doctor'                        => 'Medical Office',
    'family_practice'               => 'Family Practice & Primary Care',
    'pediatrician'                  => "Pediatrician & Children's Health",
    'ob_gyn'                        => "OB-GYN & Women's Health",
    'dermatologist'                 => 'Dermatologist & Skin Care Clinic',
    'cardiologist'                  => 'Cardiologist & Heart Clinic',
    'orthopedic_surgeon'            => 'Orthopedic Surgeon & Sports Medicine',
    'neurologist'                   => 'Neurologist',
    'gastroenterologist'            => 'Gastroenterologist & Digestive Health',
    'urologist'                     => 'Urologist',
    'endocrinologist'               => 'Endocrinologist & Diabetes Care',
    'allergist'                     => 'Allergist & Immunologist',
    'oncologist'                    => 'Oncologist & Cancer Center',
    'psychiatrist'                  => 'Psychiatrist',
    'dentist'                       => 'Dental Office',
    'orthodontist'                  => 'Orthodontist & Braces',
    'periodontist'                  => 'Periodontist & Gum Specialist',
    'oral_surgeon'                  => 'Oral Surgeon',
    'pediatric_dentist'             => 'Pediatric Dentist',
    'cosmetic_dentist'              => 'Cosmetic Dentist & Teeth Whitening',
    'hospital'                      => 'Hospital',
    'urgent_care'                   => 'Urgent Care Clinic',
    'physiotherapist'               => 'Physical Therapy Clinic',
    'chiropractor'                  => 'Chiropractic Office',
    'optometrist'                   => 'Optometrist & Eye Care',
    'mental_health'                 => 'Mental Health & Therapy Practice',
    'acupuncturist'                 => 'Acupuncture & Oriental Medicine',
    'naturopath'                    => 'Naturopath & Holistic Health',
    'dietitian'                     => 'Registered Dietitian & Nutrition',
    'audiologist'                   => 'Audiologist & Hearing Clinic',
    'speech_therapist'              => 'Speech-Language Pathology',
    'occupational_therapist'        => 'Occupational Therapy',
    'plastic_surgeon'               => 'Plastic Surgeon & Cosmetic Surgery',
    'fertility_clinic'              => 'Fertility Clinic & Reproductive Health',
    'dialysis_center'               => 'Dialysis Center',
    'medical_lab'                   => 'Medical Laboratory & Diagnostics',
    'sleep_clinic'                  => 'Sleep Clinic & Sleep Medicine',
    'pain_management'               => 'Pain Management Clinic',
    'iv_therapy'                    => 'IV Therapy & Hydration Clinic',
    'functional_medicine'           => 'Functional Medicine Practice',
    'drug_rehab'                    => 'Drug & Alcohol Rehabilitation Center',
    'blood_bank'                    => 'Blood Bank & Donation Center',

    // ═══════════════════════════════════════════════════════════════════════
    // WELLNESS & BEAUTY
    // ═══════════════════════════════════════════════════════════════════════

    'spa'                           => 'Day Spa',
    'med_spa'                       => 'Medical Spa (MedSpa)',
    'beauty_salon'                  => 'Beauty Salon',
    'hair_care'                     => 'Hair Salon',
    'barber'                        => 'Barbershop',
    'nail_salon'                    => 'Nail Salon',
    'tanning_salon'                 => 'Tanning Salon',
    'tattoo_parlor'                 => 'Tattoo Studio',
    'piercing_studio'               => 'Body Piercing Studio',
    'microblading_studio'           => 'Microblading & Permanent Makeup Studio',
    'lash_studio'                   => 'Lash Extension Studio',
    'brow_bar'                      => 'Brow Bar & Threading Studio',
    'waxing_studio'                 => 'Waxing & Hair Removal Studio',
    'laser_hair_removal'            => 'Laser Hair Removal & Skin Clinic',
    'massage'                       => 'Massage Therapy Studio',
    'reflexology'                   => 'Reflexology & Foot Massage',
    'float_spa'                     => 'Float Tank & Sensory Deprivation Spa',
    'infrared_sauna'                => 'Infrared Sauna Studio',
    'cryotherapy'                   => 'Cryotherapy & Recovery Center',
    'gym'                           => 'Gym & Fitness Center',
    'yoga_studio'                   => 'Yoga Studio',
    'pilates_studio'                => 'Pilates Studio',
    'barre_studio'                  => 'Barre Studio',
    'crossfit'                      => 'CrossFit Gym',
    'personal_trainer'              => 'Personal Training Studio',
    'boxing_gym'                    => 'Boxing & Kickboxing Gym',
    'martial_arts_school'           => 'Martial Arts School',
    'dance_studio'                  => 'Dance Studio',
    'cycling_studio'                => 'Indoor Cycling & Spin Studio',
    'swimming_pool'                 => 'Swimming Pool & Aquatic Center',
    'weight_loss_center'            => 'Weight Loss Center',
    'wellness_center'               => 'Holistic Wellness Center',

    // ═══════════════════════════════════════════════════════════════════════
    // AUTOMOTIVE
    // ═══════════════════════════════════════════════════════════════════════

    'car_dealer'                    => 'Car Dealership',
    'used_car_dealer'               => 'Used Car Dealership',
    'luxury_car_dealer'             => 'Luxury Car Dealership',
    'electric_vehicle_dealer'       => 'Electric Vehicle Dealership',
    'motorcycle_dealer'             => 'Motorcycle & Powersports Dealership',
    'rv_dealer'                     => 'RV & Camper Dealership',
    'boat_dealer'                   => 'Boat & Marine Dealership',
    'car_repair'                    => 'Auto Repair Shop',
    'oil_change'                    => 'Oil Change & Lube Center',
    'tire_shop'                     => 'Tire Shop',
    'brake_service'                 => 'Brake & Exhaust Service',
    'transmission_repair'           => 'Transmission Repair',
    'collision_repair'              => 'Collision Repair & Body Shop',
    'auto_glass_repair'             => 'Auto Glass & Windshield Repair',
    'car_audio_shop'                => 'Car Audio & Electronics Shop',
    'vehicle_wrap'                  => 'Vehicle Wrap & Graphics',
    'car_wash'                      => 'Car Wash',
    'auto_detailing'                => 'Auto Detailing Studio',
    'gas_station'                   => 'Gas Station',
    'ev_charging_station'           => 'EV Charging Station',
    'auto_parts_store'              => 'Auto Parts Store',
    'towing_service'                => 'Towing & Roadside Assistance',
    'driving_school'                => 'Driving School',
    'smog_check'                    => 'Smog Check & Emissions Testing',

    // ═══════════════════════════════════════════════════════════════════════
    // PROFESSIONAL & FINANCIAL SERVICES
    // ═══════════════════════════════════════════════════════════════════════

    'lawyer'                        => 'Law Office',
    'family_lawyer'                 => 'Family Law Attorney',
    'criminal_lawyer'               => 'Criminal Defense Attorney',
    'immigration_lawyer'            => 'Immigration Attorney',
    'personal_injury_lawyer'        => 'Personal Injury Attorney',
    'real_estate_lawyer'            => 'Real Estate Attorney',
    'estate_planning_lawyer'        => 'Estate Planning & Probate Attorney',
    'corporate_lawyer'              => 'Business & Corporate Attorney',
    'accounting'                    => 'Accounting & Bookkeeping',
    'cpa_firm'                      => 'CPA Firm',
    'tax_preparation'               => 'Tax Preparation Service',
    'real_estate_agency'            => 'Real Estate Agency',
    'property_management'           => 'Property Management Company',
    'mortgage_broker'               => 'Mortgage Broker & Lender',
    'insurance_agency'              => 'Insurance Agency',
    'travel_agency'                 => 'Travel Agency',
    'financial_advisor'             => 'Financial Advisor & Planner',
    'bank'                          => 'Bank',
    'credit_union'                  => 'Credit Union',
    'currency_exchange'             => 'Currency Exchange',
    'check_cashing'                 => 'Check Cashing & Payday Loans',
    'notary'                        => 'Notary Public',
    'hr_consulting'                 => 'HR & Staffing Agency',
    'it_consulting'                 => 'IT Consulting & Managed Services',
    'business_consulting'           => 'Business Consulting',
    'marketing_agency'              => 'Marketing & Advertising Agency',
    'printing_shop'                 => 'Print & Copy Shop',
    'signage_company'               => 'Sign & Signage Company',
    'coworking_space'               => 'Coworking Space',
    'moving_company'                => 'Moving & Relocation Company',

    // ═══════════════════════════════════════════════════════════════════════
    // HOME & TRADE SERVICES
    // ═══════════════════════════════════════════════════════════════════════

    'plumber'                       => 'Plumbing Service',
    'electrician'                   => 'Electrician',
    'hvac'                          => 'HVAC & Heating & Cooling',
    'roofing_contractor'            => 'Roofing Contractor',
    'general_contractor'            => 'General Contractor',
    'remodeling_contractor'         => 'Kitchen & Bathroom Remodeler',
    'painting_contractor'           => 'Painting Contractor',
    'flooring_installer'            => 'Flooring Installer',
    'landscaping'                   => 'Landscaping & Lawn Care',
    'tree_service'                  => 'Tree Service & Arborist',
    'cleaning_service'              => 'House Cleaning Service',
    'commercial_cleaning'           => 'Commercial Cleaning & Janitorial',
    'carpet_cleaning'               => 'Carpet & Upholstery Cleaning',
    'pressure_washing'              => 'Pressure Washing Service',
    'pest_control'                  => 'Pest Control Service',
    'security_system'               => 'Security & Smart Home Installer',
    'solar_installer'               => 'Solar Panel Installer',
    'pool_service'                  => 'Pool & Spa Service',
    'handyman'                      => 'Handyman Service',
    'locksmith'                     => 'Locksmith',
    'garage_door_service'           => 'Garage Door Service & Repair',
    'masonry'                       => 'Masonry & Concrete Contractor',
    'fencing_contractor'            => 'Fencing Contractor',
    'interior_designer'             => 'Interior Designer & Decorator',
    'architect'                     => 'Architect',

    // ═══════════════════════════════════════════════════════════════════════
    // PERSONAL & LOCAL SERVICES
    // ═══════════════════════════════════════════════════════════════════════

    'laundry'                       => 'Laundromat',
    'dry_cleaning'                  => 'Dry Cleaner',
    'shoe_repair'                   => 'Shoe Repair Shop',
    'watch_repair'                  => 'Watch & Jewelry Repair',
    'electronics_repair'            => 'Electronics Repair Shop',
    'phone_repair'                  => 'Phone & Tablet Repair',
    'computer_repair'               => 'Computer Repair Shop',
    'appliance_repair'              => 'Appliance Repair Service',
    'bicycle_repair'                => 'Bicycle Repair Shop',
    'photography'                   => 'Photography Studio',
    'photo_printing'                => 'Photo Printing & Gifts',
    'post_office'                   => 'Post Office',
    'mailbox_store'                 => 'Mailbox & Shipping Center',
    'storage_facility'              => 'Self-Storage Facility',
    'funeral_home'                  => 'Funeral Home & Cremation Services',
    'wedding_planner'               => 'Wedding Planner & Coordinator',
    'event_planning'                => 'Event Planning & Party Rentals',
    'alterations_shop'              => 'Clothing Alterations & Tailoring',

    // ═══════════════════════════════════════════════════════════════════════
    // EDUCATION & CHILDCARE
    // ═══════════════════════════════════════════════════════════════════════

    'school'                        => 'K-12 School',
    'university'                    => 'College & University',
    'library'                       => 'Library',
    'tutoring'                      => 'Tutoring & Test Prep',
    'language_school'               => 'Language School & ESL',
    'music_school'                  => 'Music Lessons & School',
    'art_school'                    => 'Art Classes & School',
    'cooking_school'                => 'Cooking School & Culinary Classes',
    'dance_school'                  => 'Dance School',
    'coding_school'                 => 'Coding Bootcamp & STEM School',
    'vocational_school'             => 'Vocational & Trade School',
    'child_care'                    => 'Childcare Center & Daycare',
    'preschool'                     => 'Preschool & Pre-K',
    'after_school'                  => 'After-School Program',
    'swim_school'                   => 'Swim School & Lessons',
    'gymnastics_school'             => 'Gymnastics & Cheer School',

    // ═══════════════════════════════════════════════════════════════════════
    // ENTERTAINMENT & RECREATION
    // ═══════════════════════════════════════════════════════════════════════

    'movie_theater'                 => 'Movie Theater & Cinema',
    'drive_in_theater'              => 'Drive-In Theater',
    'museum'                        => 'Museum',
    'history_museum'                => 'History Museum',
    'science_museum'                => 'Science Center & Planetarium',
    'childrens_museum'              => "Children's Museum",
    'art_gallery'                   => 'Art Gallery & Exhibition Space',
    'amusement_park'                => 'Amusement Park & Theme Park',
    'water_park'                    => 'Water Park',
    'bowling_alley'                 => 'Bowling Alley',
    'casino'                        => 'Casino',
    'escape_room'                   => 'Escape Room',
    'arcade'                        => 'Arcade & Family Entertainment Center',
    'trampoline_park'               => 'Trampoline Park',
    'laser_tag'                     => 'Laser Tag Center',
    'go_kart'                       => 'Go-Kart Track',
    'mini_golf'                     => 'Mini Golf & Putt-Putt',
    'axe_throwing'                  => 'Axe Throwing Venue',
    'virtual_reality_arcade'        => 'Virtual Reality Arcade',
    'billiards'                     => 'Billiards & Pool Hall',
    'bingo_hall'                    => 'Bingo Hall',
    'paintball'                     => 'Paintball & Airsoft Field',
    'live_music_venue'              => 'Live Music Venue',
    'performing_arts_theater'       => 'Performing Arts Theater',
    'opera_house'                   => 'Opera House & Concert Hall',
    'comedy_club'                   => 'Comedy Club',
    'aquarium'                      => 'Aquarium',
    'zoo'                           => 'Zoo & Wildlife Park',
    'botanical_garden'              => 'Botanical Garden',

    // ═══════════════════════════════════════════════════════════════════════
    // SPORTS & FITNESS FACILITIES
    // ═══════════════════════════════════════════════════════════════════════

    'stadium'                       => 'Stadium & Arena',
    'sports_complex'                => 'Sports Complex & Recreation Center',
    'golf_course'                   => 'Golf Course & Country Club',
    'golf_driving_range'            => 'Golf Driving Range',
    'tennis_club'                   => 'Tennis Club & Courts',
    'ice_rink'                      => 'Ice Skating & Hockey Rink',
    'skate_park'                    => 'Skate Park',
    'rock_climbing'                 => 'Rock Climbing Gym',
    'shooting_range'                => 'Shooting Range',
    'equestrian'                    => 'Equestrian Center & Horse Riding',
    'surf_school'                   => 'Surf School & Watersports',
    'ski_resort'                    => 'Ski Resort & Snow Sports',
    'batting_cage'                  => 'Batting Cage & Sports Training',
    'soccer_complex'                => 'Soccer Complex & Futsal',
    'swim_center'                   => 'Swim Center & Lap Pool',

    // ═══════════════════════════════════════════════════════════════════════
    // HOSPITALITY & LODGING
    // ═══════════════════════════════════════════════════════════════════════

    'hotel'                         => 'Hotel',
    'luxury_hotel'                  => 'Luxury Hotel & Resort',
    'boutique_hotel'                => 'Boutique Hotel',
    'budget_hotel'                  => 'Budget Hotel & Motel',
    'extended_stay_hotel'           => 'Extended Stay Hotel',
    'bed_and_breakfast'             => 'Bed & Breakfast',
    'hostel'                        => 'Hostel',
    'vacation_rental'               => 'Vacation Rental',
    'glamping'                      => 'Glamping & Eco Retreat',
    'campground'                    => 'Campground & RV Park',
    'conference_center'             => 'Conference & Meeting Center',
    'wedding_venue'                 => 'Wedding Venue',
    'banquet_hall'                  => 'Banquet Hall & Event Venue',
    'lodging'                       => 'Lodging & Accommodation',

    // ═══════════════════════════════════════════════════════════════════════
    // PETS & ANIMALS
    // ═══════════════════════════════════════════════════════════════════════

    'veterinary_care'               => 'Veterinary Clinic',
    'animal_hospital'               => 'Animal Hospital & Emergency Vet',
    'exotic_vet'                    => 'Exotic Animal Veterinarian',
    'holistic_vet'                  => 'Holistic & Integrative Veterinarian',
    'pet_groomer'                   => 'Pet Groomer',
    'pet_boarding'                  => 'Pet Boarding & Kennel',
    'dog_daycare'                   => 'Dog Daycare & Boarding',
    'dog_training'                  => 'Dog Training & Behavior School',

    // ═══════════════════════════════════════════════════════════════════════
    // RELIGIOUS & COMMUNITY
    // ═══════════════════════════════════════════════════════════════════════

    'church'                        => 'Church & Christian Center',
    'catholic_church'               => 'Catholic Church & Parish',
    'evangelical_church'            => 'Evangelical & Pentecostal Church',
    'orthodox_church'               => 'Orthodox Church',
    'mosque'                        => 'Mosque & Islamic Center',
    'synagogue'                     => 'Synagogue & Jewish Center',
    'hindu_temple'                  => 'Hindu Temple',
    'sikh_gurdwara'                 => 'Sikh Gurdwara',
    'buddhist_temple'               => 'Buddhist Temple & Meditation Center',
    'place_of_worship'              => 'Place of Worship',
    'community_center'              => 'Community Center',
    'nonprofit'                     => 'Nonprofit & Charity',
    'senior_center'                 => 'Senior Center & Adult Day Program',

    // ═══════════════════════════════════════════════════════════════════════
    // GOVERNMENT & PUBLIC SERVICES
    // ═══════════════════════════════════════════════════════════════════════

    'city_hall'                     => 'City Hall & Government Office',
    'courthouse'                    => 'Courthouse',
    'fire_station'                  => 'Fire Station',
    'police'                        => 'Police Station',
    'embassy'                       => 'Embassy & Consulate',
    'local_government_office'       => 'Local Government & Licensing Office',

    // ═══════════════════════════════════════════════════════════════════════
    // TRANSPORTATION
    // ═══════════════════════════════════════════════════════════════════════

    'parking'                       => 'Parking Facility',
    'bus_station'                   => 'Bus & Transit Station',
    'train_station'                 => 'Train & Metro Station',
    'airport'                       => 'Airport',
    'car_rental'                    => 'Car Rental',

    // ═══════════════════════════════════════════════════════════════════════
    // CATCH-ALL
    // ═══════════════════════════════════════════════════════════════════════

    'point_of_interest'             => 'Point of Interest',
    'establishment'                 => 'General Business',
);