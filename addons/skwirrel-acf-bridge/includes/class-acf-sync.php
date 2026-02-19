<?php
/**
 * Skwirrel ACF Bridge — Sync logica.
 *
 * Luistert naar de hooks uit Skwirrel WooCommerce Sync en schrijft
 * Skwirrel productdata naar ACF velden op WooCommerce producten.
 *
 * Ondersteunt:
 * - Standaard veld-mapping (GTIN, brand, manufacturer, codes)
 * - ETIM features als individuele ACF velden
 * - Trade item / prijs data
 * - Alle Skwirrel vertalingen per taal als aparte ACF velden
 * - Configureerbare extra mappings
 * - Automatische ACF veldgroep registratie
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Skwirrel_ACF_Bridge_Sync {

    private static ?self $instance = null;
    private Skwirrel_WC_Sync_Logger $logger;

    /** Standaard mapping: Skwirrel veld => ACF veldnaam. */
    private const DEFAULT_FIELD_MAP = [
        'product_gtin'               => 'skwirrel_gtin',
        'brand_name'                 => 'skwirrel_brand',
        'manufacturer_name'          => 'skwirrel_manufacturer',
        'product_id'                 => 'skwirrel_product_id',
        'external_product_id'        => 'skwirrel_external_id',
        'internal_product_code'      => 'skwirrel_internal_code',
        'manufacturer_product_code'  => 'skwirrel_manufacturer_code',
    ];

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->logger = new Skwirrel_WC_Sync_Logger();

        // Hooks uit de hoofdplugin
        add_action('skwirrel_wc_sync_after_product_save', [$this, 'on_product_saved'], 10, 4);
        add_action('skwirrel_wc_sync_after_variation_save', [$this, 'on_variation_saved'], 10, 3);
        add_action('skwirrel_wc_sync_after_variable_product_save', [$this, 'on_variable_saved'], 10, 3);

        // Registreer optioneel een ACF veldgroep
        add_action('acf/init', [$this, 'maybe_register_field_group']);

        $this->logger->info('Skwirrel ACF Bridge geladen');
    }

    /**
     * Na opslaan simpel product.
     */
    public function on_product_saved(int $wc_id, array $product, bool $is_new, array $attrs): void {
        $this->map_standard_fields($wc_id, $product);
        $this->map_etim_fields($wc_id, $product);
        $this->map_trade_items($wc_id, $product);
        $this->map_translations($wc_id, $product);

        /**
         * Action: ACF velden zijn gesynchroniseerd voor een simpel product.
         *
         * @param int   $wc_id   WooCommerce product ID.
         * @param array $product Ruwe Skwirrel productdata.
         */
        do_action('skwirrel_acf_bridge_after_product_sync', $wc_id, $product);

        $this->logger->verbose('ACF Bridge: product velden gesynchroniseerd', [
            'wc_id' => $wc_id,
            'is_new' => $is_new,
        ]);
    }

    /**
     * Na opslaan variation.
     */
    public function on_variation_saved(int $variation_id, array $variation_attrs, array $product): void {
        $this->map_standard_fields($variation_id, $product);
        $this->map_etim_fields($variation_id, $product);

        do_action('skwirrel_acf_bridge_after_variation_sync', $variation_id, $product);

        $this->logger->verbose('ACF Bridge: variation velden gesynchroniseerd', [
            'variation_id' => $variation_id,
        ]);
    }

    /**
     * Na opslaan variable product (uit grouped products).
     */
    public function on_variable_saved(int $wc_id, array $group, bool $is_new): void {
        $this->update_acf_field($wc_id, 'skwirrel_grouped_product_id', $group['grouped_product_id'] ?? $group['id'] ?? null);
        $this->update_acf_field($wc_id, 'skwirrel_grouped_product_name', $group['grouped_product_name'] ?? $group['name'] ?? '');
        $this->update_acf_field($wc_id, 'skwirrel_grouped_product_code', $group['grouped_product_code'] ?? $group['internal_product_code'] ?? '');

        do_action('skwirrel_acf_bridge_after_variable_sync', $wc_id, $group);

        $this->logger->verbose('ACF Bridge: variable product velden gesynchroniseerd', [
            'wc_id' => $wc_id,
        ]);
    }

    // ─── Interne mapping methoden ────────────────────────────────

    /**
     * Map standaard Skwirrel velden naar ACF.
     */
    private function map_standard_fields(int $post_id, array $product): void {
        $field_map = $this->get_field_map();

        foreach ($field_map as $skwirrel_key => $acf_field_name) {
            $value = $this->resolve_nested_value($product, $skwirrel_key);
            if ($value === null || $value === '') {
                continue;
            }
            $this->update_acf_field($post_id, $acf_field_name, $value);
        }
    }

    /**
     * Map ETIM features als individuele ACF velden.
     * Veldnaam: skwirrel_etim_{sanitized_label}
     */
    private function map_etim_fields(int $post_id, array $product): void {
        $opts = $this->get_options();
        if (empty($opts['sync_etim'])) {
            return;
        }

        $mapper = new Skwirrel_WC_Sync_Product_Mapper();
        $attrs = $mapper->get_attributes($product);

        foreach ($attrs as $label => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $field_name = 'skwirrel_etim_' . sanitize_key($label);
            $this->update_acf_field($post_id, $field_name, $value);
        }
    }

    /**
     * Map trade item / prijs informatie naar ACF.
     */
    private function map_trade_items(int $post_id, array $product): void {
        $opts = $this->get_options();
        if (empty($opts['sync_trade_items'])) {
            return;
        }

        $trade_items = $product['_trade_items'] ?? [];
        if (empty($trade_items)) {
            return;
        }

        $first_ti = $trade_items[0] ?? [];
        $prices = $first_ti['_trade_item_prices'] ?? [];

        $acf_prices = [];
        foreach ($prices as $p) {
            $acf_prices[] = [
                'net_price'        => $p['net_price'] ?? null,
                'gross_price'      => $p['gross_price'] ?? null,
                'currency'         => $p['currency_code'] ?? $p['currency'] ?? 'EUR',
                'price_on_request' => !empty($p['price_on_request']),
            ];
        }

        if (!empty($acf_prices)) {
            $this->update_acf_field($post_id, 'skwirrel_prices', $acf_prices);
        }

        // EAN uit trade item
        $ean = $first_ti['trade_item_ean'] ?? $first_ti['ean'] ?? '';
        if ($ean !== '') {
            $this->update_acf_field($post_id, 'skwirrel_ean', $ean);
        }
    }

    /**
     * Sla alle Skwirrel vertalingen op als ACF velden.
     * Per taal: skwirrel_translation_{lang}_{veld}
     */
    private function map_translations(int $post_id, array $product): void {
        $opts = $this->get_options();
        if (empty($opts['sync_translations'])) {
            return;
        }

        $translations = $product['_product_translations'] ?? [];
        if (empty($translations)) {
            return;
        }

        foreach ($translations as $t) {
            $lang = sanitize_key($t['language'] ?? 'unknown');
            $fields = [
                'description'      => $t['product_description'] ?? '',
                'long_description' => $t['product_long_description'] ?? '',
                'marketing_text'   => $t['product_marketing_text'] ?? '',
                'web_text'         => $t['product_web_text'] ?? '',
                'model'            => $t['product_model'] ?? '',
            ];

            foreach ($fields as $suffix => $value) {
                if ($value === '') {
                    continue;
                }
                $this->update_acf_field($post_id, "skwirrel_translation_{$lang}_{$suffix}", $value);
            }
        }
    }

    // ─── Hulpmethoden ────────────────────────────────────────────

    /**
     * Update een ACF veld, met fallback naar post meta.
     */
    private function update_acf_field(int $post_id, string $field_name, mixed $value): void {
        if ($value === null || $value === '') {
            return;
        }
        if (function_exists('update_field')) {
            update_field($field_name, $value, $post_id);
        } else {
            update_post_meta($post_id, $field_name, $value);
        }
    }

    /**
     * Haal de complete veld-mapping op.
     */
    private function get_field_map(): array {
        $opts = $this->get_options();
        $custom = $opts['custom_field_map'] ?? [];

        $map = self::DEFAULT_FIELD_MAP;
        if (is_array($custom)) {
            foreach ($custom as $entry) {
                if (!empty($entry['skwirrel_field']) && !empty($entry['acf_field'])) {
                    $map[$entry['skwirrel_field']] = $entry['acf_field'];
                }
            }
        }

        /**
         * Filter: pas de ACF veld-mapping aan.
         *
         * @param array $map Skwirrel veld => ACF veldnaam.
         */
        return apply_filters('skwirrel_acf_bridge_field_map', $map);
    }

    /**
     * Resolve een geneste waarde via dot-notatie.
     */
    private function resolve_nested_value(array $data, string $path): mixed {
        $keys = explode('.', $path);
        $current = $data;
        foreach ($keys as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }
        return is_scalar($current) ? $current : null;
    }

    /**
     * Plugin opties ophalen.
     */
    private function get_options(): array {
        $defaults = [
            'sync_etim'         => true,
            'sync_trade_items'  => false,
            'sync_translations' => false,
            'auto_field_group'  => false,
            'custom_field_map'  => [],
        ];
        $saved = get_option('skwirrel_acf_bridge_settings', []);
        return array_merge($defaults, is_array($saved) ? $saved : []);
    }

    /**
     * Registreer automatisch een ACF veldgroep voor Skwirrel data.
     */
    public function maybe_register_field_group(): void {
        if (!function_exists('acf_add_local_field_group')) {
            return;
        }

        $opts = $this->get_options();
        if (empty($opts['auto_field_group'])) {
            return;
        }

        $fields = [];
        $map = $this->get_field_map();
        $pos = 0;

        foreach ($map as $skwirrel_key => $acf_field_name) {
            $fields[] = [
                'key'          => 'field_skwirrel_' . md5($acf_field_name),
                'label'        => ucfirst(str_replace(['skwirrel_', '_'], ['', ' '], $acf_field_name)),
                'name'         => $acf_field_name,
                'type'         => 'text',
                'instructions' => sprintf('Skwirrel veld: %s', $skwirrel_key),
                'readonly'     => 1,
                'order_no'     => $pos++,
            ];
        }

        acf_add_local_field_group([
            'key'            => 'group_skwirrel_product_data',
            'title'          => 'Skwirrel Productdata',
            'fields'         => $fields,
            'location'       => [
                [
                    [
                        'param'    => 'post_type',
                        'operator' => '==',
                        'value'    => 'product',
                    ],
                ],
            ],
            'menu_order'      => 50,
            'position'        => 'normal',
            'style'           => 'default',
            'label_placement' => 'top',
            'active'          => true,
        ]);
    }
}
