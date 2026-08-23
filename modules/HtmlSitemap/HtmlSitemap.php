<?php
namespace Pylon\Core\Modules\HtmlSitemap;
defined('ABSPATH') || exit;
/**
 * Human-readable HTML sitemap (shortcode + admin preview + export).
 */
class HtmlSitemap {
    public function register(): void {
        add_shortcode('pylon_html_sitemap', [$this, 'render_shortcode']);
        add_action('admin_init', [$this, 'register_settings']);
        add_filter('pylon_settings_sections', [$this, 'settings_section']);
        add_action('admin_menu', [$this, 'add_admin_page']);
        add_action('admin_post_pylon_export_sitemap_html', [$this, 'export_html']);
        add_action('admin_post_pylon_export_sitemap_xml', [$this, 'export_xml']);
    }

    public function register_settings(): void {
        $opts = [
            'pylon_html_sitemap_enabled' => 'absint',
            'pylon_html_sitemap_posts' => 'absint',
            'pylon_html_sitemap_pages' => 'absint',
            'pylon_html_sitemap_cats' => 'absint',
            'pylon_html_sitemap_tags' => 'absint',
            'pylon_html_sitemap_cpt' => 'sanitize_text_field',
            'pylon_html_sitemap_columns' => 'absint',
            'pylon_html_sitemap_exclude' => 'sanitize_text_field',
        ];
        foreach ($opts as $key => $cb) {
            register_setting('pylon_settings', $key, ['sanitize_callback' => $cb]);
        }
    }

    /**
     * Treat missing / 1 / '1' as on. Only explicit 0 / '0' / false is off.
     * Fixes absint-saved ints and never-saved options both looking "empty".
     */
    public static function opt_on(string $key, $default = '1'): bool {
        $raw = get_option($key, null);
        if ($raw === null || $raw === false) {
            // Option missing — use default.
            return (string) $default === '1' || $default === 1 || $default === true;
        }
        if ($raw === '' || $raw === '0' || $raw === 0 || $raw === 'off' || $raw === 'false') {
            return false;
        }
        return true;
    }

    public function settings_section(array $sections): array {
        $sections['html_sitemap'] = [
            'icon' => '🗺️',
            'title' => __('HTML Sitemap', 'pylon-seo'),
            'desc' => __('Publish a human-readable sitemap for users and crawlers. Use shortcode [pylon_html_sitemap] or create a page from Tools → HTML Sitemap.', 'pylon-seo'),
            'fields' => [
                'pylon_html_sitemap_enabled' => [
                    'type' => 'checkbox',
                    'label' => __('Enable HTML Sitemap shortcode', 'pylon-seo'),
                    'desc' => __('When off, the shortcode renders nothing.', 'pylon-seo'),
                    'default' => '1',
                ],
                'pylon_html_sitemap_pages' => [
                    'type' => 'checkbox',
                    'label' => __('Include pages', 'pylon-seo'),
                    'default' => '1',
                ],
                'pylon_html_sitemap_posts' => [
                    'type' => 'checkbox',
                    'label' => __('Include posts', 'pylon-seo'),
                    'default' => '1',
                ],
                'pylon_html_sitemap_cats' => [
                    'type' => 'checkbox',
                    'label' => __('Include categories', 'pylon-seo'),
                    'default' => '1',
                ],
                'pylon_html_sitemap_tags' => [
                    'type' => 'checkbox',
                    'label' => __('Include tags', 'pylon-seo'),
                    'default' => '0',
                ],
                'pylon_html_sitemap_cpt' => [
                    'type' => 'text',
                    'label' => __('Extra post types', 'pylon-seo'),
                    'desc' => __('Comma-separated public CPTs. Leave empty to auto-include all public types (except attachment).', 'pylon-seo'),
                    'placeholder' => 'property, product',
                ],
                'pylon_html_sitemap_columns' => [
                    'type' => 'number',
                    'label' => __('Columns', 'pylon-seo'),
                    'desc' => __('1–4 columns on desktop', 'pylon-seo'),
                    'attrs' => 'min="1" max="4"',
                    'default' => '2',
                ],
                'pylon_html_sitemap_exclude' => [
                    'type' => 'text',
                    'label' => __('Exclude post IDs', 'pylon-seo'),
                    'desc' => __('Comma-separated IDs to hide (e.g. checkout, cart)', 'pylon-seo'),
                    'placeholder' => '12, 34',
                ],
            ],
        ];
        return $sections;
    }

    public function add_admin_page(): void {
        add_submenu_page(
            'pylon',
            __('HTML Sitemap', 'pylon-seo'),
            __('HTML Sitemap', 'pylon-seo'),
            'manage_options',
            'pylon-html-sitemap',
            [$this, 'render_admin_page']
        );
    }

