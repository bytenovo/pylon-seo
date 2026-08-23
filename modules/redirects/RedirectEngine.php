<?php
namespace Pylon\Core\Modules\Redirects;
defined('ABSPATH') || exit;
class RedirectEngine {
    private static ?array $redirect_cache = null;
    private static array $regex_redirects = [];

    public function register(): void {
        if (!get_option('pylon_redirects_enabled', '1')) return;

        add_action('template_redirect', [$this, 'handle_redirects'], 0);
        add_action('template_redirect', [$this, 'redirect_attachment_to_parent'], 1);
        add_action('template_redirect', [$this, 'log_404'], 9999);
        add_action('post_updated', [$this, 'auto_redirect_on_slug_change'], 10, 3);
        add_action('wp_ajax_pylon_suggest_404_redirect', [$this, 'ajax_suggest_redirect']);
        add_action('pylon_redirect_added', [$this, 'clear_redirect_cache']);
        add_action('pylon_redirect_deleted', [$this, 'clear_redirect_cache']);

        if (is_admin()) {
            add_action('admin_menu', [$this, 'add_admin_page']);
            add_action('admin_enqueue_scripts', [$this, 'enqueue']);
            add_action('wp_ajax_pylon_export_redirects', [$this, 'ajax_export_csv']);
            add_action('wp_ajax_pylon_import_redirects', [$this, 'ajax_import_csv']);
        }
    }

    public function enqueue(string $hook): void {
        if (strpos($hook, 'pylon-redirects') === false && strpos($hook, 'pylon-group-links') === false) return;
        wp_enqueue_style('pylon-redirects', PYLON_URL . 'assets/css/modules/redirects.css', ['pylon-admin'], filemtime(PYLON_PATH . 'assets/css/modules/redirects.css'));
        \Pylon\Core\Modules\Admin\AdminEngine::add_module_js($this->js());
    }

    private function js(): string {
        $ajax_url = esc_js(admin_url('admin-ajax.php'));
        return '
        jQuery(function($) {
            $("#pylon-import-redirects").on("submit", function(e) {
                e.preventDefault();
                var fd = new FormData(this);
                var btn = $(this).find("button[type=\"submit\"]").prop("disabled", true);
                $.ajax({
                    url: "' . $ajax_url . '",
                    type: "POST",
                    data: fd,
                    processData: false,
                    contentType: false,
                    success: function(res) {
                        var msg = res.success ? "<div class=\"pylon-notice pylon-notice-success\">" + res.data.message + "</div>" : "<div class=\"pylon-notice pylon-notice-danger\">" + ((res.data && res.data.message) || "Import failed.") + "</div>";
                        $("#pylon-import-redirects-result").html(msg);
                        if (res.success) { location.reload(); }
                    },
                    error: function() {
                        $("#pylon-import-redirects-result").html("<div class=\"pylon-notice pylon-notice-danger\">Server error.</div>");
                    },
                    complete: function() { btn.prop("disabled", false); }
                });
            });
        });
        ';
    }

