<?php
namespace Pylon\Core\Modules\Migrator;
defined('ABSPATH') || exit;
class MigratorEngine {
    private array $supported_plugins = [
        'wordpress-seo/wp-seo.php' => 'Yoast SEO',
        'wordpress-seo-premium/wp-seo-premium.php' => 'Yoast SEO Premium',
        'seo-by-rank-math/rank-math.php' => 'Rank Math',
        'all-in-one-seo-pack/all_in_one_seo_pack.php' => 'All in One SEO',
        'all-in-one-seo-pack-pro/all_in_one_seo_pack.php' => 'All in One SEO Pro',
        'wp-seopress/seopress.php' => 'SEOPress',
        'wp-seopress-pro/seopress-pro.php' => 'SEOPress Pro',
        'autodescription/autodescription.php' => 'The SEO Framework',
        'slim-seo/slim-seo.php' => 'Slim SEO',
        'smartcrawl-seo/wpmu-dev-seo.php' => 'SmartCrawl',
        'siteseo/siteseo.php' => 'SiteSEO',
        'seo-engine/seo-engine.php' => 'SEO Engine',
    ];

    public function register(): void {
        add_action('admin_menu', [$this, 'add_admin_page']);
        add_action('wp_ajax_pylon_migrate', [$this, 'ajax_migrate']);
        add_action('wp_ajax_pylon_import_file', [$this, 'ajax_import_file']);
        add_action('wp_ajax_pylon_export', [$this, 'ajax_export']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(string $hook): void {
        if (strpos($hook, 'pylon-migrate') === false) return;
        \Pylon\Core\Modules\Admin\AdminEngine::add_module_js($this->js());
    }

    private function js(): string {
        $ajax_url = esc_js(admin_url('admin-ajax.php'));
        $export_nonce = esc_js(wp_create_nonce('pylon_export'));
        $generating = esc_js(__('Generating...', 'pylon-seo'));
        $export_failed = esc_js(__('Export failed.', 'pylon-seo'));
        $download_export = esc_js(__('Download Export ZIP', 'pylon-seo'));
        return '
        jQuery(function($) {
            $(document).on("click", ".pylon-run-migration", function() {
                $("#pylon-migrate-progress").removeClass("pylon-hidden");
                $("#pylon-migrate-result").addClass("pylon-hidden");
            });

            $("#pylon-import-file-form").on("submit", function(e) {
                e.preventDefault();
                var fd = new FormData(this);
                $("#pylon-file-progress").removeClass("pylon-hidden");
                $("#pylon-file-result").addClass("pylon-hidden").empty();
                $("#pylon-upload-btn").prop("disabled", true);
                $.ajax({
                    url: "' . $ajax_url . '",
                    type: "POST",
                    data: fd,
                    processData: false,
                    contentType: false,
                    success: function(res) {
                        if (res.success) {
                            $("#pylon-file-result").removeClass("pylon-hidden").html(
                                "<div class=\"pylon-notice pylon-notice-success\">" + res.data.message + "</div>"
                            );
                        } else {
                            $("#pylon-file-result").removeClass("pylon-hidden").html(
                                "<div class=\"pylon-notice pylon-notice-danger\">" + (res.data && res.data.message || "Import failed.") + "</div>"
                            );
                        }
                    },
                    error: function() {
                        $("#pylon-file-result").removeClass("pylon-hidden").html(
                            "<div class=\"pylon-notice pylon-notice-danger\">Server error.</div>"
                        );
                    },
                    complete: function() {
                        $("#pylon-file-progress").addClass("pylon-hidden");
                        $("#pylon-upload-btn").prop("disabled", false);
                    }
                });
            });

            $("#pylon-export-btn").on("click", function(e) {
                e.preventDefault();
                var btn = $(this).prop("disabled", true).text("' . $generating . '");
                $.ajax({
                    url: "' . $ajax_url . '",
                    type: "POST",
                    data: { action: "pylon_export", _wpnonce: "' . $export_nonce . '" },
                    xhrFields: { responseType: "blob" },
                    success: function(blob, status, xhr) {
                        var cd = xhr.getResponseHeader("Content-Disposition");
                        var fn = "pylon-export.zip";
                        if (cd) { var m = cd.match(/filename=(.+)/); if (m) fn = m[1]; }
                        var a = document.createElement("a");
                        a.href = URL.createObjectURL(blob);
                        a.download = fn;
                        a.click();
                        URL.revokeObjectURL(a.href);
                    },
                    error: function() {
                        alert("' . $export_failed . '");
                    },
                    complete: function() {
                        btn.prop("disabled", false).text("' . $download_export . '");
                    }
                });
            });
        });
        ';
    }

    public function add_admin_page(): void {
        add_submenu_page(
            'pylon',
            __('Import', 'pylon-seo'),
            __('Import', 'pylon-seo'),
            'manage_options',
            'pylon-migrate',
            [$this, 'render_admin_page']
        );
    }

    public function render_admin_page(): void {
        $detected = $this->detect_plugins();
        ?>
        <div class="wrap pylon-dashboard">
            <?php \Pylon\Core\Modules\Admin\AdminEngine::page_header(__('Import from Another SEO Plugin', 'pylon-seo'), '📥'); ?>
            <p class="pylon-color-muted pylon-mb-20"><?php esc_html_e('Import SEO data from an active plugin or from an exported file (Yoast, Rank Math, AIOSEO, SEOPress, or generic CSV).', 'pylon-seo'); ?></p>

            <div class="pylon-card" id="pylon-migrator">
                <div class="pylon-card-header">
                    <h3><?php esc_html_e('From Active Plugin', 'pylon-seo'); ?></h3>
                </div>
                <div class="pylon-card-body pylon-p-0">
                    <?php if (empty($detected)): ?>
                        <div class="pylon-notice pylon-notice-info pylon-m-16"><?php esc_html_e('No supported SEO plugins detected on this site.', 'pylon-seo'); ?></div>
                    <?php else: ?>
                        <table class="pylon-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Plugin', 'pylon-seo'); ?></th>
                                    <th><?php esc_html_e('Status', 'pylon-seo'); ?></th>
                                    <th><?php esc_html_e('Action', 'pylon-seo'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($detected as $plugin_file => $plugin_name): ?>
                                    <tr>
                                        <td><?php echo esc_html($plugin_name); ?></td>
                                        <td><span class="pylon-dot pylon-dot-green"></span> <?php esc_html_e('Active', 'pylon-seo'); ?></td>
                                        <td>
                                            <button type="button" class="pylon-btn pylon-btn-primary pylon-run-migration" data-plugin="<?php echo esc_attr($plugin_file); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('pylon_migrate_' . $plugin_file)); ?>" data-pylon-target="#pylon-migrate-result" data-pylon-ajax="pylon_migrate" data-pylon-data="<?php echo esc_attr(wp_json_encode(['plugin' => $plugin_file, '_wpnonce' => wp_create_nonce('pylon_migrate_' . $plugin_file)])); ?>">
                                                <?php esc_html_e('Import', 'pylon-seo'); ?>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
                <div class="pylon-card-body">
                    <div id="pylon-migrate-progress" class="pylon-hidden">
                        <div class="pylon-notice pylon-notice-info">
                            <span class="pylon-spinner" style="float:left;margin-top:0;"></span>
                            <span id="pylon-migrate-status"><?php esc_html_e('Importing...', 'pylon-seo'); ?></span>
                        </div>
                    </div>
                    <div id="pylon-migrate-result" class="pylon-hidden pylon-mt-12"></div>
                </div>
            </div>

            <div class="pylon-card">
                <div class="pylon-card-header">
                    <h3><?php esc_html_e('From Exported File', 'pylon-seo'); ?></h3>
                </div>
                <div class="pylon-card-body">
                    <p class="pylon-color-muted pylon-mb-16"><?php esc_html_e('Upload an SEO export file. Supported formats:', 'pylon-seo'); ?></p>
                    <ul class="pylon-mb-16" style="list-style:disc;padding-left:20px;font-size:13px;color:var(--pylon-gray-600);">
                        <li><strong>Yoast (.zip)</strong> — exported via Yoast → Tools → Import/Export</li>
                        <li><strong>Yoast / Rank Math / AIOSEO (.csv)</strong> — post_id, meta_key, meta_value columns</li>
                        <li><strong>AIOSEO (.json)</strong> — exported via AIOSEO → Import/Export</li>
                        <li><strong>Generic CSV (.csv)</strong> — columns: <code>post_id, meta_key, meta_value</code></li>
                    </ul>
                    <form id="pylon-import-file-form" enctype="multipart/form-data">
                        <?php wp_nonce_field('pylon_import_file', '_wpnonce'); ?>
                        <input type="hidden" name="action" value="pylon_import_file">
                        <div class="pylon-flex pylon-flex-center pylon-gap-12">
                            <input type="file" name="import_file" accept=".zip,.csv,.json" required class="pylon-input" style="flex:1;">
                            <button type="submit" class="pylon-btn pylon-btn-primary" id="pylon-upload-btn"><?php esc_html_e('Upload & Import', 'pylon-seo'); ?></button>
                        </div>
                    </form>
                    <div id="pylon-file-progress" class="pylon-hidden pylon-mt-12">
                        <div class="pylon-notice pylon-notice-info">
                            <span class="pylon-spinner" style="float:left;margin-top:0;"></span>
                            <span id="pylon-file-status"><?php esc_html_e('Processing file...', 'pylon-seo'); ?></span>
                        </div>
                    </div>
                    <div id="pylon-file-result" class="pylon-hidden pylon-mt-12"></div>
                </div>
            </div>
        </div>

        <div class="pylon-card">
            <div class="pylon-card-header">
                <h3><?php esc_html_e('Export Pylon Data', 'pylon-seo'); ?></h3>
            </div>
            <div class="pylon-card-body">
                <p class="pylon-color-muted pylon-mb-16"><?php esc_html_e('Download all Pylon SEO data as a ZIP file containing:', 'pylon-seo'); ?></p>
                <ul class="pylon-mb-16" style="list-style:disc;padding-left:20px;font-size:13px;color:var(--pylon-gray-600);">
                    <li><strong>meta.csv</strong> — all post SEO metadata (title, description, keyword, OG, Twitter, schema)</li>
                    <li><strong>redirects.csv</strong> — all redirect rules</li>
                    <li><strong>settings.json</strong> — all Pylon settings and configuration</li>
                </ul>
                <button type="button" class="pylon-btn pylon-btn-primary" id="pylon-export-btn"><?php esc_html_e('Download Export ZIP', 'pylon-seo'); ?></button>
                <div id="pylon-export-status" class="pylon-mt-12 pylon-hidden"></div>
            </div>
        </div>
        <?php
    }

    private function detect_plugins(): array {
        $detected = [];
        foreach ($this->supported_plugins as $plugin_file => $name) {
            if (is_plugin_active($plugin_file)) {
                $detected[$plugin_file] = $name;
            }
        }
        return $detected;
    }

    public function ajax_migrate(): void {
        $plugin = sanitize_text_field(wp_unslash($_POST['plugin'] ?? ''));

        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? '')), 'pylon_migrate_' . $plugin) || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'pylon-seo')]);
        }

        if (!isset($this->supported_plugins[$plugin])) {
            wp_send_json_error(['message' => __('Unsupported plugin.', 'pylon-seo')]);
        }

        $result = $this->migrate_from($plugin);
        wp_send_json_success(['message' => $result]);
    }

    public function ajax_import_file(): void {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? '')), 'pylon_import_file') || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'pylon-seo')]);
        }

        if (empty($_FILES['import_file']) || absint($_FILES['import_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => __('File upload failed.', 'pylon-seo')]);
        }

        $file = [
            'name' => isset($_FILES['import_file']['name']) ? sanitize_file_name(wp_unslash($_FILES['import_file']['name'])) : '',
            'size' => absint($_FILES['import_file']['size'] ?? 0),
            'tmp_name' => isset($_FILES['import_file']['tmp_name']) ? sanitize_text_field(wp_unslash($_FILES['import_file']['tmp_name'])) : '',
            'error' => absint($_FILES['import_file']['error'] ?? 0),
        ];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, ['zip', 'csv', 'json'], true)) {
            wp_send_json_error(['message' => __('Unsupported file format. Use .zip, .csv, or .json.', 'pylon-seo')]);
        }

        $allowed_size = apply_filters('pylon_import_max_file_size', 5 * 1024 * 1024);
        if (!empty($file['size']) && $file['size'] > $allowed_size) {
            /* translators: %s: Maximum allowed file size. */
            wp_send_json_error(['message' => sprintf(__('Import file is too large. Max allowed size: %s.', 'pylon-seo'), size_format($allowed_size))]);
        }

        $result = ['meta' => 0, 'redirects' => 0, 'settings' => 0];

        try {
            if ($ext === 'zip') {
                $result = $this->import_yoast_zip($file['tmp_name']);
            } elseif ($ext === 'json') {
                $result = $this->import_aioseo_json($file['tmp_name']);
            } elseif ($ext === 'csv') {
                $result = $this->import_generic_csv($file['tmp_name']);
            }
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }

        $msg = sprintf(
            /* translators: %1$d: number of migrated meta entries, %2$d: number of migrated redirects, %3$d: number of migrated settings. */
            __('Import complete! Migrated: %1$d meta entries, %2$d redirects, %3$d settings.', 'pylon-seo'),
            $result['meta'],
            $result['redirects'],
            $result['settings']
        );
        wp_send_json_success(['message' => $msg]);
    }

    public function ajax_export(): void {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? '')), 'pylon_export') || !current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized.', 'pylon-seo'));
        }

        try {
            $tmp = wp_tempnam('pylon-export');
            if (!$tmp) {
                wp_die(esc_html__('Cannot create temp file.', 'pylon-seo'));
            }

            $zip = new \ZipArchive();
            if ($zip->open($tmp, \ZipArchive::CREATE) !== true) {
                wp_die(esc_html__('Cannot create ZIP archive.', 'pylon-seo'));
            }

            global $wpdb;

            $meta_keys = $wpdb->get_col("SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE meta_key LIKE 'pylon_%'");
            if (!empty($meta_keys)) {
                $placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));
                $results = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
                         WHERE meta_key IN ({$placeholders})
                         ORDER BY post_id",
                        $meta_keys
                    )
                );
                $csv_rows = [['post_id', 'meta_key', 'meta_value']];
                foreach ($results as $row) {
                    $csv_rows[] = [$row->post_id, $row->meta_key, $row->meta_value];
                }
                $zip->addFromString('meta.csv', $this->csv_encode($csv_rows));
            }

            $redirect_table = $wpdb->prefix . 'pylon_redirects';
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$redirect_table}'") === $redirect_table;
            if ($table_exists) {
                $redirects = $wpdb->get_results("SELECT source_url, target_url, type, hits FROM {$redirect_table} ORDER BY id");
                $csv_rows = [['source_url', 'target_url', 'type', 'hits']];
                foreach ($redirects as $row) {
                    $csv_rows[] = [$row->source_url, $row->target_url, $row->type, $row->hits];
                }
                $zip->addFromString('redirects.csv', $this->csv_encode($csv_rows));
            }

            $settings = [];
            $all_options = wp_load_alloptions();
            foreach ($all_options as $key => $value) {
                if (strpos($key, 'pylon_') === 0) {
                    $settings[$key] = $value;
                }
            }
            $zip->addFromString('settings.json', wp_json_encode($settings, JSON_PRETTY_PRINT));

            $zip->close();

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="pylon-export-' . current_time('Y-m-d') . '.zip"');
            header('Content-Length: ' . filesize($tmp));
            echo file_get_contents($tmp); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary ZIP download cannot be escaped.
            wp_delete_file($tmp);
            exit;

        } catch (\Throwable $e) {
            wp_die(esc_html($e->getMessage()));
        }
    }

    private function import_yoast_zip(string $tmp_path): array {
        $zip = new \ZipArchive();
        if ($zip->open($tmp_path) !== true) {
            throw new \Exception(esc_html__('Cannot open ZIP file.', 'pylon-seo'));
        }

        $json = $zip->getFromName('settings.json');
        $zip->close();

        if (!$json) {
            throw new \Exception(esc_html__('ZIP does not contain settings.json (Yoast export format).', 'pylon-seo'));
        }

        $data = json_decode($json, true);
        if (!$data || empty($data['data'])) {
            throw new \Exception(esc_html__('Invalid Yoast export data.', 'pylon-seo'));
        }

        $meta = 0;
        $map = [
            'title' => 'pylon_title',
            'metadesc' => 'pylon_description',
            'focuskw' => 'pylon_focus_keyword',
            'canonical' => 'pylon_canonical',
            'noindex' => 'pylon_noindex',
            'nofollow' => 'pylon_nofollow',
            'opengraph-title' => 'pylon_og_title',
            'opengraph-description' => 'pylon_og_description',
            'opengraph-image' => 'pylon_og_image',
            'twitter-title' => 'pylon_twitter_title',
            'twitter-description' => 'pylon_twitter_description',
            'twitter-image' => 'pylon_twitter_image',
        ];

        foreach ($data['data'] as $entry) {
            $post_id = (int) ($entry['post_id'] ?? 0);
            if (!$post_id || !get_post($post_id)) continue;

            foreach ($map as $yoast_key => $pylon_key) {
                $val = $entry[$yoast_key] ?? '';
                if ($val !== '') {
                    if (in_array($yoast_key, ['noindex', 'nofollow'], true)) {
                        $val = '1';
                    }
                    update_post_meta($post_id, $pylon_key, $val);
                    $meta++;
                }
            }
        }

        return ['meta' => $meta, 'redirects' => 0, 'settings' => 0];
    }

    private function import_aioseo_json(string $tmp_path): array {
        $json = file_get_contents($tmp_path);
        $data = json_decode($json, true);

        if (!$data) {
            throw new \Exception(esc_html__('Invalid JSON file.', 'pylon-seo'));
        }

        $meta = 0;

        if (isset($data['post'])) {
            foreach ($data['post'] as $post_id => $fields) {
                if (!get_post($post_id)) continue;
                if (!empty($fields['title'])) {
                    update_post_meta($post_id, 'pylon_title', $fields['title']);
                    $meta++;
                }
                if (!empty($fields['description'])) {
                    update_post_meta($post_id, 'pylon_description', $fields['description']);
                    $meta++;
                }
                if (!empty($fields['keywords'])) {
                    update_post_meta($post_id, 'pylon_focus_keyword', is_array($fields['keywords']) ? implode(',', $fields['keywords']) : $fields['keywords']);
                    $meta++;
                }
            }
        }

        if (isset($data['redirects'])) {
            global $wpdb;
            $table = $wpdb->prefix . 'pylon_redirects';
            $redirects = 0;
            foreach ($data['redirects'] as $r) {
                $from = esc_url_raw($r['from'] ?? '');
                $to = esc_url_raw($r['to'] ?? '');
                $type = (int) ($r['type'] ?? 301);
                if ($from && $to) {
                    $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$table} (source_url, target_url, type) VALUES (%s, %s, %d)", $from, $to, $type));
                    $redirects += $wpdb->rows_affected;
                }
            }
        }

        return ['meta' => $meta, 'redirects' => $redirects ?? 0, 'settings' => 0];
    }

    private function import_generic_csv(string $tmp_path): array {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming CSV parse requires a file handle.
        $fh = fopen($tmp_path, 'r');
        if (!$fh) {
            throw new \Exception(esc_html__('Cannot read CSV file.', 'pylon-seo'));
        }

        $meta = 0;
        $header = fgetcsv($fh);
        if (!$header) {
            fclose($fh); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Matches fopen() handle above.
            throw new \Exception(esc_html__('Empty or invalid CSV.', 'pylon-seo'));
        }

        $header = array_map('strtolower', array_map('trim', $header));
        $col_map = ['post_id' => false, 'meta_key' => false, 'meta_value' => false];

        foreach ($header as $i => $col) {
            if (in_array($col, ['post_id', 'postid', 'id', 'object_id'], true)) $col_map['post_id'] = $i;
            if (in_array($col, ['meta_key', 'metakey', 'key', 'field'], true)) $col_map['meta_key'] = $i;
            if (in_array($col, ['meta_value', 'metavalue', 'value', 'val'], true)) $col_map['meta_value'] = $i;
        }

        if (in_array(false, $col_map, true)) {
            $col_map = ['post_id' => 0, 'meta_key' => 1, 'meta_value' => 2];
        }

        $pylon_prefix = 'pylon_';
        $known_map = [
            '_yoast_wpseo_title' => 'pylon_title',
            '_yoast_wpseo_metadesc' => 'pylon_description',
            '_yoast_wpseo_focuskw' => 'pylon_focus_keyword',
            '_yoast_wpseo_canonical' => 'pylon_canonical',
            '_yoast_wpseo_noindex' => 'pylon_noindex',
            '_yoast_wpseo_nofollow' => 'pylon_nofollow',
            '_yoast_wpseo_opengraph-title' => 'pylon_og_title',
            '_yoast_wpseo_opengraph-description' => 'pylon_og_description',
            '_yoast_wpseo_opengraph-image' => 'pylon_og_image',
            '_yoast_wpseo_twitter-title' => 'pylon_twitter_title',
            '_yoast_wpseo_twitter-description' => 'pylon_twitter_description',
            '_yoast_wpseo_twitter-image' => 'pylon_twitter_image',
            'rank_math_title' => 'pylon_title',
            'rank_math_description' => 'pylon_description',
            'rank_math_focus_keyword' => 'pylon_focus_keyword',
            'rank_math_canonical_url' => 'pylon_canonical',
            'rank_math_og_title' => 'pylon_og_title',
            'rank_math_og_description' => 'pylon_og_description',
            'rank_math_og_image' => 'pylon_og_image',
            'rank_math_twitter_title' => 'pylon_twitter_title',
            'rank_math_twitter_description' => 'pylon_twitter_description',
            'rank_math_twitter_image' => 'pylon_twitter_image',
            '_aioseo_title' => 'pylon_title',
            '_aioseo_description' => 'pylon_description',
            '_aioseo_keywords' => 'pylon_focus_keyword',
            '_seopress_titles_title' => 'pylon_title',
            '_seopress_titles_desc' => 'pylon_description',
            '_seopress_analysis_target_kw' => 'pylon_focus_keyword',
        ];

        while (($row = fgetcsv($fh)) !== false) {
            $pid = (int) ($row[$col_map['post_id']] ?? 0);
            $key = trim($row[$col_map['meta_key']] ?? '');
            $val = $row[$col_map['meta_value']] ?? '';

            if (!$pid || !$key || $val === '') continue;
            if (!get_post($pid)) continue;

            $target = $known_map[$key] ?? null;
            if (!$target && strpos($key, $pylon_prefix) === 0) {
                $target = $key;
            }
            if (!$target) continue;

            update_post_meta($pid, $target, $val);
            $meta++;
        }

        fclose($fh); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Matches fopen() handle above.
        return ['meta' => $meta, 'redirects' => 0, 'settings' => 0];
    }

    private function migrate_from(string $plugin): string {
        $migrated = [
            'meta' => 0,
            'redirects' => 0,
            'settings' => 0,
        ];

        switch ($plugin) {
            case 'wordpress-seo/wp-seo.php':
            case 'wordpress-seo-premium/wp-seo-premium.php':
                $migrated = $this->migrate_yoast();
                break;
            case 'seo-by-rank-math/rank-math.php':
                $migrated = $this->migrate_rankmath();
                break;
            case 'all-in-one-seo-pack/all_in_one_seo_pack.php':
            case 'all-in-one-seo-pack-pro/all_in_one_seo_pack.php':
                $migrated = $this->migrate_aioseo();
                break;
            case 'wp-seopress/seopress.php':
            case 'wp-seopress-pro/seopress-pro.php':
                $migrated = $this->migrate_seopress();
                break;
            default:
                $migrated = $this->migrate_generic($plugin);
        }

        return sprintf(
            /* translators: %1$d: number of migrated meta entries, %2$d: number of migrated redirects, %3$d: number of migrated settings. */
            __('Import complete! Migrated: %1$d meta entries, %2$d redirects, %3$d settings.', 'pylon-seo'),
            $migrated['meta'],
            $migrated['redirects'],
            $migrated['settings']
        );
    }

    private function migrate_yoast(): array {
        global $wpdb;
        $meta = 0;
        $redirects = 0;

        $results = $wpdb->get_results(
            "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key IN ('_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw', '_yoast_wpseo_canonical', '_yoast_wpseo_noindex', '_yoast_wpseo_nofollow', '_yoast_wpseo_opengraph-title', '_yoast_wpseo_opengraph-description', '_yoast_wpseo_opengraph-image', '_yoast_wpseo_twitter-title', '_yoast_wpseo_twitter-description', '_yoast_wpseo_twitter-image')"
        );

        $map = [
            '_yoast_wpseo_title' => 'pylon_title',
            '_yoast_wpseo_metadesc' => 'pylon_description',
            '_yoast_wpseo_focuskw' => 'pylon_focus_keyword',
            '_yoast_wpseo_canonical' => 'pylon_canonical',
            '_yoast_wpseo_noindex' => 'pylon_noindex',
            '_yoast_wpseo_nofollow' => 'pylon_nofollow',
            '_yoast_wpseo_opengraph-title' => 'pylon_og_title',
            '_yoast_wpseo_opengraph-description' => 'pylon_og_description',
            '_yoast_wpseo_opengraph-image' => 'pylon_og_image',
            '_yoast_wpseo_twitter-title' => 'pylon_twitter_title',
            '_yoast_wpseo_twitter-description' => 'pylon_twitter_description',
            '_yoast_wpseo_twitter-image' => 'pylon_twitter_image',
        ];

        foreach ($results as $row) {
            if (isset($map[$row->meta_key])) {
                update_post_meta($row->post_id, $map[$row->meta_key], $row->meta_value);
                $meta++;
            }
        }

        // Import Yoast Premium redirects (stored in wp_yoastRedirects option as serialized array).
        $pylon_table = $wpdb->prefix . 'pylon_redirects';
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $pylon_table)) === $pylon_table;

        if ($table_exists) {
            $yoast_redirects = get_option('wp_yoastRedirects', []);
            if (!is_array($yoast_redirects)) {
                $yoast_redirects = maybe_unserialize(get_option('yoast_redirects', []));
            }
            if (is_array($yoast_redirects)) {
                $values = [];
                foreach ($yoast_redirects as $r) {
                    $from = $r['origin'] ?? $r['from'] ?? '';
                    $to   = $r['target'] ?? $r['to'] ?? '';
                    $code = (int) ($r['type'] ?? 301);
                    if (!empty($from) && !empty($to)) {
                        $values[] = [esc_url_raw(home_url($from)), esc_url_raw($to), $code];
                    }
                }
                if (!empty($values)) {
                    $placeholders = implode(', ', array_fill(0, count($values), '(%s, %s, %d)'));
                    $args = [];
                    foreach ($values as $row) {
                        $args = array_merge($args, $row);
                    }
                    $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$pylon_table} (source_url, target_url, type) VALUES {$placeholders}", $args));
                    $redirects = $wpdb->rows_affected;
                }
            }
        }

        return ['meta' => $meta, 'redirects' => $redirects, 'settings' => 0];
    }

    private function migrate_rankmath(): array {
        global $wpdb;
        $meta = 0;
        $redirects = 0;
        $settings = 0;

        $results = $wpdb->get_results(
            "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key LIKE 'rank_math_%'"
        );

        $map = [
            'rank_math_title' => 'pylon_title',
            'rank_math_description' => 'pylon_description',
            'rank_math_focus_keyword' => 'pylon_focus_keyword',
            'rank_math_canonical_url' => 'pylon_canonical',
            'rank_math_robots' => null,
            'rank_math_og_title' => 'pylon_og_title',
            'rank_math_og_description' => 'pylon_og_description',
            'rank_math_og_image' => 'pylon_og_image',
            'rank_math_twitter_title' => 'pylon_twitter_title',
            'rank_math_twitter_description' => 'pylon_twitter_description',
            'rank_math_twitter_image' => 'pylon_twitter_image',
        ];

        foreach ($results as $row) {
            if (isset($map[$row->meta_key])) {
                $val = $row->meta_value;
                if ($row->meta_key === 'rank_math_robots') {
                    $robots = maybe_unserialize($val);
                    if (is_array($robots)) {
                        if (in_array('noindex', $robots, true)) {
                            update_post_meta($row->post_id, 'pylon_noindex', '1');
                        }
                        if (in_array('nofollow', $robots, true)) {
                            update_post_meta($row->post_id, 'pylon_nofollow', '1');
                        }
                    }
                } else {
                    update_post_meta($row->post_id, $map[$row->meta_key], $val);
                }
                $meta++;
            }
        }

        $primary = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = 'rank_math_primary_category' AND meta_value != ''"
        );
        foreach ($primary as $row) {
            update_post_meta($row->post_id, 'pylon_primary_category', (int) $row->meta_value);
            $meta++;
        }

        $rm_redirects = $wpdb->prefix . 'rank_math_redirections';
        $rm_cache = $wpdb->prefix . 'rank_math_redirections_cache';
        $pylon_table = $wpdb->prefix . 'pylon_redirects';

        $rm_redirects_exists = $wpdb->get_var("SHOW TABLES LIKE '{$rm_redirects}'") === $rm_redirects;
        if ($rm_redirects_exists) {
            $redirect_data = [];
            $sources = $wpdb->get_results(
                "SELECT r.id, r.url_to, r.header_code, c.from_url
                 FROM {$rm_redirects} r
                 LEFT JOIN {$rm_cache} c ON r.id = c.redirection_id"
            );
            foreach ($sources as $row) {
                $from = $row->from_url;
                if ($row->from_url && $row->url_to && $row->header_code) {
                    $source = esc_url_raw(home_url($row->from_url));
                    $target = esc_url_raw($row->url_to);
                    $type = ($row->header_code == 302) ? 302 : 301;
                    if ($source && $target) {
                        $redirect_data[] = $wpdb->prepare('(%s, %s, %d)', $source, $target, $type);
                    }
                }
            }
            if (!empty($redirect_data)) {
                $values = implode(', ', $redirect_data);
                $wpdb->query("INSERT IGNORE INTO {$pylon_table} (source_url, target_url, type) VALUES {$values}");
                $redirects = $wpdb->rows_affected;
            }
        }

        $titles = get_option('rank-math-options-titles');
        if ($titles && is_array($titles)) {
            if (isset($titles['homepage_title'])) {
                update_option('pylon_home_title', $titles['homepage_title']);
                $settings++;
            }
            if (isset($titles['homepage_description'])) {
                update_option('pylon_home_description', $titles['homepage_description']);
                $settings++;
            }
            if (isset($titles['noindex_empty_category'])) {
                update_option('pylon_noindex_empty_category', $titles['noindex_empty_category']);
                $settings++;
            }
            if (isset($titles['noindex_search'])) {
                update_option('pylon_noindex_search', $titles['noindex_search']);
                $settings++;
            }
            if (isset($titles['noindex_archive'])) {
                update_option('pylon_noindex_archive', $titles['noindex_archive']);
                $settings++;
            }
            if (isset($titles['noindex_paginated_pages'])) {
                update_option('pylon_noindex_paginated', $titles['noindex_paginated_pages']);
                $settings++;
            }
        }

        $general = get_option('rank-math-options-general');
        if ($general && is_array($general)) {
            if (isset($general['strip_category_base'])) {
                update_option('pylon_strip_category_base', $general['strip_category_base']);
                $settings++;
            }
            if (isset($general['nofollow_external_links'])) {
                update_option('pylon_nofollow_external', $general['nofollow_external_links']);
                $settings++;
            }
            if (isset($general['new_post_default'])) {
                update_option('pylon_default_robots', $general['new_post_default']);
                $settings++;
            }
        }

        $sitemap = get_option('rank-math-options-sitemap');
        if ($sitemap && is_array($sitemap)) {
            if (isset($sitemap['items_per_page'])) {
                update_option('pylon_sitemap_max_per_page', (int) $sitemap['items_per_page']);
                $settings++;
            }
            if (isset($sitemap['exclude_posts'])) {
                update_option('pylon_sitemap_exclude_ids', $sitemap['exclude_posts']);
                $settings++;
            }
            if (isset($sitemap['include_images'])) {
                update_option('pylon_sitemap_include_images', $sitemap['include_images']);
                $settings++;
            }
            if (isset($sitemap['exclude_roles'])) {
                update_option('pylon_sitemap_exclude_roles', $sitemap['exclude_roles']);
                $settings++;
            }
        }

        return ['meta' => $meta, 'redirects' => $redirects, 'settings' => $settings];
    }

    private function migrate_aioseo(): array {
        global $wpdb;
        $meta = 0;
        $redirects = 0;

        $page = 1;
        $all_posts = [];
        while ($page <= 10) {
            $batch = get_posts([
                'post_type' => 'any',
                'post_status' => 'any',
                'posts_per_page' => 200,
                'paged' => $page,
                'fields' => 'ids',
                'no_found_rows' => true,
                'meta_query' => [
                    ['key' => '_aioseo_title', 'compare' => 'EXISTS'],
                ],
            ]);
            if (empty($batch)) break;
            $all_posts = array_merge($all_posts, $batch);
            $page++;
        }

        if (!empty($all_posts)) {
            update_meta_cache('post', $all_posts);
        }

        foreach ($all_posts as $post_id) {
            $title = get_post_meta($post_id, '_aioseo_title', true);
            $desc = get_post_meta($post_id, '_aioseo_description', true);
            $keywords = get_post_meta($post_id, '_aioseo_keywords', true);

            if ($title) { update_post_meta($post_id, 'pylon_title', $title); $meta++; }
            if ($desc) { update_post_meta($post_id, 'pylon_description', $desc); $meta++; }
            if ($keywords) { update_post_meta($post_id, 'pylon_focus_keyword', $keywords); $meta++; }
        }

        // Import AIOSEO redirects (stored in aioseo_redirects table or _aioseo_redirects option).
        $pylon_table = $wpdb->prefix . 'pylon_redirects';
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $pylon_table)) === $pylon_table;
        $aio_table = $wpdb->prefix . 'aioseo_redirects';
        $aio_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $aio_table)) === $aio_table;

        if ($table_exists && $aio_exists) {
            $rows = $wpdb->get_results("SELECT source_url, target_url, type FROM {$aio_table} LIMIT 500");
            if (!empty($rows)) {
                $values = [];
                foreach ($rows as $r) {
                    $code = (int) ($r->type ?? 301);
                    if (!empty($r->source_url) && !empty($r->target_url)) {
                        $values[] = [$r->source_url, $r->target_url, $code];
                    }
                }
                if (!empty($values)) {
                    $placeholders = implode(', ', array_fill(0, count($values), '(%s, %s, %d)'));
                    $args = [];
                    foreach ($values as $row) {
                        $args = array_merge($args, $row);
                    }
                    $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$pylon_table} (source_url, target_url, type) VALUES {$placeholders}", $args));
                    $redirects = $wpdb->rows_affected;
                }
            }
        }

        return ['meta' => $meta, 'redirects' => $redirects, 'settings' => 0];
    }

    private function migrate_seopress(): array {
        global $wpdb;
        $meta = 0;
        $redirects = 0;

        $page = 1;
        $all_posts = [];
        while ($page <= 10) {
            $batch = get_posts([
                'post_type' => 'any',
                'post_status' => 'any',
                'posts_per_page' => 200,
                'paged' => $page,
                'fields' => 'ids',
                'no_found_rows' => true,
                'meta_query' => [
                    ['key' => '_seopress_titles_title', 'compare' => 'EXISTS'],
                ],
            ]);
            if (empty($batch)) break;
            $all_posts = array_merge($all_posts, $batch);
            $page++;
        }

        if (!empty($all_posts)) {
            update_meta_cache('post', $all_posts);
        }

        foreach ($all_posts as $post_id) {
            $title = get_post_meta($post_id, '_seopress_titles_title', true);
            $desc = get_post_meta($post_id, '_seopress_titles_desc', true);
            $kw = get_post_meta($post_id, '_seopress_analysis_target_kw', true);

            if ($title) { update_post_meta($post_id, 'pylon_title', $title); $meta++; }
            if ($desc) { update_post_meta($post_id, 'pylon_description', $desc); $meta++; }
            if ($kw) { update_post_meta($post_id, 'pylon_focus_keyword', $kw); $meta++; }
        }

        // Import SEOPress redirects (_seopress_redirect_rules option).
        $pylon_table = $wpdb->prefix . 'pylon_redirects';
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $pylon_table)) === $pylon_table;

        if ($table_exists) {
            $sp_rules = get_option('_seopress_redirect_rules', []);
            if (is_array($sp_rules)) {
                $values = [];
                foreach ($sp_rules as $r) {
                    $enable = $r['enable'] ?? $r['enabled'] ?? true;
                    if (!$enable) continue;
                    $from = $r['source'] ?? $r['from'] ?? '';
                    $to   = $r['target'] ?? $r['to'] ?? '';
                    $code = (int) ($r['status_code'] ?? $r['code'] ?? 301);
                    if (!empty($from) && !empty($to)) {
                        $values[] = [esc_url_raw(home_url($from)), esc_url_raw($to), $code];
                    }
                }
                if (!empty($values)) {
                    $placeholders = implode(', ', array_fill(0, count($values), '(%s, %s, %d)'));
                    $args = [];
                    foreach ($values as $row) {
                        $args = array_merge($args, $row);
                    }
                    $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$pylon_table} (source_url, target_url, type) VALUES {$placeholders}", $args));
                    $redirects = $wpdb->rows_affected;
                }
            }
        }

        return ['meta' => $meta, 'redirects' => $redirects, 'settings' => 0];
    }

    private function migrate_generic(string $plugin): array {
        global $wpdb;
        $meta     = 0;
        $redirects = 0;
        $seen     = [];

        // Best-effort: scan postmeta for common SEO title/description key patterns
        // from any plugin not explicitly supported by named migrators.
        $title_patterns = ['%seo_title%', '%_title%', '%meta_title%'];
        $desc_patterns  = ['%seo_desc%', '%meta_desc%', '%seo_description%', '%meta_description%'];

        $process = function (array $patterns, string $pylon_key) use ($wpdb, &$meta, &$seen): void {
            foreach ($patterns as $pattern) {
                $results = $wpdb->get_results($wpdb->prepare(
                    "SELECT post_id, meta_key, meta_value
                     FROM {$wpdb->postmeta}
                     WHERE meta_key LIKE %s
                       AND meta_value != ''
                     LIMIT 500",
                    $pattern
                ));

                foreach ($results as $row) {
                    // Skip keys already handled by named migrators
                    if (strpos($row->meta_key, '_yoast_wpseo_') === 0) continue;
                    if (strpos($row->meta_key, 'rank_math_') === 0) continue;
                    if (strpos($row->meta_key, '_aioseo_') === 0) continue;
                    if (strpos($row->meta_key, '_seopress_') === 0) continue;
                    if (strpos($row->meta_key, 'pylon_') === 0) continue;

                    $cache_key = $row->post_id . '|' . $pylon_key;
                    if (isset($seen[$cache_key])) continue;
                    if (!get_post($row->post_id)) continue;

                    update_post_meta($row->post_id, $pylon_key, $row->meta_value);
                    $seen[$cache_key] = true;
                    $meta++;
                }
            }
        };

        $process($title_patterns, 'pylon_title');
        $process($desc_patterns, 'pylon_description');

        // Import redirects from known generic plugin option keys.
        $pylon_table = $wpdb->prefix . 'pylon_redirects';
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $pylon_table)) === $pylon_table;

        if ($table_exists) {
            $redirect_sources = [];

            // SEO Framework: _tf_redirections (serialized array of [from => to])
            $tf = get_option('_tf_redirections', []);
            if (is_array($tf)) {
                foreach ($tf as $from => $to) {
                    if (!empty($from) && !empty($to)) {
                        $redirect_sources[] = [esc_url_raw(home_url($from)), esc_url_raw($to), 301];
                    }
                }
            }

            // Slim SEO: slim_seo_redirects (array of [from, to, code])
            $slim = get_option('slim_seo_redirects', []);
            if (is_array($slim)) {
                foreach ($slim as $r) {
                    $from = $r['from'] ?? $r['url_from'] ?? '';
                    $to   = $r['to'] ?? $r['url_to'] ?? '';
                    $code = (int) ($r['code'] ?? $r['status_code'] ?? 301);
                    if (!empty($from) && !empty($to)) {
                        $redirect_sources[] = [esc_url_raw(home_url($from)), esc_url_raw($to), $code];
                    }
                }
            }

            // SmartCrawl: wpmu_dev_seo_redirects (array of [url_from, url_to, status_code])
            $sc = get_option('wpmu_dev_seo_redirects', []);
            if (is_array($sc)) {
                foreach ($sc as $r) {
                    $from = $r['url_from'] ?? $r['from'] ?? '';
                    $to   = $r['url_to'] ?? $r['to'] ?? '';
                    $code = (int) ($r['status_code'] ?? $r['code'] ?? 301);
                    if (!empty($from) && !empty($to)) {
                        $redirect_sources[] = [esc_url_raw(home_url($from)), esc_url_raw($to), $code];
                    }
                }
            }

            // SiteSEO: siteseo_redirections (array of [enable, from, to, code])
            $seo = get_option('siteseo_redirections', []);
            if (is_array($seo)) {
                foreach ($seo as $r) {
                    if (!empty($r['enable']) && $r['enable'] !== 'on') continue;
                    $from = $r['from'] ?? '';
                    $to   = $r['to'] ?? '';
                    $code = (int) ($r['code'] ?? 301);
                    if (!empty($from) && !empty($to)) {
                        $redirect_sources[] = [esc_url_raw(home_url($from)), esc_url_raw($to), $code];
                    }
                }
            }

            // SEO Engine: seo_engine_redirects (array of [old_url, new_url, type])
            $se = get_option('seo_engine_redirects', []);
            if (is_array($se)) {
                foreach ($se as $r) {
                    $from = $r['old_url'] ?? $r['from'] ?? '';
                    $to   = $r['new_url'] ?? $r['to'] ?? '';
                    $code = (int) ($r['type'] ?? $r['code'] ?? 301);
                    if (!empty($from) && !empty($to)) {
                        $redirect_sources[] = [esc_url_raw(home_url($from)), esc_url_raw($to), $code];
                    }
                }
            }

            if (!empty($redirect_sources)) {
                $chunks = array_chunk($redirect_sources, 50);
                foreach ($chunks as $chunk) {
                    $placeholders = implode(', ', array_fill(0, count($chunk), '(%s, %s, %d)'));
                    $args = [];
                    foreach ($chunk as $r) {
                        $args[] = $r[0];
                        $args[] = $r[1];
                        $args[] = $r[2];
                    }
                    $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$pylon_table} (source_url, target_url, type) VALUES {$placeholders}", $args));
                    $redirects += $wpdb->rows_affected;
                }
            }
        }

        return ['meta' => $meta, 'redirects' => $redirects, 'settings' => 0];
    }

    private function csv_encode(array $rows): string {
        $lines = [];
        foreach ($rows as $row) {
            $fields = [];
            foreach ($row as $field) {
                $fields[] = '"' . str_replace('"', '""', (string) $field) . '"';
            }
            $lines[] = implode(',', $fields);
        }
        return implode("\n", $lines);
    }
}
