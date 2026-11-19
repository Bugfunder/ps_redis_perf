<?php

class GroupReduction extends GroupReductionCore
{
    /**
     * Bulk single-query replacement for core's multi-statement INSERT loop when duplicating products.
     */
    public static function duplicateReduction($id_product_old, $id_product)
    {
        $res = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS(
            '
			SELECT pgr.`id_product`, pgr.`id_group`, pgr.`reduction`
			FROM `' . _DB_PREFIX_ . 'product_group_reduction_cache` pgr
			WHERE pgr.`id_product` = ' . (int) $id_product_old
        );

        if (!$res) {
            return true;
        }

        $values = [];
        foreach ($res as $row) {
            $values[] = '(' . (int) $id_product . ', ' . (int) $row['id_group'] . ', ' . (float) $row['reduction'] . ')';
        }

        $query = 'INSERT INTO `' . _DB_PREFIX_ . 'product_group_reduction_cache` (`id_product`, `id_group`, `reduction`) VALUES ' . implode(', ', $values) . ' ON DUPLICATE KEY UPDATE `reduction` = VALUES(`reduction`)';

        return Db::getInstance()->execute($query);
    }
}
