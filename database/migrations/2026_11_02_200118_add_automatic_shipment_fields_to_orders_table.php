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
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('shipment_automatic')->default(false)->index();
            $table->timestamp('shipment_checked_at')->nullable();
            $table->string('shipment_provider_status', 60)->nullable();
            $table->string('shipment_event_key', 64)->nullable();
            $table->string('shipment_notified_key', 64)->nullable();
            $table->timestamp('shipment_notified_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['shipment_automatic']);
            $table->dropColumn(['shipment_automatic', 'shipment_checked_at', 'shipment_provider_status',
                'shipment_event_key', 'shipment_notified_key', 'shipment_notified_at']);
        });
    }
};
