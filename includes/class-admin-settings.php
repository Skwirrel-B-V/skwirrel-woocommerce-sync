<?php
/**
 * Skwirrel Sync - Admin Settings UI.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Skwirrel_WC_Sync_Admin_Settings {

    private const PAGE_SLUG = 'skwirrel-wc-sync';
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

    private function __construct() {
        add_action('admin_menu', [$this, 'add_menu'], 99);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_skwirrel_wc_sync_test', [$this, 'handle_test_connection']);
        add_action('admin_post_skwirrel_wc_sync_run', [$this, 'handle_sync_now']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_' . self::BG_SYNC_ACTION, [$this, 'handle_background_sync']);
        add_action('wp_ajax_nopriv_' . self::BG_SYNC_ACTION, [$this, 'handle_background_sync']);
        add_action('wp_ajax_skwirrel_wc_sync_progress', [$this, 'handle_progress_ajax']);
        add_action('wp_ajax_skwirrel_wc_sync_download_log', [$this, 'handle_log_download']);
        add_action('add_meta_boxes', [$this, 'add_sync_protection_meta_box']);
        add_action('save_post_product', [$this, 'save_sync_protection_meta'], 10, 2);
    }

    public function add_menu(): void {
        add_submenu_page(
            'woocommerce',
            __('Skwirrel Sync', 'skwirrel-wc-sync'),
            __('Skwirrel Sync', 'skwirrel-wc-sync'),
            'manage_woocommerce',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
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
        $out['verbose_logging'] = !empty($input['verbose_logging']);
        // Stock management
        $out['stock_management'] = in_array($input['stock_management'] ?? 'off', ['off', 'on'], true) ? $input['stock_management'] : 'off';
        // Orphan action
        $out['orphan_action'] = in_array($input['orphan_action'] ?? 'nothing', ['nothing', 'draft', 'trash'], true) ? $input['orphan_action'] : 'nothing';
        // Tax class
        $out['default_tax_class'] = sanitize_text_field($input['default_tax_class'] ?? '');
        // Shipping class
        $out['default_shipping_class'] = sanitize_text_field($input['default_shipping_class'] ?? '');
        // Brand as taxonomy
        $out['brand_as_taxonomy'] = !empty($input['brand_as_taxonomy']);
        // Webhook secret
        $out['webhook_secret'] = sanitize_text_field($input['webhook_secret'] ?? '');
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
            wp_die(esc_html__('Geen toegang.', 'skwirrel-wc-sync'));
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
            'test' => $result['success'] ? 'ok' : 'fail',
            'message' => $result['success'] ? '' : urlencode($result['error']['message'] ?? 'Unknown error'),
        ], admin_url('admin.php'));

        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_sync_now(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Geen toegang.', 'skwirrel-wc-sync'));
        }
        check_admin_referer('skwirrel_wc_sync_run', '_wpnonce');

        $token = bin2hex(random_bytes(16));
        set_transient(self::BG_SYNC_TRANSIENT . '_' . $token, '1', 120);

        $url = add_query_arg([
            'action' => self::BG_SYNC_ACTION,
            'token' => $token,
        ], admin_url('admin-ajax.php'));

        $redirect = add_query_arg([
            'page' => self::PAGE_SLUG,
            'sync' => 'queued',
        ], admin_url('admin.php'));

        wp_safe_redirect($redirect);

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        wp_remote_post($url, [
            'blocking' => false,
            'timeout' => 0.01,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        ]);

        exit;
    }

    public function handle_background_sync(): void {
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

        wp_die('', 200);
    }

    /**
     * AJAX handler for sync progress polling.
     */
    public function handle_progress_ajax(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('No access', 403);
        }
        $running = Skwirrel_WC_Sync_Service::is_sync_running();
        $progress = Skwirrel_WC_Sync_Service::get_sync_progress();
        wp_send_json_success([
            'running' => $running,
            'progress' => $progress,
        ]);
    }

    /**
     * AJAX handler for log file download.
     */
    public function handle_log_download(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Geen toegang.', 'skwirrel-wc-sync'));
        }
        check_admin_referer('skwirrel_wc_sync_download_log');

        $log_dir = WC_LOG_DIR ?? WP_CONTENT_DIR . '/wc-logs/';
        $files = glob($log_dir . 'skwirrel-wc-sync*.log');
        if (empty($files)) {
            wp_die(esc_html__('Geen logbestanden gevonden.', 'skwirrel-wc-sync'));
        }

        // Get the most recent log file
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        $file = $files[0];

        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="skwirrel-wc-sync-' . date('Y-m-d') . '.log"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }

    /**
     * Add sync protection meta box to product edit screen.
     */
    public function add_sync_protection_meta_box(): void {
        add_meta_box(
            'skwirrel_sync_protection',
            __('Skwirrel Sync', 'skwirrel-wc-sync'),
            [$this, 'render_sync_protection_meta_box'],
            'product',
            'side',
            'default'
        );
    }

    /**
     * Render sync protection meta box.
     */
    public function render_sync_protection_meta_box(WP_Post $post): void {
        $protected = get_post_meta($post->ID, '_skwirrel_sync_protected', true);
        $synced_at = get_post_meta($post->ID, '_skwirrel_synced_at', true);
        wp_nonce_field('skwirrel_sync_protection', '_skwirrel_sync_nonce');
        ?>
        <p>
            <label>
                <input type="checkbox" name="_skwirrel_sync_protected" value="1" <?php checked($protected); ?> />
                <?php esc_html_e('Bescherm tegen overschrijving', 'skwirrel-wc-sync'); ?>
            </label>
        </p>
        <?php if ($synced_at) : ?>
            <p class="description">
                <?php echo esc_html__('Laatste sync:', 'skwirrel-wc-sync') . ' ' . esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $synced_at)); ?>
            </p>
        <?php endif; ?>
        <?php
    }

    /**
     * Save sync protection checkbox.
     */
    public function save_sync_protection_meta(int $post_id, WP_Post $post): void {
        if (!isset($_POST['_skwirrel_sync_nonce']) || !wp_verify_nonce($_POST['_skwirrel_sync_nonce'], 'skwirrel_sync_protection')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        $protected = !empty($_POST['_skwirrel_sync_protected']);
        update_post_meta($post_id, '_skwirrel_sync_protected', $protected ? '1' : '');
    }

    public function enqueue_assets(string $hook): void {
        if (strpos($hook, self::PAGE_SLUG) === false) {
            return;
        }
        wp_enqueue_style('skwirrel-admin', SKWIRREL_WC_SYNC_PLUGIN_URL . 'assets/admin.css', [], SKWIRREL_WC_SYNC_VERSION);
    }

    public function render_page(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Geen toegang.', 'skwirrel-wc-sync'));
        }

        $opts = get_option(self::OPTION_KEY, []);
        $token_masked = self::get_auth_token() !== '' ? self::MASK : '';
        $last_sync = Skwirrel_WC_Sync_Service::get_last_sync();
        $last_result = Skwirrel_WC_Sync_Service::get_last_result();
        $sync_history = Skwirrel_WC_Sync_Service::get_sync_history();
        $logger = new Skwirrel_WC_Sync_Logger();
        $log_url = $logger->get_log_file_url();

        $this->maybe_show_notices();

        ?>
        <div class="wrap skwirrel-sync-wrap">
            <h1><?php esc_html_e('Skwirrel WooCommerce Sync', 'skwirrel-wc-sync'); ?></h1>

            <form method="post" action="options.php" id="skwirrel-sync-settings-form">
                <?php wp_nonce_field('options-options'); ?>
                <?php settings_fields('skwirrel_wc_sync'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="endpoint_url"><?php esc_html_e('JSON-RPC Endpoint URL', 'skwirrel-wc-sync'); ?></label></th>
                        <td>
                            <input type="url" id="endpoint_url" name="<?php echo esc_attr(self::OPTION_KEY); ?>[endpoint_url]" value="<?php echo esc_attr($opts['endpoint_url'] ?? ''); ?>" class="regular-text" placeholder="https://xxx.skwirrel.eu/jsonrpc" required />
                            <p class="description"><?php esc_html_e('Volledige URL naar het Skwirrel JSON-RPC endpoint.', 'skwirrel-wc-sync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="auth_type"><?php esc_html_e('Authenticatie type', 'skwirrel-wc-sync'); ?></label></th>
                        <td>
                            <select id="auth_type" name="<?php echo esc_attr(self::OPTION_KEY); ?>[auth_type]">
                                <option value="bearer" <?php selected($opts['auth_type'] ?? 'bearer', 'bearer'); ?>><?php esc_html_e('Bearer token', 'skwirrel-wc-sync'); ?></option>
                                <option value="token" <?php selected($opts['auth_type'] ?? '', 'token'); ?>><?php esc_html_e('API static token (X-Skwirrel-Api-Token)', 'skwirrel-wc-sync'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="auth_token"><?php esc_html_e('Token', 'skwirrel-wc-sync'); ?></label></th>
                        <td>
                            <input type="password" id="auth_token" name="<?php echo esc_attr(self::OPTION_KEY); ?>[auth_token]" value="<?php echo esc_attr($token_masked); ?>" class="regular-text" autocomplete="off" placeholder="<?php echo esc_attr($token_masked ?: __('Token invoeren…', 'skwirrel-wc-sync')); ?>" />
                            <p class="description"><?php esc_html_e('Na opslaan wordt de token niet in platte tekst getoond.', 'skwirrel-wc-sync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="timeout"><?php esc_html_e('Timeout (seconden)', 'skwirrel-wc-sync'); ?></label></th>
                        <td>
                            <input type="number" id="timeout" name="<?php echo esc_attr(self::OPTION_KEY); ?>[timeout]" value="<?php echo esc_attr((string) ($opts['timeout'] ?? 30)); ?>" min="5" max="120" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="retries"><?php esc_html_e('Aantal retries', 'skwirrel-wc-sync'); ?></label></th>
                        <td>
                            <input type="number" id="retries" name="<?php echo esc_attr(self::OPTION_KEY); ?>[retries]" value="<?php echo esc_attr((string) ($opts['retries'] ?? 2)); ?>" min="0" max="5" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sync_interval"><?php esc_html_e('Sync interval', 'skwirrel-wc-sync'); ?></label></th>
                        <td>
                            <select id="sync_interval" name="<?php echo esc_attr(self::OPTION_KEY); ?>[sync_interval]">
                                <?php foreach (Skwirrel_WC_Sync_Action_Scheduler::get_interval_options() as $k => $v) : ?>
                                    <option value="<?php echo esc_attr($k); ?>" <?php selected($opts['sync_interval'] ?? '', $k); ?>><?php echo esc_html($v); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="batch_size"><?php esc_html_e('Batch size', 'skwirrel-wc-sync'); ?></label></th>
                        <td>
                            <input type="number" id="batch_size" name="<?php echo esc_attr(self::OPTION_KEY); ?>[batch_size]" value="<?php echo esc_attr((string) ($opts['batch_size'] ?? 100)); ?>" min="10" max="500" />
                            <p class="description"><?php esc_html_e('Producten per API-pagina (1–500).', 'skwirrel-wc-sync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Categorieën syncen', 'skwirrel-wc-sync'); ?></th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[sync_categories]" value="1" <?php checked(!empty($opts['sync_categories'])); ?> /> <?php esc_html_e('Categorieën uit product_groups aanmaken en koppelen', 'skwirrel-wc-sync'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Grouped products syncen', 'skwirrel-wc-sync'); ?></th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[sync_grouped_products]" value="1" <?php checked(!empty($opts['sync_grouped_products'])); ?> /> <?php esc_html_e('Grouped products ophalen via getGroupedProducts (producten binnen groep kunnen variable zijn)', 'skwirrel-wc-sync'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="collection_ids"><?php esc_html_e('Collectie ID\'s (filter)', 'skwirrel-wc-sync'); ?></label></th>
                        <td>
                            <input type="text" id="collection_ids" name="<?php echo esc_attr(self::OPTION_KEY); ?>[collection_ids]" value="<?php echo esc_attr($opts['collection_ids'] ?? ''); ?>" class="regular-text" placeholder="<?php esc_attr_e('bijv. 123, 456', 'skwirrel-wc-sync'); ?>" />
                            <p class="description"><?php esc_html_e('Comma-separated collectie ID\'s. Alleen producten uit deze collecties worden gesynchroniseerd. Leeg = alles synchroniseren.', 'skwirrel-wc-sync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sync_images"><?php esc_html_e('Afbeeldingen importeren', 'skwirrel-wc-sync'); ?></label></th>
                        <td>
                            <select id="sync_images" name="<?php echo esc_attr(self::OPTION_KEY); ?>[sync_images]">
                                <option value="yes" <?php selected(($opts['sync_images'] ?? true), true); ?>><?php esc_html_e('Ja, naar media library', 'skwirrel-wc-sync'); ?></option>
                                <option value="no" <?php selected(($opts['sync_images'] ?? true), false); ?>><?php esc_html_e('Nee, overslaan', 'skwirrel-wc-sync'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('Als import naar media library faalt (bijv. door security plugin), kies dan "Nee" om afbeeldingen over te slaan.', 'skwirrel-wc-sync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="image_language_select"><?php esc_html_e('Contenttaal', 'skwirrel-wc-sync'); ?></label></th>
                        <td>
                            <?php
                            $current_lang = $opts['image_language'] ?? 'nl';
                            $is_custom = !isset(self::LANGUAGE_OPTIONS[$current_lang]);
                            ?>
                            <select id="image_language_select" name="<?php echo esc_attr(self::OPTION_KEY); ?>[image_language_select]" onchange="var c=document.getElementById('image_language_custom_wrap');c.style.display=this.value==='_custom'?'inline-block':'none';if(this.value!=='_custom')document.getElementById('image_language_custom').value='';">
                                <?php foreach (self::LANGUAGE_OPTIONS as $code => $label) : ?>
                                    <option value="<?php echo esc_attr($code); ?>" <?php selected($current_lang, $code); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                                <option value="_custom" <?php selected($is_custom); ?>><?php esc_html_e('Anders…', 'skwirrel-wc-sync'); ?></option>
                            </select>
                            <span id="image_language_custom_wrap" style="display:<?php echo $is_custom ? 'inline-block' : 'none'; ?>;">
                                <input type="text" id="image_language_custom" name="<?php echo esc_attr(self::OPTION_KEY); ?>[image_language_custom]" value="<?php echo esc_attr($is_custom ? $current_lang : ''); ?>" size="6" pattern="[a-z]{2}(-[A-Z]{2})?" placeholder="bijv. es-ES" />
                            </span>
                            <p class="description"><?php esc_html_e('Taalcode voor alle teksten: afbeelding alt/caption, ETIM-attributen.', 'skwirrel-wc-sync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('API-talen (include_languages)', 'skwirrel-wc-sync'); ?></th>
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
                                <label for="include_languages_custom"><?php esc_html_e('Extra taalcodes (comma-separated):', 'skwirrel-wc-sync'); ?></label><br />
                                <input type="text" id="include_languages_custom" name="<?php echo esc_attr(self::OPTION_KEY); ?>[include_languages_custom]" value="<?php echo esc_attr(implode(', ', $custom_langs)); ?>" class="regular-text" placeholder="bijv. es, pt-BR" />
                            </p>
                            <p class="description"><?php esc_html_e('Selecteer de talen die de API moet meesturen voor product translations en ETIM.', 'skwirrel-wc-sync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="verbose_logging"><?php esc_html_e('Uitgebreide logging', 'skwirrel-wc-sync'); ?></label></th>
                        <td>
                            <label><input type="checkbox" id="verbose_logging" name="<?php echo esc_attr(self::OPTION_KEY); ?>[verbose_logging]" value="1" <?php checked(!empty($opts['verbose_logging'])); ?> /> <?php esc_html_e('Log per product: bronnen, ETIM, attributen (zie WooCommerce → Status → Logs)', 'skwirrel-wc-sync'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Verwijderde producten opruimen', 'skwirrel-wc-sync'); ?></th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[purge_stale_products]" value="1" <?php checked(!empty($opts['purge_stale_products'])); ?> /> <?php esc_html_e('Producten en categorieën die niet meer in Skwirrel staan automatisch naar de prullenbak verplaatsen bij volledige sync', 'skwirrel-wc-sync'); ?></label>
                            <p class="description"><?php esc_html_e('Alleen actief bij volledige sync (handmatige sync of na verwijdering in WooCommerce). Niet actief bij delta sync of wanneer collectie-filter is ingesteld.', 'skwirrel-wc-sync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Verwijderwaarschuwing tonen', 'skwirrel-wc-sync'); ?></th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[show_delete_warning]" value="1" <?php checked($opts['show_delete_warning'] ?? true); ?> /> <?php esc_html_e('Toon waarschuwing bij het verwijderen van Skwirrel-producten en -categorieën in WooCommerce', 'skwirrel-wc-sync'); ?></label>
                            <p class="description"><?php esc_html_e('Skwirrel is leidend: verwijderde producten worden bij de volgende sync opnieuw aangemaakt. Deze waarschuwing herinnert gebruikers hieraan.', 'skwirrel-wc-sync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="use_sku_field"><?php esc_html_e('SKU veld', 'skwirrel-wc-sync'); ?></label></th>
                        <td>
                            <select id="use_sku_field" name="<?php echo esc_attr(self::OPTION_KEY); ?>[use_sku_field]">
                                <option value="internal_product_code" <?php selected($opts['use_sku_field'] ?? 'internal_product_code', 'internal_product_code'); ?>><?php esc_html_e('internal_product_code', 'skwirrel-wc-sync'); ?></option>
                                <option value="manufacturer_product_code" <?php selected($opts['use_sku_field'] ?? '', 'manufacturer_product_code'); ?>><?php esc_html_e('manufacturer_product_code', 'skwirrel-wc-sync'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="stock_management"><?php esc_html_e('Voorraad synchroniseren', 'skwirrel-wc-sync'); ?></label></th>
                        <td>
                            <select id="stock_management" name="<?php echo esc_attr(self::OPTION_KEY); ?>[stock_management]">
                                <option value="off" <?php selected($opts['stock_management'] ?? 'off', 'off'); ?>><?php esc_html_e('Uit (altijd op voorraad)', 'skwirrel-wc-sync'); ?></option>
                                <option value="on" <?php selected($opts['stock_management'] ?? 'off', 'on'); ?>><?php esc_html_e('Aan (sync vanuit API)', 'skwirrel-wc-sync'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="orphan_action"><?php esc_html_e('Verweesde producten', 'skwirrel-wc-sync'); ?></label></th>
                        <td>
                            <select id="orphan_action" name="<?php echo esc_attr(self::OPTION_KEY); ?>[orphan_action]">
                                <option value="nothing" <?php selected($opts['orphan_action'] ?? 'nothing', 'nothing'); ?>><?php esc_html_e('Niets doen', 'skwirrel-wc-sync'); ?></option>
                                <option value="draft" <?php selected($opts['orphan_action'] ?? 'nothing', 'draft'); ?>><?php esc_html_e('Naar concept', 'skwirrel-wc-sync'); ?></option>
                                <option value="trash" <?php selected($opts['orphan_action'] ?? 'nothing', 'trash'); ?>><?php esc_html_e('Naar prullenbak', 'skwirrel-wc-sync'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('Actie voor producten die niet meer in Skwirrel voorkomen na een volledige sync.', 'skwirrel-wc-sync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="default_tax_class"><?php esc_html_e('Standaard belastingklasse', 'skwirrel-wc-sync'); ?></label></th>
                        <td>
                            <select id="default_tax_class" name="<?php echo esc_attr(self::OPTION_KEY); ?>[default_tax_class]">
                                <option value=""><?php esc_html_e('Automatisch (uit API)', 'skwirrel-wc-sync'); ?></option>
                                <?php foreach (WC_Tax::get_tax_classes() as $tax_class) : ?>
                                    <option value="<?php echo esc_attr(sanitize_title($tax_class)); ?>" <?php selected($opts['default_tax_class'] ?? '', sanitize_title($tax_class)); ?>><?php echo esc_html($tax_class); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="default_shipping_class"><?php esc_html_e('Standaard verzendklasse', 'skwirrel-wc-sync'); ?></label></th>
                        <td>
                            <select id="default_shipping_class" name="<?php echo esc_attr(self::OPTION_KEY); ?>[default_shipping_class]">
                                <option value=""><?php esc_html_e('Geen', 'skwirrel-wc-sync'); ?></option>
                                <?php
                                $shipping_classes = get_terms(['taxonomy' => 'product_shipping_class', 'hide_empty' => false]);
                                if (!is_wp_error($shipping_classes)) :
                                    foreach ($shipping_classes as $sc) : ?>
                                        <option value="<?php echo esc_attr((string) $sc->term_id); ?>" <?php selected($opts['default_shipping_class'] ?? '', (string) $sc->term_id); ?>><?php echo esc_html($sc->name); ?></option>
                                    <?php endforeach;
                                endif; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Merk als taxonomy', 'skwirrel-wc-sync'); ?></th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[brand_as_taxonomy]" value="1" <?php checked(!empty($opts['brand_as_taxonomy'])); ?> /> <?php esc_html_e('Gebruik merk als taxonomy indien beschikbaar (bijv. Perfect WooCommerce Brands)', 'skwirrel-wc-sync'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="webhook_secret"><?php esc_html_e('Webhook secret', 'skwirrel-wc-sync'); ?></label></th>
                        <td>
                            <input type="text" id="webhook_secret" name="<?php echo esc_attr(self::OPTION_KEY); ?>[webhook_secret]" value="<?php echo esc_attr($opts['webhook_secret'] ?? ''); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e('Geheim voor webhook authenticatie. Laat leeg om webhooks uit te schakelen.', 'skwirrel-wc-sync'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Instellingen opslaan', 'skwirrel-wc-sync')); ?>
            </form>

            <hr />

            <h2><?php esc_html_e('Acties', 'skwirrel-wc-sync'); ?></h2>
            <p>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=skwirrel_wc_sync_test'), 'skwirrel_wc_sync_test', '_wpnonce')); ?>" class="button"><?php esc_html_e('Test verbinding', 'skwirrel-wc-sync'); ?></a>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=skwirrel_wc_sync_run'), 'skwirrel_wc_sync_run', '_wpnonce')); ?>" class="button button-primary"><?php esc_html_e('Sync nu', 'skwirrel-wc-sync'); ?></a>
                <?php if ($log_url) : ?>
                    <a href="<?php echo esc_url($log_url); ?>" class="button" target="_blank"><?php esc_html_e('Bekijk logs', 'skwirrel-wc-sync'); ?></a>
                <?php endif; ?>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-ajax.php?action=skwirrel_wc_sync_download_log'), 'skwirrel_wc_sync_download_log')); ?>" class="button"><?php esc_html_e('Download logs', 'skwirrel-wc-sync'); ?></a>
            </p>

            <div id="skwirrel-sync-progress" style="display:none; margin: 15px 0;">
                <p><strong><?php esc_html_e('Sync bezig...', 'skwirrel-wc-sync'); ?></strong></p>
                <div style="background:#e0e0e0; border-radius:4px; height:24px; position:relative; overflow:hidden;">
                    <div id="skwirrel-progress-bar" style="background:#0073aa; height:100%; width:0%; transition:width 0.3s; border-radius:4px;"></div>
                    <span id="skwirrel-progress-text" style="position:absolute; top:0; left:50%; transform:translateX(-50%); line-height:24px; font-size:12px; font-weight:bold; color:#333;"></span>
                </div>
                <p id="skwirrel-progress-details" style="margin-top:8px; color:#666;"></p>
            </div>
            <script>
            (function(){
                var polling = null;
                function checkProgress() {
                    fetch(ajaxurl + '?action=skwirrel_wc_sync_progress&_=' + Date.now(), {credentials:'same-origin'})
                        .then(function(r){return r.json();})
                        .then(function(d){
                            if (!d.success) return;
                            var data = d.data;
                            var el = document.getElementById('skwirrel-sync-progress');
                            if (data.running && data.progress) {
                                el.style.display = 'block';
                                var p = data.progress;
                                var pct = p.total > 0 ? Math.min(100, Math.round(p.processed / p.total * 100)) : 0;
                                document.getElementById('skwirrel-progress-bar').style.width = pct + '%';
                                document.getElementById('skwirrel-progress-text').textContent = pct + '%';
                                document.getElementById('skwirrel-progress-details').textContent =
                                    'Verwerkt: ' + p.processed + ' | Aangemaakt: ' + (p.created||0) + ' | Bijgewerkt: ' + (p.updated||0) + ' | Mislukt: ' + (p.failed||0);
                            } else if (!data.running && el.style.display !== 'none') {
                                el.style.display = 'none';
                                if (polling) { clearInterval(polling); polling = null; }
                                location.reload();
                            }
                        }).catch(function(){});
                }
                // Start polling if sync was just queued or is running
                <?php if (isset($_GET['sync']) && $_GET['sync'] === 'queued') : ?>
                polling = setInterval(checkProgress, 3000);
                checkProgress();
                <?php else : ?>
                checkProgress();
                setTimeout(function(){ if (!polling) { polling = setInterval(checkProgress, 5000); } }, 1000);
                <?php endif; ?>
            })();
            </script>

            <h2><?php esc_html_e('Variatie-attributen debuggen', 'skwirrel-wc-sync'); ?></h2>
            <p><?php esc_html_e('Als variaties "Any Colour" / "Any Number of cups" tonen in plaats van echte waarden:', 'skwirrel-wc-sync'); ?></p>
            <ol style="list-style: decimal; margin-left: 1.5em;">
                <li><?php esc_html_e('Voeg in wp-config.php toe: define(\'SKWIRREL_WC_SYNC_DEBUG_ETIM\', true);', 'skwirrel-wc-sync'); ?></li>
                <li><?php esc_html_e('Voer "Sync nu" uit.', 'skwirrel-wc-sync'); ?></li>
                <li><?php esc_html_e('Bekijk wp-content/uploads/skwirrel-variation-debug.log', 'skwirrel-wc-sync'); ?></li>
                <li><?php esc_html_e('Controleer: etim_values_found leeg? → API-talen moet overeenkomen met getProducts (bijv. en, en-GB).', 'skwirrel-wc-sync'); ?></li>
                <li><?php esc_html_e('ATTR VERIFY FAIL in de log? → meta wordt niet correct weggeschreven; controleer wp_postmeta voor attribute_pa_color/attribute_pa_cups.', 'skwirrel-wc-sync'); ?></li>
            </ol>

            <h2><?php esc_html_e('Sync status', 'skwirrel-wc-sync'); ?></h2>

            <?php if ($last_result) : ?>
                <div style="background: <?php echo $last_result['success'] ? '#d4edda' : '#f8d7da'; ?>; border: 1px solid <?php echo $last_result['success'] ? '#c3e6cb' : '#f5c6cb'; ?>; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
                    <h3 style="margin-top: 0; color: <?php echo $last_result['success'] ? '#155724' : '#721c24'; ?>;">
                        <?php if ($last_result['success']) : ?>
                            ✓ <?php esc_html_e('Laatste sync geslaagd', 'skwirrel-wc-sync'); ?>
                        <?php else : ?>
                            ✗ <?php esc_html_e('Laatste sync mislukt', 'skwirrel-wc-sync'); ?>
                        <?php endif; ?>
                    </h3>
                    <p style="margin: 0; color: <?php echo $last_result['success'] ? '#155724' : '#721c24'; ?>;">
                        <?php echo $last_sync ? esc_html($this->format_datetime($last_sync)) : esc_html__('Onbekend', 'skwirrel-wc-sync'); ?>
                    </p>
                </div>

                <h3><?php esc_html_e('Sync resultaten', 'skwirrel-wc-sync'); ?></h3>
                <table class="widefat" style="margin-bottom: 20px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Categorie', 'skwirrel-wc-sync'); ?></th>
                            <th style="text-align: right;"><?php esc_html_e('Aantal', 'skwirrel-wc-sync'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong><?php esc_html_e('Aangemaakt', 'skwirrel-wc-sync'); ?></strong></td>
                            <td style="text-align: right;"><span style="color: #00a32a; font-weight: bold; font-size: 16px;"><?php echo (int) ($last_result['created'] ?? 0); ?></span></td>
                        </tr>
                        <tr style="background-color: #f9f9f9;">
                            <td><strong><?php esc_html_e('Bijgewerkt', 'skwirrel-wc-sync'); ?></strong></td>
                            <td style="text-align: right;"><span style="color: #007cba; font-weight: bold; font-size: 16px;"><?php echo (int) ($last_result['updated'] ?? 0); ?></span></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Mislukt', 'skwirrel-wc-sync'); ?></strong></td>
                            <td style="text-align: right;"><span style="color: #d63638; font-weight: bold; font-size: 16px;"><?php echo (int) ($last_result['failed'] ?? 0); ?></span></td>
                        </tr>
                        <?php
                        $trashed_count = (int) ($last_result['trashed'] ?? 0);
                        $cats_removed = (int) ($last_result['categories_removed'] ?? 0);
                        if ($trashed_count > 0 || $cats_removed > 0) :
                        ?>
                        <tr style="background-color: #fff3cd;">
                            <td><strong><?php esc_html_e('Verwijderd (prullenbak)', 'skwirrel-wc-sync'); ?></strong></td>
                            <td style="text-align: right;"><span style="color: #856404; font-weight: bold; font-size: 16px;"><?php echo $trashed_count; ?></span></td>
                        </tr>
                        <?php if ($cats_removed > 0) : ?>
                        <tr style="background-color: #fff3cd;">
                            <td style="padding-left: 20px;"><?php esc_html_e('↳ Categorieën opgeruimd', 'skwirrel-wc-sync'); ?></td>
                            <td style="text-align: right;"><span style="color: #856404;"><?php echo $cats_removed; ?></span></td>
                        </tr>
                        <?php endif; ?>
                        <?php endif; ?>
                        <?php
                        $with_a = (int) ($last_result['with_attributes'] ?? 0);
                        $without_a = (int) ($last_result['without_attributes'] ?? 0);
                        if ($with_a + $without_a > 0) :
                        ?>
                        <tr style="background-color: #f9f9f9;">
                            <td style="padding-left: 20px;"><?php esc_html_e('↳ Met kenmerken', 'skwirrel-wc-sync'); ?></td>
                            <td style="text-align: right;"><?php echo $with_a; ?></td>
                        </tr>
                        <tr>
                            <td style="padding-left: 20px;"><?php esc_html_e('↳ Zonder kenmerken', 'skwirrel-wc-sync'); ?></td>
                            <td style="text-align: right;"><?php echo $without_a; ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr style="background-color: #e8f5e9; border-top: 2px solid #4caf50;">
                            <td><strong><?php esc_html_e('Totaal verwerkt', 'skwirrel-wc-sync'); ?></strong></td>
                            <td style="text-align: right;"><strong style="font-size: 16px;"><?php echo (int) ($last_result['created'] ?? 0) + (int) ($last_result['updated'] ?? 0) + (int) ($last_result['failed'] ?? 0); ?></strong></td>
                        </tr>
                    </tbody>
                </table>

                <?php if (!$last_result['success'] && !empty($last_result['error'])) : ?>
                    <div style="background: #fff3cd; border: 1px solid #ffc107; padding: 10px; border-radius: 4px; margin-top: 15px;">
                        <strong><?php esc_html_e('Foutmelding:', 'skwirrel-wc-sync'); ?></strong><br>
                        <?php echo esc_html($last_result['error']); ?>
                    </div>
                <?php endif; ?>
            <?php else : ?>
                <div style="background: #f0f0f0; border: 1px solid #ccc; padding: 15px; border-radius: 4px; text-align: center;">
                    <p style="margin: 0;"><?php esc_html_e('Nog geen sync uitgevoerd. Klik op "Nu synchroniseren" om te beginnen.', 'skwirrel-wc-sync'); ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($sync_history)) : ?>
                <h2 style="margin-top: 30px;"><?php esc_html_e('Sync geschiedenis', 'skwirrel-wc-sync'); ?></h2>
                <table class="widefat striped" style="margin-top: 10px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Datum & Tijd', 'skwirrel-wc-sync'); ?></th>
                            <th><?php esc_html_e('Status', 'skwirrel-wc-sync'); ?></th>
                            <th style="text-align: right;"><?php esc_html_e('Aangemaakt', 'skwirrel-wc-sync'); ?></th>
                            <th style="text-align: right;"><?php esc_html_e('Bijgewerkt', 'skwirrel-wc-sync'); ?></th>
                            <th style="text-align: right;"><?php esc_html_e('Mislukt', 'skwirrel-wc-sync'); ?></th>
                            <th style="text-align: right;"><?php esc_html_e('Verwijderd', 'skwirrel-wc-sync'); ?></th>
                            <th style="text-align: right;"><?php esc_html_e('Met kenm.', 'skwirrel-wc-sync'); ?></th>
                            <th style="text-align: right;"><?php esc_html_e('Zonder kenm.', 'skwirrel-wc-sync'); ?></th>
                            <th style="text-align: right;"><?php esc_html_e('Totaal', 'skwirrel-wc-sync'); ?></th>
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
                                        <span style="color: #00a32a; font-weight: bold;">✓ <?php esc_html_e('Geslaagd', 'skwirrel-wc-sync'); ?></span>
                                    <?php else : ?>
                                        <span style="color: #d63638; font-weight: bold;">✗ <?php esc_html_e('Mislukt', 'skwirrel-wc-sync'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;"><span style="color: #00a32a;"><?php echo $created; ?></span></td>
                                <td style="text-align: right;"><span style="color: #007cba;"><?php echo $updated; ?></span></td>
                                <td style="text-align: right;"><span style="color: #d63638;"><?php echo $failed; ?></span></td>
                                <td style="text-align: right;"><span style="color: #856404;"><?php echo $trashed_h; ?></span></td>
                                <td style="text-align: right;"><?php echo $with_attrs; ?></td>
                                <td style="text-align: right;"><?php echo $without_attrs; ?></td>
                                <td style="text-align: right;"><strong><?php echo $total; ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    private function maybe_show_notices(): void {
        if (isset($_GET['test'])) {
            if ($_GET['test'] === 'ok') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Verbinding test geslaagd.', 'skwirrel-wc-sync') . '</p></div>';
            } else {
                $msg = isset($_GET['message']) ? sanitize_text_field(wp_unslash($_GET['message'])) : __('Verbinding mislukt.', 'skwirrel-wc-sync');
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($msg) . '</p></div>';
            }
        }
        if (isset($_GET['sync']) && $_GET['sync'] === 'queued') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Sync is gestart op de achtergrond. De resultaten verschijnen hier zodra de sync is voltooid. Vernieuw de pagina om de status te controleren.', 'skwirrel-wc-sync') . '</p></div>';
        }
        if (isset($_GET['sync']) && $_GET['sync'] === 'done') {
            $last = Skwirrel_WC_Sync_Service::get_last_result();
            if ($last && $last['success']) {
                $with_a = (int) ($last['with_attributes'] ?? 0);
                $without_a = (int) ($last['without_attributes'] ?? 0);
                $msg = sprintf(
                    esc_html__('Sync voltooid. Aangemaakt: %d, Bijgewerkt: %d, Mislukt: %d', 'skwirrel-wc-sync'),
                    (int) $last['created'],
                    (int) $last['updated'],
                    (int) $last['failed']
                );
                if ($with_a + $without_a > 0) {
                    $msg .= ' ' . sprintf(
                        esc_html__('(met kenmerken: %d, zonder: %d)', 'skwirrel-wc-sync'),
                        $with_a,
                        $without_a
                    );
                }
                echo '<div class="notice notice-success is-dismissible"><p>' . $msg . '</p></div>';
            } else {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Sync voltooid. Controleer de logs voor details.', 'skwirrel-wc-sync') . '</p></div>';
            }
        }
    }

    private function format_datetime(string $s): string {
        $ts = strtotime($s);
        return $ts ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $ts) : $s;
    }
}
