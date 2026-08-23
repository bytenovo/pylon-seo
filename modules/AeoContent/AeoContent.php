<?php
namespace Pylon\Core\Modules\AeoContent;
defined('ABSPATH') || exit;
class AeoContent {
    public function register(): void {
        add_action('add_meta_boxes', [$this, 'add_meta_box']);
        add_action('save_post', [$this, 'save'], 20, 2);
        add_action('wp_head', [$this, 'output_direct_answer_meta']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(string $hook): void {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) return;
        wp_enqueue_style('pylon-aeo-content', PYLON_URL . 'assets/css/modules/aeo-content.css', ['pylon-admin'], filemtime(PYLON_PATH . 'assets/css/modules/aeo-content.css'));
        wp_enqueue_script('pylon-aeo-content', PYLON_URL . 'assets/js/modules/aeo-content.js', ['jquery', 'wp-util', 'pylon-admin-js'], filemtime(PYLON_PATH . 'assets/js/modules/aeo-content.js'), true);
    }

    public function add_meta_box(): void {
        $screens = get_post_types(['public' => true]);
        foreach ($screens as $screen) {
            add_meta_box(
                'pylon_aeo_content',
                __('AEO Answer Mode', 'pylon-seo'),
                [$this, 'render_meta_box'],
                $screen,
                'normal',
                'high'
            );
        }
    }

    public function render_meta_box(\WP_Post $post): void {
        $answer = get_post_meta($post->ID, 'pylon_aeo_answer', true);
        $question = get_post_meta($post->ID, 'pylon_aeo_question', true);
        $keywords = get_post_meta($post->ID, 'pylon_aeo_keywords', true);

        wp_nonce_field('pylon_aeo_content', '_pylon_aeo_nonce');
        ?>
        <div class="pylon-aeo-tip">
            <strong><?php esc_html_e('AEO (Answer Engine Optimization) Mode:', 'pylon-seo'); ?></strong>
            <?php esc_html_e('Write a concise, direct answer below. This content is optimized for AI crawlers (ChatGPT, Perplexity, Gemini, Claude) and Google AI Overviews to extract as a featured answer. Keep it under 160 words, start with a direct statement, and avoid fluff.', 'pylon-seo'); ?>
        </div>
        <div style="display:grid;gap:12px">
            <div class="pylon-form-group">
                <label><?php esc_html_e('Target Question', 'pylon-seo'); ?></label>
                <input type="text" name="pylon_aeo_question" class="pylon-input" value="<?php echo esc_attr($question); ?>" placeholder="<?php esc_attr_e('e.g. What is the best WordPress SEO plugin?', 'pylon-seo'); ?>">
                <p class="pylon-help"><?php esc_html_e('The question this content answers. Used in FAQ schema and AI extraction.', 'pylon-seo'); ?></p>
            </div>
            <div class="pylon-form-group">
                <label><?php esc_html_e('Direct Answer', 'pylon-seo'); ?> <span id="pylon-aeo-wordcount" class="pylon-aeo-score" style="background:#f1f5f9;color:#475569">0 words</span></label>
                <textarea name="pylon_aeo_answer" id="pylon_aeo_answer" class="pylon-input" rows="6" placeholder="<?php esc_attr_e('Write a direct, concise answer to the target question. Start with the most important information.', 'pylon-seo'); ?>"><?php echo esc_textarea($answer); ?></textarea>
                <p class="pylon-help"><?php esc_html_e('Recommended: 50–160 words. This will be used for schema.org "text" property and AI-optimized meta description.', 'pylon-seo'); ?></p>
            </div>
            <div class="pylon-form-group">
                <label><?php esc_html_e('Extractable Keywords', 'pylon-seo'); ?></label>
                <input type="text" name="pylon_aeo_keywords" class="pylon-input" value="<?php echo esc_attr($keywords); ?>" placeholder="<?php esc_attr_e('keyword1, keyword2, keyword3', 'pylon-seo'); ?>">
                <p class="pylon-help"><?php esc_html_e('Comma-separated keywords to help AI systems identify topics in this answer.', 'pylon-seo'); ?></p>
            </div>
        </div>
        <?php
    }

    public function save(int $post_id, \WP_Post $post): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if (!isset($_POST['_pylon_aeo_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_pylon_aeo_nonce'])), 'pylon_aeo_content')) return;

        $answer = sanitize_textarea_field(wp_unslash($_POST['pylon_aeo_answer'] ?? ''));
        $question = sanitize_text_field(wp_unslash($_POST['pylon_aeo_question'] ?? ''));
        $keywords = sanitize_text_field(wp_unslash($_POST['pylon_aeo_keywords'] ?? ''));

        update_post_meta($post_id, 'pylon_aeo_answer', $answer);
        update_post_meta($post_id, 'pylon_aeo_question', $question);
        update_post_meta($post_id, 'pylon_aeo_keywords', $keywords);
    }

    public function output_direct_answer_meta(): void {
        if (is_admin()) return;
        if (!is_singular()) return;

        $post = get_queried_object();
        if (!$post || !($post instanceof \WP_Post)) return;

        $answer = get_post_meta($post->ID, 'pylon_aeo_answer', true);
        if (!$answer) return;

        $question = get_post_meta($post->ID, 'pylon_aeo_question', true);
        $keywords = get_post_meta($post->ID, 'pylon_aeo_keywords', true);

        // Output as structured data for AI crawlers.
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => [
                [
                    '@type' => 'Question',
                    'name' => $question ?: $post->post_title,
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $answer,
                    ],
                ],
            ],
        ];
        \Pylon\Core\JsonLd::script($schema);

        // AI crawler hint: direct answer meta tag.
        echo '<meta name="pylon-aeo-direct-answer" content="' . esc_attr(wp_trim_words($answer, 30)) . '">' . "\n";

        // If question exists, also output question-style heading data.
        if ($question) {
            echo '<meta name="pylon-aeo-question" content="' . esc_attr($question) . '">' . "\n";
        }
        if ($keywords) {
            echo '<meta name="pylon-aeo-keywords" content="' . esc_attr($keywords) . '">' . "\n";
        }
    }
}
