<?php
namespace Pylon\Core\Modules\Sitemap;
defined('ABSPATH') || exit;
class SitemapEngine {
    public function register(): void {
        add_action('init', [$this, 'register_rewrites'], 1);
        add_filter('query_vars', [$this, 'register_query_vars']);
        add_action('template_redirect', [$this, 'maybe_serve_from_uri'], 0);
        add_action('template_redirect', [$this, 'handle_sitemap_request'], 1);
        add_filter('wp_sitemaps_enabled', '__return_false');
        add_action('pylon_daily_maintenance', [$this, 'clear_cache']);
        add_action('save_post', [$this, 'clear_cache']);
        add_action('deleted_post', [$this, 'clear_cache']);
    }

    public function register_query_vars(array $vars): array {
        $vars[] = 'pylon_sitemap';
        $vars[] = 'pylon_sitemap_page';
        return $vars;
    }

    public function register_rewrites(): void {
        add_rewrite_rule('^sitemap\.xml$', 'index.php?pylon_sitemap=main', 'top');
        add_rewrite_rule('^sitemap-([a-z0-9_-]+)\.xml$', 'index.php?pylon_sitemap=$matches[1]', 'top');
        add_rewrite_rule('^sitemap-([a-z0-9_-]+)-([0-9]+)\.xml$', 'index.php?pylon_sitemap=$matches[1]&pylon_sitemap_page=$matches[2]', 'top');
        add_rewrite_rule('^sitemap-tax-([a-z0-9_-]+)\.xml$', 'index.php?pylon_sitemap=tax-$matches[1]', 'top');
        add_rewrite_rule('^sitemap-tax-([a-z0-9_-]+)-([0-9]+)\.xml$', 'index.php?pylon_sitemap=tax-$matches[1]&pylon_sitemap_page=$matches[2]', 'top');

        if (get_option('pylon_sitemap_rules_ver') !== '1.3.1') {
            flush_rewrite_rules(false);
            update_option('pylon_sitemap_rules_ver', '1.3.1', false);
            $this->clear_cache();
        }
    }

    public function maybe_serve_from_uri(): void {
        if (get_query_var('pylon_sitemap')) {
            return;
        }
        $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $path = (string) (wp_parse_url($uri, PHP_URL_PATH) ?? '');
        $home_path = (string) (wp_parse_url(home_url('/'), PHP_URL_PATH) ?? '/');
        $home_path = untrailingslashit($home_path);
        if ($home_path !== '' && 0 === strpos($path, $home_path)) {
            $path = substr($path, strlen($home_path)) ?: '/';
        }
        $path = '/' . ltrim($path, '/');

        if (preg_match('#^/sitemap\.xml/?$#i', $path)) {
            set_query_var('pylon_sitemap', 'main');
            return;
        }
        if (preg_match('#^/sitemap-tax-([a-z0-9_-]+)-([0-9]+)\.xml/?$#i', $path, $m)) {
            set_query_var('pylon_sitemap', 'tax-' . $m[1]);
            set_query_var('pylon_sitemap_page', $m[2]);
            return;
        }
        if (preg_match('#^/sitemap-tax-([a-z0-9_-]+)\.xml/?$#i', $path, $m)) {
            set_query_var('pylon_sitemap', 'tax-' . $m[1]);
            return;
        }
        if (preg_match('#^/sitemap-([a-z0-9_-]+)-([0-9]+)\.xml/?$#i', $path, $m)) {
            set_query_var('pylon_sitemap', $m[1]);
            set_query_var('pylon_sitemap_page', $m[2]);
            return;
        }
        if (preg_match('#^/sitemap-([a-z0-9_-]+)\.xml/?$#i', $path, $m)) {
            set_query_var('pylon_sitemap', $m[1]);
        }
    }

    public function handle_sitemap_request(): void {
        $type = get_query_var('pylon_sitemap');
        if (!$type) {
            return;
        }
        if (!get_option('pylon_sitemap_enabled', '1')) {
            status_header(404);
            exit;
        }

        $page = max(1, (int) get_query_var('pylon_sitemap_page'));

        status_header(200);
        header('Content-Type: text/xml; charset=' . get_option('blog_charset'), true);
        header('X-Robots-Tag: noindex, follow', true);

        if ($type === 'main') {
            echo esc_html($this->get_index_sitemap());
        } elseif (strpos($type, 'tax-') === 0) {
            echo esc_html($this->get_taxonomy_sitemap(substr($type, 4), $page));
        } else {
            echo esc_html($this->get_sitemap($type, $page));
        }
        exit;
    }

    private function xsl_url(): string {
        $ver = defined('PYLON_VERSION') ? PYLON_VERSION : '1';
        return esc_url(PYLON_URL . 'assets/xsl/sitemap.xsl?v=' . rawurlencode($ver));
    }

    /** @return string[] */
    private function included_post_types(): array {
        $raw = trim((string) get_option('pylon_sitemap_post_types', 'post, page'));
        if ($raw === '') {
            $raw = 'post, page';
        }
        $valid = [];
        foreach (array_filter(array_map('trim', explode(',', $raw))) as $pt) {
            if (post_type_exists($pt)) {
                $valid[] = $pt;
            }
        }
        return $valid ?: ['post', 'page'];
    }

