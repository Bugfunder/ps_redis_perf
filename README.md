# PrestaShop 8 Front-Office Performance & Flash-Sale Protection (`ps_redis_perf`)

[![PHP Lint](https://github.com/Bugfunder/ps_redis_perf/actions/workflows/lint.yml/badge.svg)](https://github.com/Bugfunder/ps_redis_perf/actions/workflows/lint.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PrestaShop 8](https://img.shields.io/badge/PrestaShop-8.x-DF0067.svg)](https://github.com/PrestaShop/PrestaShop)

An open-source PrestaShop 8 performance suite built for **Apache / Nginx + PHP-FPM + Redis + MariaDB**, focused on high-traffic front-office speed and high-contention limited-edition product drops.

---

## Architecture Overview

```mermaid
flowchart LR
    U["Shopper Request (Guest / Logged-In / In-Cart)"] --> FC["FrontController::setMedia()"]
    FC -->|"GET cacheable"| R1["Redis Shop FPC DB (DB 1, 2, 4+)"]
    R1 -->|"HIT"| P["Personalize CSRF Tokens + #_desktop_cart (<1 ms)"]
    P --> U
    R1 -->|"MISS + Concurrent"| LK["Atomic Stampede Lock (SET NX EX 3)"]
    LK -->|"Lock Holder (1st worker)"| PA["ProductAssembler + ps_product_listing_view + Bulk Prefetch"]
    LK -->|"Other Workers"| W["Poll Redis (25 ms) -> HIT-WAIT"]
    PA --> DB[("MariaDB")]
    PA -->|"Store HTML (TTL <= next hold expiry)"| R1
    W --> P

    U -->|"POST Add to Cart / Pay"| CC["CartController / OrderController"]
    CC -->|"Atomic Per-Item Mutex"| R3["Redis Stock Holds DB (DB 3 — Isolated)"]
    R3 -->|"Available"| OK["Hold Unit (TTL 180s / 600s) + Flush Shop FPC"]
    R3 -->|"Depleted"| REJ["Immediate Reject (Zero Oversell)"]
```

### Redis Database Isolation

| Redis DB | Purpose | Flushing Behavior |
| :--- | :--- | :--- |
| `DB 0` | PHP Sessions (`session.save_handler = redis`) | Never touched by module flushes |
| `DB 1`, `DB 2`, `DB 4+` | Per-shop Full-Page Microcache (auto-mapped per `id_shop`, skipping Reserve DB) | Flushed per shop on stock/order/BO events |
| `DB 3` *(configurable)* | Atomic Stock Reservations (`sres:*`) | Completely isolated from FPC `FLUSHDB` |

---

## Production Benchmarks

Measured on a real multi-shop PrestaShop 8.x store (**Apache + PHP 8.4-FPM + Redis + MariaDB**, heavy category pages with product combinations and filters):

| Test Scenario | Result |
| :--- | :--- |
| **Sustained Load Test (20 RPS mixed category + product traffic)** | **314 / 314 HTTP 200**, **222 / 222** HTML integrity checks passed, **`p50 = 86.8 ms`**, **`p95 = 228.0 ms`**, **`p99 = 450.2 ms`** |
| **Cold-Cache Stampede (10 simultaneous cold requests after flush)** | **`1 MISS` + `9 HIT-WAIT`** (1 MariaDB/Smarty render instead of 10) |
| **Flash-Sale Contention (10 concurrent buyers carting 2 units)** | **`2 Succeeded` / `8 Blocked`** — **0 oversells**, holds auto-expire after configured TTL |
| **Category Listing Cold Miss (`ps_product_listing_view` + bulk prefetch)** | **~5–10× fewer SQL queries** compared to vanilla `ProductAssemblerCore` |

---

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
* **MariaDB Category Listing View (`ps_product_listing_view`) + Bulk Prefetching:** Offloads category product assembly, combination stock, and price hydration to a MariaDB view (auto-created on install) paired with bulk prefetchers for images, combination images, features, and stock (`ProductAssembler`, `Product`, `StockAvailable`).
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

## Installation

### Option A — PrestaShop Back Office (Recommended)
1. Download the latest `ps_redis_perf-*.zip` from the [GitHub Releases](https://github.com/Bugfunder/ps_redis_perf/releases) page (includes pre-built Composer autoloader).
2. Upload and install via **Back Office → Modules → Module Manager → Upload a module**.

### Option B — From Source (Git)
1. Clone this repository into your PrestaShop `modules/ps_redis_perf/` directory.
2. Run `composer dump-autoload --no-dev -o` inside `modules/ps_redis_perf/` (generates PSR-4 autoloading for the optional `ProductDuplicator` Symfony service).
3. Install via **Back Office → Modules → Module Manager** (or CLI `php bin/console prestashop:module install ps_redis_perf`).

### Post-Install
* On install, the module registers its hooks, initializes safe default configuration, and creates the `ps_specific_price_best` and `ps_product_listing_view` MariaDB views if they do not already exist.
* Clear PrestaShop's class index and Symfony production cache (`var/cache/prod`), and reload PHP-FPM if OPcache timestamp validation is disabled.
* Configure everything under **Modules → Redis Performance & Drop Protection → Configure**.

---

## Core Overrides Included

This module overrides the following PrestaShop core classes (check for conflicts if you use other override-heavy modules):

* `override/classes/controller/FrontController.php` — Redis FPC, stampede lock, CSRF token/cart personalization, PayPal Option B guard
* `override/classes/stock/StockAvailable.php` — Redis connection/DB mapping, atomic stock reservations, bulk stock prefetching
* `override/controllers/front/CartController.php` — Option A add-to-cart reservation & `$qty_to_check > 0` decrement guard
* `override/controllers/front/OrderController.php` — Option B pre-payment reservation guard
* `override/classes/ProductAssembler.php` — MariaDB listing view fast-path + bulk prefetching
* `override/classes/Product.php` — Bulk image, combination image, and front feature caches
* `override/classes/Cookie.php` — `ps_logged` cookie sync & throttled session DB writes
* `override/classes/cache/Cache.php` — Configurable `Cache::setQuery()` bypass
* `override/classes/GroupReduction.php` — Bulk single-query `duplicateReduction()`

---

## Caveats & Notes for Third-Party Themes

* **Option 2 (In-Cart Caching)** is disabled by default on fresh installs because it expects Classic-theme `#_desktop_cart` header markup. If your theme uses a custom AJAX cart widget or different DOM wrapper, leave Option 2 disabled or adapt `prepareHtmlForFpcStorage()` / `renderFpcDesktopCartHtml()`.
* **SQL Listing View:** Automatically falls back to `ProductAssemblerCore` for non-standard customer groups (e.g., B2B wholesale groups) or if the SQL view is disabled/missing.

---

## License

MIT License — see [LICENSE](LICENSE).
