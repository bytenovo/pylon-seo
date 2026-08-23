<?php
namespace Pylon\Core\Modules\NewsSitemap;
defined('ABSPATH') || exit;
class NewsSitemap {
    public function register(): void {
        add_filter('init', [$this, 'register_rewrites'], 2);
        add_action('template_redirect', [$this, 'handle_request'], 0);
        add_filter('pylon_sitemap_index_entries', [$this, 'add_to_index']);
    }

    public function register_rewrites(): void {
        add_rewrite_rule('^news-sitemap\.xml$', 'index.php?pylon_news_sitemap=1', 'top');
        add_rewrite_tag('%pylon_news_sitemap%', '([01])');
    }

    public function handle_request(): void {
        if (!get_query_var('pylon_news_sitemap')) return;

        status_header(200);
        header('Content-Type: text/xml; charset=' . get_option('blog_charset'), true);

        $cached = get_transient('pylon_news_sitemap_xml');
        if ($cached !== false) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted cached XML sitemap content.
            echo $cached;
            exit;
        }

        ob_start();
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">';

        $posts = get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 1000,
            'no_found_rows' => true,
            'orderby' => 'date',
            'order' => 'DESC',
            'date_query' => [
                'after' => '48 hours ago',
            ],
        ]);

        if (!empty($posts)) {
            update_meta_cache('post', wp_list_pluck($posts, 'ID'));
        }

        $name = get_bloginfo('name');
        $lang = str_replace('_', '-', get_locale());

        foreach ($posts as $post) {
            $title = get_post_meta($post->ID, 'pylon_title', true) ?: $post->post_title;
            echo '<url>';
            echo '<loc>' . esc_url(get_permalink($post)) . '</loc>';
            echo '<news:news>';
            echo '<news:publication>';
            echo '<news:name>' . esc_html($name) . '</news:name>';
            echo '<news:language>' . esc_html($lang) . '</news:language>';
            echo '</news:publication>';
            echo '<news:publication_date>' . get_the_date('Y-m-d', $post) . '</news:publication_date>';
            echo '<news:title>' . esc_html($title) . '</news:title>';
            echo '</news:news>';
            echo '</url>';
        }

        echo '</urlset>';

        $output = ob_get_flush();
        set_transient('pylon_news_sitemap_xml', $output, HOUR_IN_SECONDS);
        exit;
    }

    public function add_to_index(array $entries): array {
        $count = (int) wp_count_posts('post')->publish;
        if ($count > 0) {
            $entries[] = [
                'loc' => home_url('/news-sitemap.xml'),
                'lastmod' => current_time('c'),
            ];
        }
        return $entries;
    }
}
