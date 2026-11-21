<?php
/**
 * StockAvailable override — bulk prefetching and Redis DB 3 Atomic Cart & Payment Stock Reservations (Zero-Oversell Drop Mode).
 */
class StockAvailable extends StockAvailableCore
{
    /**
     * Resolve the configured Redis DB index dedicated to stock reservations (default: 3).
     *
     * @return int
     */
    public static function getReserveDbIndex()
    {
        if (class_exists('Configuration', false) && Configuration::configurationIsLoaded()) {
            $db = (int) Configuration::getGlobalValue('PS_REDIS_PERF_RESERVE_DB');
            if ($db >= 1 && $db <= 15) {
                return $db;
            }
        }
        return 3;
    }

    /**
     * Resolve a collision-free Redis DB index for a shop's Full-Page Cache.
     * Keeps DB 0 reserved for PHP sessions and getReserveDbIndex() reserved for stock holds.
     *
     * @param int $idShop
     * @return int
     */
    public static function resolveFpcDbIndex($idShop)
    {
        $idShop = max(1, (int) $idShop);
        $reserveDb = self::getReserveDbIndex();
        $dbIndex = ($idShop < $reserveDb) ? $idShop : ($idShop + 1);
        return ($dbIndex >= 1 && $dbIndex <= 15 && $dbIndex !== $reserveDb) ? $dbIndex : 1;
    }

    /**
     * Open a configured Redis connection (TCP or Unix socket, optional AUTH) and select $dbIndex.
     *
     * @param int $dbIndex
     * @return \Redis|null
     */
    public static function connectPerfRedis($dbIndex)
    {
        if (!extension_loaded('redis') || !class_exists('Redis')) {
            return null;
        }
        $host = '127.0.0.1';
        $port = 6379;
        $password = '';
        if (class_exists('Configuration', false) && Configuration::configurationIsLoaded()) {
            $cfgHost = trim((string) Configuration::getGlobalValue('PS_REDIS_PERF_REDIS_HOST'));
            if ($cfgHost !== '') {
                $host = $cfgHost;
            }
            $cfgPort = (int) Configuration::getGlobalValue('PS_REDIS_PERF_REDIS_PORT');
            if ($cfgPort > 0 && $cfgPort <= 65535) {
                $port = $cfgPort;
            }
            $password = (string) Configuration::getGlobalValue('PS_REDIS_PERF_REDIS_PASSWORD');
        }
        try {
            $r = new \Redis();
            $connected = (strpos($host, '/') === 0)
                ? @$r->connect($host)
                : @$r->connect($host, $port, 0.05);
            if (!$connected) {
                return null;
            }
            if ($password !== '' && !@$r->auth($password)) {
                return null;
            }
            if (@$r->select((int) $dbIndex)) {
                return $r;
            }
        } catch (\Throwable $e) {
            // Fall through safely
        }
        return null;
    }

    /**
     * Connect to the Redis DB dedicated exclusively to atomic stock reservations,
     * completely isolated from session and full-page cache flushes.
     *
     * @return \Redis|null
     */
    public static function getReservationRedis()
    {
        static $redis = false;
        $reserveDb = self::getReserveDbIndex();
        if ($redis !== false) {
            if ($redis !== null) {
                @$redis->select($reserveDb);
            }
            return $redis;
        }
        $redis = self::connectPerfRedis($reserveDb);
        return $redis;
    }

