<?php
namespace Pylon\Core\Modules\Content;
defined('ABSPATH') || exit;
class SeoCheckerEngine {

    private \WP_Post $post;
    private array $meta = [];
    private string $title = '';
    private string $desc = '';
    private string $keyword = '';
    private string $slug = '';
    private string $content_text = '';
    private string $raw_content = '';
    private string $content_html = '';
    private int $word_count = 0;
    private array $headings = [];
    private int $heading_count = 0;
    private int $image_count = 0;
    private int $images_with_alt = 0;
    private array $image_alts = [];
    private int $internal_links = 0;
    private int $external_links = 0;
    private array $anchor_texts = [];
    private bool $has_list = false;
    private bool $has_table = false;
    private bool $has_video = false;
    private int $sentence_count = 0;
    private array $sentences = [];
    private int $h1_count = 0;
    private int $h2_count = 0;
    private int $h3_count = 0;
    private array $heading_levels = [];
    private array $paragraphs = [];

    private static array $power_words = [
        'amazing','beautiful','best','bonus','boost','breakthrough','conquer','create','crush',
        'discover','dominate','effective','epic','exclusive','exploit','extraordinary','fast','free',
        'guaranteed','hidden','hot','incredible','instant','ironclad','limited','luxury','massive',
        'miracle','must-have','new','now','proven','quick','rapid','revolutionary','save','secret',
        'shocking','simple','special','stunning','super','surge','target','tested','tremendous',
        'ultimate','unique','unlock','unlimited','valuable','victory','wonderful','zero','effortless',
        'flawless','powerful','premium','phenomenal','remarkable','sensational','spectacular',
        'staggering','striking','tried-and-tested','unbeatable','unforgettable','unparalleled',
        'unstoppable','vital','wildcard','winning','blueprint','cheat sheet','checklist',
        'case study','comparison','definitive','essential','fail-proof','foolproof','hack',
        'in-depth','insider','master','mega','myth-busting','nuts-and-bolts','step-by-step',
        'toolkit','tutorial','walkthrough','workshop','critical','essential','important',
        'mistake','avoid','warning','danger','alert','notice','attention',
    ];

    private static array $transition_words = [
        'additionally','after','afterwards','also','although','another','as a result',
        'because','before','besides','but','consequently','conversely','despite',
        'due to','equally','finally','first','firstly','following','for example',
        'for instance','further','furthermore','hence','however','in addition',
        'in conclusion','in contrast','in other words','in particular','in spite of',
        'lastly','likewise','meanwhile','moreover','nevertheless','next','nonetheless',
        'notably','on the contrary','on the other hand','overall','plus','rather',
        'regardless','second','secondly','similarly','since','so','specifically',
        'still','subsequently','such as','therefore','thus','ultimately','whereas',
        'while','yet',
    ];

    private static array $stop_words = [
        'the','a','an','and','or','but','in','on','at','to','for','of','with',
        'is','are','was','were','be','been','being','have','has','had','do','does',
        'did','will','would','could','should','may','might','can','shall','this',
        'that','these','those','it','its',
    ];

    public function __construct(\WP_Post $post) {
        $this->post = $post;
        $this->load_data();
    }

    private function load_data(): void {
        $id = $this->post->ID;
        $this->raw_content = $this->post->post_content;
        $this->meta = [
            'title'           => get_post_meta($id, 'pylon_title', true) ?: $this->post->post_title,
            'description'     => get_post_meta($id, 'pylon_description', true) ?: '',
            'keyword'         => get_post_meta($id, 'pylon_focus_keyword', true) ?: '',
            'canonical'       => get_post_meta($id, 'pylon_canonical', true) ?: '',
            'og_image'        => get_post_meta($id, 'pylon_og_image', true) ?: '',
            'schema_type'     => get_post_meta($id, 'pylon_schema_type', true) ?: '',
            'noindex'         => get_post_meta($id, 'pylon_noindex', true) ?: '',
            'nofollow'        => get_post_meta($id, 'pylon_nofollow', true) ?: '',
            'og_title'        => get_post_meta($id, 'pylon_og_title', true) ?: '',
            'og_description'  => get_post_meta($id, 'pylon_og_description', true) ?: '',
            'twitter_title'   => get_post_meta($id, 'pylon_twitter_title', true) ?: '',
            'twitter_desc'    => get_post_meta($id, 'pylon_twitter_description', true) ?: '',
        ];

        $this->title = $this->meta['title'];
        $this->desc  = $this->meta['description'];
        $this->keyword = strtolower(trim($this->meta['keyword']));
        $this->slug = $this->post->post_name;

        $this->resolve_content();
        $this->parse_content();
    }

