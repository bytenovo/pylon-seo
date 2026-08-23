<?php
namespace Pylon\Core\Modules\Onboarding;
defined('ABSPATH') || exit;
class OnboardingWizard {
    private const COMPLETE_OPTION = 'pylon_onboarding_complete';
    private const SKIP_OPTION = 'pylon_onboarding_skipped';

    public function register(): void {
        add_action('admin_menu', [$this, 'add_admin_page']);

        if ($this->is_wizard_page()) {
            add_action('admin_init', [$this, 'handle_save'], 1);
        }

        if (!wp_doing_ajax() && !wp_doing_cron()) {
            add_action('admin_init', [$this, 'maybe_redirect_to_wizard'], 5);
        }
    }

    public function add_admin_page(): void {
        add_submenu_page(
            null,
            __('Setup Wizard', 'pylon-seo'),
            __('Setup Wizard', 'pylon-seo'),
            'manage_options',
            'pylon-setup',
            [$this, 'render_page']
        );
    }

    public function is_wizard_page(): bool {
        return is_admin() && isset($_GET['page']) && sanitize_key(wp_unslash($_GET['page'])) === 'pylon-setup';
    }

    public function should_redirect_to_wizard(): bool {
        if (defined('DOING_AJAX') && DOING_AJAX) return false;
        if (defined('DOING_CRON') && DOING_CRON) return false;
        if (!current_user_can('manage_options')) return false;

        $complete = get_option(self::COMPLETE_OPTION, false);
        $skipped = get_option(self::SKIP_OPTION, false);

        return !$complete && !$skipped;
    }

    public function maybe_redirect_to_wizard(): void {
        if (!$this->should_redirect_to_wizard()) return;

        global $pagenow;
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($pagenow === 'admin.php' && $page === 'pylon-setup') return;
        if ($pagenow === 'admin.php' && $page !== '' && strpos($page, 'pylon') === 0) return;
        if (isset($_GET['setup']) && sanitize_key(wp_unslash($_GET['setup'])) === 'skip') {
            update_option(self::SKIP_OPTION, true);
            return;
        }

        wp_safe_redirect(admin_url('admin.php?page=pylon-setup'));
        exit;
    }

