<?php
/**
 * Skwirrel Brand Sync.
 *
 * Handles WooCommerce product_brand taxonomy operations:
 * - Full brand sync from Skwirrel API (getBrands)
 * - Per-product brand assignment
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Skwirrel_WC_Sync_Brand_Sync {

    private Skwirrel_WC_Sync_Logger $logger;

    /**
     * @param Skwirrel_WC_Sync_Logger $logger Logger instance.
     */
    public function __construct(Skwirrel_WC_Sync_Logger $logger) {
        $this->logger = $logger;
    }

    /**
     * Sync all brands from the API, independent of products.
     *
     * Creates product_brand terms for every brand returned by getBrands.
     *
     * @param Skwirrel_WC_Sync_JsonRpc_Client $client API client.
     */
    public function sync_all_brands(Skwirrel_WC_Sync_JsonRpc_Client $client): void {
        if (!taxonomy_exists('product_brand')) {
            return;
        }

        Skwirrel_WC_Sync_History::sync_heartbeat();
        $this->logger->info('Syncing all brands via getBrands');

        $result = $client->call('getBrands', []);

        if (!$result['success']) {
            $err = $result['error'] ?? ['message' => 'Unknown error'];
            $this->logger->error('getBrands API error', $err);
            return;
        }

        $data = $result['result'] ?? [];
        $brands = $data['brands'] ?? $data;
        if (!is_array($brands)) {
            $this->logger->warning('getBrands returned unexpected format', ['type' => gettype($brands)]);
            return;
        }

        $created = 0;
        foreach ($brands as $brand) {
            $brand_name = trim($brand['brand_name'] ?? $brand['name'] ?? '');
            if ($brand_name === '') {
                continue;
            }

            $term = term_exists($brand_name, 'product_brand');
            if ($term && !is_wp_error($term)) {
                continue; // Already exists
            }

            $inserted = wp_insert_term($brand_name, 'product_brand');
            if (is_wp_error($inserted)) {
                if ($inserted->get_error_code() !== 'term_exists') {
                    $this->logger->warning('Failed to create brand term', [
                        'brand' => $brand_name,
                        'error' => $inserted->get_error_message(),
                    ]);
                }
            } else {
                ++$created;
            }
        }

        $this->logger->info('Brands synced', [
            'total' => count($brands),
            'created' => $created,
        ]);
    }

    /**
     * Assign product_brand taxonomy term from Skwirrel brand_name.
     *
     * Finds or creates the brand term and assigns it to the product.
     *
     * @param int   $wc_product_id WooCommerce product ID.
     * @param array $product       Skwirrel product data.
     */
    public function assign_brand(int $wc_product_id, array $product): void {
        if (!taxonomy_exists('product_brand')) {
            return;
        }

        $brand_name = trim($product['brand_name'] ?? '');
        if ($brand_name === '') {
            return;
        }

        $term = term_exists($brand_name, 'product_brand');
        if ($term && !is_wp_error($term)) {
            $term_id = is_array($term) ? (int) $term['term_id'] : (int) $term;
        } else {
            $inserted = wp_insert_term($brand_name, 'product_brand');
            if (is_wp_error($inserted)) {
                if ($inserted->get_error_code() === 'term_exists') {
                    $term_id = (int) $inserted->get_error_data('term_exists');
                } else {
                    $this->logger->warning('Failed to create brand term', [
                        'brand' => $brand_name,
                        'error' => $inserted->get_error_message(),
                    ]);
                    return;
                }
            } else {
                $term_id = (int) $inserted['term_id'];
                $this->logger->verbose('Brand term created', [
                    'term_id' => $term_id,
                    'brand' => $brand_name,
                ]);
            }
        }

        wp_set_object_terms($wc_product_id, [$term_id], 'product_brand');
        $this->logger->verbose('Brand assigned', [
            'wc_product_id' => $wc_product_id,
            'brand' => $brand_name,
            'term_id' => $term_id,
        ]);
    }
}
