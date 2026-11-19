# PrestaShop 8 Front-Office Performance & Flash-Sale Protection (`ps_redis_perf`)

An open-source PrestaShop 8 performance suite built for **Apache / Nginx + PHP-FPM + Redis + MariaDB**, focused on high-traffic front-office speed and high-contention limited-edition product drops.

## Key Features

### 1. Redis Full-Page Microcache (Guest + Logged-In Drop Mode)
* **Native PHP-level FPC:** Works on standard Apache/Nginx without migrating to LiteSpeed or maintaining Varnish VCL/ESI rules.
* **Per-Shop Isolated Redis DBs:** Keeps PHP sessions (`DB 0`), per-shop page caches (`DB 1, 2, ...`), and stock reservations (`Reserve DB`, default `3`) isolated so flushing a shop's page cache never drops active sessions or stock holds.
* **Option 1 — Logged-In Empty-Cart Caching:** Caches catalog/product HTML per customer group for authenticated shoppers, scrubbing customer PII from `var prestashop` JSON and dynamically swapping each user's personal CSRF tokens (`static_token` & `token`) on every cache hit.
* **Option 2 — In-Cart Caching (`cartCount > 0`):** Serves cached catalog/product pages even when a shopper already has items in their cart, dynamically injecting their personal `#_desktop_cart` widget and `products_count`.
* **Automatic Per-Customer Specific-Price Bypass:** Dynamically detects customers with custom `ps_specific_price` rows (cached in Redis for 5 min) and bypasses FPC for them.
* **Cold-Cache Stampede Protection (Dog-Pile Lock):** Uses an atomic Redis `SET NX EX` lock so when 10+ buyers hit an uncached page simultaneously after a flush, only 1 worker renders from MariaDB/Smarty while concurrent requests wait briefly and receive `X-Page-Cache: HIT-WAIT`.

### 2. Zero-Oversell Atomic Stock Reservations (Drop Protection)
* **Option A — Reserve Stock on Add-to-Cart:** Uses a per-item Redis mutex lock (`SET NX PX`) to atomically reserve units for a configurable TTL (default `180s` / 3 min). Eliminates overselling when many buyers click *Add to Cart* on limited stock at the exact same millisecond.
* **Option B — Reserve Stock on Proceed-to-Payment:** Atomically holds all cart items for a configurable TTL (default `600s` / 10 min) right before external payment-gateway redirection (`OrderController::displayAjaxCheckCartStillOrderable` + PayPal `ecinit`/`scinit`).
* **Hold-Aware FPC TTL Clamping:** Caps page-cache TTL to the earliest expiring active stock hold so abandoned carts automatically reappear in stock right when their hold expires.

### 3. Cold-Miss Database, Session & Query Optimizations
* **MariaDB Category Listing View (`ps_product_listing_view`) + Bulk Prefetching:** Offloads category product assembly, combination stock, and price hydration to a MariaDB view (auto-created on install) paired with bulk prefetchers for images, combination images, features, and stock (`ProductAssembler`, `Product`, `StockAvailable`). Reduces category SQL queries by ~5–10× on cache misses.
* **Session DB Write Throttling (`Cookie::getSession`):** Avoids running `UPDATE` queries on `ps_customer_session` / `ps_employee_session` on every page load by throttling writes to a configurable interval (default `300s`).
* **Internal SQL Query Cache Bypass (`Cache::setQuery`):** Disables PrestaShop's legacy table-to-query cache map to eliminate serialization/write contention.
* **Bulk Group Reduction & Multi-Format Image Duplication:** Single-query bulk `GroupReduction::duplicateReduction()` and multi-format (`jpg`/`webp`/`avif`/`2x`) file copy in `ProductDuplicator`.

---

## Requirements

* PrestaShop **8.x**
* PHP **8.1+** with the **`phpredis`** extension (`ext-redis`)
* **Redis** server (recommended: `maxmemory-policy volatile-lru`)
* **MariaDB 10.2+** or **MySQL 8.0+** (window functions `ROW_NUMBER()` and `JSON_OBJECT()` are used if the optional SQL listing view is enabled)

---

## Architecture & Redis DB Layout

| Redis DB | Purpose |
| :--- | :--- |
| `DB 0` | PHP Sessions (untouched by module flushes) |
| `DB 1`, `DB 2`, `DB 4+` | Per-shop Full-Page Microcache (auto-mapped per active `id_shop`, skipping the Reserve DB) |
| `DB 3` *(configurable)* | Atomic Stock Reservations (`sres:*`), completely isolated from FPC `FLUSHDB` |

---

## Installation

1. Copy (or clone) this repository into your PrestaShop `modules/ps_redis_perf/` directory.
2. Run `composer install --no-dev -o` inside `modules/ps_redis_perf/` (generates PSR-4 autoloading for the optional `ProductDuplicator` Symfony service).
3. Install via **Back Office → Modules → Module Manager** (or CLI `php bin/console prestashop:module install ps_redis_perf`).
   * On install, the module registers its hooks, initializes safe default configuration, and creates the `ps_specific_price_best` and `ps_product_listing_view` MariaDB views if they do not already exist.
4. Clear PrestaShop's class index and Symfony production cache (`var/cache/prod`), and reload PHP-FPM if OPcache timestamp validation is disabled.
5. Configure everything under **Modules → Redis Performance & Drop Protection → Configure**.

---

## Core Overrides Included

This module overrides the following PrestaShop core classes (check for conflicts if you use other override-heavy modules):

* `override/classes/controller/FrontController.php` — Redis FPC, stampede lock, CSRF token/cart personalization, PayPal Option B guard
* `override/classes/stock/StockAvailable.php` — Redis connection/DB mapping, atomic DB 3 stock reservations, bulk stock prefetching
* `override/controllers/front/CartController.php` — Option A add-to-cart reservation & `$qty_to_check > 0` decrement guard
* `override/controllers/front/OrderController.php` — Option B pre-payment reservation guard
* `override/classes/ProductAssembler.php` — MariaDB listing view fast-path + bulk prefetching
* `override/classes/Product.php` — Bulk image, combination image, and front feature caches
* `override/classes/Cookie.php` — `ps_logged` cookie sync & throttled session DB writes
* `override/classes/cache/Cache.php` — Configurable `Cache::setQuery()` bypass
* `override/classes/GroupReduction.php` — Bulk single-query `duplicateReduction()`

---

## Caveats & Notes for Third-Party Themes

* **Option 2 (In-Cart Caching)** expects Classic-theme `#_desktop_cart` header markup. If your theme uses a custom AJAX cart widget or different DOM wrapper, disable Option 2 in the Back Office settings (or adapt `prepareHtmlForFpcStorage()` / `renderFpcDesktopCartHtml()`).
* **SQL Listing View:** Automatically falls back to `ProductAssemblerCore` for non-standard customer groups (e.g., B2B wholesale groups) or if the SQL view is disabled/missing.

---

## License

MIT License — see [LICENSE](LICENSE).
