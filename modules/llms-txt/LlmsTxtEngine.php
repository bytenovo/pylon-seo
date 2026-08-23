<?php
namespace Pylon\Core\Modules\LlmsTxt;
defined('ABSPATH') || exit;
/**
 * Serves a machine-readable /llms.txt and /llms-full.txt for AI engines.
 */
class LlmsTxtEngine {

    public function register(): void {
        add_action('init', [$this, 'add_rewrites']);
        add_filter('query_vars', [$this, 'register_query_vars']);
        add_action('template_redirect', [$this, 'maybe_serve']);
        add_action('admin_init', [$this, 'register_settings']);
        add_filter('pylon_settings_sections', [$this, 'register_settings_section']);
        add_action('pylon_daily_maintenance', [$this, 'regenerate_cache']);
        add_action('save_post', [$this, 'invalidate_cache']);
        add_action('delete_post', [$this, 'invalidate_cache']);
        add_action('created_term', [$this, 'invalidate_cache_void']);
        add_action('edited_term', [$this, 'invalidate_cache_void']);
        add_action('delete_term', [$this, 'invalidate_cache_void']);
    }

    public function add_rewrites(): void {
        add_rewrite_rule('^llms\.txt/?$', 'index.php?pylon_llmstxt=1', 'top');
        add_rewrite_rule('^llms-full\.txt/?$', 'index.php?pylon_llmstxt=full', 'top');
        if (get_option('pylon_llmstxt_rules_ver') !== PYLON_VERSION) {
            flush_rewrite_rules();
            update_option('pylon_llmstxt_rules_ver', PYLON_VERSION, false);
        }
    }

    public function register_query_vars(array $vars): array {
        $vars[] = 'pylon_llmstxt';
        return $vars;
    }

    public function register_settings(): void {
        $map = [
            'pylon_llmstxt_enabled'       => 'absint',
            'pylon_llmstxt_summary'       => 'sanitize_text_field',
            'pylon_llmstxt_intro'         => 'sanitize_textarea_field',
            'pylon_llmstxt_post_types'    => [$this, 'sanitize_post_types'],
            'pylon_llmstxt_include_posts' => 'absint',
            'pylon_llmstxt_include_pages' => 'absint',
            'pylon_llmstxt_include_cats'  => 'absint',
            'pylon_llmstxt_max'           => 'absint',
            'pylon_llmstxt_extra'         => 'sanitize_textarea_field',
        ];
        foreach ($map as $option => $callback) {
            register_setting('pylon_settings', $option, ['sanitize_callback' => $callback]);
        }
    }

    public function sanitize_post_types($value): string {
        $raw = is_string($value) ? $value : '';
        $parts = array_filter(array_map(static function ($p) {
            return sanitize_key(trim($p));
        }, explode(',', $raw)));
        $valid = array_values(array_filter($parts, static function ($slug) {
            return post_type_exists($slug);
        }));
        return implode(', ', $valid);
    }

    public function maybe_serve(): void {
        $flag = get_query_var('pylon_llmstxt');
        if ($flag === '') {
            return;
        }
        if (!get_option('pylon_llmstxt_enabled', '1')) {
            $this->die_not_found();
        }
        $full = $flag === 'full';
        $content = $full ? $this->get_full_content() : $this->get_content();
        if ($content === '') {
            $this->die_not_found();
        }
        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- llms.txt plain-text output.
        exit;
    }

    private function die_not_found(): void {
        status_header(404);
        nocache_headers();
        wp_die(esc_html__('Not found', 'pylon-seo'), '', ['response' => 404]);
    }

    public function get_content(bool $refresh = false): string {
        $cache_key = 'pylon_llmstxt_content';
        $cached = get_transient($cache_key);
        if (!$refresh && $cached !== false) {
            return (string) $cached;
        }
        $text = $this->build_content(false);
        set_transient($cache_key, $text, DAY_IN_SECONDS);
        return $text;
    }

