<?php
/**
 * Skwirrel Sync — REST API voor externe integraties (n8n, Zapier, etc.).
 *
 * Namespace: skwirrel-wc-sync/v1
 *
 * Authenticatie via:
 *  1. WordPress Application Passwords (Basic Auth)
 *  2. Custom REST API key in X-Skwirrel-Rest-Key header
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Skwirrel_WC_Sync_Rest_Api {

    private const NAMESPACE = 'skwirrel-wc-sync/v1';
    private const REST_KEY_OPTION = 'skwirrel_wc_sync_rest_api_key';

    private static ?self $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /* ------------------------------------------------------------------
     * Routes
     * ----------------------------------------------------------------*/

    public function register_routes(): void {
        // Sync triggeren
        register_rest_route(self::NAMESPACE, '/sync', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_sync'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => [
                'mode' => [
                    'type'              => 'string',
                    'enum'              => ['full', 'delta'],
                    'default'           => 'full',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Sync status (is er een sync bezig?)
        register_rest_route(self::NAMESPACE, '/sync/status', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handle_sync_status'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Laatste sync resultaat
        register_rest_route(self::NAMESPACE, '/sync/last-result', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handle_last_result'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Sync geschiedenis
        register_rest_route(self::NAMESPACE, '/sync/history', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handle_history'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => [
                'limit' => [
                    'type'              => 'integer',
                    'default'           => 20,
                    'minimum'           => 1,
                    'maximum'           => 100,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        // Verbinding testen
        register_rest_route(self::NAMESPACE, '/connection/test', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_test_connection'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Instellingen ophalen (sanitized, geen token)
        register_rest_route(self::NAMESPACE, '/settings', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handle_get_settings'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Gesynchroniseerde producten ophalen
        register_rest_route(self::NAMESPACE, '/products', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handle_get_products'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => [
                'page' => [
                    'type'              => 'integer',
                    'default'           => 1,
                    'minimum'           => 1,
                    'sanitize_callback' => 'absint',
                ],
                'per_page' => [
                    'type'              => 'integer',
                    'default'           => 20,
                    'minimum'           => 1,
                    'maximum'           => 100,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
    }

    /* ------------------------------------------------------------------
     * Handlers
     * ----------------------------------------------------------------*/

    /**
     * POST /sync — Start een synchronisatie.
     */
    public function handle_sync(\WP_REST_Request $request): \WP_REST_Response {
        $mode = $request->get_param('mode') ?? 'full';
        $delta = ($mode === 'delta');

        $service = new Skwirrel_WC_Sync_Service();

        $logger = new Skwirrel_WC_Sync_Logger();
        $logger->info(sprintf('REST API: sync gestart (mode=%s)', $mode));

        $result = $service->run_sync($delta);

        return new \WP_REST_Response([
            'success' => $result['success'] ?? false,
            'mode'    => $mode,
            'result'  => [
                'created'            => (int) ($result['created'] ?? 0),
                'updated'            => (int) ($result['updated'] ?? 0),
                'failed'             => (int) ($result['failed'] ?? 0),
                'trashed'            => (int) ($result['trashed'] ?? 0),
                'categories_removed' => (int) ($result['categories_removed'] ?? 0),
            ],
            'error'   => $result['error'] ?? null,
        ], $result['success'] ? 200 : 500);
    }

    /**
     * GET /sync/status — Is er een sync actief?
     */
    public function handle_sync_status(\WP_REST_Request $request): \WP_REST_Response {
        // Detecteer actieve sync via lock-transient
        $is_running = (bool) get_transient('skwirrel_wc_sync_running');
        $last_sync  = Skwirrel_WC_Sync_Service::get_last_sync();
        $force_full = (bool) get_option('skwirrel_wc_sync_force_full_sync', false);

        return new \WP_REST_Response([
            'is_running'       => $is_running,
            'last_sync'        => $last_sync,
            'force_full_sync'  => $force_full,
        ], 200);
    }

    /**
     * GET /sync/last-result — Laatste sync resultaat.
     */
    public function handle_last_result(\WP_REST_Request $request): \WP_REST_Response {
        $result    = Skwirrel_WC_Sync_Service::get_last_result();
        $last_sync = Skwirrel_WC_Sync_Service::get_last_sync();

        if ($result === null) {
            return new \WP_REST_Response([
                'message' => 'Nog geen sync uitgevoerd.',
            ], 404);
        }

        return new \WP_REST_Response([
            'timestamp' => $last_sync,
            'success'   => $result['success'] ?? false,
            'created'   => (int) ($result['created'] ?? 0),
            'updated'   => (int) ($result['updated'] ?? 0),
            'failed'    => (int) ($result['failed'] ?? 0),
            'trashed'   => (int) ($result['trashed'] ?? 0),
            'categories_removed' => (int) ($result['categories_removed'] ?? 0),
            'error'     => $result['error'] ?? null,
        ], 200);
    }

    /**
     * GET /sync/history — Sync geschiedenis.
     */
    public function handle_history(\WP_REST_Request $request): \WP_REST_Response {
        $history = Skwirrel_WC_Sync_Service::get_sync_history();
        $limit   = $request->get_param('limit') ?? 20;

        return new \WP_REST_Response([
            'count'   => count($history),
            'entries' => array_slice($history, 0, $limit),
        ], 200);
    }

    /**
     * POST /connection/test — Test Skwirrel API verbinding.
     */
    public function handle_test_connection(\WP_REST_Request $request): \WP_REST_Response {
        $opts   = get_option('skwirrel_wc_sync_settings', []);
        $token  = Skwirrel_WC_Sync_Admin_Settings::get_auth_token();
        $client = new Skwirrel_WC_Sync_JsonRpc_Client(
            $opts['endpoint_url'] ?? '',
            $opts['auth_type'] ?? 'bearer',
            $token,
            (int) ($opts['timeout'] ?? 30),
            (int) ($opts['retries'] ?? 2)
        );

        $result = $client->test_connection();

        return new \WP_REST_Response([
            'success' => $result['success'],
            'message' => $result['success']
                ? 'Verbinding met Skwirrel API is geslaagd.'
                : ($result['error']['message'] ?? 'Verbinding mislukt.'),
        ], $result['success'] ? 200 : 502);
    }

    /**
     * GET /settings — Plugin instellingen (zonder gevoelige data).
     */
    public function handle_get_settings(\WP_REST_Request $request): \WP_REST_Response {
        $opts = get_option('skwirrel_wc_sync_settings', []);

        // Token en gevoelige data verwijderen
        unset($opts['auth_token']);

        return new \WP_REST_Response([
            'settings' => $opts,
        ], 200);
    }

    /**
     * GET /products — Gesynchroniseerde producten met Skwirrel metadata.
     */
    public function handle_get_products(\WP_REST_Request $request): \WP_REST_Response {
        $page     = $request->get_param('page') ?? 1;
        $per_page = $request->get_param('per_page') ?? 20;

        $query = new \WP_Query([
            'post_type'      => 'product',
            'post_status'    => ['publish', 'draft', 'pending'],
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'meta_query'     => [
                [
                    'key'     => '_skwirrel_external_id',
                    'compare' => 'EXISTS',
                ],
            ],
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ]);

        $products = [];
        foreach ($query->posts as $post) {
            $products[] = [
                'id'                  => $post->ID,
                'title'               => $post->post_title,
                'status'              => $post->post_status,
                'sku'                 => get_post_meta($post->ID, '_sku', true),
                'skwirrel_external_id' => get_post_meta($post->ID, '_skwirrel_external_id', true),
                'skwirrel_product_id' => get_post_meta($post->ID, '_skwirrel_product_id', true),
                'skwirrel_synced_at'  => get_post_meta($post->ID, '_skwirrel_synced_at', true),
                'permalink'           => get_permalink($post->ID),
            ];
        }

        return new \WP_REST_Response([
            'page'       => $page,
            'per_page'   => $per_page,
            'total'      => $query->found_posts,
            'total_pages' => $query->max_num_pages,
            'products'   => $products,
        ], 200);
    }

    /* ------------------------------------------------------------------
     * Authenticatie
     * ----------------------------------------------------------------*/

    /**
     * Controleer toegang via WP-capability of REST API key.
     */
    public function check_permission(\WP_REST_Request $request): bool {
        // 1. WordPress Application Passwords / cookie auth
        if (current_user_can('manage_woocommerce')) {
            return true;
        }

        // 2. Custom REST API key via header
        $key = $request->get_header('X-Skwirrel-Rest-Key');
        if ($key !== null && $this->validate_rest_key($key)) {
            return true;
        }

        return false;
    }

    /**
     * Valideer REST API key tegen opgeslagen waarde.
     */
    private function validate_rest_key(string $key): bool {
        $stored = get_option(self::REST_KEY_OPTION, '');
        if ($stored === '' || $key === '') {
            return false;
        }
        return hash_equals($stored, $key);
    }

    /* ------------------------------------------------------------------
     * API Key Management
     * ----------------------------------------------------------------*/

    /**
     * Genereer een nieuwe REST API key.
     */
    public static function generate_rest_key(): string {
        $key = 'skw_' . bin2hex(random_bytes(24));
        update_option(self::REST_KEY_OPTION, $key, false);
        return $key;
    }

    /**
     * Haal de huidige REST API key op.
     */
    public static function get_rest_key(): string {
        return (string) get_option(self::REST_KEY_OPTION, '');
    }

    /**
     * Verwijder de REST API key.
     */
    public static function revoke_rest_key(): void {
        delete_option(self::REST_KEY_OPTION);
    }
}
