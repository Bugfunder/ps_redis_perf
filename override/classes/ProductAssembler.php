<?php
/**
 * High-performance ProductAssembler override.
 * Offloads product property assembly, combination stock, and price hydration
 * to MariaDB ps_product_listing_view, speeding up category listings by ~5-10x.
 */
class ProductAssembler extends ProductAssemblerCore
{
    protected static $listingCache = [];
    protected static $taxNameCache = [];

    /**
     * Resolve localized tax names by id_tax_rules_group once per request.
     *
     * @param int $idCountry
     * @param int $idLang
     * @return array<int,string>
     */
    protected static function getTaxNameMap($idCountry, $idLang)
    {
        $key = (int) $idCountry . '_' . (int) $idLang;
        if (isset(self::$taxNameCache[$key])) {
            return self::$taxNameCache[$key];
        }
        $map = [];
        try {
            $rows = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS(
                'SELECT tr.`id_tax_rules_group`, tl.`name`
                 FROM `' . _DB_PREFIX_ . 'tax_rule` tr
                 INNER JOIN `' . _DB_PREFIX_ . 'tax_lang` tl ON (tl.`id_tax` = tr.`id_tax` AND tl.`id_lang` = ' . (int) $idLang . ')
                 WHERE tr.`id_country` = ' . (int) $idCountry
            );
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    $gid = (int) $r['id_tax_rules_group'];
                    if (!isset($map[$gid]) && !empty($r['name'])) {
                        $map[$gid] = (string) $r['name'];
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fall back to rate label
        }
        self::$taxNameCache[$key] = $map;
        return $map;
    }

    /**
     * Fast-path product assembly using MariaDB ps_product_listing_view.
     *
     * @param array $rawProducts
     * @return array
     */
    public function assembleProducts(array $rawProducts)
    {
        if (empty($rawProducts)) {
            return [];
        }

        if (Configuration::getGlobalValue('PS_REDIS_PERF_SQL_VIEW_ENABLED') === '0') {
            return parent::assembleProducts($rawProducts);
        }

        $productIds = [];
        foreach ($rawProducts as $rp) {
            if (!empty($rp['id_product'])) {
                $productIds[] = (int) $rp['id_product'];
            }
        }

        if (empty($productIds)) {
            return parent::assembleProducts($rawProducts);
        }

        $context = Context::getContext();

        // If customer belongs to a non-standard default group (e.g. B2B wholesale), fall back to core
        $standardGroups = array_filter([
            0,
            (int) (Configuration::get('PS_UNIDENTIFIED_GROUP') ?: 1),
            (int) (Configuration::get('PS_GUEST_GROUP') ?: 2),
            (int) (Configuration::get('PS_CUSTOMER_GROUP') ?: 3),
        ], function ($v) {
            return $v >= 0;
        });
        $idCustomerGroup = ($context && $context->customer) ? (int) $context->customer->id_default_group : 0;
        if (!in_array($idCustomerGroup, $standardGroups, true)) {
            return parent::assembleProducts($rawProducts);
        }

        $idList = implode(',', $productIds);

        $idShop = $context->shop ? (int) $context->shop->id : 1;
        $idLang = $context->language ? (int) $context->language->id : 1;

        $cacheKey = $idShop . '_' . $idLang . '_' . $idList;
        if (isset(self::$listingCache[$cacheKey])) {
            $rows = self::$listingCache[$cacheKey];
        } else {
            // Fetch pre-computed rows from high-speed SQL VIEW (gracefully fall back if view is absent)
            $sql = 'SELECT * FROM `' . _DB_PREFIX_ . 'product_listing_view`
                    WHERE `id_shop` = ' . $idShop . '
                    AND `id_lang` = ' . $idLang . '
                    AND `id_product` IN (' . $idList . ')';

            try {
                $rows = Db::getInstance()->executeS($sql);
            } catch (\Throwable $e) {
                return parent::assembleProducts($rawProducts);
            }
            if (!empty($rows) && count($rows) === count($productIds)) {
                self::$listingCache[$cacheKey] = $rows;
            }
        }

        // If view returned fewer rows or failed, safely fall back to core logic
        if (empty($rows) || count($rows) !== count($productIds)) {
            return parent::assembleProducts($rawProducts);
        }
        
        // --- BULK PREFETCHING FOR ZERO-DB-HIT RENDERING ---
        
        // 1. Bulk Images
        $bulkImages = Db::getInstance()->executeS('
            SELECT image_shop.`cover`, i.`id_image`, il.`legend`, i.`position`, i.`id_product`
            FROM `' . _DB_PREFIX_ . 'image` i
            ' . Shop::addSqlAssociation('image', 'i') . '
            LEFT JOIN `' . _DB_PREFIX_ . 'image_lang` il ON (i.`id_image` = il.`id_image` AND il.`id_lang` = ' . (int)$idLang . ')
            WHERE i.`id_product` IN (' . $idList . ')
            ORDER BY `position`
        ');
        Product::$bulk_images = array_fill_keys($productIds, []);
        if ($bulkImages) {
            foreach ($bulkImages as $img) {
                Product::$bulk_images[$img['id_product']][] = $img;
            }
        }
        
        // 1.5 Bulk Front Features
        Product::prefetchFrontFeatures((int)$idLang, $productIds);
        
        // 2. Bulk Combination Images
        $bulkComboImages = Db::getInstance()->executeS('
            SELECT pai.`id_image`, pai.`id_product_attribute`, il.`legend`, pa.`id_product`
            FROM `' . _DB_PREFIX_ . 'product_attribute_image` pai
            LEFT JOIN `' . _DB_PREFIX_ . 'product_attribute` pa ON pa.`id_product_attribute` = pai.`id_product_attribute`
            LEFT JOIN `' . _DB_PREFIX_ . 'image_lang` il ON (il.`id_image` = pai.`id_image` AND il.`id_lang` = ' . (int)$idLang . ')
            LEFT JOIN `' . _DB_PREFIX_ . 'image` i ON (i.`id_image` = pai.`id_image`)
            WHERE pa.`id_product` IN (' . $idList . ') 
            ORDER BY i.`position`
        ');
        Product::$bulk_combination_images = array_fill_keys($productIds, []);
        if ($bulkComboImages) {
            foreach ($bulkComboImages as $img) {
                Product::$bulk_combination_images[$img['id_product']][$img['id_product_attribute']][] = $img;
            }
        }
        
        // 3. Bulk Stock Availability
        StockAvailable::prefetchStockForProducts($productIds, $idShop);

        $link = $context->link;
        $isoCode = ($context->language && !empty($context->language->iso_code)) ? $context->language->iso_code : 'en';
        $nbDaysNew = Configuration::get('PS_NB_DAYS_NEW_PRODUCT');
        $nbDaysNew = ($nbDaysNew !== false && $nbDaysNew !== null && $nbDaysNew !== '') ? (int) $nbDaysNew : 20;
        $newCutoff = time() - (86400 * max(0, $nbDaysNew));
        $idCountry = ($context->country && $context->country->id)
            ? (int) $context->country->id
            : (int) Configuration::get('PS_COUNTRY_DEFAULT');
        $taxNameMap = self::getTaxNameMap($idCountry, $idLang);
        $indexedRows = [];
        foreach ($rows as $row) {
            $id = (int) $row['id_product'];
            $mockProduct = (object)$row;
            $mockProduct->id = $id;
            $row['link'] = $link->getProductLink(
                $mockProduct,
                $row['link_rewrite'],
                $row['category'],
                $row['ean13']
            );
            $row['features'] = Product::getFrontFeaturesStatic((int) $idLang, $id);
            $row['id_shop_default'] = (int) ($row['id_shop_default'] ?? $idShop);
            $row['new'] = ($nbDaysNew > 0 && !empty($row['date_add']) && (strtotime($row['date_add']) > $newCutoff)) ? '1' : '0';
            $row['pack'] = (int) ($row['cache_is_pack'] ?? 0);
            $row['packItems'] = [];
            $row['nopackprice'] = 0;
            $row['customization_required'] = false;
            $row['virtual'] = (int) ($row['is_virtual'] ?? 0);
            $row['attachments'] = [];
            $row['attribute_price'] = 0.0;
            $taxGroupId = (int) ($row['id_tax_rules_group'] ?? 0);
            if ($taxGroupId > 0 && isset($taxNameMap[$taxGroupId])) {
                $row['tax_name'] = $taxNameMap[$taxGroupId];
            } else {
                $row['tax_name'] = !empty($row['rate']) ? 'VAT ' . (float) $row['rate'] . '%' : '';
            }
            $row['ecotax_rate'] = 0.0;
            $row['unit_price_tax_excluded'] = 0.0;
            $row['unit_price_tax_included'] = 0.0;
            $row['attributes'] = [];
            $row['specific_prices'] = [];
            
            // Fix string conversion of 0.000000 from MySQL which evaluates to true
            if (empty($row['specific_prices_json'])) {
                $row['reduction'] = 0;
                $row['reduction_without_tax'] = 0;
                $row['has_discount'] = false;
            }

            // Hydrate specific prices from JSON
            if (!empty($row['specific_prices_json'])) {
                $sp = json_decode($row['specific_prices_json'], true);
                if (is_array($sp)) {
                    $row['specific_prices'] = $sp;
                    
                    // Recalculate price
                    $row['price_without_reduction'] = $row['price'];
                    if ($sp['reduction_type'] == 'percentage') {
                        $row['reduction'] = (string) $sp['reduction'];
                        $row['price'] = (string) ($row['price_without_reduction'] * (1 - $sp['reduction']));
                    } else {
                        // For amount, we assume it's tax included for simplicity if reduction_tax = 1
                        $row['reduction'] = (string) $sp['reduction'];
                        $row['price'] = (string) ($row['price_without_reduction'] - $sp['reduction']);
                    }
                    $row['specific_prices']['reduction'] = (string) $sp['reduction'];
                    $row['specific_prices']['price'] = (string) $sp['price'];
                    
                    // Optional: flag it for templates
                    $row['has_discount'] = true;
                }
            }

            if (isset($row['available_date']) && $row['available_date'] === '0000-00-00') {
                $row['available_date'] = '';
            }
            if (!empty($row['cover_image_id'])) {
                $row['id_image'] = $id . '-' . $row['cover_image_id'];
            } else {
                $row['id_image'] = $isoCode . '-default';
            }
            $indexedRows[$id] = $row;
        }

        // Return products maintaining original sort order
        $results = [];
        foreach ($rawProducts as $rawProduct) {
            $id = (int) $rawProduct['id_product'];
            if (isset($indexedRows[$id])) {
                if(isset($rawProduct['reduction'])) unset($rawProduct['reduction']); $results[] = array_merge($indexedRows[$id], $rawProduct);
            } else {
                return parent::assembleProducts($rawProducts);
            }
        }

        return $results;
    }
}
