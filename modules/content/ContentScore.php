<?php
namespace Pylon\Core\Modules\Content;
defined('ABSPATH') || exit;
class ContentScore {

    public static function get_score_data(\WP_Post $post, array $overrides = []): array {
        if (class_exists('\Pylon\Core\Modules\MultiEngineScore\MultiEngineScore')) {
            $multi = new \Pylon\Core\Modules\MultiEngineScore\MultiEngineScore();
            $data = $multi->calculate_score($post, $overrides);
            if (!empty($data['content']['text']) || !empty($overrides['content'])) {
                return $data;
            }
        }
        $scorer = new self();
        return $scorer->calculate_score($post, $overrides);
    }

    public static function get_content_data(\WP_Post $post): array {
        return self::resolve_content_data($post);
    }

    /**
     * Resolve the analyzable content for a post. Delegates to the pro
     * MultiEngineScore resolver when available; otherwise falls back to the
     * free tiered resolver below so page-builder and custom-template pages
     * still produce a correct score without the pro plugin.
     */
    public static function resolve_content_data(\WP_Post $post): array {
        if (class_exists('\Pylon\Core\Modules\MultiEngineScore\MultiEngineScore')) {
            $multi = new \Pylon\Core\Modules\MultiEngineScore\MultiEngineScore();
            $cd = $multi->resolve_content_data($post);
            if (is_array($cd) && !empty($cd['text'])) {
                return $cd;
            }
        }
        return self::resolve_local_content_data($post);
    }

    private static function resolve_local_content_data(\WP_Post $post): array {
        $result = ['text' => '', 'word_count' => 0, 'heading_count' => 0, 'image_count' => 0, 'has_list' => false, 'has_table' => false, 'raw_html' => ''];
        $min_words = 50;
        $raw = $post->post_content;

        // Tier 1: raw post_content — works for standard editor, no page builder.
        if (preg_match_all('/\p{L}+/u', wp_strip_all_tags($raw)) >= $min_words) {
            $result['text'] = wp_strip_all_tags($raw);
            $result['word_count'] = preg_match_all('/\p{L}+/u', $result['text']);
            $result['heading_count'] = preg_match_all('/<h[1-6][^>]*>/i', $raw);
            $result['image_count'] = preg_match_all('/<img[^>]+>/i', $raw);
            $result['has_list'] = (bool) preg_match('/<[uo]l/i', $raw);
            $result['has_table'] = (bool) preg_match('/<table/i', $raw);
            $result['raw_html'] = $raw;
            return $result;
        }

        // Tier 2: extract from _elementor_data JSON.
        if (get_post_meta($post->ID, '_elementor_edit_mode', true) === 'builder') {
            $el_data = get_post_meta($post->ID, '_elementor_data', true);
            if (!empty($el_data)) {
                $el_arr = is_string($el_data) ? json_decode($el_data, true) : $el_data;
                if (!is_array($el_arr)) $el_arr = [];
                $el_text = self::extract_elementor_text($el_arr);
                if (preg_match_all('/\p{L}+/u', $el_text) >= $min_words) {
                    $result['text'] = $el_text;
                    $result['word_count'] = preg_match_all('/\p{L}+/u', $el_text);
                    $result['heading_count'] = self::count_elementor_headings($el_arr);
                    $result['image_count'] = self::count_elementor_images($el_arr);
                    $result['has_list'] = (bool) self::has_elementor_lists($el_arr);
                    $result['has_table'] = (bool) self::has_elementor_tables($el_arr);
                    $result['raw_html'] = '';
                    return $result;
                }
            }
        }

        // Tier 2.5: cached rendered content (populated when a page builder or
        // custom template rendered the page before).
        $cached = get_post_meta($post->ID, '_pylon_rendered_cache', true);
        if (is_array($cached) && isset($cached['text']) && preg_match_all('/\p{L}+/u', $cached['text']) >= $min_words) {
            $result['text'] = $cached['text'];
            $result['word_count'] = $cached['word_count'] ?? preg_match_all('/\p{L}+/u', $cached['text']);
            $result['heading_count'] = $cached['heading_count'] ?? 0;
            $result['image_count'] = $cached['image_count'] ?? 0;
            $result['has_list'] = $cached['has_list'] ?? false;
            $result['has_table'] = $cached['has_table'] ?? false;
            $result['raw_html'] = $cached['raw_html'] ?? '';
            return $result;
        }

        // Tier 3: the_content filter for shortcode-based page builders (e.g. Elementor).
        $rendered = apply_filters('the_content', $raw);
        $r_text = wp_strip_all_tags($rendered);
        if (preg_match_all('/\p{L}+/u', $r_text) >= $min_words) {
            $result['text'] = $r_text;
            $result['word_count'] = preg_match_all('/\p{L}+/u', $r_text);
            $result['heading_count'] = preg_match_all('/<h[1-6][^>]*>/i', $rendered);
            $result['image_count'] = preg_match_all('/<img[^>]+>/i', $rendered);
            $result['has_list'] = (bool) preg_match('/<[uo]l/i', $rendered);
            $result['has_table'] = (bool) preg_match('/<table/i', $rendered);
            $result['raw_html'] = $rendered;
            update_post_meta($post->ID, '_pylon_rendered_cache', $result);
            return $result;
        }

        // Tier 4: HTTP page fetch for pages built with a page builder or a
        // custom theme template where post_content is thin but the public page
        // renders full content. Results are cached in _pylon_rendered_cache.
        if (self::page_builder_or_custom_template($post)) {
            $page_url = get_permalink($post);
            if ($page_url) {
                $response = \Pylon\Core\HttpClient::request('GET', $page_url, [
                    'timeout' => 5,
                    'headers' => [
                        'User-Agent' => 'Pylon SEO Engine/1.0',
                    ],
                ]);
                if ($response['success'] && (int) ($response['code'] ?? 0) === 200) {
                    $html = $response['body'] ?? '';
                    $f_text = wp_strip_all_tags($html);
                    if (preg_match_all('/\p{L}+/u', $f_text) >= $min_words) {
                        $result['text'] = $f_text;
                        $result['word_count'] = preg_match_all('/\p{L}+/u', $f_text);
                        $result['heading_count'] = preg_match_all('/<h[1-6][^>]*>/i', $html);
                        $result['image_count'] = preg_match_all('/<img[^>]+>/i', $html);
                        $result['has_list'] = (bool) preg_match('/<[uo]l/i', $html);
                        $result['has_table'] = (bool) preg_match('/<table/i', $html);
                        $result['raw_html'] = $html;
                        update_post_meta($post->ID, '_pylon_rendered_cache', $result);
                        return $result;
                    }
                }
            }
        }

        return $result;
    }

