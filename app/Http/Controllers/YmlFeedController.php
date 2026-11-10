<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductLine;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use XMLWriter;

/**
 * Фид товаров в формате YML для Яндекса: блок «Товары» в поиске по запросам
 * вроде «пенка для маникюра» показывает товары магазинов рядом с
 * маркетплейсами. Подключается в Яндекс Вебмастере («Товары и цены») или
 * в Яндекс Бизнесе. Цены и наличие берутся с сайта, файл обновляется сам.
 *
 * Одно предложение — один вариант товара (объём, аромат); варианты одного
 * товара связаны group_id.
 */
class YmlFeedController extends Controller
{
    public function __invoke(): Response
    {
        $xml = Cache::remember('feed:yml', now()->addMinutes(10), fn (): string => self::build());

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public static function build(): string
    {
        $root = rtrim((string) config('app.url'), '/');
        $products = Product::active()->with(['variants' => fn ($q) => $q->where('is_active', true)->with('quota'), 'images', 'line'])
            ->orderBy('sort')->get()->filter(fn (Product $product): bool => $product->variants->isNotEmpty());
        $lines = ProductLine::query()->orderBy('sort')->get(['id', 'title']);

        $w = new XMLWriter;
        $w->openMemory();
        $w->setIndent(true);
        $w->startDocument('1.0', 'UTF-8');
        $w->startElement('yml_catalog');
        $w->writeAttribute('date', now()->timezone('Europe/Moscow')->format('Y-m-d\TH:i:sP'));
        $w->startElement('shop');
        $w->writeElement('name', 'LIVSI');
        $w->writeElement('company', (string) (SellerProfile::current()?->legal_name ?: 'LIVSI'));
        $w->writeElement('url', $root.'/');
        $w->startElement('currencies');
        $w->startElement('currency');
        $w->writeAttribute('id', 'RUR');
        $w->writeAttribute('rate', '1');
        $w->endElement();
        $w->endElement();

        $w->startElement('categories');
        $w->startElement('category');
        $w->writeAttribute('id', '1');
        $w->text('Косметика LIVSI');
        $w->endElement();
        foreach ($lines as $line) {
            $w->startElement('category');
            $w->writeAttribute('id', (string) (1000 + $line->id));
            $w->writeAttribute('parentId', '1');
            $w->text((string) $line->title);
            $w->endElement();
        }
        $w->endElement();

        $w->startElement('offers');
        foreach ($products as $product) {
            $pictures = $product->images->sortByDesc('is_primary')->unique('path')->take(10)
                ->map(fn ($image): string => self::absolute($root, $image->path))->values();
            foreach ($product->variants as $variant) {
                self::offer($w, $root, $product, $variant, $pictures->all());
            }
        }
        $w->endElement();

        $w->endElement();
        $w->endElement();
        $w->endDocument();

        return $w->outputMemory();
    }

    /** @param list<string> $pictures */
    private static function offer(XMLWriter $w, string $root, Product $product, ProductVariant $variant, array $pictures): void
    {
        $w->startElement('offer');
        $w->writeAttribute('id', (string) $variant->id);
        $w->writeAttribute('group_id', (string) $product->id);
        $w->writeAttribute('available', $variant->available() > 0 ? 'true' : 'false');
        $label = $variant->storefrontLabel();
        $name = trim($product->title.(stripos($product->title, 'livsi') === false ? ' LIVSI' : '').($label !== '' ? ', '.$label : ''));
        $w->writeElement('name', $name);
        $w->writeElement('vendor', 'LIVSI');
        if (filled($variant->sku)) {
            $w->writeElement('vendorCode', (string) $variant->sku);
        }
        $w->writeElement('url', route('catalog.show', $product));
        $w->writeElement('price', self::rub((int) $variant->price));
        if ((int) $variant->compare_at_price > (int) $variant->price) {
            $w->writeElement('oldprice', self::rub((int) $variant->compare_at_price));
        }
        $w->writeElement('currencyId', 'RUR');
        $w->writeElement('categoryId', (string) ($product->product_line_id ? 1000 + $product->product_line_id : 1));
        foreach ($pictures as $picture) {
            $w->writeElement('picture', $picture);
        }
        $w->writeElement('delivery', 'true');
        $description = trim(strip_tags((string) ($product->short_description ?: $product->effect ?: $product->title)));
        $w->startElement('description');
        $w->writeCdata(mb_substr($description, 0, 3000));
        $w->endElement();
        if (filled($variant->barcode)) {
            $w->writeElement('barcode', (string) $variant->barcode);
        }
        if ((int) $variant->volume_ml > 0) {
            $w->startElement('param');
            $w->writeAttribute('name', 'Объём');
            $w->writeAttribute('unit', 'мл');
            $w->text((string) $variant->volume_ml);
            $w->endElement();
        }
        if ((int) $variant->weight_g > 0) {
            $w->writeElement('weight', number_format($variant->weight_g / 1000, 3, '.', ''));
        }
        $w->endElement();
    }

    private static function rub(int $kopecks): string
    {
        return $kopecks % 100 === 0 ? (string) intdiv($kopecks, 100) : number_format($kopecks / 100, 2, '.', '');
    }

    private static function absolute(string $root, string $path): string
    {
        return preg_match('#^https?://#', $path) ? $path : $root.'/'.ltrim($path, '/');
    }
}
