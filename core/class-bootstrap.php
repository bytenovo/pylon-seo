<?php
namespace Pylon\Core;
defined('ABSPATH') || exit;
final class Bootstrap {

    private static array $modules = [];
    private static array $module_instances = [];
    private static bool $initialized = false;

    public static function init(): void {
        if (self::$initialized) return;
        self::$initialized = true;

        spl_autoload_register([__CLASS__, 'autoload']);

        add_action('plugins_loaded', [__CLASS__, 'load_modules'], 5);
        add_action('init', [__CLASS__, 'register_meta']);
        add_action('admin_init', [__CLASS__, 'maybe_upgrade_db']);
        add_filter('cron_schedules', [__CLASS__, 'add_monthly_schedule']);

        register_activation_hook(PYLON_FILE, [__CLASS__, 'activate']);
        register_deactivation_hook(PYLON_FILE, [__CLASS__, 'deactivate']);
    }

    public static function maybe_upgrade_db(): void {
        $db_version = get_option('pylon_db_version', '0');
        if (version_compare($db_version, PYLON_VERSION, '<')) {
            Activator::upgrade_tables();
            update_option('pylon_db_version', PYLON_VERSION);
        }
    }

    public static function add_monthly_schedule(array $schedules): array {
        $schedules['monthly'] = [
            'interval' => 30 * DAY_IN_SECONDS,
            'display' => __('Once Monthly', 'pylon-seo'),
        ];
        $schedules['weekly'] = [
            'interval' => 7 * DAY_IN_SECONDS,
            'display' => __('Once Weekly', 'pylon-seo'),
        ];
        return $schedules;
    }

    public static function register_module(string $id, string $class, array $deps = []): void {
        self::$modules[$id] = compact('class', 'deps');
    }

    public static function load_modules(): void {
        $frontend = [
            'meta'       => 'Meta\MetaEngine',
            'sitemap'    => 'Sitemap\SitemapEngine',
            'schema'     => 'Schema\SchemaEngine',
            'redirects'  => 'Redirects\RedirectEngine',
            'freshness'  => 'Freshness\FreshnessEngine',
            'author-eaat'=> 'AuthorEaat\AuthorEaatEngine',
            'content'    => 'Content\ContentAnalyzer',
            'indexnow'   => 'IndexNow\IndexNowEngine',
            'bulk'       => 'Bulk\BulkEditor',
            'news-sitemap' => 'NewsSitemap\NewsSitemap',
            'gutenberg'  => 'Gutenberg\GutenbergSidebar',
            'taxonomy-seo' => 'TaxonomySeo\TaxonomySEO',
            'aeo'        => 'Aeo\AEOEngine',
            'video-sitemap' => 'VideoSitemap\VideoSitemap',
            'citeability' => 'Citeability\CiteabilityEngine',
            'indexables' => 'Indexables\IndexablesEngine',
            'llms-txt'  => 'LlmsTxt\LlmsTxtEngine',
            'aeo-content'  => 'AeoContent\AeoContent',
            'local-seo'  => 'LocalSeo\LocalSEO',
            'robots-txt' => 'RobotsTxt\RobotsTxtEngine',
            'verification' => 'Verification\VerificationEngine',
            'rss'        => 'RssOptimizer\RssOptimizer',
            'html-sitemap' => 'HtmlSitemap\HtmlSitemap',
            'multilingual' => 'Multilingual\MultilingualEngine',
        ];

        $admin_only = [
            'migrator'   => 'Migrator\MigratorEngine',
            'admin'      => 'Admin\AdminEngine',
            'social'     => 'Social\SocialLinks',
            'onboarding' => 'Onboarding\OnboardingWizard',
            'broken-link' => 'BrokenLink\BrokenLinkChecker',
            'woocommerce' => 'WooCommerce\WooCommerceSEO',
            'link-assistant' => 'LinkAssistant\LinkAssistant',
            'seo-audit' => 'SeoAudit\SeoAuditor',
            'pulse'     => 'Pulse\PulseDashboard',
            'image-seo' => 'ImageSeo\ImageSEO',
            'system-status' => 'SystemStatus\SystemStatus',
            'page-router' => 'PageRouter\PageRouter',
            'roles'      => 'Roles\RoleManager',
            'analytics'  => 'Analytics\AnalyticsOverview',
            'conflict-detector' => 'ConflictDetector\ConflictDetector',
            'keyword-research' => 'KeywordResearch\KeywordResearch',
        ];

        $modules = apply_filters('pylon/modules', is_admin() ? ($frontend + $admin_only) : $frontend);

        foreach ($modules as $id => $class) {
            $full_class = __NAMESPACE__ . '\\Modules\\' . $class;
            if (class_exists($full_class)) {
                $instance = new $full_class();
                $instance->register();
                self::$module_instances[$id] = $instance;
                $router_class = __NAMESPACE__ . '\\Modules\\PageRouter\\PageRouter';
                if (class_exists($router_class)) {
                    $router_class::set_instance($id, $instance);
                }
            }
        }

        // Run pending module installs after activation.
        if (get_option('pylon_install_pending', false)) {
            foreach (self::$module_instances as $instance) {
                if (method_exists($instance, 'install')) {
                    $instance->install();
                }
            }
            delete_option('pylon_install_pending');
        }
    }

