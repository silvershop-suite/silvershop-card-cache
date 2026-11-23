<?php

declare(strict_types=1);

namespace SilverShop\CardCache\Dev;

use SilverShop\CardCache\CachedProductCard;
use SilverShop\CardCache\ProductCardCacheExtension;
use SilverShop\Page\Product;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\i18n\i18n;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Proactively (push) warm the materialised product-card cache: render every published product's
 * card once and upsert it into {@see CachedProductCard}, so the first listing visitor already hits a
 * warm cache instead of paying the cold render. Re-run any time to refresh; `--purge` empties the
 * table instead.
 *
 * Cards are cached per locale, so warm every language the shop serves: `--locale=nl_NL,en_US`
 * (default: the current locale). With Fluent installed the content locale is switched too.
 *
 * Addressable at /dev/tasks/SilverShop-CardCache-Dev-WarmProductCardsTask (e.g. ?limit=200, ?purge=1).
 */
class WarmProductCardsTask extends BuildTask
{
    protected string $title = 'Card cache: warm (pre-render) product cards';

    protected static string $description =
        'Render every published product card once into the SilverShop_CachedProductCard table (push warming). '
        . '--purge empties the table; --limit caps how many are built.';

    public function getOptions(): array
    {
        return [
            new InputOption('limit', null, InputOption::VALUE_OPTIONAL, 'Max products to warm (0 = all)', '0'),
            new InputOption(
                'variant',
                null,
                InputOption::VALUE_OPTIONAL,
                'Audience to build (the anonymous default, or "g:<group ids>")',
                ProductCardCacheExtension::DEFAULT_VARIANT
            ),
            new InputOption(
                'locale',
                null,
                InputOption::VALUE_OPTIONAL,
                'Comma-separated locales to build (default: the current locale)',
                ''
            ),
            new InputOption('purge', null, InputOption::VALUE_NONE, 'Empty the card-cache table, then stop'),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        if ($input->getOption('purge')) {
            $purged = 0;
            foreach (CachedProductCard::get() as $row) {
                $row->delete();
                $purged++;
            }
            $output->writeln("Purged {$purged} cached cards.");
            return Command::SUCCESS;
        }

        // A card renders $Link / Image / module bits, so give it a request + session context.
        $request = new HTTPRequest('GET', '/');
        $request->setSession(new Session([]));
        Injector::inst()->registerService($request, HTTPRequest::class);
        $pushed = Controller::create();
        $pushed->setRequest($request);
        $pushed->pushCurrent();

        try {
            return $this->warm($input, $output);
        } finally {
            $pushed->popCurrent();
        }
    }

    private function warm(InputInterface $input, PolyOutput $output): int
    {
        $audience = (string) $input->getOption('variant');
        $limit = max(0, (int) $input->getOption('limit'));
        $locales = static::parseLocales((string) $input->getOption('locale'));

        Versioned::set_stage(Versioned::LIVE);

        foreach ($locales as $locale) {
            $result = static::inLocale($locale, fn (): int => $this->warmLocale($audience, $locale, $limit, $output));
            if ($result !== Command::SUCCESS) {
                return $result;
            }
        }

        return Command::SUCCESS;
    }

    private function warmLocale(string $audience, string $locale, int $limit, PolyOutput $output): int
    {
        $variant = ProductCardCacheExtension::variantFor($audience, $locale);
        $products = Product::get();
        if ($limit > 0) {
            $products = $products->limit($limit);
        }

        $start = microtime(true);
        $built = 0;
        foreach ($products as $product) {
            if (!$product->hasMethod('renderCard')) {
                $output->writeln('ProductCardCacheExtension is not applied to Product — nothing to do.');
                return Command::FAILURE;
            }
            $html = (string) $product->renderCard(null);
            $product->storeCard($variant, $product->cardHash($variant), $html);
            $built++;
        }

        $elapsed = round(microtime(true) - $start, 2);
        $total = CachedProductCard::get()->filter('Variant', $variant)->count();
        $output->writeln(sprintf(
            "Warmed %d cards (variant '%s') in %ss; table now holds %d rows for this variant.",
            $built,
            $variant,
            $elapsed,
            $total
        ));

        return Command::SUCCESS;
    }

    /**
     * The --locale option as a list; empty means the current locale.
     *
     * @return list<string>
     */
    public static function parseLocales(string $option): array
    {
        $locales = array_values(array_unique(array_filter(array_map('trim', explode(',', $option)))));

        return $locales ?: [(string) i18n::get_locale()];
    }

    /**
     * Run $callback with $locale as the translation locale and, when Fluent is installed, as the content
     * locale, so the stored card matches what a visitor in that locale is served.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function inLocale(string $locale, callable $callback): mixed
    {
        return i18n::with_locale($locale, function () use ($locale, $callback) {
            $fluentState = 'TractorCow\\Fluent\\State\\FluentState';
            if (class_exists($fluentState)) {
                return $fluentState::singleton()->withState(function ($state) use ($locale, $callback) {
                    $state->setLocale($locale);
                    return $callback();
                });
            }
            return $callback();
        });
    }
}
