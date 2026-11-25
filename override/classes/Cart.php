<?php
/**
 * Cart override — Atomic Redis Stock Reservations for 1-Click / Express Checkouts
 * and direct Cart API mutations (Apple Pay, Google Pay, PayPal Express, etc.).
 */
class Cart extends CartCore
{
    /**
     * Atomically reserve/update Redis stock holds whenever any controller or module
     * mutates a cart via Cart::updateQty() (such as product-page Express Checkouts),
     * skipping duplicate work when CartController or Cart::duplicate() handles it.
     */
    public function updateQty(
        $quantity,
        $id_product,
        $id_product_attribute = null,
        $id_customization = false,
        $operator = 'up',
        $id_address_delivery = 0,
        Shop $shop = null,
        $auto_add_cart_rule = true,
        $skipAvailabilityCheckOutOfStock = false,
        bool $preserveGiftRemoval = true,
        bool $useOrderPrices = false
    ) {
        if (
            (property_exists('StockAvailable', 'skipNextCartUpdateQtyReserve') && StockAvailable::$skipNextCartUpdateQtyReserve)
            || (property_exists('StockAvailable', 'duplicatingFromCartId') && StockAvailable::$duplicatingFromCartId > 0)
            || !$this->id
            || (int) $id_product <= 0
            || (int) $quantity <= 0
            || !method_exists('StockAvailable', 'getActiveReservationStageForCartMutation')
            || !in_array($operator, ['up', 'down'], true)
        ) {
            return parent::updateQty(
                $quantity,
                $id_product,
                $id_product_attribute,
                $id_customization,
                $operator,
                $id_address_delivery,
                $shop,
                $auto_add_cart_rule,
                $skipAvailabilityCheckOutOfStock,
                $preserveGiftRemoval,
                $useOrderPrices
            );
        }

        $idShop = (int) ($shop ? $shop->id : ($this->id_shop ?: (Context::getContext()->shop->id ?? 1)));
        $stage = StockAvailable::getActiveReservationStageForCartMutation($idShop);
        if ($stage === null) {
            return parent::updateQty(
                $quantity,
                $id_product,
                $id_product_attribute,
                $id_customization,
                $operator,
                $id_address_delivery,
                $shop,
                $auto_add_cart_rule,
                $skipAvailabilityCheckOutOfStock,
                $preserveGiftRemoval,
                $useOrderPrices
            );
        }

        $idp = (int) $id_product;
        $ipa = (int) $id_product_attribute;
        $currentQty = (int) Db::getInstance()->getValue(
            'SELECT COALESCE(SUM(`quantity`), 0) FROM `' . _DB_PREFIX_ . 'cart_product`'
            . ' WHERE `id_cart` = ' . (int) $this->id
            . ' AND `id_product` = ' . $idp
            . ' AND `id_product_attribute` = ' . $ipa
        );
        $targetQty = ($operator === 'down') ? max(0, $currentQty - (int) $quantity) : ($currentQty + (int) $quantity);

        $reserveResult = StockAvailable::tryReserveCartItem((int) $this->id, $idp, $ipa, $targetQty, $stage, $idShop);
        if (!$reserveResult['ok']) {
            return false;
        }

        $result = parent::updateQty(
            $quantity,
            $id_product,
            $id_product_attribute,
            $id_customization,
            $operator,
            $id_address_delivery,
            $shop,
            $auto_add_cart_rule,
            $skipAvailabilityCheckOutOfStock,
            $preserveGiftRemoval,
            $useOrderPrices
        );
        if ($result < 0 || !$result) {
            StockAvailable::restoreCartItemReservation((int) $this->id, $idp, $ipa, $reserveResult['prev_payload'], $idShop);
        }

        return $result;
    }

