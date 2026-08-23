<?php
namespace Pylon\Core\Modules\Bulk;
defined('ABSPATH') || exit;
class BulkEditor {
    public function register(): void {
        add_action('admin_init', [$this, 'register_hooks']);
    }

    public function register_hooks(): void {
        $post_types = get_post_types(['public' => true]);
        foreach ($post_types as $pt) {
            add_filter("manage_{$pt}_posts_columns", [$this, 'add_columns']);
            add_action("manage_{$pt}_posts_custom_column", [$this, 'render_column'], 10, 2);
        }
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('wp_ajax_pylon_bulk_save', [$this, 'ajax_bulk_save']);
    }

    public function ajax_bulk_save(): void {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_ajax_nonce'] ?? '')), 'pylon_admin_nonce')) {
            wp_send_json_error(['message' => __('Invalid nonce.', 'pylon-seo')]);
        }
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'pylon-seo')]);
        }

        $post_id = absint($_POST['post_id'] ?? 0);
        if (!$post_id) {
            wp_send_json_error(['message' => __('Missing post ID.', 'pylon-seo')]);
        }

        $fields = isset($_POST['fields']) && is_array($_POST['fields']) ? wp_unslash($_POST['fields']) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value sanitized below.
        if (empty($fields)) {
            wp_send_json_error(['message' => __('Missing fields.', 'pylon-seo')]);
        }

        $allowed = ['pylon_title', 'pylon_description', 'pylon_focus_keyword', 'pylon_canonical'];
        $saved = [];
        foreach ($fields as $field => $value) {
            $key = sanitize_key($field);
            if (!in_array($key, $allowed, true)) continue;
            update_post_meta($post_id, $key, sanitize_text_field($value));
            $saved[] = $key;
        }

        if (empty($saved)) {
            wp_send_json_error(['message' => __('No valid fields to save.', 'pylon-seo')]);
        }

        wp_send_json_success(['saved' => $saved]);
    }

    public function enqueue(string $hook): void {
        if (!in_array($hook, ['edit.php', 'upload.php'], true)) return;
        wp_enqueue_style('pylon-admin', PYLON_URL . 'assets/css/admin.css', [], filemtime(PYLON_PATH . 'assets/css/admin.css'));
        wp_add_inline_script('pylon-admin-js', $this->inline_edit_js());
    }

    public function add_columns(array $columns): array {
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'author') {
                $new['pylon_seo_score'] = __('SEO', 'pylon-seo');
            }
        }
        return $new;
    }

    public function render_column(string $column, int $post_id): void {
        if ($column !== 'pylon_seo_score') return;

        // Use the same calculation as the editor sidebar — identical content
        // resolution and scoring formula (pro multi-engine when installed,
        // local SeoCheckerEngine-based fallback otherwise).
        $post = get_post($post_id);
        if (!class_exists('\Pylon\Core\Modules\Content\ContentScore')) {
            echo '<div class="pylon-score-wrap"><span class="pylon-score-label l-none">' . esc_html__('N/A', 'pylon-seo') . '</span></div>';
            return;
        }
        $data = \Pylon\Core\Modules\Content\ContentScore::get_score_data($post);
        update_post_meta($post_id, '_pylon_engine_score', wp_json_encode($data));
        $score = (int) ($data['overall'] ?? 0);
        $cls = $score >= 70 ? 'good' : ($score >= 40 ? 'ok' : ($score > 0 ? 'bad' : 'none'));
        $title = get_post_meta($post_id, 'pylon_title', true);
        $desc = get_post_meta($post_id, 'pylon_description', true);
        $kw = get_post_meta($post_id, 'pylon_focus_keyword', true);
        ?>
        <?php /* translators: %d: SEO score. */ ?>
        <div class="pylon-score-wrap" title="<?php echo esc_attr(sprintf(__('SEO Score: %d/100', 'pylon-seo'), $score)); ?>">
            <span class="pylon-score-circle s-<?php echo esc_attr($cls); ?>">
                <?php echo $score > 0 ? esc_html($score) : '–'; ?>
            </span>
            <span class="pylon-score-label l-<?php echo esc_attr($cls); ?>">
                <?php echo $cls === 'good' ? esc_html__('Good', 'pylon-seo') : ($cls === 'ok' ? esc_html__('Ok', 'pylon-seo') : ($cls === 'bad' ? esc_html__('Poor', 'pylon-seo') : esc_html__('N/A', 'pylon-seo'))); ?>
            </span>
            <div class="pylon-inline-edit" data-post-id="<?php echo (int) $post_id; ?>">
                <label><?php esc_html_e('SEO Title', 'pylon-seo'); ?></label>
                <input type="text" class="pylon-ie-field" data-field="pylon_title" value="<?php echo esc_attr($title); ?>" placeholder="<?php esc_attr_e('e.g. My Custom SEO Title', 'pylon-seo'); ?>">
                <label><?php esc_html_e('Meta Description', 'pylon-seo'); ?></label>
                <input type="text" class="pylon-ie-field" data-field="pylon_description" value="<?php echo esc_attr($desc); ?>" placeholder="<?php esc_attr_e('e.g. A compelling description…', 'pylon-seo'); ?>">
                <label><?php esc_html_e('Focus Keyword', 'pylon-seo'); ?></label>
                <input type="text" class="pylon-ie-field" data-field="pylon_focus_keyword" value="<?php echo esc_attr($kw); ?>" placeholder="<?php esc_attr_e('e.g. blue widgets', 'pylon-seo'); ?>">
                <div class="pylon-ie-actions">
                    <button type="button" class="pylon-ie-cancel"><?php esc_html_e('Cancel', 'pylon-seo'); ?></button>
                    <button type="button" class="pylon-ie-save"><?php esc_html_e('Save', 'pylon-seo'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }

    private function inline_edit_js(): string {
        return '
        (function(){
            document.addEventListener("click", function(e){
                var target = e.target.closest(".pylon-score-wrap");
                if(!target) return;
                var popup = target.querySelector(".pylon-inline-edit");
                if(!popup) return;
                if(popup.classList.contains("show")) {
                    popup.classList.remove("show");
                    return;
                }
                document.querySelectorAll(".pylon-inline-edit.show").forEach(function(p){ p.classList.remove("show"); });
                popup.classList.add("show");
            });
            document.addEventListener("click", function(e){
                if(!e.target.closest(".pylon-inline-edit") && !e.target.closest(".pylon-score-wrap")) {
                    document.querySelectorAll(".pylon-inline-edit.show").forEach(function(p){ p.classList.remove("show"); });
                }
            });
            document.addEventListener("click", function(e){
                var btn = e.target.closest(".pylon-ie-cancel");
                if(!btn) return;
                var popup = btn.closest(".pylon-inline-edit");
                if(popup) popup.classList.remove("show");
            });
            document.addEventListener("click", function(e){
                var btn = e.target.closest(".pylon-ie-save");
                if(!btn) return;
                var popup = btn.closest(".pylon-inline-edit");
                if(!popup) return;
                var wrap = popup.closest(".pylon-score-wrap");
                if(!wrap) return;
                var fields = {};
                popup.querySelectorAll(".pylon-ie-field").forEach(function(inp){
                    fields[inp.getAttribute("data-field")] = inp.value;
                });
                btn.disabled = true;
                popup.classList.add("pylon-ie-saving");
                var xhr = new XMLHttpRequest();
                xhr.open("POST", pylonAdmin.ajaxUrl, true);
                xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
                xhr.onload = function(){
                    var data = JSON.parse(xhr.responseText);
                    if(data.success) {
                        var circle = wrap.querySelector(".pylon-score-circle");
                        if(circle) { circle.textContent = "\u2705"; circle.style.background = "#16a34a"; }
                        if(data.data && data.data.saved) {
                            var toast = document.createElement("div");
                            toast.className = "pylon-ie-toast success show";
                            toast.textContent = "Saved: " + data.data.saved.join(", ");
                            document.body.appendChild(toast);
                            setTimeout(function(){ toast.classList.remove("show"); setTimeout(function(){ toast.remove(); }, 400); }, 2000);
                        }
                    } else {
                        var toast = document.createElement("div");
                        toast.className = "pylon-ie-toast error show";
                        toast.textContent = data.data && data.data.message ? data.data.message : "Save failed";
                        document.body.appendChild(toast);
                        setTimeout(function(){ toast.classList.remove("show"); setTimeout(function(){ toast.remove(); }, 400); }, 2500);
                    }
                    btn.disabled = false;
                    popup.classList.remove("pylon-ie-saving");
                    popup.classList.remove("show");
                };
                xhr.onerror = function(){
                    btn.disabled = false;
                    popup.classList.remove("pylon-ie-saving");
                };
                var params = "action=pylon_bulk_save&_ajax_nonce=" + encodeURIComponent(pylonAdmin.nonce) + "&post_id=" + parseInt(popup.getAttribute("data-post-id"), 10) + "&" + Object.keys(fields).map(function(k){ return "fields[" + encodeURIComponent(k) + "]=" + encodeURIComponent(fields[k]); }).join("&");
                xhr.send(params);
            });
        })();
        ';
    }

}