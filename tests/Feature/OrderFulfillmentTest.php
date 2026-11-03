<?php

namespace Tests\Feature;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Order;
use App\Models\OrderFulfillmentEvent;
use App\Models\User;
use App\Services\DeliverySelection;
use App\Services\FulfillmentService;
use App\Support\Fulfillment;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Статус доставки: ставит менеджер, видит покупатель.
 * Оплата и заявка ApiShip этим статусом не затрагиваются.
 */
class OrderFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    private function order(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'number' => 'LV-'.Str::upper(Str::random(6)),
            'customer_name' => 'Иван',
            'customer_phone' => '+79001112233',
            'customer_email' => 'ivan@example.com',
            'delivery_method' => DeliverySelection::OZON_POINT,
            'items_total' => 30000,
            'total' => 40800,
            'status' => Order::STATUS_PAID,
            'paid_at' => now()->subHour(),
            'consent_at' => now(),
            'access_token' => Str::random(48),
        ], $overrides));
    }

    public function test_paid_order_without_status_waits_for_assembly(): void
    {
        $order = $this->order();

        $this->assertNull($order->fresh()->fulfillment_status);
        $this->assertSame(Fulfillment::AWAITING, Fulfillment::current($order));
        $this->assertSame(Fulfillment::ASSEMBLED, Fulfillment::next($order));
    }

    public function test_unpaid_order_has_no_delivery_status(): void
    {
        $order = $this->order(['status' => Order::STATUS_AWAITING_PAYMENT, 'paid_at' => null]);

        $this->assertNull(Fulfillment::current($order));
        $this->assertNull(Fulfillment::next($order));
        $this->assertNull(Fulfillment::forCustomer($order));
    }

    public function test_courier_order_skips_pickup_point_step(): void
    {
        $order = $this->order(['delivery_method' => DeliverySelection::STAVROPOL_COURIER, 'fulfillment_status' => Fulfillment::SHIPPED]);

        $this->assertNotContains(Fulfillment::AT_PICKUP, Fulfillment::flow($order));
        $this->assertSame(Fulfillment::DELIVERED, Fulfillment::next($order));
    }

    public function test_service_writes_status_and_journal(): void
    {
        $order = $this->order();
        $this->actingAs($this->admin);

        app(FulfillmentService::class)->set($order, Fulfillment::ASSEMBLED, 'Упаковали в пузырчатую плёнку');

        $order->refresh();
        $this->assertSame(Fulfillment::ASSEMBLED, $order->fulfillment_status);
        $this->assertNotNull($order->fulfillment_status_at);
        $this->assertSame(Order::STATUS_PAID, $order->status, 'оплата не меняется');

        $event = OrderFulfillmentEvent::sole();
        $this->assertSame([$order->id, $this->admin->id, Fulfillment::ASSEMBLED, 'manual', 'Упаковали в пузырчатую плёнку'],
            [$event->order_id, $event->user_id, $event->status, $event->source, $event->comment]);
    }

    public function test_repeating_same_status_does_not_duplicate_journal(): void
    {
        $order = $this->order();
        $this->actingAs($this->admin);

        app(FulfillmentService::class)->set($order, Fulfillment::ASSEMBLED);
        app(FulfillmentService::class)->set($order, Fulfillment::ASSEMBLED);

        $this->assertSame(1, OrderFulfillmentEvent::count());
    }

    public function test_service_refuses_unpaid_order(): void
    {
        $order = $this->order(['status' => Order::STATUS_AWAITING_PAYMENT, 'paid_at' => null]);
        $this->actingAs($this->admin);

        $this->expectException(ValidationException::class);

        try {
            app(FulfillmentService::class)->set($order, Fulfillment::ASSEMBLED);
        } finally {
            $this->assertNull($order->fresh()->fulfillment_status);
            $this->assertSame(0, OrderFulfillmentEvent::count());
        }
    }

    public function test_service_refuses_unknown_status(): void
    {
        $this->actingAs($this->admin);

        $this->expectException(ValidationException::class);

        app(FulfillmentService::class)->set($this->order(), 'teleported');
    }

    public function test_manual_change_requires_admin(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        $this->expectException(HttpException::class);

        app(FulfillmentService::class)->set($this->order(), Fulfillment::ASSEMBLED);
    }

    public function test_customer_sees_status_and_timeline(): void
    {
        $order = $this->order(['fulfillment_status' => Fulfillment::SHIPPED, 'fulfillment_status_at' => now(),
            'shipment_tracking_number' => 'OZ123456789']);

        $this->get($order->accessUrl())
            ->assertOk()
            ->assertSee('Статус доставки')
            ->assertSee('Передан в доставку')
            ->assertSee('В пункте выдачи')
            ->assertSee('OZ123456789');
    }

    public function test_customer_does_not_see_tracking_before_shipping(): void
    {
        $order = $this->order(['fulfillment_status' => Fulfillment::ASSEMBLED, 'shipment_tracking_number' => 'OZ123456789']);

        $this->get($order->accessUrl())->assertOk()->assertSee('Заказ собран')->assertDontSee('OZ123456789');
    }

    public function test_unpaid_order_page_has_no_delivery_block(): void
    {
        $order = $this->order(['status' => Order::STATUS_AWAITING_PAYMENT, 'paid_at' => null]);

        $this->get($order->accessUrl())->assertOk()->assertDontSee('Статус доставки');
    }

    public function test_account_lists_delivery_status(): void
    {
        $user = User::factory()->create();
        $this->order(['user_id' => $user->id, 'fulfillment_status' => Fulfillment::AT_PICKUP]);

        $this->actingAs($user)->get(route('account'))->assertOk()->assertSee('Ждёт вас в пункте выдачи');
    }

    public function test_admin_orders_list_and_card_open(): void
    {
        $order = $this->order(['fulfillment_status' => Fulfillment::ASSEMBLED, 'fulfillment_status_at' => now()]);
        $this->order(['status' => Order::STATUS_AWAITING_PAYMENT, 'paid_at' => null]);

        $this->actingAs($this->admin)->get(OrderResource::getUrl('index'))->assertOk()->assertSee('Доставка');
        $this->actingAs($this->admin)->get(OrderResource::getUrl('edit', ['record' => $order]))
            ->assertOk()->assertSee('Статус доставки')->assertSee('Покупатель видит');
    }

    public function test_table_button_moves_order_to_next_step(): void
    {
        $order = $this->order();
        $this->actingAs($this->admin);

        Livewire::test(ListOrders::class)
            ->callAction(TestAction::make('advanceFulfillment')->table($order))
            ->assertHasNoErrors();

        $this->assertSame(Fulfillment::ASSEMBLED, $order->fresh()->fulfillment_status);
    }

    public function test_table_button_hidden_for_unpaid_order(): void
    {
        $order = $this->order(['status' => Order::STATUS_AWAITING_PAYMENT, 'paid_at' => null]);
        $this->actingAs($this->admin);

        Livewire::test(ListOrders::class, ['activeTab' => 'all'])
            ->assertActionHidden(TestAction::make('advanceFulfillment')->table($order));
    }

    public function test_click_on_status_badge_opens_change_and_saves(): void
    {
        $order = $this->order(['fulfillment_status' => Fulfillment::ASSEMBLED]);
        $this->actingAs($this->admin);

        Livewire::test(ListOrders::class)
            ->callAction(TestAction::make('changeFulfillment')->table($order), data: ['status' => Fulfillment::SHIPPED])
            ->assertHasNoActionErrors();

        $this->assertSame(Fulfillment::SHIPPED, $order->fresh()->fulfillment_status);
    }

    public function test_card_action_sets_any_status_with_comment(): void
    {
        $order = $this->order(['fulfillment_status' => Fulfillment::SHIPPED]);
        $this->actingAs($this->admin);

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('changeFulfillment', data: ['status' => Fulfillment::RETURNED, 'comment' => 'Не забрали из ПВЗ'])
            ->assertHasNoActionErrors();

        $this->assertSame(Fulfillment::RETURNED, $order->fresh()->fulfillment_status);
        $this->assertSame('Не забрали из ПВЗ', OrderFulfillmentEvent::sole()->comment);
    }

    public function test_bulk_action_skips_unpaid_orders(): void
    {
        $paid = $this->order();
        $unpaid = $this->order(['status' => Order::STATUS_AWAITING_PAYMENT, 'paid_at' => null]);
        $this->actingAs($this->admin);

        Livewire::test(ListOrders::class, ['activeTab' => 'all'])
            ->selectTableRecords([$paid->getKey(), $unpaid->getKey()])
            ->callAction(TestAction::make('bulkFulfillment')->table()->bulk(), data: ['status' => Fulfillment::ASSEMBLED]);

        $this->assertSame(Fulfillment::ASSEMBLED, $paid->fresh()->fulfillment_status);
        $this->assertNull($unpaid->fresh()->fulfillment_status);
    }

    public function test_delivery_tabs_filter_orders(): void
    {
        $waiting = $this->order();
        $assembled = $this->order(['fulfillment_status' => Fulfillment::ASSEMBLED]);
        $shipped = $this->order(['fulfillment_status' => Fulfillment::AT_PICKUP]);
        $this->actingAs($this->admin);

        Livewire::test(ListOrders::class, ['activeTab' => 'to_assemble'])
            ->assertCanSeeTableRecords([$waiting])->assertCanNotSeeTableRecords([$assembled, $shipped]);
        Livewire::test(ListOrders::class, ['activeTab' => 'assembled'])
            ->assertCanSeeTableRecords([$assembled])->assertCanNotSeeTableRecords([$waiting, $shipped]);
        Livewire::test(ListOrders::class, ['activeTab' => 'in_delivery'])
            ->assertCanSeeTableRecords([$shipped])->assertCanNotSeeTableRecords([$waiting, $assembled]);
    }
}
