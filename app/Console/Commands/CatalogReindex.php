<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;

class CatalogReindex extends Command
{
    protected $signature = 'catalog:reindex';

    protected $description = 'Пересобрать поисковый текст у всех товаров';

    public function handle(): int
    {
        $count = 0;

        Product::query()->chunkById(200, function ($products) use (&$count) {
            foreach ($products as $product) {
                $product->rebuildSearchText();
                $count++;
            }
        });

        $this->info("Переиндексировано товаров: {$count}");

        return self::SUCCESS;
    }
}