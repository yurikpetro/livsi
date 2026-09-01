<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Вариант В из docs/06-scope-v2.md §2.1: под сайт выделяется квота остатков,
 * менеджер пополняет её вручную. Маркетплейсы торгуют из своей части склада,
 * поэтому овербукинг невозможен без интеграции с учётной системой.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_quotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('allocated')->default(0); // выделено менеджером
            $table->unsignedInteger('reserved')->default(0);  // в незавершённых оплатах
            $table->unsignedInteger('sold')->default(0);      // отгружено
            $table->unsignedInteger('low_threshold')->default(5);
            $table->timestamp('allocated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_quota_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_quota_id')->constrained()->cascadeOnDelete();
            $table->string('type');                  // allocate | reserve | release | sell | adjust
            $table->integer('qty');                  // со знаком
            $table->string('comment')->nullable();
            $table->nullableMorphs('subject');       // заказ и т.п.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_quota_movements');
        Schema::dropIfExists('stock_quotas');
    }
};