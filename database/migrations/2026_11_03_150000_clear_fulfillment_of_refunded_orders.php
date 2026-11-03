<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Заказы, за которые вернули деньги до отгрузки, не должны висеть
 * «собранными» или «ждущими сборки» — в админке, на канбане и у покупателя.
 * Новые возвраты сбрасывают статус доставки сами (RefundService), здесь —
 * те, что вернули раньше. История остаётся в журнале заказа.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('orders')
            ->where('status', 'refunded')
            ->whereIn('fulfillment_status', ['awaiting', 'assembled'])
            ->update(['fulfillment_status' => null, 'fulfillment_status_at' => now()]);
    }

    public function down(): void
    {
        // Данные не восстанавливаем: прежний статус виден в журнале заказа.
    }
};
