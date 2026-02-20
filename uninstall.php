<?php
/**
 * Skwirrel WooCommerce Sync - Uninstall.
 *
 * Verwijdert alle plugin data bij verwijdering via WordPress admin.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Cleanup function for a single site.
 */
function skwirrel_wc_sync_cleanup_site(): void {
    $options = [
        'skwirrel_wc_sync_settings',
        'skwirrel_wc_sync_auth_token',
        'skwirrel_wc_sync_last_sync',
        'skwirrel_wc_sync_last_result',
        'skwirrel_wc_sync_history',
        'skwirrel_wc_sync_progress',
    ];
    foreach ($options as $option) {
        delete_option($option);
    }

    delete_transient('skwirrel_wc_sync_running');

    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_skwirrel_%'");

    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->termmeta} WHERE meta_key = %s",
            '_skwirrel_category_id'
        )
    );

    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions('skwirrel_wc_sync_run', [], 'skwirrel-wc-sync');
    }
    wp_clear_scheduled_hook('skwirrel_wc_sync_run');
}

// Multisite support: cleanup all sites
if (is_multisite()) {
    $sites = get_sites(['fields' => 'ids']);
    foreach ($sites as $site_id) {
        switch_to_blog($site_id);
        skwirrel_wc_sync_cleanup_site();
        restore_current_blog();
    }
} else {
    skwirrel_wc_sync_cleanup_site();
}
