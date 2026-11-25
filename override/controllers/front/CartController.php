<?php

use PrestaShop\PrestaShop\Core\Domain\Product\Stock\ValueObject\OutOfStockType;

class CartController extends CartControllerCore
{
    protected function processChangeProductInCart()
    {
        $mode = (Tools::getIsset('update') && $this->id_product) ? 'update' : 'add';
        $ErrorKey = ('update' === $mode) ? 'updateOperationError' : 'errors';

        if (Tools::getIsset('group')) {
            $this->id_product_attribute = (int) Product::getIdProductAttributeByIdAttributes(
                $this->id_product,
                Tools::getValue('group')
            );
        }

        if ($this->qty == 0) {
            $this->{$ErrorKey}[] = $this->trans(
                'Null quantity.',
                [],
                'Shop.Notifications.Error'
            );
        } elseif (!$this->id_product) {
            $this->{$ErrorKey}[] = $this->trans(
                'Product not found',
                [],
                'Shop.Notifications.Error'
            );
        }

        $product = new Product($this->id_product, true, $this->context->language->id);
        if (!$product->id || !$product->active || !$product->checkAccess($this->context->cart->id_customer)) {
            $this->{$ErrorKey}[] = $this->trans(
                'This product (%product%) is no longer available.',
                ['%product%' => $product->name],
                'Shop.Notifications.Error'
            );

            return;
        }

        if (!$this->id_product_attribute && $product->hasAttributes()) {
            $minimum_quantity = ($product->out_of_stock == OutOfStockType::OUT_OF_STOCK_DEFAULT)
                ? !Configuration::get('PS_ORDER_OUT_OF_STOCK')
                : !$product->out_of_stock;
            $this->id_product_attribute = Product::getDefaultAttribute($product->id, (int) $minimum_quantity);
            // @todo do something better than a redirect admin !!
            if (!$this->id_product_attribute) {
                Tools::redirectAdmin($this->context->link->getProductLink($product));
            }
        }

        $qty_to_check = $this->qty;
        $cart_products = $this->context->cart->getProducts();

        if (is_array($cart_products)) {
            foreach ($cart_products as $cart_product) {
                if ($this->productInCartMatchesCriteria($cart_product)) {
                    $qty_to_check = $cart_product['cart_quantity'];

                    if (Tools::getValue('op', 'up') == 'down') {
                        $qty_to_check -= $this->qty;
                    } else {
                        $qty_to_check += $this->qty;
                    }

                    break;
                }
            }
        }

        // Check product quantity availability
        if ('update' !== $mode && $this->shouldAvailabilityErrorBeRaised($product, $qty_to_check)) {
            /*
             * If the product can't be in the cart in this quantity, we raise an error.
             * For the purpose of this error message, we must get the real quantity in stock.
             * No subtracting of quantity in the cart here.
             */
            $availableProductQuantity = Product::getQuantity($this->id_product, $this->id_product_attribute);
            $this->errors[] = $this->trans(
                'You can only buy %quantity% "%product%". Please adjust the quantity in your cart to continue.',
                [
                    '%product%' => $product->name,
                    '%quantity%' => $availableProductQuantity,
                ],
                'Shop.Notifications.Error'
            );

            return;
        }

        // Check minimal_quantity
        if (!$this->id_product_attribute) {
            if ($qty_to_check < $product->minimal_quantity) {
                $this->errors[] = $this->trans(
                    'The minimum purchase order quantity for the product %product% is %quantity%.',
                    ['%product%' => $product->name, '%quantity%' => $product->minimal_quantity],
                    'Shop.Notifications.Error'
                );

                return;
            }
        } else {
            $combination = new Combination($this->id_product_attribute);
            if ($qty_to_check < $combination->minimal_quantity) {
                $this->errors[] = $this->trans(
                    'The minimum purchase order quantity for the product %product% is %quantity%.',
                    ['%product%' => $product->name, '%quantity%' => $combination->minimal_quantity],
                    'Shop.Notifications.Error'
                );

                return;
            }
        }

        // If no errors, process product addition
        if (!$this->errors) {
            // Add cart if no cart found
            if (!$this->context->cart->id) {
                if (Context::getContext()->cookie->id_guest) {
                    $guest = new Guest((int) Context::getContext()->cookie->id_guest);
                    $this->context->cart->mobile_theme = $guest->mobile_theme;
                }
                $this->context->cart->add();
                if (Validate::isLoadedObject($this->context->cart)) {
                    $this->context->cookie->id_cart = (int) $this->context->cart->id;
                }
            }

            // Check customizable fields
            if (!$product->hasAllRequiredCustomizableFields() && !$this->customization_id) {
                $this->{$ErrorKey}[] = $this->trans(
                    'Please fill in all of the required fields, and then save your customizations.',
                    [],
                    'Shop.Notifications.Error'
                );
            }

            // Atomic Redis DB 3 Cart Stock Reservation (Option A)
            $redisReserveResult = null;
            if (
                empty($this->{$ErrorKey})
                && $this->context->cart->id
                && method_exists('StockAvailable', 'isReservationEnabled')
                && StockAvailable::isReservationEnabled('cart')
            ) {
                $redisReserveResult = StockAvailable::tryReserveCartItem(
                    (int) $this->context->cart->id,
                    (int) $this->id_product,
                    (int) $this->id_product_attribute,
                    max(0, (int) $qty_to_check),
                    'cart',
                    (int) $this->context->shop->id
                );
                if (!$redisReserveResult['ok']) {
                    $this->{$ErrorKey}[] = $this->trans(
                        'You can only buy %quantity% "%product%". Please adjust the quantity in your cart to continue.',
                        [
                            '%product%' => $product->name,
                            '%quantity%' => (int) $redisReserveResult['available'],
                        ],
                        'Shop.Notifications.Error'
                    );
                    return;
                }
            }

            if ($redisReserveResult !== null && property_exists('StockAvailable', 'skipNextCartUpdateQtyReserve')) {
                StockAvailable::$skipNextCartUpdateQtyReserve = true;
            }
            try {
                $update_quantity = $this->context->cart->updateQty(
                    $this->qty,
                    $this->id_product,
                    $this->id_product_attribute,
                    $this->customization_id,
                    Tools::getValue('op', 'up'),
                    $this->id_address_delivery,
                    null,
                    true,
                    true
                );
            } finally {
                if (property_exists('StockAvailable', 'skipNextCartUpdateQtyReserve')) {
                    StockAvailable::$skipNextCartUpdateQtyReserve = false;
                }
            }
            if ($redisReserveResult !== null && ($update_quantity < 0 || !$update_quantity)) {
                StockAvailable::restoreCartItemReservation(
                    (int) $this->context->cart->id,
                    (int) $this->id_product,
                    (int) $this->id_product_attribute,
                    $redisReserveResult['prev_payload'],
                    (int) $this->context->shop->id
                );
            }
            if ($update_quantity < 0) {
                // If product has attribute, minimal quantity is set with minimal quantity of attribute
                $minimal_quantity = ($this->id_product_attribute)
                    ? ProductAttribute::getAttributeMinimalQty($this->id_product_attribute)
                    : $product->minimal_quantity;
                $this->{$ErrorKey}[] = $this->trans(
                    'You must add %quantity% minimum quantity',
                    ['%quantity%' => $minimal_quantity],
                    'Shop.Notifications.Error'
                );
            } elseif (!$update_quantity) {
                $this->errors[] = $this->trans(
                    'You already have the maximum quantity available for this product.',
                    [],
                    'Shop.Notifications.Error'
                );
            } elseif ($this->shouldAvailabilityErrorBeRaised($product, $qty_to_check)) {
                /*
                 * If the product can't be in the cart in this quantity, we raise an error.
                 * For the purpose of this error message, we must get the real quantity in stock.
                 * No subtracting of quantity in the cart here.
                 */
                $availableProductQuantity = Product::getQuantity($this->id_product, $this->id_product_attribute);
                $this->{$ErrorKey}[] = $this->trans(
                    'You can only buy %quantity% "%product%". Please adjust the quantity in your cart to continue.',
                    [
                        '%product%' => $product->name,
                        '%quantity%' => $availableProductQuantity,
                    ],
                    'Shop.Notifications.Error'
                );
            }
        }

        // Check validity of all cart rules in cart and check if there are some automatic ones that should be applied
        CartRule::autoRemoveFromCart();
        CartRule::autoAddToCart();

        // Finally check that all other products are also available, but only if there was no previous error
        if ('add' !== $mode && empty($this->{$ErrorKey})) {
            $areProductsAvailable = $this->areProductsAvailable();
            if (true !== $areProductsAvailable) {
                $this->{$ErrorKey}[] = $areProductsAvailable;
            }
        }
    }
}
