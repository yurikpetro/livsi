<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $page = DB::table('legal_pages')->where('slug', 'privacy')->first();

        if (! $page) {
            return;
        }

        $old = 'для приблизительного определения местоположения. При открытии карты';
        $new = 'для приблизительного определения местоположения. Полученные приблизительные координаты сайт передаёт с сервера в API Геокодера Яндекса, чтобы предложить название населённого пункта. При открытии карты';

        if (str_contains($page->body, $old)) {
            DB::table('legal_pages')->where('slug', 'privacy')->update([
                'body' => str_replace($old, $new, $page->body),
                'reviewed_at' => null,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // После публикации документ может быть отредактирован юристом.
    }
};
