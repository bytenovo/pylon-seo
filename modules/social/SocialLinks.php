<?php
namespace Pylon\Core\Modules\Social;
defined('ABSPATH') || exit;
class SocialLinks {
    public function register(): void {
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function register_settings(): void {
        register_setting('pylon_social', 'pylon_social_links', [
            'sanitize_callback' => [$this, 'sanitize'],
        ]);
    }

    public function sanitize($input): array {
        $defaults = $this->defaults();
        $output = [];
        foreach ($defaults as $key => $default) {
            $value = $input[$key] ?? $default;
            if ($key === 'additional') {
                $output[$key] = sanitize_textarea_field($value);
            } else {
                $output[$key] = esc_url_raw($value, ['http', 'https']);
            }
        }
        return $output;
    }

    public function render_page(): void {
        if (!current_user_can('manage_options')) return;
        $links = get_option('pylon_social_links', $this->defaults());
        ?>
        <div class="wrap pylon-settings-page">
            <?php \Pylon\Core\Modules\Admin\AdminEngine::page_header(__('Social Links', 'pylon-seo'), '🌐'); ?>

            <form method="post" action="options.php">
                <?php settings_fields('pylon_social'); ?>

                <div class="pylon-card">
                    <div class="pylon-card-header">
                        <h3><?php esc_html_e('Social Media Profiles', 'pylon-seo'); ?></h3>
                    </div>
                    <div class="pylon-card-body">
                        <p class="pylon-help pylon-mb-16">
                            <?php esc_html_e('Enter the full URLs to your social media profiles. These will be used in Schema.org markup (sameAs) and Open Graph tags.', 'pylon-seo'); ?>
                        </p>
                        <?php foreach ($this->platforms() as $key => $label): ?>
                            <div class="pylon-form-group">
                                <label><?php echo esc_html($label); ?></label>
                                <input type="url" name="pylon_social_links[<?php echo esc_attr($key); ?>]"
                                    value="<?php echo esc_attr($links[$key] ?? ''); ?>"
                                    class="pylon-input pylon-max-w-400"
                                    placeholder="https://<?php echo esc_attr($key === 'twitter_url' ? 'x.com/username' : str_replace('_url', '.com/username', $key)); ?>">
                            </div>
                        <?php endforeach; ?>

                        <div class="pylon-form-group">
                            <label><?php esc_html_e('Additional URLs', 'pylon-seo'); ?></label>
                            <textarea name="pylon_social_links[additional]" class="pylon-input pylon-max-w-400" rows="4" placeholder="https://tiktok.com/@&#8230;&#10;https://snapchat.com/add/&#8230;"><?php echo esc_textarea($links['additional'] ?? ''); ?></textarea>
                            <span class="pylon-help"><?php esc_html_e('One URL per line for any other social platforms (TikTok, Snapchat, WhatsApp, Telegram, etc.)', 'pylon-seo'); ?></span>
                        </div>
                    </div>
                </div>

                <div class="pylon-card pylon-bg-white pylon-mt-20">
                    <div class="pylon-flex pylon-flex-center pylon-gap-8">
                        <button type="submit" class="pylon-btn pylon-btn-primary pylon-btn-lg">
                            <?php esc_html_e('Save Settings', 'pylon-seo'); ?>
                        </button>
                    </div>
                </div>
            </form>
        </div>
        <?php
    }

    public static function get_social_links(): array {
        $defaults = (new self)->defaults();
        $stored = get_option('pylon_social_links', []);
        return array_merge($defaults, $stored);
    }

    public static function get_same_as_urls(): array {
        $links = self::get_social_links();
        $urls = [];
        foreach (array_keys((new self)->platforms()) as $key) {
            if (!empty($links[$key])) {
                $urls[] = $links[$key];
            }
        }
        if (!empty($links['additional'])) {
            $lines = array_filter(array_map('trim', explode("\n", $links['additional'])));
            foreach ($lines as $line) {
                if (!empty($line)) {
                    $urls[] = $line;
                }
            }
        }
        return $urls;
    }

    private function platforms(): array {
        return [
            'facebook_url' => __('Facebook', 'pylon-seo'),
            'twitter_url' => __('Twitter / X', 'pylon-seo'),
            'instagram_url' => __('Instagram', 'pylon-seo'),
            'linkedin_url' => __('LinkedIn', 'pylon-seo'),
            'pinterest_url' => __('Pinterest', 'pylon-seo'),
            'youtube_url' => __('YouTube', 'pylon-seo'),
        ];
    }

    private function defaults(): array {
        $defaults = [];
        foreach ($this->platforms() as $key => $label) {
            $defaults[$key] = '';
        }
        $defaults['additional'] = '';
        return $defaults;
    }
}
