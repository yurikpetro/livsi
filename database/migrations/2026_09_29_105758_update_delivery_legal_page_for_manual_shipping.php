<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $page = DB::table('legal_pages')->where('slug', 'delivery')->first();

        if (! $page) {
            return;
        }

        $replacements = [
            'Доставка осуществляется по всей России в пункты выдачи заказов и курьером. Стоимость и срок рассчитываются при оформлении заказа и показываются до оплаты.' => 'На этапе оформления можно выбрать предпочтительный способ доставки. Стоимость, срок и конкретный пункт выдачи пока уточняет менеджер после заказа. На странице оплаты отображается сумма только за товары; доставка в неё не включена.',
            'Заказы на сумму от установленного порога доставляются бесплатно. Актуальный порог отображается в корзине.' => 'Условия бесплатной доставки по достижении установленного порога уточняются менеджером при согласовании отправки. Порог, если акция активна, отображается в корзине.',
        ];

        $body = str_replace(array_keys($replacements), array_values($replacements), $page->body);

        if ($body !== $page->body) {
            DB::table('legal_pages')->where('slug', 'delivery')->update([
                'body' => $body,
                'reviewed_at' => null,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Документ мог быть отредактирован после миграции — не перезаписываем его.
    }
};
