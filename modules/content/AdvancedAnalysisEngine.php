<?php
namespace Pylon\Core\Modules\Content;
defined('ABSPATH') || exit;
class AdvancedAnalysisEngine {

    private \WP_Post $post;
    private string $content_text = '';
    private string $raw_content = '';
    private string $content_html = '';
    private int $word_count = 0;
    private array $headings = [];
    private array $internal_links = [];
    private array $external_links = [];
    private array $images = [];
    private int $image_count = 0;
    private int $images_with_alt = 0;
    private bool $has_schema = false;
    private string $schema_type = '';
    private bool $has_video = false;
    private bool $has_table = false;
    private bool $has_list = false;
    private int $h2_count = 0;
    private int $h3_count = 0;
    private int $sentence_count = 0;
    private array $paragraphs = [];
    private array $sentences = [];
    private string $post_type = '';
    private int $author_id = 0;

    public function __construct(\WP_Post $post) {
        $this->post = $post;
        $this->author_id = (int) $post->post_author;
        $this->post_type = $post->post_type;
        $this->load_data();
    }

    private function load_data(): void {
        $this->raw_content = $this->post->post_content;

        $cd = null;
        if (class_exists('\Pylon\Core\Modules\MultiEngineScore\MultiEngineScore')) {
            $multi = new \Pylon\Core\Modules\MultiEngineScore\MultiEngineScore();
            $cd = $multi->resolve_content_data($this->post);
        }
        if ($cd && !empty($cd['text'])) {
            $this->content_text = $cd['text'];
            $this->word_count = $cd['word_count'] ?? preg_match_all('/\p{L}+/u', $cd['text']);
            $this->image_count = $cd['image_count'] ?? 0;
            $this->has_list = $cd['has_list'] ?? false;
            $this->has_table = $cd['has_table'] ?? false;
            $this->content_html = ($cd['raw_html'] ?? '') ?: $this->raw_content;
        } else {
            $this->content_text = wp_strip_all_tags($this->raw_content);
            $this->content_html = $this->raw_content;
            $this->word_count = preg_match_all('/\p{L}+/u', $this->content_text);
            $this->image_count = preg_match_all('/<img[^>]+>/i', $this->raw_content);
            $this->has_table = (bool) preg_match('/<table/i', $this->raw_content);
            $this->has_list = (bool) preg_match('/<[uo]l/i', $this->raw_content);
        }

        $this->headings = $this->parse_headings();
        $this->h2_count = count(array_filter($this->headings, fn($h) => $h['level'] === 2));
        $this->h3_count = count(array_filter($this->headings, fn($h) => $h['level'] === 3));
        $this->has_video = (bool) preg_match('/<iframe[^>]+(youtube|vimeo|dailymotion)|<video[^>]+/i', $this->content_html ?: $this->raw_content);
        $this->has_schema = !empty(get_post_meta($this->post->ID, 'pylon_schema_type', true));
        $this->schema_type = get_post_meta($this->post->ID, 'pylon_schema_type', true) ?: '';
        $this->parse_links();
        $this->parse_images();
        $this->paragraphs = $this->parse_paragraphs();
        $this->sentences = $this->parse_sentences();
        $this->sentence_count = count($this->sentences);
    }

    public function get_score_by_tabs(): array {
        $eeat = $this->check_eeat();
        $topical = $this->check_topical_authority();
        $uniqueness = $this->check_content_uniqueness();

        $all = array_merge($eeat, $topical, $uniqueness);
        $tabs = ['eeat' => [], 'topical' => [], 'uniqueness' => []];
        foreach ($all as $c) {
            $tab = $c['tab'] ?? 'eeat';
            $tabs[$tab][] = $c;
        }
        $scores = [];
        foreach ($tabs as $tab => $items) {
            $tw = 0; $ws = 0;
            foreach ($items as $c) {
                if (($c['weight'] ?? 0) <= 0) continue;
                $tw += $c['weight'];
                $sv = $c['status'] === 'pass' ? 100 : ($c['status'] === 'warn' ? 50 : 0);
                $ws += $sv * $c['weight'];
            }
            $scores[$tab] = $tw > 0 ? (int) round($ws / $tw) : 0;
        }
        return ['checks' => $all, 'scores' => $scores];
    }

    public function get_score(): int {
        $data = $this->get_score_by_tabs();
        $total = 0; $count = 0;
        foreach ($data['scores'] as $s) { $total += $s; $count++; }
        return $count > 0 ? (int) round($total / $count) : 0;
    }

