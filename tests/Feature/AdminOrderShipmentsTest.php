<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\Setting;
use App\Models\User;
use App\Services\ShipmentService;
use Database\Seeders\CatalogSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AdminOrderShipmentsTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        config(['livsi.apiship.token' => 'secret-test-token', 'livsi.apiship.api_url' => 'https://apiship.example.test/v1',
            'livsi.apiship.active_providers' => ['ozondel', 'cdek', 'yataxi'], 'livsi.apiship.ozon_connection_id' => '40706']);
        Setting::put('delivery_origin_address', 'Ставрополь, улица Ленина, 1');
        Setting::put('delivery_pickup_type', 1);
        Setting::put('delivery_padding_mm', 10);
        Setting::put('delivery_packaging_weight_g', 100);
        SellerProfile::current()->update(['email' => 'sender@example.test', 'phone' => '+79991112233']);
        $this->variant = ProductVariant::firstOrFail();
        $this->variant->update(['dimensions_verified' => true, 'weight_g' => 200,
            'length_mm' => 50, 'width_mm' => 50, 'height_mm' => 150, 'is_active' => false]);
        Http::preventStrayRequests();
    }

    private function order(string $provider = 'ozondel'): Order
    {
        $order = Order::create([
            'number' => 'LV-'.str_pad((string) (Order::count() + 1), 6, '0', STR_PAD_LEFT),
            'customer_name' => 'Тестовый покупатель', 'customer_phone' => '+79990000000',
            'customer_email' => 'customer@example.test', 'items_total' => 30000, 'discount_total' => 0,
            'delivery_price' => 10800, 'total' => 40800, 'status' => Order::STATUS_PAID,
            'consent_at' => now(), 'paid_at' => now(), 'access_token' => str_repeat('s', 48),
            'delivery_method' => ['ozondel' => 'ozon_point', 'cdek' => 'cdek_point', 'yataxi' => 'yandex_point'][$provider],
            'delivery_country_code' => 'RU', 'delivery_city' => 'Ставрополь', 'delivery_region' => 'Ставропольский край',
            'delivery_point_id' => '2381537', 'delivery_point_address' => 'Ставрополь, улица Мира, 2',
            'delivery_quote' => ['test' => true, 'provider' => $provider, 'tariff_id' => 147,
                'carrier_cost' => 10800, 'price' => 10800, 'tariff_name' => 'Тестовый тариф',
                'parcel' => ['weight' => 300, 'length' => 7, 'width' => 7, 'height' => 17]],
        ]);
        $order->items()->create(['product_variant_id' => $this->variant->id, 'sku' => $this->variant->sku,
            'title' => 'Тестовая мульти-пенка', 'unit_price' => 30000, 'quantity' => 1, 'total' => 30000]);

        return $order;
    }

    /** @return array{name: string, phone: string, email: string} */
    private function sender(): array
    {
        return ['name' => 'Анна Отправитель', 'phone' => '8 (999) 111-22-33', 'email' => 'sender@example.test'];
    }

    public function test_admin_creates_ozon_shipment_for_confirmed_inactive_test_variant_from_order_snapshot(): void
    {
        $order = $this->order();
        $before = $order->only(['status', 'items_total', 'total', 'delivery_price']);
        $quota = $this->variant->quota->getAttributes();
        $this->variant->update(['price' => 99900]);
        Http::fake(['*/orders' => Http::response(['orderId' => 123456])]);

        app(ShipmentService::class)->create($order, $this->sender());

        $this->assertSame('123456', $order->fresh()->apiship_order_id);
        $this->assertSame('created', $order->fresh()->shipment_state);
        $this->assertNotNull($order->fresh()->shipment_created_at);
        $this->assertSame('+79991112233', $order->fresh()->shipment_sender['phone']);
        $this->assertSame($before, $order->fresh()->only(array_keys($before)));
        $this->assertSame($quota, $this->variant->quota->fresh()->getAttributes());
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payments', 0);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request['order']['clientNumber'] === $order->number
            && $request['order']['providerKey'] === 'ozondel'
            && $request['order']['providerConnectId'] === 40706 && $request['order']['pickupType'] === 2
            && ! isset($request['order']['pointInId'])
            && $request['order']['pointOutId'] === 2381537 && $request['order']['tariffId'] === 147
            && $request['order']['weight'] === 300 && $request['order']['height'] === 17
            && $request['cost']['codCost'] === 0 && $request['cost']['deliveryCost'] === 0
            && $request['cost']['isDeliveryPayedByRecipient'] === 0
            && $request['cost']['assessedCost'] === 300
            && $request['places'][0]['items'][0]['assessedCost'] === 300
            && $request['places'][0]['items'][0]['cost'] === 0
            && $request['sender']['contactName'] === 'Анна Отправитель'
            && $request['sender']['addressString'] === 'Ставрополь, улица Ленина, 1'
            && $request['recipient']['contactName'] === $order->customer_name);
        Http::assertSentCount(1);
    }

    public function test_normal_paid_order_does_not_need_manual_confirmation(): void
    {
        $order = $this->order();
        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionHidden('retryShipment');
        Livewire::test(ListOrders::class)->assertActionHidden(TestAction::make('retryShipment')->table($order));
        Http::assertNothingSent();
    }

    public function test_repeating_creation_returns_existing_shipment_without_another_request(): void
    {
        $order = $this->order();
        Http::fake(['*/orders' => Http::response(['orderId' => 123456])]);
        $service = app(ShipmentService::class);
        $service->create($order, $this->sender());
        $again = $service->create($order, []);

        $this->assertSame('123456', $again->apiship_order_id);
        Http::assertSentCount(1);
        Livewire::test(ListOrders::class)->assertActionHidden(TestAction::make('retryShipment')->table($order));
    }

    public function test_warehouse_is_configured_once_and_reused_by_sender_defaults(): void
    {
        Livewire::test(Settings::class)
            ->fillForm(['delivery_warehouse_name' => 'Основной склад', 'delivery_sender_phone' => '+79992223344',
                'delivery_origin_address' => 'Ставрополь, улица Складская, 18'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Основной склад', Setting::get('delivery_warehouse_name'));
        $this->assertSame('Ставрополь, улица Складская, 18', Setting::get('delivery_origin_address'));
        $this->assertSame('+79992223344', app(ShipmentService::class)->senderDefaults()['phone']);
        $this->assertSame('+79991112233', SellerProfile::current()->phone);
        Http::assertNothingSent();
    }

    public function test_automatic_shipment_reuses_warehouse_phone_without_confirmation_form(): void
    {
        $order = $this->order();
        Setting::put('delivery_warehouse_name', 'Основной склад');
        Setting::put('delivery_sender_phone', '+79992223344');
        $order->update(['shipment_automatic' => true, 'shipment_state' => 'queued']);
        Http::fake(['*/orders' => Http::response(['orderId' => 123456])]);
        app(ShipmentService::class)->createAutomatically($order);
        Http::assertSent(fn (Request $request): bool => $request['sender']['phone'] === '+79992223344'
            && $request['sender']['addressString'] === 'Ставрополь, улица Ленина, 1');
    }

    public function test_transport_failure_is_recovered_by_status_lookup_without_reposting(): void
    {
        $order = $this->order();
        $creationAttempts = 0;
        Http::fake([
            '*/orders' => function () use (&$creationAttempts): never {
                $creationAttempts++;
                throw new ConnectionException('private token and personal data');
            },
            '*/orders/status*' => Http::response($this->statusResponse($order)),
        ]);
        $this->reject(fn () => app(ShipmentService::class)->create($order, $this->sender()));
        $this->assertSame('unknown', $order->fresh()->shipment_state);
        $this->assertStringNotContainsString('private', $order->fresh()->shipment_error);

        Livewire::test(ListOrders::class)
            ->assertActionHidden(TestAction::make('retryShipment')->table($order))
            ->callAction(TestAction::make('checkShipment')->table($order))
            ->assertNotified('Передан перевозчику');

        $this->assertSame('123456', $order->fresh()->apiship_order_id);
        $this->assertSame('TRACK-123', $order->fresh()->shipment_tracking_number);
        $this->assertSame(1, $creationAttempts);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request['clientNumber'] === $order->number);
    }

    /** @return array<string, mixed> */
    private function statusResponse(Order $order): array
    {
        return ['orderInfo' => ['orderId' => '123456', 'clientNumber' => $order->number,
            'providerKey' => $order->delivery_quote['provider'], 'providerNumber' => 'TRACK-123'],
            'status' => ['key' => 'uploaded', 'name' => 'Передан перевозчику']];
    }

    #[DataProvider('ambiguousResponses')]
    public function test_ambiguous_result_never_allows_automatic_resubmission(int $httpStatus, array $body): void
    {
        $order = $this->order();
        Http::fake(['*/orders' => Http::response($body, $httpStatus)]);
        $this->reject(fn () => app(ShipmentService::class)->create($order, $this->sender()));
        $this->assertSame('unknown', $order->fresh()->shipment_state);
        $this->reject(fn () => app(ShipmentService::class)->create($order, $this->sender()));
        Http::assertSentCount(1);
    }

    public static function ambiguousResponses(): array
    {
        return ['server failure' => [500, []], 'duplicate conflict' => [409, []],
            'missing id' => [200, []], 'invalid id' => [200, ['orderId' => 'invalid']]];
    }

    public function test_an_in_flight_request_cannot_be_created_by_another_click(): void
    {
        $order = $this->order();
        $order->update(['shipment_state' => 'creating', 'shipment_requested_at' => now()]);
        $this->reject(fn () => app(ShipmentService::class)->create($order, $this->sender()));
        Http::assertNothingSent();
    }

    public function test_definite_rejection_can_be_corrected_and_retried(): void
    {
        $order = $this->order();
        Http::fake(['*/orders' => Http::sequence()->push(['message' => 'sensitive details'], 422)->push(['orderId' => 123456])]);
        $this->reject(fn () => app(ShipmentService::class)->create($order, $this->sender()));
        $this->assertSame('failed', $order->fresh()->shipment_state);
        $this->assertStringNotContainsString('sensitive', $order->fresh()->shipment_error);

        app(ShipmentService::class)->create($order, $this->sender());

        $this->assertSame('123456', $order->fresh()->apiship_order_id);
        $this->assertNull($order->fresh()->shipment_error);
        Http::assertSentCount(2);
    }

    public function test_missing_status_keeps_uncertainty_and_does_not_send_a_new_shipment(): void
    {
        $order = $this->order();
        $order->update(['shipment_state' => 'unknown', 'shipment_requested_at' => now()]);
        Http::fake(['*/orders/status*' => Http::response([], 404)]);
        $this->reject(fn () => app(ShipmentService::class)->refresh($order));
        $this->assertSame('unknown', $order->fresh()->shipment_state);
        $this->assertNull($order->fresh()->apiship_order_id);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    #[DataProvider('statusMismatches')]
    public function test_status_of_another_order_or_provider_is_not_attached(string $key, string $value): void
    {
        $order = $this->order();
        $order->update(['shipment_state' => 'created', 'shipment_requested_at' => now(), 'apiship_order_id' => '123456']);
        $status = $this->statusResponse($order);
        $status['orderInfo'][$key] = $value;
        Http::fake(['*/orders/status*' => Http::response($status)]);
        $this->reject(fn () => app(ShipmentService::class)->refresh($order));
        $this->assertSame('123456', $order->fresh()->apiship_order_id);
        Http::assertSentCount(1);
    }

    public static function statusMismatches(): array
    {
        return [['clientNumber', 'another-order'], ['providerKey', 'cdek'], ['orderId', '999999']];
    }

    #[DataProvider('unpaidStatuses')]
    public function test_unpaid_and_refunded_orders_cannot_be_sent(string $status): void
    {
        $order = $this->order();
        $order->update(['status' => $status]);
        $this->reject(fn () => app(ShipmentService::class)->create($order, $this->sender()));
        $this->assertNull($order->fresh()->shipment_state);
        Http::assertNothingSent();
        Livewire::test(ListOrders::class)->set('activeTab', 'all')
            ->assertActionHidden(TestAction::make('retryShipment')->table($order));
    }

    public static function unpaidStatuses(): array
    {
        return [[Order::STATUS_AWAITING_PAYMENT], [Order::STATUS_CANCELLED], [Order::STATUS_REFUNDED]];
    }

    #[DataProvider('invalidPreparation')]
    public function test_incomplete_or_changed_shipping_data_never_reaches_apiship(string $scenario): void
    {
        $order = $this->order();
        match ($scenario) {
            'dimensions' => $this->variant->update(['dimensions_verified' => false]),
            'changed packaging' => Setting::put('delivery_packaging_weight_g', 999),
            'changed size' => $this->variant->update(['height_mm' => 999]),
            'missing variant' => $order->items()->update(['product_variant_id' => null]),
            'origin' => Setting::put('delivery_origin_address', ''),
            'quote' => $order->update(['delivery_quote' => null]),
            'point' => $order->update(['delivery_point_id' => null]),
            'disabled carrier' => config(['livsi.apiship.active_providers' => []]),
            'ozon connection' => config(['livsi.apiship.ozon_connection_id' => null]),
        };
        $this->reject(fn () => app(ShipmentService::class)->create($order, $this->sender()));
        $this->assertNull($order->fresh()->shipment_state);
        Http::assertNothingSent();
    }

    public static function invalidPreparation(): array
    {
        return array_map(fn (string $scenario): array => [$scenario], ['dimensions', 'changed packaging', 'changed size',
            'missing variant', 'origin', 'quote', 'point', 'disabled carrier', 'ozon connection']);
    }

    public function test_sender_phone_is_required_and_client_cannot_override_shipping_address(): void
    {
        $order = $this->order();
        $this->reject(fn () => app(ShipmentService::class)->create($order, [...$this->sender(), 'phone' => '123']));
        Http::assertNothingSent();
        Http::fake(['*/orders' => Http::response(['orderId' => 123456])]);
        app(ShipmentService::class)->create($order, [...$this->sender(), 'address' => 'Подменённый адрес']);
        Http::assertSent(fn (Request $request): bool => $request['sender']['addressString'] === 'Ставрополь, улица Ленина, 1');
    }

    #[DataProvider('otherCarriers')]
    public function test_other_carriers_preserve_their_handoff_method(string $provider, int $pickup): void
    {
        $order = $this->order($provider);
        Setting::put('delivery_pickup_type', $pickup);
        Setting::put('delivery_origin_points', [$provider => '101']);
        Http::fake(['*/orders' => Http::response(['orderId' => 123456])]);
        app(ShipmentService::class)->create($order, $this->sender());
        Http::assertSent(fn (Request $request): bool => $request['order']['providerKey'] === $provider
            && $request['order']['pickupType'] === $pickup && ! isset($request['order']['providerConnectId'])
            && ($pickup === 1 ? ! isset($request['order']['pointInId']) : $request['order']['pointInId'] === 101));
    }

    public static function otherCarriers(): array
    {
        return [['cdek', 1], ['cdek', 2], ['yataxi', 1], ['yataxi', 2]];
    }

    public function test_non_administrator_cannot_create_or_check_shipments(): void
    {
        $order = $this->order();
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        foreach (['create', 'refresh'] as $method) {
            try {
                app(ShipmentService::class)->{$method}($order, $this->sender());
                $this->fail('Доступ покупателя должен быть запрещён.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        Http::assertNothingSent();
    }

    private function reject(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Ожидался отказ до повторной отправки.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }
}
