<?php

namespace App\Filament\Pages;

use App\Filament\Support\MoneyField;
use App\Models\Setting;
use App\Payments\Vat;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * Настройки сайта.
 *
 * Заказчику обещано, что пороги подарка и бесплатной доставки он меняет сам
 * (docs/07-design-review.md § 8, п. 5). До этого таблица `settings` правилась
 * только из кода, то есть требовала разработчика — то самое, от чего мы
 * уходили, отказываясь от значений в `.env`.
 *
 * Пороги живут в двух местах по-разному: `settings` — то, что показывает
 * витрина (прогресс до бесплатной доставки), а `promo_rules` — сами правила
 * со сроками и подарками. Здесь правится первое, и страница честно говорит,
 * где лежит второе.
 */
class Settings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|\UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?int $navigationSort = 90;

    protected static ?string $navigationLabel = 'Настройки сайта';

    protected static ?string $title = 'Настройки сайта';

    protected string $view = 'filament.pages.settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    /**
     * Ключи, которыми управляет этот экран. Значения по умолчанию нужны,
     * чтобы форма не падала на свежей базе без сидера.
     */
    public const KEYS = [
        'delivery_calculation_enabled' => false,
        'delivery_calculation_test' => false,
        'delivery_warehouse_name' => '',
        'delivery_sender_phone' => '',
        'delivery_origin_address' => '',
        'delivery_pickup_type' => null,
        'delivery_origin_points' => [],
        'delivery_padding_mm' => null,
        'delivery_packaging_weight_g' => null,
        'stavropol_courier_enabled' => false,
        'stavropol_courier_price' => 19900,
        'stavropol_courier_note' => 'Привезём сегодня или завтра. Курьер позвонит перед выездом.',
        'free_shipping_threshold' => 100000,
        'gift_threshold' => 500000,
        'topbar_text' => '',
        'work_hours' => '',
        'manager_email' => '',
        'telegram_url' => '',
        'whatsapp_url' => '',
        'vk_url' => '',
        'wildberries_url' => '',
        'ozon_url' => '',
        'yandex_verification' => '',
        'vat_not_payer' => false,
        'vat_rate_default' => null,
        'vat_codes' => Vat::DEFAULT_CODES,
        'closing_receipts_enabled' => false,
        'reviews_eyebrow' => 'Отзывы покупателей',
        'reviews_score' => '4.9',
        'reviews_score_caption' => 'Средняя оценка',
        'reviews_score_note' => 'По отзывам покупателей LIVSI',
    ];

    public function mount(): void
    {
        $values = [];

        foreach (self::KEYS as $key => $default) {
            $values[$key] = Setting::get($key, $default);
        }

        $this->form->fill($values);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Расчёт доставки')
                    ->description('СДЭК, Яндекс и Ozon: товары стоят вертикально в один ряд, ширина суммируется, длина и высота берутся максимальные. Прибавляются запас с каждой стороны и масса упаковки. Укажите проверенные параметры; итоговая коробка должна соответствовать расчёту.')
                    ->schema([
                        Toggle::make('delivery_calculation_enabled')->label('Автоматический расчёт')->live(),
                        Toggle::make('delivery_calculation_test')->label('Тестировать с временными габаритами')
                            ->helperText('Разрешает использовать неподтверждённые размеры только при тестовом ключе оплаты. Реальные замеры подтвердите в карточках вариантов, затем выключите этот режим.'),
                        TextInput::make('delivery_warehouse_name')->label('Название склада отгрузки')->maxLength(120)
                            ->helperText('Например, название вашего склада в ApiShip. Адрес и телефон ниже используются для всех отправлений.'),
                        TextInput::make('delivery_sender_phone')->label('Телефон склада')->tel()->telRegex('/^[+\d\s().-]+$/u')->maxLength(30)
                            ->helperText('Подставляется при создании отправления. Если пусто — используется телефон из реквизитов продавца.'),
                        TextInput::make('delivery_origin_address')->label('Полный адрес склада отгрузки')->maxLength(500)
                            ->helperText('Город, улица и дом. Используется для расчёта доставки и подставляется в отправления автоматически.')
                            ->required(fn (Get $get): bool => (bool) $get('delivery_calculation_enabled')),
                        Select::make('delivery_pickup_type')->label('Передача перевозчику')
                            ->options([1 => 'Забор курьером со склада', 2 => 'Сдаём в пункт приёма'])
                            ->required(fn (Get $get): bool => (bool) $get('delivery_calculation_enabled')),
                        KeyValue::make('delivery_origin_points')->label('ID пунктов приёма ApiShip по службам')
                            ->helperText('При самостоятельной сдаче: ключ cdek или yataxi, значение — ID пункта приёма ApiShip.'),
                        Placeholder::make('delivery_direct_note')->label('СДЭК, Яндекс и Ozon напрямую')
                            ->content(fn () => new HtmlString('Режим, ключи и пункты сдачи посылок — на странице <a class="underline font-semibold" href="'.e(DeliveryIntegrations::getUrl()).'">«Интеграции доставки»</a>.')),
                        TextInput::make('delivery_padding_mm')->label('Запас упаковки с каждой стороны, мм')->integer()->minValue(0)->maxValue(1000)
                            ->required(fn (Get $get): bool => (bool) $get('delivery_calculation_enabled')),
                        TextInput::make('delivery_packaging_weight_g')->label('Масса коробки и защиты, г')->integer()->minValue(0)->maxValue(100000)
                            ->required(fn (Get $get): bool => (bool) $get('delivery_calculation_enabled')),
                    ])->columns(2),
                Section::make('Курьер по Ставрополю')
                    ->description('Покупатель из Ставрополя может выбрать курьера вместо пункта выдачи. Курьера вызываете вы (например, через Яндекс Go); в livsipro у такого заказа адрес и телефон, кнопки «Передан курьеру» и «Доставлен». Бесплатно — по тем же правилам, что и доставка в пункт: от порога или с промокодом.')
                    ->schema([
                        Toggle::make('stavropol_courier_enabled')->label('Курьер по Ставрополю доступен')->live(),
                        MoneyField::make('stavropol_courier_price', 'Стоимость для покупателя')
                            ->required(fn (Get $get): bool => (bool) $get('stavropol_courier_enabled'))
                            ->helperText('В рублях.'),
                        Textarea::make('stavropol_courier_note')->label('Что увидит покупатель под ценой')->rows(2)->maxLength(300)
                            ->columnSpanFull(),
                    ])->columns(2),
                Section::make('Пороги и промо')
                    ->description('Числа, которые видит покупатель в корзине: прогресс до бесплатной доставки и до подарка. Сами правила — со сроками действия и списком подарков на выбор — в разделе «Промо-правила».')
                    ->schema([
                        MoneyField::make('free_shipping_threshold', 'Бесплатная доставка от')
                            ->required()
                            ->helperText('В рублях. Показывается в корзине: «до бесплатной доставки — 510 ₽».'),

                        MoneyField::make('gift_threshold', 'Подарок от')
                            ->required()
                            ->helperText('В рублях. Подарок оформляется скидкой на заказ, а не позицией с нулевой ценой.'),
                    ])
                    ->columns(2),

                Section::make('Шапка и часы работы')
                    ->schema([
                        Textarea::make('topbar_text')
                            ->label('Строка над шапкой')
                            ->rows(2)
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->helperText('Бегущая строка вверху сайта. Пусто — полоса не показывается.'),

                        TextInput::make('work_hours')
                            ->label('Часы работы')
                            ->maxLength(120)
                            ->placeholder('пн–пт, 09:00–18:00')
                            ->helperText('Показывается в подвале и в подтверждении заявки.'),
                    ])
                    ->columns(2),

                Section::make('Блок отзывов на главной')
                    ->description('Шапка над карточками. Сами карточки — в разделе «Отзывы на главной».')
                    ->schema([
                        TextInput::make('reviews_eyebrow')
                            ->label('Надпись над заголовком')
                            ->maxLength(60),

                        TextInput::make('reviews_score')
                            ->label('Средняя оценка')
                            ->maxLength(4)
                            ->placeholder('4.9')
                            ->helperText('Крупная цифра слева. Пусто — весь блок с оценкой не показывается.'),

                        TextInput::make('reviews_score_caption')
                            ->label('Подпись к оценке')
                            ->maxLength(60)
                            ->placeholder('Средняя оценка'),

                        TextInput::make('reviews_score_note')
                            ->label('Уточнение под подписью')
                            ->maxLength(80)
                            ->placeholder('По отзывам покупателей LIVSI')
                            ->helperText('Здесь стоит указать, где эти отзывы собраны.'),
                    ])
                    ->columns(4),

                Section::make('Налоги и чеки')
                    ->description('Ставка нужна для кассового чека: без неё платёж не создаётся. Пустое поле означает «не выяснили», а не «ноль» — угаданная ставка в чеке хуже, чем отказ в оплате.')
                    ->schema([
                        Toggle::make('vat_not_payer')
                            ->label('Продавец не платит НДС')
                            ->live()
                            ->helperText('Тогда весь чек уходит с признаком «без НДС».'),

                        TextInput::make('vat_rate_default')
                            ->label('Ставка НДС по умолчанию, %')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->step(0.01)
                            ->visible(fn (Get $get) => ! $get('vat_not_payer'))
                            ->helperText('Применяется к товарам, у которых своя ставка не задана. На УСН это чаще всего 5 % или 7 % — зависит от годового дохода.'),

                        // Ставку в чек передают кодом, а не процентом, и коды
                        // назначает ФНС. При изменении закона появляется новый
                        // код — здесь его можно добавить, не дожидаясь выката.
                        KeyValue::make('vat_codes')
                            ->label('Коды ставок для чека')
                            ->keyLabel('Ставка, %')
                            ->valueLabel('Код в чеке')
                            ->addActionLabel('Добавить ставку')
                            ->visible(fn (Get $get) => ! $get('vat_not_payer'))
                            ->columnSpanFull()
                            ->helperText('Коды уже проставлены по документации ЮKassa: 0 % — 2, 10 % — 3, 20 % — 4, 5 % — 7, 7 % — 8, 22 % — 11. Обратите внимание: у ставки 5 % код 7, а у ставки 7 % код 8, их легко перепутать. Менять таблицу нужно только если закон введёт новую ставку — тогда добавьте строку и впишите код от провайдера. Строка с пустым кодом не работает: платёж по такой ставке не создастся.'),

                        Toggle::make('closing_receipts_enabled')
                            ->label('Второй чек «полный расчёт» с кодами маркировки')
                            ->columnSpanFull()
                            ->helperText('Когда заказ получает статус «Получен», сайт сам пробивает закрывающий чек по той же оплате: у каждой штуки — её код «Честного знака», коды выходят из оборота. Оплату ЮKassa закрывает чек ЮKassa («Чеки от ЮKassa» по ФФД 1.2), оплату Точки — чек CloudKassir (ключи — в «Интеграциях оплаты»). Заказы, полученные до включения, не трогаются.'),
                    ])
                    ->columns(2),

                Section::make('Связь')
                    ->schema([
                        TextInput::make('manager_email')
                            ->label('E-mail для заявок')
                            ->email()
                            ->maxLength(255)
                            ->helperText('Куда приходят заявки с сайта. Пусто — заявка сохранится, но письмо никто не получит.'),

                        TextInput::make('telegram_url')
                            ->label('Ссылка на Telegram')
                            ->url()
                            ->maxLength(255)
                            ->placeholder('https://t.me/...'),

                        TextInput::make('whatsapp_url')
                            ->label('Ссылка на WhatsApp')
                            ->url()
                            ->maxLength(255),
                    ])
                    ->columns(3),

                // Яндекс по этим данным понимает, что livsi.shop — официальный сайт бренда.
                Section::make('Поиск: Яндекс и официальные страницы бренда')
                    ->description('Ссылки ниже попадают в разметку главной страницы: так поисковик связывает сайт с брендом LIVSI. VK показывается и в подвале сайта.')
                    ->schema([
                        TextInput::make('yandex_verification')
                            ->label('Код подтверждения Яндекс Вебмастера')
                            ->helperText('Вебмастер → «Добавить сайт» → способ «Мета-тег»: из строки <meta name="yandex-verification" content="…"> вставьте только то, что в кавычках после content.')
                            ->maxLength(64)
                            ->regex('/^[A-Za-z0-9_-]*$/'),
                        TextInput::make('vk_url')->label('Группа VK')->url()->maxLength(255)->placeholder('https://vk.com/...'),
                        TextInput::make('wildberries_url')->label('Магазин бренда на Wildberries')->url()->maxLength(255),
                        TextInput::make('ozon_url')->label('Магазин бренда на Ozon')->url()->maxLength(255),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    /**
     * Filament 5 собирает страницу схемой, а не блейдом: форма и кнопки
     * складываются здесь, шаблон только выводит $this->content.
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make($this->getFormActions())
                        ->alignment(Alignment::Start)
                        ->sticky()
                        ->key('form-actions'),
                ]),
        ]);
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Сохранить')
                ->submit('save'),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();

        foreach (array_keys(self::KEYS) as $key) {
            Setting::put($key, $data[$key] ?? null);
        }

        Notification::make()
            ->title('Настройки сохранены')
            ->success()
            ->send();
    }
}
