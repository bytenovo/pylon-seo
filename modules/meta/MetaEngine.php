<?php
namespace Pylon\Core\Modules\Meta;
defined('ABSPATH') || exit;
class MetaEngine {
    public function register(): void {
        add_action('wp_head', [$this, 'output_meta'], 1);
        add_action('add_meta_boxes', [$this, 'add_meta_box']);
        add_action('save_post', [$this, 'save_meta_box'], 10, 2);
        add_action('save_post', [$this, 'clear_meta_cache'], 20, 2);
        add_filter('wp_title', [$this, 'filter_title'], 99);
        add_filter('pre_get_document_title', [$this, 'filter_title'], 99);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_init', [$this, 'register_side_filters']);
        add_filter('manage_posts_columns', [$this, 'add_cornerstone_column']);
        add_action('manage_posts_custom_column', [$this, 'render_cornerstone_column'], 10, 2);
        add_filter('manage_pages_columns', [$this, 'add_cornerstone_column']);
        add_action('manage_pages_custom_column', [$this, 'render_cornerstone_column'], 10, 2);
        add_action('restrict_manage_posts', [$this, 'render_cornerstone_filter']);
        add_filter('parse_query', [$this, 'apply_cornerstone_filter']);
        add_action('admin_init', [$this, 'register_titles_settings']);
        add_filter('pylon_settings_sections', [$this, 'register_titles_section']);
        if (!class_exists('\Pylon\Core\Modules\MultiEngineScore\MultiEngineScore')) {
            add_action('wp_ajax_pylon_recalculate_engine_score', [$this, 'ajax_recalculate_engine_score']);
        }
    }

