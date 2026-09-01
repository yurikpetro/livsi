<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Отзывы вводятся вручную из админки (решение заказчика от 31.08.2026).
 * Поле source обязательно: указывать площадку, откуда отзыв, — требование
 * достоверности сведений о товаре. См. docs/07-design-review.md §8.1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author');
            $table->text('text');
            $table->unsignedTinyInteger('rating')->default(5);
            $table->string('source');                    // ozon | wildberries | site
            $table->string('source_label')->nullable();  // «Ozon»
            $table->string('image_path')->nullable();
            $table->boolean('is_featured')->default(false); // показывать на главной
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->date('published_on')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'is_featured']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};