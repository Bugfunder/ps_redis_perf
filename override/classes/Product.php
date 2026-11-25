<?php

class Product extends ProductCore
{
    public static $bulk_images = null;
    public static $bulk_combination_images = null;
    protected static $single_images_cache = [];
    protected static $single_combination_images_cache = [];

    public function getImages($id_lang, Context $context = null)
    {
        if (self::$bulk_images !== null && isset(self::$bulk_images[$this->id])) {
            return self::$bulk_images[$this->id];
        }
        if (self::$bulk_images !== null && !isset(self::$bulk_images[$this->id])) {
            return [];
        }
        $pid = (int) $this->id;
        if (!defined('_PS_ADMIN_DIR_') && $pid > 0) {
            $idShop = (int) (($context && $context->shop) ? $context->shop->id : (Context::getContext()->shop->id ?? 1));
            $cacheKey = $idShop . '-' . $pid . '-' . (int) $id_lang;
            if (array_key_exists($cacheKey, self::$single_images_cache)) {
                return self::$single_images_cache[$cacheKey];
            }
            self::$single_images_cache[$cacheKey] = parent::getImages($id_lang, $context);

            return self::$single_images_cache[$cacheKey];
        }

        return parent::getImages($id_lang, $context);
    }

    public function getCombinationImages($id_lang)
    {
        if (self::$bulk_combination_images !== null && isset(self::$bulk_combination_images[$this->id])) {
            return self::$bulk_combination_images[$this->id];
        }
        if (self::$bulk_combination_images !== null && !isset(self::$bulk_combination_images[$this->id])) {
            return [];
        }
        $pid = (int) $this->id;
        if (!defined('_PS_ADMIN_DIR_') && $pid > 0) {
            $cacheKey = $pid . '-' . (int) $id_lang;
            if (array_key_exists($cacheKey, self::$single_combination_images_cache)) {
                return self::$single_combination_images_cache[$cacheKey];
            }
            self::$single_combination_images_cache[$cacheKey] = parent::getCombinationImages($id_lang);

            return self::$single_combination_images_cache[$cacheKey];
        }

        return parent::getCombinationImages($id_lang);
    }

    public static function prefetchFrontFeatures($id_lang, array $productIds)
    {
        if (empty($productIds)) {
            return;
        }
        $idList = implode(',', array_map('intval', $productIds));
        $rows = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS('
            SELECT pf.id_product, name, value, pf.id_feature, f.position, fvl.id_feature_value
            FROM ' . _DB_PREFIX_ . 'feature_product pf
            LEFT JOIN ' . _DB_PREFIX_ . 'feature_lang fl ON (fl.id_feature = pf.id_feature AND fl.id_lang = ' . (int) $id_lang . ')
            LEFT JOIN ' . _DB_PREFIX_ . 'feature_value_lang fvl ON (fvl.id_feature_value = pf.id_feature_value AND fvl.id_lang = ' . (int) $id_lang . ')
            LEFT JOIN ' . _DB_PREFIX_ . 'feature f ON (f.id_feature = pf.id_feature AND fl.id_lang = ' . (int) $id_lang . ')
            ' . Shop::addSqlAssociation('feature', 'f') . '
            WHERE pf.id_product IN (' . $idList . ')
            ORDER BY f.position ASC
        ');

        foreach ($productIds as $pid) {
            self::$_frontFeaturesCache[$pid . '-' . $id_lang] = [];
        }

        if ($rows) {
            foreach ($rows as $row) {
                self::$_frontFeaturesCache[$row['id_product'] . '-' . $id_lang][] = $row;
            }
        }
    }
}
