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
                'Cache variant key to build',
                ProductCardCacheExtension::DEFAULT_VARIANT
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
        $variant = (string) $input->getOption('variant');
        $limit = max(0, (int) $input->getOption('limit'));

        Versioned::set_stage(Versioned::LIVE);
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
}