    private static function page_builder_or_custom_template(\WP_Post $post): bool {
        if (get_post_meta($post->ID, '_elementor_edit_mode', true) === 'builder') {
            return true;
        }
        $template = get_post_meta($post->ID, '_wp_page_template', true);
        return $template && $template !== 'default';
    }

    private static function count_elementor_headings(array $elements): int {
        $count = 0;
        foreach ($elements as $el) {
            if (isset($el['widgetType']) && preg_match('/^heading$/i', $el['widgetType'])) $count++;
            if (!empty($el['elements'])) $count += self::count_elementor_headings($el['elements']);
        }
        return $count;
    }

    private static function count_elementor_images(array $elements): int {
        $count = 0;
        foreach ($elements as $el) {
            if (isset($el['widgetType']) && preg_match('/image|gallery|media/i', $el['widgetType'])) $count++;
            if (!empty($el['elements'])) $count += self::count_elementor_images($el['elements']);
        }
        return $count;
    }

    private static function has_elementor_lists(array $elements): bool {
        foreach ($elements as $el) {
            if (isset($el['widgetType']) && preg_match('/icon-list|icon_list|list/i', $el['widgetType'])) return true;
            if (isset($el['settings'])) {
                if (isset($el['settings']['list_items']) || isset($el['settings']['items'])) {
                    $items = $el['settings']['list_items'] ?? $el['settings']['items'] ?? [];
                    if (is_array($items) && count($items) > 1) return true;
                }
            }
            if (!empty($el['elements']) && self::has_elementor_lists($el['elements'])) return true;
        }
        return false;
    }

    private static function has_elementor_tables(array $elements): bool {
        foreach ($elements as $el) {
            if (isset($el['widgetType']) && preg_match('/table/i', $el['widgetType'])) return true;
            if (!empty($el['elements']) && self::has_elementor_tables($el['elements'])) return true;
        }
        return false;
    }

