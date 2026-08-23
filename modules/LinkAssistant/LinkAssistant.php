<?php
namespace Pylon\Core\Modules\LinkAssistant;
defined('ABSPATH') || exit;
class LinkAssistant {
    public function register(): void {
        add_action('wp_ajax_pylon_link_suggest', [$this, 'ajax_suggest_links']);
        add_action('wp_ajax_pylon_link_insert', [$this, 'ajax_insert_link']);
    }

    public function render_admin_page(): void {
        global $wpdb;
        $orphans = $wpdb->get_results("
            SELECT ID, post_title, post_type, post_date
            FROM {$wpdb->posts}
            WHERE post_status = 'publish' AND post_type IN ('post','page')
            ORDER BY post_date DESC
            LIMIT 50
        ");
        ?>
        <div class="wrap pylon-dashboard">
            <?php \Pylon\Core\Modules\Admin\AdminEngine::page_header(__('Link Assistant', 'pylon-seo'), '🔗'); ?>

            <div class="pylon-card">
                <h3><?php esc_html_e('Recent Published Content', 'pylon-seo'); ?></h3>
                <p style="font-size:13px;color:var(--pylon-gray-500);"><?php esc_html_e('Review and add internal links to improve SEO structure.', 'pylon-seo'); ?></p>
                <div class="pylon-table-wrap">
                    <table class="pylon-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Title', 'pylon-seo'); ?></th>
                                <th><?php esc_html_e('Type', 'pylon-seo'); ?></th>
                                <th><?php esc_html_e('Date', 'pylon-seo'); ?></th>
                                <th><?php esc_html_e('Actions', 'pylon-seo'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orphans as $post): ?>
                                <tr>
                                    <td><a href="<?php echo esc_url(get_edit_post_link($post->ID)); ?>"><?php echo esc_html($post->post_title ?: '#' . $post->ID); ?></a></td>
                                    <td><?php echo esc_html($post->post_type); ?></td>
                                    <td><?php echo esc_html(get_the_date(get_option('date_format'), $post->ID)); ?></td>
                                    <td>
                                        <button type="button" class="pylon-btn pylon-btn-sm pylon-btn-secondary" data-pylon-ajax="pylon_link_suggest" data-pylon-data='{"post_id":<?php echo (int)$post->ID; ?>}' data-pylon-target="la_<?php echo (int)$post->ID; ?>">
                                            <?php esc_html_e('Suggest Links', 'pylon-seo'); ?>
                                        </button>
                                        <div id="la_<?php echo (int)$post->ID; ?>" style="margin-top:4px;"></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    public function ajax_suggest_links(): void {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_ajax_nonce'] ?? '')), 'pylon_admin_nonce') || !current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'pylon-seo')]);
        }

        $post_id = absint($_POST['post_id'] ?? 0);
        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error(['message' => __('Invalid post.', 'pylon-seo')]);
        }

        $all_posts = get_posts([
            'post_type' => get_post_types(['public' => true]),
            'post_status' => 'publish',
            'posts_per_page' => 50,
            'no_found_rows' => true,
            'exclude' => [$post_id],
            'fields' => ['ID', 'post_title'],
        ]);

        $site_urls = [];
        foreach ($all_posts as $p) {
            $site_urls[] = ['id' => $p->ID, 'title' => $p->post_title, 'url' => get_permalink($p->ID)];
        }

        $suggestions = $this->generate_suggestions($post, $site_urls);

        if (empty($suggestions)) {
            wp_send_json_success(['html' => '<div style="font-size:11px;color:var(--pylon-gray-400);padding:8px 0;">' . __('No suggestions available.', 'pylon-seo') . '</div>']);
        }

        $keywords = get_post_meta($post_id, 'pylon_focus_keyword', true);
        $content_lower = strtolower($post->post_content . ' ' . $post->post_title);

        ob_start();
        foreach ($suggestions as $s) {
            $confidence = $s['confidence'] > 0.7 ? 'high' : ($s['confidence'] > 0.4 ? 'medium' : 'low');
            ?>
            <div class="pylon-link-suggestion">
                <span class="pls-anchor" title="<?php echo esc_attr($s['anchor']); ?>"><?php echo esc_html(mb_substr($s['anchor'], 0, 30)); ?></span>
                <span class="pls-url" title="<?php echo esc_url($s['url']); ?>"><?php echo esc_url($s['url']); ?></span>
                <span class="pls-reason"><?php echo esc_html(mb_substr($s['reason'], 0, 40)); ?></span>
                <span class="pls-confidence <?php echo esc_attr($confidence); ?>"><?php echo esc_html(round($s['confidence'] * 100) . '%'); ?></span>
                <button type="button" class="pylon-btn pylon-btn-sm pylon-btn-primary pls-insert"
                        data-pylon-ajax="pylon_link_insert"
                        data-pylon-data="<?php echo esc_attr(wp_json_encode([
                            'post_id'  => $post_id,
                            'anchor'   => $s['anchor'],
                            'url'      => $s['url'],
                            '_ajax_nonce' => wp_create_nonce('pylon_admin_nonce'),
                        ])); ?>"
                        data-confirm="<?php esc_attr_e('Insert this internal link into the content?', 'pylon-seo'); ?>">
                    <?php esc_html_e('Insert Link', 'pylon-seo'); ?>
                </button>
            </div>
            <?php
        }
        $html = ob_get_clean();

        \Pylon\Core\Bootstrap::track_usage('link_suggestions');
        wp_send_json_success(['html' => $html]);
    }

    /**
     * Insert a single internal link into post content. Finds the first
     * occurrence of the anchor text (case-insensitive) that isn't already inside
     * a link, wraps it, and saves. Falls back to appending the link if the text
     * isn't found.
     */
    public function ajax_insert_link(): void {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_ajax_nonce'] ?? '')), 'pylon_admin_nonce') || !current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'pylon-seo')]);
        }

        $post_id = absint($_POST['post_id'] ?? 0);
        $anchor  = sanitize_text_field(wp_unslash($_POST['anchor'] ?? ''));
        $url     = esc_url_raw(wp_unslash($_POST['url'] ?? ''));

        $post = get_post($post_id);
        if (!$post || $anchor === '' || $url === '') {
            wp_send_json_error(['message' => __('Invalid request.', 'pylon-seo')]);
        }

        // Don't double-link an anchor that's already linked to this URL.
        $content = $post->post_content;
        $escaped_anchor = preg_quote($anchor, '/');

        // Match the anchor only when NOT already inside an <a>...</a>.
        // Strategy: find the first occurrence not preceded by an open <a ...>.
        if (preg_match('/((?<!<a[^>]*>)' . $escaped_anchor . ')/iu', $content, $m, PREG_OFFSET_CAPTURE)) {
            $match_text = $m[1][0];
            $offset     = $m[1][1];
            $replacement = '<a href="' . esc_attr($url) . '">' . esc_html($match_text) . '</a>';
            $content = substr_replace($content, $replacement, $offset, strlen($match_text));
            $action = 'replaced';
        } else {
            // Anchor not present in plain text — append a "Related:" link.
            $link_html = sprintf(
                '<p>%s <a href="%s">%s</a></p>',
                esc_html__('Related:', 'pylon-seo'),
                esc_attr($url),
                esc_html($anchor)
            );
            $content .= "\n" . $link_html;
            $action = 'appended';
        }

        wp_update_post([
            'ID'           => $post_id,
            'post_content' => wp_slash($content),
        ]);

        \Pylon\Core\Bootstrap::track_usage('link_inserted');

        wp_send_json_success([
            'message' => __('Link inserted and content updated.', 'pylon-seo'),
            'action'  => $action,
        ]);
    }

    private function generate_suggestions(\WP_Post $post, array $site_posts): array {
        $content = $post->post_content . ' ' . $post->post_title;
        $content_lower = strtolower($content);
        $suggestions = [];
        $used_urls = [];

        preg_match_all('/<a[^>]+href=["\']([^"\']+)["\']/i', $content, $existing_links);
        foreach ($existing_links[1] as $link) {
            $used_urls[] = trim($link);
        }

        $focus_kw = get_post_meta($post->ID, 'pylon_focus_keyword', true);

        foreach ($site_posts as $sp) {
            $url = $sp['url'];
            if (in_array($url, $used_urls)) continue;

            $title_lower = strtolower($sp['title']);
            $confidence = 0;

            if ($focus_kw && strpos($title_lower, strtolower($focus_kw)) !== false) {
                $confidence = 0.85;
                $reason = __('Related to focus keyword', 'pylon-seo');
                $anchor = $sp['title'];
            } elseif (strpos($content_lower, $title_lower) !== false) {
                $confidence = 0.7;
                $reason = __('Mentions this topic', 'pylon-seo');
                $anchor = $sp['title'];
            } else {
                foreach (explode(' ', $title_lower) as $word) {
                    if (strlen($word) > 4 && strpos($content_lower, $word) !== false) {
                        $confidence = 0.4;
                        /* translators: %s: Related topic word. */
                        $reason = sprintf(__('Related: %s', 'pylon-seo'), $word);
                        $anchor = $sp['title'];
                        break;
                    }
                }
            }

            if ($confidence > 0) {
                $suggestions[] = [
                    'anchor' => $anchor,
                    'url' => $url,
                    'confidence' => $confidence,
                    'reason' => $reason,
                ];
            }
        }

        usort($suggestions, fn($a, $b) => $b['confidence'] <=> $a['confidence']);
        return array_slice($suggestions, 0, 10);
    }
}