    // ─── E-E-A-T Checks ───
    private function check_eeat(): array {
        $author = get_userdata($this->author_id);
        $author_name = $author ? $author->display_name : '';
        $author_bio = $author ? ($author->description ?: '') : '';
        $author_url = $author ? ($author->user_url ?: '') : '';
        $post_date = $this->post->post_date;
        $post_modified = $this->post->post_modified;
        $content_lower = strtolower($this->content_text);

        $has_author = !empty($author_name);
        $has_bio = count(preg_split('/\s+/', trim($author_bio))) > 50;
        $has_author_url = !empty($author_url);
        $has_date = !empty($post_date);
        $recently_updated = strtotime($post_modified) > strtotime('-1 year');

        $personal_pronouns = preg_match_all('/\b(I|my|me|we|our|us|myself|ourselves)\b/i', $this->content_text);
        $has_personal_voice = $personal_pronouns >= 3;

        $data_patterns = ['\d+%', '\d{4}', '\$\d+', '\d+\.\d+'];
        $data_count = 0;
        foreach ($data_patterns as $p) {
            $data_count += preg_match_all('/' . $p . '/i', $this->content_text);
        }
        $text_patterns = ['according to', 'study', 'research', 'statistics', 'survey', 'report'];
        foreach ($text_patterns as $p) {
            $data_count += preg_match_all('/\b' . $p . '\b/i', $this->content_text);
        }
        $has_data_citations = $data_count >= 3;

        $quotes = preg_match_all('/[\x{201c}\x{201d}\x{2018}\x{2019}"].*?[\x{201c}\x{201d}\x{2018}\x{2019}"]/u', $this->content_text);
        $has_quotes = $quotes >= 1;

        $has_https = is_ssl() || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') || stripos(home_url(), 'https://') === 0;
        $has_contact = (bool) get_page_by_path('contact') || (bool) get_page_by_path('contact-us') || (bool) get_page_by_path('contact-me');
        $has_about = (bool) get_page_by_path('about') || (bool) get_page_by_path('about-us') || (bool) get_page_by_path('about-me');
        $has_privacy = (bool) get_page_by_path('privacy-policy') || (bool) get_page_by_path('privacy');

        $first_person_count = $personal_pronouns;
        $has_first_person = $first_person_count >= 5;

        $exp_terms = ['experience', 'years', 'tested', 'tried', 'used', 'personally', 'in my', 'from my', 'i have', 'i\'ve', 'i was', 'i found'];
        $exp_count = 0;
        foreach ($exp_terms as $t) {
            $exp_count += substr_count($content_lower, $t);
        }
        $has_experience = $exp_count >= 3;

        $expert_terms = ['research shows', 'studies indicate', 'according to', 'published in', 'journal', 'evidence', 'peer-reviewed', 'findings', 'analysis'];
        $expert_count = 0;
        foreach ($expert_terms as $t) {
            $expert_count += substr_count($content_lower, $t);
        }
        $has_expertise = $expert_count >= 2;

        return [
            $this->check('eat_author_name', __('Author name is set', 'pylon-seo'), $has_author, $author_name ?: '—', __('Add an author name to the user profile for trust signals.', 'pylon-seo'), 3, 'eeat'),
            $this->check('eat_author_bio', __('Author bio is present (50+ words)', 'pylon-seo'), $has_bio, mb_strlen($author_bio) . ' words', __('Write a detailed author bio to establish expertise.', 'pylon-seo'), 3, 'eeat'),
            $this->check('eat_author_url', __('Author has a website URL', 'pylon-seo'), $has_author_url, $has_author_url ? __('Set', 'pylon-seo') : '—', __('Add a personal website URL to the author profile.', 'pylon-seo'), 1, 'eeat'),
            $this->check('eat_post_date', __('Post date is displayed', 'pylon-seo'), $has_date, $has_date ? gmdate('M j, Y', strtotime($post_date)) : '—', __('Ensure the publication date is visible for freshness signals.', 'pylon-seo'), 2, 'eeat'),
            $this->check('eat_recently_updated', __('Content updated within 1 year', 'pylon-seo'), $recently_updated, gmdate('M j, Y', strtotime($post_modified)), __('Update old content to maintain freshness and relevance.', 'pylon-seo'), 2, 'eeat'),
            $this->check('eat_personal_voice', __('First-person perspective used', 'pylon-seo'), $has_personal_voice, $first_person_count . ' instances', __('Use first-person language to share personal experience.', 'pylon-seo'), 3, 'eeat'),
            $this->check('eat_experience', __('Experience signals present', 'pylon-seo'), $has_experience, $exp_count . ' signals', __('Include hands-on experience, testing results, or personal anecdotes.', 'pylon-seo'), 3, 'eeat'),
            $this->check('eat_expertise', __('Expertise signals present', 'pylon-seo'), $has_expertise, $expert_count . ' signals', __('Reference studies, research, or expert sources to demonstrate expertise.', 'pylon-seo'), 3, 'eeat'),
            $this->check('eat_data_citations', __('Data and citations present', 'pylon-seo'), $has_data_citations, $data_count . ' references', __('Include statistics, data points, or cited sources for authority.', 'pylon-seo'), 2, 'eeat'),
            $this->check('eat_quotes', __('Quotes or references included', 'pylon-seo'), $has_quotes, $quotes . ' quotes', __('Add expert quotes or notable references to boost credibility.', 'pylon-seo'), 1, 'eeat'),
            $this->check('eat_https', __('Site uses HTTPS', 'pylon-seo'), $has_https, $has_https ? 'HTTPS' : 'HTTP', __('Switch to HTTPS for trust and security signals.', 'pylon-seo'), 3, 'eeat'),
            $this->check('eat_contact_page', __('Contact page exists', 'pylon-seo'), $has_contact, $has_contact ? __('Found', 'pylon-seo') : '—', __('Create a contact page to build trust with readers.', 'pylon-seo'), 2, 'eeat'),
            $this->check('eat_about_page', __('About page exists', 'pylon-seo'), $has_about, $has_about ? __('Found', 'pylon-seo') : '—', __('Create an about page to establish credibility and authority.', 'pylon-seo'), 2, 'eeat'),
            $this->check('eat_privacy_page', __('Privacy policy exists', 'pylon-seo'), $has_privacy, $has_privacy ? __('Found', 'pylon-seo') : '—', __('Add a privacy policy page for legal compliance and trust.', 'pylon-seo'), 2, 'eeat'),
        ];
    }

