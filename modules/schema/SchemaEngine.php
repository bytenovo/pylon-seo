<?php
namespace Pylon\Core\Modules\Schema;
defined('ABSPATH') || exit;
class SchemaEngine {
    private array $types = [
        'Article', 'BlogPosting', 'NewsArticle', 'Product', 'LocalBusiness',
        'FAQPage', 'HowTo', 'Recipe', 'Event', 'Organization',
        'Person', 'BreadcrumbList', 'VideoObject',
    ];

    /**
     * Allow-list used when printing breadcrumb markup (see wp_kses calls).
     */
    public const BREADCRUMB_ALLOWED_HTML = [
        'div' => ['class' => true],
        'nav' => ['class' => true, 'aria-label' => true, 'itemscope' => true, 'itemtype' => true],
        'span' => ['class' => true, 'itemprop' => true, 'itemscope' => true, 'itemtype' => true, 'aria-hidden' => true],
        'a' => ['href' => true, 'class' => true, 'itemprop' => true, 'itemscope' => true, 'itemtype' => true, 'rel' => true],
        'meta' => ['itemprop' => true, 'content' => true],
    ];

    /**
     * Wrapper tags themes use around widgets (before_widget/after_widget etc).
     */
    public static function widget_wrapper_allowed_html(): array {
        return [
            'section' => ['id' => true, 'class' => true],
            'div' => ['id' => true, 'class' => true],
            'aside' => ['id' => true, 'class' => true],
            'li' => ['id' => true, 'class' => true],
            'h2' => ['class' => true],
            'h3' => ['class' => true],
            'h4' => ['class' => true],
            'span' => ['class' => true],
            'p' => ['class' => true],
        ];
    }

