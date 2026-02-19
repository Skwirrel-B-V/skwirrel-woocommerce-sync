<?php
/**
 * Skwirrel WPML Bridge — Sync logica.
 *
 * Na elke Skwirrel product-sync worden automatisch WPML vertalingen
 * aangemaakt/bijgewerkt voor alle geconfigureerde talen.
 *
 * Gebruikt de meertalige _product_translations data die de Skwirrel API
 * al meelevert, zodat elke taalversie correct wordt ingevuld.
 *
 * Werkt met WPML's SitePress API:
 * - wpml_element_type filter
 * - wpml_element_trid filter
 * - wpml_set_element_language_details action
 * - wpml_get_element_translations filter (voor controle)
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Skwirrel_WPML_Bridge_Sync {

    private static ?self $instance = null;
    private Skwirrel_WC_Sync_Logger $logger;

    /**
     * Skwirrel taalcode → WPML taalcode mapping.
     * Skwirrel gebruikt ISO formaat (nl-NL, en-GB), WPML gebruikt 2-letterig (nl, en).
     */
    private const LANGUAGE_MAP = [
        'nl-NL' => 'nl',
        'nl'    => 'nl',
        'en-GB' => 'en',
        'en'    => 'en',
        'de-DE' => 'de',
        'de'    => 'de',
        'fr-FR' => 'fr',
        'fr'    => 'fr',
        'es-ES' => 'es',
        'es'    => 'es',
        'it-IT' => 'it',
        'it'    => 'it',
        'pt-BR' => 'pt-br',
        'pt'    => 'pt-pt',
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
        add_action('skwirrel_wc_sync_after_variable_product_save', [$this, 'on_variable_saved'], 10, 3);
        // Variaties worden automatisch meevertaald via WooCommerce Multilingual

        $this->logger->info('Skwirrel WPML Bridge geladen');
    }

    /**
     * Na opslaan simpel product: maak vertalingen aan.
     */
    public function on_product_saved(int $wc_id, array $product, bool $is_new, array $attrs): void {
        $this->create_translations($wc_id, $product, 'product');
    }

    /**
     * Na opslaan variable product: maak vertalingen aan.
     */
    public function on_variable_saved(int $wc_id, array $group, bool $is_new): void {
        // Grouped products kunnen ook _product_translations bevatten
        // via een virtueel product; probeer ze te vertalen
        $this->create_translations($wc_id, $group, 'product');
    }

    /**
     * Maak/update WPML vertalingen voor een product.
     */
    private function create_translations(int $source_post_id, array $product, string $post_type): void {
        $opts = $this->get_options();
        $target_languages = $opts['target_languages'] ?? [];
        $source_language = $opts['source_language'] ?? 'nl';

        if (empty($target_languages)) {
            return;
        }

        $translations = $product['_product_translations'] ?? [];
        if (empty($translations)) {
            $this->logger->verbose('WPML Bridge: geen vertalingen in Skwirrel data', [
                'wc_id' => $source_post_id,
            ]);
            return;
        }

        // Zorg dat het bronproduct geregistreerd staat bij WPML
        $this->register_source_language($source_post_id, $post_type, $source_language);

        // Haal de trid op (translation group ID)
        $element_type = 'post_' . $post_type;
        $trid = (int) apply_filters('wpml_element_trid', null, $source_post_id, $element_type);

        if (!$trid) {
            $this->logger->warning('WPML Bridge: kon geen trid ophalen voor product', [
                'wc_id' => $source_post_id,
            ]);
            return;
        }

        // Index vertalingen per taalcode
        $translation_by_lang = [];
        foreach ($translations as $t) {
            $skwirrel_lang = $t['language'] ?? '';
            $wpml_lang = $this->map_language($skwirrel_lang);
            if ($wpml_lang !== '') {
                $translation_by_lang[$wpml_lang] = $t;
            }
        }

        foreach ($target_languages as $lang) {
            if ($lang === $source_language) {
                continue;
            }

            $t = $translation_by_lang[$lang] ?? null;
            if (!$t) {
                // Probeer ook met 2-letterige code
                $short = substr($lang, 0, 2);
                $t = $translation_by_lang[$short] ?? null;
            }

            if (!$t) {
                $this->logger->verbose('WPML Bridge: geen vertaling gevonden voor taal', [
                    'wc_id' => $source_post_id,
                    'target_lang' => $lang,
                    'available_langs' => array_keys($translation_by_lang),
                ]);
                continue;
            }

            $this->upsert_translation($source_post_id, $trid, $lang, $t, $product, $post_type);
        }

        $this->logger->verbose('WPML Bridge: vertalingen gesynchroniseerd', [
            'wc_id' => $source_post_id,
            'target_languages' => $target_languages,
            'translations_found' => array_keys($translation_by_lang),
        ]);
    }

    /**
     * Registreer het bronproduct bij WPML in de juiste taal.
     */
    private function register_source_language(int $post_id, string $post_type, string $language): void {
        $element_type = 'post_' . $post_type;
        $trid = (int) apply_filters('wpml_element_trid', null, $post_id, $element_type);

        if (!$trid) {
            // Registreer als nieuw element
            do_action('wpml_set_element_language_details', [
                'element_id'    => $post_id,
                'element_type'  => $element_type,
                'trid'          => false, // WPML maakt een nieuwe trid aan
                'language_code' => $language,
            ]);
        }
    }

    /**
     * Maak of update een vertaling voor een product.
     */
    private function upsert_translation(
        int $source_post_id,
        int $trid,
        string $target_lang,
        array $translation_data,
        array $product,
        string $post_type
    ): void {
        $element_type = 'post_' . $post_type;

        // Controleer of er al een vertaling bestaat
        $existing_translations = (array) apply_filters('wpml_get_element_translations', null, $trid, $element_type);
        $existing_post_id = 0;
        foreach ($existing_translations as $trans) {
            if (is_object($trans) && ($trans->language_code ?? '') === $target_lang) {
                $existing_post_id = (int) ($trans->element_id ?? 0);
                break;
            }
        }

        // Bereid de post data voor
        $name = $translation_data['product_description'] ?? $translation_data['product_model'] ?? '';
        $description = $translation_data['product_long_description']
            ?? $translation_data['product_marketing_text']
            ?? $translation_data['product_web_text']
            ?? '';
        $short_desc = $translation_data['product_description'] ?? '';

        if ($name === '' && $description === '' && $short_desc === '') {
            return; // Geen bruikbare vertaaldata
        }

        // Fallback naam: pak de naam van het bronproduct
        if ($name === '') {
            $source = get_post($source_post_id);
            $name = $source ? $source->post_title : '';
        }

        $post_data = [
            'post_title'   => $name,
            'post_content' => $description,
            'post_excerpt'  => $short_desc,
            'post_type'    => $post_type,
            'post_status'  => get_post_status($source_post_id) ?: 'publish',
        ];

        if ($existing_post_id) {
            // Update bestaande vertaling
            $post_data['ID'] = $existing_post_id;
            wp_update_post($post_data);
            $translated_post_id = $existing_post_id;
        } else {
            // Maak nieuwe vertaling aan
            $translated_post_id = wp_insert_post($post_data);
            if (is_wp_error($translated_post_id) || !$translated_post_id) {
                $this->logger->warning('WPML Bridge: kon vertaling niet aanmaken', [
                    'source_id' => $source_post_id,
                    'target_lang' => $target_lang,
                ]);
                return;
            }
        }

        // Koppel aan WPML translation group
        do_action('wpml_set_element_language_details', [
            'element_id'           => $translated_post_id,
            'element_type'         => $element_type,
            'trid'                 => $trid,
            'language_code'        => $target_lang,
            'source_language_code' => $this->get_options()['source_language'] ?? 'nl',
        ]);

        // Kopieer WooCommerce product meta naar de vertaling
        $this->copy_product_meta($source_post_id, $translated_post_id);

        // Kopieer Skwirrel meta
        $this->copy_skwirrel_meta($source_post_id, $translated_post_id);

        // Vertaal categorieën als WPML taxonomy translation actief is
        $this->sync_taxonomy_translations($source_post_id, $translated_post_id, $target_lang);

        /**
         * Action: WPML vertaling is aangemaakt/bijgewerkt.
         *
         * @param int    $translated_post_id  Vertaald product ID.
         * @param int    $source_post_id      Bron product ID.
         * @param string $target_lang         WPML taalcode.
         * @param array  $translation_data    Skwirrel vertaaldata.
         * @param array  $product             Volledige Skwirrel productdata.
         */
        do_action('skwirrel_wpml_bridge_after_translation', $translated_post_id, $source_post_id, $target_lang, $translation_data, $product);

        $this->logger->verbose('WPML Bridge: vertaling opgeslagen', [
            'source_id' => $source_post_id,
            'translated_id' => $translated_post_id,
            'lang' => $target_lang,
            'is_new' => !$existing_post_id,
        ]);
    }

    /**
     * Kopieer essentiële WooCommerce product meta naar vertaling.
     */
    private function copy_product_meta(int $source_id, int $target_id): void {
        $opts = $this->get_options();
        if (empty($opts['copy_product_meta'])) {
            return;
        }

        $meta_keys = [
            '_sku',
            '_regular_price',
            '_sale_price',
            '_price',
            '_manage_stock',
            '_stock',
            '_stock_status',
            '_weight',
            '_length',
            '_width',
            '_height',
            '_tax_status',
            '_tax_class',
            '_downloadable',
            '_virtual',
            '_product_attributes',
            '_thumbnail_id',
            '_product_image_gallery',
        ];

        /**
         * Filter: welke WC meta keys gekopieerd worden naar vertalingen.
         */
        $meta_keys = apply_filters('skwirrel_wpml_bridge_copy_meta_keys', $meta_keys, $source_id);

        foreach ($meta_keys as $key) {
            $value = get_post_meta($source_id, $key, true);
            if ($value !== '' && $value !== false) {
                update_post_meta($target_id, $key, $value);
            }
        }
    }

    /**
     * Kopieer Skwirrel-specifieke meta naar vertaling.
     */
    private function copy_skwirrel_meta(int $source_id, int $target_id): void {
        $skwirrel_keys = [
            '_skwirrel_external_id',
            '_skwirrel_product_id',
            '_skwirrel_synced_at',
            '_skwirrel_document_attachments',
        ];

        foreach ($skwirrel_keys as $key) {
            $value = get_post_meta($source_id, $key, true);
            if ($value !== '' && $value !== false) {
                update_post_meta($target_id, $key, $value);
            }
        }
    }

    /**
     * Synchroniseer taxonomy termen (categorieën) naar de vertaling.
     */
    private function sync_taxonomy_translations(int $source_id, int $target_id, string $target_lang): void {
        $opts = $this->get_options();
        if (empty($opts['sync_categories'])) {
            return;
        }

        $taxonomies = ['product_cat', 'product_tag'];

        foreach ($taxonomies as $taxonomy) {
            $terms = wp_get_object_terms($source_id, $taxonomy, ['fields' => 'ids']);
            if (empty($terms) || is_wp_error($terms)) {
                continue;
            }

            $translated_term_ids = [];
            foreach ($terms as $term_id) {
                // WPML: haal vertaalde term op
                $translated_id = (int) apply_filters('wpml_object_id', $term_id, $taxonomy, false, $target_lang);
                if ($translated_id) {
                    $translated_term_ids[] = $translated_id;
                } else {
                    // Geen vertaling beschikbaar: gebruik originele term
                    $translated_term_ids[] = $term_id;
                }
            }

            if (!empty($translated_term_ids)) {
                wp_set_object_terms($target_id, $translated_term_ids, $taxonomy);
            }
        }
    }

    /**
     * Map Skwirrel taalcode naar WPML taalcode.
     */
    private function map_language(string $skwirrel_lang): string {
        $opts = $this->get_options();
        $custom_map = $opts['language_map'] ?? [];

        // Eerst custom mapping checken
        if (!empty($custom_map[$skwirrel_lang])) {
            return $custom_map[$skwirrel_lang];
        }

        // Standaard mapping
        if (isset(self::LANGUAGE_MAP[$skwirrel_lang])) {
            return self::LANGUAGE_MAP[$skwirrel_lang];
        }

        // Fallback: eerste 2 tekens
        $short = strtolower(substr($skwirrel_lang, 0, 2));
        if (strlen($short) === 2) {
            return $short;
        }

        return '';
    }

    /**
     * Haal plugin opties op.
     */
    private function get_options(): array {
        $defaults = [
            'source_language'   => 'nl',
            'target_languages'  => [],
            'copy_product_meta' => true,
            'sync_categories'   => true,
            'language_map'      => [],
        ];
        $saved = get_option('skwirrel_wpml_bridge_settings', []);
        return array_merge($defaults, is_array($saved) ? $saved : []);
    }
}