    /**
     * Alias duplicated carts (e.g. Buckaroo Express cart/checkout duplication) to
     * the canonical source cart so holds do not self-block and are released cleanly.
     */
    public function duplicate()
    {
        if (!Validate::isLoadedObject($this) || !method_exists('StockAvailable', 'linkDuplicatedCart')) {
            return parent::duplicate();
        }

        $oldCartId = (int) $this->id;
        $idShop = (int) ($this->id_shop ?: (Context::getContext()->shop->id ?? 1));

        StockAvailable::$duplicatingFromCartId = $oldCartId;
        try {
            $result = parent::duplicate();
        } finally {
            StockAvailable::$duplicatingFromCartId = 0;
        }

        if (!is_array($result) || empty($result['success']) || !Validate::isLoadedObject($result['cart'])) {
            return $result;
        }

        $newCart = $result['cart'];
        if (StockAvailable::isReservationEnabled(null, $idShop)) {
            StockAvailable::linkDuplicatedCart($oldCartId, (int) $newCart->id, $idShop);
            $stage = StockAvailable::getActiveReservationStageForCartMutation($idShop);
            if ($stage !== null && method_exists('StockAvailable', 'tryReserveWholeCart') && !StockAvailable::tryReserveWholeCart($newCart, $stage)) {
                StockAvailable::unlinkDuplicatedCart((int) $newCart->id, $idShop);
                $newCart->delete();

                return ['cart' => false, 'success' => false];
            }
        }

        return $result;
    }

    /**
     * Keep Redis item holds aligned (or released) when a product is removed via
     * Cart::deleteProduct() outside CartController.
     */
    public function deleteProduct(
        $id_product,
        $id_product_attribute = 0,
        $id_customization = 0,
        $id_address_delivery = 0,
        bool $preserveGiftsRemoval = true,
        bool $useOrderPrices = false
    ) {
        $res = parent::deleteProduct(
            $id_product,
            $id_product_attribute,
            $id_customization,
            $id_address_delivery,
            $preserveGiftsRemoval,
            $useOrderPrices
        );

        if ($res && $this->id && (int) $id_product > 0 && method_exists('StockAvailable', 'releaseCartItemReservation')) {
            $idp = (int) $id_product;
            $ipa = (int) $id_product_attribute;
            $idShop = (int) ($this->id_shop ?: (Context::getContext()->shop->id ?? 1));
            $remaining = (int) Db::getInstance()->getValue(
                'SELECT COALESCE(SUM(`quantity`), 0) FROM `' . _DB_PREFIX_ . 'cart_product`'
                . ' WHERE `id_cart` = ' . (int) $this->id
                . ' AND `id_product` = ' . $idp
                . ' AND `id_product_attribute` = ' . $ipa
            );
            if ($remaining <= 0) {
                StockAvailable::releaseCartItemReservation((int) $this->id, $idp, $ipa, $idShop);
            } elseif (method_exists('StockAvailable', 'isReservationEnabled') && StockAvailable::isReservationEnabled('cart', $idShop)) {
                StockAvailable::tryReserveCartItem((int) $this->id, $idp, $ipa, $remaining, 'cart', $idShop);
            }
        }

        return $res;
    }

    /**
     * When an express duplicate cart is deleted, only unlink its alias so the shopper's
     * source cart keeps its reservation; when a standalone/source cart is deleted,
     * release all holds immediately and flush shop FPC if holds existed.
     */
    public function delete()
    {
        $cartId = (int) $this->id;
        $idShop = (int) ($this->id_shop ?: (Context::getContext()->shop->id ?? 1));
        $res = parent::delete();

        if ($res && $cartId > 0 && method_exists('StockAvailable', 'unlinkDuplicatedCart')) {
            $wasAlias = StockAvailable::unlinkDuplicatedCart($cartId, $idShop);
            if (!$wasAlias && method_exists('StockAvailable', 'releaseAllCartReservations')) {
                StockAvailable::releaseAllCartReservations($cartId, $idShop, true);
            }
        }

        return $res;
    }
}