    public function get_full_content(bool $refresh = false): string {
        $cache_key = 'pylon_llmsfulltxt_content';
        $cached = get_transient($cache_key);
        if (!$refresh && $cached !== false) {
            return (string) $cached;
        }
        $text = $this->build_content(true);
        set_transient($cache_key, $text, DAY_IN_SECONDS);
        return $text;
    }

    /**
     * Resolve which public post types to list.
     *
     * @return string[]
     */
    private function resolve_post_types(bool $full): array {
        $custom = trim((string) get_option('pylon_llmstxt_post_types', ''));
        if ($custom !== '') {
            $types = array_filter(array_map('trim', explode(',', $custom)));
            $types = array_values(array_filter($types, 'post_type_exists'));
            if (!empty($types)) {
                return $types;
            }
        }

        $post_types = [];
        if (get_option('pylon_llmstxt_include_posts', '1')) {
            $post_types[] = 'post';
        }
        if ($full || get_option('pylon_llmstxt_include_pages', '1')) {
            $post_types[] = 'page';
        }
        return apply_filters('pylon/llmstxt/post_types', $post_types, $full);
    }

    private function build_content(bool $full): string {
        $site = get_bloginfo('name');
        $summary = trim((string) get_option('pylon_llmstxt_summary', ''));
        if ($summary === '') {
            $summary = trim((string) get_bloginfo('description'));
        }
        $intro = trim((string) get_option('pylon_llmstxt_intro', ''));
        $extra = trim((string) get_option('pylon_llmstxt_extra', ''));

        $lines = ['# ' . $site];
        if ($summary !== '') {
            $lines[] = '';
            $lines[] = '> ' . $summary;
        }
        if ($intro !== '') {
            $lines[] = '';
            foreach (preg_split("/\r\n|\n|\r/", $intro) as $intro_line) {
                $lines[] = $intro_line;
            }
        }
        $lines[] = '';
        $lines[] = '## Optional';
        $lines[] = '';
        $lines[] = '- [Full content index](' . home_url('/llms-full.txt') . '): Extended link list for AI crawlers';
        $lines[] = '';

        $post_types = $this->resolve_post_types($full);
        if (empty($post_types)) {
            if ($extra !== '') {
                $lines[] = $extra;
            }
            return implode("\n", $lines);
        }

        $max = min(500, max(10, (int) get_option('pylon_llmstxt_max', 50)));
        if ($full) {
            $max = min(500, max($max, 100));
        }

        $posts = get_posts([
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => $max,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ]);

        $groups = [];
        foreach ($posts as $post_id) {
            $type = get_post_type($post_id);
            $groups[$type][] = $post_id;
        }

        $labels = [
            'post' => __('Blog', 'pylon-seo'),
            'page' => __('Pages', 'pylon-seo'),
            'product' => __('Products', 'pylon-seo'),
        ];

        foreach ($groups as $type => $ids) {
            $pto = get_post_type_object($type);
            $heading = $labels[$type] ?? ($pto->labels->name ?? ucfirst($type));
            $lines[] = '## ' . $heading;
            $lines[] = '';
            foreach ($ids as $post_id) {
                $desc = get_post_meta($post_id, 'pylon_description', true);
                if (!$desc) {
                    $desc = get_the_excerpt($post_id);
                }
                $desc = $desc ? ' — ' . $this->clean_title(wp_trim_words(wp_strip_all_tags((string) $desc), 18)) : '';
                $lines[] = '- [' . $this->clean_title(get_the_title($post_id)) . '](' . get_permalink($post_id) . ')' . $desc;
            }
            $lines[] = '';
        }

        if (get_option('pylon_llmstxt_include_cats', '0')) {
            $cats = get_terms([
                'taxonomy'   => 'category',
                'hide_empty' => true,
                'number'     => 40,
            ]);
            if (!is_wp_error($cats) && !empty($cats)) {
                $lines[] = '## ' . __('Topics', 'pylon-seo');
                $lines[] = '';
                foreach ($cats as $cat) {
                    $lines[] = '- [' . $this->clean_title($cat->name) . '](' . get_term_link($cat) . ')';
                }
                $lines[] = '';
            }
        }

        if ($extra !== '') {
            $lines[] = '## ' . __('Notes', 'pylon-seo');
            $lines[] = '';
            foreach (preg_split("/\r\n|\n|\r/", $extra) as $extra_line) {
                $lines[] = $extra_line;
            }
        }

        return rtrim(implode("\n", $lines)) . "\n";
    }

