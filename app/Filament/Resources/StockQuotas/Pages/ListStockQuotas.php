<?php

namespace App\Filament\Resources\StockQuotas\Pages;

use App\Filament\Resources\StockQuotas\StockQuotaResource;
use App\Models\Product;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\View\View;

class ListStockQuotas extends ListRecords
{
    protected static string $resource = StockQuotaResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getSubheading(): ?string
    {
        return 'Все варианты товаров. Новые варианты появляются сразу с нулевым остатком — нажмите «Пополнить» или «Изменить».';
    }

    public function getFooter(): ?View
    {
        $query = Product::query()->doesntHave('variants')->with('images')->orderBy('title');
        $active = $this->getTableFilterState('product_active')['value'] ?? null;
        if ($active !== null && $active !== '') {
            $query->where('is_active', (bool) $active);
        }
        $search = mb_strtolower(trim($this->getTableSearch() ?? ''));
        $products = $query->get()->filter(fn (Product $product): bool => $search === '' || str_contains(mb_strtolower($product->title.' '.$product->sku), $search));

        return view('filament.pages.stock-quotas-without-variants', ['products' => $products]);
    }
}
