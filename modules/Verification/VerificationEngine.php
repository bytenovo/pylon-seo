<?php
namespace Pylon\Core\Modules\Verification;
defined('ABSPATH') || exit;
class VerificationEngine {
    public function register(): void {
        add_action('wp_head', [$this, 'output_verification'], 1);
        add_action('admin_init', [$this, 'register_settings']);
        add_filter('pylon_settings_sections', [$this, 'register_section']);
    }

    public function register_settings(): void {
        $codes = $this->get_providers();
        foreach (array_keys($codes) as $key) {
            register_setting('pylon_settings', 'pylon_verification_' . $key, ['sanitize_callback' => 'sanitize_text_field']);
        }
    }

    public function register_section(array $sections): array {
        $codes = $this->get_providers();
        $fields = [];
        foreach ($codes as $key => $provider) {
            $fields['pylon_verification_' . $key] = [
                'type'  => 'text',
                'label' => $provider['label'],
                'desc'  => $provider['desc'],
                'placeholder' => $provider['placeholder'],
            ];
        }
        $sections['verification'] = [
            'icon'   => '✅',
            'title'  => __('Site Verification', 'pylon-seo'),
            'desc'   => __('Verify your site with search engines and webmaster tools. Paste the content value from each verification tag.', 'pylon-seo'),
            'fields' => $fields,
        ];
        return $sections;
    }

    public function get_providers(): array {
        return [
            'google' => [
                'label' => __('Google Search Console', 'pylon-seo'),
                'meta' => 'google-site-verification',
                'desc' => __('Google Site Verification meta content value.', 'pylon-seo'),
                'placeholder' => 'xxxxxxxxxxxxxxxxxxxx',
            ],
            'bing' => [
                'label' => __('Bing Webmaster Tools', 'pylon-seo'),
                'meta' => 'msvalidate.01',
                'desc' => __('Bing Webmaster Tools verification code.', 'pylon-seo'),
                'placeholder' => '0123456789ABCDEF',
            ],
            'yandex' => [
                'label' => __('Yandex Webmaster', 'pylon-seo'),
                'meta' => 'yandex-verification',
                'desc' => __('Yandex Webmaster verification code.', 'pylon-seo'),
                'placeholder' => '0123456789abcdef',
            ],
            'baidu' => [
                'label' => __('Baidu Webmaster', 'pylon-seo'),
                'meta' => 'baidu-site-verification',
                'desc' => __('Baidu Webmaster Tools verification code.', 'pylon-seo'),
                'placeholder' => 'code-xxxxxxxxxxxx',
            ],
            'pinterest' => [
                'label' => __('Pinterest', 'pylon-seo'),
                'meta' => 'pinterest-site-verification',
                'desc' => __('Pinterest site verification code.', 'pylon-seo'),
                'placeholder' => 'xxxxxxxxxxxxxxxxxxxx',
            ],
            'norton' => [
                'label' => __('Norton Safe Web', 'pylon-seo'),
                'meta' => 'norton-safeweb-site-verification',
                'desc' => __('Norton Safe Web site verification code.', 'pylon-seo'),
                'placeholder' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
            ],
            'alexa' => [
                'label' => __('Alexa', 'pylon-seo'),
                'meta' => 'alexaVerifyID',
                'desc' => __('Alexa site verification ID.', 'pylon-seo'),
                'placeholder' => 'xxxxxxxxxxxxxxxxxxxx',
            ],
        ];
    }

    public function output_verification(): void {
        if (is_admin()) return;

        $codes = $this->get_providers();
        foreach ($codes as $key => $provider) {
            $value = get_option('pylon_verification_' . $key, '');
            if (trim($value) === '') continue;
            echo "\n" . '<meta name="' . esc_attr($provider['meta']) . '" content="' . esc_attr($value) . '" />';
        }
        echo "\n";
    }
}
