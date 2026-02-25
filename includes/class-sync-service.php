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

    private Skwirrel_WC_Sync_Logger $logger;
    private Skwirrel_WC_Sync_Product_Mapper $mapper;
    private Skwirrel_WC_Sync_Product_Lookup $lookup;
    private Skwirrel_WC_Sync_Purge_Handler $purge_handler;
    private Skwirrel_WC_Sync_Category_Sync $category_sync;
    private Skwirrel_WC_Sync_Brand_Sync $brand_sync;
    private Skwirrel_WC_Sync_Taxonomy_Manager $taxonomy_manager;

    public function __construct() {
        $this->logger = new Skwirrel_WC_Sync_Logger();
        $this->mapper = new Skwirrel_WC_Sync_Product_Mapper();
        $this->lookup = new Skwirrel_WC_Sync_Product_Lookup($this->mapper);
        $this->purge_handler = new Skwirrel_WC_Sync_Purge_Handler($this->logger);
        $this->category_sync = new Skwirrel_WC_Sync_Category_Sync($this->logger);
        $this->brand_sync = new Skwirrel_WC_Sync_Brand_Sync($this->logger);
        $this->taxonomy_manager = new Skwirrel_WC_Sync_Taxonomy_Manager($this->logger);
    }

    /**
     * Run sync. Returns summary array.
     *
     * @param bool $delta Use delta sync (updated_on >= last sync) if possible.
     * @return array{success: bool, created: int, updated: int, failed: int, error?: string}
     */
    public function run_sync(bool $delta = false): array {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged,Squiz.PHP.DiscouragedFunctions.Discouraged -- long-running sync requires no time limit
        }

        $sync_started_at = time();
        $this->category_sync->reset_seen_category_ids();
        Skwirrel_WC_Sync_History::sync_heartbeat();

        $client = $this->get_client();
        if (!$client) {
            $this->logger->error('Sync aborted: invalid configuration');
            return ['success' => false, 'error' => 'Invalid configuration', 'created' => 0, 'updated' => 0, 'failed' => 0];
        }

        $options = $this->get_options();
        $created = 0;
        $updated = 0;
        $failed = 0;
        $delta_since = get_option(Skwirrel_WC_Sync_History::OPTION_LAST_SYNC, '');

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

        // Sync category tree from super category (before products)
        if (!empty($options['sync_categories']) && !empty($options['super_category_id'])) {
            $this->category_sync->sync_category_tree($client, $options, $this->get_include_languages());
        }

        // Sync all brands (independent of products)
        $this->brand_sync->sync_all_brands($client);

        // Sync all custom classes as WooCommerce attributes (independent of products)
        if (!empty($options['sync_custom_classes']) || !empty($options['sync_trade_item_custom_classes'])) {
            $this->taxonomy_manager->sync_all_custom_classes($client, $options, $this->get_include_languages());
        }

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
            Skwirrel_WC_Sync_History::update_last_result(false, $created, $updated, $failed, $err['message'] ?? '');
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
            Skwirrel_WC_Sync_History::update_last_result(true, 0, 0, 0);
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
                Skwirrel_WC_Sync_History::sync_heartbeat();
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
                $trashed = $this->purge_handler->purge_stale_products($sync_started_at, $this->mapper);
                if (!empty($options['sync_categories'])) {
                    $categories_removed = $this->purge_handler->purge_stale_categories($this->category_sync->get_seen_category_ids());
                }
            }
        }

        update_option(Skwirrel_WC_Sync_History::OPTION_LAST_SYNC, gmdate('Y-m-d\TH:i:s\Z'));
        Skwirrel_WC_Sync_History::update_last_result(true, $created, $updated, $failed, '', $with_attrs, $without_attrs, $trashed, $categories_removed);

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
            $wc_id = $this->lookup->find_by_external_id($key);
        }

        // Stap 3: Zoek op _skwirrel_product_id meta (meest stabiele identifier)
        if (!$wc_id && $skwirrel_product_id !== null && $skwirrel_product_id !== '' && $skwirrel_product_id !== 0) {
            $wc_id = $this->lookup->find_by_skwirrel_product_id((int) $skwirrel_product_id);
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

        $this->category_sync->assign_categories($id, $product, $this->mapper);
        $this->brand_sync->assign_brand($id, $product);

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

        $wc_id = $this->lookup->find_by_grouped_product_id((int) $grouped_id);
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
                $etim_slug = $this->taxonomy_manager->get_etim_attribute_slug($code);
                $label = !empty($ef['label']) ? $ef['label'] : $code;
                $tax = $this->taxonomy_manager->ensure_product_attribute_exists($etim_slug, $label);
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
            $this->taxonomy_manager->ensure_variant_taxonomy_exists();
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
        update_post_meta($id, Skwirrel_WC_Sync_Product_Lookup::GROUPED_PRODUCT_ID_META, (int) $grouped_id);
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

        $this->category_sync->assign_categories($id, $group, $this->mapper);
        $this->brand_sync->assign_brand($id, $group);

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

        $variation_id = $this->lookup->find_variation_by_sku($wc_variable_id, $sku);
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
                $slug = $this->taxonomy_manager->get_etim_attribute_slug($code);
                $tax = wc_attribute_taxonomy_name($slug);
                $label = !empty($data['label']) ? $data['label'] : $code;
                if (!taxonomy_exists($tax)) {
                    $this->taxonomy_manager->ensure_product_attribute_exists($slug, $label);
                } else {
                    $this->taxonomy_manager->maybe_update_attribute_label($slug, $label);
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

}
