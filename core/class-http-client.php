<?php
namespace Pylon\Core;
defined('ABSPATH') || exit;
class HttpClient {
    public static function request(string $method, string $url, array $args = []): array {
        $safe_method = strtoupper($method);
        if (!in_array($safe_method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $safe_method = 'POST';
        }

        $defaults = [
            'method' => $safe_method,
            'timeout' => 30,
            'sslverify' => !defined('PYLON_DEV_MODE') || !PYLON_DEV_MODE,
            'headers' => [],
        ];

        $args = array_merge($defaults, $args);
        if (!is_array($args['headers'] ?? null)) {
            $args['headers'] = [];
        }

        if (function_exists('\wp_remote_request')) {
            $response = \wp_remote_request($url, $args);
            if (\is_wp_error($response)) {
                return [
                    'success' => false,
                    'error' => $response->get_error_message(),
                    'code' => 0,
                    'body' => '',
                    'data' => null,
                ];
            }

            $code = (int) \wp_remote_retrieve_response_code($response);
            $body = (string) \wp_remote_retrieve_body($response);
            $data = null;

            if ($body !== '') {
                $decoded = json_decode($body, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $data = $decoded;
                }
            }

            return [
                'success' => $code >= 200 && $code < 300,
                'code' => $code,
                'body' => $body,
                'data' => $data,
                'error' => ($code >= 200 && $code < 300) ? null : sprintf('HTTP %d', $code),
            ];
        }

        return [
            'success' => false,
            'error' => 'WordPress HTTP API unavailable',
            'code' => 0,
            'body' => '',
            'data' => null,
        ];
    }

    public static function post_json(string $url, array $data, array $args = []): array {
        $headers = is_array($args['headers'] ?? null) ? $args['headers'] : [];
        $args['headers'] = array_merge(['Content-Type' => 'application/json'], $headers);
        $args['body'] = function_exists('\wp_json_encode') ? \wp_json_encode($data) : json_encode($data);
        return self::request('POST', $url, $args);
    }

    public static function get_json(string $url, array $args = []): array {
        return self::request('GET', $url, $args);
    }
}
