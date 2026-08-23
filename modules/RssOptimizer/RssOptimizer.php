<?php
namespace Pylon\Core\Modules\RssOptimizer;
defined('ABSPATH') || exit;
class RssOptimizer {
    public function register(): void {
        add_filter('the_content_feed', [$this, 'before_content'], 10);
        add_filter('the_content_feed', [$this, 'after_content'], 20);
        add_action('admin_init', [$this, 'register_settings']);
        add_filter('pylon_settings_sections', [$this, 'register_section']);
    }

    public function register_settings(): void {
        register_setting('pylon_settings', 'pylon_rss_before_content', ['sanitize_callback' => [$this, 'sanitize_html']]);
        register_setting('pylon_settings', 'pylon_rss_after_content', ['sanitize_callback' => [$this, 'sanitize_html']]);
        register_setting('pylon_settings', 'pylon_rss_enabled', ['sanitize_callback' => 'absint']);
    }

    public function sanitize_html(string $value): string {
        return wp_kses_post($value);
    }

    public function before_content(string $content): string {
        if (!$this->is_enabled()) return $content;
        $before = get_option('pylon_rss_before_content', '');
        return trim($before) !== '' ? $before . "\n" . $content : $content;
    }

    public function after_content(string $content): string {
        if (!$this->is_enabled()) return $content;
        $after = get_option('pylon_rss_after_content', '');
        return trim($after) !== '' ? $content . "\n" . $after : $content;
    }

    private function is_enabled(): bool {
        return get_option('pylon_rss_enabled', '1') === '1';
    }

    public function register_section(array $sections): array {
        $sections['rss'] = [
            'icon'   => '📡',
            'title'  => __('RSS Optimization', 'pylon-seo'),
            'desc'   => __('Add custom content before and after each post in your RSS feed, and control how feed readers see your posts.', 'pylon-seo'),
            'render' => function () {
                ?>
                <div class="pylon-settings-field">
                    <label class="pylon-settings-field-label" for="pylon_rss_enabled"><?php esc_html_e('Enable RSS Enhancements', 'pylon-seo'); ?></label>
                    <div class="pylon-settings-field-input-wrap">
                        <input type="checkbox" name="pylon_rss_enabled" id="pylon_rss_enabled" value="1" <?php checked(get_option('pylon_rss_enabled', '1'), '1'); ?>>
                        <span class="pylon-help"><?php esc_html_e('Applies the content blocks below to every feed item.', 'pylon-seo'); ?></span>
                    </div>
                </div>
                <div class="pylon-settings-field">
                    <label class="pylon-settings-field-label" for="pylon_rss_before_content"><?php esc_html_e('Before Content', 'pylon-seo'); ?></label>
                    <div class="pylon-settings-field-input-wrap">
                        <textarea name="pylon_rss_before_content" id="pylon_rss_before_content" rows="4" class="pylon-input" style="width:100%;max-width:640px;font-family:Consolas,Menlo,monospace;font-size:12px;"><?php echo esc_textarea(get_option('pylon_rss_before_content', '')); ?></textarea>
                        <span class="pylon-help"><?php esc_html_e('HTML prepended to each feed item. Tip: use it to add a copyright line or link back to your site.', 'pylon-seo'); ?></span>
                    </div>
                </div>
                <div class="pylon-settings-field">
                    <label class="pylon-settings-field-label" for="pylon_rss_after_content"><?php esc_html_e('After Content', 'pylon-seo'); ?></label>
                    <div class="pylon-settings-field-input-wrap">
                        <textarea name="pylon_rss_after_content" id="pylon_rss_after_content" rows="4" class="pylon-input" style="width:100%;max-width:640px;font-family:Consolas,Menlo,monospace;font-size:12px;"><?php echo esc_textarea(get_option('pylon_rss_after_content', '')); ?></textarea>
                        <span class="pylon-help"><?php esc_html_e('HTML appended to each feed item.', 'pylon-seo'); ?></span>
                    </div>
                </div>
                <?php
            },
        ];
        return $sections;
    }
}
