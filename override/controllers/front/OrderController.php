<?php

class OrderController extends OrderControllerCore
{
    /**
     * AJAX check triggered immediately before submitting the payment gateway form.
     * When Redis Payment Stock Reservation (Option B) is enabled, atomically reserves
     * every item in the customer's cart in Redis DB 3 before redirecting to the payment provider.
     */
    public function displayAjaxCheckCartStillOrderable(): void
    {
        $responseData = [
            'errors' => false,
            'cartUrl' => '',
        ];

        $isOrderable = (
            $this->context->cart->isAllProductsInStock() === true
            && $this->context->cart->checkAllProductsAreStillAvailableInThisState() === true
            && $this->context->cart->checkAllProductsHaveMinimalQuantities() === true
        );

        if ($isOrderable && method_exists('StockAvailable', 'tryReserveWholeCartForPayment')) {
            if (!StockAvailable::tryReserveWholeCartForPayment($this->context->cart)) {
                $isOrderable = false;
            }
        }

        if (!$isOrderable) {
            $responseData['errors'] = true;
            $responseData['cartUrl'] = $this->context->link->getPageLink('cart', null, null, ['action' => 'show']);
        }

        header('Content-Type: application/json');
        $this->ajaxRender(json_encode($responseData));
    }
}