    private static function extract_elementor_text(array $elements): string {
        $text = '';
        $text_keys = ['title', 'text', 'editor', 'content', 'description', 'caption', 'heading', 'subheading', 'testimonial_content', 'testimonial_name', 'alert_title', 'alert_description', 'blockquote_content', 'author_name', 'item_text', 'tab_title', 'tab_content', 'accordion_title', 'accordion_content', 'toggle_title', 'toggle_content', 'faq_question', 'faq_answer', 'list_items', 'item_title', 'item_description', 'name', 'biography', 'address', 'phone', 'email', 'website'];
        $repeater_keys = ['items', 'slides', 'list', 'tabs', 'accordion', 'toggle', 'faq_items', 'testimonials', 'team_members', 'pricing_items', 'icon_list'];

        foreach ($elements as $element) {
            if (isset($element['settings']) && is_array($element['settings'])) {
                foreach ($element['settings'] as $key => $value) {
                    if (in_array($key, $text_keys, true) && is_string($value) && !empty(trim($value))) {
                        $text .= ' ' . trim($value);
                    }
                    if (in_array($key, $repeater_keys, true) && is_array($value)) {
                        foreach ($value as $item) {
                            if (is_array($item)) $text .= ' ' . self::extract_elementor_text([$item]);
                        }
                    }
                }
            }
            if (isset($element['elements']) && is_array($element['elements'])) {
                $text .= ' ' . self::extract_elementor_text($element['elements']);
            }
        }
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Multi-engine score. Owned by the free plugin (pro parity): each engine
     * scores traditional SEO, content depth, structure and authority signals,
     * and the overall is the average engine score.
     */
    public function calculate_score(\WP_Post $post, array $overrides = []): array {
        $title = $overrides['title'] ?? (get_post_meta($post->ID, 'pylon_title', true) ?: $post->post_title);
        $desc = $overrides['description'] ?? (get_post_meta($post->ID, 'pylon_description', true) ?: '');

        $cd = !empty($overrides['content']) ? null : self::resolve_content_data($post);
        if ($cd) {
            $content = $cd['text'];
        } else {
            $content = wp_strip_all_tags($overrides['content'] ?? '');
        }

        $schema_type = $overrides['schema_type'] ?? (get_post_meta($post->ID, 'pylon_schema_type', true) ?: '');
        $canonical = $overrides['canonical'] ?? get_post_meta($post->ID, 'pylon_canonical', true);
        $og_image = $overrides['og_image'] ?? get_post_meta($post->ID, 'pylon_og_image', true);
        $focus_keyword = $overrides['focus_keyword'] ?? (get_post_meta($post->ID, 'pylon_focus_keyword', true) ?: '');

        $word_count = preg_match_all('/\p{L}+/u', $content);
        $heading_count = $cd ? $cd['heading_count'] : preg_match_all('/<h[1-6][^>]*>/i', $overrides['content'] ?? '');
        $image_count = $cd ? $cd['image_count'] : preg_match_all('/<img[^>]+>/i', $overrides['content'] ?? '');
        $raw_html = !empty($overrides['content']) ? $overrides['content'] : $post->post_content;
        $has_list = $cd ? $cd['has_list'] : (bool) preg_match('/<[uo]l/i', $raw_html);
        $has_table = $cd ? $cd['has_table'] : (bool) preg_match('/<table/i', $raw_html);
        $has_qa = (bool) preg_match('/\?/', $content) && $heading_count > 0;
        $first_100_words = wp_trim_words($content, 100);

        $kw_list = array_filter(array_map('strtolower', array_map('trim', explode(',', $focus_keyword))));
        $kw_set = !empty($kw_list);
        $kw_in_title = false;
        $kw_in_desc = false;
        $kw_in_content = false;
        $kw_in_slug = false;
        $slug = $post->post_name;
        foreach ($kw_list as $kw) {
            if (!$kw_in_title && mb_stripos($title, $kw) !== false) $kw_in_title = true;
            if (!$kw_in_desc && mb_stripos($desc, $kw) !== false) $kw_in_desc = true;
            if (!$kw_in_content && mb_stripos($content, $kw) !== false) $kw_in_content = true;
            if (!$kw_in_slug && mb_stripos($slug, $kw) !== false) $kw_in_slug = true;
        }

        $kw_points = 0;
        if ($kw_set) {
            $kw_points += 5;
            if ($kw_in_title) $kw_points += 5;
            if ($kw_in_desc) $kw_points += 4;
            if ($kw_in_content) $kw_points += 4;
            if ($kw_in_slug) $kw_points += 2;
        }

        // Google: traditional SEO signals.
        $google = 0;
        if (mb_strlen($title) >= 30 && mb_strlen($title) <= 60) $google += 15;
        elseif ($title) $google += 8;
        if (mb_strlen($desc) >= 120 && mb_strlen($desc) <= 160) $google += 15;
        elseif ($desc) $google += 7;
        if ($word_count >= 300) $google += 10;
        if ($word_count >= 1000) $google += 5;
        if ($heading_count >= 3) $google += 10;
        if ($image_count > 0) $google += 10;
        elseif ($image_count === 0) $google += 5;
        if ($schema_type) $google += 10;
        if ($canonical) $google += 5;
        if ($og_image) $google += 5;
        if ($has_list || $has_table) $google += 5;
        $google += $kw_points;

        // Bing: mirrors Google but favors multimedia and structured data.
        $bing = max(0, min(100, $google + ($schema_type ? 5 : -5) + ($image_count >= 2 ? 5 : 0)));

        // ChatGPT: content depth, structure, citeability.
        $chatgpt = 0;
        if ($word_count >= 500) $chatgpt += 15;
        if ($word_count >= 1500) $chatgpt += 10;
        if (preg_match('/^[A-Z].+\./', $first_100_words)) $chatgpt += 15;
        if ($has_qa) $chatgpt += 10;
        if ($has_list) $chatgpt += 10;
        if ($heading_count >= 5) $chatgpt += 10;
        if ($schema_type && in_array($schema_type, ['Article', 'FAQPage', 'HowTo'], true)) $chatgpt += 15;
        if (preg_match('/\d{4}/', $content)) $chatgpt += 5;
        if ($has_table) $chatgpt += 10;
        $chatgpt += $kw_points;

        // Perplexity: citation-ready, factual, structured.
        $perplexity = 0;
        if ($word_count >= 800) $perplexity += 20;
        if (preg_match('/\[\d+\]|<sup>|(University|Research|Study|According)/i', $content)) $perplexity += 15;
        if ($has_list) $perplexity += 10;
        if ($has_qa) $perplexity += 10;
        if ($heading_count >= 4) $perplexity += 10;
        if ($schema_type) $perplexity += 15;
        if ($image_count >= 1) $perplexity += 5;
        if (preg_match('/\d{4}/', $content)) $perplexity += 5;
        if (preg_match('/definition|meaning|what is|how to/i', strtolower($title))) $perplexity += 10;
        $perplexity += $kw_points;

        // Gemini: multimodal, structured, authoritative.
        $gemini = min(100, (int) (($google + $bing) / 2) + ($image_count >= 2 ? 10 : 0) + ($og_image ? 5 : 0));

        // Claude: depth, nuance, expert-level.
        $claude = 0;
        if ($word_count >= 1000) $claude += 20;
        if ($heading_count >= 6) $claude += 15;
        if ($has_list && $has_table) $claude += 10;
        if (preg_match('/nuance|trade-off|however|contrast|comparison|pros and cons/i', $content)) $claude += 15;
        if ($schema_type) $claude += 10;
        if (preg_match('/expert|professional|years of experience/i', $content)) $claude += 10;
        if ($has_qa) $claude += 10;
        $claude += $kw_points;

        $engines = [
            'google'     => ['label' => 'Google', 'score' => min(100, $google)],
            'bing'       => ['label' => 'Bing', 'score' => min(100, $bing)],
            'chatgpt'    => ['label' => 'ChatGPT', 'score' => min(100, $chatgpt)],
            'perplexity' => ['label' => 'Perplexity', 'score' => min(100, $perplexity)],
            'gemini'     => ['label' => 'Gemini', 'score' => min(100, $gemini)],
            'claude'     => ['label' => 'Claude', 'score' => min(100, $claude)],
        ];

        $overall = (int) (array_sum(array_column($engines, 'score')) / count($engines));

        return [
            'overall' => $overall,
            'engines' => $engines,
            'content' => [
                'text' => $content,
                'word_count' => $word_count,
                'heading_count' => $heading_count,
                'image_count' => $image_count,
                'has_list' => $has_list,
                'has_table' => $has_table,
                'has_qa' => $has_qa,
                'first_100_words' => $first_100_words,
                'raw_html' => is_array($cd) ? ($cd['raw_html'] ?? '') : '',
            ],
        ];
    }
}
