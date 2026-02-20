<?php
/**
 * Skwirrel Sync - REST API Endpoints.
 *
 * Provides REST API for sync status, history, and trigger.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Skwirrel_WC_Sync_Rest_Api {

    private const NAMESPACE = 'skwirrel-wc-sync/v1';

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

    public function register_routes(): void {
        register_rest_route(self::NAMESPACE, '/status', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_status'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/history', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_history'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/trigger', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'trigger_sync'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'delta' => [
                    'type' => 'boolean',
                    'default' => false,
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/webhook', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'handle_webhook'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function check_permission(): bool {
        return current_user_can('manage_woocommerce');
    }

    public function get_status(WP_REST_Request $request): WP_REST_Response {
        return new WP_REST_Response([
            'last_sync' => Skwirrel_WC_Sync_Service::get_last_sync(),
            'last_result' => Skwirrel_WC_Sync_Service::get_last_result(),
            'is_running' => Skwirrel_WC_Sync_Service::is_sync_running(),
            'progress' => Skwirrel_WC_Sync_Service::get_sync_progress(),
        ]);
    }

    public function get_history(WP_REST_Request $request): WP_REST_Response {
        return new WP_REST_Response([
            'history' => Skwirrel_WC_Sync_Service::get_sync_history(),
        ]);
    }

    public function trigger_sync(WP_REST_Request $request): WP_REST_Response {
        if (Skwirrel_WC_Sync_Service::is_sync_running()) {
            return new WP_REST_Response(['message' => 'Sync is al actief'], 409);
        }

        $delta = (bool) $request->get_param('delta');
        $scheduler = Skwirrel_WC_Sync_Action_Scheduler::instance();
        $scheduler->enqueue_manual_sync();

        return new WP_REST_Response([
            'message' => 'Sync gestart',
            'delta' => $delta,
        ]);
    }

    public function handle_webhook(WP_REST_Request $request): WP_REST_Response {
        $opts = get_option('skwirrel_wc_sync_settings', []);
        $secret = $opts['webhook_secret'] ?? '';

        if ($secret === '') {
            return new WP_REST_Response(['message' => 'Webhooks disabled'], 403);
        }

        // Verify webhook secret from header or body
        $provided_secret = $request->get_header('X-Webhook-Secret')
            ?? $request->get_header('X-Skwirrel-Webhook-Secret')
            ?? ($request->get_json_params()['secret'] ?? '');

        if (!hash_equals($secret, $provided_secret)) {
            return new WP_REST_Response(['message' => 'Invalid secret'], 403);
        }

        $body = $request->get_json_params();
        $product_ids = $body['product_ids'] ?? [];

        // Queue delta sync
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('skwirrel_wc_sync_run', ['delta' => true], 'skwirrel-wc-sync');
        } else {
            $scheduler = Skwirrel_WC_Sync_Action_Scheduler::instance();
            $scheduler->enqueue_manual_sync();
        }

        return new WP_REST_Response([
            'message' => 'Webhook ontvangen, delta sync gepland',
            'product_ids' => $product_ids,
        ]);
    }
}
