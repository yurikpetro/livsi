<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Нормализованный текст для поиска.
 *
 * Своё поле, а не LOWER() по колонкам в запросе: SQLite приводит к нижнему
 * регистру только ASCII, и «ПЕНКА» не нашлась бы по «пенка». В PHP есть
 * mb_strtolower, поэтому нормализуем при сохранении — работает одинаково
 * и на SQLite, и на PostgreSQL.
 *
 * Складываем только собственные атрибуты товара: связи (линейка, назначения,
 * задачи, артикулы вариантов) ищутся через whereHas, поэтому поле не нужно
 * перестраивать при изменении связей.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->text('search_text')->nullable()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('search_text');
        });
    }
};