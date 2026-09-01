<?php

namespace Database\Seeders;

use App\Models\FaqItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductLine;
use App\Models\ProductVariant;
use App\Models\PromoRule;
use App\Models\Purpose;
use App\Models\Review;
use App\Models\SellerProfile;
use App\Models\Setting;
use App\Models\StockQuota;
use App\Models\Task;
use App\Models\UgcItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Наполнение каталога данными из прототипа livsi-store-new.khetaggg.chatgpt.site.
 *
 * Цены — заглушки (подтверждено заказчиком 31.08.2026), реальный прайс ждём.
 * Изображения — временные, из старого макета: чистых фото без маркетплейсной
 * инфографики пока нет (вопрос 14.6).
 * Рейтинги проставлены с указанием источника — см. docs/07-design-review.md §8.1.
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $lines     = $this->seedLines();
        $purposes  = $this->seedPurposes();
        $tasks     = $this->seedTasks();

        $this->seedProducts($lines, $purposes, $tasks);
        $this->seedReviews();
        $this->seedUgc();
        $this->seedFaq();
        $this->seedSettings();
        $this->seedPromoRules();
        $this->seedSellerProfile();
    }

    /** @return array<string, ProductLine> */
    private function seedLines(): array
    {
        $rows = [
            ['code' => 'fresh', 'title' => 'FRESH', 'subtitle' => 'свежий',           'color_bg' => '#d0dd56', 'color_ink' => '#51a940', 'sort' => 10,
             'description' => 'Про свежесть, лёгкость и ощущение чистоты. Средства с лёгкими текстурами и свежими ароматами вроде чая, мяты и цитрусов.'],
            ['code' => 'sweet', 'title' => 'SWEET', 'subtitle' => 'сладкий',          'color_bg' => '#f6accd', 'color_ink' => '#be6eb5', 'sort' => 20,
             'description' => 'Про удовольствие, чувственность и игру. Линейка построена вокруг сладких, фруктовых и гурманских ароматов.'],
            ['code' => 'warm',  'title' => 'WARM',  'subtitle' => 'тёплый',           'color_bg' => '#ffe405', 'color_ink' => '#ec9022', 'sort' => 30,
             'description' => 'Про тепло, комфорт и уют. Тёплые и пряные ароматы, более насыщенные текстуры и плотный уход.'],
            ['code' => 'base',  'title' => 'BASE',  'subtitle' => 'базовый',          'color_bg' => '#f5e2b7', 'color_ink' => '#cc8c34', 'sort' => 40,
             'description' => 'Базовый уход без ароматической доминанты. Универсальные продукты, которые подходят к любой другой линейке.'],
        ];

        $out = [];

        foreach ($rows as $row) {
            $out[$row['code']] = ProductLine::updateOrCreate(['code' => $row['code']], $row);
        }

        return $out;
    }

    /** @return array<string, Purpose> */
    private function seedPurposes(): array
    {
        $rows = [
            'hands'    => 'Руки',
            'feet'     => 'Стопы',
            'body'     => 'Тело',
            'face'     => 'Лицо',
            'manicure' => 'Маникюр',
            'pedicure' => 'Педикюр',
        ];

        $out  = [];
        $sort = 0;

        foreach ($rows as $code => $title) {
            $out[$code] = Purpose::updateOrCreate(['code' => $code], ['title' => $title, 'sort' => $sort += 10]);
        }

        return $out;
    }

    /** @return array<string, Task> */
    private function seedTasks(): array
    {
        $rows = [
            'cleansing'   => 'Очищение',
            'moisturize'  => 'Увлажнение',
            'exfoliate'   => 'Отшелушивание',
            'soften'      => 'Размягчение',
        ];

        $out  = [];
        $sort = 0;

        foreach ($rows as $code => $title) {
            $out[$code] = Task::updateOrCreate(['code' => $code], ['title' => $title, 'sort' => $sort += 10]);
        }

        return $out;
    }

    /**
     * @param  array<string, ProductLine>  $lines
     * @param  array<string, Purpose>      $purposes
     * @param  array<string, Task>         $tasks
     */
    private function seedProducts(array $lines, array $purposes, array $tasks): void
    {
        $rows = [
            [
                'slug'      => 'multipenka-dlya-ruk-i-stop',
                'title'     => 'Мультипенка для рук и стоп',
                'short'     => 'Очищает без воды, липкости и ощущения сухости',
                'line'      => 'fresh',
                'badge'     => 'new',
                'aroma'     => 'грейпфрут · зелёный чай',
                'effect'    => 'Комфортное очищение с понятным результатом',
                'rating'    => 4.9,
                'reviews'   => 326,
                'purposes'  => ['hands', 'feet'],
                'tasks'     => ['cleansing'],
                'image'     => 'fresh-hero.jpg',
                'variants'  => [
                    ['volume' => '180 мл', 'ml' => 180, 'price' => 45000, 'weight' => 210, 'dims' => [55, 55, 175], 'default' => true],
                    ['volume' => '540 мл', 'ml' => 540, 'price' => 99000, 'weight' => 590, 'dims' => [80, 80, 220]],
                ],
            ],
            [
                'slug'      => 'krem-dlya-ruk-i-stop-25-urea',
                'title'     => 'Крем для рук и стоп 25% Urea',
                'short'     => 'Интенсивно смягчает сухую и огрубевшую кожу',
                'line'      => 'base',
                'badge'     => 'best',
                'aroma'     => 'чистый · сливочный',
                'effect'    => 'Смягчение и восстановление барьера',
                'rating'    => 4.9,
                'reviews'   => 614,
                'purposes'  => ['hands', 'feet'],
                'tasks'     => ['moisturize', 'soften'],
                'image'     => 'warm-portrait.jpg',
                'variants'  => [
                    ['volume' => '200 мл', 'ml' => 200, 'price' => 79000, 'weight' => 235, 'dims' => [55, 55, 180], 'default' => true],
                    ['volume' => '500 мл', 'ml' => 500, 'price' => 149000, 'weight' => 560, 'dims' => [80, 80, 210]],
                ],
            ],
            [
                'slug'      => 'parfyumirovannyy-mist-dlya-tela',
                'title'     => 'Парфюмированный мист для тела',
                'short'     => 'Оставляет лёгкий ароматный шлейф без тяжести',
                'line'      => 'sweet',
                'badge'     => 'new',
                'aroma'     => 'сочные ягоды · вишня',
                'effect'    => 'Аромат и лёгкое увлажнение',
                'rating'    => 4.8,
                'reviews'   => 148,
                'purposes'  => ['body'],
                'tasks'     => ['moisturize'],
                'image'     => 'sweet-portrait.jpg',
                'variants'  => [
                    ['volume' => '100 мл', 'ml' => 100, 'price' => 109000, 'weight' => 145, 'dims' => [45, 45, 160], 'default' => true],
                ],
            ],
            [
                'slug'      => 'skrab-dlya-tela',
                'title'     => 'Скраб для тела',
                'short'     => 'Разглаживает кожу и возвращает ей мягкость',
                'line'      => 'warm',
                'badge'     => 'new',
                'aroma'     => 'манго · маракуйя · апельсин',
                'effect'    => 'Отшелушивание и питание',
                'rating'    => 4.9,
                'reviews'   => 207,
                'purposes'  => ['body'],
                'tasks'     => ['exfoliate'],
                'image'     => 'mango-product.jpg',
                'variants'  => [
                    ['volume' => '250 мл', 'ml' => 250, 'price' => 129000, 'weight' => 320, 'dims' => [85, 85, 95], 'default' => true],
                ],
            ],
            [
                'slug'      => 'keratoliticheskiy-gel-dlya-stop',
                'title'     => 'Кератолитический гель для стоп',
                'short'     => 'Точное действие для контролируемого педикюра',
                'line'      => null,
                'is_pro'    => true,
                'badge'     => 'pro',
                'aroma'     => 'профессиональная формула',
                'effect'    => 'Размягчение гиперкератоза',
                'rating'    => 4.9,
                'reviews'   => 431,
                'purposes'  => ['feet', 'pedicure'],
                'tasks'     => ['soften'],
                'image'     => 'avatar3.jpg',
                'active'    => 'Мочевина, молочная кислота. Профессиональное применение, требует нейтрализации.',
                'variants'  => [
                    ['volume' => '100 мл', 'ml' => 100, 'price' => 69000, 'weight' => 130, 'dims' => [40, 40, 145], 'default' => true],
                ],
            ],
            [
                'slug'      => 'multipenka-dlya-manikyura-fresh',
                'title'     => 'Мультипенка для маникюра',
                'short'     => 'Мягко очищает и освежает кожу на каждом этапе ухода',
                'line'      => 'fresh',
                'badge'     => 'best',
                'aroma'     => 'грейпфрут · зелёный чай',
                'effect'    => 'Очищение без воды',
                'rating'    => 4.9,
                'reviews'   => 684,
                'purposes'  => ['hands', 'manicure'],
                'tasks'     => ['cleansing'],
                'image'     => 'fresh-hero.jpg',
                'variants'  => [
                    ['volume' => '200 мл', 'ml' => 200, 'price' => 49000, 'weight' => 230, 'dims' => [55, 55, 180], 'default' => true],
                ],
            ],
            [
                'slug'      => 'multipenka-dlya-manikyura-sweet',
                'title'     => 'Мультипенка для маникюра',
                'short'     => 'Очищает без воды и оставляет мягкий ягодный аромат',
                'line'      => 'sweet',
                'aroma'     => 'сочная вишня',
                'effect'    => 'Комфортный уход с понятным результатом',
                'rating'    => 4.9,
                'reviews'   => 392,
                'purposes'  => ['hands', 'manicure'],
                'tasks'     => ['cleansing'],
                'image'     => 'sweet-closeup.jpg',
                'variants'  => [
                    ['volume' => '200 мл', 'ml' => 200, 'price' => 49000, 'weight' => 230, 'dims' => [55, 55, 180], 'default' => true],
                ],
            ],
            [
                'slug'      => 'nabor-chistota-i-myagkost',
                'title'     => 'Набор «Чистота и мягкость»',
                'short'     => 'Два понятных шага для комфортного ежедневного ухода',
                'line'      => 'fresh',
                'is_bundle' => true,
                'aroma'     => 'грейпфрут · чистый сливочный',
                'effect'    => 'Очищение и смягчение',
                'rating'    => 4.9,
                'reviews'   => 64,
                'purposes'  => ['hands', 'feet'],
                'tasks'     => ['cleansing', 'moisturize'],
                'image'     => 'avatar4.jpg',
                'variants'  => [
                    ['volume' => '180 мл + 200 мл', 'price' => 119000, 'weight' => 460, 'dims' => [120, 70, 185], 'default' => true],
                ],
            ],
        ];

        $sort = 0;

        foreach ($rows as $row) {
            $product = Product::updateOrCreate(['slug' => $row['slug']], [
                'title'             => $row['title'],
                'short_description' => $row['short'],
                'badge'             => $row['badge'] ?? null,
                'product_line_id'   => isset($row['line']) && $row['line'] ? $lines[$row['line']]->id : null,
                'is_pro'            => $row['is_pro'] ?? false,
                'is_bundle'         => $row['is_bundle'] ?? false,
                'aroma'             => $row['aroma'] ?? null,
                'effect'            => $row['effect'] ?? null,
                'active_ingredients' => $row['active'] ?? null,
                'rating'            => $row['rating'],
                'reviews_count'     => $row['reviews'],
                'reviews_source'    => 'Ozon',
                'sort'              => $sort += 10,
                'is_active'         => true,
                'published_at'      => now(),
            ]);

            $product->purposes()->sync(collect($row['purposes'])->map(fn ($c) => $purposes[$c]->id)->all());
            $product->tasks()->sync(collect($row['tasks'])->map(fn ($c) => $tasks[$c]->id)->all());

            ProductImage::updateOrCreate(
                ['product_id' => $product->id, 'path' => 'img/catalog/' . $row['image']],
                ['alt' => $row['title'], 'is_primary' => true, 'sort' => 0],
            );

            $vSort = 0;

            foreach ($row['variants'] as $i => $v) {
                $variant = ProductVariant::updateOrCreate(
                    [
                        'product_id'    => $product->id,
                        'option_volume' => $v['volume'],
                        'option_aroma'  => $v['aroma'] ?? null,
                    ],
                    [
                        'sku'        => Str::upper(Str::substr(md5($row['slug'] . $v['volume']), 0, 10)),
                        'volume_ml'  => $v['ml'] ?? null,
                        'price'      => $v['price'],
                        'weight_g'   => $v['weight'],
                        'length_mm'  => $v['dims'][0],
                        'width_mm'   => $v['dims'][1],
                        'height_mm'  => $v['dims'][2],
                        'is_default' => $v['default'] ?? false,
                        'sort'       => $vSort += 10,
                        'is_active'  => true,
                    ],
                );

                // Квота сайта: стартовое наполнение, дальше менеджер пополняет из админки.
                StockQuota::updateOrCreate(
                    ['product_variant_id' => $variant->id],
                    ['allocated' => 25, 'low_threshold' => 5, 'allocated_at' => now()],
                );
            }
        }
    }

    private function seedReviews(): void
    {
        $rows = [
            [
                'slug'   => 'multipenka-dlya-ruk-i-stop',
                'author' => 'Алина',
                'text'   => 'Пенка всегда стоит у рабочего стола: очищает быстро, не липнет и не сушит. Один из тех продуктов, которые заканчиваются первыми.',
            ],
            [
                'slug'   => 'krem-dlya-ruk-i-stop-25-urea',
                'author' => 'Мария',
                'text'   => 'С кремом уход наконец стал регулярным: быстро впитывается, а стопы заметно мягче уже после первых применений.',
            ],
        ];

        $sort = 0;

        foreach ($rows as $row) {
            $product = Product::where('slug', $row['slug'])->first();

            Review::updateOrCreate(
                ['product_id' => $product?->id, 'author' => $row['author']],
                [
                    'text'         => $row['text'],
                    'rating'       => 5,
                    'source'       => 'ozon',
                    'source_label' => 'Ozon',
                    'is_featured'  => true,
                    'is_active'    => true,
                    'sort'         => $sort += 10,
                ],
            );
        }
    }

    private function seedUgc(): void
    {
        // Блок «Ты и LIVSI» — подборка изображений (видео решено не делать).
        $images = ['fresh-hero.jpg', 'sweet-portrait.jpg', 'mango-product.jpg', 'warm-portrait.jpg', 'avatar4.jpg'];
        $sort   = 0;

        foreach ($images as $image) {
            UgcItem::updateOrCreate(
                ['image_path' => 'img/catalog/' . $image],
                ['alt' => 'LIVSI', 'sort' => $sort += 10, 'is_active' => true],
            );
        }
    }

    private function seedFaq(): void
    {
        $rows = [
            ['Как быстро доставите заказ?', 'Доставляем по всей России. Итоговый срок и стоимость показываются до оплаты, бесплатная доставка — от 1 000 ₽.'],
            ['Как понять, что средство мне подходит?', 'Выбирайте по назначению и задаче: руки, стопы, тело или лицо; очищение, увлажнение, отшелушивание или размягчение. Ароматическая линейка отвечает только за запах и настроение.'],
            ['Можно ли использовать PRO дома?', 'Можно, но внимательно. Профессиональные средства работают быстрее и требуют точного соблюдения инструкции.'],
            ['Как выбрать ароматическую линейку?', 'FRESH — свежесть и цитрус, SWEET — сладкие и ягодные ароматы, WARM — тёплые и фруктовые, BASE — нейтральный уход без выраженного аромата.'],
        ];

        $sort = 0;

        foreach ($rows as [$question, $answer]) {
            FaqItem::updateOrCreate(['question' => $question], ['answer' => $answer, 'sort' => $sort += 10, 'is_active' => true]);
        }
    }

    private function seedSettings(): void
    {
        // Пороги меняются из админки. Значения по умолчанию — как в макете.
        Setting::put('free_shipping_threshold', 100000); // 1 000 ₽ в копейках
        Setting::put('gift_threshold', 500000);          // 5 000 ₽ в копейках
        Setting::put('topbar_text', 'БЕСПЛАТНАЯ ДОСТАВКА ОТ 1 000 ₽ · ДОСТАВКА ПО ВСЕЙ РОССИИ');
        Setting::put('work_hours', 'пн–пт, 09:00–18:00');
        Setting::put('telegram_url', '');
        Setting::put('whatsapp_url', '');
        // Ставку НДС подтверждает бухгалтер — см. docs/06-scope-v2.md §2.3.
        Setting::put('vat_rate_default', null);
    }

    private function seedPromoRules(): void
    {
        PromoRule::updateOrCreate(
            ['type' => 'free_shipping', 'channel' => 'retail'],
            ['title' => 'Бесплатная доставка', 'threshold' => 100000, 'is_active' => true, 'sort' => 10],
        );

        PromoRule::updateOrCreate(
            ['type' => 'gift', 'channel' => 'retail'],
            ['title' => 'Подарок к заказу', 'threshold' => 500000, 'is_active' => true, 'sort' => 20],
        );
    }

    private function seedSellerProfile(): void
    {
        // Реквизиты придут отдельным файлом — пока заглушка, помеченная как незаполненная.
        SellerProfile::updateOrCreate(
            ['code' => 'ip'],
            [
                'legal_name'      => 'ИП Волкодав Антон Андреевич',
                'address'         => 'г. Ставрополь',
                'signer_name'     => 'Волкодав А. А.',
                'signer_position' => 'Индивидуальный предприниматель',
                'is_default'      => true,
            ],
        );
    }
}