    public function register(): void {
        add_action('wp_head', [$this, 'output_schema'], 1);
        add_action('pylon/metabox/render', [$this, 'render_metabox']);
        add_action('save_post', [$this, 'save_metabox'], 10, 2);
        add_action('save_post', [$this, 'clear_schema_cache'], 20);
        add_shortcode('pylon_breadcrumb', [$this, 'shortcode_breadcrumb']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_breadcrumb_styles']);
        add_action('widgets_init', [$this, 'register_breadcrumb_widget']);
        add_action('wp_body_open', [$this, 'auto_insert_breadcrumbs'], 5);
        add_filter('the_content', [$this, 'prepend_breadcrumbs_to_content'], 8);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_metabox_assets']);
    }

    public function enqueue_metabox_assets(string $hook): void {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) return;
        wp_add_inline_script('pylon-admin-js', '
        function pylonToggleSchemaFields(type) {
            document.querySelectorAll(".pylon-schema-type-fields").forEach(function(el){ el.style.display = "none"; });
            var map = { "Product": "pylon-schema-product", "LocalBusiness": "pylon-schema-localbusiness", "Event": "pylon-schema-event", "Person": "pylon-schema-person" };
            var id = map[type];
            if (id) { document.getElementById(id).style.display = "block"; }
        }
        ');
    }

    public function enqueue_breadcrumb_styles(): void {
        if (!get_option('pylon_breadcrumb_enabled', '1')) return;
        if (!is_singular() && !is_archive() && !is_search() && !is_404() && !is_home()) return;
        wp_add_inline_style('wp-block-library', '
            .pylon-breadcrumbs{display:flex;flex-wrap:wrap;align-items:center;gap:6px;font-size:14px;line-height:1.6;padding:10px 0;color:var(--pylon-gray-600,#6b7280);}
            .pylon-breadcrumbs a{color:var(--pylon-primary,#4f46e5);text-decoration:none;}
            .pylon-breadcrumbs a:hover{text-decoration:underline;}
            .pylon-breadcrumbs .pylon-bc-sep{color:var(--pylon-gray-400,#9ca3af);user-select:none;}
            .pylon-breadcrumbs .pylon-bc-current{color:var(--pylon-gray-800,#1f2937);font-weight:500;}
        ');
    }

    public function register_breadcrumb_widget(): void {
        register_widget(__NAMESPACE__ . '\\BreadcrumbWidget');
    }

    public function shortcode_breadcrumb(): string {
        return $this->render_frontend_breadcrumbs();
    }

    /**
     * Auto-insert via wp_body_open when location is "body" or "auto".
     */
    public function auto_insert_breadcrumbs(): void {
        if (!$this->should_auto_insert_breadcrumbs()) {
            return;
        }
        $location = get_option('pylon_breadcrumb_auto_location', 'auto');
        if (!in_array($location, ['body', 'auto'], true)) {
            return;
        }
        $html = $this->render_frontend_breadcrumbs();
        if ($html === '') {
            return;
        }
        echo wp_kses($this->wrap_auto_breadcrumbs($html), self::BREADCRUMB_ALLOWED_HTML);
        $GLOBALS['pylon_breadcrumbs_auto_printed'] = true;
    }

    /**
     * Insert above post content when location is "content", or as fallback when
     * "auto"/"body" was selected but the theme never fired wp_body_open.
     */
    public function prepend_breadcrumbs_to_content(string $content): string {
        if (!$this->should_auto_insert_breadcrumbs()) {
            return $content;
        }
        if (!is_singular() || !in_the_loop() || !is_main_query()) {
            return $content;
        }
        if (!empty($GLOBALS['pylon_breadcrumbs_auto_printed'])) {
            return $content;
        }

        $location = get_option('pylon_breadcrumb_auto_location', 'auto');
        $allow = ($location === 'content')
            || (in_array($location, ['body', 'auto'], true) && !did_action('wp_body_open'));
        if (!$allow) {
            return $content;
        }

        $crumbs = $this->render_frontend_breadcrumbs();
        if ($crumbs === '') {
            return $content;
        }
        $GLOBALS['pylon_breadcrumbs_auto_printed'] = true;
        return $this->wrap_auto_breadcrumbs($crumbs) . $content;
    }

    private function should_auto_insert_breadcrumbs(): bool {
        if (is_admin() || wp_doing_ajax() || is_feed()) {
            return false;
        }
        if (!get_option('pylon_breadcrumb_enabled', '1')) {
            return false;
        }
        return (bool) get_option('pylon_breadcrumb_auto_insert', '0');
    }

    private function wrap_auto_breadcrumbs(string $html): string {
        if ($html === '') {
            return '';
        }
        return '<div class="pylon-breadcrumbs-wrap">' . $html . '</div>';
    }

    public function render_frontend_breadcrumbs(): string {
        if (!get_option('pylon_breadcrumb_enabled', '1')) return '';

        if (is_front_page() && !get_option('pylon_breadcrumb_show_on_home', '1')) return '';

        $home_text = get_option('pylon_breadcrumb_home_text', __('Home', 'pylon-seo'));
        $separator = get_option('pylon_breadcrumb_separator', '→');

        $items = [];
        $items[] = '<a href="' . esc_url(home_url()) . '">' . esc_html($home_text) . '</a>';

        if (is_singular()) {
            $post = get_queried_object();
            $post_type = get_post_type_object($post->post_type);

            if ($post_type && $post_type->has_archive) {
                $items[] = '<a href="' . esc_url(get_post_type_archive_link($post->post_type)) . '">' . esc_html($post_type->labels->name) . '</a>';
            }

            if ($post->post_type === 'post') {
                $categories = get_the_category();
                if (!empty($categories)) {
                    $items[] = '<a href="' . esc_url(get_category_link($categories[0]->term_id)) . '">' . esc_html($categories[0]->name) . '</a>';
                }
            }

            $items[] = '<span class="pylon-bc-current">' . esc_html(get_the_title()) . '</span>';
        } elseif (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();
            $items[] = '<span class="pylon-bc-current">' . esc_html($term->name ?? '') . '</span>';
        } elseif (is_search()) {
            /* translators: %s: the search query. */
            $items[] = '<span class="pylon-bc-current">' . sprintf(__('Search: %s', 'pylon-seo'), get_search_query()) . '</span>';
        } elseif (is_404()) {
            $items[] = '<span class="pylon-bc-current">' . __('404 Not Found', 'pylon-seo') . '</span>';
        } elseif (is_home()) {
            $page_for_posts = (int) get_option('page_for_posts');
            $title = $page_for_posts ? get_the_title($page_for_posts) : __('Blog', 'pylon-seo');
            $items[] = '<span class="pylon-bc-current">' . esc_html($title) . '</span>';
        } elseif (is_archive()) {
            $items[] = '<span class="pylon-bc-current">' . esc_html(get_the_archive_title()) . '</span>';
        }

        $html = '<nav class="pylon-breadcrumbs" aria-label="' . esc_attr__('Breadcrumb', 'pylon-seo') . '" itemscope itemtype="https://schema.org/BreadcrumbList">';
        foreach ($items as $i => $item) {
            $position = $i + 1;
            $html .= '<span itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">';
            $html .= $item;
            $html .= '<meta itemprop="position" content="' . $position . '">';
            $html .= '</span>';
            if ($i < count($items) - 1) {
                $html .= '<span class="pylon-bc-sep" aria-hidden="true">' . esc_html($separator) . '</span>';
            }
        }
        $html .= '</nav>';

        return $html;
    }

    public function save_metabox(int $post_id, \WP_Post $post): void {
        if (!isset($_POST['pylon_schema_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['pylon_schema_nonce'])), 'pylon_schema')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $fields = [
            'pylon_woo_brand', 'pylon_woo_mpn', 'pylon_woo_gtin',
            'pylon_event_start', 'pylon_event_end', 'pylon_event_venue',
        ];

        foreach ($fields as $key) {
            if (isset($_POST[$key])) {
                update_post_meta($post_id, $key, sanitize_text_field(wp_unslash($_POST[$key])));
            }
        }
    }

    public function output_schema(): void {
        if (!get_option('pylon_schema_enabled', '1')) return;
        if (is_admin()) return;

        $post_id = get_queried_object_id();
        if (is_singular() && $post_id) {
            $cached = get_transient('pylon_schema_' . $post_id);
            if (is_array($cached)) {
                \Pylon\Core\JsonLd::script($cached);
                return;
            }
        }

        $schema = $this->build_schema();
        if ($schema) {
            \Pylon\Core\JsonLd::script($schema);
            if (is_singular() && !empty($post_id)) {
                set_transient('pylon_schema_' . $post_id, $schema, HOUR_IN_SECONDS);
            }
        }
    }

    public function clear_schema_cache(int $post_id): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        delete_transient('pylon_schema_' . $post_id);
    }

    private function build_schema(): ?array {
        $graph = [];

        $organization = array_filter([
            '@type' => 'Organization',
            '@id' => home_url('/#organization'),
            'name' => get_bloginfo('name'),
            'url' => home_url(),
            'logo' => $this->get_logo_url() ? [
                '@type' => 'ImageObject',
                '@id' => home_url('/#logo'),
                'url' => $this->get_logo_url(),
            ] : null,
        ], static function ($v) {
            return $v !== null && $v !== '';
        });

        $same_as = \Pylon\Core\Modules\Social\SocialLinks::get_same_as_urls();
        if (!empty($same_as)) {
            $organization['sameAs'] = $same_as;
        }

        $graph[] = $organization;
        $graph[] = $this->build_website_schema();

        if (is_singular()) {
            $post = get_queried_object();
            if (!$post instanceof \WP_Post) {
                return [
                    '@context' => 'https://schema.org',
                    '@graph' => $graph,
                ];
            }

            $permalink = get_permalink($post);
            $webpage = [
                '@type' => 'WebPage',
                '@id' => $permalink . '#webpage',
                'url' => $permalink,
                'name' => get_the_title($post),
                'isPartOf' => ['@id' => home_url('/#website')],
                'about' => ['@id' => home_url('/#organization')],
                'inLanguage' => get_bloginfo('language') ?: 'en-US',
                'datePublished' => get_the_date('c', $post),
                'dateModified' => get_the_modified_date('c', $post),
            ];
            $desc = get_post_meta($post->ID, 'pylon_description', true) ?: (get_the_excerpt($post) ?: '');
            if ($desc) {
                $webpage['description'] = wp_strip_all_tags($desc);
            }

            $breadcrumbs = $this->build_breadcrumbs();
            if ($breadcrumbs) {
                $webpage['breadcrumb'] = ['@id' => $permalink . '#breadcrumb'];
            }

            $graph[] = $webpage;

            $schema_type = get_post_meta($post->ID, 'pylon_schema_type', true) ?: 'Article';

            if (in_array($schema_type, $this->types, true)) {
                $entity = $this->build_entity($post, $schema_type);
                if (in_array($schema_type, ['Article', 'BlogPosting', 'NewsArticle'], true)) {
                    $entity['mainEntityOfPage'] = ['@id' => $permalink . '#webpage'];
                    $entity['isPartOf'] = ['@id' => home_url('/#website')];
                }
                $graph[] = $entity;
            }

            $rules_has_faq = class_exists('\Pylon\Core\Modules\Rules\RulesEngine') && \Pylon\Core\Modules\Rules\RulesEngine::has_output('faq');
            if (empty(get_post_meta($post->ID, 'pylon_aeo_answer', true))
                && !$rules_has_faq) {
                if ($faq = $this->extract_faqs($post)) {
                    $graph[] = $faq;
                }
            }

            if ($breadcrumbs) {
                $graph[] = $breadcrumbs;
            }
        }

        if (empty($graph)) {
            return null;
        }

        return [
            '@context' => 'https://schema.org',
            '@graph' => $graph,
        ];
    }

    private function build_entity(\WP_Post $post, string $type): array {
        // Dispatch to a type-specific builder so each schema type is genuinely
        // valid for its type, not an Article shape relabeled. Falls back to a
        // well-formed Article for creative-content types.
        switch ($type) {
            case 'Product':
                return $this->build_product_entity($post);
            case 'LocalBusiness':
                return $this->build_local_business_entity($post);
            case 'Person':
                return $this->build_person_entity($post);
            case 'Event':
                return $this->build_event_entity($post);
            case 'Recipe':
                return $this->build_recipe_entity($post);
            case 'VideoObject':
                return $this->build_video_entity($post);
            case 'Article':
            case 'BlogPosting':
            case 'NewsArticle':
            default:
                return $this->build_article_entity($post, $type);
        }
    }

    /**
     * Article-family schema: Article, BlogPosting, NewsArticle.
     */
    private function build_article_entity(\WP_Post $post, string $type): array {
        $entity = [
            '@type' => $type,
            '@id' => get_permalink($post) . '#' . strtolower($type),
            'headline' => get_the_title($post),
            'description' => get_the_excerpt($post) ?: wp_trim_words(get_the_content($post), 20),
            'datePublished' => get_the_date('c', $post),
            'dateModified' => get_the_modified_date('c', $post),
            'author' => [
                '@type' => 'Person',
                '@id' => get_author_posts_url($post->post_author) . '#person',
                'name' => get_the_author_meta('display_name', $post->post_author),
            ],
            'publisher' => [
                '@id' => home_url('/#organization'),
            ],
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id' => get_permalink($post),
            ],
        ];

        $this->attach_featured_image($post, $entity);
        $this->attach_speakable($entity);

        return $entity;
    }

    /**
     * SpeakableSpecification helps voice assistants and AI extract spoken passages.
     */
    private function attach_speakable(array &$entity): void {
        if (!get_option('pylon_schema_speakable', '1')) {
            return;
        }
        $selectors = apply_filters('pylon/schema/speakable_selectors', [
            'h1',
            '.entry-title',
            '.entry-content > p',
            'article .entry-content p',
            '.post-content > p',
        ]);
        if (empty($selectors) || !is_array($selectors)) {
            return;
        }
        $entity['speakable'] = [
            '@type' => 'SpeakableSpecification',
            'cssSelector' => array_values(array_filter(array_map('strval', $selectors))),
        ];
    }

    /**
     * Product schema. Pulls WooCommerce fields when available so it renders
     * valid offers/inventory; otherwise emits a minimal Product.
     */
    private function build_product_entity(\WP_Post $post): array {
        $entity = [
            '@type' => 'Product',
            '@id' => get_permalink($post) . '#product',
            'name' => get_the_title($post),
            'description' => get_the_excerpt($post) ?: wp_trim_words(get_the_content($post), 25),
            'url' => get_permalink($post),
            'sku' => get_post_meta($post->ID, '_sku', true) ?: null,
            'brand' => get_post_meta($post->ID, 'pylon_woo_brand', true)
                ? ['@type' => 'Brand', 'name' => get_post_meta($post->ID, 'pylon_woo_brand', true)]
                : null,
            'mpn' => get_post_meta($post->ID, 'pylon_woo_mpn', true) ?: null,
            'gtin' => get_post_meta($post->ID, 'pylon_woo_gtin', true) ?: null,
        ];

        $price = get_post_meta($post->ID, '_price', true);
        if ($price) {
            $availability = get_post_meta($post->ID, '_stock_status', true) === 'outofstock'
                ? 'https://schema.org/OutOfStock'
                : 'https://schema.org/InStock';
            $entity['offers'] = [
                '@type' => 'Offer',
                'url' => get_permalink($post),
                'price' => (string) $price,
                'priceCurrency' => get_option('woocommerce_currency') ?: 'USD',
                'availability' => $availability,
            ];
        }

        $this->attach_featured_image($post, $entity, 'image');

        return $this->drop_nulls($entity);
    }

    /**
     * LocalBusiness schema. Uses Pylon's local SEO fields when present.
     */
    private function build_local_business_entity(\WP_Post $post): array {
        $name = get_option('pylon_local_business_name', '');
        $type = 'LocalBusiness';
        if (class_exists('\Pylon\Core\Modules\LocalSeo\LocalSEO')) {
            $type = \Pylon\Core\Modules\LocalSeo\LocalSEO::schema_type();
        }
        $entity = [
            '@type' => $type,
            '@id' => get_permalink($post) . '#localbusiness',
            'name' => $name ?: get_the_title($post),
            'url' => get_permalink($post),
            'description' => get_the_excerpt($post) ?: wp_trim_words(get_the_content($post), 25),
        ];

        $street = get_option('pylon_local_street', '');
        $city   = get_option('pylon_local_city', '');
        if ($street || $city) {
            $entity['address'] = array_filter([
                '@type' => 'PostalAddress',
                'streetAddress' => $street,
                'addressLocality' => $city,
                'addressRegion' => get_option('pylon_local_state', ''),
                'postalCode' => get_option('pylon_local_zip', ''),
            ]);
        }

        $phone = get_option('pylon_local_phone', '');
        if ($phone) {
            $entity['telephone'] = $phone;
        }

        $lat = get_option('pylon_local_lat', '');
        $lng = get_option('pylon_local_lng', '');
        if ($lat && $lng) {
            $entity['geo'] = [
                '@type' => 'GeoCoordinates',
                'latitude' => $lat,
                'longitude' => $lng,
            ];
        }

        $hours = get_option('pylon_local_hours', []);
        if (!empty($hours)) {
            $day_map = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];
            foreach ($hours as $i => $h) {
                if (!empty($h['open']) && !empty($h['close'])) {
                    $entity['openingHoursSpecification'][] = [
                        '@type' => 'OpeningHoursSpecification',
                        'dayOfWeek' => $day_map[$i],
                        'opens' => $h['open'],
                        'closes' => $h['close'],
                    ];
                }
            }
        }

        $this->attach_featured_image($post, $entity, 'image');

        return $this->drop_nulls($entity);
    }

    /**
     * Person schema (e.g. author/about pages).
     */
    private function build_person_entity(\WP_Post $post): array {
        $entity = [
            '@type' => 'Person',
            '@id' => get_permalink($post) . '#person',
            'name' => get_the_title($post),
            'description' => get_the_excerpt($post),
            'url' => get_permalink($post),
        ];

        $this->attach_featured_image($post, $entity, 'image');

        return $this->drop_nulls($entity);
    }

    /**
     * Event schema. Reads custom fields prefixed with pylon_event_ when present.
     */
    private function build_event_entity(\WP_Post $post): array {
        $start = get_post_meta($post->ID, 'pylon_event_start', true);
        $end   = get_post_meta($post->ID, 'pylon_event_end', true);
        $venue = get_post_meta($post->ID, 'pylon_event_venue', true);

        $entity = [
            '@type' => 'Event',
            '@id' => get_permalink($post) . '#event',
            'name' => get_the_title($post),
            'description' => get_the_excerpt($post),
            'startDate' => $start ?: null,
            'endDate' => $end ?: null,
            'eventStatus' => 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
        ];

        if ($venue) {
            $entity['location'] = [
                '@type' => 'Place',
                'name' => $venue,
                'address' => $venue,
            ];
        }

        $this->attach_featured_image($post, $entity, 'image');

        return $this->drop_nulls($entity);
    }

    /**
     * Recipe schema derived from content structure.
     */
    private function build_recipe_entity(\WP_Post $post): array {
        $content = get_post_field('post_content', $post);

        // Pull instructions from H2/H3 + paragraph pairs.
        preg_match_all('/<h[23][^>]*>(.+?)<\/h[23]>\s*<(?:p|div)[^>]*>(.+?)<\/(?:p|div)>/is', $content, $matches, PREG_SET_ORDER);
        $instructions = [];
        $position = 1;
        foreach ($matches as $m) {
            $instructions[] = [
                '@type' => 'HowToStep',
                'position' => $position++,
                'text' => wp_strip_all_tags($m[2]),
            ];
        }

        $entity = [
            '@type' => 'Recipe',
            '@id' => get_permalink($post) . '#recipe',
            'name' => get_the_title($post),
            'description' => get_the_excerpt($post),
            'datePublished' => get_the_date('c', $post),
            'author' => [
                '@type' => 'Person',
                'name' => get_the_author_meta('display_name', $post->post_author),
            ],
            'recipeInstructions' => $instructions
                ?: wp_strip_all_tags(wp_trim_words($content, 30)),
        ];

        $this->attach_featured_image($post, $entity, 'image');

        return $entity;
    }

    /**
     * VideoObject schema. Requires a video URL custom field.
     */
    private function build_video_entity(\WP_Post $post): array {
        $content_url = get_post_meta($post->ID, 'pylon_video_url', true);
        $duration    = get_post_meta($post->ID, 'pylon_video_duration', true);

        $entity = [
            '@type' => 'VideoObject',
            '@id' => get_permalink($post) . '#video',
            'name' => get_the_title($post),
            'description' => get_the_excerpt($post),
            'uploadDate' => get_the_date('c', $post),
            'contentUrl' => $content_url ?: null,
            'duration' => $duration ?: null,
        ];

        $this->attach_featured_image($post, $entity, 'thumbnailUrl', true);

        return $this->drop_nulls($entity);
    }

    /**
     * Attach the post's featured image to an entity as either an ImageObject
     * (default) or a plain URL string ($as_string = true).
     */
    private function attach_featured_image(\WP_Post $post, array &$entity, string $key = 'image', bool $as_string = false): void {
        if (!has_post_thumbnail($post)) return;

        $image = wp_get_attachment_image_src(get_post_thumbnail_id($post), 'full');
        if (!$image) return;

        $entity[$key] = $as_string ? $image[0] : [
            '@type' => 'ImageObject',
            'url' => $image[0],
            'width' => $image[1],
            'height' => $image[2],
        ];
    }

    /**
     * Remove null-valued keys so JSON-LD stays valid (schema.org dislikes nulls).
     */
    private function drop_nulls(array $data): array {
        return array_filter($data, fn ($v) => null !== $v);
    }

    private function extract_faqs(\WP_Post $post): ?array {
        if (!get_option('pylon_schema_auto_faq', '1')) return null;

        $content = get_post_field('post_content', $post);

        // Match an H2/H3 heading followed by a paragraph, treating the heading
        // as a Question and the paragraph as its Answer. The heading must read
        // like a question (ends in "?") OR the page already declares FAQ schema
        // — either way we require a heading + body pair so we never emit junk.
        $pattern = '/<h[23][^>]*>(.+?)<\/h[23]>\s*<(?:p|div)[^>]*>(.+?)<\/(?:p|div)>/is';

        if (!preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) return null;

        $faqs = [];
        foreach ($matches as $m) {
            $question = trim(wp_strip_all_tags($m[1]));
            $answer   = trim(wp_strip_all_tags($m[2]));

            // Only treat heading + paragraph pairs where the heading is a question.
            if ($question === '' || $answer === '') continue;
            if (substr($question, -1) !== '?') continue;

            $faqs[] = [
                '@type' => 'Question',
                'name' => $question,
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $answer,
                ],
            ];
        }

        if (count($faqs) < 2) return null;

        return [
            '@type' => 'FAQPage',
            '@id' => get_permalink($post) . '#faq',
            'mainEntity' => $faqs,
        ];
    }

    private function build_breadcrumbs(): ?array {
        if (!is_singular() && !is_page()) return null;

        $items = [];
        $items[] = [
            '@type' => 'ListItem',
            'position' => 1,
            'name' => __('Home', 'pylon-seo'),
            'item' => home_url(),
        ];

        if (is_singular('post')) {
            $categories = get_the_category();
            if (!empty($categories)) {
                $items[] = [
                    '@type' => 'ListItem',
                    'position' => 2,
                    'name' => $categories[0]->name,
                    'item' => get_category_link($categories[0]->term_id),
                ];
            }
        }

        $items[] = [
            '@type' => 'ListItem',
            'position' => count($items) + 1,
            'name' => get_the_title(),
            'item' => get_permalink(),
        ];

        return [
            '@type' => 'BreadcrumbList',
            '@id' => get_permalink() . '#breadcrumb',
            'itemListElement' => $items,
        ];
    }

    private function build_website_schema(): array {
        return [
            '@type' => 'WebSite',
            '@id' => home_url('/#website'),
            'url' => home_url(),
            'name' => get_bloginfo('name'),
            'description' => get_bloginfo('description'),
            'publisher' => ['@id' => home_url('/#organization')],
            'inLanguage' => get_bloginfo('language') ?: 'en-US',
            'potentialAction' => [
                [
                    '@type' => 'SearchAction',
                    'target' => [
                        '@type' => 'EntryPoint',
                        'urlTemplate' => home_url('/?s={search_term_string}'),
                    ],
                    'query-input' => 'required name=search_term_string',
                ],
            ],
        ];
    }

    private function get_logo_url(): ?string {
        $custom_logo_id = get_theme_mod('custom_logo');
        if ($custom_logo_id) {
            return wp_get_attachment_url($custom_logo_id);
        }
        return null;
    }

    public function render_metabox(): void {
        $post_id = get_the_ID();
        $current = get_post_meta($post_id, 'pylon_schema_type', true);
        wp_nonce_field('pylon_schema', 'pylon_schema_nonce');
        ?>
        <div class="pylon-form-group">
            <label for="pylon_schema_type"><?php esc_html_e('Schema Type', 'pylon-seo'); ?></label>
            <select id="pylon_schema_type" name="pylon_schema_type" class="pylon-select" onchange="pylonToggleSchemaFields(this.value)">
                <option value=""><?php esc_html_e('Auto-detect', 'pylon-seo'); ?></option>
                <?php foreach ($this->types as $type): ?>
                    <option value="<?php echo esc_attr($type); ?>" <?php selected($current, $type); ?>><?php echo esc_html($type); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div id="pylon-schema-product" class="pylon-schema-type-fields" style="display:<?php echo $current === 'Product' ? 'block' : 'none'; ?>;margin-top:10px;">
            <div style="font-size:11px;font-weight:600;color:var(--pylon-gray-500);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;"><?php esc_html_e('Product Details', 'pylon-seo'); ?></div>
            <div class="pylon-form-group">
                <label for="pylon_woo_brand"><?php esc_html_e('Brand', 'pylon-seo'); ?></label>
                <input type="text" id="pylon_woo_brand" name="pylon_woo_brand" class="pylon-input" value="<?php echo esc_attr(get_post_meta($post_id, 'pylon_woo_brand', true)); ?>" placeholder="<?php esc_attr_e('e.g. Nike', 'pylon-seo'); ?>">
            </div>
            <div class="pylon-form-group">
                <label for="pylon_woo_mpn"><?php esc_html_e('MPN', 'pylon-seo'); ?></label>
                <input type="text" id="pylon_woo_mpn" name="pylon_woo_mpn" class="pylon-input" value="<?php echo esc_attr(get_post_meta($post_id, 'pylon_woo_mpn', true)); ?>" placeholder="<?php esc_attr_e('e.g. MPN12345', 'pylon-seo'); ?>">
            </div>
            <div class="pylon-form-group">
                <label for="pylon_woo_gtin"><?php esc_html_e('GTIN', 'pylon-seo'); ?></label>
                <input type="text" id="pylon_woo_gtin" name="pylon_woo_gtin" class="pylon-input" value="<?php echo esc_attr(get_post_meta($post_id, 'pylon_woo_gtin', true)); ?>" placeholder="<?php esc_attr_e('e.g. 12345678901234', 'pylon-seo'); ?>">
            </div>
        </div>

        <div id="pylon-schema-localbusiness" class="pylon-schema-type-fields" style="display:<?php echo $current === 'LocalBusiness' ? 'block' : 'none'; ?>;margin-top:10px;">
            <div style="padding:12px;background:var(--pylon-gray-50,#f9fafb);border:1px dashed var(--pylon-gray-300,#d1d5db);border-radius:6px;font-size:13px;color:var(--pylon-gray-500);">
                <?php esc_html_e('Business address and hours are configured globally under', 'pylon-seo'); ?> <a href="<?php echo esc_url(admin_url('admin.php?page=pylon-group-audit&tab=local-seo')); ?>" target="_blank"><?php esc_html_e('Pylon SEO → Audit → Local SEO', 'pylon-seo'); ?></a>.
            </div>
        </div>

        <div id="pylon-schema-event" class="pylon-schema-type-fields" style="display:<?php echo $current === 'Event' ? 'block' : 'none'; ?>;margin-top:10px;">
            <div style="font-size:11px;font-weight:600;color:var(--pylon-gray-500);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;"><?php esc_html_e('Event Details', 'pylon-seo'); ?></div>
            <div class="pylon-form-group">
                <label for="pylon_event_start"><?php esc_html_e('Start Date', 'pylon-seo'); ?></label>
                <input type="text" id="pylon_event_start" name="pylon_event_start" class="pylon-input" value="<?php echo esc_attr(get_post_meta($post_id, 'pylon_event_start', true)); ?>" placeholder="<?php esc_attr_e('e.g. 2025-06-15T09:00', 'pylon-seo'); ?>">
            </div>
            <div class="pylon-form-group">
                <label for="pylon_event_end"><?php esc_html_e('End Date', 'pylon-seo'); ?></label>
                <input type="text" id="pylon_event_end" name="pylon_event_end" class="pylon-input" value="<?php echo esc_attr(get_post_meta($post_id, 'pylon_event_end', true)); ?>" placeholder="<?php esc_attr_e('e.g. 2025-06-15T17:00', 'pylon-seo'); ?>">
            </div>
            <div class="pylon-form-group">
                <label for="pylon_event_venue"><?php esc_html_e('Venue', 'pylon-seo'); ?></label>
                <input type="text" id="pylon_event_venue" name="pylon_event_venue" class="pylon-input" value="<?php echo esc_attr(get_post_meta($post_id, 'pylon_event_venue', true)); ?>" placeholder="<?php esc_attr_e('e.g. Madison Square Garden', 'pylon-seo'); ?>">
            </div>
        </div>

        <div id="pylon-schema-person" class="pylon-schema-type-fields" style="display:<?php echo $current === 'Person' ? 'block' : 'none'; ?>;margin-top:10px;">
            <div style="font-size:11px;font-weight:600;color:var(--pylon-gray-500);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;"><?php esc_html_e('Person Details', 'pylon-seo'); ?></div>
            <p style="font-size:12px;color:var(--pylon-gray-500);"><?php esc_html_e('Person schema uses the post title as the person name and the excerpt as the description.', 'pylon-seo'); ?></p>
        </div>

        <div class="pylon-schema-preview" style="margin-top:12px;">
            <div style="font-size:11px;font-weight:600;color:var(--pylon-gray-500);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;"><?php esc_html_e('Generated Schema Preview', 'pylon-seo'); ?></div>
            <div style="background:var(--pylon-gray-50);border:1px solid var(--pylon-gray-200);border-radius:8px;padding:12px;font-size:12px;line-height:1.6;">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                    <span style="background:var(--pylon-primary);color:#fff;font-size:10px;font-weight:700;padding:2px 7px;border-radius:4px;">JSON-LD</span>
                    <span style="color:var(--pylon-gray-600);">@context: schema.org · @graph: 2+ entities</span>
                </div>
                <div style="display:grid;gap:6px;">
                    <div style="display:flex;align-items:center;gap:6px;padding:6px 8px;background:#fff;border-radius:6px;">
                        <span style="font-size:14px;">🏢</span>
                        <span style="font-weight:600;color:var(--pylon-gray-800);">Organization</span>
                        <span style="color:var(--pylon-gray-400);font-size:11px;">— <?php echo esc_html(get_bloginfo('name')); ?></span>
                    </div>
                    <div style="display:flex;align-items:center;gap:6px;padding:6px 8px;background:#fff;border-radius:6px;">
                        <span style="font-size:14px;">📄</span>
                        <span style="font-weight:600;color:var(--pylon-gray-800);"><?php echo esc_html($current ?: __('Auto (Article)', 'pylon-seo')); ?></span>
                        <span style="color:var(--pylon-gray-400);font-size:11px;">— <?php echo esc_html(get_the_title($post_id)); ?></span>
                    </div>
                    <div style="display:flex;align-items:center;gap:6px;padding:6px 8px;background:#fff;border-radius:6px;">
                        <span style="font-size:14px;">🍞</span>
                        <span style="font-weight:600;color:var(--pylon-gray-800);">BreadcrumbList</span>
                        <span style="color:var(--pylon-gray-400);font-size:11px;">— <?php echo esc_html__('Auto-generated', 'pylon-seo'); ?></span>
                    </div>
                    <?php if (get_option('pylon_schema_auto_faq', '1') === '1'): ?>
                    <div style="display:flex;align-items:center;gap:6px;padding:6px 8px;background:#fff;border-radius:6px;">
                        <span style="font-size:14px;">❓</span>
                        <span style="font-weight:600;color:var(--pylon-gray-800);">FAQPage</span>
                        <span style="color:var(--pylon-gray-400);font-size:11px;">— <?php echo esc_html__('Auto-extracted from H2?', 'pylon-seo'); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                <div style="margin-top:8px;font-size:11px;color:var(--pylon-gray-400);border-top:1px solid var(--pylon-gray-200);padding-top:8px;">
                    <?php esc_html_e('Schema is output in the', 'pylon-seo'); ?> <code style="font-size:10px;">&lt;head&gt;</code> <?php esc_html_e('via wp_head. Enable/disable in Settings → Schema.', 'pylon-seo'); ?>
                </div>
            </div>
        </div>
        <?php
    }
}
