<?php
/**
 * Skwirrel WPML Bridge — Admin instellingen.
 *
 * Eigen instellingenpagina onder WooCommerce → Skwirrel WPML Bridge.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Skwirrel_WPML_Bridge_Settings {

    private const PAGE_SLUG = 'skwirrel-wpml-bridge';
    private const OPTION_KEY = 'skwirrel_wpml_bridge_settings';

    private static ?self $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'add_menu'], 101);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function add_menu(): void {
        add_submenu_page(
            'woocommerce',
            __('Skwirrel WPML Bridge', 'skwirrel-wpml-bridge'),
            __('Skwirrel WPML', 'skwirrel-wpml-bridge'),
            'manage_woocommerce',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function register_settings(): void {
        register_setting('skwirrel_wpml_bridge', self::OPTION_KEY, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);
    }

    public function sanitize_settings(array $input): array {
        $out = [];
        $out['source_language'] = sanitize_text_field($input['source_language'] ?? 'nl');

        $targets = $input['target_languages'] ?? [];
        if (!is_array($targets)) {
            $targets = [];
        }
        $out['target_languages'] = array_map('sanitize_text_field', $targets);

        $out['copy_product_meta'] = !empty($input['copy_product_meta']);
        $out['sync_categories'] = !empty($input['sync_categories']);

        // Custom language mapping
        $custom_map = [];
        $sk_langs = $input['custom_sk_lang'] ?? [];
        $wpml_langs = $input['custom_wpml_lang'] ?? [];
        if (is_array($sk_langs) && is_array($wpml_langs)) {
            foreach ($sk_langs as $i => $sk) {
                $sk = sanitize_text_field(trim($sk));
                $wpml = sanitize_text_field(trim($wpml_langs[$i] ?? ''));
                if ($sk !== '' && $wpml !== '') {
                    $custom_map[$sk] = $wpml;
                }
            }
        }
        $out['language_map'] = $custom_map;

        return $out;
    }

    /**
     * Haal actieve WPML talen op.
     */
    private function get_wpml_languages(): array {
        $languages = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);
        if (!is_array($languages)) {
            return [];
        }
        $result = [];
        foreach ($languages as $lang) {
            $code = $lang['language_code'] ?? $lang['code'] ?? '';
            $name = $lang['translated_name'] ?? $lang['native_name'] ?? $code;
            if ($code !== '') {
                $result[$code] = $name;
            }
        }
        return $result;
    }

    public function render_page(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Geen toegang.');
        }

        $opts = get_option(self::OPTION_KEY, []);
        $defaults = [
            'source_language'   => 'nl',
            'target_languages'  => [],
            'copy_product_meta' => true,
            'sync_categories'   => true,
            'language_map'      => [],
        ];
        $opts = array_merge($defaults, is_array($opts) ? $opts : []);

        $wpml_langs = $this->get_wpml_languages();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Skwirrel WPML Bridge — Instellingen', 'skwirrel-wpml-bridge'); ?></h1>
            <p><?php esc_html_e('Maakt automatisch WPML productvertalingen aan vanuit de meertalige Skwirrel API-data.', 'skwirrel-wpml-bridge'); ?></p>

            <form method="post" action="options.php">
                <?php wp_nonce_field('options-options'); ?>
                <?php settings_fields('skwirrel_wpml_bridge'); ?>

                <h2><?php esc_html_e('Taalinstellingen', 'skwirrel-wpml-bridge'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="source_language"><?php esc_html_e('Brontaal (primaire taal)', 'skwirrel-wpml-bridge'); ?></label></th>
                        <td>
                            <select id="source_language" name="<?php echo esc_attr(self::OPTION_KEY); ?>[source_language]">
                                <?php foreach ($wpml_langs as $code => $name) : ?>
                                    <option value="<?php echo esc_attr($code); ?>" <?php selected($opts['source_language'], $code); ?>><?php echo esc_html($name . " ({$code})"); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('De taal waarin het bronproduct wordt opgeslagen door de Skwirrel hoofdplugin (normaal: nl).', 'skwirrel-wpml-bridge'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Doeltalen (vertalingen aanmaken)', 'skwirrel-wpml-bridge'); ?></th>
                        <td>
                            <?php foreach ($wpml_langs as $code => $name) : ?>
                                <label style="display: block; margin-bottom: 4px;">
                                    <input type="checkbox"
                                           name="<?php echo esc_attr(self::OPTION_KEY); ?>[target_languages][]"
                                           value="<?php echo esc_attr($code); ?>"
                                           <?php checked(in_array($code, $opts['target_languages'], true)); ?> />
                                    <?php echo esc_html($name . " ({$code})"); ?>
                                </label>
                            <?php endforeach; ?>
                            <p class="description"><?php esc_html_e('Selecteer de talen waarvoor vertalingen moeten worden aangemaakt. De brontaal hoeft niet geselecteerd te worden.', 'skwirrel-wpml-bridge'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Sync opties', 'skwirrel-wpml-bridge'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Product meta kopiëren', 'skwirrel-wpml-bridge'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[copy_product_meta]" value="1" <?php checked(!empty($opts['copy_product_meta'])); ?> />
                                <?php esc_html_e('WooCommerce product meta (SKU, prijs, voorraad, afbeeldingen) kopiëren naar vertalingen', 'skwirrel-wpml-bridge'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Meestal wil je dit aan laten staan. Zo hebben vertalingen dezelfde prijs/SKU/afbeeldingen als het origineel.', 'skwirrel-wpml-bridge'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Categorieën koppelen', 'skwirrel-wpml-bridge'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[sync_categories]" value="1" <?php checked(!empty($opts['sync_categories'])); ?> />
                                <?php esc_html_e('Product categorieën meekoppelen aan vertaalde producten (gebruikt WPML taxonomy vertalingen)', 'skwirrel-wpml-bridge'); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Taal-mapping (Skwirrel → WPML)', 'skwirrel-wpml-bridge'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Standaard mapping:', 'skwirrel-wpml-bridge'); ?>
                    <code>nl-NL → nl</code>, <code>en-GB → en</code>, <code>de-DE → de</code>, <code>fr-FR → fr</code>.
                    <?php esc_html_e('Voeg hier extra of afwijkende mappings toe.', 'skwirrel-wpml-bridge'); ?>
                </p>

                <table class="widefat" id="skwirrel-wpml-lang-map" style="max-width: 500px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Skwirrel taalcode', 'skwirrel-wpml-bridge'); ?></th>
                            <th><?php esc_html_e('WPML taalcode', 'skwirrel-wpml-bridge'); ?></th>
                            <th style="width: 50px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $lang_map = $opts['language_map'] ?? [];
                        if (empty($lang_map)) {
                            $lang_map = ['' => ''];
                        }
                        foreach ($lang_map as $sk_lang => $wpml_lang) : ?>
                            <tr>
                                <td><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[custom_sk_lang][]" value="<?php echo esc_attr($sk_lang); ?>" class="regular-text" placeholder="bijv. en-US" /></td>
                                <td><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[custom_wpml_lang][]" value="<?php echo esc_attr($wpml_lang); ?>" class="regular-text" placeholder="bijv. en" /></td>
                                <td><button type="button" class="button" onclick="this.closest('tr').remove();">&times;</button></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p>
                    <button type="button" class="button" onclick="
                        var tbody = document.querySelector('#skwirrel-wpml-lang-map tbody');
                        var tr = tbody.querySelector('tr').cloneNode(true);
                        tr.querySelectorAll('input').forEach(function(i){i.value='';});
                        tbody.appendChild(tr);
                    "><?php esc_html_e('+ Regel toevoegen', 'skwirrel-wpml-bridge'); ?></button>
                </p>

                <h2><?php esc_html_e('Hoe werkt het?', 'skwirrel-wpml-bridge'); ?></h2>
                <div style="background: #f0f6fc; border: 1px solid #c8d8e8; padding: 15px; border-radius: 4px; max-width: 700px;">
                    <ol style="margin: 0; padding-left: 1.5em;">
                        <li><?php esc_html_e('De Skwirrel hoofdplugin synchroniseert producten in de brontaal (bijv. Nederlands).', 'skwirrel-wpml-bridge'); ?></li>
                        <li><?php esc_html_e('De Skwirrel API levert meertalige data mee (_product_translations).', 'skwirrel-wpml-bridge'); ?></li>
                        <li><?php esc_html_e('Na elke product-save maakt deze bridge automatisch WPML vertalingen aan voor de geselecteerde doeltalen.', 'skwirrel-wpml-bridge'); ?></li>
                        <li><?php esc_html_e('Product titel, beschrijving en korte beschrijving worden gevuld vanuit de Skwirrel vertaaldata.', 'skwirrel-wpml-bridge'); ?></li>
                        <li><?php esc_html_e('WC meta (prijs, SKU, afbeeldingen) wordt optioneel gekopieerd.', 'skwirrel-wpml-bridge'); ?></li>
                    </ol>
                </div>

                <?php submit_button(__('Instellingen opslaan', 'skwirrel-wpml-bridge')); ?>
            </form>
        </div>
        <?php
    }
}
