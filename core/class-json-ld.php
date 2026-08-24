<?php
namespace Pylon\Core;
defined('ABSPATH') || exit;
/**
 * Central JSON-LD emitter.
 *
 * Schema arrays are encoded at output time with HEX flags so no value can
 * contain a literal "</script>" or break out of the script element, while
 * remaining valid JSON for crawlers. Printing goes through
 * wp_print_inline_script_tag(), the standard WordPress API for inline
 * scripts, which handles attribute escaping.
 */
final class JsonLd {

    private const ENCODE_FLAGS = JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;

    /**
     * Echo a JSON-LD script element for the given schema array.
     *
     * @param array $schema Full schema.org graph (single object or @graph array).
     */
    public static function script(array $schema): void {
        $json = wp_json_encode($schema, self::ENCODE_FLAGS);
        if (!is_string($json) || '' === $json) {
            return;
        }
        wp_print_inline_script_tag($json, ['type' => 'application/ld+json']);
    }
}
