<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('currency', 3)->default('RUB');
            // UTM сохраняется от первого визита до заказа — требование заказчика.
            $table->json('utm')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('qty')->default(1);
            // Подарок по промо-правилу. В чеке он пойдёт позицией со своей ценой,
            // а скидка распределится по заказу — см. docs/06-scope-v2.md §2.4.
            $table->boolean('is_gift')->default(false);
            $table->foreignId('promo_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['cart_id', 'product_variant_id', 'is_gift'], 'cart_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};