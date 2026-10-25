<?php

declare(strict_types=1);

namespace SilverShop\CardCache;

use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Security\SecurityToken;

/**
 * Applied to {@see \SilverShop\Page\ProductCategoryController}. Gives the PLP template the gate
 * ({@see CardCacheEnabled()}) and the one-shot page priming ({@see PrimeProductCardCache()}) that
 * turns the whole grid's card lookups into a single `WHERE ProductID IN (…)` read.
 *
 * @property \SilverShop\Page\ProductCategoryController $owner
 */
class ProductCardCacheControllerExtension extends Extension
{
    public function CardCacheEnabled(): bool
    {
        return (bool) Config::inst()->get(ProductCardCacheExtension::class, 'enabled');
    }

    /**
     * Prime the card cache for the products on this page with one query. Returns '' so it can be
     * dropped into the template before the tile loop.
     */
    public function PrimeProductCardCache(): string
    {
        if (!$this->CardCacheEnabled()) {
            return '';
        }
        if (!$this->owner->hasMethod('Products')) {
            return '';
        }
        $list = $this->owner->Products();
        $ids = [];
        foreach ($list as $product) {
            $ids[] = (int) $product->ID;
        }
        ProductCardCacheExtension::primeProductCards($ids);

        return '';
    }

    /**
     * One live, per-request page-level CSRF field for the cached grid. The cached cards are baked
     * session-neutral (no wishlist token — see ProductCardCacheExtension::renderCard), so wishlist.js
     * falls back to this page-level `SecurityID` (same global {@see SecurityToken} the wishlist
     * controller checks). Rendered live each request — never cached — so it is always the current
     * visitor's token. Emit once on the listing, outside the tile loop.
     */
    public function CardCacheSecurityField(): DBHTMLText
    {
        $field = DBHTMLText::create();
        if (!$this->CardCacheEnabled()) {
            return $field;
        }
        $token = SecurityToken::inst();
        $field->setValue(sprintf(
            '<input type="hidden" name="%s" value="%s" data-sscc-sid />',
            Convert::raw2att($token->getName()),
            Convert::raw2att($token->getValue())
        ));

        return $field;
    }
}
