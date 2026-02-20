<?php
/**
 * Pest bootstrap — standalone (no WP test suite required).
 *
 * Defines minimal WP/WC stubs so plugin classes can be instantiated
 * without a running WordPress installation.
 */

// Prevent ABSPATH guard from exiting.
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wp/');
}

// Stub WordPress functions used by plugin classes.
if (!function_exists('get_locale')) {
    function get_locale(): string {
        return 'nl_NL';
    }
}

if (!function_exists('get_option')) {
    function get_option(string $option, $default = false) {
        // Return sensible defaults for test context.
        $options = [
            'skwirrel_wc_sync_settings' => [
                'image_language' => 'nl',
                'include_languages' => ['nl-NL', 'nl'],
                'use_sku_field' => 'internal_product_code',
            ],
        ];
        return $options[$option] ?? $default;
    }
}

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $text): string {
        return strip_tags($text);
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string {
        return trim(strip_tags($str));
    }
}

if (!function_exists('wc_get_logger')) {
    function wc_get_logger() {
        return null;
    }
}

// Stub WC_Logger to prevent fatal errors in Logger constructor.
if (!class_exists('WC_Logger')) {
    class WC_Logger {
        public function log($level, $message, $context = []) {}
    }
}

// Load plugin classes (order matters — dependencies first).
require_once __DIR__ . '/../includes/class-logger.php';
require_once __DIR__ . '/../includes/class-media-importer.php';
require_once __DIR__ . '/../includes/class-product-mapper.php';
