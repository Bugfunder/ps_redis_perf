<?php
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
if (!defined('_PS_VERSION_')) {
    exit;
}

class Ps_redis_perf extends Module
{
    public function __construct()
    {
        $this->name = 'ps_redis_perf';
        $this->tab = 'administration';
        $this->version = '1.0.3';
        $this->author = 'Slingomama';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Redis Performance & Drop Protection');
        $this->description = $this->l('PrestaShop 8 front-office performance suite: Redis Full-Page Microcache (Guest + Logged-In Drop Mode), Stampede Protection, Atomic Stock Reservations, and MariaDB Category Listing acceleration.');
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
    }

    public function install()
    {
        $this->ensureDefaultConfiguration();
        $this->ensureSqlViews();

        return parent::install() &&
            $this->registerRequiredHooks();
    }

    public function uninstall()
    {
        $keys = [
            'PS_REDIS_PERF_REDIS_HOST',
            'PS_REDIS_PERF_REDIS_PORT',
            'PS_REDIS_PERF_REDIS_PASSWORD',
            'PS_REDIS_PERF_RESERVE_DB',
            'PS_REDIS_PERF_FPC_ENABLED',
            'PS_REDIS_PERF_FPC_LOGGED_EMPTY',
            'PS_REDIS_PERF_FPC_LOGGED_CART',
            'PS_REDIS_PERF_FPC_STAMPEDE_LOCK',
            'PS_REDIS_PERF_FPC_STAMPEDE_WAIT_MS',
            'PS_REDIS_PERF_FPC_TTL',
            'PS_REDIS_PERF_FPC_CONTROLLERS',
            'PS_REDIS_PERF_FPC_HIT_HOOK_MODULES',
            'PS_REDIS_PERF_FPC_AUTOFLUSH_BO_EDITS',
            'PS_REDIS_PERF_RESERVE_SHOPS',
            'PS_REDIS_PERF_RESERVE_CART_ENABLED',
            'PS_REDIS_PERF_RESERVE_CART_TTL',
            'PS_REDIS_PERF_RESERVE_PAYMENT_ENABLED',
            'PS_REDIS_PERF_RESERVE_PAYMENT_TTL',
            'PS_REDIS_PERF_SQL_VIEW_ENABLED',
            'PS_REDIS_PERF_SESSION_THROTTLE_ENABLED',
            'PS_REDIS_PERF_SESSION_THROTTLE_SECONDS',
            'PS_REDIS_PERF_DISABLE_QUERY_CACHE',
        ];
        foreach ($keys as $key) {
            Configuration::deleteByName($key);
        }

        return parent::uninstall();
    }

    public function ensureDefaultConfiguration()
    {
        $defaults = [
            'PS_REDIS_PERF_REDIS_HOST' => '127.0.0.1',
            'PS_REDIS_PERF_REDIS_PORT' => '6379',
            'PS_REDIS_PERF_RESERVE_DB' => '3',
            'PS_REDIS_PERF_FPC_ENABLED' => '1',
            'PS_REDIS_PERF_FPC_LOGGED_EMPTY' => '1',
            'PS_REDIS_PERF_FPC_LOGGED_CART' => '0',
            'PS_REDIS_PERF_FPC_STAMPEDE_LOCK' => '1',
            'PS_REDIS_PERF_FPC_STAMPEDE_WAIT_MS' => '1500',
            'PS_REDIS_PERF_FPC_TTL' => '300',
            'PS_REDIS_PERF_FPC_CONTROLLERS' => 'index,category,product,cms,manufacturer,prices-drop,new-products,best-sales,sitemap',
            'PS_REDIS_PERF_FPC_AUTOFLUSH_BO_EDITS' => '1',
            'PS_REDIS_PERF_RESERVE_SHOPS' => 'all',
            'PS_REDIS_PERF_RESERVE_CART_ENABLED' => '0',
            'PS_REDIS_PERF_RESERVE_CART_TTL' => '180',
            'PS_REDIS_PERF_RESERVE_PAYMENT_ENABLED' => '0',
            'PS_REDIS_PERF_RESERVE_PAYMENT_TTL' => '600',
            'PS_REDIS_PERF_SQL_VIEW_ENABLED' => '1',
            'PS_REDIS_PERF_SESSION_THROTTLE_ENABLED' => '1',
            'PS_REDIS_PERF_SESSION_THROTTLE_SECONDS' => '300',
            'PS_REDIS_PERF_DISABLE_QUERY_CACHE' => '1',
        ];
        foreach ($defaults as $key => $val) {
            if (Configuration::getGlobalValue($key) === false || Configuration::getGlobalValue($key) === null || Configuration::getGlobalValue($key) === '') {
                Configuration::updateGlobalValue($key, $val);
            }
        }
        // Preserve intentional empty values for optional string fields
        if (Configuration::getGlobalValue('PS_REDIS_PERF_REDIS_PASSWORD') === false || Configuration::getGlobalValue('PS_REDIS_PERF_REDIS_PASSWORD') === null) {
            Configuration::updateGlobalValue('PS_REDIS_PERF_REDIS_PASSWORD', '');
        }
        if (Configuration::getGlobalValue('PS_REDIS_PERF_FPC_HIT_HOOK_MODULES') === false || Configuration::getGlobalValue('PS_REDIS_PERF_FPC_HIT_HOOK_MODULES') === null) {
            Configuration::updateGlobalValue('PS_REDIS_PERF_FPC_HIT_HOOK_MODULES', '');
        }
    }

