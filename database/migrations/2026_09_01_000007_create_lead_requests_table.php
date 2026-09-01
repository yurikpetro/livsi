<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Две разные формы с разными полями: оптовое партнёрство и контрактное
 * производство. Храним в одной таблице с типом, но валидация и уведомления
 * разные. См. docs/07-design-review.md §8.2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_requests', function (Blueprint $table) {
            $table->id();
            $table->string('type');                    // wholesale | contract
            $table->string('name');
            $table->string('contact');                 // телефон или Telegram
            $table->string('city')->nullable();        // опт
            $table->string('sales_format')->nullable();// опт
            $table->string('product_category')->nullable(); // контрактное производство
            $table->string('planned_volume')->nullable();   // контрактное производство
            $table->text('comment')->nullable();

            $table->timestamp('consent_at');           // фиксируем факт согласия
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->json('utm')->nullable();           // атрибуция до заявки

            $table->string('status')->default('new');  // new | in_progress | done | rejected
            $table->text('manager_comment')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_requests');
    }
};