    // ─── Topical Authority Checks ───
    private function check_topical_authority(): array {
        $word_count = $this->word_count;
        $content_lower = strtolower($this->content_text);
        $internal_count = count($this->internal_links);
        $external_count = count($this->external_links);
        $heading_count = count($this->headings);

        $avg_words_between_headings = 0;
        if ($heading_count > 1 && $word_count > 0) {
            $avg_words_between_headings = (int) round($word_count / $heading_count);
        }

        $has_faq = $this->h2_count >= 3 || $this->h3_count >= 5;
        $comprehensive = $word_count >= 1500;
        $deep_content = $word_count >= 2500;

        $has_internal_links = $internal_count >= 2;
        $has_external_links = $external_count >= 1;
        $link_balance = $internal_count >= 1 && $external_count >= 1;

        $structured_content = $this->has_list || $this->has_table;
        $multimedia = $this->image_count >= 2 || $this->has_video;

        $section_depth = $this->h2_count >= 3 && $this->h3_count >= 2;

        $has_numbers = (bool) preg_match('/\d+/', $this->content_text);
        $has_lists_or_tables = $this->has_list || $this->has_table;

        $recently_published = strtotime($this->post->post_date) > strtotime('-6 months');

        $related_terms = ['also', 'related', 'similar', 'another', 'more about', 'see also', 'learn more', 'continue reading'];
        $related_count = 0;
        foreach ($related_terms as $t) {
            $related_count += substr_count($content_lower, $t);
        }
        $has_related_content = $related_count >= 2 || $internal_count >= 3;

        $has_toc = $heading_count >= 5;

        return [
            $this->check('top_comprehensive', __('Content is comprehensive (1500+ words)', 'pylon-seo'), $comprehensive, $word_count . ' words', __('Aim for 1500+ words for comprehensive topical coverage.', 'pylon-seo'), 4, 'topical'),
            $this->check('top_deep', __('Deep content (2500+ words)', 'pylon-seo'), $deep_content, $word_count . ' words', __('For competitive topics, aim for 2500+ words for deeper coverage.', 'pylon-seo'), 2, 'topical'),
            $this->check('top_headings', __('Multiple subheadings (3+ H2s)', 'pylon-seo'), $this->h2_count >= 3, $this->h2_count . ' H2s', __('Use multiple H2 subheadings to organize content comprehensively.', 'pylon-seo'), 3, 'topical'),
            $this->check('top_section_depth', __('Content has H2 + H3 depth', 'pylon-seo'), $section_depth, $this->h2_count . ' H2s, ' . $this->h3_count . ' H3s', __('Use H3 subheadings under H2s for detailed topic coverage.', 'pylon-seo'), 2, 'topical'),
            $this->check('top_heading_spacing', __('Headings every ~300 words', 'pylon-seo'), $avg_words_between_headings > 0 && $avg_words_between_headings <= 350, $avg_words_between_headings . ' words avg', __('Space subheadings evenly for better scannability.', 'pylon-seo'), 1, 'topical'),
            $this->check('top_internal_links', __('2+ internal links', 'pylon-seo'), $has_internal_links, $internal_count . ' links', __('Add internal links to related content to build topic clusters.', 'pylon-seo'), 3, 'topical'),
            $this->check('top_external_links', __('External links to sources', 'pylon-seo'), $has_external_links, $external_count . ' links', __('Link to authoritative external sources for credibility.', 'pylon-seo'), 2, 'topical'),
            $this->check('top_link_balance', __('Balanced internal/external links', 'pylon-seo'), $link_balance, $internal_count . ' internal / ' . $external_count . ' external', __('Maintain a mix of internal and external links.', 'pylon-seo'), 1, 'topical'),
            $this->check('top_structured', __('Content uses lists or tables', 'pylon-seo'), $structured_content, ($this->has_list ? 'Lists' : '') . ($this->has_table ? ' Tables' : '') ?: '—', __('Use lists and tables to present structured information.', 'pylon-seo'), 2, 'topical'),
            $this->check('top_multimedia', __('Content includes multimedia', 'pylon-seo'), $multimedia, $this->image_count . ' images' . ($this->has_video ? ' + video' : ''), __('Add images, videos, or infographics to enrich content.', 'pylon-seo'), 2, 'topical'),
            $this->check('top_toc', __('Content has 5+ headings (TOC-friendly)', 'pylon-seo'), $has_toc, $heading_count . ' headings', __('Add more headings to enable a table of contents.', 'pylon-seo'), 1, 'topical'),
            $this->check('top_numbers', __('Content includes data/numbers', 'pylon-seo'), $has_numbers, $has_numbers ? __('Found', 'pylon-seo') : '—', __('Include specific numbers and data points for depth.', 'pylon-seo'), 1, 'topical'),
            $this->check('top_recently_published', __('Published within 6 months', 'pylon-seo'), $recently_published, gmdate('M j, Y', strtotime($this->post->post_date)), __('Recently published content tends to rank better.', 'pylon-seo'), 1, 'topical'),
            $this->check('top_related', __('Internal cross-linking to related topics', 'pylon-seo'), $has_related_content, $related_count . ' references', __('Link to related content to build topical authority.', 'pylon-seo'), 2, 'topical'),
        ];
    }