    private function resolve_content(): void {
        // Shared tiered resolver: pro multi-engine when installed, otherwise the
        // free tiered resolver that understands page builders and custom templates.
        $cd = ContentScore::resolve_content_data($this->post);
        if (is_array($cd) && !empty($cd['text'])) {
            $this->content_text = $cd['text'];
            $this->word_count = $cd['word_count'] ?? 0;
            $this->heading_count = $cd['heading_count'] ?? 0;
            $this->image_count = $cd['image_count'] ?? 0;
            $this->has_list = $cd['has_list'] ?? false;
            $this->has_table = $cd['has_table'] ?? false;
            $this->content_html = ($cd['raw_html'] ?? '') ?: $this->raw_content;
            return;
        }
        $this->content_text = wp_strip_all_tags($this->raw_content);
        $this->content_html = $this->raw_content;
        $this->word_count = preg_match_all('/\p{L}+/u', $this->content_text);
        $this->heading_count = preg_match_all('/<h[1-6][^>]*>/i', $this->raw_content);
        $this->image_count = preg_match_all('/<img[^>]+>/i', $this->raw_content);
        $this->has_list = (bool) preg_match('/<[uo]l/i', $this->raw_content);
        $this->has_table = (bool) preg_match('/<table/i', $this->raw_content);
    }

    private function parse_content(): void {
        $html = $this->content_html ?: $this->raw_content;
        preg_match_all('/<h([1-6])[^>]*>(.*?)<\/h[1-6]>/is', $html, $hm, PREG_SET_ORDER);
        foreach ($hm as $m) {
            $level = (int) $m[1];
            $text = wp_strip_all_tags($m[2]);
            $this->headings[] = ['level' => $level, 'text' => $text];
            $this->heading_levels[] = $level;
            if ($level === 1) $this->h1_count++;
            if ($level === 2) $this->h2_count++;
            if ($level === 3) $this->h3_count++;
        }

        preg_match_all('/<img[^>]+>/i', $html, $imgs);
        foreach ($imgs[0] as $img) {
            preg_match('/alt=["\']([^"\']*)["\']/i', $img, $am);
            $alt = $am[1] ?? '';
            $this->image_alts[] = $alt;
            if (!empty(trim($alt))) $this->images_with_alt++;
        }

        $this->has_video = (bool) preg_match('/<iframe[^>]+(youtube|vimeo|dailymotion)|<video[^>]*>/i', $html);

        $this->extract_links();
        $this->split_sentences();
        $this->split_paragraphs();
    }

    private function extract_links(): void {
        $html = $this->content_html ?: $this->raw_content;
        preg_match_all('/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $links, PREG_SET_ORDER);
        $home = home_url();
        foreach ($links as $link) {
            $href = $link[1];
            $anchor = wp_strip_all_tags($link[2]);
            $this->anchor_texts[] = $anchor;
            if (strpos($href, $home) !== false || $href[0] === '/') {
                $this->internal_links++;
            } elseif (preg_match('/^https?:\/\//', $href)) {
                $this->external_links++;
            }
        }
    }

    private function split_sentences(): void {
        $text = $this->content_text;
        $this->sentences = preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $this->sentence_count = count($this->sentences);
    }

    private function split_paragraphs(): void {
        $raw = $this->content_html ?: $this->raw_content;
        preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $raw, $pm);
        $this->paragraphs = [];
        foreach ($pm[1] as $p) {
            $text = wp_strip_all_tags($p);
            $words = preg_match_all('/\p{L}+/u', $text);
            if ($words > 0) {
                $this->paragraphs[] = ['text' => $text, 'words' => $words];
            }
        }
    }

    public function run_all(): array {
        $checks = [];
        $checks = array_merge($checks, $this->check_basic_seo());
        $checks = array_merge($checks, $this->check_keyword_analysis());
        $checks = array_merge($checks, $this->check_content_structure());
        $checks = array_merge($checks, $this->check_title_readability());
        $checks = array_merge($checks, $this->check_content_readability());
        $checks = array_merge($checks, $this->check_technical_seo());
        $checks = array_merge($checks, $this->check_link_analysis());
        $checks = array_merge($checks, $this->check_media());
        $checks = array_merge($checks, $this->check_bonus());
        return $checks;
    }

    public function get_score(): int {
        $checks = $this->run_all();
        $total_weight = 0;
        $weighted_sum = 0;
        foreach ($checks as $c) {
            if (($c['weight'] ?? 0) <= 0 || ($c['status'] ?? '') === 'info') continue;
            $total_weight += $c['weight'];
            $score_val = $c['status'] === 'pass' ? 100 : ($c['status'] === 'warn' ? 50 : 0);
            $weighted_sum += $score_val * $c['weight'];
        }
        return $total_weight > 0 ? (int) round($weighted_sum / $total_weight) : 0;
    }

