<?php
/**
 * Skwirrel ACF Bridge — Admin instellingen.
 *
 * Eigen instellingenpagina onder WooCommerce → Skwirrel ACF Bridge.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Skwirrel_ACF_Bridge_Settings {

    private const PAGE_SLUG = 'skwirrel-acf-bridge';
    private const OPTION_KEY = 'skwirrel_acf_bridge_settings';

    private static ?self $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'add_menu'], 100);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function add_menu(): void {
        add_submenu_page(
            'woocommerce',
            __('Skwirrel ACF Bridge', 'skwirrel-acf-bridge'),
            __('Skwirrel ACF', 'skwirrel-acf-bridge'),
            'manage_woocommerce',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function register_settings(): void {
        register_setting('skwirrel_acf_bridge', self::OPTION_KEY, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);
    }

    public function sanitize_settings(array $input): array {
        $out = [];
        $out['sync_etim'] = !empty($input['sync_etim']);
        $out['sync_trade_items'] = !empty($input['sync_trade_items']);
        $out['sync_translations'] = !empty($input['sync_translations']);
        $out['auto_field_group'] = !empty($input['auto_field_group']);

        // Custom field mapping
        $custom_map = [];
        $sk_fields = $input['custom_skwirrel_field'] ?? [];
        $acf_fields = $input['custom_acf_field'] ?? [];
        if (is_array($sk_fields) && is_array($acf_fields)) {
            foreach ($sk_fields as $i => $sk) {
                $sk = sanitize_text_field(trim($sk));
                $acf = sanitize_text_field(trim($acf_fields[$i] ?? ''));
                if ($sk !== '' && $acf !== '') {
                    $custom_map[] = [
                        'skwirrel_field' => $sk,
                        'acf_field' => $acf,
                    ];
                }
            }
        }
        $out['custom_field_map'] = $custom_map;

        return $out;
    }

    public function render_page(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Geen toegang.');
        }

        $opts = get_option(self::OPTION_KEY, []);
        $defaults = [
            'sync_etim'         => true,
            'sync_trade_items'  => false,
            'sync_translations' => false,
            'auto_field_group'  => false,
            'custom_field_map'  => [],
        ];
        $opts = array_merge($defaults, is_array($opts) ? $opts : []);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Skwirrel ACF Bridge — Instellingen', 'skwirrel-acf-bridge'); ?></h1>
            <p><?php esc_html_e('Koppelt Skwirrel productdata aan ACF velden bij elke sync.', 'skwirrel-acf-bridge'); ?></p>

            <form method="post" action="options.php">
                <?php wp_nonce_field('options-options'); ?>
                <?php settings_fields('skwirrel_acf_bridge'); ?>

                <h2><?php esc_html_e('Wat synchroniseren?', 'skwirrel-acf-bridge'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Standaard velden', 'skwirrel-acf-bridge'); ?></th>
                        <td>
                            <p class="description"><?php esc_html_e('GTIN, Brand, Manufacturer, product codes worden altijd gesynchroniseerd.', 'skwirrel-acf-bridge'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('ETIM kenmerken', 'skwirrel-acf-bridge'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[sync_etim]" value="1" <?php checked(!empty($opts['sync_etim'])); ?> />
                                <?php esc_html_e('ETIM features opslaan als individuele ACF velden (skwirrel_etim_*)', 'skwirrel-acf-bridge'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Trade items / prijzen', 'skwirrel-acf-bridge'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[sync_trade_items]" value="1" <?php checked(!empty($opts['sync_trade_items'])); ?> />
                                <?php esc_html_e('Prijs-/EAN data opslaan (skwirrel_prices, skwirrel_ean)', 'skwirrel-acf-bridge'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Vertalingen', 'skwirrel-acf-bridge'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[sync_translations]" value="1" <?php checked(!empty($opts['sync_translations'])); ?> />
                                <?php esc_html_e('Alle product-vertalingen opslaan als ACF velden (skwirrel_translation_{taal}_{veld})', 'skwirrel-acf-bridge'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Handig als je vertalingen wilt tonen zonder WPML, of als bron voor eigen templates.', 'skwirrel-acf-bridge'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('ACF veldgroep', 'skwirrel-acf-bridge'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Auto veldgroep', 'skwirrel-acf-bridge'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[auto_field_group]" value="1" <?php checked(!empty($opts['auto_field_group'])); ?> />
                                <?php esc_html_e('Automatisch een "Skwirrel Productdata" ACF veldgroep registreren (zichtbaar op product-edit)', 'skwirrel-acf-bridge'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Als je liever zelf een veldgroep maakt in ACF, laat dit uit.', 'skwirrel-acf-bridge'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Extra veld-mapping', 'skwirrel-acf-bridge'); ?></h2>
                <p class="description"><?php esc_html_e('Voeg extra Skwirrel → ACF mappings toe. Gebruik dot-notatie voor geneste velden (bijv. "_product_status.product_status_description").', 'skwirrel-acf-bridge'); ?></p>

                <table class="widefat" id="skwirrel-acf-custom-map" style="max-width: 700px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Skwirrel veld', 'skwirrel-acf-bridge'); ?></th>
                            <th><?php esc_html_e('ACF veldnaam', 'skwirrel-acf-bridge'); ?></th>
                            <th style="width: 50px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $custom_map = $opts['custom_field_map'] ?? [];
                        if (empty($custom_map)) {
                            $custom_map = [['skwirrel_field' => '', 'acf_field' => '']];
                        }
                        foreach ($custom_map as $i => $entry) : ?>
                            <tr>
                                <td><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[custom_skwirrel_field][]" value="<?php echo esc_attr($entry['skwirrel_field'] ?? ''); ?>" class="regular-text" placeholder="bijv. product_gtin" /></td>
                                <td><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[custom_acf_field][]" value="<?php echo esc_attr($entry['acf_field'] ?? ''); ?>" class="regular-text" placeholder="bijv. mijn_gtin_veld" /></td>
                                <td><button type="button" class="button" onclick="this.closest('tr').remove();">&times;</button></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p>
                    <button type="button" class="button" onclick="
                        var tbody = document.querySelector('#skwirrel-acf-custom-map tbody');
                        var tr = tbody.querySelector('tr').cloneNode(true);
                        tr.querySelectorAll('input').forEach(function(i){i.value='';});
                        tbody.appendChild(tr);
                    "><?php esc_html_e('+ Regel toevoegen', 'skwirrel-acf-bridge'); ?></button>
                </p>

                <h2><?php esc_html_e('Standaard veld-mapping (referentie)', 'skwirrel-acf-bridge'); ?></h2>
                <table class="widefat striped" style="max-width: 700px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Skwirrel veld', 'skwirrel-acf-bridge'); ?></th>
                            <th><?php esc_html_e('ACF veldnaam', 'skwirrel-acf-bridge'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td><code>product_gtin</code></td><td><code>skwirrel_gtin</code></td></tr>
                        <tr><td><code>brand_name</code></td><td><code>skwirrel_brand</code></td></tr>
                        <tr><td><code>manufacturer_name</code></td><td><code>skwirrel_manufacturer</code></td></tr>
                        <tr><td><code>product_id</code></td><td><code>skwirrel_product_id</code></td></tr>
                        <tr><td><code>external_product_id</code></td><td><code>skwirrel_external_id</code></td></tr>
                        <tr><td><code>internal_product_code</code></td><td><code>skwirrel_internal_code</code></td></tr>
                        <tr><td><code>manufacturer_product_code</code></td><td><code>skwirrel_manufacturer_code</code></td></tr>
                    </tbody>
                </table>

                <?php submit_button(__('Instellingen opslaan', 'skwirrel-acf-bridge')); ?>
            </form>
        </div>
        <?php
    }
}
