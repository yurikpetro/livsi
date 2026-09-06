<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Юридические и информационные документы: оферта, политика, согласие,
 * доставка и оплата, возврат.
 *
 * Отдельная сущность, а не тексты в шаблонах: документы меняются при смене
 * реквизитов, платёжного провайдера или условий доставки, и правит их
 * заказчик, а не разработчик. Дата последней правки — часть документа:
 * оферта без указания редакции спорна.
 *
 * `reviewed_at` фиксирует, что текст проверен юристом. Пока пусто —
 * документ считается черновиком разработчика, и админка об этом говорит.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('menu_title')->nullable();   // короткая подпись для футера
            $table->text('lede')->nullable();           // абзац под заголовком
            $table->longText('body');                   // HTML из редактора админки
            $table->string('seo_title')->nullable();
            $table->string('seo_description')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_pages');
    }
};
