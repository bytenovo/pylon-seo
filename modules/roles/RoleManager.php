<?php
namespace Pylon\Core\Modules\Roles;
defined('ABSPATH') || exit;
class RoleManager {
    private const CAP_MAP = 'pylon_role_caps';

    public static function all_caps(): array {
        return [
            'pylon_manage_seo' => __('Access Pylon SEO Dashboard', 'pylon-seo'),
            'pylon_edit_seo_meta' => __('Edit Per-Post SEO Meta', 'pylon-seo'),
            'pylon_manage_redirects' => __('Manage Redirects', 'pylon-seo'),
            'pylon_manage_settings' => __('Manage SEO Settings', 'pylon-seo'),
            'pylon_manage_schema' => __('Manage Schema Markup', 'pylon-seo'),
            'pylon_manage_audit' => __('Run SEO Audits', 'pylon-seo'),
            'pylon_manage_import' => __('Import from Other Plugins', 'pylon-seo'),
        ];
    }

    public static function default_roles(): array {
        return [
            'administrator' => array_keys(self::all_caps()),
            'editor' => ['pylon_edit_seo_meta', 'pylon_manage_audit'],
        ];
    }

    public static function register_caps(): void {
        $saved = get_option(self::CAP_MAP, []);
        $defaults = self::default_roles();

        foreach (wp_roles()->roles as $role_name => $role_info) {
            $role = get_role($role_name);
            if (!$role) continue;

            $caps = $saved[$role_name] ?? ($defaults[$role_name] ?? []);
            foreach (self::all_caps() as $cap => $label) {
                if (in_array($cap, $caps, true)) {
                    if (!$role->has_cap($cap)) {
                        $role->add_cap($cap);
                    }
                } else {
                    if ($role->has_cap($cap)) {
                        $role->remove_cap($cap);
                    }
                }
            }
        }
    }

    public static function remove_all(): void {
        foreach (wp_roles()->roles as $role_name => $role_info) {
            $role = get_role($role_name);
            if (!$role) continue;
            foreach (self::all_caps() as $cap => $label) {
                $role->remove_cap($cap);
            }
        }
        delete_option(self::CAP_MAP);
    }

    public function register(): void {
        add_action('admin_init', [__CLASS__, 'register_caps']);
        add_action('wp_ajax_pylon_save_roles', [$this, 'ajax_save']);
        add_filter('pylon_settings_sections', [$this, 'add_settings_section']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(string $hook): void {
        if (strpos($hook, 'pylon-settings') === false) return;
        \Pylon\Core\Modules\Admin\AdminEngine::add_module_js($this->js());
    }

    private function js(): string {
        $ajax_url = esc_url(admin_url('admin-ajax.php'));
        $save_label = esc_js(__('Save Role Capabilities', 'pylon-seo'));
        return '
        jQuery(function($) {
            $("#pylon-save-roles").on("click", function() {
                var btn = $(this).prop("disabled", true).text("Saving...");
                var $form = $("#pylon-roles-form");
                var data = $form.find(":input").serialize();
                data += "&action=pylon_save_roles&_wpnonce=" + encodeURIComponent($form.find("input[name=\"pylon_roles_nonce\"]").val());
                $.post("' . $ajax_url . '", data, function(res) {
                    if (res.success) {
                        pylonToast("' . esc_js(__('Roles saved.', 'pylon-seo')) . '", "success");
                    } else {
                        pylonToast((res.data && res.data.message) || "' . esc_js(__('Error saving roles.', 'pylon-seo')) . '", "error");
                    }
                }).fail(function() {
                    pylonToast("' . esc_js(__('Server error.', 'pylon-seo')) . '", "error");
                }).always(function() {
                    btn.prop("disabled", false).text("' . $save_label . '");
                });
            });
        });
        ';
    }

    public function add_settings_section(array $sections): array {
        $sections['roles'] = [
            'title' => __('Role Capabilities', 'pylon-seo'),
            'icon' => '🔐',
            'render' => [$this, 'render_section'],
        ];
        return $sections;
    }

    public function render_section(): void {
        $saved = get_option(self::CAP_MAP, []);
        $defaults = self::default_roles();
        $all_caps = self::all_caps();
        ?>
        <div class="pylon-card">
            <div class="pylon-card-header">
                <h3><?php esc_html_e('SEO Capabilities per Role', 'pylon-seo'); ?></h3>
            </div>
            <div class="pylon-card-body">
                <p class="pylon-color-muted pylon-mb-16"><?php esc_html_e('Assign which user roles can access each SEO feature. Changes apply immediately after saving.', 'pylon-seo'); ?></p>
                <div id="pylon-roles-form">
                    <?php wp_nonce_field('pylon_save_roles', 'pylon_roles_nonce'); ?>
                    <input type="hidden" name="pylon_roles_action" value="pylon_save_roles">
                    <div class="pylon-table-wrap">
                        <table class="pylon-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Role', 'pylon-seo'); ?></th>
                                    <?php foreach ($all_caps as $cap => $label): ?>
                                        <th title="<?php echo esc_attr($label); ?>"><?php echo esc_html(shorten_label($label)); ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (wp_roles()->roles as $role_name => $role_info): ?>
                                    <?php if ($role_name === 'subscriber') continue; ?>
                                    <tr>
                                        <td><strong><?php echo esc_html(translate_user_role($role_info['name'])); ?></strong></td>
                                        <?php foreach ($all_caps as $cap => $label):
                                            $checked = in_array($cap, $saved[$role_name] ?? ($defaults[$role_name] ?? []), true);
                                        ?>
                                            <td>
                                                <label class="pylon-toggle">
                                                    <input type="checkbox" name="caps[<?php echo esc_attr($role_name); ?>][]" value="<?php echo esc_attr($cap); ?>" <?php checked($checked); ?>>
                                                    <span class="pylon-toggle-slider"></span>
                                                </label>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="pylon-mt-16">
                        <button type="button" class="pylon-btn pylon-btn-primary" id="pylon-save-roles"><?php esc_html_e('Save Role Capabilities', 'pylon-seo'); ?></button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function ajax_save(): void {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? $_POST['pylon_roles_nonce'] ?? '')), 'pylon_save_roles') || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'pylon-seo')]);
        }

        $raw = isset($_POST['caps']) && is_array($_POST['caps']) ? wp_unslash($_POST['caps']) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value sanitized below.
        $caps = [];
        foreach ($raw as $role_name => $list) {
            $role_name = sanitize_key($role_name);
            if (!get_role($role_name)) continue;
            $caps[$role_name] = array_intersect(array_map('sanitize_key', (array) $list), array_keys(self::all_caps()));
        }

        update_option(self::CAP_MAP, $caps, false);
        self::register_caps();

        wp_send_json_success();
    }
}

function shorten_label(string $label): string {
    $short = preg_replace('/^(Access|Edit|Manage)\s/', '', $label);
    return $short ?: $label;
}
