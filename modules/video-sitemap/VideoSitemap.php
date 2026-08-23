<?php
namespace Pylon\Core\Modules\VideoSitemap;
defined('ABSPATH') || exit;
class VideoSitemap {
    public function register(): void {
        add_filter('init', [$this, 'register_rewrites'], 2);
        add_action('template_redirect', [$this, 'handle_request'], 0);
        add_filter('pylon_sitemap_index_entries', [$this, 'add_to_index']);
    }

    public function register_rewrites(): void {
        add_rewrite_rule('^video-sitemap\.xml$', 'index.php?pylon_video_sitemap=1', 'top');
        add_rewrite_tag('%pylon_video_sitemap%', '([01])');
    }

    public function handle_request(): void {
        if (!get_query_var('pylon_video_sitemap')) return;

        status_header(200);
        header('Content-Type: text/xml; charset=' . get_option('blog_charset'), true);

        $cached = get_transient('pylon_video_sitemap_xml');
        if ($cached !== false) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted cached XML sitemap content.
            echo $cached;
            exit;
        }

        ob_start();
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">';

        $videos = get_posts([
            'post_type' => get_post_types(['public' => true]),
            'post_status' => 'publish',
            'posts_per_page' => 50000,
            'no_found_rows' => true,
            'meta_query' => [
                ['key' => 'pylon_video_url', 'compare' => 'EXISTS'],
            ],
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        if (!empty($videos)) {
            update_meta_cache('post', wp_list_pluck($videos, 'ID'));
        }

        foreach ($videos as $post) {
            $video_url = get_post_meta($post->ID, 'pylon_video_url', true);
            if (empty($video_url)) continue;

            $page_url = get_permalink($post);
            $title = get_post_meta($post->ID, 'pylon_video_title', true) ?: $post->post_title;
            $desc = get_post_meta($post->ID, 'pylon_video_description', true) ?: wp_trim_words(wp_strip_all_tags($post->post_content), 40);
            $thumbnail = get_post_meta($post->ID, 'pylon_video_thumbnail', true) ?: get_the_post_thumbnail_url($post);
            $duration = (int) get_post_meta($post->ID, 'pylon_video_duration', true);

            echo '<url>';
            echo '<loc>' . esc_url($page_url) . '</loc>';
            echo '<video:video>';
            if ($thumbnail) {
                echo '<video:thumbnail_loc>' . esc_url($thumbnail) . '</video:thumbnail_loc>';
            }
            echo '<video:title>' . esc_html($title) . '</video:title>';
            echo '<video:description>' . esc_html($desc) . '</video:description>';
            echo '<video:content_loc>' . esc_url($video_url) . '</video:content_loc>';
            if ($duration > 0) {
                echo '<video:duration>' . (int) $duration . '</video:duration>';
            }
            echo '<video:publication_date>' . get_the_date('c', $post) . '</video:publication_date>';
            echo '<video:family_friendly>yes</video:family_friendly>';
            echo '</video:video>';
            echo '</url>';
        }

        echo '</urlset>';

        $output = ob_get_flush();
        set_transient('pylon_video_sitemap_xml', $output, 6 * HOUR_IN_SECONDS);
        exit;
    }

    public function add_to_index(array $entries): array {
        global $wpdb;
        $types = get_post_types(['public' => true]);
        $placeholders = implode(',', array_fill(0, count($types), '%s'));
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'pylon_video_url'
             WHERE p.post_status = 'publish' AND p.post_type IN ({$placeholders})",
            ...array_values($types)
        ));
        if ($count > 0) {
            $entries[] = [
                'loc' => home_url('/video-sitemap.xml'),
                'lastmod' => current_time('c'),
            ];
        }
        return $entries;
    }
}
