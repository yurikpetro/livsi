<?php

use App\Services\CustomerDirectory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Клиенты из того, что уже есть: заказы (по почте, от старых к новым —
 * имя, телефон и город берутся из последнего) и зарегистрированные
 * пользователи без заказов.
 */
return new class extends Migration
{
    public function up(): void
    {
        $directory = app(CustomerDirectory::class);
        DB::table('orders')->whereNotNull('customer_email')->orderBy('id')->chunkById(200, function ($orders) use ($directory): void {
            foreach ($orders as $order) {
                $customer = $directory->remember((string) $order->customer_email, $order->customer_name, $order->customer_phone,
                    $order->delivery_city, $order->user_id, $order->marketing_consent_at);
                if ($customer) {
                    DB::table('orders')->where('id', $order->id)->update(['customer_id' => $customer->id]);
                }
            }
        });
        DB::table('users')->whereNotNull('email')->orderBy('id')->chunkById(200, function ($users) use ($directory): void {
            foreach ($users as $user) {
                $directory->remember((string) $user->email, $user->name, $user->phone ?? null, null, $user->id, null);
            }
        });
    }

    public function down(): void
    {
        DB::table('orders')->update(['customer_id' => null]);
        DB::table('customers')->delete();
    }
};