    public function ajax_recalculate_engine_score(): void {
        check_ajax_referer('pylon_admin_nonce', '_ajax_nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error();

        $post_id = absint($_POST['post_id'] ?? 0);
        $post = get_post($post_id);
        if (!$post) wp_send_json_error();

        $overrides = [];
        foreach (['title', 'description', 'focus_keyword', 'canonical', 'og_image', 'schema_type', 'noindex'] as $field) {
            $key = 'pylon_' . $field;
            if (isset($_POST[$key]) && is_string($_POST[$key])) {
                $overrides[$field] = sanitize_text_field(wp_unslash($_POST[$key]));
            }
        }
        if (isset($_POST['content']) && is_string($_POST['content'])) {
            $overrides['content'] = sanitize_textarea_field(wp_unslash($_POST['content']));
        }

        $data = \Pylon\Core\Modules\Content\ContentScore::get_score_data($post, $overrides);
        wp_send_json_success($data);
    }

    public function register_titles_settings(): void {
        register_setting('pylon_settings', 'pylon_capitalize_titles', ['sanitize_callback' => 'absint']);
        register_setting('pylon_settings', 'pylon_noindex_password', ['sanitize_callback' => 'absint']);
    }

    public function register_titles_section(array $sections): array {
        $sections['titles'] = [
            'icon'  => '🔠',
            'title' => __('Titles & Crawl Rules', 'pylon-seo'),
            'desc'  => __('Global title formatting and indexing rules for password-protected content.', 'pylon-seo'),
            'fields' => [
                'pylon_capitalize_titles' => [
                    'type'    => 'checkbox',
                    'default' => '0',
                    'label'   => __('Capitalize Titles', 'pylon-seo'),
                    'desc'    => __('Convert generated titles to Title Case (articles, conjunctions and short prepositions stay lowercase). Custom per-post titles are kept as-is.', 'pylon-seo'),
                ],
                'pylon_noindex_password' => [
                    'type'  => 'checkbox',
                    'label' => __('Noindex Password-Protected Pages', 'pylon-seo'),
                    'desc'  => __('Add noindex to pages and posts that require a password to view.', 'pylon-seo'),
                ],
            ],
        ];
        return $sections;
    }

    public function register_side_filters(): void {
        $post_types = get_post_types(['public' => true]);
        foreach ($post_types as $pt) {
            add_filter("get_user_option_meta-box-order_{$pt}", [$this, 'force_side_context']);
        }
    }

    public function force_side_context($order) {
        if (!is_array($order)) {
            return $order;
        }
        $normal = isset($order['normal']) ? explode(',', $order['normal']) : [];
        $side = isset($order['side']) ? explode(',', $order['side']) : [];

        // Move pylon_meta_box from normal to side
        $key = array_search('pylon_meta_box', $normal, true);
        if ($key !== false) {
            unset($normal[$key]);
            if (!in_array('pylon_meta_box', $side, true)) {
                $side[] = 'pylon_meta_box';
            }
        }

        // Keep pylon_ab_testing in normal
        $key2 = array_search('pylon_ab_testing', $side, true);
        if ($key2 !== false) {
            unset($side[$key2]);
            if (!in_array('pylon_ab_testing', $normal, true)) {
                $normal[] = 'pylon_ab_testing';
            }
        }

        $order['normal'] = implode(',', array_filter($normal));
        $order['side'] = implode(',', array_filter($side));
        return $order;
    }

    public function enqueue_assets(string $hook): void {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) return;
        wp_enqueue_style('pylon-admin', PYLON_URL . 'assets/css/admin.css', [], filemtime(PYLON_PATH . 'assets/css/admin.css'));
        wp_enqueue_script('pylon-admin-js', PYLON_URL . 'assets/js/admin.js', ['jquery', 'wp-util'], filemtime(PYLON_PATH . 'assets/js/admin.js'), true);
        wp_localize_script('pylon-admin-js', 'pylonAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pylon_admin_nonce'),
            'i18n' => [
                'generating' => __('Generating...', 'pylon-seo'),
                'error' => __('An error occurred.', 'pylon-seo'),
            ],
        ]);
    }

    public function output_meta(): void {
        if (is_admin()) return;

        $post_id = get_queried_object_id();
        if (is_singular() && $post_id) {
            $cached = get_transient('pylon_meta_output_' . $post_id);
            if ($cached !== false) {
                echo wp_kses_post($cached);
                return;
            }
            ob_start();
        }

        $title = $this->get_meta_value('pylon_title') ?: wp_get_document_title();
        $description = $this->get_meta_value('pylon_description') ?: $this->get_excerpt();
        $og_title = $this->get_meta_value('pylon_og_title') ?: $title;
        $og_description = $this->get_meta_value('pylon_og_description') ?: $description;
        $og_image = $this->get_meta_value('pylon_og_image') ?: $this->get_default_image(get_queried_object_id());
        $twitter_title = $this->get_meta_value('pylon_twitter_title') ?: $og_title;
        $twitter_description = $this->get_meta_value('pylon_twitter_description') ?: $og_description;
        $twitter_image = $this->get_meta_value('pylon_twitter_image') ?: $og_image;
        $canonical = $this->get_meta_value('pylon_canonical') ?: get_permalink();

        $noindex = $this->get_meta_value('pylon_noindex');
        $nofollow = $this->get_meta_value('pylon_nofollow');

        $is_single = is_singular();
        if ($is_single && get_option('pylon_noindex_password', '1') && post_password_required()) {
            $noindex = true;
        }

        $robots = 'index, follow';
        if ($noindex && $nofollow) $robots = 'noindex, nofollow';
        elseif ($noindex) $robots = 'noindex, follow';
        elseif ($nofollow) $robots = 'index, nofollow';

        echo "<meta name=\"description\" content=\"" . esc_attr($description) . "\" />\n";
        echo "<link rel=\"canonical\" href=\"" . esc_url($canonical) . "\" />\n";
        echo "<meta name=\"robots\" content=\"" . esc_attr($robots) . "\" />\n";

        if (get_option('pylon_og_enabled', '1')) {
            echo "<meta property=\"og:title\" content=\"" . esc_attr($og_title) . "\" />\n";
            echo "<meta property=\"og:description\" content=\"" . esc_attr($og_description) . "\" />\n";
            echo "<meta property=\"og:url\" content=\"" . esc_url($canonical) . "\" />\n";
            echo "<meta property=\"og:type\" content=\"" . esc_attr($this->get_og_type()) . "\" />\n";
            echo "<meta property=\"og:site_name\" content=\"" . esc_attr(get_bloginfo('name')) . "\" />\n";
            if ($og_image) {
                echo "<meta property=\"og:image\" content=\"" . esc_url($og_image) . "\" />\n";
            }
        }

        if (get_option('pylon_twitter_enabled', '1')) {
            echo "<meta name=\"twitter:card\" content=\"" . esc_attr($this->get_twitter_card_type()) . "\" />\n";
            echo "<meta name=\"twitter:title\" content=\"" . esc_attr($twitter_title) . "\" />\n";
            echo "<meta name=\"twitter:description\" content=\"" . esc_attr($twitter_description) . "\" />\n";
            if ($twitter_image) {
                echo "<meta name=\"twitter:image\" content=\"" . esc_url($twitter_image) . "\" />\n";
            }
        }

        if (is_singular() && !empty($post_id)) {
            $output = ob_get_flush();
            set_transient('pylon_meta_output_' . $post_id, $output, HOUR_IN_SECONDS);
        }
    }

    public function clear_meta_cache(int $post_id, \WP_Post $post): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        delete_transient('pylon_meta_output_' . $post_id);
    }

    public function add_meta_box(): void {
        $post_types = get_post_types(['public' => true]);
        add_meta_box(
            'pylon_meta_box',
            __('Pylon SEO', 'pylon-seo'),
            [$this, 'render_meta_box'],
            $post_types,
            'side',
            'high'
        );
    }

    public function get_analysis_vals($post): array {
        $vals = [];
        $meta_keys = [
            'pylon_title', 'pylon_description', 'pylon_focus_keyword',
            'pylon_canonical', 'pylon_noindex', 'pylon_nofollow',
            'pylon_og_title', 'pylon_og_description', 'pylon_og_image',
            'pylon_twitter_title', 'pylon_twitter_description', 'pylon_twitter_image',
            'pylon_schema_type',
        ];
        foreach ($meta_keys as $k) {
            $vals[$k] = get_post_meta($post->ID, $k, true);
        }
        return $vals;
    }

    public function render_analysis_public($post): void {
        $this->render_analysis($post, $this->get_analysis_vals($post));
    }

    public function render_meta_box($post): void {
        wp_nonce_field('pylon_meta', 'pylon_meta_nonce');
        $vals = [];
        $meta_keys = [
            'pylon_title', 'pylon_description', 'pylon_focus_keyword',
            'pylon_canonical', 'pylon_noindex', 'pylon_nofollow',
            'pylon_og_title', 'pylon_og_description', 'pylon_og_image',
            'pylon_twitter_title', 'pylon_twitter_description', 'pylon_twitter_image',
            'pylon_schema_type',
        ];
        foreach ($meta_keys as $k) {
            $vals[$k] = get_post_meta($post->ID, $k, true);
        }

        $site_name = get_bloginfo('name');
        $permalink = get_permalink($post);
        $default_title = $post->post_title;
        $default_desc = get_the_excerpt($post) ?: wp_trim_words($post->post_content, 20);
        $schema_types = ['', 'Article', 'BlogPosting', 'NewsArticle', 'Product', 'LocalBusiness', 'FAQPage', 'Recipe', 'Event', 'Person', 'Organization', 'WebPage', 'AboutPage', 'ContactPage'];

        $fb_title = $vals['pylon_title'] ?: $default_title;
        $fb_desc = $vals['pylon_description'] ?: $default_desc;
        $fb_image = $this->get_default_image($post->ID);
        $og_title_fb = $vals['pylon_og_title'] ?: $fb_title;
        $og_desc_fb = $vals['pylon_og_description'] ?: $fb_desc;
        $og_image_fb = $vals['pylon_og_image'] ?: $fb_image;
        $tw_title_fb = $vals['pylon_twitter_title'] ?: $og_title_fb;
        $tw_desc_fb = $vals['pylon_twitter_description'] ?: $og_desc_fb;
        $tw_image_fb = $vals['pylon_twitter_image'] ?: $og_image_fb;
        ?>
        <div class="pylon-meta-wrap">
            <div class="pylon-meta-toolbar">
                <div class="pylon-meta-toolbar-left">
                    <span class="pylon-meta-icon"><?php echo esc_html__('🔍', 'pylon-seo'); ?></span>
                    <span class="pylon-meta-brand"><?php esc_html_e('Pylon SEO', 'pylon-seo'); ?></span>
                </div>
                <div class="pylon-meta-score" id="pylon-live-score">
                    <div class="pylon-score-ring">
                        <svg viewBox="0 0 36 36" class="pylon-score-svg">
                            <path class="pylon-score-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                            <path class="pylon-score-fill" id="pylon-score-arc" stroke-dasharray="0, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                        </svg>
                        <span class="pylon-score-number" id="pylon-score-num">0</span>
                    </div>
                    <span class="pylon-score-label-text" id="pylon-score-label"><?php esc_html_e('N/A', 'pylon-seo'); ?></span>
                </div>
            </div>

            <div class="pylon-meta-body">
                <div class="pylon-meta-main">
                    <div class="pylon-meta-tabs">
                        <button type="button" class="pylon-meta-tab active" data-tab="general"><?php esc_html_e('General', 'pylon-seo'); ?></button>
                        <button type="button" class="pylon-meta-tab" data-tab="social"><?php esc_html_e('Social', 'pylon-seo'); ?></button>
                        <button type="button" class="pylon-meta-tab" data-tab="advanced"><?php esc_html_e('Advanced', 'pylon-seo'); ?></button>
                    </div>

                    <div class="pylon-meta-panels">
                        <div class="pylon-meta-panel active" id="pylon-panel-general">
                            <div class="pylon-meta-field">
                                <label for="pylon_title"><?php esc_html_e('SEO Title', 'pylon-seo'); ?></label>
                                <div class="pylon-input-wrap">
                                    <input type="text" id="pylon_title" name="pylon_title" value="<?php echo esc_attr($vals['pylon_title']); ?>" class="pylon-input pylon-input-lg" data-pylon-maxlength="70" data-pylon-counter="pylon-char-title" placeholder="<?php echo esc_attr($default_title); ?>">
                                    <div class="pylon-input-footer">
                                        <span class="pylon-char-counter" id="pylon-char-title">0 / 70</span>
                                        <span class="pylon-char-hint"><?php esc_html_e('≤60 search, ≤70 SEO', 'pylon-seo'); ?></span>
                                    </div>
                                </div>
                            </div>

                            <div class="pylon-meta-field">
                                <label for="pylon_description"><?php esc_html_e('Meta Description', 'pylon-seo'); ?></label>
                                <div class="pylon-input-wrap">
                                    <textarea id="pylon_description" name="pylon_description" rows="3" class="pylon-textarea pylon-textarea-lg" data-pylon-maxlength="160" data-pylon-counter="pylon-char-desc" placeholder="<?php echo esc_attr($default_desc); ?>"><?php echo esc_textarea($vals['pylon_description']); ?></textarea>
                                    <div class="pylon-input-footer">
                                        <span class="pylon-char-counter" id="pylon-char-desc">0 / 160</span>
                                        <span class="pylon-char-hint"><?php esc_html_e('Max 160 chars for snippet', 'pylon-seo'); ?></span>
                                    </div>
                                </div>
                            </div>

                            <div class="pylon-meta-field">
                                <label for="pylon_focus_keyword"><?php esc_html_e('Focus Keyword', 'pylon-seo'); ?></label>
                                <div class="pylon-input-wrap">
                                    <?php
                                    $raw_kw = $vals['pylon_focus_keyword'];
                                    $kw_list = array_filter(array_map('trim', explode(',', $raw_kw)));
                                    $kw_limit = 999;
                                    ?>
                                    <div class="pylon-tag-wrapper" id="pylon-kw-wrapper" data-limit="<?php echo (int) $kw_limit; ?>">
                                        <input type="hidden" name="pylon_focus_keyword" id="pylon_focus_keyword" value="<?php echo esc_attr($raw_kw); ?>">
                                        <input type="text" class="pylon-input pylon-input-lg pylon-kw-input" id="pylon-kw-input" placeholder="<?php esc_attr_e('Type keyword and press Enter', 'pylon-seo'); ?>" autocomplete="off">
                                        <div class="pylon-tag-list" id="pylon-kw-tags">
                                            <?php
                                            $kw_title = $vals['pylon_title'] ?: $post->post_title;
                                            $kw_desc = $vals['pylon_description'] ?: '';
                                            $kw_content = wp_strip_all_tags($post->post_content);
                                            $kw_slug = $post->post_name;
                                            ?>
                                            <?php foreach ($kw_list as $kw): ?>
                                                <?php
                                                $kw_trimmed = trim($kw);
                                                $kw_cls = 'pylon-tag';
                                                if ($kw_trimmed) {
                                                    if (mb_stripos($kw_title, $kw_trimmed) !== false) $kw_cls .= ' kw-in-title';
                                                    elseif (mb_stripos($kw_desc, $kw_trimmed) !== false) $kw_cls .= ' kw-in-desc';
                                                    elseif (mb_stripos($kw_content, $kw_trimmed) !== false) $kw_cls .= ' kw-in-content';
                                                    elseif (mb_stripos($kw_slug, $kw_trimmed) !== false) $kw_cls .= ' kw-in-slug';
                                                    else $kw_cls .= ' kw-missing';
                                                }
                                                ?>
                                                <span class="<?php echo esc_attr($kw_cls); ?>"><?php echo esc_html($kw_trimmed); ?><button type="button" class="pylon-tag-remove" aria-label="<?php esc_attr_e('Remove', 'pylon-seo'); ?>">&times;</button></span>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="pylon-kw-footer">
                                            <span class="pylon-kw-count" id="pylon-kw-count"><?php echo count($kw_list); ?> / <?php echo $kw_limit === 999 ? '&infin;' : esc_html($kw_limit); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php $this->render_analysis($post, $vals); ?>
                        </div>

                        <div class="pylon-meta-panel" id="pylon-panel-social">
                            <?php
                            $social_links = \Pylon\Core\Modules\Social\SocialLinks::get_social_links();
                            $social_urls = \Pylon\Core\Modules\Social\SocialLinks::get_same_as_urls();
                            if (!empty($social_urls)):
                            ?>
                            <div class="pylon-social-profiles" style="margin-bottom:16px;padding:10px 12px;background:#f8f9fa;border-radius:6px;border:1px solid #e9ecef;">
                                <div style="font-size:12px;font-weight:600;color:#374151;margin-bottom:8px;text-transform:uppercase;letter-spacing:0.3px;"><?php esc_html_e('Connected Profiles', 'pylon-seo'); ?></div>
                                <div style="display:flex;flex-wrap:wrap;gap:6px;">
                                    <?php foreach ($social_links as $key => $url): if (empty($url)) continue; ?>
                                        <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;background:#fff;border-radius:20px;border:1px solid #e0e0e0;font-size:12px;color:#1a73e8;text-decoration:none;">
                                            <?php
                                            $icons = [
                                                'facebook_url' => 'f',
                                                'twitter_url' => '𝕏',
                                                'instagram_url' => '📷',
                                                'linkedin_url' => 'in',
                                                'pinterest_url' => 'P',
                                                'youtube_url' => '▶',
                                            ];
                                            echo esc_html($icons[$key] ?? '🔗');
                                            ?>
                                            <span><?php echo esc_html(ucfirst(explode('_', $key)[0])); ?></span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                                <div style="margin-top:8px;font-size:11px;color:#6b7280;">
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=pylon-group-advanced&tab=social')); ?>" style="color:#6b7280;text-decoration:underline;"><?php esc_html_e('Manage social links', 'pylon-seo'); ?></a>
                                </div>
                            </div>
                            <?php endif; ?>
                            <div class="pylon-meta-field">
                                <label for="pylon_og_title"><?php esc_html_e('OG Title', 'pylon-seo'); ?> <span class="pylon-field-badge"><?php esc_html_e('Facebook, LinkedIn', 'pylon-seo'); ?></span></label>
                                <div class="pylon-input-wrap">
                                    <input type="text" id="pylon_og_title" name="pylon_og_title" value="<?php echo esc_attr($vals['pylon_og_title']); ?>" class="pylon-input" placeholder="<?php echo esc_attr($og_title_fb); ?>">
                                </div>
                            </div>
                            <div class="pylon-meta-field">
                                <label for="pylon_og_description"><?php esc_html_e('OG Description', 'pylon-seo'); ?></label>
                                <div class="pylon-input-wrap">
                                    <textarea id="pylon_og_description" name="pylon_og_description" rows="2" class="pylon-textarea" placeholder="<?php echo esc_attr($og_desc_fb); ?>"><?php echo esc_textarea($vals['pylon_og_description']); ?></textarea>
                                </div>
                            </div>
                            <div class="pylon-meta-field">
                                <label for="pylon_og_image"><?php esc_html_e('OG Image URL', 'pylon-seo'); ?></label>
                                <div class="pylon-input-wrap">
                                    <div class="pylon-input-group">
                                        <input type="text" id="pylon_og_image" name="pylon_og_image" value="<?php echo esc_attr($vals['pylon_og_image']); ?>" class="pylon-input" placeholder="<?php echo esc_attr($fb_image ?: ''); ?>">
                                        <button type="button" class="pylon-btn pylon-btn-secondary pylon-btn-sm pylon-media-btn" data-target="pylon_og_image"><?php esc_html_e('Select', 'pylon-seo'); ?></button>
                                    </div>
                                </div>
                            </div>
                            <hr class="pylon-divider">
                            <div class="pylon-meta-field">
                                <label for="pylon_twitter_title"><?php esc_html_e('Twitter Title', 'pylon-seo'); ?> <span class="pylon-field-badge">X</span></label>
                                <div class="pylon-input-wrap">
                                    <input type="text" id="pylon_twitter_title" name="pylon_twitter_title" value="<?php echo esc_attr($vals['pylon_twitter_title']); ?>" class="pylon-input" placeholder="<?php echo esc_attr($tw_title_fb); ?>">
                                </div>
                            </div>
                            <div class="pylon-meta-field">
                                <label for="pylon_twitter_description"><?php esc_html_e('Twitter Description', 'pylon-seo'); ?></label>
                                <div class="pylon-input-wrap">
                                    <textarea id="pylon_twitter_description" name="pylon_twitter_description" rows="2" class="pylon-textarea" placeholder="<?php echo esc_attr($tw_desc_fb); ?>"><?php echo esc_textarea($vals['pylon_twitter_description']); ?></textarea>
                                </div>
                            </div>
                            <div class="pylon-meta-field">
                                <label for="pylon_twitter_image"><?php esc_html_e('Twitter Image URL', 'pylon-seo'); ?></label>
                                <div class="pylon-input-wrap">
                                    <div class="pylon-input-group">
                                        <input type="text" id="pylon_twitter_image" name="pylon_twitter_image" value="<?php echo esc_attr($vals['pylon_twitter_image']); ?>" class="pylon-input" placeholder="<?php echo esc_attr($og_image_fb ?: ''); ?>">
                                        <button type="button" class="pylon-btn pylon-btn-secondary pylon-btn-sm pylon-media-btn" data-target="pylon_twitter_image"><?php esc_html_e('Select', 'pylon-seo'); ?></button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="pylon-meta-panel" id="pylon-panel-advanced">
                            <div class="pylon-meta-field">
                                <label for="pylon_canonical"><?php esc_html_e('Canonical URL', 'pylon-seo'); ?></label>
                                <div class="pylon-input-wrap">
                                    <input type="text" id="pylon_canonical" name="pylon_canonical" value="<?php echo esc_attr($vals['pylon_canonical']); ?>" class="pylon-input" placeholder="<?php echo esc_attr($permalink); ?>">
                                </div>
                            </div>

                            <div class="pylon-meta-field">
                                <label><?php esc_html_e('Robots Meta', 'pylon-seo'); ?></label>
                                <div>
                                    <label class="pylon-toggle">
                                        <input type="checkbox" name="pylon_noindex" value="1" <?php checked($vals['pylon_noindex'], '1'); ?>>
                                        <span class="pylon-toggle-track"></span>
                                        <span class="pylon-toggle-label" data-on="<?php esc_attr_e('No Index', 'pylon-seo'); ?>" data-off="<?php esc_attr_e('No Index', 'pylon-seo'); ?>"><?php esc_html_e('No Index', 'pylon-seo'); ?></span>
                                    </label>
                                    <label class="pylon-toggle">
                                        <input type="checkbox" name="pylon_nofollow" value="1" <?php checked($vals['pylon_nofollow'], '1'); ?>>
                                        <span class="pylon-toggle-track"></span>
                                        <span class="pylon-toggle-label" data-on="<?php esc_attr_e('No Follow', 'pylon-seo'); ?>" data-off="<?php esc_attr_e('No Follow', 'pylon-seo'); ?>"><?php esc_html_e('No Follow', 'pylon-seo'); ?></span>
                                    </label>
                                </div>
                            </div>

                            <div class="pylon-meta-field">
                                <label for="pylon_schema_type"><?php esc_html_e('Schema Type', 'pylon-seo'); ?></label>
                                <div class="pylon-input-wrap">
                                    <select id="pylon_schema_type" name="pylon_schema_type" class="pylon-select">
                                        <?php foreach ($schema_types as $st): ?>
                                            <option value="<?php echo esc_attr($st); ?>" <?php selected($vals['pylon_schema_type'], $st); ?>><?php echo $st ? esc_html($st) : '— ' . esc_html__('Default', 'pylon-seo') . ' —'; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <?php if (get_option('pylon_cornerstone_enabled', '1')): ?>
                            <div class="pylon-meta-field" style="margin-top:12px;padding-top:12px;border-top:1px solid var(--pylon-gray-200);">
                                <label class="pylon-toggle">
                                    <input type="checkbox" name="pylon_cornerstone_content" value="1" <?php checked(get_post_meta($post->ID, 'pylon_cornerstone_content', true), '1'); ?>>
                                    <span class="pylon-toggle-track"></span>
                                    <span class="pylon-toggle-label-text" style="font-weight:600;">💎 <?php esc_html_e('Cornerstone Content', 'pylon-seo'); ?></span>
                                </label>
                                <p style="font-size:11px;color:var(--pylon-gray-400);margin:6px 0 0 0;">
                                    <?php esc_html_e('Mark this as a pillar article. Pylon will warn when other posts target the same keyword to prevent cannibalization.', 'pylon-seo'); ?>
                                </p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="pylon-meta-sidebar">
                    <div class="pylon-preview-card">
                        <div class="pylon-preview-header"><?php esc_html_e('Google Preview', 'pylon-seo'); ?></div>
                        <div class="pylon-preview-google">
                            <div class="pylon-gp-title" id="pylon-gp-title"><?php echo esc_html(mb_substr($vals['pylon_title'] ?: $default_title, 0, 60)); ?></div>
                            <div class="pylon-gp-url" id="pylon-gp-url"><?php echo esc_url($permalink); ?></div>
                            <div class="pylon-gp-desc" id="pylon-gp-desc"><?php echo esc_html(mb_substr($vals['pylon_description'] ?: $default_desc, 0, 160)); ?></div>
                        </div>
                    </div>

                            <div class="pylon-preview-card">
                                <div class="pylon-preview-header"><?php esc_html_e('Social Preview', 'pylon-seo'); ?></div>
                                <div class="pylon-preview-social">
                                    <div class="pylon-og-card">
                                        <div class="pylon-og-img" id="pylon-og-img">
                                            <?php if ($og_image_fb): ?>
                                                <img src="<?php echo esc_url($og_image_fb); ?>" alt="">
                                            <?php else: ?>
                                                <span><?php esc_html_e('No image', 'pylon-seo'); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="pylon-og-body">
                                            <div class="pylon-og-site"><?php echo esc_html($site_name); ?></div>
                                            <div class="pylon-og-title" id="pylon-og-title"><?php echo esc_html(mb_substr($og_title_fb, 0, 70)); ?></div>
                                            <div class="pylon-og-desc" id="pylon-og-desc"><?php echo esc_html(mb_substr($og_desc_fb, 0, 120)); ?></div>
                                        </div>
                                    </div>
                                    <div class="pylon-tw-card">
                                        <div class="pylon-tw-img" id="pylon-tw-img">
                                            <?php if ($tw_image_fb): ?>
                                                <img src="<?php echo esc_url($tw_image_fb); ?>" alt="">
                                            <?php else: ?>
                                                <span><?php esc_html_e('No image', 'pylon-seo'); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="pylon-tw-body">
                                            <div class="pylon-tw-title" id="pylon-tw-title"><?php echo esc_html(mb_substr($tw_title_fb, 0, 70)); ?></div>
                                            <div class="pylon-tw-desc" id="pylon-tw-desc"><?php echo esc_html(mb_substr($tw_desc_fb, 0, 120)); ?></div>
                                            <div class="pylon-tw-site"><?php echo esc_url($permalink); ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                </div>
            </div>
        </div>

        <div class="pylon-pixel-info" style="display:none;margin-top:8px;padding:8px 10px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;font-size:11px;color:#0369a1;">
            <div id="pylon-pixel-title-warn"></div>
            <div id="pylon-pixel-desc-warn"></div>
        </div>
        <?php
    }

    public function render_analysis($post, array $vals): void {
        $title = $vals['pylon_title'] ?: $post->post_title;
        $pb = $this->get_page_builder_content($post);
        $content = $pb['text'];
        if (empty($content)) {
            $content = $post->post_content;
        }
        if (empty($content)) {
            $content = wp_strip_all_tags(apply_filters('the_content', $post->post_content));
        }
        if (empty(wp_strip_all_tags($content)) || preg_match_all('/\p{L}+/u', wp_strip_all_tags($content)) < 50) {
            $permalink = get_permalink($post);
            if ($permalink) {
                $fetched = $this->fetch_url_content($permalink);
                if (!empty($fetched)) {
                    $content = $fetched;
                }
            }
        }
        $desc = $vals['pylon_description'] ?: (get_the_excerpt($post) ?: wp_trim_words($content, 20));
        $kw = $vals['pylon_focus_keyword'];
        $kw_list = array_filter(array_map('trim', explode(',', $kw)));
        $word_count = preg_match_all('/\p{L}+/u', wp_strip_all_tags($content));
        $has_headings = $pb['heading_count'] > 0 || preg_match('/<h[1-6][^>]*>/i', $post->post_content);
        $has_images = $pb['image_count'] > 0 || preg_match('/<img[^>]+>/i', $post->post_content) || has_post_thumbnail($post);
        $tlen = mb_strlen($title);
        $dlen = mb_strlen($desc);


        $slug = $post->post_name;
        $kw_set = !empty($kw_list);
        $kw_in_title = false;
        $kw_in_desc = false;
        $kw_in_content = false;
        $kw_in_slug = false;
        foreach ($kw_list as $k) {
            $k = trim($k);
            if (!strlen($k)) continue;
            if (!$kw_in_title && mb_stripos($title, $k) !== false) $kw_in_title = true;
            if (!$kw_in_desc && mb_stripos($desc, $k) !== false) $kw_in_desc = true;
            if (!$kw_in_content && mb_stripos($content, $k) !== false) $kw_in_content = true;
            if (!$kw_in_slug && mb_stripos($slug, $k) !== false) $kw_in_slug = true;
        }
        $kw_used = $kw_in_title || $kw_in_desc || $kw_in_content || $kw_in_slug;
        $title_ok = $tlen >= 10 && $tlen <= 70;
        $desc_ok = $dlen >= 50 && $dlen <= 160;
        $content_ok = $word_count >= 300;
        $has_canonical = !empty($vals['pylon_canonical']);
        $noindex = !empty($vals['pylon_noindex']);

        $checks_pass = (int)$kw_set + (int)$kw_used + (int)$title_ok + (int)$desc_ok + (int)$content_ok + (int)$has_headings + (int)$has_images;
        $checks_total = 7;
        $engine_overall = 0;
        $engine_content = null;
        $engine_data = [];
        if (class_exists('\Pylon\Core\Modules\Content\ContentScore')) {
            $engine_data = \Pylon\Core\Modules\Content\ContentScore::get_score_data($post);
            $engine_overall = $engine_data['overall'] ?? 0;
            $engine_content = $engine_data['content'] ?? null;
        }
        if (!$engine_overall) {
            $engine_data = json_decode(get_post_meta($post->ID, '_pylon_engine_score', true) ?: '', true);
            if (!is_array($engine_data)) $engine_data = [];
            $engine_overall = $engine_data['overall'] ?? 0;
            $engine_content = $engine_data['content'] ?? null;
        }
        $score_pct = (int) $engine_overall;
        ?>
        <div class="pylon-adash">
            <?php if (empty($content)): ?>
            <div class="pylon-notice pylon-notice-warning" style="background:#fff3cd;border:1px solid #ffc107;color:#856404;padding:8px 12px;border-radius:4px;margin-bottom:12px;font-size:13px;">
                <?php esc_html_e('Content analysis unavailable — no analyzable content found on this page. Add content to the editor or use a compatible page builder to enable analysis.', 'pylon-seo'); ?>
            </div>
            <?php endif; ?>
            <div class="pylon-adash-hdr">
                <div class="pylon-adash-gauge">
                    <svg viewBox="0 0 40 40" class="pylon-adash-svg">
                        <circle cx="20" cy="20" r="17" class="pylon-adash-bg" />
                        <circle cx="20" cy="20" r="17" class="pylon-adash-fill" id="pylon-adash-arc" stroke-dasharray="0, 106.8" />
                    </svg>
                    <span class="pylon-adash-num" id="pylon-adash-num"><?php echo esc_html($score_pct); ?></span>
                </div>
                <div class="pylon-adash-stats">
                    <div class="pylon-adash-stat ok">
                        <span class="pylon-adash-stat-val"><?php echo esc_html($checks_pass); ?></span>
                        <span class="pylon-adash-stat-lbl"><?php esc_html_e('Passed', 'pylon-seo'); ?></span>
                    </div>
                    <div class="pylon-adash-stat no">
                        <span class="pylon-adash-stat-val"><?php echo esc_html($checks_total - $checks_pass); ?></span>
                        <span class="pylon-adash-stat-lbl"><?php esc_html_e('Needs work', 'pylon-seo'); ?></span>
                    </div>
                </div>
            </div>

            <?php if (!empty($engine_data['engines'])): ?>
            <div style="margin-top:8px;display:grid;gap:4px">
                <?php foreach ($engine_data['engines'] as $eng): ?>
                    <div style="display:flex;align-items:center;gap:6px;font-size:11px">
                        <span style="width:70px;color:#475569;font-weight:500"><?php echo esc_html($eng['label']); ?></span>
                        <div style="flex:1;height:4px;background:#e2e8f0;border-radius:2px;overflow:hidden">
                            <div style="height:100%;width:<?php echo (int) $eng['score']; ?>%;background:<?php echo $eng['score'] >= 80 ? '#16a34a' : ($eng['score'] >= 60 ? '#d97706' : '#dc2626'); ?>;border-radius:2px"></div>
                        </div>
                        <span style="width:20px;text-align:right;font-weight:600;color:<?php echo $eng['score'] >= 80 ? '#16a34a' : ($eng['score'] >= 60 ? '#d97706' : '#dc2626'); ?>"><?php echo (int) $eng['score']; ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php wp_add_inline_script('pylon-admin-js', 'window.pylonPageBuilderData = ' . wp_json_encode([
                'words' => $engine_content ? $engine_content['word_count'] : $word_count,
                'headings' => $engine_content ? $engine_content['heading_count'] : $pb['heading_count'],
                'images' => $engine_content ? $engine_content['image_count'] : $pb['image_count'],
                'content_text' => $engine_content ? wp_strip_all_tags($engine_content['text']) : wp_strip_all_tags($content),
                'has_list' => $engine_content ? $engine_content['has_list'] : (bool) preg_match('/<[uo]l/i', $post->post_content),
                'has_table' => $engine_content ? $engine_content['has_table'] : (bool) preg_match('/<table/i', $post->post_content),
                'engine_overall' => (int) $engine_overall,
            ]) . ';'); ?>
        <?php
        // --- SeoCheckerEngine: 5-tab analysis (SEO / Readability / Technical / Media / Issues) ---
        $seo_checks = ['checks' => [], 'scores' => ['seo' => 0, 'readability' => 0, 'technical' => 0, 'media' => 0], 'score' => 0];
        $highlight_issues = [];
        if (class_exists('\Pylon\Core\Modules\Content\SeoCheckerEngine')) {
            $checker = new \Pylon\Core\Modules\Content\SeoCheckerEngine($post);
            $seo_data = $checker->get_score_by_tabs();
            $seo_checks = $seo_data;
            $highlight_issues = $checker->get_highlight_issues();
        }
        $seo_tab_icons = ['seo' => '🔑', 'readability' => '📝', 'technical' => '⚙️', 'media' => '🖼️', 'issues' => '👁️'];
        $status_colors = ['pass' => '#22c55e', 'warn' => '#f59e0b', 'fail' => '#ef4444', 'info' => '#6366f1'];
        $status_icons = ['pass' => '✓', 'warn' => '!', 'fail' => '✗', 'info' => 'i'];
        $tabs = ['seo' => 'SEO', 'readability' => 'Readability', 'technical' => 'Technical', 'media' => 'Media'];
        ?>
        <div class="pylon-seo-checker" id="pylon-seo-checker">
            <?php wp_add_inline_script('pylon-admin-js', 'window.pylonHighlightIssues = ' . wp_json_encode($highlight_issues) . ';'); ?>
            <div class="pylon-seo-checker-tabs" id="pylon-seo-tabs">
                <?php foreach ($tabs as $tab_key => $tab_label): ?>
                    <div class="pylon-seo-tab<?php echo $tab_key === 'seo' ? ' active' : ''; ?>" data-tab="<?php echo esc_attr($tab_key); ?>">
                        <span class="pylon-seo-tab-icon"><?php echo esc_html($seo_tab_icons[$tab_key]); ?></span>
                        <span class="pylon-seo-tab-label"><?php echo esc_html($tab_label); ?></span>
                        <span class="pylon-seo-tab-score"><?php echo (int)($seo_checks['scores'][$tab_key] ?? 0); ?></span>
                    </div>
                <?php endforeach; ?>
                <div class="pylon-seo-tab pylon-seo-tab-issues" data-tab="issues">
                    <span class="pylon-seo-tab-icon">👁️</span>
                    <span class="pylon-seo-tab-label"><?php esc_html_e('Issues', 'pylon-seo'); ?></span>
                    <span class="pylon-seo-tab-score"><?php echo count($highlight_issues); ?></span>
                </div>
            </div>
            <?php foreach ($tabs as $tab_key => $tab_label): ?>
                <div class="pylon-seo-checker-content<?php echo $tab_key === 'seo' ? ' active' : ''; ?>" data-tab-content="<?php echo esc_attr($tab_key); ?>">
                    <?php
                    $tab_checks = array_filter($seo_checks['checks'], function ($c) use ($tab_key) { return $c['tab'] === $tab_key; });
                    $pass_count = count(array_filter($tab_checks, function ($c) { return $c['status'] === 'pass'; }));
                    $total_count = count($tab_checks);
                    ?>
                    <div class="pylon-seo-checker-summary">
                        <span class="pylon-seo-checker-passed"><?php echo esc_html($pass_count); ?>/<?php echo esc_html($total_count); ?> <?php esc_html_e('passed', 'pylon-seo'); ?></span>
                    </div>
                    <?php foreach ($tab_checks as $check): ?>
                        <div class="pylon-seo-checker-item status-<?php echo esc_attr($check['status']); ?>" data-check="<?php echo esc_attr($check['id']); ?>">
                            <span class="pylon-seo-checker-dot" style="background:<?php echo esc_attr($status_colors[$check['status']] ?? '#9ca3af'); ?>"></span>
                            <div class="pylon-seo-checker-body">
                                <span class="pylon-seo-checker-label"><?php echo esc_html($check['label']); ?></span>
                                <?php if (!empty($check['value'])): ?>
                                    <span class="pylon-seo-checker-value"><?php echo esc_html($check['value']); ?></span>
                                <?php endif; ?>
                                <?php if ($check['status'] !== 'pass' && $check['status'] !== 'info' && !empty($check['suggestion'])): ?>
                                    <div class="pylon-seo-checker-suggestion"><?php echo esc_html($check['suggestion']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            <div class="pylon-seo-checker-content" data-tab-content="issues">
                <div class="pylon-seo-issues-header">
                    <span class="pylon-seo-issues-count"><?php echo count($highlight_issues); ?> <?php esc_html_e('content issues found', 'pylon-seo'); ?></span>
                    <button type="button" class="pylon-seo-highlight-toggle" id="pylon-highlight-toggle" data-active="0">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                        <span id="pylon-highlight-label"><?php esc_html_e('Highlight in editor', 'pylon-seo'); ?></span>
                    </button>
                </div>
                <?php if (empty($highlight_issues)): ?>
                    <div class="pylon-seo-issues-empty">
                        <span style="font-size:24px;display:block;margin-bottom:6px">🎉</span>
                        <?php esc_html_e('No content issues found. Great work!', 'pylon-seo'); ?>
                    </div>
                <?php else: ?>
                    <?php foreach ($highlight_issues as $issue): ?>
                        <div class="pylon-seo-issue-item severity-<?php echo esc_attr($issue['severity']); ?>" data-issue-id="<?php echo esc_attr($issue['id']); ?>" data-type="<?php echo esc_attr($issue['type']); ?>" data-text="<?php echo esc_attr($issue['full_text']); ?>">
                            <span class="pylon-seo-issue-icon"><?php echo esc_html($issue['icon']); ?></span>
                            <div class="pylon-seo-issue-body">
                                <span class="pylon-seo-issue-label"><?php echo esc_html($issue['label']); ?></span>
                                <span class="pylon-seo-issue-text"><?php echo esc_html($issue['text']); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php
            $adv_checks = ['checks' => [], 'scores' => ['eeat' => 0, 'topical' => 0, 'uniqueness' => 0], 'score' => 0];
            if (class_exists('\Pylon\Core\Modules\Content\AdvancedAnalysisEngine')) {
                $adv = new \Pylon\Core\Modules\Content\AdvancedAnalysisEngine($post);
                $adv_data = $adv->get_score_by_tabs();
                $adv_checks = $adv_data;
            }
            $adv_tabs = ['eeat' => ['label' => 'E-E-A-T', 'icon' => '⭐'], 'topical' => ['label' => 'Authority', 'icon' => '🎯'], 'uniqueness' => ['label' => 'Originality', 'icon' => '✨']];
            ?>
            <?php wp_add_inline_script('pylon-admin-js', 'window.pylonAdvancedChecks = ' . wp_json_encode($adv_checks) . ';'); ?>
            <div class="pylon-seo-tab" data-tab="advanced" style="border-top:1px solid #e5e7eb;margin-top:4px;padding-top:4px">
                <span class="pylon-seo-tab-icon">⭐</span>
                <span class="pylon-seo-tab-label"><?php esc_html_e('Advanced', 'pylon-seo'); ?></span>
                <span class="pylon-seo-tab-score" style="background:#6366f1"><?php echo esc_html($adv_checks['scores']['eeat'] ?? 0); ?>-<?php echo esc_html($adv_checks['scores']['topical'] ?? 0); ?>-<?php echo esc_html($adv_checks['scores']['uniqueness'] ?? 0); ?></span>
            </div>
            <div class="pylon-seo-checker-content" data-tab-content="advanced">
                <div class="pylon-adv-scores">
                    <?php foreach ($adv_tabs as $akey => $ainfo): ?>
                        <div class="pylon-adv-score-card">
                            <span class="pylon-adv-score-icon"><?php echo esc_html($ainfo['icon']); ?></span>
                            <span class="pylon-adv-score-val" style="color:<?php echo ($adv_checks['scores'][$akey] ?? 0) >= 80 ? '#22c55e' : (($adv_checks['scores'][$akey] ?? 0) >= 60 ? '#f59e0b' : '#ef4444'); ?>"><?php echo (int)($adv_checks['scores'][$akey] ?? 0); ?></span>
                            <span class="pylon-adv-score-lbl"><?php echo esc_html($ainfo['label']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="pylon-adv-tabs" id="pylon-adv-tabs">
                    <?php foreach ($adv_tabs as $akey => $ainfo): ?>
                        <div class="pylon-seo-tab<?php echo $akey === 'eeat' ? ' active' : ''; ?>" data-adv-tab="<?php echo esc_attr($akey); ?>">
                            <span class="pylon-seo-tab-icon"><?php echo esc_html($ainfo['icon']); ?></span>
                            <span class="pylon-seo-tab-label"><?php echo esc_html($ainfo['label']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php foreach ($adv_tabs as $akey => $ainfo): ?>
                    <div class="pylon-adv-content<?php echo $akey === 'eeat' ? ' active' : ''; ?>" data-adv-content="<?php echo esc_attr($akey); ?>">
                        <?php
                        $a_checks = array_filter($adv_checks['checks'], function ($c) use ($akey) { return ($c['tab'] ?? 'eeat') === $akey; });
                        $a_pass = count(array_filter($a_checks, function ($c) { return $c['status'] === 'pass'; }));
                        $a_total = count($a_checks);
                        ?>
                        <div class="pylon-seo-checker-summary">
                            <span class="pylon-seo-checker-passed"><?php echo esc_html($a_pass); ?>/<?php echo esc_html($a_total); ?> <?php esc_html_e('passed', 'pylon-seo'); ?></span>
                        </div>
                        <?php foreach ($a_checks as $check): ?>
                            <div class="pylon-seo-checker-item status-<?php echo esc_attr($check['status']); ?>">
                                <span class="pylon-seo-checker-dot" style="background:<?php echo esc_attr($status_colors[$check['status']] ?? '#9ca3af'); ?>"></span>
                                <div class="pylon-seo-checker-body">
                                    <span class="pylon-seo-checker-label"><?php echo esc_html($check['label']); ?></span>
                                    <?php if (!empty($check['value'])): ?>
                                        <span class="pylon-seo-checker-value"><?php echo esc_html($check['value']); ?></span>
                                    <?php endif; ?>
                                    <?php if ($check['status'] !== 'pass' && !empty($check['suggestion'])): ?>
                                        <div class="pylon-seo-checker-suggestion"><?php echo esc_html($check['suggestion']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php
        // Legacy checks hidden — kept for admin.js real-time score data flow
        echo '<div style="display:none" id="pylon-legacy-checks">';
        $this->analysis_check('keyword', $kw_set, __('Focus keyword is set', 'pylon-seo'), $kw_set ? '' : __('Add a focus keyword', 'pylon-seo'), 'high');
        /* translators: %s: focus keyword. */
        $this->analysis_check('kw_in_title', $kw_in_title, __('Keyword in SEO title', 'pylon-seo'), $kw_in_title ? sprintf(__('Found: %s', 'pylon-seo'), $kw) : __('Add keyword to title', 'pylon-seo'), 'high');
        /* translators: %s: focus keyword. */
        $this->analysis_check('kw_in_desc', $kw_in_desc, __('Keyword in meta description', 'pylon-seo'), $kw_in_desc ? sprintf(__('Found: %s', 'pylon-seo'), $kw) : __('Add keyword to description', 'pylon-seo'), 'medium');
        /* translators: %d: number of times the keyword is found. */
        $this->analysis_check('kw_in_content', $kw_in_content, __('Keyword in content', 'pylon-seo'), $kw_in_content ? sprintf(__('Found %d time(s)', 'pylon-seo'), substr_count(mb_strtolower(wp_strip_all_tags($content)), mb_strtolower($kw))) : __('Use keyword in body text', 'pylon-seo'), 'medium');
        /* translators: %s: URL slug. */
        $this->analysis_check('kw_in_slug', $kw_in_slug, __('Keyword in URL', 'pylon-seo'), $kw_in_slug ? sprintf(__('Found: %s', 'pylon-seo'), $slug) : __('Add keyword to URL slug', 'pylon-seo'), 'low');
        /* translators: %d: title length in characters. */
        $this->analysis_check('title_len', $title_ok, __('Title length optimal', 'pylon-seo'), sprintf(__('%d / 10–70 chars', 'pylon-seo'), $tlen));
        /* translators: %d: description length in characters. */
        $this->analysis_check('desc_len', $desc_ok, __('Description length optimal', 'pylon-seo'), sprintf(__('%d / 50–160 chars', 'pylon-seo'), $dlen));
        /* translators: %d: word count. */
        $this->analysis_check('content_words', $content_ok, __('Content 300+ words', 'pylon-seo'), sprintf(__('%d words', 'pylon-seo'), $word_count));
        $this->analysis_check('headings', $has_headings, __('Headings used', 'pylon-seo'), $has_headings ? '' : __('Add headings', 'pylon-seo'));
        $this->analysis_check('images', $has_images, __('Images used', 'pylon-seo'), $has_images ? '' : __('Add images', 'pylon-seo'));
        $this->analysis_check('canonical', $has_canonical, __('Canonical URL set', 'pylon-seo'), $has_canonical ? '' : __('Not set', 'pylon-seo'));
        $this->analysis_check('noindex', !$noindex, __('Indexing enabled', 'pylon-seo'), $noindex ? __('Disabled (noindex)', 'pylon-seo') : '');
        echo '</div>';
        ?>
        <?php wp_add_inline_script('pylon-admin-js', <<<'JS'
        (function(){
            var tabs = document.querySelectorAll('#pylon-seo-checker .pylon-seo-tab[data-tab]');
            var contents = document.querySelectorAll('#pylon-seo-checker [data-tab-content]');
            tabs.forEach(function(tab){
                tab.addEventListener('click', function(){
                    var target = this.getAttribute('data-tab');
                    tabs.forEach(function(t){ t.classList.remove('active'); });
                    contents.forEach(function(c){ c.classList.remove('active'); });
                    this.classList.add('active');
                    var panel = document.querySelector('[data-tab-content="'+target+'"]');
                    if(panel) panel.classList.add('active');
                });
            });

            var advTabs = document.querySelectorAll('[data-adv-tab]');
            var advContents = document.querySelectorAll('[data-adv-content]');
            advTabs.forEach(function(tab){
                tab.addEventListener('click', function(){
                    var target = this.getAttribute('data-adv-tab');
                    advTabs.forEach(function(t){ t.classList.remove('active'); });
                    advContents.forEach(function(c){ c.classList.remove('active'); });
                    this.classList.add('active');
                    var panel = document.querySelector('[data-adv-content="'+target+'"]');
                    if(panel) panel.classList.add('active');
                });
            });

            var toggle = document.getElementById('pylon-highlight-toggle');
            if (!toggle) return;
            toggle.addEventListener('click', function(){
                var active = this.getAttribute('data-active') === '1';
                var label = document.getElementById('pylon-highlight-label');
                if (active) {
                    pylonRemoveHighlights();
                    this.setAttribute('data-active', '0');
                    this.classList.remove('active');
                    if (label) label.textContent = 'Highlight in editor';
                } else {
                    pylonApplyHighlights();
                    this.setAttribute('data-active', '1');
                    this.classList.add('active');
                    if (label) label.textContent = 'Remove highlights';
                }
            });

            function pylonApplyHighlights() {
                var issues = window.pylonHighlightIssues || [];
                if (!issues.length) return;
                var editor = null;
                if (typeof tinymce !== 'undefined') editor = tinymce.get('content');
                if (!editor || !editor.getBody) return;

                issues.forEach(function(issue) {
                    if (!issue.full_text || issue.full_text.length < 10) return;
                    var search = pylonEscapeRegex(issue.full_text.substring(0, 80));
                    try {
                        var body = editor.getBody();
                        var walker = document.createTreeWalker(body, NodeFilter.SHOW_TEXT, null, false);
                        var node;
                        while (node = walker.nextNode()) {
                            var idx = node.nodeValue.indexOf(issue.full_text.substring(0, 80));
                            if (idx === -1) continue;
                            var range = editor.dom.createRng();
                            range.setStart(node, idx);
                            range.setEnd(node, idx + issue.full_text.length);
                            var mark = editor.dom.create('mark', {
                                'data-pylon-highlight': issue.id,
                                'data-pylon-type': issue.type,
                                'title': issue.label,
                                'style': 'background:' + (issue.severity === 'fail' ? '#fecaca' : '#fef08a') + ';border-bottom:2px solid ' + (issue.severity === 'fail' ? '#ef4444' : '#f59e0b') + ';cursor:pointer;padding:0 1px;border-radius:2px;'
                            });
                            range.surroundContents(mark);
                            break;
                        }
                    } catch(e) {}
                });
            }

            function pylonRemoveHighlights() {
                var editor = null;
                if (typeof tinymce !== 'undefined') editor = tinymce.get('content');
                if (!editor || !editor.getBody) return;
                var marks = editor.getBody().querySelectorAll('mark[data-pylon-highlight]');
                marks.forEach(function(m) {
                    var parent = m.parentNode;
                    while (m.firstChild) parent.insertBefore(m.firstChild, m);
                    parent.removeChild(m);
                });
            }

            function pylonEscapeRegex(str) {
                return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            }
        })();
        JS
        ); ?>
        </div>
        <?php
    }

    private function fetch_url_content(string $url): string {
        $resp = \Pylon\Core\HttpClient::get_json($url, ['timeout' => 10]);
        if (!$resp['success'] || !is_string($resp['body'])) {
            return '';
        }
        $html = $resp['body'];
        $text = wp_strip_all_tags($html);
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    private function get_page_builder_content(\WP_Post $post): array {
        $result = ['text' => '', 'heading_count' => 0, 'image_count' => 0];
        $raw = $post->post_content;

        // Step 1: Try raw post content directly (works in admin where the_content filter may not render).
        $raw_text = wp_strip_all_tags($raw);
        if (str_word_count($raw_text) >= 75) {
            $result['text'] = $raw_text;
            $result['heading_count'] = preg_match_all('/<h[1-6][^>]*>/i', $raw);
            $result['image_count'] = preg_match_all('/<img[^>]+>/i', $raw);
            return $result;
        }

        // Step 2: Try the_content filter (works on frontend when page builder hooks into it).
        $rendered = apply_filters('the_content', $raw);
        $text = wp_strip_all_tags($rendered);
        $heading_count = preg_match_all('/<h[1-6][^>]*>/i', $rendered);
        $image_count = preg_match_all('/<img[^>]+>/i', $rendered);

        if (str_word_count($text) >= 75) {
            $result['text'] = $text;
            $result['heading_count'] = $heading_count;
            $result['image_count'] = $image_count;
            return $result;
        }

        // Step 3: If post uses Elementor, use its own renderer to capture content
        // including dynamic content (ACF, forms, maps, custom widgets) that isn't
        // present in the _elementor_data JSON. Works in admin too (the_content filter
        // isn't registered there).
        if (get_post_meta($post->ID, '_elementor_edit_mode', true) === 'builder' && class_exists('\Elementor\Plugin')) {
            try {
                $el_rendered = \Elementor\Plugin::$instance->frontend->get_builder_content($post->ID, false);
                if (empty($el_rendered) || str_word_count(wp_strip_all_tags($el_rendered)) < 10) {
                    // Try document renderer as fallback for custom widgets.
                    $doc = \Elementor\Plugin::$instance->documents->get($post->ID);
                    if ($doc) {
                        $el_rendered = $doc->get_content();
                    }
                }
                if (!empty($el_rendered)) {
                    $el_text = wp_strip_all_tags($el_rendered);
                    if (str_word_count($el_text) >= 10) {
                        $result['text'] = $el_text;
                        $result['heading_count'] = preg_match_all('/<h[1-6][^>]*>/i', $el_rendered);
                        $result['image_count'] = preg_match_all('/<img[^>]+>/i', $el_rendered);
                        return $result;
                    }
                }
            } catch (\Exception $e) {
                error_log('Pylon MetaEngine Elementor render failed: ' . $e->getMessage());
            }
        }

        // Step 4: Extract from _elementor_data JSON (static content, no dynamic values).
        $el_data = get_post_meta($post->ID, '_elementor_data', true);
        if (!empty($el_data)) {
            $el_arr = is_string($el_data) ? json_decode($el_data, true) : $el_data;
            if (is_array($el_arr)) {
                $el_text = $this->extract_elementor_text($el_arr);
                if (!empty($el_text)) {
                    $result['text'] = $el_text;
                } else {
                    // Fallback: extract all strings for custom widgets with unknown keys.
                    $el_text = $this->extract_builder_json_text($el_arr);
                    if (empty($el_text)) {
                        // Last resort: grab every non-empty string value regardless of key.
                        $el_text = $this->extract_all_text($el_arr);
                    }
                    if (!empty($el_text)) {
                        $result['text'] = $el_text;
                    }
                }
                $el_el = $this->count_elementor_structure($el_arr);
                $result['heading_count'] = max($result['heading_count'], $el_el['headings']);
                $result['image_count'] = max($result['image_count'], $el_el['images']);
            }
            return $result;
        }

        // Step 5: Beaver Builder fallback.
        $bb_data = get_post_meta($post->ID, '_fl_builder_data', true);
        if (is_string($bb_data)) {
            $bb_data = json_decode($bb_data, true);
        }
        if (!empty($bb_data) && is_array($bb_data)) {
            $bb_text = $this->extract_beaver_text($bb_data);
            if (!empty($bb_text)) {
                $result['text'] = $bb_text;
            }
            $bb_el = $this->count_beaver_structure($bb_data);
            $result['heading_count'] = max($result['heading_count'], $bb_el['headings']);
            $result['image_count'] = max($result['image_count'], $bb_el['images']);
            return $result;
        }

        // Step 6: Shortcode-based page builders (Divi, WPBakery, Cornerstone, Oxygen).
        $shortcode_pb = false;
        $shortcode_pb = $shortcode_pb || get_post_meta($post->ID, '_et_builder_version', true);
        $shortcode_pb = $shortcode_pb || get_post_meta($post->ID, '_wpb_vc_js_status', true);
        $shortcode_pb = $shortcode_pb || get_post_meta($post->ID, '_cornerstone_data', true);
        $shortcode_pb = $shortcode_pb || get_post_meta($post->ID, '_cs_data', true);
        $shortcode_pb = $shortcode_pb || get_post_meta($post->ID, 'ct_builder_shortcodes', true);
        if ($shortcode_pb) {
            // Remove shortcode tags but keep inner content
            $stripped = preg_replace('/\[(\/)?(et_pb_|vc_|cs_|ct_|wpb_)[^\]]*\]/', ' ', $raw);
            if (str_word_count(wp_strip_all_tags($stripped)) < 10) {
                // More aggressive: remove all known-page-builder shortcodes
                $stripped = preg_replace('/\[(\/)?(et_pb_|vc_|cs_|ct_|wpb_|rev_slider|layerslider)[^\]]*\]/', ' ', $raw);
                $stripped = preg_replace('/\[(\/)?(et_|vc_|cs_|ct_|wpb_)[^\]]*\]/', ' ', $stripped);
            }
            $pb_text = wp_strip_all_tags($stripped);
            if (str_word_count($pb_text) >= 10) {
                $result['text'] = $pb_text;
                $result['heading_count'] = max(preg_match_all('/<h[1-6][^>]*>/i', $raw), $heading_count);
                $result['image_count'] = max(preg_match_all('/<img[^>]+>/i', $raw), $image_count);
                return $result;
            }
        }

        // Step 7: JSON-structure page builders (Bricks, Breakdance).
        $bricks_data = get_post_meta($post->ID, 'bricks_data', true);
        if (!empty($bricks_data) && is_array($bricks_data)) {
            $bx_text = $this->extract_builder_json_text($bricks_data);
            if (!empty($bx_text)) {
                $result['text'] = $bx_text;
                return $result;
            }
        }
        $breakdance_data = get_post_meta($post->ID, 'breakdance_data', true);
        if (!empty($breakdance_data) && is_array($breakdance_data)) {
            $bd_text = $this->extract_builder_json_text($breakdance_data);
            if (!empty($bd_text)) {
                $result['text'] = $bd_text;
                return $result;
            }
        }

        // Step 8: Brizy page builder (compiled HTML or JSON).
        $brizy_data = get_post_meta($post->ID, 'brizy', true);
        if (!empty($brizy_data)) {
            if (is_string($brizy_data)) {
                $brizy_arr = json_decode($brizy_data, true);
            } else {
                $brizy_arr = $brizy_data;
            }
            if (is_array($brizy_arr)) {
                // Compiled HTML contains rendered content
                $compiled = $brizy_arr['compiled_html'] ?? '';
                if (!empty($compiled)) {
                    $compiled = base64_decode($compiled, true);
                    if ($compiled === false) $compiled = $brizy_arr['compiled_html'];
                }
                if (!empty($compiled)) {
                    $bz_text = wp_strip_all_tags($compiled);
                    if (str_word_count($bz_text) >= 10) {
                        $result['text'] = $bz_text;
                        $result['heading_count'] = preg_match_all('/<h[1-6][^>]*>/i', $compiled);
                        $result['image_count'] = preg_match_all('/<img[^>]+>/i', $compiled);
                        return $result;
                    }
                }
                // Fall back to extracting from all text fields in Brizy JSON
                $bz_text = $this->extract_builder_json_text($brizy_arr);
                if (!empty($bz_text)) {
                    $result['text'] = $bz_text;
                    return $result;
                }
            }
        }

        // Step 8: Whatever the_content gave us.
        $result['text'] = $text;
        $result['heading_count'] = $heading_count;
        $result['image_count'] = $image_count;
        return $result;
    }

    private function extract_builder_json_text(array $data, array $text_keys = []): string {
        $text = '';
        $default_keys = ['text', 'content', 'title', 'heading', 'subheading', 'caption', 'description', 'editor', 'html', 'name', 'label', 'placeholder', 'alt', 'value', 'buttonText', 'linkText', 'address', 'phone', 'email'];
        $keys = !empty($text_keys) ? $text_keys : $default_keys;

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $text .= ' ' . $this->extract_builder_json_text($value, $text_keys ?: $default_keys);
            } elseif (is_string($value) && !empty(trim($value))) {
                if (in_array($key, $keys, true) || is_numeric($key)) {
                    $text .= ' ' . trim(wp_strip_all_tags($value));
                }
            }
        }
        return trim($text);
    }

    private function extract_all_text(array $data): string {
        $text = '';
        foreach ($data as $value) {
            if (is_array($value)) {
                $text .= ' ' . $this->extract_all_text($value);
            } elseif (is_string($value) && !empty(trim($value))) {
                $text .= ' ' . trim(wp_strip_all_tags($value));
            }
        }
        return trim($text);
    }

    private function extract_elementor_text(array $elements): string {
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
                            if (is_array($item)) {
                                $text .= ' ' . $this->extract_elementor_text([$item]);
                            }
                        }
                    }
                }
            }
            if (isset($element['elements']) && is_array($element['elements'])) {
                $text .= ' ' . $this->extract_elementor_text($element['elements']);
            }
        }
        return trim($text);
    }

    private function count_elementor_structure(array $elements): array {
        $headings = 0;
        $images = 0;
        foreach ($elements as $el) {
            if (isset($el['widgetType'])) {
                if (preg_match('/^heading$/i', $el['widgetType'])) $headings++;
                if (preg_match('/image|icon|gallery|media|photo|picture/i', $el['widgetType'])) $images++;
            }
            if (!empty($el['elements'])) {
                $sub = $this->count_elementor_structure($el['elements']);
                $headings += $sub['headings'];
                $images += $sub['images'];
            }
        }
        return ['headings' => $headings, 'images' => $images];
    }

    private function extract_beaver_text(array $nodes): string {
        $text = '';
        foreach ($nodes as $node) {
            // Beaver Builder nodes are objects (stdClass when unserialized),
            // not arrays. Handle both shapes defensively.
            if (is_object($node)) {
                $settings = isset($node->settings) ? $node->settings : $node;
            } elseif (is_array($node)) {
                $settings = $node['settings'] ?? $node;
            } else {
                continue;
            }

            // $settings itself may be an object or array.
            if (is_object($settings)) {
                $get = fn ($key) => property_exists($settings, $key) ? $settings->$key : '';
            } elseif (is_array($settings)) {
                $get = fn ($key) => $settings[$key] ?? '';
            } else {
                continue;
            }

            foreach (['text', 'content', 'title', 'html', 'editor', 'text_field'] as $field) {
                $val = $get($field);
                if ($val) {
                    $text .= ' ' . wp_strip_all_tags($val);
                }
            }

            // Recurse into child nodes if present.
            if (is_object($node) && isset($node->nodes) && is_array($node->nodes)) {
                $text .= ' ' . $this->extract_beaver_text($node->nodes);
            } elseif (is_array($node) && !empty($node['nodes'])) {
                $text .= ' ' . $this->extract_beaver_text($node['nodes']);
            }
        }
        return trim($text);
    }

    private function count_beaver_structure(array $nodes): array {
        $headings = 0;
        $images = 0;
        foreach ($nodes as $node) {
            // Handle both object and array node shapes.
            $type = '';
            if (is_object($node)) {
                $settings = isset($node->settings) ? $node->settings : $node;
                $type = is_object($settings) && property_exists($settings, 'type') ? $settings->type : '';
            } elseif (is_array($node)) {
                $settings = $node['settings'] ?? $node;
                $type = $settings['type'] ?? '';
            }

            if ($type && preg_match('/^heading$/i', $type)) $headings++;
            if ($type && preg_match('/photo|image|gallery|icon/i', $type)) $images++;
        }
        return ['headings' => $headings, 'images' => $images];
    }

    private function analysis_check(string $id, bool $pass, string $label, string $note = '', string $impact = ''): void {
        $impact_cls = $impact ? ' impact-' . esc_attr($impact) : '';
        ?>
        <div class="pylon-adash-i <?php echo $pass ? 'ok' : 'no'; ?><?php echo esc_attr($impact_cls); ?>" data-check="<?php echo esc_attr($id); ?>">
            <span class="pylon-adash-i-dot"></span>
            <span class="pylon-adash-i-lbl"><?php echo esc_html($label); ?></span>
            <?php if ($note): ?>
                <span class="pylon-adash-i-note"><?php echo esc_html($note); ?></span>
            <?php endif; ?>
        </div>
        <?php
    }

    public function save_meta_box(int $post_id): void {
        if (!isset($_POST['pylon_meta_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['pylon_meta_nonce'])), 'pylon_meta')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $fields = [
            'pylon_title', 'pylon_description', 'pylon_focus_keyword',
            'pylon_canonical', 'pylon_og_title', 'pylon_og_description',
            'pylon_og_image', 'pylon_twitter_title', 'pylon_twitter_description',
            'pylon_twitter_image', 'pylon_schema_type',
        ];

        foreach ($fields as $key) {
            if (isset($_POST[$key])) {
                if (in_array($key, ['pylon_canonical', 'pylon_og_image', 'pylon_twitter_image'], true)) {
                    update_post_meta($post_id, $key, esc_url_raw(wp_unslash($_POST[$key])));
                } elseif (in_array($key, ['pylon_description', 'pylon_og_description', 'pylon_twitter_description'], true)) {
                    update_post_meta($post_id, $key, sanitize_textarea_field(wp_unslash($_POST[$key])));
                } else {
                    update_post_meta($post_id, $key, sanitize_text_field(wp_unslash($_POST[$key])));
                }
            }
        }

        foreach (['pylon_noindex', 'pylon_nofollow'] as $key) {
            if (isset($_POST[$key])) {
                update_post_meta($post_id, $key, '1');
            } else {
                delete_post_meta($post_id, $key);
            }
        }

        if (get_option('pylon_cornerstone_enabled', '1')) {
            if (isset($_POST['pylon_cornerstone_content'])) {
                update_post_meta($post_id, 'pylon_cornerstone_content', '1');
            } else {
                delete_post_meta($post_id, 'pylon_cornerstone_content');
            }
        }
    }

    public function filter_title(string $title): string {
        if (is_singular() || is_category() || is_tag() || is_tax()) {
            $custom = $this->get_meta_value('pylon_title');
            if ($custom) return $custom;
        }
        if (get_option('pylon_capitalize_titles')) {
            $title = $this->capitalize_title($title);
        }
        return $title;
    }

    private function capitalize_title(string $title): string {
        $small = ['a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'if', 'in', 'nor', 'of', 'on', 'or', 'per', 'so', 'the', 'to', 'up', 'via', 'with', 'from', 'over', 'under', 'vs'];
        $words = preg_split('/\s+/', trim($title));
        if (empty($words)) return $title;
        foreach ($words as $i => $word) {
            if ($word === '') continue;
            if ($i === 0 || !in_array(mb_strtolower($word), $small, true)) {
                $words[$i] = mb_strtoupper(mb_substr($word, 0, 1)) . mb_substr($word, 1);
            }
        }
        return implode(' ', $words);
    }

    private function get_meta_value(string $key): ?string {
        $id = get_queried_object_id();
        if (is_singular()) {
            $value = get_post_meta($id, $key, true);
            return $value ?: null;
        }
        if (is_category() || is_tag() || is_tax()) {
            $term_key = str_replace('pylon_', 'pylon_term_', $key);
            $value = get_term_meta($id, $term_key, true);
            return $value ?: null;
        }
        return null;
    }

    private function get_excerpt(): string {
        if (is_singular()) {
            $post = get_queried_object();
            $excerpt = get_the_excerpt($post);
            if ($excerpt) return $excerpt;
            return wp_trim_words(get_the_content($post), 20);
        }
        if (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();
            if ($term && !empty($term->description)) {
                return wp_trim_words($term->description, 20);
            }
        }
        return get_bloginfo('description');
    }

    private function get_default_image(?int $post_id = null): ?string {
        if ($post_id && has_post_thumbnail($post_id)) {
            $url = wp_get_attachment_image_url(get_post_thumbnail_id($post_id), 'full');
            if ($url) return $url;
        }
        $custom_logo_id = get_theme_mod('custom_logo');
        if ($custom_logo_id) {
            return wp_get_attachment_url($custom_logo_id);
        }
        return null;
    }

    private function get_og_type(): string {
        if (is_singular('post')) return 'article';
        if (is_singular('product')) return 'product';
        return 'website';
    }

    private function get_twitter_card_type(): string {
        $post_id = get_queried_object_id();
        if (!$post_id) return 'summary';

        $og_image = get_post_meta($post_id, 'pylon_og_image', true);
        if (!$og_image) {
            $og_image = get_the_post_thumbnail_url($post_id, 'full');
        }

        if (empty($og_image)) {
            return 'summary';
        }

        if (is_singular('video')) {
            return 'player';
        }

        return 'summary_large_image';
    }

    public function add_cornerstone_column(array $columns): array {
        if (!get_option('pylon_cornerstone_enabled', '1')) return $columns;
        $columns['pylon_cornerstone'] = '<span title="' . esc_attr__('Cornerstone Content', 'pylon-seo') . '">💎</span>';
        return $columns;
    }

    public function render_cornerstone_column(string $column_name, int $post_id): void {
        if ($column_name !== 'pylon_cornerstone') return;
        if (get_post_meta($post_id, 'pylon_cornerstone_content', true) === '1') {
            echo '<span style="font-size:16px;" title="' . esc_attr__('Cornerstone Content', 'pylon-seo') . '">💎</span>';
        } else {
            echo '<span style="color:var(--pylon-gray-300);">—</span>';
        }
    }

    public function render_cornerstone_filter(string $post_type): void {
        if (!get_option('pylon_cornerstone_enabled', '1')) return;
        $selected = isset($_GET['pylon_cornerstone']) ? sanitize_key(wp_unslash($_GET['pylon_cornerstone'])) : '';
        ?>
        <select name="pylon_cornerstone">
            <option value=""><?php esc_html_e('All posts', 'pylon-seo'); ?></option>
            <option value="yes" <?php selected($selected, 'yes'); ?>><?php esc_html_e('Cornerstone only', 'pylon-seo'); ?></option>
            <option value="no" <?php selected($selected, 'no'); ?>><?php esc_html_e('Non-cornerstone only', 'pylon-seo'); ?></option>
        </select>
        <?php
    }

    public function apply_cornerstone_filter(\WP_Query $query): void {
        if (!is_admin() || !$query->is_main_query()) return;
        if (!get_option('pylon_cornerstone_enabled', '1')) return;
        $filter = isset($_GET['pylon_cornerstone']) ? sanitize_key($_GET['pylon_cornerstone']) : '';
        if ($filter === 'yes') {
            $meta_query = $query->get('meta_query', []);
            $meta_query[] = ['key' => 'pylon_cornerstone_content', 'value' => '1'];
            $query->set('meta_query', $meta_query);
        } elseif ($filter === 'no') {
            $meta_query = $query->get('meta_query', []);
            $meta_query[] = [
                'relation' => 'OR',
                ['key' => 'pylon_cornerstone_content', 'compare' => 'NOT EXISTS'],
                ['key' => 'pylon_cornerstone_content', 'value' => '1', 'compare' => '!='],
            ];
            $query->set('meta_query', $meta_query);
        }
    }
}
