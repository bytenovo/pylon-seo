<?php
namespace Pylon\Core\Modules\Citeability;
defined('ABSPATH') || exit;
class CiteabilityEngine {
    public function register(): void {
        add_action('rest_api_init', [$this, 'register_rest_fields']);
        add_filter('pylon_gutenberg_sidebar_data', [$this, 'add_gutenberg_data']);
        add_action('manage_posts_columns', [$this, 'add_admin_column']);
        add_action('manage_pages_columns', [$this, 'add_admin_column']);
        add_action('manage_posts_custom_column', [$this, 'render_admin_column'], 10, 2);
        add_action('manage_pages_custom_column', [$this, 'render_admin_column'], 10, 2);
        add_action('wp_ajax_pylon_citeability_refresh', [$this, 'ajax_refresh']);
        add_action('save_post', [$this, 'clear_cache_on_save'], 10, 2);
    }

    public function register_rest_fields(): void {
        $cb = function($post) {
            try {
                return $this->get_score($post['id']);
            } catch (\Throwable $e) {
                return ['score' => 0, 'grade' => 'low', 'breakdown' => []];
            }
        };
        foreach (['post', 'page'] as $type) {
            register_rest_field($type, 'pylon_citeability', [
                'get_callback' => $cb,
                'schema' => ['type' => 'object'],
            ]);
        }
    }

    public function add_gutenberg_data(array $data): array {
        $post_id = get_the_ID();
        if ($post_id) {
            $data['citeability'] = $this->get_score($post_id);
        }
        return $data;
    }

    public function get_score(int $post_id): array {
        $cached = get_transient('pylon_citeability_' . $post_id);
        if (false !== $cached) return $cached;

        $post = get_post($post_id);
        if (!$post) return ['score' => 0, 'grade' => 'low', 'breakdown' => []];

        $content = $post->post_content;
        $text = wp_strip_all_tags($content);
        $word_count = $this->count_words($text);

        $breakdown = [];
        $total = 0;

        // 1. Structure (0-30)
        $structure = $this->score_structure($content, $post_id);
        $breakdown['structure'] = $structure;
        $total += $structure['score'];

        // 2. Evidence (0-25)
        $evidence = $this->score_evidence($content, $post_id);
        $breakdown['evidence'] = $evidence;
        $total += $evidence['score'];

        // 3. Comprehensiveness (0-25)
        $comprehensiveness = $this->score_comprehensiveness($content, $post, $word_count);
        $breakdown['comprehensiveness'] = $comprehensiveness;
        $total += $comprehensiveness['score'];

        // 4. Authority (0-10)
        $authority = $this->score_authority($post);
        $breakdown['authority'] = $authority;
        $total += $authority['score'];

        // 5. Freshness (0-10)
        $freshness = $this->score_freshness($post);
        $breakdown['freshness'] = $freshness;
        $total += $freshness['score'];

        $total = min(100, max(0, $total));
        $grade = $total >= 80 ? 'high' : ($total >= 50 ? 'medium' : 'low');

        $result = [
            'score' => $total,
            'grade' => $grade,
            'breakdown' => $breakdown,
        ];

        set_transient('pylon_citeability_' . $post_id, $result, HOUR_IN_SECONDS);
        return $result;
    }

    private function score_structure(string $content, int $post_id): array {
        $score = 0;
        $details = [];

        $headings = preg_match_all('/<h[1-6][^>]*>/i', $content);
        $lists = preg_match_all('/<(ul|ol)[^>]*>/i', $content);
        $ordered = preg_match_all('/<ol[^>]*>/i', $content);
        $q_headings = preg_match_all('/<h[1-6][^>]*>\s*(?:what|why|how|when|where|which|who|does|is|are|can|should)\b/i', $content, $qw);
        $def_headings = preg_match_all('/<h[1-6][^>]*>\s*(?:what\s+is|definition|meaning)\b/i', $content, $dh);
        $blockquotes = preg_match_all('/<blockquote[^>]*>/i', $content, $bq);

        // Heading structure (0-5)
        if ($headings >= 5) { $score += 5; $details[] = 'Rich heading structure'; }
        elseif ($headings >= 3) { $score += 3; $details[] = 'Good headings'; }
        elseif ($headings >= 1) { $score += 1; $details[] = 'Has headings'; }

        // Lists (0-5)
        if ($lists >= 3) { $score += 5; $details[] = 'Multiple lists'; }
        elseif ($lists >= 1) { $score += 3; $details[] = 'Has lists'; }

        // Ordered steps (0-5)
        if ($ordered >= 2) { $score += 5; $details[] = 'Step-by-step structure'; }
        elseif ($ordered >= 1) { $score += 3; $details[] = 'Has numbered steps'; }

        // Q&A format (0-5)
        if ($qw[0] >= 3) { $score += 5; $details[] = 'Strong Q&A format'; }
        elseif ($qw[0] >= 1) { $score += 3; $details[] = 'Q&A format present'; }

        // Definitions (0-5)
        if ($dh[0] >= 2) { $score += 5; $details[] = 'Terms well defined'; }
        elseif ($dh[0] >= 1) { $score += 3; $details[] = 'Key terms defined'; }

        // Blockquotes as structure (0-5)
        if ($bq[0] >= 2) { $score += 5; $details[] = 'Structured quotes'; }
        elseif ($bq[0] >= 1) { $score += 2; $details[] = 'Has blockquotes'; }

        return ['score' => min(30, $score), 'details' => $details, 'max' => 30];
    }

