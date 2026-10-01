<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $page = DB::table('legal_pages')->where('slug', 'privacy')->first();

        if (! $page || str_contains($page->body, 'При открытии страницы оформления заказа загружается сервис «Яндекс Карты»')) {
            return;
        }

        $body = str_replace(
            'Эти данные обрабатываются в обезличенном виде.',
            'При оформлении заказа также обрабатывается выбранный населённый пункт.',
            $page->body,
        );

        $disclosure = '<p>При открытии страницы оформления заказа загружается сервис «Яндекс Карты»: Яндекс получает IP-адрес и технические сведения браузера для приблизительного определения города и отображения карты пунктов выдачи. При вводе населённого пункта Яндексу передаётся текст запроса для подсказок. Точные координаты устройства не запрашиваются. Предложенный город сохраняется в браузере только после подтверждения покупателем; его можно изменить в форме заказа.</p>';
        $marker = '<h2>6. Сроки хранения</h2>';
        $body = str_contains($body, $marker)
            ? str_replace($marker, $disclosure."\n\n".$marker, $body)
            : $body."\n".$disclosure;

        DB::table('legal_pages')->where('slug', 'privacy')->update([
            'body' => $body,
            'reviewed_at' => null,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Документ мог быть отредактирован после миграции — не перезаписываем его.
    }
};