    private function clean_title(string $title): string {
        return trim(str_replace(['[', ']', '(', ')'], '', $title));
    }

    public function invalidate_cache_void(): void {
        $this->invalidate_cache(0);
    }

    public function invalidate_cache(int $post_id): void {
        if ($post_id && defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        delete_transient('pylon_llmstxt_content');
        delete_transient('pylon_llmsfulltxt_content');
    }

    public function regenerate_cache(): void {
        $this->get_content(true);
        $this->get_full_content(true);
    }

    public function register_settings_section(array $sections): array {
        $sections['llmstxt'] = [
            'icon'  => '🤖',
            'title' => __('LLMs.txt', 'pylon-seo'),
            'desc'  => __('Publish a machine-readable /llms.txt so AI engines (Google AI, ChatGPT, Perplexity) can discover your site.', 'pylon-seo'),
            'fields' => [
                'pylon_llmstxt_enabled' => [
                    'type'  => 'checkbox',
                    'label' => __('Enable LLMs.txt', 'pylon-seo'),
                    'desc'  => __('Serves /llms.txt and /llms-full.txt from your site root. Disable to stop serving them.', 'pylon-seo'),
                ],
                'pylon_llmstxt_summary' => [
                    'type'  => 'text',
                    'label' => __('Site Summary', 'pylon-seo'),
                    'desc'  => __('One-line description shown to AI engines. Leave blank to use the site tagline.', 'pylon-seo'),
                ],
                'pylon_llmstxt_intro' => [
                    'type'  => 'textarea',
                    'label' => __('Custom Intro', 'pylon-seo'),
                    'desc'  => __('Optional markdown/plain text inserted after the summary (policies, contact, how to cite you).', 'pylon-seo'),
                    'rows'  => 4,
                    'placeholder' => __('e.g. Preferred citation: Brand Name. Contact: hello@example.com', 'pylon-seo'),
                ],
                'pylon_llmstxt_post_types' => [
                    'type'        => 'text',
                    'label'       => __('Post Types', 'pylon-seo'),
                    'desc'        => __('Comma-separated public post type slugs (e.g. post, page, product). Leave blank to use the toggles below.', 'pylon-seo'),
                    'placeholder' => 'post, page, product',
                ],
                'pylon_llmstxt_include_posts' => [
                    'type'  => 'checkbox',
                    'label' => __('Include Blog Posts', 'pylon-seo'),
                    'desc'  => __('Used when Post Types is empty. Lists recent posts in the Blog section.', 'pylon-seo'),
                ],
                'pylon_llmstxt_include_pages' => [
                    'type'  => 'checkbox',
                    'label' => __('Include Pages', 'pylon-seo'),
                    'desc'  => __('Used when Post Types is empty. Always included in /llms-full.txt.', 'pylon-seo'),
                ],
                'pylon_llmstxt_include_cats' => [
                    'type'    => 'checkbox',
                    'label'   => __('Include Categories', 'pylon-seo'),
                    'desc'    => __('Adds a Topics section with category archive links.', 'pylon-seo'),
                    'default' => '0',
                ],
                'pylon_llmstxt_max' => [
                    'type'  => 'number',
                    'label' => __('Max Links', 'pylon-seo'),
                    'desc'  => __('Maximum number of content links listed (10–500). Full file uses at least 100.', 'pylon-seo'),
                    'attrs' => 'min="10" max="500" step="10"',
                ],
                'pylon_llmstxt_extra' => [
                    'type'  => 'textarea',
                    'label' => __('Custom Notes / Footer', 'pylon-seo'),
                    'desc'  => __('Appended under a Notes section (licensing, crawl preferences, brand facts).', 'pylon-seo'),
                    'rows'  => 4,
                ],
            ],
        ];
        return $sections;
    }
}