    /**
     * Check whether Redis stock reservation is enabled for the given stage and shop.
     *
     * @param string|null $stage 'cart', 'pay', or null (either)
     * @param int|null $idShop
     * @return bool
     */
    public static function isReservationEnabled($stage = null, $idShop = null)
    {
        if (defined('_PS_ADMIN_DIR_')) {
            return false;
        }

        $cartEnabled = ((int) Configuration::getGlobalValue('PS_REDIS_PERF_RESERVE_CART_ENABLED') === 1);
        $payEnabled = ((int) Configuration::getGlobalValue('PS_REDIS_PERF_RESERVE_PAYMENT_ENABLED') === 1);

        if ($stage === 'cart' && !$cartEnabled) {
            return false;
        }
        if ($stage === 'pay' && !$payEnabled) {
            return false;
        }
        if ($stage === null && !$cartEnabled && !$payEnabled) {
            return false;
        }

        if ($idShop === null) {
            $context = Context::getContext();
            $idShop = (int) ($context->shop->id ?? 1);
        }
        $scope = trim((string) Configuration::getGlobalValue('PS_REDIS_PERF_RESERVE_SHOPS'));
        if ($scope !== '' && $scope !== 'all' && ctype_digit($scope) && (int) $scope !== (int) $idShop) {
            return false;
        }

        return true;
    }

    /**
     * Query fresh physical stock quantity and out_of_stock policy directly from MySQL
     * (bypassing PrestaShop's in-memory Cache so concurrent reservations see real-time stock).
     *
     * @param int $idProduct
     * @param int $idProductAttribute
     * @param int $idShop
     * @return array{quantity:int,out_of_stock:int}
     */
    protected static function fetchFreshPhysicalStock($idProduct, $idProductAttribute, $idShop)
    {
        $query = new DbQuery();
        $query->select('SUM(quantity) AS qty, MAX(out_of_stock) AS oos');
        $query->from('stock_available');
        $query->where('id_product = ' . (int) $idProduct);
        $query->where('id_product_attribute = ' . (int) $idProductAttribute);
        $query = StockAvailable::addSqlShopRestriction($query, (int) $idShop);

        $row = Db::getInstance()->getRow($query);
        $oos = isset($row['oos']) ? (int) $row['oos'] : 2;
        // Also check parent product out_of_stock row if combination row uses default (2)
        if ($oos === 2 && (int) $idProductAttribute > 0) {
            $qParent = new DbQuery();
            $qParent->select('out_of_stock');
            $qParent->from('stock_available');
            $qParent->where('id_product = ' . (int) $idProduct);
            $qParent->where('id_product_attribute = 0');
            $qParent = StockAvailable::addSqlShopRestriction($qParent, (int) $idShop);
            $parentOos = Db::getInstance()->getValue($qParent);
            if ($parentOos !== false && $parentOos !== null) {
                $oos = (int) $parentOos;
            }
        }

        return [
            'quantity' => isset($row['qty']) ? (int) $row['qty'] : 0,
            'out_of_stock' => $oos,
        ];
    }

    /**
     * Sum active (non-expired) reservations in Redis DB 3 for a single (shop, product, attribute),
     * excluding $excludeCartId and lazily pruning expired members.
     *
     * @param \Redis $redis
     * @param int $idShop
     * @param int $idProduct
     * @param int $idProductAttribute
     * @param int $excludeCartId
     * @return int
     */
    protected static function sumActiveItemReservations($redis, $idShop, $idProduct, $idProductAttribute, $excludeCartId)
    {
        $cartEnabled = self::isReservationEnabled('cart', $idShop);
        $payEnabled = self::isReservationEnabled('pay', $idShop);
        if (!$cartEnabled && !$payEnabled) {
            return 0;
        }

        $setKey = 'sres:item:s' . $idShop . ':' . $idProduct . ':' . $idProductAttribute;
        $cartIds = @$redis->sMembers($setKey);
        if (!is_array($cartIds) || empty($cartIds)) {
            return 0;
        }

        $now = time();
        $reserved = 0;
        foreach ($cartIds as $cidRaw) {
            $cid = (int) $cidRaw;
            if ($cid <= 0) {
                @$redis->sRem($setKey, $cidRaw);
                continue;
            }
            $hKey = 'sres:hold:s' . $idShop . ':' . $idProduct . ':' . $idProductAttribute . ':' . $cid;
            $val = @$redis->get($hKey);
            if (!is_string($val) || $val === '') {
                @$redis->sRem($setKey, $cidRaw);
                continue;
            }
            $parts = explode(':', $val);
            $qty = (int) ($parts[0] ?? 0);
            $exp = (int) ($parts[1] ?? 0);
            $stage = (string) ($parts[2] ?? 'cart');

            if ($qty <= 0 || $exp < $now) {
                @$redis->del($hKey);
                @$redis->sRem($setKey, $cidRaw);
                continue;
            }
            if ($cid === $excludeCartId) {
                continue;
            }
            if ($stage === 'cart' && !$cartEnabled) {
                continue;
            }
            if ($stage === 'pay' && !$payEnabled) {
                continue;
            }
            $reserved += $qty;
        }

        return $reserved;
    }

