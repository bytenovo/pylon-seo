<?php
namespace Pylon\Core\Modules\BrokenLink;
defined('ABSPATH') || exit;
use Pylon\Core\HttpClient;

class BrokenLinkChecker {
    private const TABLE = 'pylon_broken_links';
    private const BATCH_SIZE = 20;
    private const CURSOR_OPTION = 'pylon_broken_link_cursor';

    public function register(): void {
        add_action('admin_menu', [$this, 'add_admin_page']);
        add_action('pylon_monthly_link_check', [$this, 'run_scheduled_scan']);
        add_action('wp_ajax_pylon_scan_links', [$this, 'ajax_scan_batch']);
        add_action('wp_ajax_pylon_ignore_broken_link', [$this, 'ajax_ignore']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(string $hook): void {
        if (strpos($hook, 'pylon-broken-link') === false) return;
        wp_enqueue_style('pylon-broken-link', PYLON_URL . 'assets/css/modules/broken-link.css', ['pylon-admin'], filemtime(PYLON_PATH . 'assets/css/modules/broken-link.css'));
    }

    public function install(): void {
        $this->activate();
    }

    public function activate(): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            $wpdb->query("CREATE TABLE {$table} (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                post_id BIGINT UNSIGNED NOT NULL,
                link_url TEXT NOT NULL,
                anchor_text TEXT,
                status_code INT UNSIGNED DEFAULT 0,
                checked_at DATETIME DEFAULT NULL,
                ignored TINYINT UNSIGNED DEFAULT 0,
                INDEX idx_post (post_id),
                INDEX idx_ignored (ignored),
                INDEX idx_link_url (link_url(191)),
                INDEX idx_checked_at (checked_at)
            )");
        }

        if (!wp_next_scheduled('pylon_monthly_link_check')) {
            wp_schedule_event(time(), 'monthly', 'pylon_monthly_link_check');
        }
    }

    public function uninstall(): void {
        $this->deactivate();
    }

    public function deactivate(): void {
        wp_clear_scheduled_hook('pylon_monthly_link_check');
    }

    public function add_admin_page(): void {
        add_submenu_page(
            'pylon',
            __('Broken Links', 'pylon-seo'),
            __('Broken Links', 'pylon-seo'),
            'manage_options',
            'pylon-broken-links',
            [$this, 'render_admin_page']
        );
    }

    public function render_admin_page(): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        if (!$this->table_exists($table)) {
            $this->activate();
        }

        $broken = $wpdb->get_results("SELECT bl.*, p.post_title FROM {$table} bl LEFT JOIN {$wpdb->posts} p ON bl.post_id = p.ID WHERE bl.ignored = 0 AND bl.status_code >= 400 ORDER BY bl.checked_at DESC LIMIT 100");
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE ignored = 0 AND status_code >= 400");
        $last_scan = $wpdb->get_var("SELECT MAX(checked_at) FROM {$table}");
        $cursor = (int) get_option(self::CURSOR_OPTION, 0);
        ?>
        <div class="pylon-broken-toolbar">
            <div class="info">
                <?php /* translators: %s: Date and time of the last scan. */ ?>
                <?php echo $last_scan ? esc_html(sprintf(__('Last scan: %s', 'pylon-seo'), $last_scan)) : esc_html__('Never scanned', 'pylon-seo'); ?>
                &middot; <strong><?php echo (int) $total; ?></strong> <?php esc_html_e('broken links', 'pylon-seo'); ?>
                &middot; <strong><?php echo count($broken); ?></strong> <?php esc_html_e('shown', 'pylon-seo'); ?>
                &middot; <?php echo wp_next_scheduled('pylon_monthly_link_check') ? esc_html__('Auto-scan active', 'pylon-seo') : esc_html__('Auto-scan inactive', 'pylon-seo'); ?>
                <?php if ($cursor): ?>
                    <?php /* translators: %d: Post ID the scan cursor is positioned after. */ ?>
                    &middot; <?php echo esc_html(sprintf(__('Scan cursor: post #%d', 'pylon-seo'), $cursor)); ?>
                <?php endif; ?>
            </div>
            <button type="button" class="pylon-btn pylon-btn-primary" id="pylon-scan-links" data-pylon-ajax="pylon_scan_links" data-pylon-reload="true">
                <?php esc_html_e('Scan Now', 'pylon-seo'); ?>
            </button>
        </div>

