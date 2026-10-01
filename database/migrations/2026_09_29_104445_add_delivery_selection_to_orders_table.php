<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->char('delivery_country_code', 2)->nullable();
            $table->string('delivery_city')->nullable();
            $table->string('delivery_point_hint')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['delivery_country_code', 'delivery_city', 'delivery_point_hint']);
        });
    }
};