    public function render_admin_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        $notice = '';
        if (isset($_POST['pylon_create_sitemap_page']) && check_admin_referer('pylon_html_sitemap_page')) {
            $page_id = $this->ensure_sitemap_page();
            $notice = $page_id
                ? sprintf(
                    /* translators: %1$s: edit page URL, %2$s: view page URL. */
                    __('Sitemap page ready. <a href="%1$s">Edit page</a> · <a href="%2$s" target="_blank" rel="noopener">View</a>', 'pylon-seo'),
                    esc_url(get_edit_post_link($page_id, 'raw')),
                    esc_url(get_permalink($page_id))
                )
                : __('Could not create page.', 'pylon-seo');
        }
        $existing = (int) get_option('pylon_html_sitemap_page_id', 0);
        $urls = $this->collect_urls(true);
        $export_html = wp_nonce_url(admin_url('admin-post.php?action=pylon_export_sitemap_html'), 'pylon_export_sitemap');
        $export_xml = wp_nonce_url(admin_url('admin-post.php?action=pylon_export_sitemap_xml'), 'pylon_export_sitemap');
        $live_xml = home_url('/sitemap.xml');
        ?>
        <div class="wrap">
            <?php \Pylon\Core\Modules\Admin\AdminEngine::page_header(__('HTML Sitemap', 'pylon-seo'), '🗺️'); ?>
            <?php if ($notice): ?>
                <div class="notice notice-success"><p><?php echo wp_kses_post($notice); ?></p></div>
            <?php endif; ?>

            <div class="pylon-status-grid" style="margin-bottom:16px;">
                <div class="pylon-status-card">
                    <div class="pylon-status-value"><?php echo count($urls); ?></div>
                    <div class="pylon-status-label"><?php esc_html_e('URLs in sitemap', 'pylon-seo'); ?></div>
                </div>
                <div class="pylon-status-card">
                    <div class="pylon-status-value"><?php echo count(array_unique(array_column($urls, 'section'))); ?></div>
                    <div class="pylon-status-label"><?php esc_html_e('Sections', 'pylon-seo'); ?></div>
                </div>
            </div>

            <div class="pylon-card" style="margin-bottom:16px;">
                <div class="pylon-card-header"><h3><?php esc_html_e('Publish & export', 'pylon-seo'); ?></h3></div>
                <div class="pylon-card-body">
                    <p class="pylon-help" style="margin-top:0;">
                        <?php esc_html_e('Shortcode for a public page, or download a full HTML / XML file. Live XML sitemap stays at /sitemap.xml.', 'pylon-seo'); ?>
                    </p>
                    <p><code>[pylon_html_sitemap]</code></p>
                    <div class="pylon-actions" style="margin:12px 0;">
                        <a class="pylon-btn pylon-btn-primary" href="<?php echo esc_url($export_html); ?>"><?php esc_html_e('Export HTML', 'pylon-seo'); ?></a>
                        <a class="pylon-btn pylon-btn-secondary" href="<?php echo esc_url($export_xml); ?>"><?php esc_html_e('Export XML', 'pylon-seo'); ?></a>
                        <a class="pylon-btn pylon-btn-secondary" href="<?php echo esc_url($live_xml); ?>" target="_blank" rel="noopener"><?php esc_html_e('Open /sitemap.xml', 'pylon-seo'); ?></a>
                    </div>
                    <?php if ($existing && get_post($existing)): ?>
                        <p>
                            <a class="pylon-btn pylon-btn-secondary" href="<?php echo esc_url(get_edit_post_link($existing)); ?>"><?php esc_html_e('Edit sitemap page', 'pylon-seo'); ?></a>
                            <a class="pylon-btn pylon-btn-secondary" href="<?php echo esc_url(get_permalink($existing)); ?>" target="_blank" rel="noopener"><?php esc_html_e('View page', 'pylon-seo'); ?></a>
                        </p>
                    <?php else: ?>
                        <form method="post" style="display:inline;">
                            <?php wp_nonce_field('pylon_html_sitemap_page'); ?>
                            <button type="submit" name="pylon_create_sitemap_page" value="1" class="pylon-btn pylon-btn-primary"><?php esc_html_e('Create “Sitemap” page', 'pylon-seo'); ?></button>
                        </form>
                    <?php endif; ?>
                    <p style="margin-top:12px;">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=pylon-settings#html_sitemap')); ?>"><?php esc_html_e('Configure sections in Settings →', 'pylon-seo'); ?></a>
                    </p>
                </div>
            </div>

