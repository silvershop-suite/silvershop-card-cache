<?php

declare(strict_types=1);

namespace SilverShop\CardCache\Tests;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;

/**
 * A project widening the key, e.g. by currency.
 */
class CurrencyVariantExtension extends Extension implements TestOnly
{
    protected function updateProductCardVariant(string &$variant, string $locale): void
    {
        $variant .= '|cur:EUR';
    }
}