    public function get_score_by_tabs(): array {
        $checks = $this->run_all();
        $tabs = ['seo' => [], 'readability' => [], 'technical' => [], 'media' => []];
        foreach ($checks as $c) {
            $tab = $c['tab'] ?? 'seo';
            $tabs[$tab][] = $c;
        }
        $scores = [];
        foreach ($tabs as $tab => $items) {
            $tw = 0; $ws = 0;
            foreach ($items as $c) {
                if (($c['weight'] ?? 0) <= 0 || ($c['status'] ?? '') === 'info') continue;
                $tw += $c['weight'];
                $sv = $c['status'] === 'pass' ? 100 : ($c['status'] === 'warn' ? 50 : 0);
                $ws += $sv * $c['weight'];
            }
            $scores[$tab] = $tw > 0 ? (int) round($ws / $tw) : 0;
        }
        return ['checks' => $checks, 'scores' => $scores];
    }

    private function kw_in(string $haystack, string $needle = ''): bool {
        $haystack = strtolower($haystack);
        $kw_list = $this->get_keyword_list();
        foreach ($kw_list as $kw) {
            if ($kw !== '' && mb_stripos($haystack, $kw) !== false) return true;
        }
        return false;
    }

    private function kw_count_in(string $text): int {
        $kw_list = $this->get_keyword_list();
        if (empty($kw_list)) return 0;
        $text = strtolower($text);
        $count = 0;
        foreach ($kw_list as $kw) {
            if ($kw !== '') $count += substr_count($text, $kw);
        }
        return $count;
    }

    private function get_keyword_list(): array {
        return array_filter(array_map('trim', explode(',', $this->keyword)));
    }

    private function has_kw(): bool {
        return $this->keyword !== '';
    }

    private function check(string $id, string $label, bool $pass, string $value = '', string $suggestion = '', int $weight = 1, string $tab = 'seo', string $status_override = ''): array {
        $status = $status_override ?: ($pass ? 'pass' : 'fail');
        return compact('id', 'label', 'status', 'value', 'suggestion', 'weight', 'tab');
    }

    private function check_basic_seo(): array {
        $kw = $this->keyword;
        $title = $this->title;
        $desc = $this->desc;
        $slug = $this->slug;
        $content_lower = strtolower($this->content_text);
        $first_100 = preg_match_all('/\p{L}+/u', $this->content_text, $m) ? implode(' ', array_slice($m[0], 0, 100)) : '';

        return [
            $this->check('focus_keyword_set', __('Focus keyword set', 'pylon-seo'), $this->has_kw(), $kw ?: '—', __('Enter a focus keyword to optimize your content.', 'pylon-seo'), 5, 'seo'),
            $this->check('kw_in_seo_title', __('Keyword in SEO title', 'pylon-seo'), $this->kw_in($title), $kw, __('Add your keyword to the SEO title for better rankings.', 'pylon-seo'), 5, 'seo'),
            $this->check('kw_in_meta_desc', __('Keyword in meta description', 'pylon-seo'), $this->kw_in($desc), $kw, __('Include your keyword in the meta description.', 'pylon-seo'), 4, 'seo'),
            $this->check('kw_in_url_slug', __('Keyword in URL slug', 'pylon-seo'), $this->kw_in($slug), $kw, __('Add your keyword to the URL slug.', 'pylon-seo'), 4, 'seo'),
            $this->check('kw_in_first_para', __('Keyword in first 100 words', 'pylon-seo'), $this->kw_in($first_100), $kw, __('Mention your keyword early in the content for SEO relevance.', 'pylon-seo'), 3, 'seo'),
            $this->check('kw_in_subheadings', __('Keyword in subheadings', 'pylon-seo'), $this->kw_in_subheadings(), $kw, __('Include your keyword in at least one H2/H3 subheading.', 'pylon-seo'), 3, 'seo'),
            $this->check('kw_in_content', __('Keyword in content body', 'pylon-seo'), $this->has_kw() && $this->kw_count_in($content_lower) >= 2, $this->kw_count_in($content_lower) . ' mentions', __('Use your keyword at least 2-3 times naturally in the content.', 'pylon-seo'), 4, 'seo'),
            $this->check('seo_title_length', __('SEO title length', 'pylon-seo'), $this->title_len_ok(), mb_strlen($title) . ' chars', __('Keep your SEO title between 30-60 characters for optimal SERP display.', 'pylon-seo'), 4, 'seo'),
            $this->check('meta_desc_length', __('Meta description length', 'pylon-seo'), $this->desc_len_ok(), mb_strlen($desc) . ' chars', __('Keep your meta description between 120-160 characters.', 'pylon-seo'), 4, 'seo'),
            $this->check('seo_title_unique', __('SEO title differs from post title', 'pylon-seo'), !$this->has_kw() || $this->title !== $this->post->post_title, $this->title === $this->post->post_title ? __('Same as post title', 'pylon-seo') : __('Different', 'pylon-seo'), __('Customize your SEO title to differentiate it from the post title.', 'pylon-seo'), 2, 'seo'),
        ];
    }

