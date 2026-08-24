<?php
namespace Pylon\Core\Modules\SeoAudit;
defined('ABSPATH') || exit;
use Pylon\Core\HttpClient;

/**
 * Pylon SEO Auditor — comprehensive per-page audit engine.
 *
 * Fetches the live rendered URL of any post/page, parses the HTML, and runs
 * 40+ checks across 6 categories (On-Page, Content, Technical, Performance,
 * Social/Schema, UX) to produce an overall 0-100 score with actionable
 * "how to fix" recommendations per check.
 */
class SeoAuditor {
    /** @var array<string,int> Category weights (must total 100). */
    private const CATEGORY_WEIGHTS = [
        'on_page'     => 25,
        'content'     => 25,
        'technical'   => 20,
        'performance' => 10,
        'social'      => 10,
        'ux'          => 10,
    ];

    /** @var array<string,string> Category labels. */
    private const CATEGORY_LABELS = [
        'on_page'     => 'On-Page Meta',
        'content'     => 'Content Quality',
        'technical'   => 'Technical SEO',
        'performance' => 'Performance',
        'social'      => 'Social & Schema',
        'ux'          => 'User Experience',
    ];

    public function register(): void {
        add_action('wp_ajax_pylon_seo_audit_run', [$this, 'ajax_run_audit']);
        add_action('wp_ajax_pylon_export_audit', [$this, 'ajax_export_audit']);
        add_action('wp_ajax_pylon_audit_history', [$this, 'ajax_audit_history']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(string $hook): void {
        if (strpos($hook, 'pylon-seo-audit') === false) return;

        wp_enqueue_style('pylon-seo-audit', PYLON_URL . 'assets/css/modules/seo-audit.css', ['pylon-admin'], filemtime(PYLON_PATH . 'assets/css/modules/seo-audit.css'));
        wp_add_inline_script('pylon-admin-js', $this->audit_js());
    }

    /* -----------------------------------------------------------------
     *  ADMIN UI
     * --------------------------------------------------------------- */

    /* -----------------------------------------------------------------
     *  SITE-WIDE OVERVIEW (charts from audit history)
     * --------------------------------------------------------------- */

    private function render_site_overview(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'pylon_audit_history';

        $history = $wpdb->get_results(
            "SELECT DATE(checked_at) AS day, ROUND(AVG(score)) AS avg_score, COUNT(*) AS audits,
                    SUM(passed) AS passed, SUM(warnings) AS warnings, SUM(failed) AS failed
             FROM {$table}
             GROUP BY DATE(checked_at)
             ORDER BY day ASC
             LIMIT 60"
        );

        if (empty($history)) {
            return;
        }

        $scores = [];
        $labels = [];
        $segments = ['pass' => 0, 'warn' => 0, 'fail' => 0];
        $total_audits = 0;
        $latest = 0;

        foreach ($history as $row) {
            $scores[] = (int) $row->avg_score;
            $labels[] = date_i18n('M j', strtotime($row->day));
            $segments['pass'] += (int) $row->passed;
            $segments['warn'] += (int) $row->warnings;
            $segments['fail'] += (int) $row->failed;
            $total_audits += (int) $row->audits;
            $latest = (int) $row->avg_score;
        }

        $trend_color = $latest >= 70 ? '#22c55e' : ($latest >= 40 ? '#f59e0b' : '#ef4444');
        $trend = \Pylon\Core\ChartRenderer::line([
            ['name' => __('Avg Score', 'pylon-seo'), 'color' => $trend_color, 'data' => $scores],
        ], [
            'width' => 520,
            'height' => 190,
            'y_min' => 0,
            'y_max' => 100,
            'y_ticks' => 4,
            'x_labels' => $labels,
            'x_label_every' => max(1, (int) (count($labels) / 8)),
            'fill' => true,
            'legend' => false,
        ]);

        $pass_total = $segments['pass'] + $segments['warn'] + $segments['fail'];
        $donut_segments = [
            ['label' => __('Passed', 'pylon-seo'), 'value' => $segments['pass'], 'color' => '#22c55e'],
            ['label' => __('Warnings', 'pylon-seo'), 'value' => $segments['warn'], 'color' => '#f59e0b'],
            ['label' => __('Failed', 'pylon-seo'), 'value' => $segments['fail'], 'color' => '#ef4444'],
        ];
        $pass_pct = $pass_total > 0 ? round($segments['pass'] / $pass_total * 100) : 0;
        $donut = \Pylon\Core\ChartRenderer::donut($donut_segments, [
            'size' => 170,
            'thickness' => 24,
            'center_value' => $pass_pct . '%',
            'center_label' => __('Passed', 'pylon-seo'),
        ]);

        $grade = $latest >= 70 ? __('Good', 'pylon-seo') : ($latest >= 40 ? __('Ok', 'pylon-seo') : __('Poor', 'pylon-seo'));
        ?>
        <div class="pylon-card pylon-mb-20">
            <div class="pylon-card-header">
                <h3>📊 <?php esc_html_e('Site Audit Overview', 'pylon-seo'); ?></h3>
                <span class="pylon-text-12 pylon-color-muted"><?php esc_html_e('Aggregated from all audits you have run', 'pylon-seo'); ?></span>
            </div>
            <div class="pylon-card-body">
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:18px;">
                    <div class="pylon-status-card">
                        <div class="pylon-status-value" style="color:<?php echo esc_attr($trend_color); ?>;"><?php echo (int) $latest; ?></div>
                        <div class="pylon-status-label"><?php esc_html_e('Latest Avg Score', 'pylon-seo'); ?></div>
                        <div class="pylon-text-11 pylon-color-muted"><?php echo esc_html($grade); ?></div>
                    </div>
                    <div class="pylon-status-card">
                        <div class="pylon-status-value"><?php echo (int) $total_audits; ?></div>
                        <div class="pylon-status-label"><?php esc_html_e('Total Audits', 'pylon-seo'); ?></div>
                        <div class="pylon-text-11 pylon-color-muted"><?php echo (int) count($history); ?><?php esc_html_e(' days tracked', 'pylon-seo'); ?></div>
                    </div>
                    <div class="pylon-status-card">
                        <div class="pylon-status-value" style="color:<?php echo $segments['fail'] > 0 ? 'var(--pylon-danger)' : 'var(--pylon-success)'; ?>;"><?php echo (int) $segments['fail']; ?></div>
                        <div class="pylon-status-label"><?php esc_html_e('Failed Checks', 'pylon-seo'); ?></div>
                        <div class="pylon-text-11 pylon-color-muted"><?php esc_html_e('across all audits', 'pylon-seo'); ?></div>
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;align-items:center;">
                    <div>
                        <h4 style="margin:0 0 8px;font-size:13px;color:#334155;"><?php esc_html_e('Average Score Trend', 'pylon-seo'); ?></h4>
                        <?php echo wp_kses($trend, \Pylon\Core\ChartRenderer::allowed_html()); ?>
                    </div>
                    <div style="text-align:center;">
                        <h4 style="margin:0 0 8px;font-size:13px;color:#334155;"><?php esc_html_e('Check Results', 'pylon-seo'); ?></h4>
                        <?php echo wp_kses($donut, \Pylon\Core\ChartRenderer::allowed_html()); ?>
                        <div style="display:inline-flex;flex-wrap:wrap;gap:12px;margin-top:10px;">
                            <?php foreach ($donut_segments as $seg): ?>
                                <span style="display:inline-flex;align-items:center;gap:5px;font-size:11px;color:#475569;">
                                    <span style="width:10px;height:10px;border-radius:3px;background:<?php echo esc_attr($seg['color']); ?>;"></span>
                                    <?php echo esc_html($seg['label']); ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_admin_page(): void {
        $posts = get_posts([
            'post_type'      => get_post_types(['public' => true]),
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'no_found_rows'  => true,
            'orderby'        => 'modified',
            'order'          => 'DESC',
        ]);
        ?>
        <div class="wrap pylon-dashboard">
            <?php \Pylon\Core\Modules\Admin\AdminEngine::page_header(__('SEO Auditor', 'pylon-seo'), '🔍'); ?>
            <p class="pylon-color-muted pylon-mb-20"><?php esc_html_e('Select any published page or post and run a full SEO audit with 40+ checks across 6 categories.', 'pylon-seo'); ?></p>

            <?php $this->render_site_overview(); ?>

            <!-- Page / Post Selector -->
            <div class="pylon-card pylon-mb-20">
                <div class="pylon-card-body">
                    <div class="pylon-flex pylon-flex-wrap pylon-gap-12 pylon-flex-center">
                        <select id="pylon-audit-page" class="pylon-select" style="min-width:380px;">
                            <option value=""><?php esc_html_e('— Select a page or post —', 'pylon-seo'); ?></option>
                            <?php foreach ($posts as $p): ?>
                                <option value="<?php echo esc_attr($p->ID); ?>">
                                    [<?php echo esc_html($p->post_type); ?>] <?php echo esc_html($p->post_title); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="url" id="pylon-audit-url" class="pylon-input" style="min-width:380px;" placeholder="<?php esc_attr_e('…or enter any URL', 'pylon-seo'); ?>">
                        <button type="button" class="pylon-btn pylon-btn-primary" id="pylon-audit-run">
                            <?php esc_html_e('Run Audit', 'pylon-seo'); ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Results container (populated by AJAX) -->
            <div id="pylon-audit-results">
                <div class="pylon-card">
                    <div class="pylon-card-body pylon-text-center pylon-color-muted" style="padding:60px;">
                        <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:0.3;margin-bottom:16px;"><path d="M9 12l2 2 4-4"/><path d="M21 12c-1 0-3-1-3-3s2-3 3-3 3 1 3 3-2 3-3 3"/><path d="M3 12c1 0 3-1 3-3S4 6 3 6 0 7 0 9s2 3 3 3"/><path d="M12 3c0 1-1 3-3 3S6 4 6 3s1-3 3-3 3 2 3 3"/><path d="M12 21c0-1-1-3-3-3s-3 2-3 3 1 3 3 3 3-2 3-3"/></svg>
                        <p><?php esc_html_e('Select a page above and click "Run Audit" to see a detailed SEO report.', 'pylon-seo'); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /* -----------------------------------------------------------------
     *  AJAX ENTRY POINT
     * --------------------------------------------------------------- */

    public function ajax_run_audit(): void {
        check_ajax_referer('pylon_seo_audit');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'pylon-seo')]);
        }

        $post_id = absint($_POST['post_id'] ?? 0);
        $raw_url = esc_url_raw(wp_unslash($_POST['url'] ?? ''));

        // Resolve URL: post_id takes priority, else use raw URL.
        if ($post_id) {
            $post = get_post($post_id);
            if (!$post) {
                wp_send_json_error(['message' => __('Post not found.', 'pylon-seo')]);
            }
            $url = get_permalink($post_id);
        } elseif ($raw_url) {
            $url = $raw_url;
            $post = null;
        } else {
            wp_send_json_error(['message' => __('Please select a page or enter a URL.', 'pylon-seo')]);
        }

        // Check for cached audit result (30 min TTL).
        $cache_key = 'pylon_audit_' . md5($url);
        $cached = get_transient($cache_key);
        if ($cached && $post_id) {
            $summary = $cached['summary'];
            $results = $cached['results'];
            wp_send_json_success([
                'summary'   => $summary,
                'results'   => $results,
                'url'       => $url,
                'title'     => $cached['title'] ?? basename($url),
                'cached'    => true,
                'html'      => $this->render_results_html($summary, $results, $url, $cached['title'] ?? '', $post_id),
            ]);
        }

        // Fetch the live rendered HTML.
        $response = HttpClient::get_json($url, [
            'timeout'     => 15,
            'redirection' => 5,
            'headers' => [
                'User-Agent' => 'Pylon-SEO-Auditor/1.0 (+https://bytenovo.com)',
            ],
        ]);

        if (!$response['success']) {
            wp_send_json_error(['message' => sprintf(
                /* translators: %s: error message */
                __('Could not fetch the page: %s', 'pylon-seo'),
                $response['error'] ?? __('Unable to retrieve page content.', 'pylon-seo')
            )]);
        }

        $html        = $response['body'] ?? '';
        $status_code = $response['code'] ?? 0;

        if ($status_code >= 400 || empty($html)) {
            wp_send_json_error(['message' => sprintf(
                /* translators: %d: HTTP status code returned by the page */
                __('Page returned HTTP %d. Make sure the page is published and publicly accessible.', 'pylon-seo'),
                $status_code
            )]);
        }

        // Run all checks.
        $context = $this->build_context($url, $html, $post);
        $results = $this->run_all_checks($context);
        $summary = $this->compute_summary($results);

        // Track usage.
        \Pylon\Core\Bootstrap::track_usage('seo_audit_run');

        // Save to audit history.
        if ($post_id) {
            global $wpdb;
            $wpdb->insert($wpdb->prefix . 'pylon_audit_history', [
                'post_id' => $post_id,
                'score' => $summary['overall'],
                'grade' => $summary['grade'],
                'total_checks' => $summary['total'],
                'passed' => $summary['passed'],
                'warnings' => $summary['warnings'],
                'failed' => $summary['failed'],
                'checked_at' => current_time('mysql'),
            ]);
        }

        // Cache the result globally (30 min TTL) and on the post.
        set_transient($cache_key, [
            'summary' => $summary,
            'results' => $results,
            'title'   => $context['title'] ?? '',
        ], 30 * MINUTE_IN_SECONDS);

        if ($post_id) {
            update_post_meta($post_id, 'pylon_audit_cache', [
                'summary' => $summary,
                'results' => $results,
                'checked_at' => current_time('mysql'),
            ]);
        }

        wp_send_json_success([
            'summary'   => $summary,
            'results'   => $results,
            'url'       => $url,
            'title'     => $context['title'] ?? basename($url),
            'cached'    => false,
            'html'      => $this->render_results_html($summary, $results, $url, $context['title'] ?? '', $post_id),
        ]);
    }

