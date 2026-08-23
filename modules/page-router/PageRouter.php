<?php
namespace Pylon\Core\Modules\PageRouter;
defined('ABSPATH') || exit;
class PageRouter {
    private static array $groups = [];
    private static array $instance_map = [];

    public function register(): void {
        self::define_groups();
        add_action('admin_init', function () {
            if (!defined('PYLON_ROUTER_ACTIVE')) {
                define('PYLON_ROUTER_ACTIVE', true);
            }
        }, 0);

        add_action('admin_menu', [$this, 'remove_old_submenus'], 20);
        add_action('admin_menu', [$this, 'add_grouped_pages'], 20);
        add_action('admin_enqueue_scripts', [$this, 'enqueue'], 20);
    }

    public static function is_active(): bool {
        return defined('PYLON_ROUTER_ACTIVE') && PYLON_ROUTER_ACTIVE;
    }

    public static function set_instance(string $slug, object $instance): void {
        self::$instance_map[$slug] = $instance;
    }

    public static function get_instance(string $slug): ?object {
        return self::$instance_map[$slug] ?? null;
    }

    private static function define_groups(): void {
        self::$groups = [
            'pylon-group-analytics' => [
                'page_title' => __('Analytics', 'pylon-seo'),
                'menu_title' => __('Analytics', 'pylon-seo'),
                'icon' => '📊',
                'default_tab' => 'overview',
                'tabs' => [
                    'overview' => [
                        'title' => __('Overview', 'pylon-seo'),
                        'icon' => '📊',
                        'module_slug' => 'analytics',
                        'render_method' => 'render_admin_page',
                    ],
                ],
            ],
            'pylon-group-audit' => [
                'page_title' => __('Audit & Images', 'pylon-seo'),
                'menu_title' => __('Audit & Images', 'pylon-seo'),
                'icon' => '📋',
                'default_tab' => 'seo-audit',
                'tabs' => [
                    'seo-audit' => [
                        'title' => __('SEO Audit', 'pylon-seo'),
                        'icon' => '🔍',
                        'module_slug' => 'seo-audit',
                        'render_method' => 'render_admin_page',
                    ],
                    'image-seo' => [
                        'title' => __('Image SEO', 'pylon-seo'),
                        'icon' => '🖼️',
                        'module_slug' => 'image-seo',
                        'render_method' => 'render_page',
                    ],
                    'local-seo' => [
                        'title' => __('Local SEO', 'pylon-seo'),
                        'icon' => '📍',
                        'module_slug' => 'local-seo',
                        'render_method' => 'render_admin_page',
                    ],
                    'indexables' => [
                        'title' => __('Indexables', 'pylon-seo'),
                        'icon' => '📑',
                        'module_slug' => 'indexables',
                        'render_method' => 'render_page',
                    ],
                    'seo-pulse' => [
                        'title' => __('SEO Pulse', 'pylon-seo'),
                        'icon' => '📊',
                        'module_slug' => 'pulse',
                        'render_method' => 'render_pulse_page',
                    ],
                ],
            ],
            'pylon-group-links' => [
                'page_title' => __('Links', 'pylon-seo'),
                'menu_title' => __('Links', 'pylon-seo'),
                'icon' => '🔗',
                'default_tab' => 'redirects',
                'tabs' => [
                    'redirects' => [
                        'title' => __('Redirects', 'pylon-seo'),
                        'icon' => '↩️',
                        'module_slug' => 'redirects',
                        'render_method' => 'render_admin_page',
                    ],
                    'broken-links' => [
                        'title' => __('Broken Links', 'pylon-seo'),
                        'icon' => '🔗',
                        'module_slug' => 'broken-link',
                        'render_method' => 'render_admin_page',
                    ],
                    'keyword-research' => [
                        'title' => __('Keyword Research', 'pylon-seo'),
                        'icon' => '🔑',
                        'module_slug' => 'keyword-research',
                        'render_method' => 'render_admin_page',
                    ],
                ],
            ],
            'pylon-group-tools' => [
                'page_title' => __('Tools', 'pylon-seo'),
                'menu_title' => __('Tools', 'pylon-seo'),
                'icon' => '🛠️',
                'default_tab' => 'import',
                'tabs' => [
                    'import' => [
                        'title' => __('Import', 'pylon-seo'),
                        'icon' => '📥',
                        'module_slug' => 'migrator',
                        'render_method' => 'render_admin_page',
                    ],
                    'html-sitemap' => [
                        'title' => __('HTML Sitemap', 'pylon-seo'),
                        'icon' => '🗺️',
                        'module_slug' => 'html-sitemap',
                        'render_method' => 'render_admin_page',
                    ],
                ],
            ],
            'pylon-group-ai' => [
                'page_title' => __('AI Analysis', 'pylon-seo'),
                'menu_title' => __('AI Analysis', 'pylon-seo'),
                'icon' => '🤖',
                'default_tab' => 'link-assistant',
                'tabs' => [
                    'link-assistant' => [
                        'title' => __('Link Assistant', 'pylon-seo'),
                        'icon' => '🔗',
                        'module_slug' => 'link-assistant',
                        'render_method' => 'render_admin_page',
                    ],
                ],
            ],
            'pylon-group-advanced' => [
                'page_title' => __('Advanced', 'pylon-seo'),
                'menu_title' => __('Advanced', 'pylon-seo'),
                'icon' => '⚙️',
                'default_tab' => 'social',
                'tabs' => [
                    'social' => [
                        'title' => __('Social Links', 'pylon-seo'),
                        'icon' => '📱',
                        'module_slug' => 'social',
                        'render_method' => 'render_page',
                    ],
                ],
            ],
        ];

        self::$groups = apply_filters('pylon/router_groups', self::$groups);
    }