    private function check_keyword_analysis(): array {
        $kw = $this->keyword;
        $content_lower = strtolower($this->content_text);
        $density = $this->word_count > 0 ? ($this->kw_count_in($content_lower) / $this->word_count) * 100 : 0;
        $density_str = sprintf('%.1f%%', $density);
        $density_ok = $density >= 0.5 && $density <= 2.5;
        $density_warn = $density > 2.5 && $density <= 3.0;
        $density_status = $density_ok ? 'pass' : ($density_warn ? 'warn' : 'fail');

        $alts_with_kw = 0;
        foreach ($this->image_alts as $alt) {
            if ($this->kw_in(strtolower($alt))) $alts_with_kw++;
        }

        return [
            $this->check('kw_density', __('Keyword density', 'pylon-seo'), $density_ok, $density_str, !$this->has_kw() ? '' : ($density < 0.5 ? __('Keyword density is too low. Aim for 0.5-2.5%.', 'pylon-seo') : ($density > 2.5 ? __('Keyword density is too high. Reduce keyword usage to avoid stuffing.', 'pylon-seo') : '')), 3, 'seo', $this->has_kw() ? $density_status : 'info'),
            $this->check('kw_in_image_alts', __('Keyword in image alt text', 'pylon-seo'), $this->image_count === 0 || $alts_with_kw > 0, $alts_with_kw . '/' . $this->image_count, __('Add your keyword to at least one image alt text.', 'pylon-seo'), 2, 'seo'),
            $this->check('kw_in_h2', __('Keyword in H2 heading', 'pylon-seo'), $this->kw_in_h_level(2), $kw, __('Include your keyword in at least one H2 subheading.', 'pylon-seo'), 3, 'seo'),
            $this->check('kw_in_h3', __('Keyword in H3 heading', 'pylon-seo'), $this->h3_count === 0 || $this->kw_in_h_level(3), $kw, __('Include your keyword in an H3 subheading if you use them.', 'pylon-seo'), 2, 'seo'),
            $this->check('kw_not_overused', __('Keyword not overused', 'pylon-seo'), $density <= 3.0, $density_str, __('Reduce keyword stuffing. Use synonyms or related terms instead.', 'pylon-seo'), 3, 'seo'),
        ];
    }

    private function check_content_structure(): array {
        $heading_gap = $this->max_heading_gap();
        return [
            $this->check('word_count_300', __('Content has 300+ words', 'pylon-seo'), $this->word_count >= 300, $this->word_count . ' words', __('Write at least 300 words for adequate content depth.', 'pylon-seo'), 5, 'seo'),
            $this->check('word_count_1000', __('Content has 1000+ words', 'pylon-seo'), $this->word_count >= 1000, $this->word_count . ' words', __('Longer content (1000+ words) tends to rank better.', 'pylon-seo'), 2, 'seo'),
            $this->check('single_h1', __('Only one H1 tag', 'pylon-seo'), $this->h1_count === 1, $this->h1_count . ' H1', __('Use exactly one H1 heading per page.', 'pylon-seo'), 4, 'seo'),
            $this->check('heading_hierarchy', __('Heading hierarchy is valid', 'pylon-seo'), $this->heading_hierarchy_valid(), '', __('Maintain proper heading order: H2 before H3, H3 before H4.', 'pylon-seo'), 3, 'seo'),
            $this->check('subheading_distribution', __('Subheadings distributed evenly', 'pylon-seo'), $heading_gap <= 300, $heading_gap . ' words max gap', __('Add subheadings every 200-300 words to break up content.', 'pylon-seo'), 2, 'seo'),
            $this->check('has_lists_tables', __('Content has lists or tables', 'pylon-seo'), $this->has_list || $this->has_table, ($this->has_list ? 'Lists' : '') . ($this->has_table ? ' Tables' : '') ?: '—', __('Use bullet points or tables to make content scannable.', 'pylon-seo'), 2, 'seo'),
            $this->check('paragraph_length', __('Paragraphs are not too long', 'pylon-seo'), $this->max_paragraph_words() <= 150, $this->max_paragraph_words() . ' words max', __('Keep paragraphs under 150 words for readability.', 'pylon-seo'), 2, 'seo'),
        ];
    }

