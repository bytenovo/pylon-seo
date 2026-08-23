<?php
namespace Pylon\Core;

class Activator {
    public static function activate(): void {
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
        self::create_tables();
        self::set_defaults();
        if (class_exists('\Pylon\Core\Modules\Roles\RoleManager')) {
            \Pylon\Core\Modules\Roles\RoleManager::register_caps();
        }
        self::schedule_cron();
        self::clear_stale_seo_scores();
        flush_rewrite_rules();
    }

    public static function deactivate(): void {
        if (class_exists('\Pylon\Core\Modules\Roles\RoleManager')) {
            \Pylon\Core\Modules\Roles\RoleManager::remove_all();
        }
        self::clear_cron();
        flush_rewrite_rules();
    }

    private static function create_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $table_prefix = $wpdb->prefix . 'pylon_';

        $tables = [
            "CREATE TABLE IF NOT EXISTS {$table_prefix}redirects (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                source_url TEXT NOT NULL,
                target_url TEXT NOT NULL,
                type SMALLINT UNSIGNED DEFAULT 301,
                match_type VARCHAR(20) DEFAULT 'exact',
                hits BIGINT UNSIGNED DEFAULT 0,
                last_accessed DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_source_url (source_url(191)),
                INDEX idx_match_type (match_type)
            ) $charset;",

