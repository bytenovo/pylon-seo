<?php
namespace Pylon\Core\Modules\ConflictDetector;

/**
 * Detects competing SEO plugins that duplicate meta/schema/sitemaps.
 */
class ConflictDetector {
    public function register(): void {
        add_action('admin_notices', [$this, 'maybe_notice']);
        add_filter('pylon/system_status_sections', [$this, 'status_section']);
        // Hook into SystemStatus if it doesn't support the filter yet — also patch gather via action.
        add_action('admin_init', [$this, 'boot_status_hook']);
    }

    public function boot_status_hook(): void {
        // Soft integration: SystemStatus will call apply_filters if present; we also expose API.
    }

    /** @return array<int, array{file:string,name:string,risk:string}> */
    public static function conflicts(): array {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $known = [
            'wordpress-seo/wp-seo.php' => ['name' => 'Yoast SEO', 'risk' => 'meta_schema_sitemap'],
            'wordpress-seo-premium/wp-seo-premium.php' => ['name' => 'Yoast SEO Premium', 'risk' => 'meta_schema_sitemap'],
            'seo-by-rank-math/rank-math.php' => ['name' => 'Rank Math SEO', 'risk' => 'meta_schema_sitemap'],
            'seo-by-rank-math-pro/rank-math-pro.php' => ['name' => 'Rank Math SEO Pro', 'risk' => 'meta_schema_sitemap'],
            'all-in-one-seo-pack/all_in_one_seo_pack.php' => ['name' => 'All in One SEO', 'risk' => 'meta_schema_sitemap'],
            'all-in-one-seo-pack-pro/all_in_one_seo_pack.php' => ['name' => 'All in One SEO Pro', 'risk' => 'meta_schema_sitemap'],
            'wp-seopress/seopress.php' => ['name' => 'SEOPress', 'risk' => 'meta_schema_sitemap'],
            'wp-seopress-pro/seopress-pro.php' => ['name' => 'SEOPress Pro', 'risk' => 'meta_schema_sitemap'],
            'the-seo-framework/the-seo-framework.php' => ['name' => 'The SEO Framework', 'risk' => 'meta_schema_sitemap'],
            'squirrly-seo/squirrly.php' => ['name' => 'Squirrly SEO', 'risk' => 'meta_schema_sitemap'],
            'autodescription/autodescription.php' => ['name' => 'The SEO Framework', 'risk' => 'meta_schema_sitemap'],
            'google-sitemap-generator/sitemap.php' => ['name' => 'XML Sitemaps (Google)', 'risk' => 'sitemap'],
            'redirection/redirection.php' => ['name' => 'Redirection', 'risk' => 'redirects'],
            'pretty-link/pretty-link.php' => ['name' => 'Pretty Links', 'risk' => 'redirects'],
        ];
        $found = [];
        foreach ($known as $file => $meta) {
            if (is_plugin_active($file)) {
                $found[] = [
                    'file' => $file,
                    'name' => $meta['name'],
                    'risk' => $meta['risk'],
                ];
            }
        }
        return $found;
    }

    public function maybe_notice(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || (strpos((string) $screen->id, 'pylon') === false && $screen->id !== 'plugins')) {
            return;
        }
        if (get_option('pylon_conflict_notice_dismissed') === PYLON_VERSION) {
            return;
        }
        $conflicts = self::conflicts();
        // Only warn on duplicate head/sitemap engines — redirects plugins are fine alongside.
        $serious = array_filter($conflicts, static function ($c) {
            return in_array($c['risk'], ['meta_schema_sitemap', 'sitemap'], true);
        });
        if (!$serious) {
            return;
        }
        $names = array_map(static function ($c) {
            return $c['name'];
        }, $serious);
        echo '<div class="notice notice-warning is-dismissible"><p>';
        echo esc_html(
            sprintf(
                /* translators: %s: plugin names */
                __('Pylon SEO detected other SEO plugins that may duplicate titles, schema, or sitemaps: %s. Deactivate them or use Pylon Migrator, then keep a single SEO engine.', 'pylon-seo'),
                implode(', ', $names)
            )
        );
        echo ' <a href="' . esc_url(admin_url('admin.php?page=pylon-system-status')) . '">' . esc_html__('View System Status', 'pylon-seo') . '</a>';
        echo ' · <a href="' . esc_url(admin_url('admin.php?page=pylon-group-tools&tab=import')) . '">' . esc_html__('Import & switch', 'pylon-seo') . '</a>';
        echo '</p></div>';
    }

    public function status_section(array $sections): array {
        $conflicts = self::conflicts();
        $items = [];
        if (!$conflicts) {
            $items[] = [
                'label' => __('Competing SEO plugins', 'pylon-seo'),
                'value' => __('None detected', 'pylon-seo'),
                'status' => 'good',
            ];
        } else {
            foreach ($conflicts as $c) {
                $status = $c['risk'] === 'redirects' ? 'warn' : 'bad';
                $note = $c['risk'] === 'redirects'
                    ? __('OK alongside Pylon if you disable Pylon redirects', 'pylon-seo')
                    : __('Duplicate meta/schema risk — deactivate after migrating', 'pylon-seo');
                $items[] = [
                    'label' => $c['name'],
                    'value' => $c['file'],
                    'status' => $status,
                    'note' => $note,
                ];
            }
        }
        $sections['conflicts'] = [
            'icon' => '⚔️',
            'title' => __('SEO Conflicts', 'pylon-seo'),
            'items' => $items,
        ];
        return $sections;
    }
}
