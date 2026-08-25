<?php
namespace Pylon\Core\Modules\Indexables;

class IndexablesEngine {
    private static string $table = 'pylon_indexables';

    public function register(): void {
        add_action('pylon_daily_maintenance', [$this, 'rebuild_index']);
        add_action('save_post', [$this, 'index_post'], 20, 2);
        add_action('wp_ajax_pylon_rebuild_index', [$this, 'ajax_rebuild']);
        add_action('wp_ajax_pylon_index_stats', [$this, 'ajax_stats']);
        add_action('pylon_dashboard_pulse', [$this, 'render_index_summary']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . self::$table;
    }

    public function install_table(): void {
        global $wpdb;
        $table = self::table();
        $charset = $wpdb->get_charset_collate();

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) return;

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            post_id BIGINT UNSIGNED NOT NULL UNIQUE,
            object_type VARCHAR(20) NOT NULL DEFAULT 'post',
            object_subtype VARCHAR(20) NOT NULL DEFAULT '',
            title TEXT,
            description TEXT,
            canonical TEXT,
            focus_keyword VARCHAR(255) DEFAULT '',
            schema_type VARCHAR(50) DEFAULT '',
            seo_score INT UNSIGNED DEFAULT 0,
            citeability_score INT UNSIGNED DEFAULT 0,
            aeo_score INT UNSIGNED DEFAULT 0,
            freshness_score INT UNSIGNED DEFAULT 0,
            word_count INT UNSIGNED DEFAULT 0,
            links_count INT UNSIGNED DEFAULT 0,
            heading_count INT UNSIGNED DEFAULT 0,
            image_count INT UNSIGNED DEFAULT 0,
            last_modified DATETIME DEFAULT NULL,
            indexed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_post (post_id),
            INDEX idx_object_type (object_type),
            INDEX idx_seo_score (seo_score),
            INDEX idx_citeability (citeability_score),
            INDEX idx_aeo (aeo_score)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public function enqueue(string $hook): void {
        if (strpos($hook, 'pylon-indexables') === false && strpos($hook, 'pylon-group-audit') === false) return;
        wp_add_inline_script('pylon-admin-js', $this->inline_js());
        wp_enqueue_style('pylon-indexables', PYLON_URL . 'assets/css/modules/indexables.css', ['pylon-admin'], filemtime(PYLON_PATH . 'assets/css/modules/indexables.css'));
    }

    public function enqueue_assets(string $hook): void {
        $this->enqueue($hook);
    }

    private function inline_js(): string {
        return '
            document.getElementById("pylon-rebuild-index")?.addEventListener("click", function() {
                var btn = this, status = document.getElementById("pylon-index-status");
                btn.disabled = true;
                status.textContent = "' . esc_js(__('Rebuilding...', 'pylon-seo')) . '";
                wp.ajax.post("pylon_rebuild_index", { _wpnonce: pylonAdmin.nonce })
                    .done(function(r) { status.textContent = r.message; location.reload(); })
                    .fail(function(m) { status.textContent = m.message || m; btn.disabled = false; });
            });
        ';
    }

    public function render_index_summary(): void {
        global $wpdb;
        $table = self::table();
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        $avg_seo = (int) $wpdb->get_var("SELECT AVG(seo_score) FROM $table");
        $avg_aeo = (int) $wpdb->get_var("SELECT AVG(aeo_score) FROM $table");
        $avg_cite = (int) $wpdb->get_var("SELECT AVG(citeability_score) FROM $table");
        ?>
        <div class="pylon-card">
            <div class="pylon-card-header">
                <h3><?php esc_html_e('Indexables Summary', 'pylon-seo'); ?></h3>
                <a href="<?php echo esc_url(admin_url('admin.php?page=pylon-group-audit&tab=indexables')); ?>" class="pylon-btn pylon-btn-sm pylon-btn-secondary"><?php esc_html_e('View All', 'pylon-seo'); ?></a>
            </div>
            <div class="pylon-status-grid">
                <div class="pylon-status-card"><span class="pylon-status-icon">📊</span><div class="pylon-status-value"><?php echo (int) $total; ?></div><div class="pylon-status-label"><?php esc_html_e('Indexed Posts', 'pylon-seo'); ?></div></div>
                <div class="pylon-status-card"><span class="pylon-status-icon">⭐</span><div class="pylon-status-value"><?php echo (int) $avg_seo; ?><span style="font-size:14px;color:#9ca3af">/100</span></div><div class="pylon-status-label"><?php esc_html_e('Avg SEO Score', 'pylon-seo'); ?></div></div>
                <div class="pylon-status-card"><span class="pylon-status-icon">🤖</span><div class="pylon-status-value"><?php echo (int) $avg_aeo; ?><span style="font-size:14px;color:#9ca3af">/100</span></div><div class="pylon-status-label"><?php esc_html_e('Avg AEO Score', 'pylon-seo'); ?></div></div>
                <div class="pylon-status-card"><span class="pylon-status-icon">📝</span><div class="pylon-status-value"><?php echo (int) $avg_cite; ?><span style="font-size:14px;color:#9ca3af">/100</span></div><div class="pylon-status-label"><?php esc_html_e('Avg Citeability', 'pylon-seo'); ?></div></div>
            </div>
        </div>
        <?php
    }

    public function render_page(): void {
        if (!current_user_can('manage_options')) return;
        \Pylon\Core\Modules\Admin\AdminEngine::page_header(__('SEO Indexables', 'pylon-seo'), '📊');

        echo '<div style="padding-right:20px">';
        global $wpdb;
        $table = self::table();
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        $avg_seo = (int) $wpdb->get_var("SELECT AVG(seo_score) FROM $table");
        $avg_aeo = (int) $wpdb->get_var("SELECT AVG(aeo_score) FROM $table");
        $avg_cite = (int) $wpdb->get_var("SELECT AVG(citeability_score) FROM $table");
        $last_indexed = $wpdb->get_var("SELECT MAX(indexed_at) FROM $table");
        $total_words = (int) $wpdb->get_var("SELECT SUM(word_count) FROM $table");
        ?>
        <div class="pylon-status-grid">
            <div class="pylon-status-card"><span class="pylon-status-icon">📊</span><div class="pylon-status-value"><?php echo (int) $total; ?></div><div class="pylon-status-label"><?php esc_html_e('Indexed Posts', 'pylon-seo'); ?></div></div>
            <div class="pylon-status-card"><span class="pylon-status-icon">⭐</span><div class="pylon-status-value"><?php echo (int) $avg_seo; ?><span class="pylon-text-14 pylon-color-muted">/100</span></div><div class="pylon-status-label"><?php esc_html_e('Avg SEO Score', 'pylon-seo'); ?></div></div>
            <div class="pylon-status-card"><span class="pylon-status-icon">🤖</span><div class="pylon-status-value"><?php echo (int) $avg_aeo; ?><span class="pylon-text-14 pylon-color-muted">/100</span></div><div class="pylon-status-label"><?php esc_html_e('Avg AEO Score', 'pylon-seo'); ?></div></div>
            <div class="pylon-status-card"><span class="pylon-status-icon">📝</span><div class="pylon-status-value"><?php echo (int) $avg_cite; ?><span class="pylon-text-14 pylon-color-muted">/100</span></div><div class="pylon-status-label"><?php esc_html_e('Avg Citeability', 'pylon-seo'); ?></div></div>
            <div class="pylon-status-card"><span class="pylon-status-icon">🔤</span><div class="pylon-status-value"><?php echo number_format($total_words); ?><?php if ($last_indexed): ?><div class="pylon-status-label" style="margin-top:2px;text-transform:none"><?php echo esc_html(sprintf(
                /* translators: %s: Human-readable time elapsed since the index was last rebuilt. */
                __('Last indexed: %s', 'pylon-seo'),
                human_time_diff(strtotime($last_indexed), current_time('timestamp')) . ' ' . __('ago', 'pylon-seo')
            )); ?></div><?php endif; ?></div><div class="pylon-status-label"><?php esc_html_e('Total Words', 'pylon-seo'); ?></div></div>
        </div>

        <?php
        $search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $posts_per_page = 30;
        $paged = max(1, absint($_GET['paged'] ?? 1));
        $offset = ($paged - 1) * $posts_per_page;

        if ($search) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $total = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE title LIKE %s OR CAST(post_id AS CHAR) LIKE %s",
                $like, $like
            ));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE title LIKE %s OR CAST(post_id AS CHAR) LIKE %s ORDER BY seo_score ASC LIMIT %d OFFSET %d",
                $like, $like, $posts_per_page, $offset
            ));
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table ORDER BY seo_score ASC LIMIT %d OFFSET %d",
                $posts_per_page, $offset
            ));
        }
        $total_pages = $total ? ceil($total / $posts_per_page) : 1;
        ?>

        <div style="display:flex;align-items:center;gap:16px;margin-top:16px">
            <div style="flex-shrink:0">
                <button class="button button-primary" id="pylon-rebuild-index"><?php esc_html_e('Rebuild Index', 'pylon-seo'); ?></button>
                <span id="pylon-index-status" style="margin-left:12px;font-size:13px;color:#6b7280"></span>
            </div>
            <div style="flex-grow:1;text-align:right">
                <form method="get" style="display:inline-flex;gap:4px">
                    <input type="hidden" name="page" value="pylon-group-audit">
                    <input type="hidden" name="tab" value="indexables">
                    <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search title or ID...', 'pylon-seo'); ?>" style="width:220px">
                    <button class="button"><?php esc_html_e('Search', 'pylon-seo'); ?></button>
                    <?php if ($search): ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=pylon-group-audit&tab=indexables')); ?>" class="button"><?php esc_html_e('Clear', 'pylon-seo'); ?></a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <p style="font-size:13px;color:#6b7280;margin:8px 0 0">
            <?php echo esc_html(sprintf(
                /* translators: 1: Number of indexed posts shown, 2: Total number of indexed posts, 3: Number of posts shown per page. */
                __('Showing %1$d of %2$d indexed posts (%3$d per page)', 'pylon-seo'),
                min($offset + $posts_per_page, $total),
                $total,
                $posts_per_page
            )); ?>
        </p>

        <table class="wp-list-table widefat fixed striped" style="margin-top:8px">
            <thead>
                <tr>
                    <th><?php esc_html_e('Post', 'pylon-seo'); ?></th>
                    <th><?php esc_html_e('Type', 'pylon-seo'); ?></th>
                    <th><?php esc_html_e('Words', 'pylon-seo'); ?></th>
                    <th><?php esc_html_e('SEO', 'pylon-seo'); ?></th>
                    <th><?php esc_html_e('AEO', 'pylon-seo'); ?></th>
                    <th><?php esc_html_e('Citeability', 'pylon-seo'); ?></th>
                    <th><?php esc_html_e('Freshness', 'pylon-seo'); ?></th>
                    <th><?php esc_html_e('Modified', 'pylon-seo'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): $post = get_post($row->post_id); ?>
                <tr>
                    <td><?php echo $post ? '<a href="' . esc_url(get_edit_post_link($row->post_id)) . '">' . esc_html($post->post_title ?: '(' . __('no title', 'pylon-seo') . ')') . '</a>' : '<em>' . esc_html__('Deleted', 'pylon-seo') . '</em>'; ?></td>
                    <td><?php echo esc_html($row->object_subtype ?: $row->object_type); ?></td>
                    <td><?php echo (int) $row->word_count; ?></td>
                    <td style="font-weight:600;color:<?php echo $row->seo_score >= 80 ? '#22c55e' : ($row->seo_score >= 50 ? '#f59e0b' : '#ef4444'); ?>"><?php echo (int) $row->seo_score; ?></td>
                    <td style="font-weight:600;color:<?php echo $row->aeo_score >= 80 ? '#22c55e' : ($row->aeo_score >= 50 ? '#f59e0b' : '#ef4444'); ?>"><?php echo (int) $row->aeo_score; ?></td>
                    <td style="font-weight:600;color:<?php echo $row->citeability_score >= 80 ? '#22c55e' : ($row->citeability_score >= 50 ? '#f59e0b' : '#ef4444'); ?>"><?php echo (int) $row->citeability_score; ?></td>
                    <td><?php echo (int) $row->freshness_score; ?></td>
                    <td style="font-size:11px;color:#6b7280"><?php echo $row->last_modified ? esc_html(human_time_diff(strtotime($row->last_modified), current_time('timestamp')) . ' ' . __('ago', 'pylon-seo')) : '—'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1): ?>
        <div class="pylon-pagination">
            <?php
            echo wp_kses_post(paginate_links([
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'prev_text' => '‹',
                'next_text' => '›',
                'total' => $total_pages,
                'current' => $paged,
                'mid_size' => 2,
                'end_size' => 1,
            ]));
            ?>
        </div>
        <?php endif;
        echo '</div>';
    }

    public function index_post(int $post_id, \WP_Post $post): void {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        $post = get_post($post_id);
        if (!$post || !in_array($post->post_status, ['publish', 'private'])) return;

        global $wpdb;
        $table = self::table();
        $this->install_table();

        $content = $post->post_content;
        $text = wp_strip_all_tags($content);
        $word_count = str_word_count($text);
        $title = get_post_meta($post_id, 'pylon_title', true) ?: $post->post_title;
        $description = get_post_meta($post_id, 'pylon_description', true);
        $canonical = get_post_meta($post_id, 'pylon_canonical', true);
        $focus_kw = get_post_meta($post_id, 'pylon_focus_keyword', true);
        $schema = get_post_meta($post_id, 'pylon_schema_type', true);
        $freshness = (int) get_post_meta($post_id, 'pylon_freshness_score', true);
        $heading_count = preg_match_all('/<h[1-6][^>]*>/i', $content);
        $image_count = preg_match_all('/<img[^>]+>/i', $content);
        $links_count = preg_match_all('/<a[^>]+href=["\']https?:\/\//i', $content);

        // Compute scores
        $seo_score = 0;
        if (class_exists('\Pylon\Core\Modules\Content\ContentScore')) {
            $engine_data = \Pylon\Core\Modules\Content\ContentScore::get_score_data($post);
            $seo_score = $engine_data['overall'] ?? 0;
        }

        $aeo_score = 0;
        if (class_exists('\Pylon\Core\Modules\Aeo\AEOEngine')) {
            $aeo = new \Pylon\Core\Modules\Aeo\AEOEngine();
            $aeo_analysis = $aeo->analyze($post_id);
            $aeo_score = $aeo_analysis['score'] ?? 0;
        }

        $citeability_score = 0;
        if (class_exists('\Pylon\Core\Modules\Citeability\CiteabilityEngine')) {
            $cite = new \Pylon\Core\Modules\Citeability\CiteabilityEngine();
            $cite_data = $cite->get_score($post_id);
            $citeability_score = $cite_data['score'] ?? 0;
        }

        $data = [
            'post_id' => $post_id,
            'object_type' => 'post',
            'object_subtype' => $post->post_type,
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'focus_keyword' => $focus_kw,
            'schema_type' => $schema,
            'seo_score' => $seo_score,
            'citeability_score' => $citeability_score,
            'aeo_score' => $aeo_score,
            'freshness_score' => $freshness,
            'word_count' => $word_count,
            'links_count' => $links_count,
            'heading_count' => $heading_count,
            'image_count' => $image_count,
            'last_modified' => $post->post_modified,
        ];

        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE post_id = %d", $post_id));
        if ($exists) {
            $wpdb->update($table, $data, ['post_id' => $post_id]);
        } else {
            $wpdb->insert($table, $data);
        }
    }

    public function rebuild_index(): array {
        $this->install_table();

        $post_types = get_post_types(['public' => true]);
        $count = 0;
        $page = 1;
        $batch = 100;

        while (true) {
            $posts = get_posts([
                'post_type' => $post_types,
                'post_status' => 'publish',
                'posts_per_page' => $batch,
                'paged' => $page,
                'no_found_rows' => true,
                'fields' => 'ids',
            ]);
            if (empty($posts)) break;

            update_meta_cache('post', $posts);

            foreach ($posts as $post_id) {
                $post = get_post($post_id);
                if ($post) {
                    $this->index_post($post_id, $post);
                    $count++;
                }
            }
            $page++;
        }

        return ['count' => $count];
    }

    public function ajax_rebuild(): void {
        check_ajax_referer('pylon_admin_nonce', '_wpnonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => __('Unauthorized.', 'pylon-seo')]);
    
        $result = $this->rebuild_index();
        wp_send_json_success(['message' => sprintf(
            /* translators: %d: Number of posts that were re-indexed. */
            __('Index rebuilt: %d posts processed.', 'pylon-seo'),
            $result['count']
        )]);
    }

    public function ajax_stats(): void {
        check_ajax_referer('pylon_admin_nonce', '_wpnonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => __('Unauthorized.', 'pylon-seo')]);
    
        global $wpdb;
        $table = self::table();
        wp_send_json_success([
            'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table"),
            'avg_seo' => (int) $wpdb->get_var("SELECT AVG(seo_score) FROM $table"),
            'avg_aeo' => (int) $wpdb->get_var("SELECT AVG(aeo_score) FROM $table"),
        ]);
    }

    public static function get_score(int $post_id): array {
        global $wpdb;
        $table = self::table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT seo_score, aeo_score, citeability_score, freshness_score, word_count FROM {$table} WHERE post_id = %d",
            $post_id
        ));
        return $row ? (array) $row : [];
    }
}
