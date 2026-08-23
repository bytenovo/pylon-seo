<?php
namespace Pylon\Core\Modules\RobotsTxt;

class RobotsTxtEngine {
    public function register(): void {
        add_filter('robots_txt', [$this, 'filter_robots_txt'], 99, 2);
        add_action('init', [$this, 'ensure_robots_rewrite'], 5);
        add_action('template_redirect', [$this, 'maybe_serve_robots'], 0);
        add_action('admin_init', [$this, 'register_settings']);
        if (is_admin()) {
            add_action('admin_menu', [$this, 'add_admin_page']);
            add_action('admin_post_pylon_save_robots_txt', [$this, 'save_robots_txt']);
        }
    }

    /**
     * Subdirectory installs sometimes miss WP's built-in robots.txt detection.
     * Mirror the llms.txt approach with an explicit rewrite.
     */
    public function ensure_robots_rewrite(): void {
        add_rewrite_rule('^robots\.txt$', 'index.php?robots=1', 'top');
        if (get_option('pylon_robots_rules_ver') !== '1.2.1') {
            flush_rewrite_rules(false);
            update_option('pylon_robots_rules_ver', '1.2.1', false);
        }
    }

    /**
     * Fallback when pretty /robots.txt is not recognized (common on subdirectory WAMP).
     */
    public function maybe_serve_robots(): void {
        if (is_robots()) {
            return;
        }
        $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $path = (string) (wp_parse_url($uri, PHP_URL_PATH) ?? '');
        $home_path = (string) (wp_parse_url(home_url('/'), PHP_URL_PATH) ?? '/');
        $home_path = untrailingslashit($home_path);
        if ($home_path !== '' && 0 === strpos($path, $home_path)) {
            $path = substr($path, strlen($home_path)) ?: '/';
        }
        $path = '/' . ltrim($path, '/');
        if (!preg_match('#^/robots\.txt/?$#i', $path)) {
            return;
        }
        if ($this->physical_robots_file()) {
            return;
        }

        status_header(200);
        header('Content-Type: text/plain; charset=utf-8');
        $public = (bool) get_option('blog_public');
        /** @var string $output */
        $output = apply_filters('robots_txt', "User-agent: *\nDisallow:", $public);
        echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    public function register_settings(): void {
        register_setting('pylon_settings', 'pylon_robots_txt', ['sanitize_callback' => [$this, 'sanitize_robots_txt']]);
        register_setting('pylon_settings', 'pylon_ai_bots_enabled', ['sanitize_callback' => 'absint', 'default' => 1]);
    }

    /**
     * Known AI / answer-engine crawlers. Default allow = good for AEO citations.
     *
     * @return array<string, array{label: string, desc: string, default: string}>
     */
    public static function known_ai_bots(): array {
        return [
            'GPTBot' => [
                'label' => 'GPTBot (OpenAI / ChatGPT)',
                'desc' => __('Used by ChatGPT browsing and training-related crawls.', 'pylon-seo'),
                'default' => 'allow',
            ],
            'ChatGPT-User' => [
                'label' => 'ChatGPT-User',
                'desc' => __('User-initiated ChatGPT fetches of your pages.', 'pylon-seo'),
                'default' => 'allow',
            ],
            'OAI-SearchBot' => [
                'label' => 'OAI-SearchBot',
                'desc' => __('OpenAI search indexing bot.', 'pylon-seo'),
                'default' => 'allow',
            ],
            'Google-Extended' => [
                'label' => 'Google-Extended',
                'desc' => __('Controls Gemini / Google AI training use of your content (separate from Googlebot).', 'pylon-seo'),
                'default' => 'allow',
            ],
            'GoogleOther' => [
                'label' => 'GoogleOther',
                'desc' => __('Google product crawlers outside classic search.', 'pylon-seo'),
                'default' => 'allow',
            ],
            'ClaudeBot' => [
                'label' => 'ClaudeBot (Anthropic)',
                'desc' => __('Anthropic Claude crawler.', 'pylon-seo'),
                'default' => 'allow',
            ],
            'anthropic-ai' => [
                'label' => 'anthropic-ai',
                'desc' => __('Legacy Anthropic user-agent.', 'pylon-seo'),
                'default' => 'allow',
            ],
            'PerplexityBot' => [
                'label' => 'PerplexityBot',
                'desc' => __('Perplexity AI search crawler.', 'pylon-seo'),
                'default' => 'allow',
            ],
            'Bytespider' => [
                'label' => 'Bytespider (ByteDance)',
                'desc' => __('ByteDance / TikTok-related crawler.', 'pylon-seo'),
                'default' => 'disallow',
            ],
            'CCBot' => [
                'label' => 'CCBot (Common Crawl)',
                'desc' => __('Common Crawl — often used for open datasets / model training.', 'pylon-seo'),
                'default' => 'disallow',
            ],
            'Applebot-Extended' => [
                'label' => 'Applebot-Extended',
                'desc' => __('Apple Intelligence / Apple AI training (separate from Applebot search).', 'pylon-seo'),
                'default' => 'allow',
            ],
            'meta-externalagent' => [
                'label' => 'meta-externalagent',
                'desc' => __('Meta AI external content agent.', 'pylon-seo'),
                'default' => 'allow',
            ],
        ];
    }

    public function get_bot_policies(): array {
        $saved = get_option('pylon_ai_bot_policies', []);
        if (!is_array($saved)) {
            $saved = [];
        }
        $out = [];
        foreach (self::known_ai_bots() as $ua => $meta) {
            $val = $saved[$ua] ?? $meta['default'];
            $out[$ua] = in_array($val, ['allow', 'disallow'], true) ? $val : $meta['default'];
        }
        return $out;
    }

    public function sanitize_robots_txt(string $value): string {
        $value = wp_check_invalid_utf8($value);
        $value = wp_strip_all_tags($value);
        $value = preg_replace('/[^\x20-\x7E\x0A\x0D\x09]/', '', $value);
        return trim($value);
    }

    public function filter_robots_txt(string $output, bool $public): string {
        if (!$public) {
            return $output;
        }
        if ($this->physical_robots_file()) {
            return $output;
        }
        $content = get_option('pylon_robots_txt', '');
        $base = trim($content) !== '' ? $content : $output;
        $bots = $this->build_ai_bot_rules();
        if ($bots === '') {
            return $base;
        }
        return rtrim($base) . "\n\n# Pylon SEO — AI crawler rules\n" . $bots;
    }

    public function build_ai_bot_rules(): string {
        if (!get_option('pylon_ai_bots_enabled', '1')) {
            return '';
        }
        $lines = [];
        foreach ($this->get_bot_policies() as $ua => $policy) {
            $lines[] = 'User-agent: ' . $ua;
            $lines[] = ($policy === 'allow') ? 'Allow: /' : 'Disallow: /';
            $lines[] = '';
        }
        return rtrim(implode("\n", $lines));
    }

    private function physical_robots_file(): string {
        $path = ABSPATH . 'robots.txt';
        return file_exists($path) ? $path : '';
    }

    public function add_admin_page(): void {
        add_submenu_page(
            'pylon',
            __('Robots.txt', 'pylon-seo'),
            __('Robots.txt', 'pylon-seo'),
            'manage_options',
            'pylon-robots-txt',
            [$this, 'render_admin_page']
        );
    }

    public function render_admin_page(): void {
        $physical = $this->physical_robots_file();
        $stored = get_option('pylon_robots_txt', '');
        $bots_enabled = get_option('pylon_ai_bots_enabled', '1');
        $policies = $this->get_bot_policies();
        $bots = self::known_ai_bots();
        ?>
        <div class="wrap" style="max-width:1000px;">
            <?php \Pylon\Core\Modules\Admin\AdminEngine::page_header(__('Robots.txt Editor', 'pylon-seo'), '🤖'); ?>
            <?php if (isset($_GET['saved'])): ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Robots.txt settings saved.', 'pylon-seo'); ?></p></div>
            <?php endif; ?>
            <?php if ($physical): ?>
                <div class="notice notice-warning">
                    <p>
                        <?php esc_html_e('A physical robots.txt file exists at the site root. It takes precedence over the editor and AI crawler rules below. Edit or remove that file to use Pylon.', 'pylon-seo'); ?>
                    </p>
                </div>
                <div class="pylon-card">
                    <div class="pylon-card-header"><h3><?php esc_html_e('Current robots.txt (physical file)', 'pylon-seo'); ?></h3></div>
                    <div class="pylon-card-body">
                        <pre style="background:var(--pylon-gray-100,#f5f5f5);padding:12px;border-radius:6px;font-size:12px;white-space:pre-wrap;word-break:break-word;margin:0;"><?php echo esc_html(file_get_contents($physical)); ?></pre>
                    </div>
                </div>
            <?php else: ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="pylon_save_robots_txt">
                    <?php wp_nonce_field('pylon_save_robots_txt', 'pylon_robots_txt_nonce'); ?>

                    <div class="pylon-card">
                        <div class="pylon-card-header"><h3><?php esc_html_e('AI Crawler Allowlist', 'pylon-seo'); ?></h3></div>
                        <div class="pylon-card-body">
                            <p style="font-size:13px;color:var(--pylon-gray-500);margin-top:0;">
                                <?php esc_html_e('Control which AI bots may crawl your site. Allow bots you want citing you in ChatGPT, Perplexity, Gemini, and Claude. These rules are appended to robots.txt automatically.', 'pylon-seo'); ?>
                            </p>
                            <div class="pylon-toggle" style="margin-bottom:16px;">
                                <input type="checkbox" name="pylon_ai_bots_enabled" id="pylon_ai_bots_enabled" value="1" <?php checked($bots_enabled, '1'); ?>>
                                <span class="pylon-toggle-track"></span>
                                <div class="pylon-toggle-text-wrap">
                                    <label class="pylon-toggle-label-text" for="pylon_ai_bots_enabled"><?php esc_html_e('Append AI crawler rules to robots.txt', 'pylon-seo'); ?></label>
                                    <span class="pylon-toggle-desc"><?php esc_html_e('Adds Allow/Disallow blocks for known AI crawlers below.', 'pylon-seo'); ?></span>
                                </div>
                            </div>
                            <div class="pylon-table-wrap">
                                <table class="pylon-table">
                                    <thead>
                                        <tr>
                                            <th><?php esc_html_e('Bot', 'pylon-seo'); ?></th>
                                            <th style="width:160px;"><?php esc_html_e('Policy', 'pylon-seo'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($bots as $ua => $meta): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo esc_html($meta['label']); ?></strong>
                                                <div style="font-size:12px;color:var(--pylon-gray-500);"><?php echo esc_html($ua); ?> — <?php echo esc_html($meta['desc']); ?></div>
                                            </td>
                                            <td>
                                                <select name="pylon_ai_bot_policies[<?php echo esc_attr($ua); ?>]" class="pylon-select">
                                                    <option value="allow" <?php selected($policies[$ua], 'allow'); ?>><?php esc_html_e('Allow', 'pylon-seo'); ?></option>
                                                    <option value="disallow" <?php selected($policies[$ua], 'disallow'); ?>><?php esc_html_e('Disallow', 'pylon-seo'); ?></option>
                                                </select>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="pylon-card" style="margin-top:16px;">
                        <div class="pylon-card-header"><h3><?php esc_html_e('Robots.txt Content', 'pylon-seo'); ?></h3></div>
                        <div class="pylon-card-body">
                            <p style="font-size:13px;color:var(--pylon-gray-400);margin-top:0;">
                                <?php esc_html_e('Leave empty to use the WordPress default. AI crawler rules (above) are appended when enabled.', 'pylon-seo'); ?>
                            </p>
                            <textarea name="pylon_robots_txt" rows="12" style="width:100%;font-family:Consolas,Menlo,monospace;font-size:12px;line-height:1.6;" class="pylon-input"><?php echo esc_textarea($stored); ?></textarea>
                        </div>
                    </div>

                    <div class="pylon-card" style="margin-top:16px;">
                        <div class="pylon-card-header"><h3><?php esc_html_e('Live Preview', 'pylon-seo'); ?></h3></div>
                        <div class="pylon-card-body">
                            <pre style="background:var(--pylon-gray-100,#f5f5f5);padding:12px;border-radius:6px;font-size:12px;white-space:pre-wrap;word-break:break-word;margin:0;"><?php
                                $preview_base = trim($stored) !== '' ? $stored : "# WordPress default robots.txt (when empty)\nUser-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php";
                                $preview_bots = $bots_enabled ? $this->build_ai_bot_rules() : '';
                                echo esc_html($preview_bots !== '' ? rtrim($preview_base) . "\n\n# Pylon SEO — AI crawler rules\n" . $preview_bots : $preview_base);
                            ?></pre>
                            <p style="margin:10px 0 0;font-size:12px;">
                                <a href="<?php echo esc_url(home_url('/robots.txt')); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open /robots.txt', 'pylon-seo'); ?> →</a>
                            </p>
                        </div>
                    </div>

                    <div class="pylon-card" style="margin-top:16px;">
                        <div class="pylon-card-body">
                            <button type="submit" class="pylon-btn pylon-btn-primary"><?php esc_html_e('Save Robots.txt', 'pylon-seo'); ?></button>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    public function save_robots_txt(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized.');
        check_admin_referer('pylon_save_robots_txt', 'pylon_robots_txt_nonce');

        $content = $this->sanitize_robots_txt(wp_unslash($_POST['pylon_robots_txt'] ?? '')); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by sanitize_robots_txt().
        update_option('pylon_robots_txt', $content, false);

        update_option('pylon_ai_bots_enabled', !empty($_POST['pylon_ai_bots_enabled']) ? '1' : '0', false);

        $raw = isset($_POST['pylon_ai_bot_policies']) && is_array($_POST['pylon_ai_bot_policies'])
            ? wp_unslash($_POST['pylon_ai_bot_policies']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value sanitized below.
            : [];
        $policies = [];
        foreach (array_keys(self::known_ai_bots()) as $ua) {
            $val = sanitize_text_field($raw[$ua] ?? 'allow');
            $policies[$ua] = $val === 'disallow' ? 'disallow' : 'allow';
        }
        update_option('pylon_ai_bot_policies', $policies, false);

        wp_redirect(add_query_arg(['saved' => '1'], wp_get_referer() ?: admin_url('admin.php?page=pylon-robots-txt')));
        exit;
    }
}