    private function check_title_readability(): array {
        $title_lower = strtolower($this->title);
        $has_power = $this->title_has_power_word();
        $has_number = (bool) preg_match('/\d/', $this->title);
        $has_bracket = (bool) preg_match('/[\(\)\[\]\{\}]| \|{2}/', $this->title);

        return [
            $this->check('title_power_words', __('Title has power words', 'pylon-seo'), $has_power, $has_power ? __('Found', 'pylon-seo') : '—', __('Add power words like "Ultimate", "Proven", "Essential" to boost CTR.', 'pylon-seo'), 2, 'readability'),
            $this->check('title_has_number', __('Title contains a number', 'pylon-seo'), $has_number, $has_number ? __('Found', 'pylon-seo') : '—', __('Numbers in titles increase click-through rates.', 'pylon-seo'), 1, 'readability'),
            $this->check('title_has_bracket', __('Title contains brackets or parentheses', 'pylon-seo'), $has_bracket, $has_bracket ? __('Found', 'pylon-seo') : '—', __('Brackets in titles can increase CTR by attracting attention.', 'pylon-seo'), 1, 'readability'),
        ];
    }

    private function check_content_readability(): array {
        $analysis = $this->analyze_readability();

        return [
            /* translators: %s: current readability grade level. */
            $this->check('flesch_reading_ease', __('Flesch Reading Ease', 'pylon-seo'), $analysis['flesch'] >= 60, $analysis['flesch'] . ' (' . $analysis['flesch_label'] . ')', sprintf(__('Simplify your language. Use shorter words and sentences. Current grade level: %s', 'pylon-seo'), $analysis['grade']), 4, 'readability'),
            $this->check('passive_voice', __('Passive voice < 10%', 'pylon-seo'), $analysis['passive_pct'] < 10, $analysis['passive_pct'] . '% (' . $analysis['passive_count'] . '/' . $analysis['sentence_count'] . ')', __('Rewrite passive sentences in active voice for clearer writing.', 'pylon-seo'), 3, 'readability'),
            $this->check('transition_words', __('Transition words >= 30%', 'pylon-seo'), $analysis['transition_pct'] >= 30, $analysis['transition_pct'] . '%', __('Add transition words (however, moreover, therefore) to connect ideas.', 'pylon-seo'), 2, 'readability'),
            $this->check('consecutive_sentences', __('No 4+ sentences start with same word', 'pylon-seo'), $analysis['max_consecutive'] <= 3, $analysis['max_consecutive'] . ' consecutive', __('Vary your sentence openings. Avoid starting multiple sentences with the same word.', 'pylon-seo'), 3, 'readability'),
            $this->check('sentence_length', __('Avg sentence length <= 20 words', 'pylon-seo'), $analysis['avg_sentence_len'] <= 20, $analysis['avg_sentence_len'] . ' words', __('Break long sentences into shorter ones. Aim for 15-20 words per sentence.', 'pylon-seo'), 3, 'readability'),
            $this->check('long_sentences', __('No sentence exceeds 40 words', 'pylon-seo'), $analysis['longest'] <= 40, $analysis['longest'] . ' words max', __('Split very long sentences to improve readability.', 'pylon-seo'), 2, 'readability'),
            $this->check('paragraph_length_avg', __('Avg paragraph < 100 words', 'pylon-seo'), $analysis['avg_para_len'] < 100, $analysis['avg_para_len'] . ' words', __('Keep paragraphs short. Aim for 3-4 sentences per paragraph.', 'pylon-seo'), 2, 'readability'),
        ];
    }

