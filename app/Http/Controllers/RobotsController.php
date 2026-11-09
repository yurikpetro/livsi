<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * robots.txt с картой сайта по адресу из APP_URL. Служебные страницы —
 * корзина, оформление, кабинет, заказы — в поиске не нужны. Clean-param
 * (понимает Яндекс) склеивает ссылки с метками рекламы с обычными.
 */
class RobotsController extends Controller
{
    public const CLOSED = ['/admin', '/account', '/cart', '/checkout', '/favorites', '/login', '/logout',
        '/order/', '/auth/', '/delivery/', '/livewire', '/webhooks/', '/api/'];

    public function __invoke(): Response
    {
        return response(self::text(), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /** Текст robots.txt — и для этого адреса, и для файла, который пишет seo:robots. */
    public static function text(): string
    {
        $lines = ['User-agent: *'];
        foreach (self::CLOSED as $path) {
            $lines[] = 'Disallow: '.$path;
        }
        $lines[] = 'Clean-param: utm_source&utm_medium&utm_campaign&utm_content&utm_term&yclid&gclid&fbclid';
        $lines[] = '';
        $lines[] = 'Sitemap: '.rtrim((string) config('app.url'), '/').'/sitemap.xml';

        return implode("\n", $lines)."\n";
    }
}
