<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Заявки покупателей на возврат товара после получения.
 *
 * Путь заявки: покупатель просит → мы подтверждаем (и оформляем обратную
 * отправку) или отказываем → товар пришёл → деньги вернулись.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 40)->unique();
            $table->string('status', 20)->index();
            $table->string('reason', 20);
            $table->text('comment')->nullable();
            $table->json('items');
            $table->json('photos')->nullable();
            $table->unsignedInteger('goods_amount')->default(0);
            $table->boolean('partial')->default(false);

            // Решение магазина.
            $table->string('method', 20)->nullable();
            $table->string('shipping_payer', 10)->nullable();
            $table->unsignedInteger('shipping_cost')->default(0);
            $table->boolean('refund_delivery')->default(false);
            $table->text('instructions')->nullable();
            $table->text('reject_reason')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decided_by', 120)->nullable();

            // Обратная накладная СДЭК: покупатель сдаёт посылку в пункт СДЭК.
            $table->string('cdek_point', 40)->nullable();
            $table->string('cdek_point_address')->nullable();
            $table->string('shipment_state', 20)->nullable();
            $table->string('shipment_uuid', 64)->nullable();
            $table->string('shipment_number', 40)->nullable();
            $table->string('shipment_status_code', 60)->nullable();
            $table->string('shipment_status_name')->nullable();
            $table->string('shipment_error')->nullable();
            $table->timestamp('shipment_checked_at')->nullable();
            $table->timestamp('arrived_notified_at')->nullable();

            // Приёмка и деньги.
            $table->timestamp('received_at')->nullable();
            $table->string('received_by', 120)->nullable();
            $table->boolean('restocked')->default(false);
            $table->text('receive_note')->nullable();
            $table->unsignedInteger('refund_amount')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->string('refund_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_returns');
    }
};