    private function check_technical_seo(): array {
        $og_ok = !empty($this->meta['og_title']) || !empty($this->meta['og_description']) || !empty($this->meta['og_image']);
        $twitter_ok = !empty($this->meta['twitter_title']) || !empty($this->meta['twitter_desc']);
        $schema_ok = !empty($this->meta['schema_type']);
        $canonical_ok = !empty($this->meta['canonical']);

        return [
            $this->check('schema_type', __('Schema type selected', 'pylon-seo'), $schema_ok, $this->meta['schema_type'] ?: '—', __('Select a schema type to enable structured data.', 'pylon-seo'), 4, 'technical'),
            $this->check('canonical_set', __('Canonical URL is set', 'pylon-seo'), $canonical_ok, $canonical_ok ? __('Set', 'pylon-seo') : '—', __('Set a canonical URL to prevent duplicate content issues.', 'pylon-seo'), 3, 'technical'),
            $this->check('og_tags', __('Open Graph tags present', 'pylon-seo'), $og_ok, $og_ok ? __('Set', 'pylon-seo') : '—', __('Set Open Graph tags for better social media sharing.', 'pylon-seo'), 3, 'technical'),
            $this->check('og_image', __('OG image is set', 'pylon-seo'), !empty($this->meta['og_image']), $this->meta['og_image'] ? __('Set', 'pylon-seo') : '—', __('Add an OG image for social media previews.', 'pylon-seo'), 3, 'technical'),
            $this->check('twitter_card', __('Twitter card tags present', 'pylon-seo'), $twitter_ok, $twitter_ok ? __('Set', 'pylon-seo') : '—', __('Set Twitter card tags for X/Twitter sharing.', 'pylon-seo'), 2, 'technical'),
            $this->check('meta_robots', __('Meta robots configured', 'pylon-seo'), true, $this->meta['noindex'] ? 'noindex' : __('index', 'pylon-seo'), '', 2, 'technical'),
        ];
    }

    private function check_link_analysis(): array {
        $kw_in_anchors = false;
        foreach ($this->anchor_texts as $a) {
            if ($this->kw_in(strtolower($a))) { $kw_in_anchors = true; break; }
        }

        return [
            $this->check('has_internal_links', __('Has internal links', 'pylon-seo'), $this->internal_links >= 1, $this->internal_links . ' links', __('Add at least 1 internal link to related content.', 'pylon-seo'), 4, 'technical'),
            $this->check('has_external_links', __('Has external links', 'pylon-seo'), $this->external_links >= 1, $this->external_links . ' links', __('Add at least 1 external link to authoritative sources.', 'pylon-seo'), 2, 'technical'),
            $this->check('kw_in_anchor', __('Keyword in anchor text', 'pylon-seo'), !$this->has_kw() || $kw_in_anchors, $kw_in_anchors ? __('Found', 'pylon-seo') : '—', __('Use your keyword in the anchor text of at least one link.', 'pylon-seo'), 2, 'technical'),
        ];
    }

    private function check_media(): array {
        $alt_pct = $this->image_count > 0 ? round(($this->images_with_alt / $this->image_count) * 100) : 0;

        return [
            $this->check('has_images', __('Content has images', 'pylon-seo'), $this->image_count >= 1, $this->image_count . ' images', __('Add at least one image to your content.', 'pylon-seo'), 3, 'media'),
            $this->check('image_alt_coverage', __('All images have alt text', 'pylon-seo'), $this->image_count === 0 || $alt_pct === 100, $this->images_with_alt . '/' . $this->image_count . ' (' . $alt_pct . '%)', __('Add descriptive alt text to all images for accessibility and SEO.', 'pylon-seo'), 4, 'media'),
            $this->check('has_video', __('Content has video', 'pylon-seo'), $this->has_video, $this->has_video ? __('Found', 'pylon-seo') : '—', __('Adding video can increase engagement and time on page.', 'pylon-seo'), 0, 'media'),
        ];
    }

    private function check_bonus(): array {
        $slug_len = mb_strlen($this->slug);
        $has_stop = false;
        $slug_words = explode('-', $this->slug);
        foreach ($slug_words as $w) {
            if (in_array($w, self::$stop_words, true)) { $has_stop = true; break; }
        }

        return [
            $this->check('slug_length', __('URL slug <= 75 characters', 'pylon-seo'), $slug_len <= 75, $slug_len . ' chars', __('Shorter URLs are better for SEO and sharing.', 'pylon-seo'), 1, 'seo'),
            $this->check('slug_no_stop', __('URL slug has no stop words', 'pylon-seo'), !$has_stop || $slug_len <= 10, $has_stop ? __('Stop words found', 'pylon-seo') : '—', __('Remove common stop words from your URL slug.', 'pylon-seo'), 1, 'seo'),
        ];
    }

    private function kw_in_subheadings(): bool {
        if (!$this->has_kw()) return false;
        foreach ($this->headings as $h) {
            if ($h['level'] >= 2 && $this->kw_in($h['text'])) return true;
        }
        return false;
    }

    private function kw_in_h_level(int $level): bool {
        if (!$this->has_kw()) return false;
        foreach ($this->headings as $h) {
            if ($h['level'] === $level && $this->kw_in($h['text'])) return true;
        }
        return false;
    }

    private function title_len_ok(): bool {
        $len = mb_strlen($this->title);
        return $len >= 30 && $len <= 60;
    }

    private function desc_len_ok(): bool {
        $len = mb_strlen($this->desc);
        return $len >= 120 && $len <= 160;
    }

