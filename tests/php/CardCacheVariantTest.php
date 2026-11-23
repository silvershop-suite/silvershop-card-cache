<?php

declare(strict_types=1);

namespace SilverShop\CardCache\Tests;

use SilverShop\CardCache\Dev\WarmProductCardsTask;
use SilverShop\CardCache\ProductCardCacheExtension;
use SilverShop\Page\Product;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\i18n\i18n;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;

/**
 * The cache key must keep apart cards that render differently: per audience (member-group pricing) and
 * per locale (translated text, localised content). Otherwise a card cached in one language is served to
 * visitors of another.
 */
class CardCacheVariantTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        ProductCardCacheExtension::resetRequestCache();
    }

    protected function tearDown(): void
    {
        ProductCardCacheExtension::resetRequestCache();
        parent::tearDown();
    }

    public function testAnonymousVariantIsKeyedOnTheLocale(): void
    {
        $this->logOut();

        $active = [ProductCardCacheExtension::class, 'activeVariant'];

        $this->assertSame('default@en_US', i18n::with_locale('en_US', $active));
        $this->assertSame('default@nl_NL', i18n::with_locale('nl_NL', $active));
    }

    public function testVariantIsMemoisedPerLocaleNotPerRequest(): void
    {
        $this->logOut();

        // A request (or warm run) that switches locale must not keep the first locale's key.
        foreach (['de_DE', 'fr_FR', 'de_DE', 'es_ES'] as $locale) {
            $this->assertSame(
                'default@' . $locale,
                i18n::with_locale($locale, [ProductCardCacheExtension::class, 'activeVariant'])
            );
        }
    }

    public function testMemberGroupsFormTheAudiencePart(): void
    {
        $group = Group::create(['Title' => 'Wholesale', 'Code' => 'wholesale']);
        $group->write();
        $member = Member::create(['Email' => 'cardcache-variant@example.com']);
        $member->write();
        $member->Groups()->add($group);
        $this->logInAs($member);
        ProductCardCacheExtension::resetRequestCache();

        $variant = i18n::with_locale('nl_NL', [ProductCardCacheExtension::class, 'activeVariant']);

        $this->assertSame('g:' . $group->ID . '@nl_NL', $variant);
    }

    public function testUpdateProductCardVariantHookWidensTheKey(): void
    {
        Product::add_extension(CurrencyVariantExtension::class);
        Injector::inst()->unregisterNamedObject(Product::class);

        try {
            $this->assertSame(
                'default@nl_NL|cur:EUR',
                ProductCardCacheExtension::variantFor(ProductCardCacheExtension::DEFAULT_VARIANT, 'nl_NL')
            );
        } finally {
            Product::remove_extension(CurrencyVariantExtension::class);
            Injector::inst()->unregisterNamedObject(Product::class);
        }

        $this->assertSame(
            'default@nl_NL',
            ProductCardCacheExtension::variantFor(ProductCardCacheExtension::DEFAULT_VARIANT, 'nl_NL')
        );
    }

    public function testWarmTaskBuildsTheKeyARequestInThatLocaleReads(): void
    {
        $this->logOut();

        foreach (['en_US', 'nl_NL'] as $locale) {
            $warmed = WarmProductCardsTask::inLocale(
                $locale,
                fn (): string => ProductCardCacheExtension::variantFor(
                    ProductCardCacheExtension::DEFAULT_VARIANT,
                    $locale
                )
            );
            $requested = i18n::with_locale($locale, [ProductCardCacheExtension::class, 'activeVariant']);

            $this->assertSame($requested, $warmed);
        }
    }

    public function testWarmTaskRendersInTheRequestedLocale(): void
    {
        $before = i18n::get_locale();

        $this->assertSame('de_DE', WarmProductCardsTask::inLocale('de_DE', fn (): string => i18n::get_locale()));
        $this->assertSame($before, i18n::get_locale(), 'the locale is restored afterwards');
    }

    public function testWarmTaskLocaleOption(): void
    {
        $this->assertSame(['nl_NL', 'en_US'], WarmProductCardsTask::parseLocales(' nl_NL, en_US,nl_NL,'));
        $this->assertSame([i18n::get_locale()], WarmProductCardsTask::parseLocales(''));
    }
}
