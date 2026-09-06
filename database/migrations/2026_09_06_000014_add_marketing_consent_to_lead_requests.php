<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Согласие на рекламные рассылки — отдельно от согласия на обработку данных.
 *
 * Одна галочка на два разных согласия недопустима: обработка персональных
 * данных нужна, чтобы ответить на заявку, а рассылка — самостоятельная цель,
 * и согласие на неё должно даваться отдельно и добровольно (152-ФЗ, ст. 9
 * и 38-ФЗ «О рекламе», ст. 18). Поэтому колонка своя, и она необязательная.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_requests', function (Blueprint $table) {
            $table->timestamp('marketing_consent_at')->nullable()->after('consent_at');
        });
    }

    public function down(): void
    {
        Schema::table('lead_requests', function (Blueprint $table) {
            $table->dropColumn('marketing_consent_at');
        });
    }
};
