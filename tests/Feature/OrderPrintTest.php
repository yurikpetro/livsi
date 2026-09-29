<?php

namespace Tests\Feature;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderPrintTest extends TestCase
{
    use RefreshDatabase;

    private function order(): Order
    {
        $order = Order::create([
            'number' => 'LV-000123',
            'customer_name' => 'Мария <Соколова>',
            'customer_phone' => '+79990000000',
            'customer_email' => 'maria@example.test',
            'comment' => 'Позвонить перед отправкой',
            'items_total' => 190000,
            'discount_total' => 10000,
            'total' => 180000,
            'status' => Order::STATUS_PAID,
            'consent_at' => now(),
            'paid_at' => now(),
            'access_token' => str_repeat('s', 48),
        ]);

        $order->items()->create([
            'sku' => 'LIVSI-123',
            'title' => 'Крем для рук',
            'option_label' => '200 мл',
            'unit_price' => 95000,
            'quantity' => 2,
            'discount' => 10000,
            'total' => 180000,
            'vat_rate' => 5,
        ]);

        $order->items()->create([
            'sku' => 'LIVSI-GIFT',
            'title' => 'Подарочный крем',
            'unit_price' => 0,
            'quantity' => 1,
            'discount' => 0,
            'total' => 0,
            'is_gift' => true,
        ]);

        $order->payments()->create([
            'idempotence_key' => str_repeat('i', 32),
            'amount' => 180000,
            'status' => Payment::STATUS_SUCCEEDED,
        ]);

        return $order;
    }

    public function test_only_administrator_can_open_printable_order(): void
    {
        $order = $this->order();
        $url = route('admin.orders.print', $order);

        $this->get($url)->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->get($url)->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get($url)->assertOk();
    }

    public function test_print_sheet_uses_order_snapshot_and_hides_access_token(): void
    {
        $order = $this->order();

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get(route('admin.orders.print', $order))
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('LV-000123')
            ->assertSee('LIVSI-123')
            ->assertSee('LIVSI-GIFT')
            ->assertSee('подарок')
            ->assertSee('Крем для рук, 200 мл')
            ->assertSee('1 800', escape: false)
            ->assertSee('Позвонить перед отправкой')
            ->assertSee('Мария &lt;Соколова&gt;', escape: false)
            ->assertDontSee('Мария <Соколова>', escape: false)
            ->assertDontSee($order->access_token);
    }

    public function test_admin_order_pages_link_to_print_sheet(): void
    {
        $order = $this->order();
        $url = route('admin.orders.print', $order);

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get(OrderResource::getUrl('index'))
            ->assertOk()
            ->assertSee($url);

        $this
            ->get(OrderResource::getUrl('edit', ['record' => $order]))
            ->assertOk()
            ->assertSee($url);
    }
}