            <div class="pylon-card">
                <div class="pylon-card-header">
                    <h3><?php esc_html_e('URL list', 'pylon-seo'); ?></h3>
                    <span class="pylon-text-12 pylon-color-muted"><?php echo esc_html(sprintf(/* translators: %d: number of URLs. */ __('%d URLs', 'pylon-seo'), count($urls))); ?></span>
                </div>
                <div class="pylon-card-body" style="padding:0;">
                    <?php if (!$urls): ?>
                        <div class="pylon-empty" style="padding:32px;">
                            <p style="margin:0;"><?php esc_html_e('No published URLs found. Publish pages/posts or enable sections under Settings → HTML Sitemap.', 'pylon-seo'); ?></p>
                        </div>
                    <?php else: ?>
                        <div class="pylon-table-wrap" style="max-height:520px;overflow:auto;">
                            <table class="pylon-table">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e('Title', 'pylon-seo'); ?></th>
                                        <th><?php esc_html_e('URL', 'pylon-seo'); ?></th>
                                        <th><?php esc_html_e('Section', 'pylon-seo'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($urls as $row): ?>
                                    <tr>
                                        <td><?php echo esc_html($row['title']); ?></td>
                                        <td style="font-size:12px;font-family:Consolas,Menlo,monospace;word-break:break-all;">
                                            <a href="<?php echo esc_url($row['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($row['url']); ?></a>
                                        </td>
                                        <td><?php echo esc_html($row['section']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="pylon-card" style="margin-top:16px;">
                <div class="pylon-card-header"><h3><?php esc_html_e('Front-end preview', 'pylon-seo'); ?></h3></div>
                <div class="pylon-card-body"><?php echo wp_kses_post($this->render_shortcode(['preview' => '1'])); ?></div>
            </div>
        </div>
        <?php
    }

    private function ensure_sitemap_page(): int {
        $existing = (int) get_option('pylon_html_sitemap_page_id', 0);
        if ($existing && get_post_status($existing)) {
            return $existing;
        }
        $id = wp_insert_post([
            'post_title' => __('Sitemap', 'pylon-seo'),
            'post_name' => 'sitemap',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_content' => '[pylon_html_sitemap]',
        ], true);
        if (is_wp_error($id) || !$id) {
            return 0;
        }
        update_option('pylon_html_sitemap_page_id', (int) $id, false);
        return (int) $id;
    }

    public function export_html(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Forbidden', 'pylon-seo'));
        }
        check_admin_referer('pylon_export_sitemap');
        $body = $this->build_html_document($this->collect_urls(true));
        $filename = sanitize_file_name(get_bloginfo('name') . '-sitemap.html');
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    public function export_xml(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Forbidden', 'pylon-seo'));
        }
        check_admin_referer('pylon_export_sitemap');
        $urls = $this->collect_urls(true);
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $row) {
            $xml .= "  <url>\n";
            $xml .= '    <loc>' . esc_url($row['url']) . "</loc>\n";
            if (!empty($row['lastmod'])) {
                $xml .= '    <lastmod>' . esc_html($row['lastmod']) . "</lastmod>\n";
            }
            $xml .= "  </url>\n";
        }
        $xml .= '</urlset>';
        $filename = sanitize_file_name(get_bloginfo('name') . '-sitemap.xml');
        nocache_headers();
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    private function build_html_document(array $urls): string {
        $site = get_bloginfo('name');
        $grouped = [];
        foreach ($urls as $row) {
            $grouped[$row['section']][] = $row;
        }
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . esc_html($site) . ' — Sitemap</title></head>';
        $html .= '<body style="font-family:system-ui,sans-serif;max-width:960px;margin:40px auto;padding:0 16px;color:#111">';
        $html .= '<h1 style="font-size:1.5rem">' . esc_html($site) . ' — ' . esc_html__('Sitemap', 'pylon-seo') . '</h1>';
        $html .= '<p style="color:#64748b;font-size:13px">' . esc_html(sprintf(/* translators: %1$d: number of URLs, %2$s: generation timestamp. */ __('%1$d URLs · Generated %2$s', 'pylon-seo'), count($urls), current_time('mysql'))) . '</p>';
        foreach ($grouped as $section => $rows) {
            $html .= '<h2 style="font-size:1.1rem;margin-top:28px">' . esc_html($section) . '</h2>';
            $html .= '<ul style="padding-left:1.2em">';
            foreach ($rows as $row) {
                $html .= '<li><a href="' . esc_url($row['url']) . '" style="color:#2563eb;text-decoration:none">' . esc_html($row['title']) . '</a></li>';
            }
            $html .= '</ul>';
        }
        $html .= '</body></html>';
        return $html;
    }

