<?php

class FrontController extends FrontControllerCore
{
    /** @var string|null Redis full-page cache key for current request */
    protected $fpcCacheKey = null;

    /** @var string|null Redis stampede lock key acquired by current request */
    protected $fpcLockKey = null;

    /** @var int Redis full-page cache TTL in seconds (5 minutes) */
    protected $fpcTtl = 300;

    /**
     * Initializes front controller: synchronizes dynamic state cookies (ps_logged, ps_cart_count).
     */
    public function init()
    {
        parent::init();

        if (!headers_sent()) {
            // 1. Maintain ps_logged cookie
            $isLogged = (bool) ($this->context->customer && $this->context->customer->isLogged());
            if ($isLogged && empty($_COOKIE['ps_logged'])) {
                setcookie('ps_logged', '1', [
                    'expires' => time() + 2592000,
                    'path' => '/',
                    'secure' => (bool) Configuration::get('PS_SSL_ENABLED'),
                    'httponly' => false,
                    'samesite' => 'Lax',
                ]);
                $_COOKIE['ps_logged'] = '1';
            } elseif (!$isLogged && !empty($_COOKIE['ps_logged'])) {
                setcookie('ps_logged', '', [
                    'expires' => time() - 3600,
                    'path' => '/',
                    'secure' => (bool) Configuration::get('PS_SSL_ENABLED'),
                    'httponly' => false,
                    'samesite' => 'Lax',
                ]);
                unset($_COOKIE['ps_logged']);
            }

            // 2. Maintain ps_cart_count cookie
            $cartCount = ($this->context->cart && $this->context->cart->id) ? (int) $this->context->cart->nbProducts() : 0;
            if ($cartCount > 0) {
                if (!isset($_COOKIE['ps_cart_count']) || (int) $_COOKIE['ps_cart_count'] !== $cartCount) {
                    setcookie('ps_cart_count', (string) $cartCount, [
                        'expires' => time() + 1728000,
                        'path' => '/',
                        'secure' => (bool) Configuration::get('PS_SSL_ENABLED'),
                        'httponly' => false,
                        'samesite' => 'Lax',
                    ]);
                    $_COOKIE['ps_cart_count'] = (string) $cartCount;
                }
            } else {
                if (isset($_COOKIE['ps_cart_count'])) {
                    setcookie('ps_cart_count', '', [
                        'expires' => time() - 3600,
                        'path' => '/',
                        'secure' => (bool) Configuration::get('PS_SSL_ENABLED'),
                        'httponly' => false,
                        'samesite' => 'Lax',
                    ]);
                    unset($_COOKIE['ps_cart_count']);
                }
            }
        }

        // Option B (Payment Stock Reservation) guard for binary PayPal Express / Smart Button payment initiation
        if (
            $this->context->cart
            && $this->context->cart->id
            && strtolower((string) Tools::getValue('module')) === 'paypal'
            && in_array(strtolower((string) Tools::getValue('controller')), ['ecinit', 'scinit'], true)
            && method_exists('StockAvailable', 'tryReserveWholeCartForPayment')
        ) {
            if (!StockAvailable::tryReserveWholeCartForPayment($this->context->cart)) {
                $cartUrl = $this->context->link->getPageLink('cart', null, null, ['action' => 'show']);
                if ($this->ajax || strtolower((string) Tools::getValue('controller')) === 'scinit') {
                    http_response_code(409);
                    header('Content-Type: application/json');
                    die(json_encode(['success' => false, 'errors' => true, 'cartUrl' => $cartUrl, 'message' => 'Item is no longer available in requested quantity.']));
                }
                Tools::redirect($cartUrl);
            }
        }
    }

