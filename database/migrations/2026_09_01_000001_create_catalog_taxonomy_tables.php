<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ароматическая линейка. У товара она ровно одна (решение заказчика).
        Schema::create('product_lines', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();          // fresh | sweet | warm | base
            $table->string('title');                   // FRESH
            $table->string('subtitle')->nullable();    // свежий
            $table->string('color_bg', 7)->nullable(); // #d0dd56
            $table->string('color_ink', 7)->nullable();
            $table->text('description')->nullable();
            $table->string('seo_title')->nullable();
            $table->string('seo_description')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Назначение — независимая ось, many-to-many: руки, стопы, тело, лицо, маникюр, педикюр.
        Schema::create('purposes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('title');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Задача — независимая ось, many-to-many: очищение, увлажнение, отшелушивание, размягчение.
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('title');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('purposes');
        Schema::dropIfExists('product_lines');
    }
};