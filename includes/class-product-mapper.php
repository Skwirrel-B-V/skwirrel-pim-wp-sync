<?php
/**
 * Skwirrel → WooCommerce Product Mapper.
 *
 * Maps Skwirrel product data to WooCommerce product fields.
 * Schema reference: Context/getProducts.json (ProductExporterSchema-v12)
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Skwirrel_WC_Sync_Product_Mapper {

    private const EXTERNAL_ID_META = '_skwirrel_external_id';
    private const PRODUCT_ID_META = '_skwirrel_product_id';
    private const SYNCED_AT_META = '_skwirrel_synced_at';
    public const CATEGORY_ID_META = '_skwirrel_category_id';

    private Skwirrel_WC_Sync_Logger $logger;
    private Skwirrel_WC_Sync_Media_Importer $media_importer;
    private string $image_language;
    private Skwirrel_WC_Sync_Etim_Extractor $etim;
    private Skwirrel_WC_Sync_Custom_Class_Extractor $custom_class;
    private Skwirrel_WC_Sync_Attachment_Handler $attachment;

    public function __construct() {
        $this->logger = new Skwirrel_WC_Sync_Logger();
        $this->media_importer = new Skwirrel_WC_Sync_Media_Importer();
        $this->image_language = get_option('skwirrel_wc_sync_settings', [])['image_language'] ?? 'nl';
        $this->etim = new Skwirrel_WC_Sync_Etim_Extractor($this->image_language);
        $this->custom_class = new Skwirrel_WC_Sync_Custom_Class_Extractor($this->image_language);
        $this->attachment = new Skwirrel_WC_Sync_Attachment_Handler($this->image_language);
    }

    // ------------------------------------------------------------------
    // Core field mapping
    // ------------------------------------------------------------------

    public function get_unique_key(array $product): ?string {
        $external = $product['external_product_id'] ?? null;
        if (!empty($external) && is_string($external)) {
            return 'ext:' . $external;
        }
        $code = $product['internal_product_code'] ?? null;
        if (!empty($code) && is_string($code)) {
            return 'sku:' . $code;
        }
        $code = $product['manufacturer_product_code'] ?? null;
        if (!empty($code) && is_string($code)) {
            return 'sku:' . $code;
        }
        $id = $product['product_id'] ?? null;
        if ($id !== null && $id !== '') {
            return 'id:' . $id;
        }
        return null;
    }

    /**
     * Get SKU for WooCommerce. Uses setting use_sku_field.
     */
    public function get_sku(array $product): string {
        $opts = get_option('skwirrel_wc_sync_settings', []);
        $field = $opts['use_sku_field'] ?? 'internal_product_code';
        if ($field === 'manufacturer_product_code') {
            $sku = (string) ($product['manufacturer_product_code'] ?? $product['internal_product_code'] ?? '');
        } else {
            $sku = (string) ($product['internal_product_code'] ?? $product['manufacturer_product_code'] ?? '');
        }
        if ($sku === '' && isset($product['product_id'])) {
            return 'SKW-' . $product['product_id'];
        }
        return $sku;
    }

    /**
     * Get product name. Prefer product_erp_description, then translations.
     */
    public function get_name(array $product): string {
        $erp = $product['product_erp_description'] ?? '';
        if (!empty($erp)) {
            return $erp;
        }
        $translations = $product['_product_translations'] ?? [];
        if (!empty($translations)) {
            $t = $this->pick_translation($translations);
            return $t['product_model'] ?? $t['product_description'] ?? '';
        }
        return '';
    }

    /**
     * Get short description.
     */
    public function get_short_description(array $product): string {
        $translations = $product['_product_translations'] ?? [];
        if (empty($translations)) {
            return '';
        }
        $t = $this->pick_translation($translations);
        return $t['product_description'] ?? '';
    }

    /**
     * Get long description.
     */
    public function get_long_description(array $product): string {
        $translations = $product['_product_translations'] ?? [];
        if (empty($translations)) {
            return '';
        }
        $t = $this->pick_translation($translations);
        return $t['product_long_description'] ?? $t['product_marketing_text'] ?? $t['product_web_text'] ?? '';
    }

    /**
     * Get product status: publish, draft, trash.
     */
    public function get_status(array $product): string {
        if (!empty($product['product_trashed_on'])) {
            return 'trash';
        }
        $status = $product['_product_status']['product_status_description'] ?? null;
        if ($status && stripos((string) $status, 'draft') !== false) {
            return 'draft';
        }
        return 'publish';
    }

    /**
     * Get price from first trade item.
     */
    public function get_price(array $product): ?float {
        $trade_items = $product['_trade_items'] ?? [];
        foreach ($trade_items as $ti) {
            $prices = $ti['_trade_item_prices'] ?? [];
            foreach ($prices as $p) {
                if (!empty($p['price_on_request'])) {
                    return null; // price on request
                }
                $net = $p['net_price'] ?? null;
                if ($net !== null && $net >= 0) {
                    return (float) $net;
                }
            }
        }
        return null;
    }

    /**
     * Get regular price. Same as get_price for now (no sale mapping).
     */
    public function get_regular_price(array $product): ?float {
        return $this->get_price($product);
    }

    /**
     * Get price on request flag.
     */
    public function is_price_on_request(array $product): bool {
        $trade_items = $product['_trade_items'] ?? [];
        foreach ($trade_items as $ti) {
            $prices = $ti['_trade_item_prices'] ?? [];
            foreach ($prices as $p) {
                if (!empty($p['price_on_request'])) {
                    return true;
                }
            }
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Attachment delegation (see Skwirrel_WC_Sync_Attachment_Handler)
    // ------------------------------------------------------------------

    public function get_image_attachment_ids(array $product, int $product_id = 0): array {
        return $this->attachment->get_image_attachment_ids($product, $product_id);
    }

    public function get_downloadable_files(array $product, int $product_id = 0): array {
        return $this->attachment->get_downloadable_files($product, $product_id);
    }

    public function get_document_attachments(array $product, int $product_id = 0): array {
        return $this->attachment->get_document_attachments($product, $product_id);
    }

    // ------------------------------------------------------------------
    // Attributes
    // ------------------------------------------------------------------

    /**
     * Get attributes/specs for product (custom attributes, no taxonomy needed).
     * Includes Manufacturer, GTIN, and ETIM features from _product_groups._etim._etim_features.
     */
    public function get_attributes(array $product): array {
        $attrs = [];
        $manufacturer = $product['manufacturer_name'] ?? '';
        if (!empty($manufacturer)) {
            $attrs['Manufacturer'] = $manufacturer;
        }
        $gtin = $product['product_gtin'] ?? '';
        if (!empty($gtin)) {
            $attrs['GTIN'] = $gtin;
        }
        $etim = $this->etim->get_etim_attributes($product);
        foreach ($etim as $name => $value) {
            if ($value !== '' && $value !== null) {
                $attrs[$name] = (string) $value;
            }
        }
        $product_id = $product['internal_product_code'] ?? $product['product_id'] ?? '?';
        $etimItems = $this->etim->collect_etim_items($product);
        $this->logger->verbose('get_attributes result', [
            'product' => $product_id,
            'total_attrs' => count($attrs),
            'base_attrs' => ['manufacturer' => !empty($manufacturer), 'gtin' => !empty($gtin)],
            'etim_attrs' => count($etim),
            'etim_items' => count($etimItems),
        ]);
        if (empty($attrs)) {
            $this->logger->debug('Product has no attributes', [
                'product' => $product_id,
                'has_brand' => !empty($product['brand_name'] ?? ''),
                'has_manufacturer' => !empty($product['manufacturer_name'] ?? ''),
                'has_gtin' => !empty($product['product_gtin'] ?? ''),
                'has__etim' => isset($product['_etim']),
                'etim_items_count' => count($etimItems),
                'has_product_groups' => !empty($product['_product_groups'] ?? []),
            ]);
        } elseif (empty($etim) && !empty($etimItems)) {
            $this->logger->debug('Product has base attributes but no ETIM values; features may be not_applicable', [
                'product' => $product_id,
                'attr_count' => count($attrs),
                'etim_features_count' => count($etimItems[0]['_etim_features'] ?? []),
            ]);
        }
        return $attrs;
    }

    // ------------------------------------------------------------------
    // ETIM delegation (see Skwirrel_WC_Sync_Etim_Extractor)
    // ------------------------------------------------------------------

    public function resolve_etim_feature_label(array $feature, string $lang = ''): string {
        return $this->etim->resolve_etim_feature_label($feature, $lang);
    }

    public function get_etim_feature_values_for_codes(array $product, array $etim_codes, string $lang = ''): array {
        return $this->etim->get_etim_feature_values_for_codes($product, $etim_codes, $lang);
    }

    // ------------------------------------------------------------------
    // Categories
    // ------------------------------------------------------------------

    public function get_categories(array $product): array {
        $categories = [];
        $seen_ids = [];

        // Primary source: _categories array (from include_categories API flag)
        $raw_cats = $product['_categories'] ?? [];
        if (!empty($raw_cats) && is_array($raw_cats)) {
            foreach ($raw_cats as $cat) {
                if (!is_array($cat)) {
                    continue;
                }

                // Walk the _parent_category chain first so ancestors are added
                // before the leaf category. This inserts from root → leaf.
                $this->extract_ancestor_chain($cat, $categories, $seen_ids);
            }
        }

        // Fallback: _product_groups (legacy — only group name, no ID)
        if (empty($categories)) {
            $groups = $product['_product_groups'] ?? [];
            foreach ($groups as $g) {
                $name = (string) ($g['product_group_name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $group_id = $g['product_group_id'] ?? $g['id'] ?? null;
                $categories[] = [
                    'id' => $group_id !== null ? (int) $group_id : null,
                    'name' => $name,
                    'parent_id' => null,
                    'parent_name' => '',
                ];
            }
        }

        // Deduplicate by name (for entries without ID, or fallback source)
        $unique = [];
        $names_seen = [];
        foreach ($categories as $cat) {
            $key = strtolower($cat['name']);
            if (isset($names_seen[$key])) {
                continue;
            }
            $names_seen[$key] = true;
            $unique[] = $cat;
        }

        $product_id = $product['internal_product_code'] ?? $product['product_id'] ?? '?';
        $this->logger->verbose('get_categories result', [
            'product' => $product_id,
            'source' => !empty($raw_cats) ? '_categories' : '_product_groups',
            'count' => count($unique),
            'names' => array_column($unique, 'name'),
        ]);

        return $unique;
    }

    /**
     * Recursively walk the _parent_category chain and add each ancestor
     * (root-first) plus the category itself to $categories.
     *
     * Deduplicates by Skwirrel category ID so that categories shared by
     * multiple products or referenced as both leaf and ancestor appear once.
     *
     * @param array                $cat        Single category entry from the API
     * @param array<int, array>    &$categories Accumulated category list (mutated)
     * @param array<int|string, bool> &$seen_ids  Tracks already-added Skwirrel IDs (mutated)
     */
    private function extract_ancestor_chain(array $cat, array &$categories, array &$seen_ids): void {
        $cat_id = $cat['category_id'] ?? $cat['product_category_id'] ?? $cat['id'] ?? null;
        $name = $this->pick_category_translation($cat);
        if ($name === '') {
            $name = (string) ($cat['category_name'] ?? $cat['product_category_name'] ?? $cat['name'] ?? '');
        }
        if ($name === '') {
            return;
        }

        $parent_id = $cat['parent_category_id'] ?? $cat['parent_id'] ?? null;
        $parent_name = '';

        // Recurse into _parent_category first (so ancestors are added root→leaf)
        if (!empty($cat['_parent_category']) && is_array($cat['_parent_category'])) {
            $this->extract_ancestor_chain($cat['_parent_category'], $categories, $seen_ids);
            $parent_name = $this->pick_category_translation($cat['_parent_category']);
            if ($parent_name === '') {
                $parent_name = (string) ($cat['_parent_category']['category_name'] ?? $cat['_parent_category']['name'] ?? '');
            }
            // Ensure parent_id is set from the nested object if not on the current entry
            if ($parent_id === null) {
                $parent_id = $cat['_parent_category']['category_id']
                    ?? $cat['_parent_category']['product_category_id']
                    ?? $cat['_parent_category']['id']
                    ?? null;
            }
        }
        $parent_name = $parent_name ?: (string) ($cat['parent_category_name'] ?? $cat['parent_name'] ?? '');

        // Skip if we already added this exact category (by ID)
        if ($cat_id !== null && isset($seen_ids[$cat_id])) {
            return;
        }

        if ($cat_id !== null) {
            $seen_ids[$cat_id] = true;
        }

        $categories[] = [
            'id' => $cat_id !== null ? (int) $cat_id : null,
            'name' => $name,
            'parent_id' => $parent_id !== null ? (int) $parent_id : null,
            'parent_name' => $parent_name,
        ];
    }

    /**
     * Pick translated category name using existing pick_translation pattern.
     */
    private function pick_category_translation(array $cat): string {
        $translations = $cat['_category_translations'] ?? $cat['_translations'] ?? [];
        if (empty($translations) || !is_array($translations)) {
            return '';
        }
        $t = $this->pick_translation($translations);
        return (string) ($t['category_name'] ?? $t['product_category_name'] ?? $t['name'] ?? '');
    }

    /**
     * Get category names from product groups (backward-compatible wrapper).
     */
    public function get_category_names(array $product): array {
        $categories = $this->get_categories($product);
        return array_values(array_unique(array_column($categories, 'name')));
    }

    // ------------------------------------------------------------------
    // Meta key accessors
    // ------------------------------------------------------------------

    public function get_external_id_meta_key(): string {
        return self::EXTERNAL_ID_META;
    }

    public function get_product_id_meta_key(): string {
        return self::PRODUCT_ID_META;
    }

    public function get_synced_at_meta_key(): string {
        return self::SYNCED_AT_META;
    }

    // ------------------------------------------------------------------
    // Translation helper
    // ------------------------------------------------------------------

    private function pick_translation(array $translations): array {
        $locale = get_locale();
        $lang = substr($locale, 0, 2);
        foreach ($translations as $t) {
            $l = $t['language'] ?? '';
            if (str_starts_with($l, $lang)) {
                return $t;
            }
        }
        return $translations[0] ?? [];
    }

    // ------------------------------------------------------------------
    // Custom class delegation (see Skwirrel_WC_Sync_Custom_Class_Extractor)
    // ------------------------------------------------------------------

    public static function parse_custom_class_filter(string $raw): array {
        return Skwirrel_WC_Sync_Custom_Class_Extractor::parse_custom_class_filter($raw);
    }

    public function get_custom_class_attributes(
        array $product,
        bool $include_trade_items = false,
        string $filter_mode = '',
        array $filter_ids = [],
        array $filter_codes = []
    ): array {
        return $this->custom_class->get_custom_class_attributes($product, $include_trade_items, $filter_mode, $filter_ids, $filter_codes);
    }

    public function get_custom_class_text_meta(
        array $product,
        bool $include_trade_items = false,
        string $filter_mode = '',
        array $filter_ids = [],
        array $filter_codes = []
    ): array {
        return $this->custom_class->get_custom_class_text_meta($product, $include_trade_items, $filter_mode, $filter_ids, $filter_codes);
    }
}
