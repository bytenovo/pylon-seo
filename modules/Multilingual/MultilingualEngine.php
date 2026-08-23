<?php
namespace Pylon\Core\Modules\Multilingual;
defined('ABSPATH') || exit;
/**
 * Hreflang + deep hooks for WPML, Polylang, TranslatePress, Weglot.
 * Goes beyond “we work with multilingual” to automatic alternates + x-default.
 */
class MultilingualEngine {
    public function register(): void {
        add_action('wp_head', [$this, 'output_hreflang'], 2);
        add_action('admin_init', [$this, 'register_settings']);
        add_filter('pylon_settings_sections', [$this, 'settings_section']);
    }

    public function register_settings(): void {
        register_setting('pylon_settings', 'pylon_hreflang_enabled', ['sanitize_callback' => 'absint', 'default' => 1]);
        register_setting('pylon_settings', 'pylon_hreflang_xdefault', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('pylon_settings', 'pylon_hreflang_manual', ['sanitize_callback' => 'sanitize_textarea_field']);
    }

    public function settings_section(array $sections): array {
        $detected = $this->detect_plugins();
        $desc = __('Automatic hreflang for WPML, Polylang, TranslatePress, and Weglot — plus manual overrides. Stronger multilingual SEO than plugins that only “compat” translations.', 'pylon-seo');
        if ($detected) {
            $desc .= ' ' . sprintf(
                /* translators: %s: plugin names */
                __('Detected: %s.', 'pylon-seo'),
                implode(', ', $detected)
            );
        }
        $sections['multilingual'] = [
            'icon' => '🌐',
            'title' => __('Multilingual / Hreflang', 'pylon-seo'),
            'desc' => $desc,
            'fields' => [
                'pylon_hreflang_enabled' => [
                    'type' => 'checkbox',
                    'label' => __('Output hreflang tags', 'pylon-seo'),
                    'desc' => __('Injects rel="alternate" hreflang in &lt;head&gt; from your multilingual plugin or manual map.', 'pylon-seo'),
                    'default' => '1',
                ],
                'pylon_hreflang_xdefault' => [
                    'type' => 'text',
                    'label' => __('x-default language code', 'pylon-seo'),
                    'desc' => __('e.g. en or en-US. Leave empty to use your site language.', 'pylon-seo'),
                    'placeholder' => 'en',
                ],
                'pylon_hreflang_manual' => [
                    'type' => 'textarea',
                    'label' => __('Manual hreflang map (optional)', 'pylon-seo'),
                    'desc' => __('One per line: lang|url  — used when no multilingual plugin is active, or as extras.', 'pylon-seo'),
                    'placeholder' => "en|https://example.com/\nfr|https://example.com/fr/",
                ],
            ],
        ];
        return $sections;
    }

    /** @return string[] */
    public function detect_plugins(): array {
        $out = [];
        if (defined('ICL_SITEPRESS_VERSION') || function_exists('icl_object_id')) {
            $out[] = 'WPML';
        }
        if (function_exists('pll_current_language') || defined('POLYLANG_VERSION')) {
            $out[] = 'Polylang';
        }
        if (function_exists('trp_get_languages') || defined('TRP_PLUGIN_VERSION')) {
            $out[] = 'TranslatePress';
        }
        if (function_exists('weglot_get_current_language') || defined('WEGLOT_VERSION')) {
            $out[] = 'Weglot';
        }
        return $out;
    }

    public function output_hreflang(): void {
        if (!get_option('pylon_hreflang_enabled', '1')) {
            return;
        }
        if (is_admin() || wp_doing_ajax() || is_404()) {
            return;
        }
        $links = $this->collect_alternates();
        if (count($links) < 1) {
            return;
        }
        $xdefault = trim((string) get_option('pylon_hreflang_xdefault', ''));
        if ($xdefault === '') {
            $xdefault = str_replace('_', '-', get_bloginfo('language'));
            $xdefault = strtolower(substr($xdefault, 0, 2)) ?: 'en';
        }
        $printed = [];
        foreach ($links as $lang => $url) {
            $lang = strtolower(str_replace('_', '-', $lang));
            if (isset($printed[$lang])) {
                continue;
            }
            $printed[$lang] = true;
            echo '<link rel="alternate" hreflang="' . esc_attr($lang) . '" href="' . esc_url($url) . '" />' . "\n";
        }
        // x-default → prefer matching lang URL, else first / current.
        $xd_url = $links[$xdefault] ?? ($links[substr($xdefault, 0, 2)] ?? reset($links));
        if ($xd_url) {
            echo '<link rel="alternate" hreflang="x-default" href="' . esc_url($xd_url) . '" />' . "\n";
        }
    }

    /** @return array<string,string> lang => url */
    private function collect_alternates(): array {
        $links = [];

        // WPML
        if (function_exists('icl_get_languages')) {
            $langs = icl_get_languages('skip_missing=0');
            if (is_array($langs)) {
                foreach ($langs as $l) {
                    if (!empty($l['language_code']) && !empty($l['url'])) {
                        $links[$l['language_code']] = $l['url'];
                    }
                }
            }
        }

        // Polylang
        if (function_exists('pll_the_languages')) {
            $langs = pll_the_languages(['raw' => 1, 'echo' => 0]);
            if (is_array($langs)) {
                foreach ($langs as $l) {
                    if (!empty($l['slug']) && !empty($l['url'])) {
                        $links[$l['slug']] = $l['url'];
                    }
                }
            }
        }

        // TranslatePress
        if (class_exists('\TRP_Translate_Press') && function_exists('trp_custom_language_switcher')) {
            // Best-effort: current URL per published language from settings.
            $settings = get_option('trp_settings', []);
            $codes = $settings['publish-languages'] ?? [];
            $default = $settings['default-language'] ?? '';
            if (is_array($codes)) {
                foreach ($codes as $code) {
                    $url = $this->current_url();
                    if ($default && $code !== $default && function_exists('trp_get_url_for_language')) {
                        // trp may not expose this — keep current as fallback.
                        $url = apply_filters('trp_get_url_for_language', $url, $code, $url);
                    }
                    $links[$code] = $url;
                }
            }
        }

        // Weglot
        if (function_exists('weglot_get_destination_languages') && function_exists('weglot_get_request_url_service')) {
            try {
                $langs = weglot_get_destination_languages();
                $service = weglot_get_request_url_service();
                if (is_array($langs) && $service) {
                    foreach ($langs as $lang) {
                        $code = is_object($lang) && method_exists($lang, 'getInternalCode') ? $lang->getInternalCode() : (string) $lang;
                        if ($code && method_exists($service, 'get_current_full_url')) {
                            $links[$code] = $service->get_current_full_url($code);
                        }
                    }
                }
            } catch (\Throwable $e) {
                // ignore weglot API variance
            }
        }

        // Manual extras
        $manual = (string) get_option('pylon_hreflang_manual', '');
        foreach (preg_split('/\r\n|\r|\n/', $manual) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '|') === false) {
                continue;
            }
            [$lang, $url] = array_map('trim', explode('|', $line, 2));
            if ($lang && $url) {
                $links[$lang] = esc_url_raw($url);
            }
        }

        // Always include current if empty set and we're on singular — self alternate.
        if (!$links && (is_singular() || is_front_page())) {
            $lang = strtolower(substr(str_replace('_', '-', get_bloginfo('language')), 0, 2)) ?: 'en';
            $links[$lang] = $this->current_url();
        }

        return apply_filters('pylon/hreflang_links', $links);
    }

    private function current_url(): string {
        if (is_singular()) {
            return get_permalink() ?: home_url('/');
        }
        global $wp;
        $path = isset($wp->request) ? $wp->request : '';
        return home_url(user_trailingslashit($path));
    }
}
