<?php
namespace Pylon\Core\Modules\LocalSeo;
defined('ABSPATH') || exit;
class LocalSEO {
    public function register(): void {
        add_action('admin_menu', [$this, 'add_admin_page']);
        add_action('admin_post_pylon_save_local_seo', [$this, 'save_settings']);
        add_action('wp_head', [$this, 'output_local_business_schema'], 15);
        add_shortcode('pylon_contact', [$this, 'contact_shortcode']);
    }

    /**
     * Resolve the schema.org type from the saved business type (falls back to LocalBusiness).
     */
    public function schema_type(): string {
        $type = get_option('pylon_local_business_type', 'LocalBusiness');
        $types = self::business_types();
        if (!isset($types[$type]) || $type === 'off') {
            return 'LocalBusiness';
        }
        return $type;
    }

    /**
     * Flattened schema.org local business types list (mirrors Rank Math choices).
     */
    public static function business_types(): array {
        return [
            'off' => 'None',
            'Organization' => 'Organization',
            'Airline' => '&mdash; Airline',
            'Consortium' => '&mdash; Consortium',
            'Corporation' => '&mdash; Corporation',
            'EducationalOrganization' => '&mdash; Educational Organization',
            'CollegeOrUniversity' => '&mdash; &mdash; College Or University',
            'ElementarySchool' => '&mdash; &mdash; Elementary School',
            'HighSchool' => '&mdash; &mdash; High School',
            'MiddleSchool' => '&mdash; &mdash; Middle School',
            'Preschool' => '&mdash; &mdash; Preschool',
            'School' => '&mdash; &mdash; School',
            'FundingScheme' => '&mdash; Funding Scheme',
            'GovernmentOrganization' => '&mdash; Government Organization',
            'LibrarySystem' => '&mdash; Library System',
            'LocalBusiness' => '&mdash; Local Business',
            'AnimalShelter' => '&mdash; &mdash; Animal Shelter',
            'ArchiveOrganization' => '&mdash; &mdash; Archive Organization',
            'AutomotiveBusiness' => '&mdash; &mdash; Automotive Business',
            'AutoBodyShop' => '&mdash; &mdash; &mdash; Auto Body Shop',
            'AutoDealer' => '&mdash; &mdash; &mdash; Auto Dealer',
            'AutoPartsStore' => '&mdash; &mdash; &mdash; Auto Parts Store',
            'AutoRental' => '&mdash; &mdash; &mdash; Auto Rental',
            'AutoRepair' => '&mdash; &mdash; &mdash; Auto Repair',
            'AutoWash' => '&mdash; &mdash; &mdash; Auto Wash',
            'GasStation' => '&mdash; &mdash; &mdash; Gas Station',
            'MotorcycleDealer' => '&mdash; &mdash; &mdash; Motorcycle Dealer',
            'MotorcycleRepair' => '&mdash; &mdash; &mdash; Motorcycle Repair',
            'ChildCare' => '&mdash; &mdash; Child Care',
            'DryCleaningOrLaundry' => '&mdash; &mdash; Dry Cleaning Or Laundry',
            'EmergencyService' => '&mdash; &mdash; Emergency Service',
            'FireStation' => '&mdash; &mdash; &mdash; Fire Station',
            'Hospital' => '&mdash; &mdash; &mdash; Hospital',
            'PoliceStation' => '&mdash; &mdash; &mdash; Police Station',
            'EmploymentAgency' => '&mdash; &mdash; Employment Agency',
            'EntertainmentBusiness' => '&mdash; &mdash; Entertainment Business',
            'AdultEntertainment' => '&mdash; &mdash; &mdash; Adult Entertainment',
            'AmusementPark' => '&mdash; &mdash; &mdash; Amusement Park',
            'ArtGallery' => '&mdash; &mdash; &mdash; Art Gallery',
            'Casino' => '&mdash; &mdash; &mdash; Casino',
            'ComedyClub' => '&mdash; &mdash; &mdash; Comedy Club',
            'MovieTheater' => '&mdash; &mdash; &mdash; Movie Theater',
            'NightClub' => '&mdash; &mdash; &mdash; Night Club',
            'FinancialService' => '&mdash; &mdash; Financial Service',
            'AccountingService' => '&mdash; &mdash; &mdash; Accounting Service',
            'AutomatedTeller' => '&mdash; &mdash; &mdash; Automated Teller',
            'BankOrCreditUnion' => '&mdash; &mdash; &mdash; Bank Or CreditUnion',
            'InsuranceAgency' => '&mdash; &mdash; &mdash; Insurance Agency',
            'FoodEstablishment' => '&mdash; &mdash; Food Establishment',
            'Bakery' => '&mdash; &mdash; &mdash; Bakery',
            'BarOrPub' => '&mdash; &mdash; &mdash; Bar Or Pub',
            'Brewery' => '&mdash; &mdash; &mdash; Brewery',
            'CafeOrCoffeeShop' => '&mdash; &mdash; &mdash; Cafe Or CoffeeShop',
            'Distillery' => '&mdash; &mdash; &mdash; Distillery',
            'FastFoodRestaurant' => '&mdash; &mdash; &mdash; Fast Food Restaurant',
            'IceCreamShop' => '&mdash; &mdash; &mdash; IceCream Shop',
            'Restaurant' => '&mdash; &mdash; &mdash; Restaurant',
            'Winery' => '&mdash; &mdash; &mdash; Winery',
            'GovernmentOffice' => '&mdash; &mdash; Government Office',
            'PostOffice' => '&mdash; &mdash; &mdash; Post Office',
            'HealthAndBeautyBusiness' => '&mdash; &mdash; Health And Beauty Business',
            'BeautySalon' => '&mdash; &mdash; &mdash; Beauty Salon',
            'DaySpa' => '&mdash; &mdash; &mdash; Day Spa',
            'HairSalon' => '&mdash; &mdash; &mdash; Hair Salon',
            'HealthClub' => '&mdash; &mdash; &mdash; Health Club',
            'NailSalon' => '&mdash; &mdash; &mdash; Nail Salon',
            'TattooParlor' => '&mdash; &mdash; &mdash; Tattoo Parlor',
            'HomeAndConstructionBusiness' => '&mdash; &mdash; Home And Construction Business',
            'Electrician' => '&mdash; &mdash; &mdash; Electrician',
            'GeneralContractor' => '&mdash; &mdash; &mdash; General Contractor',
            'HVACBusiness' => '&mdash; &mdash; &mdash; HVAC Business',
            'HousePainter' => '&mdash; &mdash; &mdash; House Painter',
            'Locksmith' => '&mdash; &mdash; &mdash; Locksmith',
            'MovingCompany' => '&mdash; &mdash; &mdash; Moving Company',
            'Plumber' => '&mdash; &mdash; &mdash; Plumber',
            'RoofingContractor' => '&mdash; &mdash; &mdash; Roofing Contractor',
            'InternetCafe' => '&mdash; &mdash; Internet Cafe',
            'LegalService' => '&mdash; &mdash; Legal Service',
            'Notary' => '&mdash; &mdash; &mdash; Notary',
            'Library' => '&mdash; &mdash; Library',
            'LodgingBusiness' => '&mdash; &mdash; Lodging Business',
            'BedAndBreakfast' => '&mdash; &mdash; &mdash; Bed And Breakfast',
            'Campground' => '&mdash; &mdash; &mdash; Campground',
            'Hostel' => '&mdash; &mdash; &mdash; Hostel',
            'Hotel' => '&mdash; &mdash; &mdash; Hotel',
            'Motel' => '&mdash; &mdash; &mdash; Motel',
            'Resort' => '&mdash; &mdash; &mdash; Resort',
            'SkiResort' => '&mdash; &mdash; &mdash; Ski Resort',
            'MedicalBusiness' => '&mdash; &mdash; Medical Business',
            'CommunityHealth' => '&mdash; &mdash; &mdash; Community Health',
            'Dentist' => '&mdash; &mdash; &mdash; Dentist',
            'Dermatology' => '&mdash; &mdash; &mdash; Dermatology',
            'DietNutrition' => '&mdash; &mdash; &mdash; Diet Nutrition',
            'Emergency' => '&mdash; &mdash; &mdash; Emergency',
            'Geriatric' => '&mdash; &mdash; &mdash; Geriatric',
            'Gynecologic' => '&mdash; &mdash; &mdash; Gynecologic',
            'MedicalClinic' => '&mdash; &mdash; &mdash; Medical Clinic',
            'Optician' => '&mdash; &mdash; &mdash; Optician',
            'Pharmacy' => '&mdash; &mdash; &mdash; Pharmacy',
            'Physician' => '&mdash; &mdash; &mdash; Physician',
            'ProfessionalService' => '&mdash; &mdash; Professional Service',
            'RadioStation' => '&mdash; &mdash; Radio Station',
            'RealEstateAgent' => '&mdash; &mdash; Real Estate Agent',
            'RecyclingCenter' => '&mdash; &mdash; Recycling Center',
            'SelfStorage' => '&mdash; &mdash; Self Storage',
            'ShoppingCenter' => '&mdash; &mdash; Shopping Center',
            'SportsActivityLocation' => '&mdash; &mdash; Sports Activity Location',
            'BowlingAlley' => '&mdash; &mdash; &mdash; Bowling Alley',
            'ExerciseGym' => '&mdash; &mdash; &mdash; Exercise Gym',
            'GolfCourse' => '&mdash; &mdash; &mdash; Golf Course',
            'PublicSwimmingPool' => '&mdash; &mdash; &mdash; Public Swimming Pool',
            'SportsClub' => '&mdash; &mdash; &mdash; Sports Club',
            'StadiumOrArena' => '&mdash; &mdash; &mdash; Stadium Or Arena',
            'TennisComplex' => '&mdash; &mdash; &mdash; Tennis Complex',
            'Store' => '&mdash; &mdash; Store',
            'BikeStore' => '&mdash; &mdash; &mdash; Bike Store',
            'BookStore' => '&mdash; &mdash; &mdash; Book Store',
            'ClothingStore' => '&mdash; &mdash; &mdash; Clothing Store',
            'ComputerStore' => '&mdash; &mdash; &mdash; Computer Store',
            'ConvenienceStore' => '&mdash; &mdash; &mdash; Convenience Store',
            'DepartmentStore' => '&mdash; &mdash; &mdash; Department Store',
            'ElectronicsStore' => '&mdash; &mdash; &mdash; Electronics Store',
            'Florist' => '&mdash; &mdash; &mdash; Florist',
            'FurnitureStore' => '&mdash; &mdash; &mdash; Furniture Store',
            'GardenStore' => '&mdash; &mdash; &mdash; Garden Store',
            'GroceryStore' => '&mdash; &mdash; &mdash; Grocery Store',
            'HardwareStore' => '&mdash; &mdash; &mdash; Hardware Store',
            'HobbyShop' => '&mdash; &mdash; &mdash; Hobby Shop',
            'HomeGoodsStore' => '&mdash; &mdash; &mdash; Home Goods Store',
            'JewelryStore' => '&mdash; &mdash; &mdash; Jewelry Store',
            'LiquorStore' => '&mdash; &mdash; &mdash; Liquor Store',
            'MensClothingStore' => '&mdash; &mdash; &mdash; Mens Clothing Store',
            'MobilePhoneStore' => '&mdash; &mdash; &mdash; Mobile Phone Store',
            'MovieRentalStore' => '&mdash; &mdash; &mdash; Movie Rental Store',
            'MusicStore' => '&mdash; &mdash; &mdash; Music Store',
            'OfficeEquipmentStore' => '&mdash; &mdash; &mdash; Office Equipment Store',
            'OutletStore' => '&mdash; &mdash; &mdash; Outlet Store',
            'PawnShop' => '&mdash; &mdash; &mdash; Pawn Shop',
            'PetStore' => '&mdash; &mdash; &mdash; Pet Store',
            'ShoeStore' => '&mdash; &mdash; &mdash; Shoe Store',
            'SportingGoodsStore' => '&mdash; &mdash; &mdash; Sporting GoodsStore',
            'TireShop' => '&mdash; &mdash; &mdash; Tire Shop',
            'ToyStore' => '&mdash; &mdash; &mdash; Toy Store',
            'WholesaleStore' => '&mdash; &mdash; &mdash; Wholesale Store',
            'TelevisionStation' => '&mdash; &mdash; Television Station',
            'TouristInformationCenter' => '&mdash; &mdash; Tourist Information Center',
            'TravelAgency' => '&mdash; &mdash; Travel Agency',
            'MedicalOrganization' => '&mdash; Medical Organization',
            'DiagnosticLab' => '&mdash; &mdash; Diagnostic Lab',
            'VeterinaryCare' => '&mdash; &mdash; Veterinary Care',
            'NGO' => '&mdash; NGO',
            'OnlineBusiness' => '&mdash; Online Business',
            'OnlineStore' => '&mdash; &mdash; Online Store',
            'NewsMediaOrganization' => '&mdash; News Media Organization',
            'PerformingGroup' => '&mdash; Performing Group',
            'DanceGroup' => '&mdash; &mdash; Dance Group',
            'MusicGroup' => '&mdash; &mdash; Music Group',
            'TheaterGroup' => '&mdash; &mdash; Theater Group',
            'Project' => '&mdash; Project',
            'FundingAgency' => '&mdash; &mdash; Funding Agency',
            'ResearchProject' => '&mdash; &mdash; Research Project',
            'SportsOrganization' => '&mdash; Sports Organization',
            'SportsTeam' => '&mdash; &mdash; Sports Team',
            'WorkersUnion' => '&mdash; Workers Union',
        ];
    }

