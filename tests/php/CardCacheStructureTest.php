<?php

declare(strict_types=1);

namespace SilverShop\CardCache\Tests;

use SilverShop\CardCache\CachedProductCard;
use SilverShop\CardCache\ProductCardCacheControllerExtension;
use SilverShop\CardCache\ProductCardCacheExtension;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Extension;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;

/**
 * Structural smoke tests: the storage model is a real DataObject with the expected columns and a
 * resolvable table, and the behaviour hooks are SilverStripe extensions. These read config/schema only
 * (no fixtures), so they stay fast and give CI something green to assert while the behavioural suite
 * (fingerprint → re-render) is fleshed out.
 */
class CardCacheStructureTest extends SapphireTest
{
    public function testCachedCardIsADataObjectWithATable(): void
    {
        $this->assertTrue(
            is_a(CachedProductCard::class, DataObject::class, true),
            'CachedProductCard should be a DataObject'
        );
        $this->assertNotEmpty(
            DataObject::getSchema()->tableName(CachedProductCard::class),
            'CachedProductCard should resolve to a database table'
        );
    }

    public function testCachedCardHasItsStorageColumns(): void
    {
        /** @var array<string, string> $db */
        $db = Config::inst()->get(CachedProductCard::class, 'db');

        foreach (['ProductID', 'Variant', 'Hash', 'HTML', 'LastBuilt'] as $field) {
            $this->assertArrayHasKey($field, $db, "CachedProductCard should declare the {$field} column");
        }
    }

    public function testBehaviourHooksAreExtensions(): void
    {
        $this->assertTrue(
            is_a(ProductCardCacheExtension::class, Extension::class, true),
            'ProductCardCacheExtension should be an Extension'
        );
        $this->assertTrue(
            is_a(ProductCardCacheControllerExtension::class, Extension::class, true),
            'ProductCardCacheControllerExtension should be an Extension'
        );
    }
}
