<?php
/**
 * Plugin Name: Skwirrel ACF Bridge
 * Plugin URI: https://github.com/example/skwirrel-woocommerce-sync
 * Description: Koppelt Skwirrel productdata automatisch aan ACF (Advanced Custom Fields) velden. Vereist: Skwirrel WooCommerce Sync + ACF (Free of PRO).
 * Version: 1.0.0
 * Author: Skwirrel Sync
 * Author URI: https://skwirrel.eu
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * License: GPL v2 or later
 * Text Domain: skwirrel-acf-bridge
 * Domain Path: /languages
 * Requires Plugins: skwirrel-woocommerce-sync
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('SKWIRREL_ACF_BRIDGE_VERSION', '1.0.0');
define('SKWIRREL_ACF_BRIDGE_FILE', __FILE__);
define('SKWIRREL_ACF_BRIDGE_DIR', plugin_dir_path(__FILE__));

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
    if (!empty($errors)) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            sprintf(
                'Skwirrel ACF Bridge vereist de volgende plugins: <strong>%s</strong>.',
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
final class Skwirrel_ACF_Bridge_Plugin {

    private static ?self $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', [$this, 'init'], 20); // na de hoofdplugin (10)
    }

    public function init(): void {
        // Dependency checks
        if (!class_exists('WooCommerce')) {
            return;
        }
        if (!class_exists('Skwirrel_WC_Sync_Service')) {
            add_action('admin_notices', fn() => $this->missing_notice('Skwirrel WooCommerce Sync'));
            return;
        }
        if (!$this->is_acf_available()) {
            add_action('admin_notices', fn() => $this->missing_notice('Advanced Custom Fields (ACF)'));
            return;
        }

        require_once SKWIRREL_ACF_BRIDGE_DIR . 'includes/class-acf-sync.php';
        require_once SKWIRREL_ACF_BRIDGE_DIR . 'includes/class-acf-settings.php';

        Skwirrel_ACF_Bridge_Sync::instance();
        Skwirrel_ACF_Bridge_Settings::instance();
    }

    private function is_acf_available(): bool {
        return function_exists('acf_get_field_groups') || class_exists('ACF');
    }

    private function missing_notice(string $name): void {
        printf(
            '<div class="notice notice-error"><p><strong>Skwirrel ACF Bridge:</strong> %s is vereist maar niet actief.</p></div>',
            esc_html($name)
        );
    }
}

Skwirrel_ACF_Bridge_Plugin::instance();
