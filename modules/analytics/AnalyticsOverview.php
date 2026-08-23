<?php
namespace Pylon\Core\Modules\Analytics;
defined('ABSPATH') || exit;
/**
 * Analytics overview page — aggregates audit history, redirect and 404 data.
 */
class AnalyticsOverview {

    public function register(): void {
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(string $hook): void {
        if (strpos($hook, 'pylon-analytics') === false && strpos($hook, 'pylon-group-analytics') === false) return;
        wp_enqueue_style('pylon-analytics', PYLON_URL . 'assets/css/modules/analytics.css', ['pylon-admin'], filemtime(PYLON_PATH . 'assets/css/modules/analytics.css'));
    }

    public function render_admin_page(): void {
        $audits  = $this->get_audit_trend();
        $issues  = $this->get_redirect_404_totals();
        $score   = $this->get_latest_scores();
        ?>
        <div class="pylon-analytics-root">
            <div class="pylon-status-grid" style="grid-template-columns:repeat(4,1fr);">
                <div class="pylon-status-card">
                    <div class="pylon-status-icon">⭐</div>
                    <div class="pylon-status-value"><?php echo (int) $score['avg']; ?></div>
                    <div class="pylon-status-label"><?php esc_html_e('Avg SEO Score', 'pylon-seo'); ?></div>
                    <div class="pylon-text-11 pylon-color-muted"><?php echo esc_html($score['grade']); ?></div>
                </div>
                <div class="pylon-status-card">
                    <div class="pylon-status-icon">🔀</div>
                    <div class="pylon-status-value"><?php echo (int) $issues['redirects']; ?></div>
                    <div class="pylon-status-label"><?php esc_html_e('Redirects', 'pylon-seo'); ?></div>
                    <div class="pylon-text-11 pylon-color-muted"><?php esc_html_e('active rules', 'pylon-seo'); ?></div>
                </div>
                <div class="pylon-status-card">
                    <div class="pylon-status-icon">🚫</div>
                    <div class="pylon-status-value" style="color:<?php echo $issues['404s'] > 0 ? 'var(--pylon-danger)' : 'var(--pylon-success)'; ?>;"><?php echo (int) $issues['404s']; ?></div>
                    <div class="pylon-status-label"><?php esc_html_e('404 Errors', 'pylon-seo'); ?></div>
                    <div class="pylon-text-11 pylon-color-muted"><?php esc_html_e('logged 404s', 'pylon-seo'); ?></div>
                </div>
                <div class="pylon-status-card">
                    <div class="pylon-status-icon">🗺️</div>
                    <div class="pylon-status-value"><?php echo (int) $issues['pages']; ?></div>
                    <div class="pylon-status-label"><?php esc_html_e('Published Pages', 'pylon-seo'); ?></div>
                    <div class="pylon-text-11 pylon-color-muted"><?php esc_html_e('posts & pages', 'pylon-seo'); ?></div>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-top:18px;">
                <div class="pylon-card">
                    <div class="pylon-card-header">
                        <h3>📉 <?php esc_html_e('Audit Score Trend', 'pylon-seo'); ?></h3>
                        <span class="pylon-text-12 pylon-color-muted"><?php esc_html_e('average across audits', 'pylon-seo'); ?></span>
                    </div>
                    <div class="pylon-card-body" style="min-height:200px;">
                        <?php if (!empty($audits['scores'])): ?>
                            <?php echo wp_kses($audits['chart'], \Pylon\Core\ChartRenderer::allowed_html()); ?>
                        <?php else: ?>
                            <div class="pylon-text-center pylon-color-muted" style="padding:60px 20px;">
                                <p style="margin:0;"><?php esc_html_e('Run a few audits to start building your score trend.', 'pylon-seo'); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="pylon-card">
                    <div class="pylon-card-header">
                        <h3>🔧 <?php esc_html_e('Most Common Issues', 'pylon-seo'); ?></h3>
                        <span class="pylon-text-12 pylon-color-muted"><?php esc_html_e('from recent audits', 'pylon-seo'); ?></span>
                    </div>
                    <div class="pylon-card-body" style="min-height:200px;">
                        <?php if (!empty($audits['issues'])): ?>
                            <?php echo wp_kses_post($audits['issues_html']); ?>
                        <?php else: ?>
                            <div class="pylon-text-center pylon-color-muted" style="padding:60px 20px;">
                                <p style="margin:0;"><?php esc_html_e('No audit issues recorded yet.', 'pylon-seo'); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php do_action('pylon/analytics/after_cards'); ?>
        </div>
        <?php
    }

    private function get_audit_trend(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'pylon_audit_history';
        $rows = $wpdb->get_results(
            "SELECT DATE(checked_at) AS day, ROUND(AVG(score)) AS avg_score
             FROM {$table}
             GROUP BY DATE(checked_at)
             ORDER BY day ASC
             LIMIT 60"
        );
        $scores = [];
        $labels = [];
        $issues = [];
        $issue_counts = [];
        foreach ($rows as $row) {
            $scores[] = (int) $row->avg_score;
            $labels[] = date_i18n('M j', strtotime($row->day));
        }
        $chart = '';
        if (count($scores) > 0) {
            $latest = end($scores);
            $color = $latest >= 70 ? '#22c55e' : ($latest >= 40 ? '#f59e0b' : '#ef4444');
            $chart = \Pylon\Core\ChartRenderer::line([
                ['name' => __('Avg Score', 'pylon-seo'), 'color' => $color, 'data' => $scores],
            ], [
                'width' => 480,
                'height' => 180,
                'y_min' => 0,
                'y_max' => 100,
                'y_ticks' => 4,
                'x_labels' => $labels,
                'x_label_every' => max(1, (int) (count($labels) / 7)),
                'fill' => true,
                'legend' => false,
            ]);
        }

        // Top issues from cached audits on posts.
        global $wpdb;
        $cached = $wpdb->get_results("SELECT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key = 'pylon_audit_cache' ORDER BY meta_id DESC LIMIT 10");
        foreach ($cached as $c) {
            $meta = get_post_meta($c->post_id, 'pylon_audit_cache', true);
            if (!is_array($meta) || empty($meta['results'])) continue;
            foreach ($meta['results'] as $r) {
                if (($r['status'] ?? '') === 'fail') {
                    $key = $r['label'] ?? 'Unknown';
                    $issue_counts[$key] = ($issue_counts[$key] ?? 0) + 1;
                }
            }
        }
        arsort($issue_counts);
        $issue_counts = array_slice($issue_counts, 0, 6, true);
        $issue_items = [];
        $max_issue = 1;
        foreach ($issue_counts as $label => $count) {
            $max_issue = max($max_issue, $count);
        }
        foreach ($issue_counts as $label => $count) {
            $issue_items[] = [
                'label' => wp_trim_words($label, 8),
                'value' => $count,
                'color' => '#ef4444',
                'hint' => $count . '×',
            ];
        }
        $issues_html = $issue_items ? \Pylon\Core\ChartRenderer::hbars($issue_items, ['max' => $max_issue]) : '';

        return ['scores' => $scores, 'chart' => $chart, 'issues' => $issue_items, 'issues_html' => $issues_html];
    }

    private function get_redirect_404_totals(): array {
        global $wpdb;
        $redirects = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pylon_redirects");
        $not_found = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pylon_404_log");
        $post_count = wp_count_posts('post')->publish ?? 0;
        $page_count = wp_count_posts('page')->publish ?? 0;
        return ['redirects' => $redirects, '404s' => $not_found, 'pages' => $post_count + $page_count];
    }

    private function get_latest_scores(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'pylon_audit_history';
        $latest = (int) $wpdb->get_var("SELECT score FROM {$table} ORDER BY checked_at DESC LIMIT 1");
        $grade = $latest >= 70 ? __('Good', 'pylon-seo') : ($latest >= 40 ? __('Ok', 'pylon-seo') : __('Poor', 'pylon-seo'));
        return ['avg' => $latest, 'grade' => $grade];
    }

}
