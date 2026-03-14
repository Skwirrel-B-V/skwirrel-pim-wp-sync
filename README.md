# Skwirrel PIM sync for WooCommerce

**Version 2.0.7** — WordPress plugin that synchronises products from the Skwirrel PIM system to WooCommerce via a JSON-RPC 2.0 API.

## Description

Skwirrel PIM sync for WooCommerce connects your WooCommerce webshop to the Skwirrel PIM system. Products, variations, categories, brands, manufacturers, images, and documents are synchronised automatically or on demand.

**Features:**

* Full and delta (incremental) product synchronisation
* Simple and variable product support with ETIM classification for variation axes
* Automatic category tree sync with parent-child hierarchy
* Brand sync via WooCommerce native `product_brand` taxonomy
* Manufacturer sync with dedicated `product_manufacturer` taxonomy
* Product image and document import into the WordPress media library
* Custom class attributes (alphanumeric, logical, numeric, range, date, multi)
* Configurable product URL slugs (source field, suffix, update on re-sync)
* GTIN and manufacturer product code search filter on the product list page
* Scheduled synchronisation via WP-Cron or Action Scheduler
* Manual synchronisation from the admin dashboard
* Sync progress banner with live phase checklist and counters
* Date-grouped sync history (last 20 runs)
* Stale product and category purge after full sync
* Delete protection: warnings and automatic full re-sync when Skwirrel items are deleted in WooCommerce
* Multilingual support with 7 locales (nl_NL, nl_BE, de_DE, fr_FR, fr_BE, en_US, en_GB)

## Requirements

- WordPress 6.0 or higher
- WooCommerce 8.0 or higher (9.6+ recommended for native brand support; tested up to 10.6)
- PHP 8.1 or higher
- An active Skwirrel account with API access

## Installation

1. Upload the plugin files to `/wp-content/plugins/skwirrel-pim-sync/`, or install the plugin directly through the WordPress plugin screen.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Navigate to **WooCommerce** → **Skwirrel Sync** to configure the plugin.
4. Enter your Skwirrel subdomain and API authentication token.
5. Click **Test connection** to verify the API connection.
6. Click **Sync now** to start the first synchronisation.

## Settings

| Setting | Description |
|---------|-------------|
| **Skwirrel subdomain** | Your Skwirrel subdomain (e.g. `yourcompany` → `yourcompany.skwirrel.eu/jsonrpc`) |
| **API token** | Authentication token for the Skwirrel API |
| **Timeout** | HTTP request timeout in seconds (5–120) |
| **Retries** | Number of retry attempts on failure (0–5) |
| **Sync interval** | Disabled, hourly, twice daily, daily, or weekly |
| **Batch size** | Products per API request (1–500) |
| **Selection IDs** | Comma-separated selection IDs to sync specific selections only. Leave empty for all. |
| **Super category ID** | Root category ID for category tree sync |
| **Sync categories** | Create and assign WooCommerce categories from Skwirrel |
| **Sync manufacturers** | Sync manufacturer names into `product_manufacturer` taxonomy |
| **Sync images** | Download product images into the WordPress media library |
| **SKU field** | `internal_product_code` or `manufacturer_product_code` |
| **Purge stale products** | Trash products no longer in Skwirrel after a full sync |
| **Delete warning** | Show warning banners on Skwirrel-managed items |
| **Languages** | Language codes sent with API calls + preferred language for image titles |
| **Verbose logging** | Enable detailed per-product log output |

### Permalink Settings

Product URL slug settings are configured on **Settings** → **Permalinks**:

| Setting | Description |
|---------|-------------|
| **Slug source field** | Primary field for the product URL slug (product name, SKU, manufacturer code, external ID, or Skwirrel ID) |
| **Slug suffix field** | Suffix appended when the slug already exists (or leave empty for WP auto-numbering) |
| **Update slug on re-sync** | Also update slugs for existing products during sync (not just new products) |

## How sync works

