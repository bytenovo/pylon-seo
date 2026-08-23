<?php
namespace Pylon\Core\Modules\IndexNow;

use Pylon\Core\HttpClient;

class IndexNowEngine {
    private string $api_key;
    private string $key_file;
    private array $endpoints = [
        'https://api.indexnow.org/indexnow',
        'https://www.bing.com/indexnow',
        'https://search.naver.com/indexnow',
        'https://yandex.com/indexnow',
        'https://www.seznam.cz/indexnow',
        'https://indexnow.yep.com/indexnow',
    ];

    public function register(): void {
        if (!get_option('pylon_indexnow_enabled', '1')) return;

        $this->api_key = get_option('pylon_indexnow_api_key', $this->generate_key());
        $this->key_file = ABSPATH . $this->api_key . '.txt';

        add_action('init', [$this, 'serve_key_file']);
        add_action('save_post', [$this, 'submit_on_save'], 99, 3);
        add_action('transition_post_status', [$this, 'submit_on_publish'], 10, 3);
        add_action('admin_notices', [$this, 'key_notice']);
    }

    public function install(): void {
        $this->activate();
    }

    public function activate(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'pylon_indexnow_log';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            $wpdb->query("CREATE TABLE {$table} (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                url TEXT NOT NULL,
                endpoint VARCHAR(255) NOT NULL,
                status VARCHAR(20) DEFAULT 'submitted',
                response_code INT DEFAULT 0,
                submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_submitted_at (submitted_at)
            )");
        }
    }

    public function submit_on_save(int $post_id, \WP_Post $post, bool $update): void {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        if ($post->post_status !== 'publish') return;

        $this->submit_url(get_permalink($post));
    }

    public function submit_on_publish(string $new, string $old, \WP_Post $post): void {
        if ($new === 'publish' && $old !== 'publish') {
            $this->submit_url(get_permalink($post));
        }
    }

    public function submit_url(string $url): void {
        if (!get_option('pylon_indexnow_enabled', '1')) return;

        $this->ensure_key_file();

        foreach ($this->endpoints as $endpoint) {
            if (!wp_http_validate_url($endpoint)) {
                continue;
            }
            $resp = HttpClient::post_json($endpoint, [
                'host' => wp_parse_url(home_url(), PHP_URL_HOST),
                'key' => $this->api_key,
                'urlList' => [$url],
            ], [
                'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
                'timeout' => 5,
                'blocking' => false,
            ]);
            if (!$resp['success']) {
                error_log('Pylon IndexNow submission failed: ' . ($resp['error'] ?? 'unknown error'));
                $this->log_submission($url, $endpoint, 'error', 0);
            } else {
                $this->log_submission($url, $endpoint, 'submitted', 0);
            }
        }
    }

    public function submit_urls(array $urls): void {
        $chunks = array_chunk($urls, 1000);
        foreach ($chunks as $chunk) {
            foreach ($this->endpoints as $endpoint) {
                if (!wp_http_validate_url($endpoint)) {
                    continue;
                }
                $resp = HttpClient::post_json($endpoint, [
                    'host' => wp_parse_url(home_url(), PHP_URL_HOST),
                    'key' => $this->api_key,
                    'urlList' => $chunk,
                ], [
                    'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
                    'timeout' => 10,
                    'blocking' => false,
                ]);
                if (!$resp['success']) {
                    error_log('Pylon IndexNow bulk submission failed: ' . ($resp['error'] ?? 'unknown error'));
                }
                foreach ($chunk as $url) {
                    $this->log_submission($url, $endpoint, !$resp['success'] ? 'error' : 'submitted', 0);
                }
            }
        }
    }

    private function log_submission(string $url, string $endpoint, string $status, int $code): void {
        global $wpdb;
        $table = $wpdb->prefix . 'pylon_indexnow_log';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) return;
        $wpdb->insert($table, [
            'url'          => $url,
            'endpoint'     => $endpoint,
            'status'       => $status,
            'response_code' => $code,
        ]);
    }

    public function serve_key_file(): void {
        if (isset($_SERVER['REQUEST_URI']) && basename(sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']))) === $this->api_key . '.txt') {
            $this->ensure_key_file();
            header('Content-Type: text/plain');
            echo esc_html($this->api_key);
            exit;
        }
    }

    public function key_notice(): void {
        $this->ensure_key_file();

        if (!file_exists($this->key_file)) {
            echo '<div class="notice notice-warning"><p>';
            echo esc_html__('Pylon IndexNow: Unable to create key file. Please ensure your WordPress root directory is writable.', 'pylon-seo');
            echo '</p></div>';
        }
    }

    private function ensure_key_file(): void {
        if (!file_exists($this->key_file)) {
            if (!get_option('pylon_indexnow_api_key', false)) {
                update_option('pylon_indexnow_api_key', $this->api_key);
            }
            @file_put_contents($this->key_file, $this->api_key);
        }
    }

    private function generate_key(): string {
        return strtolower(wp_generate_password(32, false, false));
    }
}
