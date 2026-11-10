<?php

namespace App\Http\Controllers;

use App\Seo\LegacyMatcher;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Адреса старого сайта livsi.shop (/magazin/…, /product-category/… и т. п.)
 * ещё лежат в выдаче Яндекса. Без переадресации они отдают 404, Яндекс их
 * выкидывает, а вес бренда пропадает. Постоянная переадресация (301): на тот
 * же товар, если его можно узнать по адресу, иначе — в каталог.
 *
 * Старые карты сайта (sitemap.3972363.xml.gz и т. п.) отвечают содержимым
 * новой карты с кодом 200: Яндекс считает ошибкой карту, которая отвечает
 * не 200, в том числе переадресацией, — а эта ошибка висела в Вебмастере.
 *
 * Срабатывает только для адресов, которых на новом сайте нет. Переадресации,
 * которые владелец задал руками («Битые ссылки»), проверяются раньше —
 * в App\Http\Middleware\ApplyRedirects.
 */
class LegacyRedirectController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $path = trim(urldecode($request->path()), '/');
        if (! in_array($request->getMethod(), ['GET', 'HEAD'], true) || $path === '') {
            throw new NotFoundHttpException;
        }

        if (preg_match(LegacyMatcher::OLD_SITEMAP, $path)) {
            $sitemap = app(SitemapController::class)();
            if (str_ends_with(strtolower($path), '.gz')) {
                return response(gzencode((string) $sitemap->getContent(), 9), 200, ['Content-Type' => 'application/x-gzip']);
            }

            return $sitemap;
        }

        // Тот же адрес товара, что и раньше, — только под другим началом.
        if ($product = LegacyMatcher::product($path)) {
            return redirect()->route('catalog.show', $product, 301);
        }

        if (! LegacyMatcher::isLegacy($path)) {
            throw new NotFoundHttpException;
        }

        if ($product = LegacyMatcher::similar($path)) {
            return redirect()->route('catalog.show', $product, 301);
        }

        return redirect()->route('catalog.index', [], 301);
    }
}
