<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Блок «Ты и LIVSI» — подборка изображений из админки (видео решено не делать).
        Schema::create('ugc_items', function (Blueprint $table) {
            $table->id();
            $table->string('image_path');
            $table->string('alt')->nullable();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        /**
         * Реквизиты продавца — отдельная сущность, а не константы в коде:
         * часть продаж в перспективе пойдёт от юрлица, и подписи в чеках,
         * счетах и оферте должны меняться из админки.
         */
        Schema::create('seller_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();          // ip | ooo
            $table->string('legal_name');
            $table->string('inn', 12)->nullable();
            $table->string('kpp', 9)->nullable();
            $table->string('ogrn', 15)->nullable();
            $table->string('address')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_bic', 9)->nullable();
            $table->string('bank_account', 20)->nullable();
            $table->string('bank_corr_account', 20)->nullable();
            $table->string('signer_name')->nullable();
            $table->string('signer_position')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('faq_items', function (Blueprint $table) {
            $table->id();
            $table->string('question');
            $table->text('answer');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faq_items');
        Schema::dropIfExists('seller_profiles');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('ugc_items');
    }
};