    private function get_index_sitemap(): string {
        $cached = get_transient('pylon_sitemap_index');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<?xml-stylesheet type="text/xsl" href="' . $this->xsl_url() . '"?>';
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        $post_types = $this->included_post_types();
        $max_per_page = max(100, (int) get_option('pylon_sitemap_max_per_page', 1000));

        foreach (apply_filters('pylon_sitemap_index_entries', []) as $entry) {
            if (empty($entry['loc'])) {
                continue;
            }
            $xml .= '<sitemap><loc>' . esc_url($entry['loc']) . '</loc>';
            $xml .= '<lastmod>' . esc_html($entry['lastmod'] ?? current_time('c')) . '</lastmod></sitemap>';
        }

        foreach ($post_types as $pt) {
            $count = (int) (wp_count_posts($pt)->publish ?? 0);
            if ($count <= 0) {
                continue;
            }
            $pages = (int) ceil($count / $max_per_page);
            for ($i = 1; $i <= $pages; $i++) {
                $xml .= '<sitemap><loc>' . esc_url(home_url("/sitemap-{$pt}-{$i}.xml")) . '</loc>';
                $xml .= '<lastmod>' . current_time('c') . '</lastmod></sitemap>';
            }
        }

        foreach (get_taxonomies(['public' => true], 'names') as $tax) {
            $count = (int) wp_count_terms(['taxonomy' => $tax, 'hide_empty' => true]);
            if ($count <= 0) {
                continue;
            }
            $pages = (int) ceil($count / $max_per_page);
            for ($i = 1; $i <= $pages; $i++) {
                $xml .= '<sitemap><loc>' . esc_url(home_url("/sitemap-tax-{$tax}-{$i}.xml")) . '</loc>';
                $xml .= '<lastmod>' . current_time('c') . '</lastmod></sitemap>';
            }
        }

        $xml .= '</sitemapindex>';
        set_transient('pylon_sitemap_index', $xml, HOUR_IN_SECONDS);
        return $xml;
    }

    private function get_sitemap(string $type, int $page): string {
        if (!post_type_exists($type)) {
            return $this->empty_urlset();
        }

        $cache_key = "pylon_sitemap_{$type}_{$page}";
        $cached = get_transient($cache_key);
        if ($cached) {
            return $cached;
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<?xml-stylesheet type="text/xsl" href="' . $this->xsl_url() . '"?>';
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        $default_priority = get_option('pylon_sitemap_priority', '0.8');
        $exclude_ids = array_filter(array_map('intval', explode(',', (string) get_option('pylon_sitemap_exclude_ids', ''))));
        $max_per_page = max(100, min(50000, (int) get_option('pylon_sitemap_max_per_page', 1000)));

        $posts = get_posts([
            'post_type' => $type,
            'post_status' => 'publish',
            'posts_per_page' => $max_per_page,
            'no_found_rows' => true,
            'paged' => $page,
            'orderby' => 'modified',
            'order' => 'DESC',
            'exclude' => $exclude_ids,
        ]);

        $front_page_id = (int) get_option('page_on_front');
        foreach ($posts as $post) {
            if (get_post_meta($post->ID, 'pylon_noindex', true)) {
                continue;
            }
            $xml .= '<url>';
            $xml .= '<loc>' . esc_url(get_permalink($post)) . '</loc>';
            $xml .= '<lastmod>' . esc_html(get_the_modified_date('c', $post)) . '</lastmod>';
            $xml .= '<changefreq>' . ($type === 'post' ? 'weekly' : 'monthly') . '</changefreq>';
            $xml .= '<priority>' . ($post->ID === $front_page_id ? '1.0' : esc_html((string) $default_priority)) . '</priority>';
            $xml .= '</url>';
        }

        $xml .= '</urlset>';
        set_transient($cache_key, $xml, HOUR_IN_SECONDS);
        return $xml;
    }

    private function empty_urlset(): string {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<?xml-stylesheet type="text/xsl" href="' . $this->xsl_url() . '"?>'
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';
    }

    public function clear_cache(): void {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_pylon_sitemap_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_pylon_sitemap_%'");
    }

    private function get_taxonomy_sitemap(string $taxonomy, int $page): string {
        if (!taxonomy_exists($taxonomy)) {
            return $this->empty_urlset();
        }

        $cache_key = "pylon_sitemap_tax_{$taxonomy}_{$page}";
        $cached = get_transient($cache_key);
        if ($cached) {
            return $cached;
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<?xml-stylesheet type="text/xsl" href="' . $this->xsl_url() . '"?>';
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        $max_per_page = max(100, (int) get_option('pylon_sitemap_max_per_page', 1000));
        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => true,
            'number' => $max_per_page,
            'offset' => ($page - 1) * $max_per_page,
            'orderby' => 'count',
            'order' => 'DESC',
        ]);

        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                $link = get_term_link($term);
                if (is_wp_error($link)) {
                    continue;
                }
                $xml .= '<url><loc>' . esc_url($link) . '</loc>';
                $xml .= '<changefreq>monthly</changefreq><priority>0.6</priority></url>';
            }
        }

        $xml .= '</urlset>';
        set_transient($cache_key, $xml, HOUR_IN_SECONDS);
        return $xml;
    }
}
