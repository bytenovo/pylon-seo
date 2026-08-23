<?php
namespace Pylon\Core\Modules\SystemStatus;
defined('ABSPATH') || exit;
class SystemStatus {
    public function register(): void {
        add_action('admin_menu', [$this, 'add_admin_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(string $hook): void {
        if (strpos($hook, 'pylon-system-status') === false) return;
        wp_enqueue_style('pylon-system-status', PYLON_URL . 'assets/css/modules/system-status.css', ['pylon-admin'], filemtime(PYLON_PATH . 'assets/css/modules/system-status.css'));
    }

    public function add_admin_page(): void {
        add_submenu_page(
            'pylon',
            __('System Status', 'pylon-seo'),
            __('System Status', 'pylon-seo'),
            'manage_options',
            'pylon-system-status',
            [$this, 'render_page']
        );
    }

    public function render_page(): void {
        $info = $this->gather_info();
        $all_items = array_merge(...array_map(function($s) { return $s['items']; }, array_values($info)));
        $good_count = count(array_filter($all_items, function($i) { return ($i['status'] ?? '') === 'good'; }));
        $warn_count = count(array_filter($all_items, function($i) { return ($i['status'] ?? '') === 'warn'; }));
        $bad_count = count(array_filter($all_items, function($i) { return ($i['status'] ?? '') === 'bad'; }));
        ?>
        <div class="wrap" style="padding-right:20px">
            <?php \Pylon\Core\Modules\Admin\AdminEngine::page_header(__('System Status', 'pylon-seo'), '🔧'); ?>

            <div class="pylon-status-grid">
                <div class="pylon-status-card"><span class="pylon-status-icon">✓</span><div class="pylon-status-value" style="color:var(--pylon-success)"><?php echo esc_html($good_count); ?></div><div class="pylon-status-label"><?php esc_html_e('Healthy', 'pylon-seo'); ?></div></div>
                <div class="pylon-status-card"><span class="pylon-status-icon">⚠</span><div class="pylon-status-value" style="color:var(--pylon-warning)"><?php echo esc_html($warn_count); ?></div><div class="pylon-status-label"><?php esc_html_e('Warnings', 'pylon-seo'); ?></div></div>
                <div class="pylon-status-card"><span class="pylon-status-icon">✕</span><div class="pylon-status-value" style="color:var(--pylon-danger)"><?php echo esc_html($bad_count); ?></div><div class="pylon-status-label"><?php esc_html_e('Errors', 'pylon-seo'); ?></div></div>
                <div class="pylon-status-card"><span class="pylon-status-icon">📦</span><div class="pylon-status-value"><?php echo count($all_items); ?></div><div class="pylon-status-label"><?php esc_html_e('Total Checks', 'pylon-seo'); ?></div></div>
            </div>

            <?php foreach ($info as $section): ?>
            <div class="pylon-card">
                <div class="pylon-card-header">
                    <h3><?php echo esc_html($section['icon']); ?> <?php echo esc_html($section['title']); ?></h3>
                    <span class="pylon-badge pylon-badge-gray"><?php echo count($section['items']); ?></span>
                </div>
                <div class="pylon-card-body">
                    <table class="pylon-info-table">
                        <tbody>
                            <?php foreach ($section['items'] as $item): ?>
                            <tr>
                                <td class="pylon-info-label"><?php echo esc_html($item['label']); ?></td>
                                <td class="pylon-info-value">
                                    <code><?php echo esc_html($item['value']); ?></code>
                                    <?php if (isset($item['status'])): $s = $item['status']; $bc = $s === 'good' ? 'green' : ($s === 'warn' ? 'amber' : 'red'); ?>
                                        <span class="pylon-badge pylon-badge-<?php echo esc_attr($bc); ?>">
                                            <?php echo $s === 'good' ? '✓' : ($s === 'warn' ? '⚠' : '✕'); ?>
                                            <?php echo $s === 'good' ? esc_html__('OK', 'pylon-seo') : ($s === 'warn' ? esc_html__('Warning', 'pylon-seo') : esc_html__('Error', 'pylon-seo')); ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (isset($item['note'])): ?>
                                        <span class="pylon-info-note"><?php echo esc_html($item['note']); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private function gather_info(): array {
        global $wpdb;

        $active_plugins = get_option('active_plugins', []);
        $all_plugins = get_plugins();
        $plugin_list = [];
        foreach ($active_plugins as $p) {
            if (isset($all_plugins[$p])) {
                $plugin_list[] = $all_plugins[$p]['Name'] . ' ' . $all_plugins[$p]['Version'];
            }
        }

        $theme = wp_get_theme();
        $debug = defined('WP_DEBUG') && WP_DEBUG;
        $memory_limit = ini_get('memory_limit');
        $max_exec = ini_get('max_execution_time');
        $upload_max = ini_get('upload_max_filesize');
        $post_max = ini_get('post_max_size');
        $max_input = ini_get('max_input_vars');

        $db_size = 0;
        $db_rows = $wpdb->get_var("SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = DATABASE()");
        if ($db_size = $db_rows) {
            $db_size = $this->format_bytes((int) $db_size);
        }

        $sapi = php_sapi_name();
        $opcache_enabled = function_exists('opcache_get_status') && opcache_get_status(false)['opcache_enabled'] ?? false;

        return apply_filters('pylon/system_status_sections', [
            'wordpress' => [
                'icon' => '📌',
                'title' => __('WordPress', 'pylon-seo'),
                'items' => [
                    ['label' => __('Version', 'pylon-seo'), 'value' => get_bloginfo('version'), 'status' => 'good'],
                    ['label' => __('Site URL', 'pylon-seo'), 'value' => site_url()],
                    ['label' => __('Home URL', 'pylon-seo'), 'value' => home_url()],
                    ['label' => __('Multisite', 'pylon-seo'), 'value' => is_multisite() ? __('Yes', 'pylon-seo') : __('No', 'pylon-seo')],
                    ['label' => __('WP Debug', 'pylon-seo'), 'value' => $debug ? __('Enabled', 'pylon-seo') : __('Disabled', 'pylon-seo'), 'status' => $debug ? 'warn' : 'good'],
                    ['label' => __('Cron', 'pylon-seo'), 'value' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? __('Disabled', 'pylon-seo') : __('Enabled', 'pylon-seo'), 'status' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? 'warn' : 'good'],
                    ['label' => __('Memory Limit (WP)', 'pylon-seo'), 'value' => defined('WP_MEMORY_LIMIT') ? WP_MEMORY_LIMIT : __('Not set', 'pylon-seo'), 'status' => (defined('WP_MEMORY_LIMIT') && WP_MEMORY_LIMIT >= '64M') ? 'good' : 'warn'],
                    ['label' => __('Active Theme', 'pylon-seo'), 'value' => $theme->get('Name') . ' ' . $theme->get('Version')],
                    ['label' => __('Active Plugins', 'pylon-seo'), 'value' => count($active_plugins)],
                ],
            ],
            'server' => [
                'icon' => '🖥️',
                'title' => __('Server', 'pylon-seo'),
                'items' => [
                    ['label' => __('PHP Version', 'pylon-seo'), 'value' => PHP_VERSION, 'status' => version_compare(PHP_VERSION, '7.4', '>=') ? 'good' : 'bad'],
                    ['label' => __('SAPI', 'pylon-seo'), 'value' => $sapi],
                    ['label' => __('Memory Limit', 'pylon-seo'), 'value' => $memory_limit, 'status' => $this->compare_bytes($memory_limit, '128M') ? 'good' : 'warn'],
                    ['label' => __('Max Execution Time', 'pylon-seo'), 'value' => $max_exec . 's', 'status' => $max_exec >= 60 ? 'good' : 'warn'],
                    ['label' => __('Upload Max Size', 'pylon-seo'), 'value' => $upload_max],
                    ['label' => __('Post Max Size', 'pylon-seo'), 'value' => $post_max],
                    ['label' => __('Max Input Vars', 'pylon-seo'), 'value' => $max_input, 'status' => $max_input >= 1000 ? 'good' : 'warn'],
                    ['label' => __('MySQL Version', 'pylon-seo'), 'value' => $wpdb->get_var("SELECT VERSION()"), 'status' => 'good'],
                    ['label' => __('Database Size', 'pylon-seo'), 'value' => $db_size ?: __('Unknown', 'pylon-seo')],
                    ['label' => __('OPcache', 'pylon-seo'), 'value' => $opcache_enabled ? __('Enabled', 'pylon-seo') : __('Disabled', 'pylon-seo'), 'status' => $opcache_enabled ? 'good' : 'warn'],
                    ['label' => __('cURL', 'pylon-seo'), 'value' => function_exists('curl_version') ? (curl_version()['version'] ?? __('Available', 'pylon-seo')) : __('Not available', 'pylon-seo'), 'status' => function_exists('curl_version') ? 'good' : 'bad'],
                    ['label' => __('mbstring', 'pylon-seo'), 'value' => extension_loaded('mbstring') ? __('Loaded', 'pylon-seo') : __('Missing', 'pylon-seo'), 'status' => extension_loaded('mbstring') ? 'good' : 'bad'],
                    ['label' => __('DOM', 'pylon-seo'), 'value' => class_exists('DOMDocument') ? __('Available', 'pylon-seo') : __('Missing', 'pylon-seo'), 'status' => class_exists('DOMDocument') ? 'good' : 'bad'],
                    ['label' => __('JSON', 'pylon-seo'), 'value' => function_exists('json_encode') ? __('Available', 'pylon-seo') : __('Missing', 'pylon-seo'), 'status' => function_exists('json_encode') ? 'good' : 'bad'],
                    ['label' => __('GD / Imagick', 'pylon-seo'), 'value' => extension_loaded('gd') || extension_loaded('imagick') ? __('Available', 'pylon-seo') : __('Missing', 'pylon-seo'), 'status' => extension_loaded('gd') || extension_loaded('imagick') ? 'good' : 'bad'],
                    ['label' => __('Server OS', 'pylon-seo'), 'value' => PHP_OS],
                ],
            ],
            'pylon' => [
                'icon' => '⚡',
                'title' => __('Pylon Plugin', 'pylon-seo'),
                'items' => [
                    ['label' => __('Version', 'pylon-seo'), 'value' => PYLON_VERSION],
                    ['label' => __('Modules Loaded', 'pylon-seo'), 'value' => count(\Pylon\Core\Bootstrap::get_loaded_modules())],
                    ['label' => __('Cache (Transients)', 'pylon-seo'), 'value' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_pylon_%'") . ' active'],
                    ['label' => __('Usage Stats', 'pylon-seo'), 'value' => $this->format_usage_stats()],
                ],
            ],
        ]);
    }

    private function format_usage_stats(): string {
        $stats = \Pylon\Core\Bootstrap::get_usage_stats();
        $parts = [];
        foreach ($stats as $key => $count) {
            $parts[] = str_replace('_', ' ', $key) . ': ' . $count;
        }
        return empty($parts) ? __('No usage recorded', 'pylon-seo') : implode(' | ', $parts);
    }

    private function compare_bytes(string $ini_val, string $compare): bool {
        return $this->bytes_from_ini($ini_val) >= $this->bytes_from_ini($compare);
    }

    private function bytes_from_ini(string $val): int {
        $val = trim($val);
        $last = strtolower(substr($val, -1));
        $num = (int) $val;
        if ($last === 'g') return $num * GB_IN_BYTES;
        if ($last === 'm') return $num * MB_IN_BYTES;
        if ($last === 'k') return $num * KB_IN_BYTES;
        return $num;
    }

    private function format_bytes(int $bytes): string {
        if ($bytes >= GB_IN_BYTES) return round($bytes / GB_IN_BYTES, 2) . ' GB';
        if ($bytes >= MB_IN_BYTES) return round($bytes / MB_IN_BYTES, 1) . ' MB';
        if ($bytes >= KB_IN_BYTES) return round($bytes / KB_IN_BYTES) . ' KB';
        return $bytes . ' B';
    }
}