<?php
namespace Pylon\Core\Modules\Admin;
defined('ABSPATH') || exit;
class AdminEngine {
    private static array $module_js = [];

    public static function add_module_js(string $js): void {
        self::$module_js[] = $js;
    }

    /** Brand name for menus/headers (Agency white-label can override). */
    public static function brand_name(): string {
        return (string) apply_filters('pylon/brand_name', __('Pylon SEO', 'pylon-seo'));
    }

    public function register(): void {
        add_action('admin_menu', [$this, 'add_menu_pages'], 5);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_enqueue_scripts', [$this, 'output_module_js'], 100);
        add_action('admin_post_pylon_add_redirect', [$this, 'handle_add_redirect']);
        add_action('admin_post_pylon_delete_redirect', [$this, 'handle_delete_redirect']);
        add_action('pylon_daily_maintenance', [$this, 'clear_dashboard_cache']);
        add_action('save_post', [$this, 'clear_dashboard_cache']);
        add_action('wp_ajax_pylon_add_redirect', [$this, 'ajax_add_redirect'], 1);
        add_action('wp_ajax_pylon_delete_redirect', [$this, 'ajax_delete_redirect'], 1);
    }

    public function add_menu_pages(): void {
        $icon = 'data:image/svg+xml;base64,' . base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>'
        );

        add_menu_page(
            self::brand_name(),
            self::brand_name(),
            'manage_options',
            'pylon',
            [$this, 'render_dashboard'],
            $icon,
            30
        );

        add_submenu_page(
            'pylon',
            __('Dashboard', 'pylon-seo'),
            __('Dashboard', 'pylon-seo'),
            'manage_options',
            'pylon',
            [$this, 'render_dashboard']
        );