    private function heading_hierarchy_valid(): bool {
        if (empty($this->heading_levels)) return true;
        for ($i = 1; $i < count($this->heading_levels); $i++) {
            if ($this->heading_levels[$i] > $this->heading_levels[$i - 1] + 1) return false;
        }
        return true;
    }

    private function max_heading_gap(): int {
        if ($this->word_count <= 300 || empty($this->headings)) return 0;
        $word_positions = [];
        $words = preg_split('/\s+/', $this->content_text, -1, PREG_SPLIT_NO_EMPTY);
        $word_positions[] = 0;
        $pos = 0;
        $heading_texts = array_map(function($h) { return strtolower($h['text']); }, $this->headings);
        foreach ($words as $i => $w) {
            $lw = strtolower($w);
            foreach ($heading_texts as $ht) {
                if (strpos($ht, $lw) !== false) {
                    $word_positions[] = $i;
                    break;
                }
            }
        }
        if (count($word_positions) < 2) return $this->word_count;
        $max_gap = 0;
        for ($i = 1; $i < count($word_positions); $i++) {
            $gap = $word_positions[$i] - $word_positions[$i - 1];
            if ($gap > $max_gap) $max_gap = $gap;
        }
        return $max_gap;
    }

    private function max_paragraph_words(): int {
        $max = 0;
        foreach ($this->paragraphs as $p) {
            if ($p['words'] > $max) $max = $p['words'];
        }
        return $max;
    }

    private function title_has_power_word(): bool {
        $title_lower = strtolower($this->title);
        foreach (self::$power_words as $pw) {
            if (strpos($title_lower, $pw) !== false) return true;
        }
        return false;
    }

    private function analyze_readability(): array {
        $text = $this->content_text;
        $sentences = $this->sentences;
        $sentence_count = max(1, $this->sentence_count);

        $total_words = $this->word_count;
        $total_syllables = $this->count_syllables($text);
        $flesch = max(0, min(100, round(206.835 - 1.015 * ($total_words / $sentence_count) - 84.6 * ($total_syllables / max(1, $total_words)))));
        $grade = max(0, round(0.39 * ($total_words / $sentence_count) + 11.8 * ($total_syllables / max(1, $total_words)) - 15.59));

        if ($flesch >= 90) $flesch_label = 'Very Easy';
        elseif ($flesch >= 80) $flesch_label = 'Easy';
        elseif ($flesch >= 70) $flesch_label = 'Fairly Easy';
        elseif ($flesch >= 60) $flesch_label = 'Standard';
        elseif ($flesch >= 50) $flesch_label = 'Fairly Hard';
        elseif ($flesch >= 30) $flesch_label = 'Hard';
        else $flesch_label = 'Very Hard';

        $passive_count = 0;
        foreach ($sentences as $s) {
            if ($this->is_passive($s)) $passive_count++;
        }
        $passive_pct = $sentence_count > 0 ? (int) round(($passive_count / $sentence_count) * 100) : 0;

        $transition_count = 0;
        $trans_lower = array_map('strtolower', self::$transition_words);
        foreach ($sentences as $s) {
            $sl = strtolower($s);
            foreach ($trans_lower as $tw) {
                if (strpos($sl, $tw) !== false) { $transition_count++; break; }
            }
        }
        $transition_pct = $sentence_count > 0 ? (int) round(($transition_count / $sentence_count) * 100) : 0;

        $max_consecutive = $this->get_consecutive_starts($sentences);

        $total_len = 0;
        foreach ($sentences as $s) {
            $total_len += preg_match_all('/\p{L}+/u', $s);
        }
        $avg_sentence_len = $sentence_count > 0 ? (int) round($total_len / $sentence_count) : 0;

        $longest = 0;
        foreach ($sentences as $s) {
            $wc = preg_match_all('/\p{L}+/u', $s);
            if ($wc > $longest) $longest = $wc;
        }

        $avg_para_len = 0;
        if (!empty($this->paragraphs)) {
            $total_para_words = 0;
            foreach ($this->paragraphs as $p) $total_para_words += $p['words'];
            $avg_para_len = (int) round($total_para_words / count($this->paragraphs));
        }

        return compact('flesch', 'flesch_label', 'grade', 'passive_count', 'passive_pct', 'transition_pct', 'max_consecutive', 'avg_sentence_len', 'longest', 'avg_para_len', 'sentence_count');
    }