    /**
     * Calculate how many units of ($idProduct, $idProductAttribute) are currently reserved
     * in Redis DB 3 by OTHER customers' carts or checkout sessions.
     *
     * @param int $idProduct
     * @param int $idProductAttribute
     * @param int|null $idShop
     * @param int|null $excludeCartId
     * @return int
     */
    public static function getReservedQuantityByOthers($idProduct, $idProductAttribute = 0, $idShop = null, $excludeCartId = null)
    {
        $context = Context::getContext();
        if ($idShop === null) {
            $idShop = (int) ($context->shop->id ?? 1);
        }
        $idShop = (int) $idShop;
        if (!self::isReservationEnabled(null, $idShop)) {
            return 0;
        }

        if ($excludeCartId === null) {
            $excludeCartId = (int) ($context->cart->id ?? ($context->cookie->id_cart ?? 0));
        }

        $redis = self::getReservationRedis();
        if ($redis === null) {
            return 0;
        }

        try {
            if ((int) $idProductAttribute > 0) {
                return self::sumActiveItemReservations($redis, $idShop, (int) $idProduct, (int) $idProductAttribute, (int) $excludeCartId);
            }

            // When checking parent product (id_product_attribute == 0), sum across all reserved combinations
            $prodAttrsKey = 'sres:prod_attrs:s' . $idShop . ':' . (int) $idProduct;
            $attrs = @$redis->sMembers($prodAttrsKey);
            if (!is_array($attrs) || empty($attrs)) {
                return self::sumActiveItemReservations($redis, $idShop, (int) $idProduct, 0, (int) $excludeCartId);
            }
            $totalReserved = 0;
            foreach ($attrs as $ipa) {
                $totalReserved += self::sumActiveItemReservations($redis, $idShop, (int) $idProduct, (int) $ipa, (int) $excludeCartId);
            }
            return $totalReserved;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Atomically check physical stock + other active holds and reserve $targetQty in Redis DB 3
     * for ($idCart, $idProduct, $idProductAttribute) using a per-item Redis mutex lock.
     *
     * @param int $idCart
     * @param int $idProduct
     * @param int $idProductAttribute
     * @param int $targetQty Total desired quantity in this cart
     * @param string $stage 'cart' or 'pay'
     * @param int|null $idShop
     * @return array{ok:bool,available:int,physical:int,reserved_by_others:int,remaining_after:int,prev_payload:string|null}
     */
    public static function tryReserveCartItem($idCart, $idProduct, $idProductAttribute, $targetQty, $stage = 'cart', $idShop = null)
    {
        $idCart = (int) $idCart;
        $idProduct = (int) $idProduct;
        $idProductAttribute = (int) $idProductAttribute;
        $targetQty = (int) $targetQty;

        $context = Context::getContext();
        if ($idShop === null) {
            $idShop = (int) ($context->shop->id ?? 1);
        }
        $idShop = (int) $idShop;

        $defaultOk = [
            'ok' => true,
            'available' => $targetQty,
            'physical' => $targetQty,
            'reserved_by_others' => 0,
            'remaining_after' => 1,
            'prev_payload' => null,
        ];

        if ($idCart <= 0 || $idProduct <= 0 || !self::isReservationEnabled($stage, $idShop)) {
            return $defaultOk;
        }

        $redis = self::getReservationRedis();
        if ($redis === null) {
            return $defaultOk;
        }

        $lockKey = 'sres:lock:s' . $idShop . ':' . $idProduct . ':' . $idProductAttribute;
        $lockToken = uniqid('l', true);
        $locked = false;

        // Acquire short atomic mutex (max 1000ms wait in 10ms steps)
        for ($attempt = 0; $attempt < 100; $attempt++) {
            if (@$redis->set($lockKey, $lockToken, ['nx', 'px' => 2000])) {
                $locked = true;
                break;
            }
            usleep(10000);
        }

        try {
            $setKey = 'sres:item:s' . $idShop . ':' . $idProduct . ':' . $idProductAttribute;
            $hKey = 'sres:hold:s' . $idShop . ':' . $idProduct . ':' . $idProductAttribute . ':' . $idCart;
            $prodAttrsKey = 'sres:prod_attrs:s' . $idShop . ':' . $idProduct;
            $cartItemsKey = 'sres:cart_items:s' . $idShop . ':' . $idCart;
            $activeHoldsSet = 'sres:active_holds:s' . $idShop;

            $prevVal = @$redis->get($hKey);
            $prevPayload = is_string($prevVal) ? $prevVal : null;

            if ($targetQty <= 0) {
                @$redis->del($hKey);
                @$redis->sRem($setKey, (string) $idCart);
                @$redis->sRem($cartItemsKey, $idProduct . ':' . $idProductAttribute);
                @$redis->sRem($activeHoldsSet, $hKey);
                return $defaultOk;
            }

            $fresh = self::fetchFreshPhysicalStock($idProduct, $idProductAttribute, $idShop);
            $physicalQty = $fresh['quantity'];
            $allowOutOfStock = Product::isAvailableWhenOutOfStock($fresh['out_of_stock']);

            if ($allowOutOfStock) {
                return [
                    'ok' => true,
                    'available' => $targetQty,
                    'physical' => $physicalQty,
                    'reserved_by_others' => 0,
                    'remaining_after' => 1,
                    'prev_payload' => $prevPayload,
                ];
            }

            $reservedByOthers = self::sumActiveItemReservations($redis, $idShop, $idProduct, $idProductAttribute, $idCart);
            $availableForMe = max(0, $physicalQty - $reservedByOthers);

            if ($targetQty > $availableForMe) {
                if ($reservedByOthers > 0 && $targetQty <= $physicalQty) {
                    @$redis->incr('sres:stats:s' . $idShop . ':blocked');
                }
                return [
                    'ok' => false,
                    'available' => $availableForMe,
                    'physical' => $physicalQty,
                    'reserved_by_others' => $reservedByOthers,
                    'remaining_after' => $availableForMe,
                    'prev_payload' => $prevPayload,
                ];
            }

            $ttl = ($stage === 'pay')
                ? (int) Configuration::getGlobalValue('PS_REDIS_PERF_RESERVE_PAYMENT_TTL')
                : (int) Configuration::getGlobalValue('PS_REDIS_PERF_RESERVE_CART_TTL');
            if ($ttl < 30) {
                $ttl = ($stage === 'pay') ? 600 : 180;
            }

            // Never downgrade an existing 'pay' hold with longer remaining TTL back to a shorter 'cart' hold
            if ($stage === 'cart' && $prevPayload !== null) {
                $pParts = explode(':', $prevPayload);
                $prevExp = (int) ($pParts[1] ?? 0);
                $prevStage = (string) ($pParts[2] ?? 'cart');
                if ($prevStage === 'pay' && $prevExp > time() + $ttl) {
                    $stage = 'pay';
                    $ttl = $prevExp - time();
                }
            }

            $exp = time() + $ttl;
            $payload = $targetQty . ':' . $exp . ':' . $stage;
            @$redis->setex($hKey, $ttl, $payload);
            @$redis->sAdd($setKey, (string) $idCart);
            @$redis->expire($setKey, max($ttl, 1800));
            @$redis->sAdd($prodAttrsKey, (string) $idProductAttribute);
            @$redis->expire($prodAttrsKey, max($ttl, 1800));
            @$redis->sAdd($cartItemsKey, $idProduct . ':' . $idProductAttribute);
            @$redis->expire($cartItemsKey, max($ttl, 1800));
            @$redis->sAdd($activeHoldsSet, $hKey);
            @$redis->expire($activeHoldsSet, max($ttl, 1800));

            if ($stage === 'pay') {
                @$redis->incr('sres:stats:s' . $idShop . ':reserved_pay');
            } else {
                @$redis->incr('sres:stats:s' . $idShop . ':reserved_cart');
            }

            $remainingAfter = max(0, $availableForMe - $targetQty);

            // If we just reserved the last available unit, flush this shop's FPC DB so other shoppers see it immediately
            if ($remainingAfter === 0) {
                self::flushShopFpcCache($idShop);
            }

            return [
                'ok' => true,
                'available' => $availableForMe,
                'physical' => $physicalQty,
                'reserved_by_others' => $reservedByOthers,
                'remaining_after' => $remainingAfter,
                'prev_payload' => $prevPayload,
            ];
        } catch (\Throwable $e) {
            return $defaultOk;
        } finally {
            if ($locked) {
                try {
                    if (@$redis->get($lockKey) === $lockToken) {
                        @$redis->del($lockKey);
                    }
                } catch (\Throwable $e) {
                    // Ignore unlock failure
                }
            }
        }
    }

    /**
     * Restore a previous reservation payload (rollback helper if Cart::updateQty() or payment check fails).
     *
     * @param int $idCart
     * @param int $idProduct
     * @param int $idProductAttribute
     * @param string|null $prevPayload
     * @param int|null $idShop
     */
    public static function restoreCartItemReservation($idCart, $idProduct, $idProductAttribute, $prevPayload, $idShop = null)
    {
        $idCart = (int) $idCart;
        $idProduct = (int) $idProduct;
        $idProductAttribute = (int) $idProductAttribute;
        if ($idShop === null) {
            $idShop = (int) (Context::getContext()->shop->id ?? 1);
        }
        $idShop = (int) $idShop;

        $redis = self::getReservationRedis();
        if ($redis === null || $idCart <= 0 || $idProduct <= 0) {
            return;
        }
        $setKey = 'sres:item:s' . $idShop . ':' . $idProduct . ':' . $idProductAttribute;
        $hKey = 'sres:hold:s' . $idShop . ':' . $idProduct . ':' . $idProductAttribute . ':' . $idCart;
        $cartItemsKey = 'sres:cart_items:s' . $idShop . ':' . $idCart;
        $activeHoldsSet = 'sres:active_holds:s' . $idShop;

        try {
            if ($prevPayload === null || $prevPayload === '') {
                @$redis->del($hKey);
                @$redis->sRem($setKey, (string) $idCart);
                @$redis->sRem($cartItemsKey, $idProduct . ':' . $idProductAttribute);
                @$redis->sRem($activeHoldsSet, $hKey);
                return;
            }
            $parts = explode(':', $prevPayload);
            $exp = (int) ($parts[1] ?? 0);
            $remTtl = $exp - time();
            if ($remTtl > 0) {
                @$redis->setex($hKey, $remTtl, $prevPayload);
            } else {
                @$redis->del($hKey);
                @$redis->sRem($setKey, (string) $idCart);
                @$redis->sRem($cartItemsKey, $idProduct . ':' . $idProductAttribute);
                @$redis->sRem($activeHoldsSet, $hKey);
            }
        } catch (\Throwable $e) {
            // Ignore
        }
    }

    /**
     * Atomically reserve every product in $cart for payment ('pay' stage).
     * Rolls back all newly acquired holds in this call if any single product fails.
     *
     * @param Cart $cart
     * @return bool True if all items are reserved (or Option B disabled), false if out of stock
     */
    public static function tryReserveWholeCartForPayment(Cart $cart)
    {
        if (!$cart || !$cart->id) {
            return true;
        }
        $idShop = (int) ($cart->id_shop ?: (Context::getContext()->shop->id ?? 1));
        if (!self::isReservationEnabled('pay', $idShop)) {
            return true;
        }

        $products = $cart->getProducts(true);
        if (!is_array($products) || empty($products)) {
            return true;
        }

        // Aggregate quantities per (id_product, id_product_attribute)
        $needed = [];
        foreach ($products as $p) {
            $idp = (int) $p['id_product'];
            $ipa = (int) ($p['id_product_attribute'] ?? 0);
            $qty = (int) ($p['cart_quantity'] ?? $p['quantity'] ?? 0);
            if ($idp <= 0 || $qty <= 0) {
                continue;
            }
            $k = $idp . ':' . $ipa;
            if (!isset($needed[$k])) {
                $needed[$k] = ['idp' => $idp, 'ipa' => $ipa, 'qty' => 0];
            }
            $needed[$k]['qty'] += $qty;
        }

        $acquired = [];
        foreach ($needed as $item) {
            $res = self::tryReserveCartItem($cart->id, $item['idp'], $item['ipa'], $item['qty'], 'pay', $idShop);
            if (!$res['ok']) {
                // Roll back any holds upgraded/created during this payment attempt
                foreach ($acquired as $prev) {
                    self::restoreCartItemReservation($cart->id, $prev['idp'], $prev['ipa'], $prev['prev_payload'], $idShop);
                }
                return false;
            }
            $acquired[] = [
                'idp' => $item['idp'],
                'ipa' => $item['ipa'],
                'prev_payload' => $res['prev_payload'],
            ];
        }

        return true;
    }

    /**
     * Release a single item reservation held by a cart (e.g. when removed from cart).
     *
     * @param int $idCart
     * @param int $idProduct
     * @param int $idProductAttribute
     * @param int|null $idShop
     */
    public static function releaseCartItemReservation($idCart, $idProduct, $idProductAttribute = 0, $idShop = null)
    {
        $idCart = (int) $idCart;
        $idProduct = (int) $idProduct;
        $idProductAttribute = (int) $idProductAttribute;
        if ($idCart <= 0 || $idProduct <= 0) {
            return;
        }
        if ($idShop === null) {
            $idShop = (int) (Context::getContext()->shop->id ?? 1);
        }
        $idShop = (int) $idShop;

        $redis = self::getReservationRedis();
        if ($redis === null) {
            return;
        }
        try {
            $hKey = 'sres:hold:s' . $idShop . ':' . $idProduct . ':' . $idProductAttribute . ':' . $idCart;
            $existed = @$redis->del($hKey);
            @$redis->sRem('sres:item:s' . $idShop . ':' . $idProduct . ':' . $idProductAttribute, (string) $idCart);
            @$redis->sRem('sres:cart_items:s' . $idShop . ':' . $idCart, $idProduct . ':' . $idProductAttribute);
            @$redis->sRem('sres:active_holds:s' . $idShop, $hKey);
            if ($existed) {
                self::flushShopFpcCache($idShop);
            }
        } catch (\Throwable $e) {
            // Ignore
        }
    }

    /**
     * Release all Redis DB 3 stock reservations held by a cart (e.g. when an order is validated).
     *
     * @param int $idCart
     * @param int|null $idShop
     */
    public static function releaseAllCartReservations($idCart, $idShop = null)
    {
        $idCart = (int) $idCart;
        if ($idCart <= 0) {
            return;
        }
        if ($idShop !== null && (int) $idShop > 0) {
            $shopsToCheck = [(int) $idShop];
        } else {
            $shopsToCheck = array_map('intval', Shop::getShops(true, null, true));
            if (empty($shopsToCheck)) {
                $shopsToCheck = [1];
            }
        }

        $redis = self::getReservationRedis();
        if ($redis === null) {
            return;
        }
        foreach ($shopsToCheck as $sid) {
            try {
                $cartItemsKey = 'sres:cart_items:s' . $sid . ':' . $idCart;
                $items = @$redis->sMembers($cartItemsKey);
                if (is_array($items)) {
                    foreach ($items as $pair) {
                        $p = explode(':', (string) $pair);
                        $idp = (int) ($p[0] ?? 0);
                        $ipa = (int) ($p[1] ?? 0);
                        if ($idp > 0) {
                            $hKey = 'sres:hold:s' . $sid . ':' . $idp . ':' . $ipa . ':' . $idCart;
                            @$redis->del($hKey);
                            @$redis->sRem('sres:item:s' . $sid . ':' . $idp . ':' . $ipa, (string) $idCart);
                            @$redis->sRem('sres:active_holds:s' . $sid, $hKey);
                        }
                    }
                }
                @$redis->del($cartItemsKey);
            } catch (\Throwable $e) {
                // Ignore
            }
        }
    }

    /**
     * Return the number of seconds until the earliest active stock hold in Redis DB 3
     * for $idShop expires, or null if there are no active holds.
     * Used by FrontController to cap FPC TTL so abandoned holds reappear in stock immediately.
     *
     * @param int $idShop
     * @return int|null
     */
    public static function getNextExpiringHoldSeconds($idShop)
    {
        $idShop = (int) $idShop;
        if (!self::isReservationEnabled(null, $idShop)) {
            return null;
        }
        $redis = self::getReservationRedis();
        if ($redis === null) {
            return null;
        }
        try {
            $activeSetKey = 'sres:active_holds:s' . $idShop;
            $holdKeys = @$redis->sMembers($activeSetKey);
            if (!is_array($holdKeys) || empty($holdKeys)) {
                return null;
            }
            $now = time();
            $minSec = null;
            foreach ($holdKeys as $hk) {
                $val = @$redis->get($hk);
                if (!is_string($val) || $val === '') {
                    @$redis->sRem($activeSetKey, $hk);
                    continue;
                }
                $parts = explode(':', $val);
                $qty = (int) ($parts[0] ?? 0);
                $exp = (int) ($parts[1] ?? 0);
                if ($qty <= 0 || $exp <= $now) {
                    @$redis->sRem($activeSetKey, $hk);
                    continue;
                }
                $rem = $exp - $now;
                if ($minSec === null || $rem < $minSec) {
                    $minSec = $rem;
                }
            }
            return $minSec;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Flush a shop's Full-Page Cache DB while preserving hit/miss counters.
     *
     * @param int $idShop
     */
    protected static function flushShopFpcCache($idShop)
    {
        $idShop = (int) $idShop;
        if ($idShop <= 0) {
            return;
        }
        $dbId = self::resolveFpcDbIndex($idShop);
        $r = self::connectPerfRedis($dbId);
        if ($r === null) {
            return;
        }
        try {
            $disabled = @$r->get('fpc:disabled');
            $hits = @$r->get('fpc:stats:hits');
            $misses = @$r->get('fpc:stats:misses');
            $loggedHits = @$r->get('fpc:stats:logged_hits');
            $cartHits = @$r->get('fpc:stats:cart_hits');
            $waitHits = @$r->get('fpc:stats:wait_hits');
            @$r->flushDB();
            if ($disabled === '1') {
                @$r->set('fpc:disabled', '1');
            }
            if ($hits !== false) {
                @$r->set('fpc:stats:hits', (int) $hits);
            }
            if ($misses !== false) {
                @$r->set('fpc:stats:misses', (int) $misses);
            }
            if ($loggedHits !== false) {
                @$r->set('fpc:stats:logged_hits', (int) $loggedHits);
            }
            if ($cartHits !== false) {
                @$r->set('fpc:stats:cart_hits', (int) $cartHits);
            }
            if ($waitHits !== false) {
                @$r->set('fpc:stats:wait_hits', (int) $waitHits);
            }
        } catch (\Throwable $e) {
            // Ignore
        }
    }

    /**
     * For a given id_product and id_product_attribute, gets its stock available
     * minus any active Redis DB 3 reservations held by OTHER customers' carts / checkout sessions.
     *
     * @param int $id_product
     * @param int $id_product_attribute Optional
     * @param int $id_shop Optional : gets context by default
     *
     * @return int Quantity
     */
    public static function getQuantityAvailableByProduct($id_product = null, $id_product_attribute = null, $id_shop = null)
    {
        $idProd = (int) $id_product;
        $idAttr = ($id_product_attribute === null) ? 0 : (int) $id_product_attribute;
        if ($idProd <= 0 && $idAttr > 0) {
            $comb = new Combination($idAttr);
            $idProd = (int) $comb->id_product;
        }

        $physicalQty = (int) parent::getQuantityAvailableByProduct($idProd > 0 ? $idProd : $id_product, $idAttr, $id_shop);
        if ($physicalQty <= 0 || $idProd <= 0 || defined('_PS_ADMIN_DIR_')) {
            return $physicalQty;
        }

        $reservedByOthers = self::getReservedQuantityByOthers($idProd, $idAttr, $id_shop);
        if ($reservedByOthers <= 0) {
            return $physicalQty;
        }

        return max(0, $physicalQty - $reservedByOthers);
    }

    /**
     * Prefetch stock quantities for a batch of products into PrestaShop's
     * Cache layer. After calling this, every subsequent call to
     * getQuantityAvailableByProduct() for these products will be a
     * zero-cost cache hit.
     *
     * @param array $productIds Array of product IDs (int[])
     * @param int   $idShop     Shop ID
     */
    public static function prefetchStockForProducts(array $productIds, $idShop)
    {
        if (empty($productIds)) {
            return;
        }

        $idList = implode(',', array_map('intval', $productIds));

        // Determine the shop restriction columns
        $context = Context::getContext();
        $shopGroup = $context->shop->getGroup();

        if ($shopGroup->share_stock) {
            $shopWhere = 'id_shop_group = ' . (int) $shopGroup->id . ' AND id_shop = 0';
        } else {
            $shopWhere = 'id_shop = ' . (int) $idShop . ' AND id_shop_group = 0';
        }

        // Single bulk query: fetch stock for ALL products × ALL their attributes
        $rows = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS('
            SELECT id_product, id_product_attribute, SUM(quantity) as qty
            FROM `' . _DB_PREFIX_ . 'stock_available`
            WHERE id_product IN (' . $idList . ')
            AND ' . $shopWhere . '
            GROUP BY id_product, id_product_attribute
        ');

        if (!$rows) {
            $rows = [];
        }

        // Inject each result into PrestaShop's Cache using the exact key
        // format that getQuantityAvailableByProduct() expects
        foreach ($rows as $row) {
            $key = 'StockAvailable::getQuantityAvailableByProduct_'
                 . (int) $row['id_product'] . '-'
                 . (int) $row['id_product_attribute'] . '-'
                 . (int) $idShop;
            if (!Cache::isStored($key)) {
                Cache::store($key, (int) $row['qty']);
            }
        }

        // Also ensure attribute=0 entries exist for products that might
        // not have a row with id_product_attribute=0
        foreach ($productIds as $pid) {
            $key = 'StockAvailable::getQuantityAvailableByProduct_'
                 . (int) $pid . '-0-' . (int) $idShop;
            if (!Cache::isStored($key)) {
                Cache::store($key, 0);
            }
        }
    }
}
