<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Вернувшаяся посылка принята на склад: когда и кем (товар снова в продаже). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('returned_to_stock_at')->nullable();
            $table->string('returned_to_stock_by', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['returned_to_stock_at', 'returned_to_stock_by']);
        });
    }
};
