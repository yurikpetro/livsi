<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Статус доставки заказа (сборка → передача → выдача) и журнал его смены.
 *
 * Только добавляет: две пустые колонки и новую таблицу. Существующие
 * заказы не меняются — у оплаченных пустой статус читается как «ждёт сборки».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('fulfillment_status', 20)->nullable();
            $table->timestamp('fulfillment_status_at')->nullable();
        });

        Schema::create('order_fulfillment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20);
            $table->string('source', 20)->default('manual');
            $table->string('comment', 500)->nullable();
            $table->timestamps();

            $table->index(['order_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_fulfillment_events');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['fulfillment_status', 'fulfillment_status_at']);
        });
    }
};
