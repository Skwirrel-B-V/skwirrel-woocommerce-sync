<?php
/**
 * Plugin Name: Skwirrel WPML Bridge
 * Plugin URI: https://github.com/example/skwirrel-woocommerce-sync
 * Description: Maakt automatisch WPML vertalingen aan voor producten die via Skwirrel Sync worden geïmporteerd. Gebruikt de meertalige data uit de Skwirrel API. Vereist: Skwirrel WooCommerce Sync + WPML + WooCommerce Multilingual.
 * Version: 1.0.0
 * Author: Skwirrel Sync
 * Author URI: https://skwirrel.eu
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * License: GPL v2 or later
 * Text Domain: skwirrel-wpml-bridge
 * Domain Path: /languages
 * Requires Plugins: skwirrel-woocommerce-sync
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('SKWIRREL_WPML_BRIDGE_VERSION', '1.0.0');
define('SKWIRREL_WPML_BRIDGE_FILE', __FILE__);
define('SKWIRREL_WPML_BRIDGE_DIR', plugin_dir_path(__FILE__));

/**
 * Controleer afhankelijkheden bij activatie.
 */
register_activation_hook(__FILE__, function (): void {
    $errors = [];
    if (!class_exists('WooCommerce') && !in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins', [])), true)) {
        $errors[] = 'WooCommerce';
    }
    if (!in_array('skwirrel-woocommerce-sync/skwirrel-woocommerce-sync.php', apply_filters('active_plugins', get_option('active_plugins', [])), true)) {
        $errors[] = 'Skwirrel WooCommerce Sync';
    }
    if (!defined('ICL_SITEPRESS_VERSION') && !in_array('sitepress-multilingual-cms/sitepress.php', apply_filters('active_plugins', get_option('active_plugins', [])), true)) {
        $errors[] = 'WPML (Sitepress Multilingual CMS)';
    }
    if (!empty($errors)) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            sprintf(
                'Skwirrel WPML Bridge vereist de volgende plugins: <strong>%s</strong>.',
                esc_html(implode(', ', $errors))
            ),
            'Plugin Activation Error',
            ['back_link' => true]
        );
    }
});

/**
 * Bootstrap.
 */
final class Skwirrel_WPML_Bridge_Plugin {

    private static ?self $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', [$this, 'init'], 20);
    }

    public function init(): void {
        if (!class_exists('WooCommerce')) {
            return;
        }
        if (!class_exists('Skwirrel_WC_Sync_Service')) {
            add_action('admin_notices', fn() => $this->missing_notice('Skwirrel WooCommerce Sync'));
            return;
        }
        if (!$this->is_wpml_available()) {
            add_action('admin_notices', fn() => $this->missing_notice('WPML'));
            return;
        }

        require_once SKWIRREL_WPML_BRIDGE_DIR . 'includes/class-wpml-sync.php';
        require_once SKWIRREL_WPML_BRIDGE_DIR . 'includes/class-wpml-settings.php';

        Skwirrel_WPML_Bridge_Sync::instance();
        Skwirrel_WPML_Bridge_Settings::instance();
    }

    private function is_wpml_available(): bool {
        return defined('ICL_SITEPRESS_VERSION') || function_exists('wpml_get_active_languages_filter');
    }

    private function missing_notice(string $name): void {
        printf(
            '<div class="notice notice-error"><p><strong>Skwirrel WPML Bridge:</strong> %s is vereist maar niet actief.</p></div>',
            esc_html($name)
        );
    }
}

Skwirrel_WPML_Bridge_Plugin::instance();
