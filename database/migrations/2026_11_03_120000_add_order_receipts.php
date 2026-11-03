<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Второй чек и возвраты.
 *
 * order_receipts — денежные и кассовые операции после оплаты:
 *   closing — чек «полный расчёт» (зачёт предоплаты) с КИЗ, когда покупатель
 *             получил заказ; external_id — номер чека в ЮKassa;
 *   refund  — возврат денег по заказу; external_id — номер возврата в ЮKassa.
 * По одной записи каждого вида на заказ: повтор не создаст второй чек.
 *
 * orders.shipment_attempt — какая по счёту заявка в службу. После отмены
 * отправления следующая уходит с номером «LV-…-2»: службы помнят старый
 * номер и иначе вернули бы отменённую заявку.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('status', 30)->default('new');
            $table->string('external_id', 100)->nullable();
            $table->string('idempotence_key', 64);
            $table->unsignedInteger('amount')->default(0);
            $table->json('payload')->nullable();
            $table->json('response')->nullable();
            $table->string('error', 500)->nullable();
            $table->string('created_by', 120)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'kind']);
            $table->index(['kind', 'status']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedSmallInteger('shipment_attempt')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('shipment_attempt');
        });
        Schema::dropIfExists('order_receipts');
    }
};