    public function auto_redirect_on_slug_change(int $post_id, \WP_Post $post_after, \WP_Post $post_before): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!get_option('pylon_auto_redirect_slug', '1')) return;
        if ($post_before->post_status !== 'publish') return;
        if ($post_after->post_status !== 'publish') return;

        $old_slug = $post_before->post_name;
        $new_slug = $post_after->post_name;
        if ($old_slug === $new_slug) return;

        $old_path = ltrim(get_permalink($post_before), home_url());
        $new_path = ltrim(get_permalink($post_after), home_url());

        $this->add_redirect($old_path, $new_path);
    }

    public function suggest_redirect_for_404(string $url): ?array {
        $url = ltrim($url, '/');
        $slug = basename($url);

        $posts = get_posts([
            's' => str_replace(['-', '_'], ' ', $slug),
            'post_type' => 'any',
            'post_status' => 'publish',
            'posts_per_page' => 5,
            'no_found_rows' => true,
        ]);

        if (empty($posts)) return null;

        $suggestions = [];
        foreach ($posts as $post) {
            $suggestions[] = [
                'id' => $post->ID,
                'title' => $post->post_title,
                'url' => get_permalink($post),
                'similarity' => similar_text($slug, $post->post_name, $pct) ? $pct : 0,
            ];
        }

        usort($suggestions, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
        return $suggestions;
    }

    public function ajax_suggest_redirect(): void {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_ajax_nonce'] ?? '')), 'pylon_admin_nonce') || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'pylon-seo')]);
        }

        $url = sanitize_text_field(wp_unslash($_POST['url'] ?? ''));
        $suggestions = $this->suggest_redirect_for_404($url);

        if (!$suggestions) {
            wp_send_json_success(['suggestions' => []]);
        }

        ob_start();
        foreach ($suggestions as $s):
            $new_url = ltrim(wp_parse_url($s['url'], PHP_URL_PATH), '/');
            ?>
            <div class="pylon-flex pylon-flex-center pylon-gap-8" style="margin-bottom:4px;">
                <span style="flex:1;font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html($s['title']); ?></span>
                <a href="<?php echo esc_url(admin_url('admin-post.php?action=pylon_add_redirect&source=' . urlencode($url) . '&target=' . urlencode($new_url) . '&_wpnonce=' . wp_create_nonce('pylon_redirect_action'))); ?>" class="pylon-btn pylon-btn-sm pylon-btn-primary">
                    <?php esc_html_e('Redirect', 'pylon-seo'); ?> →
                </a>
            </div>
            <?php
        endforeach;
        $html = ob_get_clean();

        wp_send_json_success(['suggestions' => $suggestions, 'html' => $html]);
    }

    public function handle_redirects(): void {
        if (is_admin()) return;

        $request_path = $this->get_relative_path();

        if (self::$redirect_cache === null) {
            global $wpdb;
            self::$redirect_cache = [];
            self::$regex_redirects = [];

            $cached = get_transient('pylon_redirect_list');
            if ($cached !== false) {
                self::$redirect_cache = $cached['exact'] ?? [];
                self::$regex_redirects = $cached['regex'] ?? [];
            } else {
                $results = $wpdb->get_results("SELECT source_url, target_url, type, match_type, id FROM {$wpdb->prefix}pylon_redirects");
                foreach ($results as $r) {
                    $mt = $r->match_type ?? 'exact';
                    if ($mt === 'regex') {
                        self::$regex_redirects[] = $r;
                    } else {
                        self::$redirect_cache[$r->source_url] = $r;
                    }
                }
                set_transient('pylon_redirect_list', ['exact' => self::$redirect_cache, 'regex' => self::$regex_redirects], HOUR_IN_SECONDS);
            }
        }

        // Exact match first.
        $redirect = self::$redirect_cache[$request_path] ?? null;

        // Wildcard / regex fallback.
        if (!$redirect && !empty(self::$regex_redirects)) {
            foreach (self::$regex_redirects as $candidate) {
                $mt = $candidate->match_type ?? 'exact';
                if ($mt === 'wildcard') {
                    $pattern = str_replace(['*', '?'], ['.*', '.'], $candidate->source_url);
                    if (preg_match('#^' . $pattern . '$#', $request_path)) {
                        $redirect = $candidate;
                        break;
                    }
                } elseif ($mt === 'regex') {
                    if (@preg_match($candidate->source_url, $request_path)) {
                        $redirect = $candidate;
                        break;
                    }
                }
            }
        }

        if ($redirect) {
            global $wpdb;
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}pylon_redirects SET hits = hits + 1, last_accessed = NOW() WHERE id = %d",
                $redirect->id
            ));

            $target = $redirect->target_url;

            // Replace capture-group placeholders for regex/wildcard ($1, $2, etc.).
            if (preg_match_all('/\$(\d+)/', $target, $matches)) {
                $mt = $redirect->match_type ?? 'exact';
                $src_pattern = $candidate->source_url ?? '';
                if ($mt === 'wildcard') {
                    $src_pattern = str_replace(['*', '?'], ['.*', '.'], $src_pattern);
                }
                if (@preg_match('#^' . $src_pattern . '#', $request_path, $parts)) {
                    foreach ($matches[1] as $idx => $group) {
                        $target = str_replace($matches[0][$idx], $parts[(int)$group] ?? '', $target);
                    }
                }
            }

            // 410 (Gone) and 451 (Unavailable For Legal Reasons) are status-only:
            // no Location header, just the HTTP status + a minimal page.
            if (in_array((int) $redirect->type, [410, 451], true)) {
                status_header((int) $redirect->type);
                nocache_headers();
                $status_msg = (int) $redirect->type === 410
                    ? __('This page is no longer available.', 'pylon-seo')
                    : __('This page is unavailable for legal reasons.', 'pylon-seo');
                wp_die(
                    esc_html($status_msg),
                    '',
                    ['response' => (int) $redirect->type, 'back_link' => false]
                );
            }

            wp_redirect(home_url($target), (int) $redirect->type);
            exit;
        }
    }

        public function redirect_attachment_to_parent(): void {
        if (!get_option('pylon_redirect_attachments', '1')) return;
        if (is_admin()) return;
        if (!is_attachment()) return;

        $post = get_queried_object();
        if (!$post instanceof \WP_Post) return;

        $parent_id = (int) $post->post_parent;
        if (!$parent_id) return;

        $parent_url = get_permalink($parent_id);
        if (!$parent_url) return;

        wp_redirect($parent_url, 301);
        exit;
    }

    public function log_404(): void {
        if (!get_option('pylon_404_monitor_enabled', '1')) return;
        if (!is_404()) return;

        $request_path = $this->get_relative_path();

        global $wpdb;
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}pylon_404_log WHERE url = %s",
            $request_path
        ));

        if ($existing) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}pylon_404_log SET hits = hits + 1, last_seen = NOW() WHERE id = %d",
                $existing
            ));
        } else {
            $wpdb->insert(
                "{$wpdb->prefix}pylon_404_log",
                [
                    'url' => $request_path,
                    'referer' => wp_get_raw_referer(),
                    'user_agent' => sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')),
                    'ip' => sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '')),
                ]
            );
        }
    }

    public function add_redirect(string $source, string $target, int $type = 301, string $match_type = 'exact'): void {
        $valid = ['exact', 'wildcard', 'regex'];
        if (!in_array($match_type, $valid, true)) $match_type = 'exact';

        $source_clean = $match_type === 'exact' ? $this->normalize_path($source) : $source;
        $target_clean = $this->normalize_path($target);

        global $wpdb;
        $wpdb->replace(
            "{$wpdb->prefix}pylon_redirects",
            [
                'source_url'  => $source_clean,
                'target_url'  => $target_clean,
                'type'        => $type,
                'match_type'  => $match_type,
            ]
        );
        do_action('pylon_redirect_added');
    }

    public function delete_redirect(int $id): void {
        global $wpdb;
        $wpdb->delete("{$wpdb->prefix}pylon_redirects", ['id' => $id]);
        do_action('pylon_redirect_deleted');
    }

    public function clear_redirect_cache(): void {
        self::$redirect_cache = null;
        delete_transient('pylon_redirect_list');
    }

    private function get_relative_path(): string {
        $home_path = wp_parse_url(home_url(), PHP_URL_PATH);
        $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
        $path = $home_path ? str_replace($home_path, '', $request_uri) : $request_uri;
        return ltrim(wp_parse_url($path, PHP_URL_PATH), '/');
    }

    private function normalize_path(string $path): string {
        return ltrim(str_replace(home_url(), '', $path), '/');
    }

    public function ajax_export_csv(): void {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'pylon_export_redirects') || !current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized.', 'pylon-seo'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'pylon_redirects';
        $results = $wpdb->get_results("SELECT source_url, target_url, type FROM {$table} ORDER BY id");

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="pylon-redirects-export.csv"');

        $fh = fopen('php://output', 'w');
        fputcsv($fh, ['source_url', 'target_url', 'type']);
        foreach ($results as $row) {
            fputcsv($fh, [$row->source_url, $row->target_url, (int) $row->type]);
        }
        fclose($fh); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        exit;
    }

    public function ajax_import_csv(): void {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? '')), 'pylon_import_redirects') || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'pylon-seo')]);
        }

        if (empty($_FILES['import_csv']) || absint($_FILES['import_csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => __('Upload failed.', 'pylon-seo')]);
        }

        $tmp_name = sanitize_text_field(wp_unslash($_FILES['import_csv']['tmp_name'] ?? ''));
        $fh = fopen($tmp_name, 'r'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if (!$fh) {
            wp_send_json_error(['message' => __('Cannot read file.', 'pylon-seo')]);
        }

        $header = fgetcsv($fh);
        if (!$header) {
            fclose($fh); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            wp_send_json_error(['message' => __('Empty CSV.', 'pylon-seo')]);
        }

        $header = array_map('strtolower', array_map('trim', $header));
        $src_idx = array_search('source_url', $header, true);
        $tgt_idx = array_search('target_url', $header, true);
        $typ_idx = array_search('type', $header, true);
        $mt_idx = array_search('match_type', $header, true);

        if ($src_idx === false || $tgt_idx === false) {
            fclose($fh); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            wp_send_json_error(['message' => __('CSV must have source_url and target_url columns.', 'pylon-seo')]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'pylon_redirects';
        $imported = 0;
        $skipped = 0;

        while (($row = fgetcsv($fh)) !== false) {
            $source = trim($row[$src_idx] ?? '');
            $target = trim($row[$tgt_idx] ?? '');
            $type = $typ_idx !== false ? (int) ($row[$typ_idx] ?? 301) : 301;
            $mt = $mt_idx !== false ? strtolower(trim($row[$mt_idx] ?? 'exact')) : 'exact';

            if (!$source || !$target) continue;
            if (!in_array($type, [301, 302, 307, 308, 410, 451], true)) $type = 301;
            if (!in_array($mt, ['exact', 'wildcard', 'regex'], true)) $mt = 'exact';

            $source_check = $mt === 'exact' ? $this->normalize_path($source) : $source;
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE source_url = %s",
                $source_check
            ));

            if ($existing) {
                $skipped++;
                continue;
            }

            $this->add_redirect($source, $target, $type, $mt);
            $imported++;
        }

        fclose($fh); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

        $msg = sprintf(
            /* translators: 1: Number of redirects imported, 2: Number of redirects skipped because they already exist. */
            __('Imported: %1$d, Skipped (already exist): %2$d', 'pylon-seo'),
            $imported,
            $skipped
        );
        wp_send_json_success(['message' => $msg]);
    }

    public function add_admin_page(): void {
        add_submenu_page(
            'pylon',
            __('Redirects', 'pylon-seo'),
            __('Redirects', 'pylon-seo'),
            'manage_options',
            'pylon-redirects',
            [$this, 'render_admin_page']
        );
    }

    public function render_admin_page(): void {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $rpp = max(10, min(100, absint($_GET['rpp'] ?? 30)));
        $rpaged = max(1, absint($_GET['rpaged'] ?? 1));
        $roffset = ($rpaged - 1) * $rpp;
        $rtotal = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}pylon_redirects");
        $redirects = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$prefix}pylon_redirects ORDER BY hits DESC LIMIT %d OFFSET %d", $rpp, $roffset
        ));
        $rtotal_pages = ceil($rtotal / $rpp);

        $lpp = max(10, min(100, absint($_GET['lpp'] ?? 30)));
        $lpaged = max(1, absint($_GET['lpaged'] ?? 1));
        $loffset = ($lpaged - 1) * $lpp;
        $ltotal = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}pylon_404_log");
        $log = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$prefix}pylon_404_log ORDER BY hits DESC LIMIT %d OFFSET %d", $lpp, $loffset
        ));
        $ltotal_pages = ceil($ltotal / $lpp);

        $base_url = admin_url('admin.php?page=pylon-group-links&tab=redirects');
        ?>
        <div class="pylon-redirects-grid">
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px">
                <h3 style="margin:0 0 14px;font-size:14px;font-weight:600;color:#111827"><?php esc_html_e('Add Redirect', 'pylon-seo'); ?></h3>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pylon-redirects-form">
                    <input type="hidden" name="action" value="pylon_add_redirect">
                    <?php wp_nonce_field('pylon_redirect_action'); ?>
                    <div class="pylon-form-group">
                        <label for="source"><?php esc_html_e('Source URL', 'pylon-seo'); ?></label>
                        <input type="text" name="source" id="source" class="pylon-input" placeholder="/old-page" required>
                    </div>
                    <div class="pylon-form-group">
                        <label for="target"><?php esc_html_e('Target URL', 'pylon-seo'); ?></label>
                        <input type="text" name="target" id="target" class="pylon-input" placeholder="/new-page" required>
                    </div>
                    <div class="pylon-form-group">
                        <label for="type"><?php esc_html_e('Type', 'pylon-seo'); ?></label>
                        <select name="type" id="type" class="pylon-select">
