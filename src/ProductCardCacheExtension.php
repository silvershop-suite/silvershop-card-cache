<?php

declare(strict_types=1);

namespace SilverShop\CardCache;

use SilverShop\Page\Product;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Extension;
use SilverStripe\i18n\i18n;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Security\Security;

/**
 * Applied to {@see \SilverShop\Page\Product}. Serves a product's card from {@see CachedProductCard}
 * (the materialised-HTML table) and keeps it fresh.
 *
 * Read path (one DB round-trip per listing page): the controller calls {@see primeProductCards()}
 * once with the page's product IDs — a single `WHERE ProductID IN (…)` that fills a request-static
 * map. Each tile then calls {@see getCachedCard()}, which is a map lookup; on a hit (matching hash,
 * within TTL) it returns the stored markup, on a miss it renders the real card once and — when
 * `warm_on_miss` is set — stores it.
 *
 * Freshness: the stored card's hash fingerprints not just the product's own edit time but its
 * **volatile display state** — purchasability, the low-stock badge, and the selling price
 * ({@see volatileFingerprint()}). So anything that changes what the card shows without editing the
 * product — stock movements, cart reservations, a discount added / removed / time-activated — makes
 * the hash mismatch and that one tile re-renders live and re-stores, while every unchanged tile stays
 * a cache hit. On a listing the stock inputs are already batch-prefetched for the page (silvershop/
 * stock's ProductCategoryStockExtension), so computing the fingerprint for the whole page costs no
 * extra per-tile query. Publishing / unpublishing / deleting the product still drops its rows, and an
 * optional `cache_ttl` bounds any staleness the fingerprint cannot observe.
 *
 * Remaining seam: the card also carries per-session bits (wishlist CSRF token, wishlist/compare
 * pressed state) that are NOT in the fingerprint — a shared cached card shows one visitor's token/
 * state to the next. That is why the module is off by default; carve those out (or restrict to
 * anonymous) before enabling on a live multi-user storefront. See the README.
 *
 * @property \SilverShop\Page\Product $owner
 */
class ProductCardCacheExtension extends Extension
{
    private static bool $enabled = false;

    /** Render-and-store on a cache miss during a normal request (lazy/pull warming). */
    private static bool $warm_on_miss = true;

    /** Bump to invalidate every stored card at once (e.g. after a card-template change). */
    private static int $cache_version = 1;

    /** Seconds a stored card may be served before it is treated as stale regardless of the hash
     *  (a backstop for drift the fingerprint cannot see). 0 = no time limit. */
    private static int $cache_ttl = 0;

    private static string $card_template = 'SilverShop/Storefront/Includes/ProductCard';

    /** Sentinel for the default/anonymous variant. Must be non-empty: a Varchar nullifies '' to NULL,
     *  which both breaks equality lookups and defeats the (ProductID, Variant) unique index. */
    public const DEFAULT_VARIANT = 'default';

    /**
     * Runtime (not config) caches: protected static so SilverStripe's config system — which manages
     * `private static` — leaves them alone and they stay plain per-request statics.
     *
     * @var array<string, array{html: string, hash: string, built: int}> request-static map, key "variant\0productID"
     */
    protected static array $map = [];

    /** @var array<string, bool> variants whose page rows have been primed this request */
    protected static array $primed = [];

    /** Request-memoised audience part of the variant (one Groups() query at most per request). */
    protected static ?string $audienceResolved = null;

    /** @var array<string, string> request-memoised active variant per locale */
    protected static array $variantResolved = [];

    public function CardCacheEnabled(): bool
    {
        return (bool) Config::inst()->get(static::class, 'enabled');
    }

    /**
     * The active cache variant — isolates cards whose content differs per audience or language, so one
     * visitor's card is never served to another. It is "<audience>@<locale>":
     * - audience: anonymous visitors share {@see DEFAULT_VARIANT}; a logged-in member is keyed by their
     *   group IDs, because group-based pricing (silvershop/discounts SpecificPrice.GroupID) makes
     *   `sellingPrice()` member-specific — no cross-member price leak;
     * - locale: the card's text is translated (`<%t %>`) and, with Fluent, its content is localised, so
     *   each language gets its own rows (Fluent sets the i18n locale per request).
     * Projects can widen the key further (currency, shipping country, …) with the
     * `updateProductCardVariant(string &$variant, string $locale)` extension hook on Product.
     * Memoised per request and locale, so it costs at most one Groups() query.
     */
    public static function activeVariant(): string
    {
        $locale = (string) i18n::get_locale();
        if (isset(self::$variantResolved[$locale])) {
            return self::$variantResolved[$locale];
        }

        return self::$variantResolved[$locale] = static::variantFor(static::audienceVariant(), $locale);
    }