    public static function get_group_tabs(string $group_slug): array {
        return self::$groups[$group_slug]['tabs'] ?? [];
    }

    public static function is_on(string $module_slug, string $group_slug): bool {
        $hook = $GLOBALS['hook_suffix'] ?? '';
        if (strpos($hook, 'pylon-' . $module_slug) !== false) return true;
        if (strpos($hook, $group_slug) !== false) {
            $tab = sanitize_key($_GET['tab'] ?? '');
            $tabs = self::$groups[$group_slug]['tabs'] ?? [];
            foreach ($tabs as $tab_slug => $tab_info) {
                if ($tab_info['module_slug'] === $module_slug) {
                    if ($tab === $tab_slug || ($tab === '' && $tab_slug === self::$groups[$group_slug]['default_tab'])) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    public function remove_old_submenus(): void {
        $remove = [
            'pylon-seo-audit', 'pylon-image-seo', 'pylon-local-seo', 'pylon-indexables',
            'pylon-redirects', 'pylon-broken-links', 'pylon-link-assistant',
            'pylon-migrate', 'pylon-agent', 'pylon-ai-overview',
            'pylon-workflows', 'pylon-rules', 'pylon-ab-tests', 'pylon-audit',
            'pylon-clusters', 'pylon-social', 'pylon-ai-citations',
            'pylon-schema-testing', 'pylon-schema-migration', 'pylon-entity-manager',
            'pylon-gsc-ai', 'pylon-bing-webmaster',
            'pylon-preferred-sources', 'pylon-traffic-forecast',
            'pylon-html-sitemap', 'pylon-keyword-research',
        ];
        foreach ($remove as $slug) {
            remove_submenu_page('pylon', $slug);
        }
    }

    public function add_grouped_pages(): void {
        foreach (self::$groups as $slug => $group) {
            add_submenu_page(
                'pylon',
                $group['page_title'],
                $group['menu_title'],
                'manage_options',
                $slug,
                function () use ($slug, $group) {
                    $this->render_group_page($slug, $group);
                }
            );
        }
    }

    public function enqueue(string $hook): void {
        foreach (self::$groups as $slug => $group) {
            if (strpos($hook, $slug) === false) continue;

            wp_enqueue_style('pylon-group', PYLON_URL . 'assets/css/modules/group.css', ['pylon-admin'], filemtime(PYLON_PATH . 'assets/css/modules/group.css'));

            $tab = sanitize_key($_GET['tab'] ?? $group['default_tab']);
            $tabs = $group['tabs'];

            if (isset($tabs[$tab]) && ($inst = self::get_instance($tabs[$tab]['module_slug']))) {
                if (method_exists($inst, 'enqueue')) {
                    $module_slug = $tabs[$tab]['module_slug'];
                    $module_hook = 'pylon_page_pylon-' . $module_slug;
                    $inst->enqueue($module_hook);
                }
            }
        }
    }

    private function render_group_page(string $slug, array $group): void {
        $tab = sanitize_key($_GET['tab'] ?? $group['default_tab']);
        $tabs = $group['tabs'];

        if (!isset($tabs[$tab])) {
            $tab = $group['default_tab'];
        }

        $current = $tabs[$tab];
        $inst = self::get_instance($current['module_slug']);
        ?>
        <div class="wrap pylon-group-page" style="padding-right:20px">
            <div class="pylon-group-nav-card">
                <div class="pylon-group-nav-head">
                    <span class="pylon-group-icon"><?php echo esc_html($group['icon']); ?></span>
                    <h1 class="pylon-group-title"><?php echo esc_html($group['page_title']); ?></h1>
                </div>
                <div class="pylon-group-nav-top">
                    <p class="pylon-group-breadcrumb">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=pylon')); ?>"><?php esc_html_e('Dashboard', 'pylon-seo'); ?></a>
                        <span class="pylon-group-breadcrumb-sep">/</span>
                        <?php echo esc_html($group['page_title']); ?>
                    </p>
                    <span class="pylon-badge pylon-badge-blue"><?php echo esc_html($current['title']); ?></span>
                </div>

                <div class="pylon-group-tabs">
                    <?php foreach ($tabs as $tab_slug => $tab_info):
                        $active = $tab_slug === $tab ? 'active' : '';
                        $url = admin_url('admin.php?page=' . $slug . '&tab=' . $tab_slug);
                    ?>
                        <a href="<?php echo esc_url($url); ?>" class="pylon-group-tab <?php echo esc_attr($active); ?>">
                            <span class="pylon-group-tab-icon"><?php echo esc_html($tab_info['icon'] ?? ''); ?></span>
                            <span class="pylon-group-tab-label"><?php echo esc_html($tab_info['title']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="pylon-group-content">
                <?php if ($inst && method_exists($inst, $current['render_method'])): ?>
                    <?php $inst->{$current['render_method']}(); ?>
                <?php else: ?>
                    <div class="pylon-group-error">
                        <span class="pylon-group-error-icon">⚠️</span>
                        <h3><?php esc_html_e('Module Not Available', 'pylon-seo'); ?></h3>
                        <p><?php esc_html_e('Could not load this section. The module may be missing or disabled.', 'pylon-seo'); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

}