<option value="301">301</option>
<option value="302">302</option>
<option value="307">307</option>
<option value="308">308</option>
<option value="410">410</option>
<option value="451">451</option>
                        </select>
                    </div>
                    <div class="pylon-form-group">
                        <label for="match_type"><?php esc_html_e('Match', 'pylon-seo'); ?></label>
                        <select name="match_type" id="match_type" class="pylon-select">
                            <option value="exact"><?php esc_html_e('Exact', 'pylon-seo'); ?></option>
                            <option value="wildcard"><?php esc_html_e('Wildcard', 'pylon-seo'); ?></option>
                            <option value="regex"><?php esc_html_e('Regex', 'pylon-seo'); ?></option>
                        </select>
                    </div>
                    <button type="submit" class="pylon-btn pylon-btn-primary" style="height:38px"><?php esc_html_e('Add', 'pylon-seo'); ?></button>
                </form>
            </div>

            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px">
                <h3 style="margin:0 0 14px;font-size:14px;font-weight:600;color:#111827"><?php esc_html_e('Import / Export', 'pylon-seo'); ?></h3>
                <div class="pylon-import-area">
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-ajax.php?action=pylon_export_redirects'), 'pylon_export_redirects')); ?>" class="pylon-btn pylon-btn-secondary">
                        <?php esc_html_e('Download CSV', 'pylon-seo'); ?>
                    </a>
                    <span style="font-size:11px;color:#9ca3af"><?php esc_html_e('source_url, target_url, type', 'pylon-seo'); ?></span>
                </div>
                <form id="pylon-import-redirects" enctype="multipart/form-data" style="margin-top:12px">
                    <?php wp_nonce_field('pylon_import_redirects', '_wpnonce'); ?>
                    <input type="hidden" name="action" value="pylon_import_redirects">
                    <div class="pylon-import-area">
                        <input type="file" name="import_csv" accept=".csv" required class="pylon-input" style="flex:1;padding:6px 10px;font-size:12px">
                        <button type="submit" class="pylon-btn pylon-btn-primary"><?php esc_html_e('Import CSV', 'pylon-seo'); ?></button>
                    </div>
                    <p style="margin:8px 0 0;font-size:11px;color:#9ca3af"><?php esc_html_e('Headers: source_url, target_url, type (301/302/307/308/410/451), match_type (exact/wildcard/regex). Existing sources skipped.', 'pylon-seo'); ?></p>
                    <div id="pylon-import-redirects-result" style="margin-top:8px"></div>
                </form>
            </div>
        </div>

        <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;margin-bottom:16px;overflow:hidden">
            <div style="padding:14px 20px;border-bottom:1px solid #f3f4f6;background:#f9fafb;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
                <div style="display:flex;align-items:center;gap:12px">
                    <h2 style="margin:0;font-size:14px;font-weight:600;color:#111827"><?php esc_html_e('Active Redirects', 'pylon-seo'); ?></h2>
                    <span style="font-size:12px;color:#6b7280"><?php echo (int) $rtotal; ?> <?php esc_html_e('total', 'pylon-seo'); ?></span>
                </div>
                <form method="get" style="display:inline-flex;gap:4px;align-items:center">
                    <input type="hidden" name="page" value="pylon-group-links">
                    <input type="hidden" name="tab" value="redirects">
                    <label style="font-size:11px;color:#6b7280"><?php esc_html_e('Per page:', 'pylon-seo'); ?>
                        <select name="rpp" onchange="this.form.submit()" style="width:76px;height:30px;font-size:12px;padding:2px 6px;margin-left:4px;border:1px solid #d0d5dd;border-radius:6px;background:#fff">
                            <?php foreach ([20, 30, 40, 50, 100] as $n): ?>
                            <option value="<?php echo esc_attr($n); ?>" <?php selected($rpp, $n); ?>><?php echo esc_html($n); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </form>
            </div>
            <div class="pylon-table-wrap">
                <table class="pylon-table">
                    <thead>
                        <tr><th><?php esc_html_e('Source', 'pylon-seo'); ?></th><th><?php esc_html_e('Target', 'pylon-seo'); ?></th><th><?php esc_html_e('Type', 'pylon-seo'); ?></th><th><?php esc_html_e('Match', 'pylon-seo'); ?></th><th><?php esc_html_e('Hits', 'pylon-seo'); ?></th><th><?php esc_html_e('Actions', 'pylon-seo'); ?></th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($redirects)): ?>
                            <tr><td colspan="6"><div class="pylon-table-empty"><p><?php esc_html_e('No redirects yet.', 'pylon-seo'); ?></p></div></td></tr>
                        <?php else: ?>
                            <?php foreach ($redirects as $r): ?>
                                <tr>
                                    <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo esc_html($r->source_url); ?></td>
                                    <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo esc_html($r->target_url); ?></td>
                                    <td><span class="pylon-badge" style="background:#f3f4f6;color:#374151;font-weight:600"><?php echo esc_html($r->type); ?></span></td>
                                    <td><span class="pylon-badge" style="background:#e0e7ff;color:#3730a3;font-weight:500;font-size:11px"><?php echo esc_html($r->match_type ?? 'exact'); ?></span></td>
                                    <td><?php echo (int) $r->hits; ?></td>
                                    <td class="pylon-cell-actions"><a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=pylon_delete_redirect&id=' . $r->id), 'pylon_redirect_action')); ?>" class="pylon-btn pylon-btn-danger pylon-btn-sm"><?php esc_html_e('Delete', 'pylon-seo'); ?></a></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($rtotal_pages > 1): ?>
            <div style="padding:10px 20px;border-top:1px solid #f3f4f6;display:flex;justify-content:center">
                <div class="pylon-pagination">
                <?php echo wp_kses_post(paginate_links([
                    'base' => add_query_arg(['rpaged' => '%#%', 'rpp' => $rpp], $base_url),
                    'format' => '',
                    'prev_text' => '‹',
                    'next_text' => '›',
                    'total' => $rtotal_pages,
                    'current' => $rpaged,
                    'mid_size' => 2,
                    'end_size' => 1,
                ])); ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden">
            <div style="padding:14px 20px;border-bottom:1px solid #f3f4f6;background:#f9fafb;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
                <div style="display:flex;align-items:center;gap:12px">
                    <h2 style="margin:0;font-size:14px;font-weight:600;color:#111827"><?php esc_html_e('Recent 404 Errors', 'pylon-seo'); ?></h2>
                    <span style="font-size:12px;color:#6b7280"><?php echo (int) $ltotal; ?> <?php esc_html_e('total', 'pylon-seo'); ?></span>
                </div>
                <form method="get" style="display:inline-flex;gap:4px;align-items:center">
                    <input type="hidden" name="page" value="pylon-group-links">
                    <input type="hidden" name="tab" value="redirects">
                    <label style="font-size:11px;color:#6b7280"><?php esc_html_e('Per page:', 'pylon-seo'); ?>
                        <select name="lpp" onchange="this.form.submit()" style="width:76px;height:30px;font-size:12px;padding:2px 6px;margin-left:4px;border:1px solid #d0d5dd;border-radius:6px;background:#fff">
                            <?php foreach ([20, 30, 40, 50, 100] as $n): ?>
                            <option value="<?php echo esc_attr($n); ?>" <?php selected($lpp, $n); ?>><?php echo esc_html($n); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </form>
            </div>
            <div class="pylon-table-wrap">
                <table class="pylon-table">
                    <thead>
                        <tr><th><?php esc_html_e('URL', 'pylon-seo'); ?></th><th><?php esc_html_e('Hits', 'pylon-seo'); ?></th><th><?php esc_html_e('Last Seen', 'pylon-seo'); ?></th><th><?php esc_html_e('Actions', 'pylon-seo'); ?></th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($log)): ?>
                            <tr><td colspan="4"><div class="pylon-table-empty"><p><?php esc_html_e('No 404 errors recorded.', 'pylon-seo'); ?></p></div></td></tr>
                        <?php else: ?>
                            <?php foreach ($log as $entry): ?>
                                <tr>
                                    <td style="max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo esc_html($entry->url); ?></td>
                                    <td><?php echo (int) $entry->hits; ?></td>
                                    <td style="font-size:12px;color:#6b7280"><?php echo esc_html($entry->last_seen); ?></td>
                                    <td class="pylon-cell-actions">
                                        <a href="<?php echo esc_url(admin_url('admin-post.php?action=pylon_add_redirect&source=' . urlencode($entry->url) . '&_wpnonce=' . wp_create_nonce('pylon_redirect_action'))); ?>" class="pylon-btn pylon-btn-sm pylon-btn-secondary"><?php esc_html_e('Redirect', 'pylon-seo'); ?></a>
                                        <button type="button" class="pylon-btn pylon-btn-sm pylon-btn-secondary" data-pylon-suggest="<?php echo esc_attr($entry->url); ?>" data-target="suggest_<?php echo esc_attr($entry->id); ?>"><?php esc_html_e('Suggest', 'pylon-seo'); ?></button>
                                        <div id="suggest_<?php echo esc_attr($entry->id); ?>" style="margin-top:4px;"></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($ltotal_pages > 1): ?>
            <div style="padding:10px 20px;border-top:1px solid #f3f4f6;display:flex;justify-content:center">
                <div class="pylon-pagination">
                <?php echo wp_kses_post(paginate_links([
                    'base' => add_query_arg(['lpaged' => '%#%', 'lpp' => $lpp], $base_url),
                    'format' => '',
                    'prev_text' => '‹',
                    'next_text' => '›',
                    'total' => $ltotal_pages,
                    'current' => $lpaged,
                    'mid_size' => 2,
                    'end_size' => 1,
                ])); ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