1. **Manual**: Click **Sync now** on the dashboard. The sync runs in the background via an asynchronous HTTP request. Progress is shown live on the dashboard.
2. **Scheduled**: Configure a sync interval; the plugin uses WP-Cron or Action Scheduler.
3. **Upsert logic**: Existing products (matched by external ID, SKU, or Skwirrel ID) are updated; new products are created.
4. **Delta sync**: Scheduled syncs only fetch products modified since the last sync (`updated_on >= last_sync`).
5. **Purge**: After a full sync (if enabled), products and categories no longer in Skwirrel are moved to trash.

## Delete protection

Skwirrel is the source of truth: products managed by Skwirrel will be recreated on the next sync if deleted in WooCommerce.

- **Warning banner**: a yellow banner on the product edit page indicates the product is managed by Skwirrel.
- **Confirmation dialog**: deleting a Skwirrel product or category in the list shows a JavaScript confirmation.
- **Automatic full sync**: when a Skwirrel item is deleted in WooCommerce, the next scheduled sync automatically runs as a full sync.

The warning can be disabled via the "Delete warning" setting.

## Field mapping

| Skwirrel | WooCommerce |
|----------|-------------|
| `internal_product_code` / `manufacturer_product_code` | SKU |
| `external_product_id` | Post meta `_skwirrel_external_id` |
| `product_erp_description` | Product name |
| `_product_translations[].product_description` | Short description |
| `_product_translations[].product_long_description` | Long description |
| `_trade_item_prices[].net_price` | Regular price (first trade item) |
| `getGroupedProducts` (optional) | Variable products; grouped products become variations |
| `_attachments` (type IMG) | Featured image + gallery |
| `_attachments` (type MAN, DAT, etc.) | Downloadable files / documents |
| `brand_name` | Product brand (`product_brand` taxonomy) |
| `manufacturer_name` | Product manufacturer (`product_manufacturer` taxonomy) |
| `_categories[]` / `_product_groups[]` | Product categories (with parent-child hierarchy) |
| `_etim` / `_custom_classes` | Product attributes |

## Troubleshooting

### Connection test fails
- Verify the subdomain is correct
- Check that the API token is valid and not expired
- Ensure the server allows outgoing HTTPS connections

### Sync times out
- The sync runs in the background; there should be no page timeout.
- Lower the batch size (e.g. 50) if background sync still has issues.
- Increase the timeout (e.g. 60 seconds) in settings.

### Sync does not start in the background
- Some hosts block HTTP requests from the server to itself (loopback requests). Ask your host to allow loopback requests.

### No products synchronised
- Check the logs via the dashboard **Sync Logs** link or **WooCommerce** → **Status** → **Logs**
- Verify that the API token has the correct permissions

### Duplicate products
- The plugin uses `external_product_id` or `internal_product_code` as unique key. Ensure these fields are correctly filled in Skwirrel.

## Logging

The plugin uses the WooCommerce logger (`wc_get_logger`). Logs are available at:
- **WooCommerce** → **Status** → **Logs** → source: `skwirrel-pim-sync`

## Translations

The plugin includes translations for the following languages:

| Language | Locale |
|----------|--------|
| Dutch (Netherlands) | `nl_NL` |
| Dutch (Belgium) | `nl_BE` |
| English (US) | `en_US` |
| English (GB) | `en_GB` |
| German | `de_DE` |
| French (France) | `fr_FR` |
| French (Belgium) | `fr_BE` |

## Development

### Prerequisites

```bash
composer install
```

### Running tests

```bash
vendor/bin/pest
```

### Static analysis

```bash
vendor/bin/phpstan analyse
```

### Code style

```bash
vendor/bin/phpcs        # check
vendor/bin/phpcbf       # auto-fix
```

### Quality checks (run before every commit)

```bash
vendor/bin/pest            # Unit tests
vendor/bin/phpstan analyse # Static analysis (level 6)
vendor/bin/phpcs           # Code style (WordPress standards)
```

## License

GPL v2 or later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).
