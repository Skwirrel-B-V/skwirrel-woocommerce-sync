<?php
/**
 * Skwirrel Sync - Admin Columns & Bulk Actions.
 *
 * Adds Skwirrel Sync column to product list and bulk actions for sync reset.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Skwirrel_WC_Sync_Admin_Columns {

    private static ?self $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter('manage_edit-product_columns', [$this, 'add_column']);
        add_action('manage_product_posts_custom_column', [$this, 'render_column'], 10, 2);
        add_filter('manage_edit-product_sortable_columns', [$this, 'sortable_columns']);
        add_action('pre_get_posts', [$this, 'sort_by_sync']);
        add_filter('bulk_actions-edit-product', [$this, 'add_bulk_actions']);
        add_filter('handle_bulk_actions-edit-product', [$this, 'handle_bulk_actions'], 10, 3);
        add_action('admin_notices', [$this, 'bulk_action_notice']);
    }

    public function add_column(array $columns): array {
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'sku') {
                $new['skwirrel_sync'] = __('Skwirrel Sync', 'skwirrel-wc-sync');
            }
        }
        if (!isset($new['skwirrel_sync'])) {
            $new['skwirrel_sync'] = __('Skwirrel Sync', 'skwirrel-wc-sync');
        }
        return $new;
    }

    public function render_column(string $column, int $post_id): void {
        if ($column !== 'skwirrel_sync') {
            return;
        }
        $synced_at = get_post_meta($post_id, '_skwirrel_synced_at', true);
        $protected = get_post_meta($post_id, '_skwirrel_sync_protected', true);
        if ($synced_at) {
            echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $synced_at));
            if ($protected) {
                echo ' <span title="' . esc_attr__('Sync beschermd', 'skwirrel-wc-sync') . '">&#128274;</span>';
            }
        } else {
            echo '<span style="color:#999;">&mdash;</span>';
        }
    }

    public function sortable_columns(array $columns): array {
        $columns['skwirrel_sync'] = 'skwirrel_sync';
        return $columns;
    }

    public function sort_by_sync(WP_Query $query): void {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }
        if ($query->get('orderby') === 'skwirrel_sync') {
            $query->set('meta_key', '_skwirrel_synced_at');
            $query->set('orderby', 'meta_value_num');
        }
    }

    public function add_bulk_actions(array $actions): array {
        $actions['skwirrel_reset_sync'] = __('Reset Skwirrel sync data', 'skwirrel-wc-sync');
        return $actions;
    }

    public function handle_bulk_actions(string $redirect_to, string $action, array $post_ids): string {
        if ($action !== 'skwirrel_reset_sync') {
            return $redirect_to;
        }

        foreach ($post_ids as $post_id) {
            delete_post_meta((int) $post_id, '_skwirrel_synced_at');
        }

        return add_query_arg('skwirrel_reset', count($post_ids), $redirect_to);
    }

    public function bulk_action_notice(): void {
        if (!empty($_REQUEST['skwirrel_reset'])) {
            $count = (int) $_REQUEST['skwirrel_reset'];
            printf(
                '<div class="notice notice-success is-dismissible"><p>' .
                esc_html(_n(
                    'Skwirrel sync data gereset voor %d product.',
                    'Skwirrel sync data gereset voor %d producten.',
                    $count,
                    'skwirrel-wc-sync'
                )) . '</p></div>',
                $count
            );
        }
    }
}
