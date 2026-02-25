<?php
/**
 * Skwirrel → WooCommerce Product Mapper.
 *
 * Maps Skwirrel product data to WooCommerce product fields.
 * Schema reference: Context/getProducts.json (ProductExporterSchema-v12)
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Skwirrel_WC_Sync_Product_Mapper {

    private const EXTERNAL_ID_META = '_skwirrel_external_id';
    private const PRODUCT_ID_META = '_skwirrel_product_id';
    private const SYNCED_AT_META = '_skwirrel_synced_at';
    public const CATEGORY_ID_META = '_skwirrel_category_id';

    private Skwirrel_WC_Sync_Logger $logger;
    private Skwirrel_WC_Sync_Media_Importer $media_importer;

    public function __construct() {
        $this->logger = new Skwirrel_WC_Sync_Logger();
        $this->media_importer = new Skwirrel_WC_Sync_Media_Importer();
    }

    /**
     * Get product_attachment_title and product_attachment_description for the configured image language.
     * Language pattern: ^[a-z]{2}(-[A-Z]{2}){0,1}$ (e.g. nl, nl-NL).
     */
    private function get_attachment_meta_for_language(array $att): array {
        $lang = get_option('skwirrel_wc_sync_settings', [])['image_language'] ?? 'nl';
        $translations = $att['_attachment_translations'] ?? [];
        if (empty($translations)) {
            return ['title' => $att['file_name'] ?? '', 'description' => ''];
        }
        foreach ($translations as $t) {
            $tlang = (string) ($t['language'] ?? '');
            if (strcasecmp($tlang, $lang) === 0) {
                return [
                    'title' => (string) ($t['product_attachment_title'] ?? $att['file_name'] ?? ''),
                    'description' => (string) ($t['product_attachment_description'] ?? ''),
                ];
            }
        }
        foreach ($translations as $t) {
            $tlang = (string) ($t['language'] ?? '');
            if (strlen($lang) >= 2 && strlen($tlang) >= 2 && strcasecmp(substr($tlang, 0, 2), substr($lang, 0, 2)) === 0) {
                return [
                    'title' => (string) ($t['product_attachment_title'] ?? $att['file_name'] ?? ''),
                    'description' => (string) ($t['product_attachment_description'] ?? ''),
                ];
            }
        }
        $list = array_values((array) $translations);
        $first = $list[0] ?? [];
        return [
            'title' => (string) ($first['product_attachment_title'] ?? $att['file_name'] ?? ''),
            'description' => (string) ($first['product_attachment_description'] ?? ''),
        ];
    }

    /**
     * Check if string is a valid HTTP(S) URL. Uses filter_var with parse_url fallback.
     */
    private function is_valid_url(string $url): bool {
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return true;
        }
        $parsed = wp_parse_url($url);
        return isset($parsed['scheme'], $parsed['host'])
            && in_array(strtolower($parsed['scheme']), ['http', 'https'], true);
    }

    /**
     * Normalize URL from API: replace JSON-escaped \/ with /, trim, then rawurldecode.
     * Handles both single and double-escaped URLs from JSON.
     */
    private function normalize_attachment_url(string $url): string {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        while (str_contains($url, '\\/')) {
            $url = str_replace('\\/', '/', $url);
        }
        return rawurldecode($url);
    }

    /**
     * Get unique identifier for Skwirrel product (for upsert).
     * Prefer external_product_id, fallback internal_product_code.
     */
    public function get_unique_key(array $product): ?string {
        $external = $product['external_product_id'] ?? null;
        if (!empty($external) && is_string($external)) {
            return 'ext:' . $external;
        }
        $code = $product['internal_product_code'] ?? null;
        if (!empty($code) && is_string($code)) {
            return 'sku:' . $code;
        }
        $code = $product['manufacturer_product_code'] ?? null;
        if (!empty($code) && is_string($code)) {
            return 'sku:' . $code;
        }
        $id = $product['product_id'] ?? null;
        if ($id !== null && $id !== '') {
            return 'id:' . $id;
        }
        return null;
    }

    /**
     * Get SKU for WooCommerce. Uses setting use_sku_field.
     */
    public function get_sku(array $product): string {
        $opts = get_option('skwirrel_wc_sync_settings', []);
        $field = $opts['use_sku_field'] ?? 'internal_product_code';
        if ($field === 'manufacturer_product_code') {
            $sku = (string) ($product['manufacturer_product_code'] ?? $product['internal_product_code'] ?? '');
        } else {
            $sku = (string) ($product['internal_product_code'] ?? $product['manufacturer_product_code'] ?? '');
        }
        if ($sku === '' && isset($product['product_id'])) {
            return 'SKW-' . $product['product_id'];
        }
        return $sku;
    }

    /**
     * Get product name. Prefer product_erp_description, then translations.
     */
    public function get_name(array $product): string {
        $erp = $product['product_erp_description'] ?? '';
        if (!empty($erp)) {
            return $erp;
        }
        $translations = $product['_product_translations'] ?? [];
        if (!empty($translations)) {
            $t = $this->pick_translation($translations);
            return $t['product_model'] ?? $t['product_description'] ?? '';
        }
        return '';
    }

    /**
     * Get short description.
     */
    public function get_short_description(array $product): string {
        $translations = $product['_product_translations'] ?? [];
        if (empty($translations)) {
            return '';
        }
        $t = $this->pick_translation($translations);
        return $t['product_description'] ?? '';
    }

    /**
     * Get long description.
     */
    public function get_long_description(array $product): string {
        $translations = $product['_product_translations'] ?? [];
        if (empty($translations)) {
            return '';
        }
        $t = $this->pick_translation($translations);
        return $t['product_long_description'] ?? $t['product_marketing_text'] ?? $t['product_web_text'] ?? '';
    }

    /**
     * Get product status: publish, draft, trash.
     */
    public function get_status(array $product): string {
        if (!empty($product['product_trashed_on'])) {
            return 'trash';
        }
        $status = $product['_product_status']['product_status_description'] ?? null;
        if ($status && stripos((string) $status, 'draft') !== false) {
            return 'draft';
        }
        return 'publish';
    }

    /**
     * Get price from first trade item.
     */
    public function get_price(array $product): ?float {
        $trade_items = $product['_trade_items'] ?? [];
        foreach ($trade_items as $ti) {
            $prices = $ti['_trade_item_prices'] ?? [];
            foreach ($prices as $p) {
                if (!empty($p['price_on_request'])) {
                    return null; // price on request
                }
                $net = $p['net_price'] ?? null;
                if ($net !== null && $net >= 0) {
                    return (float) $net;
                }
            }
        }
        return null;
    }

    /**
     * Get regular price. Same as get_price for now (no sale mapping).
     */
    public function get_regular_price(array $product): ?float {
        return $this->get_price($product);
    }

    /**
     * Get price on request flag.
     */
    public function is_price_on_request(array $product): bool {
        $trade_items = $product['_trade_items'] ?? [];
        foreach ($trade_items as $ti) {
            $prices = $ti['_trade_item_prices'] ?? [];
            foreach ($prices as $p) {
                if (!empty($p['price_on_request'])) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Get attachment IDs for images (featured + gallery).
     * @param int $product_id WooCommerce product post ID to attach media to (0 = no parent).
     */
    public function get_image_attachment_ids(array $product, int $product_id = 0): array {
        $opts = get_option('skwirrel_wc_sync_settings', []);
        if (isset($opts['sync_images']) && !$opts['sync_images']) {
            return [];
        }

        $attachments = $product['_attachments'] ?? $product['attachments'] ?? [];
        $ids = [];
        foreach ($attachments as $att) {
            if (!empty($att['for_internal_use'])) {
                continue;
            }
            $code = $att['product_attachment_type_code'] ?? '';
            if (!$this->media_importer->is_image_attachment_type($code)) {
                continue;
            }
            $url = $this->normalize_attachment_url($att['source_url'] ?? $att['file_source_url'] ?? $att['url'] ?? '');
            if (empty($url) || !$this->is_valid_url($url)) {
                $this->logger->debug('Skipping image attachment: no valid URL', [
                    'product' => $product['internal_product_code'] ?? $product['product_id'] ?? '?',
                    'code' => $code,
                    'source_url' => $att['source_url'] ?? null,
                ]);
                continue;
            }
            $order = $att['product_attachment_order'] ?? $att['order'] ?? 999;
            $meta = $this->get_attachment_meta_for_language($att);
            $id = $this->media_importer->import_image($url, $att['file_name'] ?? '', $product_id, $meta['title'], $meta['description']);
            if ($id && !in_array($id, $ids, true)) {
                $ids[] = ['id' => $id, 'order' => $order];
            }
        }
        usort($ids, fn($a, $b) => $a['order'] <=> $b['order']);
        $result = array_map(fn($x) => $x['id'], $ids);
        if (!empty($attachments) && empty($result)) {
            $this->logger->debug('Product has attachments but no image imports succeeded', [
                'product' => $product['internal_product_code'] ?? $product['product_id'] ?? '?',
                'attachment_count' => count($attachments),
                'sample' => array_slice($attachments, 0, 2),
            ]);
        }
        return $result;
    }

    /**
     * Get downloadable file URLs/names for product (MAN, DAT, etc.).
     * @param int $product_id WooCommerce product post ID to attach media to (0 = no parent).
     */
    public function get_downloadable_files(array $product, int $product_id = 0): array {
        $attachments = $product['_attachments'] ?? $product['attachments'] ?? [];
        $files = [];
        foreach ($attachments as $att) {
            if (!empty($att['for_internal_use'])) {
                continue;
            }
            $code = $att['product_attachment_type_code'] ?? '';
            if ($this->media_importer->is_image_attachment_type($code)) {
                continue;
            }
            $url = $this->normalize_attachment_url($att['source_url'] ?? $att['file_source_url'] ?? $att['url'] ?? '');
            if (empty($url) || !$this->is_valid_url($url)) {
                continue;
            }
            $name = $att['file_name'] ?? 'Download';
            $id = $this->media_importer->import_file($url, $name, $product_id);
            if ($id) {
                $guid = wp_get_attachment_url($id);
                if ($guid) {
                    $files[] = [
                        'name' => $name,
                        'file' => $guid,
                    ];
                }
            }
        }
        return $files;
    }

    /**
     * Get document attachments (PDF, etc.) for product tab and dashboard.
     * Uses same non-image sources as downloadable files but returns full metadata for display.
     * @param int $product_id WooCommerce product post ID to attach media to (0 = no parent).
     * @return array<int, array{id: int, url: string, name: string, type: string, type_label: string}>
     */
    public function get_document_attachments(array $product, int $product_id = 0): array {
        $raw = $product['_attachments'] ?? $product['attachments'] ?? [];
        $attachments = is_array($raw) && isset($raw[0]) ? $raw : (is_array($raw) ? array_values($raw) : []);
        $docs = [];
        foreach ($attachments as $att) {
            if (!is_array($att)) {
                continue;
            }
            if (!empty($att['for_internal_use'])) {
                continue;
            }
            $code = (string) ($att['product_attachment_type_code'] ?? $att['attachment_type_code'] ?? '');
            $url = $this->normalize_attachment_url($att['source_url'] ?? $att['file_source_url'] ?? $att['url'] ?? '');
            if (empty($url) || !$this->is_valid_url($url)) {
                continue;
            }
            if ($this->media_importer->is_image_attachment_type($code)) {
                continue;
            }
            $name = (string) ($att['file_name'] ?? $att['product_attachment_title'] ?? '');
            if ($name === '') {
                $path = wp_parse_url($url, PHP_URL_PATH);
                $name = $path ? basename($path) : 'Document';
            }
            $id = $this->media_importer->import_file($url, $name, $product_id);
            if ($id) {
                $guid = wp_get_attachment_url($id);
                if ($guid) {
                    $docs[] = [
                        'id' => $id,
                        'url' => $guid,
                        'name' => $name,
                        'type' => $code,
                        'type_label' => $this->get_document_type_label($code),
                    ];
                }
            }
        }
        return $docs;
    }

    /** Document type codes from Skwirrel: MAN=Manual, DAT=Datasheet, etc. */
    private function get_document_type_label(string $code): string {
        $labels = [
            'MAN' => __('Manual', 'skwirrel-pim-wp-sync'),
            'DAT' => __('Datasheet', 'skwirrel-pim-wp-sync'),
            'CER' => __('Certificate', 'skwirrel-pim-wp-sync'),
            'WAR' => __('Warranty', 'skwirrel-pim-wp-sync'),
            'OTV' => __('Other document', 'skwirrel-pim-wp-sync'),
        ];
        return $labels[strtoupper($code)] ?? $code;
    }

    /**
     * Get attributes/specs for product (custom attributes, no taxonomy needed).
     * Includes Brand, Manufacturer, GTIN, and ETIM features from _product_groups._etim._etim_features.
     */
    public function get_attributes(array $product): array {
        $attrs = [];
        $brand = $product['brand_name'] ?? '';
        if (!empty($brand)) {
            $attrs['Brand'] = $brand;
        }
        $manufacturer = $product['manufacturer_name'] ?? '';
        if (!empty($manufacturer)) {
            $attrs['Manufacturer'] = $manufacturer;
        }
        $gtin = $product['product_gtin'] ?? '';
        if (!empty($gtin)) {
            $attrs['GTIN'] = $gtin;
        }
        $etim = $this->get_etim_attributes($product);
        foreach ($etim as $name => $value) {
            if ($value !== '' && $value !== null) {
                $attrs[$name] = (string) $value;
            }
        }
        $product_id = $product['internal_product_code'] ?? $product['product_id'] ?? '?';
        $etimItems = $this->collect_etim_items($product);
        $this->logger->verbose('get_attributes result', [
            'product' => $product_id,
            'total_attrs' => count($attrs),
            'base_attrs' => ['brand' => !empty($brand), 'manufacturer' => !empty($manufacturer), 'gtin' => !empty($gtin)],
            'etim_attrs' => count($etim),
            'etim_items' => count($etimItems),
        ]);
        if (empty($attrs)) {
            $this->logger->debug('Product has no attributes', [
                'product' => $product_id,
                'has_brand' => !empty($product['brand_name'] ?? ''),
                'has_manufacturer' => !empty($product['manufacturer_name'] ?? ''),
                'has_gtin' => !empty($product['product_gtin'] ?? ''),
                'has__etim' => isset($product['_etim']),
                'etim_items_count' => count($etimItems),
                'has_product_groups' => !empty($product['_product_groups'] ?? []),
            ]);
        } elseif (empty($etim) && !empty($etimItems)) {
            $this->logger->debug('Product has base attributes but no ETIM values; features may be not_applicable', [
                'product' => $product_id,
                'attr_count' => count($attrs),
                'etim_features_count' => count($etimItems[0]['_etim_features'] ?? []),
            ]);
        }
        return $attrs;
    }

    /**
     * Get ETIM features as attributes from product._etim or _product_groups[]._etim.
     * Uses content language for labels/values.
     * Feature types: A=alphanumeric, L=logical, N=numeric, R=range, C=class, M=modelling.
     */
    private function get_etim_attributes(array $product): array {
        $lang = get_option('skwirrel_wc_sync_settings', [])['image_language'] ?? 'nl';
        $attrs = [];
        $seen = [];
        $etimItems = $this->collect_etim_items($product);
        $product_id = $product['internal_product_code'] ?? $product['product_id'] ?? '?';
        $this->logger->verbose('ETIM extraction', [
            'product' => $product_id,
            'lang' => $lang,
            'etim_items_count' => count($etimItems),
            'has__etim' => isset($product['_etim']),
            'has_product_groups' => !empty($product['_product_groups'] ?? []),
        ]);
        foreach ($etimItems as $etim) {
            $features = $etim['_etim_features'] ?? [];
            $features = $this->normalize_etim_features($features);
            foreach ($features as $feat) {
                $trans = $this->normalize_etim_translations($feat['_etim_feature_translations'] ?? []);
                $label = $this->pick_etim_translation($trans, $lang, 'etim_feature_description');
                if ($label === '') {
                    $label = $feat['etim_feature_code'] ?? '';
                }
                $value = $this->format_etim_feature_value($feat, $lang);
                if ($value === null || $value === '') {
                    $this->logger->verbose('ETIM feature skipped (no value)', [
                        'product' => $product['internal_product_code'] ?? $product['product_id'] ?? '?',
                        'code' => $feat['etim_feature_code'] ?? '?',
                        'label' => $label,
                        'type' => $feat['etim_feature_type'] ?? '',
                        'not_applicable' => $feat['not_applicable'] ?? null,
                        'logical_value' => $feat['logical_value'] ?? null,
                        'numeric_value' => $feat['numeric_value'] ?? null,
                    ]);
                    continue;
                }
                $key = $feat['etim_feature_code'] ?? ('etim_' . ($feat['order_number'] ?? 0));
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $attrs[$label] = $value;
                $this->logger->verbose('ETIM attribute added', [
                    'product' => $product_id,
                    'label' => $label,
                    'value' => $value,
                    'code' => $key,
                ]);
            }
        }
        if (empty($attrs) && !empty($etimItems)) {
            $this->logger->debug('ETIM items found but no attributes extracted', [
                'product' => $product['internal_product_code'] ?? $product['product_id'] ?? '?',
                'etim_count' => count($etimItems),
                'sample_feature' => $etimItems[0]['_etim_features'] ?? null,
            ]);
        }
        return $attrs;
    }

    /**
     * Collect ETIM items from product._etim, product._etim_features, and product._product_groups[]._etim.
     * Also recursively searches for _etim_features anywhere in the product structure.
     */
    private function collect_etim_items(array $product): array {
        $items = [];
        $raw = $product['_etim'] ?? null;
        $etimItems = (is_array($raw) && isset($raw[0])) ? $raw : ($raw ? [$raw] : []);
        foreach ($etimItems as $etim) {
            if (!empty($etim['_etim_features'])) {
                $items[] = $etim;
            }
        }
        // Fallback: some APIs return _etim_features directly on product
        if (empty($items) && !empty($product['_etim_features'])) {
            $items[] = ['_etim_features' => $product['_etim_features']];
        }
        $groups = $product['_product_groups'] ?? [];
        foreach ($groups as $g) {
            $raw = $g['_etim'] ?? null;
            $groupEtim = (is_array($raw) && isset($raw[0])) ? $raw : ($raw ? [$raw] : []);
            foreach ($groupEtim as $etim) {
                if (!empty($etim['_etim_features'])) {
                    $items[] = $etim;
                }
            }
            if (!empty($g['_etim_features'])) {
                $items[] = ['_etim_features' => $g['_etim_features']];
            }
        }
        // Last resort: recursively find _etim_features anywhere in product
        if (empty($items)) {
            $found = $this->find_etim_features_recursive($product);
            foreach ($found as $feat) {
                $items[] = ['_etim_features' => $feat];
            }
        }
        if (!empty($items)) {
            $total_features = 0;
            foreach ($items as $etim) {
                $features = $etim['_etim_features'] ?? [];
                $total_features += is_array($features) && !isset($features[0]) ? count($features) : count((array) $features);
            }
            $this->logger->verbose('ETIM collected', [
                'product' => $product['internal_product_code'] ?? $product['product_id'] ?? '?',
                'sources' => ['product._etim' => !empty($product['_etim']), 'product_groups' => count($groups)],
                'etim_items' => count($items),
                'total_features' => $total_features,
            ]);
        }
        return $items;
    }

    /**
     * Recursively find arrays that look like _etim_features (contain etim_feature_code).
     */
    private function find_etim_features_recursive(array $data, int $depth = 0): array {
        if ($depth > 10) {
            return [];
        }
        $found = [];
        foreach ($data as $key => $val) {
            if (!is_array($val)) {
                continue;
            }
            $has_feature_code = false;
            $count = 0;
            foreach ($val as $v) {
                if (is_array($v) && isset($v['etim_feature_code'])) {
                    $has_feature_code = true;
                    $count++;
                }
            }
            if ($has_feature_code && $count > 0) {
                $found[] = $val;
            } else {
                $found = array_merge($found, $this->find_etim_features_recursive($val, $depth + 1));
            }
        }
        return $found;
    }

    /**
     * Normalize features: object keyed by feature code -> array. Preserves code into each feature.
     */
    private function normalize_etim_features($features): array {
        if (empty($features)) {
            return [];
        }
        $isAssoc = is_array($features) && !isset($features[0]);
        $list = $isAssoc ? $features : (array) $features;
        $result = [];
        foreach ($list as $k => $feat) {
            if (!is_array($feat)) {
                continue;
            }
            if ($isAssoc && empty($feat['etim_feature_code']) && is_string($k) && preg_match('/^[A-Za-z0-9]+$/', $k)) {
                $feat['etim_feature_code'] = $k;
            }
            $result[] = $feat;
        }
        return $result;
    }

    /**
     * Normalize translation structure. Handles:
     * - Array of {language: 'nl-NL', field: value}
     * - Object keyed by language: {'nl-NL': {field: value}}
     */
    private function normalize_etim_translations($trans): array {
        if (empty($trans)) {
            return [];
        }
        $list = is_array($trans) && isset($trans[0]) ? $trans : array_values((array) $trans);
        $normalized = [];
        foreach ($list as $t) {
            if (!is_array($t)) {
                continue;
            }
            if (isset($t['language'])) {
                $normalized[] = $t;
                continue;
            }
        }
        if (!empty($normalized)) {
            return $normalized;
        }
        $assoc = (array) $trans;
        foreach ($assoc as $langCode => $data) {
            if (is_array($data) && preg_match('/^[a-z]{2}(-[A-Z]{2})?$/i', (string) $langCode)) {
                $normalized[] = array_merge(['language' => $langCode], $data);
            }
        }
        return $normalized;
    }

    /**
     * Resolve a human-readable label for an ETIM feature from its translations.
     * Falls back to the raw etim_feature_code if no translation is found.
     */
    public function resolve_etim_feature_label(array $feature, string $lang = ''): string {
        if ($lang === '') {
            $lang = get_option('skwirrel_wc_sync_settings', [])['image_language'] ?? 'nl';
        }
        $trans = $this->normalize_etim_translations($feature['_etim_feature_translations'] ?? []);
        $label = $this->pick_etim_translation($trans, $lang, 'etim_feature_description');
        return $label !== '' ? $label : ((string) ($feature['etim_feature_code'] ?? ''));
    }

    private function pick_etim_translation(array $translations, string $lang, string $field): string {
        foreach ($translations as $t) {
            $tlang = (string) ($t['language'] ?? '');
            if (strcasecmp($tlang, $lang) === 0) {
                return (string) ($t[$field] ?? '');
            }
        }
        foreach ($translations as $t) {
            $tlang = (string) ($t['language'] ?? '');
            if (strlen($lang) >= 2 && strlen($tlang) >= 2 && strcasecmp(substr($tlang, 0, 2), substr($lang, 0, 2)) === 0) {
                return (string) ($t[$field] ?? '');
            }
        }
        $list = array_values((array) $translations);
        $first = $list[0] ?? [];
        return (string) (is_array($first) ? ($first[$field] ?? '') : '');
    }

    private function format_etim_feature_value(array $feat, string $lang): ?string {
        if (!empty($feat['not_applicable'])) {
            return null;
        }
        $type = $feat['etim_feature_type'] ?? '';
        $valTrans = $this->normalize_etim_translations($feat['_etim_value_translations'] ?? []);
        if ($type === 'A' && !empty($feat['etim_value_code'])) {
            $val = $this->pick_etim_translation($valTrans, $lang, 'etim_value_description');
            return $val ?: $feat['etim_value_code'];
        }
        $unitTrans = $this->normalize_etim_translations($feat['_etim_unit_translations'] ?? []);
        if ($type === 'N' && $feat['numeric_value'] !== null && $feat['numeric_value'] !== '') {
            $unit = $this->pick_etim_translation($unitTrans, $lang, 'etim_unit_abbreviation');
            $unit = $unit ?: $this->pick_etim_translation($unitTrans, $lang, 'etim_unit_description');
            return $feat['numeric_value'] . ($unit ? ' ' . $unit : '');
        }
        $isLogical = ($type === 'L' || strtoupper((string) $type) === 'LOGICAL' || empty($type));
        if ($isLogical && array_key_exists('logical_value', $feat) && $feat['logical_value'] !== null) {
            return $feat['logical_value'] ? 'Ja' : 'Nee';
        }
        if ($type === 'R' && ($feat['range_min'] !== null || $feat['range_max'] !== null)) {
            $min = $feat['range_min'] ?? '';
            $max = $feat['range_max'] ?? '';
            $unit = $this->pick_etim_translation($unitTrans, $lang, 'etim_unit_abbreviation');
            $unit = $unit ?: $this->pick_etim_translation($unitTrans, $lang, 'etim_unit_description');
            $s = $min . ($min !== '' && $max !== '' ? ' - ' : '') . $max;
            return $s . ($unit ? ' ' . $unit : '');
        }
        if ($type === 'A' && empty($feat['etim_value_code']) && $feat['numeric_value'] !== null) {
            return (string) $feat['numeric_value'];
        }
        if (in_array($type, ['C', 'M'], true) && !empty($feat['etim_value_code'])) {
            $val = $this->pick_etim_translation($valTrans, $lang, 'etim_value_description');
            return $val ?: $feat['etim_value_code'];
        }
        return null;
    }

    /**
     * Get eTIM feature values for specific codes (for variation attributes).
     * Returns array of [etim_code => ['label' => string, 'value' => string, 'slug' => string]].
     * Only includes features that have a value.
     */
    public function get_etim_feature_values_for_codes(array $product, array $etim_codes, string $lang = ''): array {
        if (empty($etim_codes)) {
            return [];
        }
        if ($lang === '') {
            $lang = get_option('skwirrel_wc_sync_settings', [])['image_language'] ?? 'nl';
        }
        $code_list = [];
        foreach ($etim_codes as $c) {
            $code_list[] = strtoupper((string) ($c['code'] ?? $c['etim_feature_code'] ?? ''));
        }
        $codes = array_flip(array_filter($code_list));
        $result = [];
        $etimItems = $this->collect_etim_items($product);

        foreach ($etimItems as $etim) {
            $features = $this->normalize_etim_features($etim['_etim_features'] ?? []);
            foreach ($features as $feat) {
                $code = strtoupper((string) ($feat['etim_feature_code'] ?? ''));
                if (!isset($codes[$code])) {
                    continue;
                }
                $value = $this->format_etim_feature_value($feat, $lang);
                if ($value === null || $value === '') {
                    continue;
                }
                $trans = $this->normalize_etim_translations($feat['_etim_feature_translations'] ?? []);
                $label = $this->pick_etim_translation($trans, $lang, 'etim_feature_description');
                if ($label === '') {
                    $label = $code;
                }
                $slug = sanitize_title($value);
                if ($slug === '') {
                    $slug = sanitize_title((string) $value);
                }
                $result[$code] = [
                    'label' => $label,
                    'value' => $value,
                    'slug' => $slug ?: 'val-' . $code,
                ];
            }
        }
        return $result;
    }

    /**
     * Get structured category data from product.
     * Primary source: $product['_categories'] (when include_categories is enabled).
     * Fallback: $product['_product_groups'] (legacy behavior).
     *
     * Walks the full _parent_category chain recursively so that the complete
     * category tree (grandparents, great-grandparents, …) is included.
     *
     * @return array<int, array{id: int|null, name: string, parent_id: int|null, parent_name: string}>
     */
    public function get_categories(array $product): array {
        $categories = [];
        $seen_ids = [];

        // Primary source: _categories array (from include_categories API flag)
        $raw_cats = $product['_categories'] ?? [];
        if (!empty($raw_cats) && is_array($raw_cats)) {
            foreach ($raw_cats as $cat) {
                if (!is_array($cat)) {
                    continue;
                }

                // Walk the _parent_category chain first so ancestors are added
                // before the leaf category. This inserts from root → leaf.
                $this->extract_ancestor_chain($cat, $categories, $seen_ids);
            }
        }

        // Fallback: _product_groups (legacy — only group name, no ID)
        if (empty($categories)) {
            $groups = $product['_product_groups'] ?? [];
            foreach ($groups as $g) {
                $name = (string) ($g['product_group_name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $group_id = $g['product_group_id'] ?? $g['id'] ?? null;
                $categories[] = [
                    'id' => $group_id !== null ? (int) $group_id : null,
                    'name' => $name,
                    'parent_id' => null,
                    'parent_name' => '',
                ];
            }
        }

        // Deduplicate by name (for entries without ID, or fallback source)
        $unique = [];
        $names_seen = [];
        foreach ($categories as $cat) {
            $key = strtolower($cat['name']);
            if (isset($names_seen[$key])) {
                continue;
            }
            $names_seen[$key] = true;
            $unique[] = $cat;
        }

        $product_id = $product['internal_product_code'] ?? $product['product_id'] ?? '?';
        $this->logger->verbose('get_categories result', [
            'product' => $product_id,
            'source' => !empty($raw_cats) ? '_categories' : '_product_groups',
            'count' => count($unique),
            'names' => array_column($unique, 'name'),
        ]);

        return $unique;
    }

    /**
     * Recursively walk the _parent_category chain and add each ancestor
     * (root-first) plus the category itself to $categories.
     *
     * Deduplicates by Skwirrel category ID so that categories shared by
     * multiple products or referenced as both leaf and ancestor appear once.
     *
     * @param array                $cat        Single category entry from the API
     * @param array<int, array>    &$categories Accumulated category list (mutated)
     * @param array<int|string, bool> &$seen_ids  Tracks already-added Skwirrel IDs (mutated)
     */
    private function extract_ancestor_chain(array $cat, array &$categories, array &$seen_ids): void {
        $cat_id = $cat['category_id'] ?? $cat['product_category_id'] ?? $cat['id'] ?? null;
        $name = $this->pick_category_translation($cat);
        if ($name === '') {
            $name = (string) ($cat['category_name'] ?? $cat['product_category_name'] ?? $cat['name'] ?? '');
        }
        if ($name === '') {
            return;
        }

        $parent_id = $cat['parent_category_id'] ?? $cat['parent_id'] ?? null;
        $parent_name = '';

        // Recurse into _parent_category first (so ancestors are added root→leaf)
        if (!empty($cat['_parent_category']) && is_array($cat['_parent_category'])) {
            $this->extract_ancestor_chain($cat['_parent_category'], $categories, $seen_ids);
            $parent_name = $this->pick_category_translation($cat['_parent_category']);
            if ($parent_name === '') {
                $parent_name = (string) ($cat['_parent_category']['category_name'] ?? $cat['_parent_category']['name'] ?? '');
            }
            // Ensure parent_id is set from the nested object if not on the current entry
            if ($parent_id === null) {
                $parent_id = $cat['_parent_category']['category_id']
                    ?? $cat['_parent_category']['product_category_id']
                    ?? $cat['_parent_category']['id']
                    ?? null;
            }
        }
        $parent_name = $parent_name ?: (string) ($cat['parent_category_name'] ?? $cat['parent_name'] ?? '');

        // Skip if we already added this exact category (by ID)
        if ($cat_id !== null && isset($seen_ids[$cat_id])) {
            return;
        }

        if ($cat_id !== null) {
            $seen_ids[$cat_id] = true;
        }

        $categories[] = [
            'id' => $cat_id !== null ? (int) $cat_id : null,
            'name' => $name,
            'parent_id' => $parent_id !== null ? (int) $parent_id : null,
            'parent_name' => $parent_name,
        ];
    }

    /**
     * Pick translated category name using existing pick_translation pattern.
     */
    private function pick_category_translation(array $cat): string {
        $translations = $cat['_category_translations'] ?? $cat['_translations'] ?? [];
        if (empty($translations) || !is_array($translations)) {
            return '';
        }
        $t = $this->pick_translation($translations);
        return (string) ($t['category_name'] ?? $t['product_category_name'] ?? $t['name'] ?? '');
    }

    /**
     * Get category names from product groups (backward-compatible wrapper).
     */
    public function get_category_names(array $product): array {
        $categories = $this->get_categories($product);
        return array_values(array_unique(array_column($categories, 'name')));
    }

    public function get_external_id_meta_key(): string {
        return self::EXTERNAL_ID_META;
    }

    public function get_product_id_meta_key(): string {
        return self::PRODUCT_ID_META;
    }

    public function get_synced_at_meta_key(): string {
        return self::SYNCED_AT_META;
    }

    private function pick_translation(array $translations): array {
        $locale = get_locale();
        $lang = substr($locale, 0, 2);
        foreach ($translations as $t) {
            $l = $t['language'] ?? '';
            if (str_starts_with($l, $lang)) {
                return $t;
            }
        }
        return $translations[0] ?? [];
    }

    // ------------------------------------------------------------------
    // Custom Classes
    // ------------------------------------------------------------------

    /** Custom feature types that are stored as product attributes. */
    private const CC_ATTRIBUTE_TYPES = ['A', 'M', 'L', 'N', 'R', 'D', 'I'];

    /** Custom feature types that are stored as product meta (long text). */
    private const CC_META_TYPES = ['T', 'B'];

    /**
     * Collect custom class objects from the product, optionally including trade-item level.
     *
     * @param array $product             Raw API product
     * @param bool  $include_trade_items Also include _trade_item_custom_classes
     * @return array<int, array>         Flat list of custom class objects
     */
    private function collect_custom_classes(array $product, bool $include_trade_items = false): array {
        $classes = [];
        // Product-level custom classes
        $raw = $product['_custom_classes'] ?? [];
        if (is_array($raw)) {
            foreach ($raw as $cc) {
                if (is_array($cc) && !empty($cc['_custom_features'])) {
                    $classes[] = $cc;
                }
            }
        }
        // Trade-item level custom classes
        if ($include_trade_items) {
            $trade_items = $product['_trade_items'] ?? [];
            foreach ($trade_items as $ti) {
                $ti_classes = $ti['_trade_item_custom_classes'] ?? [];
                if (!is_array($ti_classes)) {
                    continue;
                }
                foreach ($ti_classes as $cc) {
                    if (is_array($cc) && !empty($cc['_custom_features'])) {
                        $classes[] = $cc;
                    }
                }
            }
        }
        return $classes;
    }

    /**
     * Filter custom classes by whitelist or blacklist.
     *
     * @param array  $classes     List of custom class objects
     * @param string $mode        'whitelist' | 'blacklist' | '' (no filter)
     * @param array  $filter_ids  Numeric class IDs
     * @param array  $filter_codes String class codes
     * @return array Filtered list
     */
    private function filter_custom_classes(array $classes, string $mode, array $filter_ids, array $filter_codes): array {
        if ($mode === '' || (empty($filter_ids) && empty($filter_codes))) {
            return $classes;
        }
        return array_values(array_filter($classes, static function (array $cc) use ($mode, $filter_ids, $filter_codes): bool {
            $id = $cc['custom_class_id'] ?? null;
            $code = $cc['custom_class_code'] ?? null;
            $match = ($id !== null && in_array((int) $id, $filter_ids, true))
                  || ($code !== null && in_array(strtolower((string) $code), $filter_codes, true));
            return $mode === 'whitelist' ? $match : !$match;
        }));
    }

    /**
     * Parse the filter IDs setting into numeric IDs and string codes.
     *
     * @return array{ids: int[], codes: string[]}
     */
    public static function parse_custom_class_filter(string $raw): array {
        $ids = [];
        $codes = [];
        $parts = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (is_numeric($part)) {
                $ids[] = (int) $part;
            } else {
                $codes[] = strtolower($part);
            }
        }
        return ['ids' => $ids, 'codes' => $codes];
    }

    /**
     * Get custom class features as WC product attributes.
     * Returns attribute-type features (A, M, L, N, R, D, I) as label => value.
     * Skips T, B (long text) and not_applicable features.
     *
     * @param array $product             Raw API product
     * @param bool  $include_trade_items Include trade-item custom classes
     * @param string $filter_mode        'whitelist' | 'blacklist' | ''
     * @param array  $filter_ids         Numeric class IDs to filter
     * @param array  $filter_codes       String class codes to filter (lowercase)
     * @return array<string, string>     label => formatted value
     */
    public function get_custom_class_attributes(
        array $product,
        bool $include_trade_items = false,
        string $filter_mode = '',
        array $filter_ids = [],
        array $filter_codes = []
    ): array {
        $lang = get_option('skwirrel_wc_sync_settings', [])['image_language'] ?? 'nl';
        $classes = $this->collect_custom_classes($product, $include_trade_items);
        $classes = $this->filter_custom_classes($classes, $filter_mode, $filter_ids, $filter_codes);

        $attrs = [];
        $seen = [];
        foreach ($classes as $cc) {
            foreach ($cc['_custom_features'] ?? [] as $feat) {
                if (!is_array($feat)) {
                    continue;
                }
                $type = $feat['custom_feature_type'] ?? '';
                if (!in_array($type, self::CC_ATTRIBUTE_TYPES, true)) {
                    continue;
                }
                if (!empty($feat['not_applicable'])) {
                    continue;
                }
                $value = $this->format_custom_feature_value($feat, $lang);
                if ($value === null || $value === '') {
                    continue;
                }
                $label = $this->resolve_custom_feature_label($feat, $lang);
                $key = $feat['custom_feature_code'] ?? ('cc_' . ($feat['custom_class_feature_id'] ?? ($feat['custom_feature_id'] ?? '')));
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $attrs[$label] = $value;
            }
        }
        return $attrs;
    }

    /**
     * Get custom class long-text features as product meta.
     * Returns T and B type features as meta_key => value.
     *
     * @return array<string, string>  '_skwirrel_cc_{code}' => text value
     */
    public function get_custom_class_text_meta(
        array $product,
        bool $include_trade_items = false,
        string $filter_mode = '',
        array $filter_ids = [],
        array $filter_codes = []
    ): array {
        $lang = get_option('skwirrel_wc_sync_settings', [])['image_language'] ?? 'nl';
        $classes = $this->collect_custom_classes($product, $include_trade_items);
        $classes = $this->filter_custom_classes($classes, $filter_mode, $filter_ids, $filter_codes);

        $meta = [];
        foreach ($classes as $cc) {
            foreach ($cc['_custom_features'] ?? [] as $feat) {
                if (!is_array($feat)) {
                    continue;
                }
                $type = $feat['custom_feature_type'] ?? '';
                if (!in_array($type, self::CC_META_TYPES, true)) {
                    continue;
                }
                if (!empty($feat['not_applicable'])) {
                    continue;
                }
                $value = $type === 'B'
                    ? ($feat['big_text_value'] ?? '')
                    : ($feat['text_value'] ?? '');
                if ($value === '' || $value === null) {
                    continue;
                }
                $code = $feat['custom_feature_code']
                    ?? ('id_' . ($feat['custom_feature_id'] ?? $feat['custom_class_feature_id'] ?? '0'));
                $meta_key = '_skwirrel_cc_' . sanitize_key($code);
                $meta[$meta_key] = (string) $value;
            }
        }
        return $meta;
    }

    /**
     * Resolve a human-readable label for a custom class feature.
     */
    private function resolve_custom_feature_label(array $feat, string $lang): string {
        $trans = $feat['_custom_feature_translations'] ?? [];
        if (!empty($trans) && is_array($trans)) {
            $label = $this->pick_custom_translation($trans, $lang, 'custom_feature_description');
            if ($label !== '') {
                return $label;
            }
        }
        return (string) ($feat['custom_feature_code'] ?? $feat['custom_feature_id'] ?? '');
    }

    /**
     * Format a custom class feature value for display as product attribute.
     */
    private function format_custom_feature_value(array $feat, string $lang): ?string {
        if (!empty($feat['not_applicable'])) {
            return null;
        }
        $type = $feat['custom_feature_type'] ?? '';

        // A — Alphanumeric (single value from list)
        if ($type === 'A') {
            if (!empty($feat['_custom_values']) && is_array($feat['_custom_values'])) {
                foreach ($feat['_custom_values'] as $v) {
                    $desc = $this->pick_custom_value_translation($v, $lang);
                    if ($desc !== '') {
                        return $desc;
                    }
                }
            }
            return $feat['custom_value_code'] ?? null;
        }

        // M — Multi-alphanumeric (comma-separated values)
        if ($type === 'M') {
            $values = [];
            if (!empty($feat['_custom_values']) && is_array($feat['_custom_values'])) {
                foreach ($feat['_custom_values'] as $v) {
                    $desc = $this->pick_custom_value_translation($v, $lang);
                    if ($desc !== '') {
                        $values[] = $desc;
                    } else {
                        $code = $v['custom_value_code'] ?? '';
                        if ($code !== '') {
                            $values[] = $code;
                        }
                    }
                }
            }
            return !empty($values) ? implode(', ', $values) : null;
        }

        // L — Logical
        if ($type === 'L' && array_key_exists('logical_value', $feat) && $feat['logical_value'] !== null) {
            return $feat['logical_value'] ? 'Ja' : 'Nee';
        }

        // N — Numeric
        if ($type === 'N' && $feat['numeric_value'] !== null && $feat['numeric_value'] !== '') {
            $unit = $this->resolve_custom_unit($feat, $lang);
            return $feat['numeric_value'] . ($unit !== '' ? ' ' . $unit : '');
        }

        // R — Range
        if ($type === 'R' && ($feat['range_min'] !== null || $feat['range_max'] !== null)) {
            $min = $feat['range_min'] ?? '';
            $max = $feat['range_max'] ?? '';
            $unit = $this->resolve_custom_unit($feat, $lang);
            $s = $min . ($min !== '' && $max !== '' ? ' – ' : '') . $max;
            return $s . ($unit !== '' ? ' ' . $unit : '');
        }

        // D — Date
        if ($type === 'D' && !empty($feat['date_value'])) {
            return (string) $feat['date_value'];
        }

        // I — Internationalized text (pick by language)
        if ($type === 'I') {
            $texts = $feat['translated_texts'] ?? [];
            if (!empty($texts) && is_array($texts)) {
                // Try exact match, then prefix match, then first
                foreach ($texts as $t) {
                    if (strcasecmp((string) ($t['language'] ?? ''), $lang) === 0) {
                        return (string) ($t['text'] ?? $t['value'] ?? '');
                    }
                }
                foreach ($texts as $t) {
                    $tl = (string) ($t['language'] ?? '');
                    if (strlen($lang) >= 2 && strlen($tl) >= 2 && strcasecmp(substr($tl, 0, 2), substr($lang, 0, 2)) === 0) {
                        return (string) ($t['text'] ?? $t['value'] ?? '');
                    }
                }
                $first = $texts[0] ?? [];
                return (string) ($first['text'] ?? $first['value'] ?? '');
            }
            return null;
        }

        return null;
    }

    /**
     * Pick a translated label from custom feature/unit/value translations.
     */
    private function pick_custom_translation(array $translations, string $lang, string $field): string {
        // Exact match
        foreach ($translations as $t) {
            if (!is_array($t)) {
                continue;
            }
            $tl = (string) ($t['language'] ?? '');
            if (strcasecmp($tl, $lang) === 0) {
                return (string) ($t[$field] ?? '');
            }
        }
        // Prefix match (e.g. nl matches nl-NL)
        foreach ($translations as $t) {
            if (!is_array($t)) {
                continue;
            }
            $tl = (string) ($t['language'] ?? '');
            if (strlen($lang) >= 2 && strlen($tl) >= 2 && strcasecmp(substr($tl, 0, 2), substr($lang, 0, 2)) === 0) {
                return (string) ($t[$field] ?? '');
            }
        }
        // Fallback to first
        $first = $translations[0] ?? [];
        return (string) (is_array($first) ? ($first[$field] ?? '') : '');
    }

    /**
     * Pick translated value description from a custom value object.
     */
    private function pick_custom_value_translation(array $value, string $lang): string {
        $trans = $value['_custom_value_translations'] ?? [];
        if (!empty($trans) && is_array($trans)) {
            return $this->pick_custom_translation($trans, $lang, 'custom_value_description');
        }
        return (string) ($value['custom_value_code'] ?? '');
    }

    /**
     * Resolve unit abbreviation from custom feature translations.
     */
    private function resolve_custom_unit(array $feat, string $lang): string {
        $trans = $feat['_custom_unit_translations'] ?? [];
        if (empty($trans) || !is_array($trans)) {
            return (string) ($feat['custom_unit_code'] ?? '');
        }
        $abbr = $this->pick_custom_translation($trans, $lang, 'custom_unit_abbreviation');
        if ($abbr !== '') {
            return $abbr;
        }
        return $this->pick_custom_translation($trans, $lang, 'custom_unit_description');
    }
}
