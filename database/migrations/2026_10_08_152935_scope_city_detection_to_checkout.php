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

        $body = str_replace(
            ['При первом посещении сайта для предложения города', 'его можно изменить в шапке сайта или форме заказа'],
            ['При открытии страницы оформления заказа для предложения города', 'его можно изменить в форме заказа'],
            $page->body,
        );

        if ($body !== $page->body) {
            DB::table('legal_pages')->where('id', $page->id)->update([
                'body' => $body,
                'reviewed_at' => null,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Опубликованный документ может быть отредактирован юристом.
    }
};
