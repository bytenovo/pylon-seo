<?php
namespace Pylon\Core\Modules\Gutenberg;
defined('ABSPATH') || exit;
class GutenbergSidebar {
    public function register(): void {
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_editor_assets']);
    }

    public function enqueue_editor_assets(): void {
        $asset_file = PYLON_PATH . 'assets/js/gutenberg-sidebar.js';
        if (!file_exists($asset_file)) return;

        $post_id = get_the_ID();
        $post = $post_id ? get_post($post_id) : null;

        $schema_types = [
            '' => __('Auto (default)', 'pylon-seo'),
            'Article' => 'Article',
            'BlogPosting' => 'BlogPosting',
            'NewsArticle' => 'NewsArticle',
            'Product' => 'Product',
            'LocalBusiness' => 'LocalBusiness',
            'FAQPage' => 'FAQPage',
            'HowTo' => 'HowTo',
            'Recipe' => 'Recipe',
            'Event' => 'Event',
            'Person' => 'Person',
            'VideoObject' => 'VideoObject',
        ];

        wp_enqueue_script(
            'pylon-gutenberg',
            PYLON_URL . 'assets/js/gutenberg-sidebar.js',
            ['wp-plugins', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n', 'wp-core-data'],
            filemtime($asset_file),
            true
        );

        $engine_overall = 0;
        $engine_content = null;
        $post_obj = get_post($post_id);
        if ($post_obj && class_exists('\Pylon\Core\Modules\Content\ContentScore')) {
            $engine_data = \Pylon\Core\Modules\Content\ContentScore::get_score_data($post_obj);
            $engine_overall = $engine_data['overall'] ?? 0;
            $engine_content = $engine_data['content'] ?? null;
        }
        if (!$engine_overall) {
            $engine_meta = json_decode(get_post_meta($post_id, '_pylon_engine_score', true) ?: '', true);
            $engine_overall = isset($engine_meta['overall']) ? (int) $engine_meta['overall'] : 0;
        }

        $content_text = '';
        $words = 0;
        $headings = 0;
        $images = 0;
        $has_list = false;
        $has_table = false;
        if ($engine_content) {
            $content_text = wp_strip_all_tags($engine_content['text']);
            $words = $engine_content['word_count'];
            $headings = $engine_content['heading_count'];
            $images = $engine_content['image_count'];
            $has_list = $engine_content['has_list'];
            $has_table = $engine_content['has_table'];
        } elseif ($post) {
            $raw = $post->post_content;
            $content_text = wp_strip_all_tags($raw);
            $words = preg_match_all('/\p{L}+/u', $content_text);
            $headings = preg_match_all('/<h[1-6][^>]*>/i', $raw);
            $images = preg_match_all('/<img[^>]+>/i', $raw);
            $has_list = (bool) preg_match('/<[uo]l/i', $raw);
            $has_table = (bool) preg_match('/<table/i', $raw);
        }

        $seo_checks = ['checks' => [], 'scores' => ['seo' => 0, 'readability' => 0, 'technical' => 0, 'media' => 0], 'score' => 0, 'highlights' => []];
        $highlight_issues = [];
        $advanced_checks = ['checks' => [], 'scores' => ['eeat' => 0, 'topical' => 0, 'uniqueness' => 0], 'score' => 0];
        if ($post && class_exists('\Pylon\Core\Modules\Content\SeoCheckerEngine')) {
            $checker = new \Pylon\Core\Modules\Content\SeoCheckerEngine($post);
            $seo_data = $checker->get_score_by_tabs();
            $seo_checks = [
                'checks' => $seo_data['checks'],
                'scores' => $seo_data['scores'],
                'score' => $checker->get_score(),
                'highlights' => $checker->get_highlight_issues(),
            ];
            $highlight_issues = $checker->get_highlight_issues();
        }
        if ($post && class_exists('\Pylon\Core\Modules\Content\AdvancedAnalysisEngine')) {
            $adv = new \Pylon\Core\Modules\Content\AdvancedAnalysisEngine($post);
            $adv_data = $adv->get_score_by_tabs();
            $advanced_checks = [
                'checks' => $adv_data['checks'],
                'scores' => $adv_data['scores'],
                'score' => $adv->get_score(),
            ];
        }

        $gutenberg_data = apply_filters('pylon_gutenberg_sidebar_data', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pylon_admin_nonce'),
            'permalink' => $post ? get_permalink($post) : '',
            'slug' => $post ? $post->post_name : '',
            'schema_types' => $schema_types,
            'kw_limit' => 5,
            'full_edit_url' => $post_id ? admin_url('post.php?post=' . $post_id . '&action=edit#pylon_meta_box') : '',
            'engine_overall' => $engine_overall,
            'content_text' => $content_text,
            'words' => $words,
            'headings' => $headings,
            'images' => $images,
            'has_list' => $has_list,
            'has_table' => $has_table,
            'seo_checks' => $seo_checks,
            'highlight_issues' => $highlight_issues,
            'advanced_checks' => $advanced_checks,
        ]);

        wp_localize_script('pylon-gutenberg', 'pylonGutenbergData', $gutenberg_data);

        wp_enqueue_style(
            'pylon-gutenberg-css',
            PYLON_URL . 'assets/css/admin.css',
            [],
            filemtime(PYLON_PATH . 'assets/css/admin.css')
        );
    }
}
