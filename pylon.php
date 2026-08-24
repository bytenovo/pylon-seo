<?php
/**
 * Plugin Name: Pylon SEO
 * Plugin URI:  https://bytenovo.com/wordpress/plugin/pylon-seo
 * Description: All-in-one WordPress SEO. Meta tags, Open Graph, schema markup, XML sitemaps, redirects, 404 monitoring, content analysis, image SEO, broken link checker, and more. Built by Bytenovo.
 * Version:     1.0.0
 * Author:      Bytenovo
 * Author URI:  https://bytenovo.com
 * Requires at least: 6.4
 * Tested up to:      7.1
 * Requires PHP:      7.4
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pylon-seo
 * Domain Path:       /languages
 */

defined('ABSPATH') || exit;

define('PYLON_VERSION', '1.0.0');
define('PYLON_FILE', __FILE__);
define('PYLON_PATH', plugin_dir_path(__FILE__));
define('PYLON_URL', plugin_dir_url(__FILE__));
define('PYLON_MIN_PHP', '7.4');
define('PYLON_MIN_WP', '6.4'); // Tested up to 7.1

if (version_compare(PHP_VERSION, PYLON_MIN_PHP, '<')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        echo esc_html(sprintf(
            /* translators: %1$s: Minimum required PHP version, %2$s: Current PHP version. */
            __('Pylon requires PHP %1$s or higher. Your site is running PHP %2$s.', 'pylon-seo'),
            PYLON_MIN_PHP,
            PHP_VERSION
        ));
        echo '</p></div>';
    });
    return;
}

require_once PYLON_PATH . 'core/class-bootstrap.php';
\Pylon\Core\Bootstrap::init();