    /**
     * The variant key for an audience ({@see DEFAULT_VARIANT} or "g:<group ids>") in a locale, with the
     * project's updateProductCardVariant hook applied. Used by the read path and by the warm task, so a
     * warmed row is found by the request that needs it.
     */
    public static function variantFor(string $audience, string $locale): string
    {
        $variant = $audience . '@' . $locale;
        Product::singleton()->extend('updateProductCardVariant', $variant, $locale);

        return $variant;
    }

    /**
     * The audience part of the variant: {@see DEFAULT_VARIANT} for anonymous visitors (and members
     * without groups), else the member's sorted group IDs.
     */
    public static function audienceVariant(): string
    {
        if (self::$audienceResolved !== null) {
            return self::$audienceResolved;
        }

        $audience = self::DEFAULT_VARIANT;
        $member = Security::getCurrentUser();
        if ($member) {
            $groupIds = $member->Groups()->sort('ID')->column('ID');
            if ($groupIds) {
                $audience = 'g:' . implode(',', array_map('intval', $groupIds));
            }
        }

        return self::$audienceResolved = $audience;
    }

    /**
     * Forget the request-memoised variant (and primed rows). For long-running processes and tests that
     * change the logged-in member within one request.
     */
    public static function resetRequestCache(): void
    {
        self::$audienceResolved = null;
        self::$variantResolved = [];
        self::$map = [];
        self::$primed = [];
    }

    /**
     * Load every stored row for these product IDs (current variant) into the request-static map
     * with ONE query. Call once per listing page before the tile loop.
     *
     * @param array<int|string> $productIDs
     */
    public static function primeProductCards(array $productIDs, ?string $variant = null): void
    {
        $variant = $variant ?? static::activeVariant();
        self::$primed[$variant] = true;

        $ids = array_values(array_unique(array_filter(array_map('intval', $productIDs))));
        if (!$ids) {
            return;
        }

        $rows = CachedProductCard::get()->filter(['ProductID' => $ids, 'Variant' => $variant]);
        foreach ($rows as $row) {
            self::$map[$variant . "\0" . (int) $row->ProductID] = [
                'html' => (string) $row->HTML,
                'hash' => (string) $row->Hash,
                'built' => (int) strtotime((string) $row->LastBuilt),
            ];
        }
    }

    /**
     * Return this product's card markup for position $pos in the grid (1-based; drives the
     * eager-image hint on a live render). Hit → stored HTML; miss/stale → render (and store when
     * warm_on_miss).
     */
    public function getCachedCard(?int $pos = null): DBHTMLText
    {
        $variant = static::activeVariant();

        if (empty(self::$primed[$variant])) {
            // Fallback priming if the template did not prime explicitly (keeps the loop a one-liner).
            self::primeProductCards($this->pageProductIDs(), $variant);
        }

        $key = $variant . "\0" . (int) $this->owner->ID;
        $expected = $this->cardHash($variant);
        $hit = self::$map[$key] ?? null;
        $ttl = (int) Config::inst()->get(static::class, 'cache_ttl');

        $fresh = $hit !== null
            && ($hit['hash'] ?? null) === $expected
            && ($ttl <= 0 || (time() - (int) ($hit['built'] ?? 0)) <= $ttl);

        if ($fresh) {
            return DBHTMLText::create()->setValue($hit['html']);
        }

        $html = (string) $this->renderCard($pos);
        if ((bool) Config::inst()->get(static::class, 'warm_on_miss')) {
            $this->storeCard($variant, $expected, $html);
            self::$map[$key] = ['html' => $html, 'hash' => $expected, 'built' => time()];
        }

        return DBHTMLText::create()->setValue($html);
    }

