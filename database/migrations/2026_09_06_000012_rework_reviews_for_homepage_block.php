<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Блок отзывов приводится к макету.
 *
 * В макете это не список произвольной длины, а фиксированная композиция
 * из трёх карточек: большая слева на две строки и две узкие справа.
 * Поэтому у отзыва появляется место в блоке (`slot`), а не только порядок:
 * при сортировке числом вёрстка разъезжается, стоит кому-то поменять
 * порядок в админке.
 *
 * Подписи в макете свои: строка над цитатой («FRESH / МУЛЬТИПЕНКА»)
 * и строка под именем («ПОДТВЕРЖДЁННАЯ ПОКУПКА», «МАСТЕР ПЕДИКЮРА»).
 * Ни та, ни другая не выводится из площадки-источника, поэтому это
 * отдельные поля, которые заполняет заказчик.
 */
return new class extends Migration
{
    /** Места в блоке — ровно те три, что нарисованы в макете. */
    private const SLOTS = ['main', 'top', 'bottom'];

    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->string('slot')->nullable()->after('id');
            $table->string('caption')->nullable()->after('author');
            $table->string('role_caption')->nullable()->after('caption');
            $table->string('accent')->default('fresh')->after('role_caption');

            $table->unique('slot');
        });

        // Источник в макете не выводится, поэтому обязательным он быть перестаёт.
        // Колонку оставляем: она нужна для внутреннего учёта, откуда отзыв.
        Schema::table('reviews', function (Blueprint $table) {
            $table->string('source')->nullable()->change();
        });

        // Раскладываем то, что уже есть, по местам блока — по прежнему порядку.
        $existing = DB::table('reviews')->orderBy('sort')->orderBy('id')->pluck('id');

        foreach ($existing as $index => $id) {
            if ($index >= count(self::SLOTS)) {
                break;
            }

            DB::table('reviews')->where('id', $id)->update(['slot' => self::SLOTS[$index]]);
        }
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropUnique(['slot']);
            $table->dropColumn(['slot', 'caption', 'role_caption', 'accent']);
        });
    }
};