    private function score_evidence(string $content, int $post_id): array {
        $score = 0;
        $details = [];

        // Statistics (0-10)
        preg_match_all('/\b\d+[%]|\b\d+%\b|\d+ out of \d+|\d+ in \d+|\b(?:study|research|survey|data|statistics?)\b/i', $content, $stats);
        $stat_count = count($stats[0]);
        if ($stat_count >= 5) { $score += 10; $details[] = 'Rich data/statistics'; }
        elseif ($stat_count >= 3) { $score += 7; $details[] = 'Multiple data points'; }
        elseif ($stat_count >= 1) { $score += 4; $details[] = 'Has statistics'; }

        // Expert quotes / attributions (0-10)
        preg_match_all('/<blockquote[^>]*>/i', $content, $bq);
        preg_match_all('/\b(?:according to|as noted by|as explained by|as reported by|research shows|studies show)\b/i', $content, $attr);
        preg_match_all('/"[^"]{30,}"|\'[^\']{30,}\'/u', $content, $long_quotes);
        $quote_count = count($bq[0]) + count($attr[0]) + count($long_quotes[0]);
        if ($quote_count >= 3) { $score += 10; $details[] = 'Strong expert attribution'; }
        elseif ($quote_count >= 1) { $score += 5; $details[] = 'Has expert quotes'; }

        // External references / links (0-5)
        preg_match_all('/<a[^>]+href=["\']https?:\/\//i', $content, $ext_links);
        $ext_link_count = count($ext_links[0]);
        if ($ext_link_count >= 5) { $score += 5; $details[] = 'Multiple external references'; }
        elseif ($ext_link_count >= 2) { $score += 3; $details[] = 'Has external links'; }
        elseif ($ext_link_count >= 1) { $score += 1; $details[] = 'Has external reference'; }

        return ['score' => min(25, $score), 'details' => $details, 'max' => 25];
    }

    private function score_comprehensiveness(string $content, \WP_Post $post, int $word_count): array {
        $score = 0;
        $details = [];

        // Word count depth (0-10)
        if ($word_count >= 3000) { $score += 10; $details[] = 'Very comprehensive (3000+ words)'; }
        elseif ($word_count >= 1500) { $score += 7; $details[] = 'Comprehensive (1500+ words)'; }
        elseif ($word_count >= 750) { $score += 5; $details[] = 'Good length (750+ words)'; }
        elseif ($word_count >= 300) { $score += 2; $details[] = 'Adequate length'; }

        // Multimedia (0-5)
        $images = preg_match_all('/<img[^>]+>/i', $content);
        $videos = preg_match_all('/<video[^>]*>|<iframe[^>]*(?:youtube|vimeo|youtu\.be)[^>]*>/i', $content);
        $total_media = $images + $videos;
        if ($total_media >= 5) { $score += 5; $details[] = 'Rich multimedia'; }
        elseif ($total_media >= 2) { $score += 3; $details[] = 'Has images/video'; }
        elseif ($total_media >= 1) { $score += 1; $details[] = 'Has media'; }

        // Topic coverage via schema (0-5)
        $schema = get_post_meta($post->ID, 'pylon_schema_type', true);
        if (in_array($schema, ['Article', 'BlogPosting', 'NewsArticle', 'HowTo', 'FAQPage'])) {
            $score += 5;
            $details[] = 'Rich schema markup';
        } elseif (!empty($schema)) {
            $score += 2;
            $details[] = 'Has schema';
        }

        // Topic breadth via headings (0-5)
        preg_match_all('/<h[1-6][^>]*>([^<]+)<\/h[1-6]>/i', $content, $h_texts);
        $topic_count = count(array_unique($h_texts[1]));
        if ($topic_count >= 8) { $score += 5; $details[] = 'Broad topic coverage'; }
        elseif ($topic_count >= 4) { $score += 3; $details[] = 'Multiple subtopics'; }
        elseif ($topic_count >= 1) { $score += 1; $details[] = 'Has subtopics'; }

        return ['score' => min(25, $score), 'details' => $details, 'max' => 25];
    }

