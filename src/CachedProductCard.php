<?php

declare(strict_types=1);

namespace SilverShop\CardCache;

use SilverStripe\ORM\DataObject;

/**
 * One row of materialised (pre-rendered) product-card HTML.
 *
 * Deliberately a dedicated table rather than a column on Product: it stays out of every
 * `Product::get()` hydration, can hold several rows per product (one per {@see $Variant} —
 * audience and locale, plus whatever else a project keys on), and is truncated or rebuilt
 * independently of the catalogue. A listing primes the whole page with one `WHERE ProductID IN (…)`
 * read and then emits the stored HTML, so there is no per-tile render and no per-tile query.
 *
 * {@see ProductCardCacheExtension} writes and reads these; {@see Dev\WarmProductCardsTask} bulk-builds them.
 */
class CachedProductCard extends DataObject
{
    private static string $table_name = 'SilverShop_CachedProductCard';

    private static array $db = [
        // Plain Int (not a has_one) so a stale/orphan row can never block a Product delete and the
        // lookup is a flat indexed scan. The owning Product is cleaned up via the extension hooks.
        'ProductID' => 'Int',
        // "<audience>@<locale>", e.g. 'default@nl_NL' (anonymous) or 'g:2,5@en_US' (member groups);
        // projects can widen it (currency, …) via the updateProductCardVariant hook.
        'Variant' => 'Varchar(100)',
        // Fingerprint of the inputs the fragment was built from; a mismatch on read = treat as a miss.
        'Hash' => 'Varchar(40)',
        // The rendered card markup, returned raw (HTMLText) to the template.
        'HTML' => 'HTMLText',
        'LastBuilt' => 'DBDatetime',
    ];

    private static array $indexes = [
        'ProductVariant' => [
            'type' => 'unique',
            'columns' => ['ProductID', 'Variant'],
        ],
    ];

    private static array $summary_fields = [
        'ProductID' => 'Product ID',
        'Variant' => 'Variant',
        'Hash' => 'Hash',
        'LastBuilt' => 'Built',
    ];

    private static string $default_sort = 'LastBuilt DESC';
}
