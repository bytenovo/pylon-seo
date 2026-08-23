<?php
namespace Pylon\Core\Modules\WooCommerce;
defined('ABSPATH') || exit;
class WooCommerceSEO {
    public function register(): void {
        if (!class_exists('WooCommerce')) return;
        add_action('add_meta_boxes', [$this, 'add_meta_box']);
        add_action('save_post', [$this, 'save_meta_box']);
        add_filter('pylon/modules', [$this, 'add_product_schema']);
    }

    public function add_meta_box(): void {
        add_meta_box(
            'pylon_woocommerce_seo',
            __('Pylon WooCommerce SEO', 'pylon-seo'),
            [$this, 'render_meta_box'],
            'product',
            'normal',
            'high'
        );
    }

    public function render_meta_box($post): void {
        wp_nonce_field('pylon_woo_seo', 'pylon_woo_seo_nonce');

        $product = wc_get_product($post->ID);
        $price_set = $product && $product->get_price() > 0;
        $sku_set = $product && (bool) $product->get_sku();
        $brand = get_post_meta($post->ID, 'pylon_woo_brand', true);
        $gtin = get_post_meta($post->ID, 'pylon_woo_gtin', true);
        $mpn = get_post_meta($post->ID, 'pylon_woo_mpn', true);
        $has_identifier = $gtin || $mpn;
        $has_desc = !empty($post->post_content);
        $has_excerpt = !empty($post->post_excerpt);
        $gallery_ids = $product ? $product->get_gallery_image_ids() : [];
        $has_gallery = count($gallery_ids) > 0;
        $in_stock = $product && $product->is_in_stock();
        $has_thumb = has_post_thumbnail($post->ID);
        $has_category = (bool) wc_get_product_category_list($post->ID);

        $has_brand = (bool) $brand;
        $checks = compact('price_set', 'sku_set', 'has_brand', 'has_identifier', 'has_desc', 'has_excerpt', 'has_gallery', 'in_stock', 'has_thumb', 'has_category');
        $checks_pass = count(array_filter($checks));
        $checks_total = count($checks);
        $score_pct = $checks_total > 0 ? round(($checks_pass / $checks_total) * 100) : 0;

        $labels = [
            'price_set' => __('Price is set', 'pylon-seo'),
            'sku_set' => __('SKU is set', 'pylon-seo'),
            'has_brand' => __('Brand is set', 'pylon-seo'),
            'has_identifier' => __('GTIN or MPN set', 'pylon-seo'),
            'has_desc' => __('Product description', 'pylon-seo'),
            'has_excerpt' => __('Short description', 'pylon-seo'),
            'has_gallery' => __('Gallery images', 'pylon-seo'),
            'in_stock' => __('In stock', 'pylon-seo'),
            'has_thumb' => __('Featured image', 'pylon-seo'),
            'has_category' => __('Category assigned', 'pylon-seo'),
        ];

        $notes = [
            'price_set' => '',
            'sku_set' => '',
            'has_brand' => '',
            'has_identifier' => __('Helps rich results', 'pylon-seo'),
            'has_desc' => '',
            'has_excerpt' => __('Shown in catalog', 'pylon-seo'),
            'has_gallery' => '',
            'in_stock' => '',
            'has_thumb' => '',
            'has_category' => '',
        ];
        ?>
        <div class="pylon-adash">
            <div class="pylon-adash-hdr">
                <div class="pylon-adash-gauge">
                    <svg viewBox="0 0 40 40" class="pylon-adash-svg">
                        <circle cx="20" cy="20" r="17" class="pylon-adash-bg" />
                        <circle cx="20" cy="20" r="17" class="pylon-adash-fill" stroke-dasharray="<?php echo esc_attr($score_pct); ?>, 106.8" style="stroke:<?php echo esc_attr($score_pct >= 70 ? '#22c55e' : ($score_pct >= 40 ? '#f59e0b' : '#ef4444')); ?>;" />
                    </svg>
                    <span class="pylon-adash-num"><?php echo esc_html($score_pct); ?></span>
                </div>
                <div class="pylon-adash-stats">
                    <div class="pylon-adash-stat ok">
                        <span class="pylon-adash-stat-val"><?php echo esc_html($checks_pass); ?></span>
                        <span class="pylon-adash-stat-lbl"><?php esc_html_e('Passed', 'pylon-seo'); ?></span>
                    </div>
                    <div class="pylon-adash-stat no">
                        <span class="pylon-adash-stat-val"><?php echo esc_html($checks_total - $checks_pass); ?></span>
                        <span class="pylon-adash-stat-lbl"><?php esc_html_e('Needs work', 'pylon-seo'); ?></span>
                    </div>
                </div>
            </div>

            <div class="pylon-adash-cat">
                <div class="pylon-adash-cat-top">
                    <span class="pylon-adash-cat-icon">📦</span>
                    <span class="pylon-adash-cat-lbl"><?php esc_html_e('Product Data', 'pylon-seo'); ?></span>
                    <span class="pylon-adash-cat-pct"><?php echo (int)$price_set + (int)$sku_set + (int)$has_brand + (int)$has_identifier + (int)$in_stock; ?>/5</span>
                </div>
                <div class="pylon-adash-track"><div class="pylon-adash-track-fill" style="width:<?php echo ((int)$price_set + (int)$sku_set + (int)$has_brand + (int)$has_identifier + (int)$in_stock) * 20; ?>%"></div></div>
                <?php foreach (['price_set', 'sku_set', 'has_brand', 'has_identifier', 'in_stock'] as $k): ?>
                    <div class="pylon-adash-i <?php echo $checks[$k] ? 'ok' : 'no'; ?>">
                        <span class="pylon-adash-i-dot"></span>
                        <span class="pylon-adash-i-lbl"><?php echo esc_html($labels[$k]); ?></span>
                        <?php if ($notes[$k]): ?>
                            <span class="pylon-adash-i-note"><?php echo esc_html($notes[$k]); ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="pylon-adash-cat">
                <div class="pylon-adash-cat-top">
                    <span class="pylon-adash-cat-icon">🖼️</span>
                    <span class="pylon-adash-cat-lbl"><?php esc_html_e('Media', 'pylon-seo'); ?></span>
                    <span class="pylon-adash-cat-pct"><?php echo (int)$has_gallery + (int)$has_thumb; ?>/2</span>
                </div>
                <div class="pylon-adash-track"><div class="pylon-adash-track-fill" style="width:<?php echo ((int)$has_gallery + (int)$has_thumb) * 50; ?>%"></div></div>
                <?php foreach (['has_gallery', 'has_thumb'] as $k): ?>
                    <div class="pylon-adash-i <?php echo $checks[$k] ? 'ok' : 'no'; ?>">
                        <span class="pylon-adash-i-dot"></span>
                        <span class="pylon-adash-i-lbl"><?php echo esc_html($labels[$k]); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="pylon-adash-cat">
                <div class="pylon-adash-cat-top">
                    <span class="pylon-adash-cat-icon">📝</span>
                    <span class="pylon-adash-cat-lbl"><?php esc_html_e('Content', 'pylon-seo'); ?></span>
                    <span class="pylon-adash-cat-pct"><?php echo (int)$has_desc + (int)$has_excerpt + (int)$has_category; ?>/3</span>
                </div>
                <div class="pylon-adash-track"><div class="pylon-adash-track-fill" style="width:<?php echo ((int)$has_desc + (int)$has_excerpt + (int)$has_category) * 33; ?>%"></div></div>
                <?php foreach (['has_desc', 'has_excerpt', 'has_category'] as $k): ?>
                    <div class="pylon-adash-i <?php echo $checks[$k] ? 'ok' : 'no'; ?>">
                        <span class="pylon-adash-i-dot"></span>
                        <span class="pylon-adash-i-lbl"><?php echo esc_html($labels[$k]); ?></span>
                        <?php if ($notes[$k]): ?>
                            <span class="pylon-adash-i-note"><?php echo esc_html($notes[$k]); ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php
        $fields = [
            'pylon_woo_gtin' => __('GTIN (Global Trade Item Number)', 'pylon-seo'),
            'pylon_woo_mpn' => __('MPN (Manufacturer Part Number)', 'pylon-seo'),
            'pylon_woo_brand' => __('Brand', 'pylon-seo'),
            'pylon_woo_condition' => __('Condition', 'pylon-seo'),
        ];
        ?>
        <div class="pylon-row">
            <?php foreach ($fields as $key => $label): ?>
                <div class="pylon-form-group">
                    <label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
                    <input type="text" id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr(get_post_meta($post->ID, $key, true)); ?>" class="pylon-input" placeholder="<?php echo esc_attr($label); ?>">
                </div>
            <?php endforeach; ?>
        </div>

        <div class="pylon-form-group">
            <label for="pylon_woo_rich_snippet"><?php esc_html_e('Rich Snippet Type', 'pylon-seo'); ?></label>
            <select id="pylon_woo_rich_snippet" name="pylon_woo_rich_snippet" class="pylon-select">
                <option value=""><?php esc_html_e('Default (Product)', 'pylon-seo'); ?></option>
                <option value="Product" <?php selected(get_post_meta($post->ID, 'pylon_woo_rich_snippet', true), 'Product'); ?>><?php esc_html_e('Product', 'pylon-seo'); ?></option>
                <option value="ProductGroup" <?php selected(get_post_meta($post->ID, 'pylon_woo_rich_snippet', true), 'ProductGroup'); ?>><?php esc_html_e('Product Group (Variant)', 'pylon-seo'); ?></option>
            </select>
        </div>

        <div class="pylon-woo-preview">
            <div class="pw-title"><?php echo esc_html($post->post_title); ?></div>
            <div class="pw-meta"><?php echo esc_html(get_post_meta($post->ID, 'pylon_woo_brand', true) ?: '—'); ?> | GTIN: <?php echo esc_html(get_post_meta($post->ID, 'pylon_woo_gtin', true) ?: '—'); ?></div>
            <div class="pw-price"><?php echo wp_kses_post(wc_price(get_post_meta($post->ID, '_price', true) ?: 0)); ?></div>
            <div class="pw-desc"><?php echo esc_html(get_post_meta($post->ID, 'pylon_description', true) ?: wp_trim_words($post->post_excerpt ?: $post->post_content, 15)); ?></div>
        </div>
        <?php
    }

