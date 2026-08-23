<?php
namespace Pylon\Core\Modules\Pulse;
defined('ABSPATH') || exit;
class PulseDashboard {
    public function register(): void {
        add_action('wp_ajax_pylon_pulse_data', [$this, 'ajax_pulse_data']);
        add_action('wp_ajax_pylon_pulse_worst', [$this, 'ajax_pulse_worst']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('pylon_dashboard_pulse', [$this, 'render_pulse_section']);
        add_action('save_post', [$this, 'clear_cache']);
    }

    public function enqueue(string $hook): void {
        if (strpos($hook, 'toplevel_page_pylon') === false && strpos($hook, 'pylon-pulse') === false) return;
        wp_enqueue_style('pylon-pulse', PYLON_URL . 'assets/css/modules/pulse.css', ['pylon-admin'], filemtime(PYLON_PATH . 'assets/css/modules/pulse.css'));
        wp_add_inline_script('pylon-admin-js', $this->pulse_js());
    }

    public function render_pulse_section(): void {
        ?>
        <div class="pylon-card pp-root" id="pylon-pulse-card">
            <div class="pylon-card-header">
                <div style="display:flex;align-items:center;gap:10px;">
                    <span style="font-size:20px;">ðŸ“Š</span>
                    <div>
                        <h3 style="margin:0;font-size:16px;"><?php esc_html_e('SEO Health Pulse', 'pylon-seo'); ?></h3>
                        <span class="pylon-text-12 pylon-color-muted"><?php esc_html_e('Site-wide SEO health snapshot', 'pylon-seo'); ?></span>
                    </div>
                </div>
            </div>
            <div id="pylon-pulse-loading" style="padding:50px;text-align:center;">
                <div class="pulse-spinner"></div>
                <p style="margin-top:14px;color:#6b7280;font-size:13px;"><?php esc_html_e('Scanning all pagesâ€¦', 'pylon-seo'); ?></p>
            </div>
            <div id="pylon-pulse-dashboard" style="display:none;padding:4px 0;">
                <div class="pp-grid pp-grid-4" id="pp-summary"></div>
                <div style="text-align:center;margin-top:20px;padding:8px 0 4px;">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=pylon-group-audit&tab=seo-pulse')); ?>" class="pylon-btn pylon-btn-primary">
                        <?php esc_html_e('View Full Reports', 'pylon-seo'); ?> â†’
                    </a>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_pulse_page(): void {
        $data = get_transient('pylon_pulse_data');
        $has_data = $data !== false && !empty($data['total_pages']);
        ?>
        <div class="pylon-card-header">
            <div style="display:flex;align-items:center;gap:10px;">
                <span style="font-size:20px;">ðŸ“Š</span>
                <div>
                    <h3><?php esc_html_e('SEO Health Pulse', 'pylon-seo'); ?></h3>
                    <span class="pylon-text-12 pylon-color-muted"><?php esc_html_e('Full site-wide SEO health report', 'pylon-seo'); ?></span>
                </div>
            </div>
            </div>
        <?php if ($has_data): ?>
        <div id="pylon-pulse-loading" style="display:none;padding:50px;text-align:center;">
            <div class="pulse-spin"></div>
            <p style="margin-top:14px;color:#6b7280;font-size:13px;"><?php esc_html_e('Scanning all pagesâ€¦', 'pylon-seo'); ?></p>
        </div>
        <div id="pylon-pulse-dashboard">
            <?php $this->render_pulse_summary($data); ?>
            <div class="pulse-grid pulse-grid-2" style="margin-top:18px;">
                <div class="pulse-chart">
                    <div class="pulse-chart-title">ðŸ“ˆ <?php esc_html_e('Score Distribution', 'pylon-seo'); ?></div>
                    <div id="pp-chart-dist" class="pulse-chart-body"><?php $this->render_dist_chart_svg($data['buckets'] ?? [], $data['total_pages'] ?? 0); ?></div>
                </div>
                <div class="pulse-chart">
                    <div class="pulse-chart-title">ðŸ“‰ <?php esc_html_e('Average Score Trend', 'pylon-seo'); ?></div>
                    <div id="pp-chart-trend" class="pulse-chart-body"><?php $this->render_trend_chart_svg($data['history'] ?? [], $data['avg_score'] ?? 0); ?></div>
                </div>
            </div>
            <div class="pulse-chart" style="margin-top:18px;">
                <div class="pulse-chart-title">âš ï¸ <?php esc_html_e('Top Issues Across All Pages', 'pylon-seo'); ?></div>
                <div id="pp-issues" class="pulse-chart-body"><?php $this->render_issues_list($data['issues'] ?? []); ?></div>
            </div>
            <div class="pulse-chart" style="margin-top:18px;">
                <div class="pulse-chart-title">ðŸ—ºï¸ <?php esc_html_e('XML Sitemap Health', 'pylon-seo'); ?></div>
                <div id="pp-sitemap" class="pulse-chart-body"><?php $this->render_sitemap_block($data['sitemap'] ?? []); ?></div>
            </div>
            <div class="pulse-chart" style="margin-top:18px;">
                <div class="pulse-chart-title">â†©ï¸ <?php esc_html_e('Redirects & 404s', 'pylon-seo'); ?></div>
                <div id="pp-redirects" class="pulse-chart-body"><?php $this->render_redirects_block($data['redirects'] ?? []); ?></div>
            </div>
            <div class="pulse-chart" style="margin-top:18px;">
                <div class="pulse-chart-title" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                    <span>ðŸ”§ <?php esc_html_e('Pages Needing Attention', 'pylon-seo'); ?></span>
                    <span id="pp-table-info" style="font-size:11px;font-weight:400;color:#6b7280;"></span>
                </div>
                <div class="pulse-table-wrap">
                    <table class="pulse-table" id="pp-worst">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Page', 'pylon-seo'); ?></th>
                                <th style="width:70px;text-align:center;"><?php esc_html_e('Score', 'pylon-seo'); ?></th>
                                <th style="width:140px;"><?php esc_html_e('Missing', 'pylon-seo'); ?></th>
                                <th style="width:60px;text-align:center;"></th>
                            </tr>
                        </thead>
                        <tbody><?php $this->render_worst_rows(array_slice($data['worst'] ?? [], 0, 40)); ?></tbody>
                    </table>
                </div>
                <div id="pp-pagination" style="display:flex;align-items:center;justify-content:center;gap:6px;padding:14px 0 4px;flex-wrap:wrap;"></div>
            </div>
        </div>
        <?php
        wp_add_inline_script('pylon-admin-js', 'window.pulseData = ' . wp_json_encode($data) . '; var totalWorst = ' . (int) ($data['total_worst'] ?? count($data['worst'] ?? [])) . '; window.totalWorstItems = totalWorst; window.totalWorstPages = Math.ceil(totalWorst / 40) || 1; window.currentWorstPage = 1;');
        ?>
        <?php else: ?>
        <div id="pylon-pulse-loading" style="padding:50px;text-align:center;">
            <div class="pulse-spin"></div>
            <p style="margin-top:14px;color:#6b7280;font-size:13px;"><?php esc_html_e('Scanning all pagesâ€¦', 'pylon-seo'); ?></p>
        </div>
        <div id="pylon-pulse-dashboard" style="display:none;">
            <div class="pulse-grid pulse-grid-4" id="pp-summary"></div>
            <div class="pulse-grid pulse-grid-2" style="margin-top:18px;">
                <div class="pulse-chart">
                    <div class="pulse-chart-title">ðŸ“ˆ <?php esc_html_e('Score Distribution', 'pylon-seo'); ?></div>
                    <div id="pp-chart-dist" class="pulse-chart-body"></div>
                </div>
                <div class="pulse-chart">
                    <div class="pulse-chart-title">ðŸ“‰ <?php esc_html_e('Average Score Trend', 'pylon-seo'); ?></div>
                    <div id="pp-chart-trend" class="pulse-chart-body"></div>
                </div>
            </div>
            <div class="pulse-chart" style="margin-top:18px;">
                <div class="pulse-chart-title">âš ï¸ <?php esc_html_e('Top Issues Across All Pages', 'pylon-seo'); ?></div>
                <div id="pp-issues" class="pulse-chart-body"></div>
            </div>
            <div class="pulse-chart" style="margin-top:18px;">
                <div class="pulse-chart-title">ðŸ—ºï¸ <?php esc_html_e('XML Sitemap Health', 'pylon-seo'); ?></div>
                <div id="pp-sitemap" class="pulse-chart-body"></div>
            </div>
            <div class="pulse-chart" style="margin-top:18px;">
                <div class="pulse-chart-title">â†©ï¸ <?php esc_html_e('Redirects & 404s', 'pylon-seo'); ?></div>
                <div id="pp-redirects" class="pulse-chart-body"></div>
            </div>
            <div class="pulse-chart" style="margin-top:18px;">
                <div class="pulse-chart-title" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                    <span>ðŸ”§ <?php esc_html_e('Pages Needing Attention', 'pylon-seo'); ?></span>
                    <span id="pp-table-info" style="font-size:11px;font-weight:400;color:#6b7280;"></span>
                </div>
                <div class="pulse-table-wrap">
                    <table class="pulse-table" id="pp-worst">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Page', 'pylon-seo'); ?></th>
                                <th style="width:70px;text-align:center;"><?php esc_html_e('Score', 'pylon-seo'); ?></th>
                                <th style="width:140px;"><?php esc_html_e('Missing', 'pylon-seo'); ?></th>
                                <th style="width:60px;text-align:center;"></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
                <div id="pp-pagination" style="display:flex;align-items:center;justify-content:center;gap:6px;padding:14px 0 4px;flex-wrap:wrap;"></div>
            </div>
        </div>
        <?php endif; ?>
        <?php
    }

    private function render_pulse_summary(array $data): void {
        $total = $data['total_pages'] ?? 0;
        $avg = $data['avg_score'] ?? 0;
        $good = $data['buckets']['good'] ?? 0;
        $poor = $data['buckets']['poor'] ?? 0;
        $good_pct = $data['good_pct'] ?? ($total > 0 ? round($good / $total * 100) : 0);
        $avg_grade = $data['avg_grade'] ?? ($avg >= 70 ? 'Good' : ($avg >= 40 ? 'Ok' : 'Poor'));
        $cards = [
            ['icon' => 'ðŸ“„', 'val' => $total, 'lab' => __('Pages Analyzed', 'pylon-seo'), 'sub' => __('published posts & pages', 'pylon-seo'), 'bg' => 'linear-gradient(135deg,#6366f1,#8b5cf6)'],
            ['icon' => 'â­', 'val' => $avg, 'lab' => __('Average Score', 'pylon-seo'), 'sub' => $avg_grade, 'bg' => $avg >= 70 ? 'linear-gradient(135deg,#16a34a,#22c55e)' : ($avg >= 40 ? 'linear-gradient(135deg,#f59e0b,#fbbf24)' : 'linear-gradient(135deg,#dc2626,#ef4444)')],
            ['icon' => 'âœ…', 'val' => $good, 'lab' => __('Good Pages', 'pylon-seo'), 'sub' => $good_pct . '% ' . __('of total', 'pylon-seo'), 'bg' => 'linear-gradient(135deg,#059669,#34d399)'],
            ['icon' => 'âš ï¸', 'val' => $poor, 'lab' => __('Poor Pages', 'pylon-seo'), 'sub' => $total > 0 ? round($poor / $total * 100) . '% ' . __('need work', 'pylon-seo') : '0%', 'bg' => 'linear-gradient(135deg,#dc2626,#f87171)'],
        ];
        echo '<div class="pulse-grid pulse-grid-4" id="pp-summary">';
        foreach ($cards as $c) {
            echo '<div class="pulse-stat" style="background:' . esc_attr($c['bg']) . ';color:#fff;">';
            echo '<div class="pulse-stat-icon">' . esc_html($c['icon']) . '</div>';
            echo '<div class="pulse-stat-value">' . esc_html($c['val']) . '</div>';
            echo '<div class="pulse-stat-label">' . esc_html($c['lab']) . '</div>';
            if (!empty($c['sub'])) {
                echo '<div class="pulse-stat-sub">' . esc_html($c['sub']) . '</div>';
            }
            echo '</div>';
        }
        echo '</div>';
    }

    private function render_dist_chart_svg(array $buckets, int $total): void {
        if (!$total) {
            echo '<div style="color:#94a3b8;text-align:center;padding-top:70px;font-size:13px;">' . esc_html__('No data yet.', 'pylon-seo') . '</div>';
            return;
        }
        $segments = [
            ['label' => __('Good 70-100', 'pylon-seo'), 'value' => $buckets['good'] ?? 0, 'color' => '#22c55e'],
            ['label' => __('Ok 40-69', 'pylon-seo'), 'value' => $buckets['ok'] ?? 0, 'color' => '#f59e0b'],
            ['label' => __('Poor 0-39', 'pylon-seo'), 'value' => $buckets['poor'] ?? 0, 'color' => '#ef4444'],
        ];
        $good_pct = $total > 0 ? round(($buckets['good'] ?? 0) / $total * 100) : 0;
        ?>
        <?php $dist_donut = \Pylon\Core\ChartRenderer::donut($segments, [
            'size' => 170,
            'thickness' => 26,
            'center_value' => $good_pct . '%',
            'center_label' => __('Good', 'pylon-seo'),
        ]); ?>
        <div style="display:flex;align-items:center;gap:24px;flex-wrap:wrap;">
            <div style="flex-shrink:0;width:180px;"><?php echo wp_kses($dist_donut, \Pylon\Core\ChartRenderer::allowed_html()); ?></div>
            <div style="flex:1;min-width:180px;">
                <?php foreach ($segments as $seg): ?>
                    <div style="display:flex;align-items:center;gap:10px;padding:7px 0;">
                        <span style="width:12px;height:12px;border-radius:3px;background:<?php echo esc_attr($seg['color']); ?>;flex-shrink:0;"></span>
                        <span style="flex:1;font-size:13px;color:#334155;"><?php echo esc_html($seg['label']); ?></span>
                        <span style="font-size:13px;font-weight:700;color:#1e293b;"><?php echo (int) $seg['value']; ?></span>
                        <span style="width:46px;text-align:right;font-size:11px;color:#94a3b8;"><?php echo $total > 0 ? esc_html(round($seg['value'] / $total * 100) . '%') : 'â€”'; ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    private function render_trend_chart_svg(array $history, int $currentAvg): void {
        $pts = $history ?: [];
        if (count($pts) < 2 && $currentAvg) {
            $pts[] = ['avg' => $currentAvg, 'time' => current_time('mysql')];
        }
        if (count($pts) < 2) {
            echo '<div style="color:#94a3b8;text-align:center;padding-top:70px;font-size:13px;">' . esc_html__('Run audits over time to see trends.', 'pylon-seo') . '</div>';
            return;
        }
        $scores = array_map(fn($p) => (float) ($p['avg'] ?? 0), $pts);
        $labels = array_map(fn($p) => wp_date('M j', strtotime($p['time'] ?? 'now')), $pts);
        $color  = $currentAvg >= 70 ? '#22c55e' : ($currentAvg >= 40 ? '#f59e0b' : '#ef4444');
        $trend_chart = \Pylon\Core\ChartRenderer::line([
            ['name' => __('Avg Score', 'pylon-seo'), 'color' => $color, 'data' => $scores],
        ], [
            'width' => 480,
            'height' => 170,
            'y_min' => 0,
            'y_max' => 100,
            'y_ticks' => 4,
            'x_labels' => $labels,
            'x_label_every' => max(1, (int) (count($labels) / 8)),
            'fill' => true,
            'legend' => false,
        ]);
        echo wp_kses($trend_chart, \Pylon\Core\ChartRenderer::allowed_html());
    }

    private function render_issues_list(array $issues): void {
        if (empty($issues)) {
            echo '<div style="color:#16a34a;font-weight:500;font-size:13px;">âœ… ' . esc_html__('No major issues found â€” your site looks healthy!', 'pylon-seo') . '</div>';
            return;
        }
        $maxCount = $issues[0]['count'] ?? 1;
        foreach ($issues as $issue) {
            $pct = ($issue['count'] / $maxCount) * 100;
            $color = $issue['pct'] > 50 ? '#ef4444' : ($issue['pct'] > 20 ? '#f59e0b' : '#3b82f6');
            echo '<div style="display:flex;align-items:center;gap:14px;padding:4px 0;">';
            echo '<div style="width:180px;font-size:12px;color:#334155;font-weight:500;flex-shrink:0;">' . esc_html($issue['label']) . '</div>';
            echo '<div style="flex:1;height:26px;background:#f1f5f9;border-radius:8px;overflow:hidden;position:relative;">';
            echo '<div style="width:' . esc_attr($pct) . '%;height:100%;background:' . esc_attr($color) . ';border-radius:8px;opacity:0.8;"></div>';
            echo '</div>';
            echo '<div style="width:50px;text-align:right;font-size:13px;font-weight:700;color:' . esc_attr($color) . ';">' . (int) $issue['count'] . '</div>';
            echo '<div style="width:44px;text-align:right;font-size:11px;color:#94a3b8;">' . (int) $issue['pct'] . '%</div>';
            echo '</div>';
        }
    }

    private function render_sitemap_block(array $sm): void {
        if (empty($sm)) {
            echo '<div style="color:#94a3b8;font-size:13px;padding:10px 0;">' . esc_html__('No sitemap data.', 'pylon-seo') . '</div>';
            return;
        }
        $status_color = !empty($sm['enabled']) ? '#22c55e' : '#ef4444';
        $status_text = !empty($sm['enabled']) ? __('Enabled', 'pylon-seo') : __('Disabled', 'pylon-seo');
        $cache_color = !empty($sm['cache_ok']) ? '#22c55e' : '#f59e0b';
        $cache_text = !empty($sm['cache_ok']) ? __('Cached', 'pylon-seo') : __('Not cached (will generate on first visit)', 'pylon-seo');
        ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:12px;">
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:12px;text-align:center;">
                <div style="font-size:18px;font-weight:700;color:<?php echo esc_attr($status_color); ?>;"><?php echo esc_html($status_text); ?></div>
                <div style="font-size:10px;color:#64748b;margin-top:3px;"><?php esc_html_e('Sitemap Status', 'pylon-seo'); ?></div>
            </div>
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:12px;text-align:center;">
                <div style="font-size:18px;font-weight:700;color:#6366f1;"><?php echo (int) ($sm['total_urls'] ?? 0); ?></div>
                <div style="font-size:10px;color:#64748b;margin-top:3px;"><?php esc_html_e('Total URLs Indexed', 'pylon-seo'); ?></div>
            </div>
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:12px;text-align:center;">
                <div style="font-size:18px;font-weight:700;color:#6366f1;"><?php echo (int) ($sm['sitemap_pages'] ?? 0); ?></div>
                <div style="font-size:10px;color:#64748b;margin-top:3px;"><?php esc_html_e('Sitemap Pages', 'pylon-seo'); ?></div>
            </div>
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:12px;text-align:center;">
                <div style="font-size:18px;font-weight:700;color:<?php echo esc_attr($cache_color); ?>;"><?php echo esc_html($cache_text); ?></div>
                <div style="font-size:10px;color:#64748b;margin-top:3px;"><?php esc_html_e('Cache Status', 'pylon-seo'); ?></div>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <span style="font-size:11px;color:#64748b;"><?php esc_html_e('Post types:', 'pylon-seo'); ?> <strong style="color:#334155;"><?php echo esc_html($sm['post_types'] ?? ''); ?></strong></span>
            <a href="<?php echo esc_url($sm['sitemap_url'] ?? home_url('/sitemap.xml')); ?>" target="_blank" style="font-size:12px;text-decoration:none;color:#6366f1;"><?php esc_html_e('Open Sitemap â†’', 'pylon-seo'); ?></a>
        </div>
        <?php
    }

    private function render_redirects_block(array $rd): void {
        if (empty($rd) || empty($rd['has_table'])) {
            echo '<div style="color:#94a3b8;font-size:13px;padding:10px 0;">' . esc_html__('Redirect tables not found.', 'pylon-seo') . '</div>';
            return;
        }
        $inactive = $rd['inactive'] ?? 0;
        $last30 = $rd['404_last_30'] ?? 0;
        ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;">
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:12px;text-align:center;">
                <div style="font-size:18px;font-weight:700;color:#6366f1;"><?php echo (int) ($rd['active'] ?? 0); ?></div>
                <div style="font-size:10px;color:#64748b;margin-top:3px;"><?php esc_html_e('Active Redirects', 'pylon-seo'); ?></div>
            </div>
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:12px;text-align:center;">
                <div style="font-size:18px;font-weight:700;color:<?php echo $inactive > 0 ? '#f59e0b' : '#22c55e'; ?>;"><?php echo (int) $inactive; ?></div>
                <div style="font-size:10px;color:#64748b;margin-top:3px;"><?php esc_html_e('Inactive Redirects', 'pylon-seo'); ?></div>
            </div>
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:12px;text-align:center;">
                <div style="font-size:18px;font-weight:700;color:<?php echo $last30 > 0 ? '#ef4444' : '#22c55e'; ?>;"><?php echo (int) $last30; ?></div>
                <div style="font-size:10px;color:#64748b;margin-top:3px;"><?php esc_html_e('404s (30 days)', 'pylon-seo'); ?></div>
            </div>
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:12px;text-align:center;">
                <div style="font-size:18px;font-weight:700;color:#6366f1;"><?php echo (int) ($rd['404_total'] ?? 0); ?></div>
                <div style="font-size:10px;color:#64748b;margin-top:3px;"><?php esc_html_e('Total 404s', 'pylon-seo'); ?></div>
            </div>
        </div>
        <?php
    }

    private function render_worst_rows(array $items): void {
        if (empty($items)) {
            echo '<tr><td colspan="4" style="text-align:center;padding:30px;color:#94a3b8;font-size:13px;">âœ… ' . esc_html__('All pages look good!', 'pylon-seo') . '</td></tr>';
            return;
        }
        foreach ($items as $p) {
            $score = (int) ($p['score'] ?? 0);
            $color = $score >= 70 ? '#22c55e' : ($score >= 40 ? '#f59e0b' : '#ef4444');
            $title = esc_html($p['title'] ?? '');
            $edit_url = esc_url($p['edit_url'] ?? '');
            $missing_html = '';
            if (!empty($p['missing'])) {
                $parts = explode(', ', $p['missing']);
                foreach ($parts as $m) {
                    $m = trim($m);
                    $cls = $m === 'title' ? 'm-title' : ($m === 'desc' ? 'm-desc' : 'm-kw');
                    $lab = $m === 'title' ? 'title' : ($m === 'desc' ? 'desc' : 'keyword');
                    $missing_html .= '<span class="pulse-tag ' . $cls . '">' . esc_html($lab) . '</span> ';
                }
            } else {
                $missing_html = '<span style="color:#94a3b8;font-size:11px;">' . esc_html($p['missing'] ?? '') . '</span>';
            }
            echo '<tr>';
            echo '<td><a href="' . esc_url($edit_url) . '" class="pulse-plink">' . esc_html($title) . '</a></td>';
            echo '<td style="text-align:center;"><span class="pulse-badge" style="background:' . esc_attr($color) . ';">' . esc_html($score) . '</span></td>';
            echo '<td>' . wp_kses_post($missing_html) . '</td>';
            echo '<td style="text-align:center;"><a href="' . esc_url($edit_url) . '" style="text-decoration:none;font-size:18px;color:#94a3b8;transition:color 0.15s;" title="' . esc_attr__('Edit', 'pylon-seo') . '">âœŽ</a></td>';
            echo '</tr>';
        }
    }

    public function clear_cache(): void {
        delete_transient('pylon_pulse_data');
        delete_option('pylon_pulse_history');
    }

    public function ajax_pulse_data(): void {
        check_ajax_referer('pylon_admin_nonce', '_ajax_nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'pylon-seo')]);
        }

        $force_refresh = !empty($_POST['refresh']);
        $scan_id = sanitize_key($_POST['scan_id'] ?? '');

        if (!$force_refresh && !$scan_id) {
            $cached = get_transient('pylon_pulse_data');
            if ($cached !== false) {
                $resp = $cached;
                $resp['total_worst'] = count($cached['worst'] ?? []);
                $resp['worst'] = array_slice($cached['worst'] ?? [], 0, 40);
                wp_send_json_success($resp);
            }
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(30);
        }

        $meta_defs = [
            'pylon_title' => ['label' => __('Missing SEO title', 'pylon-seo'), 'count' => 0],
            'pylon_description' => ['label' => __('Missing meta description', 'pylon-seo'), 'count' => 0],
            'pylon_focus_keyword' => ['label' => __('Missing focus keyword', 'pylon-seo'), 'count' => 0],
            'pylon_canonical' => ['label' => __('Missing canonical URL', 'pylon-seo'), 'count' => 0],
            'noindex_off' => ['label' => __('Noindex not set', 'pylon-seo'), 'count' => 0],
        ];

        if (!$scan_id) {
            $scan_id = wp_hash('pulse_scan_' . time() . '_' . wp_rand());
            $post_types = array_values(array_diff(get_post_types(['public' => true], 'names'), ['attachment']));
            $max_posts = max(200, min((int) get_option('pylon_pulse_max_pages', 2000), 5000));
            $estimated = 0;
            foreach ($post_types as $pt) {
                $counts = wp_count_posts($pt);
                $estimated += (int) ($counts->publish ?? 0);
            }
            $estimated = min($estimated, $max_posts);
            $state = [
                'scan_id' => $scan_id,
                'post_types' => $post_types,
                'pt_index' => 0,
                'page' => 1,
                'batch_size' => 250,
                'max_posts' => $max_posts,
                'total_processed' => 0,
                'sum' => 0,
                'buckets' => ['good' => 0, 'ok' => 0, 'poor' => 0],
                'worst' => [],
                'meta_checks' => $meta_defs,
                'estimated_total' => $estimated,
                'scan_truncated' => false,
            ];
        } else {
            $state = get_transient('pylon_pulse_scan_' . $scan_id);
            if (!$state) {
                wp_send_json_error(['message' => __('Scan expired or invalid. Please try again.', 'pylon-seo')]);
                return;
            }
        }

        $complete = $this->process_scan_batch($state);

        if ($complete) {
            delete_transient('pylon_pulse_scan_' . $scan_id);
            $data = $this->finalize_scan($state);

            set_transient('pylon_pulse_data', $data, 6 * HOUR_IN_SECONDS);

            $history = get_option('pylon_pulse_history', []);
            $history[] = ['avg' => $data['avg_score'], 'good_pct' => $data['good_pct'], 'total' => $data['total_pages'], 'time' => current_time('mysql')];
            if (count($history) > 30) array_shift($history);
            update_option('pylon_pulse_history', $history, false);

            $data['total_worst'] = count($data['worst'] ?? []);
            $data['worst'] = array_slice($data['worst'] ?? [], 0, 40);
            wp_send_json_success($data);
        } else {
            set_transient('pylon_pulse_scan_' . $scan_id, $state, 10 * MINUTE_IN_SECONDS);
            wp_send_json_success([
                'scan_id' => $scan_id,
                'scan_complete' => false,
                'progress' => $state['estimated_total'] > 0 ? round($state['total_processed'] / $state['estimated_total'] * 100) : 0,
                'processed' => $state['total_processed'],
                'estimated' => $state['estimated_total'],
            ]);
        }
    }

    private function process_scan_batch(array &$state): bool {
        $max_posts = $state['max_posts'];

        while ($state['pt_index'] < count($state['post_types'])) {
            $pt = $state['post_types'][$state['pt_index']];
            $query = new \WP_Query([
                'post_type' => $pt,
                'post_status' => 'publish',
                'posts_per_page' => $state['batch_size'],
                'paged' => $state['page'],
                'fields' => 'ids',
                'no_found_rows' => true,
            ]);

            $post_ids = $query->posts;
            if (empty($post_ids)) {
                $state['pt_index']++;
                $state['page'] = 1;
                continue;
            }

            _prime_post_caches($post_ids, true, true);

            foreach ($post_ids as $post_id) {
                if ($state['total_processed'] >= $max_posts) {
                    $state['scan_truncated'] = true;
                    return true;
                }
                $state['total_processed']++;
                $post = get_post($post_id);
                $title = get_post_meta($post_id, 'pylon_title', true);
                $desc = get_post_meta($post_id, 'pylon_description', true);
                $kw = get_post_meta($post_id, 'pylon_focus_keyword', true);
                $canonical = get_post_meta($post_id, 'pylon_canonical', true);
                $noindex = get_post_meta($post_id, 'pylon_noindex', true);

                $score = 0;

                if (!empty($title)) {
                    $score += 15;
                    $len = mb_strlen($title);
                    if ($len >= 10 && $len <= 70) $score += 10;
                } else {
                    $state['meta_checks']['pylon_title']['count']++;
                }

                if (!empty($desc)) {
                    $score += 15;
                    $len = mb_strlen($desc);
                    if ($len >= 50 && $len <= 160) $score += 10;
                } else {
                    $state['meta_checks']['pylon_description']['count']++;
                }

                if (!empty($kw)) {
                    $score += 10;
                    $content = $post->post_content;
                    if (function_exists('mb_stripos') && mb_stripos($content, $kw) !== false) $score += 10;
                } else {
                    $state['meta_checks']['pylon_focus_keyword']['count']++;
                }

                $wc = str_word_count(wp_strip_all_tags($post->post_content));
                if ($wc >= 600) $score += 15;
                elseif ($wc >= 300) $score += 10;
                elseif ($wc >= 150) $score += 5;

                if (!empty($canonical)) {
                    $score += 10;
                } else {
                    $state['meta_checks']['pylon_canonical']['count']++;
                }

                if (empty($noindex)) {
                    $score += 5;
                } else {
                    $state['meta_checks']['noindex_off']['count']++;
                }

                $score = min($score, 100);
                $state['sum'] += $score;

                if ($score >= 70) $state['buckets']['good']++;
                elseif ($score >= 40) $state['buckets']['ok']++;
                else $state['buckets']['poor']++;

                if ($score < 70) {
                    $missing = [];
                    if (empty($title)) $missing[] = 'title';
                    if (empty($desc)) $missing[] = 'desc';
                    if (empty($kw)) $missing[] = 'kw';
                    $state['worst'][] = [
                        'id' => $post_id,
                        'title' => $post->post_title,
                        'edit_url' => get_edit_post_link($post_id),
                        'score' => $score,
                        'missing' => implode(', ', $missing),
                    ];
                }
            }
            wp_reset_postdata();

            $state['page']++;
            return false;
        }

        return true;
    }

    private function finalize_scan(array $state): array {
        $total = $state['total_processed'];
        $avg = $total > 0 ? round($state['sum'] / $total, 1) : 0;
        $good_pct = $total > 0 ? round($state['buckets']['good'] / $total * 100) : 0;

        $issues_list = [];
        foreach ($state['meta_checks'] as $key => $check) {
            if ($check['count'] > 0) {
                $issues_list[] = [
                    'label' => $check['label'],
                    'count' => $check['count'],
                    'pct' => $total > 0 ? round($check['count'] / $total * 100) : 0,
                ];
            }
        }
        usort($issues_list, fn($a, $b) => $b['count'] - $a['count']);

        usort($state['worst'], fn($a, $b) => $a['score'] - $b['score']);

        $history = get_option('pylon_pulse_history', []);

        $sitemap = $this->compute_sitemap_data();
        $redirects_data = $this->compute_redirects_data();

        return [
            'total_pages' => $total,
            'avg_score' => $avg,
            'avg_grade' => $avg >= 70 ? 'Good' : ($avg >= 40 ? 'Ok' : 'Poor'),
            'good_pct' => $good_pct,
            'buckets' => $state['buckets'],
            'issues' => $issues_list,
            'worst' => $state['worst'],
            'history' => $history,
            'computed_at' => current_time('mysql'),
            'sitemap' => $sitemap,
            'redirects' => $redirects_data,
            'scan_truncated' => $state['scan_truncated'],
            'scan_limit' => $state['max_posts'],
        ];
    }

    private function compute_sitemap_data(): array {
        $sitemap = [];
        $sitemap['enabled'] = get_option('pylon_sitemap_enabled', '1') === '1';
        $post_types_str = get_option('pylon_sitemap_post_types', 'post,page');
        $sitemap_pts = array_filter(array_map('trim', explode(',', $post_types_str)));
        $sitemap['post_types'] = implode(', ', $sitemap_pts);
        $total_urls = 0;
        $total_pages = 0;
        foreach ($sitemap_pts as $pt) {
            $count = wp_count_posts($pt)->publish ?? 0;
            $total_urls += $count;
            $total_pages += (int) ceil($count / 1000);
        }
        $sitemap['total_urls'] = $total_urls;
        $sitemap['sitemap_pages'] = $total_pages;
        $sitemap['cache_ok'] = get_transient('pylon_sitemap_index') !== false;
        $sitemap['sitemap_url'] = home_url('/sitemap.xml');
        return $sitemap;
    }

    private function compute_redirects_data(): array {
        global $wpdb;
        $redirect_table = $wpdb->prefix . 'pylon_redirects';
        $log_table = $wpdb->prefix . 'pylon_404_log';
        $data = [];
        $data['has_table'] = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $redirect_table)) === $redirect_table;
        $data['has_404_table'] = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $log_table)) === $log_table;
        $data['total'] = $data['has_table'] ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$redirect_table}") : 0;
        $data['active'] = $data['total'];
        $data['inactive'] = 0;
        $data['404_last_30'] = $data['has_404_table'] ? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$log_table} WHERE last_seen >= %s", gmdate('Y-m-d H:i:s', strtotime('-30 days')))) : 0;
        $data['404_total'] = $data['has_404_table'] ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$log_table}") : 0;
        return $data;
    }

    public function ajax_pulse_worst(): void {
        check_ajax_referer('pylon_admin_nonce', '_ajax_nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'pylon-seo')]);
        }

        $cached = get_transient('pylon_pulse_data');
        if ($cached === false || empty($cached['worst'])) {
            wp_send_json_error(['message' => __('No cached data. Run a pulse scan first.', 'pylon-seo')]);
        }

        $page = max(1, absint($_POST['page'] ?? 1));
        $per_page = min(100, max(10, absint($_POST['per_page'] ?? 40)));
        $worst = $cached['worst'];
        $total = count($worst);
        $total_pages = (int) ceil($total / $per_page);
        $start = ($page - 1) * $per_page;
        $items = array_slice($worst, $start, $per_page);

        wp_send_json_success([
            'worst' => $items,
            'page' => $page,
            'per_page' => $per_page,
            'total' => $total,
            'total_pages' => $total_pages,
        ]);
    }

    private function pulse_js(): string {
        return '
        jQuery(function($){
            var pageSize = 40, currentPage = 1, totalWorstItems = 0, totalWorstPages = 1;

            function renderSection(name, fn){
                if ($("#"+name).length) fn();
            }

            function loadPulse(){
                if (window.pulseData) {
                    currentPage = 1;
                    totalWorstItems = window.totalWorstItems || 0;
                    totalWorstPages = window.totalWorstPages || 1;
                    renderPagination();
                }
                setTimeout(function(){ pollScan(""); }, 500);
            }

            function renderSummary(data){
                var cards = [
                    { icon: "ðŸ“„", val: data.total_pages, lab: "' . esc_js(__('Pages Analyzed', 'pylon-seo')) . '", sub: "' . esc_js(__('published posts & pages', 'pylon-seo')) . '", bg: "linear-gradient(135deg,#6366f1,#8b5cf6)" },
                    { icon: "â­", val: data.avg_score, lab: "' . esc_js(__('Average Score', 'pylon-seo')) . '", sub: data.avg_grade, bg: data.avg_score >= 70 ? "linear-gradient(135deg,#16a34a,#22c55e)" : (data.avg_score >= 40 ? "linear-gradient(135deg,#f59e0b,#fbbf24)" : "linear-gradient(135deg,#dc2626,#ef4444)") },
                    { icon: "âœ…", val: data.buckets.good, lab: "' . esc_js(__('Good Pages', 'pylon-seo')) . '",                     sub: data.good_pct + "% " + "' . esc_js(__('of total', 'pylon-seo')) . '", bg: "linear-gradient(135deg,#059669,#34d399)" },
                    { icon: "âš ï¸", val: data.buckets.poor, lab: "' . esc_js(__('Poor Pages', 'pylon-seo')) . '",                     sub: data.total_pages > 0 ? Math.round(data.buckets.poor/data.total_pages*100) + "% " + "' . esc_js(__('need work', 'pylon-seo')) . '" : "0%", bg: "linear-gradient(135deg,#dc2626,#f87171)" }
                ];
                var html = "";
                $.each(cards, function(i, c){
                    html += \'<div class="pp-stat" style="background:\'+c.bg+\';color:#fff;">\'
                        + \'<div class="pp-stat-icon">\'+c.icon+\'</div>\'
                        + \'<div class="pp-stat-value">\'+c.val+\'</div>\'
                        + \'<div class="pp-stat-label">\'+c.lab+\'</div>\'
                        + (c.sub ? \'<div class="pp-stat-sub" style="opacity:0.7;">\'+c.sub+\'</div>\' : "")
                        + \'</div>\';
                });
                $("#pp-summary").html(html);
            }

            function renderDistChart(buckets, total){
                if (!total){
                    $("#pp-chart-dist").html(\'<div style="color:#94a3b8;text-align:center;padding-top:70px;font-size:13px;">' . esc_js(__('No data yet.', 'pylon-seo')) . '</div>\');
                    return;
                }
                var segs = [
                    { label: "' . esc_js(__('Good 70-100', 'pylon-seo')) . '", v: buckets.good, color: "#22c55e" },
                    { label: "' . esc_js(__('Ok 40-69', 'pylon-seo')) . '", v: buckets.ok, color: "#f59e0b" },
                    { label: "' . esc_js(__('Poor 0-39', 'pylon-seo')) . '", v: buckets.poor, color: "#ef4444" }
                ];
                var sum = segs[0].v + segs[1].v + segs[2].v;
                var size = 170, cx = size/2, cy = size/2, th = 26, r = (size-th)/2, circ = 2*Math.PI*r;
                var rotation = -90, arcs = "";
                $.each(segs, function(i, s){
                    if (s.v <= 0) return;
                    var frac = s.v/sum, sweep = frac*circ;
                    arcs += \'<circle cx="\'+cx+\'" cy="\'+cy+\'" r="\'+r+\'" fill="none" stroke="\'+s.color+\'" stroke-width="\'+th+\'" stroke-dasharray="\'+sweep.toFixed(1)+\' \'+circ.toFixed(1)+\'" stroke-dashoffset="\'+(-sweep/2).toFixed(1)+\'" transform="rotate(\'+rotation+\' \'+cx+\' \'+cy+\')"></circle>\';
                    rotation += frac*360;
                });
                var goodPct = Math.round(buckets.good/sum*100);
                var donut = \'<svg viewBox="0 0 \'+size+\' \'+size+\'" style="width:170px;height:170px;display:block;">\'+arcs
                    + \'<text x="\'+cx+\'" y="\'+(cy-4)+\'" font-size="20" font-weight="700" fill="#1e293b" text-anchor="middle">\'+goodPct+\'%</text>\'
                    + \'<text x="\'+cx+\'" y="\'+(cy+16)+\'" font-size="10" fill="#64748b" text-anchor="middle">' . esc_js(__('Good', 'pylon-seo')) . '</text></svg>\';
                var legend = "";
                $.each(segs, function(i, s){
                    legend += \'<div style="display:flex;align-items:center;gap:10px;padding:7px 0;">\'
                        + \'<span style="width:12px;height:12px;border-radius:3px;background:\'+s.color+\';flex-shrink:0;"></span>\'
                        + \'<span style="flex:1;font-size:13px;color:#334155;">\'+s.label+\'</span>\'
                        + \'<span style="font-size:13px;font-weight:700;color:#1e293b;">\'+s.v+\'</span>\'
                        + \'<span style="width:46px;text-align:right;font-size:11px;color:#94a3b8;">\'+Math.round(s.v/sum*100)+\'%</span></div>\';
                });
                $("#pp-chart-dist").html(\'<div style="display:flex;align-items:center;gap:24px;flex-wrap:wrap;"><div style="flex-shrink:0;width:180px;">\'+donut+\'</div><div style="flex:1;min-width:180px;">\'+legend+\'</div></div>\');
            }

            function renderTrendChart(history, currentAvg){
                var pts = history || [];
                if (pts.length < 2 && currentAvg) {
                    pts.push({ avg: currentAvg, time: new Date().toISOString() });
                }
                if (pts.length < 2) {
                    $("#pp-chart-trend").html(\'<div style="color:#94a3b8;text-align:center;padding-top:70px;font-size:13px;">' . esc_js(__('Run audits over time to see trends.', 'pylon-seo')) . '</div>\');
                    return;
                }
                var w = 480, h = 170, pad = 40;
                var scores = pts.map(function(p){ return parseFloat(p.avg) || 0; });
                var yMin = 0, yMax = 100;
                var xs = pts.map(function(_, i){ return pad + (i / (pts.length-1 || 1)) * (w - pad*2); });
                var ys = scores.map(function(s){ return h - pad - ((s - yMin) / (yMax - yMin)) * (h - pad*2); });
                var color = currentAvg >= 70 ? "#22c55e" : (currentAvg >= 40 ? "#f59e0b" : "#ef4444");
                var grad = \'<defs><linearGradient id="pp-trend-grad2" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="\'+color+\'" stop-opacity="0.25"/><stop offset="100%" stop-color="\'+color+\'" stop-opacity="0.02"/></linearGradient></defs>\';
                var area = xs.map(function(x, i){ return (i === 0 ? "M" : "L") + x.toFixed(1) + "," + ys[i].toFixed(1); }).join(" ")
                    + " L" + xs[xs.length-1].toFixed(1) + "," + (h - pad) + " L" + xs[0].toFixed(1) + "," + (h - pad) + " Z";
                var path = xs.map(function(x, i){ return (i === 0 ? "M" : "L") + x.toFixed(1) + "," + ys[i].toFixed(1); }).join(" ");
                var dots = xs.map(function(x, i){ return \'<circle cx="\'+x.toFixed(1)+\'" cy="\'+ys[i].toFixed(1)+\'" r="3" fill="#fff" stroke="\'+color+\'" stroke-width="2"/>\'; }).join("");
                var grid = "";
                for (var t = 0; t <= 4; t++) {
                    var gy = pad + (t/4)*(h-pad*2);
                    var val = yMax - (t/4)*(yMax-yMin);
                    grid += \'<line x1="\'+pad+\'" y1="\'+gy.toFixed(1)+\'" x2="\'+(w-pad)+\'" y2="\'+gy.toFixed(1)+\'" stroke="#e2e8f0" stroke-width="1"/>\'
                        + \'<text x="\'+(pad-6)+\'" y="\'+(gy+3)+\'" font-size="10" fill="#94a3b8" text-anchor="end">\'+val+\'</text>\';
                }
                $("#pp-chart-trend").html(\'<svg viewBox="0 0 \'+w+\' \'+h+\'" style="width:100%;height:auto;display:block;">\'+grad
                    + grid
                    + \'<path d="\'+area+\'" fill="url(#pp-trend-grad2)"/>\'
                    + \'<path d="\'+path+\'" fill="none" stroke="\'+color+\'" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>\'
                    + dots
                    + \'</svg>\');
            }

            function renderIssues(issues){
                if (!issues || issues.length === 0){
                    $("#pp-issues").html(\'<div style="color:#16a34a;font-weight:500;font-size:13px;">âœ… ' . esc_js(__('No major issues found â€” your site looks healthy!', 'pylon-seo')) . '</div>\');
                    return;
                }
                var html = "";
                var maxCount = issues[0].count || 1;
                $.each(issues, function(i, issue){
                    var pct = (issue.count / maxCount) * 100;
                    var color = issue.pct > 50 ? "#ef4444" : (issue.pct > 20 ? "#f59e0b" : "#3b82f6");
                    html += \'<div style="display:flex;align-items:center;gap:14px;padding:4px 0;">\'
                        + \'<div style="width:180px;font-size:12px;color:#334155;font-weight:500;flex-shrink:0;">\'+issue.label+\'</div>\'
                        + \'<div style="flex:1;height:26px;background:#f1f5f9;border-radius:8px;overflow:hidden;position:relative;">\'
                        + \'<div style="width:\'+pct+\'%;height:100%;background:\'+color+\';border-radius:8px;transition:width 0.6s ease;opacity:0.8;"></div>\'
                        + \'</div>\'
                        + \'<div style="width:50px;text-align:right;font-size:13px;font-weight:700;color:\'+color+\';">\'+issue.count+\'</div>\'
                        + \'<div style="width:44px;text-align:right;font-size:11px;color:#94a3b8;">\'+issue.pct+\'%</div>\'
                        + \'</div>\';
                });
                $("#pp-issues").html(html);
            }

            function renderSitemap(sm){
                if (!sm) { $("#pp-sitemap").html(\'<div style="color:#94a3b8;font-size:13px;padding:10px 0;">' . esc_js(__('No sitemap data.', 'pylon-seo')) . '</div>\'); return; }
                var color = sm.enabled ? "#22c55e" : "#ef4444";
                var statusText = sm.enabled ? "' . esc_js(__('Enabled', 'pylon-seo')) . '" : "' . esc_js(__('Disabled', 'pylon-seo')) . '";
                var cacheColor = sm.cache_ok ? "#22c55e" : "#f59e0b";
                var cacheText = sm.cache_ok ? "' . esc_js(__('Cached', 'pylon-seo')) . '" : "' . esc_js(__('Not cached (will generate on first visit)', 'pylon-seo')) . '";

                var html = \'<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:12px;">\'
                    + \'<div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:12px;text-align:center;">\'
                        + \'<div style="font-size:18px;font-weight:700;color:\'+color+\';">\'+statusText+\'</div>\'
                        + \'<div style="font-size:10px;color:#64748b;margin-top:3px;">' . esc_js(__('Sitemap Status', 'pylon-seo')) . '</div>\'
                    + \'</div>\'
                    + \'<div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:12px;text-align:center;">\'
                        + \'<div style="font-size:18px;font-weight:700;color:#6366f1;">\'+sm.total_urls+\'</div>\'
                        + \'<div style="font-size:10px;color:#64748b;margin-top:3px;">' . esc_js(__('Total URLs Indexed', 'pylon-seo')) . '</div>\'
                    + \'</div>\'
                    + \'<div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:12px;text-align:center;">\'
                        + \'<div style="font-size:18px;font-weight:700;color:#6366f1;">\'+sm.sitemap_pages+\'</div>\'
                        + \'<div style="font-size:10px;color:#64748b;margin-top:3px;">' . esc_js(__('Sitemap Pages', 'pylon-seo')) . '</div>\'
                    + \'</div>\'
                    + \'<div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:12px;text-align:center;">\'
                        + \'<div style="font-size:18px;font-weight:700;color:\'+cacheColor+\';">\'+cacheText+\'</div>\'
                        + \'<div style="font-size:10px;color:#64748b;margin-top:3px;">' . esc_js(__('Cache Status', 'pylon-seo')) . '</div>\'
                    + \'</div>\'
                    + \'</div>\'
                    + \'<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">\'
                    + \'<span style="font-size:11px;color:#64748b;">' . esc_js(__('Post types:', 'pylon-seo')) . ' <strong style="color:#334155;">\'+sm.post_types+\'</strong></span>\'
                    + \'<a href="\'+encodeURI(sm.sitemap_url)+\'" target="_blank" style="font-size:12px;text-decoration:none;color:#6366f1;">' . esc_js(__('Open Sitemap â†’', 'pylon-seo')) . '</a>\'
                    + \'</div>\';
                $("#pp-sitemap").html(html);
            }

            function renderRedirects(rd){
                if (!rd || !rd.has_table) { $("#pp-redirects").html(\'<div style="color:#94a3b8;font-size:13px;padding:10px 0;">' . esc_js(__('Redirect tables not found.', 'pylon-seo')) . '</div>\'); return; }
                var html = \'<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;">\'
                    + \'<div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:12px;text-align:center;">\'
                        + \'<div style="font-size:18px;font-weight:700;color:#6366f1;">\'+rd.active+\'</div>\'
                        + \'<div style="font-size:10px;color:#64748b;margin-top:3px;">' . esc_js(__('Active Redirects', 'pylon-seo')) . '</div>\'
                    + \'</div>\'
                    + \'<div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:12px;text-align:center;">\'
                        + \'<div style="font-size:18px;font-weight:700;color:\'+(rd.inactive>0?"#f59e0b":"#22c55e")+\';">\'+rd.inactive+\'</div>\'
                        + \'<div style="font-size:10px;color:#64748b;margin-top:3px;">' . esc_js(__('Inactive Redirects', 'pylon-seo')) . '</div>\'
                    + \'</div>\'
                    + \'<div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:12px;text-align:center;">\'
                        + \'<div style="font-size:18px;font-weight:700;color:\'+(rd["404_last_30"]>0?"#ef4444":"#22c55e")+\';">\'+rd["404_last_30"]+\'</div>\'
                        + \'<div style="font-size:10px;color:#64748b;margin-top:3px;">' . esc_js(__('404s (30 days)', 'pylon-seo')) . '</div>\'
                    + \'</div>\'
                    + \'<div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:12px;text-align:center;">\'
                        + \'<div style="font-size:18px;font-weight:700;color:#6366f1;">\'+rd["404_total"]+\'</div>\'
                        + \'<div style="font-size:10px;color:#64748b;margin-top:3px;">' . esc_js(__('Total 404s', 'pylon-seo')) . '</div>\'
                    + \'</div>\'
                    + \'</div>\';
                $("#pp-redirects").html(html);
            }

            function renderWorstTable(pageData){
                var $tbody = $("#pp-worst tbody");
                if (!pageData) pageData = [];

                var start = (currentPage - 1) * pageSize + 1;
                var end = start + pageData.length - 1;
                $("#pp-table-info").text(totalWorstItems > 0 ? "' . esc_js(__('Showing', 'pylon-seo')) . ' " + start + "-" + end + " ' . esc_js(__('of', 'pylon-seo')) . ' " + totalWorstItems : "");

                if (!pageData || pageData.length === 0){
                    $tbody.html(\'<tr><td colspan="4" style="text-align:center;padding:30px;color:#94a3b8;font-size:13px;">âœ… ' . esc_js(__('All pages look good!', 'pylon-seo')) . '</td></tr>\');
                    renderPagination();
                    return;
                }

                var html = "";
                $.each(pageData, function(i, p){
                    var color = p.score >= 70 ? "#22c55e" : (p.score >= 40 ? "#f59e0b" : "#ef4444");
                    var missHtml = "";
                    if (p.missing) {
                        var parts = p.missing.split(", ");
                        $.each(parts, function(j, m){
                            var cls = m === "title" ? "m-title" : (m === "desc" ? "m-desc" : "m-kw");
                            var lab = m === "title" ? "title" : (m === "desc" ? "desc" : "keyword");
                            missHtml += \'<span class="pp-missing-tag \'+cls+\'">\'+lab+\'</span> \';
                        });
                    } else {
                        missHtml = \'<span style="color:#94a3b8;font-size:11px;">\'+p.missing+\'</span>\';
                    }
                    html += \'<tr>\'
                        + \'<td><a href="\'+encodeURI(p.edit_url)+\'" class="pp-page-link">\'+$("<span>").text(p.title).html()+\'</a></td>\'
                        + \'<td style="text-align:center;"><span class="pp-score-badge" style="background:\'+color+\';">\'+p.score+\'</span></td>\'
                        + \'<td>\'+missHtml+\'</td>\'
                        + \'<td style="text-align:center;"><a href="\'+encodeURI(p.edit_url)+\'" style="text-decoration:none;font-size:18px;color:#94a3b8;transition:color 0.15s;" title="' . esc_js(__('Edit', 'pylon-seo')) . '">âœŽ</a></td>\'
                        + \'</tr>\';
                });
                $tbody.html(html);
                renderPagination();
            }

            function loadWorstPage(page){
                pylonAjax("pylon_pulse_worst", { _ajax_nonce: pylonAdmin.nonce, page: page, per_page: pageSize }, { toast: false })
                .done(function(data){
                    currentPage = data.page;
                    totalWorstItems = data.total;
                    totalWorstPages = data.total_pages;
                    renderWorstTable(data.worst);
                });
            }

            function renderPagination(){
                var $p = $("#pp-pagination");
                if (totalWorstPages <= 1) { $p.empty(); return; }
                var html = "";
                html += \'<button class="pp-pg-btn" data-page="prev" \'+(currentPage<=1?"disabled":"")+\'>â€¹</button>\';

                var startP = Math.max(1, currentPage - 2);
                var endP = Math.min(totalWorstPages, currentPage + 2);
                if (startP > 1) { html += \'<button class="pp-pg-btn" data-page="1">1</button>\'; if (startP > 2) html += \'<span style="color:#94a3b8;font-size:12px;padding:0 2px;">â€¦</span>\'; }
                for (var p = startP; p <= endP; p++) {
                    html += \'<button class="pp-pg-btn\'+(p===currentPage?" active":"")+\'" data-page="\'+p+\'">\'+p+\'</button>\';
                }
                if (endP < totalWorstPages) { if (endP < totalWorstPages-1) html += \'<span style="color:#94a3b8;font-size:12px;padding:0 2px;">â€¦</span>\'; html += \'<button class="pp-pg-btn" data-page="\'+totalWorstPages+\'">\'+totalWorstPages+\'</button>\'; }
                html += \'<button class="pp-pg-btn" data-page="next" \'+(currentPage>=totalWorstPages?"disabled":"")+\'>â€º</button>\';
                $p.html(html);
            }

            $(document).on("click", "#pp-pagination .pp-pg-btn", function(){
                var page = $(this).data("page");
                if (page === "prev") { page = currentPage - 1; }
                else if (page === "next") { page = currentPage + 1; }
                else { page = parseInt(page); }
                if (page >= 1 && page <= totalWorstPages) loadWorstPage(page);
            });

            function renderAllSections(data){
                renderSummary(data);
                renderSection("pp-chart-dist", function(){ renderDistChart(data.buckets, data.total_pages); });
                renderSection("pp-chart-trend", function(){ renderTrendChart(data.history, data.avg_score); });
                renderSection("pp-issues", function(){ renderIssues(data.issues); });
                renderSection("pp-sitemap", function(){ renderSitemap(data.sitemap); });
                renderSection("pp-redirects", function(){ renderRedirects(data.redirects); });
                renderSection("pp-worst", function(){
                    currentPage = 1;
                    totalWorstItems = data.total_worst || 0;
                    totalWorstPages = Math.ceil(totalWorstItems / pageSize) || 1;
                    renderWorstTable(data.worst || []);
                });
                $("#pylon-pulse-loading").hide(); $("#pylon-pulse-dashboard").show();
            }

            function pollScan(scanId){
                pylonAjax("pylon_pulse_data", { _ajax_nonce: pylonAdmin.nonce, refresh: 1, scan_id: scanId }, { toast: false })
                .done(function(data){
                    if (data.scan_complete === false) {
                        var pct = data.progress || 0;
                        $("#pylon-pulse-loading").html(\'<div style="text-align:center;padding:40px 20px;"><div class="pulse-spinner"></div><p style="margin-top:14px;color:#6b7280;font-size:13px;">' . esc_js(__('Scanningâ€¦', 'pylon-seo')) . ' \'+pct+\'%</p></div>\');
                        setTimeout(function(){ pollScan(data.scan_id); }, 1500);
                    } else if (data && data.total_pages !== undefined) {
                        renderAllSections(data);
                    }
                });
            }

            loadPulse();
        });
        ';
    }
}
