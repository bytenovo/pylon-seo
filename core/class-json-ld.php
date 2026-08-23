<?php
namespace Pylon\Core;
defined('ABSPATH') || exit;
/**
 * Central JSON-LD emitter.
 *
 * Schema arrays are encoded at output time with HEX flags so no value can
 * contain a literal "</script>" or break out of the script element, while
 * remaining valid JSON for crawlers.
 */
final class JsonLd {

    private const ENCODE_FLAGS = JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;

    /**
     * Echo a JSON-LD <script> element for the given schema array.
     *
     * @param array $schema Full schema.org graph (single object or @graph array).
     */
    public static function script(array $schema): void {
        printf(
            '<script type="application/ld+json">%s</script>' . "\n",
            (string) wp_json_encode($schema, self::ENCODE_FLAGS)
        );
    }
}
