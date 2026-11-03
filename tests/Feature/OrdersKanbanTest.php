<?php

namespace Tests\Feature;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Order;
use App\Models\User;
use App\Services\DeliverySelection;
use App\Support\Fulfillment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrdersKanbanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Http::preventStrayRequests();
    }

    public function test_switching_views_preserves_delivery_columns_and_remembers_the_choice(): void
    {
        $orders = [];
        foreach (Fulfillment::statuses() as $status => $label) {
            $orders[$status] = $this->order(['fulfillment_status' => $status]);
        }
        $legacy = $this->order();

        $page = Livewire::test(ListOrders::class)
            ->assertSet('displayMode', 'table')
            ->set('activeTab', 'to_assemble')
            ->call('setDisplayMode', 'kanban')
            ->assertSet('activeTab', 'paid')
            ->assertSee('Заказы по статусам доставки');
        $columns = $page->instance()->getKanbanColumns();
        $this->assertSame(array_keys(Fulfillment::statuses()), array_keys($columns));
        foreach ($orders as $status => $order) {
            $this->assertContains($order->id, $columns[$status]['records']->modelKeys());
        }
        $this->assertContains($legacy->id, $columns[Fulfillment::AWAITING]['records']->modelKeys());
        $this->assertSame('kanban', session('orders.layout'));
        Livewire::test(ListOrders::class)->assertSet('displayMode', 'kanban')
            ->call('setDisplayMode', 'table')->assertCanSeeTableRecords(array_values($orders));
        $this->assertSame('table', session('orders.layout'));
    }

    public function test_url_overrides_saved_view_and_unknown_views_fall_back_to_table(): void
    {
        session()->put('orders.layout', 'table');
        $this->get('/admin/orders?view=kanban')->assertOk()->assertSee('Заказы по статусам доставки');
        session()->put('orders.layout', 'kanban');
        $this->get('/admin/orders?view=table')->assertOk()->assertDontSee('Заказы по статусам доставки');
        $this->get('/admin/orders?view=unknown')->assertOk()->assertDontSee('Заказы по статусам доставки');
        Livewire::test(ListOrders::class)->call('setDisplayMode', 'unknown')->assertStatus(422);
        $this->assertSame('kanban', session('orders.layout'));
    }

    public function test_search_and_financial_filter_apply_immediately_to_board_and_table(): void
    {
        $paid = $this->order(['customer_name' => 'Нужный покупатель']);
        $returned = $this->order(['customer_name' => 'Нужный возврат', 'status' => Order::STATUS_REFUNDED, 'fulfillment_status' => Fulfillment::RETURNED]);
        $this->order(['customer_name' => 'Другой покупатель']);

        Livewire::test(ListOrders::class)->set('activeTab', 'all')->call('setDisplayMode', 'kanban')
            ->searchTable('Нужный')->assertSee($paid->customer_name)->assertSee($returned->customer_name)
            ->assertDontSee('Другой покупатель')
            ->set('tableFilters.status.values', [Order::STATUS_REFUNDED])
            ->assertSee($returned->customer_name)->assertDontSee($paid->customer_name)
            ->assertDontSee('Применить фильтры')
            ->call('setDisplayMode', 'table')->assertCanSeeTableRecords([$returned])->assertCanNotSeeTableRecords([$paid]);
    }

    public function test_unpaid_cancelled_and_refunded_without_delivery_status_are_not_on_the_board(): void
    {
        foreach ([Order::STATUS_AWAITING_PAYMENT, Order::STATUS_CANCELLED, Order::STATUS_REFUNDED] as $status) {
            $this->order(['customer_name' => 'Скрытый '.$status, 'status' => $status]);
        }
        $this->order(['customer_name' => 'Неоплаченный со статусом', 'status' => Order::STATUS_AWAITING_PAYMENT, 'fulfillment_status' => Fulfillment::SHIPPED]);
        $page = Livewire::test(ListOrders::class)->set('activeTab', 'all')->call('setDisplayMode', 'kanban')
            ->assertDontSee('Скрытый')->assertDontSee('Неоплаченный со статусом')->assertSee('Нет заказов');
        $this->assertSame(0, collect($page->instance()->getKanbanColumns())->sum('total'));
    }

    public function test_delivery_filter_is_only_available_in_table_and_cannot_hide_kanban_cards(): void
    {
        $waiting = $this->order();
        $shipped = $this->order(['fulfillment_status' => Fulfillment::SHIPPED]);
        $page = Livewire::test(ListOrders::class)
            ->set('tableFilters.fulfillment.values', [Fulfillment::AWAITING])
            ->assertCanSeeTableRecords([$waiting])->assertCanNotSeeTableRecords([$shipped])
            ->call('setDisplayMode', 'kanban')
            ->assertSee($waiting->number)->assertSee($shipped->number)
            ->assertFormFieldDoesNotExist('fulfillment.values', 'tableFiltersForm')
            ->assertFormFieldIsVisible('status.values', 'tableFiltersForm');
        $this->assertArrayNotHasKey('fulfillment', $page->instance()->getTable()->getFilters());
        $page->call('setDisplayMode', 'table')
            ->assertFormFieldIsVisible('fulfillment.values', 'tableFiltersForm')
            ->assertCanSeeTableRecords([$waiting])->assertCanNotSeeTableRecords([$shipped]);
    }

    public function test_each_column_loads_twenty_newest_orders_and_then_more(): void
    {
        $old = $this->order(['customer_name' => 'Самый старый', 'created_at' => now()->subDay()]);
        for ($index = 0; $index < 20; $index++) {
            $this->order();
        }
        $page = Livewire::test(ListOrders::class)->call('setDisplayMode', 'kanban')
            ->assertDontSee($old->customer_name)->assertSee('Показать ещё');
        $this->assertSame(21 * 35000, $page->instance()->getKanbanColumns()[Fulfillment::AWAITING]['amount']);
        $page
            ->call('loadMoreKanbanRecords', Fulfillment::AWAITING)
            ->assertSee($old->customer_name)->assertDontSee('Показать ещё');
    }

    public function test_stage_amounts_follow_search_and_financial_filters_and_include_empty_columns(): void
    {
        $this->order(['customer_name' => 'Нужный покупатель', 'total' => 12345]);
        $this->order(['customer_name' => 'Другой покупатель', 'total' => 20000]);
        $this->order(['customer_name' => 'Нужный возврат', 'total' => 6789, 'status' => Order::STATUS_REFUNDED, 'fulfillment_status' => Fulfillment::RETURNED]);
        $this->order(['total' => 90000, 'status' => Order::STATUS_CANCELLED]);
        $page = Livewire::test(ListOrders::class)->set('activeTab', 'all')->call('setDisplayMode', 'kanban');
        $columns = $page->instance()->getKanbanColumns();
        $this->assertSame(32345, $columns[Fulfillment::AWAITING]['amount']);
        $this->assertSame(6789, $columns[Fulfillment::RETURNED]['amount']);
        $this->assertSame(0, $columns[Fulfillment::SHIPPED]['amount']);
        $page->searchTable('Нужный');
        $this->assertSame(12345, $page->instance()->getKanbanColumns()[Fulfillment::AWAITING]['amount']);
        $page->set('tableFilters.status.values', [Order::STATUS_PAID]);
        $this->assertSame(0, $page->instance()->getKanbanColumns()[Fulfillment::RETURNED]['amount']);
    }

    /** @return array<string, array{string}> */
    public static function deliveryStatuses(): array
    {
        return array_map(fn (string $status): array => [$status], array_combine(array_keys(Fulfillment::statuses()), array_keys(Fulfillment::statuses())));
    }

    #[DataProvider('deliveryStatuses')]
    public function test_moving_card_updates_delivery_journal_and_leaves_payment_and_shipment_unchanged(string $status): void
    {
        $order = $this->order(['apiship_order_id' => '123', 'shipment_state' => 'created']);
        $before = $order->only(['status', 'total', 'apiship_order_id', 'shipment_state']);
        $paidAt = $order->paid_at->toIso8601String();
        $page = Livewire::test(ListOrders::class)->call('setDisplayMode', 'kanban')
            ->call('moveSortedKanbanRecord', $order->id, 0, $status)->assertHasNoErrors();

        $this->assertSame($before, $order->fresh()->only(array_keys($before)));
        $this->assertSame($paidAt, $order->fresh()->paid_at->toIso8601String());
        $this->assertContains($order->id, $page->instance()->getKanbanColumns()[$status]['records']->modelKeys());
        $this->assertSame(35000, $page->instance()->getKanbanColumns()[$status]['amount']);
        if ($status !== Fulfillment::AWAITING) {
            $this->assertSame(0, $page->instance()->getKanbanColumns()[Fulfillment::AWAITING]['amount']);
        }
        if ($status !== Fulfillment::AWAITING) {
            $this->assertDatabaseHas('order_fulfillment_events', ['order_id' => $order->id, 'status' => $status, 'user_id' => auth()->id(), 'source' => 'manual']);
        } else {
            $this->assertDatabaseCount('order_fulfillment_events', 0);
        }
        Http::assertNothingSent();
    }

    public function test_repeated_move_does_not_duplicate_journal_and_persists_after_reopening(): void
    {
        $order = $this->order();
        Livewire::test(ListOrders::class)->call('setDisplayMode', 'kanban')
            ->call('moveKanbanRecord', $order->id, Fulfillment::ASSEMBLED)
            ->call('moveKanbanRecord', $order->id, Fulfillment::ASSEMBLED)->assertHasNoErrors();
        $this->assertDatabaseCount('order_fulfillment_events', 1);
        $page = Livewire::test(ListOrders::class);
        $this->assertSame([$order->id], $page->instance()->getKanbanColumns()[Fulfillment::ASSEMBLED]['records']->modelKeys());
        $page->call('setDisplayMode', 'table')->assertCanSeeTableRecords([$order]);
    }

    public function test_invalid_status_and_courier_pickup_destination_are_rejected(): void
    {
        $order = $this->order(['delivery_method' => DeliverySelection::STAVROPOL_COURIER]);
        Livewire::test(ListOrders::class)->call('moveKanbanRecord', $order->id, 'unknown')->assertHasErrors(['fulfillment_status']);
        Livewire::test(ListOrders::class)->call('moveKanbanRecord', $order->id, Fulfillment::AT_PICKUP)->assertHasErrors(['fulfillment_status']);
        $this->assertNull($order->fresh()->fulfillment_status);
        $this->assertDatabaseCount('order_fulfillment_events', 0);
    }

    public function test_unpaid_order_cannot_be_moved_by_direct_action(): void
    {
        $order = $this->order(['status' => Order::STATUS_AWAITING_PAYMENT]);
        Livewire::test(ListOrders::class)->call('moveKanbanRecord', $order->id, Fulfillment::SHIPPED)->assertHasErrors(['fulfillment_status']);
        $this->assertNull($order->fresh()->fulfillment_status);
        $this->assertDatabaseCount('order_fulfillment_events', 0);
    }

    public function test_edit_policy_is_checked_before_moving_order(): void
    {
        Gate::policy(Order::class, KanbanOrderPolicy::class);
        $order = $this->order();
        Livewire::test(ListOrders::class)->call('moveKanbanRecord', $order->id, Fulfillment::SHIPPED)->assertForbidden();
        $this->assertNull($order->fresh()->fulfillment_status);
    }

    public function test_board_is_only_accessible_to_administrators(): void
    {
        $order = $this->order();
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->get('/admin/orders?view=kanban')->assertForbidden();
        Livewire::test(ListOrders::class)->call('moveKanbanRecord', $order->id, Fulfillment::SHIPPED)->assertForbidden();
        auth()->logout();
        $this->get('/admin/orders?view=kanban')->assertRedirect('/admin/login');
        $this->assertDatabaseCount('order_fulfillment_events', 0);
    }

    public function test_customer_fields_are_escaped_and_order_card_opens_existing_order(): void
    {
        $order = $this->order(['customer_name' => '<script>alert(1)</script>', 'shipment_tracking_number' => '<img src=x onerror=alert(2)>']);
        Livewire::test(ListOrders::class)->call('setDisplayMode', 'kanban')
            ->assertSee($order->customer_name)->assertDontSee($order->customer_name, false)
            ->assertSee($order->shipment_tracking_number)->assertDontSee($order->shipment_tracking_number, false)
            ->assertSee(OrderResource::getUrl('edit', ['record' => $order]), false);
    }

    /** @param array<string, mixed> $attributes */
    private function order(array $attributes = []): Order
    {
        return Order::create(array_merge([
            'number' => 'LV-'.str_pad((string) (Order::count() + 1), 6, '0', STR_PAD_LEFT),
            'customer_name' => 'Покупатель', 'customer_phone' => '+79990000000', 'customer_email' => 'buyer@example.test',
            'items_total' => 35000, 'total' => 35000, 'status' => Order::STATUS_PAID,
            'consent_at' => now(), 'paid_at' => now(), 'access_token' => fake()->unique()->sha256(),
            'delivery_method' => DeliverySelection::OZON_POINT,
        ], $attributes));
    }
}

class KanbanOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function update(User $user, Order $order): bool
    {
        return false;
    }
}
