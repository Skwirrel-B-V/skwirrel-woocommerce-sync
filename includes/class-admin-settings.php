<?php
/**
 * Skwirrel Sync - Admin Settings UI.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Skwirrel_WC_Sync_Admin_Settings {

    private const PAGE_SLUG = 'skwirrel-pim-wp-sync';
    private const OPTION_KEY = 'skwirrel_wc_sync_settings';
    private const TOKEN_OPTION_KEY = 'skwirrel_wc_sync_auth_token';
    private const MASK = '••••••••';

    private const LANGUAGE_OPTIONS = [
        'nl'    => 'Nederlands (nl)',
        'nl-NL' => 'Nederlands – Nederland (nl-NL)',
        'en'    => 'English (en)',
        'en-GB' => 'English – GB (en-GB)',
        'de'    => 'Deutsch (de)',
        'de-DE' => 'Deutsch – Deutschland (de-DE)',
        'fr'    => 'Français (fr)',
        'fr-FR' => 'Français – France (fr-FR)',
    ];

    private static ?self $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private const BG_SYNC_ACTION = 'skwirrel_wc_sync_background';
    private const BG_SYNC_TRANSIENT = 'skwirrel_wc_sync_bg_token';
    private const BG_PURGE_ACTION = 'skwirrel_wc_sync_purge_all';
    private const BG_PURGE_TRANSIENT = 'skwirrel_wc_sync_purge_token';
    private const SYNC_IN_PROGRESS = 'skwirrel_wc_sync_in_progress';
    private function __construct() {
        add_action('admin_menu', [$this, 'add_menu'], 99);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_skwirrel_wc_sync_test', [$this, 'handle_test_connection']);
        add_action('admin_post_skwirrel_wc_sync_run', [$this, 'handle_sync_now']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_head', [$this, 'render_sync_busy_css']);
        add_action('wp_ajax_' . self::BG_SYNC_ACTION, [$this, 'handle_background_sync']);
        add_action('wp_ajax_nopriv_' . self::BG_SYNC_ACTION, [$this, 'handle_background_sync']);
        add_action('admin_post_skwirrel_wc_sync_purge', [$this, 'handle_purge_now']);
        add_action('wp_ajax_' . self::BG_PURGE_ACTION, [$this, 'handle_background_purge']);
        add_action('wp_ajax_nopriv_' . self::BG_PURGE_ACTION, [$this, 'handle_background_purge']);
    }

    public function add_menu(): void {
        $sync_in_progress = (bool) get_transient(self::SYNC_IN_PROGRESS);

        add_submenu_page(
            'woocommerce',
            __('Skwirrel Sync', 'skwirrel-pim-wp-sync'),
            __('Skwirrel Sync', 'skwirrel-pim-wp-sync'),
            'manage_woocommerce',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );

        $menu_label = __('Sync Products', 'skwirrel-pim-wp-sync');
        if ($sync_in_progress) {
            $menu_label .= ' <span class="awaiting-mod skwirrel-sync-busy" title="Sync in progress">⟳</span>';
        }

        $hook = add_menu_page(
            __('Sync Products', 'skwirrel-pim-wp-sync'),
            $menu_label,
            'manage_woocommerce',
            'skwirrel-sync-now',
            '__return_null',
            'dashicons-update',
            81
        );
        add_action('load-' . $hook, [$this, 'handle_menu_sync_redirect']);
    }

    public function handle_menu_sync_redirect(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Access denied.', 'skwirrel-pim-wp-sync'));
        }

        $token = bin2hex(random_bytes(16));
        set_transient(self::BG_SYNC_TRANSIENT . '_' . $token, '1', 120);
        set_transient(self::SYNC_IN_PROGRESS, '1', 600);

        $url = add_query_arg([
            'action' => self::BG_SYNC_ACTION,
            'token' => $token,
        ], admin_url('admin-ajax.php'));

        $redirect = add_query_arg([
            'page' => self::PAGE_SLUG,
            'tab' => 'sync',
        ], admin_url('admin.php'));

        wp_safe_redirect($redirect);

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        wp_remote_post($url, [
            'blocking' => false,
            'timeout' => 0.01,
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        ]);

        exit;
    }

    public function register_settings(): void {
        register_setting('skwirrel_wc_sync', self::OPTION_KEY, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);
        add_action('update_option_' . self::OPTION_KEY, [$this, 'on_settings_updated'], 10, 3);
    }

    public function on_settings_updated($old_value, $value, $option): void {
        if (is_array($value)) {
            Skwirrel_WC_Sync_Action_Scheduler::instance()->schedule();
        }
    }

    public function sanitize_settings(array $input): array {
        $out = [];
        $out['endpoint_url'] = isset($input['endpoint_url']) ? esc_url_raw(trim($input['endpoint_url'])) : '';
        $out['auth_type'] = in_array($input['auth_type'] ?? '', ['bearer', 'token'], true) ? $input['auth_type'] : 'bearer';
        $token = $this->sanitize_token($input['auth_token'] ?? '');
        if (!empty($token)) {
            update_option(self::TOKEN_OPTION_KEY, $token, false);
        }
        $out['auth_token'] = !empty($token) ? self::MASK : '';
        $out['timeout'] = isset($input['timeout']) ? max(5, min(120, (int) $input['timeout'])) : 30;
        $out['retries'] = isset($input['retries']) ? max(0, min(5, (int) $input['retries'])) : 2;
        $out['sync_interval'] = $input['sync_interval'] ?? '';
        $out['batch_size'] = isset($input['batch_size']) ? max(10, min(500, (int) $input['batch_size'])) : 100;
        $out['sync_categories'] = !empty($input['sync_categories']);
        $out['super_category_id'] = isset($input['super_category_id']) ? sanitize_text_field(trim($input['super_category_id'])) : '';
        $out['sync_grouped_products'] = !empty($input['sync_grouped_products']);
        $out['sync_images'] = ($input['sync_images'] ?? 'yes') === 'yes';
        // Image language: dropdown or custom
        $lang_select = $input['image_language_select'] ?? '';
        $lang_custom = sanitize_text_field($input['image_language_custom'] ?? '');
        if ($lang_select === '_custom' && $lang_custom !== '') {
            $out['image_language'] = $lang_custom;
        } elseif ($lang_select !== '' && $lang_select !== '_custom') {
            $out['image_language'] = sanitize_text_field($lang_select);
        } else {
            // Backward compatibility: accept old direct field
            $out['image_language'] = sanitize_text_field($input['image_language'] ?? 'nl');
        }
        // Include languages: merge checkboxes + custom input
        $checked = $input['include_languages_checkboxes'] ?? [];
        if (!is_array($checked)) {
            $checked = [];
        }
        $checked = array_map('sanitize_text_field', $checked);
        $custom_raw = $input['include_languages_custom'] ?? '';
        $custom_parts = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', is_string($custom_raw) ? $custom_raw : '', -1, PREG_SPLIT_NO_EMPTY))));
        $custom_parts = array_map('sanitize_text_field', $custom_parts);
        $merged = array_values(array_unique(array_merge($checked, $custom_parts)));
        if (empty($merged)) {
            // Backward compatibility: accept old direct field
            $inc = $input['include_languages'] ?? '';
            $parsed = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', is_string($inc) ? $inc : '', -1, PREG_SPLIT_NO_EMPTY))));
            $merged = !empty($parsed) ? $parsed : ['nl-NL', 'nl'];
        }
        $out['include_languages'] = $merged;
        $out['use_sku_field'] = sanitize_text_field($input['use_sku_field'] ?? 'internal_product_code');
        // Collection IDs: comma-separated, keep only numeric values
        $raw_collections = $input['collection_ids'] ?? '';
        $collection_parts = preg_split('/[\s,]+/', is_string($raw_collections) ? $raw_collections : '', -1, PREG_SPLIT_NO_EMPTY);
        $out['collection_ids'] = implode(', ', array_filter(array_map('trim', $collection_parts), 'is_numeric'));
        // Custom classes
        $out['sync_custom_classes'] = !empty($input['sync_custom_classes']);
        $out['sync_trade_item_custom_classes'] = !empty($input['sync_trade_item_custom_classes']);
        $out['custom_class_filter_mode'] = in_array($input['custom_class_filter_mode'] ?? '', ['whitelist', 'blacklist'], true)
            ? $input['custom_class_filter_mode']
            : '';
        $raw_cc_filter = $input['custom_class_filter_ids'] ?? '';
        $cc_parts = preg_split('/[\s,]+/', is_string($raw_cc_filter) ? $raw_cc_filter : '', -1, PREG_SPLIT_NO_EMPTY);
        $out['custom_class_filter_ids'] = implode(', ', array_map('sanitize_text_field', array_map('trim', $cc_parts)));

        $out['verbose_logging'] = !empty($input['verbose_logging']);
        $out['purge_stale_products'] = !empty($input['purge_stale_products']);
        $out['show_delete_warning'] = !empty($input['show_delete_warning']);
        return $out;
    }

    private function sanitize_token(string $token): string {
        $token = trim($token);
        if ($token === self::MASK || $token === '') {
            return (string) get_option(self::TOKEN_OPTION_KEY, '');
        }
        return $token;
    }

    public static function get_auth_token(): string {
        return (string) get_option(self::TOKEN_OPTION_KEY, '');
    }

    public function handle_test_connection(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Access denied.', 'skwirrel-pim-wp-sync'));
        }
        check_admin_referer('skwirrel_wc_sync_test', '_wpnonce');

        $opts = get_option(self::OPTION_KEY, []);
        $token = self::get_auth_token();
        $client = new Skwirrel_WC_Sync_JsonRpc_Client(
            $opts['endpoint_url'] ?? '',
            $opts['auth_type'] ?? 'bearer',
            $token,
            (int) ($opts['timeout'] ?? 30),
            (int) ($opts['retries'] ?? 2)
        );

        $result = $client->test_connection();
        $redirect = add_query_arg([
            'page' => self::PAGE_SLUG,
            'tab' => 'settings',
            'test' => $result['success'] ? 'ok' : 'fail',
            'message' => $result['success'] ? '' : urlencode($result['error']['message'] ?? 'Unknown error'),
        ], admin_url('admin.php'));

        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_sync_now(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Access denied.', 'skwirrel-pim-wp-sync'));
        }
        check_admin_referer('skwirrel_wc_sync_run', '_wpnonce');

        $token = bin2hex(random_bytes(16));
        set_transient(self::BG_SYNC_TRANSIENT . '_' . $token, '1', 120);
        set_transient(self::SYNC_IN_PROGRESS, '1', 600);

        $url = add_query_arg([
            'action' => self::BG_SYNC_ACTION,
            'token' => $token,
        ], admin_url('admin-ajax.php'));

        $redirect = add_query_arg([
            'page' => self::PAGE_SLUG,
            'tab' => 'sync',
        ], admin_url('admin.php'));

        wp_safe_redirect($redirect);

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        wp_remote_post($url, [
            'blocking' => false,
            'timeout' => 0.01,
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        ]);

        exit;
    }

    public function handle_background_sync(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- uses transient-based token instead of nonce
        $token = isset($_REQUEST['token']) ? sanitize_text_field(wp_unslash($_REQUEST['token'])) : '';
        if (empty($token) || strlen($token) !== 32 || !ctype_xdigit($token)) {
            wp_die('Invalid request', 403);
        }
        if (get_transient(self::BG_SYNC_TRANSIENT . '_' . $token) !== '1') {
            wp_die('Invalid or expired token', 403);
        }
        delete_transient(self::BG_SYNC_TRANSIENT . '_' . $token);

        $service = new Skwirrel_WC_Sync_Service();
        $service->run_sync(false);

        delete_transient(self::SYNC_IN_PROGRESS);

        wp_die('', 200);
    }

    public function handle_purge_now(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Access denied.', 'skwirrel-pim-wp-sync'));
        }
        check_admin_referer('skwirrel_wc_sync_purge', '_wpnonce');

        $permanent = !empty($_POST['skwirrel_purge_empty_trash']);
        $mode = $permanent ? 'delete' : 'trash';

        $token = bin2hex(random_bytes(16));
        set_transient(self::BG_PURGE_TRANSIENT . '_' . $token, $mode, 120);

        $url = add_query_arg([
            'action' => self::BG_PURGE_ACTION,
            'token' => $token,
        ], admin_url('admin-ajax.php'));

        $redirect = add_query_arg([
            'page' => self::PAGE_SLUG,
            'tab' => 'settings',
            'purge' => 'queued',
        ], admin_url('admin.php'));

        wp_safe_redirect($redirect);

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        wp_remote_post($url, [
            'blocking' => false,
            'timeout' => 0.01,
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        ]);

        exit;
    }

    public function handle_background_purge(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- uses transient-based token instead of nonce
        $token = isset($_REQUEST['token']) ? sanitize_text_field(wp_unslash($_REQUEST['token'])) : '';
        if (empty($token) || strlen($token) !== 32 || !ctype_xdigit($token)) {
            wp_die('Invalid request', 403);
        }
        $mode = get_transient(self::BG_PURGE_TRANSIENT . '_' . $token);
        if ($mode === false) {
            wp_die('Invalid or expired token', 403);
        }
        delete_transient(self::BG_PURGE_TRANSIENT . '_' . $token);

        $permanent = ($mode === 'delete');
        $this->purge_all_skwirrel_products($permanent);

        wp_die('', 200);
    }

    private function purge_all_skwirrel_products(bool $permanent): void {
        $logger = new Skwirrel_WC_Sync_Logger();
        $mode_label = $permanent ? 'permanent delete' : 'trash';
        $logger->info("Purge all Skwirrel products started (mode: {$mode_label})");

        global $wpdb;

        // --- Step 1: Delete Skwirrel media attachments ---
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk purge operation
        $attachment_ids = $wpdb->get_col(
            "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'attachment'
            AND pm.meta_key = '_skwirrel_source_url'"
        );

        $attachments_deleted = 0;
        if (!empty($attachment_ids)) {
            $logger->info('Purge: ' . count($attachment_ids) . ' Skwirrel-media-bestanden gevonden, verwijderen...');
            foreach ($attachment_ids as $attachment_id) {
                wp_delete_attachment((int) $attachment_id, true);
                ++$attachments_deleted;
            }
            $logger->info("Purge: {$attachments_deleted} media-bestanden verwijderd.");
        }

        // --- Step 2: Find products and collect their category term IDs ---
        $post_statuses = $permanent
            ? "'publish','draft','pending','private','trash'"
            : "'publish','draft','pending','private'";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk purge operation
        $product_ids = $wpdb->get_col(
            "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type IN ('product', 'product_variation')
            AND p.post_status IN ({$post_statuses})
            AND pm.meta_key IN ('_skwirrel_external_id', '_skwirrel_grouped_product_id')"
        );

        // Collect category term IDs assigned to Skwirrel products BEFORE deleting them.
        // This catches categories without _skwirrel_category_id meta (e.g. from _product_groups fallback).
        $skwirrel_cat_term_ids = [];
        if (!empty($product_ids)) {
            $ids_csv = implode(',', array_map('intval', $product_ids));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk purge operation
            $skwirrel_cat_term_ids = $wpdb->get_col(
                "SELECT DISTINCT tt.term_id FROM {$wpdb->term_relationships} tr
                INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'product_cat'
                WHERE tr.object_id IN ({$ids_csv})" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- IDs are cast to int above
            );
        }

        // Also collect categories with _skwirrel_category_id meta (may include categories not assigned to any current product)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk purge operation
        $meta_cat_term_ids = $wpdb->get_col(
            "SELECT tm.term_id FROM {$wpdb->termmeta} tm
            INNER JOIN {$wpdb->term_taxonomy} tt ON tm.term_id = tt.term_id AND tt.taxonomy = 'product_cat'
            WHERE tm.meta_key = '_skwirrel_category_id' AND tm.meta_value != ''"
        );

        $all_cat_term_ids = array_unique(array_map('intval', array_merge($skwirrel_cat_term_ids, $meta_cat_term_ids)));

        // --- Step 3: Delete products ---
        $deleted = 0;
        if (!empty($product_ids)) {
            $count = count($product_ids);
            $logger->info("Purge: {$count} Skwirrel-producten gevonden, verwerken...");

            foreach ($product_ids as $product_id) {
                $product_id = (int) $product_id;

                if ($permanent) {
                    // Delete variations first for variable products
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk purge operation
                    $children = $wpdb->get_col(
                        $wpdb->prepare(
                            "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation'",
                            $product_id
                        )
                    );
                    foreach ($children as $child_id) {
                        wp_delete_post((int) $child_id, true);
                    }
                    wp_delete_post($product_id, true);
                } else {
                    $product = wc_get_product($product_id);
                    if ($product) {
                        $product->set_status('trash');
                        $product->save();
                    }
                }
                ++$deleted;
            }
        }

        $logger->info("Purge: {$deleted} producten verwerkt (mode: {$mode_label})");

        // --- Step 4: Delete Skwirrel-related categories ---
        // Skip the default WooCommerce "Uncategorized" category.
        $default_cat_id = (int) get_option('default_product_cat', 0);
        $categories_deleted = 0;
        if (!empty($all_cat_term_ids)) {
            $logger->info('Purge: ' . count($all_cat_term_ids) . ' Skwirrel-categorieën gevonden, verwijderen...');
            foreach ($all_cat_term_ids as $term_id) {
                if ($term_id === $default_cat_id) {
                    continue;
                }
                $result = wp_delete_term($term_id, 'product_cat');
                if ($result === true) {
                    ++$categories_deleted;
                }
            }
            $logger->info("Purge: {$categories_deleted} categorieën verwijderd.");
        }

        // --- Step 5: Delete Skwirrel-created attribute taxonomies ---
        // Matches: etim_* (all ETIM-based attributes) and variant (fallback attribute)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk purge operation
        $attribute_rows = $wpdb->get_results(
            "SELECT attribute_id, attribute_name FROM {$wpdb->prefix}woocommerce_attribute_taxonomies
            WHERE attribute_name LIKE 'etim\\_%'
            OR attribute_name = 'variant'"
        );

        $attributes_deleted = 0;
        if (!empty($attribute_rows)) {
            $logger->info('Purge: ' . count($attribute_rows) . ' Skwirrel-attributen gevonden, verwijderen...');
            foreach ($attribute_rows as $attr) {
                if (function_exists('wc_delete_attribute')) {
                    wc_delete_attribute((int) $attr->attribute_id);
                }
                ++$attributes_deleted;
            }
            delete_transient('wc_attribute_taxonomies');
            $logger->info("Purge: {$attributes_deleted} attributen verwijderd.");
        }

        // --- Step 6: Reset sync state options ---
        delete_option('skwirrel_wc_sync_last_sync');
        delete_option('skwirrel_wc_sync_last_result');
        delete_option('skwirrel_wc_sync_history');
        delete_option('skwirrel_wc_sync_force_full_sync');
        $logger->info('Purge: sync-status opties gereset.');

        // --- Step 7: Store purge result ---
        $logger->info("Purge voltooid: {$deleted} producten, {$attachments_deleted} media, {$categories_deleted} categorieën, {$attributes_deleted} attributen (mode: {$mode_label})");

        update_option('skwirrel_wc_sync_last_purge', [
            'timestamp' => time(),
            'mode' => $mode_label,
            'products' => $deleted,
            'attachments' => $attachments_deleted,
            'categories' => $categories_deleted,
            'attributes' => $attributes_deleted,
        ], false);
    }

    public function enqueue_assets(string $hook): void {
        if (strpos($hook, self::PAGE_SLUG) === false) {
            return;
        }
        wp_enqueue_style('skwirrel-admin', SKWIRREL_WC_SYNC_PLUGIN_URL . 'assets/admin.css', [], SKWIRREL_WC_SYNC_VERSION);
    }

    public function render_sync_busy_css(): void {
        if (!get_transient(self::SYNC_IN_PROGRESS)) {
            return;
        }
        ?>
        <style>
            .skwirrel-sync-busy {
                display: inline-block;
                animation: skwirrel-spin 1.2s linear infinite;
                font-size: 14px;
                min-width: 16px;
                text-align: center;
                background: #d63638 !important;
            }
            @keyframes skwirrel-spin {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }
            #toplevel_page_skwirrel-sync-now > a .dashicons-update {
                animation: skwirrel-spin 1.2s linear infinite;
            }
        </style>
        <?php
    }

    public function render_page(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Access denied.', 'skwirrel-pim-wp-sync'));
        }

        $this->maybe_show_notices();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab parameter is display-only
        $active_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'sync';
        $allowed_tabs = ['sync', 'settings', 'logs'];
        if (!in_array($active_tab, $allowed_tabs, true)) {
            $active_tab = 'sync';
        }

        $base_url = admin_url('admin.php?page=' . self::PAGE_SLUG);

        ?>
        <?php $sync_in_progress = (bool) get_transient(self::SYNC_IN_PROGRESS); ?>
        <div class="wrap skwirrel-sync-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Skwirrel PIM Sync', 'skwirrel-pim-wp-sync'); ?></h1>
            <?php if ($sync_in_progress) : ?>
                <span class="page-title-action" style="opacity: 0.5; pointer-events: none; cursor: default;">⟳ <?php esc_html_e('Sync in progress…', 'skwirrel-pim-wp-sync'); ?></span>
            <?php else : ?>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=skwirrel_wc_sync_run'), 'skwirrel_wc_sync_run', '_wpnonce')); ?>" class="page-title-action"><?php esc_html_e('Sync Products', 'skwirrel-pim-wp-sync'); ?></a>
            <?php endif; ?>
            <hr class="wp-header-end">

            <nav class="nav-tab-wrapper">
                <a href="<?php echo esc_url(add_query_arg('tab', 'sync', $base_url)); ?>" class="nav-tab <?php echo $active_tab === 'sync' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Sync Products', 'skwirrel-pim-wp-sync'); ?></a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'settings', $base_url)); ?>" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Settings', 'skwirrel-pim-wp-sync'); ?></a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'logs', $base_url)); ?>" class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Logs', 'skwirrel-pim-wp-sync'); ?></a>
            </nav>

            <div class="skwirrel-tab-content">
                <?php
                switch ($active_tab) {
                    case 'settings':
                        $this->render_tab_settings();
                        break;
                    case 'logs':
                        $this->render_tab_logs();
                        break;
                    default:
                        $this->render_tab_sync();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    private function render_tab_sync(): void {
        $last_sync = Skwirrel_WC_Sync_Service::get_last_sync();
        $last_result = Skwirrel_WC_Sync_Service::get_last_result();
        $sync_history = Skwirrel_WC_Sync_Service::get_sync_history();
        $sync_in_progress = (bool) get_transient(self::SYNC_IN_PROGRESS);

        ?>
        <h2><?php esc_html_e('Sync status', 'skwirrel-pim-wp-sync'); ?></h2>

        <?php if ($sync_in_progress) : ?>
            <div style="background: #d1ecf1; border: 1px solid #bee5eb; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
                <h3 style="margin-top: 0; color: #0c5460;">
                    ⟳ <?php esc_html_e('Sync in progress…', 'skwirrel-pim-wp-sync'); ?>
                </h3>
                <p style="margin: 0; color: #0c5460;">
                    <?php esc_html_e('The page will refresh automatically when the sync is completed.', 'skwirrel-pim-wp-sync'); ?>
                </p>
            </div>
            <script>setTimeout(function(){ window.location.reload(); }, 5000);</script>
        <?php elseif ($last_result) : ?>
            <div style="background: <?php echo $last_result['success'] ? '#d4edda' : '#f8d7da'; ?>; border: 1px solid <?php echo $last_result['success'] ? '#c3e6cb' : '#f5c6cb'; ?>; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
                <h3 style="margin-top: 0; color: <?php echo $last_result['success'] ? '#155724' : '#721c24'; ?>;">
                    <?php if ($last_result['success']) : ?>
                        ✓ <?php esc_html_e('Last sync successful', 'skwirrel-pim-wp-sync'); ?>
                    <?php else : ?>
                        ✗ <?php esc_html_e('Last sync failed', 'skwirrel-pim-wp-sync'); ?>
                    <?php endif; ?>
                </h3>
                <p style="margin: 0; color: <?php echo $last_result['success'] ? '#155724' : '#721c24'; ?>;">
                    <?php echo $last_sync ? esc_html($this->format_datetime($last_sync)) : esc_html__('Unknown', 'skwirrel-pim-wp-sync'); ?>
                </p>
            </div>

            <h3><?php esc_html_e('Sync results', 'skwirrel-pim-wp-sync'); ?></h3>
            <table class="widefat" style="margin-bottom: 20px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Category', 'skwirrel-pim-wp-sync'); ?></th>
                        <th style="text-align: right;"><?php esc_html_e('Count', 'skwirrel-pim-wp-sync'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong><?php esc_html_e('Created', 'skwirrel-pim-wp-sync'); ?></strong></td>
                        <td style="text-align: right;"><span style="color: #00a32a; font-weight: bold; font-size: 16px;"><?php echo (int) ($last_result['created'] ?? 0); ?></span></td>
                    </tr>
                    <tr style="background-color: #f9f9f9;">
                        <td><strong><?php esc_html_e('Updated', 'skwirrel-pim-wp-sync'); ?></strong></td>
                        <td style="text-align: right;"><span style="color: #007cba; font-weight: bold; font-size: 16px;"><?php echo (int) ($last_result['updated'] ?? 0); ?></span></td>
                    </tr>
                    <?php $failed_count = (int) ($last_result['failed'] ?? 0); ?>
                    <tr style="<?php echo $failed_count > 0 ? 'background-color: #f8d7da;' : ''; ?>">
                        <td><strong><?php esc_html_e('Failed', 'skwirrel-pim-wp-sync'); ?></strong></td>
                        <td style="text-align: right;"><span style="color: #d63638; font-weight: bold; font-size: 16px;"><?php echo esc_html((string) $failed_count); ?></span></td>
                    </tr>
                    <?php
                    $trashed_count = (int) ($last_result['trashed'] ?? 0);
                    $cats_removed = (int) ($last_result['categories_removed'] ?? 0);
                    if ($trashed_count > 0 || $cats_removed > 0) :
                    ?>
                    <tr style="background-color: #fff3cd;">
                        <td><strong><?php esc_html_e('Deleted (trash)', 'skwirrel-pim-wp-sync'); ?></strong></td>
                        <td style="text-align: right;"><span style="color: #856404; font-weight: bold; font-size: 16px;"><?php echo esc_html((string)$trashed_count); ?></span></td>
                    </tr>
                    <?php if ($cats_removed > 0) : ?>
                    <tr style="background-color: #fff3cd;">
                        <td style="padding-left: 20px;"><?php esc_html_e('↳ Categories cleaned up', 'skwirrel-pim-wp-sync'); ?></td>
                        <td style="text-align: right;"><span style="color: #856404;"><?php echo esc_html((string)$cats_removed); ?></span></td>
                    </tr>
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php
                    $with_a = (int) ($last_result['with_attributes'] ?? 0);
                    $without_a = (int) ($last_result['without_attributes'] ?? 0);
                    if ($with_a + $without_a > 0) :
                    ?>
                    <tr style="background-color: #f9f9f9;">
                        <td style="padding-left: 20px;"><?php esc_html_e('↳ With attributes', 'skwirrel-pim-wp-sync'); ?></td>
                        <td style="text-align: right;"><?php echo esc_html((string)$with_a); ?></td>
                    </tr>
                    <tr>
                        <td style="padding-left: 20px;"><?php esc_html_e('↳ Without attributes', 'skwirrel-pim-wp-sync'); ?></td>
                        <td style="text-align: right;"><?php echo esc_html((string)$without_a); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr style="background-color: #e8f5e9; border-top: 2px solid #4caf50;">
                        <td><strong><?php esc_html_e('Total processed', 'skwirrel-pim-wp-sync'); ?></strong></td>
                        <td style="text-align: right;"><strong style="font-size: 16px;"><?php echo (int) ($last_result['created'] ?? 0) + (int) ($last_result['updated'] ?? 0) + (int) ($last_result['failed'] ?? 0); ?></strong></td>
                    </tr>
                </tbody>
            </table>

            <?php if (!$last_result['success'] && !empty($last_result['error'])) : ?>
                <div style="background: #fff3cd; border: 1px solid #ffc107; padding: 10px; border-radius: 4px; margin-top: 15px;">
                    <strong><?php esc_html_e('Error message:', 'skwirrel-pim-wp-sync'); ?></strong><br>
                    <?php echo esc_html($last_result['error']); ?>
                </div>
            <?php endif; ?>
        <?php else : ?>
            <div style="background: #f0f0f0; border: 1px solid #ccc; padding: 15px; border-radius: 4px; text-align: center;">
                <p style="margin: 0;"><?php esc_html_e('No sync has been run yet. Click "Sync Products" to get started.', 'skwirrel-pim-wp-sync'); ?></p>
            </div>
        <?php endif; ?>

        <?php if (!empty($sync_history)) : ?>
            <h2 style="margin-top: 30px;"><?php esc_html_e('Sync history', 'skwirrel-pim-wp-sync'); ?></h2>
            <table class="widefat striped" style="margin-top: 10px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Date & Time', 'skwirrel-pim-wp-sync'); ?></th>
                        <th><?php esc_html_e('Status', 'skwirrel-pim-wp-sync'); ?></th>
                        <th style="text-align: right;"><?php esc_html_e('Created', 'skwirrel-pim-wp-sync'); ?></th>
                        <th style="text-align: right;"><?php esc_html_e('Updated', 'skwirrel-pim-wp-sync'); ?></th>
                        <th style="text-align: right;"><?php esc_html_e('Failed', 'skwirrel-pim-wp-sync'); ?></th>
                        <th style="text-align: right;"><?php esc_html_e('Deleted', 'skwirrel-pim-wp-sync'); ?></th>
                        <th style="text-align: right;"><?php esc_html_e('With attr.', 'skwirrel-pim-wp-sync'); ?></th>
                        <th style="text-align: right;"><?php esc_html_e('Without attr.', 'skwirrel-pim-wp-sync'); ?></th>
                        <th style="text-align: right;"><?php esc_html_e('Total', 'skwirrel-pim-wp-sync'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sync_history as $entry) : ?>
                        <?php
                        $is_success = !empty($entry['success']);
                        $created = (int) ($entry['created'] ?? 0);
                        $updated = (int) ($entry['updated'] ?? 0);
                        $failed = (int) ($entry['failed'] ?? 0);
                        $trashed_h = (int) ($entry['trashed'] ?? 0);
                        $with_attrs = (int) ($entry['with_attributes'] ?? 0);
                        $without_attrs = (int) ($entry['without_attributes'] ?? 0);
                        $total = $created + $updated + $failed;
                        $timestamp = !empty($entry['timestamp']) ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $entry['timestamp']) : '-';
                        ?>
                        <tr>
                            <td><?php echo esc_html($timestamp); ?></td>
                            <td>
                                <?php if ($is_success) : ?>
                                    <span style="color: #00a32a; font-weight: bold;">✓ <?php esc_html_e('Successful', 'skwirrel-pim-wp-sync'); ?></span>
                                <?php else : ?>
                                    <span style="color: #d63638; font-weight: bold;">✗ <?php esc_html_e('Failed', 'skwirrel-pim-wp-sync'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right;"><span style="color: #00a32a;"><?php echo esc_html((string)$created); ?></span></td>
                            <td style="text-align: right;"><span style="color: #007cba;"><?php echo esc_html((string)$updated); ?></span></td>
                            <td style="text-align: right;"><span style="color: #d63638;"><?php echo esc_html((string)$failed); ?></span></td>
                            <td style="text-align: right;"><span style="color: #856404;"><?php echo esc_html((string)$trashed_h); ?></span></td>
                            <td style="text-align: right;"><?php echo esc_html((string)$with_attrs); ?></td>
                            <td style="text-align: right;"><?php echo esc_html((string)$without_attrs); ?></td>
                            <td style="text-align: right;"><strong><?php echo esc_html((string)$total); ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }

    private function render_tab_settings(): void {
        $opts = get_option(self::OPTION_KEY, []);
        $token_masked = self::get_auth_token() !== '' ? self::MASK : '';

        ?>
        <form method="post" action="options.php" id="skwirrel-sync-settings-form">
            <?php wp_nonce_field('options-options'); ?>
            <?php settings_fields('skwirrel_wc_sync'); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="endpoint_url"><?php esc_html_e('JSON-RPC Endpoint URL', 'skwirrel-pim-wp-sync'); ?></label></th>
                    <td>
                        <input type="url" id="endpoint_url" name="<?php echo esc_attr(self::OPTION_KEY); ?>[endpoint_url]" value="<?php echo esc_attr($opts['endpoint_url'] ?? ''); ?>" class="regular-text" placeholder="https://xxx.skwirrel.eu/jsonrpc" required />
                        <p class="description"><?php esc_html_e('Full URL to the Skwirrel JSON-RPC endpoint.', 'skwirrel-pim-wp-sync'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="auth_type"><?php esc_html_e('Authentication type', 'skwirrel-pim-wp-sync'); ?></label></th>
                    <td>
                        <select id="auth_type" name="<?php echo esc_attr(self::OPTION_KEY); ?>[auth_type]">
                            <option value="bearer" <?php selected($opts['auth_type'] ?? 'bearer', 'bearer'); ?>><?php esc_html_e('Bearer token', 'skwirrel-pim-wp-sync'); ?></option>
                            <option value="token" <?php selected($opts['auth_type'] ?? '', 'token'); ?>><?php esc_html_e('API static token (X-Skwirrel-Api-Token)', 'skwirrel-pim-wp-sync'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="auth_token"><?php esc_html_e('Token', 'skwirrel-pim-wp-sync'); ?></label></th>
                    <td>
                        <input type="password" id="auth_token" name="<?php echo esc_attr(self::OPTION_KEY); ?>[auth_token]" value="<?php echo esc_attr($token_masked); ?>" class="regular-text" autocomplete="off" placeholder="<?php echo esc_attr($token_masked ?: __('Enter token…', 'skwirrel-pim-wp-sync')); ?>" />
                        <p class="description"><?php esc_html_e('After saving, the token will not be shown in plain text.', 'skwirrel-pim-wp-sync'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="timeout"><?php esc_html_e('Timeout (seconds)', 'skwirrel-pim-wp-sync'); ?></label></th>
                    <td>
                        <input type="number" id="timeout" name="<?php echo esc_attr(self::OPTION_KEY); ?>[timeout]" value="<?php echo esc_attr((string) ($opts['timeout'] ?? 30)); ?>" min="5" max="120" />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="retries"><?php esc_html_e('Number of retries', 'skwirrel-pim-wp-sync'); ?></label></th>
                    <td>
                        <input type="number" id="retries" name="<?php echo esc_attr(self::OPTION_KEY); ?>[retries]" value="<?php echo esc_attr((string) ($opts['retries'] ?? 2)); ?>" min="0" max="5" />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="sync_interval"><?php esc_html_e('Sync interval', 'skwirrel-pim-wp-sync'); ?></label></th>
                    <td>
                        <select id="sync_interval" name="<?php echo esc_attr(self::OPTION_KEY); ?>[sync_interval]">
                            <?php foreach (Skwirrel_WC_Sync_Action_Scheduler::get_interval_options() as $k => $v) : ?>
                                <option value="<?php echo esc_attr($k); ?>" <?php selected($opts['sync_interval'] ?? '', $k); ?>><?php echo esc_html($v); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="batch_size"><?php esc_html_e('Batch size', 'skwirrel-pim-wp-sync'); ?></label></th>
                    <td>
                        <input type="number" id="batch_size" name="<?php echo esc_attr(self::OPTION_KEY); ?>[batch_size]" value="<?php echo esc_attr((string) ($opts['batch_size'] ?? 100)); ?>" min="10" max="500" />
                        <p class="description"><?php esc_html_e('Products per API page (1–500).', 'skwirrel-pim-wp-sync'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Sync categories', 'skwirrel-pim-wp-sync'); ?></th>
                    <td>
                        <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[sync_categories]" value="1" <?php checked(!empty($opts['sync_categories'])); ?> /> <?php esc_html_e('Create and assign categories from product_groups', 'skwirrel-pim-wp-sync'); ?></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="super_category_id"><?php esc_html_e('Super category ID', 'skwirrel-pim-wp-sync'); ?></label></th>
                    <td>
                        <input type="text" id="super_category_id" name="<?php echo esc_attr(self::OPTION_KEY); ?>[super_category_id]" value="<?php echo esc_attr($opts['super_category_id'] ?? ''); ?>" class="small-text" placeholder="<?php esc_attr_e('e.g. 42', 'skwirrel-pim-wp-sync'); ?>" />
                        <p class="description"><?php esc_html_e('Skwirrel super category ID. The entire category tree under this category will be synced to WooCommerce.', 'skwirrel-pim-wp-sync'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Sync grouped products', 'skwirrel-pim-wp-sync'); ?></th>
                    <td>
                        <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[sync_grouped_products]" value="1" <?php checked(!empty($opts['sync_grouped_products'])); ?> /> <?php esc_html_e('Fetch grouped products via getGroupedProducts (products within a group can be variable)', 'skwirrel-pim-wp-sync'); ?></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Sync custom classes', 'skwirrel-pim-wp-sync'); ?></th>
                    <td>
                        <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[sync_custom_classes]" value="1" <?php checked(!empty($opts['sync_custom_classes'])); ?> /> <?php esc_html_e('Fetch custom class attributes and save as product attributes', 'skwirrel-pim-wp-sync'); ?></label>
                        <br />
                        <label style="margin-top:4px;display:inline-block;"><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[sync_trade_item_custom_classes]" value="1" <?php checked(!empty($opts['sync_trade_item_custom_classes'])); ?> /> <?php esc_html_e('Also include trade item custom classes', 'skwirrel-pim-wp-sync'); ?></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="custom_class_filter_mode"><?php esc_html_e('Custom class filter', 'skwirrel-pim-wp-sync'); ?></label></th>
                    <td>
                        <?php $cc_mode = $opts['custom_class_filter_mode'] ?? ''; ?>
                        <select id="custom_class_filter_mode" name="<?php echo esc_attr(self::OPTION_KEY); ?>[custom_class_filter_mode]">
                            <option value="" <?php selected($cc_mode, ''); ?>><?php esc_html_e('No filter (all classes)', 'skwirrel-pim-wp-sync'); ?></option>
                            <option value="whitelist" <?php selected($cc_mode, 'whitelist'); ?>><?php esc_html_e('Whitelist (only these classes)', 'skwirrel-pim-wp-sync'); ?></option>
                            <option value="blacklist" <?php selected($cc_mode, 'blacklist'); ?>><?php esc_html_e('Blacklist (all except these classes)', 'skwirrel-pim-wp-sync'); ?></option>
                        </select>
                        <br />
                        <input type="text" id="custom_class_filter_ids" name="<?php echo esc_attr(self::OPTION_KEY); ?>[custom_class_filter_ids]" value="<?php echo esc_attr($opts['custom_class_filter_ids'] ?? ''); ?>" class="regular-text" placeholder="<?php esc_attr_e('e.g. 12, 45, BUIS', 'skwirrel-pim-wp-sync'); ?>" style="margin-top:6px;" />
                        <p class="description"><?php esc_html_e('Comma-separated class IDs or codes. Numeric values are used as ID, others as class code.', 'skwirrel-pim-wp-sync'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="collection_ids"><?php esc_html_e('Collection IDs (filter)', 'skwirrel-pim-wp-sync'); ?></label></th>
                    <td>
                        <input type="text" id="collection_ids" name="<?php echo esc_attr(self::OPTION_KEY); ?>[collection_ids]" value="<?php echo esc_attr($opts['collection_ids'] ?? ''); ?>" class="regular-text" placeholder="<?php esc_attr_e('e.g. 123, 456', 'skwirrel-pim-wp-sync'); ?>" />
                        <p class="description"><?php esc_html_e('Comma-separated collection IDs. Only products from these collections will be synced. Empty = sync all.', 'skwirrel-pim-wp-sync'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="sync_images"><?php esc_html_e('Import images', 'skwirrel-pim-wp-sync'); ?></label></th>
                    <td>
                        <select id="sync_images" name="<?php echo esc_attr(self::OPTION_KEY); ?>[sync_images]">
                            <option value="yes" <?php selected(($opts['sync_images'] ?? true), true); ?>><?php esc_html_e('Yes, to media library', 'skwirrel-pim-wp-sync'); ?></option>
                            <option value="no" <?php selected(($opts['sync_images'] ?? true), false); ?>><?php esc_html_e('No, skip', 'skwirrel-pim-wp-sync'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('If media library import fails (e.g. due to security plugin), choose "No" to skip images.', 'skwirrel-pim-wp-sync'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="image_language_select"><?php esc_html_e('Content language', 'skwirrel-pim-wp-sync'); ?></label></th>
                    <td>
                        <?php
                        $current_lang = $opts['image_language'] ?? 'nl';
                        $is_custom = !isset(self::LANGUAGE_OPTIONS[$current_lang]);
                        ?>
                        <select id="image_language_select" name="<?php echo esc_attr(self::OPTION_KEY); ?>[image_language_select]" onchange="var c=document.getElementById('image_language_custom_wrap');c.style.display=this.value==='_custom'?'inline-block':'none';if(this.value!=='_custom')document.getElementById('image_language_custom').value='';">
                            <?php foreach (self::LANGUAGE_OPTIONS as $code => $label) : ?>
                                <option value="<?php echo esc_attr($code); ?>" <?php selected($current_lang, $code); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                            <option value="_custom" <?php selected($is_custom); ?>><?php esc_html_e('Other…', 'skwirrel-pim-wp-sync'); ?></option>
                        </select>
                        <span id="image_language_custom_wrap" style="display:<?php echo $is_custom ? 'inline-block' : 'none'; ?>;">
                            <input type="text" id="image_language_custom" name="<?php echo esc_attr(self::OPTION_KEY); ?>[image_language_custom]" value="<?php echo esc_attr($is_custom ? $current_lang : ''); ?>" size="6" pattern="[a-z]{2}(-[A-Z]{2})?" placeholder="e.g. es-ES" />
                        </span>
                        <p class="description"><?php esc_html_e('Language code for all text: image alt/caption, ETIM attributes.', 'skwirrel-pim-wp-sync'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('API languages (include_languages)', 'skwirrel-pim-wp-sync'); ?></th>
                    <td>
                        <?php
                        $saved_langs = !empty($opts['include_languages']) && is_array($opts['include_languages'])
                            ? $opts['include_languages']
                            : ['nl-NL', 'nl'];
                        $known_codes = array_keys(self::LANGUAGE_OPTIONS);
                        $custom_langs = array_diff($saved_langs, $known_codes);
                        ?>
                        <fieldset>
                            <?php foreach (self::LANGUAGE_OPTIONS as $code => $label) : ?>
                                <label style="display:block;margin-bottom:4px;">
                                    <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[include_languages_checkboxes][]" value="<?php echo esc_attr($code); ?>" <?php checked(in_array($code, $saved_langs, true)); ?> />
                                    <?php echo esc_html($label); ?>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
                        <p style="margin-top:8px;">
                            <label for="include_languages_custom"><?php esc_html_e('Additional language codes (comma-separated):', 'skwirrel-pim-wp-sync'); ?></label><br />
                            <input type="text" id="include_languages_custom" name="<?php echo esc_attr(self::OPTION_KEY); ?>[include_languages_custom]" value="<?php echo esc_attr(implode(', ', $custom_langs)); ?>" class="regular-text" placeholder="e.g. es, pt-BR" />
                        </p>
                        <p class="description"><?php esc_html_e('Select the languages the API should include for product translations and ETIM.', 'skwirrel-pim-wp-sync'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="verbose_logging"><?php esc_html_e('Verbose logging', 'skwirrel-pim-wp-sync'); ?></label></th>
                    <td>
                        <label><input type="checkbox" id="verbose_logging" name="<?php echo esc_attr(self::OPTION_KEY); ?>[verbose_logging]" value="1" <?php checked(!empty($opts['verbose_logging'])); ?> /> <?php esc_html_e('Log per product: sources, ETIM, attributes (see WooCommerce → Status → Logs)', 'skwirrel-pim-wp-sync'); ?></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Clean up deleted products', 'skwirrel-pim-wp-sync'); ?></th>
                    <td>
                        <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[purge_stale_products]" value="1" <?php checked(!empty($opts['purge_stale_products'])); ?> /> <?php esc_html_e('Automatically trash products and categories no longer in Skwirrel during full sync', 'skwirrel-pim-wp-sync'); ?></label>
                        <p class="description"><?php esc_html_e('Only active during full sync (manual sync or after deletion in WooCommerce). Not active during delta sync or when collection filter is set.', 'skwirrel-pim-wp-sync'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Show delete warning', 'skwirrel-pim-wp-sync'); ?></th>
                    <td>
                        <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[show_delete_warning]" value="1" <?php checked($opts['show_delete_warning'] ?? true); ?> /> <?php esc_html_e('Show warning when deleting Skwirrel products and categories in WooCommerce', 'skwirrel-pim-wp-sync'); ?></label>
                        <p class="description"><?php esc_html_e('Skwirrel is the source of truth: deleted products will be recreated during the next sync. This warning reminds users of this.', 'skwirrel-pim-wp-sync'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="use_sku_field"><?php esc_html_e('SKU field', 'skwirrel-pim-wp-sync'); ?></label></th>
                    <td>
                        <select id="use_sku_field" name="<?php echo esc_attr(self::OPTION_KEY); ?>[use_sku_field]">
                            <option value="internal_product_code" <?php selected($opts['use_sku_field'] ?? 'internal_product_code', 'internal_product_code'); ?>><?php esc_html_e('internal_product_code', 'skwirrel-pim-wp-sync'); ?></option>
                            <option value="manufacturer_product_code" <?php selected($opts['use_sku_field'] ?? '', 'manufacturer_product_code'); ?>><?php esc_html_e('manufacturer_product_code', 'skwirrel-pim-wp-sync'); ?></option>
                        </select>
                    </td>
                </tr>
            </table>

            <?php submit_button(__('Save settings', 'skwirrel-pim-wp-sync')); ?>
        </form>

        <p>
            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=skwirrel_wc_sync_test'), 'skwirrel_wc_sync_test', '_wpnonce')); ?>" class="button"><?php esc_html_e('Test connection', 'skwirrel-pim-wp-sync'); ?></a>
        </p>

        <div class="skwirrel-danger-zone">
            <h2><?php esc_html_e('Danger zone', 'skwirrel-pim-wp-sync'); ?></h2>
            <p><?php esc_html_e('Delete all products created or synced by Skwirrel. This action cannot be undone if you empty the trash.', 'skwirrel-pim-wp-sync'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="skwirrel-purge-form">
                <input type="hidden" name="action" value="skwirrel_wc_sync_purge" />
                <?php wp_nonce_field('skwirrel_wc_sync_purge', '_wpnonce'); ?>
                <p>
                    <label>
                        <input type="checkbox" name="skwirrel_purge_empty_trash" value="1" id="skwirrel-purge-permanent" />
                        <?php esc_html_e('Also empty the trash (permanently delete)', 'skwirrel-pim-wp-sync'); ?>
                    </label>
                </p>
                <p>
                    <?php submit_button(
                        __('Delete all Skwirrel products', 'skwirrel-pim-wp-sync'),
                        'delete skwirrel-danger-button',
                        'submit',
                        false
                    ); ?>
                </p>
            </form>
        </div>
        <script>
        (function() {
            var form = document.getElementById('skwirrel-purge-form');
            if (!form) return;
            form.addEventListener('submit', function(e) {
                var permanent = document.getElementById('skwirrel-purge-permanent').checked;
                var msg = permanent
                    ? <?php echo wp_json_encode(__('WARNING: All Skwirrel products will be PERMANENTLY deleted. This cannot be undone!\n\nAre you sure?', 'skwirrel-pim-wp-sync')); ?>
                    : <?php echo wp_json_encode(__('All Skwirrel products will be moved to the trash.\n\nAre you sure?', 'skwirrel-pim-wp-sync')); ?>;
                if (!confirm(msg)) {
                    e.preventDefault();
                }
            });
        })();
        </script>
        <?php
    }

    private function render_tab_logs(): void {
        $logger = new Skwirrel_WC_Sync_Logger();
        $log_url = $logger->get_log_file_url();

        ?>
        <h2><?php esc_html_e('View logs', 'skwirrel-pim-wp-sync'); ?></h2>
        <?php if ($log_url) : ?>
            <p>
                <a href="<?php echo esc_url($log_url); ?>" class="button" target="_blank"><?php esc_html_e('View logs', 'skwirrel-pim-wp-sync'); ?></a>
            </p>
        <?php else : ?>
            <p><?php esc_html_e('No log file available.', 'skwirrel-pim-wp-sync'); ?></p>
        <?php endif; ?>

        <h2><?php esc_html_e('Debug variation attributes', 'skwirrel-pim-wp-sync'); ?></h2>
        <p><?php esc_html_e('If variations show "Any Colour" / "Any Number of cups" instead of real values:', 'skwirrel-pim-wp-sync'); ?></p>
        <ol style="list-style: decimal; margin-left: 1.5em;">
            <li><?php esc_html_e('Add to wp-config.php: define(\'SKWIRREL_WC_SYNC_DEBUG_ETIM\', true);', 'skwirrel-pim-wp-sync'); ?></li>
            <li><?php esc_html_e('Run "Sync Products".', 'skwirrel-pim-wp-sync'); ?></li>
            <li><?php esc_html_e('Check wp-content/uploads/skwirrel-variation-debug.log', 'skwirrel-pim-wp-sync'); ?></li>
            <li><?php esc_html_e('Check: etim_values_found empty? → API languages must match getProducts (e.g. en, en-GB).', 'skwirrel-pim-wp-sync'); ?></li>
            <li><?php esc_html_e('ATTR VERIFY FAIL in the log? → meta is not being written correctly; check wp_postmeta for attribute_pa_color/attribute_pa_cups.', 'skwirrel-pim-wp-sync'); ?></li>
        </ol>
        <?php
    }

    private function maybe_show_notices(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only redirect parameters
        if (isset($_GET['test'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ($_GET['test'] === 'ok') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Connection test successful.', 'skwirrel-pim-wp-sync') . '</p></div>';
            } else {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only redirect parameter
                $msg = isset($_GET['message']) ? sanitize_text_field(wp_unslash($_GET['message'])) : __('Connection failed.', 'skwirrel-pim-wp-sync');
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($msg) . '</p></div>';
            }
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only redirect parameter
        if (isset($_GET['sync']) && $_GET['sync'] === 'queued') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Sync started in the background. Results will appear here once the sync is completed. Refresh the page to check the status.', 'skwirrel-pim-wp-sync') . '</p></div>';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only redirect parameter
        if (isset($_GET['purge']) && $_GET['purge'] === 'queued') {
            echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('Purge started in the background. All Skwirrel products, imported media, categories and attributes will be deleted. Refresh the page to check the status.', 'skwirrel-pim-wp-sync') . '</p></div>';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only redirect parameter
        if (isset($_GET['sync']) && $_GET['sync'] === 'done') {
            $last = Skwirrel_WC_Sync_Service::get_last_result();
            if ($last && $last['success']) {
                $with_a = (int) ($last['with_attributes'] ?? 0);
                $without_a = (int) ($last['without_attributes'] ?? 0);
                $msg = sprintf(
                    /* translators: %1$d = created count, %2$d = updated count, %3$d = failed count */
                    esc_html__('Sync completed. Created: %1$d, Updated: %2$d, Failed: %3$d', 'skwirrel-pim-wp-sync'),
                    (int) $last['created'],
                    (int) $last['updated'],
                    (int) $last['failed']
                );
                if ($with_a + $without_a > 0) {
                    $msg .= ' ' . sprintf(
                        /* translators: %1$d = count with attributes, %2$d = count without attributes */
                        esc_html__('(with attributes: %1$d, without: %2$d)', 'skwirrel-pim-wp-sync'),
                        $with_a,
                        $without_a
                    );
                }
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
            } else {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Sync completed. Check the logs for details.', 'skwirrel-pim-wp-sync') . '</p></div>';
            }
        }
    }

    private function format_datetime(string $s): string {
        $ts = strtotime($s);
        return $ts ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $ts) : $s;
    }
}