    /**
     * Connect to the shop-specific Redis database for Full-Page Cache
     * (keeping DB 0 free for PHP sessions and the configured Reserve DB free for stock holds).
     *
     * @return \Redis|null
     */
    protected function getFpcRedis()
    {
        static $redis = false;
        $shopId = (int) ($this->context->shop->id ?? 1);
        $dbIndex = method_exists('StockAvailable', 'resolveFpcDbIndex')
            ? StockAvailable::resolveFpcDbIndex($shopId)
            : max(1, $shopId);

        if ($redis !== false) {
            if ($redis !== null) {
                @$redis->select($dbIndex);
            }
            return $redis;
        }
        if (method_exists('StockAvailable', 'connectPerfRedis')) {
            $redis = StockAvailable::connectPerfRedis($dbIndex);
            return $redis;
        }
        if (!extension_loaded('redis') || !class_exists('Redis')) {
            $redis = null;
            return null;
        }
        try {
            $r = new \Redis();
            if (@$r->connect('127.0.0.1', 6379, 0.05) && @$r->select($dbIndex)) {
                $redis = $r;
                return $redis;
            }
        } catch (\Throwable $e) {
            // Fall through safely
        }
        $redis = null;
        return null;
    }

    /**
     * Return customer IDs that have custom per-customer specific_price rows (cached in Redis for 5 min).
     *
     * @return int[]
     */
    protected function getSpecificPriceCustomerIds()
    {
        static $ids = null;
        if ($ids !== null) {
            return $ids;
        }

        $redis = $this->getFpcRedis();
        if ($redis !== null) {
            try {
                $cached = @$redis->get('fpc:meta:sp_customers');
                if (is_string($cached)) {
                    $decoded = json_decode($cached, true);
                    if (is_array($decoded)) {
                        $ids = array_map('intval', $decoded);
                        return $ids;
                    }
                }
            } catch (\Throwable $e) {
                // Fall back to DB
            }
        }

        $ids = [];
        try {
            $rows = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS(
                'SELECT DISTINCT `id_customer` FROM `' . _DB_PREFIX_ . 'specific_price` WHERE `id_customer` > 0'
            );
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $ids[] = (int) $row['id_customer'];
                }
            }
        } catch (\Throwable $e) {
            // Ignore DB error
        }

        if ($redis !== null) {
            try {
                @$redis->setex('fpc:meta:sp_customers', 300, json_encode($ids));
            } catch (\Throwable $e) {
                // Ignore
            }
        }

        return $ids;
    }

    /**
     * Determine whether the current request is eligible for Redis Full-Page Microcaching
     * and return its deterministic cache key (or null if ineligible).
     *
     * Supports:
     * - Base Mode: Anonymous guests with empty cart
     * - Option 1 (PS_REDIS_PERF_FPC_LOGGED_EMPTY): Logged-in users with empty cart (token-swapped)
     * - Option 2 (PS_REDIS_PERF_FPC_LOGGED_CART): Logged-in & guest users with items in cart (cartCount > 0)
     *
     * @return string|null
     */
    protected function resolveFpcCacheKey()
    {
        if ((int) Configuration::getGlobalValue('PS_REDIS_PERF_FPC_ENABLED') !== 1) {
            return null;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' || http_response_code() !== 200) {
            return null;
        }
        if (!empty($this->ajax) || !empty($this->content_only) || !empty($this->xml_output) || !empty($this->errors) || !empty($this->warning) || !empty($this->success) || !empty($this->redirect_after)) {
            return null;
        }
        if ((defined('_PS_MODE_DEV_') && _PS_MODE_DEV_) || Configuration::get('PS_CATALOG_MODE')) {
            return null;
        }

        $controllersConf = (string) Configuration::getGlobalValue('PS_REDIS_PERF_FPC_CONTROLLERS');
        if ($controllersConf !== '') {
            $allowedControllers = array_filter(array_map('trim', explode(',', $controllersConf)));
        } else {
            $allowedControllers = [
                'index',
                'category',
                'product',
                'cms',
                'manufacturer',
                'prices-drop',
                'new-products',
                'best-sales',
                'sitemap',
            ];
        }
        if (empty($this->php_self) || !in_array($this->php_self, $allowedControllers, true)) {
            return null;
        }
        if (!empty($_GET['nocache'])) {
            return null;
        }

        $isLogged = (bool) ($this->context->customer && $this->context->customer->isLogged());
        $cartCount = ($this->context->cart && $this->context->cart->id) ? (int) $this->context->cart->nbProducts() : 0;

        $allowLoggedEmpty = ((int) Configuration::getGlobalValue('PS_REDIS_PERF_FPC_LOGGED_EMPTY') === 1);
        $allowCart = ((int) Configuration::getGlobalValue('PS_REDIS_PERF_FPC_LOGGED_CART') === 1);

        if ($isLogged && !$allowLoggedEmpty) {
            return null;
        }
        if ($cartCount > 0 && !$allowCart) {
            return null;
        }
        // Bypass FPC for customers who have custom per-customer specific_price rows
        if ($isLogged && in_array((int) $this->context->customer->id, $this->getSpecificPriceCustomerIds(), true)) {
            return null;
        }

        $bypassGetKeys = [
            'gclid', 'gbraid', 'wbraid', 'srsltid', 'fbclid', 'gad_source', 'gad_campaignid',
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
            'msclkid', 'ttclid', 'add', 'delete', 'token', 'submitCurrency', 'id_currency',
            'back', 'mylogout', 'from-xhr', 's', 'q',
        ];
        foreach ($bypassGetKeys as $k) {
            if (isset($_GET[$k]) && $_GET[$k] !== '') {
                return null;
            }
        }

        $idGroup = $isLogged
            ? (int) $this->context->customer->id_default_group
            : (int) Group::getCurrent()->id;

        return sprintf(
            'fpc:s%d:l%d:c%d:co%d:t%d:m%d:lg%d:g%d:%s:%s',
            (int) ($this->context->shop->id ?? 0),
            (int) ($this->context->language->id ?? 0),
            (int) ($this->context->currency->id ?? 0),
            (int) ($this->context->country->id ?? 0),
            (int) Product::getTaxCalculationMethod(),
            (int) $this->context->isMobile(),
            $isLogged ? 1 : 0,
            $idGroup,
            strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')),
            (string) ($_SERVER['REQUEST_URI'] ?? '/')
        );
    }

    /**
     * Normalize rendered HTML into a reusable Redis template by replacing user-specific
     * CSRF tokens, customer PII in var prestashop, and any non-empty cart block with placeholders.
     *
     * @param string $html
     * @return string
     */
    protected function prepareHtmlForFpcStorage($html)
    {
        $staticToken = (string) Tools::getToken(false);
        $token = (string) Tools::getToken(true);

        if ($staticToken !== '') {
            $html = str_replace($staticToken, '__FPC_STATIC_TOKEN__', $html);
        }
        if ($token !== '' && $token !== $staticToken) {
            $html = str_replace($token, '__FPC_TOKEN__', $html);
        }

        $isLogged = (bool) ($this->context->customer && $this->context->customer->isLogged());
        $cartCount = ($this->context->cart && $this->context->cart->id) ? (int) $this->context->cart->nbProducts() : 0;

        // If this MISS was rendered by a user with items already in their cart, normalize #_desktop_cart to the empty-cart placeholder
        if ($cartCount > 0) {
            $html = preg_replace(
                '#<div id="_desktop_cart">.*?</div>\s*</div>\s*</div>#s',
                '<!--__FPC_DESKTOP_CART_START__-->' . $this->renderFpcDesktopCartHtml(true) . '<!--__FPC_DESKTOP_CART_END__-->',
                $html,
                1
            );
        } else {
            $html = preg_replace(
                '#(<div id="_desktop_cart">.*?</div>\s*</div>\s*</div>)#s',
                '<!--__FPC_DESKTOP_CART_START__-->$1<!--__FPC_DESKTOP_CART_END__-->',
                $html,
                1
            );
        }

        // Sanitize var prestashop JSON so no customer PII or personal cart items are ever stored in Redis DB 1
        $html = preg_replace_callback(
            '/^(\s*var\s+prestashop\s*=\s*)(\{.*\})(;\s*)$/m',
            function ($m) use ($isLogged) {
                $data = json_decode($m[2], true);
                if (!is_array($data)) {
                    return $m[0];
                }
                $data['static_token'] = '__FPC_STATIC_TOKEN__';
                $data['token'] = '__FPC_TOKEN__';
                if (isset($data['customer']) && is_array($data['customer'])) {
                    $data['customer'] = [
                        'lastname' => null,
                        'firstname' => null,
                        'email' => null,
                        'birthday' => null,
                        'newsletter' => null,
                        'newsletter_date_add' => null,
                        'optin' => null,
                        'website' => null,
                        'company' => null,
                        'siret' => null,
                        'ape' => null,
                        'is_logged' => $isLogged,
                        'gender' => ['type' => null, 'name' => null],
                        'addresses' => [],
                    ];
                }
                if (isset($data['cart']) && is_array($data['cart']) && !empty($data['cart']['products'])) {
                    $data['cart']['products'] = [];
                    $data['cart']['products_count'] = 0;
                    $data['cart']['summary_string'] = '0 items';
                    $data['cart']['vouchers'] = ['allowed' => 1, 'added' => []];
                    $data['cart']['discounts'] = [];
                }
                return $m[1] . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . $m[3];
            },
            $html,
            1
        );

        return $html;
    }

    /**
     * Render the #_desktop_cart header widget for the current user's cart (or an empty cart).
     *
     * @param bool $forceEmpty
     * @return string
     */
    protected function renderFpcDesktopCartHtml($forceEmpty = false)
    {
        $cartCount = ($forceEmpty || !$this->context->cart || !$this->context->cart->id)
            ? 0
            : (int) $this->context->cart->nbProducts();
        $activeClass = $cartCount > 0 ? 'active' : 'inactive';
        $refreshUrl = $this->context->link->getModuleLink('ps_shoppingcart', 'ajax', [], null, null, null, true);
        $cartUrl = $this->context->link->getPageLink('cart', null, $this->context->language->id, ['action' => 'show'], false, null, true);
        $basketLabel = $this->trans('Cart', [], 'Shop.Theme.Checkout');
        if ($basketLabel === '' || $basketLabel === null) {
            $basketLabel = 'Cart';
        }

        return '<div id="_desktop_cart">'
            . '<div class="blockcart cart-preview ' . $activeClass . '" data-refresh-url="' . htmlspecialchars($refreshUrl, ENT_QUOTES, 'UTF-8') . '">'
            . '<a rel="nofollow" href="' . htmlspecialchars($cartUrl, ENT_QUOTES, 'UTF-8') . '" aria-label="Shopping Cart">'
            . '<div class="header">'
            . '<span class="hidden-sm-down">' . htmlspecialchars($basketLabel, ENT_QUOTES, 'UTF-8') . '</span> '
            . '<span class="cart-products-count">(' . $cartCount . ')</span>'
            . '</div>'
            . '</a>'
            . '</div>'
            . '</div>';
    }

    /**
     * Personalize a cached HTML template from Redis DB 1 for the current visitor
     * by swapping in their valid CSRF tokens and (if cartCount > 0) their live cart badge.
     *
     * @param string $cachedHtml
     * @return string
     */
    protected function personalizeFpcCachedHtml($cachedHtml)
    {
        $staticToken = (string) Tools::getToken(false);
        $token = (string) Tools::getToken(true);

        $html = strtr($cachedHtml, [
            '__FPC_STATIC_TOKEN__' => $staticToken,
            '__FPC_TOKEN__' => $token,
        ]);

        $cartCount = ($this->context->cart && $this->context->cart->id) ? (int) $this->context->cart->nbProducts() : 0;
        if ($cartCount > 0) {
            $liveCartHtml = $this->renderFpcDesktopCartHtml(false);
            $startTag = '<!--__FPC_DESKTOP_CART_START__-->';
            $endTag = '<!--__FPC_DESKTOP_CART_END__-->';
            $sPos = strpos($html, $startTag);
            $ePos = strpos($html, $endTag);
            if ($sPos !== false && $ePos !== false && $ePos > $sPos) {
                $html = substr($html, 0, $sPos) . $liveCartHtml . substr($html, $ePos + strlen($endTag));
            }
            $html = str_replace('"products_count":0', '"products_count":' . $cartCount, $html);
        }

        return $html;
    }

    /**
     * Serve cached full-page HTML from Redis DB 1/2 right after init() and canonical checks complete,
     * with Cold-Cache Stampede Protection (Dog-Pile Lock) and ps_ga_client_id tracking.
     */
    public function setMedia()
    {
        $cacheKey = $this->resolveFpcCacheKey();
        if ($cacheKey !== null) {
            $redis = $this->getFpcRedis();
            if ($redis !== null) {
                try {
                    if ($redis->get('fpc:disabled') === '1') {
                        return parent::setMedia();
                    }
                    $cachedHtml = $redis->get($cacheKey);
                    $waitedForLock = false;
                    $waitedMs = 0;

                    // Cold-Cache Stampede Protection (Dog-Pile Lock)
                    if ((!is_string($cachedHtml) || strlen($cachedHtml) <= 1000) && (int) Configuration::getGlobalValue('PS_REDIS_PERF_FPC_STAMPEDE_LOCK') === 1) {
                        $lockKey = 'lock:' . $cacheKey;
                        // Atomic SET NX EX 3: returns true for the 1st worker, false for concurrent workers
                        $acquired = @$redis->set($lockKey, '1', ['nx', 'ex' => 3]);
                        if ($acquired) {
                            $this->fpcLockKey = $lockKey;
                        } else {
                            $maxWaitMs = (int) Configuration::getGlobalValue('PS_REDIS_PERF_FPC_STAMPEDE_WAIT_MS');
                            if ($maxWaitMs < 100 || $maxWaitMs > 5000) {
                                $maxWaitMs = 1500;
                            }
                            $waitedMs = 0;
                            while ($waitedMs < $maxWaitMs) {
                                usleep(25000); // 25 ms
                                $waitedMs += 25;
                                $cachedHtml = $redis->get($cacheKey);
                                if (is_string($cachedHtml) && strlen($cachedHtml) > 1000) {
                                    $waitedForLock = true;
                                    break;
                                }
                                if (!@$redis->exists($lockKey)) {
                                    $cachedHtml = $redis->get($cacheKey);
                                    if (is_string($cachedHtml) && strlen($cachedHtml) > 1000) {
                                        $waitedForLock = true;
                                    }
                                    break;
                                }
                            }
                        }
                    }

                    if (is_string($cachedHtml) && strlen($cachedHtml) > 1000) {
                        // Execute configured lightweight header-hook modules (e.g. analytics trackers) on cache HITs
                        $hitModulesRaw = Configuration::getGlobalValue('PS_REDIS_PERF_FPC_HIT_HOOK_MODULES');
                        if ($hitModulesRaw === false || $hitModulesRaw === null) {
                            $hitModulesRaw = '';
                        }
                        foreach (array_filter(array_map('trim', explode(',', (string) $hitModulesRaw))) as $hitModName) {
                            $hitMod = Module::getInstanceByName($hitModName);
                            if ($hitMod && $hitMod->active && method_exists($hitMod, 'hookDisplayHeader')) {
                                $hitMod->hookDisplayHeader([]);
                            }
                        }
                        $this->context->cookie->write();
                        $isLogged = (bool) ($this->context->customer && $this->context->customer->isLogged());
                        $cartCount = ($this->context->cart && $this->context->cart->id) ? (int) $this->context->cart->nbProducts() : 0;
                        $modeHeader = $isLogged ? ($cartCount > 0 ? 'HIT-LOGGED-CART' : 'HIT-LOGGED') : ($cartCount > 0 ? 'HIT-GUEST-CART' : 'HIT');
                        @$redis->incr('fpc:stats:hits');
                        if ($isLogged) {
                            @$redis->incr('fpc:stats:logged_hits');
                        }
                        if ($cartCount > 0) {
                            @$redis->incr('fpc:stats:cart_hits');
                        }
                        if ($waitedForLock) {
                            $modeHeader .= '-WAIT';
                            @$redis->incr('fpc:stats:wait_hits');
                            $waitEntry = json_encode([
                                'ts' => date('Y-m-d H:i:s'),
                                'url' => substr((string) ($_SERVER['REQUEST_URI'] ?? '/'), 0, 200),
                                'wait_ms' => (int) $waitedMs,
                                'mode' => $modeHeader,
                                'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                                'ua' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 100),
                            ], JSON_UNESCAPED_SLASHES);
                            if (is_string($waitEntry)) {
                                @$redis->lPush('fpc:stats:recent_wait_hits', $waitEntry);
                                @$redis->lTrim('fpc:stats:recent_wait_hits', 0, 19);
                            }
                        }
                        // Sliding TTL for page caches only (never extends DB 3 stock holds, and respects active hold caps)
                        $slidingCfg = Configuration::getGlobalValue('PS_REDIS_PERF_FPC_SLIDING_TTL');
                        $slidingEnabled = ($slidingCfg === false || $slidingCfg === null || (int) $slidingCfg === 1);
                        if (!$waitedForLock && $slidingEnabled) {
                            $ttl = (int) Configuration::getGlobalValue('PS_REDIS_PERF_FPC_TTL');
                            if ($ttl < 10) {
                                $ttl = (int) $this->fpcTtl;
                            }
                            $remTtl = @$redis->ttl($cacheKey);
                            // Throttle writes: skip DB 3 check and EXPIRE if refreshed within the last 5 seconds
                            if (is_int($remTtl) && $remTtl > 0 && $remTtl < ($ttl - 5)) {
                                $extendTtl = $ttl;
                                if (method_exists('StockAvailable', 'getNextExpiringHoldSeconds')) {
                                    $nextHoldSec = StockAvailable::getNextExpiringHoldSeconds((int) ($this->context->shop->id ?? 1));
                                    if ($nextHoldSec !== null) {
                                        $extendTtl = max(5, min($ttl, $nextHoldSec + 1));
                                    }
                                }
                                if ($remTtl < ($extendTtl - 5)) {
                                    @$redis->expire($cacheKey, $extendTtl);
                                }
                            }
                        }
                        $personalizedHtml = $this->personalizeFpcCachedHtml($cachedHtml);
                        if (!headers_sent()) {
                            header('X-Page-Cache: ' . $modeHeader);
                            header('Content-Type: text/html; charset=utf-8');
                        }
                        echo $personalizedHtml;
                        exit;
                    }
                    $this->fpcCacheKey = $cacheKey;
                    if (!headers_sent()) {
                        header('X-Page-Cache: MISS');
                    }
                } catch (\Throwable $e) {
                    $this->fpcCacheKey = null;
                    $this->fpcLockKey = null;
                }
            }
        }

        return parent::setMedia();
    }

    /**
     * Store rendered HTML into Redis DB 1/2 on a cache MISS for eligible catalog pages
     * and release any stampede lock held by this request.
     *
     * @param string|array $content
     */
    protected function smartyOutputContent($content)
    {
        if ($this->fpcCacheKey === null || $this->resolveFpcCacheKey() === null) {
            if ($this->fpcLockKey !== null) {
                $redis = $this->getFpcRedis();
                if ($redis !== null) {
                    @$redis->del($this->fpcLockKey);
                }
                $this->fpcLockKey = null;
            }
            parent::smartyOutputContent($content);
            return;
        }

        ob_start();
        parent::smartyOutputContent($content);
        $html = ob_get_flush();

        $redis = $this->getFpcRedis();
        if ($redis !== null) {
            try {
                $cartCount = ($this->context->cart && $this->context->cart->id) ? (int) $this->context->cart->nbProducts() : 0;
                $skipWriteForReservedCart = ($cartCount > 0 && method_exists('StockAvailable', 'isReservationEnabled') && StockAvailable::isReservationEnabled(null, (int) ($this->context->shop->id ?? 1)));
                if (!$skipWriteForReservedCart && is_string($html) && strlen($html) > 1000 && http_response_code() === 200 && empty($this->errors)) {
                    $ttl = (int) Configuration::getGlobalValue('PS_REDIS_PERF_FPC_TTL');
                    if ($ttl < 10) {
                        $ttl = (int) $this->fpcTtl;
                    }
                    // If this shop has active stock holds in DB 3, cap FPC TTL so abandoned holds reappear in stock right when the hold expires
                    if (method_exists('StockAvailable', 'getNextExpiringHoldSeconds')) {
                        $nextHoldSec = StockAvailable::getNextExpiringHoldSeconds((int) ($this->context->shop->id ?? 1));
                        if ($nextHoldSec !== null) {
                            $ttl = max(5, min($ttl, $nextHoldSec + 1));
                        }
                    }
                    $normalizedHtml = $this->prepareHtmlForFpcStorage($html);
                    @$redis->setex($this->fpcCacheKey, $ttl, $normalizedHtml);
                    @$redis->incr('fpc:stats:misses');
                }
                if ($this->fpcLockKey !== null) {
                    @$redis->del($this->fpcLockKey);
                    $this->fpcLockKey = null;
                }
            } catch (\Throwable $e) {
                // Ignore cache write errors
            }
        }
    }
}