    public function add_admin_page(): void {
        add_submenu_page(
            'pylon',
            __('Local SEO', 'pylon-seo'),
            __('Local SEO', 'pylon-seo'),
            'manage_options',
            'pylon-local-seo',
            [$this, 'render_admin_page']
        );
    }

    public function render_admin_page(): void {
        $fields = [
            'pylon_local_business_name' => __('Business Name', 'pylon-seo'),
            'pylon_local_street' => __('Street Address', 'pylon-seo'),
            'pylon_local_city' => __('City', 'pylon-seo'),
            'pylon_local_state' => __('State / Region', 'pylon-seo'),
            'pylon_local_zip' => __('ZIP / Postal Code', 'pylon-seo'),
            'pylon_local_phone' => __('Phone', 'pylon-seo'),
            'pylon_local_email' => __('Email', 'pylon-seo'),
            'pylon_local_lat' => __('Latitude', 'pylon-seo'),
            'pylon_local_lng' => __('Longitude', 'pylon-seo'),
            'pylon_local_image' => __('Logo / Image URL', 'pylon-seo'),
        ];
        $current_type = get_option('pylon_local_business_type', 'LocalBusiness');
        $hours = get_option('pylon_local_hours', []);
        $day_names = [__('Monday', 'pylon-seo'), __('Tuesday', 'pylon-seo'), __('Wednesday', 'pylon-seo'), __('Thursday', 'pylon-seo'), __('Friday', 'pylon-seo'), __('Saturday', 'pylon-seo'), __('Sunday', 'pylon-seo')];
        ?>
        <div class="wrap" style="max-width:800px;">
            <?php \Pylon\Core\Modules\Admin\AdminEngine::page_header(__('Local SEO Settings', 'pylon-seo'), '📍'); ?>
            <?php if (isset($_GET['saved'])): ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'pylon-seo'); ?></p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="pylon_save_local_seo">
                <?php wp_nonce_field('pylon_save_local_seo', 'pylon_local_seo_nonce'); ?>
                <div class="pylon-card">
                    <div class="pylon-card-header"><h3><?php esc_html_e('Business Information', 'pylon-seo'); ?></h3></div>
                    <div class="pylon-card-body">
                        <div class="pylon-form-group">
                            <label><?php esc_html_e('Business Type', 'pylon-seo'); ?></label>
                            <select name="pylon_local_business_type" class="pylon-select">
                                <?php foreach (self::business_types() as $key => $label): ?>
                                    <option value="<?php echo esc_attr($key); ?>" <?php selected($current_type, $key); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php foreach ($fields as $key => $label): ?>
                            <div class="pylon-form-group">
                                <label><?php echo esc_html($label); ?></label>
                                <input type="text" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr(get_option($key, '')); ?>" class="pylon-input">
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="pylon-card">
                    <div class="pylon-card-header"><h3><?php esc_html_e('Opening Hours', 'pylon-seo'); ?></h3></div>
                    <div class="pylon-card-body">
                        <p style="font-size:13px;color:var(--pylon-gray-400);margin-top:0;"><?php esc_html_e('Leave closed days blank.', 'pylon-seo'); ?></p>
                        <?php foreach ($day_names as $i => $day): ?>
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                <span style="min-width:90px;font-size:13px;font-weight:500;"><?php echo esc_html($day); ?></span>
                                <input type="time" name="pylon_local_hours[<?php echo esc_attr($i); ?>][open]" value="<?php echo esc_attr($hours[$i]['open'] ?? ''); ?>" class="pylon-input" style="width:140px;" placeholder="09:00">
                                <span style="color:var(--pylon-gray-400);">—</span>
                                <input type="time" name="pylon_local_hours[<?php echo esc_attr($i); ?>][close]" value="<?php echo esc_attr($hours[$i]['close'] ?? ''); ?>" class="pylon-input" style="width:140px;" placeholder="17:00">
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="pylon-card" style="margin-top:16px;">
                    <div class="pylon-card-body">
                        <?php
                        $lat = get_option('pylon_local_lat', '');
                        $lng = get_option('pylon_local_lng', '');
                        if ($lat && $lng):
                        ?>
                            <div style="border:1px solid var(--pylon-gray-200,#e5e7eb);border-radius:6px;overflow:hidden;height:250px;margin-bottom:12px;">
                                <iframe src="https://www.openstreetmap.org/export/embed.html?bbox=<?php echo esc_attr((float)$lng - 0.02); ?>,<?php echo esc_attr((float)$lat - 0.02); ?>,<?php echo esc_attr((float)$lng + 0.02); ?>,<?php echo esc_attr((float)$lat + 0.02); ?>&layer=mapnik&marker=<?php echo esc_attr((float)$lat); ?>,<?php echo esc_attr((float)$lng); ?>" style="width:100%;height:100%;border:0;" loading="lazy"></iframe>
                            </div>
                        <?php endif; ?>
                        <button type="submit" class="pylon-btn pylon-btn-primary"><?php esc_html_e('Save Settings', 'pylon-seo'); ?></button>
                    </div>
                </div>
            </form>
        </div>
        <?php
    }

    public function save_settings(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized.');
        check_admin_referer('pylon_save_local_seo', 'pylon_local_seo_nonce');

        $text_fields = [
            'pylon_local_business_name', 'pylon_local_street', 'pylon_local_city',
            'pylon_local_state', 'pylon_local_zip', 'pylon_local_phone',
            'pylon_local_email', 'pylon_local_lat', 'pylon_local_lng', 'pylon_local_image',
        ];
        foreach ($text_fields as $key) {
            $val = sanitize_text_field(wp_unslash($_POST[$key] ?? ''));
            update_option($key, $val);
        }

        $type = sanitize_text_field(wp_unslash($_POST['pylon_local_business_type'] ?? 'LocalBusiness'));
        $valid_types = array_keys(self::business_types());
        $type = in_array($type, $valid_types, true) ? $type : 'LocalBusiness';
        update_option('pylon_local_business_type', $type);

        $raw = isset($_POST['pylon_local_hours']) ? (array) wp_unslash($_POST['pylon_local_hours']) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value sanitized below.
        $hours = [];
        foreach ($raw as $i => $h) {
            $open = sanitize_text_field($h['open'] ?? '');
            $close = sanitize_text_field($h['close'] ?? '');
            if ($open && $close) {
                $hours[$i] = ['open' => $open, 'close' => $close];
            }
        }
        update_option('pylon_local_hours', $hours);

        wp_redirect(add_query_arg(['saved' => '1'], wp_get_referer() ?: admin_url('admin.php?page=pylon-group-audit&tab=local-seo')));
        exit;
    }

    public function contact_shortcode(array $atts = []): string {
        $atts = shortcode_atts([
            'show' => 'all', // all|address|phone|email|hours
            'class' => '',
        ], $atts, 'pylon_contact');

        $parts = array_map('trim', explode(',', $atts['show']));
        $items = [];

        $street = get_option('pylon_local_street', '');
        $city   = get_option('pylon_local_city', '');
        $state  = get_option('pylon_local_state', '');
        $zip    = get_option('pylon_local_zip', '');

        $include = fn($k) => in_array('all', $parts, true) || in_array($k, $parts, true);

        if ($include('address') && ($street || $city)) {
            $addr = trim(implode(', ', array_filter([$street, $city, $state, $zip])));
            if ($addr) {
                $items[] = [
                    'label' => __('Address', 'pylon-seo'),
                    'value' => $addr,
                ];
            }
        }

        $phone = get_option('pylon_local_phone', '');
        if ($include('phone') && $phone) {
            $items[] = [
                'label' => __('Phone', 'pylon-seo'),
                'value' => $phone,
                'href' => 'tel:' . preg_replace('/[^0-9+]/', '', $phone),
            ];
        }

        $email = get_option('pylon_local_email', '');
        if ($include('email') && $email) {
            $items[] = [
                'label' => __('Email', 'pylon-seo'),
                'value' => $email,
                'href' => 'mailto:' . $email,
            ];
        }

        if (empty($items)) {
            return '';
        }

        $class = $atts['class'] ? ' pylon-contact ' . esc_attr($atts['class']) : ' pylon-contact';
        $out = '<div class="' . trim($class) . '">';
        foreach ($items as $item) {
            $out .= '<p class="pylon-contact-item">';
            $out .= '<span class="pylon-contact-label">' . esc_html($item['label']) . ':</span> ';
            if (!empty($item['href'])) {
                $out .= '<a href="' . esc_url($item['href']) . '">' . esc_html($item['value']) . '</a>';
            } else {
                $out .= esc_html($item['value']);
            }
            $out .= '</p>';
        }
        $out .= '</div>';
        return $out;
    }

    public function output_local_business_schema(): void {
        $name = get_option('pylon_local_business_name', '');
        if (!$name) return;

        // Skip if the current post already outputs a LocalBusiness schema via SchemaEngine.
        if (is_singular() && get_post_meta(get_the_ID(), 'pylon_schema_type', true) === 'LocalBusiness') return;

        $cached = get_transient('pylon_local_schema_global');
        if (is_array($cached)) {
            \Pylon\Core\JsonLd::script($cached);
            return;
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => $this->schema_type(),
            'name' => $name,
        ];

        $street = get_option('pylon_local_street', '');
        $city = get_option('pylon_local_city', '');
        $state = get_option('pylon_local_state', '');
        $zip = get_option('pylon_local_zip', '');
        if ($street || $city) {
            $addr = ['@type' => 'PostalAddress'];
            if ($street) $addr['streetAddress'] = $street;
            if ($city) $addr['addressLocality'] = $city;
            if ($state) $addr['addressRegion'] = $state;
            if ($zip) $addr['postalCode'] = $zip;
            $schema['address'] = $addr;
        }

        $phone = get_option('pylon_local_phone', '');
        if ($phone) $schema['telephone'] = $phone;

        $email = get_option('pylon_local_email', '');
        if ($email) $schema['email'] = $email;

        $image = get_option('pylon_local_image', '');
        if ($image) $schema['image'] = $image;

        $lat = get_option('pylon_local_lat', '');
        $lng = get_option('pylon_local_lng', '');
        if ($lat && $lng) {
            $schema['geo'] = [
                '@type' => 'GeoCoordinates',
                'latitude' => (float) $lat,
                'longitude' => (float) $lng,
            ];
        }

        $hours = get_option('pylon_local_hours', []);
        if (!empty($hours)) {
            $day_map = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];
            $opening = [];
            foreach ($hours as $i => $h) {
                if (!empty($h['open']) && !empty($h['close'])) {
                    $opening[] = $day_map[$i] . ' ' . $h['open'] . '-' . $h['close'];
                }
            }
            if (!empty($opening)) {
                $schema['openingHoursSpecification'] = [];
                foreach ($hours as $i => $h) {
                    if (!empty($h['open']) && !empty($h['close'])) {
                        $schema['openingHoursSpecification'][] = [
                            '@type' => 'OpeningHoursSpecification',
                            'dayOfWeek' => $day_map[$i],
                            'opens' => $h['open'],
                            'closes' => $h['close'],
                        ];
                    }
                }
            }
        }

        \Pylon\Core\JsonLd::script($schema);
        set_transient('pylon_local_schema_global', $schema, HOUR_IN_SECONDS);
    }
}
