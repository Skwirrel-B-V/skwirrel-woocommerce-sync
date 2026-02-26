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

// WordPress time constants.
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

// Stub WordPress i18n/escaping functions.
if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string {
        return $text;
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('sanitize_title')) {
    function sanitize_title(string $title): string {
        $slug = strtolower(trim($title));
        $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        return trim($slug, '-');
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, int $options = 0, int $depth = 512) {
        return json_encode($data, $options, $depth);
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool {
        return $thing instanceof WP_Error;
    }
}

// Stub WP_Error class.
if (!class_exists('WP_Error')) {
    class WP_Error {
        private string $code;
        private string $message;
        private $data;

        public function __construct(string $code = '', string $message = '', $data = '') {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }

        public function get_error_code(): string {
            return $this->code;
        }

        public function get_error_message(): string {
            return $this->message;
        }

        public function get_error_data() {
            return $this->data;
        }
    }
}

// Stub WordPress functions used by plugin classes.
if (!function_exists('get_locale')) {
    function get_locale(): string {
        return 'nl_NL';
    }
}

// Global overrides for get_option() — tests can set $GLOBALS['_test_options'] to
// override specific options for a single test run.
if (!function_exists('get_option')) {
    function get_option(string $option, $default = false) {
        // Allow per-test overrides.
        if (isset($GLOBALS['_test_options'][$option])) {
            return $GLOBALS['_test_options'][$option];
        }
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

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
    }
}

if (!function_exists('wc_get_logger')) {
    function wc_get_logger() {
        return null;
    }
}

// Stub $wpdb for slug_exists() and other direct queries.
if (!isset($GLOBALS['wpdb'])) {
    $GLOBALS['wpdb'] = new class {
        public string $posts = 'wp_posts';
        public string $postmeta = 'wp_postmeta';
        public string $terms = 'wp_terms';
        public string $term_taxonomy = 'wp_term_taxonomy';
        public string $term_relationships = 'wp_term_relationships';
        public string $termmeta = 'wp_termmeta';

        public function prepare(string $query, ...$args): string {
            return vsprintf(str_replace('%s', "'%s'", str_replace('%d', '%d', $query)), $args);
        }

        public function get_var(string $query) {
            return '0'; // Default: slug does not exist.
        }

        public function get_results(string $query, $output = 'OBJECT') {
            return [];
        }
    };
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
require_once __DIR__ . '/../includes/class-etim-extractor.php';
require_once __DIR__ . '/../includes/class-custom-class-extractor.php';
require_once __DIR__ . '/../includes/class-attachment-handler.php';
require_once __DIR__ . '/../includes/class-product-mapper.php';
require_once __DIR__ . '/../includes/class-permalink-settings.php';
require_once __DIR__ . '/../includes/class-slug-resolver.php';
