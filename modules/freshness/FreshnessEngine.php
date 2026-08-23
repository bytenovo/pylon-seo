<?php
namespace Pylon\Core\Modules\Freshness;
defined('ABSPATH') || exit;
class FreshnessEngine {
    public function register(): void {
        if (!get_option('pylon_freshness_enabled', '1')) return;

        add_action('save_post', [$this, 'update_freshness_on_save'], 10, 2);
        add_action('pylon_daily_maintenance', [$this, 'scan_stale_content']);
        add_action('admin_notices', [$this, 'stale_content_notice']);
        add_filter('pylon/metabox/freshness', [$this, 'render_metabox']);
    }

    public function update_freshness_on_save(int $post_id, \WP_Post $post): void {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;

        update_post_meta($post_id, 'pylon_last_updated', current_time('mysql'));

        $freshness = $this->calculate_freshness($post);
        update_post_meta($post_id, 'pylon_freshness_score', $freshness);
    }

    public function calculate_freshness(\WP_Post $post): int {
        $modified = strtotime($post->post_modified);
        $now = current_time('timestamp');
        $days_since_update = (int) floor(($now - $modified) / DAY_IN_SECONDS);

        $threshold = (int) get_option('pylon_freshness_days', 180);

        if ($days_since_update <= 30) return 100;
        if ($days_since_update <= 60) return 90;
        if ($days_since_update <= 90) return 75;
        if ($days_since_update <= $threshold) return 50;
        return max(0, 50 - (int) (($days_since_update - $threshold) / 30) * 10);
    }

    public function scan_stale_content(): void {
        $threshold = (int) get_option('pylon_freshness_days', 180);
        $stale_posts = get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 50,
            'no_found_rows' => true,
            'date_query' => [
                'column' => 'post_modified',
                'before' => "-{$threshold} days",
            ],
            'fields' => 'ids',
        ]);

        if (!empty($stale_posts)) {
            update_option('pylon_stale_posts', $stale_posts, false);
        }
    }

    public function stale_content_notice(): void {
        if (!current_user_can('edit_posts')) return;

        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, ['dashboard', 'edit-post', 'post', 'toplevel_page_pylon'], true)) return;

        $stale = get_option('pylon_stale_posts', []);
        if (empty($stale)) return;

        $count = count($stale);
        echo '<div class="notice notice-warning is-dismissible">';
        echo '<p>';
        echo esc_html(sprintf(
            /* translators: %d: Number of stale posts detected. */
            _n(
                'Pylon detected %d post with stale content. Consider updating it for better freshness signals.',
                'Pylon detected %d posts with stale content. Consider updating them for better freshness signals.',
                $count,
                'pylon-seo'
            ),
            $count
        ));        echo ' <a href="' . esc_url(admin_url('edit.php?pylon_filter=stale')) . '">' . esc_html__('View stale posts', 'pylon-seo') . '</a>';
        echo '</p></div>';
    }

    public function render_metabox(int $post_id): void {
        $score = get_post_meta($post_id, 'pylon_freshness_score', true);
        $last_updated = get_post_meta($post_id, 'pylon_last_updated', true);

        if ($score === '') $score = 100;
        $score = (int) $score;
        ?>
        <div class="pylon-form-group">
            <label><?php esc_html_e('Content Freshness', 'pylon-seo'); ?></label>
            <div class="pylon-flex pylon-flex-center pylon-gap-8">
                <span class="pylon-badge pylon-badge-<?php echo $score >= 75 ? 'green' : ($score >= 50 ? 'amber' : 'red'); ?> pylon-text-14 pylon-fw-700"><?php echo esc_html($score); ?></span>
                <span class="pylon-text-12 pylon-color-gray">
                    <?php /* translators: %s: Human-readable time difference. */ ?>
                    <?php echo $last_updated ? esc_html(sprintf(__('Last updated: %s', 'pylon-seo'), human_time_diff(strtotime($last_updated), current_time('timestamp')) . ' ' . __('ago', 'pylon-seo'))) : ''; ?>
                </span>
            </div>
        </div>
        <?php
    }
}
