<?php

namespace Tests\Feature;

use App\Analytics\AnalyticsSettings;
use App\Analytics\Period;
use App\Analytics\SalesBySource;
use App\Analytics\TrafficChannel;
use App\Analytics\YandexOAuth;
use App\Filament\Pages\Analytics\AnalyticsConnection;
use App\Filament\Pages\Analytics\AnalyticsSales;
use App\Filament\Pages\Analytics\AnalyticsSearch;
use App\Filament\Pages\Analytics\AnalyticsTraffic;
use App\Models\LegalPage;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Раздел «Аналитика сайта»: Метрика на сайте только с согласия, доступ
 * к Метрике и Вебмастеру из админки, заказы по источникам.
 */
class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_ID = '0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://livsi.shop']);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    // ------------------------------------------------------------ подключение

    public function test_pages_explain_what_to_do_before_yandex_is_connected(): void
    {
        $this->get(AnalyticsTraffic::getUrl())->assertOk()->assertSee('Посещаемость появится после подключения');
        $this->get(AnalyticsSearch::getUrl())->assertOk()->assertSee('Данные поиска появятся после подключения');
        $this->get(AnalyticsSales::getUrl())->assertOk()->assertSee('Заказы по источникам')->assertSee('Расходы на рекламу');
        $this->get(AnalyticsConnection::getUrl())->assertOk()->assertSee('Для доступа к API или отладки')
            ->assertSee('metrika:read')->assertSee('webmaster:hostinfo');
    }

    public function test_keys_are_saved_secretly_and_the_verification_code_is_taken_from_a_whole_meta_tag(): void
    {
        Livewire::test(AnalyticsConnection::class)
            ->fillForm([
                'oauth_client_id' => ' '.self::CLIENT_ID.' ',
                'oauth_client_secret' => 'secret-value-123',
                'metrika_counter_id' => '98 765 432',
                'metrika_webvisor' => false,
                'yandex_verification' => '<meta name="yandex-verification" content="8cee32ceb06d67e7" />',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(self::CLIENT_ID, AnalyticsSettings::clientId());
        $this->assertSame('secret-value-123', AnalyticsSettings::secret('oauth_client_secret'));
        $this->assertStringNotContainsString('secret-value-123', json_encode(Setting::all()->toArray()));
        $this->assertSame(98765432, AnalyticsSettings::counterId());
        $this->assertFalse(AnalyticsSettings::webvisor());
        $this->assertSame('8cee32ceb06d67e7', Setting::get('yandex_verification'));

        // Пустое поле секрета — оставить как было.
        Livewire::test(AnalyticsConnection::class)->fillForm(['oauth_client_secret' => ''])->call('save');
        $this->assertSame('secret-value-123', AnalyticsSettings::secret('oauth_client_secret'));
    }

    public function test_site_settings_no_longer_wipe_the_webmaster_code(): void
    {
        Setting::put('yandex_verification', '8cee32ceb06d67e7');

        Livewire::test(\App\Filament\Pages\Settings::class)->fillForm(['reviews_score' => '4.9'])->call('save');

        $this->assertSame('8cee32ceb06d67e7', Setting::get('yandex_verification'));
        $this->get('/contacts')->assertSee('<meta name="yandex-verification" content="8cee32ceb06d67e7">', false);
    }

    public function test_code_from_the_yandex_screen_connects_and_fills_in_the_webmaster_code(): void
    {
        $this->configureApp();
        $this->fakeYandex();

        Livewire::test(AnalyticsConnection::class)
            ->fillForm(['oauth_code' => ' 1234567 '])
            ->call('confirmCode')
            ->assertNotified('Яндекс подключён');

        Http::assertSent(fn (HttpRequest $r) => $r->url() === YandexOAuth::TOKEN_URL
            && $r['code'] === '1234567' && $r['grant_type'] === 'authorization_code'
            && $r['client_id'] === self::CLIENT_ID && $r['client_secret'] === 'secret-value-123');
        $this->assertSame('token-1', AnalyticsSettings::secret('access_token'));
        $this->assertSame('refresh-1', AnalyticsSettings::secret('refresh_token'));
        $this->assertTrue(app(YandexOAuth::class)->expiresAt()->gt(now()->addDays(300)));
        $this->assertSame('8cee32ceb06d67e7', AnalyticsSettings::verificationCode());
        $this->assertSame('https:livsi.shop:443', AnalyticsSettings::option('webmaster_host_id'));
    }

    public function test_redirect_back_from_yandex_needs_the_state_it_was_sent_with(): void
    {
        $this->configureApp();
        $this->fakeYandex();

        $redirect = $this->get(route('admin.analytics.yandex.connect'))->assertRedirect();
        $url = $redirect->headers->get('Location');
        $this->assertStringStartsWith(YandexOAuth::AUTHORIZE_URL.'?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame(self::CLIENT_ID, $query['client_id']);
        $this->assertSame('code', $query['response_type']);

        // Чужой state — ничего не подключаем.
        $this->get(route('admin.analytics.yandex.callback', ['code' => '777', 'state' => 'forged']))->assertRedirect(AnalyticsConnection::getUrl());
        $this->assertNull(AnalyticsSettings::secret('access_token'));

        $this->get(route('admin.analytics.yandex.connect'));
        $state = session('analytics_oauth_state');
        $this->get(route('admin.analytics.yandex.callback', ['code' => '777', 'state' => $state]))->assertRedirect(AnalyticsConnection::getUrl());
        $this->assertSame('token-1', AnalyticsSettings::secret('access_token'));

        // Не владелец сайта — не пускаем.
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->get(route('admin.analytics.yandex.connect'))->assertForbidden();
    }

    public function test_access_is_renewed_a_month_before_it_ends(): void
    {
        $this->configureApp();
        AnalyticsSettings::setSecrets(['access_token' => 'old-token', 'refresh_token' => 'refresh-0']);
        AnalyticsSettings::setOptions(['token_expires_at' => now()->addDays(10)->toIso8601String()]);
        Http::fake([YandexOAuth::TOKEN_URL => Http::response(['access_token' => 'new-token', 'refresh_token' => 'refresh-2', 'expires_in' => 31536000])]);

        $this->artisan('analytics:token')->assertSuccessful();

        Http::assertSent(fn (HttpRequest $r) => $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'refresh-0');
        $this->assertSame('new-token', AnalyticsSettings::secret('access_token'));
        $this->assertSame('refresh-2', AnalyticsSettings::secret('refresh_token'));
    }

    // ---------------------------------------------------------------- отчёты

    public function test_traffic_page_shows_metrika_numbers_and_one_refused_block_does_not_hide_the_rest(): void
    {
        $this->connect();
        Http::fake(function (HttpRequest $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            $this->assertSame('98765432', $q['ids']);
            $this->assertSame('lastsign', $q['attribution']);
            $this->assertSame('OAuth token-1', $request->header('Authorization')[0]);

            return match ($q['dimensions'] ?? null) {
                null => Http::response(['data' => [], 'totals' => [321, 210, 980, 41.5, 95.4, 3.05, 150]]),
                'ym:s:date' => Http::response(['data' => [
                    ['dimensions' => [['name' => now('Europe/Moscow')->format('Y-m-d')]], 'metrics' => [17, 12]],
                ], 'totals' => [17, 12]]),
                'ym:s:<attribution>TrafficSource' => Http::response(['data' => [
                    ['dimensions' => [['name' => 'Переходы из поисковых систем', 'id' => 'organic']], 'metrics' => [200, 150, 30.0]],
                    ['dimensions' => [['name' => 'Прямые заходы', 'id' => 'direct']], 'metrics' => [121, 60, 50.0]],
                ], 'totals' => [321, 210, 38.0]]),
                'ym:s:regionCity' => Http::response(['data' => [
                    ['dimensions' => [['name' => 'Ставрополь']], 'metrics' => [90, 70, 20.0]],
                ], 'totals' => [321, 210, 38.0]]),
                'ym:s:deviceCategory' => Http::response(['errors' => [['message' => 'Access denied']], 'message' => 'Access denied'], 403),
                'ym:pv:URLPath' => Http::response(['data' => [
                    ['dimensions' => [['name' => '/product/multipenka']], 'metrics' => [55, 40]],
                ]]),
                default => Http::response(['data' => [], 'totals' => [0, 0, 0]]),
            };
        });

        $this->get(AnalyticsTraffic::getUrl())->assertOk()
            ->assertSee('посетителей (людей)')->assertSee('210')->assertSee('1 мин 35 с')
            ->assertSee('Переходы из поисковых систем')->assertSee('62,3%')
            ->assertSee('Ставрополь')->assertSee('/product/multipenka')
            ->assertSee('Метрика: нет доступа.')
            ->assertDontSee('token-1');
    }

    public function test_search_page_shows_queries_from_webmaster_and_marks_brand_ones(): void
    {
        $this->connect();
        $this->fakeYandex([
            '*/search-queries/popular*' => Http::response(['queries' => [
                ['query_id' => 'a', 'query_text' => 'livsi', 'indicators' => ['TOTAL_SHOWS' => 120.0, 'TOTAL_CLICKS' => 30.0, 'AVG_SHOW_POSITION' => 2.4, 'AVG_CLICK_POSITION' => 1.3]],
                ['query_id' => 'b', 'query_text' => 'пенка для маникюра', 'indicators' => ['TOTAL_SHOWS' => 80.0, 'TOTAL_CLICKS' => 2.0, 'AVG_SHOW_POSITION' => 14.2]],
            ], 'count' => 2]),
            '*/search-queries/all/history*' => Http::response(['indicators' => [
                'TOTAL_SHOWS' => [['date' => now('Europe/Moscow')->subDays(5)->format('Y-m-d').'T00:00:00.000+03:00', 'value' => 40.0]],
                'TOTAL_CLICKS' => [['date' => now('Europe/Moscow')->subDays(5)->format('Y-m-d').'T00:00:00.000+03:00', 'value' => 6.0]],
            ]]),
            '*/summary' => Http::response(['sqi' => 10, 'searchable_pages_count' => 24, 'excluded_pages_count' => 3,
                'site_problems' => ['FATAL' => 0, 'CRITICAL' => 0, 'POSSIBLE_PROBLEM' => 2, 'RECOMMENDATION' => 1]]),
        ]);

        $this->get(AnalyticsSearch::getUrl())->assertOk()
            ->assertSee('страниц в поиске')->assertSee('24')
            ->assertSee('пенка для маникюра')->assertSee('14,2')
            ->assertSee('<tr class="is-brand">', false);

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/search-queries/popular')
            && str_contains($r->url(), 'query_indicator=TOTAL_SHOWS&query_indicator=TOTAL_CLICKS')
            && str_contains($r->url(), '/hosts/https:livsi.shop:443/'));
    }

    public function test_sales_page_splits_orders_by_where_buyers_came_from(): void
    {
        $this->order(['referer' => 'https://ya.ru/'], 150000);
        $this->order(['referer' => 'https://yandex.ru/search/?text=livsi'], 50000);
        $this->order(['utm_source' => 'yandex', 'utm_medium' => 'cpc', 'utm_campaign' => 'brand', 'yclid' => '123'], 90000);
        $this->order(['referer' => 'https://vk.com/livsi'], 70000, Order::STATUS_REFUNDED);
        $this->order(null, 30000, Order::STATUS_AWAITING_PAYMENT);

        $report = SalesBySource::report(Period::make('30d'));
        $byKey = collect($report['channels'])->keyBy('key');

        $this->assertSame(5, $report['totals']['orders']);
        $this->assertSame(3, $report['totals']['paid']);
        $this->assertSame(290000, $report['totals']['revenue']);
        $this->assertSame(200000, $byKey['search_yandex']['revenue']);
        $this->assertSame(2, $byKey['search_yandex']['orders']);
        $this->assertSame(90000, $byKey['ad_yandex']['revenue']);
        $this->assertSame(0, $byKey['social']['revenue']);
        $this->assertSame(1, $byKey['direct']['orders']);
        $this->assertSame('Реклама в Яндексе (Директ) · yandex · brand', $report['campaigns'][0]['label']);

        $this->get(AnalyticsSales::getUrl())->assertOk()->assertSee('Поиск Яндекса')->assertSee('Реклама в Яндексе (Директ)');
    }

    public function test_channels_are_named_by_marks_and_referer(): void
    {
        $this->assertSame('search_google', TrafficChannel::of(['referer' => 'https://www.google.com/'])['key']);
        $this->assertSame('marketplace', TrafficChannel::of(['referer' => 'https://www.wildberries.ru/catalog/1'])['key']);
        $this->assertSame('social', TrafficChannel::of(['referer' => 'https://t.me/livsi'])['key']);
        $this->assertSame('email', TrafficChannel::of(['referer' => 'https://mail.yandex.ru/'])['key']);
        $this->assertSame('referral', TrafficChannel::of(['referer' => 'https://beauty-forum.ru/thread/5'])['key']);
        $this->assertSame('direct', TrafficChannel::of(['referer' => 'https://livsi.shop/catalog'])['key']);
        $this->assertSame('utm', TrafficChannel::of(['utm_source' => 'telegram'])['key']);
        $this->assertSame('ad', TrafficChannel::of(['utm_source' => 'vk', 'utm_medium' => 'cpc'])['key']);
        $this->assertSame('direct', TrafficChannel::of(null)['key']);
    }

    // ---------------------------------------------------- источник визита

    public function test_visit_source_is_remembered_from_the_referer_and_ad_marks_win(): void
    {
        $this->get('/catalog', ['Referer' => 'https://ya.ru/'])->assertOk();
        $this->assertSame('https://ya.ru/', session('utm')['referer']);

        // Свои переходы и возврат с оплаты источник не меняют.
        $this->get('/', ['Referer' => 'https://pay.tochka.com/abc'])->assertOk();
        $this->get('/', ['Referer' => 'https://vk.com/'])->assertOk();
        $this->assertSame('https://ya.ru/', session('utm')['referer']);

        // Метки рекламы точнее — перекрывают адрес перехода, но не наоборот.
        $this->get('/catalog?utm_source=yandex&utm_medium=cpc&token=secret', ['Referer' => 'https://yandex.ru/'])->assertOk();
        $this->assertSame('yandex', session('utm')['utm_source']);
        $this->assertStringEndsWith('/catalog?utm_source=yandex&utm_medium=cpc', session('utm')['landing']);
        $this->get('/catalog?utm_source=vk')->assertOk();
        $this->assertSame('yandex', session('utm')['utm_source']);
    }

    public function test_payment_page_return_is_not_a_visit_source(): void
    {
        $this->get('/', ['Referer' => 'https://yoomoney.ru/checkout/payments/v2/contract'])->assertOk();
        $this->get('/', ['Referer' => 'https://livsi.shop/cart'])->assertOk();
        $this->assertNull(session('utm'));
    }

    // ------------------------------------------------------- счётчик на сайте

    public function test_metrika_is_on_the_site_only_when_a_counter_is_chosen_and_waits_for_consent(): void
    {
        $this->get('/contacts')->assertOk()->assertDontSee('mc.yandex.ru', false)->assertSee('Настройки cookie');

        AnalyticsSettings::setOptions(['metrika_counter_id' => '98765432']);
        $this->get('/contacts')->assertOk()
            ->assertSee('mc.yandex.ru/metrika/tag.js', false)
            ->assertSee('var ID = 98765432;', false)
            ->assertSee("indexOf('livsi_cookie_consent=all')", false)
            ->assertSee("addEventListener('livsi:consent'", false)
            ->assertSee('var WEBVISOR = true;', false)
            ->assertSee('Аналитические файлы (Яндекс Метрика)');
    }

    public function test_paid_order_page_tells_metrika_about_the_purchase_without_personal_data(): void
    {
        AnalyticsSettings::setOptions(['metrika_counter_id' => '98765432']);
        $order = $this->order(null, 175000);

        $html = $this->get(route('order.show', ['order' => $order->number, 'token' => $order->access_token]))->assertOk()
            ->assertSee('purchase', false)->assertSee('revenue: 1750', false)
            ->assertSee('var WEBVISOR = false;', false)->getContent();
        preg_match('#window\.dataLayer\.push\((.+?)\);\s*\}\)\(\);#s', $html, $match);
        $this->assertStringContainsString('LV-F1', $match[1] ?? '');
        $this->assertStringNotContainsString('anna@example.com', $match[1] ?? '');
        $this->assertStringNotContainsString('+79001112233', $match[1] ?? '');
    }

    public function test_privacy_policy_names_yandex_metrika_unless_edited_by_hand(): void
    {
        $migration = require base_path('database/migrations/2026_11_10_120000_privacy_mentions_yandex_metrika.php');
        $page = LegalPage::create(['slug' => 'privacy', 'title' => 'Политика', 'body' => '<h2>7</h2>'.$migration::OLD, 'is_active' => true]);

        $migration->up();
        $this->assertStringContainsString('«Яндекс Метрика»', $page->fresh()->body);
        $this->assertStringContainsString('«Настройки cookie»', $page->fresh()->body);
        $migration->down();
        $this->assertStringContainsString($migration::OLD, $page->fresh()->body);
    }

    // ------------------------------------------------------------ помощники

    private function configureApp(): void
    {
        AnalyticsSettings::setOptions(['oauth_client_id' => self::CLIENT_ID]);
        AnalyticsSettings::setSecrets(['oauth_client_secret' => 'secret-value-123']);
    }

    private function connect(): void
    {
        $this->configureApp();
        AnalyticsSettings::setSecrets(['access_token' => 'token-1', 'refresh_token' => 'refresh-1']);
        AnalyticsSettings::setOptions(['token_expires_at' => now()->addYear()->toIso8601String(), 'metrika_counter_id' => '98765432']);
    }

    /** @param array<string, mixed> $extra */
    private function fakeYandex(array $extra = []): void
    {
        Http::fake($extra + [
            YandexOAuth::TOKEN_URL => Http::response(['access_token' => 'token-1', 'refresh_token' => 'refresh-1', 'expires_in' => 31536000, 'token_type' => 'bearer']),
            'https://api.webmaster.yandex.net/v4/user' => Http::response(['user_id' => 555]),
            'https://api.webmaster.yandex.net/v4/user/555/hosts' => Http::response(['hosts' => [
                ['host_id' => 'http:livsi.shop:80', 'ascii_host_url' => 'http://livsi.shop/', 'verified' => false],
                ['host_id' => 'https:livsi.shop:443', 'ascii_host_url' => 'https://livsi.shop/', 'verified' => true],
                ['host_id' => 'https:livsipro.ru:443', 'ascii_host_url' => 'https://livsipro.ru/', 'verified' => true],
            ]]),
            '*/verification' => Http::response(['verification_uin' => '8cee32ceb06d67e7', 'verification_state' => 'VERIFIED', 'verification_type' => 'DNS']),
            'https://api-metrika.yandex.net/management/v1/counters*' => Http::response(['rows' => 1, 'counters' => [
                ['id' => 98765432, 'name' => 'LIVSI', 'site2' => ['site' => 'livsi.shop'], 'owner_login' => 'livsi', 'code_status' => 'CS_OK'],
            ]]),
        ]);
    }

    /** @param array<string, string>|null $utm */
    private function order(?array $utm, int $total, string $status = Order::STATUS_PAID): Order
    {
        $order = Order::create([
            'number' => 'LV-'.Str::upper(Str::random(6)),
            'customer_name' => 'Анна Петрова', 'customer_phone' => '+79001112233', 'customer_email' => 'anna@example.com',
            'delivery_method' => 'cdek_point', 'delivery_city' => 'Москва',
            'items_total' => $total, 'delivery_price' => 0, 'total' => $total,
            'status' => $status, 'paid_at' => $status === Order::STATUS_AWAITING_PAYMENT ? null : now()->subDay(),
            'consent_at' => now(), 'access_token' => Str::random(48), 'utm' => $utm,
        ]);
        OrderItem::create(['order_id' => $order->id, 'sku' => 'LV-F1', 'title' => 'Мультипенка', 'option_label' => '200 мл',
            'unit_price' => $total, 'quantity' => 1, 'total' => $total]);

        return $order->fresh();
    }
}
