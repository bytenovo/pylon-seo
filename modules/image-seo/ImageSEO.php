<?php
namespace Pylon\Core\Modules\ImageSeo;
defined('ABSPATH') || exit;

class ImageSEO {
    public function register(): void {
        add_action('admin_menu', [$this, 'add_admin_page']);
        add_action('wp_ajax_pylon_image_seo_scan', [$this, 'ajax_scan']);
        add_action('wp_ajax_pylon_image_seo_update_alt', [$this, 'ajax_update_alt']);
        add_action('wp_ajax_pylon_image_seo_dash_summary', [$this, 'ajax_dash_summary']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('pylon_dashboard_image_seo', [$this, 'render_dashboard_card']);
        add_action('added_postmeta', [$this, 'on_alt_meta_changed'], 10, 3);
        add_action('updated_postmeta', [$this, 'on_alt_meta_changed'], 10, 3);
        add_action('deleted_postmeta', [$this, 'on_alt_meta_changed'], 10, 3);
    }

    public function on_alt_meta_changed($meta_id, $object_id, $meta_key): void {
        if ($meta_key !== '_wp_attachment_image_alt') {
            return;
        }
        $this->invalidate_scan_cache();
    }

    private function invalidate_scan_cache(): void {
        global $wpdb;
        $like = $wpdb->esc_like('_transient_pylon_img_scan_') . '%';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $like
        ));
        foreach ($rows as $row) {
            $key = preg_replace('/^_transient_/', '', $row->option_name);
            if ($key) {
                delete_transient($key);
            }
        }
    }

    public function add_admin_page(): void {
        add_submenu_page(
            'pylon',
            __('Image SEO', 'pylon-seo'),
            __('Image SEO', 'pylon-seo'),
            'manage_options',
            'pylon-image-seo',
            [$this, 'render_page']
        );
    }

    public function enqueue(string $hook): void {
        $is_image_seo = strpos($hook, 'pylon-image-seo') !== false;

        if (strpos($hook, 'toplevel_page_pylon') !== false) {
            wp_enqueue_style('pylon-image-seo-dashboard', PYLON_URL . 'assets/css/modules/image-seo-dashboard.css', ['pylon-admin'], filemtime(PYLON_PATH . 'assets/css/modules/image-seo-dashboard.css'));
            wp_add_inline_script('pylon-admin-js', $this->dashboard_js());
        }

        if ($is_image_seo) {
            wp_enqueue_style('pylon-image-seo', PYLON_URL . 'assets/css/modules/image-seo.css', ['pylon-admin'], filemtime(PYLON_PATH . 'assets/css/modules/image-seo.css'));
            wp_add_inline_script('pylon-admin-js', $this->js());
        }
    }

    public function render_page(): void {
        ?>
        <div class="wrap" style="max-width:1200px;">
            <?php \Pylon\Core\Modules\Admin\AdminEngine::page_header(__('Image SEO Scanner', 'pylon-seo'), '🖼️'); ?>
            <p class="pylon-color-muted pylon-mb-20"><?php esc_html_e('Audit and optimize images across your media library', 'pylon-seo'); ?></p>

            <div id="pylon-img-loading" style="text-align:center;padding:80px 0;">
                <div class="img-spinner"></div>
                <p style="margin-top:14px;color:#64748b;"><?php esc_html_e('Scanning media library…', 'pylon-seo'); ?></p>
            </div>

            <div id="pylon-img-dashboard" style="display:none;">

                <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px;" id="pylon-img-summary"></div>

                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;">
                    <button class="button img-filter active" data-filter="all"><?php esc_html_e('All', 'pylon-seo'); ?></button>
                    <button class="button img-filter" data-filter="no_alt"><?php esc_html_e('Missing Alt Text', 'pylon-seo'); ?></button>
                    <button class="button img-filter" data-filter="bad_name"><?php esc_html_e('Bad Filenames', 'pylon-seo'); ?></button>
                    <button class="button img-filter" data-filter="large"><?php esc_html_e('Oversized', 'pylon-seo'); ?></button>
                </div>

                <div class="img-table-wrap">
                    <table class="wp-list-table widefat fixed striped" id="pylon-img-table">
                        <thead>
                            <tr>
                                <th style="width:70px;"><?php esc_html_e('Image', 'pylon-seo'); ?></th>
                                <th><?php esc_html_e('Filename', 'pylon-seo'); ?></th>
                                <th style="width:200px;"><?php esc_html_e('Alt Text', 'pylon-seo'); ?></th>
                                <th style="width:80px;"><?php esc_html_e('Size', 'pylon-seo'); ?></th>
                                <th style="width:100px;"><?php esc_html_e('Status', 'pylon-seo'); ?></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
                <div id="pylon-img-pagination" style="display:flex;align-items:center;justify-content:center;gap:6px;padding:14px 0 4px;flex-wrap:wrap;"></div>
            </div>
        </div>
        <?php
    }

    public function ajax_scan(): void {
        check_ajax_referer('pylon_admin_nonce', '_ajax_nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'pylon-seo')]);
        }

        try {
            set_time_limit(120);
        } catch (\Throwable $e) {
            // safe mode — ignore
        }

        try {
            global $wpdb;

            $page = max(1, absint($_POST['page'] ?? 1));
            $per_page = max(25, min(200, absint($_POST['per_page'] ?? 100)));
            $offset = ($page - 1) * $per_page;
            $mime_types = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
            $mime_placeholders = implode( ', ', array_fill( 0, count( $mime_types ), '%s' ) );

            $cache_key = 'pylon_img_scan_' . $page . '_' . $per_page;
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                wp_send_json_success($cached);
            }

            $total_images = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ({$mime_placeholders}) AND post_status = 'inherit'",
                    $mime_types
                )
            );
            $missing_alt = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = '_wp_attachment_image_alt' WHERE p.post_type = 'attachment' AND p.post_mime_type IN ({$mime_placeholders}) AND p.post_status = 'inherit' AND (m.meta_value IS NULL OR m.meta_value = '')",
                    $mime_types
                )
            );

            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ({$mime_placeholders}) AND post_status = 'inherit' ORDER BY ID DESC LIMIT %d OFFSET %d",
                array_merge($mime_types, [$per_page, $offset])
            ));

            if (!empty($ids)) {
                _prime_post_caches($ids, true, true);
            }

            $result = [];
            $stats = ['total' => $total_images, 'no_alt' => $missing_alt, 'bad_name' => 0, 'large' => 0, 'good' => 0];

            foreach ($ids as $id) {
                $alt = get_post_meta($id, '_wp_attachment_image_alt', true);
                $file = get_attached_file($id);
                $url = wp_get_attachment_url($id);
                $filename = ($file ? basename($file) : ($url ? basename($url) : 'unknown'));
                $size = $file && file_exists($file) ? filesize($file) : 0;
                $name_only = strtolower(pathinfo($filename, PATHINFO_FILENAME));

                $no_alt = empty(trim($alt ?? ''));
                $bad_name = (bool) preg_match('/^(IMG|DSC|DSCN|PIC|PHOTO|IMG_|DSC_|WP_)\d|^\d{6,}_|^\d{8,}_/i', $name_only);
                $is_large = $size > 500 * 1024;

                $issues = [];
                if ($no_alt) { $issues[] = 'no_alt'; }
                if ($bad_name) { $issues[] = 'bad_name'; $stats['bad_name']++; }
                if ($is_large) { $issues[] = 'large'; $stats['large']++; }
                if (empty($issues)) $stats['good']++;

                $suggested_alt = '';
                if ($no_alt) {
                    $suggested_alt = $this->suggest_alt_from_filename($name_only);
                }

                $result[] = [
                    'id' => (int) $id,
                    'thumb' => wp_get_attachment_thumb_url($id) ?: ($url ?: ''),
                    'filename' => $filename,
                    'alt' => $alt ?: '',
                    'suggested_alt' => $suggested_alt,
                    'size' => $size,
                    'size_label' => $size > 0 ? ($size >= 1048576 ? round($size / 1048576, 1) . 'MB' : round($size / 1024) . 'KB') : '-',
                    'is_large' => $is_large,
                    'issues' => $issues,
                    'edit_url' => get_edit_post_link($id),
                ];
            }

            $data = [
                'images' => $result,
                'stats' => $stats,
                'placeholder_img' => includes_url('images/media/archive.png'),
                'pagination' => [
                    'page' => $page,
                    'per_page' => $per_page,
                    'total' => $total_images,
                    'total_pages' => $total_images > 0 ? (int) ceil($total_images / $per_page) : 1,
                ],
            ];
            set_transient($cache_key, $data, HOUR_IN_SECONDS);

            wp_send_json_success($data);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function ajax_update_alt(): void {
        check_ajax_referer('pylon_admin_nonce', '_ajax_nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'pylon-seo')]);
        }

        $id = absint($_POST['image_id'] ?? 0);
        $alt = sanitize_text_field(wp_unslash($_POST['alt'] ?? ''));
        if (!$id) {
            wp_send_json_error(['message' => __('No image specified.', 'pylon-seo')]);
        }

        update_post_meta($id, '_wp_attachment_image_alt', $alt);
        $this->invalidate_scan_cache();
        wp_send_json_success(['message' => __('Alt text updated.', 'pylon-seo')]);
    }

    public function render_dashboard_card(): void {
        ?>
        <div class="pylon-card" id="pylon-img-dash-card">
            <div class="pylon-card-header">
                <div style="display:flex;align-items:center;gap:10px;">
                    <span style="font-size:20px;">🖼️</span>
                    <div>
                        <h3 style="margin:0;font-size:16px;"><?php esc_html_e('Image SEO', 'pylon-seo'); ?></h3>
                        <span class="pylon-text-12 pylon-color-muted"><?php esc_html_e('Media library alt text &amp; filename audit', 'pylon-seo'); ?></span>
                    </div>
                </div>
                <a href="<?php echo esc_url(admin_url('admin.php?page=pylon-group-audit&tab=image-seo')); ?>" style="font-size:12px;text-decoration:none;"><?php esc_html_e('View Full Report →', 'pylon-seo'); ?></a>
            </div>
            <div id="pylon-img-dash-loading" style="padding:30px;text-align:center;">
                <span style="color:#94a3b8;font-size:13px;"><?php esc_html_e('Loading…', 'pylon-seo'); ?></span>
            </div>
            <div id="pylon-img-dash-body" style="display:none;padding:4px 16px 16px;">
                <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;" id="pylon-img-dash-stats"></div>
                <div style="margin-top:14px;">
                    <div style="display:flex;align-items:center;gap:10px;" id="pylon-img-dash-bar"></div>
                </div>
            </div>
        </div>
        <?php
    }

    public function ajax_dash_summary(): void {
        check_ajax_referer('pylon_admin_nonce', '_ajax_nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'pylon-seo')]);
        }

        global $wpdb;
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg', 'image/png', 'image/gif', 'image/webp') AND post_status = 'inherit'"
        ));

        $no_alt = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = '_wp_attachment_image_alt' WHERE p.post_type = 'attachment' AND p.post_mime_type IN ('image/jpeg', 'image/png', 'image/gif', 'image/webp') AND p.post_status = 'inherit' AND (m.meta_value IS NULL OR m.meta_value = '')"
        ));

        wp_send_json_success([
            'total' => $total,
            'no_alt' => $no_alt,
            'with_alt' => $total - $no_alt,
            'pct_ok' => $total > 0 ? round(($total - $no_alt) / $total * 100) : 0,
        ]);
    }

    private function suggest_alt_from_filename(string $name): string {
        $name = preg_replace('/[-_]+/', ' ', $name);
        $name = preg_replace('/\s+/', ' ', $name);
        $name = preg_replace('/\b\d{4,}\b/', '', $name);
        $name = trim($name);
        if (empty($name) || preg_match('/^\d+$/', $name)) return '';
        return ucfirst($name);
    }

    private function dashboard_js(): string {
        return '
        jQuery(function($){
            function loadDashSummary(){
                pylonAjax("pylon_image_seo_dash_summary", { _ajax_nonce: pylonAdmin.nonce }, { toast: false })
                .done(function(data){
                    if (!data) return;
                    var stats = [
                        { val: data.total, lab: "' . esc_js(__('Total Images', 'pylon-seo')) . '", sub: "' . esc_js(__('in media library', 'pylon-seo')) . '", color: "#6366f1" },
                        { val: data.no_alt, lab: "' . esc_js(__('Missing Alt Text', 'pylon-seo')) . '", sub: data.total > 0 ? Math.round(data.no_alt/data.total*100)+"%" : "0%", color: data.no_alt > 0 ? "#ef4444" : "#22c55e" },
                        { val: data.with_alt, lab: "' . esc_js(__('With Alt Text', 'pylon-seo')) . '", sub: data.pct_ok+"% " + "' . esc_js(__('of total', 'pylon-seo')) . '", color: "#22c55e" },
                        { val: data.pct_ok, lab: "' . esc_js(__('Alt Text Coverage', 'pylon-seo')) . '", sub: data.pct_ok >= 80 ? "' . esc_js(__('good', 'pylon-seo')) . '" : (data.pct_ok >= 50 ? "' . esc_js(__('needs work', 'pylon-seo')) . '" : "' . esc_js(__('poor', 'pylon-seo')) . '"), color: data.pct_ok >= 80 ? "#22c55e" : (data.pct_ok >= 50 ? "#f59e0b" : "#ef4444") }
                    ];
                    var html = "";
                    $.each(stats, function(i, s){
                        html += \'<div class="dash-stat"><div class="dash-stat-val" style="color:\'+s.color+\'">\'+s.val+\'</div><div class="dash-stat-lab">\'+s.lab+\'</div><div class="dash-stat-sub">\'+s.sub+\'</div></div>\';
                    });
                    $("#pylon-img-dash-stats").html(html);

                    var okPct = data.pct_ok;
                    var color = okPct >= 80 ? "#22c55e" : (okPct >= 50 ? "#f59e0b" : "#ef4444");
                    $("#pylon-img-dash-bar").html(
                        \'<span style="font-size:12px;font-weight:600;color:#334155;flex-shrink:0;width:80px;">' . esc_js(__('With alt text', 'pylon-seo')) . '</span>\'
                        + \'<div class="dash-bar-bg"><div class="dash-bar-fill" style="width:\'+okPct+\'%;background:\'+color+\';"></div></div>\'
                        + \'<span style="font-size:13px;font-weight:700;color:\'+color+\';flex-shrink:0;width:44px;text-align:right;">\'+okPct+\'%</span>\'
                    );

                    $("#pylon-img-dash-loading").fadeOut(200, function(){
                        $("#pylon-img-dash-body").fadeIn(300);
                    });
                }).fail(function(){
                    $("#pylon-img-dash-loading").html(\'<span style="color:#94a3b8;font-size:12px;">' . esc_js(__('Could not load data.', 'pylon-seo')) . '</span>\');
                });
            }
            loadDashSummary();
        });
        ';
    }

    private function js(): string {
        return '
        jQuery(function($){
            var allImages = [], pageSize = 50, currentPage = 1, currentFilter = "all", serverTotalPages = 1;

            function loadImages(page){
                page = page || currentPage || 1;
                pylonAjax("pylon_image_seo_scan", { _ajax_nonce: pylonAdmin.nonce, page: page, per_page: pageSize }, { toast: false })
                .done(function(data){
                    if (!data || !data.images) return;
                    allImages = data.images;
                    currentPage = data.pagination ? parseInt(data.pagination.page, 10) || page : page;
                    serverTotalPages = data.pagination ? parseInt(data.pagination.total_pages, 10) || 1 : 1;
                    renderSummary(data.stats);
                    renderTable();
                    $("#pylon-img-loading").fadeOut(300);
                    $("#pylon-img-dashboard").fadeIn(400);
                }).fail(function(){
                    $("#pylon-img-loading").html(\'<div style="color:#dc2626;font-size:13px;">' . esc_js(__('Scan failed. Try again.', 'pylon-seo')) . '</div>\');
                });
            }

            function renderSummary(stats){
                var cards = [
                    { val: stats.total, lab: "' . esc_js(__('Total Images', 'pylon-seo')) . '", sub: "' . esc_js(__('in media library', 'pylon-seo')) . '", color: "#6366f1" },
                    { val: stats.no_alt, lab: "' . esc_js(__('Missing Alt Text', 'pylon-seo')) . '", sub: stats.total > 0 ? Math.round(stats.no_alt/stats.total*100) + "%" : "0%", color: stats.no_alt > 0 ? "#ef4444" : "#22c55e" },
                    { val: stats.bad_name, lab: "' . esc_js(__('Bad Filenames', 'pylon-seo')) . '", sub: stats.total > 0 ? Math.round(stats.bad_name/stats.total*100) + "%" : "0%", color: stats.bad_name > 0 ? "#f59e0b" : "#22c55e" },
                    { val: stats.large, lab: "' . esc_js(__('Oversized (>500KB)', 'pylon-seo')) . '", sub: stats.total > 0 ? Math.round(stats.large/stats.total*100) + "%" : "0%", color: stats.large > 0 ? "#f59e0b" : "#22c55e" }
                ];
                var html = "";
                $.each(cards, function(i, c){
                    html += \'<div class="img-summary-card"><div class="img-summary-val" style="color:\'+c.color+\'">\'+c.val+\'</div><div class="img-summary-lab">\'+c.lab+\'</div><div class="img-summary-sub">\'+c.sub+\'</div></div>\';
                });
                $("#pylon-img-summary").html(html);
            }

            function renderTable(){
                var filtered = allImages;
                if (currentFilter === "no_alt") filtered = allImages.filter(function(i){ return i.issues.indexOf("no_alt") >= 0; });
                else if (currentFilter === "bad_name") filtered = allImages.filter(function(i){ return i.issues.indexOf("bad_name") >= 0; });
                else if (currentFilter === "large") filtered = allImages.filter(function(i){ return i.issues.indexOf("large") >= 0; });

                var totalPages = serverTotalPages || 1;
                var pageData = filtered;

                var $tbody = $("#pylon-img-table tbody");
                if (!pageData || pageData.length === 0){
                    $tbody.html(\'<tr><td colspan="5" style="text-align:center;padding:30px;color:#94a3b8;">✅ ' . esc_js(__('No images match this filter.', 'pylon-seo')) . '</td></tr>\');
                    renderPagination(totalPages);
                    return;
                }

                var html = "";
                $.each(pageData, function(i, img){
                    var statusDot = img.issues.length === 0 ? "good" : (img.issues.length <= 2 ? "warn" : "bad");
                    var badges = "";
                    if (img.issues.indexOf("no_alt") >= 0) badges += \'<span class="img-badge alt-missing">' . esc_js(__('no alt', 'pylon-seo')) . '</span> \';
                    if (img.issues.indexOf("bad_name") >= 0) badges += \'<span class="img-badge name-bad">' . esc_js(__('filename', 'pylon-seo')) . '</span> \';
                    if (img.issues.indexOf("large") >= 0) badges += \'<span class="img-badge size-large">' . esc_js(__('oversized', 'pylon-seo')) . '</span> \';
                    if (!badges) badges = \'<span style="color:#22c55e;font-weight:500;font-size:11px;">' . esc_js(__('good', 'pylon-seo')) . '</span>\';

                    var altCell = \'<input class="img-alt-input\'+(img.alt ? \'\' : \' img-alt-missing\')+\'" type="text" value="\'+$("<span>").text(img.alt).html()+\'" data-id="\'+encodeURIComponent(img.id)+\'"\'+(img.alt ? \'\' : \' style="border-color:#ef4444;background:#fef2f2;"\')+\' />\';

                    html += \'<tr>\'
                        + \'<td style="width:70px;"><img src="\'+(img.thumb?encodeURI(img.thumb):data.placeholder_img)+\'" class="img-thumb" width="56" height="56" /></td>\'
                        + \'<td style="font-size:12px;color:#334155;word-break:break-all;">\'+$("<span>").text(img.filename).html()+\'</td>\'
                        + \'<td>\'+altCell+\'</td>\'
                        + \'<td style="width:80px;font-size:12px;color:\'+(img.is_large?"#ef4444":"#64748b")+\';">\'+img.size_label+\'</td>\'
                        + \'<td style="width:100px;">\'+badges+\'</td>\'
                        + \'</tr>\';
                });
                $tbody.html(html);
                renderPagination(totalPages);

                $(".img-alt-input").off("input change").on("input", function(){
                    var empty = $(this).val().trim() === "";
                    $(this).toggleClass("img-alt-missing", empty);
                    $(this).css({ "border-color": empty ? "#ef4444" : "#e2e8f0", "background": empty ? "#fef2f2" : "#fff" });
                }).on("change", function(){
                    var $input = $(this);
                    var val = $input.val().trim();
                    var empty = val === "";
                    $input.toggleClass("img-alt-missing", empty);
                    $input.css({ "border-color": empty ? "#ef4444" : "#e2e8f0", "background": empty ? "#fef2f2" : "#fff" });
                    pylonAjax("pylon_image_seo_update_alt", {
                        image_id: $input.data("id"),
                        alt: val,
                        _ajax_nonce: pylonAdmin.nonce
                    }, { toast: true });
                });
            }

            function renderPagination(totalPages){
                var $p = $("#pylon-img-pagination");
                if (totalPages <= 1) { $p.empty(); return; }
                var html = "";
                html += \'<button class="button" data-page="prev" \'+(currentPage<=1?"disabled":"")+\'>\u2039</button>\';
                var s = Math.max(1, currentPage - 2);
                var e = Math.min(totalPages, currentPage + 2);
                if (s > 1) { html += \'<button class="button" data-page="1">1</button>\'; if (s > 2) html += \'<span style="color:#94a3b8;padding:0 4px;">\u2026</span>\'; }
                for (var p = s; p <= e; p++) { html += \'<button class="button \'+(p===currentPage?"button-primary":"")+\'" data-page="\'+p+\'">\'+p+\'</button>\'; }
                if (e < totalPages) { if (e < totalPages-1) html += \'<span style="color:#94a3b8;padding:0 4px;">\u2026</span>\'; html += \'<button class="button" data-page="\'+totalPages+\'">\'+totalPages+\'</button>\'; }
                html += \'<button class="button" data-page="next" \'+(currentPage>=totalPages?"disabled":"")+\'>\u203A</button>\';
                $p.html(html);
            }

            $(document).on("click", "#pylon-img-pagination .button", function(){
                var page = $(this).data("page");
                if (page === "prev") { currentPage--; }
                else if (page === "next") { currentPage++; }
                else { currentPage = parseInt(page); }
                if (currentPage < 1) currentPage = 1;
                if (currentPage > serverTotalPages) currentPage = serverTotalPages;
                $("#pylon-img-dashboard").hide();
                $("#pylon-img-loading").show();
                loadImages(currentPage);
            });

            $(document).on("click", ".img-filter", function(){
                $(".img-filter").removeClass("button-primary").addClass("button");
                $(this).addClass("button-primary").removeClass("button");
                currentFilter = $(this).data("filter");
                currentPage = 1;
                renderTable();
            });

            loadImages();
        });
        ';
    }
}
