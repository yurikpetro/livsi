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
 * Мусор от сканеров (wp-login, .env, phpmyadmin, phpinfo, terraform…) и файлы
 * (картинки, скрипты, конфиги) не пишем: в списке должны быть ссылки, по которым
 * приходят люди. Сканеры прикидываются браузером, поэтому узнаём их по адресу:
 * чего ищут (дыры и конфиги) и как (ссылаются сами на себя — «пришли с /old на /old»).
 */
final class MissingPages
{
    /** Таблица не растёт бесконечно: новые адреса сверх этого числа не записываются. */
    public const LIMIT = 3000;

    private const JUNK = '#(^|/)(wp-|wp/|wp$|wordpress|xmlrpc|phpmyadmin|pma|cgi-bin|vendor/|\.git|\.env|\.aws|\.ssh|\.vscode|\.DS_Store|config\.|config/|backup|admin/|administrator|boaform|actuator|telescope|_ignition|autodiscover|owa/|ecp/|\.well-known/(?!security\.txt)|phpinfo|_profiler|_debugbar|debug/|elmah|server-status|server-info|nginx_status|metrics$|env$|credentials|secrets?(\.|/|$)|dockerfile|jenkinsfile|vagrantfile|docker-compose|terraform|api/|v\d/_catalog|app/etc/|symfony/|oai_config|settings\.py|local_settings|wlwmanifest|sftp|ftpconfig)|\.(php\d?|aspx?|axd|jsp|cgi|sql|bak|old|zip|tar|t?gz|rar|7z|ini|log|yml|yaml|toml|xml|env|sh|py|rb|pem|key|enc|properties|plist|tfstate|tfvars|backup|config|csv|conf|png|jpe?g|gif|webp|avif|svg|ico|css|js|map|woff2?|ttf|eot|txt|json|pdf|mp4)$#i';

    private const BOTS = '/bot|crawl|spider|slurp|yandex|google|bing|curl|wget|python|go-http|http-client|scan/i';

    public static function record(Request $request): void
    {
        if (! in_array($request->getMethod(), ['GET', 'HEAD'], true) || $request->ajax() || $request->expectsJson()) {
            return;
        }
        $path = SeoRedirect::normalize($request->getPathInfo());
        $referer = mb_substr((string) $request->headers->get('referer'), 0, 255) ?: null;
        if (self::isJunk($path, $referer)) {
            return;
        }

        $bot = (bool) preg_match(self::BOTS, (string) $request->userAgent());

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

    /** Не ссылка, по которой приходят люди: сканер, файл, служебный адрес. */
    public static function isJunk(string $path, ?string $referer = null): bool
    {
        $path = SeoRedirect::normalize($path);
        if ($path === '/' || mb_strlen($path) > 250 || preg_match(self::JUNK, $path)
            || str_starts_with($path, '/livewire') || str_starts_with($path, '/admin')) {
            return true;
        }
        // Сканер ссылается сам на себя: «пришли с http://livsi.shop/old» на /old.
        // Человек на несуществующую страницу так не попадает.
        if ($referer !== null && parse_url($referer, PHP_URL_HOST) !== null
            && preg_replace('/^www\./', '', (string) parse_url($referer, PHP_URL_HOST)) === preg_replace('/^www\./', '', (string) parse_url((string) config('app.url'), PHP_URL_HOST))
            && SeoRedirect::normalize($referer) === $path) {
            return true;
        }

        return false;
    }

    /** Убрать из списка то, что теперь считается мусором. Возвращает, сколько убрано. */
    public static function purgeJunk(): int
    {
        $junk = MissingPage::query()->get(['id', 'path', 'last_referer'])
            ->filter(fn (MissingPage $page): bool => self::isJunk($page->path, $page->last_referer));
        MissingPage::query()->whereKey($junk->modelKeys())->delete();

        return $junk->count();
    }
}
