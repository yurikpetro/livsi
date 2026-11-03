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
            $table->string('apiship_order_id', 30)->nullable()->unique();
            $table->string('shipment_state', 20)->nullable();
            $table->string('shipment_status_name')->nullable();
            $table->string('shipment_tracking_number', 150)->nullable();
            $table->string('shipment_error')->nullable();
            $table->json('shipment_sender')->nullable();
            $table->timestamp('shipment_requested_at')->nullable();
            $table->timestamp('shipment_created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['apiship_order_id']);
            $table->dropColumn(['apiship_order_id', 'shipment_state', 'shipment_status_name',
                'shipment_tracking_number', 'shipment_error', 'shipment_sender',
                'shipment_requested_at', 'shipment_created_at']);
        });
    }
};