    public function save_meta_box(int $post_id): void {
        if (!isset($_POST['pylon_woo_seo_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['pylon_woo_seo_nonce'])), 'pylon_woo_seo')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $fields = ['pylon_woo_gtin', 'pylon_woo_mpn', 'pylon_woo_brand', 'pylon_woo_condition', 'pylon_woo_rich_snippet'];
        foreach ($fields as $key) {
            if (isset($_POST[$key])) {
                update_post_meta($post_id, $key, sanitize_text_field(wp_unslash($_POST[$key])));
            }
        }
    }

    public function add_product_schema(array $modules): array {
        add_action('wp_head', [$this, 'output_product_schema'], 20);
        add_action('save_post', [$this, 'clear_product_schema_cache']);
        return $modules;
    }

    public function output_product_schema(): void {
        if (!is_singular('product')) return;
        $product_id = get_queried_object_id();

        $cached = get_transient("pylon_woo_schema_{$product_id}");
        if (is_array($cached)) {
            \Pylon\Core\JsonLd::script($cached);
            return;
        }

        $product = wc_get_product($product_id);
        if (!$product) return;

        $snippet_type = get_post_meta($product_id, 'pylon_woo_rich_snippet', true) ?: 'Product';

        if ($snippet_type === 'ProductGroup' && $product->is_type('variable')) {
            $schema = $this->build_product_group_schema($product);
        } else {
            $schema = $this->build_product_schema($product_id, $product);
        }

        if ($schema) {
            \Pylon\Core\JsonLd::script($schema);
            set_transient("pylon_woo_schema_{$product_id}", $schema, HOUR_IN_SECONDS);
        }
    }

    public function clear_product_schema_cache(int $post_id): void {
        delete_transient("pylon_woo_schema_{$post_id}");
    }

    /**
     * Standard single Product schema.
     */
    private function build_product_schema(int $product_id, \WC_Product $product): array {
        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => 'Product',
            'name'     => $product->get_name(),
            'description' => wp_strip_all_tags(
                get_post_meta($product_id, 'pylon_description', true)
                ?: $product->get_short_description()
                ?: wp_trim_words($product->get_description(), 20)
            ),
            'sku' => $product->get_sku() ?: null,
            'brand' => [
                '@type' => 'Brand',
                'name' => get_post_meta($product_id, 'pylon_woo_brand', true) ?: get_bloginfo('name'),
            ],
            'offers' => [
                '@type'         => 'Offer',
                'url'           => get_permalink($product_id),
                'priceCurrency' => get_woocommerce_currency(),
                'price'         => $product->get_price(),
                'availability'  => $product->is_in_stock()
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock',
            ],
        ];

        $rating_avg = $product->get_average_rating();
        $rating_count = $product->get_rating_count();
        if ($rating_count > 0 && $rating_avg > 0) {
            $schema['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => (string) round($rating_avg, 1),
                'reviewCount' => (string) $rating_count,
                'bestRating'  => '5',
            ];
        }

        if (has_post_thumbnail($product_id)) {
            $image = wp_get_attachment_image_url(get_post_thumbnail_id($product_id), 'full');
            if ($image) $schema['image'] = $image;
        }

        foreach (['gtin' => 'pylon_woo_gtin', 'mpn' => 'pylon_woo_mpn'] as $key => $meta) {
            $val = get_post_meta($product_id, $meta, true);
            if ($val) $schema[$key] = $val;
        }

        return array_filter($schema, fn ($v) => null !== $v && $v !== '');
    }

    /**
     * ProductGroup schema for variable products. Lists each variation as an
     * IndividualProduct with its own SKU and price.
     */
    private function build_product_group_schema(\WC_Product_Variable $product): array {
        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => 'ProductGroup',
            'name'     => $product->get_name(),
            'description' => get_post_meta($product->get_id(), 'pylon_description', true)
                ?: wp_trim_words($product->get_description(), 20),
            'productGroupID' => (string) $product->get_id(),
        ];

        $rating_avg = $product->get_average_rating();
        $rating_count = $product->get_rating_count();
        if ($rating_count > 0 && $rating_avg > 0) {
            $schema['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => (string) round($rating_avg, 1),
                'reviewCount' => (string) $rating_count,
                'bestRating'  => '5',
            ];
        }

        if (has_post_thumbnail($product->get_id())) {
            $image = wp_get_attachment_image_url(get_post_thumbnail_id($product->get_id()), 'full');
            if ($image) $schema['image'] = $image;
        }

        $variations = $product->get_available_variations();
        $has_variations = [];

        foreach ($variations as $variation) {
            $var_obj = wc_get_product($variation['variation_id']);
            if (!$var_obj) continue;

            $var_schema = [
                '@type'         => 'Product',
                'name'          => $var_obj->get_name(),
                'sku'           => $var_obj->get_sku() ?: "VAR-{$variation['variation_id']}",
                'inProductGroupWithID' => (string) $product->get_id(),
            ];

            if (!empty($variation['display_price'])) {
                $var_schema['offers'] = [
                    '@type'         => 'Offer',
                    'url'           => get_permalink($variation['variation_id']),
                    'priceCurrency' => get_woocommerce_currency(),
                    'price'         => $variation['display_price'],
                    'availability'  => ($variation['is_in_stock'] ?? false)
                        ? 'https://schema.org/InStock'
                        : 'https://schema.org/OutOfStock',
                ];
            }

            if (!empty($variation['image']['url'])) {
                $var_schema['image'] = $variation['image']['url'];
            }

            $has_variations[] = $var_schema;
        }

        if (!empty($has_variations)) {
            $schema['hasVariant'] = $has_variations;
        }

        return array_filter($schema, fn ($v) => null !== $v && $v !== '');
    }
}