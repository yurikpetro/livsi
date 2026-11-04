<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Баннеры первого экрана главной: раньше были вписаны в шаблон, теперь
 * правятся в админке («Баннеры на главной»). Нынешние пять переносятся как есть.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hero_slides', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('title_first', 60);
            $table->string('title_second', 60)->nullable();
            $table->string('title_accent', 60)->nullable();
            $table->string('description', 400)->nullable();
            $table->string('aroma', 120)->nullable();
            $table->string('accent_color', 9)->default('#d0e64f');
            $table->string('tab_label', 40)->nullable();
            $table->string('desktop_image')->nullable();
            $table->string('mobile_image')->nullable();
            $table->unsignedTinyInteger('desktop_focus_x')->default(50);
            $table->unsignedTinyInteger('desktop_focus_y')->default(47);
            $table->unsignedTinyInteger('mobile_focus_x')->nullable();
            $table->unsignedTinyInteger('mobile_focus_y')->nullable();
            $table->timestamps();
        });

        $now = now();
        $slides = [
            'fresh' => ['Свежесть,', 'которая', 'включает.', 'Ощущение чистоты и лёгкости, с которого приятно начинать день — или начинать заново.', 'Свежий · чистый · бодрящий', '#d0e64f', 'свежий'],
            'sweet' => ['Сладкий', 'характер', 'ухода.', 'Мягкие текстуры и сочное настроение для моментов, когда хочется позволить себе больше.', 'Сладкий · фруктовый · игривый', '#f6accd', 'сладкий'],
            'warm' => ['Тепло,', 'которое', 'остаётся.', 'Солнечный ритуал ухода, который возвращает коже мягкость, а тебе — ощущение лета.', 'Тёплый · солнечный · насыщенный', '#ffe405', 'тёплый'],
            'base' => ['База,', 'которая', 'нужна.', 'Понятный ежедневный уход без лишнего шума — спокойный, универсальный и всегда уместный.', 'Спокойный · чистый · универсальный', '#f5e2b7', 'базовый'],
            'pro' => ['Точность,', 'которая', 'работает.', 'Профессиональные формулы и последовательные протоколы для мастеров и требовательного ухода.', 'Точность · протокол · результат', '#a8d8ef', 'профессиональный'],
        ];
        // Точки обрезки на телефоне — те, что раньше были вписаны в стили.
        $phone = ['fresh' => 54, 'sweet' => 54, 'warm' => 51, 'base' => 56, 'pro' => 62];
        $sort = 0;
        foreach ($slides as $code => [$first, $second, $accent, $description, $aroma, $color, $tab]) {
            DB::table('hero_slides')->insert([
                'code' => $code, 'sort' => $sort += 10, 'is_active' => true,
                'title_first' => $first, 'title_second' => $second, 'title_accent' => $accent,
                'description' => $description, 'aroma' => $aroma, 'accent_color' => $color, 'tab_label' => $tab,
                'desktop_image' => 'img/catalog/livsi-'.$code.'.png',
                'desktop_focus_x' => 50, 'desktop_focus_y' => 47,
                'mobile_focus_x' => $phone[$code], 'mobile_focus_y' => 50,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hero_slides');
    }
};
