<?php

namespace App\Providers;

use Filament\Forms\Components\Field;
use Filament\Tables\Columns\Column;
use Illuminate\Support\ServiceProvider;

/**
 * Filament формирует подписи полей из имён колонок по-английски.
 * Вместо того чтобы расставлять ->label() в двенадцати ресурсах, держим
 * один словарь и применяем его ко всем полям форм и колонкам таблиц.
 */
class FilamentLabelsServiceProvider extends ServiceProvider
{
    /** @var array<string, string> */
    public const LABELS = [
        // общее
        'title'              => 'Название',
        'slug'               => 'Адрес (slug)',
        'code'               => 'Код',
        'subtitle'           => 'Подзаголовок',
        'description'        => 'Описание',
        'sort'               => 'Порядок',
        'is_active'          => 'Активен',
        'created_at'         => 'Создано',
        'updated_at'         => 'Обновлено',
        'published_at'       => 'Опубликовано',
        'image_path'         => 'Изображение',
        'alt'                => 'Альт-текст',
        'comment'            => 'Комментарий',
        'status'             => 'Статус',
        'type'               => 'Тип',
        'email'              => 'E-mail',
        'phone'              => 'Телефон',
        'address'            => 'Адрес',

        // товар
        'short_description'  => 'Краткое описание',
        'badge'              => 'Бейдж',
        'product_line_id'    => 'Линейка',
        'is_pro'             => 'Для мастеров (PRO)',
        'is_bundle'          => 'Это набор',
        'aroma'              => 'Аромат',
        'effect'             => 'Эффект',
        'composition'        => 'Состав (INCI)',
        'application'        => 'Способ применения',
        'active_ingredients' => 'Действующие вещества',
        'shelf_life'         => 'Срок годности',
        'documents'          => 'Документы соответствия',
        'rating'             => 'Рейтинг',
        'reviews_count'      => 'Количество отзывов',
        'reviews_source'     => 'Площадка-источник',
        'vat_rate'           => 'Ставка НДС, %',
        'seo_title'          => 'SEO: заголовок',
        'seo_description'    => 'SEO: описание',
        'color_bg'           => 'Цвет фона',
        'color_ink'          => 'Цвет текста',

        // вариант
        'product_id'         => 'Товар',
        'sku'                => 'Артикул',
        'barcode'            => 'Штрихкод',
        'option_volume'      => 'Объём',
        'option_aroma'       => 'Аромат варианта',
        'volume_ml'          => 'Объём, мл',
        'price'              => 'Цена, копейки',
        'compare_at_price'   => 'Цена до, копейки',
        'weight_g'           => 'Вес, г',
        'length_mm'          => 'Длина, мм',
        'width_mm'           => 'Ширина, мм',
        'height_mm'          => 'Высота, мм',
        'is_default'         => 'По умолчанию',

        // квота склада
        'product_variant_id' => 'Вариант',
        'allocated'          => 'Выделено сайту',
        'reserved'           => 'В резерве',
        'sold'               => 'Продано',
        'low_threshold'      => 'Порог уведомления',
        'allocated_at'       => 'Пополнено',

        // отзывы
        'author'             => 'Автор',
        'text'               => 'Текст',
        'source'             => 'Источник',
        'source_label'       => 'Подпись источника',
        'is_featured'        => 'Показывать на главной',
        'published_on'       => 'Дата отзыва',

        // FAQ
        'question'           => 'Вопрос',
        'answer'             => 'Ответ',

        // промо
        'threshold'          => 'Порог, копейки',
        'channel'            => 'Канал',
        'payload'            => 'Параметры',
        'starts_at'          => 'Действует с',
        'ends_at'            => 'Действует по',
        'promo_rule_id'      => 'Промо-правило',

        // заявки
        'name'               => 'Имя',
        'contact'            => 'Телефон или Telegram',
        'city'               => 'Город',
        'sales_format'       => 'Формат продаж',
        'product_category'   => 'Категория продукта',
        'planned_volume'     => 'Планируемый объём',
        'consent_at'         => 'Согласие получено',
        'ip'                 => 'IP',
        'user_agent'         => 'User-Agent',
        'utm'                => 'UTM-метки',
        'manager_comment'    => 'Комментарий менеджера',

        // реквизиты
        'legal_name'         => 'Наименование',
        'inn'                => 'ИНН',
        'kpp'                => 'КПП',
        'ogrn'               => 'ОГРН / ОГРНИП',
        'bank_name'          => 'Банк',
        'bank_bic'           => 'БИК',
        'bank_account'       => 'Расчётный счёт',
        'bank_corr_account'  => 'Корреспондентский счёт',
        'signer_name'        => 'Подписант',
        'signer_position'    => 'Должность подписанта',
    ];

    public function boot(): void
    {
        Field::configureUsing(function (Field $field): void {
            if ($label = self::LABELS[$field->getName()] ?? null) {
                $field->label($label);
            }
        });

        Column::configureUsing(function (Column $column): void {
            if ($label = self::LABELS[$column->getName()] ?? null) {
                $column->label($label);
            }
        });
    }
}