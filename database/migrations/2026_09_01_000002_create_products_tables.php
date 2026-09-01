<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('short_description')->nullable();
            $table->string('badge')->nullable();          // new | best | pro
            $table->foreignId('product_line_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_pro')->default(false);    // признак «для мастера», не пятая линейка
            $table->boolean('is_bundle')->default(false); // набор на продажу

            $table->string('aroma')->nullable();          // «грейпфрут · зелёный чай»
            $table->string('effect')->nullable();         // короткое обещание результата
            $table->text('composition')->nullable();      // состав / INCI
            $table->text('application')->nullable();      // способ применения
            $table->text('active_ingredients')->nullable(); // действующие вещества (PRO)
            $table->string('shelf_life')->nullable();
            $table->json('documents')->nullable();        // декларации ЕАЭС / СГР

            // Рейтинг вводится вручную из админки. Источник обязателен — см. docs/07-design-review.md §8.1.
            $table->decimal('rating', 2, 1)->nullable();
            $table->unsignedInteger('reviews_count')->nullable();
            $table->string('reviews_source')->nullable(); // «Ozon», «Ozon и Wildberries»

            // Ставка НДС на уровне товара, null → значение по умолчанию из настроек.
            $table->decimal('vat_rate', 5, 2)->nullable();

            $table->string('seo_title')->nullable();
            $table->string('seo_description')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'is_pro']);
            $table->index('product_line_id');
        });

        Schema::create('product_purpose', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purpose_id')->constrained()->cascadeOnDelete();
            $table->primary(['product_id', 'purpose_id']);
        });

        Schema::create('product_task', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->primary(['product_id', 'task_id']);
        });

        // Вариант — пересечение двух осей: объём × аромат. Подтверждено карточками на Ozon.
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku')->unique();
            $table->string('barcode')->nullable();

            $table->string('option_volume')->nullable();  // «200 мл»
            $table->string('option_aroma')->nullable();   // «сочная вишня»
            $table->unsignedInteger('volume_ml')->nullable();

            // Деньги — целые копейки, никаких float.
            $table->unsignedInteger('price');
            $table->unsignedInteger('compare_at_price')->nullable();

            // Без веса и габаритов доставка не считается.
            $table->unsignedInteger('weight_g')->nullable();
            $table->unsignedInteger('length_mm')->nullable();
            $table->unsignedInteger('width_mm')->nullable();
            $table->unsignedInteger('height_mm')->nullable();

            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['product_id', 'option_volume', 'option_aroma'], 'variant_options_unique');
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('alt')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        // Состав набора: набор собирается из существующих вариантов.
        Schema::create('bundle_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bundle_product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('qty')->default(1);
            $table->timestamps();

            $table->unique(['bundle_product_id', 'product_variant_id'], 'bundle_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bundle_items');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('product_task');
        Schema::dropIfExists('product_purpose');
        Schema::dropIfExists('products');
    }
};