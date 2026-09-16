<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Избранное.
 *
 * Гостю оно доступно так же, как корзина: на сайте гостевой сценарий
 * объявлен обязательным, и требовать вход ради сердечка означало бы
 * отменить это ровно в том месте, где человек впервые проявил интерес.
 * Поэтому владелец записи — либо пользователь, либо токен из cookie,
 * и при входе гостевые записи переезжают в аккаунт.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('token', 64)->nullable();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // Два владельца — два ограничения. NULL в уникальном индексе
            // не конфликтует сам с собой, поэтому записи гостя ограничены
            // токеном, а записи пользователя — его идентификатором.
            $table->unique(['user_id', 'product_id']);
            $table->unique(['token', 'product_id']);

            $table->index('token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favorites');
    }
};
