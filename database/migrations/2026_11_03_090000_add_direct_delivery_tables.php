<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Прямая работа со службами доставки, без ApiShip.
 *
 * delivery_points — пункты выдачи СДЭК, Яндекса и Ozon. Раньше карта на
 * каждом открытии спрашивала их у ApiShip; теперь служба отдаёт свой список
 * раз в сутки ночью, а карта берёт его из своей базы. Если служба днём
 * недоступна, карта всё равно открывается.
 *
 * Новые колонки заказа описывают отправление у самой службы: какой службой
 * создано и под каким её номером. Старые колонки ApiShip остаются: по ним
 * живут заказы, оформленные до переключения.
 *
 * order_item_marks — коды «Честного знака», отсканированные при сборке.
 * Один код может уйти только в один заказ: уникальность на уровне базы.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_points', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('external_id', 64);
            $table->string('type', 20)->default('pvz');
            $table->string('name')->default('');
            $table->string('address', 500);
            $table->string('city', 120)->default('');
            $table->string('city_key', 120)->default('');
            $table->string('region', 120)->default('');
            $table->string('region_key', 120)->default('');
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('timetable', 500)->default('');
            $table->json('limits')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_id']);
            $table->index(['provider', 'is_active', 'city_key']);
            $table->index(['provider', 'is_active', 'region_key']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('shipment_provider', 20)->nullable();
            $table->string('shipment_external_id', 100)->nullable();
            $table->string('shipment_status_code', 60)->nullable();
            $table->json('shipment_data')->nullable();
            $table->timestamp('shipment_synced_at')->nullable();

            $table->unique(['shipment_provider', 'shipment_external_id']);
        });

        Schema::create('order_item_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->string('gtin', 14);
            $table->string('serial', 64);
            $table->text('code');
            $table->string('scanned_by', 120)->nullable();
            $table->timestamps();

            // GTIN + серийный номер однозначно задают экземпляр товара:
            // один и тот же тюбик нельзя продать дважды.
            $table->unique(['gtin', 'serial']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_marks');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['shipment_provider', 'shipment_external_id']);
            $table->dropColumn(['shipment_provider', 'shipment_external_id', 'shipment_status_code',
                'shipment_data', 'shipment_synced_at']);
        });

        Schema::dropIfExists('delivery_points');
    }
};