    // ─── Content Uniqueness Checks ───
    private function check_content_uniqueness(): array {
        $word_count = $this->word_count;
        $content_lower = strtolower($this->content_text);
        $title = $this->post->post_title;
        $content = $this->content_text;

        $is_thin = $word_count < 300;
        $is_short = $word_count < 600;

        $generic_starters = ['in this article', 'in this post', 'today we will', 'in this guide', 'if you are looking for', 'welcome to', 'in this tutorial'];
        $generic_count = 0;
        foreach ($generic_starters as $g) {
            $generic_count += substr_count($content_lower, $g);
        }
        $has_generic_intro = $generic_count >= 1;

        $filler_words = ['very', 'really', 'actually', 'basically', 'simply', 'just', 'quite', 'rather', 'somewhat', 'pretty much'];
        $filler_count = 0;
        foreach ($filler_words as $f) {
            $filler_count += preg_match_all('/\b' . $f . '\b/i', $content);
        }
        $filler_density = $word_count > 0 ? ($filler_count / $word_count) * 100 : 0;
        $has_excessive_fillers = $filler_density > 1.5;

        $sentences = $this->sentences;
        $sentence_count = max(1, count($sentences));
        $short_sentences = 0;
        foreach ($sentences as $s) {
            if (preg_match_all('/\p{L}+/u', $s) < 5) $short_sentences++;
        }
        $short_sentence_pct = ($short_sentences / $sentence_count) * 100;
        $has_many_short = $short_sentence_pct > 30;

        $has_original_title = mb_strlen($title) > 10 && !preg_match('/^(\d+\s+)?(best|top|what|how|why|when|where|who)\s/i', mb_strtolower($title));

        $has_entity_density = $this->check_entity_usage($content);

        $paragraphs = $this->paragraphs;
        $long_paragraphs = 0;
        foreach ($paragraphs as $p) {
            if ($p['words'] > 150) $long_paragraphs++;
        }
        $has_long_paragraphs = $long_paragraphs > 0;

        $repeated_sentences = $this->check_repeated_sentences($sentences);
        $has_repeated = $repeated_sentences > 0;

        $has_special_chars = (bool) preg_match('/[\x{2013}\x{2014}\x{201C}\x{201D}\x{2018}\x{2019}\x{00B7}\x{2022}\x{2192}\x{00AE}\x{2122}\x{00A9}\x{00B0}\x{00B1}\x{00D7}\x{00F7}]/u', $content);

        $all_words = preg_split('/\s+/', mb_strtolower($content), -1, PREG_SPLIT_NO_EMPTY);
        $total_words = count($all_words);
        $unique_words = count(array_unique($all_words));
        $lexical_diversity = $total_words > 0 ? ($unique_words / $total_words) * 100 : 0;
        $good_diversity = $lexical_diversity > 60;

        $has_original_structure = $this->h2_count >= 3 && ($this->has_list || $this->has_table || $this->has_video);

        return [
            $this->check('uniq_thin_content', __('Content is not thin (< 300 words)', 'pylon-seo'), !$is_thin, $word_count . ' words', __('Write at least 300 words to avoid thin content penalties.', 'pylon-seo'), 5, 'uniqueness'),
            $this->check('uniq_no_generic_intro', __('No generic intro phrases', 'pylon-seo'), !$has_generic_intro, $generic_count . ' found', __('Avoid generic openings like "In this article..." — be specific.', 'pylon-seo'), 2, 'uniqueness'),
            $this->check('uniq_filler_words', __('Low filler word usage (< 1.5%)', 'pylon-seo'), !$has_excessive_fillers, sprintf('%.1f%%', $filler_density), __('Reduce filler words (very, really, actually, basically) for sharper writing.', 'pylon-seo'), 2, 'uniqueness'),
            $this->check('uniq_sentence_variety', __('Good sentence length variety', 'pylon-seo'), !$has_many_short, sprintf('%.0f%% short sentences', $short_sentence_pct), __('Mix short and long sentences for better rhythm.', 'pylon-seo'), 2, 'uniqueness'),
            $this->check('uniq_original_title', __('Title is not overly generic', 'pylon-seo'), $has_original_title, mb_substr($title, 0, 60), __('Create a unique, descriptive title rather than a generic list format.', 'pylon-seo'), 2, 'uniqueness'),
            $this->check('uniq_no_repeated', __('No repeated sentences', 'pylon-seo'), !$has_repeated, $repeated_sentences . ' repeated', __('Remove duplicate sentences to improve originality.', 'pylon-seo'), 3, 'uniqueness'),
            $this->check('uniq_lexical_diversity', __('Good lexical diversity (> 60%)', 'pylon-seo'), $good_diversity, sprintf('%.0f%%', $lexical_diversity), __('Use varied vocabulary to demonstrate depth and originality.', 'pylon-seo'), 2, 'uniqueness'),
            $this->check('uniq_structure', __('Original content structure', 'pylon-seo'), $has_original_structure, ($this->h2_count . ' H2s') . ($this->has_list ? ' + Lists' : '') . ($this->has_table ? ' + Tables' : '') . ($this->has_video ? ' + Video' : ''), __('Use unique formatting (lists, tables, video) to differentiate.', 'pylon-seo'), 2, 'uniqueness'),
            $this->check('uniq_paragraph_length', __('No overly long paragraphs', 'pylon-seo'), !$has_long_paragraphs, $long_paragraphs . ' long paragraphs', __('Break long paragraphs into shorter, scannable chunks.', 'pylon-seo'), 1, 'uniqueness'),
            $this->check('uniq_entities', __('Content references entities/topics', 'pylon-seo'), $has_entity_density, __('Rich', 'pylon-seo'), __('Reference specific entities, brands, places, or concepts for depth.', 'pylon-seo'), 1, 'uniqueness'),
        ];
    }

