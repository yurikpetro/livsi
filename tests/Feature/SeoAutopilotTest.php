<?php

namespace Tests\Feature;

use App\Analytics\AnalyticsSettings;
use App\Filament\Pages\Analytics\AnalyticsSearch;
use App\Filament\Resources\MissingPages\MissingPageResource;
use App\Filament\Resources\MissingPages\Pages\ListMissingPages;
use App\Filament\Resources\SeoRedirects\Pages\ManageSeoRedirects;
use App\Filament\Resources\SeoRedirects\SeoRedirectResource;
use App\Models\MissingPage;
use App\Models\Product;
use App\Models\SeoRedirect;
use App\Models\User;
use App\Monitoring\Telegram;
use App\Seo\Autopilot;
use App\Seo\SiteHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * SEO-автопилот: сайт сам следит, что открыт для поисковиков, держит
 * в порядке Вебмастер и ловит битые ссылки.
 */
class SeoAutopilotTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'https://api.webmaster.yandex.net/v4/user/555/hosts/https:livsi.shop:443';

    /** @var list<string> */
    private array $telegram = [];

    /** @var array<string, mixed> адрес (шаблон) → ответ */
    private array $routes = [];

    private int $quota = 100;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://livsi.shop']);
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $mock = Mockery::mock(Telegram::class);
        $mock->shouldReceive('send')->andReturnUsing(function (string $html): void {
            $this->telegram[] = $html;
        });
        $this->app->instance(Telegram::class, $mock);

        // Один «интернет» на весь тест: ответы меняются по ходу теста.
        Http::fake(function (HttpRequest $request) {
            foreach ($this->routes as $pattern => $response) {
                if (Str::is($pattern, $request->url())) {
                    return is_callable($response) ? $response($request) : $response;
                }
            }

            return Http::response('not faked', 599);
        });
    }

    // ------------------------------------------------- открыт ли сайт

    public function test_site_check_catches_the_nginx_noindex_header_that_hid_the_site(): void
    {
        $this->fakeSite(['X-Robots-Tag' => 'noindex, nofollow']);

        $health = SiteHealth::check();

        $this->assertFalse($health['ok']);
        $this->assertStringContainsString('X-Robots-Tag «noindex, nofollow»', $health['problems'][0]);
        $this->assertStringContainsString('nginx', $health['problems'][0]);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://livsi.shop/' && str_contains($r->header('User-Agent')[0], 'YandexBot'));
    }

    public function test_open_site_passes_and_a_closed_robots_txt_does_not(): void
    {
        $this->fakeSite();
        $health = SiteHealth::check();
        $this->assertTrue($health['ok'], implode('; ', $health['problems']));
        $this->assertContains('sitemap.xml: код 200, адресов 2', $health['checked']);

        $this->fakeSite(robots: "User-agent: *\nDisallow: /\n");
        $this->assertContains('robots.txt закрывает весь сайт (Disallow: /) для Яндекса.', SiteHealth::check()['problems']);
    }

    public function test_closed_site_is_reported_to_telegram_even_before_yandex_is_connected(): void
    {
        $this->fakeSite(['X-Robots-Tag' => 'noindex']);

        $this->artisan('seo:autopilot')->assertFailed()->expectsOutputToContain('САЙТ ЗАКРЫТ ДЛЯ ПОИСКОВИКОВ');

        $this->assertCount(1, $this->telegram, implode(' ||| ', $this->telegram));
        $this->assertStringContainsString('поисковики не могут нормально видеть сайт', $this->telegram[0]);
        $this->assertStringContainsString('X-Robots-Tag', $this->telegram[0]);

        // В админке — красная плашка, без подключения Яндекса тоже.
        $this->get(AnalyticsSearch::getUrl())->assertOk()
            ->assertSee('Поисковики не могут нормально видеть сайт')
            ->assertSee('Данные поиска появятся после подключения');

        // Тихий запуск (после установки обновления) в Telegram не пишет.
        $this->artisan('seo:autopilot --quiet-telegram');
        $this->assertCount(1, $this->telegram, implode(' ||| ', $this->telegram));
    }

    // ------------------------------------------------- Вебмастер

    public function test_autopilot_fixes_the_sitemap_list_recrawls_old_and_new_pages_and_reports_new_problems(): void
    {
        $this->connect();
        Product::create(['slug' => 'multipenka-dlya-ruk-i-stop', 'title' => 'Мультипенка', 'is_active' => true, 'sort' => 1]);
        Product::create(['slug' => 'keratoliticheskiy-gel-dlya-stop', 'title' => 'Гель', 'is_active' => true, 'sort' => 2]);
        $problems = ['MAIN_PAGE_ERROR' => ['severity' => 'FATAL', 'state' => 'PRESENT', 'last_state_update' => '2026-10-20T00:00:00.000+03:00'],
            'NO_REGIONS' => ['severity' => 'RECOMMENDATION', 'state' => 'PRESENT']];
        $this->fakeYandex(function () use (&$problems) {
            return $problems;
        });

        $this->artisan('seo:autopilot')->assertSuccessful();

        // Старая карта прежнего сайта убрана, новая добавлена.
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && $r->url() === self::HOST.'/user-added-sitemaps/old-1');
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r->url() === self::HOST.'/user-added-sitemaps' && $r['url'] === 'https://livsi.shop/sitemap.xml');

        $sent = $this->recrawled();
        // Главная и каталог; старый адрес из выдачи, который теперь ведёт на новую страницу;
        // товар, которого ещё нет в поиске.
        $this->assertContains('https://livsi.shop/', $sent);
        $this->assertContains('https://livsi.shop/catalog', $sent);
        $this->assertContains('https://livsi.shop/magazin/vendor/livsi-professional/page/8/', $sent);
        $this->assertContains('https://livsi.shop/product/keratoliticheskiy-gel-dlya-stop', $sent);
        // Живая страница, которая уже в поиске, — не трогаем.
        $this->assertNotContains('https://livsi.shop/product/multipenka-dlya-ruk-i-stop', $sent);
        // Товары раньше юридических документов.
        $legal = array_values(array_filter($sent, fn (string $u) => str_contains($u, '/legal/') || str_contains($u, 'privacy') || str_contains($u, 'offer')));
        if ($legal !== []) {
            $this->assertLessThan(array_search($legal[0], $sent), array_search('https://livsi.shop/product/keratoliticheskiy-gel-dlya-stop', $sent));
        }

        $this->assertCount(1, $this->telegram, implode(' ||| ', $this->telegram));
        $this->assertStringContainsString('Главная страница отвечает ошибкой', $this->telegram[0]);

        // Назавтра: о той же проблеме не повторяем, отправленные страницы повторно не шлём.
        $this->travel(1)->day();
        $before = count($this->recrawled());
        $this->artisan('seo:autopilot')->assertSuccessful();
        $this->assertCount(1, $this->telegram, implode(' ||| ', $this->telegram));
        $this->assertSame([], $this->recrawled($before));

        // Яндекс снял проблему — сообщаем.
        $problems = ['NO_REGIONS' => ['severity' => 'RECOMMENDATION', 'state' => 'PRESENT']];
        $this->travel(1)->day();
        $this->artisan('seo:autopilot')->assertSuccessful();
        $this->assertCount(2, $this->telegram);
        $this->assertStringContainsString('Яндекс снял проблему', $this->telegram[1]);

        // Через неделю — снова главная и каталог; при квоте 5 тратим не больше 4.
        $this->travel(6)->days();
        $this->quota = 5;
        $before = count($this->recrawled());
        $this->artisan('seo:autopilot')->assertSuccessful();
        $again = $this->recrawled($before);
        $this->assertContains('https://livsi.shop/', $again);
        $this->assertLessThanOrEqual(4, count($again));

        $state = Autopilot::state();
        $this->assertTrue($state['health']['ok']);
        $this->assertNotEmpty($state['log']);
    }

    public function test_when_webmaster_is_read_only_the_pages_go_through_indexnow_and_the_log_says_why(): void
    {
        // 10.11.2026 Яндекс перевёл Вебмастер в режим «только чтение»: любое изменение — 504.
        $this->app['env'] = 'production';
        $this->connect();
        Product::create(['slug' => 'keratoliticheskiy-gel-dlya-stop', 'title' => 'Гель', 'is_active' => true, 'sort' => 1]);
        $this->fakeYandex(fn () => []);
        $this->routes = [
            self::HOST.'/recrawl/queue' => Http::response('', 504),
            self::HOST.'/user-added-sitemaps/*' => Http::response('', 504),
            'https://yandex.com/indexnow' => Http::response('', 202),
        ] + array_filter($this->routes, fn ($key) => ! str_contains($key, 'recrawl/queue') && ! str_contains($key, 'user-added-sitemaps/'), ARRAY_FILTER_USE_KEY);

        $this->artisan('seo:autopilot --quiet-telegram')->assertSuccessful();

        // До Вебмастера постучались дважды и остановились, а не 55 раз.
        $this->assertCount(2, $this->recrawled());
        $ping = Http::recorded(fn (HttpRequest $r) => $r->url() === 'https://yandex.com/indexnow')->first()[0];
        $this->assertContains('https://livsi.shop/', $ping['urlList']);
        $this->assertContains('https://livsi.shop/magazin/vendor/livsi-professional/page/8/', $ping['urlList']);
        $this->assertContains('https://livsi.shop/product/keratoliticheskiy-gel-dlya-stop', $ping['urlList']);

        $log = implode("\n", Autopilot::state()['log']);
        $this->assertStringContainsString('Вебмастер сейчас не принимает изменения (ответ 504) — сбой у Яндекса', $log);
        $this->assertStringContainsString('Переобход: Вебмастер сейчас не принимает заявки (ответ 504) — сбой у Яндекса, повторю завтра.', $log);
        $this->assertStringContainsString('Вместо Вебмастера отправил через IndexNow адресов', $log);

        // Назавтра Вебмастер всё ещё лежит: в Вебмастер пробуем снова, через IndexNow повторно не шлём.
        $this->travel(1)->day();
        $before = count($this->recrawled());
        $this->artisan('seo:autopilot --quiet-telegram')->assertSuccessful();
        $this->assertCount(2, $this->recrawled($before));
        $this->assertCount(1, Http::recorded(fn (HttpRequest $r) => $r->url() === 'https://yandex.com/indexnow'));
        $this->assertStringContainsString('Через IndexNow эти адреса уже отправлены на этой неделе', implode("\n", Autopilot::state()['log']));

        // Вебмастер ожил — всё уходит туда.
        $this->travel(1)->day();
        $this->routes[self::HOST.'/recrawl/queue'] = Http::response(['task_id' => 'x', 'quota_remainder' => 9], 202);
        $this->routes[self::HOST.'/user-added-sitemaps/*'] = Http::response([], 204);
        $before = count($this->recrawled());
        $this->artisan('seo:autopilot --quiet-telegram')->assertSuccessful();
        $this->assertContains('https://livsi.shop/', $this->recrawled($before));
        $this->assertStringContainsString('Убрал из Вебмастера старую карту сайта', implode("\n", Autopilot::state()['log']));
    }

    public function test_refused_requests_stop_after_three_and_name_the_reason(): void
    {
        $this->connect();
        $this->fakeYandex(fn () => []);
        $this->routes = [self::HOST.'/recrawl/queue' => Http::response(['error_code' => 'INVALID_URL'], 400)] + $this->routes;

        $this->artisan('seo:autopilot --quiet-telegram')->assertSuccessful();

        $this->assertCount(3, $this->recrawled());
        $this->assertStringContainsString('Яндекс отклоняет заявки (ответ 400 INVALID_URL)', implode("\n", Autopilot::state()['log']));
        // Не боевой сайт — IndexNow выключен, так и пишем.
        $this->assertStringContainsString('IndexNow на этом сайте выключен', implode("\n", Autopilot::state()['log']));
    }

    public function test_search_page_shows_the_autopilot_log_and_downloads_the_report_for_claude(): void
    {
        $this->connect();
        $this->fakeYandex(fn () => []);
        $this->artisan('seo:autopilot --quiet-telegram');
        MissingPage::create(['path' => '/shampuni', 'hits' => 3, 'bot_hits' => 1, 'last_seen_at' => now()]);

        $this->get(AnalyticsSearch::getUrl())->assertOk()
            ->assertSee('SEO-автопилот')->assertSee('Последний запуск')
            ->assertSee('Сайт открыт для поисковиков')
            ->assertSee('Скачать SEO-отчёт для Claude');

        $this->travelTo(now()->setDate(2026, 11, 10));
        Livewire::test(AnalyticsSearch::class)->callAction('report')->assertFileDownloaded('livsi-seo-2026-11-10.md');

        $report = AnalyticsSearch::download();
        ob_start();
        $report->sendContent();
        $md = (string) ob_get_clean();
        $this->assertStringContainsString('# livsi.shop — SEO-отчёт', $md);
        $this->assertStringContainsString('Страниц в поиске: 31', $md);
        $this->assertStringContainsString('/shampuni — людей 3, роботов 1, не перенаправлена (подсказка: /catalog)', $md);
        $this->assertStringNotContainsString('token-1', $md);

        Artisan::call('seo:weekly', ['--print' => true]);
        $weekly = Artisan::output();
        $this->assertStringContainsString('В поиске Яндекса страниц: 31', $weekly);
        $this->assertStringContainsString('Битых ссылок за неделю: 1', $weekly);
        $this->assertStringContainsString('Сайт открыт для поисковиков', $weekly);
    }

    // ------------------------------------------------- битые ссылки

    public function test_old_sitemaps_answer_with_the_current_map(): void
    {
        Product::create(['slug' => 'multipenka-dlya-ruk-i-stop', 'title' => 'Мультипенка', 'is_active' => true, 'sort' => 1]);

        $gz = $this->get('/sitemap.3972363.xml.gz')->assertOk();
        $this->assertStringContainsString('multipenka-dlya-ruk-i-stop', gzdecode($gz->getContent()));
        $this->get('/sitemap_index.xml')->assertOk()->assertSee('<loc>', false);
        $this->get('/wp-sitemap.xml')->assertOk()->assertSee('multipenka-dlya-ruk-i-stop');
    }

    public function test_not_found_addresses_are_listed_for_the_owner_but_scanner_junk_is_not(): void
    {
        $this->get('/shampuni', ['Referer' => 'https://yandex.ru/search/?text=livsi'])->assertNotFound();
        $this->get('/shampuni', ['User-Agent' => 'Mozilla/5.0 (compatible; YandexBot/3.0)'])->assertNotFound();
        $this->get('/product/staryy-tovar')->assertNotFound();
        $this->get('/catalog/staryy-tovar')->assertStatus(301); // старое начало адреса — сайт ведёт в каталог сам
        foreach (['/wp-login.php', '/.env', '/img/old.png', '/admin/nothing', '/phpmyadmin/index'] as $junk) {
            $this->get($junk);
        }
        $this->postJson('/nothing-here', []);

        $this->assertSame(['/product/staryy-tovar', '/shampuni'], MissingPage::query()->orderBy('path')->pluck('path')->all());
        $page = MissingPage::query()->where('path', '/shampuni')->first();
        $this->assertSame(1, $page->hits);
        $this->assertSame(1, $page->bot_hits);
        $this->assertSame('https://yandex.ru/search/?text=livsi', $page->last_referer);
    }

    public function test_redirect_set_in_the_admin_works_keeps_ad_marks_and_beats_a_live_route(): void
    {
        $foam = Product::create(['slug' => 'multipenka-dlya-ruk-i-stop', 'title' => 'Мультипенка', 'is_active' => true, 'sort' => 1]);
        Product::create(['slug' => 'staraya-penka', 'title' => 'Старая', 'is_active' => true, 'sort' => 2]);

        SeoRedirect::create(['from_path' => 'https://livsi.shop/Shampuni/', 'to_url' => '/catalog']);
        SeoRedirect::create(['from_path' => '/product/staraya-penka', 'to_url' => '/product/multipenka-dlya-ruk-i-stop']);

        $this->get('/shampuni?utm_source=vk')->assertStatus(301)->assertRedirect('/catalog?utm_source=vk');
        $this->get('/product/staraya-penka')->assertStatus(301)->assertRedirect(route('catalog.show', $foam));
        $this->assertSame(1, SeoRedirect::query()->where('from_path', '/shampuni')->value('hits'));

        // Формы не трогаем.
        $this->post('/shampuni')->assertStatus(405);

        // Удалили — снова обычный ответ сайта.
        SeoRedirect::query()->where('from_path', '/product/staraya-penka')->first()->delete();
        $this->get('/product/staraya-penka')->assertOk();

        // Две переадресации друг на друга — не гоняем по кругу.
        SeoRedirect::create(['from_path' => '/a-page', 'to_url' => '/b-page']);
        SeoRedirect::create(['from_path' => '/b-page', 'to_url' => '/a-page']);
        $this->get('/a-page')->assertNotFound();
    }

    public function test_broken_links_page_redirects_with_one_click_and_suggests_the_product(): void
    {
        $foam = Product::create(['slug' => 'multipenka-dlya-ruk-i-stop', 'title' => 'Мультипенка', 'is_active' => true, 'sort' => 1]);
        $a = MissingPage::create(['path' => '/shampuni', 'hits' => 5, 'last_seen_at' => now()]);
        $b = MissingPage::create(['path' => '/nails/multipenka-dlya-ruk-i-stop-200', 'hits' => 2, 'last_seen_at' => now()]);
        $c = MissingPage::create(['path' => '/old-promo', 'bot_hits' => 2, 'last_seen_at' => now()]);

        $this->get(MissingPageResource::getUrl())->assertOk()->assertSee('/shampuni')->assertSee('Перенаправить');
        $this->assertSame('3', MissingPageResource::getNavigationBadge());

        Livewire::test(ListMissingPages::class)
            ->assertCanSeeTableRecords([$a, $b, $c])
            ->mountTableAction('redirect', $b)
            ->assertTableActionDataSet(['to_url' => route('catalog.show', $foam, false)])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();
        Livewire::test(ListMissingPages::class)
            ->callTableAction('redirect', $a, data: ['to_url' => 'https://www.livsi.shop/catalog?tab=care'])
            ->assertHasNoTableActionErrors();
        Livewire::test(ListMissingPages::class)
            ->callTableAction('redirect', $c, data: ['to_url' => '/old-promo'])
            ->assertHasTableActionErrors(['to_url']);
        Livewire::test(ListMissingPages::class)
            ->callTableAction('ignore', $c)
            // Перенаправленные и скрытые — из списка уходят.
            ->assertCanNotSeeTableRecords([$a, $b, $c]);

        $this->assertSame('/catalog?tab=care', SeoRedirect::map()['/shampuni']);
        $this->assertSame('/product/multipenka-dlya-ruk-i-stop', SeoRedirect::map()['/nails/multipenka-dlya-ruk-i-stop-200']);
        $this->assertTrue($c->fresh()->ignored);
        $this->assertNull(MissingPageResource::getNavigationBadge());
        $this->get('/shampuni')->assertRedirect('/catalog?tab=care');

        // Переадресации видны и правятся отдельным списком.
        $this->get(SeoRedirectResource::getUrl())->assertOk()->assertSee('/shampuni')->assertSee('/catalog?tab=care');
        Livewire::test(ManageSeoRedirects::class)
            ->callAction('create', data: ['from_path' => '/', 'to_url' => '/catalog'])
            ->assertHasActionErrors(['from_path']);
        Livewire::test(ManageSeoRedirects::class)
            ->callAction('create', data: ['from_path' => '/shampuni/', 'to_url' => '/catalog'])
            ->assertHasActionErrors(['from_path']);
        Livewire::test(ManageSeoRedirects::class)
            ->callAction('create', data: ['from_path' => 'https://livsi.shop/Akcii/', 'to_url' => 'https://livsi.shop/catalog?tab=new'])
            ->assertHasNoActionErrors();
        $this->assertSame('/catalog?tab=new', SeoRedirect::map()['/akcii']);
    }

    public function test_only_the_owner_sees_the_seo_pages(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->get(MissingPageResource::getUrl())->assertForbidden();
        $this->get(SeoRedirectResource::getUrl())->assertForbidden();
    }

    // ------------------------------------------------- помощники

    /** @param array<string, string> $headers заголовки главной страницы */
    private function fakeSite(array $headers = [], string $robots = "User-agent: *\nDisallow: /admin\n\nSitemap: https://livsi.shop/sitemap.xml\n", array $extra = []): void
    {
        $this->routes = $extra + [
            'https://livsi.shop/robots.txt' => Http::response($robots),
            'https://livsi.shop/sitemap.xml' => Http::response('<urlset><url><loc>https://livsi.shop/</loc></url><url><loc>https://livsi.shop/catalog</loc></url></urlset>'),
            'https://livsi.shop/catalog' => Http::response('<html><head><title>Каталог</title></head></html>'),
            'https://livsi.shop/' => Http::response('<html><head><meta name="robots" content="index, follow"></head></html>', 200, $headers),
        ];
    }

    private function connect(): void
    {
        AnalyticsSettings::setOptions(['oauth_client_id' => '0123456789abcdef0123456789abcdef', 'webmaster_user_id' => '555',
            'webmaster_host_id' => 'https:livsi.shop:443', 'token_expires_at' => now()->addYear()->toIso8601String()]);
        AnalyticsSettings::setSecrets(['oauth_client_secret' => 'secret', 'access_token' => 'token-1', 'refresh_token' => 'refresh-1']);
    }

    /** @param callable(): array<string, mixed> $problems */
    private function fakeYandex(callable $problems): void
    {
        $this->fakeSite(extra: [
            self::HOST.'/recrawl/quota' => fn () => Http::response(['daily_quota' => $this->quota, 'quota_remainder' => $this->quota]),
            self::HOST.'/recrawl/queue' => Http::response(['task_id' => 'x', 'quota_remainder' => 9], 202),
            self::HOST.'/user-added-sitemaps/*' => Http::response([], 204),
            self::HOST.'/user-added-sitemaps*' => fn (HttpRequest $r) => $r->method() === 'GET'
                ? Http::response(['count' => 1, 'sitemaps' => [['sitemap_id' => 'old-1', 'sitemap_url' => 'https://livsi.shop/sitemap.3972363.xml.gz']]])
                : Http::response(['sitemap_id' => 'new-1'], 202),
            self::HOST.'/search-urls/in-search/samples*' => Http::response(['count' => 2, 'samples' => [
                ['url' => 'https://livsi.shop/product/multipenka-dlya-ruk-i-stop', 'title' => 'Мультипенка'],
                ['url' => 'https://livsi.shop/magazin/vendor/livsi-professional/page/8/', 'title' => 'шампуни, Nails Ливси'],
            ]]),
            self::HOST.'/diagnostics' => fn () => Http::response(['problems' => $problems()]),
            self::HOST.'/summary' => Http::response(['sqi' => 10, 'searchable_pages_count' => 31, 'excluded_pages_count' => 15, 'site_problems' => ['FATAL' => 1]]),
            self::HOST.'/search-queries/popular*' => Http::response(['count' => 0, 'queries' => []]),
            self::HOST.'/search-urls/events/samples*' => Http::response(['count' => 0, 'samples' => []]),
            // Свой сайт глазами робота: старый адрес ведёт на новую страницу, живая — 200.
            'https://livsi.shop/magazin/*' => Http::response('', 301, ['Location' => 'https://livsi.shop/catalog']),
            'https://livsi.shop/product/*' => Http::response('<html></html>'),
        ]);
    }

    /** @return list<string> адреса, отправленные на переобход, начиная с $from-й отправки */
    private function recrawled(int $from = 0): array
    {
        return array_slice(Http::recorded(fn (HttpRequest $r) => $r->method() === 'POST' && $r->url() === self::HOST.'/recrawl/queue')
            ->map(fn (array $pair) => $pair[0]['url'])->values()->all(), $from);
    }
}