        <div class="pylon-table-wrap">
            <table class="pylon-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Post', 'pylon-seo'); ?></th>
                        <th><?php esc_html_e('Broken URL', 'pylon-seo'); ?></th>
                        <th><?php esc_html_e('Anchor Text', 'pylon-seo'); ?></th>
                        <th><?php esc_html_e('Status', 'pylon-seo'); ?></th>
                        <th><?php esc_html_e('Checked', 'pylon-seo'); ?></th>
                        <th><?php esc_html_e('Actions', 'pylon-seo'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($broken)): ?>
                        <tr><td colspan="6"><div class="pylon-table-empty"><p><?php esc_html_e('No broken links found!', 'pylon-seo'); ?></p></div></td></tr>
                    <?php else: ?>
                        <?php foreach ($broken as $link): ?>
                            <tr>
                                <td><a href="<?php echo esc_url(get_edit_post_link($link->post_id)); ?>" style="font-weight:500;color:#155eef;text-decoration:none"><?php echo esc_html($link->post_title ?: '#' . $link->post_id); ?></a></td>
                                <td class="pylon-overflow-ellipsis" style="max-width:200px;"><a href="<?php echo esc_url($link->link_url); ?>" target="_blank" rel="noopener" style="color:#6b7280;text-decoration:none"><?php echo esc_html($link->link_url); ?></a></td>
                                <td style="color:#6b7280;font-size:12px"><?php echo esc_html(mb_substr($link->anchor_text, 0, 50)); ?></td>
                                <td><span class="pylon-badge" style="background:#fef2f2;color:#dc2626;font-weight:600"><?php echo esc_html($link->status_code); ?></span></td>
                                <td style="font-size:12px;color:#6b7280"><?php echo esc_html($link->checked_at ? date_i18n(get_option('date_format'), strtotime($link->checked_at)) : '-'); ?></td>
                                <td class="pylon-cell-actions">
                                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-ajax.php?action=pylon_ignore_broken_link&id=' . $link->id), 'pylon_ignore_link_' . $link->id)); ?>" class="pylon-btn pylon-btn-sm pylon-btn-secondary" data-pylon-ajax="pylon_ignore_broken_link" data-pylon-data="<?php echo esc_attr(wp_json_encode(['id' => $link->id, '_wpnonce' => wp_create_nonce('pylon_ignore_link_' . $link->id)])); ?>" data-pylon-reload="true"><?php esc_html_e('Ignore', 'pylon-seo'); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function run_scheduled_scan(): void {
        $this->scan_next_batch(25, 100, 25);
    }

    public function scan_all_posts(): void {
        delete_option(self::CURSOR_OPTION);
        do {
            $result = $this->scan_next_batch(100, 500, 25);
        } while (empty($result['complete']));
    }

    public function scan_next_batch(int $max_posts = 20, int $max_links = 50, int $max_seconds = 25): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        if (!$this->table_exists($table)) {
            $this->activate();
        }

        $post_types = get_post_types(['public' => true]);
        $cursor = (int) get_option(self::CURSOR_OPTION, 0);
        $start_time = time();
        $processed_posts = 0;
        $checked_links = 0;

        $posts = $this->get_post_ids_after($cursor, $post_types, $max_posts);

        if (empty($posts)) {
            delete_option(self::CURSOR_OPTION);
            return ['complete' => true, 'processed_posts' => 0, 'checked_links' => 0];
        }

        foreach ($posts as $post_id) {
            if ((time() - $start_time) >= $max_seconds || $checked_links >= $max_links) {
                break;
            }

            $content = get_post_field('post_content', $post_id);
            $links = $this->extract_links($content, $post_id);
            foreach ($links as $link) {
                if ((time() - $start_time) >= $max_seconds || $checked_links >= $max_links) {
                    break;
                }

                $status = $this->check_link($link['url']);
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$table} WHERE post_id = %d AND link_url = %s",
                    $post_id, $link['url']
                ));
                if ($existing) {
                    $wpdb->update($table, [
                        'status_code' => $status,
                        'anchor_text' => $link['anchor'],
                        'checked_at' => current_time('mysql'),
                    ], ['id' => $existing]);
                } else {
                    $wpdb->insert($table, [
                        'post_id' => $post_id,
                        'link_url' => $link['url'],
                        'anchor_text' => $link['anchor'],
                        'status_code' => $status,
                        'checked_at' => current_time('mysql'),
                    ]);
                }
                $checked_links++;
            }
            $cursor = (int) $post_id;
            $processed_posts++;
        }

        $has_more = $this->has_more_posts($cursor, $post_types);
        if ($has_more) {
            update_option(self::CURSOR_OPTION, $cursor, false);
        } else {
            delete_option(self::CURSOR_OPTION);
        }

        return [
            'complete' => !$has_more,
            'processed_posts' => $processed_posts,
            'checked_links' => $checked_links,
            'cursor' => $has_more ? $cursor : 0,
        ];
    }

    public function ajax_scan_batch(): void {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_ajax_nonce'] ?? '')), 'pylon_admin_nonce') || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'pylon-seo')]);
        }

        $result = $this->scan_next_batch();
        $message = !empty($result['complete'])
            ? __('Scan complete.', 'pylon-seo')
            : sprintf(
                /* translators: %1$d: Number of posts processed, %2$d: Number of links checked. */
                __('Batch complete: %1$d posts, %2$d links checked. Run again to continue.', 'pylon-seo'),
                (int) $result['processed_posts'],
                (int) $result['checked_links']
            );

        wp_send_json_success($result + ['message' => $message]);
    }

    public function ajax_ignore(): void {
        $id = absint($_GET['id'] ?? $_POST['id'] ?? 0);
        $nonce = sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? $_POST['_wpnonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'pylon_ignore_link_' . $id) || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'pylon-seo')]);
        }

        global $wpdb;
        $wpdb->update($wpdb->prefix . self::TABLE, ['ignored' => 1], ['id' => $id]);
        wp_send_json_success(['message' => __('Link ignored.', 'pylon-seo')]);
    }

    private function extract_links(string $content, int $post_id): array {
        preg_match_all('/<a\s[^>]*href=["\'](https?:\/\/[^"\']+)["\'][^>]*>([^<]*)<\/a>/i', $content, $matches, PREG_SET_ORDER);
        $links = [];
        $site_host = wp_parse_url(home_url(), PHP_URL_HOST);
        foreach ($matches as $m) {
            $url = $m[1];
            $host = wp_parse_url($url, PHP_URL_HOST);
            if ($host === $site_host) continue;
            if (empty(trim($url))) continue;
            $links[] = ['url' => $url, 'anchor' => wp_strip_all_tags($m[2])];
        }
        return $links;
    }

    private function has_more_posts(int $cursor, array $post_types): bool {
        if ($cursor <= 0) return false;
        return !empty($this->get_post_ids_after($cursor, $post_types, 1));
    }

    private function get_post_ids_after(int $cursor, array $post_types, int $limit): array {
        global $wpdb;
        $post_types = array_values(array_filter(array_map('sanitize_key', $post_types)));
        if (empty($post_types)) return [];

        $placeholders = implode(',', array_fill(0, count($post_types), '%s'));

        return array_map('intval', $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$placeholders}) AND ID > %d ORDER BY ID ASC LIMIT %d",
                array_merge($post_types, [$cursor, $limit])
            )
        ));
    }

    private function table_exists(string $table): bool {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    private function check_link(string $url): int {
        $response = HttpClient::request('HEAD', $url, [
            'timeout' => 10,
            'redirection' => 2,
            'headers' => [
                'User-Agent' => 'Pylon Link Checker/1.0',
            ],
        ]);

        $code = (int) ($response['code'] ?? 0);
        if ($code === 403 || $code === 405 || $code === 501) {
            $response = HttpClient::request('GET', $url, [
                'timeout' => 10,
                'redirection' => 2,
                'headers' => [
                    'User-Agent' => 'Pylon Link Checker/1.0',
                ],
            ]);
            if (!$response['success']) {
                return 0;
            }
            $code = (int) ($response['code'] ?? 0);
        }

        return $response['success'] ? $code : 0;
    }
}