    /**
     * Render the real card template for this product. Used on a miss and by the warm task.
     */
    public function renderCard(?int $pos = null): DBHTMLText
    {
        $template = (string) Config::inst()->get(static::class, 'card_template');

        return $this->owner->renderWith([$template], [
            'QuickView' => true,
            'Eager' => $pos,
            // Render the shared, stored copy session-neutral: no wishlist CSRF token, wishlist/compare
            // pressed-state false. Per-session correctness is layered back at serve time — the page-level
            // $CardCacheSecurityField (controller) for CSRF, and wishlist.js / compare.js for pressed state.
            'CardCacheBaking' => true,
        ]);
    }

    /**
     * Fingerprint of everything the fragment is built from that we can cheaply observe: the product's
     * own edit time, the cache version, the variant, and the volatile display state (stock / price).
     * A change in any of these makes the next read a miss, so the card re-renders live.
     */
    public function cardHash(string $variant): string
    {
        return md5(implode('|', [
            (int) $this->owner->ID,
            (string) $this->owner->LastEdited,
            $variant,
            (int) Config::inst()->get(static::class, 'cache_version'),
            $this->volatileFingerprint(),
        ]));
    }

    /**
     * The stock/price state the card displays, as a short string. Reads purchasability, the low-stock
     * badge and the selling price; on a listing these come from the per-page batch prefetch, so this
     * adds no per-tile query. Projects can mix in more (a catalogue-discount label, a currency) via the
     * updateProductCardFingerprint extension hook. Everything is defensively guarded so a product
     * without the stock module (or a thrown accessor) never breaks card serving.
     */
    public function volatileFingerprint(): string
    {
        $owner = $this->owner;
        $parts = [];

        try {
            $parts[] = $owner->hasMethod('canPurchase')
                ? ((bool) $owner->canPurchase() ? '1' : '0')
                : '-';
        } catch (\Throwable $e) {
            $parts[] = '-';
        }

        try {
            $parts[] = $owner->hasMethod('sellingPrice')
                ? (string) $owner->sellingPrice()
                : (string) $owner->BasePrice;
        } catch (\Throwable $e) {
            $parts[] = '-';
        }

        // The low-stock badge ("Only N left" / "Last one"). Method is StorefrontStockBadge() (no get
        // prefix); it reads the already-primed stock caches, so no per-tile query on a listing.
        if ($owner->hasMethod('StorefrontStockBadge')) {
            try {
                $badge = $owner->StorefrontStockBadge();
                $parts[] = $badge ? (string) $badge->Label : '';
            } catch (\Throwable $e) {
                $parts[] = '';
            }
        }

        $owner->extend('updateProductCardFingerprint', $parts);

        return implode('|', $parts);
    }

    public function storeCard(string $variant, string $hash, string $html): void
    {
        $existing = CachedProductCard::get()->filter([
            'ProductID' => (int) $this->owner->ID,
            'Variant' => $variant,
        ])->first();

        $row = $existing ?: CachedProductCard::create();
        $row->ProductID = (int) $this->owner->ID;
        $row->Variant = $variant;
        $row->Hash = $hash;
        $row->HTML = $html;
        $row->LastBuilt = DBDatetime::now()->getValue();

        try {
            $row->write();
        } catch (\Throwable $e) {
            // A concurrent cold request may have inserted the same (ProductID, Variant) first; the
            // unique index then rejects this write. Harmless — the row exists either way.
        }
    }

    /**
     * Drop every stored card for this product (all variants).
     */
    public function invalidateCards(): void
    {
        foreach (CachedProductCard::get()->filter('ProductID', (int) $this->owner->ID) as $row) {
            $row->delete();
        }
    }

    public function onAfterPublish(): void
    {
        $this->invalidateCards();
    }

    public function onAfterUnpublish(): void
    {
        $this->invalidateCards();
    }

    public function onBeforeDelete(): void
    {
        $this->invalidateCards();
    }

    /**
     * IDs of the products on the current listing page, for fallback priming.
     *
     * @return array<int>
     */
    protected function pageProductIDs(): array
    {
        $controller = Controller::curr();
        if (!$controller || !$controller->hasMethod('Products')) {
            return [(int) $this->owner->ID];
        }
        $list = $controller->Products();
        if (!$list) {
            return [(int) $this->owner->ID];
        }
        $ids = [];
        foreach ($list as $product) {
            $ids[] = (int) $product->ID;
        }
        return $ids ?: [(int) $this->owner->ID];
    }
}
