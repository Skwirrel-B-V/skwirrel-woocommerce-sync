<?php
/**
 * Skwirrel Sync Service.
 *
 * Orchestrates product sync: fetches from API, maps, upserts to WooCommerce.
 * Supports full sync and delta sync (updated_on filter).
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Skwirrel_WC_Sync_Service {

    private const OPTION_LAST_SYNC = 'skwirrel_wc_sync_last_sync';
    private const OPTION_LAST_SYNC_RESULT = 'skwirrel_wc_sync_last_result';
    private const OPTION_SYNC_HISTORY = 'skwirrel_wc_sync_history';
    private const MAX_HISTORY_ENTRIES = 20;

    private Skwirrel_WC_Sync_Logger $logger;
    private Skwirrel_WC_Sync_Product_Mapper $mapper;

    /** @var string[] Skwirrel category IDs seen during current sync run */
    private array $seen_category_ids = [];

    public function __construct() {
        $this->logger = new Skwirrel_WC_Sync_Logger();
        $this->mapper = new Skwirrel_WC_Sync_Product_Mapper();
    }

    /**
     * Run sync. Returns summary array.
     *
     * @param bool $delta Use delta sync (updated_on >= last sync) if possible
     */
    public function run_sync(bool $delta = false): array {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged,Squiz.PHP.DiscouragedFunctions.Discouraged -- long-running sync requires no time limit
        }

        $sync_started_at = time();
        $this->seen_category_ids = [];

        $client = $this->get_client();
        if (!$client) {
            $this->logger->error('Sync aborted: invalid configuration');
            return ['success' => false, 'error' => 'Invalid configuration', 'created' => 0, 'updated' => 0, 'failed' => 0];
        }

        $options = $this->get_options();
        $created = 0;
        $updated = 0;
        $failed = 0;
        $delta_since = get_option(self::OPTION_LAST_SYNC, '');

        $collection_ids = $this->get_collection_ids();

        $get_params = [
            'page' => 1,
            'limit' => (int) ($options['batch_size'] ?? 100),
            'include_product_status' => true,
            'include_product_translations' => true,
            'include_attachments' => true,
            'include_trade_items' => true,
            'include_trade_item_prices' => true,
            'include_categories' => !empty($options['sync_categories']),
            // Product groups: needed for _product_groups (eTIM can be nested here)
            'include_product_groups' => !empty($options['sync_categories']) || !empty($options['sync_grouped_products']),
            // Grouped products: include when product is a variation (may affect eTIM structure)
            'include_grouped_products' => !empty($options['sync_grouped_products']),
            'include_etim' => true,
            // Note: include_etim_features exists only for getGroupedProducts, not getProducts
            'include_etim_translations' => true,
            'include_languages' => $this->get_include_languages(),
            'include_contexts' => [1],
        ];

        // Custom classes: product-level
        $sync_cc = !empty($options['sync_custom_classes']);
        $sync_ti_cc = !empty($options['sync_trade_item_custom_classes']);
        if ($sync_cc) {
            $get_params['include_custom_classes'] = true;
            // Whitelist: pass specific IDs to the API for efficient filtering
            $cc_filter_mode = $options['custom_class_filter_mode'] ?? '';
            $cc_raw = $options['custom_class_filter_ids'] ?? '';
            $cc_parsed = Skwirrel_WC_Sync_Product_Mapper::parse_custom_class_filter($cc_raw);
            if ($cc_filter_mode === 'whitelist' && !empty($cc_parsed['ids'])) {
                $get_params['include_custom_class_id'] = $cc_parsed['ids'];
            }
        }
        if ($sync_ti_cc) {
            $get_params['include_trade_item_custom_classes'] = true;
            $cc_filter_mode = $cc_filter_mode ?? ($options['custom_class_filter_mode'] ?? '');
            $cc_raw = $cc_raw ?? ($options['custom_class_filter_ids'] ?? '');
            $cc_parsed = $cc_parsed ?? Skwirrel_WC_Sync_Product_Mapper::parse_custom_class_filter($cc_raw);
            if ($cc_filter_mode === 'whitelist' && !empty($cc_parsed['ids'])) {
                $get_params['include_trade_item_custom_class_id'] = $cc_parsed['ids'];
            }
        }

        // Collection ID filter: only sync products from these collections (empty = all)
        // Note: exact API parameter name may need adjustment — logged for debugging
        if (!empty($collection_ids)) {
            $get_params['collection_ids'] = $collection_ids;
        }

        $this->logger->verbose('Sync started', [
            'delta' => $delta,
            'delta_since' => $delta_since,
            'batch_size' => $get_params['limit'],
            'include_etim' => true,
            'collection_ids' => $collection_ids ?: '(all)',
        ]);

        $product_to_group_map = [];
        if (!empty($options['sync_grouped_products'])) {
            $grouped_result = $this->sync_grouped_products_first($client, $options);
            $product_to_group_map = $grouped_result['map'];
            $created += $grouped_result['created'];
            $updated += $grouped_result['updated'];
        }

        if ($delta && !empty($delta_since)) {
            $req_options = $get_params;
            unset($req_options['page'], $req_options['limit']);
            $result = $client->call('getProductsByFilter', [
                'filter' => [
                    'updated_on' => [
                        'datetime' => $delta_since,
                        'operator' => '>=',
                    ],
                ],
                'options' => $req_options,
                'page' => 1,
                'limit' => $get_params['limit'],
            ]);
        } else {
            $result = $client->call('getProducts', $get_params);
        }

        if (!$result['success']) {
            $err = $result['error'] ?? ['message' => 'Unknown error'];
            $this->logger->error('Sync API error', $err);
            $this->update_last_result(false, $created, $updated, $failed, $err['message'] ?? '');
            return ['success' => false, 'error' => $err['message'] ?? 'API error', 'created' => 0, 'updated' => 0, 'failed' => 0];
        }

        $data = $result['result'] ?? [];
        $products = $data['products'] ?? [];

        $this->logger->verbose('API response received', [
            'products_count' => count($products),
            'page' => 1,
        ]);

        if ($delta && empty($products)) {
            $this->logger->info('Delta sync: no products updated since last sync');
            $this->update_last_result(true, 0, 0, 0);
            return ['success' => true, 'created' => 0, 'updated' => 0, 'failed' => 0];
        }

        $page = 1;
        $total_processed = 0;
        $with_attrs = 0;
        $without_attrs = 0;
        $logged_first_product = false;

        do {
            $this->logger->verbose('Processing batch', ['page' => $page, 'count' => count($products)]);

            // Log first product's raw structure once per sync for diagnostics
            if (!$logged_first_product && !empty($products[0])) {
                $first = $products[0];
                $this->logger->info('First product structure sample', [
                    'product_id' => $first['product_id'] ?? '?',
                    'has__categories' => isset($first['_categories']),
                    '_categories_count' => is_array($first['_categories'] ?? null) ? count($first['_categories']) : 0,
                    '_categories_sample' => is_array($first['_categories'] ?? null) ? array_slice($first['_categories'], 0, 2) : null,
                    'has__product_groups' => isset($first['_product_groups']),
                    '_product_groups_count' => is_array($first['_product_groups'] ?? null) ? count($first['_product_groups']) : 0,
                    '_product_groups_names' => is_array($first['_product_groups'] ?? null)
                        ? array_column($first['_product_groups'], 'product_group_name')
                        : null,
                    'top_level_keys' => array_keys($first),
                ]);
                $logged_first_product = true;
            }

            foreach ($products as $product) {
                try {
                    $product_id = $product['internal_product_code'] ?? $product['product_id'] ?? '?';
                    $skwirrel_product_id = $product['product_id'] ?? $product['id'] ?? null;

                    // Check if this is a virtual product for a variable product
                    $virtual_info = null;
                    if ($skwirrel_product_id !== null) {
                        $virtual_info = $product_to_group_map['virtual:' . (int) $skwirrel_product_id] ?? null;
                    }

                    // If this product is a virtual product for a variable product, assign its images and documents
                    if ($virtual_info && !empty($virtual_info['is_virtual_for_variable'])) {
                        $wc_variable_id = $virtual_info['wc_variable_id'];
                        $this->logger->info('Processing virtual product - assigning images and documents to variable product', [
                            'virtual_product_id' => $skwirrel_product_id,
                            'wc_variable_id' => $wc_variable_id,
                        ]);

                        // Get images from virtual product and assign to variable product
                        $img_ids = $this->mapper->get_image_attachment_ids($product, $wc_variable_id);
                        if (!empty($img_ids)) {
                            $wc_product = wc_get_product($wc_variable_id);
                            if ($wc_product) {
                                $wc_product->set_image_id($img_ids[0]);
                                $wc_product->set_gallery_image_ids(array_slice($img_ids, 1));
                                $wc_product->save();
                                $this->logger->info('Assigned images from virtual product to variable product', [
                                    'wc_variable_id' => $wc_variable_id,
                                    'image_count' => count($img_ids),
                                ]);
                            }
                        }

                        // Get documents from virtual product and assign to variable product
                        $documents = $this->mapper->get_document_attachments($product, $wc_variable_id);
                        if (!empty($documents)) {
                            update_post_meta($wc_variable_id, '_skwirrel_document_attachments', $documents);
                            $this->logger->info('Assigned documents from virtual product to variable product', [
                                'wc_variable_id' => $wc_variable_id,
                                'document_count' => count($documents),
                            ]);
                        }

                        continue; // Skip creating a product for this virtual product
                    }

                    // Skip other VIRTUAL type products that aren't virtual products for variable products
                    if (($product['product_type'] ?? '') === 'VIRTUAL') {
                        $this->logger->verbose('Skipping virtual product (not used for variable product)', [
                            'product_id' => $product['product_id'] ?? '?',
                            'internal_product_code' => $product['internal_product_code'] ?? '',
                        ]);
                        continue;
                    }
                    $sku_for_lookup = (string) ($product['internal_product_code'] ?? $product['manufacturer_product_code'] ?? $this->mapper->get_sku($product));
                    $group_info = null;
                    if ($skwirrel_product_id !== null && $skwirrel_product_id !== '') {
                        $group_info = $product_to_group_map[(int) $skwirrel_product_id] ?? null;
                    }
                    if (!$group_info && $sku_for_lookup !== '') {
                        $group_info = $product_to_group_map['sku:' . $sku_for_lookup] ?? null;
                    }
                    $this->logger->verbose('Product lookup', [
                        'product_id' => $skwirrel_product_id,
                        'sku' => $sku_for_lookup,
                        'in_group' => (bool) $group_info,
                    ]);
                    if (defined('SKWIRREL_WC_SYNC_DEBUG_ETIM') && SKWIRREL_WC_SYNC_DEBUG_ETIM && !$group_info && $skwirrel_product_id !== null) {
                        $upload = wp_upload_dir();
                        $dir = $upload['basedir'] ?? '';
                        if ($dir && wp_is_writable($dir)) {
                            $line = sprintf("[%s] Product NOT in group: product_id=%s, sku=%s (map has %d product_ids)\n",
                                gmdate('Y-m-d H:i:s'), $skwirrel_product_id, $sku_for_lookup, count(array_filter(array_keys($product_to_group_map), 'is_int')));
                            file_put_contents($dir . '/skwirrel-variation-debug.log', $line, FILE_APPEND | LOCK_EX);
                        }
                    }
                    $outcome = $group_info
                        ? $this->upsert_product_as_variation(
                            apply_filters('skwirrel_wc_sync_product_before_variation', $product, $group_info),
                            $group_info
                        )
                        : $this->upsert_product($product);
                    if ($outcome !== 'skipped') {
                        $attrs = $this->mapper->get_attributes($product);
                        $attr_count = count($attrs);
                        if ($attr_count > 0) {
                            $with_attrs++;
                            $this->logger->verbose('Product has attributes', [
                                'product' => $product_id,
                                'outcome' => $outcome,
                                'attr_count' => $attr_count,
                                'attrs' => array_keys($attrs),
                            ]);
                        } else {
                            $without_attrs++;
                            $this->logger->verbose('Product has no attributes', [
                                'product' => $product_id,
                                'outcome' => $outcome,
                                'has__etim' => isset($product['_etim']),
                                'brand' => $product['brand_name'] ?? null,
                                'manufacturer' => $product['manufacturer_name'] ?? null,
                            ]);
                        }
                    }
                    if ($outcome === 'created') {
                        $created++;
                    } elseif ($outcome === 'updated') {
                        $updated++;
                    } else {
                        $failed++;
                    }
                } catch (Throwable $e) {
                    $failed++;
                    $this->logger->error('Product sync failed', [
                        'product' => $product['internal_product_code'] ?? $product['product_id'] ?? '?',
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $total_processed += count($products);
            if (count($products) < $get_params['limit']) {
                break;
            }

            $page++;
            $get_params['page'] = $page;
            if ($delta && !empty($delta_since)) {
                $req_options = $get_params;
                unset($req_options['page'], $req_options['limit']);
                $result = $client->call('getProductsByFilter', [
                    'filter' => ['updated_on' => ['datetime' => $delta_since, 'operator' => '>=']],
                    'options' => $req_options,
                    'page' => $page,
                    'limit' => $get_params['limit'],
                ]);
            } else {
                $result = $client->call('getProducts', $get_params);
            }
            if (!$result['success']) {
                $this->logger->error('Pagination failed', $result['error'] ?? []);
                break;
            }
            $data = $result['result'] ?? [];
            $products = $data['products'] ?? [];

            $this->logger->verbose('API pagination', [
                'page' => $page,
                'products_in_page' => count($products),
            ]);

        } while (!empty($products));

        $this->logger->verbose('Sync finished, persisting last sync timestamp');

        // Purge stale products/categories (only during full sync without collection filter)
        $trashed = 0;
        $categories_removed = 0;
        if (!empty($options['purge_stale_products'])) {
            if ($delta) {
                $this->logger->verbose('Purge overgeslagen: delta sync (alleen bij volledige sync)');
            } elseif (!empty($collection_ids)) {
                $this->logger->warning('Purge overgeslagen: collectie-filter actief. Verwijder het collectie-filter of voer een volledige sync uit zonder filter om verwijderde producten op te ruimen.', [
                    'collection_ids' => $collection_ids,
                ]);
            } else {
                $trashed = $this->purge_stale_products($sync_started_at);
                if (!empty($options['sync_categories'])) {
                    $categories_removed = $this->purge_stale_categories();
                }
            }
        }

        update_option(self::OPTION_LAST_SYNC, gmdate('Y-m-d\TH:i:s\Z'));
        $this->update_last_result(true, $created, $updated, $failed, '', $with_attrs, $without_attrs, $trashed, $categories_removed);

        $this->logger->info('Sync completed', [
            'created' => $created,
            'updated' => $updated,
            'failed' => $failed,
            'trashed' => $trashed,
            'categories_removed' => $categories_removed,
            'with_attributes' => $with_attrs,
            'without_attributes' => $without_attrs,
        ]);

        return [
            'success' => true,
            'created' => $created,
            'updated' => $updated,
            'failed' => $failed,
            'trashed' => $trashed,
            'categories_removed' => $categories_removed,
        ];
    }

    /**
     * Upsert single product. Returns 'created'|'updated'|'skipped'.
     *
     * Lookup chain (eerste match wint):
     * 1. SKU → wc_get_product_id_by_sku() (snelste, WC index)
     * 2. _skwirrel_external_id meta → find_by_external_id() (betrouwbare API key)
     * 3. _skwirrel_product_id meta → find_by_skwirrel_product_id() (stabiele Skwirrel ID)
     */
    public function upsert_product(array $product): string {
        $key = $this->mapper->get_unique_key($product);
        if (!$key) {
            $this->logger->warning('Product has no unique key, skipping', ['product_id' => $product['product_id'] ?? '?']);
            return 'skipped';
        }

        $sku = $this->mapper->get_sku($product);
        $skwirrel_product_id = $product['product_id'] ?? null;

        // Stap 1: Zoek op SKU (snelste via WC index)
        $wc_id = wc_get_product_id_by_sku($sku);

        // Als SKU matcht met een variable product, sla over — dit simple product
        // mag niet het variable product overschrijven
        if ($wc_id) {
            $existing = wc_get_product($wc_id);
            if ($existing && $existing->is_type('variable')) {
                $this->logger->verbose('SKU matcht met variable product, zoek verder', [
                    'sku' => $sku,
                    'wc_variable_id' => $wc_id,
                ]);
                $wc_id = 0; // Niet matchen, maar SKU NIET veranderen — we zoeken verder
            }
        }

        // Stap 2: Zoek op _skwirrel_external_id meta
        if (!$wc_id) {
            $wc_id = $this->find_by_external_id($key);
        }

        // Stap 3: Zoek op _skwirrel_product_id meta (meest stabiele identifier)
        if (!$wc_id && $skwirrel_product_id !== null && $skwirrel_product_id !== '' && $skwirrel_product_id !== 0) {
            $wc_id = $this->find_by_skwirrel_product_id((int) $skwirrel_product_id);
            if ($wc_id) {
                $this->logger->info('Product gevonden via _skwirrel_product_id fallback', [
                    'skwirrel_product_id' => $skwirrel_product_id,
                    'wc_id' => $wc_id,
                ]);
            }
        }

        // Voorkom dubbele SKU bij nieuw product: als een ander product al deze SKU heeft,
        // genereer een unieke SKU met suffix
        $is_new = !$wc_id;
        if ($is_new) {
            $existing_sku_id = wc_get_product_id_by_sku($sku);
            if ($existing_sku_id) {
                $original_sku = $sku;
                $sku = $sku . '-' . ($skwirrel_product_id ?? uniqid());
                $this->logger->warning('Dubbele SKU voorkomen bij nieuw product', [
                    'original_sku' => $original_sku,
                    'new_sku' => $sku,
                    'existing_wc_id' => $existing_sku_id,
                    'skwirrel_product_id' => $skwirrel_product_id,
                ]);
            }
        } else {
            // Bestaand product: controleer of SKU is veranderd en geen conflict veroorzaakt
            $existing_sku_id = wc_get_product_id_by_sku($sku);
            if ($existing_sku_id && (int) $existing_sku_id !== (int) $wc_id) {
                $original_sku = $sku;
                $sku = $sku . '-' . ($skwirrel_product_id ?? uniqid());
                $this->logger->warning('SKU conflict bij update, unieke SKU gegenereerd', [
                    'original_sku' => $original_sku,
                    'new_sku' => $sku,
                    'wc_id' => $wc_id,
                    'conflicting_wc_id' => $existing_sku_id,
                ]);
            }
        }

        $this->logger->verbose('Upsert product', [
            'product' => $product['internal_product_code'] ?? $product['product_id'] ?? '?',
            'sku' => $sku,
            'key' => $key,
            'wc_id' => $wc_id,
            'is_new' => $is_new,
            'lookup_chain' => $is_new ? 'geen match (nieuw)' : 'gevonden',
        ]);

        if ($is_new) {
            $wc_product = new WC_Product_Simple();
        } else {
            $wc_product = wc_get_product($wc_id);
            if (!$wc_product) {
                $this->logger->warning('WC product not found', ['wc_id' => $wc_id]);
                return 'skipped';
            }
            // Bestaand product dat variable is mag niet overschreven worden als simple
            if ($wc_product->is_type('variable')) {
                $this->logger->warning('Bestaand product is variable, kan niet overschrijven als simple', [
                    'wc_id' => $wc_id,
                    'sku' => $sku,
                    'skwirrel_product_id' => $skwirrel_product_id,
                ]);
                return 'skipped';
            }
        }

        $wc_product->set_sku($sku);
        $wc_product->set_name($this->mapper->get_name($product));
        $wc_product->set_short_description($this->mapper->get_short_description($product));
        $wc_product->set_description($this->mapper->get_long_description($product));
        $wc_product->set_status($this->mapper->get_status($product));

        $price = $this->mapper->get_regular_price($product);
        if ($this->mapper->is_price_on_request($product)) {
            $wc_product->set_regular_price('');
            $wc_product->set_price('');
            $wc_product->set_sold_individually(false);
        } elseif ($price !== null) {
            $wc_product->set_regular_price((string) $price);
            $wc_product->set_price((string) $price);
        }

        $attrs = $this->mapper->get_attributes($product);

        // Merge custom class attributes (if enabled)
        $cc_options = $this->get_options();
        $cc_text_meta = [];
        if (!empty($cc_options['sync_custom_classes']) || !empty($cc_options['sync_trade_item_custom_classes'])) {
            $cc_filter_mode = $cc_options['custom_class_filter_mode'] ?? '';
            $cc_parsed = Skwirrel_WC_Sync_Product_Mapper::parse_custom_class_filter($cc_options['custom_class_filter_ids'] ?? '');
            $include_ti = !empty($cc_options['sync_trade_item_custom_classes']);

            $cc_attrs = $this->mapper->get_custom_class_attributes(
                $product,
                $include_ti,
                $cc_filter_mode,
                $cc_parsed['ids'],
                $cc_parsed['codes']
            );
            // Merge: custom class attrs after ETIM attrs (ETIM takes precedence on name conflict)
            foreach ($cc_attrs as $name => $value) {
                if (!isset($attrs[$name])) {
                    $attrs[$name] = $value;
                }
            }

            $cc_text_meta = $this->mapper->get_custom_class_text_meta(
                $product,
                $include_ti,
                $cc_filter_mode,
                $cc_parsed['ids'],
                $cc_parsed['codes']
            );
        }

        if (!empty($attrs)) {
            $wc_attrs = [];
            $position = 0;
            foreach ($attrs as $name => $value) {
                $attr = new WC_Product_Attribute();
                $attr->set_id(0);
                $attr->set_name($name);
                $attr->set_options([(string) $value]);
                $attr->set_position($position++);
                $attr->set_visible(true);
                $attr->set_variation(false);
                $wc_attrs[ sanitize_title($name) ] = $attr;
            }
            $wc_product->set_attributes($wc_attrs);
        }

        $wc_product->save();

        $id = $wc_product->get_id();
        update_post_meta($id, $this->mapper->get_external_id_meta_key(), $key);
        update_post_meta($id, $this->mapper->get_product_id_meta_key(), $product['product_id'] ?? 0);
        update_post_meta($id, $this->mapper->get_synced_at_meta_key(), time());

        $img_ids = $this->mapper->get_image_attachment_ids($product, $id);
        if (!empty($img_ids)) {
            $wc_product->set_image_id($img_ids[0]);           // First image = featured
            $wc_product->set_gallery_image_ids(array_slice($img_ids, 1)); // All others = gallery
            $wc_product->save();
        }

        $downloads = $this->mapper->get_downloadable_files($product, $id);
        if (!empty($downloads)) {
            $wc_product->set_downloadable(true);
            $wc_product->set_downloads($this->format_downloads($downloads));
            $wc_product->save();
        }

        $documents = $this->mapper->get_document_attachments($product, $id);
        update_post_meta($id, '_skwirrel_document_attachments', $documents);

        // Save custom class text meta (T/B types)
        if (!empty($cc_text_meta)) {
            foreach ($cc_text_meta as $meta_key => $meta_value) {
                update_post_meta($id, $meta_key, $meta_value);
            }
            $this->logger->verbose('Custom class text meta saved', [
                'wc_id' => $id,
                'meta_keys' => array_keys($cc_text_meta),
            ]);
        }

        $this->assign_categories($id, $product);

        if (!empty($attrs)) {
            $product_attrs = [];
            $position = 0;
            foreach ($attrs as $name => $value) {
                $slug = sanitize_title($name);
                $product_attrs[ $slug ] = [
                    'name'         => $name,
                    'value'        => (string) $value,
                    'position'     => (string) $position++,
                    'is_visible'   => 1,
                    'is_variation' => 0,
                    'is_taxonomy'  => 0,
                ];
            }
            update_post_meta($id, '_product_attributes', $product_attrs);
            clean_post_cache($id);
            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($id);
            }
            $this->logger->verbose('Attributes saved (product+meta)', [
                'wc_id' => $id,
                'attr_count' => count($attrs),
                'names' => array_keys($attrs),
            ]);
        }

        return $is_new ? 'created' : 'updated';
    }

    private const GROUPED_PRODUCT_ID_META = '_skwirrel_grouped_product_id';

    /**
     * Stap 1: Haal grouped products op, maak variable producten aan (zonder variations).
     * Retourneert map: product_id => [grouped_product_id, order, sku, wc_variable_id].
     */
    private function sync_grouped_products_first(Skwirrel_WC_Sync_JsonRpc_Client $client, array $options): array {
        $created = 0;
        $updated = 0;
        $product_to_group_map = [];
        $batch_size = (int) ($options['batch_size'] ?? 100);
        $params = [
            'page' => 1,
            'limit' => $batch_size,
            'include_products' => true,
            'include_etim_features' => true,
        ];

        // Pass collection_ids filter to grouped products API call
        $collection_ids = $this->get_collection_ids();
        if (!empty($collection_ids)) {
            $params['collection_ids'] = $collection_ids;
        }

        $page = 1;
        do {
            $params['page'] = $page;
            $params['limit'] = $batch_size;
            $result = $client->call('getGroupedProducts', $params);

            if (!$result['success']) {
                $this->logger->warning('getGroupedProducts failed', $result['error'] ?? []);
                break;
            }

            $data = $result['result'] ?? [];
            $groups = $data['grouped_products'] ?? $data['groups'] ?? $data['products'] ?? [];
            if (!is_array($groups)) {
                $groups = [];
            }

            $page_info = $data['page'] ?? [];
            $current_page = (int) ($page_info['current_page'] ?? $page);
            $total_pages = (int) ($page_info['number_of_pages'] ?? 1);

            foreach ($groups as $group) {
                try {
                    $outcome = $this->create_variable_product_from_group($group, $product_to_group_map);
                    if ($outcome === 'created') {
                        $created++;
                    } elseif ($outcome === 'updated') {
                        $updated++;
                    }
                } catch (Throwable $e) {
                    $this->logger->error('Grouped product sync failed', [
                        'grouped_product_id' => $group['grouped_product_id'] ?? $group['id'] ?? '?',
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (empty($groups) || $current_page >= $total_pages) {
                break;
            }
            $page++;
        } while (true);

        $product_ids_in_groups = array_filter(array_keys($product_to_group_map), 'is_int');
        $this->logger->info('Grouped products loaded', [
            'variable_products' => $created + $updated,
            'product_ids_in_groups' => count($product_ids_in_groups),
        ]);
        return ['created' => $created, 'updated' => $updated, 'map' => $product_to_group_map];
    }

    /**
     * Maak variable product aan (zonder variations). Vul product_to_group_map voor later.
     */
    private function create_variable_product_from_group(array $group, array &$product_to_group_map): string {
        $grouped_id = $group['grouped_product_id'] ?? $group['id'] ?? null;
        if ($grouped_id === null || $grouped_id === '') {
            return 'skipped';
        }

        $products = $group['_products'] ?? $group['products'] ?? [];
        $variant_skus = [];

        foreach ($products as $item) {
            $product_id = null;
            $sku = null;
            $order = 999;
            if (is_array($item)) {
                $product_id = isset($item['product_id']) ? (int) $item['product_id'] : null;
                $sku = (string) ($item['internal_product_code'] ?? '');
                $order = isset($item['order']) ? (int) $item['order'] : 999;
            } else {
                $product_id = (int) $item;
                $sku = '';
            }
            if ($product_id && $sku !== '') {
                $variant_skus[] = $sku;
            }
        }

        $wc_id = $this->find_by_grouped_product_id((int) $grouped_id);
        $is_new = !$wc_id;

        if ($is_new) {
            $wc_product = new WC_Product_Variable();
        } else {
            $wc_product = wc_get_product($wc_id);
            if (!$wc_product || !$wc_product->is_type('variable')) {
                $wc_product = new WC_Product_Variable();
                if ($wc_id) {
                    wp_delete_post($wc_id, true);
                }
                $is_new = true;
            }
        }

        $name = (string) ($group['grouped_product_name'] ?? $group['grouped_product_code'] ?? $group['name'] ?? '');
        if ($name === '') {
            /* translators: %s = grouped product ID */
            $name = sprintf(__('Product %s', 'skwirrel-pim-wp-sync'), $grouped_id);
        }

        $group_sku = (string) ($group['grouped_product_code'] ?? $group['internal_product_code'] ?? '');
        if ($group_sku !== '') {
            $wc_product->set_sku($group_sku);
        }
        $wc_product->set_name($name);
        $wc_product->set_status(!empty($group['product_trashed_on']) ? 'trash' : 'publish');
        $wc_product->set_catalog_visibility('visible');
        $wc_product->set_stock_status('instock'); // Parent must be in stock
        $wc_product->set_manage_stock(false); // Don't manage stock at parent level

        $etim_features = $group['_etim_features'] ?? [];
        $etim_variation_codes = [];
        if (is_array($etim_features)) {
            $raw = isset($etim_features[0]) ? $etim_features : array_values($etim_features);
            foreach ($raw as $f) {
                if (is_array($f) && !empty($f['etim_feature_code'])) {
                    $etim_variation_codes[] = [
                        'code' => $f['etim_feature_code'],
                        'order' => (int) ($f['order'] ?? 999),
                        'label' => $this->mapper->resolve_etim_feature_label($f),
                    ];
                }
            }
            usort($etim_variation_codes, fn($a, $b) => $a['order'] <=> $b['order']);
        }

        $attrs = [];
        if (!empty($etim_variation_codes)) {
            foreach ($etim_variation_codes as $pos => $ef) {
                $code = $ef['code'];
                $etim_slug = $this->get_etim_attribute_slug($code);
                $label = !empty($ef['label']) ? $ef['label'] : $code;
                $tax = $this->ensure_product_attribute_exists($etim_slug, $label);
                $attr = new WC_Product_Attribute();
                $attr->set_id(wc_attribute_taxonomy_id_by_name($etim_slug));
                $attr->set_name($tax);
                $attr->set_options([]);
                $attr->set_position($pos);
                $attr->set_visible(true);
                $attr->set_variation(true);
                $attrs[$tax] = $attr;
            }
        }
        if (empty($attrs)) {
            $this->ensure_variant_taxonomy_exists();
            $attr = new WC_Product_Attribute();
            $attr->set_id(wc_attribute_taxonomy_id_by_name('variant'));
            $attr->set_name('pa_variant');
            $attr->set_options(array_values(array_unique($variant_skus)));
            $attr->set_position(0);
            $attr->set_visible(true);
            $attr->set_variation(true);
            $attrs['pa_variant'] = $attr;
        }
        $wc_product->set_attributes($attrs);
        $wc_product->save();

        $id = $wc_product->get_id();
        update_post_meta($id, self::GROUPED_PRODUCT_ID_META, (int) $grouped_id);
        update_post_meta($id, $this->mapper->get_synced_at_meta_key(), time());

        // Store virtual_product_id if present (this product has images for the variable product)
        $virtual_product_id = $group['virtual_product_id'] ?? null;
        if ($virtual_product_id) {
            update_post_meta($id, '_skwirrel_virtual_product_id', (int) $virtual_product_id);
        }

        foreach ($products as $item) {
            $product_id = null;
            $sku = null;
            $order = 999;
            if (is_array($item)) {
                $product_id = isset($item['product_id']) ? (int) $item['product_id'] : null;
                $sku = (string) ($item['internal_product_code'] ?? '');
                $order = isset($item['order']) ? (int) $item['order'] : 999;
            }
            if ($product_id && $sku !== '') {
                $info = [
                    'grouped_product_id' => (int) $grouped_id,
                    'order' => $order,
                    'sku' => $sku,
                    'wc_variable_id' => $id,
                    'etim_variation_codes' => $etim_variation_codes,
                    'virtual_product_id' => $virtual_product_id, // Include virtual product ID in map
                ];
                $product_to_group_map[(int) $product_id] = $info;
                $product_to_group_map['sku:' . $sku] = $info;
            }
        }

        // If this group has a virtual product, track it for image assignment
        if ($virtual_product_id) {
            $product_to_group_map['virtual:' . (int) $virtual_product_id] = [
                'wc_variable_id' => $id,
                'is_virtual_for_variable' => true,
            ];
        }

        $this->assign_categories($id, $group);

        return $is_new ? 'created' : 'updated';
    }

    /**
     * Voeg product uit getProducts toe als variation aan variable product.
     */
    private function upsert_product_as_variation(array $product, array $group_info): string {
        $wc_variable_id = $group_info['wc_variable_id'] ?? 0;
        $sku = $group_info['sku'] ?? $this->mapper->get_sku($product);
        if (!$wc_variable_id) {
            return $this->upsert_product($product);
        }

        $variation_id = $this->find_variation_by_sku($wc_variable_id, $sku);
        if (!$variation_id) {
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($wc_variable_id);
        } else {
            $variation = wc_get_product($variation_id);
            if (!$variation instanceof WC_Product_Variation) {
                return 'skipped';
            }
        }

        $variation->set_sku($sku);
        $variation->set_status('publish'); // Ensure variation is enabled
        $variation->set_catalog_visibility('visible'); // Make visible in catalog

        $price = $this->mapper->get_regular_price($product);
        if ($this->mapper->is_price_on_request($product)) {
            $variation->set_regular_price('');
            $variation->set_price('');
            $variation->set_stock_status('outofstock'); // Price on request = out of stock
        } elseif ($price !== null && $price > 0) {
            $variation->set_regular_price((string) $price);
            $variation->set_price((string) $price);
            $variation->set_stock_status('instock');
            $variation->set_manage_stock(false); // Don't manage stock, always available
        } else {
            // No price available - set to 0 and log warning
            $this->logger->warning('Variation has no price, setting to 0', [
                'sku' => $sku,
                'product_id' => $product['product_id'] ?? '?',
                'has_trade_items' => !empty($product['_trade_items'] ?? []),
            ]);
            $variation->set_regular_price('0');
            $variation->set_price('0');
            $variation->set_stock_status('instock');
            $variation->set_manage_stock(false);
        }

        $variation_attrs = [];
        $etim_codes = $group_info['etim_variation_codes'] ?? [];
        $etim_values = [];
        if (!empty($etim_codes)) {
            $lang = $this->get_include_languages();
            $lang = !empty($lang) ? $lang[0] : (get_option('skwirrel_wc_sync_settings', [])['image_language'] ?? 'nl');
            $etim_values = $this->mapper->get_etim_feature_values_for_codes($product, $etim_codes, $lang);
            $this->logger->verbose('Variation eTIM lookup', [
                'sku' => $sku,
                'etim_codes' => array_column($etim_codes, 'code'),
                'etim_values_found' => array_keys($etim_values),
                'has_product_etim' => isset($product['_etim']),
                'has_product_groups' => !empty($product['_product_groups'] ?? []),
            ]);
            foreach ($etim_codes as $ef) {
                $code = strtoupper((string) ($ef['code'] ?? ''));
                $data = $etim_values[$code] ?? null;
                if (!$data) {
                    continue;
                }
                $slug = $this->get_etim_attribute_slug($code);
                $tax = wc_attribute_taxonomy_name($slug);
                $label = !empty($data['label']) ? $data['label'] : $code;
                if (!taxonomy_exists($tax)) {
                    $this->ensure_product_attribute_exists($slug, $label);
                } else {
                    $this->maybe_update_attribute_label($slug, $label);
                }
                $term = get_term_by('slug', $data['slug'], $tax) ?: get_term_by('name', $data['value'], $tax);
                if (!$term || is_wp_error($term)) {
                    $insert = wp_insert_term($data['value'], $tax, ['slug' => $data['slug']]);
                    $term = !is_wp_error($insert) ? get_term($insert['term_id'], $tax) : null;
                }
                if ($term && !is_wp_error($term)) {
                    $variation_attrs[$tax] = $term->slug;
                    // Add term to parent IMMEDIATELY so parent knows about it before variation uses it
                    $this->add_term_to_parent_attribute($wc_variable_id, $tax, $term->term_id);
                }
            }
        }
        if (empty($variation_attrs)) {
            // Fallback to pa_variant only when parent uses pa_variant (no eTIM attributes)
            $parent_uses_etim = !empty($etim_codes);
            if ($parent_uses_etim) {
                $this->logger->warning('Variation has no eTIM values; parent expects eTIM attributes', [
                    'sku' => $sku,
                    'wc_variable_id' => $wc_variable_id,
                    'etim_codes' => array_column($etim_codes ?? [], 'code'),
                ]);
                if (defined('SKWIRREL_WC_SYNC_DEBUG_ETIM') && SKWIRREL_WC_SYNC_DEBUG_ETIM) {
                    $dump = wp_upload_dir();
                    $file = ($dump['basedir'] ?? '') . '/skwirrel-etim-debug-' . $sku . '.json';
                    if ($file && wp_is_writable(dirname($file))) {
                        file_put_contents($file, wp_json_encode([
                            'sku' => $sku,
                            'product_keys' => array_keys($product),
                            '_etim' => $product['_etim'] ?? null,
                            '_etim_features' => $product['_etim_features'] ?? null,
                            '_product_groups' => array_map(function ($g) {
                                return array_intersect_key($g, array_flip(['product_group_name', '_etim', '_etim_features']));
                            }, $product['_product_groups'] ?? []),
                        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    }
                }
            }
            $term = get_term_by('name', $sku, 'pa_variant') ?: get_term_by('slug', sanitize_title($sku), 'pa_variant');
            if (!$term) {
                $insert = wp_insert_term($sku, 'pa_variant');
                $term = !is_wp_error($insert) ? get_term($insert['term_id'], 'pa_variant') : null;
            }
            if ($term && !is_wp_error($term)) {
                $variation_attrs['pa_variant'] = $term->slug;
            }
        }
        if (defined('SKWIRREL_WC_SYNC_DEBUG_ETIM') && SKWIRREL_WC_SYNC_DEBUG_ETIM) {
            $this->write_variation_debug($sku, $etim_codes ?? [], $etim_values ?? [], $product, $variation_attrs);
        }

        // Set variation attributes BEFORE saving
        if (!empty($variation_attrs)) {
            $variation->set_attributes($variation_attrs);
        }

        $img_ids = $this->mapper->get_image_attachment_ids($product, $wc_variable_id);
        if (!empty($img_ids)) {
            $variation->set_image_id($img_ids[0]);
        }

        $variation->update_meta_data($this->mapper->get_product_id_meta_key(), $product['product_id'] ?? 0);
        $variation->update_meta_data($this->mapper->get_external_id_meta_key(), $this->mapper->get_unique_key($product) ?? '');
        $variation->update_meta_data($this->mapper->get_synced_at_meta_key(), time());

        // Save variation first to get ID
        $variation->save();
        $vid = $variation->get_id();

        // Explicitly persist variation attributes in post meta
        // WooCommerce variations use ONLY post meta (not term relationships)
        if ($vid && !empty($variation_attrs)) {
            foreach ($variation_attrs as $tax => $term_slug) {
                // Update post meta with the term slug
                update_post_meta($vid, 'attribute_' . $tax, wp_slash($term_slug));
            }

            // Log what we're saving
            $this->logger->verbose('Variation attributes saved to meta', [
                'sku' => $sku,
                'vid' => $vid,
                'attributes' => $variation_attrs,
            ]);

            // Verify immediately
            $verified = [];
            foreach ($variation_attrs as $tax => $expected) {
                $verified[$tax] = get_post_meta($vid, 'attribute_' . $tax, true);
            }

            if ($verified !== $variation_attrs) {
                $this->logger->error('Variation attribute meta verification failed', [
                    'sku' => $sku,
                    'vid' => $vid,
                    'expected' => $variation_attrs,
                    'verified' => $verified,
                ]);
            } else {
                $this->logger->verbose('Variation attribute meta verification SUCCESS', [
                    'sku' => $sku,
                    'vid' => $vid,
                    'verified' => $verified,
                ]);
            }

            clean_post_cache($vid);
            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($vid);
            }
        }

        // Sync parent product to update available variations
        $wc_product = wc_get_product($wc_variable_id);
        if ($wc_product && $wc_product->is_type('variable')) {
            try {
                WC_Product_Variable::sync($wc_variable_id);
                WC_Product_Variable::sync_stock_status($wc_variable_id);
                clean_post_cache($wc_variable_id);
                if (function_exists('wc_delete_product_transients')) {
                    wc_delete_product_transients($wc_variable_id);
                }
            } catch (Throwable $e) {
                $this->logger->warning('Parent sync failed, continuing', [
                    'wc_variable_id' => $wc_variable_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        do_action('skwirrel_wc_sync_after_variation_save', $variation->get_id(), $variation_attrs, $product);

        return $variation_id ? 'updated' : 'created';
    }

    /**
     * Assign product categories to a WooCommerce product.
     * Matches by Skwirrel category ID first (term meta), then by name.
     * Supports parent/child hierarchy from _categories data.
     */
    private function assign_categories(int $wc_product_id, array $product): void {
        $categories = $this->mapper->get_categories($product);
        if (empty($categories)) {
            return;
        }

        $tax = 'product_cat';
        $term_ids = [];
        $cat_id_meta = Skwirrel_WC_Sync_Product_Mapper::CATEGORY_ID_META;

        // Build lookup: skwirrel_id → category entry (for parent resolution)
        $by_skwirrel_id = [];
        foreach ($categories as $cat) {
            if ($cat['id'] !== null) {
                $by_skwirrel_id[$cat['id']] = $cat;
            }
        }

        // Resolve the full tree in topological order (roots first).
        // skwirrel_id → WC term ID mapping built up as we go.
        $resolved = []; // skwirrel_id => wc_term_id

        // Recursive resolver — resolves parent chain before the category itself.
        $resolve = function (array $cat) use (
            &$resolve, &$resolved, &$term_ids,
            $by_skwirrel_id, $tax, $cat_id_meta
        ): int {
            $cat_id = $cat['id'] ?? null;

            // Already resolved?
            if ($cat_id !== null && isset($resolved[$cat_id])) {
                return $resolved[$cat_id];
            }

            $parent_id = $cat['parent_id'] ?? null;
            $wc_parent_term_id = 0;

            // Resolve parent first (if it exists in our tree)
            if ($parent_id !== null && isset($by_skwirrel_id[$parent_id])) {
                $wc_parent_term_id = $resolve($by_skwirrel_id[$parent_id]);
            } elseif ($parent_id !== null || ($cat['parent_name'] ?? '') !== '') {
                // Parent not in our tree — look up / create by ID+name
                $wc_parent_term_id = $this->find_or_create_category_term(
                    $parent_id,
                    $cat['parent_name'] ?? '',
                    $tax,
                    $cat_id_meta,
                    0
                );
                if ($wc_parent_term_id && $parent_id !== null) {
                    $resolved[$parent_id] = $wc_parent_term_id;
                }
            }

            // Resolve the category itself
            $wc_term_id = $this->find_or_create_category_term(
                $cat_id,
                $cat['name'],
                $tax,
                $cat_id_meta,
                $wc_parent_term_id
            );

            if ($wc_term_id) {
                $term_ids[] = $wc_term_id;
                if ($cat_id !== null) {
                    $resolved[$cat_id] = $wc_term_id;
                }
                // Include all ancestors in the product's terms
                if ($wc_parent_term_id) {
                    $term_ids[] = $wc_parent_term_id;
                }
            }

            return $wc_term_id;
        };

        foreach ($categories as $cat) {
            $resolve($cat);
        }

        $term_ids = array_unique(array_map('intval', $term_ids));
        if (!empty($term_ids)) {
            wp_set_object_terms($wc_product_id, $term_ids, $tax);
            $this->logger->verbose('Categories assigned', [
                'wc_product_id' => $wc_product_id,
                'term_ids' => $term_ids,
                'names' => array_column($categories, 'name'),
            ]);
        }
    }

    /**
     * Find existing term by Skwirrel category ID (term meta) or name, or create new.
     * Returns WC term_id or 0 on failure.
     */
    private function find_or_create_category_term(
        ?int $skwirrel_id,
        string $name,
        string $taxonomy,
        string $meta_key,
        int $parent_term_id
    ): int {
        if ($name === '' && $skwirrel_id === null) {
            return 0;
        }

        // Track seen category IDs for purge logic
        if ($skwirrel_id !== null) {
            $this->seen_category_ids[] = (string) $skwirrel_id;
        }

        // 1. Match by Skwirrel category ID in term meta (reliable)
        if ($skwirrel_id !== null) {
            global $wpdb;
            $existing_term_id = $wpdb->get_var($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- term meta lookup by value not supported by WP API
                "SELECT tm.term_id FROM {$wpdb->termmeta} tm
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tm.term_id = tt.term_id AND tt.taxonomy = %s
                 WHERE tm.meta_key = %s AND tm.meta_value = %s
                 LIMIT 1",
                $taxonomy,
                $meta_key,
                (string) $skwirrel_id
            ));
            if ($existing_term_id) {
                return (int) $existing_term_id;
            }
        }

        // 2. Fall back to name matching
        if ($name !== '') {
            $term = term_exists($name, $taxonomy, $parent_term_id ?: 0);
            if ($term && !is_wp_error($term)) {
                $term_id = is_array($term) ? (int) $term['term_id'] : (int) $term;
                // Store Skwirrel ID for next sync
                if ($skwirrel_id !== null) {
                    update_term_meta($term_id, $meta_key, (string) $skwirrel_id);
                }
                return $term_id;
            }
        }

        // 3. Create new term
        if ($name === '') {
            return 0;
        }
        $args = [];
        if ($parent_term_id) {
            $args['parent'] = $parent_term_id;
        }
        $inserted = wp_insert_term($name, $taxonomy, $args);
        if (is_wp_error($inserted)) {
            // Handle "term already exists" race condition
            if ($inserted->get_error_code() === 'term_exists') {
                $term_id = (int) $inserted->get_error_data('term_exists');
                if ($skwirrel_id !== null && $term_id) {
                    update_term_meta($term_id, $meta_key, (string) $skwirrel_id);
                }
                return $term_id;
            }
            $this->logger->warning('Failed to create category term', [
                'name' => $name,
                'error' => $inserted->get_error_message(),
            ]);
            return 0;
        }

        $term_id = (int) $inserted['term_id'];
        if ($skwirrel_id !== null) {
            update_term_meta($term_id, $meta_key, (string) $skwirrel_id);
        }
        $this->logger->verbose('Category term created', [
            'term_id' => $term_id,
            'name' => $name,
            'skwirrel_id' => $skwirrel_id,
            'parent' => $parent_term_id,
        ]);
        return $term_id;
    }

    private function ensure_variant_taxonomy_exists(): void {
        if (taxonomy_exists('pa_variant')) {
            return;
        }
        if (!wc_attribute_taxonomy_id_by_name('variant')) {
            global $wpdb;
            $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- WC attribute table has no API
                $wpdb->prefix . 'woocommerce_attribute_taxonomies',
                [
                    'attribute_name' => 'variant',
                    'attribute_label' => __('Variant', 'skwirrel-pim-wp-sync'),
                    'attribute_type' => 'select',
                    'attribute_orderby' => 'menu_order',
                    'attribute_public' => 1,
                ]
            );
            delete_transient('wc_attribute_taxonomies');
        }
        register_taxonomy('pa_variant', 'product', [
            'labels' => ['name' => __('Variant', 'skwirrel-pim-wp-sync')],
            'hierarchical' => false,
            'show_ui' => true,
            'query_var' => true,
            'rewrite' => ['slug' => 'pa_variant'],
        ]);
    }

    private function get_etim_attribute_slug(string $code): string {
        $slug = 'etim_' . strtolower($code);
        return strlen($slug) > 28 ? substr($slug, 0, 28) : $slug;
    }

    /**
     * Update an existing WC attribute taxonomy label if the current label
     * is a raw code (e.g. "EF000721") and we now have a proper label from the API.
     */
    private function maybe_update_attribute_label(string $slug, string $label): void {
        if ($label === '' || preg_match('/^(EF|etim_)/i', $label)) {
            return;
        }
        $attr_id = wc_attribute_taxonomy_id_by_name($slug);
        if (!$attr_id) {
            return;
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- attribute label update
        $current_label = $wpdb->get_var($wpdb->prepare(
            "SELECT attribute_label FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_id = %d",
            $attr_id
        ));
        if ($current_label !== null && $current_label !== $label && preg_match('/^(EF|etim_)/i', $current_label)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- attribute label update
            $wpdb->update(
                $wpdb->prefix . 'woocommerce_attribute_taxonomies',
                ['attribute_label' => $label],
                ['attribute_id' => $attr_id]
            );
            delete_transient('wc_attribute_taxonomies');
        }
    }

    private function ensure_product_attribute_exists(string $slug, string $label): string {
        $tax = wc_attribute_taxonomy_name($slug);
        if (taxonomy_exists($tax)) {
            return $tax;
        }
        if (!wc_attribute_taxonomy_id_by_name($slug)) {
            if (function_exists('wc_create_attribute')) {
                $result = wc_create_attribute([
                    'name' => $label ?: $slug,
                    'slug' => $slug,
                    'type' => 'select',
                    'order_by' => 'menu_order',
                    'has_archives' => false,
                ]);
                if (is_wp_error($result)) {
                    return $tax;
                }
            } else {
                global $wpdb;
                $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- WC attribute table fallback for old WC versions
                    $wpdb->prefix . 'woocommerce_attribute_taxonomies',
                    [
                        'attribute_name' => $slug,
                        'attribute_label' => $label ?: $slug,
                        'attribute_type' => 'select',
                        'attribute_orderby' => 'menu_order',
                        'attribute_public' => 1,
                    ]
                );
                delete_transient('wc_attribute_taxonomies');
                if (function_exists('WC_Cache_Helper') && method_exists('WC_Cache_Helper', 'invalidate_cache_group')) {
                    WC_Cache_Helper::invalidate_cache_group('woocommerce-attributes');
                }
            }
        }
        $this->register_etim_taxonomy($tax, $slug, $label ?: $slug);
        return $tax;
    }

    private function register_etim_taxonomy(string $taxonomy, string $slug, string $label): void {
        if (taxonomy_exists($taxonomy)) {
            return;
        }
        $permalinks = function_exists('wc_get_permalink_structure') ? wc_get_permalink_structure() : [];
        $attr_rewrite = $permalinks['attribute_rewrite_slug'] ?? 'attribute';
        $taxonomy_data = [
            'hierarchical' => false,
            'update_count_callback' => '_update_post_term_count',
            'labels' => [
                'name' => $label,
                'singular_name' => $label,
                /* translators: %s = attribute label */
                'search_items' => sprintf(__('Search %s', 'skwirrel-pim-wp-sync'), $label),
                /* translators: %s = attribute label */
                'all_items' => sprintf(__('All %s', 'skwirrel-pim-wp-sync'), $label),
                /* translators: %s = attribute label */
                'edit_item' => sprintf(__('Edit %s', 'skwirrel-pim-wp-sync'), $label),
                /* translators: %s = attribute label */
                'update_item' => sprintf(__('Update %s', 'skwirrel-pim-wp-sync'), $label),
                /* translators: %s = attribute label */
                'add_new_item' => sprintf(__('Add new %s', 'skwirrel-pim-wp-sync'), $label),
                /* translators: %s = attribute label */
                'new_item_name' => sprintf(__('New %s', 'skwirrel-pim-wp-sync'), $label),
            ],
            'show_ui' => true,
            'show_in_quick_edit' => false,
            'show_in_menu' => false,
            'meta_box_cb' => false,
            'query_var' => true,
            'rewrite' => $attr_rewrite && $slug
                ? ['slug' => trailingslashit($attr_rewrite) . urldecode(sanitize_title($slug)), 'with_front' => false, 'hierarchical' => true]
                : false,
            'sort' => false,
            'public' => true,
            'capabilities' => [
                'manage_terms' => 'manage_product_terms',
                'edit_terms' => 'edit_product_terms',
                'delete_terms' => 'delete_product_terms',
                'assign_terms' => 'assign_product_terms',
            ],
        ];
        register_taxonomy($taxonomy, ['product'], $taxonomy_data);
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WooCommerce global
        global $wc_product_attributes;
        if (!is_array($wc_product_attributes)) {
            $wc_product_attributes = []; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        }
        $wc_product_attributes[$taxonomy] = (object) [ // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
            'attribute_id' => wc_attribute_taxonomy_id_by_name($slug),
            'attribute_name' => $slug,
            'attribute_label' => $label,
            'attribute_type' => 'select',
            'attribute_orderby' => 'menu_order',
            'attribute_public' => 1,
        ];
    }

    private function write_variation_debug(string $sku, array $etim_codes, array $etim_values, array $product, array $variation_attrs): void {
        $upload = wp_upload_dir();
        $dir = $upload['basedir'] ?? '';
        if (!$dir || !wp_is_writable($dir)) {
            return;
        }
        $file = $dir . '/skwirrel-variation-debug.log';
        $line = sprintf(
            "[%s] SKU=%s | etim_codes=%s | etim_values_found=%s | has__etim=%s | variation_attrs=%s\n",
            gmdate('Y-m-d H:i:s'),
            $sku,
            wp_json_encode(array_column($etim_codes, 'code')),
            wp_json_encode(array_keys($etim_values)),
            isset($product['_etim']) ? 'yes' : 'no',
            wp_json_encode($variation_attrs)
        );
        file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    private function find_variation_by_sku(int $parent_id, string $sku): int {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- variation lookup by parent+SKU not supported by WP API
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
            WHERE p.post_parent = %d AND pm.meta_value = %s AND p.post_type = 'product_variation'",
            $parent_id,
            $sku
        ));
        return $id ? (int) $id : 0;
    }

    private function add_term_to_parent_attribute(int $parent_id, string $taxonomy, int $term_id): void {
        $wc_product = wc_get_product($parent_id);
        if (!$wc_product || !$wc_product->is_type('variable')) {
            return;
        }
        $attrs = $wc_product->get_attributes();
        if (empty($attrs) || !isset($attrs[$taxonomy])) {
            return;
        }
        $attr = $attrs[$taxonomy];
        if (!$attr->is_taxonomy()) {
            return;
        }
        $options = $attr->get_options();
        $options = is_array($options) ? $options : [];
        if (in_array($term_id, array_map('intval', $options), true)) {
            return;
        }
        $options[] = $term_id;
        $attr->set_options($options);
        $attrs[$taxonomy] = $attr;
        $wc_product->set_attributes($attrs);

        // Explicitly set term relationship on parent product
        // This ensures the parent post is linked to all attribute terms
        wp_set_object_terms($parent_id, $options, $taxonomy, false);

        $wc_product->save();
    }

    private function find_by_grouped_product_id(int $grouped_product_id): int {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- meta value lookup not supported by WP API
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
            self::GROUPED_PRODUCT_ID_META,
            (string) $grouped_product_id
        ));
        return $id ? (int) $id : 0;
    }

    private function find_by_skwirrel_product_id(int $product_id): int {
        global $wpdb;
        $meta_key = $this->mapper->get_product_id_meta_key();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- meta value lookup with post_type filter not supported by WP API
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT pm.post_id FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                 AND p.post_type IN ('product', 'product_variation')
                 AND p.post_status NOT IN ('trash', 'auto-draft')
             WHERE pm.meta_key = %s AND pm.meta_value = %s
             LIMIT 1",
            $meta_key,
            (string) $product_id
        ));
        return $id ? (int) $id : 0;
    }

    private function get_client(): ?Skwirrel_WC_Sync_JsonRpc_Client {
        $opts = $this->get_options();
        $url = $opts['endpoint_url'] ?? '';
        $auth = $opts['auth_type'] ?? 'bearer';
        $token = Skwirrel_WC_Sync_Admin_Settings::get_auth_token();
        if (empty($url) || empty($token)) {
            return null;
        }
        return new Skwirrel_WC_Sync_JsonRpc_Client(
            $url,
            $auth,
            $token,
            (int) ($opts['timeout'] ?? 30),
            (int) ($opts['retries'] ?? 2)
        );
    }

    private function get_options(): array {
        $defaults = [
            'endpoint_url' => '',
            'auth_type' => 'bearer',
            'auth_token' => '',
            'timeout' => 30,
            'retries' => 2,
            'batch_size' => 100,
            'sync_categories' => true,
            'sync_grouped_products' => false,
            'sync_images' => true,
            'image_language' => 'nl',
            'include_languages' => ['nl-NL', 'nl'],
            'verbose_logging' => false,
        ];
        $saved = get_option('skwirrel_wc_sync_settings', []);
        return array_merge($defaults, is_array($saved) ? $saved : []);
    }

    /**
     * Get collection IDs from settings. Returns array of int IDs, or empty array for "sync all".
     */
    private function get_collection_ids(): array {
        $opts = get_option('skwirrel_wc_sync_settings', []);
        $raw = $opts['collection_ids'] ?? '';
        if ($raw === '' || !is_string($raw)) {
            return [];
        }
        $parts = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_map('intval', array_filter($parts, 'is_numeric')));
    }

    private function get_include_languages(): array {
        $opts = get_option('skwirrel_wc_sync_settings', []);
        $langs = $opts['include_languages'] ?? ['nl-NL', 'nl'];
        if (!empty($langs) && is_array($langs)) {
            return array_values(array_filter(array_map('sanitize_text_field', $langs)));
        }
        return ['nl-NL', 'nl'];
    }

    private function find_by_external_id(string $key): int {
        global $wpdb;
        $meta_key = $this->mapper->get_external_id_meta_key();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- meta value lookup with post_type filter not supported by WP API
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT pm.post_id FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                 AND p.post_type IN ('product', 'product_variation')
                 AND p.post_status NOT IN ('trash', 'auto-draft')
             WHERE pm.meta_key = %s AND pm.meta_value = %s
             LIMIT 1",
            $meta_key,
            $key
        ));
        return $id ? (int) $id : 0;
    }

    private function format_downloads(array $files): array {
        $downloads = [];
        foreach ($files as $i => $f) {
            $downloads[(string) $i] = [
                'name' => $f['name'],
                'file' => $f['file'],
            ];
        }
        return $downloads;
    }

    /**
     * Verwijder producten uit WooCommerce die niet meer in Skwirrel voorkomen.
     * Werkt alleen bij volledige sync (niet delta) zonder collectie-filter.
     * Producten worden naar de prullenbak verplaatst (niet permanent verwijderd).
     */
    private function purge_stale_products(int $sync_started_at): int {
        global $wpdb;
        $external_id_meta = $this->mapper->get_external_id_meta_key();
        $synced_at_meta = $this->mapper->get_synced_at_meta_key();

        // Vind producten met _skwirrel_external_id die NIET bijgewerkt zijn tijdens deze sync
        // Veiligheidscheck: meta_value moet numeriek zijn (voorkom corrupt data → onterecht trashen)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $stale_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT pm_ext.post_id
             FROM {$wpdb->postmeta} pm_ext
             INNER JOIN {$wpdb->posts} p ON pm_ext.post_id = p.ID
                 AND p.post_status NOT IN ('trash', 'auto-draft')
                 AND p.post_type IN ('product', 'product_variation')
             LEFT JOIN {$wpdb->postmeta} pm_sync ON pm_ext.post_id = pm_sync.post_id
                 AND pm_sync.meta_key = %s
             WHERE pm_ext.meta_key = %s
                 AND pm_ext.meta_value != ''
                 AND (
                     pm_sync.meta_value IS NULL
                     OR (pm_sync.meta_value REGEXP '^[0-9]+$' AND CAST(pm_sync.meta_value AS UNSIGNED) < %d)
                 )",
            $synced_at_meta,
            $external_id_meta,
            $sync_started_at
        ));

        // Vind variable producten (grouped products) die niet bijgewerkt zijn
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $stale_variable_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT pm_grp.post_id
             FROM {$wpdb->postmeta} pm_grp
             INNER JOIN {$wpdb->posts} p ON pm_grp.post_id = p.ID
                 AND p.post_status NOT IN ('trash', 'auto-draft')
                 AND p.post_type = 'product'
             LEFT JOIN {$wpdb->postmeta} pm_sync ON pm_grp.post_id = pm_sync.post_id
                 AND pm_sync.meta_key = %s
             WHERE pm_grp.meta_key = %s
                 AND pm_grp.meta_value != ''
                 AND (
                     pm_sync.meta_value IS NULL
                     OR (pm_sync.meta_value REGEXP '^[0-9]+$' AND CAST(pm_sync.meta_value AS UNSIGNED) < %d)
                 )",
            $synced_at_meta,
            self::GROUPED_PRODUCT_ID_META,
            $sync_started_at
        ));

        $all_stale = array_unique(array_merge(
            array_map('intval', $stale_ids),
            array_map('intval', $stale_variable_ids)
        ));

        if (empty($all_stale)) {
            $this->logger->verbose('Geen verwijderde producten gevonden');
            return 0;
        }

        // Pre-purge samenvatting loggen
        $this->logger->info('Verwijderde producten gedetecteerd', [
            'count' => count($all_stale),
            'product_ids' => array_slice($all_stale, 0, 20),
            'sync_started_at' => gmdate('Y-m-d H:i:s', $sync_started_at),
        ]);

        $trashed = 0;
        foreach ($all_stale as $post_id) {
            $product = wc_get_product($post_id);
            if (!$product) {
                $this->logger->verbose('Stale product niet gevonden, overgeslagen', ['wc_id' => $post_id]);
                continue;
            }

            $this->logger->info('Product verwijderd uit Skwirrel, naar prullenbak verplaatst', [
                'wc_id' => $post_id,
                'sku' => $product->get_sku(),
                'name' => $product->get_name(),
                'type' => $product->get_type(),
            ]);

            $product->set_status('trash');
            $product->save();
            $trashed++;

            // Variable product: ook variaties naar prullenbak
            if ($product->is_type('variable')) {
                $variation_ids = $product->get_children();
                foreach ($variation_ids as $vid) {
                    $variation = wc_get_product($vid);
                    if ($variation && $variation->get_status() !== 'trash') {
                        $variation->set_status('trash');
                        $variation->save();
                        $trashed++;
                    }
                }
            }
        }

        if ($trashed > 0) {
            $this->logger->info('Verwijderde producten opgeruimd', ['count' => $trashed]);
        }

        return $trashed;
    }

    /**
     * Verwijder categorieën die niet meer in Skwirrel voorkomen.
     * Alleen categorieën met _skwirrel_category_id meta worden verwijderd.
     * Categorieën met nog gekoppelde producten worden overgeslagen (veiligheid).
     */
    private function purge_stale_categories(): int {
        global $wpdb;
        $cat_meta_key = Skwirrel_WC_Sync_Product_Mapper::CATEGORY_ID_META;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $all_skwirrel_terms = $wpdb->get_results($wpdb->prepare(
            "SELECT tm.term_id, tm.meta_value as skwirrel_id, t.name as term_name
             FROM {$wpdb->termmeta} tm
             INNER JOIN {$wpdb->term_taxonomy} tt ON tm.term_id = tt.term_id AND tt.taxonomy = 'product_cat'
             INNER JOIN {$wpdb->terms} t ON tm.term_id = t.term_id
             WHERE tm.meta_key = %s AND tm.meta_value != ''",
            $cat_meta_key
        ));

        $seen = array_unique($this->seen_category_ids);
        $purged = 0;

        foreach ($all_skwirrel_terms as $term) {
            if (in_array($term->skwirrel_id, $seen, true)) {
                continue;
            }

            // Veiligheidscheck: verwijder niet als er nog producten aan gekoppeld zijn
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $product_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
                 INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID
                     AND p.post_type IN ('product', 'product_variation')
                     AND p.post_status NOT IN ('trash', 'auto-draft')
                 WHERE tr.term_taxonomy_id = (
                     SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy}
                     WHERE term_id = %d AND taxonomy = 'product_cat'
                 )",
                $term->term_id
            ));

            if ($product_count > 0) {
                $this->logger->warning('Categorie niet verwijderd: nog producten gekoppeld', [
                    'term_id' => $term->term_id,
                    'name' => $term->term_name,
                    'skwirrel_id' => $term->skwirrel_id,
                    'product_count' => $product_count,
                ]);
                continue;
            }

            $this->logger->info('Categorie verwijderd uit Skwirrel, opgeruimd', [
                'term_id' => $term->term_id,
                'name' => $term->term_name,
                'skwirrel_id' => $term->skwirrel_id,
            ]);
            wp_delete_term((int) $term->term_id, 'product_cat');
            $purged++;
        }

        if ($purged > 0) {
            $this->logger->info('Verwijderde categorieën opgeruimd', ['count' => $purged]);
        }

        return $purged;
    }

    private function update_last_result(bool $ok, int $created, int $updated, int $failed, string $error = '', int $with_attrs = 0, int $without_attrs = 0, int $trashed = 0, int $categories_removed = 0): void {
        $result = [
            'success' => $ok,
            'created' => $created,
            'updated' => $updated,
            'failed' => $failed,
            'trashed' => $trashed,
            'categories_removed' => $categories_removed,
            'error' => $error,
            'with_attributes' => $with_attrs,
            'without_attributes' => $without_attrs,
            'timestamp' => time(),
        ];

        update_option(self::OPTION_LAST_SYNC_RESULT, $result, false);

        // Add to history
        $history = get_option(self::OPTION_SYNC_HISTORY, []);
        if (!is_array($history)) {
            $history = [];
        }

        // Prepend newest entry at the beginning
        array_unshift($history, $result);

        // Keep only the latest MAX_HISTORY_ENTRIES
        $history = array_slice($history, 0, self::MAX_HISTORY_ENTRIES);

        update_option(self::OPTION_SYNC_HISTORY, $history, false);
    }

    public static function get_last_sync(): ?string {
        return get_option(self::OPTION_LAST_SYNC, null);
    }

    public static function get_last_result(): ?array {
        return get_option(self::OPTION_LAST_SYNC_RESULT, null);
    }

    public static function get_sync_history(): array {
        $history = get_option(self::OPTION_SYNC_HISTORY, []);
        return is_array($history) ? $history : [];
    }
}
