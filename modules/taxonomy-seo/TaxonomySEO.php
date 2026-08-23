<?php
namespace Pylon\Core\Modules\TaxonomySeo;
defined('ABSPATH') || exit;
class TaxonomySEO {
    private array $meta_keys = [
        'pylon_term_title',
        'pylon_term_description',
        'pylon_term_noindex',
        'pylon_term_nofollow',
        'pylon_term_canonical',
        'pylon_term_og_title',
        'pylon_term_og_description',
        'pylon_term_og_image',
        'pylon_term_twitter_title',
        'pylon_term_twitter_description',
        'pylon_term_twitter_image',
        'pylon_term_schema_type',
    ];

    public function register(): void {
        add_action('init', [$this, 'register_term_meta']);
        add_action('admin_init', [$this, 'add_taxonomy_fields']);
        add_filter('pylon/taxonomy/title', [$this, 'filter_title'], 10, 2);
        add_filter('pylon/taxonomy/description', [$this, 'filter_description'], 10, 2);
    }

    public function register_term_meta(): void {
        foreach ($this->meta_keys as $key) {
            register_term_meta('', $key, [
                'show_in_rest' => true,
                'type' => 'string',
                'single' => true,
                'auth_callback' => function () { return current_user_can('manage_categories'); },
            ]);
        }
    }

    public function add_taxonomy_fields(): void {
        $taxonomies = get_taxonomies(['public' => true], 'names');
        foreach ($taxonomies as $tax) {
            add_action("{$tax}_add_form_fields", [$this, 'render_add_fields'], 10, 1);
            add_action("{$tax}_edit_form_fields", [$this, 'render_edit_fields'], 10, 2);
            add_action("created_{$tax}", [$this, 'save_fields'], 10, 2);
            add_action("edited_{$tax}", [$this, 'save_fields'], 10, 2);
        }
    }

    public function render_add_fields(string $taxonomy): void {
        ?>
        <?php wp_nonce_field('pylon_term_seo', 'pylon_term_seo_nonce'); ?>
        <div class="form-field term-pylon-wrap">
            <h3><?php esc_html_e('Pylon SEO', 'pylon-seo'); ?></h3>
        </div>
        <div class="form-field">
            <label for="pylon_term_title"><?php esc_html_e('SEO Title', 'pylon-seo'); ?></label>
            <input type="text" name="pylon_term_title" id="pylon_term_title" value="" class="regular-text">
        </div>
        <div class="form-field">
            <label for="pylon_term_description"><?php esc_html_e('Meta Description', 'pylon-seo'); ?></label>
            <textarea name="pylon_term_description" id="pylon_term_description" rows="3"></textarea>
        </div>
        <div class="form-field">
            <label for="pylon_term_noindex">
                <input type="checkbox" name="pylon_term_noindex" id="pylon_term_noindex" value="1">
                <?php esc_html_e('Noindex this archive', 'pylon-seo'); ?>
            </label>
        </div>
        <div class="form-field">
            <label for="pylon_term_nofollow">
                <input type="checkbox" name="pylon_term_nofollow" id="pylon_term_nofollow" value="1">
                <?php esc_html_e('Nofollow this archive', 'pylon-seo'); ?>
            </label>
        </div>
        <?php
    }

    public function render_edit_fields(\WP_Term $term, string $taxonomy): void {
        $vals = [];
        foreach ($this->meta_keys as $key) {
            $vals[$key] = get_term_meta($term->term_id, $key, true);
        }
        ?>
        <?php wp_nonce_field('pylon_term_seo', 'pylon_term_seo_nonce'); ?>
        <tr class="form-field term-pylon-wrap">
            <th colspan="2">
                <h3 style="margin:0;padding:12px 0 4px;"><?php esc_html_e('Pylon SEO', 'pylon-seo'); ?></h3>
            </th>
        </tr>
        <tr class="form-field">
            <th scope="row">
                <label for="pylon_term_title"><?php esc_html_e('SEO Title', 'pylon-seo'); ?></label>
            </th>
            <td>
                <input type="text" name="pylon_term_title" id="pylon_term_title" value="<?php echo esc_attr($vals['pylon_term_title']); ?>" class="regular-text">
                <p class="description"><?php esc_html_e('Override the default archive page title for this term.', 'pylon-seo'); ?></p>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row">
                <label for="pylon_term_description"><?php esc_html_e('Meta Description', 'pylon-seo'); ?></label>
            </th>
            <td>
                <textarea name="pylon_term_description" id="pylon_term_description" rows="3" class="large-text"><?php echo esc_textarea($vals['pylon_term_description']); ?></textarea>
                <p class="description"><?php esc_html_e('A concise description displayed in search results for this archive page.', 'pylon-seo'); ?></p>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row">
                <label for="pylon_term_canonical"><?php esc_html_e('Canonical URL', 'pylon-seo'); ?></label>
            </th>
            <td>
                <input type="text" name="pylon_term_canonical" id="pylon_term_canonical" value="<?php echo esc_attr($vals['pylon_term_canonical']); ?>" class="regular-text" placeholder="<?php echo esc_url(get_term_link($term)); ?>">
                <p class="description"><?php esc_html_e('Leave empty to use the default term URL.', 'pylon-seo'); ?></p>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><?php esc_html_e('Robots', 'pylon-seo'); ?></th>
            <td>
                <label style="display:block;margin-bottom:4px;">
                    <input type="checkbox" name="pylon_term_noindex" value="1" <?php checked($vals['pylon_term_noindex'], '1'); ?>>
                    <?php esc_html_e('Noindex — hide this archive from search results', 'pylon-seo'); ?>
                </label>
                <label style="display:block;">
                    <input type="checkbox" name="pylon_term_nofollow" value="1" <?php checked($vals['pylon_term_nofollow'], '1'); ?>>
                    <?php esc_html_e('Nofollow — do not follow links on this archive', 'pylon-seo'); ?>
                </label>
            </td>
        </tr>
        <?php
    }

    public function save_fields(int $term_id, int $tt_id): void {
        if (!current_user_can('manage_categories')) return;
        if (!isset($_POST['pylon_term_seo_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['pylon_term_seo_nonce'])), 'pylon_term_seo')) return;

        foreach ($this->meta_keys as $key) {
            if ($key === 'pylon_term_noindex' || $key === 'pylon_term_nofollow') {
                $val = isset($_POST[$key]) ? '1' : '';
            } else {
                $val = sanitize_text_field(wp_unslash($_POST[$key] ?? ''));
            }
            if ($val) {
                update_term_meta($term_id, $key, $val);
            } else {
                delete_term_meta($term_id, $key);
            }
        }
    }

    public function filter_title(string $title, int $term_id): string {
        $custom = get_term_meta($term_id, 'pylon_term_title', true);
        return $custom ?: $title;
    }

    public function filter_description(string $desc, int $term_id): string {
        $custom = get_term_meta($term_id, 'pylon_term_description', true);
        return $custom ?: $desc;
    }
}
