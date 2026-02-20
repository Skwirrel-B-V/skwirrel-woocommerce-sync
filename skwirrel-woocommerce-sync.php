<?php
/**
 * Plugin Name: Skwirrel WooCommerce Sync
 * Plugin URI: https://github.com/example/skwirrel-woocommerce-sync
 * Description: Synchroniseert producten van Skwirrel JSON-RPC API naar WooCommerce.
 * Version: 1.1.1
 * Author: Skwirrel Sync
 * Author URI: https://skwirrel.eu
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 * WC tested up to: 10.5
 * License: GPL v2 or later
 * Text Domain: skwirrel-wc-sync
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('SKWIRREL_WC_SYNC_VERSION', '1.1.1');
define('SKWIRREL_WC_SYNC_PLUGIN_FILE', __FILE__);
define('SKWIRREL_WC_SYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SKWIRREL_WC_SYNC_PLUGIN_URL', plugin_dir_url(__FILE__));

add_action('plugins_loaded', function (): void {
    load_plugin_textdomain('skwirrel-wc-sync', false, dirname(plugin_basename(__FILE__)) . '/languages');

    if (class_exists('WooCommerce') && is_readable(WP_PLUGIN_DIR . '/woocommerce/i18n/languages')) {
        load_plugin_textdomain('woocommerce', false, 'woocommerce/i18n/languages');
    }
}, -999);

add_action('before_woocommerce_init', function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

register_activation_hook(__FILE__, function (): void {
    // Check WooCommerce dependency on activation
    if (!class_exists('WooCommerce') && !in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins', [])), true)) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('Skwirrel WooCommerce Sync vereist WooCommerce om te functioneren.', 'skwirrel-wc-sync')
            . ' <a href="' . esc_url(admin_url('plugin-install.php?s=woocommerce&tab=search&type=term')) . '">'
            . esc_html__('Installeer WooCommerce', 'skwirrel-wc-sync') . '</a>.',
            'Plugin Activation Error',
            ['back_link' => true]
        );
    }

    require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-action-scheduler.php';
    Skwirrel_WC_Sync_Action_Scheduler::instance()->schedule();
});

/**
 * Plugin bootstrap.
 */
final class Skwirrel_WC_Sync_Plugin {

    private static ?self $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', [$this, 'init']);
    }

    public function init(): void {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', fn() => $this->woocommerce_missing_notice());
            return;
        }

        $this->load_dependencies();
        $this->register_hooks();
    }

    private function load_dependencies(): void {
        require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-logger.php';
        require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-jsonrpc-client.php';
        require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-media-importer.php';
        require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-product-mapper.php';
        require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-sync-service.php';
        require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-action-scheduler.php';
        require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-admin-settings.php';
        require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-product-documents.php';
        require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-variation-attributes-fix.php';
        require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-delete-protection.php';
    }

    private function register_hooks(): void {
        Skwirrel_WC_Sync_Admin_Settings::instance();
        Skwirrel_WC_Sync_Action_Scheduler::instance();
        Skwirrel_WC_Sync_Product_Documents::instance();
        Skwirrel_WC_Sync_Variation_Attributes_Fix::init();
        Skwirrel_WC_Sync_Delete_Protection::instance();
    }

    private function woocommerce_missing_notice(): void {
        $install_url = admin_url('plugin-install.php?s=woocommerce&tab=search&type=term');
        $activate_url = admin_url('plugins.php');
        ?>
        <div class="notice notice-error">
            <p><strong>Skwirrel WooCommerce Sync</strong></p>
            <p><?php
                printf(
                    /* translators: %1$s = install URL, %2$s = activate URL */
                    wp_kses(
                        __('WooCommerce is vereist. <a href="%s">Installeer WooCommerce</a> of <a href="%s">activeer WooCommerce</a>.', 'skwirrel-wc-sync'),
                        ['a' => ['href' => []]]
                    ),
                    esc_url($install_url),
                    esc_url($activate_url)
                );
            ?></p>
        </div>
        <?php
    }
}

Skwirrel_WC_Sync_Plugin::instance();