        add_submenu_page(
            'pylon',
            __('Settings', 'pylon-seo'),
            __('Settings', 'pylon-seo'),
            'manage_options',
            'pylon-settings',
            [$this, 'render_settings']
        );
    }

    public function register_settings(): void {
        $sanitize = [
            'pylon_sitemap_enabled' => 'absint',
            'pylon_sitemap_post_types' => 'sanitize_text_field',
            'pylon_og_enabled' => 'absint',
            'pylon_twitter_enabled' => 'absint',
            'pylon_schema_enabled' => 'absint',
            'pylon_schema_auto_faq' => 'absint',
            'pylon_schema_speakable' => 'absint',
            'pylon_redirects_enabled' => 'absint',
            'pylon_404_monitor_enabled' => 'absint',
            'pylon_freshness_enabled' => 'absint',
            'pylon_freshness_days' => 'absint',
            'pylon_author_eaat_enabled' => 'absint',
            'pylon_indexnow_enabled' => 'absint',
            'pylon_auto_canonical' => 'absint',
            'pylon_auto_redirect_slug' => 'absint',
            'pylon_sitemap_priority' => 'sanitize_text_field',
            'pylon_sitemap_exclude_ids' => 'sanitize_text_field',
            'pylon_sitemap_max_per_page' => 'absint',
            'pylon_breadcrumb_enabled' => 'absint',
            'pylon_breadcrumb_home_text' => 'sanitize_text_field',
            'pylon_breadcrumb_separator' => 'sanitize_text_field',
            'pylon_breadcrumb_show_on_home' => 'absint',
            'pylon_breadcrumb_auto_insert' => 'absint',
            'pylon_breadcrumb_auto_location' => 'sanitize_text_field',
            'pylon_cornerstone_enabled' => 'absint',
            'pylon_aeo_enabled' => 'absint',
            'pylon_redirect_attachments' => 'absint',
        ];

        foreach ($sanitize as $setting => $callback) {
            register_setting('pylon_settings', $setting, ['sanitize_callback' => $callback]);
        }
    }

    public function enqueue_assets(string $hook): void {
        if (strpos($hook, 'pylon') === false) return;
        if (in_array($hook, ['post.php', 'post-new.php'], true)) return;

        wp_enqueue_style('pylon-admin', PYLON_URL . 'assets/css/admin.css', [], filemtime(PYLON_PATH . 'assets/css/admin.css'));
        wp_enqueue_script('pylon-admin-js', PYLON_URL . 'assets/js/admin.js', ['jquery', 'wp-util'], filemtime(PYLON_PATH . 'assets/js/admin.js'), true);
        wp_localize_script('pylon-admin-js', 'pylonAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pylon_admin_nonce'),
            'i18n' => [
                'generating' => __('Generating...', 'pylon-seo'),
                'error' => __('An error occurred.', 'pylon-seo'),
            ],
        ]);
    }

    public function output_module_js(): void {
        if (empty(self::$module_js)) return;
        if (strpos(get_current_screen()->base ?? '', 'pylon') === false) return;
        wp_add_inline_script('pylon-admin-js', implode("\n", self::$module_js));
        self::$module_js = [];
    }

    public function render_dashboard(): void {
        $version = PYLON_VERSION;

        $stats = get_transient('pylon_dashboard_stats');
        if ($stats === false) {
            $post_count = wp_count_posts('post')->publish ?? 0;
            $page_count = wp_count_posts('page')->publish ?? 0;

            global $wpdb;
            $redirect_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pylon_redirects");
            $error_404_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pylon_404_log");

            $stats = [
                'published_pages' => $post_count + $page_count,
                'redirects' => $redirect_count,
                '404_errors' => $error_404_count,
            ];
            set_transient('pylon_dashboard_stats', $stats, 300);
        }

        $stale_count = count(get_option('pylon_stale_posts', []));
        $workflow_count = count(get_option('pylon_workflow_rules', []));

        $usage = \Pylon\Core\Bootstrap::get_usage_stats();
        ?>
        <div class="wrap pylon-dashboard">
            <?php \Pylon\Core\Modules\Admin\AdminEngine::page_header(__('Pylon SEO Dashboard', 'pylon-seo'), '🏠', false, 'v' . $version); ?>

            <div class="pylon-status-grid">
                <div class="pylon-status-card"><span class="pylon-status-icon">📄</span><div class="pylon-status-value"><?php echo esc_html($stats['published_pages']); ?></div><div class="pylon-status-label"><?php esc_html_e('Published Pages', 'pylon-seo'); ?></div></div>
                <div class="pylon-status-card"><span class="pylon-status-icon">🔀</span><div class="pylon-status-value"><?php echo esc_html($stats['redirects']); ?></div><div class="pylon-status-label"><?php esc_html_e('Active Redirects', 'pylon-seo'); ?></div></div>
                <div class="pylon-status-card"><span class="pylon-status-icon">🚫</span><div class="pylon-status-value" style="color:<?php echo $stats['404_errors'] > 0 ? 'var(--pylon-danger)' : 'var(--pylon-success)'; ?>"><?php echo esc_html($stats['404_errors']); ?></div><div class="pylon-status-label"><?php esc_html_e('404 Errors', 'pylon-seo'); ?></div></div>
                <div class="pylon-status-card"><span class="pylon-status-icon">🕰️</span><div class="pylon-status-value" style="color:<?php echo $stale_count > 0 ? 'var(--pylon-warning)' : 'var(--pylon-success)'; ?>"><?php echo esc_html($stale_count); ?></div><div class="pylon-status-label"><?php esc_html_e('Stale Pages', 'pylon-seo'); ?></div></div>
                <div class="pylon-status-card"><span class="pylon-status-icon">⚙️</span><div class="pylon-status-value"><?php echo esc_html($workflow_count); ?></div><div class="pylon-status-label"><?php esc_html_e('Workflows', 'pylon-seo'); ?></div></div>
            </div>

            <div class="pylon-card">
                <div class="pylon-card-header">
                    <h3><?php esc_html_e('Usage Summary', 'pylon-seo'); ?></h3>
                    <span class="pylon-text-12 pylon-color-muted"><?php esc_html_e('All-time totals', 'pylon-seo'); ?></span>
                </div>
                <div class="pylon-usage-grid">
                    <div class="pylon-usage-card"><div class="pylon-usage-value"><?php echo esc_html($usage['agent_scan'] ?? 0); ?></div><div class="pylon-usage-label"><?php esc_html_e('Audits Run', 'pylon-seo'); ?></div></div>
                    <div class="pylon-usage-card"><div class="pylon-usage-value"><?php echo esc_html($usage['ab_promote'] ?? 0); ?></div><div class="pylon-usage-label"><?php esc_html_e('A/B Promotions', 'pylon-seo'); ?></div></div>
                    <div class="pylon-usage-card"><div class="pylon-usage-value"><?php echo esc_html($usage['workflow_rule_created'] ?? 0); ?></div><div class="pylon-usage-label"><?php esc_html_e('Workflow Rules', 'pylon-seo'); ?></div></div>
                </div>
            </div>

            <div class="pylon-card">
                <div class="pylon-card-header">
                    <h3><?php esc_html_e('Quick Actions', 'pylon-seo'); ?></h3>
                </div>
                <div class="pylon-actions">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=pylon-settings')); ?>" class="pylon-btn pylon-btn-primary"><?php esc_html_e('Settings', 'pylon-seo'); ?></a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=pylon-group-links&tab=redirects')); ?>" class="pylon-btn pylon-btn-secondary"><?php esc_html_e('Redirects', 'pylon-seo'); ?></a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=pylon-group-audit&tab=image-seo')); ?>" class="pylon-btn pylon-btn-secondary"><?php esc_html_e('Image SEO', 'pylon-seo'); ?></a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=pylon-group-tools&tab=import')); ?>" class="pylon-btn pylon-btn-secondary"><?php esc_html_e('Import', 'pylon-seo'); ?></a>
                </div>
            </div>

            <?php do_action('pylon_dashboard_pulse'); ?>
        </div>
        <?php
    }

    public function render_settings(): void {
        if (!current_user_can('manage_options')) return;

        $sections = apply_filters('pylon_settings_sections', [
            'sitemap' => [
                'icon' => '🗺️',
                'title' => __('XML Sitemap', 'pylon-seo'),
                'desc' => __('Automatically generate XML sitemaps so search engines can quickly discover and index your content.', 'pylon-seo'),
                'fields' => [
                    'pylon_sitemap_enabled' => ['type' => 'checkbox', 'label' => __('Enable XML Sitemap', 'pylon-seo'), 'desc' => __('Generates a dynamic sitemap at /sitemap.xml', 'pylon-seo')],
                    'pylon_sitemap_post_types' => ['type' => 'text', 'label' => __('Included Post Types', 'pylon-seo'), 'desc' => __('Comma-separated slugs (e.g. post, page, product)', 'pylon-seo'), 'placeholder' => 'post, page, product'],
                    'pylon_sitemap_priority' => ['type' => 'select', 'label' => __('Default Priority', 'pylon-seo'), 'desc' => __('Default priority for sitemap URLs (front page always gets 1.0)', 'pylon-seo'), 'default' => '0.8', 'options' => ['0.1' => '0.1 (Lowest)', '0.3' => '0.3', '0.5' => '0.5', '0.6' => '0.6', '0.7' => '0.7', '0.8' => '0.8 (Default)', '0.9' => '0.9', '1.0' => '1.0 (Highest)']],
                    'pylon_sitemap_exclude_ids' => ['type' => 'text', 'label' => __('Exclude Post IDs', 'pylon-seo'), 'desc' => __('Comma-separated post/page IDs to exclude from the sitemap', 'pylon-seo'), 'placeholder' => '2, 5, 12'],
                    'pylon_sitemap_max_per_page' => ['type' => 'number', 'label' => __('URLs Per Sitemap Page', 'pylon-seo'), 'desc' => __('Max URLs per sitemap file (100–50000)', 'pylon-seo'), 'attrs' => 'min="100" max="50000" step="100"'],
                ],
            ],
            'breadcrumb' => [
                'icon' => '🍞',
                'title' => __('Breadcrumbs', 'pylon-seo'),
                'desc' => __('Show breadcrumb navigation on your site for better UX and SEO. Use the [pylon_breadcrumb] shortcode or pylon_breadcrumb() template tag.', 'pylon-seo'),
                'fields' => [
                    'pylon_breadcrumb_enabled' => ['type' => 'checkbox', 'label' => __('Enable Breadcrumbs', 'pylon-seo'), 'desc' => __('Outputs BreadcrumbList schema markup and enables shortcode/template tag', 'pylon-seo')],
                    'pylon_breadcrumb_auto_insert' => ['type' => 'checkbox', 'label' => __('Auto-Insert Breadcrumbs', 'pylon-seo'), 'desc' => __('Show breadcrumbs on the front-end without a shortcode (uses wp_body_open or post content)', 'pylon-seo'), 'default' => '0'],
                    'pylon_breadcrumb_auto_location' => [
                        'type' => 'select',
                        'label' => __('Auto-Insert Location', 'pylon-seo'),
                        'desc' => __('Where to place auto-inserted breadcrumbs. Auto tries the theme body hook, then falls back to content.', 'pylon-seo'),
                        'default' => 'auto',
                        'options' => [
                            'auto' => __('Auto (recommended)', 'pylon-seo'),
                            'body' => __('After body open', 'pylon-seo'),
                            'content' => __('Above post content', 'pylon-seo'),
                        ],
                    ],
                    'pylon_breadcrumb_home_text' => ['type' => 'text', 'label' => __('Home Text', 'pylon-seo'), 'desc' => __('Text for the home link in breadcrumbs', 'pylon-seo'), 'placeholder' => 'Home'],
                    'pylon_breadcrumb_separator' => ['type' => 'text', 'label' => __('Separator', 'pylon-seo'), 'desc' => __('Character between breadcrumb items (e.g. / → ›)', 'pylon-seo'), 'placeholder' => '→'],
                    'pylon_breadcrumb_show_on_home' => ['type' => 'checkbox', 'label' => __('Show on Homepage', 'pylon-seo'), 'desc' => __('Display breadcrumbs on the front page', 'pylon-seo')],
                ],
            ],
            'social' => [
                'icon' => '📱',
                'title' => __('Social Meta', 'pylon-seo'),
                'desc' => __('Control how your content appears when shared on social platforms — Facebook, Twitter, LinkedIn, and more.', 'pylon-seo'),
                'fields' => [
                    'pylon_og_enabled' => ['type' => 'checkbox', 'label' => __('Open Graph Tags', 'pylon-seo'), 'desc' => __('Rich previews on Facebook, LinkedIn, WhatsApp, and other platforms', 'pylon-seo')],
                    'pylon_twitter_enabled' => ['type' => 'checkbox', 'label' => __('Twitter Cards', 'pylon-seo'), 'desc' => __('Rich previews with image and description on X/Twitter', 'pylon-seo')],
                ],
            ],
            'schema' => [
                'icon' => '🧩',
                'title' => __('Schema Markup', 'pylon-seo'),
                'desc' => __('Add structured data to your pages for enhanced search result appearances like rich snippets and FAQs.', 'pylon-seo'),
                'fields' => [
                    'pylon_schema_enabled' => ['type' => 'checkbox', 'label' => __('Enable Schema Markup', 'pylon-seo'), 'desc' => __('Adds JSON-LD structured data to all pages', 'pylon-seo')],
                    'pylon_schema_auto_faq' => ['type' => 'checkbox', 'label' => __('Auto FAQ Detection', 'pylon-seo'), 'desc' => __('Automatically detects FAQ blocks and generates FAQ schema', 'pylon-seo')],
                    'pylon_schema_speakable' => ['type' => 'checkbox', 'label' => __('Speakable Schema', 'pylon-seo'), 'desc' => __('Marks title and lead paragraphs for voice assistants and AI extraction (SpeakableSpecification)', 'pylon-seo')],
                ],
            ],
            'freshness' => [
                'icon' => '🕰️',
                'title' => __('Content Freshness', 'pylon-seo'),
                'desc' => __('Identify stale content and boost your site\'s relevance by keeping older posts up to date.', 'pylon-seo'),
                'fields' => [
                    'pylon_freshness_enabled' => ['type' => 'checkbox', 'label' => __('Enable Freshness Scoring', 'pylon-seo'), 'desc' => __('Flags posts that haven\'t been updated beyond the threshold', 'pylon-seo')],
                    'pylon_freshness_days' => ['type' => 'number', 'label' => __('Stale Threshold', 'pylon-seo'), 'desc' => __('Days before a post is considered stale (30–730)', 'pylon-seo'), 'attrs' => 'min="30" max="730" step="30"'],
                ],
            ],
            'eaat' => [
                'icon' => '👤',
                'title' => __('Author E-E-A-T', 'pylon-seo'),
                'desc' => __('Enhance your author authority signals with detailed bio, social links, and schema markup for Google E-E-A-T.', 'pylon-seo'),
                'fields' => [
                    'pylon_author_eaat_enabled' => ['type' => 'checkbox', 'label' => __('Enable Author Profiles', 'pylon-seo'), 'desc' => __('Adds enhanced author meta and schema to author archive pages', 'pylon-seo')],
                ],
            ],
            'redirects' => [
                'icon' => '↩️',
                'title' => __('Redirects & 404s', 'pylon-seo'),
                'desc' => __('Manage URL redirects and monitor 404 errors to preserve link equity and improve user experience.', 'pylon-seo'),
                'fields' => [
                    'pylon_redirects_enabled' => ['type' => 'checkbox', 'label' => __('Enable Redirect Manager', 'pylon-seo'), 'desc' => __('Create and manage 301/302 redirects from the Pylon dashboard', 'pylon-seo')],
                    'pylon_404_monitor_enabled' => ['type' => 'checkbox', 'label' => __('404 Error Monitor', 'pylon-seo'), 'desc' => __('Logs 404 errors so you can fix broken links quickly', 'pylon-seo')],
                    'pylon_redirect_attachments' => ['type' => 'checkbox', 'label' => __('Redirect Attachments to Parent', 'pylon-seo'), 'desc' => __('301-redirect single attachment pages to their parent post to consolidate link equity', 'pylon-seo')],
                ],
            ],
            'indexnow' => [
                'icon' => '⚡',
                'title' => __('IndexNow', 'pylon-seo'),
                'desc' => __('Instantly notify search engines when you publish or update content — get indexed in seconds, not days.', 'pylon-seo'),
                'fields' => [
                    'pylon_indexnow_enabled' => ['type' => 'checkbox', 'label' => __('Enable IndexNow', 'pylon-seo'), 'desc' => __('Pings Bing, Yandex, and other IndexNow-compatible search engines on content changes', 'pylon-seo')],
                ],
            ],
            'cornerstone' => [
                'icon' => '💎',
                'title' => __('Cornerstone Content', 'pylon-seo'),
                'desc' => __('Mark your most important articles as cornerstone content. Pylon will flag when other posts target the same keywords, helping you avoid cannibalization.', 'pylon-seo'),
                'fields' => [
                    'pylon_cornerstone_enabled' => ['type' => 'checkbox', 'label' => __('Enable Cornerstone Content', 'pylon-seo'), 'desc' => __('Adds cornerstone toggle to the meta box and warns on keyword conflicts', 'pylon-seo')],
                ],
            ],
            'aeo' => [
                'icon' => '🤖',
                'title' => __('AEO — Answer Engine Optimization', 'pylon-seo'),
                'desc' => __('Optimize your content for AI Overviews and AI Mode. Pylon scores how likely your content is to be cited by Google AI, ChatGPT, and other LLMs.', 'pylon-seo'),
                'fields' => [
                    'pylon_aeo_enabled' => ['type' => 'checkbox', 'label' => __('Enable AEO Analysis', 'pylon-seo'), 'desc' => __('Adds AEO score and checks to the Gutenberg sidebar', 'pylon-seo')],
                ],
            ],
            'advanced' => [
                'icon' => '⚙️',
                'title' => __('Advanced', 'pylon-seo'),
                'desc' => __('Fine-tune advanced behavior for power users including automatic redirects.', 'pylon-seo'),
                'fields' => [
                    'pylon_auto_redirect_slug' => ['type' => 'checkbox', 'label' => __('Auto-Redirect on Slug Change', 'pylon-seo'), 'desc' => __('Automatically creates a 301 redirect when a post slug is changed', 'pylon-seo')],

                ],
            ],
            'canonical' => [
                'icon' => '🔗',
                'title' => __('Canonical URLs', 'pylon-seo'),
                'desc' => __('Prevent duplicate content issues by automatically setting canonical URLs across your site.', 'pylon-seo'),
                'fields' => [
                    'pylon_auto_canonical' => ['type' => 'checkbox', 'label' => __('Auto-Canonical URL', 'pylon-seo'), 'desc' => __('Automatically sets the self-referencing canonical URL on every page', 'pylon-seo')],
                ],
            ],
        ]);

        ?>
        <div class="wrap pylon-settings-page">
            <?php \Pylon\Core\Modules\Admin\AdminEngine::page_header(__('Settings', 'pylon-seo'), '⚙️'); ?>
            <div class="pylon-flex pylon-flex-center pylon-gap-12 pylon-mb-20">
                <p class="pylon-color-muted pylon-m-0"><?php esc_html_e('Configure all Pylon SEO features in one place', 'pylon-seo'); ?></p>
                <span class="pylon-badge pylon-badge-blue">v<?php echo esc_html(PYLON_VERSION); ?></span>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields('pylon_settings'); ?>

                <div class="pylon-settings-grid">
                    <?php foreach ($sections as $key => $section):
                        $enabled_count = 0;
                        $total_count = 0;
                        if (!empty($section['fields'])) {
                            foreach ($section['fields'] as $sk => $fv) {
                                if ($fv['type'] === 'checkbox') {
                                    $total_count++;
                                    if ((string) get_option($sk, $fv['default'] ?? '1') === '1') $enabled_count++;
                                }
                            }
                        }
                    ?>
                        <div class="pylon-settings-section" id="<?php echo esc_attr($key); ?>" data-section="<?php echo esc_attr($key); ?>">
                            <div class="pylon-settings-section-top">
                                <span class="pylon-settings-section-icon"><?php echo esc_html($section['icon']); ?></span>
                                <div class="pylon-settings-section-info">
                                    <h3><?php echo esc_html($section['title']); ?></h3>
                                    <p class="pylon-settings-section-desc"><?php echo esc_html($section['desc'] ?? ''); ?></p>
                                </div>
                                <?php if ($total_count > 0): ?>
                                    <span class="pylon-settings-status"><?php echo esc_html($enabled_count . '/' . $total_count); ?> <span class="pylon-text-11 pylon-color-muted"><?php esc_html_e('active', 'pylon-seo'); ?></span></span>
                                <?php endif; ?>
                            </div>

                            <div class="pylon-settings-section-body">
                                <?php if (!empty($section['render']) && is_callable($section['render'])):
                                    call_user_func($section['render']);
                                elseif (!empty($section['fields'])): foreach ($section['fields'] as $setting => $field): ?>
                                    <div class="pylon-settings-field <?php echo $field['type'] === 'checkbox' ? 'pylon-settings-field-toggle' : ''; ?>">
                                        <?php if ($field['type'] === 'checkbox'): ?>
                                            <div class="pylon-toggle">
                                                <input type="hidden" name="<?php echo esc_attr($setting); ?>" value="0">
                                                <input type="checkbox" name="<?php echo esc_attr($setting); ?>" id="<?php echo esc_attr($setting); ?>" value="1" <?php checked((string) get_option($setting, $field['default'] ?? '1'), '1'); ?>>
                                                <span class="pylon-toggle-track"></span>
                                                <div class="pylon-toggle-text-wrap">
                                                    <label class="pylon-toggle-label-text" for="<?php echo esc_attr($setting); ?>"><?php echo esc_html($field['label']); ?></label>
                                                    <?php if (!empty($field['desc'])): ?>
                                                        <span class="pylon-toggle-desc"><?php echo esc_html($field['desc']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php elseif ($field['type'] === 'number'): ?>
                                            <label class="pylon-settings-field-label" for="<?php echo esc_attr($setting); ?>"><?php echo esc_html($field['label']); ?></label>
                                            <div class="pylon-settings-field-input-wrap">
                                                <input type="number" name="<?php echo esc_attr($setting); ?>" id="<?php echo esc_attr($setting); ?>" value="<?php echo esc_attr(get_option($setting, '')); ?>" class="pylon-input pylon-max-w-400" <?php echo esc_attr($field['attrs'] ?? ''); ?>>
                                                <?php if (!empty($field['desc'])): ?>
                                                    <span class="pylon-help"><?php echo esc_html($field['desc']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php elseif ($field['type'] === 'textarea'): ?>
                                            <label class="pylon-settings-field-label" for="<?php echo esc_attr($setting); ?>"><?php echo esc_html($field['label']); ?></label>
                                            <div class="pylon-settings-field-input-wrap">
                                                <textarea name="<?php echo esc_attr($setting); ?>" id="<?php echo esc_attr($setting); ?>" rows="<?php echo esc_attr($field['rows'] ?? 4); ?>" class="pylon-textarea pylon-max-w-400" placeholder="<?php echo esc_attr($field['placeholder'] ?? ''); ?>"><?php echo esc_textarea(get_option($setting, '')); ?></textarea>
                                                <?php if (!empty($field['desc'])): ?>
                                                    <span class="pylon-help"><?php echo esc_html($field['desc']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php elseif ($field['type'] === 'select'): ?>
                                            <label class="pylon-settings-field-label" for="<?php echo esc_attr($setting); ?>"><?php echo esc_html($field['label']); ?></label>
                                            <div class="pylon-settings-field-input-wrap">
                                                <select name="<?php echo esc_attr($setting); ?>" id="<?php echo esc_attr($setting); ?>" class="pylon-select pylon-max-w-400">
                                                    <?php foreach (($field['options'] ?? []) as $opt_val => $opt_label): ?>
                                                        <option value="<?php echo esc_attr($opt_val); ?>" <?php selected(get_option($setting, $field['default'] ?? ''), $opt_val); ?>><?php echo esc_html($opt_label); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <?php if (!empty($field['desc'])): ?>
                                                    <span class="pylon-help"><?php echo esc_html($field['desc']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <label class="pylon-settings-field-label" for="<?php echo esc_attr($setting); ?>"><?php echo esc_html($field['label']); ?></label>
                                            <div class="pylon-settings-field-input-wrap">
                                                <input type="text" name="<?php echo esc_attr($setting); ?>" id="<?php echo esc_attr($setting); ?>" value="<?php echo esc_attr(get_option($setting, '')); ?>" class="pylon-input pylon-max-w-400" placeholder="<?php echo esc_attr($field['placeholder'] ?? ''); ?>">
                                                <?php if (!empty($field['desc'])): ?>
                                                    <span class="pylon-help"><?php echo esc_html($field['desc']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="pylon-settings-save-bar">
                    <button type="submit" class="pylon-btn pylon-btn-primary pylon-btn-lg">
                        <span>💾</span> <?php esc_html_e('Save Settings', 'pylon-seo'); ?>
                    </button>
                </div>
            </form>
        </div>
        <?php
    }

    public function clear_dashboard_cache(): void {
        if (defined('DOING_CRON') || (function_exists('wp_doing_cron') && wp_doing_cron()) || current_user_can('manage_options')) {
            delete_transient('pylon_dashboard_stats');
        }
    }

    public function handle_add_redirect(): void {
        $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? $_GET['_wpnonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'pylon_redirect_action') || !current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized.', 'pylon-seo'));
        }

        $source = sanitize_text_field(wp_unslash($_POST['source'] ?? $_GET['source'] ?? ''));
        $target = sanitize_text_field(wp_unslash($_POST['target'] ?? $_GET['target'] ?? ''));
        $type = absint($_POST['type'] ?? $_GET['type'] ?? 301);
        $match_type = sanitize_text_field(wp_unslash($_POST['match_type'] ?? 'exact'));

        if ($source && $target) {
            $redirect_engine = new \Pylon\Core\Modules\Redirects\RedirectEngine();
            $redirect_engine->add_redirect($source, $target, $type, $match_type);
        }

        wp_safe_redirect(admin_url('admin.php?page=pylon-group-links&tab=redirects&message=added'));
        exit;
    }

    public function handle_delete_redirect(): void {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'pylon_redirect_action') || !current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized.', 'pylon-seo'));
        }

        $id = absint($_GET['id'] ?? 0);
        if ($id) {
            $redirect_engine = new \Pylon\Core\Modules\Redirects\RedirectEngine();
            $redirect_engine->delete_redirect($id);
        }

        wp_safe_redirect(admin_url('admin.php?page=pylon-group-links&tab=redirects&message=deleted'));
        exit;
    }

    public function ajax_add_redirect(): void {
        check_ajax_referer('pylon_redirect_action', '_ajax_nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'pylon-seo')]);
        }

        $source = sanitize_text_field(wp_unslash($_POST['source'] ?? ''));
        $target = sanitize_text_field(wp_unslash($_POST['target'] ?? ''));
        $type = absint($_POST['type'] ?? 301);
        $match_type = sanitize_text_field(wp_unslash($_POST['match_type'] ?? 'exact'));

        if ($source && $target) {
            $redirect_engine = new \Pylon\Core\Modules\Redirects\RedirectEngine();
            $redirect_engine->add_redirect($source, $target, $type, $match_type);
            wp_send_json_success(['message' => __('Redirect added.', 'pylon-seo')]);
        }

        wp_send_json_error(['message' => __('Source and target are required.', 'pylon-seo')]);
    }

    public function ajax_delete_redirect(): void {
        check_ajax_referer('pylon_redirect_action', '_ajax_nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'pylon-seo')]);
        }

        $id = absint($_POST['id'] ?? 0);
        if ($id) {
            $redirect_engine = new \Pylon\Core\Modules\Redirects\RedirectEngine();
            $redirect_engine->delete_redirect($id);
            wp_send_json_success(['message' => __('Redirect deleted.', 'pylon-seo')]);
        }

        wp_send_json_error(['message' => __('Invalid redirect ID.', 'pylon-seo')]);
    }

    public static function page_header(string $title, string $icon = '', bool $show_breadcrumb = true, string $badge = ''): void {
        // Group router already renders nav + title — skip nested header cards.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page !== '' && strpos($page, 'pylon-group-') === 0) {
            return;
        }
        ?>
        <div class="pylon-card pylon-mb-20" style="opacity:1;transform:translateY(0);transition:0.3s">
            <div class="pylon-card-header">
                <div style="display:flex;align-items:center;gap:8px">
                    <?php if ($icon): ?>
                    <span style="font-size:22px;line-height:1"><?php echo esc_html($icon); ?></span>
                    <?php endif; ?>
                    <h3 style="margin:0;font-size:16px;font-weight:700"><?php echo esc_html($title); ?></h3>
                </div>
                <?php if ($badge && !$show_breadcrumb): ?>
                    <span class="pylon-badge pylon-badge-blue"><?php echo esc_html($badge); ?></span>
                <?php endif; ?>
            </div>
            <?php if ($show_breadcrumb): ?>
            <div class="pylon-card-body" style="padding-top:0;padding-bottom:14px">
                <p class="pylon-group-breadcrumb" style="margin:0">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=pylon')); ?>"><?php esc_html_e('Dashboard', 'pylon-seo'); ?></a>
                    <span class="pylon-group-breadcrumb-sep">/</span>
                    <?php echo esc_html($title); ?>
                </p>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
