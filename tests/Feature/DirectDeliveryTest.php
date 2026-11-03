<?php

namespace Tests\Feature;

use App\Delivery\Carrier;
use App\Delivery\Carriers\CdekCarrier;
use App\Delivery\Carriers\OzonCarrier;
use App\Delivery\Carriers\YandexCarrier;
use App\Delivery\DeliveryGateway;
use App\Delivery\DeliverySettings;
use App\Delivery\DirectShipmentService;
use App\Delivery\PointSync;
use App\Filament\Pages\DeliveryIntegrations;
use App\Models\DeliveryPoint;
use App\Models\Order;
use App\Models\OrderFulfillmentEvent;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\User;
use App\Services\DeliverySelection;
use App\Support\Fulfillment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Службы доставки напрямую, без ApiShip, и связь с livsipro.
 *
 * Сеть к службам здесь подменена (Http::fake): проверяется, что сайт
 * спрашивает службу правильно и правильно понимает её ответ. Живую связь
 * проверяет на сервере команда delivery:check.
 */
class DirectDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'livsipro-test-token-0123456789-abcdefghijklmnop';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'livsi.livsipro.token' => self::TOKEN,
            'livsi.livsipro.ips' => [],
            'livsi.cdek.test' => true,
            'livsi.yandex_delivery.test' => true,
            'livsi.yandex_delivery.test_token' => 'yandex-test-token',
        ]);
        Setting::put('delivery_direct_origin', ['cdek' => 'STV7', 'yataxi' => 'station-stv', 'ozondel' => '']);
    }

    // ------------------------------------------------------------ помощники

    private function variant(string $sku = 'LV-MF-200', string $barcode = '4870216352462'): ProductVariant
    {
        $product = Product::create(['slug' => 'p-'.Str::lower(Str::random(6)), 'title' => 'Мультипенка', 'is_active' => true, 'sort' => 1]);

        return ProductVariant::create(['product_id' => $product->id, 'sku' => $sku, 'barcode' => $barcode,
            'option_volume' => '200 мл', 'price' => 89000, 'is_default' => true, 'is_active' => true,
            'weight_g' => 250, 'length_mm' => 50, 'width_mm' => 50, 'height_mm' => 180, 'dimensions_verified' => true]);
    }

    private function order(string $provider = 'cdek', array $overrides = [], int $quantity = 2): Order
    {
        $method = ['cdek' => DeliverySelection::CDEK_POINT, 'yataxi' => DeliverySelection::YANDEX_POINT, 'ozondel' => DeliverySelection::OZON_POINT][$provider];
        $order = Order::create(array_merge([
            'number' => 'LV-'.Str::upper(Str::random(6)),
            'customer_name' => 'Анна Петрова', 'customer_phone' => '+79001112233', 'customer_email' => 'anna@example.com',
            'delivery_method' => $method, 'delivery_city' => 'Москва', 'delivery_point_id' => 'MSK305',
            'delivery_point_address' => 'Москва, Тверская, 1',
            'delivery_quote' => ['direct' => true, 'provider' => $provider, 'tariff_id' => '136', 'tariff_name' => 'Посылка склад-склад',
                'carrier_cost' => 35000, 'parcel' => ['weight' => 700, 'length' => 12, 'width' => 12, 'height' => 20]],
            'items_total' => 178000, 'total' => 178000, 'status' => Order::STATUS_PAID, 'paid_at' => now()->subHour(),
            'consent_at' => now(), 'access_token' => Str::random(48),
        ], $overrides));
        $variant = ProductVariant::query()->first() ?? $this->variant();
        OrderItem::create(['order_id' => $order->id, 'product_variant_id' => $variant->id, 'sku' => $variant->sku,
            'title' => 'Мультипенка', 'option_label' => '200 мл', 'unit_price' => 89000, 'quantity' => $quantity,
            'total' => 89000 * $quantity]);

        return $order->fresh();
    }

    private function api(string $method, string $uri, array $data = [])
    {
        return $this->withHeader('Authorization', 'Bearer '.self::TOKEN)->json($method, '/api/livsipro'.$uri, $data);
    }

    private function kiz(string $serial = 'Ab1-2C'): array
    {
        $gtin = '04870216352462';

        return ['gtin' => $gtin, 'serial' => $serial, 'code' => '01'.$gtin.'21'.$serial."\x1D".'93ABCD'];
    }

    // ------------------------------------------------------------- развилка

    public function test_everything_stays_on_apiship_until_switched(): void
    {
        $gateway = app(DeliveryGateway::class);

        foreach (DeliveryGateway::PROVIDERS as $provider) {
            $this->assertFalse($gateway->isDirect($provider));
        }
        $this->assertFalse(DirectShipmentService::isDirectOrder($this->order('cdek', ['delivery_quote' => ['provider' => 'cdek']])));
    }

    public function test_direct_service_appears_only_after_points_are_loaded(): void
    {
        config(['livsi.delivery.drivers.cdek' => 'direct']);
        $gateway = app(DeliveryGateway::class);
        $this->assertFalse($gateway->isProviderActive('cdek'));

        DeliveryPoint::create(['provider' => 'cdek', 'external_id' => 'MSK305', 'address' => 'Москва, Тверская, 1', 'city' => 'Москва',
            'city_key' => 'москва', 'region' => 'Москва', 'region_key' => 'москва', 'is_active' => true]);
        Cache::forget('delivery-points-ready:cdek');

        $this->assertTrue($gateway->isProviderActive('cdek'));
    }

    public function test_map_gets_direct_points_from_own_database_in_old_format(): void
    {
        config(['livsi.delivery.drivers.cdek' => 'direct']);
        DeliveryPoint::create(['provider' => 'cdek', 'external_id' => 'STV12', 'address' => 'Ставрополь, Ленина, 1', 'city' => 'г. Ставрополь',
            'city_key' => 'ставрополь', 'region' => 'Ставропольский край', 'region_key' => 'ставропольский', 'lat' => 45.04, 'lng' => 41.97,
            'timetable' => 'Пн-Пт 10:00-20:00', 'is_active' => true]);
        DeliveryPoint::create(['provider' => 'cdek', 'external_id' => 'STV99', 'address' => 'закрыт', 'city' => 'Ставрополь',
            'city_key' => 'ставрополь', 'region_key' => 'ставропольский', 'is_active' => false]);

        $response = $this->getJson('/delivery/points?method=cdek_point&city=Ставрополь')->assertOk();

        $this->assertSame([['id' => 'STV12', 'name' => '', 'address' => 'Ставрополь, Ленина, 1', 'city' => 'г. Ставрополь',
            'region' => 'Ставропольский край', 'provider' => 'cdek', 'timetable' => 'Пн-Пт 10:00-20:00', 'lat' => 45.04, 'lng' => 41.97]],
            $response->json('points'));
        $this->getJson('/delivery/points?method=cdek_point&city=Ставрополь&region=Ставропольский край')->assertOk()->assertJsonCount(1, 'points');
    }

    // --------------------------------------------------------- синхронизация

    public function test_point_sync_loads_and_closes_points_but_never_wipes_the_map(): void
    {
        $carrier = new class implements Carrier
        {
            public array $points = [];

            public function key(): string
            {
                return 'cdek';
            }

            public function title(): string
            {
                return 'Тест';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function allPoints(): iterable
            {
                yield from $this->points;
            }

            public function tariffs(array $parcel, array $point, array $context): array
            {
                return [];
            }

            public function createShipment(Order $order, array $sender): array
            {
                return [];
            }

            public function findShipment(Order $order): ?array
            {
                return null;
            }

            public function shipmentInfo(Order $order): array
            {
                return [];
            }

            public function label(Order $order, string $size = '75x120'): string
            {
                return '';
            }

            public function cancel(Order $order): void {}
        };
        $row = fn (string $id, string $city = 'Ставрополь') => ['external_id' => $id, 'address' => 'адрес '.$id, 'city' => $city, 'region' => 'Ставропольский край'];
        $carrier->points = [$row('A'), $row('B'), $row('C'), $row('D'), $row('A'), ['external_id' => '', 'address' => 'x']];

        $result = app(PointSync::class)->run($carrier);
        $this->assertSame(['loaded' => 4, 'disabled' => 0, 'skipped' => 2], $result);
        $this->assertSame('ставрополь', DeliveryPoint::firstWhere('external_id', 'A')->city_key);

        $carrier->points = [$row('A'), $row('B'), $row('C')];
        $this->travel(1)->minutes();
        $this->assertSame(1, app(PointSync::class)->run($carrier)['disabled']);
        $this->assertFalse(DeliveryPoint::firstWhere('external_id', 'D')->is_active);

        $carrier->points = [$row('A')];
        $this->travel(1)->minutes();
        try {
            app(PointSync::class)->run($carrier);
            $this->fail('Половина списка пропала — старый список должен остаться');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Старый список оставлен', $exception->getMessage());
        }
        $this->assertSame(3, DeliveryPoint::where('is_active', true)->count());
    }

    // ------------------------------------------------------------------ СДЭК

    public function test_cdek_quote_picks_pvz_tariff_and_adds_insurance(): void
    {
        DeliveryPoint::create(['provider' => 'cdek', 'external_id' => 'MSK305', 'type' => 'pvz', 'address' => 'Москва', 'city' => 'Москва',
            'city_key' => 'москва', 'limits' => ['city_code' => 44, 'weight_max_g' => 30000], 'is_active' => true]);
        Http::fake([
            '*/oauth/token*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/deliverypoints?code=STV7*' => Http::response([['code' => 'STV7', 'location' => ['city_code' => 434]]]),
            '*/calculator/tarifflist' => Http::response(['tariff_codes' => [
                ['tariff_code' => 368, 'tariff_name' => 'Посылка склад-постамат', 'delivery_mode' => 7, 'delivery_sum' => 300.0],
                ['tariff_code' => 136, 'tariff_name' => 'Посылка склад-склад', 'delivery_mode' => 4, 'delivery_sum' => 420.0, 'calendar_min' => 3, 'calendar_max' => 5],
                ['tariff_code' => 139, 'tariff_name' => 'Посылка дверь-дверь', 'delivery_mode' => 1, 'delivery_sum' => 100.0],
            ]]),
            '*/calculator/tariff' => Http::response(['total_sum' => 433.5]),
        ]);

        $tariffs = app(CdekCarrier::class)->tariffs(['weight' => 700, 'length' => 12, 'width' => 12, 'height' => 20],
            ['id' => 'MSK305'], ['assessed' => 178000]);

        $this->assertSame([['tariff_id' => '136', 'tariff_name' => 'Посылка склад-склад', 'cost' => 43350, 'days_min' => 3, 'days_max' => 5]], $tariffs);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/calculator/tarifflist')
            && $request['from_location'] === ['code' => 434] && $request['to_location'] === ['code' => 44] && $request['currency'] === 1);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/calculator/tariff')
            && $request['tariff_code'] === 136 && $request['services'][0] === ['code' => 'INSURANCE', 'parameter' => '1780']);
    }

    public function test_cdek_postamat_rejects_parcel_that_does_not_fit_the_cell(): void
    {
        DeliveryPoint::create(['provider' => 'cdek', 'external_id' => 'MSK900', 'type' => 'postamat', 'address' => 'Москва', 'city' => 'Москва',
            'city_key' => 'москва', 'limits' => ['city_code' => 44, 'cells' => [['width' => 10, 'height' => 10, 'depth' => 40]]], 'is_active' => true]);
        Http::fake();

        $this->expectException(ValidationException::class);
        app(CdekCarrier::class)->tariffs(['weight' => 700, 'length' => 12, 'width' => 12, 'height' => 20], ['id' => 'MSK900'], ['assessed' => 1000]);
    }

    public function test_cdek_order_goes_with_marks_per_unit_and_number_of_the_site(): void
    {
        $order = $this->order('cdek');
        $item = $order->items->first();
        $kiz = $this->kiz();
        $order->marks()->create(['order_item_id' => $item->id, ...$kiz]);
        Http::fake([
            '*/oauth/token*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/orders' => Http::response(['entity' => ['uuid' => 'uuid-1'], 'requests' => [['state' => 'ACCEPTED']]], 202),
            '*/orders/uuid-1' => Http::response(['entity' => ['uuid' => 'uuid-1', 'cdek_number' => '1106207812',
                'statuses' => [['code' => 'ACCEPTED', 'name' => 'Принят', 'date_time' => '2026-11-02T10:00:00+0300'],
                    ['code' => 'CREATED', 'name' => 'Создан', 'date_time' => '2026-11-02T10:00:05+0300']]],
                'requests' => [['state' => 'SUCCESSFUL']]]),
        ]);

        $result = app(CdekCarrier::class)->createShipment($order, ['name' => 'ИП', 'phone' => '+79000000000']);

        $this->assertSame('uuid-1', $result['external_id']);
        $this->assertSame('1106207812', $result['tracking']);
        $this->assertSame('CREATED', $result['status_code']);
        Http::assertSent(function (Request $request) use ($order, $kiz): bool {
            if (! str_ends_with($request->url(), '/orders') || $request->method() !== 'POST') {
                return false;
            }
            $items = $request['packages'][0]['items'];

            return $request['number'] === $order->number && $request['shipment_point'] === 'STV7' && $request['delivery_point'] === 'MSK305'
                && $request['tariff_code'] === 136 && count($items) === 2
                && $items[0]['amount'] === 1 && $items[0]['marking'] === $kiz['code'] && ! isset($items[1]['marking'])
                && $items[0]['payment'] === ['value' => 0] && $request['recipient']['phones'] === [['number' => '+79001112233']];
        });
    }

    public function test_cdek_rejected_order_is_a_clear_error(): void
    {
        Http::fake([
            '*/oauth/token*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/orders' => Http::response(['requests' => [['state' => 'INVALID', 'errors' => [['code' => 'v2_field_is_empty', 'message' => 'Не указан телефон']]]]], 400),
        ]);

        try {
            app(CdekCarrier::class)->createShipment($this->order('cdek'), []);
            $this->fail('Ошибка СДЭК должна дойти до человека');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Не указан телефон', $exception->validator->errors()->first());
        }
    }

    public function test_cdek_statuses_are_sorted_by_time_and_mapped(): void
    {
        $order = $this->order('cdek', ['shipment_provider' => 'cdek', 'shipment_external_id' => 'uuid-1', 'shipment_state' => 'created']);
        Http::fake([
            '*/oauth/token*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/orders/uuid-1' => Http::response(['entity' => ['cdek_number' => '77', 'statuses' => [
                ['code' => 'ACCEPTED_AT_PICK_UP_POINT', 'name' => 'Принят на склад до востребования', 'date_time' => '2026-11-05T12:00:00+0300'],
                ['code' => 'CREATED', 'name' => 'Создан', 'date_time' => '2026-11-02T10:00:00+0300'],
                ['code' => 'DELIVERED', 'name' => 'Вручен', 'date_time' => '2026-11-06T12:00:00+0300', 'deleted' => true],
            ]]]),
        ]);

        $info = app(CdekCarrier::class)->shipmentInfo($order);

        $this->assertSame('ACCEPTED_AT_PICK_UP_POINT', $info['status_code']);
        $this->assertSame(Fulfillment::AT_PICKUP, $info['fulfillment']);
        $this->assertSame('77', $info['tracking']);
    }

    public function test_cdek_label_waits_for_ready_pdf(): void
    {
        $order = $this->order('cdek', ['shipment_provider' => 'cdek', 'shipment_external_id' => 'uuid-1', 'shipment_state' => 'created']);
        Http::fake([
            '*/oauth/token*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/print/barcodes' => Http::response(['entity' => ['uuid' => 'print-1']], 202),
            '*/print/barcodes/print-1' => Http::sequence()
                ->push(['entity' => ['statuses' => [['code' => 'PROCESSING']]]])
                ->push(['entity' => ['statuses' => [['code' => 'READY']], 'url' => 'https://api.edu.cdek.ru/v2/print/barcodes/print-1.pdf']]),
            '*/print/barcodes/print-1.pdf' => Http::response('%PDF-1.4 label', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $this->assertSame('%PDF-1.4 label', app(CdekCarrier::class)->label($order, '58x40'));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/print/barcodes') && $request['format'] === 'A7');
    }

    // ---------------------------------------------------------------- Яндекс

    public function test_yandex_test_mode_requires_its_own_configured_token(): void
    {
        config([
            'livsi.yandex_delivery.test_token' => null,
            'livsi.yandex_delivery.token' => 'production-token',
        ]);

        $this->assertFalse(app(YandexCarrier::class)->isConfigured());

        config(['livsi.yandex_delivery.test_token' => 'yandex-test-token']);

        $this->assertTrue(app(YandexCarrier::class)->isConfigured());
    }

    public function test_yandex_production_mode_uses_the_configured_production_token(): void
    {
        config([
            'livsi.yandex_delivery.test' => false,
            'livsi.yandex_delivery.token' => 'production-token',
        ]);
        Http::preventStrayRequests();
        Http::fake(['*/pricing-calculator' => Http::response(['pricing_total' => '225.7 RUB'])]);

        $this->assertTrue(app(YandexCarrier::class)->isConfigured());
        app(YandexCarrier::class)->tariffs(['weight' => 700, 'length' => 12, 'width' => 12, 'height' => 20],
            ['id' => 'point'], ['assessed' => 178000]);

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://b2b-authproxy.taxi.yandex.net/')
            && $request->hasHeader('Authorization', 'Bearer production-token'));
    }

    public function test_yandex_quote_parses_rubles_string(): void
    {
        Http::fake(['*/pricing-calculator' => Http::response(['pricing_total' => '225.7 RUB', 'delivery_days' => 4])]);

        $tariffs = app(YandexCarrier::class)->tariffs(['weight' => 700, 'length' => 12, 'width' => 12, 'height' => 20],
            ['id' => '0198602de4a6749aba12e151bdf4caaa'], ['assessed' => 178000]);

        $this->assertSame(22570, $tariffs[0]['cost']);
        $this->assertSame(4, $tariffs[0]['days_max']);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://b2b.taxi.tst.yandex.net/')
            && $request['source'] === ['platform_station_id' => 'station-stv'] && $request['tariff'] === 'self_pickup'
            && $request['total_assessed_price'] === 178000 && $request->hasHeader('Authorization', 'Bearer yandex-test-token'));
    }

    public function test_yandex_duplicate_create_finds_existing_request_instead_of_second_order(): void
    {
        $order = $this->order('yataxi', ['delivery_point_id' => '01946f4f013c7337874ec2fb848a58a4']);
        Http::fake([
            '*/request/create*' => Http::response(['code' => 'bad_request', 'message' => 'There already was request with such code within this employer, request_id'], 400),
            '*/request/info*' => Http::response(['request_id' => 'abc-udp', 'state' => ['status' => 'CREATED', 'description' => 'Заказ создан'],
                'sharing_url' => 'https://dostavka.yandex.ru/route/x']),
        ]);

        $result = app(YandexCarrier::class)->createShipment($order, []);

        $this->assertSame('abc-udp', $result['external_id']);
        $this->assertSame('https://dostavka.yandex.ru/route/x', $result['data']['tracking_url']);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'request/info') && str_contains($request->url(), 'request_code='.$order->number));
    }

    public function test_yandex_statuses_map_to_site_statuses(): void
    {
        $order = $this->order('yataxi', ['shipment_provider' => 'yataxi', 'shipment_external_id' => 'abc-udp', 'shipment_state' => 'created']);
        $map = ['SORTING_CENTER_AT_START' => Fulfillment::SHIPPED, 'DELIVERY_ARRIVED_PICKUP_POINT' => Fulfillment::AT_PICKUP,
            'DELIVERY_DELIVERED' => Fulfillment::DELIVERED, 'RETURN_TRANSPORTATION_STARTED' => Fulfillment::RETURNED, 'CREATED' => null, 'CANCELLED' => null];
        $current = null;
        Http::fake(['*/request/info*' => function () use (&$current) {
            return Http::response(['request_id' => 'abc-udp', 'state' => ['status' => $current, 'description' => $current]]);
        }]);
        foreach ($map as $status => $expected) {
            $current = $status;
            $this->assertSame($expected, app(YandexCarrier::class)->shipmentInfo($order)['fulfillment'], $status);
        }
    }

    // ------------------------------------------------------------------ Ozon

    private function connectOzon(): void
    {
        config(['livsi.ozon.client_id' => 'app-id', 'livsi.ozon.client_secret' => 'app-secret']);
        DeliverySettings::setOrigin('ozondel', '123456');
    }

    private function ozonToken(): array
    {
        return ['https://xapi.ozon.ru/oauth/token' => Http::response(['access_token' => 'ozon-token', 'expires_in' => time() + 3600])];
    }

    public function test_ozon_token_is_json_with_scope_array_and_absolute_expiry(): void
    {
        $this->connectOzon();
        Http::fake($this->ozonToken() + ['*/v1/delivery/check-client' => Http::response(['can_be_delivered' => true])]);

        $this->assertTrue(app(OzonCarrier::class)->canDeliverTo('8 900 111-22-33'));
        $this->assertTrue(app(OzonCarrier::class)->canDeliverTo('+79001112233'));

        Http::assertSentCount(3);   // токен один раз, дальше из кэша
        Http::assertSent(fn (Request $request) => $request->url() === 'https://xapi.ozon.ru/oauth/token'
            && $request['grant_type'] === 'client_credentials' && $request['scope'] === ['delivery-api.all'] && $request->isJson());
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/delivery/check-client')
            && $request['phone_number'] === '+79001112233' && $request->header('Authorization') === ['Bearer ozon-token']);
    }

    public function test_ozon_points_come_by_cursor_and_city_is_taken_from_address(): void
    {
        $this->connectOzon();
        Http::fake($this->ozonToken() + [
            '*/v1/delivery-point/list' => Http::sequence()
                ->push(['delivery_points' => [['delivery_point_id' => 1001, 'shipment_method_ids' => [123456]],
                    ['delivery_point_id' => 1002, 'shipment_method_ids' => [999, 123456]], ['delivery_point_id' => 1004, 'shipment_method_ids' => [999]]],
                    'next_cursor' => 'abc=='])
                ->push(['delivery_points' => [['delivery_point_id' => 1003, 'shipment_method_ids' => [123456]]], 'next_cursor' => null]),
            '*/v1/delivery-point/info' => Http::response(['delivery_points' => [
                ['delivery_point_id' => 1001, 'name' => 'Ozon', 'full_address' => '355000, Россия, Ставропольский край, г. Ставрополь, ул. Ленина, 1', 'type' => 'pvz', 'is_active' => true, 'coordinates' => ['latitude' => 45.04, 'longitude' => 41.97]],
                ['delivery_point_id' => 1002, 'name' => 'Ozon', 'full_address' => 'Москва, ул. Тверская, 7', 'type' => 'postamat', 'is_active' => true],
                ['delivery_point_id' => 1003, 'name' => 'Закрыт', 'full_address' => 'Москва, ул. Арбат, 1', 'type' => 'pvz', 'is_active' => false],
            ]]),
        ]);

        $points = iterator_to_array(app(OzonCarrier::class)->allPoints(), false);

        $this->assertCount(2, $points);
        $this->assertSame(['Ставрополь', 'Ставропольский край'], [$points[0]['city'], $points[0]['region']]);
        $this->assertSame(['Москва', 'Москва', 'postamat'], [$points[1]['city'], $points[1]['region'], $points[1]['type']]);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/list') && $request['pagination'] === ['cursor' => null, 'limit' => 100]);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/list') && $request['pagination']['cursor'] === 'abc==');
        // 1004 — туда наш метод отгрузки не возит: его даже не запрашиваем
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/info') && $request['delivery_point_ids'] === [1001, 1002, 1003]);
    }

    public function test_ozon_point_info_is_asked_by_100_at_most(): void
    {
        $this->connectOzon();
        $list = array_map(fn (int $id): array => ['delivery_point_id' => $id, 'shipment_method_ids' => [123456]], range(1, 250));
        Http::fake($this->ozonToken() + [
            '*/v1/delivery-point/list' => Http::response(['delivery_points' => $list, 'next_cursor' => null]),
            '*/v1/delivery-point/info' => Http::response(['delivery_points' => []]),
        ]);

        iterator_to_array(app(OzonCarrier::class)->allPoints(), false);

        $sizes = collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/v1/delivery-point/info'))
            ->map(fn ($pair) => count($pair[0]['delivery_point_ids']))->values()->all();
        $this->assertSame([100, 100, 50], $sizes);   // рабочий Ozon больше 100 за раз не принимает
    }

    public function test_ozon_points_that_ozon_cannot_find_are_skipped_not_fatal(): void
    {
        $this->connectOzon();
        $gone = [3, 7];
        Http::fake($this->ozonToken() + [
            '*/v1/delivery-point/list' => Http::response(['delivery_points' => array_map(fn (int $id): array => ['delivery_point_id' => $id], range(1, 8)), 'next_cursor' => null]),
            '*/v1/delivery-point/info' => function (Request $request) use ($gone) {
                $missing = array_values(array_intersect($request['delivery_point_ids'], $gone));
                if ($missing) {
                    return Http::response(['error' => ['code' => 'NotFound', 'message' => 'Не найдены пункты выдачи: '.implode(',', $missing)]], 404);
                }

                return Http::response(['delivery_points' => array_map(fn (int $id): array => ['delivery_point_id' => $id, 'name' => 'П'.$id,
                    'full_address' => 'Москва, ул. Тверская, '.$id, 'type' => 'pvz', 'is_active' => true], $request['delivery_point_ids'])]);
            },
        ]);

        $points = iterator_to_array(app(OzonCarrier::class)->allPoints(), false);

        $this->assertSame(['1', '2', '4', '5', '6', '8'], array_column($points, 'external_id'));
    }

    public function test_ozon_unreadable_not_found_is_narrowed_down_by_halves(): void
    {
        $this->connectOzon();
        Http::fake($this->ozonToken() + [
            '*/v1/delivery-point/list' => Http::response(['delivery_points' => array_map(fn (int $id): array => ['delivery_point_id' => $id], range(1, 5)), 'next_cursor' => null]),
            '*/v1/delivery-point/info' => function (Request $request) {
                if (in_array(4, $request['delivery_point_ids'], true)) {
                    return Http::response(['error' => ['code' => 'NotFound', 'message' => 'not found']], 404);
                }

                return Http::response(['delivery_points' => array_map(fn (int $id): array => ['delivery_point_id' => $id, 'name' => 'П',
                    'full_address' => 'Москва, ул. Арбат, '.$id, 'type' => 'pvz', 'is_active' => true], $request['delivery_point_ids'])]);
            },
        ]);

        $points = iterator_to_array(app(OzonCarrier::class)->allPoints(), false);

        $this->assertSame(['1', '2', '3', '5'], array_column($points, 'external_id'));
    }

    public function test_ozon_points_are_all_taken_if_ozon_names_no_methods(): void
    {
        $this->connectOzon();
        Http::fake($this->ozonToken() + [
            '*/v1/delivery-point/list' => Http::response(['delivery_points' => [['delivery_point_id' => 1], ['delivery_point_id' => 2]], 'next_cursor' => null]),
            '*/v1/delivery-point/info' => Http::response(['delivery_points' => []]),
        ]);

        iterator_to_array(app(OzonCarrier::class)->allPoints(), false);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/info') && $request['delivery_point_ids'] === [1, 2]);
    }

    public function test_ozon_city_parser_handles_common_address_shapes(): void
    {
        $cases = [
            'Ставропольский край, Ставрополь, проспект Кулакова, 5' => ['Ставрополь', 'Ставропольский край'],
            'Республика Татарстан, г. Казань, ул. Баумана, 1' => ['Казань', 'Республика Татарстан'],
            'Санкт-Петербург, Невский проспект, 1' => ['Санкт-Петербург', 'Санкт-Петербург'],
            'Краснодарский край, станица Ленинградская, ул. Мира, 2' => ['Ленинградская', 'Краснодарский край'],
        ];
        foreach ($cases as $address => $expected) {
            $this->assertSame($expected, OzonCarrier::cityAndRegion($address), $address);
        }
    }

    public function test_ozon_quote_takes_price_from_ozon_with_insurance_and_millimetres(): void
    {
        Carbon::setTestNow('2026-11-02 21:00:00');   // 00:00 по Москве
        $this->connectOzon();
        Http::fake($this->ozonToken() + [
            '*/v1/delivery-point/check-availability' => Http::response(['results' => [['request_id' => 1, 'delivery_point_id' => 1001, 'cutoff_at' => '2026-11-03T12:00:00Z']]]),
            '*/v1/order/checkout' => Http::response(['results' => [['request_id' => 1, 'posting' => [
                'estimated_delivery_cost' => ['amount' => '125.00', 'currency_code' => 'RUB'],
                'estimated_insurance_cost' => ['amount' => '5.50', 'currency_code' => 'RUB'],
                'estimated_delivery_days' => 3, 'cutoff_at' => '2026-11-03T12:00:00Z']]]]),
        ]);

        $tariffs = app(OzonCarrier::class)->tariffs(['weight' => 700, 'length' => 12, 'width' => 12, 'height' => 20], ['id' => '1001'], ['assessed' => 178000]);

        $this->assertSame(13050, $tariffs[0]['cost']);
        $this->assertSame(3, $tariffs[0]['days_max']);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/order/checkout')
            && $request['postings'][0]['dimensions'] === ['weight_g' => 700, 'length_mm' => 120, 'width_mm' => 120, 'height_mm' => 200]
            && $request['postings'][0]['declared_value'] === ['amount' => '1780.00', 'currency_code' => 'RUB']
            && $request['postings'][0]['shipment_method_id'] === 123456 && $request['postings'][0]['cutoff_at'] === '2026-11-03T12:00:00Z'
            && $request['delivery'] === ['delivery_point' => ['delivery_point_id' => 1001]]);
        // в проверку пункта — наш план, а не пустота
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/check-availability')
            && $request['postings'][0]['cutoff_at'] === '2026-11-03T09:00:00Z');
    }

    public function test_ozon_planned_cutoff_is_always_in_the_future(): void
    {
        Carbon::setTestNow('2026-11-03 06:00:00');   // 09:00 по Москве — успеваем сегодня к 12:00
        $this->assertSame('2026-11-03T09:00:00Z', OzonCarrier::plannedCutoff());
        Carbon::setTestNow('2026-11-03 07:30:00');   // 10:30 — уже завтра
        $this->assertSame('2026-11-04T09:00:00Z', OzonCarrier::plannedCutoff());
        Carbon::setTestNow('2026-11-02 21:11:00');   // 00:11 — сегодня к 12:00
        $this->assertSame('2026-11-03T09:00:00Z', OzonCarrier::plannedCutoff());
    }

    public function test_ozon_past_or_empty_cutoff_from_ozon_is_replaced_by_our_plan(): void
    {
        Carbon::setTestNow('2026-11-02 21:11:00');
        $this->connectOzon();
        $returned = '0001-01-01T00:00:00Z';
        Http::fake($this->ozonToken() + [
            '*/v1/delivery-point/check-availability' => function () use (&$returned) {
                return Http::response(['results' => [['request_id' => 1, 'delivery_point_id' => 1001, 'cutoff_at' => $returned]]]);
            },
            '*/v1/order/checkout' => Http::response(['results' => [['request_id' => 1, 'posting' => [
                'estimated_delivery_cost' => ['amount' => '99.00', 'currency_code' => 'RUB'], 'estimated_delivery_days' => 2]]]]),
        ]);
        $parcel = ['weight' => 700, 'length' => 12, 'width' => 12, 'height' => 20];

        foreach (['0001-01-01T00:00:00Z', '2026-11-02T10:00:00Z', null] as $value) {
            $returned = $value;
            app(OzonCarrier::class)->tariffs($parcel, ['id' => '1001'], ['assessed' => 35000]);
        }

        $sent = collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/v1/order/checkout'))
            ->map(fn ($pair) => $pair[0]['postings'][0]['cutoff_at'])->unique()->values()->all();
        $this->assertSame(['2026-11-03T09:00:00Z'], $sent);
    }

    public function test_points_page_says_loading_is_running_instead_of_old_error(): void
    {
        DeliverySettings::recordSync('ozondel', ['error' => 'старая ошибка']);
        DeliverySettings::setRunning('ozondel', true);
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)->test(DeliveryIntegrations::class)->assertSee('идёт загрузка с')->assertDontSee('старая ошибка');

        DeliverySettings::recordSync('ozondel', ['loaded' => 10, 'disabled' => 0, 'skipped' => 0]);
        $this->assertNull(DeliverySettings::runningSince('ozondel'));
    }

    public function test_ozon_item_error_inside_200_is_shown_to_the_buyer(): void
    {
        $this->connectOzon();
        Http::fake($this->ozonToken() + ['*/v1/delivery-point/check-availability' => Http::response(['results' => [['request_id' => 1, 'error' => ['code' => 'WeightLimit', 'message' => 'превышен вес']]]])]);

        try {
            app(OzonCarrier::class)->tariffs(['weight' => 90000, 'length' => 10, 'width' => 10, 'height' => 10], ['id' => '1001'], ['assessed' => 1000]);
            $this->fail('Отказ пункта должен дойти до покупателя');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('превышен вес', $exception->validator->errors()->first());
        }
    }

    public function test_ozon_order_create_repeats_with_the_same_idempotency_key(): void
    {
        Carbon::setTestNow('2026-11-02 21:00:00');
        $this->connectOzon();
        $order = $this->order('ozondel', ['delivery_point_id' => '1001']);
        Http::fake($this->ozonToken() + [
            '*/v1/delivery-point/check-availability' => Http::response(['results' => [['request_id' => 1, 'cutoff_at' => '2026-11-03T12:00:00Z']]]),
            '*/v1/order/create' => Http::response(['order_number' => '777', 'postings' => [['request_id' => 1, 'posting_number' => '777-0001-1']]]),
            '*/v1/posting/approve' => Http::response([]),
        ]);

        $first = app(OzonCarrier::class)->createShipment($order, []);
        $this->assertSame('forming', $first['status_code']);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/posting/approve') && $request->data() === ['posting_number' => '777-0001-1']);
        $again = app(OzonCarrier::class)->findShipment($order);

        $this->assertSame(['777', '777-0001-1'], [$first['external_id'], $first['tracking']]);
        $this->assertSame('777', $again['external_id']);
        $keys = collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/v1/order/create'))
            ->map(fn ($pair) => $pair[0]->header('Idempotency-Key')[0] ?? null)->unique()->values()->all();
        $this->assertCount(1, $keys);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $keys[0]);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/order/create')
            && $request['order_external_id'] === $order->number && $request['recipient'] === ['phone_number' => '+79001112233', 'full_name' => 'Анна Петрова']
            && str_starts_with($request['postings'][0]['description'], 'Косметика LIVSI: Мультипенка, 200 мл ×2'));
    }

    private function ozonOrder(): Order
    {
        return $this->order('ozondel', ['delivery_point_id' => '1001', 'shipment_provider' => 'ozondel', 'shipment_external_id' => '777',
            'shipment_state' => 'created', 'shipment_data' => ['number' => '777', 'postings' => ['777-0001-1']]]);
    }

    public function test_ozon_low_balance_on_create_keeps_the_order_and_says_what_to_do(): void
    {
        Carbon::setTestNow('2026-11-02 21:00:00');
        $this->connectOzon();
        $order = $this->order('ozondel', ['delivery_point_id' => '1001']);
        Http::fake($this->ozonToken() + [
            '*/v1/delivery-point/check-availability' => Http::response(['results' => [['request_id' => 1, 'cutoff_at' => '2026-11-03T12:00:00Z']]]),
            '*/v1/order/create' => Http::response(['order_number' => '777', 'postings' => [['request_id' => 1, 'posting_number' => '777-0001-1']]]),
            '*/v1/posting/approve' => Http::response(['error' => ['code' => 'NotEnoughBalance', 'message' => 'Not enough balance'], 'details' => []], 400),
        ]);

        $result = app(OzonCarrier::class)->createShipment($order, []);

        $this->assertSame(['777', 'created'], [$result['external_id'], $result['status_code']]);
        $this->assertStringContainsString('пополните баланс', $result['status_name']);
        $this->assertStringContainsString('пополните баланс', $result['data']['approve_error']);
    }

    public function test_ozon_statuses_come_from_posting_info_and_map_to_site_statuses(): void
    {
        $this->connectOzon();
        $order = $this->ozonOrder();
        $current = null;
        Http::fake($this->ozonToken() + ['*/v1/posting/info' => function () use (&$current) {
            return Http::response(['postings' => [['posting_number' => '777-0001-1', 'order_number' => '777', 'status' => $current,
                'status_changed_at' => '2026-11-04T09:00:00Z', 'storage_expires_at' => '2026-11-10T20:59:59Z']]]);
        }]);
        $map = ['created' => null, 'forming' => null, 'ready_for_shipping' => null, 'acceptance_in_progress' => Fulfillment::SHIPPED,
            'on_way' => Fulfillment::SHIPPED, 'in_courier_service' => Fulfillment::SHIPPED, 'in_delivery_point' => Fulfillment::AT_PICKUP,
            'delivered' => Fulfillment::DELIVERED, 'canceled' => null, 'not_accepted_to_delivery' => null];
        foreach ($map as $status => $expected) {
            $current = $status;
            $this->assertSame($expected, app(OzonCarrier::class)->shipmentInfo($order)['fulfillment'], $status);
        }

        $current = 'in_delivery_point';
        $info = app(OzonCarrier::class)->shipmentInfo($order);
        $this->assertSame('В пункте выдачи, хранится до 10.11', $info['status_name']);
        $this->assertSame('777-0001-1', $info['tracking']);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/posting/info') && $request->data() === ['posting_numbers' => ['777-0001-1']]);
    }

    public function test_ozon_label_approves_waits_for_ready_and_returns_pdf(): void
    {
        $this->connectOzon();
        Sleep::fake();
        $order = $this->ozonOrder();
        $statuses = ['created', 'forming', 'ready_for_shipping'];
        Http::fake($this->ozonToken() + [
            '*/v1/posting/info' => function () use (&$statuses) {
                return Http::response(['postings' => [['posting_number' => '777-0001-1', 'status' => count($statuses) > 1 ? array_shift($statuses) : $statuses[0]]]]);
            },
            '*/v1/posting/approve' => Http::response([]),
            '*/v1/posting/label' => Http::response("%PDF-1.4\nozon label", 200, ['Content-Type' => 'application/pdf']),
        ]);

        $pdf = app(OzonCarrier::class)->label($order);

        $this->assertStringStartsWith('%PDF', $pdf);
        Sleep::assertSleptTimes(2);   // после подтверждения: «forming», потом «готово»
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/posting/approve') && $request->data() === ['posting_number' => '777-0001-1']);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/posting/label') && $request->data() === ['posting_number' => '777-0001-1']
            && str_contains($request->header('Accept')[0] ?? '', 'application/pdf'));
    }

    public function test_ozon_fresh_posting_not_found_is_approved_after_a_short_wait(): void
    {
        Carbon::setTestNow('2026-11-02 21:00:00');
        $this->connectOzon();
        Sleep::fake();
        $order = $this->order('ozondel', ['delivery_point_id' => '1001']);
        $answers = [404, 404, 200];
        Http::fake($this->ozonToken() + [
            '*/v1/delivery-point/check-availability' => Http::response(['results' => [['request_id' => 1, 'cutoff_at' => '2026-11-03T12:00:00Z']]]),
            '*/v1/order/create' => Http::response(['order_number' => '777', 'postings' => [['request_id' => 1, 'posting_number' => '777-0001-1']]]),
            '*/v1/posting/approve' => function () use (&$answers) {
                $code = array_shift($answers) ?? 200;

                return $code === 200 ? Http::response([]) : Http::response(['message' => 'Posting с номером 777-0001-1 не найден'], 404);
            },
        ]);

        $result = app(OzonCarrier::class)->createShipment($order, []);

        $this->assertSame('forming', $result['status_code']);
        $this->assertArrayNotHasKey('approve_error', $result['data']);
        Sleep::assertSleptTimes(2);
    }

    public function test_ozon_status_is_updated_right_after_the_label(): void
    {
        $this->connectOzon();
        Sleep::fake();
        $order = $this->ozonOrder();
        $order->forceFill(['shipment_status_code' => 'created', 'shipment_status_name' => 'Создано в Ozon, но не подтверждено: HTTP 404'])->save();
        $statuses = ['created', 'ready_for_shipping'];
        Http::fake($this->ozonToken() + [
            '*/v1/posting/info' => function () use (&$statuses) {
                return Http::response(['postings' => [['posting_number' => '777-0001-1', 'status' => count($statuses) > 1 ? array_shift($statuses) : $statuses[0]]]]);
            },
            '*/v1/posting/approve' => Http::response([]),
            '*/v1/posting/label' => Http::response("%PDF-1.4\nozon label", 200, ['Content-Type' => 'application/pdf']),
        ]);

        app(DirectShipmentService::class)->label($order);

        $this->assertSame(['ready_for_shipping', 'Готово к отгрузке — можно сдавать в пункт'],
            [$order->fresh()->shipment_status_code, $order->fresh()->shipment_status_name]);
    }

    public function test_ozon_canceled_after_handover_is_a_return(): void
    {
        $this->connectOzon();
        $order = $this->ozonOrder();
        $order->forceFill(['fulfillment_status' => Fulfillment::AT_PICKUP])->save();
        Http::fake($this->ozonToken() + [
            '*/v1/posting/info' => Http::response(['postings' => [['posting_number' => '777-0001-1', 'status' => 'canceled']]]),
        ]);

        $order = app(DirectShipmentService::class)->sync($order);

        $this->assertSame([Fulfillment::RETURNED, 'Не забрали — Ozon везёт посылку обратно'], [$order->fulfillment_status, $order->shipment_status_name]);
    }

    public function test_ozon_label_with_low_balance_tells_to_top_up(): void
    {
        $this->connectOzon();
        Sleep::fake();
        Http::fake($this->ozonToken() + [
            '*/v1/posting/info' => Http::response(['postings' => [['posting_number' => '777-0001-1', 'status' => 'created']]]),
            '*/v1/posting/approve' => Http::response(['error' => ['code' => 'NotEnoughBalance', 'message' => 'x'], 'details' => []], 400),
        ]);

        try {
            app(OzonCarrier::class)->label($this->ozonOrder());
            $this->fail('Без денег этикетки нет');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('пополните баланс', $exception->validator->errors()->first());
        }
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/v1/posting/label'));
    }

    public function test_ozon_label_still_forming_asks_to_retry_later(): void
    {
        $this->connectOzon();
        Sleep::fake();
        Http::fake($this->ozonToken() + ['*/v1/posting/info' => Http::response(['postings' => [['posting_number' => '777-0001-1', 'status' => 'forming']]])]);

        try {
            app(OzonCarrier::class)->label($this->ozonOrder());
            $this->fail('Пока Ozon готовит этикетку — просим подождать');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('через минуту', $exception->validator->errors()->first());
        }
        Sleep::assertSleptTimes(6);
    }

    public function test_ozon_cancel_goes_per_posting(): void
    {
        $this->connectOzon();
        Http::fake($this->ozonToken() + ['*/v1/posting/cancel' => Http::response([])]);

        app(OzonCarrier::class)->cancel($this->ozonOrder());

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/posting/cancel') && $request->data() === ['posting_number' => '777-0001-1']);
    }

    public function test_ozon_errors_are_explained_by_code(): void
    {
        $this->connectOzon();
        Http::fake($this->ozonToken() + ['*/v1/posting/cancel' => Http::response(['error' => ['code' => 'PostingNotFound', 'message' => 'not found'],
            'details' => [['item_id' => '777-0001-1', 'error' => ['code' => 'OtherError', 'message' => 'Отмена недоступна']]]], 404)]);

        try {
            app(OzonCarrier::class)->cancel($this->ozonOrder());
            $this->fail('Ошибка Ozon должна дойти до человека');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Ozon не нашёл отправление; Отмена недоступна', $exception->getMessage());
        }
    }

    // ------------------------------------------------- отправление и статусы

    public function test_lost_create_response_never_creates_a_second_shipment(): void
    {
        config(['livsi.delivery.drivers.cdek' => 'direct']);
        $order = $this->order('cdek', ['fulfillment_status' => Fulfillment::ASSEMBLED]);
        Http::fake([
            '*/oauth/token*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/orders' => fn () => throw new ConnectionException('timeout'),
        ]);

        try {
            app(DirectShipmentService::class)->create($order, ['name' => 'ИП Волкодав', 'phone' => '+79000000000']);
            $this->fail('Потерянный ответ — не успех');
        } catch (ValidationException) {
        }
        $this->assertSame('unknown', $order->fresh()->shipment_state);

        try {
            app(DirectShipmentService::class)->create($order->fresh(), ['name' => 'ИП Волкодав', 'phone' => '+79000000000']);
            $this->fail('Повторное нажатие не должно слать вторую заявку');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Проверить отправление', $exception->validator->errors()->first());
        }

        Http::fake([
            '*/oauth/token*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/orders?im_number=*' => Http::response(['entity' => ['uuid' => 'found-uuid', 'cdek_number' => '55', 'statuses' => []], 'requests' => [['state' => 'SUCCESSFUL']]]),
            '*/orders/found-uuid' => Http::response(['entity' => ['cdek_number' => '55', 'statuses' => [['code' => 'CREATED', 'name' => 'Создан', 'date_time' => '2026-11-02T10:00:00+0300']]]]),
        ]);
        $order = app(DirectShipmentService::class)->refresh($order->fresh());

        $this->assertSame('found-uuid', $order->shipment_external_id);
        $this->assertSame('created', $order->shipment_state);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/orders'));
    }

    public function test_status_moves_only_forward_and_is_logged_as_the_service(): void
    {
        $order = $this->order('yataxi', ['shipment_provider' => 'yataxi', 'shipment_external_id' => 'abc-udp', 'shipment_state' => 'created',
            'fulfillment_status' => Fulfillment::AT_PICKUP]);
        Http::fake(['*/request/info*' => Http::sequence()
            ->push(['request_id' => 'abc-udp', 'state' => ['status' => 'DELIVERY_TRANSPORTATION', 'description' => 'Заказ выехал']])
            ->push(['request_id' => 'abc-udp', 'state' => ['status' => 'DELIVERY_DELIVERED', 'description' => 'Заказ доставлен получателю']])]);

        $order = app(DirectShipmentService::class)->sync($order);
        $this->assertSame(Fulfillment::AT_PICKUP, $order->fulfillment_status);

        $order = app(DirectShipmentService::class)->sync($order);
        $this->assertSame(Fulfillment::DELIVERED, $order->fulfillment_status);
        $event = OrderFulfillmentEvent::query()->latest('id')->first();
        $this->assertSame(OrderFulfillmentEvent::SOURCE_YANDEX, $event->source);
        $this->assertSame('Яндекс Доставка', $event->sourceLabel());
    }

    public function test_check_command_lists_dropoff_points_and_saves_chosen_one(): void
    {
        Http::fake([
            '*/oauth/token*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/location/cities*' => Http::response([['code' => 434, 'city' => 'Ставрополь']]),
            '*/deliverypoints*' => Http::response([['code' => 'STV12', 'type' => 'PVZ', 'location' => ['address_full' => 'Ставрополь, Доваторцев, 1', 'city' => 'Ставрополь']]]),
        ]);

        $this->artisan('delivery:check', ['provider' => 'cdek', '--origin' => 'STV99'])->assertFailed();
        $this->artisan('delivery:check', ['provider' => 'cdek', '--origin' => 'STV12'])
            ->expectsOutputToContain('STV12  Ставрополь, Доваторцев, 1')
            ->expectsOutputToContain('сохранено: сдаём посылки в STV12')
            ->assertSuccessful();
        $this->assertSame('STV12', Setting::get('delivery_direct_origin')['cdek']);
    }

    public function test_admin_settings_win_over_env_and_keys_are_stored_encrypted(): void
    {
        config(['livsi.cdek.client_id' => 'env-id', 'livsi.cdek.client_secret' => 'env-secret', 'livsi.delivery.drivers.cdek' => 'apiship']);
        $this->assertSame('env-id', DeliverySettings::credential('cdek', 'client_id'));
        $this->assertSame('env', DeliverySettings::credentialSource('cdek', 'client_id'));

        DeliverySettings::setCredentials('cdek', ['client_id' => 'admin-id', 'client_secret' => '']);
        $this->assertSame('admin-id', DeliverySettings::credential('cdek', 'client_id'));
        $this->assertSame('env-secret', DeliverySettings::credential('cdek', 'client_secret'));
        $this->assertStringNotContainsString('admin-id', json_encode(Setting::query()->find('delivery_credentials')->value));

        DeliverySettings::setDriver('cdek', 'direct');
        $this->assertTrue(app(DeliveryGateway::class)->isDirect('cdek'));
    }

    public function test_integrations_page_refuses_direct_until_service_is_ready(): void
    {
        Http::fake(['*' => Http::response([], 500)]);
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        config(['livsi.cdek.test' => false, 'livsi.cdek.client_id' => null, 'livsi.cdek.client_secret' => null]);

        Livewire::test(DeliveryIntegrations::class)
            ->set('data.cdek.direct', true)
            ->call('save')
            ->assertNotified('Сохранено, но не всё включено');
        $this->assertFalse(app(DeliveryGateway::class)->isDirect('cdek'));

        DeliveryPoint::create(['provider' => 'cdek', 'external_id' => 'MSK305', 'address' => 'Москва', 'city' => 'Москва', 'city_key' => 'москва', 'is_active' => true]);
        Livewire::test(DeliveryIntegrations::class)
            ->set('data.cdek.keys.client_id', 'id-from-admin')
            ->set('data.cdek.keys.client_secret', 'secret-from-admin')
            ->set('data.cdek.origin', 'STV7')
            ->set('data.cdek.direct', true)
            ->call('save')
            ->assertNotified('Интеграции сохранены');

        $this->assertTrue(app(DeliveryGateway::class)->isDirect('cdek'));
        $this->assertSame('secret-from-admin', DeliverySettings::credential('cdek', 'client_secret'));
        $this->assertSame('STV7', DeliverySettings::origin('cdek'));
    }

    public function test_sync_button_leaves_a_request_for_the_scheduler(): void
    {
        DeliverySettings::requestSync('yataxi');
        $this->assertTrue(DeliverySettings::syncRequested('yataxi'));
        $this->assertSame(['yataxi'], DeliverySettings::takeSyncRequests());
        $this->assertSame([], DeliverySettings::takeSyncRequests());
    }

    // --------------------------------------------------------------- livsipro

    public function test_livsipro_door_is_closed_without_token_or_from_other_address(): void
    {
        $this->getJson('/api/livsipro/orders')->assertStatus(401);
        $this->withHeader('Authorization', 'Bearer wrong')->getJson('/api/livsipro/orders')->assertStatus(401);

        config(['livsi.livsipro.ips' => ['178.208.88.186']]);
        $this->api('GET', '/orders')->assertStatus(403);

        config(['livsi.livsipro.token' => '']);
        $this->api('GET', '/orders')->assertStatus(503);
    }

    public function test_livsipro_sees_paid_orders_without_customer_contacts(): void
    {
        $paid = $this->order('cdek');
        $this->order('cdek', ['status' => Order::STATUS_AWAITING_PAYMENT, 'paid_at' => null]);

        $response = $this->api('GET', '/orders')->assertOk();

        $this->assertSame([$paid->number], array_column($response->json('orders'), 'number'));
        $this->assertSame(1, $response->json('counts.awaiting'));
        $this->assertStringNotContainsString('79001112233', $response->getContent());
        $this->assertStringNotContainsString('anna@example.com', $response->getContent());
        $this->api('GET', '/orders/'.$paid->number)->assertOk()->assertJsonPath('order.items.0.gtin', '04870216352462');
    }

    public function test_marks_are_checked_against_product_quantity_and_other_orders(): void
    {
        $order = $this->order('cdek', [], 1);
        $item = $order->items->first();

        $this->api('POST', '/orders/'.$order->number.'/marks', ['item_id' => $item->id, 'by' => 'Мария', ...$this->kiz()])
            ->assertOk()->assertJsonPath('order.items.0.marks.0.serial', 'Ab1-2C');
        $this->api('POST', '/orders/'.$order->number.'/marks', ['item_id' => $item->id, 'by' => 'Мария', ...$this->kiz('Zz9')])
            ->assertStatus(422)->assertJsonFragment(['order' => ['По «Мультипенка, 200 мл» уже отсканировано 1 шт. — больше не нужно.']]);

        $other = $this->order('cdek', [], 1);
        $this->api('POST', '/orders/'.$other->number.'/marks', ['item_id' => $other->items->first()->id, 'by' => 'Мария', ...$this->kiz()])
            ->assertStatus(422)->assertJsonFragment(['order' => ['Этот код уже ушёл в заказ '.$order->number.'. Возьмите другой экземпляр.']]);

        $wrong = ['gtin' => '04600000000000', 'serial' => 'X1', 'code' => '0104600000000000'.'21X1'."\x1D".'93AAAA'];
        $this->api('POST', '/orders/'.$other->number.'/marks', ['item_id' => $other->items->first()->id, 'by' => 'Мария', ...$wrong])
            ->assertStatus(422)->assertJsonFragment(['order' => ['Это код другого товара: в заказе «Мультипенка, 200 мл».']]);
    }

    public function test_assembled_requires_all_marks_then_shipped_requires_shipment(): void
    {
        $order = $this->order('cdek', [], 1);
        $this->api('POST', '/orders/'.$order->number.'/assembled', ['by' => 'Мария'])->assertStatus(422);

        $order->marks()->create(['order_item_id' => $order->items->first()->id, ...$this->kiz()]);
        $this->api('POST', '/orders/'.$order->number.'/assembled', ['by' => 'Мария'])->assertOk()
            ->assertJsonPath('order.fulfillment.code', Fulfillment::ASSEMBLED);
        $this->assertSame('Сборщик: Мария', OrderFulfillmentEvent::query()->latest('id')->first()->comment);
        $this->assertSame('livsipro.ru', OrderFulfillmentEvent::query()->latest('id')->first()->sourceLabel());

        $this->api('POST', '/orders/'.$order->number.'/shipped', ['by' => 'Мария'])->assertStatus(422);

        $order->forceFill(['shipment_provider' => 'cdek', 'shipment_external_id' => 'uuid-9', 'shipment_state' => 'created'])->save();
        $this->api('POST', '/orders/'.$order->number.'/shipped', ['by' => 'Мария'])->assertOk()
            ->assertJsonPath('order.fulfillment.code', Fulfillment::SHIPPED);
        $this->api('POST', '/orders/'.$order->number.'/marks', ['item_id' => $order->items->first()->id, 'by' => 'Мария', ...$this->kiz('Q1')])
            ->assertStatus(422);
    }

    public function test_apiship_orders_are_not_sent_to_services_from_livsipro(): void
    {
        $order = $this->order('cdek', ['delivery_quote' => ['provider' => 'cdek', 'tariff_id' => 136], 'fulfillment_status' => Fulfillment::ASSEMBLED]);

        $this->api('POST', '/orders/'.$order->number.'/shipment', ['by' => 'Мария'])->assertStatus(422)
            ->assertJsonFragment(['order' => ['Заказ оформлен через ApiShip — отправление создаётся в админке сайта.']]);
    }

    public function test_console_mode_switch_is_the_same_switch_as_in_admin(): void
    {
        $this->artisan('delivery:mode', ['provider' => 'cdek', 'mode' => 'direct'])
            ->expectsOutputToContain('СДЭК: напрямую')->assertSuccessful();
        $this->assertSame('direct', DeliverySettings::driver('cdek'));
        $this->assertSame('apiship', DeliverySettings::driver('yataxi'));

        $this->artisan('delivery:mode', ['provider' => 'cdek', 'mode' => 'fast'])->assertFailed();
        $this->assertSame('direct', DeliverySettings::driver('cdek'));

        $this->artisan('delivery:mode', ['provider' => 'cdek', 'mode' => 'apiship'])->assertSuccessful();
        $this->assertSame('apiship', DeliverySettings::driver('cdek'));
    }

    public function test_console_saves_ozon_shipment_method_only_as_a_number(): void
    {
        config(['livsi.ozon.client_id' => 'app-id', 'livsi.ozon.client_secret' => 'app-secret']);
        Http::fake(['*' => Http::response(['access_token' => 't', 'expires_in' => now()->addHour()->timestamp])]);

        $this->artisan('delivery:check', ['provider' => 'ozondel', '--origin' => 'склад'])
            ->expectsOutputToContain('это число')->assertFailed();
        $this->assertNull(DeliverySettings::origin('ozondel'));

        $this->artisan('delivery:check', ['provider' => 'ozondel', '--origin' => '1020000123'])
            ->expectsOutputToContain('метод отгрузки в настройках: 1020000123')->assertSuccessful();
        $this->assertSame('1020000123', DeliverySettings::origin('ozondel'));
    }

    public function test_ozon_check_really_asks_ozon_and_reports_wrong_keys(): void
    {
        config(['livsi.ozon.client_id' => 'app-id', 'livsi.ozon.client_secret' => 'wrong']);
        $keysOk = false;
        Http::fake(function (Request $request) use (&$keysOk) {
            if (str_contains($request->url(), 'xapi.ozon.ru')) {
                return $keysOk
                    ? Http::response(['access_token' => 't', 'expires_in' => now()->addHour()->timestamp])
                    : Http::response(['error' => 'invalid_client'], 401);
            }

            return Http::response(['delivery_point_ids' => [1], 'next_cursor' => 'x']);
        });

        $this->artisan('delivery:check', ['provider' => 'ozondel'])
            ->expectsOutputToContain('Ozon не принял ключи приложения')->assertFailed();

        $keysOk = true;
        $this->artisan('delivery:check', ['provider' => 'ozondel'])
            ->expectsOutputToContain('служба приняла ключи')->assertSuccessful();
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/delivery-point/list')
            && $request['pagination']['limit'] === 1);
    }
}