            "CREATE TABLE IF NOT EXISTS {$table_prefix}404_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                url TEXT NOT NULL,
                referer TEXT NULL,
                user_agent TEXT NULL,
                ip VARCHAR(45) NULL,
                hits BIGINT UNSIGNED DEFAULT 1,
                first_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
                last_seen DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_url (url(191))
            ) $charset;",

            "CREATE TABLE IF NOT EXISTS {$table_prefix}audit_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                object_id BIGINT UNSIGNED NULL,
                object_type VARCHAR(50) NOT NULL DEFAULT 'post',
                field VARCHAR(100) NOT NULL,
                old_value LONGTEXT NULL,
                new_value LONGTEXT NULL,
                user_id BIGINT UNSIGNED NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_object (object_id, object_type)
            ) $charset;",

            "CREATE TABLE IF NOT EXISTS {$table_prefix}sitemap_cache (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                type VARCHAR(50) NOT NULL,
                content LONGTEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_type (type)
            ) $charset;",

            "CREATE TABLE IF NOT EXISTS {$table_prefix}indexnow_log (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                url TEXT NOT NULL,
                endpoint VARCHAR(255) NOT NULL,
                status VARCHAR(20) DEFAULT 'submitted',
                response_code INT DEFAULT 0,
                submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_submitted_at (submitted_at)
            ) $charset;",

            "CREATE TABLE IF NOT EXISTS {$table_prefix}broken_links (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                post_id BIGINT UNSIGNED NOT NULL,
                link_url TEXT NOT NULL,
                anchor_text TEXT,
                status_code INT UNSIGNED DEFAULT 0,
                checked_at DATETIME DEFAULT NULL,
                ignored TINYINT UNSIGNED DEFAULT 0,
                INDEX idx_post (post_id),
                INDEX idx_ignored (ignored),
                INDEX idx_link_url (link_url(191)),
                INDEX idx_checked_at (checked_at)
            ) $charset;",

            "CREATE TABLE IF NOT EXISTS {$table_prefix}indexables (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                post_id BIGINT UNSIGNED NOT NULL UNIQUE,
                object_type VARCHAR(20) NOT NULL DEFAULT 'post',
                object_subtype VARCHAR(20) NOT NULL DEFAULT '',
                title TEXT,
                description TEXT,
                canonical TEXT,
                focus_keyword VARCHAR(255) DEFAULT '',
                schema_type VARCHAR(50) DEFAULT '',
                seo_score INT UNSIGNED DEFAULT 0,
                citeability_score INT UNSIGNED DEFAULT 0,
                aeo_score INT UNSIGNED DEFAULT 0,
                freshness_score INT UNSIGNED DEFAULT 0,
                word_count INT UNSIGNED DEFAULT 0,
                links_count INT UNSIGNED DEFAULT 0,
                heading_count INT UNSIGNED DEFAULT 0,
                image_count INT UNSIGNED DEFAULT 0,
                last_modified DATETIME DEFAULT NULL,
                indexed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_post (post_id),
                INDEX idx_object_type (object_type),
                INDEX idx_seo_score (seo_score),
                INDEX idx_citeability (citeability_score),
                INDEX idx_aeo (aeo_score)
            ) $charset;",

            "CREATE TABLE IF NOT EXISTS {$table_prefix}audit_history (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                post_id BIGINT UNSIGNED NOT NULL,
                score INT UNSIGNED NOT NULL DEFAULT 0,
                grade VARCHAR(2) NOT NULL DEFAULT '',
                total_checks INT UNSIGNED NOT NULL DEFAULT 0,
                passed INT UNSIGNED NOT NULL DEFAULT 0,
                warnings INT UNSIGNED NOT NULL DEFAULT 0,
                failed INT UNSIGNED NOT NULL DEFAULT 0,
                checked_at DATETIME NOT NULL,
                INDEX idx_post (post_id),
                INDEX idx_checked (checked_at)
            ) $charset;",

        ];

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        foreach ($tables as $sql) {
            dbDelta($sql);
        }

        if (class_exists('\Pylon\Core\Modules\Indexables\IndexablesEngine')) {
            $indexables = new \Pylon\Core\Modules\Indexables\IndexablesEngine();
            $indexables->install_table();
        }

        self::upgrade_tables();
    }

    public static function upgrade_tables(): void {
        global $wpdb;
        $prefix = $wpdb->prefix . 'pylon_';

        $checks = [
            "{$prefix}redirects" => [
                'idx_source_url' => 'source_url(191)',
                'idx_hits' => 'hits',
            ],
            "{$prefix}404_log" => [
                'idx_url' => 'url(191)',
                'idx_hits' => 'hits',
                'idx_last_seen' => 'last_seen',
            ],
            "{$prefix}audit_log" => [
                'idx_object' => 'object_id, object_type',
            ],
            "{$prefix}sitemap_cache" => [
                'idx_type' => 'type',
            ],
            "{$prefix}broken_links" => [
                'idx_link_url' => 'link_url(191)',
                'idx_checked_at' => 'checked_at',
            ],
            "{$prefix}audit_history" => [
                'idx_post' => 'post_id',
                'idx_checked' => 'checked_at',
                'idx_post_checked' => 'post_id, checked_at',
            ],
        ];

        foreach ($checks as $table => $indexes) {
            if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
                continue;
            }
            $existing = [];
            $rows = $wpdb->get_results("SHOW INDEX FROM `{$table}`");
            foreach ($rows as $row) {
                $existing[] = $row->Key_name;
            }
            foreach ($indexes as $name => $columns) {
                if (!in_array($name, $existing, true)) {
                    $wpdb->query("ALTER TABLE `{$table}` ADD INDEX {$name} ({$columns})");
                }
            }
        }

        // Add match_type column to redirects table for older installs.
        $col_check = $wpdb->get_var("SHOW COLUMNS FROM `" . esc_sql($prefix) . "redirects` LIKE 'match_type'");
        if (!$col_check) {
            $wpdb->query("ALTER TABLE `" . esc_sql($prefix) . "redirects` ADD COLUMN match_type VARCHAR(20) DEFAULT 'exact' AFTER type");
        }

    }

    private static function set_defaults(): void {
        $defaults = [
            'pylon_sitemap_enabled' => '1',
            'pylon_sitemap_post_types' => 'post,page',
            'pylon_og_enabled' => '1',
            'pylon_twitter_enabled' => '1',
            'pylon_schema_enabled' => '1',
            'pylon_redirects_enabled' => '1',
            'pylon_404_monitor_enabled' => '1',
            'pylon_freshness_enabled' => '1',
            'pylon_freshness_days' => '180',
            'pylon_author_eaat_enabled' => '1',
            'pylon_indexnow_enabled' => '1',
            'pylon_auto_canonical' => '1',
            'pylon_auto_redirect_slug' => '1',
        ];

        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                update_option($key, $value);
            }
        }
    }

    private static function schedule_cron(): void {
        if (!wp_next_scheduled('pylon_daily_maintenance')) {
            wp_schedule_event(time(), 'daily', 'pylon_daily_maintenance');
        }
        if (!wp_next_scheduled('pylon_monthly_link_check')) {
            wp_schedule_event(time(), 'monthly', 'pylon_monthly_link_check');
        }
    }

    private static function clear_stale_seo_scores(): void {
        global $wpdb;
        $wpdb->delete($wpdb->postmeta, ['meta_key' => '_pylon_engine_score']);
        $wpdb->delete($wpdb->postmeta, ['meta_key' => '_pylon_rendered_cache']);
    }

    private static function clear_cron(): void {
        $daily = wp_next_scheduled('pylon_daily_maintenance');
        if ($daily) {
            wp_unschedule_event($daily, 'pylon_daily_maintenance');
        }
        $monthly = wp_next_scheduled('pylon_monthly_link_check');
        if ($monthly) {
            wp_unschedule_event($monthly, 'pylon_monthly_link_check');
        }
    }

    public static function uninstall(): void {
        // No-op: plugin data preserved on uninstall.
    }
}
