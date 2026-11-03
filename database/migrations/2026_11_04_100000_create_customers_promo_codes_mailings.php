<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Клиенты сайта, личные промокоды и рассылки.
 *
 * Клиент — человек с почтой: зарегистрированный или купивший гостем.
 * Заказы и пользователи привязываются к нему по почте (см. следующую миграцию).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->nullable()->unique();
            $table->string('phone', 30)->nullable()->index();
            $table->string('name')->nullable();
            $table->string('city', 120)->nullable();
            $table->timestamp('marketing_consent_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('mailings', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 10)->default('email');
            $table->string('subject', 200);
            $table->text('body');
            $table->string('promo_kind', 20)->nullable();
            $table->unsignedInteger('promo_value')->nullable();
            $table->unsignedSmallInteger('promo_days')->nullable();
            $table->string('status', 20)->default('sending');
            $table->string('created_by', 120)->nullable();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('sent')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('kind', 20);
            $table->unsignedInteger('value')->default(0);
            $table->unsignedInteger('min_total')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->boolean('once_per_customer')->default(true);
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('mailing_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->string('issued_by', 120)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('mailing_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email');
            $table->string('status', 20)->default('queued');
            $table->foreignId('promo_code_id')->nullable()->constrained()->nullOnDelete();
            $table->string('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['mailing_id', 'customer_id']);
            $table->index(['status', 'mailing_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('promo_code_id')->nullable()->constrained()->nullOnDelete();
            $table->string('promo_code', 40)->nullable();
            $table->unsignedInteger('promo_discount')->default(0);
            $table->boolean('promo_free_delivery')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
            $table->dropConstrainedForeignId('promo_code_id');
            $table->dropColumn(['promo_code', 'promo_discount', 'promo_free_delivery']);
        });
        Schema::dropIfExists('mailing_recipients');
        Schema::dropIfExists('promo_codes');
        Schema::dropIfExists('mailings');
        Schema::dropIfExists('customers');
    }
};
