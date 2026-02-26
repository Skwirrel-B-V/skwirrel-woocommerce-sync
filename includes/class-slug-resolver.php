<?php
/**
 * Skwirrel → WooCommerce Slug Resolver.
 *
 * Resolves product URL slugs based on admin settings.
 * Uses a two-level strategy: primary field as base slug,
 * optional suffix field when slug already exists.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Skwirrel_WC_Sync_Slug_Resolver {

    /**
     * Resolve slug for a product (from getProducts API data).
     * Returns null to let WordPress generate from title.
     *
     * @param array $product Skwirrel product data.
     * @return string|null Slug or null.
     */
    public function resolve(array $product): ?string {
        $opts = $this->get_settings();
        $primary = $opts['slug_source_field'] ?? 'product_name';

        if ($primary === 'product_name') {
            return null;
        }

        $base = $this->sanitize_field($this->resolve_raw_value($product, $primary));
        if ($base === null) {
            return null;
        }

        return $this->resolve_unique($base, $product, $opts);
    }

    /**
     * Resolve slug for a grouped (variable) product.
     * Uses group-level field names (grouped_product_code, grouped_product_id).
     *
     * @param array $group Grouped product data from API.
     * @return string|null Slug or null.
     */
    public function resolve_for_group(array $group): ?string {
        $opts = $this->get_settings();
        $primary = $opts['slug_source_field'] ?? 'product_name';

        if ($primary === 'product_name') {
            return null;
        }

        $base = $this->sanitize_field($this->resolve_group_raw_value($group, $primary));
        if ($base === null) {
            return null;
        }

        return $this->resolve_unique($base, $group, $opts, true);
    }

    /**
     * Try base slug, then base-suffix, then return base (WP will append -2, -3).
     *
     * @param string $base      Sanitized base slug.
     * @param array  $data      Product or group data (for suffix field lookup).
     * @param array  $opts      Plugin settings.
     * @param bool   $is_group  Whether $data is a grouped product.
     * @return string The resolved slug.
     */
    private function resolve_unique(string $base, array $data, array $opts, bool $is_group = false): string {
        if (!$this->slug_exists($base)) {
            return $base;
        }

        $suffix_field = $opts['slug_suffix_field'] ?? '';
        if ($suffix_field !== '') {
            $suffix_raw = $is_group
                ? $this->resolve_group_raw_value($data, $suffix_field)
                : $this->resolve_raw_value($data, $suffix_field);
            if ($suffix_raw !== '') {
                $candidate = $base . '-' . sanitize_title($suffix_raw);
                if ($candidate !== $base && $candidate !== '') {
                    return $candidate;
                }
            }
        }

        return $base;
    }

    /**
     * Sanitize a raw field value into a slug. Returns null if empty.
     */
    private function sanitize_field(string $raw): ?string {
        if ($raw === '') {
            return null;
        }
        $slug = sanitize_title($raw);
        return $slug !== '' ? $slug : null;
    }

    /**
     * Extract raw field value from product data.
     */
    private function resolve_raw_value(array $product, string $field): string {
        return match ($field) {
            'internal_product_code'     => (string) ($product['internal_product_code'] ?? ''),
            'manufacturer_product_code' => (string) ($product['manufacturer_product_code'] ?? ''),
            'external_product_id'       => (string) ($product['external_product_id'] ?? ''),
            'product_id'                => (string) ($product['product_id'] ?? ''),
            default                     => '',
        };
    }

    /**
     * Extract raw field value from grouped product data.
     * Maps to group-specific field names.
     */
    private function resolve_group_raw_value(array $group, string $field): string {
        return match ($field) {
            'internal_product_code'     => (string) ($group['grouped_product_code'] ?? $group['internal_product_code'] ?? ''),
            'manufacturer_product_code' => (string) ($group['manufacturer_product_code'] ?? ''),
            'external_product_id'       => (string) ($group['external_product_id'] ?? ''),
            'product_id'                => (string) ($group['grouped_product_id'] ?? $group['id'] ?? ''),
            default                     => '',
        };
    }

    /**
     * Check if a slug already exists for a non-trashed product.
     */
    private function slug_exists(string $slug): bool {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_name = %s AND post_type = 'product' AND post_status != 'trash'",
            $slug
        ));
        return (int) $count > 0;
    }

    /**
     * Get plugin settings.
     *
     * @return array
     */
    private function get_settings(): array {
        return get_option('skwirrel_wc_sync_settings', []);
    }
}