    /**
     * Create the MariaDB views used by ProductAssembler on fresh installations (no-op if views already exist).
     */
    public function ensureSqlViews()
    {
        $p = _DB_PREFIX_;
        $defaultCountry = (int) (Configuration::get('PS_COUNTRY_DEFAULT') ?: 1);

        try {
            $existsSp = Db::getInstance()->executeS("SHOW FULL TABLES LIKE '" . pSQL($p . 'specific_price_best') . "'");
            if (empty($existsSp)) {
                Db::getInstance()->execute("
                    CREATE VIEW `{$p}specific_price_best` AS
                    SELECT `t`.`id_product`, `t`.`id_shop`, `t`.`reduction`, `t`.`reduction_type`,
                           `t`.`reduction_tax`, `t`.`price` AS `sp_price`,
                           JSON_OBJECT(
                               'id_specific_price', `t`.`id_specific_price`,
                               'reduction', `t`.`reduction`,
                               'reduction_type', `t`.`reduction_type`,
                               'price', `t`.`price`,
                               'reduction_tax', `t`.`reduction_tax`,
                               'from_quantity', `t`.`from_quantity`
                           ) AS `specific_prices_json`
                    FROM (
                        SELECT sp.*,
                               ROW_NUMBER() OVER (PARTITION BY sp.`id_product` ORDER BY sp.`id_shop` DESC, sp.`id_specific_price` DESC) AS `rn`
                        FROM `{$p}specific_price` sp
                        WHERE sp.`id_currency` = 0
                          AND sp.`id_country` = 0
                          AND sp.`id_group` = 0
                          AND sp.`id_customer` = 0
                          AND sp.`from_quantity` = 1
                          AND sp.`id_product_attribute` = 0
                          AND (sp.`from` = '0000-00-00 00:00:00' OR sp.`from` <= CURRENT_TIMESTAMP())
                          AND (sp.`to` = '0000-00-00 00:00:00' OR sp.`to` >= CURRENT_TIMESTAMP())
                    ) `t`
                    WHERE `t`.`rn` = 1
                ");
            }

            $existsListing = Db::getInstance()->executeS("SHOW FULL TABLES LIKE '" . pSQL($p . 'product_listing_view') . "'");
            if (empty($existsListing)) {
                Db::getInstance()->execute("
                    CREATE VIEW `{$p}product_listing_view` AS
                    SELECT
                        p.`id_product`, p.`id_supplier`, p.`id_manufacturer`,
                        ps.`id_category_default`, ps.`id_tax_rules_group`, ps.`on_sale`, ps.`online_only`,
                        p.`ean13`, p.`isbn`, p.`upc`, p.`mpn`, ps.`ecotax`,
                        IF(ps.`cache_default_attribute` > 0, IFNULL(sa_def.`quantity`, 0), IFNULL(sa0.`quantity`, 0)) AS `quantity`,
                        IFNULL(sa0.`quantity`, 0) AS `quantity_all_versions`,
                        ps.`minimal_quantity`, ps.`low_stock_threshold`, ps.`low_stock_alert`,
                        ps.`price` + IFNULL(pas.`price`, 0) AS `price_tax_exc`,
                        ROUND((ps.`price` + IFNULL(pas.`price`, 0)) * (1 + IFNULL(t.`rate`, 0) / 100), 2) AS `price`,
                        ROUND((ps.`price` + IFNULL(pas.`price`, 0)) * (1 + IFNULL(t.`rate`, 0) / 100), 2) AS `price_without_reduction`,
                        ps.`price` + IFNULL(pas.`price`, 0) AS `price_without_reduction_without_tax`,
                        IFNULL(sp.`reduction`, 0) AS `reduction`,
                        0 AS `reduction_without_tax`,
                        sp.`specific_prices_json`,
                        ps.`wholesale_price`, ps.`unity`, ps.`unit_price`, ps.`unit_price_ratio`,
                        ps.`additional_shipping_cost`, p.`reference`, p.`supplier_reference`, p.`location`,
                        p.`width`, p.`height`, p.`depth`, p.`weight`,
                        IFNULL(sa0.`out_of_stock`, 0) AS `out_of_stock`,
                        p.`additional_delivery_times`, 0 AS `quantity_discount`,
                        ps.`customizable`, ps.`uploadable_files`, ps.`text_fields`,
                        ps.`active`, ps.`redirect_type`, ps.`id_type_redirected`,
                        ps.`available_for_order`, ps.`available_date`, ps.`show_condition`, ps.`condition`,
                        ps.`show_price`, ps.`indexed`, ps.`visibility`,
                        p.`cache_is_pack`, p.`cache_has_attachments`, p.`is_virtual`,
                        ps.`cache_default_attribute`, ps.`date_add`, ps.`date_upd`,
                        ps.`advanced_stock_management`, ps.`pack_stock_type`, p.`state`, p.`product_type`,
                        ps.`id_shop`, pl.`id_lang`,
                        pl.`description`, pl.`description_short`, pl.`link_rewrite`,
                        pl.`meta_description`, pl.`meta_keywords`, pl.`meta_title`, pl.`name`,
                        pl.`available_now`, pl.`available_later`, pl.`delivery_in_stock`, pl.`delivery_out_stock`,
                        cl.`link_rewrite` AS `category`, cl.`name` AS `category_name`,
                        m.`name` AS `manufacturer_name`,
                        IFNULL(ps.`cache_default_attribute`, 0) AS `id_product_attribute`,
                        IF(sa0.`out_of_stock` = 1, 1, 0) AS `allow_oosp`,
                        im.`id_image` AS `id_image`, im.`id_image` AS `cover_image_id`,
                        IFNULL(t.`rate`, 0) AS `rate`
                    FROM `{$p}product` p
                    JOIN `{$p}product_shop` ps ON (ps.`id_product` = p.`id_product`)
                    JOIN `{$p}product_lang` pl ON (pl.`id_product` = p.`id_product` AND pl.`id_shop` = ps.`id_shop`)
                    LEFT JOIN `{$p}category_lang` cl ON (cl.`id_category` = ps.`id_category_default` AND cl.`id_shop` = ps.`id_shop` AND cl.`id_lang` = pl.`id_lang`)
                    LEFT JOIN `{$p}manufacturer` m ON (m.`id_manufacturer` = p.`id_manufacturer`)
                    LEFT JOIN `{$p}product_attribute_shop` pas ON (pas.`id_product_attribute` = ps.`cache_default_attribute` AND pas.`id_shop` = ps.`id_shop`)
                    LEFT JOIN `{$p}stock_available` sa0 ON (sa0.`id_product` = p.`id_product` AND sa0.`id_product_attribute` = 0 AND sa0.`id_shop` = ps.`id_shop`)
                    LEFT JOIN `{$p}stock_available` sa_def ON (sa_def.`id_product` = p.`id_product` AND sa_def.`id_product_attribute` = ps.`cache_default_attribute` AND sa_def.`id_shop` = ps.`id_shop`)
                    LEFT JOIN `{$p}image_shop` im ON (im.`id_product` = p.`id_product` AND im.`id_shop` = ps.`id_shop` AND im.`cover` = 1)
                    LEFT JOIN `{$p}tax_rules_group` trg ON (trg.`id_tax_rules_group` = ps.`id_tax_rules_group`)
                    LEFT JOIN `{$p}tax_rule` tr ON (tr.`id_tax_rules_group` = trg.`id_tax_rules_group` AND tr.`id_country` = {$defaultCountry})
                    LEFT JOIN `{$p}tax` t ON (t.`id_tax` = tr.`id_tax`)
                    LEFT JOIN `{$p}specific_price_best` sp ON (sp.`id_product` = p.`id_product`)
                ");
            }
        } catch (\Throwable $e) {
            // Non-fatal: ProductAssembler safely falls back to ProductAssemblerCore if views cannot be created
        }
    }

    public function registerRequiredHooks()
    {
        $hooks = [
            'actionProductUpdate',
            'actionProductDelete',
            'actionProductSave',
            'actionObjectImageAddAfter',
            'actionObjectImageUpdateAfter',
            'actionObjectImageDeleteAfter',
            'actionUpdateQuantity',
            'actionValidateOrder',
            'actionOrderStatusPostUpdate',
            'actionCategoryUpdate',
            'actionObjectProductInCartDeleteAfter',
        ];
        $ok = true;
        foreach ($hooks as $hook) {
            if (!$this->isRegisteredInHook($hook)) {
                $ok = $this->registerHook($hook) && $ok;
            }
        }
        return $ok;
    }

    /**
     * Resolve the configured Redis DB index for stock reservations (default: 3).
     *
     * @return int
     */
    private function getReserveDbIndex()
    {
        return method_exists('StockAvailable', 'getReserveDbIndex')
            ? StockAvailable::getReserveDbIndex()
            : 3;
    }

    /**
     * Resolve a collision-free Redis DB index for a shop's Full-Page Cache.
     *
     * @param int $idShop
     * @return int
     */
    private function getShopFpcDb($idShop)
    {
        if (method_exists('StockAvailable', 'resolveFpcDbIndex')) {
            return StockAvailable::resolveFpcDbIndex($idShop);
        }
        $idShop = max(1, (int) $idShop);
        $resDb = $this->getReserveDbIndex();
        return ($idShop < $resDb) ? $idShop : ($idShop + 1);
    }

    /**
     * Return active shops from PrestaShop along with their resolved Redis FPC DB indices.
     *
     * @return array<int, array{id_shop:int,name:string,domain:string,label:string,db:int}>
     */
    private function getManagedShops()
    {
        static $managed = null;
        if ($managed !== null) {
            return $managed;
        }
        $managed = [];
        try {
            $shops = Shop::getShops(true);
            if (is_array($shops)) {
                foreach ($shops as $s) {
                    $sid = (int) $s['id_shop'];
                    $name = trim((string) ($s['name'] ?? ('Shop #' . $sid)));
                    $domain = trim((string) (($s['domain_ssl'] ?? '') ?: ($s['domain'] ?? '')));
                    $managed[$sid] = [
                        'id_shop' => $sid,
                        'name' => $name,
                        'domain' => $domain,
                        'label' => $domain !== '' ? ($name . ' (' . $domain . ')') : $name,
                        'db' => $this->getShopFpcDb($sid),
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Fallback below
        }
        if (empty($managed)) {
            $managed[1] = [
                'id_shop' => 1,
                'name' => 'Shop #1',
                'domain' => '',
                'label' => 'Shop #1',
                'db' => $this->getShopFpcDb(1),
            ];
        }
        return $managed;
    }

    /**
     * Return all Redis FPC DB indices belonging to active shops.
     *
     * @return int[]
     */
    private function getAllShopFpcDbs()
    {
        $dbs = [];
        foreach ($this->getManagedShops() as $shop) {
            $dbs[] = (int) $shop['db'];
        }
        return array_values(array_unique($dbs));
    }

    private function getFpcRedis($dbIndex = null)
    {
        if ($dbIndex === null) {
            $allDbs = $this->getAllShopFpcDbs();
            $dbIndex = !empty($allDbs) ? $allDbs[0] : 1;
        }
        if (method_exists('StockAvailable', 'connectPerfRedis')) {
            return StockAvailable::connectPerfRedis((int) $dbIndex);
        }
        if (!extension_loaded('redis') || !class_exists('Redis')) {
            return null;
        }
        try {
            $r = new \Redis();
            if (@$r->connect('127.0.0.1', 6379, 0.05) && @$r->select((int) $dbIndex)) {
                return $r;
            }
        } catch (\Throwable $e) {
            // Ignore Redis error
        }
        return null;
    }

    /**
     * Purge cached pages from one or more shop Redis FPC databases.
     *
     * @param bool $resetStats
     * @param array<int>|null $shopDbs Optional list of Redis DB IDs to flush (defaults to all active shop FPC DBs)
     */
    public function purgeRedisPageCache($resetStats = false, $shopDbs = null)
    {
        $allowedDbs = $this->getAllShopFpcDbs();
        $reserveDb = $this->getReserveDbIndex();
        $targetDbs = is_array($shopDbs) && !empty($shopDbs) ? $shopDbs : $allowedDbs;
        $r = $this->getFpcRedis();
        if ($r !== null) {
            foreach ($targetDbs as $dbId) {
                $dbId = (int) $dbId;
                if ($dbId === 0 || $dbId === $reserveDb || !in_array($dbId, $allowedDbs, true)) {
                    continue;
                }
                try {
                    if (!@$r->select($dbId)) {
                        continue;
                    }
                    $disabled = @$r->get('fpc:disabled');
                    $hits = $resetStats ? false : @$r->get('fpc:stats:hits');
                    $misses = $resetStats ? false : @$r->get('fpc:stats:misses');
                    $loggedHits = $resetStats ? false : @$r->get('fpc:stats:logged_hits');
                    $cartHits = $resetStats ? false : @$r->get('fpc:stats:cart_hits');
                    $waitHits = $resetStats ? false : @$r->get('fpc:stats:wait_hits');

                    @$r->flushDB();

                    if ($disabled === '1') {
                        @$r->set('fpc:disabled', '1');
                    }
                    if (!$resetStats) {
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
                    }
                } catch (\Throwable $e) {
                    // Ignore Redis cache flush failure
                }
            }
        }
    }

    /**
     * Resolve which active shop Redis FPC DBs a product belongs to.
     *
     * @param int $idProduct
     * @return array<int>
     */
    private function getShopDbsForProduct($idProduct)
    {
        $allDbs = $this->getAllShopFpcDbs();
        $id = (int) $idProduct;
        if ($id <= 0) {
            return $allDbs;
        }
        $managedShops = $this->getManagedShops();
        $shopIdList = implode(',', array_map('intval', array_keys($managedShops)));
        if ($shopIdList === '') {
            return $allDbs;
        }
        try {
            $rows = Db::getInstance()->executeS(
                'SELECT DISTINCT id_shop FROM `' . _DB_PREFIX_ . 'product_shop` WHERE id_product = ' . $id . ' AND id_shop IN (' . $shopIdList . ')'
            );
            if (!empty($rows)) {
                $dbs = [];
                foreach ($rows as $r) {
                    $sid = (int) $r['id_shop'];
                    if (isset($managedShops[$sid])) {
                        $dbs[] = (int) $managedShops[$sid]['db'];
                    }
                }
                $dbs = array_values(array_unique($dbs));
                if (!empty($dbs)) {
                    return $dbs;
                }
            }
        } catch (\Throwable $e) {
            // Fallback to all shops
        }
        return $allDbs;
    }

    public function getContent()
    {
        $this->ensureDefaultConfiguration();
        $this->registerRequiredHooks();
        $output = '';

        $managedShops = $this->getManagedShops();
        $allShopDbs = $this->getAllShopFpcDbs();
        $reserveDb = $this->getReserveDbIndex();
        $baseUrl = AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules');

        if (Tools::isSubmit('submitFlushPsRedisPerfCacheAll')) {
            $this->purgeRedisPageCache(false, $allShopDbs);
            Tools::redirectAdmin($baseUrl . '&perf_conf=flush_all');
        } elseif (Tools::isSubmit('submitFlushPsRedisPerfCacheShop')) {
            $flushShopId = (int) Tools::getValue('flush_shop_id');
            if (isset($managedShops[$flushShopId])) {
                $targetDb = (int) $managedShops[$flushShopId]['db'];
                $this->purgeRedisPageCache(false, [$targetDb]);
                Tools::redirectAdmin($baseUrl . '&perf_conf=flush_shop&perf_shop=' . $flushShopId);
            }
            Tools::redirectAdmin($baseUrl);
        } elseif (Tools::isSubmit('submitClearPsRedisPerfReservations')) {
            $rRes = $this->getFpcRedis($reserveDb);
            if ($rRes !== null) {
                try {
                    $statsBackup = [];
                    foreach (array_keys($managedShops) as $sid) {
                        foreach (['reserved_cart', 'reserved_pay', 'blocked'] as $sk) {
                            $k = 'sres:stats:s' . $sid . ':' . $sk;
                            $v = @$rRes->get($k);
                            if ($v !== false) {
                                $statsBackup[$k] = (int) $v;
                            }
                        }
                    }
                    @$rRes->flushDB();
                    foreach ($statsBackup as $k => $v) {
                        @$rRes->set($k, $v);
                    }
                } catch (\Throwable $e) {
                    // Ignore
                }
            }
            $this->purgeRedisPageCache(false, $allShopDbs);
            Tools::redirectAdmin($baseUrl . '&perf_conf=clear_holds');
        } elseif (Tools::isSubmit('submitResetPsRedisPerfStats')) {
            $r = $this->getFpcRedis();
            if ($r !== null) {
                foreach ($allShopDbs as $dbId) {
                    if (@$r->select($dbId)) {
                        @$r->set('fpc:stats:hits', 0);
                        @$r->set('fpc:stats:misses', 0);
                        @$r->set('fpc:stats:logged_hits', 0);
                        @$r->set('fpc:stats:cart_hits', 0);
                        @$r->set('fpc:stats:wait_hits', 0);
                    }
                }
                if (@$r->select($reserveDb)) {
                    foreach (array_keys($managedShops) as $sid) {
                        @$r->set('sres:stats:s' . $sid . ':reserved_cart', 0);
                        @$r->set('sres:stats:s' . $sid . ':reserved_pay', 0);
                        @$r->set('sres:stats:s' . $sid . ':blocked', 0);
                    }
                }
            }
            Tools::redirectAdmin($baseUrl . '&perf_conf=reset_stats');
        } elseif (Tools::isSubmit('submitPsRedisPerfConfig')) {
            $redisHost = trim((string) Tools::getValue('PS_REDIS_PERF_REDIS_HOST', '127.0.0.1'));
            if ($redisHost === '') {
                $redisHost = '127.0.0.1';
            }
            $redisPort = (int) Tools::getValue('PS_REDIS_PERF_REDIS_PORT', 6379);
            if ($redisPort < 1 || $redisPort > 65535) {
                $redisPort = 6379;
            }
            $redisPassword = (string) Tools::getValue('PS_REDIS_PERF_REDIS_PASSWORD', '');
            $redisReserveDb = max(1, min(15, (int) Tools::getValue('PS_REDIS_PERF_RESERVE_DB', 3)));

            $enabled = (int) Tools::getValue('PS_REDIS_PERF_FPC_ENABLED');
            $loggedEmpty = (int) Tools::getValue('PS_REDIS_PERF_FPC_LOGGED_EMPTY');
            $loggedCart = (int) Tools::getValue('PS_REDIS_PERF_FPC_LOGGED_CART');
            $stampedeLock = (int) Tools::getValue('PS_REDIS_PERF_FPC_STAMPEDE_LOCK');
            $stampedeWaitMs = max(100, min(5000, (int) Tools::getValue('PS_REDIS_PERF_FPC_STAMPEDE_WAIT_MS', 1500)));
            $ttl = max(10, min(86400, (int) Tools::getValue('PS_REDIS_PERF_FPC_TTL', 300)));
            $controllersRaw = (string) Tools::getValue('PS_REDIS_PERF_FPC_CONTROLLERS', '');
            $controllersList = array_filter(array_map(function ($c) {
                return preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($c)));
            }, explode(',', $controllersRaw)));
            if (empty($controllersList)) {
                $controllersList = ['index', 'category', 'product', 'cms', 'manufacturer', 'prices-drop', 'new-products', 'best-sales', 'sitemap'];
            }
            $hitModulesRaw = (string) Tools::getValue('PS_REDIS_PERF_FPC_HIT_HOOK_MODULES', '');
            $hitModulesList = array_filter(array_map(function ($mName) {
                return preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($mName)));
            }, explode(',', $hitModulesRaw)));

            $autoFlushBoEdits = (int) Tools::getValue('PS_REDIS_PERF_FPC_AUTOFLUSH_BO_EDITS');

            $validScopes = array_merge(['all'], array_map('strval', array_keys($managedShops)));
            $resShops = (string) Tools::getValue('PS_REDIS_PERF_RESERVE_SHOPS', 'all');
            if (!in_array($resShops, $validScopes, true)) {
                $resShops = 'all';
            }
            $resCartEnabled = (int) Tools::getValue('PS_REDIS_PERF_RESERVE_CART_ENABLED');
            $resCartTtl = max(30, min(3600, (int) Tools::getValue('PS_REDIS_PERF_RESERVE_CART_TTL', 180)));
            $resPayEnabled = (int) Tools::getValue('PS_REDIS_PERF_RESERVE_PAYMENT_ENABLED');
            $resPayTtl = max(60, min(3600, (int) Tools::getValue('PS_REDIS_PERF_RESERVE_PAYMENT_TTL', 600)));
            $sqlViewEnabled = (int) Tools::getValue('PS_REDIS_PERF_SQL_VIEW_ENABLED');
            $sessionThrottleEnabled = (int) Tools::getValue('PS_REDIS_PERF_SESSION_THROTTLE_ENABLED');
            $sessionThrottleSeconds = max(30, min(86400, (int) Tools::getValue('PS_REDIS_PERF_SESSION_THROTTLE_SECONDS', 300)));
            $disableQueryCache = (int) Tools::getValue('PS_REDIS_PERF_DISABLE_QUERY_CACHE');

            Configuration::updateGlobalValue('PS_REDIS_PERF_REDIS_HOST', $redisHost);
            Configuration::updateGlobalValue('PS_REDIS_PERF_REDIS_PORT', (string) $redisPort);
            Configuration::updateGlobalValue('PS_REDIS_PERF_REDIS_PASSWORD', $redisPassword);
            Configuration::updateGlobalValue('PS_REDIS_PERF_RESERVE_DB', (string) $redisReserveDb);

            Configuration::updateGlobalValue('PS_REDIS_PERF_FPC_ENABLED', (string) $enabled);
            Configuration::updateGlobalValue('PS_REDIS_PERF_FPC_LOGGED_EMPTY', (string) $loggedEmpty);
            Configuration::updateGlobalValue('PS_REDIS_PERF_FPC_LOGGED_CART', (string) $loggedCart);
            Configuration::updateGlobalValue('PS_REDIS_PERF_FPC_STAMPEDE_LOCK', (string) $stampedeLock);
            Configuration::updateGlobalValue('PS_REDIS_PERF_FPC_STAMPEDE_WAIT_MS', (string) $stampedeWaitMs);
            Configuration::updateGlobalValue('PS_REDIS_PERF_FPC_TTL', (string) $ttl);
            Configuration::updateGlobalValue('PS_REDIS_PERF_FPC_CONTROLLERS', implode(',', $controllersList));
            Configuration::updateGlobalValue('PS_REDIS_PERF_FPC_HIT_HOOK_MODULES', implode(',', $hitModulesList));
            Configuration::updateGlobalValue('PS_REDIS_PERF_FPC_AUTOFLUSH_BO_EDITS', (string) $autoFlushBoEdits);

            Configuration::updateGlobalValue('PS_REDIS_PERF_RESERVE_SHOPS', $resShops);
            Configuration::updateGlobalValue('PS_REDIS_PERF_RESERVE_CART_ENABLED', (string) $resCartEnabled);
            Configuration::updateGlobalValue('PS_REDIS_PERF_RESERVE_CART_TTL', (string) $resCartTtl);
            Configuration::updateGlobalValue('PS_REDIS_PERF_RESERVE_PAYMENT_ENABLED', (string) $resPayEnabled);
            Configuration::updateGlobalValue('PS_REDIS_PERF_RESERVE_PAYMENT_TTL', (string) $resPayTtl);
            Configuration::updateGlobalValue('PS_REDIS_PERF_SQL_VIEW_ENABLED', (string) $sqlViewEnabled);
            Configuration::updateGlobalValue('PS_REDIS_PERF_SESSION_THROTTLE_ENABLED', (string) $sessionThrottleEnabled);
            Configuration::updateGlobalValue('PS_REDIS_PERF_SESSION_THROTTLE_SECONDS', (string) $sessionThrottleSeconds);
            Configuration::updateGlobalValue('PS_REDIS_PERF_DISABLE_QUERY_CACHE', (string) $disableQueryCache);

            $r = $this->getFpcRedis();
            if ($r !== null) {
                foreach ($allShopDbs as $dbId) {
                    if (@$r->select($dbId)) {
                        if ($enabled === 1) {
                            @$r->del('fpc:disabled');
                        } else {
                            @$r->set('fpc:disabled', '1');
                        }
                    }
                }
            }
            $this->purgeRedisPageCache(false, $allShopDbs);
            Tools::redirectAdmin($baseUrl . '&perf_conf=saved');
        }

        $perfConf = (string) Tools::getValue('perf_conf', '');
        if ($perfConf === 'flush_all') {
            $output .= $this->displayConfirmation(sprintf(
                $this->l('Flushed Redis Page Cache for all active shops (DB %s). DB 0 sessions and DB %d stock holds untouched.'),
                implode(', ', $allShopDbs),
                $reserveDb
            ));
        } elseif ($perfConf === 'flush_shop') {
            $perfShopId = (int) Tools::getValue('perf_shop');
            if (isset($managedShops[$perfShopId])) {
                $output .= $this->displayConfirmation(sprintf(
                    $this->l('Flushed %s Page Cache (Redis DB %d). Other shops, DB 0 sessions, and DB %d stock holds untouched.'),
                    $managedShops[$perfShopId]['name'],
                    (int) $managedShops[$perfShopId]['db'],
                    $reserveDb
                ));
            }
        } elseif ($perfConf === 'clear_holds') {
            $output .= $this->displayConfirmation(sprintf(
                $this->l('All active Redis DB %d stock holds have been cleared and page caches refreshed.'),
                $reserveDb
            ));
        } elseif ($perfConf === 'reset_stats') {
            $output .= $this->displayConfirmation($this->l('Cache hit/miss and reservation statistics have been reset.'));
        } elseif ($perfConf === 'saved') {
            $output .= $this->displayConfirmation($this->l('Settings updated and Redis page caches refreshed.'));
        }

        return $output . $this->renderStatsPanel() . $this->renderForm();
    }

    private function renderStatsPanel()
    {
        $enabled = (int) Configuration::getGlobalValue('PS_REDIS_PERF_FPC_ENABLED') === 1;
        $loggedEmpty = (int) Configuration::getGlobalValue('PS_REDIS_PERF_FPC_LOGGED_EMPTY') === 1;
        $loggedCart = (int) Configuration::getGlobalValue('PS_REDIS_PERF_FPC_LOGGED_CART') === 1;

        $managedShops = $this->getManagedShops();
        $reserveDb = $this->getReserveDbIndex();
        $redisHost = trim((string) (Configuration::getGlobalValue('PS_REDIS_PERF_REDIS_HOST') ?: '127.0.0.1'));
        $redisPort = (int) (Configuration::getGlobalValue('PS_REDIS_PERF_REDIS_PORT') ?: 6379);
        $redisEndpoint = (strpos($redisHost, '/') === 0) ? $redisHost : ($redisHost . ':' . $redisPort);

        $r = $this->getFpcRedis();
        $redisConnected = ($r !== null);
        $memHuman = 'N/A';
        $memPeakHuman = 'N/A';
        $db0Sessions = 0;
        $db0AvgTtlMin = 0;
        $sessionHandler = (string) @ini_get('session.save_handler');

        $shopStats = [];
        foreach ($managedShops as $sid => $shopInfo) {
            $shopStats[$sid] = [
                'id_shop' => $sid,
                'name' => $shopInfo['name'],
                'label' => $shopInfo['label'],
                'db' => (int) $shopInfo['db'],
                'enabled' => $enabled,
                'keys' => 0,
                'pages' => 0,
                'avg_ttl' => 0,
                'hits' => 0,
                'misses' => 0,
                'logged_hits' => 0,
                'cart_hits' => 0,
                'wait_hits' => 0,
            ];
        }

        if ($r !== null) {
            try {
                $infoMem = @$r->info('memory');
                if (is_array($infoMem)) {
                    if (isset($infoMem['used_memory_human'])) {
                        $memHuman = $infoMem['used_memory_human'];
                    }
                    if (isset($infoMem['used_memory_peak_human'])) {
                        $memPeakHuman = $infoMem['used_memory_peak_human'];
                    }
                }

                $infoKs = @$r->info('keyspace');
                if (is_array($infoKs)) {
                    if (isset($infoKs['db0']) && preg_match('/keys=(\d+),expires=(\d+),avg_ttl=(\d+)/', $infoKs['db0'], $m0)) {
                        $db0Sessions = (int) $m0[1];
                        $db0AvgTtlMin = round(((int) $m0[3]) / 60000, 1);
                    }
                    foreach ($shopStats as $sid => &$st) {
                        $ksKey = 'db' . (int) $st['db'];
                        if (isset($infoKs[$ksKey]) && preg_match('/keys=(\d+),expires=(\d+),avg_ttl=(\d+)/', $infoKs[$ksKey], $mDb)) {
                            $st['pages'] = (int) $mDb[2];
                            $st['avg_ttl'] = round(((int) $mDb[3]) / 1000);
                        }
                    }
                    unset($st);
                }
                if ($db0Sessions === 0 && @$r->select(0)) {
                    $db0Sessions = (int) @$r->dbSize();
                }

                foreach ($shopStats as $sid => &$st) {
                    if (@$r->select((int) $st['db'])) {
                        if (@$r->get('fpc:disabled') === '1') {
                            $st['enabled'] = false;
                        }
                        $st['keys'] = (int) @$r->dbSize();
                        $st['hits'] = (int) @$r->get('fpc:stats:hits');
                        $st['misses'] = (int) @$r->get('fpc:stats:misses');
                        $st['logged_hits'] = (int) @$r->get('fpc:stats:logged_hits');
                        $st['cart_hits'] = (int) @$r->get('fpc:stats:cart_hits');
                        $st['wait_hits'] = (int) @$r->get('fpc:stats:wait_hits');
                    }
                }
                unset($st);
            } catch (\Throwable $e) {
                // Ignore
            }
        }

        // Gather Redis Stock Reservation stats
        $resScope = (string) Configuration::getGlobalValue('PS_REDIS_PERF_RESERVE_SHOPS');
        $resCartEnabled = (int) Configuration::getGlobalValue('PS_REDIS_PERF_RESERVE_CART_ENABLED') === 1;
        $resCartTtl = (int) Configuration::getGlobalValue('PS_REDIS_PERF_RESERVE_CART_TTL') ?: 180;
        $resPayEnabled = (int) Configuration::getGlobalValue('PS_REDIS_PERF_RESERVE_PAYMENT_ENABLED') === 1;
        $resPayTtl = (int) Configuration::getGlobalValue('PS_REDIS_PERF_RESERVE_PAYMENT_TTL') ?: 600;

        $resActiveHolds = [];
        $resReservedCart = [];
        $resReservedPay = [];
        $resBlocked = [];
        foreach (array_keys($managedShops) as $sid) {
            $resActiveHolds[$sid] = 0;
            $resReservedCart[$sid] = 0;
            $resReservedPay[$sid] = 0;
            $resBlocked[$sid] = 0;
        }
        if ($r !== null) {
            try {
                if (@$r->select($reserveDb)) {
                    $nowTs = time();
                    foreach (array_keys($managedShops) as $sid) {
                        $resReservedCart[$sid] = (int) @$r->get('sres:stats:s' . $sid . ':reserved_cart');
                        $resReservedPay[$sid] = (int) @$r->get('sres:stats:s' . $sid . ':reserved_pay');
                        $resBlocked[$sid] = (int) @$r->get('sres:stats:s' . $sid . ':blocked');

                        $activeSetKey = 'sres:active_holds:s' . $sid;
                        $holdKeys = @$r->sMembers($activeSetKey);
                        if (is_array($holdKeys)) {
                            foreach ($holdKeys as $hk) {
                                $val = @$r->get($hk);
                                if (is_string($val) && $val !== '') {
                                    $parts = explode(':', $val);
                                    if (((int) ($parts[0] ?? 0)) > 0 && ((int) ($parts[1] ?? 0)) >= $nowTs) {
                                        $resActiveHolds[$sid]++;
                                    } else {
                                        @$r->sRem($activeSetKey, $hk);
                                    }
                                } else {
                                    @$r->sRem($activeSetKey, $hk);
                                }
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Ignore
            }
        }
        $totalActiveHolds = array_sum($resActiveHolds);
        $totalBlocked = array_sum($resBlocked);
        $totalCartRes = array_sum($resReservedCart);
        $totalPayRes = array_sum($resReservedPay);

        if ($resScope !== '' && $resScope !== 'all' && isset($managedShops[(int) $resScope])) {
            $scShop = $managedShops[(int) $resScope];
            $scopeLabel = $scShop['name'] . ' Only (Shop #' . $scShop['id_shop'] . ' / DB ' . $scShop['db'] . ')';
        } else {
            $scopeLabel = 'All Active Shops';
        }

        $holdsPerShopParts = [];
        foreach ($managedShops as $sid => $shopInfo) {
            $holdsPerShopParts[] = htmlspecialchars($shopInfo['name'], ENT_QUOTES, 'UTF-8') . ' <strong>' . (int) ($resActiveHolds[$sid] ?? 0) . '</strong>';
        }
        $holdsPerShopHtml = implode(', ', $holdsPerShopParts);

        $actionUrl = AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules');

        $statusBadge = $enabled
            ? '<span class="badge badge-success" style="background:#2ecc71;font-size:13px;padding:5px 10px;">ENABLED</span>'
            : '<span class="badge badge-danger" style="background:#e74c3c;font-size:13px;padding:5px 10px;">DISABLED</span>';

        $stampedeLock = (int) Configuration::getGlobalValue('PS_REDIS_PERF_FPC_STAMPEDE_LOCK') === 1;
        $stampedeWaitMs = (int) Configuration::getGlobalValue('PS_REDIS_PERF_FPC_STAMPEDE_WAIT_MS');
        if ($stampedeWaitMs < 100) {
            $stampedeWaitMs = 1500;
        }

        $modeDesc = [];
        if ($enabled) {
            $modeDesc[] = 'Guests (Empty Cart)';
            if ($loggedEmpty) {
                $modeDesc[] = 'Logged-In Users (Empty Cart / Token-Swapped)';
            }
            if ($loggedCart) {
                $modeDesc[] = 'Logged-In & Guest Users With Items in Cart (Dynamic Cart Block + Token-Swapped)';
            }
            if ($stampedeLock) {
                $modeDesc[] = 'Stampede Lock (' . $stampedeWaitMs . 'ms max wait)';
            }
        }
        $activeModesHtml = !empty($modeDesc) ? implode(' &bull; ', $modeDesc) : 'Cache Disabled';

        $shopCardsHtml = '';
        $colClass = (count($shopStats) >= 3) ? 'col-md-4' : ((count($shopStats) === 1) ? 'col-md-12' : 'col-md-6');
        foreach ($shopStats as $sid => $st) {
            $dbId = (int) $st['db'];
            $tot = $st['hits'] + $st['misses'];
            $rate = $tot > 0 ? round(($st['hits'] / $tot) * 100, 1) . '%' : '0.0%';
            $shopCardsHtml .= '
                <div class="' . $colClass . '">
                    <div class="well" style="margin-bottom:12px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #ddd;padding-bottom:8px;margin-bottom:10px;">
                            <strong style="font-size:14px;"><i class="icon-shopping-cart"></i> ' . htmlspecialchars($st['label'], ENT_QUOTES, 'UTF-8') . ' &mdash; <code>Redis DB ' . $dbId . '</code></strong>
                            <form method="post" action="' . htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8') . '" style="margin:0;">
                                <input type="hidden" name="flush_shop_id" value="' . (int) $sid . '" />
                                <button type="submit" name="submitFlushPsRedisPerfCacheShop" class="btn btn-default btn-xs">
                                    <i class="icon-trash"></i> ' . $this->l('Flush DB ') . $dbId . '
                                </button>
                            </form>
                        </div>
                        <div class="row">
                            <div class="col-sm-4">
                                <div style="font-size:12px;color:#666;">' . $this->l('Cached Pages') . '</div>
                                <h3 style="margin:4px 0;">' . (int) $st['pages'] . ' <small>pages</small></h3>
                                <div style="font-size:11px;color:#888;">' . (int) $st['keys'] . ' total keys' . ($st['avg_ttl'] > 0 ? ' &bull; Avg TTL: ' . (int) $st['avg_ttl'] . 's' : '') . '</div>
                            </div>
                            <div class="col-sm-4">
                                <div style="font-size:12px;color:#666;">' . $this->l('Hits / Misses') . '</div>
                                <h3 style="margin:4px 0;">' . (int) $st['hits'] . ' <small>hits</small> / ' . (int) $st['misses'] . ' <small>misses</small></h3>
                                <div style="font-size:11px;color:#666;">Hit Rate: <strong>' . $rate . '</strong></div>
                            </div>
                            <div class="col-sm-4">
                                <div style="font-size:12px;color:#666;">' . $this->l('Drop Mode Hits') . '</div>
                                <div style="margin-top:6px;font-size:12px;">
                                    Logged-In: <strong>' . (int) $st['logged_hits'] . '</strong><br>
                                    In-Cart: <strong>' . (int) $st['cart_hits'] . '</strong><br>
                                    Stampede Saved: <strong>' . (int) $st['wait_hits'] . '</strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>';
        }

        $sqlViewEnabled = (Configuration::getGlobalValue('PS_REDIS_PERF_SQL_VIEW_ENABLED') !== '0');
        $sqlViewStatus = $sqlViewEnabled
            ? '<strong style="color:#27ae60;">ENABLED</strong> (<code>' . htmlspecialchars(_DB_PREFIX_, ENT_QUOTES, 'UTF-8') . 'product_listing_view</code> + Bulk Prefetch)'
            : '<strong style="color:#888;">DISABLED</strong> (Standard ProductAssemblerCore)';
        $sessionThrottleEnabled = (Configuration::getGlobalValue('PS_REDIS_PERF_SESSION_THROTTLE_ENABLED') !== '0');
        $sessionThrottleSeconds = (int) (Configuration::getGlobalValue('PS_REDIS_PERF_SESSION_THROTTLE_SECONDS') ?: 300);
        $disableQueryCache = (Configuration::getGlobalValue('PS_REDIS_PERF_DISABLE_QUERY_CACHE') !== '0');
        $autoFlushBoEdits = (Configuration::getGlobalValue('PS_REDIS_PERF_FPC_AUTOFLUSH_BO_EDITS') !== '0');
        $dbExtrasHtml = ($sessionThrottleEnabled ? '<strong style="color:#27ae60;">Session Throttle ON (' . $sessionThrottleSeconds . 's)</strong>' : '<span style="color:#888;">Session Throttle OFF</span>')
            . ' &bull; '
            . ($disableQueryCache ? '<strong style="color:#27ae60;">Query Cache Bypass ON</strong>' : '<span style="color:#888;">Query Cache Bypass OFF</span>')
            . ' &bull; '
            . ($autoFlushBoEdits ? '<strong style="color:#27ae60;">BO Edit Auto-Flush ON</strong>' : '<span style="color:#e67e22;">BO Edit Auto-Flush OFF (Stock/Orders Still Flush)</span>');

        return '
        <div class="panel">
            <div class="panel-heading">
                <i class="icon-dashboard"></i> ' . sprintf($this->l('Redis Live Status (DB 0 Sessions, Per-Shop FPC DBs, DB %d Stock Holds)'), $reserveDb) . '
            </div>
            <div class="row" style="margin-bottom:10px;">
                <div class="col-md-3">
                    <div class="well" style="margin-bottom:10px;min-height:115px;">
                        <strong>' . $this->l('Master Cache Status') . ':</strong><br><br>' . $statusBadge . '
                        <div style="margin-top:8px;font-size:12px;color:#666;">Redis <code>' . htmlspecialchars($redisEndpoint, ENT_QUOTES, 'UTF-8') . '</code>: ' . ($redisConnected ? '<span style="color:#27ae60;font-weight:bold;">Online</span>' : '<span style="color:#c0392b;font-weight:bold;">Offline</span>') . '</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="well" style="margin-bottom:10px;min-height:115px;">
                        <strong>' . $this->l('PHP Session Cache (DB 0)') . ':</strong>
                        <h3 style="margin:8px 0 4px 0;">' . (int) $db0Sessions . ' <small>active sessions</small></h3>
                        <div style="font-size:12px;color:#666;">Handler: <code>' . htmlspecialchars($sessionHandler ?: 'redis', ENT_QUOTES, 'UTF-8') . '</code>' . ($db0AvgTtlMin > 0 ? ' &bull; Avg TTL: <strong>' . $db0AvgTtlMin . 'm</strong>' : '') . '</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="well" style="margin-bottom:10px;min-height:115px;">
                        <strong>' . $this->l('Total Redis Memory Usage') . ':</strong>
                        <h3 style="margin:8px 0 4px 0;">' . htmlspecialchars($memHuman, ENT_QUOTES, 'UTF-8') . ' <small style="font-size:12px;color:#888;">(Peak: ' . htmlspecialchars($memPeakHuman, ENT_QUOTES, 'UTF-8') . ')</small></h3>
                        <div style="font-size:12px;color:#666;"><code>DB 0</code> Sessions &bull; Shop FPC DBs &bull; <code>DB ' . $reserveDb . '</code> Holds</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="well" style="margin-bottom:10px;min-height:115px;">
                        <strong>' . sprintf($this->l('Stock Reservations (DB %d)'), $reserveDb) . ':</strong>
                        <h3 style="margin:6px 0 4px 0;">' . (int) $totalActiveHolds . ' <small>active holds</small></h3>
                        <div style="font-size:11px;color:#666;">
                            Cart: ' . ($resCartEnabled ? '<strong style="color:#27ae60;">ON (' . $resCartTtl . 's)</strong>' : '<span style="color:#888;">OFF</span>') . ' &bull;
                            Pay: ' . ($resPayEnabled ? '<strong style="color:#27ae60;">ON (' . $resPayTtl . 's)</strong>' : '<span style="color:#888;">OFF</span>') . '<br>
                            Reserved (Cart/Pay): <strong>' . $totalCartRes . ' / ' . $totalPayRes . '</strong> &bull; Blocked: <strong style="color:#c0392b;">' . $totalBlocked . '</strong>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row" style="margin-bottom:10px;">
                ' . $shopCardsHtml . '
            </div>
            <div class="alert alert-info" style="margin-bottom:15px;">
                <strong>' . $this->l('Active Page Cache Coverage') . ':</strong> ' . $activeModesHtml . '<br>
                <strong>' . $this->l('Zero-Oversell Stock Holds Scope') . ':</strong> ' . htmlspecialchars($scopeLabel, ENT_QUOTES, 'UTF-8') . ' (Active holds: ' . $holdsPerShopHtml . ')<br>
                <strong>' . $this->l('MariaDB Category Listing View') . ':</strong> ' . $sqlViewStatus . '<br>
                <strong>' . $this->l('Additional Optimizations') . ':</strong> ' . $dbExtrasHtml . '
            </div>
            <form method="post" action="' . htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;">
                <button type="submit" name="submitFlushPsRedisPerfCacheAll" class="btn btn-warning">
                    <i class="icon-trash"></i> ' . $this->l('Flush All Shop Page Caches') . '
                </button>
                <button type="submit" name="submitClearPsRedisPerfReservations" class="btn btn-danger" style="margin-left:8px;">
                    <i class="icon-unlock"></i> ' . sprintf($this->l('Clear Active Stock Holds (DB %d)'), $reserveDb) . '
                </button>
                <button type="submit" name="submitResetPsRedisPerfStats" class="btn btn-default" style="margin-left:8px;">
                    <i class="icon-refresh"></i> ' . $this->l('Reset All Counters') . '
                </button>
            </form>
        </div>';
    }

    private function renderForm()
    {
        $managedShops = $this->getManagedShops();
        $reserveDb = $this->getReserveDbIndex();

        $shopScopeOptions = [
            ['id' => 'all', 'name' => $this->l('All Active Shops')],
        ];
        foreach ($managedShops as $sid => $shopInfo) {
            $shopScopeOptions[] = [
                'id' => (string) $sid,
                'name' => sprintf($this->l('%s Only (Shop #%d)'), $shopInfo['name'], $sid),
            ];
        }

        $fieldsFormRedis = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Redis Connection Settings'),
                    'icon' => 'icon-server',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->l('Redis Host or Unix Socket Path'),
                        'name' => 'PS_REDIS_PERF_REDIS_HOST',
                        'class' => 'fixed-width-xl',
                        'desc' => $this->l('Default: 127.0.0.1. For Unix sockets, enter an absolute path starting with / (e.g. /var/run/redis/redis-server.sock).'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Redis Port'),
                        'name' => 'PS_REDIS_PERF_REDIS_PORT',
                        'class' => 'fixed-width-sm',
                        'desc' => $this->l('Default: 6379. Ignored when using a Unix socket path.'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Redis Password (Optional)'),
                        'name' => 'PS_REDIS_PERF_REDIS_PASSWORD',
                        'class' => 'fixed-width-xl',
                        'desc' => $this->l('Leave blank if Redis does not require AUTH.'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Stock Reservations Redis DB Index'),
                        'name' => 'PS_REDIS_PERF_RESERVE_DB',
                        'class' => 'fixed-width-sm',
                        'desc' => $this->l('Default: 3 (Range 1–15). Dedicated DB isolated from PHP sessions (DB 0) and per-shop page caches so FPC flushes never clear active stock holds.'),
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save Settings'),
                ],
            ],
        ];

        $fieldsForm = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Redis Full-Page Microcache Settings'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->l('Enable Redis Full-Page Cache (Master Switch)'),
                        'name' => 'PS_REDIS_PERF_FPC_ENABLED',
                        'is_bool' => true,
                        'desc' => $this->l('Enable or disable full-page HTML caching in Redis across all active shops.'),
                        'values' => [
                            ['id' => 'fpc_enabled_on', 'value' => 1, 'label' => $this->l('Enabled')],
                            ['id' => 'fpc_enabled_off', 'value' => 0, 'label' => $this->l('Disabled')],
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Option 1: Cache for Logged-In Users (Empty Cart)'),
                        'name' => 'PS_REDIS_PERF_FPC_LOGGED_EMPTY',
                        'is_bool' => true,
                        'desc' => $this->l('Recommended for high-contention flash sales and product drops. Caches catalog/product pages per customer group for logged-in users with an empty cart, dynamically swapping each user\'s personal CSRF tokens (static_token & token) in <0.2 ms.'),
                        'values' => [
                            ['id' => 'fpc_logged_empty_on', 'value' => 1, 'label' => $this->l('Enabled')],
                            ['id' => 'fpc_logged_empty_off', 'value' => 0, 'label' => $this->l('Disabled')],
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Option 2: Cache for Users With Items in Cart (cartCount > 0)'),
                        'name' => 'PS_REDIS_PERF_FPC_LOGGED_CART',
                        'is_bool' => true,
                        'desc' => $this->l('Allows logged-in and guest users who already have 1 or more items in their cart to still receive cached catalog/product pages, dynamically injecting their personal header basket badge (#_desktop_cart), cart JSON, and CSRF tokens.'),
                        'values' => [
                            ['id' => 'fpc_logged_cart_on', 'value' => 1, 'label' => $this->l('Enabled')],
                            ['id' => 'fpc_logged_cart_off', 'value' => 0, 'label' => $this->l('Disabled')],
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Enable Cold-Cache Stampede Protection (Dog-Pile Lock)'),
                        'name' => 'PS_REDIS_PERF_FPC_STAMPEDE_LOCK',
                        'is_bool' => true,
                        'desc' => $this->l('When multiple users request the exact same uncached page simultaneously (e.g. right after a cache flush during a drop), only the 1st request queries MySQL/Smarty while concurrent requests wait briefly and receive the newly cached HTML as a HIT-WAIT.'),
                        'values' => [
                            ['id' => 'fpc_stampede_on', 'value' => 1, 'label' => $this->l('Enabled')],
                            ['id' => 'fpc_stampede_off', 'value' => 0, 'label' => $this->l('Disabled')],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Stampede Lock Max Wait Time (milliseconds)'),
                        'name' => 'PS_REDIS_PERF_FPC_STAMPEDE_WAIT_MS',
                        'class' => 'fixed-width-sm',
                        'suffix' => $this->l('ms'),
                        'desc' => $this->l('Default: 1500 ms (1.5 seconds). Concurrent requests poll Redis every 25 ms up to this limit waiting for the 1st worker to finish rendering.'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Cache Lifetime / TTL (seconds)'),
                        'name' => 'PS_REDIS_PERF_FPC_TTL',
                        'class' => 'fixed-width-sm',
                        'suffix' => $this->l('seconds'),
                        'desc' => $this->l('Default: 300 seconds (5 minutes). Note: the cache is also flushed automatically whenever stock changes or an order is placed.'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Cacheable Front Controllers'),
                        'name' => 'PS_REDIS_PERF_FPC_CONTROLLERS',
                        'desc' => $this->l('Comma-separated list of FrontController php_self names eligible for caching. Private controllers (cart, order, my-account, addresses, identity, history, auth) are never cached.'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Header-Hook Modules to Run on Cache HIT'),
                        'name' => 'PS_REDIS_PERF_FPC_HIT_HOOK_MODULES',
                        'desc' => $this->l('Optional comma-separated list of lightweight module technical names (e.g. ps_googleanalytics) whose hookDisplayHeader() should still execute on cached page hits for server-side analytics/session tracking. Leave empty to run none.'),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Auto-Flush Cache on Back Office Catalog & Order-Status Edits'),
                        'name' => 'PS_REDIS_PERF_FPC_AUTOFLUSH_BO_EDITS',
                        'is_bool' => true,
                        'desc' => $this->l('Automatically flush the shop\'s Redis page cache when products, categories, images, or order statuses are updated in Back Office. Stock quantity updates and completed customer orders ALWAYS flush the cache regardless of this setting.'),
                        'values' => [
                            ['id' => 'fpc_autoflush_bo_on', 'value' => 1, 'label' => $this->l('Enabled')],
                            ['id' => 'fpc_autoflush_bo_off', 'value' => 0, 'label' => $this->l('Disabled')],
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save Settings'),
                ],
            ],
        ];

        $fieldsFormReserve = [
            'form' => [
                'legend' => [
                    'title' => sprintf($this->l('Zero-Oversell Stock Reservations (Redis DB %d — Drop Protection)'), $reserveDb),
                    'icon' => 'icon-lock',
                ],
                'input' => [
                    [
                        'type' => 'select',
                        'label' => $this->l('Apply Stock Reservations To'),
                        'name' => 'PS_REDIS_PERF_RESERVE_SHOPS',
                        'desc' => $this->l('Choose whether atomic Redis stock reservations apply to all active shops or only to a single shop.'),
                        'options' => [
                            'query' => $shopScopeOptions,
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Option A: Reserve Stock on Add to Cart'),
                        'name' => 'PS_REDIS_PERF_RESERVE_CART_ENABLED',
                        'is_bool' => true,
                        'desc' => $this->l('Strict Drop Mode: When a customer adds a product to their cart, atomically hold that unit in Redis using a per-item mutex lock. If 10 users try to cart 2 available units at the exact same millisecond, only 2 succeed and 8 are immediately rejected. Hold auto-expires after the Cart Reservation TTL if unpaid.'),
                        'values' => [
                            ['id' => 'res_cart_on', 'value' => 1, 'label' => $this->l('Enabled')],
                            ['id' => 'res_cart_off', 'value' => 0, 'label' => $this->l('Disabled')],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Option A: Cart Reservation Duration (seconds)'),
                        'name' => 'PS_REDIS_PERF_RESERVE_CART_TTL',
                        'class' => 'fixed-width-sm',
                        'suffix' => $this->l('seconds'),
                        'desc' => $this->l('Default: 180 seconds (3 minutes). Min: 30s, Max: 3600s. Released immediately if the customer removes the item or completes checkout. Cached pages auto-expire right when a hold ends.'),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Option B: Reserve Stock on Proceed to Payment'),
                        'name' => 'PS_REDIS_PERF_RESERVE_PAYMENT_ENABLED',
                        'is_bool' => true,
                        'desc' => $this->l('When a customer clicks the final Pay button, atomically lock every item in their cart in Redis before redirecting to the external payment gateway. Prevents simultaneous payment-redirect oversells (backorders).'),
                        'values' => [
                            ['id' => 'res_pay_on', 'value' => 1, 'label' => $this->l('Enabled')],
                            ['id' => 'res_pay_off', 'value' => 0, 'label' => $this->l('Disabled')],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Option B: Payment Reservation Duration (seconds)'),
                        'name' => 'PS_REDIS_PERF_RESERVE_PAYMENT_TTL',
                        'class' => 'fixed-width-sm',
                        'suffix' => $this->l('seconds'),
                        'desc' => $this->l('Default: 600 seconds (10 minutes). Min: 60s, Max: 3600s. Covers the time the customer spends authorizing payment at their bank / payment provider. Released automatically as soon as the order is validated.'),
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save Settings'),
                ],
            ],
        ];

        $fieldsFormDb = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Database, Session & Query Optimizations'),
                    'icon' => 'icon-database',
                ],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->l('Enable MariaDB Category Listing View (ps_product_listing_view)'),
                        'name' => 'PS_REDIS_PERF_SQL_VIEW_ENABLED',
                        'is_bool' => true,
                        'desc' => $this->l('Offloads category product assembly, combination stock, and price hydration to MariaDB product_listing_view along with bulk prefetching of images, features, combination images, and stock availability (reduces category page SQL queries by ~5–10x on cache misses). Disable to fall back to vanilla PrestaShop ProductAssemblerCore.'),
                        'values' => [
                            ['id' => 'sql_view_on', 'value' => 1, 'label' => $this->l('Enabled')],
                            ['id' => 'sql_view_off', 'value' => 0, 'label' => $this->l('Disabled')],
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Throttle Session DB Writes (Cookie::getSession)'),
                        'name' => 'PS_REDIS_PERF_SESSION_THROTTLE_ENABLED',
                        'is_bool' => true,
                        'desc' => $this->l('Avoids running an UPDATE query on ps_customer_session / ps_employee_session on every single page load by only saving when the throttle interval has elapsed.'),
                        'values' => [
                            ['id' => 'sess_throttle_on', 'value' => 1, 'label' => $this->l('Enabled')],
                            ['id' => 'sess_throttle_off', 'value' => 0, 'label' => $this->l('Disabled')],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Session DB Write Throttle Interval (seconds)'),
                        'name' => 'PS_REDIS_PERF_SESSION_THROTTLE_SECONDS',
                        'class' => 'fixed-width-sm',
                        'suffix' => $this->l('seconds'),
                        'desc' => $this->l('Default: 300 seconds (5 minutes). Min: 30s, Max: 86400s. Session date_upd is only written to MariaDB if more than this many seconds have passed.'),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Bypass PrestaShop Internal SQL Query Cache (Cache::setQuery)'),
                        'name' => 'PS_REDIS_PERF_DISABLE_QUERY_CACHE',
                        'is_bool' => true,
                        'desc' => $this->l('Disables PrestaShop\'s built-in table-to-query map tracking in Cache::setQuery() to prevent cache write contention and serialization overhead. Disable only if benchmarking native PrestaShop query caching.'),
                        'values' => [
                            ['id' => 'disable_qcache_on', 'value' => 1, 'label' => $this->l('Enabled')],
                            ['id' => 'disable_qcache_off', 'value' => 0, 'label' => $this->l('Disabled')],
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save Settings'),
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitPsRedisPerfConfig';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = [
            'fields_value' => [
                'PS_REDIS_PERF_REDIS_HOST' => (string) (Configuration::getGlobalValue('PS_REDIS_PERF_REDIS_HOST') ?: '127.0.0.1'),
                'PS_REDIS_PERF_REDIS_PORT' => (int) (Configuration::getGlobalValue('PS_REDIS_PERF_REDIS_PORT') ?: 6379),
                'PS_REDIS_PERF_REDIS_PASSWORD' => (string) Configuration::getGlobalValue('PS_REDIS_PERF_REDIS_PASSWORD'),
                'PS_REDIS_PERF_RESERVE_DB' => $reserveDb,
                'PS_REDIS_PERF_FPC_ENABLED' => (int) Configuration::getGlobalValue('PS_REDIS_PERF_FPC_ENABLED'),
                'PS_REDIS_PERF_FPC_LOGGED_EMPTY' => (int) Configuration::getGlobalValue('PS_REDIS_PERF_FPC_LOGGED_EMPTY'),
                'PS_REDIS_PERF_FPC_LOGGED_CART' => (int) Configuration::getGlobalValue('PS_REDIS_PERF_FPC_LOGGED_CART'),
                'PS_REDIS_PERF_FPC_STAMPEDE_LOCK' => (int) Configuration::getGlobalValue('PS_REDIS_PERF_FPC_STAMPEDE_LOCK'),
                'PS_REDIS_PERF_FPC_STAMPEDE_WAIT_MS' => (int) Configuration::getGlobalValue('PS_REDIS_PERF_FPC_STAMPEDE_WAIT_MS'),
                'PS_REDIS_PERF_FPC_TTL' => (int) Configuration::getGlobalValue('PS_REDIS_PERF_FPC_TTL'),
                'PS_REDIS_PERF_FPC_CONTROLLERS' => (string) Configuration::getGlobalValue('PS_REDIS_PERF_FPC_CONTROLLERS'),
                'PS_REDIS_PERF_FPC_HIT_HOOK_MODULES' => (string) Configuration::getGlobalValue('PS_REDIS_PERF_FPC_HIT_HOOK_MODULES'),
                'PS_REDIS_PERF_FPC_AUTOFLUSH_BO_EDITS' => (Configuration::getGlobalValue('PS_REDIS_PERF_FPC_AUTOFLUSH_BO_EDITS') !== '0') ? 1 : 0,
                'PS_REDIS_PERF_RESERVE_SHOPS' => (string) (Configuration::getGlobalValue('PS_REDIS_PERF_RESERVE_SHOPS') ?: 'all'),
                'PS_REDIS_PERF_RESERVE_CART_ENABLED' => (int) Configuration::getGlobalValue('PS_REDIS_PERF_RESERVE_CART_ENABLED'),
                'PS_REDIS_PERF_RESERVE_CART_TTL' => (int) (Configuration::getGlobalValue('PS_REDIS_PERF_RESERVE_CART_TTL') ?: 180),
                'PS_REDIS_PERF_RESERVE_PAYMENT_ENABLED' => (int) Configuration::getGlobalValue('PS_REDIS_PERF_RESERVE_PAYMENT_ENABLED'),
                'PS_REDIS_PERF_RESERVE_PAYMENT_TTL' => (int) (Configuration::getGlobalValue('PS_REDIS_PERF_RESERVE_PAYMENT_TTL') ?: 600),
                'PS_REDIS_PERF_SQL_VIEW_ENABLED' => (Configuration::getGlobalValue('PS_REDIS_PERF_SQL_VIEW_ENABLED') !== '0') ? 1 : 0,
                'PS_REDIS_PERF_SESSION_THROTTLE_ENABLED' => (Configuration::getGlobalValue('PS_REDIS_PERF_SESSION_THROTTLE_ENABLED') !== '0') ? 1 : 0,
                'PS_REDIS_PERF_SESSION_THROTTLE_SECONDS' => (int) (Configuration::getGlobalValue('PS_REDIS_PERF_SESSION_THROTTLE_SECONDS') ?: 300),
                'PS_REDIS_PERF_DISABLE_QUERY_CACHE' => (Configuration::getGlobalValue('PS_REDIS_PERF_DISABLE_QUERY_CACHE') !== '0') ? 1 : 0,
            ],
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        return $helper->generateForm([$fieldsForm, $fieldsFormReserve, $fieldsFormDb, $fieldsFormRedis]);
    }
    
    private function touchProduct($idProduct)
    {
        $id = (int) $idProduct;
        if ($id) {
            $now = date('Y-m-d H:i:s');
            Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . 'product` SET `date_upd` = \'' . $now . '\' WHERE `id_product` = ' . $id);
            Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . 'product_shop` SET `date_upd` = \'' . $now . '\' WHERE `id_product` = ' . $id);
            Cache::clean('Product::*');
        }
    }

    private function shouldAutoFlushBoEdits()
    {
        return Configuration::getGlobalValue('PS_REDIS_PERF_FPC_AUTOFLUSH_BO_EDITS') !== '0';
    }

    public function hookActionProductUpdate($params)
    {
        if (!$this->shouldAutoFlushBoEdits()) {
            return;
        }
        $idProduct = (int) ($params['id_product'] ?? ($params['product']->id ?? 0));
        $this->purgeRedisPageCache(false, $this->getShopDbsForProduct($idProduct));
    }

    public function hookActionProductDelete($params)
    {
        if (!$this->shouldAutoFlushBoEdits()) {
            return;
        }
        $idProduct = (int) ($params['id_product'] ?? ($params['product']->id ?? 0));
        $this->purgeRedisPageCache(false, $this->getShopDbsForProduct($idProduct));
    }

    public function hookActionObjectImageAddAfter($params)
    {
        $idProduct = (isset($params['object']) && $params['object'] instanceof Image) ? (int) $params['object']->id_product : 0;
        if ($this->shouldAutoFlushBoEdits()) {
            $this->purgeRedisPageCache(false, $this->getShopDbsForProduct($idProduct));
        }
        if ($idProduct > 0) {
            $this->touchProduct($idProduct);
        }
    }

    public function hookActionObjectImageUpdateAfter($params)
    {
        $idProduct = (isset($params['object']) && $params['object'] instanceof Image) ? (int) $params['object']->id_product : 0;
        if ($this->shouldAutoFlushBoEdits()) {
            $this->purgeRedisPageCache(false, $this->getShopDbsForProduct($idProduct));
        }
        if ($idProduct > 0) {
            $this->touchProduct($idProduct);
        }
    }

    public function hookActionObjectImageDeleteAfter($params)
    {
        $idProduct = (isset($params['object']) && $params['object'] instanceof Image) ? (int) $params['object']->id_product : 0;
        if ($this->shouldAutoFlushBoEdits()) {
            $this->purgeRedisPageCache(false, $this->getShopDbsForProduct($idProduct));
        }
        if ($idProduct > 0) {
            $this->touchProduct($idProduct);
        }
    }

    public function hookActionProductSave($params)
    {
        if (!$this->shouldAutoFlushBoEdits()) {
            return;
        }
        $idProduct = (int) ($params['id_product'] ?? ($params['product']->id ?? 0));
        $this->purgeRedisPageCache(false, $this->getShopDbsForProduct($idProduct));
    }

    public function hookActionUpdateQuantity($params)
    {
        $idProduct = (int) ($params['id_product'] ?? 0);
        $this->purgeRedisPageCache(false, $this->getShopDbsForProduct($idProduct));
    }

    public function hookActionValidateOrder($params)
    {
        $idShop = (int) ($params['order']->id_shop ?? ($this->context->shop->id ?? 0));
        $idCart = (int) ($params['cart']->id ?? ($params['order']->id_cart ?? 0));
        if ($idCart > 0 && method_exists('StockAvailable', 'releaseAllCartReservations')) {
            StockAvailable::releaseAllCartReservations($idCart, $idShop > 0 ? $idShop : null);
        }
        $targetDbs = ($idShop > 0) ? [$this->getShopFpcDb($idShop)] : $this->getAllShopFpcDbs();
        $this->purgeRedisPageCache(false, $targetDbs);
    }

    public function hookActionObjectProductInCartDeleteAfter($params)
    {
        $idCart = (int) ($params['id_cart'] ?? ($this->context->cart->id ?? 0));
        $idProduct = (int) ($params['id_product'] ?? 0);
        $idProductAttribute = (int) ($params['id_product_attribute'] ?? 0);
        $idShop = (int) ($this->context->shop->id ?? 1);
        if ($idCart > 0 && $idProduct > 0 && method_exists('StockAvailable', 'releaseCartItemReservation')) {
            StockAvailable::releaseCartItemReservation($idCart, $idProduct, $idProductAttribute, $idShop);
        }
    }

    public function hookActionOrderStatusPostUpdate($params)
    {
        if (!$this->shouldAutoFlushBoEdits()) {
            return;
        }
        $idOrder = (int) ($params['id_order'] ?? 0);
        if ($idOrder > 0) {
            $order = new Order($idOrder);
            if (Validate::isLoadedObject($order) && (int) $order->id_shop > 0) {
                $this->purgeRedisPageCache(false, [$this->getShopFpcDb((int) $order->id_shop)]);
                return;
            }
        }
        $this->purgeRedisPageCache(false, $this->getAllShopFpcDbs());
    }

    public function hookActionCategoryUpdate($params)
    {
        if (!$this->shouldAutoFlushBoEdits()) {
            return;
        }
        $this->purgeRedisPageCache(false, $this->getAllShopFpcDbs());
    }
}
