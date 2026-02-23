=== Skwirrel PIM Sync ===
Contributors: skwirrel
Tags: woocommerce, sync, erp, pim, skwirrel
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Synchronises products from the Skwirrel ERP/PIM system to WooCommerce via a JSON-RPC 2.0 API.

== Description ==

Skwirrel PIM Sync connects your WooCommerce webshop to the Skwirrel ERP/PIM system. Products, variations, images and documents are synchronised automatically.

**Features:**

* Full and delta product synchronisation
* Support for simple and variable products
* Automatic import of product images and documents
* Scheduled synchronisation via WP-Cron or Action Scheduler
* Manual synchronisation from the WordPress admin panel
* ETIM classification support for variation axes

**Requirements:**

* WooCommerce 8.0 or higher
* PHP 8.1 or higher
* An active Skwirrel account with API access

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/skwirrel-pim-wp-sync/`, or install the plugin directly through the WordPress plugin screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to WooCommerce > Skwirrel Sync to configure the plugin.
4. Enter your Skwirrel API URL and authentication token.
5. Click 'Sync now' to start the first synchronisation.

== Frequently Asked Questions ==

= Which Skwirrel API version is supported? =

The plugin works with the Skwirrel JSON-RPC 2.0 API.

= How often are products synchronised? =

You can set an automatic schedule (hourly, twice daily, or daily) or synchronise manually from the settings page.

= Are existing products overwritten? =

The plugin uses the Skwirrel external ID as a unique key. Existing products are updated, not duplicated.

== Changelog ==

= 1.2.0 =
* Rebranded to Skwirrel PIM Sync
* WordPress Plugin Check compliance improvements

= 1.1.2 =
* Version bump

= 1.0.0 =
* Initial release
* Full product synchronisation
* Variable products with ETIM variation axes
* Image and document import
* Delta synchronisation support