    private function score_authority(\WP_Post $post): array {
        $score = 0;
        $details = [];
        $author_id = $post->post_author;

        // Author EEAT data (0-5)
        $has_photo = (bool) get_user_meta($author_id, 'pylon_author_photo', true);
        $has_bio = (bool) get_user_meta($author_id, 'pylon_author_bio_short', true);
        $has_job_title = (bool) get_user_meta($author_id, 'pylon_job_title', true);
        $has_credentials = (bool) get_user_meta($author_id, 'pylon_credentials', true);
        $has_knows = (bool) get_user_meta($author_id, 'pylon_knows_about', true);

        $eeat_count = (int) $has_photo + (int) $has_bio + (int) $has_job_title + (int) $has_credentials + (int) $has_knows;
        if ($eeat_count >= 4) { $score += 5; $details[] = 'Complete EEAT profile'; }
        elseif ($eeat_count >= 2) { $score += 3; $details[] = 'Partial EEAT profile'; }
        elseif ($eeat_count >= 1) { $score += 1; $details[] = 'Has EEAT data'; }

        // Has author schema output (0-3)
        if ($has_bio || $has_job_title) {
            $score += 3;
            $details[] = 'Author schema ready';
        }

        // Social presence (0-2)
        $same_as = get_user_meta($author_id, 'pylon_same_as', true);
        if (!empty(trim($same_as ?? ''))) {
            $score += 2;
            $details[] = 'Social profiles linked';
        }

        return ['score' => min(10, $score), 'details' => $details, 'max' => 10];
    }

    private function score_freshness(\WP_Post $post): array {
        $score = 0;
        $details = [];

        $freshness = (int) get_post_meta($post->ID, 'pylon_freshness_score', true);
        $modified = strtotime($post->post_modified);
        $days = (int) floor((current_time('timestamp') - $modified) / DAY_IN_SECONDS);

        // Freshness score (0-5)
        if ($freshness >= 90) { $score += 5; $details[] = 'Very fresh'; }
        elseif ($freshness >= 75) { $score += 3; $details[] = 'Recently fresh'; }
        elseif ($freshness >= 50) { $score += 1; $details[] = 'Moderately fresh'; }

        // Last updated recency (0-5)
        if ($days <= 30) { $score += 5; $details[] = 'Updated within 30 days'; }
        elseif ($days <= 90) { $score += 3; $details[] = 'Updated within 90 days'; }
        elseif ($days <= 180) { $score += 1; $details[] = 'Updated within 6 months'; }

        return ['score' => min(10, $score), 'details' => $details, 'max' => 10];
    }

    public function add_admin_column(array $columns): array {
        $columns['pylon_citeability'] = __('Citeability', 'pylon-seo');
        return $columns;
    }

    public function render_admin_column(string $column, int $post_id): void {
        if ($column !== 'pylon_citeability') return;
        $data = $this->get_score($post_id);
        $score = $data['score'];
        $grade = $data['grade'];
        $color = $grade === 'high' ? '#22c55e' : ($grade === 'medium' ? '#f59e0b' : '#ef4444');
        echo '<span style="font-weight:600;color:' . esc_attr($color) . '">' . (int) $score . '</span>';
        echo '<span style="font-size:10px;color:#9ca3af;margin-left:4px">/100</span>';
    }

    public function ajax_refresh(): void {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? '')), 'pylon_admin_nonce')) wp_send_json_error(['message' => __('Security check failed.', 'pylon-seo')]);
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => __('Unauthorized.', 'pylon-seo')]);
        $post_id = absint($_POST['post_id'] ?? 0);
        if ($post_id) {
            delete_transient('pylon_citeability_' . $post_id);
        }
        wp_send_json(['ok' => true]);
    }

    public function clear_cache_on_save(int $post_id, \WP_Post $post): void {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        delete_transient('pylon_citeability_' . $post_id);
    }

    public static function clear_cache(int $post_id): void {
        delete_transient('pylon_citeability_' . $post_id);
    }

    private function count_words(string $text): int {
        $text = trim($text);
        if ('' === $text) return 0;
        return preg_match_all('/\S+/u', $text, $m) ?: 0;
    }
}
