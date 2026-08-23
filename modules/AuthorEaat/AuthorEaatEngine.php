<?php
namespace Pylon\Core\Modules\AuthorEaat;
defined('ABSPATH') || exit;
class AuthorEaatEngine {
    public function register(): void {
        if (!get_option('pylon_author_eaat_enabled', '1')) return;

        add_action('show_user_profile', [$this, 'render_profile_fields']);
        add_action('edit_user_profile', [$this, 'render_profile_fields']);
        add_action('personal_options_update', [$this, 'save_profile_fields']);
        add_action('edit_user_profile_update', [$this, 'save_profile_fields']);
        add_filter('user_contactmethods', [$this, 'add_contact_methods']);
        add_action('wp_head', [$this, 'output_author_schema'], 5);
        add_action('save_post', [$this, 'clear_author_cache']);
        add_action('profile_update', [$this, 'clear_author_cache']);
    }

    public function add_contact_methods(array $methods): array {
        $methods['pylon_job_title'] = __('Job Title', 'pylon-seo');
        $methods['pylon_credentials'] = __('Credentials (e.g., PhD, MD)', 'pylon-seo');
        $methods['pylon_knows_about'] = __('Topics of Expertise (comma separated)', 'pylon-seo');
        return $methods;
    }

    public function render_profile_fields(\WP_User $user): void {
        if (!current_user_can('edit_user', $user->ID)) return;
        ?>
        <?php wp_nonce_field('pylon_author_profile', 'pylon_author_profile_nonce'); ?>
        <h2><?php esc_html_e('Pylon E-E-A-T Author Profile', 'pylon-seo'); ?></h2>
        <table class="form-table">
            <tr>
                <th><label for="pylon_author_photo"><?php esc_html_e('Author Photo URL', 'pylon-seo'); ?></label></th>
                <td>
                    <input type="url" name="pylon_author_photo" id="pylon_author_photo" value="<?php echo esc_url(get_user_meta($user->ID, 'pylon_author_photo', true)); ?>" class="regular-text pylon-input">
                    <p class="pylon-help"><?php esc_html_e('URL to a professional author photo/headshot.', 'pylon-seo'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="pylon_author_bio_short"><?php esc_html_e('Short Bio', 'pylon-seo'); ?></label></th>
                <td>
                    <textarea name="pylon_author_bio_short" id="pylon_author_bio_short" rows="3" class="large-text pylon-textarea"><?php echo esc_textarea(get_user_meta($user->ID, 'pylon_author_bio_short', true)); ?></textarea>
                    <p class="pylon-help"><?php esc_html_e('1-2 sentence professional bio for schema markup.', 'pylon-seo'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="pylon_same_as"><?php esc_html_e('Social Profiles (SameAs)', 'pylon-seo'); ?></label></th>
                <td>
                    <textarea name="pylon_same_as" id="pylon_same_as" rows="3" class="large-text pylon-textarea" placeholder="https://linkedin.com/in/...&#10;https://twitter.com/...&#10;https://github.com/..."><?php echo esc_textarea(get_user_meta($user->ID, 'pylon_same_as', true)); ?></textarea>
                    <p class="pylon-help"><?php esc_html_e('One URL per line. Used for schema.org sameAs property.', 'pylon-seo'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function save_profile_fields(int $user_id): void {
        if (!current_user_can('edit_user', $user_id)) return;
        if (!isset($_POST['pylon_author_profile_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['pylon_author_profile_nonce'])), 'pylon_author_profile')) return;

        $fields = [
            'pylon_author_photo',
            'pylon_author_bio_short',
            'pylon_same_as',
        ];

        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                update_user_meta($user_id, $field, sanitize_text_field(wp_unslash($_POST[$field])));
            }
        }
    }

    public function output_author_schema(): void {
        if (!is_singular()) return;

        $post = get_queried_object();
        if (!isset($post->post_author)) return;

        $author_id = $post->post_author;
        $cache_key = 'pylon_author_schema_' . $author_id;
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            \Pylon\Core\JsonLd::script($cached);
            return;
        }

        $author_data = $this->get_author_schema_data($author_id);
        if (!$author_data) return;

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Person',
            '@id' => get_author_posts_url($author_id) . '#person',
            'name' => $author_data['display_name'],
            'url' => get_author_posts_url($author_id),
        ];

        if ($author_data['job_title']) {
            $schema['jobTitle'] = $author_data['job_title'];
        }

        if ($author_data['credentials']) {
            $schema['honorificSuffix'] = $author_data['credentials'];
        }

        if ($author_data['photo']) {
            $schema['image'] = $author_data['photo'];
        }

        if ($author_data['description']) {
            $schema['description'] = $author_data['description'];
        }

        if ($author_data['knows_about']) {
            $topics = array_map('trim', explode(',', $author_data['knows_about']));
            $schema['knowsAbout'] = $topics;
        }

        if ($author_data['same_as']) {
            $urls = array_filter(array_map('trim', explode("\n", $author_data['same_as'])));
            if (!empty($urls)) {
                $schema['sameAs'] = array_values($urls);
            }
        }

        \Pylon\Core\JsonLd::script($schema);
        set_transient($cache_key, $schema, HOUR_IN_SECONDS);
    }

    public function clear_author_cache($id): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (doing_action('save_post') && !current_user_can('edit_post', $id)) return;
        $user_id = doing_action('save_post') ? (int) get_post_field('post_author', $id) : (int) $id;
        if ($user_id) {
            delete_transient('pylon_author_schema_' . $user_id);
        }
    }

    private function get_author_schema_data(int $author_id): ?array {
        $user = get_userdata($author_id);
        if (!$user) return null;

        return [
            'display_name' => $user->display_name,
            'job_title' => get_user_meta($author_id, 'pylon_job_title', true),
            'credentials' => get_user_meta($author_id, 'pylon_credentials', true),
            'photo' => get_user_meta($author_id, 'pylon_author_photo', true),
            'description' => get_user_meta($author_id, 'pylon_author_bio_short', true) ?: get_user_meta($author_id, 'description', true),
            'knows_about' => get_user_meta($author_id, 'pylon_knows_about', true),
            'same_as' => get_user_meta($author_id, 'pylon_same_as', true),
        ];
    }
}
