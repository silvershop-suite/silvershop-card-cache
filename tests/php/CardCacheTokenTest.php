<?php

declare(strict_types=1);

namespace SilverShop\CardCache\Tests;

use SilverShop\CardCache\CachedProductCard;
use SilverShop\CardCache\ProductCardCacheExtension;
use SilverShop\Page\Product;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\SecurityToken;
use SilverStripe\View\SSViewer;

/**
 * A stored card is shared by all visitors, the security token in its add-to-cart link is not: everybody must
 * get their own, or the cart refuses the form.
 */
class CardCacheTokenTest extends SapphireTest
{
    protected $usesDatabase = true;

    private Session $session;

    /** @var array<int, string> */
    private array $themes = [];

    protected function setUp(): void
    {
        parent::setUp();
        SecurityToken::enable();
        $this->logOut();
        $this->themes = SSViewer::get_themes();
        SSViewer::set_themes(['silvershop-suite/card-cache:/tests/php/theme', SSViewer::DEFAULT_THEME]);
        $this->useTemplate('CardCacheTest/Card');
        $this->newVisitor();
    }

    protected function tearDown(): void
    {
        SSViewer::set_themes($this->themes);
        SecurityToken::enable();
        ProductCardCacheExtension::resetRequestCache();
        parent::tearDown();
    }

    public function testEveryVisitorGetsTheirOwnToken(): void
    {
        $first = $this->newVisitor();
        $cardOfFirst = $this->card();
        $second = $this->newVisitor();
        $cardOfSecond = $this->card();

        $this->assertNotSame($first, $second);
        $this->assertSame(2, substr_count($cardOfFirst, $first), 'the visitor who fills the cache');
        $this->assertSame(2, substr_count($cardOfSecond, $second), 'the visitor who gets the stored card');
        $this->assertStringNotContainsString($first, $cardOfSecond);
        $this->assertStringNotContainsString(ProductCardCacheExtension::TOKEN_PLACEHOLDER, $cardOfFirst);
        $this->assertStringNotContainsString(ProductCardCacheExtension::TOKEN_PLACEHOLDER, $cardOfSecond);
        $this->assertSame(1, CachedProductCard::get()->count(), 'one card, shared');
    }

    public function testTheSameCardTwiceInOneRequest(): void
    {
        $token = $this->newVisitor();

        $this->assertSame($this->card(), $this->card());
        $this->assertSame(2, substr_count($this->card(), $token));
    }

    public function testTheStoredCardHoldsNoToken(): void
    {
        $token = $this->newVisitor();
        $this->card();

        $stored = (string) CachedProductCard::get()->first()->HTML;

        $this->assertStringNotContainsString($token, $stored);
        $this->assertSame(2, substr_count($stored, ProductCardCacheExtension::TOKEN_PLACEHOLDER));
    }

    public function testAWarmedCardGetsTheTokenOfTheVisitor(): void
    {
        $product = $this->product();
        $variant = ProductCardCacheExtension::activeVariant();
        $warmer = $this->newVisitor();
        $product->storeCard($variant, $product->cardHash($variant), (string) $product->renderCard());
        $visitor = $this->newVisitor();
        // A hit: the template may not be rendered again.
        $this->useTemplate('CardCacheTest/CardWithoutToken');

        $card = $this->card();

        $this->assertSame(2, substr_count($card, $visitor));
        $this->assertStringNotContainsString($warmer, $card);
    }

    public function testThePlaceholderInContentGetsNoToken(): void
    {
        $title = 'A' . ProductCardCacheExtension::TOKEN_PLACEHOLDER . 'B';
        $first = $this->newVisitor();
        $this->card($title);
        $this->assertSame(2, substr_count($this->card($title), $first), 'again in the same request');
        $second = $this->newVisitor();

        $card = $this->card($title);

        $this->assertSame(2, substr_count($card, $second), 'only where the token belongs');
        $this->assertStringContainsString('<h3>AB</h3>', $card);
    }

    public function testAStoredCardWithoutTokenStartsNoSession(): void
    {
        $this->useTemplate('CardCacheTest/CardWithoutToken');
        $this->card();
        $this->newVisitor(false);

        $this->assertStringContainsString('<h3>Card</h3>', $this->card());
        $this->assertEmpty($this->session->getAll());
    }

    public function testARowOfAnOlderVersionIsRebuilt(): void
    {
        $product = $this->product();
        $variant = ProductCardCacheExtension::activeVariant();
        $hashOf110 = md5(implode('|', [
            $product->ID,
            (string) $product->LastEdited,
            $variant,
            (int) Config::inst()->get(ProductCardCacheExtension::class, 'cache_version'),
            $product->volatileFingerprint(),
        ]));
        $product->storeCard($variant, $hashOf110, '<li>stored by 1.1.0</li>');

        $this->assertStringNotContainsString('stored by 1.1.0', $this->card());
        $this->assertStringNotContainsString('stored by 1.1.0', (string) CachedProductCard::get()->first()->HTML);
    }

    public function testWithTokensSwitchedOff(): void
    {
        SecurityToken::disable();
        $first = $this->card();
        ProductCardCacheExtension::resetRequestCache();

        $this->assertSame($first, $this->card());
        $this->assertStringContainsString('name="SecurityID" value=""', $first);
    }

    private function useTemplate(string $template): void
    {
        Config::modify()->set(ProductCardCacheExtension::class, 'card_template', $template);
    }

    /**
     * A new request of a new visitor; returns their token.
     */
    private function newVisitor(bool $withToken = true): string
    {
        ProductCardCacheExtension::resetRequestCache();
        $this->session = new Session([]);
        $request = new HTTPRequest('GET', '/');
        $request->setSession($this->session);
        Injector::inst()->registerService($request, HTTPRequest::class);

        return $withToken ? (string) SecurityToken::inst()->getValue() : '';
    }

    private function product(string $title = 'Card'): Product
    {
        $product = Product::create();
        $product->ID = 7;
        $product->Title = $title;

        return $product;
    }

    private function card(string $title = 'Card'): string
    {
        return (string) $this->product($title)->getCachedCard()->getValue();
    }
}