    private function check_entity_usage(string $text): bool {
        $entity_patterns = [
            '/\b[A-Z][a-z]+ [A-Z][a-z]+\b/',
            '/\b[A-Z][a-z]{2,}\b/',
            '/\d{4}\b/',
        ];
        $count = 0;
        foreach ($entity_patterns as $p) {
            $count += preg_match_all($p, $text);
        }
        return $count >= 10;
    }

    private function check_repeated_sentences(array $sentences): int {
        $seen = [];
        $repeated = 0;
        foreach ($sentences as $s) {
            $norm = preg_replace('/\s+/', ' ', trim(strtolower($s)));
            if (strlen($norm) < 20) continue;
            if (isset($seen[$norm])) {
                $repeated++;
            } else {
                $seen[$norm] = true;
            }
        }
        return $repeated;
    }

    private function parse_headings(): array {
        $headings = [];
        $html = $this->content_html ?: $this->raw_content;
        preg_match_all('/<h([1-6])[^>]*>(.*?)<\/h[1-6]>/is', $html, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $level = (int) $m[1];
            $text = wp_strip_all_tags($m[2]);
            if (!empty(trim($text))) {
                $headings[] = ['level' => $level, 'text' => trim($text)];
            }
        }
        return $headings;
    }

    private function parse_links(): void {
        $html = $this->content_html ?: $this->raw_content;
        preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER);
        $site_url = home_url();
        foreach ($matches as $m) {
            $href = $m[1];
            $anchor = wp_strip_all_tags($m[2]);
            if (preg_match('#^https?://#i', $href)) {
                $href_host = wp_parse_url($href, PHP_URL_HOST);
                $site_host = wp_parse_url($site_url, PHP_URL_HOST);
                if ($href_host && $site_host && strtolower($href_host) === strtolower($site_host)) {
                    $this->internal_links[] = ['url' => $href, 'anchor' => $anchor];
                } else {
                    $this->external_links[] = ['url' => $href, 'anchor' => $anchor];
                }
            } elseif (strpos($href, '/') === 0 || strpos($href, '#') === 0 || strpos($href, '?') === 0) {
                $this->internal_links[] = ['url' => home_url($href), 'anchor' => $anchor];
            } elseif (strpos($href, ':') === false && preg_match('#^[a-zA-Z0-9_]#', $href)) {
                $this->internal_links[] = ['url' => home_url('/' . $href), 'anchor' => $anchor];
            }
        }
    }

    private function parse_images(): void {
        $html = $this->content_html ?: $this->raw_content;
        preg_match_all('/<img[^>]+>/i', $html, $matches);
        foreach ($matches[0] as $m) {
            $alt = '';
            if (preg_match('/alt=["\']([^"\']*)["\']/i', $m, $am)) {
                $alt = $am[1];
            }
            $this->images[] = ['tag' => $m, 'alt' => $alt];
            if (!empty(trim($alt))) $this->images_with_alt++;
        }
    }

    private function parse_paragraphs(): array {
        $paragraphs = [];
        $html = $this->content_html ?: $this->raw_content;
        preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $html, $matches);
        foreach ($matches[1] as $m) {
            $text = wp_strip_all_tags($m);
            $words = preg_match_all('/\p{L}+/u', $text);
            if ($words >= 5) {
                $paragraphs[] = ['text' => trim($text), 'words' => $words];
            }
        }
        return $paragraphs;
    }

    private function parse_sentences(): array {
        $text = wp_strip_all_tags($this->content_html ?: $this->raw_content);
        $text = preg_replace('/\s+/', ' ', $text);
        preg_match_all('/(?:[^.!?]*[.!?])\s*/u', $text, $matches);
        $sentences = [];
        foreach ($matches[0] as $s) {
            $trimmed = trim($s);
            if (mb_strlen($trimmed) > 5) {
                $sentences[] = $trimmed;
            }
        }
        return $sentences;
    }

    private function check(string $id, string $label, bool $pass, string $value = '', string $suggestion = '', int $weight = 1, string $tab = 'eeat'): array {
        $status = $pass ? 'pass' : 'fail';
        return compact('id', 'label', 'status', 'value', 'suggestion', 'weight', 'tab');
    }
}
