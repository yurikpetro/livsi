<?php

namespace App\Seo;

use App\Models\MissingPage;
use App\Models\SeoRedirect;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Запоминает адреса, на которых человек или поисковый робот получил 404, —
 * для списка «Битые ссылки» в админке.
 *
 * Мусор от сканеров (wp-login, .env, phpmyadmin…) и файлы (картинки,
 * скрипты) не пишем: в списке должны быть ссылки, по которым приходят люди.
 */
final class MissingPages
{
    /** Таблица не растёт бесконечно: новые адреса сверх этого числа не записываются. */
    public const LIMIT = 3000;

    private const JUNK = '#(^|/)(wp-|wordpress|xmlrpc|phpmyadmin|pma|cgi-bin|vendor/|\.git|\.env|\.aws|\.ssh|\.vscode|\.DS_Store|config\.|backup|admin/|administrator|boaform|actuator|telescope|_ignition|autodiscover|owa/|ecp/|\.well-known/(?!security\.txt))|\.(php\d?|aspx?|jsp|cgi|sql|bak|old|zip|tar|t?gz|rar|7z|ini|log|yml|yaml|env|sh|png|jpe?g|gif|webp|avif|svg|ico|css|js|map|woff2?|ttf|eot|txt|json|pdf|mp4)$#i';

    private const BOTS = '/bot|crawl|spider|slurp|yandex|google|bing|curl|wget|python|go-http|http-client|scan/i';

    public static function record(Request $request): void
    {
        if (! in_array($request->getMethod(), ['GET', 'HEAD'], true) || $request->ajax() || $request->expectsJson()) {
            return;
        }
        $path = SeoRedirect::normalize($request->getPathInfo());
        if ($path === '/' || mb_strlen($path) > 250 || preg_match(self::JUNK, $path) || str_starts_with($path, '/livewire') || str_starts_with($path, '/admin')) {
            return;
        }

        $bot = (bool) preg_match(self::BOTS, (string) $request->userAgent());
        $referer = mb_substr((string) $request->headers->get('referer'), 0, 255) ?: null;

        try {
            $updated = DB::table('missing_pages')->where('path', $path)->update([
                $bot ? 'bot_hits' : 'hits' => DB::raw(($bot ? 'bot_hits' : 'hits').' + 1'),
                'last_referer' => $referer ?? DB::raw('last_referer'),
                'last_seen_at' => now(),
                'updated_at' => now(),
            ]);
            if ($updated === 0 && MissingPage::query()->count() < self::LIMIT) {
                MissingPage::query()->insertOrIgnore([
                    'path' => $path,
                    'hits' => $bot ? 0 : 1,
                    'bot_hits' => $bot ? 1 : 0,
                    'last_referer' => $referer,
                    'first_seen_at' => now(),
                    'last_seen_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } catch (Throwable) {
            // Список битых ссылок не должен ломать страницу 404.
        }
    }
}