    public static function register_meta(): void {
        $meta_keys = [
            'pylon_title' => 'post',
            'pylon_description' => 'post',
            'pylon_og_title' => 'post',
            'pylon_og_description' => 'post',
            'pylon_og_image' => 'post',
            'pylon_twitter_title' => 'post',
            'pylon_twitter_description' => 'post',
            'pylon_twitter_image' => 'post',
            'pylon_canonical' => 'post',
            'pylon_noindex' => 'post',
            'pylon_nofollow' => 'post',
            'pylon_schema_type' => 'post',
            'pylon_focus_keyword' => 'post',
            'pylon_primary_category' => 'post',
            'pylon_freshness_score' => 'post',
            'pylon_last_updated' => 'post',
            'pylon_ab_enabled' => 'post',
            'pylon_ab_title_b' => 'post',
            'pylon_ab_desc_b' => 'post',
            'pylon_ab_winner' => 'post',
            'pylon_ab_impression_a' => 'post',
            'pylon_ab_impression_b' => 'post',
            'pylon_woo_gtin' => 'post',
            'pylon_woo_mpn' => 'post',
            'pylon_woo_brand' => 'post',
            'pylon_woo_condition' => 'post',
            'pylon_woo_rich_snippet' => 'post',
            'pylon_event_start' => 'post',
            'pylon_event_end' => 'post',
            'pylon_event_venue' => 'post',
            'pylon_video_url' => 'post',
            'pylon_video_duration' => 'post',
            'pylon_video_title' => 'post',
            'pylon_video_description' => 'post',
            'pylon_video_thumbnail' => 'post',
            'pylon_cornerstone_content' => 'post',
            'pylon_aeo_answer' => 'post',
            'pylon_aeo_question' => 'post',
            'pylon_aeo_keywords' => 'post',
            'pylon_preferred_source' => 'post',
            'pylon_llm_preview_cache' => 'post',
            'pylon_extractability_score' => 'post',
            'pylon_multi_engine_score' => 'post',
            'pylon_schema_test_result' => 'post',
        ];

        foreach ($meta_keys as $key => $type) {
            register_post_meta($type, $key, [
                'show_in_rest' => true,
                'single' => true,
                'type' => 'string',
                'auth_callback' => function() { return current_user_can('edit_posts') || current_user_can('pylon_edit_seo_meta'); },
            ]);
        }
    }



    private static function autoload(string $class): void {
        $ns = __NAMESPACE__ . '\\';

        $modules_prefix = $ns . 'Modules\\';
        if (strncmp($modules_prefix, $class, strlen($modules_prefix)) === 0) {
            $relative = substr($class, strlen($modules_prefix));
            $file = PYLON_PATH . 'modules/' . str_replace('\\', '/', $relative) . '.php';
            if (file_exists($file)) { require $file; return; }

            $parts = explode('\\', $relative);
            $dir = $parts[0];
            $kebab = strtolower(preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $dir));
            $plain = strtolower($dir);
            $candidates = [$kebab, $plain];
            foreach (array_unique($candidates) as $alt) {
                $parts[0] = $alt;
                $alt_path = PYLON_PATH . 'modules/' . implode('/', $parts) . '.php';
                if (file_exists($alt_path)) { require $alt_path; return; }
            }
            return;
        }

        if (strncmp($ns, $class, strlen($ns)) === 0) {
            $relative = substr($class, strlen($ns));
            $kebab = strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $relative));
            $file = PYLON_PATH . 'core/class-' . $kebab . '.php';
            if (file_exists($file)) { require $file; return; }
        }
    }

    public static function activate(): void {
        require_once PYLON_PATH . 'core/class-activator.php';
        Activator::activate();
        update_option('pylon_install_pending', true, false);
    }

    public static function deactivate(): void {
        require_once PYLON_PATH . 'core/class-activator.php';
        Activator::deactivate();
        // Run module uninstalls when all modules are still loaded.
        foreach (self::$module_instances as $instance) {
            if (method_exists($instance, 'uninstall')) {
                $instance->uninstall();
            }
        }
    }

    public static function track_usage(string $feature, int $count = 1): void {
        $stats = get_option('pylon_usage_stats', []);
        $stats[$feature] = ($stats[$feature] ?? 0) + $count;
        update_option('pylon_usage_stats', $stats, false);
    }

    public static function get_usage_stats(): array {
        return get_option('pylon_usage_stats', []);
    }

    public static function get_loaded_modules(): array {
        return array_keys(self::$module_instances);
    }
}
