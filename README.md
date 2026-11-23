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
  (`?locale=nl_NL,en_US`, `?limit=`, `?variant=`, `?purge=1`) — proactive push warming, per locale
  (default: the current locale). With Fluent installed it also switches the content locale.

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

## Upgrading from 1.0

1.1 adds the locale to the cache key, so rows stored by 1.0 (variant `default` / `g:…`) are no longer
read: cards re-render and re-store on first view. To clean up and pre-fill, run the warm task with
`--purge`, then once per locale (`--locale=…`).

## Requirements

- `silvershop/core ^6`, `silverstripe/framework ^6`, PHP 8.3+.
- `silvershop/stock` is **optional** — card-cache serves correct cards with or without it (every stock /
  price read is `hasMethod`-guarded). The module's speed win is **render + N+1 caching** and does **not**
  depend on stock internals: the fingerprint reads `canPurchase()` per tile, but on a normal (co-located)
  DB that's a cheap indexed lookup — negligible. A per-page stock *prefetch* can batch those reads, which
  only matters on a **remote / networked DB** (round-trip cost); on a local DB batching is actually
  slightly slower, so it is not needed or recommended here. In short: card-cache stands on its own.

## Per-visitor correctness (how each volatile bit is handled)

The card shows things that differ per product, per request, or per visitor. Each is kept correct so a
shared cached card never leaks or goes stale:

- **Stock / cart / price / discount** — folded into the cache hash (`volatileFingerprint()`:
  `canPurchase` + `sellingPrice()` incl. discounts + `StorefrontStockBadge()`). A change re-renders
  just that tile. The fingerprint reads `canPurchase()` per tile, a cheap indexed lookup on a normal
  co-located DB (see [Requirements](#requirements)).
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
- **Language** — the card's text is translated and, with Fluent, its content is localised, so the key
  also carries the locale: `<audience>@<locale>`, e.g. `default@nl_NL`, `g:2,5@en_US`. Each language
  gets its own rows; warm each one (`--locale=nl_NL,de_DE,…`) or let `warm_on_miss` fill them lazily.
- **Anything else a card varies on** (currency, shipping country, …) — widen the key with the
  `updateProductCardVariant(string &$variant, string $locale)` extension hook on `Product`. The warm
  task builds keys through the same hook, so warmed rows match what requests read.
- **Related-object edits** the fingerprint can't see (brand rename, new review, colour swatch) — bounded
  by a `WarmProductCardsTask` re-run (hourly cron/queuedjob) or a `cache_version` bump.
- **Position** — the first-few-tiles eager-image hint (`Eager=$Pos`) isn't baked per position (minor;
  affects only the LCP hint on the first 3 tiles).

## Status

Working, with per-visitor correctness handled (stock/price fingerprint, session-neutral bake +
page-level CSRF, per-group variant) — safe to enable on a multi-user storefront modulo the two caveats
above. Measured on a 1526-product bed: a 96-card page 1074→~327 queries, and /shop (12 cards) ~380→~209
with TTFB ~0.4s.

## License

BSD-3-Clause. See [LICENSE](LICENSE).

## Credits

Built and maintained by **Remy Vaartjes** (<remy@xd.nl>) — part of the **SilverShop-Suite**.
