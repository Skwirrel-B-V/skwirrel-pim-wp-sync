# Changelog — Skwirrel PIM sync for WooCommerce

All notable changes to Skwirrel PIM sync for WooCommerce will be documented in this file.

## [1.9.8]

* Add "Skwirrel" meta box on the WooCommerce product edit screen (above Publish) — shows Skwirrel product ID, last sync time, and a "Sync this product" button for quick single-product sync via the API

## [1.9.7]

* Add configurable "Product manufacturer base" slug on Settings → Permalinks page — allows customizing the URL base for the product_manufacturer taxonomy (like WooCommerce's brand base field)

## [1.9.6]

* Fix product sync failing when downloadable files are not in WooCommerce's approved directory — downloads/documents errors are now caught and logged as warnings, so category, brand and manufacturer assignment always proceeds

## [1.9.5]

* Brand sync is now always active — uses WooCommerce native product_brand taxonomy (available since WC 9.4)
* Add "Sync manufacturers" setting: registers product_manufacturer taxonomy, syncs manufacturer_name from Skwirrel products
* Manufacturer attribute no longer duplicated as product attribute when synced as taxonomy
* Default product list columns: hide Tags, show Manufacturers
* Add "Filter by manufacturer" dropdown on product list page
* Manufacturers column ordered after Brands, before Date

## [1.9.3]

* Fix variable product variation attributes: recover parent attribute options from child variation post meta when deferred terms are empty (e.g. when getProducts lacks _etim_features)
* Convert non-variation parent attributes to global WooCommerce taxonomy-based attributes (consistent with simple products)
* Fix brand not assigned to variable products: propagate brand_name from child variations to parent, since getGroupedProducts response lacks brand_name
* Fix categories not assigned to variable products: propagate _categories from child variations to parent (same root cause)

## [1.9.2]

* Remove legacy pa_variant migration code (no live installs to migrate)
* Fix simple product attributes: save as global WooCommerce taxonomy-based attributes instead of custom text attributes, so they appear in layered navigation and product filters

## [1.9.1]

* Remove legacy pre-1.8.0 Action Scheduler cleanup code (old slug reference)

## [1.9.0]

* Move remaining inline event handlers (onchange, onclick) to enqueued inline script for WordPress.org compliance
* Fix stale debug log path in admin help text (now correctly points to uploads/skwirrel-pim-sync/ subfolder)

## [1.8.4]

* Add non-variation ETIM and custom class attributes to parent variable products during sync
* Variation product attributes (from getProducts) are now automatically collected and merged onto the parent as visible, non-variation attributes

## [1.8.3]

* Fix empty variation attribute dropdowns on variable products by deferring parent attribute term updates to a single batch flush after all variations are processed

## [1.8.2]

* Replace all inline `<script>` and `<style>` tags with proper `wp_enqueue_script`/`wp_add_inline_script`/`wp_add_inline_style` calls
* Rename plugin display name to "Skwirrel PIM sync for WooCommerce"

## [1.8.1]

* Fix variation attribute labels showing raw ETIM codes (e.g. "EF002671") instead of human-readable names
* Add missing `include_etim_translations` and `include_languages` to `getGroupedProducts` API call

## [1.8.0]

* Rename plugin slug from `skwirrel-pim-wp-sync` to `skwirrel-pim-sync` (WordPress.org restricts "wp" in plugin slugs)
* Update text domain, Action Scheduler group, logger source, and admin page slug
* Rename main plugin file and all language files to match new slug
* Add activation cleanup for old Action Scheduler group from pre-1.8.0
* Existing settings, synced products, and translations are fully preserved

## [1.7.1]

* Remove deprecated `load_plugin_textdomain()` call (WordPress 4.6+ auto-loads translations)
* Fix unescaped SQL parameters in purge handler: use `$wpdb->prepare()` with placeholders
* Fix direct database query caching warning in taxonomy manager
* WordPress Plugin Check compliance improvements

## [1.7.0]

* Slug settings moved to Settings → Permalinks page (alongside WooCommerce product permalinks)
* New "Update slug on re-sync" option: when enabled, existing product slugs are updated during sync (not just new products)
* Slug resolver: exclude current product ID from duplicate check when updating existing products
* New class: Permalink_Settings — dedicated settings on the WordPress Permalinks page
* Backward compatible: migrates existing slug settings from plugin settings to new permalink option
* Sync history: new "Trigger" column showing Manual, Scheduled, or Purge
* Purge (delete all) now adds an entry to sync history with purge details
* Purge no longer clears the last sync status — previous sync results remain visible
* Purge rows highlighted in yellow in history table
* New dedicated option: `skwirrel_wc_sync_permalinks` for slug configuration
* Updated translation files (POT + nl_NL, nl_BE, de_DE, fr_FR, fr_BE, en_US, en_GB)
* New unit tests: SlugResolverTest (16 tests covering all source fields, groups, backward compat)

## [1.6.0]

* Product slug configuration: choose slug source field (product name, SKU, manufacturer code, external ID, Skwirrel ID)
* Slug suffix on duplicate: configurable fallback field appended when slug already exists
* New class: Slug_Resolver — resolves product URL slugs based on admin settings
* Slugs only set for new products to preserve existing URLs
* Updated translation files with slug-related strings

## [1.5.0]

* Major refactoring: SyncService split from ~2200 lines into focused sub-classes
* New class: ProductUpserter — all product creation/update logic
* New class: ProductLookup — database lookup methods for Skwirrel meta
* New class: SyncHistory — sync result persistence and heartbeat management
* New class: PurgeHandler — stale product/category cleanup
* New class: CategorySync — category tree sync and per-product assignment
* New class: BrandSync — brand taxonomy sync
* New class: TaxonomyManager — ETIM and custom class taxonomy management
* New class: EtimExtractor — ETIM attribute parsing from ProductMapper
* New class: CustomClassExtractor — custom class feature handling from ProductMapper
* New class: AttachmentHandler — image/document import from ProductMapper
* SyncService reduced to ~480 lines (pure orchestrator)
* ProductMapper reduced to ~460 lines (delegates to focused sub-classes)
* All existing public APIs preserved — no breaking changes

## [1.4.0]

* Brand sync: Skwirrel brands synced into WooCommerce product_brand taxonomy
* Category tree sync: sync full category tree from a configurable super category ID
* Sync progress indicator: spinning icon on menu item, blue status bar with auto-refresh
* Sync button disabled while sync is in progress
* Heartbeat mechanism: sync status auto-expires after 60s without activity
* Purge: danger zone now also deletes product brands
* Settings save clears sync-in-progress state
* i18n: all UI strings switched to English source text
* Updated translation files (POT + nl_NL, nl_BE, de_DE, fr_FR, fr_BE, en_US, en_GB)

## [1.3.2]

* i18n: all UI strings switched to English source text
* Updated translation files (POT + nl_NL, nl_BE, de_DE, fr_FR, fr_BE, en_US, en_GB)
* Added new translation entries for tabbed UI, custom classes, danger zone and delete protection
* Recompiled all .mo binary translation files

## [1.3.1]

* Deep category tree sync: full ancestor chain from nested _parent_category (unlimited depth)
* Custom Class sync: product-level and trade-item-level custom classes as WooCommerce attributes
* Custom Class feature types: A (alphanumeric), M (multi), L (logical), N (numeric), R (range), D (date), I (internationalized)
* Custom Class text types T and B stored as product meta (_skwirrel_cc_* prefix)
* Whitelist/blacklist filtering on custom class ID or code
* New settings: sync_custom_classes, sync_trade_item_custom_classes, custom_class_filter_mode, custom_class_filter_ids

## [1.3.0]

* Admin UI: tabbed layout (Sync Products, Instellingen, Logs)
* Sync status and history now shown on the default Sync Products tab
* Sync button moved to page title, visible on all tabs
* Logs and variation debug instructions on dedicated Logs tab
* Fixed GitHub release workflow: version is read from plugin file, no more auto-incrementing

## [1.2.3]

* WordPress Plugin Check compliance: translators comments, ordered placeholders, escape output
* WordPress Plugin Check compliance: phpcs:ignore for direct DB queries, non-prefixed WooCommerce globals, nonce verification
* Use WordPress alternative functions (wp_parse_url, wp_delete_file, wp_is_writable)
* Translate readme.txt to English

## [1.2.2]

* Version bump in preparation for release

## [1.2.1]

* Update text domain and constants for Skwirrel PIM Sync rebranding

## [1.2.0]

* Rebranded to Skwirrel PIM Sync
* Added unit tests for MediaImporter, ProductMapper, and related components
* Added WordPress.org auto-deploy workflow
* Added automated versioning, tagging, and release workflow

## [1.1.2]

* Version bump
* Fix duplicate products during sync: 3-step lookup chain + SKU conflict prevention

## [1.1.1]

* Delete protection: warning banners on Skwirrel-managed products and categories
* Purge stale products and categories after full sync
* Category sync with parent-child hierarchy support
* Collection ID filter for selective synchronisation
* Translation files (POT + nl_NL, nl_BE, en_US, en_GB, de_DE, fr_FR, fr_BE)
* New settings: purge_stale_products, show_delete_warning, collection_ids, sync_categories, include_languages, image_language
* PHPStan, PHP_CodeSniffer, and Pest PHP test framework
* WooCommerce 10.5 compatibility

## [1.0.0]

* Initial release
* Full product synchronisation
* Variable products with ETIM variation axes
* Image and document import
* Delta synchronisation support
