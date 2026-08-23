<?php
namespace Pylon\Core\Modules\Aeo;
defined('ABSPATH') || exit;
class AEOEngine {
    public function register(): void {
        add_action('rest_api_init', [$this, 'register_rest_fields']);
        add_filter('pylon_gutenberg_sidebar_data', [$this, 'add_gutenberg_data']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend']);
    }

    public function register_rest_fields(): void {
        $cb = function($post) {
            try {
                return $this->analyze($post['id']);
            } catch (\Throwable $e) {
                return ['score' => 0, 'grade' => 'low', 'passed' => 0, 'total' => 0, 'checks' => []];
            }
        };
        register_rest_field('post', 'pylon_aeo_analysis', [
            'get_callback' => $cb,
            'schema' => ['type' => 'object'],
        ]);
        register_rest_field('page', 'pylon_aeo_analysis', [
            'get_callback' => $cb,
            'schema' => ['type' => 'object'],
        ]);
    }

    public function add_gutenberg_data(array $data): array {
        $data['aeo_enabled'] = get_option('pylon_aeo_enabled', '1');
        return $data;
    }

    public function enqueue_frontend(): void {
        if (!get_option('pylon_aeo_enabled', '1')) return;
        if (!is_singular()) return;
        wp_add_inline_style('wp-block-library', '
            .pylon-aeo-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;line-height:1.4;}
            .pylon-aeo-badge-high{background:#d1fae5;color:#065f46;}
            .pylon-aeo-badge-medium{background:#fef3c7;color:#92400e;}
            .pylon-aeo-badge-low{background:#fee2e2;color:#991b1b;}
        ');
    }

    public function analyze(int $post_id): array {
        $post = get_post($post_id);
        if (!$post) return [];

        $content = $post->post_content;
        $text = wp_strip_all_tags($content);
        $word_count = $this->count_words($text);
        $checks = [];

        $checks['direct_answer'] = $this->check_direct_answer($text);
        $checks['qa_format'] = $this->check_qa_format($content);
        $checks['bullet_points'] = $this->check_bullet_points($content);
        $checks['step_by_step'] = $this->check_step_by_step($content);
        $checks['definition_blocks'] = $this->check_definitions($content);
        $checks['statistics'] = $this->check_statistics($content);
        $checks['expert_quotes'] = $this->check_expert_quotes($content);
        $checks['list_schema'] = $this->check_list_schema($post_id);

        $passed = 0;
        $total = count($checks);
        foreach ($checks as $c) {
            if ($c['pass']) $passed++;
        }
        $score = $total > 0 ? round(($passed / $total) * 100) : 0;

        return [
            'score' => $score,
            'passed' => $passed,
            'total' => $total,
            'grade' => $score >= 80 ? 'high' : ($score >= 50 ? 'medium' : 'low'),
            'checks' => $checks,
        ];
    }

    private function check_direct_answer(string $text): array {
        $first_words = mb_substr($text, 0, 500);
        $word_count = $this->count_words($first_words);
        $pass = $word_count >= 30 && $word_count <= 100;
        return [
            'pass' => $pass,
            'label' => __('Clear direct answer in first 100 words', 'pylon-seo'),
            /* translators: %d: Word count of the first 100 words. */
            'note' => $pass ? sprintf(__('%d words — good answer density', 'pylon-seo'), $word_count) : sprintf(__('%d words — aim for 30-100 word answer', 'pylon-seo'), $word_count),
        ];
    }

    private function check_qa_format(string $content): array {
        preg_match_all('/<h[1-6][^>]*>\s*[^<]*\?\s*<\/h[1-6]>/i', $content, $q_headings);
        preg_match_all('/<h[1-6][^>]*>\s*(?:what|why|how|when|where|which|who|does|is|are|can|should)\b/i', $content, $qw_headings);
        preg_match_all('/^(?:Q[.:]|Question[.:])/mi', $content, $q_labels);
        $count = count($q_headings[0]) + count($qw_headings[0]) + count($q_labels[0]);
        $pass = $count >= 2;
        return [
            'pass' => $pass,
            'label' => __('Question-answer format used', 'pylon-seo'),
            /* translators: %d: Number of question headings found. */
            'note' => $pass ? sprintf(__('%d question headings found', 'pylon-seo'), $count) : __('Add question-style headings (What, Why, How)', 'pylon-seo'),
        ];
    }

    private function check_bullet_points(string $content): array {
        preg_match_all('/<(ul|ol)[^>]*>/i', $content, $lists);
        preg_match_all('/^[-*]\s/m', $content, $markdown);
        $count = count($lists[0]) + count($markdown[0]);
        $pass = $count >= 1;
        return [
            'pass' => $pass,
            'label' => __('Lists and bullet points used', 'pylon-seo'),
            /* translators: %d: Number of lists found. */
            'note' => $pass ? sprintf(__('%d lists found', 'pylon-seo'), $count) : __('Add bullet points or numbered lists', 'pylon-seo'),
        ];
    }

    private function check_step_by_step(string $content): array {
        preg_match_all('/<(ol)[^>]*>/i', $content, $ordered);
        preg_match_all('/<h[1-6][^>]*>\s*(?:step\s*\d+|phase\s*\d+|stage\s*\d+)/i', $content, $step_headings);
        $count = count($ordered[0]) + count($step_headings[0]);
        $pass = $count >= 1;
        return [
            'pass' => $pass,
            'label' => __('Step-by-step instructions present', 'pylon-seo'),
            /* translators: %d: Number of step sequences found. */
            'note' => $pass ? sprintf(__('%d step sequences found', 'pylon-seo'), $count) : __('Add numbered steps for procedures', 'pylon-seo'),
        ];
    }

    private function check_definitions(string $content): array {
        preg_match_all('/<h[1-6][^>]*>\s*(?:what\s+is|definition|meaning|overview|introduction)\b/i', $content, $def_headings);
        preg_match_all('/<dt[^>]*>/i', $content, $def_list);
        $count = count($def_headings[0]) + count($def_list[0]);
        $pass = $count >= 1;
        return [
            'pass' => $pass,
            'label' => __('Key terms defined', 'pylon-seo'),
            /* translators: %d: Number of definitions found. */
            'note' => $pass ? sprintf(__('%d definitions found', 'pylon-seo'), $count) : __('Add definition blocks for key terms', 'pylon-seo'),
        ];
    }

    private function check_statistics(string $content): array {
        preg_match_all('/\b\d+[%]|\b\d+%\b|\d+ out of \d+|\d+ in \d+|\d+ percent|\b(?:study|research|survey|data|statistics?)\b/i', $content, $stats);
        $count = count($stats[0]);
        $pass = $count >= 2;
        return [
            'pass' => $pass,
            'label' => __('Statistics and data cited', 'pylon-seo'),
            /* translators: %d: Number of data points found. */
            'note' => $pass ? sprintf(__('%d data points found', 'pylon-seo'), $count) : __('Cite original data or statistics', 'pylon-seo'),
        ];
    }

    private function check_expert_quotes(string $content): array {
        preg_match_all('/<blockquote[^>]*>/i', $content, $blockquotes);
        preg_match_all('/"[^"]{20,}"|\'[^\']{20,}\'/u', $content, $quotes);
        preg_match_all('/\b(?:according to|as noted by|as explained by)\b/i', $content, $attributions);
        $count = count($blockquotes[0]) + count($quotes[0]) + count($attributions[0]);
        $pass = $count >= 1;
        return [
            'pass' => $pass,
            'label' => __('Expert quotes and attributions', 'pylon-seo'),
            /* translators: %d: Number of quotes or attributions found. */
            'note' => $pass ? sprintf(__('%d quotes/attributions found', 'pylon-seo'), $count) : __('Add expert quotes or cite authorities', 'pylon-seo'),
        ];
    }

    private function check_list_schema(int $post_id): array {
        $schema = get_post_meta($post_id, 'pylon_schema_type', true);
        $pass = in_array($schema, ['FAQPage', 'HowTo', 'Article', 'QAPage']);
        return [
            'pass' => $pass,
            'label' => __('Schema supports AI citation', 'pylon-seo'),
            /* translators: %s: Name of the active schema type. */
            'note' => $pass ? sprintf(__('%s schema active', 'pylon-seo'), $schema) : __('Use FAQPage, HowTo, or Article schema', 'pylon-seo'),
        ];
    }

    private function count_words(string $text): int {
        $text = trim($text);
        if ('' === $text) return 0;
        return preg_match_all('/\S+/u', $text, $m) ?: 0;
    }
}
