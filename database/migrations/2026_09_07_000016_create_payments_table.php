<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Платежи по заказам.
 *
 * Отдельная таблица, а не поля в заказе: попыток оплаты может быть
 * несколько — человек закрыл страницу, платёж истёк, попробовал снова.
 * История нужна и для разбора спорных случаев, и для сверки с провайдером.
 *
 * `external_id` уникален: по нему приходят вебхуки, и он же защищает
 * от повторной обработки одного и того же уведомления. Провайдер
 * гарантирует доставку «хотя бы один раз», то есть дубли будут.
 *
 * `idempotence_key` уходит в заголовок запроса на создание платежа:
 * без него повторный запрос при сетевой ошибке создал бы второй платёж
 * и второе списание.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->string('gateway')->default('yookassa');
            $table->string('external_id')->nullable()->unique();
            $table->string('idempotence_key', 64)->unique();

            $table->string('status')->default('pending');
            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('RUB');

            $table->text('confirmation_url')->nullable();
            $table->string('payment_method')->nullable();   // sbp, bank_card и т. д.

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();

            // Последний ответ провайдера целиком: при разборе спорных
            // случаев пересказ бесполезен, нужен исходный документ.
            $table->json('payload')->nullable();

            $table->timestamps();

            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
