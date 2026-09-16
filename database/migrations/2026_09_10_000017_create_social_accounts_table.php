<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Покупательские аккаунты и привязки к внешним провайдерам.
 *
 * Заказчик просил вход по коду из СМС как основной и кнопки Яндекса,
 * VK и Сбера как дополнительные (`04-questions-for-client.md`, п. 1.1).
 * Значит, у одного человека будет несколько способов входа — и связь
 * «провайдер + его идентификатор → наш пользователь» нужна отдельной
 * таблицей, а не колонкой `yandex_id` в `users`.
 *
 * Пароль становится необязательным: у покупателя, вошедшего по коду
 * или через Яндекс, пароля нет вовсе. У администратора он остаётся.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();

            // Телефон — будущий основной способ входа, поэтому уникальный.
            $table->string('phone', 20)->nullable()->unique()->after('email');
        });

        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('provider_user_id');
            $table->string('email')->nullable();
            $table->timestamps();

            // Один аккаунт у провайдера — один наш пользователь. Без этого
            // повторный вход создавал бы дубли при гонке двух вкладок.
            $table->unique(['provider', 'provider_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone');
            $table->string('password')->nullable(false)->change();
        });
    }
};
