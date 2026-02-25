=== Skwirrel PIM Sync ===
Contributors: skwirrel
Tags: woocommerce, sync, erp, pim, skwirrel
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.3.2
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

= 1.3.2 =
* i18n: all UI strings switched to English source text
* Updated translation files (POT + nl_NL, nl_BE, de_DE, fr_FR, fr_BE, en_US, en_GB)
* Added new translation entries for tabbed UI, custom classes, danger zone and delete protection
* Recompiled all .mo binary translation files

= 1.3.1 =
* Deep category tree sync: full ancestor chain from nested _parent_category (unlimited depth)
* Custom Class sync: product-level and trade-item-level custom classes as WooCommerce attributes
* Custom Class feature types: A (alphanumeric), M (multi), L (logical), N (numeric), R (range), D (date), I (internationalized)
* Custom Class text types T and B stored as product meta (_skwirrel_cc_* prefix)
* Whitelist/blacklist filtering on custom class ID or code
* New settings: sync_custom_classes, sync_trade_item_custom_classes, custom_class_filter_mode, custom_class_filter_ids

= 1.3.0 =
* Admin UI: tabbed layout (Sync Products, Instellingen, Logs)
* Sync status and history now shown on the default Sync Products tab
* Sync button moved to page title, visible on all tabs
* Logs and variation debug instructions on dedicated Logs tab
* Fixed GitHub release workflow: version is read from plugin file, no more auto-incrementing

= 1.2.3 =
* WordPress Plugin Check compliance: translators comments, ordered placeholders, escape output
* WordPress Plugin Check compliance: phpcs:ignore for direct DB queries, non-prefixed WooCommerce globals, nonce verification
* Use WordPress alternative functions (wp_parse_url, wp_delete_file, wp_is_writable)
* Translate readme.txt to English

= 1.2.2 =
* Version bump in preparation for release

= 1.2.1 =
* Update text domain and constants for Skwirrel PIM Sync rebranding

= 1.2.0 =
* Rebranded to Skwirrel PIM Sync
* Added unit tests for MediaImporter, ProductMapper, and related components
* Added WordPress.org auto-deploy workflow
* Added automated versioning, tagging, and release workflow

= 1.1.2 =
* Version bump
* Fix duplicate products during sync: 3-step lookup chain + SKU conflict prevention

= 1.1.1 =
* Delete protection: warning banners on Skwirrel-managed products and categories
* Purge stale products and categories after full sync
* Category sync with parent-child hierarchy support
* Collection ID filter for selective synchronisation
* Translation files (POT + nl_NL, nl_BE, en_US, en_GB, de_DE, fr_FR, fr_BE)
* New settings: purge_stale_products, show_delete_warning, collection_ids, sync_categories, include_languages, image_language
* PHPStan, PHP_CodeSniffer, and Pest PHP test framework
* WooCommerce 10.5 compatibility

= 1.0.0 =
* Initial release
* Full product synchronisation
* Variable products with ETIM variation axes
* Image and document import
* Delta synchronisation support
