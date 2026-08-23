<?php
namespace Pylon\Core\Modules\KeywordResearch;
defined('ABSPATH') || exit;
/**
 * Keyword Research hub that surfaces on-site semantic gaps and AEO question
 * candidates — ahead of volume-only keyword tools.
 */
class KeywordResearch {
    public function register(): void {
        add_action('admin_menu', [$this, 'add_admin_page']);
        add_action('wp_ajax_pylon_kw_research_refresh', [$this, 'ajax_refresh']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(string $hook): void {
        if (strpos($hook, 'pylon-keyword-research') === false) return;
        \Pylon\Core\Modules\Admin\AdminEngine::add_module_js($this->js());
    }

    private function js(): string {
        return '
        jQuery(function($){
            $("#pylon-kw-refresh").on("click", function(){
                var $b = $(this).prop("disabled", true).text("…");
                $.post(ajaxurl, {
                    action: "pylon_kw_research_refresh",
                    _ajax_nonce: "' . esc_js(wp_create_nonce('pylon_kw_research')) . '"
                }, function(r){
                    if (r.success) {
                        location.reload();
                    } else {
                        alert(r.data && r.data.message ? r.data.message : "' . esc_js(__('Error', 'pylon-seo')) . '");
                        $b.prop("disabled", false).text("' . esc_js(__('Refresh insights', 'pylon-seo')) . '");
                    }
                });
            });
        });
        ';
    }

    public function add_admin_page(): void {
        add_submenu_page(
            'pylon',
            __('Keyword Research', 'pylon-seo'),
            __('Keyword Research', 'pylon-seo'),
            'manage_options',
            'pylon-keyword-research',
            [$this, 'render_admin_page']
        );
    }

    public function render_admin_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        $data = $this->get_report();
        ?>
        <div class="wrap">
            <?php
            \Pylon\Core\Modules\Admin\AdminEngine::page_header(
                __('Keyword Research', 'pylon-seo'),
                '🔑',
                true,
                '',
                '<button type="button" class="pylon-btn pylon-btn-sm pylon-btn-secondary" id="pylon-kw-refresh">' . esc_html__('Refresh insights', 'pylon-seo') . '</button>'
            );
            ?>
            <div class="pylon-notice pylon-notice-info" style="margin-bottom:16px;">
                <?php esc_html_e('Unlike volume-only keyword DBs, Pylon ranks opportunities from your on-site coverage gaps and AEO-ready questions.', 'pylon-seo'); ?>
            </div>

            <div class="pylon-status-grid" style="margin-bottom:16px;">
                <div class="pylon-status-card">
                    <div class="pylon-status-value"><?php echo (int) ($data['stats']['gaps'] ?? 0); ?></div>
                    <div class="pylon-status-label"><?php esc_html_e('Content gaps', 'pylon-seo'); ?></div>
                </div>
                <div class="pylon-status-card">
                    <div class="pylon-status-value"><?php echo (int) ($data['stats']['aeo'] ?? 0); ?></div>
                    <div class="pylon-status-label"><?php esc_html_e('AEO questions', 'pylon-seo'); ?></div>
                </div>
                <div class="pylon-status-card">
                    <div class="pylon-status-value"><?php echo (int) ($data['stats']['clusters'] ?? 0); ?></div>
                    <div class="pylon-status-label"><?php esc_html_e('Topic clusters', 'pylon-seo'); ?></div>
                </div>
            </div>

            <div class="pylon-card" style="margin-bottom:16px;">
                <div class="pylon-card-header"><h3><?php esc_html_e('On-site content gaps', 'pylon-seo'); ?></h3></div>
                <div class="pylon-card-body" style="padding:0;">
                    <?php $this->render_table($data['gaps'] ?? []); ?>
                </div>
            </div>

            <div class="pylon-card">
                <div class="pylon-card-header"><h3><?php esc_html_e('AEO / People-also-ask style questions', 'pylon-seo'); ?></h3></div>
                <div class="pylon-card-body" style="padding:0;">
                    <?php $this->render_table($data['aeo'] ?? []); ?>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_table(array $rows): void {
        if (!$rows) {
            echo '<div class="pylon-empty" style="padding:32px;"><p style="margin:0;color:var(--pylon-gray-500);">' . esc_html__('No insights yet. Publish content, then Refresh.', 'pylon-seo') . '</p></div>';
            return;
        }
        echo '<div class="pylon-table-wrap"><table class="pylon-table"><thead><tr>';
        echo '<th>' . esc_html__('Keyword / Question', 'pylon-seo') . '</th>';
        echo '<th>' . esc_html__('Why', 'pylon-seo') . '</th>';
        echo '<th>' . esc_html__('Score', 'pylon-seo') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td><strong>' . esc_html($row['keyword'] ?? '') . '</strong></td>';
            echo '<td style="font-size:12px;color:var(--pylon-gray-500);">' . esc_html($row['why'] ?? '') . '</td>';
            echo '<td><span class="pylon-badge pylon-badge-blue">' . (int) ($row['score'] ?? 0) . '</span></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    public function ajax_refresh(): void {
        check_ajax_referer('pylon_kw_research');
        if (!current_user_can('manage_options')) {
            wp_send_json_error();
        }
        delete_transient('pylon_kw_research_report');
        $this->get_report(true);
        wp_send_json_success();
    }

    public function get_report(bool $force = false): array {
        if (!$force) {
            $cached = get_transient('pylon_kw_research_report');
            if (is_array($cached)) {
                return $cached;
            }
        }
        $gaps = $this->from_content_gaps();
        $aeo = $this->from_aeo_questions();
        $report = [
            'gaps' => $gaps,
            'aeo' => $aeo,
            'stats' => [
                'gaps' => count($gaps),
                'aeo' => count($aeo),
                'clusters' => $this->cluster_count(),
            ],
            'generated' => current_time('mysql'),
        ];
        set_transient('pylon_kw_research_report', $report, HOUR_IN_SECONDS);
        return $report;
    }

    private function from_content_gaps(): array {
        global $wpdb;
        $titles = $wpdb->get_col("SELECT post_title FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ('post','page') ORDER BY post_date DESC LIMIT 80");
        $focus = $wpdb->get_col($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value<>'' LIMIT 200",
            'pylon_focus_keyword'
        ));
        $bag = [];
        foreach (array_merge($titles ?: [], $focus ?: []) as $t) {
            $words = preg_split('/[^a-z0-9]+/i', strtolower((string) $t)) ?: [];
            foreach ($words as $w) {
                if (strlen($w) < 4 || in_array($w, $this->stopwords(), true)) {
                    continue;
                }
                $bag[$w] = ($bag[$w] ?? 0) + 1;
            }
        }
        arsort($bag);
        $top = array_slice(array_keys($bag), 0, 12);
        $out = [];
        // Pair top terms into 2-word opportunities not used as focus keywords.
        $focus_l = array_map('strtolower', $focus ?: []);
        for ($i = 0; $i < count($top) - 1; $i++) {
            $phrase = $top[$i] . ' ' . $top[$i + 1];
            if (in_array($phrase, $focus_l, true)) {
                continue;
            }
            $out[] = [
                'keyword' => $phrase,
                'why' => __('Frequent on-site terms without a dedicated focus keyword page', 'pylon-seo'),
                'score' => 40 + min(40, (int) (($bag[$top[$i]] ?? 0) + ($bag[$top[$i + 1]] ?? 0))),
            ];
        }
        return array_slice($out, 0, 15);
    }

    private function from_aeo_questions(): array {
        global $wpdb;
        $out = [];
        $meta = $wpdb->get_results(
            "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ('pylon_aeo_question','pylon_aeo_keywords') AND meta_value<>'' LIMIT 100",
            ARRAY_A
        );
        foreach ($meta ?: [] as $row) {
            $val = trim((string) $row['meta_value']);
            if ($val === '') {
                continue;
            }
            if ($row['meta_key'] === 'pylon_aeo_keywords') {
                foreach (preg_split('/[,;\n]+/', $val) ?: [] as $part) {
                    $part = trim($part);
                    if ($part !== '') {
                        $out[] = [
                            'keyword' => $part,
                            'why' => __('Saved AEO keyword — expand into FAQ / Speakable block', 'pylon-seo'),
                            'score' => 55,
                        ];
                    }
                }
            } else {
                $out[] = [
                    'keyword' => $val,
                    'why' => __('AEO question on a post — optimize for AI Overviews', 'pylon-seo'),
                    'score' => 70,
                ];
            }
        }
        // Generate question templates from top focus keywords.
        $focus = $wpdb->get_col("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='pylon_focus_keyword' AND meta_value<>'' LIMIT 30");
        foreach ($focus ?: [] as $kw) {
            $kw = trim((string) $kw);
            if ($kw === '') {
                continue;
            }
            $out[] = [
                'keyword' => sprintf(
                    /* translators: %s: focus keyword. */
                    __('What is %s?', 'pylon-seo'),
                    $kw
                ),
                'why' => __('Generated AEO question from focus keyword', 'pylon-seo'),
                'score' => 50,
            ];
            $out[] = [
                'keyword' => sprintf(
                    /* translators: %s: focus keyword. */
                    __('How to choose %s', 'pylon-seo'),
                    $kw
                ),
                'why' => __('How-to intent for AI answer engines', 'pylon-seo'),
                'score' => 48,
            ];
        }
        // Dedupe
        $seen = [];
        $uniq = [];
        foreach ($out as $row) {
            $k = strtolower($row['keyword']);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $uniq[] = $row;
        }
        usort($uniq, static function ($a, $b) {
            return ($b['score'] <=> $a['score']);
        });
        return array_slice($uniq, 0, 20);
    }

    private function cluster_count(): int {
        $raw = get_option('pylon_topic_clusters', []);
        return is_array($raw) ? count($raw) : 0;
    }

    private function stopwords(): array {
        return ['that', 'this', 'with', 'from', 'your', 'have', 'will', 'about', 'into', 'than', 'then', 'them', 'they', 'were', 'been', 'what', 'when', 'where', 'which', 'while', 'page', 'post', 'home', 'blog'];
    }
}