    private function is_passive(string $sentence): bool {
        return (bool) preg_match('/\b(?:am|is|are|was|were|be|been|being)\s+(\w+ed|written|built|brought|bought|caught|chosen|driven|eaten|given|grown|hidden|kept|known|led|left|lost|made|meant|paid|proven|put|read|said|sold|sent|shown|spoken|struck|taken|taught|thought|told|won|understood|drawn|begun|broken|done|forgotten|found|gone|heard|held|hurt|laid|lent|let|lit|overcome|ridden|risen|run|seen|sought|shaken|shut|slept|slid|spent|sung|sunk|swum|torn|thrown|worn|woven)\b/i', $sentence);
    }

    private function get_consecutive_starts(array $sentences): int {
        $max = 1;
        $current = 1;
        $prev_start = '';
        foreach ($sentences as $s) {
            $words = preg_split('/\s+/', trim($s), -1, PREG_SPLIT_NO_EMPTY);
            $start = strtolower($words[0] ?? '');
            if ($start === $prev_start && $start !== '') {
                $current++;
                if ($current > $max) $max = $current;
            } else {
                $current = 1;
            }
            $prev_start = $start;
        }
        return $max;
    }

    private function count_syllables(string $text): int {
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $total = 0;
        foreach ($words as $word) {
            $word = preg_replace('/[^a-zA-Z]/', '', strtolower($word));
            if (strlen($word) <= 3) { $total++; continue; }
            preg_match_all('/[aeiouy]+/', $word, $vowels);
            $count = max(1, count($vowels[0]));
            if (substr($word, -2) === 'le' && strlen($word) > 3 && $count > 1) {
                $before_le = substr($word, -3, 1);
                if (strpos('aeiou', $before_le) !== false) {
                    $count--;
                }
            } elseif (substr($word, -1) === 'e' && $count > 1) {
                $count--;
            }
            $total += $count;
        }
        return $total;
    }

    public static function get_power_words(): array {
        return self::$power_words;
    }

    public function get_highlight_issues(): array {
        $issues = [];
        $sentences = $this->sentences;
        $paragraphs = $this->paragraphs;
        $idx = 0;

        foreach ($sentences as $i => $s) {
            if ($this->is_passive($s)) {
                $issues[] = [
                    'id' => 'hi_' . $idx++,
                    'type' => 'passive_voice',
                    'text' => mb_strlen($s) > 120 ? mb_substr($s, 0, 120) . '...' : $s,
                    'full_text' => $s,
                    'severity' => 'fail',
                    'check_id' => 'passive_voice',
                    'label' => __('Passive voice detected', 'pylon-seo'),
                    'icon' => '🔴',
                ];
            }
            $wc = preg_match_all('/\p{L}+/u', $s);
            if ($wc > 30) {
                $issues[] = [
                    'id' => 'hi_' . $idx++,
                    'type' => 'long_sentence',
                    'text' => mb_strlen($s) > 120 ? mb_substr($s, 0, 120) . '...' : $s,
                    'full_text' => $s,
                    'severity' => 'warn',
                    'check_id' => 'long_sentences',
                    /* translators: %d: number of words in the sentence. */
                    'label' => sprintf(__('Sentence too long (%d words)', 'pylon-seo'), $wc),
                    'icon' => '🟠',
                ];
            }
        }

        foreach ($paragraphs as $p) {
            if ($p['words'] > 150) {
                $issues[] = [
                    'id' => 'hi_' . $idx++,
                    'type' => 'long_paragraph',
                    'text' => mb_substr($p['text'], 0, 120) . '...',
                    'full_text' => $p['text'],
                    'severity' => 'warn',
                    'check_id' => 'paragraph_length',
                    /* translators: %d: number of words in the paragraph. */
                    'label' => sprintf(__('Paragraph too long (%d words)', 'pylon-seo'), $p['words']),
                    'icon' => '🟠',
                ];
            }
        }

        $consecutive = $this->get_consecutive_starts($sentences);
        if ($consecutive >= 4) {
            $start_word = '';
            foreach ($sentences as $s) {
                $words = preg_split('/\s+/', trim($s), -1, PREG_SPLIT_NO_EMPTY);
                $start_word = strtolower($words[0] ?? '');
                if ($start_word !== '') break;
            }
            $issues[] = [
                'id' => 'hi_' . $idx++,
                'type' => 'consecutive_start',
                /* translators: 1: starting word of the sentences, 2: number of consecutive occurrences. */
                'text' => sprintf(__('"%1$s" used %2$d times consecutively', 'pylon-seo'), ucfirst($start_word), $consecutive),
                'full_text' => '',
                'severity' => 'warn',
                'check_id' => 'consecutive_sentences',
                /* translators: %s: starting word of the sentences. */
                'label' => sprintf(__('Consecutive sentences start with "%s"', 'pylon-seo'), ucfirst($start_word)),
                'icon' => '🟡',
            ];
        }

        return $issues;
    }
}
