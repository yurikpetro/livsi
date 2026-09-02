<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Декларации соответствия ЕАЭС.
 *
 * Отдельная сущность, а не JSON-поле у товара: у декларации есть номер,
 * даты действия и файл, а одна декларация обычно покрывает несколько
 * товаров сразу. Прототип на /declarations прямо перечисляет, что должно
 * быть в списке: наименование товара, номер декларации, срок действия
 * и кнопка скачивания.
 *
 * Поле products.documents удаляется: два источника правды по одним и тем же
 * документам разъехались бы на первой же правке.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('declarations', function (Blueprint $table) {
            $table->id();
            $table->string('number');                 // номер декларации
            $table->string('title')->nullable();      // на что выдана
            $table->date('issued_on')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('file_path')->nullable();  // PDF
            $table->unsignedInteger('file_size')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort']);
        });

        Schema::create('declaration_product', function (Blueprint $table) {
            $table->foreignId('declaration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->primary(['declaration_id', 'product_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('documents');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('documents')->nullable();
        });

        Schema::dropIfExists('declaration_product');
        Schema::dropIfExists('declarations');
    }
};