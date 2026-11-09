<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Страница «Возврат товара»: абзац «Как оформить возврат» теперь про кнопку
 * «Вернуть заказ». Меняется, только если абзац остался таким, каким его
 * положил сайт: правленый вручную текст не трогаем.
 */
return new class extends Migration
{
    private const OLD = '<p>Сообщите нам о намерении вернуть товар по контактам, указанным на сайте, и укажите номер заказа и позиции, которые хотите вернуть. Мы подтвердим адрес и способ отправки.</p>';

    private const NEW = '<p>Нажмите «Вернуть заказ» на странице заказа или в личном кабинете — кнопка появляется после получения. Отметьте, что возвращаете, укажите причину и при необходимости приложите фото. Мы ответим письмом: как передать товар и сколько вернём. Можно и написать нам по контактам, указанным на сайте.</p>'
        .'<p>Из других городов товар возвращается через СДЭК: мы оформляем накладную, вы сдаёте посылку в пункт СДЭК и называете номер возврата. В Ставрополе товар может забрать наш курьер.</p>';

    public function up(): void
    {
        $this->swap(self::OLD, self::NEW);
    }

    public function down(): void
    {
        $this->swap(self::NEW, self::OLD);
    }

    private function swap(string $from, string $to): void
    {
        $page = DB::table('legal_pages')->where('slug', 'returns')->first();
        if ($page && substr_count((string) $page->body, $from) === 1) {
            DB::table('legal_pages')->where('id', $page->id)->update(['body' => str_replace($from, $to, $page->body), 'updated_at' => now()]);
        }
    }
};
