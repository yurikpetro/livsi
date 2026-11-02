<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->boolean('dimensions_verified')->default(false);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->json('delivery_quote')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('delivery_quote'));
        Schema::table('product_variants', fn (Blueprint $table) => $table->dropColumn('dimensions_verified'));
    }
};
