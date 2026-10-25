# silvershop-suite/card-cache

[![CI](https://github.com/silvershop-suite/silvershop-card-cache/actions/workflows/ci.yml/badge.svg)](https://github.com/silvershop-suite/silvershop-card-cache/actions/workflows/ci.yml)

Materialised (pre-rendered) product-card HTML for SilverShop listings.

A product listing normally renders the `ProductCard` template for every tile on every request, and
each tile pulls its own data (image, price, brand, reviews, stock, …) — roughly 10–15 queries per
card plus the render. This module renders each card **once**, stores the HTML in a dedicated table,
and lets the listing concatenate the stored markup after a single `WHERE ProductID IN (…)` read. No
per-tile render, no per-tile query.

Unlike SilverStripe's `<% cached %>` partial cache — which is *lazy*: a block is only built when a
page is first requested, so on a large catalogue most pages are cold until someone visits them — this
cache is **push**: `WarmProductCardsTask` builds every card up front and publishing a product rebuilds
its card, so the cache is warm the moment a product goes live. It is durable (survives `?flush=all`,
lives in the DB) and primes the whole page in one query.

## How it works

- **`CachedProductCard`** — one row per (product, variant) holding the rendered `HTML`, a `Hash`
  fingerprint and `LastBuilt`. A dedicated table, so it stays out of `Product::get()` hydration and
  can be truncated or rebuilt on its own.
- **`ProductCardCacheExtension`** (on `Product`) — `primeProductCards()` fills a request-static map
  with one `IN (…)` query; `getCachedCard($pos)` serves from it (hash hit) or renders-and-stores on a
  miss (`warm_on_miss`). Publishing / unpublishing / deleting a product drops its rows.
- **`ProductCardCacheControllerExtension`** (on `ProductCategoryController`) — `CardCacheEnabled` gate
  and `PrimeProductCardCache` one-shot page priming for the template.
- **`WarmProductCardsTask`** — `/dev/tasks/SilverShop-CardCache-Dev-WarmProductCardsTask`
  (`?limit=`, `?variant=`, `?purge=1`) — proactive push warming.

## Enabling it

Off by default as opt-in hygiene — installing changes nothing until you turn it on:

```yaml
SilverShop\CardCache\ProductCardCacheExtension:
  enabled: true
```

Per-visitor correctness is handled (see below), so it is safe on a multi-user storefront with the two
noted caveats (no-JS wishlist toggle; wishlist heart pre-fill).

The storefront PLP opts in with a guarded hook (`$CardCacheEnabled` / `$PrimeProductCardCache` /
`$CachedCard($Pos)`); with the module absent or disabled it falls back to the normal
`<% include … ProductCard %>`, so it is backward-compatible.

## Requirements

- `silvershop/core ^6`, `silverstripe/framework ^6`, PHP 8.3+.
- `silvershop/stock` is **optional** — card-cache serves correct cards with or without it (every stock /
  price read is `hasMethod`-guarded). **But the query-free listing — the point of the module — needs the
  per-page stock prefetch** (`ProductCategoryStockExtension::primeStockCache` / `primeVariationsCache`).
  That prefetch is **not in `silvershop/stock` 6.0.0**; it needs a release that includes it (6.1.0+,
  currently on the `perf/reduce-stock-queries` branch). On earlier stock the module is still correct, but
  the per-tile `canPurchase()` in the fingerprint re-introduces a stock query per tile — so the listing
  isn't actually query-free until that stock release lands.

## Per-visitor correctness (how each volatile bit is handled)

The card shows things that differ per product, per request, or per visitor. Each is kept correct so a
shared cached card never leaks or goes stale:

- **Stock / cart / price / discount** — folded into the cache hash (`volatileFingerprint()`:
  `canPurchase` + `sellingPrice()` incl. discounts + `StorefrontStockBadge()`). A change re-renders
  just that tile. On a listing the stock inputs are batch-prefetched **when `silvershop/stock` carries the
  per-page prefetch** (see [Requirements](#requirements)) — otherwise the fingerprint re-introduces a
  per-tile stock query.
- **Wishlist CSRF token** — the stored card is baked session-neutral (no `$WishlistSecurityField`,
  `CardCacheBaking=true`). The PLP emits one live, per-request page-level `SecurityID`
  (`$CardCacheSecurityField`); `wishlist.js` falls back to it (same global `SecurityToken` the wishlist
  controller checks). *No-JS caveat:* with the token only page-level, a no-JS wishlist POST has no
  in-form token — the JS path works for everyone; the no-JS fallback for that one control does not.
- **Wishlist / compare pressed state** — baked `aria-pressed="false"`, then hydrated per visitor on load:
  compare.js reconciles from the session compare-tray, and the storefront `wishlist.js` (`hydrateWishlist`)
  fetches the viewer's wishlist IDs from `wishlist/state` (`WishlistController::state`, member list or guest
  session) and fills the matching hearts + any `[data-wishlist-count]` on load (one request, only when a
  heart or badge is on the page).
  *No-JS caveat remains:* the heart form still has no in-form token, so a no-JS wishlist toggle won't
  submit (JS works for all); a server-side per-request token splice would restore it.
- **Member-group pricing** — `sellingPrice()` is group-specific (silvershop/discounts
  `SpecificPrice.GroupID`), so the cache is keyed per audience (`activeVariant()`): anonymous share
  `default` (what the warm task fills); each logged-in member's groups get their own lazily-warmed
  rows. No cross-member price leak.
- **Related-object edits** the fingerprint can't see (brand rename, new review, colour swatch) — bounded
  by a `WarmProductCardsTask` re-run (hourly cron/queuedjob) or a `cache_version` bump.
- **Position** — the first-few-tiles eager-image hint (`Eager=$Pos`) isn't baked per position (minor;
  affects only the LCP hint on the first 3 tiles).

## Status

Working, with per-visitor correctness handled (stock/price fingerprint, session-neutral bake +
page-level CSRF, per-group variant) — safe to enable on a multi-user storefront modulo the two caveats
above. Measured on a 1526-product bed: a 96-card page 1074→~327 queries, and /shop (12 cards) ~380→~209
with TTFB ~0.4s. Author: Remy Vaartjes. BSD-3-Clause.