    public function render_shortcode($atts = []): string {
        $atts = shortcode_atts(['preview' => ''], $atts, 'pylon_html_sitemap');
        if ($atts['preview'] !== '1' && !self::opt_on('pylon_html_sitemap_enabled', '1')) {
            return '';
        }
        $cols = max(1, min(4, (int) get_option('pylon_html_sitemap_columns', 2)));
        if ($cols < 1) {
            $cols = 2;
        }
        $urls = $this->collect_urls($atts['preview'] === '1');
        if (!$urls) {
            return '<p class="pylon-html-sitemap-empty">' . esc_html__('No sitemap items found yet.', 'pylon-seo') . '</p>';
        }
        $grouped = [];
        foreach ($urls as $row) {
            $grouped[$row['section']][] = $row;
        }
        $html = '<nav class="pylon-html-sitemap" aria-label="' . esc_attr__('HTML Sitemap', 'pylon-seo') . '" style="display:grid;grid-template-columns:repeat(' . (int) $cols . ',minmax(0,1fr));gap:24px;">';
        foreach ($grouped as $section => $rows) {
            $html .= '<div class="pylon-html-sitemap-section"><h2 style="font-size:1.1rem;margin:0 0 12px;">' . esc_html($section) . '</h2><ul style="list-style:disc;padding-left:1.2em;margin:0;">';
            foreach ($rows as $row) {
                $html .= '<li><a href="' . esc_url($row['url']) . '">' . esc_html($row['title']) . '</a></li>';
            }
            $html .= '</ul></div>';
        }
        $html .= '</nav>';
        return $html;
    }

    /**
     * @return array<int, array{title:string,url:string,section:string,lastmod?:string}>
     */
    public function collect_urls(bool $include_noindex = false): array {
        $exclude = array_filter(array_map('absint', explode(',', (string) get_option('pylon_html_sitemap_exclude', ''))));
        $urls = [];
        $seen = [];

        $add = static function (array &$urls, array &$seen, string $title, string $url, string $section, string $lastmod = '') {
            $url = esc_url_raw($url);
            if ($url === '' || isset($seen[$url])) {
                return;
            }
            $seen[$url] = true;
            $urls[] = [
                'title' => $title !== '' ? $title : $url,
                'url' => $url,
                'section' => $section,
                'lastmod' => $lastmod,
            ];
        };

        // Home always first.
        $add($urls, $seen, __('Home', 'pylon-seo'), home_url('/'), __('Home', 'pylon-seo'), current_time('c'));

        $post_types = [];
        if (self::opt_on('pylon_html_sitemap_pages', '1')) {
            $post_types['page'] = __('Pages', 'pylon-seo');
        }
        if (self::opt_on('pylon_html_sitemap_posts', '1')) {
            $post_types['post'] = __('Posts', 'pylon-seo');
        }

        $extra_raw = trim((string) get_option('pylon_html_sitemap_cpt', ''));
        if ($extra_raw !== '') {
            foreach (array_filter(array_map('trim', explode(',', $extra_raw))) as $pt) {
                if (post_type_exists($pt) && !isset($post_types[$pt])) {
                    $obj = get_post_type_object($pt);
                    $post_types[$pt] = $obj->labels->name ?? $pt;
                }
            }
        } else {
            // Auto-include other public CPTs (property, product, etc.).
            foreach (get_post_types(['public' => true], 'objects') as $pt => $obj) {
                if (in_array($pt, ['post', 'page', 'attachment'], true)) {
                    continue;
                }
                if (!isset($post_types[$pt])) {
                    $post_types[$pt] = $obj->labels->name ?? $pt;
                }
            }
        }

        foreach ($post_types as $pt => $label) {
            $posts = get_posts([
                'post_type' => $pt,
                'post_status' => 'publish',
                'posts_per_page' => 1000,
                'orderby' => 'title',
                'order' => 'ASC',
                'exclude' => $exclude,
                'no_found_rows' => true,
            ]);
            foreach ($posts as $post) {
                if (!$include_noindex && get_post_meta($post->ID, 'pylon_noindex', true)) {
                    continue;
                }
                $permalink = get_permalink($post);
                if (!$permalink) {
                    continue;
                }
                $add(
                    $urls,
                    $seen,
                    get_the_title($post),
                    $permalink,
                    $label,
                    get_the_modified_date('c', $post) ?: ''
                );
            }
        }

        if (self::opt_on('pylon_html_sitemap_cats', '1')) {
            $terms = get_terms(['taxonomy' => 'category', 'hide_empty' => true, 'number' => 500]);
            if (!is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $link = get_term_link($term);
                    if (is_wp_error($link)) {
                        continue;
                    }
                    $add($urls, $seen, $term->name, $link, __('Categories', 'pylon-seo'));
                }
            }
        }

        if (self::opt_on('pylon_html_sitemap_tags', '0')) {
            $terms = get_terms(['taxonomy' => 'post_tag', 'hide_empty' => true, 'number' => 500]);
            if (!is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $link = get_term_link($term);
                    if (is_wp_error($link)) {
                        continue;
                    }
                    $add($urls, $seen, $term->name, $link, __('Tags', 'pylon-seo'));
                }
            }
        }

        return apply_filters('pylon/html_sitemap_urls', $urls);
    }
}
