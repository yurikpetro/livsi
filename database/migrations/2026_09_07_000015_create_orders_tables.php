<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Заказы и их позиции.
 *
 * Позиции хранят снимок товара — артикул, название, цену и ставку НДС
 * на момент покупки. Ссылки на товар недостаточно: цена и название
 * меняются, а в заказе и в чеке должно навсегда остаться то, что человек
 * реально купил.
 *
 * Деньги — целые копейки, как и везде в проекте.
 *
 * Подарок оформляется скидкой на заказ, а не позицией с нулевой ценой:
 * нулевая позиция невалидна в чеке, а безвозмездная передача — объект НДС
 * (docs/06-scope-v2.md § 2.4). Поэтому у заказа есть `discount_total`,
 * а у позиции — своя доля скидки.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();          // человекочитаемый номер
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('customer_name');
            $table->string('customer_phone');
            $table->string('customer_email');
            $table->text('comment')->nullable();

            // Доставка: поля заведены сразу, заполнит их отдельный блок.
            $table->string('delivery_method')->nullable();
            $table->text('delivery_address')->nullable();
            $table->unsignedInteger('delivery_price')->default(0);

            $table->unsignedInteger('items_total')->default(0);
            $table->unsignedInteger('discount_total')->default(0);
            $table->unsignedInteger('total')->default(0);

            $table->foreignId('gift_variant_id')->nullable()
                ->constrained('product_variants')->nullOnDelete();

            $table->string('status')->default('awaiting_payment');

            // Согласия фиксируются временем — как и в заявках.
            $table->timestamp('consent_at');
            $table->timestamp('marketing_consent_at')->nullable();

            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->json('utm')->nullable();

            // Доступ гостя к своему заказу по ссылке из письма: кабинета у него нет.
            $table->string('access_token', 64)->unique();

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            // Вариант может быть удалён, а позиция заказа обязана сохраниться.
            $table->foreignId('product_variant_id')->nullable()
                ->constrained('product_variants')->nullOnDelete();

            $table->string('sku');
            $table->string('title');
            $table->string('option_label')->nullable();

            $table->unsignedInteger('unit_price');
            $table->unsignedSmallInteger('quantity');
            $table->unsignedInteger('discount')->default(0);
            $table->unsignedInteger('total');

            // Снимок ставки: она нужна в чеке и может измениться позже.
            $table->decimal('vat_rate', 5, 2)->nullable();

            $table->boolean('is_gift')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
