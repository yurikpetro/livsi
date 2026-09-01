<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Пороги и подарок меняются из админки, а не через .env — иначе менять их
 * пришлось бы разработчику. Значения по умолчанию: бесплатная доставка
 * от 1 000 ₽, подарок от 5 000 ₽.
 *
 * Подарок оформляется НЕ как позиция с нулевой ценой, а как скидка на заказ,
 * распределённая по позициям: чек валиден, склад списывается, безвозмездной
 * передачи нет. См. docs/06-scope-v2.md §2.4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_rules', function (Blueprint $table) {
            $table->id();
            $table->string('type');                          // free_shipping | gift
            $table->string('title')->nullable();
            $table->unsignedInteger('threshold');            // копейки
            $table->string('channel')->default('retail');    // retail | wholesale | all
            $table->json('payload')->nullable();             // список подарочных вариантов и т.п.
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['type', 'is_active']);
        });

        Schema::create('promo_rule_gifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promo_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['promo_rule_id', 'product_variant_id'], 'promo_gift_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_rule_gifts');
        Schema::dropIfExists('promo_rules');
    }
};