    public function handle_save(): void {
        if (!isset($_POST['pylon_onboarding_step'])) return;
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? '')), 'pylon_onboarding')) {
            wp_die(esc_html__('Security check failed.', 'pylon-seo'));
        }
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized.', 'pylon-seo'));
        }

        $step = absint($_POST['pylon_onboarding_step'] ?? 0);
        $next = isset($_POST['pylon_onboarding_next']);

        switch ($step) {
            case 2:
                update_option('pylon_sitemap_enabled', isset($_POST['pylon_sitemap_enabled']) ? '1' : '');
                $types = isset($_POST['pylon_sitemap_post_types']) && is_array($_POST['pylon_sitemap_post_types'])
                    ? array_map('sanitize_text_field', wp_unslash($_POST['pylon_sitemap_post_types']))
                    : ['post', 'page'];
                update_option('pylon_sitemap_post_types', implode(',', $types));
                break;
            case 3:
                update_option('pylon_og_enabled', isset($_POST['pylon_og_enabled']) ? '1' : '');
                update_option('pylon_twitter_enabled', isset($_POST['pylon_twitter_enabled']) ? '1' : '');
                update_option('pylon_schema_enabled', isset($_POST['pylon_schema_enabled']) ? '1' : '');
                update_option('pylon_schema_auto_faq', isset($_POST['pylon_schema_auto_faq']) ? '1' : '');
                break;
            case 4:
                update_option('pylon_freshness_enabled', isset($_POST['pylon_freshness_enabled']) ? '1' : '');
                update_option('pylon_author_eaat_enabled', isset($_POST['pylon_author_eaat_enabled']) ? '1' : '');
                update_option('pylon_indexnow_enabled', isset($_POST['pylon_indexnow_enabled']) ? '1' : '');
                update_option('pylon_404_monitor_enabled', isset($_POST['pylon_404_monitor_enabled']) ? '1' : '');
                break;
            case 5:
                update_option(self::COMPLETE_OPTION, true);
                delete_option(self::SKIP_OPTION);
                break;
        }

        if ($next && $step < 5) {
            wp_safe_redirect(admin_url('admin.php?page=pylon-setup&step=' . ($step + 1)));
        } elseif ($step === 5) {
            wp_safe_redirect(admin_url('admin.php?page=pylon&setup=done'));
        } else {
            wp_safe_redirect(admin_url('admin.php?page=pylon-setup&step=' . $step));
        }
        exit;
    }

    public function render_page(): void {
        if (!current_user_can('manage_options')) return;

        $step = min(5, max(1, absint($_GET['step'] ?? 1)));
        $is_skippable = !get_option(self::COMPLETE_OPTION, false);
        ?>
        <div class="wrap pylon-onboarding">
            <div class="pylon-wizard">
                <div class="pylon-text-center pylon-mb-20">
                    <div class="pylon-text-28 pylon-fw-700 pylon-color-primary">Pylon</div>
                    <p class="pylon-text-14 pylon-color-muted"><?php esc_html_e('AI-Powered SEO for WordPress', 'pylon-seo'); ?></p>
                </div>

                <?php $this->render_progress($step); ?>

                <div class="pylon-wizard-content">
                    <form method="post" action="">
                        <?php wp_nonce_field('pylon_onboarding'); ?>
                        <input type="hidden" name="pylon_onboarding_step" value="<?php echo esc_attr($step); ?>">

                        <div class="pylon-fade-in">
                            <?php $this->render_step($step); ?>
                        </div>

                        <div class="pylon-wizard-footer">
                            <div>
                                <?php if ($step > 1): ?>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=pylon-setup&step=' . ($step - 1))); ?>" class="pylon-btn pylon-btn-secondary">← <?php esc_html_e('Previous', 'pylon-seo'); ?></a>
                                <?php endif; ?>
                            </div>
                            <div class="pylon-flex pylon-gap-8 pylon-flex-center">
                                <?php if ($is_skippable): ?>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=pylon&setup=skip')); ?>" class="pylon-text-12 pylon-color-muted" style="text-decoration:none;"><?php esc_html_e('Skip setup', 'pylon-seo'); ?></a>
                                <?php endif; ?>
                                <?php if ($step < 5): ?>
                                    <button type="submit" name="pylon_onboarding_next" value="1" class="pylon-btn pylon-btn-primary"><?php esc_html_e('Next', 'pylon-seo'); ?> →</button>
                                <?php else: ?>
                                    <button type="submit" name="pylon_onboarding_next" value="1" class="pylon-btn pylon-btn-success"><?php esc_html_e('Finish', 'pylon-seo'); ?></button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_progress(int $current): void {
        $steps = [
            1 => __('Welcome', 'pylon-seo'),
            2 => __('Sitemap', 'pylon-seo'),
            3 => __('Social & Schema', 'pylon-seo'),
            4 => __('Content', 'pylon-seo'),
            5 => __('Finish', 'pylon-seo'),
        ];
        ?>
        <div class="pylon-stepper">
            <?php foreach ($steps as $num => $label):
                $cls = $num < $current ? 'pylon-step-completed' : ($num === $current ? 'pylon-step-active' : '');
            ?>
                <div class="pylon-step <?php echo esc_attr($cls); ?>">
                    <div class="pylon-step-circle"><?php echo $num < $current ? '✓' : esc_html($num); ?></div>
                    <div class="pylon-step-label"><?php echo esc_html($label); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private function render_step(int $step): void {
        switch ($step) {
            case 1: $this->render_welcome(); break;
            case 2: $this->render_sitemap(); break;
            case 3: $this->render_social_schema(); break;
            case 4: $this->render_content(); break;
            case 5: $this->render_finish(); break;
        }
    }

    private function render_welcome(): void {
        ?>
        <h2><?php esc_html_e('Welcome to Pylon!', 'pylon-seo'); ?></h2>
        <p><?php esc_html_e('Thank you for installing Pylon — the all-in-one SEO plugin for WordPress. Let us help you get set up in just a few steps.', 'pylon-seo'); ?></p>

        <div class="pylon-wizard-grid">
            <div class="pylon-wizard-feature">
                <span class="pylon-wf-icon">🏷️</span>
                <h4><?php esc_html_e('Meta & Social', 'pylon-seo'); ?></h4>
                <p><?php esc_html_e('Title tags, meta descriptions, Open Graph & Twitter Cards.', 'pylon-seo'); ?></p>
            </div>
            <div class="pylon-wizard-feature">
                <span class="pylon-wf-icon">🗺️</span>
                <h4><?php esc_html_e('XML Sitemap', 'pylon-seo'); ?></h4>
                <p><?php esc_html_e('Automatic sitemap generation for better indexing.', 'pylon-seo'); ?></p>
            </div>
            <div class="pylon-wizard-feature">
                <span class="pylon-wf-icon">🔍</span>
                <h4><?php esc_html_e('Schema Markup', 'pylon-seo'); ?></h4>
                <p><?php esc_html_e('Structured data for rich snippets and AI Overviews.', 'pylon-seo'); ?></p>
            </div>
            <div class="pylon-wizard-feature">
                <span class="pylon-wf-icon">🤖</span>
                <h4><?php esc_html_e('AI Features', 'pylon-seo'); ?></h4>
                <p><?php esc_html_e('AI meta generation, keyword suggestions, content briefs.', 'pylon-seo'); ?></p>
            </div>
            <div class="pylon-wizard-feature">
                <span class="pylon-wf-icon">📊</span>
                <h4><?php esc_html_e('A/B Testing', 'pylon-seo'); ?></h4>
                <p><?php esc_html_e('Test title and description variants for better CTR.', 'pylon-seo'); ?></p>
            </div>
            <div class="pylon-wizard-feature">
                <span class="pylon-wf-icon">🔀</span>
                <h4><?php esc_html_e('Redirects & 404s', 'pylon-seo'); ?></h4>
                <p><?php esc_html_e('Manage redirects and monitor 404 errors.', 'pylon-seo'); ?></p>
            </div>
        </div>

        <p><?php esc_html_e('This quick setup will walk you through the essentials. You can change everything later in Settings.', 'pylon-seo'); ?></p>
        <?php
    }

    private function render_sitemap(): void {
        $enabled = get_option('pylon_sitemap_enabled', '1');
        $saved_types = explode(',', get_option('pylon_sitemap_post_types', 'post,page'));
        $public_types = get_post_types(['public' => true], 'objects');
        ?>
        <h2><?php esc_html_e('XML Sitemap', 'pylon-seo'); ?></h2>
        <p><?php esc_html_e('Pylon generates a clean XML sitemap to help search engines discover your content.', 'pylon-seo'); ?></p>

        <div class="pylon-form-group">
            <div class="pylon-toggle">
                <input type="checkbox" name="pylon_sitemap_enabled" value="1" <?php checked($enabled, '1'); ?> data-pylon-toggle-target="pylon-sitemap-types">
                <span class="pylon-toggle-track"></span>
                <span class="pylon-toggle-label" data-on="<?php esc_attr_e('Enabled', 'pylon-seo'); ?>" data-off="<?php esc_attr_e('Disabled', 'pylon-seo'); ?>"><?php echo $enabled === '1' ? esc_html__('Enabled', 'pylon-seo') : esc_html__('Disabled', 'pylon-seo'); ?></span>
                <span class="pylon-toggle-text"><?php esc_html_e('Generate XML sitemap', 'pylon-seo'); ?></span>
            </div>
        </div>

        <div id="pylon-sitemap-types" class="pylon-form-group" <?php echo $enabled !== '1' ? 'style="display:none"' : ''; ?>>
            <label><?php esc_html_e('Post Types to include', 'pylon-seo'); ?></label>
            <div class="pylon-checkbox-grid">
                <?php 
                $priority = ['post', 'page'];
                usort($public_types, function ($a, $b) use ($priority) {
                    $ai = array_search($a->name, $priority, true);
                    $bi = array_search($b->name, $priority, true);
                    if ($ai !== false && $bi !== false) return $ai - $bi;
                    if ($ai !== false) return -1;
                    if ($bi !== false) return 1;
                    return strcasecmp($a->label, $b->label);
                });
                foreach ($public_types as $pt):
                    $selected = in_array($pt->name, $saved_types, true); ?>
                    <label>
                        <input type="checkbox" name="pylon_sitemap_post_types[]" value="<?php echo esc_attr($pt->name); ?>" <?php checked($selected); ?>>
                        <?php echo esc_html($pt->label); ?>
                        <span class="pylon-text-11 pylon-color-muted">(<?php echo esc_html($pt->name); ?>)</span>
                    </label>
                <?php endforeach; ?>
            </div>
            <span class="pylon-help"><?php esc_html_e('Select which post types to include in the sitemap.', 'pylon-seo'); ?></span>
        </div>
        <?php
    }

    private function render_social_schema(): void {
        $og = get_option('pylon_og_enabled', '1');
        $twitter = get_option('pylon_twitter_enabled', '1');
        $schema = get_option('pylon_schema_enabled', '1');
        $faq = get_option('pylon_schema_auto_faq', '1');
        ?>
        <h2><?php esc_html_e('Social Meta & Schema', 'pylon-seo'); ?></h2>
        <p><?php esc_html_e('Social meta tags help your content look great when shared. Schema markup helps search engines understand your content.', 'pylon-seo'); ?></p>

        <div class="pylon-form-group">
            <div class="pylon-toggle">
                <input type="checkbox" name="pylon_og_enabled" value="1" <?php checked($og, '1'); ?>>
                <span class="pylon-toggle-track"></span>
                <span class="pylon-toggle-label" data-on="<?php esc_attr_e('Enabled', 'pylon-seo'); ?>" data-off="<?php esc_attr_e('Disabled', 'pylon-seo'); ?>"><?php echo $og === '1' ? esc_html__('Enabled', 'pylon-seo') : esc_html__('Disabled', 'pylon-seo'); ?></span>
                <span class="pylon-toggle-text"><?php esc_html_e('Open Graph (Facebook / LinkedIn)', 'pylon-seo'); ?></span>
            </div>
        </div>

        <div class="pylon-form-group">
            <div class="pylon-toggle">
                <input type="checkbox" name="pylon_twitter_enabled" value="1" <?php checked($twitter, '1'); ?>>
                <span class="pylon-toggle-track"></span>
                <span class="pylon-toggle-label" data-on="<?php esc_attr_e('Enabled', 'pylon-seo'); ?>" data-off="<?php esc_attr_e('Disabled', 'pylon-seo'); ?>"><?php echo $twitter === '1' ? esc_html__('Enabled', 'pylon-seo') : esc_html__('Disabled', 'pylon-seo'); ?></span>
                <span class="pylon-toggle-text"><?php esc_html_e('Twitter Cards', 'pylon-seo'); ?></span>
            </div>
        </div>

        <div class="pylon-form-group">
            <div class="pylon-toggle">
                <input type="checkbox" name="pylon_schema_enabled" value="1" <?php checked($schema, '1'); ?>>
                <span class="pylon-toggle-track"></span>
                <span class="pylon-toggle-label" data-on="<?php esc_attr_e('Enabled', 'pylon-seo'); ?>" data-off="<?php esc_attr_e('Disabled', 'pylon-seo'); ?>"><?php echo $schema === '1' ? esc_html__('Enabled', 'pylon-seo') : esc_html__('Disabled', 'pylon-seo'); ?></span>
                <span class="pylon-toggle-text"><?php esc_html_e('Schema Markup (Structured Data)', 'pylon-seo'); ?></span>
            </div>
        </div>

        <div class="pylon-form-group">
            <div class="pylon-toggle">
                <input type="checkbox" name="pylon_schema_auto_faq" value="1" <?php checked($faq, '1'); ?>>
                <span class="pylon-toggle-track"></span>
                <span class="pylon-toggle-label" data-on="<?php esc_attr_e('Enabled', 'pylon-seo'); ?>" data-off="<?php esc_attr_e('Disabled', 'pylon-seo'); ?>"><?php echo $faq === '1' ? esc_html__('Enabled', 'pylon-seo') : esc_html__('Disabled', 'pylon-seo'); ?></span>
                <span class="pylon-toggle-text"><?php esc_html_e('Auto-detect FAQ content', 'pylon-seo'); ?></span>
            </div>
        </div>
        <?php
    }

    private function render_content(): void {
        $freshness = get_option('pylon_freshness_enabled', '1');
        $author_eaat = get_option('pylon_author_eaat_enabled', '1');
        $indexnow = get_option('pylon_indexnow_enabled', '1');
        $monitor = get_option('pylon_404_monitor_enabled', '1');
        ?>
        <h2><?php esc_html_e('Content Features', 'pylon-seo'); ?></h2>
        <p><?php esc_html_e('Additional features to keep your content fresh, track author expertise, and monitor site health.', 'pylon-seo'); ?></p>

        <div class="pylon-form-group">
            <div class="pylon-toggle">
                <input type="checkbox" name="pylon_freshness_enabled" value="1" <?php checked($freshness, '1'); ?>>
                <span class="pylon-toggle-track"></span>
                <span class="pylon-toggle-label" data-on="<?php esc_attr_e('Enabled', 'pylon-seo'); ?>" data-off="<?php esc_attr_e('Disabled', 'pylon-seo'); ?>"><?php echo $freshness === '1' ? esc_html__('Enabled', 'pylon-seo') : esc_html__('Disabled', 'pylon-seo'); ?></span>
                <span class="pylon-toggle-text"><?php esc_html_e('Content Freshness tracking', 'pylon-seo'); ?></span>
            </div>
        </div>

        <div class="pylon-form-group">
            <div class="pylon-toggle">
                <input type="checkbox" name="pylon_author_eaat_enabled" value="1" <?php checked($author_eaat, '1'); ?>>
                <span class="pylon-toggle-track"></span>
                <span class="pylon-toggle-label" data-on="<?php esc_attr_e('Enabled', 'pylon-seo'); ?>" data-off="<?php esc_attr_e('Disabled', 'pylon-seo'); ?>"><?php echo $author_eaat === '1' ? esc_html__('Enabled', 'pylon-seo') : esc_html__('Disabled', 'pylon-seo'); ?></span>
                <span class="pylon-toggle-text"><?php esc_html_e('Author E-E-A-T Profiles', 'pylon-seo'); ?></span>
            </div>
        </div>

        <div class="pylon-form-group">
            <div class="pylon-toggle">
                <input type="checkbox" name="pylon_indexnow_enabled" value="1" <?php checked($indexnow, '1'); ?>>
                <span class="pylon-toggle-track"></span>
                <span class="pylon-toggle-label" data-on="<?php esc_attr_e('Enabled', 'pylon-seo'); ?>" data-off="<?php esc_attr_e('Disabled', 'pylon-seo'); ?>"><?php echo $indexnow === '1' ? esc_html__('Enabled', 'pylon-seo') : esc_html__('Disabled', 'pylon-seo'); ?></span>
                <span class="pylon-toggle-text"><?php esc_html_e('IndexNow instant indexing', 'pylon-seo'); ?></span>
            </div>
        </div>

        <div class="pylon-form-group">
            <div class="pylon-toggle">
                <input type="checkbox" name="pylon_404_monitor_enabled" value="1" <?php checked($monitor, '1'); ?>>
                <span class="pylon-toggle-track"></span>
                <span class="pylon-toggle-label" data-on="<?php esc_attr_e('Enabled', 'pylon-seo'); ?>" data-off="<?php esc_attr_e('Disabled', 'pylon-seo'); ?>"><?php echo $monitor === '1' ? esc_html__('Enabled', 'pylon-seo') : esc_html__('Disabled', 'pylon-seo'); ?></span>
                <span class="pylon-toggle-text"><?php esc_html_e('404 Error Monitoring', 'pylon-seo'); ?></span>
            </div>
        </div>
        <?php
    }

    private function render_finish(): void {
        ?>
        <h2><?php esc_html_e('Setup Complete!', 'pylon-seo'); ?></h2>
        <p><?php esc_html_e('Your site is configured with the essential SEO settings. All features work locally on your server — no external services or accounts required.', 'pylon-seo'); ?></p>

        <div class="pylon-notice pylon-notice-info pylon-mt-16">
            <strong><?php esc_html_e('Summary', 'pylon-seo'); ?></strong>
            <ul style="margin:8px 0 0;padding-left:20px;">
                <li><?php esc_html_e('XML Sitemap is generated automatically', 'pylon-seo'); ?></li>
                <li><?php esc_html_e('Social meta tags are added to your pages', 'pylon-seo'); ?></li>
                <li><?php esc_html_e('Schema markup helps search engines understand your content', 'pylon-seo'); ?></li>
                <li><?php esc_html_e('Redirects and 404 monitoring keep your site healthy', 'pylon-seo'); ?></li>
            </ul>
            <p class="pylon-text-12 pylon-color-muted pylon-mt-8"><?php esc_html_e('You can change any of these settings later from the Pylon Settings page.', 'pylon-seo'); ?></p>
        </div>
        <?php
    }
}