    /* -----------------------------------------------------------------
     *  EXPORT — download audit results as CSV
     * --------------------------------------------------------------- */

    public function ajax_export_audit(): void {
        check_ajax_referer('pylon_seo_audit');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'pylon-seo')]);
        }

        $post_id = absint($_POST['post_id'] ?? 0);
        if (!$post_id) {
            wp_send_json_error(['message' => __('No post specified.', 'pylon-seo')]);
        }

        $cache = get_post_meta($post_id, 'pylon_audit_cache', true);
        if (!$cache || empty($cache['results'])) {
            wp_send_json_error(['message' => __('No cached audit found. Run an audit first.', 'pylon-seo')]);
        }

        $post = get_post($post_id);
        $url = get_permalink($post_id);
        $title = $post ? $post->post_title : '';

        $lines = [];
        $lines[] = ['Pylon SEO Audit Report', '', '', ''];
        $lines[] = ['Page', $title, 'URL', $url];
        $lines[] = ['Score', $cache['summary']['overall'], 'Grade', $cache['summary']['grade']];
        $lines[] = ['Passed', $cache['summary']['passed'], 'Warnings', $cache['summary']['warnings'], 'Failed', $cache['summary']['failed']];
        $lines[] = ['Audited at', $cache['checked_at'] ?? '', '', ''];
        $lines[] = [];
        $lines[] = ['Check', 'Status', 'Category', 'Detail', 'Recommendation'];

        foreach ($cache['results'] as $r) {
            $lines[] = [
                $r['label'],
                strtoupper($r['status']),
                $r['category'],
                wp_strip_all_tags($r['message']),
                wp_strip_all_tags($r['recommendation'] ?? ''),
            ];
        }

        $csv = '';
        foreach ($lines as $row) {
            $escaped = array_map(function ($v) {
                return '"' . str_replace('"', '""', $v) . '"';
            }, $row);
            $csv .= implode(',', $escaped) . "\n";
        }

        wp_send_json_success([
            'csv' => $csv,
            'filename' => sanitize_title($title) . '-seo-audit.csv',
        ]);
    }

    /* -----------------------------------------------------------------
     *  AUDIT HISTORY — fetch score history for chart
     * --------------------------------------------------------------- */

    public function ajax_audit_history(): void {
        check_ajax_referer('pylon_seo_audit');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'pylon-seo')]);
        }

        $post_id = absint($_POST['post_id'] ?? 0);
        if (!$post_id) {
            wp_send_json_error(['message' => __('No post specified.', 'pylon-seo')]);
        }

        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT score, grade, passed, warnings, failed, total_checks, checked_at FROM {$wpdb->prefix}pylon_audit_history WHERE post_id = %d ORDER BY checked_at ASC LIMIT 50",
            $post_id
        ));

        if (empty($rows)) {
            wp_send_json_error(['message' => __('No history yet.', 'pylon-seo')]);
        }

        $labels = [];
        $scores = [];

        foreach ($rows as $r) {
            $labels[] = date_i18n('M j, H:i', strtotime($r->checked_at));
            $scores[] = (int) $r->score;
        }

        wp_send_json_success([
            'labels' => $labels,
            'scores' => $scores,
            'latest' => end($rows),
        ]);
    }

    /* -----------------------------------------------------------------
     *  CONTEXT BUILDER — parses the fetched HTML into structured data
     * --------------------------------------------------------------- */

    private function build_context(string $url, string $html, ?\WP_Post $post): array {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);

        $get = function (string $query) use ($xpath): ?string {
            $nodes = $xpath->query($query);
            return ($nodes && $nodes->length > 0) ? trim($nodes->item(0)->nodeValue) : null;
        };
        $get_all = function (string $query) use ($xpath): array {
            $nodes = $xpath->query($query);
            $out = [];
            if ($nodes) foreach ($nodes as $n) $out[] = trim($n->nodeValue);
            return $out;
        };
        $get_attr = function (string $query, string $attr) use ($xpath): ?string {
            $nodes = $xpath->query($query);
            if ($nodes && $nodes->length > 0 && $nodes->item(0)->hasAttribute($attr)) {
                return trim($nodes->item(0)->getAttribute($attr));
            }
            return null;
        };

        // Body text (stripped).
        $body_text = '';
        $body = $xpath->query('//body');
        if ($body && $body->length > 0) {
            $body_text = trim($body->item(0)->textContent);
        }

        $plain_text = wp_strip_all_tags($body_text);
        $plain_text = preg_replace('/\s+/', ' ', $plain_text);
        $word_count = $plain_text !== '' ? str_word_count($plain_text) : 0;

        // Images.
        $imgs = $xpath->query('//img');
        $images = [];
        $imgs_no_alt = 0;
        if ($imgs) foreach ($imgs as $img) {
            $alt = $img->hasAttribute('alt') ? trim($img->getAttribute('alt')) : '';
            $src = $img->hasAttribute('src') ? $img->getAttribute('src') : '';
            if ($alt === '') $imgs_no_alt++;
            $images[] = ['src' => $src, 'alt' => $alt];
        }

        // Links.
        $links = $xpath->query('//a[@href]');
        $internal_links = 0;
        $external_links = 0;
        $nofollow_links = 0;
        $site_host = wp_parse_url(home_url(), PHP_URL_HOST);
        if ($links) foreach ($links as $a) {
            $href = $a->getAttribute('href');
            $host = wp_parse_url($href, PHP_URL_HOST);
            if (!$host || $host === $site_host) {
                $internal_links++;
            } else {
                $external_links++;
                if (stripos($a->getAttribute('rel') ?: '', 'nofollow') !== false) {
                    $nofollow_links++;
                }
            }
        }

        // Scripts & styles (performance signal).
        $scripts = $xpath->query('//script[src]')->length;
        $styles  = $xpath->query('//link[@rel="stylesheet"]')->length;

        return [
            'url'            => $url,
            'html'           => $html,
            'html_size_kb'   => round(strlen($html) / 1024, 1),
            'dom'            => $dom,
            'xpath'          => $xpath,
            'title'          => $get('//title') ?: ($post->post_title ?? ''),
            'meta_desc'      => $get_attr('//meta[@name="description"]', 'content'),
            'canonical'      => $get_attr('//link[@rel="canonical"]', 'href'),
            'robots'         => $get_attr('//meta[@name="robots"]', 'content'),
            'viewport'       => $get_attr('//meta[@name="viewport"]', 'content'),
            'og_title'       => $get_attr('//meta[@property="og:title"]', 'content'),
            'og_desc'        => $get_attr('//meta[@property="og:description"]', 'content'),
            'og_image'       => $get_attr('//meta[@property="og:image"]', 'content'),
            'og_url'         => $get_attr('//meta[@property="og:url"]', 'content'),
            'tw_card'        => $get_attr('//meta[@name="twitter:card"]', 'content'),
            'tw_title'       => $get_attr('//meta[@name="twitter:title"]', 'content'),
            'tw_image'       => $get_attr('//meta[@name="twitter:image"]', 'content'),
            'h1_list'        => $get_all('//h1'),
            'h2_list'        => $get_all('//h2'),
            'h3_list'        => $get_all('//h3'),
            'schema_scripts' => $xpath->query('//script[@type="application/ld+json"]')->length,
            'lang_attr'      => $get_attr('//html', 'lang'),
            'word_count'     => $word_count,
            'plain_text'     => $plain_text,
            'images'         => $images,
            'imgs_no_alt'    => $imgs_no_alt,
            'imgs_total'     => count($images),
            'internal_links' => $internal_links,
            'external_links' => $external_links,
            'nofollow_links' => $nofollow_links,
            'scripts'        => $scripts,
            'styles'         => $styles,
            'is_https'       => strpos($url, 'https://') === 0,
            'post'           => $post,
            'focus_keyword'  => $post ? get_post_meta($post->ID, 'pylon_focus_keyword', true) : '',
        ];
    }

    /* -----------------------------------------------------------------
     *  THE 40+ CHECKS
     * --------------------------------------------------------------- */

    private function run_all_checks(array $c): array {
        return array_values(array_filter(array_merge(
            $this->checks_on_page($c),
            $this->checks_content($c),
            $this->checks_technical($c),
            $this->checks_performance($c),
            $this->checks_social($c),
            $this->checks_ux($c)
        )));
    }

    /* ---- ON-PAGE META ---- */
    private function checks_on_page(array $c): array {
        $checks = [];

        // 1. Title exists.
        $title = $c['title'] ?? '';
        $checks[] = $title !== ''
            ? $this->pass('title_exists', 'on_page', __('Title tag present', 'pylon-seo'), __('A title tag was found.', 'pylon-seo'))
            : $this->fail('title_exists', 'on_page', __('Title tag missing', 'pylon-seo'), __('No &lt;title&gt; tag detected on the page.', 'pylon-seo'), __('Add a descriptive title tag between 50–60 characters.', 'pylon-seo'));

        // 2. Title length.
        $tlen = strlen($title);
        if ($tlen > 0) {
            if ($tlen >= 30 && $tlen <= 60) {
                $checks[] = $this->pass(
                    'title_length',
                    'on_page',
                    __('Title length optimal', 'pylon-seo'),
                    sprintf(
                        /* translators: %d: title length in characters */
                        __('Title is %d characters.', 'pylon-seo'),
                        $tlen
                    )
                );
            } elseif ($tlen > 60) {
                $checks[] = $this->warn(
                    'title_length',
                    'on_page',
                    __('Title too long', 'pylon-seo'),
                    sprintf(
                        /* translators: %d: title length in characters */
                        __('Title is %d characters — may get truncated in search results.', 'pylon-seo'),
                        $tlen
                    ),
                    __('Shorten the title to 50–60 characters.', 'pylon-seo')
                );
            } else {
                $checks[] = $this->warn(
                    'title_length',
                    'on_page',
                    __('Title too short', 'pylon-seo'),
                    sprintf(
                        /* translators: %d: title length in characters */
                        __('Title is only %d characters.', 'pylon-seo'),
                        $tlen
                    ),
                    __('Expand the title to at least 50 characters for better relevance.', 'pylon-seo')
                );
            }
        }

        // 3. Meta description exists.
        $desc = $c['meta_desc'] ?? '';
        $checks[] = !empty($desc)
            ? $this->pass('desc_exists', 'on_page', __('Meta description present', 'pylon-seo'), __('A meta description was found.', 'pylon-seo'))
            : $this->fail('desc_exists', 'on_page', __('Meta description missing', 'pylon-seo'), __('No meta description tag detected.', 'pylon-seo'), __('Add a compelling meta description between 120–160 characters.', 'pylon-seo'));

        // 4. Meta description length.
        $dlen = strlen($desc);
        if ($dlen > 0) {
            if ($dlen >= 70 && $dlen <= 160) {
                $checks[] = $this->pass(
                    'desc_length',
                    'on_page',
                    __('Description length optimal', 'pylon-seo'),
                    sprintf(
                        /* translators: %d: meta description length in characters */
                        __('Description is %d characters.', 'pylon-seo'),
                        $dlen
                    )
                );
            } elseif ($dlen > 160) {
                $checks[] = $this->warn(
                    'desc_length',
                    'on_page',
                    __('Description too long', 'pylon-seo'),
                    sprintf(
                        /* translators: %d: meta description length in characters */
                        __('Description is %d characters — may get truncated.', 'pylon-seo'),
                        $dlen
                    ),
                    __('Trim to under 160 characters.', 'pylon-seo')
                );
            } else {
                $checks[] = $this->warn(
                    'desc_length',
                    'on_page',
                    __('Description too short', 'pylon-seo'),
                    sprintf(
                        /* translators: %d: meta description length in characters */
                        __('Description is only %d characters.', 'pylon-seo'),
                        $dlen
                    ),
                    __('Expand to 120–160 characters.', 'pylon-seo')
                );
            }
        }

        // 5. Canonical URL.
        $checks[] = !empty($c['canonical'])
            ? $this->pass('canonical', 'on_page', __('Canonical URL set', 'pylon-seo'), $c['canonical'])
            : $this->warn('canonical', 'on_page', __('No canonical URL', 'pylon-seo'), __('No canonical link tag found.', 'pylon-seo'), __('Add a canonical URL to prevent duplicate-content issues.', 'pylon-seo'));

        // 6. Focus keyword in title.
        $kw = $c['focus_keyword'] ?? '';
        if ($kw) {
            $checks[] = stripos($title, $kw) !== false
                ? $this->pass(
                    'kw_in_title',
                    'on_page',
                    __('Keyword in title', 'pylon-seo'),
                    sprintf(
                        /* translators: %s: focus keyword */
                        __('Focus keyword "%s" found in title.', 'pylon-seo'),
                        $kw
                    )
                )
                : $this->fail(
                    'kw_in_title',
                    'on_page',
                    __('Keyword not in title', 'pylon-seo'),
                    sprintf(
                        /* translators: %s: focus keyword */
                        __('Focus keyword "%s" missing from title.', 'pylon-seo'),
                        $kw
                    ),
                    __('Include your focus keyword near the start of the title.', 'pylon-seo')
                );
        }

        // 7. Focus keyword in description.
        if ($kw && $desc) {
            $checks[] = stripos($desc, $kw) !== false
                ? $this->pass('kw_in_desc', 'on_page', __('Keyword in description', 'pylon-seo'), __('Focus keyword found in meta description.', 'pylon-seo'))
                : $this->warn('kw_in_desc', 'on_page', __('Keyword not in description', 'pylon-seo'), __('Focus keyword missing from meta description.', 'pylon-seo'), __('Naturally include your keyword in the description.', 'pylon-seo'));
        }

        // 8. Keyword in URL slug.
        if ($kw) {
            $slug = basename(wp_parse_url($c['url'], PHP_URL_PATH));
            $checks[] = stripos($slug, str_replace(' ', '-', strtolower($kw))) !== false || stripos($slug, strtolower($kw)) !== false
                ? $this->pass('kw_in_url', 'on_page', __('Keyword in URL', 'pylon-seo'), __('Focus keyword found in the URL slug.', 'pylon-seo'))
                : $this->warn('kw_in_url', 'on_page', __('Keyword not in URL', 'pylon-seo'), __('Focus keyword missing from the URL slug.', 'pylon-seo'), __('Include the keyword in the permalink slug.', 'pylon-seo'));
        }

        return $checks;
    }

    /* ---- CONTENT QUALITY ---- */
    private function checks_content(array $c): array {
        $checks = [];
        $wc = $c['word_count'] ?? 0;

        // 9. Word count.
        if ($wc >= 300) {
            $checks[] = $this->pass(
                'word_count',
                'content',
                __('Sufficient content', 'pylon-seo'),
                sprintf(
                    /* translators: %d: total word count */
                    __('%d words — good content depth.', 'pylon-seo'),
                    $wc
                )
            );
        } elseif ($wc > 0) {
            $checks[] = $this->warn(
                'word_count',
                'content',
                __('Thin content', 'pylon-seo'),
                sprintf(
                    /* translators: %d: total word count */
                    __('Only %d words. Aim for 300+.', 'pylon-seo'),
                    $wc
                ),
                __('Expand the content to at least 300 words.', 'pylon-seo')
            );
        } else {
            $checks[] = $this->fail('word_count', 'content', __('Empty content', 'pylon-seo'), __('No readable text content found.', 'pylon-seo'), __('Add substantive text content.', 'pylon-seo'));
        }

        // 10. Exactly one H1.
        $h1_count = count($c['h1_list'] ?? []);
        if ($h1_count === 1) {
            $checks[] = $this->pass('h1_count', 'content', __('Single H1 heading', 'pylon-seo'), $c['h1_list'][0]);
        } elseif ($h1_count === 0) {
            $checks[] = $this->fail('h1_count', 'content', __('No H1 heading', 'pylon-seo'), __('The page has no &lt;h1&gt; tag.', 'pylon-seo'), __('Add exactly one H1 containing your primary keyword.', 'pylon-seo'));
        } else {
            $checks[] = $this->warn(
                'h1_count',
                'content',
                __('Multiple H1 headings', 'pylon-seo'),
                sprintf(
                    /* translators: %d: number of H1 headings found */
                    __('Found %d H1 tags — use only one.', 'pylon-seo'),
                    $h1_count
                ),
                __('Keep a single H1 per page; demote extras to H2.', 'pylon-seo')
            );
        }

        // 11. H2 subheadings.
        $h2_count = count($c['h2_list'] ?? []);
        if ($h2_count >= 2) {
            $checks[] = $this->pass(
                'h2_count',
                'content',
                __('Good heading structure', 'pylon-seo'),
                sprintf(
                    /* translators: %d: number of H2 subheadings found */
                    __('%d H2 subheadings found.', 'pylon-seo'),
                    $h2_count
                )
            );
        } elseif ($wc > 300) {
            $checks[] = $this->warn(
                'h2_count',
                'content',
                __('Few subheadings', 'pylon-seo'),
                sprintf(
                    /* translators: 1: number of H2 subheadings, 2: total word count */
                    __('Only %1$d H2 subheadings for %2$d words.', 'pylon-seo'),
                    $h2_count,
                    $wc
                ),
                __('Break content into sections with H2/H3 headings.', 'pylon-seo')
            );
        } else {
            $checks[] = $this->warn('h2_count', 'content', __('No subheadings', 'pylon-seo'), __('No H2 subheadings detected.', 'pylon-seo'), __('Add H2 subheadings to structure your content.', 'pylon-seo'));
        }

        // 12. Image alt text.
        $no_alt = $c['imgs_no_alt'] ?? 0;
        $total_img = $c['imgs_total'] ?? 0;
        if ($total_img === 0) {
            $checks[] = $this->warn('img_alt', 'content', __('No images', 'pylon-seo'), __('The page contains no images.', 'pylon-seo'), __('Add relevant images to improve engagement.', 'pylon-seo'));
        } elseif ($no_alt === 0) {
            $checks[] = $this->pass(
                'img_alt',
                'content',
                __('All images have alt text', 'pylon-seo'),
                sprintf(
                    /* translators: %d: total number of images */
                    __('All %d images have alt attributes.', 'pylon-seo'),
                    $total_img
                )
            );
        } else {
            $checks[] = $this->fail(
                'img_alt',
                'content',
                __('Images missing alt text', 'pylon-seo'),
                sprintf(
                    /* translators: 1: number of images without alt text, 2: total number of images */
                    __('%1$d of %2$d images lack alt text.', 'pylon-seo'),
                    $no_alt,
                    $total_img
                ),
                __('Add descriptive alt text to every image.', 'pylon-seo')
            );
        }

        // 13. Internal links.
        $int = $c['internal_links'] ?? 0;
        if ($int >= 3) {
            $checks[] = $this->pass(
                'internal_links',
                'content',
                __('Good internal linking', 'pylon-seo'),
                sprintf(
                    /* translators: %d: number of internal links */
                    __('%d internal links found.', 'pylon-seo'),
                    $int
                )
            );
        } elseif ($int > 0) {
            $checks[] = $this->warn(
                'internal_links',
                'content',
                __('Few internal links', 'pylon-seo'),
                sprintf(
                    /* translators: %d: number of internal links */
                    __('Only %d internal links.', 'pylon-seo'),
                    $int
                ),
                __('Add more internal links to related content.', 'pylon-seo')
            );
        } else {
            $checks[] = $this->fail('internal_links', 'content', __('No internal links', 'pylon-seo'), __('The page links to no other pages on your site.', 'pylon-seo'), __('Add internal links to improve crawlability and engagement.', 'pylon-seo'));
        }

        // 14. External links.
        $ext = $c['external_links'] ?? 0;
        if ($ext >= 1) {
            $checks[] = $this->pass(
                'external_links',
                'content',
                __('External links present', 'pylon-seo'),
                sprintf(
                    /* translators: %d: number of external links */
                    __('%d external links found.', 'pylon-seo'),
                    $ext
                )
            );
        } else {
            $checks[] = $this->warn('external_links', 'content', __('No external links', 'pylon-seo'), __('The page has no outbound links.', 'pylon-seo'), __('Link to authoritative sources to build trust.', 'pylon-seo'));
        }

        // 15. Keyword in first paragraph / content.
        $kw = $c['focus_keyword'] ?? '';
        if ($kw && $wc > 0) {
            $checks[] = stripos($c['plain_text'], $kw) !== false
                ? $this->pass('kw_in_content', 'content', __('Keyword in content', 'pylon-seo'), __('Focus keyword found in the body text.', 'pylon-seo'))
                : $this->fail('kw_in_content', 'content', __('Keyword not in content', 'pylon-seo'), __('Focus keyword missing from body text.', 'pylon-seo'), __('Naturally use your keyword in the content.', 'pylon-seo'));
        }

        // 16. Keyword density.
        if ($kw && $wc >= 100) {
            $count = substr_count(strtolower($c['plain_text']), strtolower($kw));
            $density = $wc > 0 ? ($count / $wc) * 100 : 0;
            if ($density >= 0.5 && $density <= 2.5) {
                $checks[] = $this->pass(
                    'kw_density',
                    'content',
                    __('Healthy keyword density', 'pylon-seo'),
                    sprintf(
                        /* translators: 1: keyword density percentage, 2: number of keyword mentions */
                        __('%1$.1f%% density (%2$d mentions).', 'pylon-seo'),
                        $density,
                        $count
                    )
                );
            } elseif ($density > 2.5) {
                $checks[] = $this->warn(
                    'kw_density',
                    'content',
                    __('Keyword stuffing risk', 'pylon-seo'),
                    sprintf(
                        /* translators: %.1f: keyword density percentage */
                        __('%.1f%% density is high — may look spammy.', 'pylon-seo'),
                        $density
                    ),
                    __('Reduce keyword frequency; use synonyms and related terms.', 'pylon-seo')
                );
            } else {
                $checks[] = $this->warn(
                    'kw_density',
                    'content',
                    __('Low keyword usage', 'pylon-seo'),
                    sprintf(
                        /* translators: %.1f: keyword density percentage */
                        __('%.1f%% density — keyword used rarely.', 'pylon-seo'),
                        $density
                    ),
                    __('Use the keyword a few more times naturally.', 'pylon-seo')
                );
            }
        }

        // 17. Readability — Flesch Reading Ease + Flesch-Kincaid Grade Level.
        if ($wc >= 100) {
            $sentences = preg_match_all('/[.!?]+/', $c['plain_text']) ?: 1;
            $syllables = $this->count_syllables($c['plain_text']);
            $avg_words_per_sent = $wc / $sentences;
            $avg_syll_per_word = $syllables / $wc;
            $flesch = 206.835 - (1.015 * $avg_words_per_sent) - (84.6 * $avg_syll_per_word);
            $flesch = max(0, min(100, round($flesch, 1)));
            $grade = (0.39 * $avg_words_per_sent) + (11.8 * $avg_syll_per_word) - 15.59;
            $grade = max(1, round($grade, 1));

            // Readability interpretation
            $level = '';
            $color = '';
            if ($flesch >= 90) { $level = __('Very Easy', 'pylon-seo'); $color = '#16a34a'; }
            elseif ($flesch >= 80) { $level = __('Easy', 'pylon-seo'); $color = '#16a34a'; }
            elseif ($flesch >= 70) { $level = __('Fairly Easy', 'pylon-seo'); $color = '#22c55e'; }
            elseif ($flesch >= 60) { $level = __('Standard', 'pylon-seo'); $color = '#22c55e'; }
            elseif ($flesch >= 50) { $level = __('Fairly Difficult', 'pylon-seo'); $color = '#f59e0b'; }
            elseif ($flesch >= 30) { $level = __('Difficult', 'pylon-seo'); $color = '#f97316'; }
            else { $level = __('Very Difficult', 'pylon-seo'); $color = '#dc2626'; }

            $message = sprintf(
                /* translators: 1: Flesch score, 2: grade level, 3: interpretation, 4: words, 5: sentences, 6: words per sentence, 7: syllables per word */
                __('Flesch: %1$s (Grade %2$s, %3$s) — %4$d words, %5$d sentences, %6$.1f w/sent, %7$.2f syl/word', 'pylon-seo'),
                $flesch, $grade, $level, $wc, $sentences, $avg_words_per_sent, $avg_syll_per_word
            );

            if ($flesch >= 60) {
                $checks[] = $this->pass('readability', 'content', __('Good readability', 'pylon-seo'), $message);
            } elseif ($flesch >= 30) {
                $checks[] = $this->warn('readability', 'content', __('Moderate readability', 'pylon-seo'), $message, __('Shorten sentences and use simpler words.', 'pylon-seo'));
            } else {
                $checks[] = $this->warn('readability', 'content', __('Difficult readability', 'pylon-seo'), $message, __('Simplify sentences and vocabulary.', 'pylon-seo'));
            }
        }

        // 17b. Image filename SEO (hyphens vs underscores/random strings).
        $bad_filenames = 0;
        foreach ($c['images'] as $img) {
            $src = $img['src'] ?? '';
            if (!$src) continue;
            $filename = basename(wp_parse_url($src, PHP_URL_PATH));
            $filename = urldecode($filename);
            if (preg_match('/[_.\s]{2,}/', $filename) || preg_match('/^[a-f0-9]{10,}\./i', $filename) || preg_match('/^[a-z]{1,3}\d{4,10}\./i', $filename)) {
                $bad_filenames++;
            } elseif (strpos($filename, '_') !== false && strpos($filename, '-') === false) {
                $bad_filenames++;
            }
        }
        $total_img = $c['imgs_total'] ?? 0;
        if ($total_img > 0) {
            if ($bad_filenames === 0) {
                $checks[] = $this->pass('img_filenames', 'content', __('SEO-friendly image filenames', 'pylon-seo'), __('All images use descriptive filenames with hyphens.', 'pylon-seo'));
            } else {
                $checks[] = $this->warn(
                    'img_filenames',
                    'content',
                    __('Poor image filenames', 'pylon-seo'),
                    sprintf(
                        /* translators: 1: number of images with poor filenames, 2: total number of images */
                        __('%1$d of %2$d images have auto-generated or underscore filenames.', 'pylon-seo'),
                        $bad_filenames,
                        $total_img
                    ),
                    __('Rename images using descriptive, hyphen-separated filenames (e.g. blue-widget.jpg).', 'pylon-seo')
                );
            }
        }

        // 17c. Content freshness.
        if ($c['post'] instanceof \WP_Post) {
            $modified = strtotime($c['post']->post_modified);
            $days_since = (int) floor((current_time('timestamp') - $modified) / DAY_IN_SECONDS);
            if ($days_since <= 30) {
                $checks[] = $this->pass(
                    'freshness',
                    'content',
                    __('Content is fresh', 'pylon-seo'),
                    sprintf(
                        /* translators: %d: number of days since last update */
                        __('Updated %d days ago.', 'pylon-seo'),
                        $days_since
                    )
                );
            } elseif ($days_since <= 180) {
                $checks[] = $this->warn(
                    'freshness',
                    'content',
                    __('Content aging', 'pylon-seo'),
                    sprintf(
                        /* translators: %d: number of days since last update */
                        __('%d days since last update.', 'pylon-seo'),
                        $days_since
                    ),
                    __('Consider reviewing and updating the content for freshness signals.', 'pylon-seo')
                );
            } else {
                $checks[] = $this->fail(
                    'freshness',
                    'content',
                    __('Stale content', 'pylon-seo'),
                    sprintf(
                        /* translators: %d: number of days since last update */
                        __('%d days since last update — very stale.', 'pylon-seo'),
                        $days_since
                    ),
                    __('Rewrite or substantially update the content to improve freshness signals.', 'pylon-seo')
                );
            }
        }

        // 17d. Internal link anchor quality.
        $descriptive = 0;
        $total_links = $c['internal_links'] ?? 0;
        if ($total_links > 0) {
            $links = $c['xpath']->query('//a[@href]');
            $site_host = wp_parse_url(home_url(), PHP_URL_HOST);
            if ($links) foreach ($links as $a) {
                $href = $a->getAttribute('href');
                $host = wp_parse_url($href, PHP_URL_HOST);
                $anchor = trim($a->textContent);
                if ((!$host || $host === $site_host) && strlen($anchor) >= 4 && stripos($anchor, 'click here') === false && stripos($anchor, 'read more') === false && stripos($anchor, 'this page') === false) {
                    $descriptive++;
                }
            }
            $pct_good = $total_links > 0 ? ($descriptive / $total_links) * 100 : 0;
            if ($pct_good >= 70) {
                $checks[] = $this->pass(
                    'link_anchors',
                    'content',
                    __('Descriptive link text', 'pylon-seo'),
                    sprintf(
                        /* translators: %d: percentage of internal links with descriptive anchors */
                        __('%d%% of internal links use descriptive anchor text.', 'pylon-seo'),
                        (int) $pct_good
                    )
                );
            } else {
                $checks[] = $this->warn(
                    'link_anchors',
                    'content',
                    __('Weak link anchor text', 'pylon-seo'),
                    sprintf(
                        /* translators: %d: percentage of internal links with descriptive anchors */
                        __('%d%% of internal links use descriptive anchors.', 'pylon-seo'),
                        (int) $pct_good
                    ),
                    __('Use descriptive anchor text (avoid "click here" or "read more").', 'pylon-seo')
                );
            }
        }

        // 17e. H1 contains keyword.
        $kw = $c['focus_keyword'] ?? '';
        $h1_text = implode(' ', $c['h1_list'] ?? []);
        if ($kw && $h1_text) {
            $checks[] = stripos($h1_text, $kw) !== false
                ? $this->pass(
                    'kw_in_h1',
                    'content',
                    __('Keyword in H1 heading', 'pylon-seo'),
                    sprintf(
                        /* translators: %s: focus keyword */
                        __('Focus keyword found in the H1: "%s".', 'pylon-seo'),
                        mb_substr($h1_text, 0, 50)
                    )
                )
                : $this->warn('kw_in_h1', 'content', __('Keyword not in H1', 'pylon-seo'), __('Focus keyword missing from the H1 heading.', 'pylon-seo'), __('Include the focus keyword in the H1 heading naturally.', 'pylon-seo'));
        }

        // 41. Heading hierarchy (no level skipping).
        $h1 = count($c['h1_list'] ?? []);
        $h2 = count($c['h2_list'] ?? []);
        $h3 = count($c['h3_list'] ?? []);
        if ($h1 > 0 && $h2 > 0) {
            $checks[] = $this->pass('heading_hierarchy', 'content', __('Heading hierarchy correct', 'pylon-seo'), __('Headings follow a proper h1 → h2 structure.', 'pylon-seo'));
        } elseif ($h1 === 0 && $h2 === 0 && $h3 === 0) {
            $checks[] = $this->warn('heading_hierarchy', 'content', __('No headings found', 'pylon-seo'), __('The page has no heading tags at all.', 'pylon-seo'), __('Add at least an H1 and H2 headings to structure the page.', 'pylon-seo'));
        } elseif ($h1 > 0 && $h2 === 0 && $h3 > 0) {
            $checks[] = $this->warn('heading_hierarchy', 'content', __('Heading level skipped', 'pylon-seo'), __('H1 → H3 without H2 subheadings.', 'pylon-seo'), __('Insert H2 subheadings between H1 and H3 headings.', 'pylon-seo'));
        } else {
            $checks[] = $this->warn(
                'heading_hierarchy',
                'content',
                __('Sparse heading structure', 'pylon-seo'),
                sprintf(
                    /* translators: 1: number of H1 headings, 2: number of H2 headings, 3: number of H3 headings */
                    __('H1: %1$d, H2: %2$d, H3: %3$d — improve hierarchy.', 'pylon-seo'),
                    $h1,
                    $h2,
                    $h3
                ),
                __('Use headings in descending order without skipping levels.', 'pylon-seo')
            );
        }

        // 42. External link quality (nofollow ratio).
        $ext = $c['external_links'] ?? 0;
        $nofollow = $c['nofollow_links'] ?? 0;
        if ($ext > 0) {
            $nofl_pct = round(($nofollow / $ext) * 100);
            if ($nofl_pct <= 30) {
                $checks[] = $this->pass(
                    'ext_link_quality',
                    'content',
                    __('Healthy external link profile', 'pylon-seo'),
                    sprintf(
                        /* translators: 1: percentage of nofollow external links, 2: total number of external links */
                        __('%1$d%% of %2$d external links use nofollow.', 'pylon-seo'),
                        $nofl_pct,
                        $ext
                    )
                );
            } elseif ($nofl_pct <= 70) {
                $checks[] = $this->warn(
                    'ext_link_quality',
                    'content',
                    __('Mixed external link quality', 'pylon-seo'),
                    sprintf(
                        /* translators: 1: percentage of nofollow external links, 2: total number of external links */
                        __('%1$d%% of %2$d external links are nofollow.', 'pylon-seo'),
                        $nofl_pct,
                        $ext
                    ),
                    __('Use nofollow sparingly — only for untrusted or paid links.', 'pylon-seo')
                );
            } else {
                $checks[] = $this->warn(
                    'ext_link_quality',
                    'content',
                    __('Mostly nofollow external links', 'pylon-seo'),
                    sprintf(
                        /* translators: 1: percentage of nofollow external links, 2: total number of external links */
                        __('%1$d%% of %2$d external links use nofollow.', 'pylon-seo'),
                        $nofl_pct,
                        $ext
                    ),
                    __('Excessive nofollow may waste link equity; use dofollow for trusted sources.', 'pylon-seo')
                );
            }
        }

        return $checks;
    }

    /* ---- TECHNICAL SEO ---- */
    private function checks_technical(array $c): array {
        $checks = [];

        // 18. HTTPS.
        $checks[] = $c['is_https']
            ? $this->pass('https', 'technical', __('Secure HTTPS connection', 'pylon-seo'), __('Page is served over HTTPS.', 'pylon-seo'))
            : $this->fail('https', 'technical', __('Insecure HTTP', 'pylon-seo'), __('Page is served over HTTP, not HTTPS.', 'pylon-seo'), __('Install an SSL certificate and force HTTPS.', 'pylon-seo'));

        // 19. Viewport / mobile.
        $checks[] = !empty($c['viewport'])
            ? $this->pass('viewport', 'technical', __('Mobile-friendly viewport', 'pylon-seo'), __('Viewport meta tag is set.', 'pylon-seo'))
            : $this->fail('viewport', 'technical', __('No viewport tag', 'pylon-seo'), __('Missing mobile viewport meta tag.', 'pylon-seo'), __('Add: &lt;meta name="viewport" content="width=device-width, initial-scale=1"&gt;.', 'pylon-seo'));

        // 20. Robots indexable.
        $robots = strtolower($c['robots'] ?? '');
        if (strpos($robots, 'noindex') !== false) {
            $checks[] = $this->warn('robots', 'technical', __('Page blocked from indexing', 'pylon-seo'), __('robots meta tag contains "noindex".', 'pylon-seo'), __('Remove noindex if you want this page in search results.', 'pylon-seo'));
        } else {
            $checks[] = $this->pass('robots', 'technical', __('Page is indexable', 'pylon-seo'), __('No noindex directive found.', 'pylon-seo'));
        }

        // 21. URL length.
        $url_len = strlen($c['url']);
        if ($url_len <= 75) {
            $checks[] = $this->pass(
                'url_length',
                'technical',
                __('URL length is fine', 'pylon-seo'),
                sprintf(
                    /* translators: %d: URL length in characters */
                    __('%d characters.', 'pylon-seo'),
                    $url_len
                )
            );
        } else {
            $checks[] = $this->warn(
                'url_length',
                'technical',
                __('URL too long', 'pylon-seo'),
                sprintf(
                    /* translators: %d: URL length in characters */
                    __('%d characters — keep URLs under 75.', 'pylon-seo'),
                    $url_len
                ),
                __('Shorten the URL slug.', 'pylon-seo')
            );
        }

        // 22. URL has no underscores (hyphens preferred).
        $slug = basename(wp_parse_url($c['url'], PHP_URL_PATH));
        $checks[] = strpos($slug, '_') === false
            ? $this->pass('url_hyphens', 'technical', __('URL uses hyphens', 'pylon-seo'), __('URL slug uses hyphens, not underscores.', 'pylon-seo'))
            : $this->warn('url_hyphens', 'technical', __('URL uses underscores', 'pylon-seo'), __('Underscores in URLs are not word separators to Google.', 'pylon-seo'), __('Replace underscores with hyphens in the slug.', 'pylon-seo'));

        // 23. Schema / structured data.
        $schema = $c['schema_scripts'] ?? 0;
        if ($schema >= 1) {
            $checks[] = $this->pass(
                'schema',
                'technical',
                __('Structured data present', 'pylon-seo'),
                sprintf(
                    /* translators: %d: number of JSON-LD schema blocks found */
                    __('%d JSON-LD schema block(s) found.', 'pylon-seo'),
                    $schema
                )
            );
        } else {
            $checks[] = $this->warn('schema', 'technical', __('No structured data', 'pylon-seo'), __('No JSON-LD schema markup detected.', 'pylon-seo'), __('Add schema.org markup (Article, FAQ, etc.) for rich results.', 'pylon-seo'));
        }

        // 24. HTML lang attribute.
        $checks[] = !empty($c['lang_attr'])
            ? $this->pass(
                'lang',
                'technical',
                __('Language declared', 'pylon-seo'),
                sprintf(
                    /* translators: %s: HTML lang attribute value */
                    __('html lang="%s".', 'pylon-seo'),
                    esc_html($c['lang_attr'])
                )
            )
            : $this->warn('lang', 'technical', __('No language attribute', 'pylon-seo'), __('No lang attribute on &lt;html&gt;.', 'pylon-seo'), __('Add a lang attribute, e.g. lang="en".', 'pylon-seo'));

        // 45. SEO permalink (no dates or numeric segments).
        $slug = basename(wp_parse_url($c['url'], PHP_URL_PATH));
        $path_segments = array_filter(explode('/', wp_parse_url($c['url'], PHP_URL_PATH)));
        $has_date = preg_match('/\b(19|20)\d{2}\b/', implode(' ', $path_segments));
        $has_numeric = preg_match('/^\d/', $slug);
        if ($slug && !$has_date && !$has_numeric) {
            $checks[] = $this->pass(
                'permalink',
                'technical',
                __('Clean permalink', 'pylon-seo'),
                sprintf(
                    /* translators: %s: URL slug */
                    __('Slug: %s — no dates or numeric IDs.', 'pylon-seo'),
                    $slug
                )
            );
        } elseif ($has_date) {
            $checks[] = $this->warn('permalink', 'technical', __('Date in URL', 'pylon-seo'), __('URL contains date segments (e.g. /2024/.../) which can hurt evergreen rankings.', 'pylon-seo'), __('Remove date-based URL structure; use a numeric-less slug instead.', 'pylon-seo'));
        } else {
            $checks[] = $this->warn(
                'permalink',
                'technical',
                __('Numeric slug', 'pylon-seo'),
                sprintf(
                    /* translators: %s: URL slug */
                    __('Slug "%s" starts with a number or is purely numeric.', 'pylon-seo'),
                    $slug
                ),
                __('Use a descriptive, keyword-rich slug without numeric prefixes.', 'pylon-seo')
            );
        }

        // 25. No broken internal anchor (href="#" or empty).
        $empty_anchors = $c['xpath']->query('//a[@href="#"]')->length + $c['xpath']->query('//a[not(@href) or @href=""]')->length;
        if ($empty_anchors === 0) {
            $checks[] = $this->pass('anchors', 'technical', __('No empty anchors', 'pylon-seo'), __('All links have valid hrefs.', 'pylon-seo'));
        } else {
            $checks[] = $this->warn(
                'anchors',
                'technical',
                __('Empty link anchors', 'pylon-seo'),
                sprintf(
                    /* translators: %d: number of empty links */
                    __('%d links point to "#" or have no href.', 'pylon-seo'),
                    $empty_anchors
                ),
                __('Fix or remove placeholder links.', 'pylon-seo')
            );
        }

        return $checks;
    }

    /* ---- PERFORMANCE ---- */
    private function checks_performance(array $c): array {
        $checks = [];
        $size = $c['html_size_kb'] ?? 0;

        // 26. HTML size.
        if ($size <= 100) {
            $checks[] = $this->pass(
                'html_size',
                'performance',
                __('Lean HTML', 'pylon-seo'),
                sprintf(
                    /* translators: %s: HTML size in kilobytes */
                    __('HTML is %s KB.', 'pylon-seo'),
                    $size
                )
            );
        } elseif ($size <= 300) {
            $checks[] = $this->warn(
                'html_size',
                'performance',
                __('Large HTML', 'pylon-seo'),
                sprintf(
                    /* translators: %s: HTML size in kilobytes */
                    __('HTML is %s KB.', 'pylon-seo'),
                    $size
                ),
                __('Reduce inline styles/scripts and unused markup.', 'pylon-seo')
            );
        } else {
            $checks[] = $this->fail(
                'html_size',
                'performance',
                __('Bloated HTML', 'pylon-seo'),
                sprintf(
                    /* translators: %s: HTML size in kilobytes */
                    __('HTML is %s KB — very heavy.', 'pylon-seo'),
                    $size
                ),
                __('Minify HTML, remove unused code, lazy-load content.', 'pylon-seo')
            );
        }

        // 27. Render-blocking scripts.
        $scripts = $c['scripts'] ?? 0;
        if ($scripts <= 5) {
            $checks[] = $this->pass(
                'scripts',
                'performance',
                __('Few external scripts', 'pylon-seo'),
                sprintf(
                    /* translators: %d: number of external scripts */
                    __('%d external script(s).', 'pylon-seo'),
                    $scripts
                )
            );
        } elseif ($scripts <= 15) {
            $checks[] = $this->warn(
                'scripts',
                'performance',
                __('Many scripts', 'pylon-seo'),
                sprintf(
                    /* translators: %d: number of external scripts */
                    __('%d external scripts may slow rendering.', 'pylon-seo'),
                    $scripts
                ),
                __('Defer or async non-critical scripts.', 'pylon-seo')
            );
        } else {
            $checks[] = $this->fail(
                'scripts',
                'performance',
                __('Too many scripts', 'pylon-seo'),
                sprintf(
                    /* translators: %d: number of external scripts */
                    __('%d external scripts — heavy payload.', 'pylon-seo'),
                    $scripts
                ),
                __('Remove unused scripts; defer the rest.', 'pylon-seo')
            );
        }

        // 28. Stylesheets.
        $styles = $c['styles'] ?? 0;
        if ($styles <= 3) {
            $checks[] = $this->pass(
                'styles',
                'performance',
                __('Few stylesheets', 'pylon-seo'),
                sprintf(
                    /* translators: %d: number of stylesheets */
                    __('%d stylesheet(s).', 'pylon-seo'),
                    $styles
                )
            );
        } else {
            $checks[] = $this->warn(
                'styles',
                'performance',
                __('Many stylesheets', 'pylon-seo'),
                sprintf(
                    /* translators: %d: number of stylesheets */
                    __('%d stylesheets — consider combining.', 'pylon-seo'),
                    $styles
                ),
                __('Combine and minify CSS files.', 'pylon-seo')
            );
        }

        // 29. Image count (rough weight proxy).
        $imgs = $c['imgs_total'] ?? 0;
        if ($imgs <= 20) {
            $checks[] = $this->pass(
                'img_count',
                'performance',
                __('Reasonable image count', 'pylon-seo'),
                sprintf(
                    /* translators: %d: number of images */
                    __('%d images.', 'pylon-seo'),
                    $imgs
                )
            );
        } else {
            $checks[] = $this->warn(
                'img_count',
                'performance',
                __('Many images', 'pylon-seo'),
                sprintf(
                    /* translators: %d: number of images */
                    __('%d images — ensure they are optimized and lazy-loaded.', 'pylon-seo'),
                    $imgs
                ),
                __('Compress images and add loading="lazy".', 'pylon-seo')
            );
        }

        // 30. Compression hint (size vs scripts).
        if ($size > 200 && $scripts > 10) {
            $checks[] = $this->warn('compression', 'performance', __('Enable compression', 'pylon-seo'), __('Large page with many assets — ensure gzip/Brotli is enabled.', 'pylon-seo'), __('Enable gzip/Brotli compression at the server level.', 'pylon-seo'));
        } else {
            $checks[] = $this->pass('compression', 'performance', __('Page payload is reasonable', 'pylon-seo'), __('No major compression red flags.', 'pylon-seo'));
        }

        // 43. Image dimensions (width/height attributes).
        $imgs_dim_missing = 0;
        if (!empty($c['images'])) {
            foreach ($c['images'] as $img) {
                if (!empty($img['src'])) {
                    $img_node = $c['xpath']->query("//img[@src='" . addslashes($img['src']) . "']");
                    if ($img_node && $img_node->length > 0) {
                        if (!$img_node->item(0)->hasAttribute('width') && !$img_node->item(0)->hasAttribute('height')) {
                            $imgs_dim_missing++;
                        }
                    }
                }
            }
        }
        $total_img = $c['imgs_total'] ?? 0;
        if ($total_img > 0) {
            if ($imgs_dim_missing === 0) {
                $checks[] = $this->pass('img_dimensions', 'performance', __('Images have dimensions', 'pylon-seo'), __('All images include width/height attributes.', 'pylon-seo'));
            } else {
                $pct = round(($imgs_dim_missing / $total_img) * 100);
                $checks[] = $this->warn(
                    'img_dimensions',
                    'performance',
                    __('Images missing dimensions', 'pylon-seo'),
                    sprintf(
                        /* translators: 1: number of images missing dimensions, 2: total number of images, 3: percentage */
                        __('%1$d of %2$d images (%3$d%%) lack width/height attributes.', 'pylon-seo'),
                        $imgs_dim_missing,
                        $total_img,
                        $pct
                    ),
                    __('Add width and height to every img tag to prevent layout shift (CLS).', 'pylon-seo')
                );
            }
        }

        return $checks;
    }

    /* ---- SOCIAL & SCHEMA ---- */
    private function checks_social(array $c): array {
        $checks = [];

        // 31. Open Graph title.
        $checks[] = !empty($c['og_title'])
            ? $this->pass('og_title', 'social', __('Open Graph title set', 'pylon-seo'), $c['og_title'])
            : $this->warn('og_title', 'social', __('Missing OG title', 'pylon-seo'), __('No og:title meta tag.', 'pylon-seo'), __('Add an og:title tag for social sharing.', 'pylon-seo'));

        // 32. Open Graph description.
        $checks[] = !empty($c['og_desc'])
            ? $this->pass('og_desc', 'social', __('Open Graph description set', 'pylon-seo'), __('og:description is present.', 'pylon-seo'))
            : $this->warn('og_desc', 'social', __('Missing OG description', 'pylon-seo'), __('No og:description meta tag.', 'pylon-seo'), __('Add an og:description tag.', 'pylon-seo'));

        // 33. Open Graph image.
        $checks[] = !empty($c['og_image'])
            ? $this->pass('og_image', 'social', __('Social share image set', 'pylon-seo'), __('og:image is present.', 'pylon-seo'))
            : $this->warn('og_image', 'social', __('Missing share image', 'pylon-seo'), __('No og:image — shares will look plain.', 'pylon-seo'), __('Add an og:image (1200×630px recommended).', 'pylon-seo'));

        // 34. Twitter card.
        $checks[] = !empty($c['tw_card'])
            ? $this->pass(
                'tw_card',
                'social',
                __('Twitter card configured', 'pylon-seo'),
                sprintf(
                    /* translators: %s: Twitter card type */
                    __('twitter:card = %s.', 'pylon-seo'),
                    esc_html($c['tw_card'])
                )
            )
            : $this->warn('tw_card', 'social', __('No Twitter card', 'pylon-seo'), __('No twitter:card meta tag.', 'pylon-seo'), __('Add a twitter:card tag (summary_large_image).', 'pylon-seo'));

        // 35. Favicon.
        $favicon = $c['xpath']->query('//link[@rel="icon"]|//link[@rel="shortcut icon"]')->length;
        $checks[] = $favicon > 0
            ? $this->pass('favicon', 'social', __('Favicon present', 'pylon-seo'), __('A favicon is set.', 'pylon-seo'))
            : $this->warn('favicon', 'social', __('No favicon', 'pylon-seo'), __('No favicon detected.', 'pylon-seo'), __('Add a favicon for brand recognition.', 'pylon-seo'));

        return $checks;
    }

    /* ---- USER EXPERIENCE ---- */
    private function checks_ux(array $c): array {
        $checks = [];

        // 36. Content-to-code ratio.
        $text_len = strlen($c['plain_text'] ?? '');
        $html_len = strlen($c['html'] ?? 1);
        $ratio = $html_len > 0 ? ($text_len / $html_len) * 100 : 0;
        if ($ratio >= 15) {
            $checks[] = $this->pass(
                'text_ratio',
                'ux',
                __('Healthy text-to-code ratio', 'pylon-seo'),
                sprintf(
                    /* translators: %.1f: text-to-code ratio percentage */
                    __('%.1f%% text content.', 'pylon-seo'),
                    $ratio
                )
            );
        } elseif ($ratio >= 7) {
            $checks[] = $this->warn(
                'text_ratio',
                'ux',
                __('Low text-to-code ratio', 'pylon-seo'),
                sprintf(
                    /* translators: %.1f: text-to-code ratio percentage */
                    __('Only %.1f%% text vs code.', 'pylon-seo'),
                    $ratio
                ),
                __('Reduce HTML bloat; increase real content.', 'pylon-seo')
            );
        } else {
            $checks[] = $this->fail(
                'text_ratio',
                'ux',
                __('Very low text ratio', 'pylon-seo'),
                sprintf(
                    /* translators: %.1f: text-to-code ratio percentage */
                    __('Only %.1f%% text content.', 'pylon-seo'),
                    $ratio
                ),
                __('Add more textual content; remove unused code.', 'pylon-seo')
            );
        }

        // 37. Paragraph length (no wall of text).
        $paras = $c['xpath']->query('//p');
        $long_paras = 0;
        if ($paras) foreach ($paras as $p) {
            if (str_word_count($p->textContent) > 150) $long_paras++;
        }
        $checks[] = $long_paras === 0
            ? $this->pass('para_length', 'ux', __('Readable paragraphs', 'pylon-seo'), __('No overly long paragraphs.', 'pylon-seo'))
            : $this->warn(
                'para_length',
                'ux',
                __('Long paragraphs', 'pylon-seo'),
                sprintf(
                    /* translators: %d: number of long paragraphs */
                    __('%d paragraph(s) exceed 150 words.', 'pylon-seo'),
                    $long_paras
                ),
                __('Break long paragraphs into shorter ones.', 'pylon-seo')
            );

        // 38. Broken / empty links count.
        $empty = $c['xpath']->query('//a[@href="#"]')->length;
        $checks[] = $empty === 0
            ? $this->pass('no_placeholder', 'ux', __('No placeholder links', 'pylon-seo'), __('No "#" placeholder links.', 'pylon-seo'))
            : $this->warn(
                'no_placeholder',
                'ux',
                __('Placeholder links', 'pylon-seo'),
                sprintf(
                    /* translators: %d: number of placeholder links */
                    __('%d "#" placeholder links found.', 'pylon-seo'),
                    $empty
                ),
                __('Replace placeholder links with real URLs.', 'pylon-seo')
            );

        // 39. Flash / deprecated embeds.
        $deprecated = $c['xpath']->query('//object|//embed|//iframe[contains(@src,"flash")]')->length;
        $checks[] = $deprecated === 0
            ? $this->pass('no_deprecated', 'ux', __('No deprecated media', 'pylon-seo'), __('No Flash/object embeds detected.', 'pylon-seo'))
            : $this->warn(
                'no_deprecated',
                'ux',
                __('Deprecated embeds', 'pylon-seo'),
                sprintf(
                    /* translators: %d: number of deprecated media elements */
                    __('%d deprecated media element(s).', 'pylon-seo'),
                    $deprecated
                ),
                __('Replace Flash/object embeds with HTML5.', 'pylon-seo')
            );

        // 40. Inline styles (code maintainability proxy).
        $inline_styled = $c['xpath']->query('//*[@style]')->length;
        if ($inline_styled <= 10) {
            $checks[] = $this->pass(
                'inline_styles',
                'ux',
                __('Minimal inline styles', 'pylon-seo'),
                sprintf(
                    /* translators: %d: number of elements with inline styles */
                    __('%d inline-styled elements.', 'pylon-seo'),
                    $inline_styled
                )
            );
        } else {
            $checks[] = $this->warn(
                'inline_styles',
                'ux',
                __('Many inline styles', 'pylon-seo'),
                sprintf(
                    /* translators: %d: number of elements with inline styles */
                    __('%d elements use inline styles.', 'pylon-seo'),
                    $inline_styled
                ),
                __('Move inline styles to CSS classes.', 'pylon-seo')
            );
        }

        // 44. Paragraph density (average words per paragraph).
        $paras = $c['xpath']->query('//p');
        $para_count = $paras ? $paras->length : 0;
        $wc = $c['word_count'] ?? 0;
        if ($para_count > 0 && $wc > 0) {
            $avg_para = round($wc / $para_count);
            if ($avg_para >= 40 && $avg_para <= 100) {
                $checks[] = $this->pass(
                    'para_density',
                    'ux',
                    __('Good paragraph density', 'pylon-seo'),
                    sprintf(
                        /* translators: 1: average words per paragraph, 2: number of paragraphs, 3: total word count */
                        __('Avg. %1$d words per paragraph (%2$d paragraphs, %3$d words).', 'pylon-seo'),
                        $avg_para,
                        $para_count,
                        $wc
                    )
                );
            } elseif ($avg_para > 100) {
                $checks[] = $this->warn(
                    'para_density',
                    'ux',
                    __('Dense paragraphs', 'pylon-seo'),
                    sprintf(
                        /* translators: %d: average words per paragraph */
                        __('Avg. %d words per paragraph — break into shorter chunks.', 'pylon-seo'),
                        $avg_para
                    ),
                    __('Keep paragraphs under 100 words for readability.', 'pylon-seo')
                );
            } else {
                $checks[] = $this->warn(
                    'para_density',
                    'ux',
                    __('Very short paragraphs', 'pylon-seo'),
                    sprintf(
                        /* translators: %d: average words per paragraph */
                        __('Avg. %d words per paragraph — may lack depth.', 'pylon-seo'),
                        $avg_para
                    ),
                    __('Aim for 40–100 words per paragraph for substantive content.', 'pylon-seo')
                );
            }
        } elseif ($para_count === 0 && $wc > 0) {
            $checks[] = $this->warn('para_density', 'ux', __('No paragraph tags', 'pylon-seo'), __('Content lacks &lt;p&gt; tags.', 'pylon-seo'), __('Wrap text in paragraph tags for readability and SEO.', 'pylon-seo'));
        }

        return $checks;
    }

    /* -----------------------------------------------------------------
     *  SCORING
     * --------------------------------------------------------------- */

    private function compute_summary(array $results): array {
        $by_category = [];
        $counts = ['pass' => 0, 'warn' => 0, 'fail' => 0];

        foreach ($results as $r) {
            $cat = $r['category'];
            $by_category[$cat] = $by_category[$cat] ?? ['score' => 0, 'max' => 0, 'count' => 0];
            $by_category[$cat]['score'] += $r['score'];
            $by_category[$cat]['max']   += $r['max_score'];
            $by_category[$cat]['count']++;
            $counts[$r['status']]++;
        }

        // Weighted overall score.
        $overall = 0;
        foreach ($by_category as $cat => $data) {
            $cat_pct = $data['max'] > 0 ? ($data['score'] / $data['max']) * 100 : 0;
            $weight = self::CATEGORY_WEIGHTS[$cat] ?? 0;
            $overall += $cat_pct * ($weight / 100);
        }

        $category_scores = [];
        foreach ($by_category as $cat => $data) {
            $category_scores[$cat] = [
                'label' => self::CATEGORY_LABELS[$cat] ?? $cat,
                'score' => $data['max'] > 0 ? round(($data['score'] / $data['max']) * 100) : 0,
                'weight' => self::CATEGORY_WEIGHTS[$cat] ?? 0,
                'count' => $data['count'],
            ];
        }

        $grade = $this->score_to_grade($overall);

        return [
            'overall'   => round($overall),
            'grade'     => $grade['letter'],
            'grade_label' => $grade['label'],
            'total'     => count($results),
            'passed'    => $counts['pass'],
            'warnings'  => $counts['warn'],
            'failed'    => $counts['fail'],
            'categories' => $category_scores,
        ];
    }

    private function score_to_grade(float $score): array {
        if ($score >= 90) return ['letter' => 'A', 'label' => __('Excellent', 'pylon-seo')];
        if ($score >= 80) return ['letter' => 'B', 'label' => __('Good', 'pylon-seo')];
        if ($score >= 70) return ['letter' => 'C', 'label' => __('Fair', 'pylon-seo')];
        if ($score >= 50) return ['letter' => 'D', 'label' => __('Poor', 'pylon-seo')];
        return ['letter' => 'F', 'label' => __('Critical', 'pylon-seo')];
    }

    /* -----------------------------------------------------------------
     *  CHECK RESULT HELPERS
     * --------------------------------------------------------------- */

    private function pass(string $id, string $cat, string $label, string $msg): array {
        return ['id' => $id, 'category' => $cat, 'label' => $label, 'status' => 'pass', 'score' => 10, 'max_score' => 10, 'message' => $msg, 'recommendation' => ''];
    }
    private function warn(string $id, string $cat, string $label, string $msg, string $rec = ''): array {
        return ['id' => $id, 'category' => $cat, 'label' => $label, 'status' => 'warn', 'score' => 5, 'max_score' => 10, 'message' => $msg, 'recommendation' => $rec];
    }
    private function fail(string $id, string $cat, string $label, string $msg, string $rec = ''): array {
        return ['id' => $id, 'category' => $cat, 'label' => $label, 'status' => 'fail', 'score' => 0, 'max_score' => 10, 'message' => $msg, 'recommendation' => $rec];
    }

    private function count_syllables(string $text): int {
        $words = preg_split('/\s+/', $text);
        $total = 0;
        foreach ($words as $word) {
            $w = strtolower(preg_replace('/[^a-z]/i', '', $word));
            if ($w === '') continue;
            $total += $this->syllables_in_word($w);
        }
        return $total;
    }

    private function syllables_in_word(string $w): int {
        $len = strlen($w);
        if ($len <= 3) return 1; // the, cat, run, etc.

        // Common exceptions (1-syllable words that look like more)
        static $ones = ['the','he','she','we','me','be','gone','done','are','were','some','come','have','live','give','whose','these','those','please','cause','cease','chute','crepe','cute','dice','ease','else','eye','fare','fee','fence','fete','five','force','forme','frieze','gage','gate','gauge','geese','glue','gon','grace','graze','gree','grime','guide','guise','gybe','gyve','hare','hate','have','hearse','heart','heath','heave','hence','here','hire','hive','hole','home','hope','huge','hume','ice','ire','jeans','jeers','jive','joie','joule','judge','juice','keen','knee','knife','knock','knoll','know','lace','lade','lake','lance','large','late','laure','lave','lease','leave','ledge','leech','leek','less','liege','lief','life','like','lime','line','live','loaf','loath','lobe','lodge','lofty','loose','lore','love','lunge','lure','lute','lye','make','male','mane','mare','mate','maul','maze','mead','meal','meas','meat','meet','meld','melt','mend','mere','merge','merit','mesh','mess','mew','mews','might','mile','milk','mill','mime','mind','mine','mint','mire','mirth','miss','mist','mite','mock','mode','mold','molt','mood','moon','moor','more','moss','most','moth','move','mown','much','mule','mull','muse','mush','musk','must','mute','mutt','myst','myth','nail','name','nape','nave','near','neat','neck','need','nest','news','next','nice','niche','night','nine','node','noise','none','noon','north','nose','note','noun','nude','null','numb','nun','nurse','nut','oaf','oak','oar','oath','obey','odds','ode','off','officer','often','ogle','oil','oink','old','olive','once','one','onion','only','ooze','open','optic','or','oral','orb','orchid','order','ore','organ','other','ouch','ought','ounce','our','out','oval','oven','over','owl','own','pace','pack','pact','pad','page','paid','pail','pain','pair','pal','pale','pall','palm','pan','pane','pang','pant','pap','paper','pare','park','part','pass','past','paste','pat','patch','path','pause','pave','pawn','pay','peace','peak','peal','pear','pearl','peat','peck','pedal','peel','peer','peg','pen','pence','pend','penny','pent','peon','perch','perk','pert','perv','peso','pest','pet','pew','phone','pi','pick','pie','piece','pier','pike','pile','pill','pine','ping','pink','pint','pipe','pique','pit','pitch','pith','pity','pixel','pixy','place','plague','plaid','plain','plan','plane','plank','plant','plate','play','plea','plead','please','pledge','pliers','plot','plow','ploy','pluck','plug','plum','plume','plump','plunge','plus','ply','pock','pocket','poem','poet','point','poise','poke','pole','police','poll','pomp','pond','pone','pool','poor','pop','pope','port','pose','post','pot','pouch','pound','pour','pout','pow','pray','preach','preen','press','price','pride','prime','print','prior','prism','prize','probe','prod','prompt','prone','proof','prop','propel','prose','proud','prove','prow','prune','pry','psalm','puff','pull','pulp','pulse','pump','pun','punch','punk','pup','pupil','pure','purge','purr','purse','push','put','putt','quack','quad','quaff','quail','quake','qualify','quality','qualm','quark','quarry','quart','queen','queer','quell','query','quest','queue','quick','quid','quiet','quill','quilt','quirk','quit','quite','quiz','quo','quota','quote','race','rack','raft','rag','rage','raid','rail','rain','rake','rally','ram','ramp','ranch','range','rank','rant','rap','rape','rash','rat','rate','rave','raw','ray','raze','reach','read','ready','real','realm','reap','rear','reason','red','reed','reef','reel','refer','reign','rein','rend','rent','rest','retch','review','rhyme','rice','rich','ride','rift','right','rigid','rim','ring','rinse','riot','rip','ripe','rise','risk','rite','road','roam','roar','robe','rock','rod','rode','role','roll","roof","room","root","rope","rose","rosy","rot","rough","round","route","rove","row","royal","rub","rube","rude","rug","ruin","rule","rump","run","rung","ruse","rush","rust","sack","safe","sage","said","sail","saint","sake","sale","salt","same","sand","sane","sang","sank","sap","sash","sat","save","saw","say","scald","scale","scalp","scan","scant","scar","scare","scarf","scene","scent","school","scold","scoop","scope","score","scorn","scour","scout","scrape","screw","scroll","scrub","sea","seal","seam","sear","seat","sect","seed","seek","seem","seen","self","sell","send","sense","sent","sentry","serve","set","settle","seven","sew","shade","shaft","shag","shake","shall","shame","shape","share","shark","sharp","shave","shawl","she","shear","shed","sheen","sheep","sheer","sheet","shelf","shell","shelve","shepherd","shield","shin","shine","ship","shire","shirt","shock","shoe","shook","shoot","shore","short","shot","should","shout","shove","show","shred","shrew","shrub","shrug","shun","shut","sick","side","sift","sigh","sight","sign","silk","sill","silly","silt","since","sing","sink","sip","sir","sire","sit","site","size","skate","skein","sketch","ski","skid","skill","skim","skin","skip","skirt","skit","skull","sky","slab","slag","slain","slam","slang","slap","slash","slate","slave","sled","sleek","sleep","sleet","slew","slice","slick","slid","slide","slim","slime","slip","slit","slope","slot","sloth","slug","slum","slump","smack","small","smart","smash","smear","smell","smile","smirk","smoke","smooth","smug","snack","snag","snap","snare","snarl","sneak","sneer","sniff","snip","snob","snore","snort","snow","snub","snuff","soak","soap","soar","sob","sock","sod","sofa","soft","soil","sold","sole","solve","some","son","song","soon","soot","sore","sort","soul","sound","soup","sour","south","sow","space","spade","span","spare","spark","spasm","spat","spawn","speak","spear","speck","speech","speed","spell","spend","spent","spill","spin","spine","spire","spit","splash","spoke","spoke","sponge","spool","spoon","sport","spot","spout","spray","spread","spree","spring","sprout","spruce","spur","spy","squad","squall","square","squash","squat","squeeze","squint","squirm","stab","stack","staff","stag","stage","stain","stair","stake","stale","stalk","stall","stamp","stand","stark","start","starve","state","stave","stay","steak","steal","steam","steed","steel","steep","steer","stem","step","stern","stick","stiff","still","sting","stint","stir","stock","stoke","stole","stone","stool","stoop","store","stork","storm","story","stout","stove","strap","straw","stray","strike","string","strip","stroke","stroll","strong","struck","strum","strut","stub","stuck","study","stuff","stump","stun","stung","stunt","style","sub","such","suck","sue","sued","sugar","suit","suite","sulk","sullen","sum","summer","summit","sun","sung","sunk","sure","surf","surge","surplus","surrender","survey","sweat","sweep","sweet","swell","swept","swift","swim","swing","swipe","swirl","switch","swore","sworn","swung","syllable","symbol","syrup","system","table","tack","tact","tag","tail","take","tale","talk","tall","tame","tan","tang","tap","tape","tar","tard","target","task","taste","tattoo","taught","tax","tea","teach","team","tear","tease","tell","ten","tend","tense","tent","term","tern","test","text","than","thank","that","thaw","the","theft","their","them","theme","then","thence","there","these","they","thick","thief","thin","thing","think","third","thirst","this","thorn","those","though","thread","threat","three","threw","throat","throne","throng","through","throw","thrust","thumb","thump","thus","thyme","tick","tide","tie","tier","tight","till","time","tin","tinge","tint","tiny","tip","tire","toad","toast","today","toe","toil","told","toll","tomorrow","tone","tongue","tonight","too","took","tool","tooth","top","tore","torn","touch","tough","tour","tow","towel","town","toy","trace","track","trade","trail","train","trait","tram","trap","trash","trawl","tread","treat","tree","trek","tremor","trench","trend","trial","tribe","trick","trim","trip","tripe","trite","trod","troll","troop","trophy","trot","trout","truce","truck","trudge","trunk","trust","truth","try","tube","tuck","tuft","tug","tulip","tumble","tune","turf","turn","tusk","tutor","tweed","tweet","twelve","twenty","twice","twin","twine","twirl","twist","two","type","ugly","ulcer","umpire","uncle","under","unfair","unfold","unhappy","unicorn","unique","unit","unite","unlike","unlock","unrest","unsafe","until","untrue","up","upgrade","uphold","upon","upper","upright","uproar","upset","upshot","upward","urban","urge","urine","use","used","usher","usual","usurp","utter","vacant","vacuum","vague","vain","valet","valid","valley","value","valve","van","vane","vanish","vanity","vantage","vase","vast","vault","veal","veer","veil","vein","venom","vent","verdict","verse","very","vessel","vest","vet","veteran","veto","vex","via","viable","vibrant","vice","victim","view","vigil","vigor","vile","village","vine","vinyl","violate","violin","viral","virtue","virus","visa","vise","visit","visor","vista","visual","vital","vitamin","vivid","vocal","voice","void","volcano","volume","volunteer","vomit","vote","vouch","vow","vowel","voyage","wad","wade","wage","wagon","waist","wait","waive","wake","walk","wall","wallet","wander","want","war","ward","warm","warn","warp","warrant","warren","wash","waste","watch","water","wave","wavy","wax","way","weak","wealth","weapon","wear","weary","weave","web","wed","wedge","weed","week","weep","weigh","weird","welcome","weld","well","went","were","west","wet","whack","whale","what","wheat","wheel","when","whence","where","whet","which","while","whim","whip","whirl","whisk","whisper","white","who","whole","whom","whore","whose","why","wick","wide","widow","width","wife","wild","will","wilt","wily","win","wince","wind","wine","wing","wink","winner","winter","wipe","wire","wise","wish","wit","witch","with","witness","witty","woke","wolf","woman","womb","won","wonder","wood","wool","word","wore","work","world","worm","worn","worried","worry","worse","worship","worst","worth","would","wound","wove","wrap","wrath","wreath","wreck","wrench","wrest","wretch","wring","wrinkle","wrist","write","wrong","wrote","wrung','yacht','yank','yard','yarn','yawn','year','yeast','yell','yes','yesterday','yet','yield','young','your','youth','zebra','zenith','zero','zest','zinc','zone','zoom'];
        if (in_array($w, $ones, true)) return 1;

        // Count vowel groups
        $vowel_groups = preg_match_all('/[aeiouy]+/', $w);

        // Subtract silent-e at end
        if (preg_match('/[^aeiou]e$/i', $w)) {
            $vowel_groups--;
        }
        // Words ending in 'le' preceded by consonant — add back if needed
        if (preg_match('/[^aeiou]le$/i', $w) && $vowel_groups >= 1) {
            $vowel_groups++;
        }
        // Words ending in 'sm' — often add a syllable
        if (preg_match('/[aeiouy]sm$/i', $w)) {
            $vowel_groups++;
        }
        return max(1, $vowel_groups);
    }

    /* -----------------------------------------------------------------
     *  RESULTS HTML RENDERER (server-side, injected via AJAX)
     * --------------------------------------------------------------- */

    private function render_results_html(array $summary, array $results, string $url, string $title, int $post_id = 0): string {
        $score = $summary['overall'];
        $grade = $summary['grade'];
        $circumference = 2 * pi() * 52;
        $offset = $circumference - ($circumference * $score / 100);
        $score_color = $score >= 80 ? 'var(--pylon-success)' : ($score >= 50 ? 'var(--pylon-warning)' : 'var(--pylon-danger)');

        ob_start();
        ?>
        <!-- Score header -->
        <div class="pylon-card pylon-mb-20">
            <div class="pylon-card-body pylon-flex pylon-flex-wrap pylon-gap-20 pylon-flex-center">
                <!-- Circular gauge -->
                <div style="position:relative;width:130px;height:130px;flex-shrink:0;">
                    <svg width="130" height="130" viewBox="0 0 130 130">
                        <circle cx="65" cy="65" r="52" fill="none" stroke="var(--pylon-gray-200)" stroke-width="10"/>
                        <circle cx="65" cy="65" r="52" fill="none" stroke="<?php echo esc_attr($score_color); ?>" stroke-width="10"
                            stroke-linecap="round" stroke-dasharray="<?php echo esc_attr($circumference); ?>"
                            stroke-dashoffset="<?php echo esc_attr($offset); ?>"
                            transform="rotate(-90 65 65)"
                            style="transition:stroke-dashoffset 1s ease;"/>
                    </svg>
                    <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center;">
                        <div style="font-size:34px;font-weight:700;line-height:1;color:<?php echo esc_attr($score_color); ?>;"><?php echo (int) $score; ?></div>
                        <div style="font-size:11px;color:var(--pylon-gray-500);"><?php echo esc_html($grade); ?> · <?php echo esc_html($summary['grade_label']); ?></div>
                    </div>
                </div>
                <!-- Title + stats -->
                <div style="flex:1;min-width:250px;">
                    <h2 style="margin:0 0 4px;font-size:18px;"><?php echo esc_html($title ?: basename($url)); ?></h2>
                    <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener" style="font-size:12px;color:var(--pylon-primary);word-break:break-all;"><?php echo esc_html($url); ?></a>
                    <div class="pylon-flex pylon-gap-16 pylon-mt-12 pylon-flex-wrap">
                        <span class="pylon-badge pylon-badge-green">✓ <?php echo esc_html(sprintf(
                            /* translators: %d: number of passed checks */
                            __('%d passed', 'pylon-seo'),
                            $summary['passed']
                        )); ?></span>
                        <span class="pylon-badge pylon-badge-amber">⚠ <?php echo esc_html(sprintf(
                            /* translators: %d: number of warnings */
                            __('%d warnings', 'pylon-seo'),
                            $summary['warnings']
                        )); ?></span>
                        <span class="pylon-badge pylon-badge-red">✕ <?php echo esc_html(sprintf(
                            /* translators: %d: number of failed checks */
                            __('%d failed', 'pylon-seo'),
                            $summary['failed']
                        )); ?></span>
                        <span class="pylon-badge pylon-badge-blue"><?php echo esc_html(sprintf(
                            /* translators: %d: total number of checks */
                            __('%d total checks', 'pylon-seo'),
                            $summary['total']
                        )); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Category breakdown -->
        <div class="pylon-card pylon-mb-20">
            <div class="pylon-card-header"><h3><?php esc_html_e('Category Scores', 'pylon-seo'); ?></h3></div>
            <div class="pylon-card-body">
                <?php foreach ($summary['categories'] as $cat_id => $cat): 
                    $c_color = $cat['score'] >= 80 ? 'var(--pylon-success)' : ($cat['score'] >= 50 ? 'var(--pylon-warning)' : 'var(--pylon-danger)');
                ?>
                    <div class="pylon-flex pylon-flex-center pylon-gap-12 pylon-mb-12">
                        <div style="width:160px;font-size:13px;font-weight:500;"><?php echo esc_html($cat['label']); ?>
                            <span style="font-size:11px;color:var(--pylon-gray-400);">(<?php echo (int) $cat['weight']; ?>%)</span>
                        </div>
                        <div style="flex:1;height:22px;background:var(--pylon-gray-100);border-radius:11px;overflow:hidden;">
                            <div style="width:<?php echo (int) $cat['score']; ?>%;height:100%;background:<?php echo esc_attr($c_color); ?>;border-radius:11px;transition:width 0.8s ease;display:flex;align-items:center;padding-left:8px;color:#fff;font-size:11px;font-weight:600;">
                                <?php if ($cat['score'] > 15) echo (int) $cat['score']; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Filter buttons + Export -->
        <div class="pylon-flex pylon-flex-wrap pylon-gap-8 pylon-mb-12 pylon-flex-center pylon-flex-between">
            <div class="pylon-flex pylon-gap-8">
                <button type="button" class="pylon-btn pylon-btn-sm pylon-btn-primary pylon-audit-filter" data-filter="all"><?php esc_html_e('All', 'pylon-seo'); ?></button>
                <button type="button" class="pylon-btn pylon-btn-sm pylon-btn-secondary pylon-audit-filter" data-filter="fail"><?php esc_html_e('Failed', 'pylon-seo'); ?></button>
                <button type="button" class="pylon-btn pylon-btn-sm pylon-btn-secondary pylon-audit-filter" data-filter="warn"><?php esc_html_e('Warnings', 'pylon-seo'); ?></button>
                <button type="button" class="pylon-btn pylon-btn-sm pylon-btn-secondary pylon-audit-filter" data-filter="pass"><?php esc_html_e('Passed', 'pylon-seo'); ?></button>
            </div>
            <button type="button" class="pylon-btn pylon-btn-sm pylon-btn-secondary pylon-export-csv" data-post-id="<?php echo esc_attr((int) $post_id); ?>">
                <?php esc_html_e('⬇ Export CSV', 'pylon-seo'); ?>
            </button>
        </div>

        <!-- Score history chart -->
        <div class="pylon-card pylon-mb-20 pylon-history-card" style="display:none;">
            <div class="pylon-card-header">
                <h3><?php esc_html_e('Score History', 'pylon-seo'); ?></h3>
                <span class="pylon-text-12 pylon-color-muted"><?php esc_html_e('Track audit scores over time', 'pylon-seo'); ?></span>
            </div>
            <div class="pylon-card-body pylon-history-chart" data-post-id="<?php echo esc_attr((int) $post_id); ?>">
                <div class="pylon-text-center pylon-color-muted" style="padding:20px;">
                    <div class="pylon-spinner" style="display:inline-block;width:24px;height:24px;border:3px solid var(--pylon-gray-200);border-top-color:var(--pylon-primary);border-radius:50%;animation:pylon-spin 0.6s linear infinite;"></div>
                    <p style="margin-top:8px;font-size:12px;"><?php esc_html_e('Loading history…', 'pylon-seo'); ?></p>
                </div>
            </div>
        </div>

        <!-- Detailed checks -->
        <div class="pylon-card">
            <div class="pylon-card-header"><h3><?php esc_html_e('Detailed Audit Report', 'pylon-seo'); ?></h3></div>
            <div class="pylon-card-body" style="padding:0;">
                <?php foreach ($results as $r): 
                    $icon = $r['status'] === 'pass' ? '✓' : ($r['status'] === 'warn' ? '⚠' : '✕');
                    $color = $r['status'] === 'pass' ? 'var(--pylon-success)' : ($r['status'] === 'warn' ? 'var(--pylon-warning)' : 'var(--pylon-danger)');
                    $cat_label = self::CATEGORY_LABELS[$r['category']] ?? $r['category'];
                ?>
                    <div class="pylon-audit-check" data-status="<?php echo esc_attr($r['status']); ?>" style="padding:14px 20px;border-bottom:1px solid var(--pylon-gray-100);">
                        <div class="pylon-flex pylon-flex-center pylon-gap-12">
                            <span style="font-size:18px;color:<?php echo esc_attr($color); ?>;font-weight:700;width:24px;text-align:center;flex-shrink:0;"><?php echo esc_html($icon); ?></span>
                            <div style="flex:1;">
                                <div style="font-weight:500;font-size:14px;"><?php echo esc_html($r['label']); ?>
                                    <span class="pylon-badge pylon-badge-blue" style="font-size:10px;margin-left:6px;"><?php echo esc_html($cat_label); ?></span>
                                </div>
                                <div style="font-size:12px;color:var(--pylon-gray-500);margin-top:2px;"><?php echo wp_kses_post($r['message']); ?></div>
                                <?php if (!empty($r['recommendation'])): ?>
                                    <div class="pylon-audit-rec" style="font-size:12px;color:var(--pylon-gray-700);margin-top:6px;padding:8px 10px;background:var(--pylon-gray-50);border-radius:6px;border-left:3px solid <?php echo esc_attr($color); ?>;">
                                        <strong><?php esc_html_e('How to fix:', 'pylon-seo'); ?></strong> <?php echo wp_kses_post($r['recommendation']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* -----------------------------------------------------------------
     *  INLINE ASSETS
     * --------------------------------------------------------------- */


    private function audit_js(): string {
        return '
        jQuery(function($){
            var auditNonce = ' . wp_json_encode(wp_create_nonce('pylon_seo_audit')) . ';

            $("#pylon-audit-run").on("click", function(){
                var pid = $("#pylon-audit-page").val();
                var url = $("#pylon-audit-url").val().trim();
                if (!pid && !url) {
                    pylonToast("Notice", "' . esc_js(__('Please select a page or enter a URL.', 'pylon-seo')) . '", "warning");
                    return;
                }
                runAudit(pid || 0, url);
            });

            function runAudit(postId, url){
                var $btn = $("#pylon-audit-run");
                var $results = $("#pylon-audit-results");
                var origText = $btn.html();
                $btn.prop("disabled", true).html(\'<span class="pylon-spinner" style="display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,0.3);border-top-color:#fff;border-radius:50%;animation:pylon-spin 0.6s linear infinite;vertical-align:middle;"></span> ' . esc_js(__('Auditing…', 'pylon-seo')) . '\');
                $results.html(\'<div class="pylon-card"><div class="pylon-card-body pylon-text-center" style="padding:60px;"><div class="pylon-spinner" style="display:inline-block;width:40px;height:40px;border:4px solid var(--pylon-gray-200);border-top-color:var(--pylon-primary);border-radius:50%;animation:pylon-spin 0.6s linear infinite;"></div><p style="margin-top:16px;color:var(--pylon-gray-500);">' . esc_js(__('Fetching page and running 45+ SEO checks…', 'pylon-seo')) . '</p></div></div>\');

                pylonAjax("pylon_seo_audit_run", {
                    post_id: postId,
                    url: url,
                    _ajax_nonce: auditNonce
                }, { toast: false }).done(function(data){
                    if (data && data.html) {
                        $results.html(data.html);
                        initFilters();
                        if (postId) { loadAuditHistory(postId); }
                        pylonToast("Success", "' . esc_js(__('Audit complete.', 'pylon-seo')) . '", "success");
                    } else {
                        $results.html(\'<div class="pylon-card"><div class="pylon-card-body pylon-text-center pylon-color-muted">' . esc_js(__('No results returned.', 'pylon-seo')) . '</div></div>\');
                    }
                }).fail(function(err){
                    var msg = (err && err.message) ? err.message : "' . esc_js(__('Audit failed.', 'pylon-seo')) . '";
                    $results.html(\'<div class="pylon-card"><div class="pylon-card-body pylon-text-center pylon-color-muted">\' + $("<span>").text(msg).html() + \'</div></div>\');
                }).always(function(){
                    $btn.prop("disabled", false).html(origText);
                });
            }

            function initFilters(){
                $(".pylon-audit-filter").off("click").on("click", function(){
                    var f = $(this).data("filter");
                    $(".pylon-audit-filter").removeClass("pylon-btn-primary").addClass("pylon-btn-secondary");
                    $(this).addClass("pylon-btn-primary").removeClass("pylon-btn-secondary");
                    if (f === "all") {
                        $(".pylon-audit-check").show();
                    } else {
                        $(".pylon-audit-check").hide().filter("[data-status=\\""+f+"\\"]").show();
                    }
                });
            }

            initFilters();

            /* ── Export CSV ── */
            $(document).on("click", ".pylon-export-csv", function(){
                var $btn = $(this);
                var postId = $btn.data("post-id");
                if (!postId) { pylonToast("Notice", "' . esc_js(__('Save the post first.', 'pylon-seo')) . '", "warning"); return; }
                var origText = $btn.html();
                $btn.prop("disabled", true).html("' . esc_js(__('Exporting…', 'pylon-seo')) . '");
                pylonAjax("pylon_export_audit", {
                    post_id: postId,
                    _ajax_nonce: auditNonce
                }, { toast: false }).done(function(data){
                    if (data && data.csv) {
                        var blob = new Blob([data.csv], { type: "text/csv;charset=utf-8;" });
                        var link = document.createElement("a");
                        link.href = URL.createObjectURL(blob);
                        link.download = data.filename || "seo-audit.csv";
                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);
                        pylonToast("Success", "' . esc_js(__('Report downloaded.', 'pylon-seo')) . '", "success");
                    }
                }).fail(function(err){
                    pylonToast("Error", (err && err.message) ? err.message : "' . esc_js(__('Export failed.', 'pylon-seo')) . '", "error");
                }).always(function(){
                    $btn.prop("disabled", false).html(origText);
                });
            });

            /* ── Score History Chart ── */
            function loadAuditHistory(postId){
                var $chart = $(".pylon-history-chart[data-post-id=\\""+postId+"\\"]");
                if (!$chart.length) return;
                $(".pylon-history-card").show();
                pylonAjax("pylon_audit_history", {
                    post_id: postId,
                    _ajax_nonce: auditNonce
                }, { toast: false }).done(function(data){
                    if (data && data.labels && data.scores && data.labels.length > 0) {
                        $chart.html(renderMiniChart(data.labels, data.scores, data.latest));
                    } else {
                        $chart.html(\'<div class="pylon-text-center pylon-color-muted" style="padding:20px;font-size:13px;">' . esc_js(__('Run more audits to build score history.', 'pylon-seo')) . '</div>\');
                    }
                }).fail(function(){
                    $chart.html(\'<div class="pylon-text-center pylon-color-muted" style="padding:20px;font-size:13px;">' . esc_js(__('No history yet.', 'pylon-seo')) . '</div>\');
                });
            }

            function renderMiniChart(labels, scores, latest){
                var w = 600, h = 180, pad = 40;
                var maxScore = 100;
                var minScore = Math.max(0, Math.min.apply(null, scores) - 10);
                var range = maxScore - minScore || 1;
                var xs = labels.map(function(_, i){ return pad + (i / (labels.length-1 || 1)) * (w - pad*2); });
                var ys = scores.map(function(s){ return h - pad - ((s - minScore) / range) * (h - pad*2); });
                var color = (latest && latest.score >= 80) ? "#22c55e" : (latest && latest.score >= 50 ? "#f59e0b" : "#ef4444");
                var path = xs.map(function(x, i){ return (i === 0 ? "M" : "L") + x.toFixed(1) + "," + ys[i].toFixed(1); }).join(" ");
                var dots = xs.map(function(x, i){ return \'<circle cx="\'+x.toFixed(1)+\'" cy="\'+ys[i].toFixed(1)+\'" r="3" fill="\'+(i===xs.length-1?color:"#6b7280")+\'" stroke="#fff" stroke-width="1.5"/>\'; }).join("");
                return \'<div style="overflow-x:auto;"><svg viewBox="0 0 \'+w+\' \'+h+\'" style="width:100%;max-width:\'+w+\'px;height:\'+h+\'px;">\' +
                    \'<rect x="0" y="0" width="\'+w+\'" height="\'+h+\'" fill="none"/>\' +
                    \'<text x="\'+pad+\'" y="16" font-size="11" fill="#9ca3af">\'+maxScore+\'</text>\' +
                    \'<text x="\'+pad+\'" y="\'+(h-pad+16)+\'" font-size="11" fill="#9ca3af">\'+minScore+\'</text>\' +
                    \'<path d="\'+path+\'" fill="none" stroke="\'+color+\'" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>\' +
                    \'<path d="\'+path+\'" fill="none" stroke="\'+color+\'" stroke-width="6" opacity="0.12"/>\' +
                    dots +
                    \'<line x1="\'+pad+\'" y1="\'+(h-pad)+\'" x2="\'+(w-pad)+\'" y2="\'+(h-pad)+\'" stroke="#e5e7eb" stroke-width="1"/>\' +
                    \'<line x1="\'+pad+\'" y1="\'+pad+\'" x2="\'+pad+\'" y2="\'+(h-pad)+\'" stroke="#e5e7eb" stroke-width="1"/>\' +
                    \'</svg>\' +
                    (latest ? \'<div style="font-size:12px;color:#6b7280;margin-top:4px;">\' +
                        \'Latest: <strong style="color:\'+color+\'">\'+latest.score+\'</strong> (\'+latest.grade+\') · \' +
                        \'Passed: \'+latest.passed+\' · Warnings: \'+latest.warnings+\' · Failed: \'+latest.failed +
                        \'</div>\' : \'\') +
                    \'</div>\';
            }

            /* Load history when a post is selected */
            $("#pylon-audit-page").on("change", function(){
                var pid = $(this).val();
                if (pid) { loadAuditHistory(pid); }
            });

            initFilters();
        });
        ';
    }
}
