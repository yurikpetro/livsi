<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Payments\CloudKassir;
use App\Payments\PaymentSettings;
use App\Payments\TochkaClient;
use App\Payments\TochkaGateway;
use App\Payments\Vat;
use App\Payments\YooKassaGateway;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
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
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use RuntimeException;
use Throwable;

/**
 * «Интеграции оплаты»: кто принимает деньги (ЮKassa или Точка Банк),
 * ключи Точки и кассы CloudKassir, проверка связи и тестовый платёж 10 ₽.
 *
 * Переключиться на Точку можно только когда всё готово: ключ и код
 * компании заданы, ставка НДС понятна Точке. Иначе покупатель увидел бы
 * «оплата недоступна» вместо оплаты.
 */
class PaymentIntegrations extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|\UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?int $navigationSort = 92;

    protected static ?string $navigationLabel = 'Интеграции оплаты';

    protected static ?string $title = 'Интеграции оплаты';

    protected static ?string $slug = 'payment-integrations';

    protected string $view = 'filament.pages.settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public function mount(): void
    {
        $this->form->fill([
            'provider' => PaymentSettings::provider(),
            'tax_system' => PaymentSettings::taxSystem(),
            'tochka_client_id' => PaymentSettings::clientId(),
            'tochka_customer_code' => PaymentSettings::customerCode(),
            'tochka_merchant_id' => PaymentSettings::merchantId(),
            'tochka_modes' => PaymentSettings::paymentModes(),
            'cloudkassir_public_id' => PaymentSettings::cloudKassirPublicId(),
            'cloudkassir_inn' => PaymentSettings::inn(),
            'cloudkassir_refund_receipts' => PaymentSettings::siteRefundReceipts(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Кто принимает оплату')
                    ->schema([
                        Placeholder::make('state')->label('Сейчас')->content(fn (): HtmlString => $this->stateHtml())->columnSpanFull(),
                        Radio::make('provider')->label('Новые заказы оплачиваются через')
                            ->options([PaymentSettings::YOOKASSA => 'ЮKassa', PaymentSettings::TOCHKA => 'Точка Банк'])
                            ->required()
                            ->helperText('Касается только новых заказов. Заказы, оплаченные через ЮKassa, и дальше сверяются, закрываются вторым чеком и возвращаются через ЮKassa. Перед переключением сделайте тестовый платёж 10 ₽ ниже.'),
                        Select::make('tax_system')->label('Система налогообложения')
                            ->options(array_map(fn (array $row): string => $row['label'], PaymentSettings::TAX_SYSTEMS))
                            ->native(false)
                            ->helperText('Уходит в чеки Точки и CloudKassir. Должна совпадать с той, что указана при регистрации кассы.'),
                    ])
                    ->columns(2),

                Section::make('Точка Банк — интернет-эквайринг')
                    ->description('Покупатель платит по ссылке Точки (СБП или картой). Первый чек «предоплата» пробивает касса, подключённая к эквайрингу.')
                    ->schema([
                        TextInput::make('tochka_jwt')->label('JWT-ключ')
                            ->password()->revealable()->autocomplete('new-password')->maxLength(5000)
                            ->placeholder(fn (): string => PaymentSettings::secret('tochka_jwt') ? 'задан — пусто, чтобы не менять' : 'не задан')
                            ->helperText('Интернет-банк Точки → «Интеграции и API» → «Подключить» → «Сгенерировать JWT-ключ». Доступы: интернет-эквайринг, информация о компании, вебхуки. Ключ вставляйте только сюда — не пересылайте его в переписке.')
                            ->columnSpanFull(),
                        TextInput::make('tochka_client_id')->label('client_id')->maxLength(100)
                            ->helperText('Показывается на том же экране, что и ключ. Нужен для уведомлений об оплате. Перевыпустили ключ — client_id тоже новый: вставьте его и снова нажмите «Подключить уведомления».'),
                        TextInput::make('tochka_customer_code')->label('Код компании (customerCode)')->maxLength(9)
                            ->helperText('9 цифр. Кнопка «Найти код компании» возьмёт его по ключу.'),
                        TextInput::make('tochka_merchant_id')->label('ID торговой точки (merchantId)')->maxLength(15)
                            ->helperText('Нужен, только если торговых точек несколько — «Проверить связь» их покажет.'),
                        CheckboxList::make('tochka_modes')->label('Способы оплаты')
                            ->options(PaymentSettings::TOCHKA_MODES)
                            ->helperText('Т-Pay и Долями работают, только если подключены в интернет-банке.'),
                        Actions::make([
                            Action::make('findCustomer')->label('Найти код компании')->icon(Heroicon::OutlinedMagnifyingGlass)->color('gray')
                                ->action(fn () => $this->findCustomer()),
                            Action::make('checkTochka')->label('Проверить связь')->icon(Heroicon::OutlinedSignal)->color('gray')
                                ->action(fn () => $this->checkTochka()),
                            Action::make('subscribe')->label('Подключить уведомления об оплате')->icon(Heroicon::OutlinedBellAlert)->color('gray')
                                ->action(fn () => $this->subscribe()),
                        ])->columnSpanFull(),
                        Placeholder::make('test_state')->label('Тестовый платёж')
                            ->content(fn (): HtmlString => $this->testHtml())->columnSpanFull(),
                        Actions::make([
                            Action::make('testPay')->label('Ссылка на тестовый платёж 10 ₽')->icon(Heroicon::OutlinedLink)->color('gray')
                                ->action(fn () => $this->testPayment()),
                            Action::make('testCheck')->label('Проверить тестовый платёж')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                                ->action(fn () => $this->testCheck()),
                            Action::make('testRefund')->label('Вернуть тестовые 10 ₽')->icon(Heroicon::OutlinedReceiptRefund)->color('gray')
                                ->requiresConfirmation()->modalHeading('Вернуть 10 ₽ тестового платежа?')
                                ->action(fn () => $this->testRefund()),
                        ])->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->collapsible(),

                Section::make('CloudKassir — второй чек и чеки возврата')
                    ->description('Когда оплату принимает Точка, второй чек «полный расчёт» с кодами маркировки сайт пробивает сам через CloudKassir.')
                    ->schema([
                        TextInput::make('cloudkassir_public_id')->label('Public ID')->maxLength(100)
                            ->helperText('Кабинет CloudKassir → «Настройки» → «Доступ к API».'),
                        TextInput::make('cloudkassir_secret')->label('API Secret')
                            ->password()->revealable()->autocomplete('new-password')->maxLength(200)
                            ->placeholder(fn (): string => PaymentSettings::secret('cloudkassir_secret') ? 'задан — пусто, чтобы не менять' : 'не задан')
                            ->helperText('Там же, где Public ID.'),
                        TextInput::make('cloudkassir_inn')->label('ИНН, на который зарегистрирована касса')->maxLength(12),
                        Toggle::make('cloudkassir_refund_receipts')->label('Чеки возврата пробивает сайт')
                            ->helperText('Выключено — чек возврата пробивает связка Точка — CloudKassir (или вы вручную). Сделайте тестовый платёж и верните его: если в кабинете CloudKassir сам появился чек «Возврат прихода» на 10 ₽ — оставьте выключенным; если не появился — включите.')
                            ->columnSpanFull(),
                        Actions::make([
                            Action::make('checkKassa')->label('Проверить связь с CloudKassir')->icon(Heroicon::OutlinedSignal)->color('gray')
                                ->action(fn () => $this->checkCloudKassir()),
                        ])->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->collapsible(),
            ])
            ->statePath('data');
    }

    // --------------------------------------------------------------- состояние

    private function stateHtml(): HtmlString
    {
        $provider = PaymentSettings::provider();
        $tochka = app(TochkaClient::class)->isConfigured();
        $kassa = app(CloudKassir::class)->isConfigured();
        $webhook = PaymentSettings::option('tochka_webhook_at');
        $closing = (bool) Setting::get('closing_receipts_enabled', false);

        $lines = [
            '<b>Оплата: '.e(PaymentSettings::providerLabel($provider)).'</b>',
            'ЮKassa: '.(app(YooKassaGateway::class)->isConfigured() ? 'ключи заданы на сервере' : 'ключи не заданы'),
            'Точка: '.($tochka ? 'ключ и код компании заданы' : 'не настроена')
                .($webhook ? ' · уведомления подключены '.e(Carbon::parse($webhook)->timezone('Europe/Moscow')->format('d.m H:i')) : ' · уведомления не подключены'),
            'CloudKassir: '.($kassa ? 'ключи заданы' : 'не настроен'),
        ];
        if ($provider === PaymentSettings::TOCHKA && $closing && ! $kassa) {
            $lines[] = '<span style="color:#dc2626">Второй чек включён, а ключи CloudKassir не заданы — заказы, оплаченные через Точку, ждут второго чека.</span>';
        }

        return new HtmlString('<div style="line-height:1.7">'.implode('<br>', $lines).'</div>');
    }

    private function testHtml(): HtmlString
    {
        $test = (array) PaymentSettings::option('tochka_test', []);
        if (blank($test['operation'] ?? null)) {
            return new HtmlString('Ещё не было. Нажмите «Ссылка на тестовый платёж 10 ₽», оплатите по ссылке, затем «Проверить тестовый платёж» и «Вернуть тестовые 10 ₽».');
        }

        return new HtmlString(sprintf(
            'Создан %s · статус: <b>%s</b><br><a href="%s" target="_blank" rel="noopener" style="text-decoration:underline">Открыть страницу оплаты</a>',
            e(Carbon::parse((string) $test['at'])->timezone('Europe/Moscow')->format('d.m H:i')),
            e(self::statusText((string) ($test['status'] ?? 'CREATED'))),
            e((string) ($test['link'] ?? '#')),
        ));
    }

    private static function statusText(string $status): string
    {
        return match (strtoupper($status)) {
            'CREATED' => 'ждёт оплаты',
            'APPROVED' => 'оплачен',
            'ON-REFUND' => 'возвращается',
            'REFUNDED' => 'возвращён',
            'REFUNDED_PARTIALLY' => 'возвращён частично',
            'EXPIRED' => 'ссылка истекла',
            default => $status,
        };
    }

    // ------------------------------------------------------------------ Точка

    public function findCustomer(): void
    {
        $this->saveKeys();
        if (PaymentSettings::secret('tochka_jwt') === null) {
            Notification::make()->title('Сначала вставьте JWT-ключ Точки')->warning()->send();

            return;
        }
        try {
            $business = array_values(array_filter(app(TochkaClient::class)->customers(),
                fn (array $row): bool => ($row['customerType'] ?? '') === 'Business'));
        } catch (Throwable $exception) {
            Notification::make()->title('Точка не ответила')->body(mb_substr($exception->getMessage(), 0, 300))->danger()->send();

            return;
        }
        if ($business === []) {
            Notification::make()->title('Ключ не видит ни одной компании')->body('Проверьте, что у ключа есть право «Информация о компании».')->warning()->send();

            return;
        }
        $code = (string) $business[0]['customerCode'];
        $this->data['tochka_customer_code'] = $code;
        PaymentSettings::setOptions(['tochka_customer_code' => $code]);
        $names = array_map(fn (array $row): string => $row['customerCode'].' — '.($row['shortName'] ?? $row['fullName'] ?? ''), $business);
        Notification::make()->title('Код компании: '.$code)
            ->body(count($business) > 1 ? 'Компаний несколько: '.implode('; ', $names).'. Если нужна другая — впишите её код.' : ($names[0] ?? ''))
            ->success()->send();
    }

    public function checkTochka(): void
    {
        $this->saveKeys();
        $client = app(TochkaClient::class);
        if (! $client->isConfigured()) {
            Notification::make()->title('Задайте JWT-ключ и код компании')->warning()->send();

            return;
        }
        try {
            $retailers = $client->retailers();
        } catch (Throwable $exception) {
            Notification::make()->title('Точка не ответила')->body(mb_substr($exception->getMessage(), 0, 300))->danger()->send();

            return;
        }
        if ($retailers === []) {
            Notification::make()->title('Связь есть, но торговых точек нет')->body('Интернет-эквайринг ещё не подключён — заявка в интернет-банке: «Сервисы» → «Интернет-эквайринг».')->warning()->send();

            return;
        }
        $lines = array_map(fn (array $row): string => sprintf('%s · %s · %s · ID %s · способы: %s · касса: %s',
            $row['name'] ?? 'точка', ($row['status'] ?? '') === 'REG' ? 'зарегистрирована' : 'статус '.($row['status'] ?? '?'),
            ($row['isActive'] ?? false) ? 'работает' : 'не активна', $row['merchantId'] ?? '—',
            implode(', ', (array) ($row['paymentModes'] ?? [])) ?: '—', ($row['cashbox'] ?? null) ?: 'не указана'), $retailers);
        $ready = array_filter($retailers, fn (array $row): bool => ($row['status'] ?? '') === 'REG' && ($row['isActive'] ?? false));
        $note = count($retailers) > 1 && PaymentSettings::merchantId() === null ? "\nТорговых точек несколько — впишите ID нужной." : '';
        Notification::make()->title($ready ? 'Точка: эквайринг подключён' : 'Точка: эквайринг ещё не готов')
            ->body(implode("\n", $lines).$note)->{$ready ? 'success' : 'warning'}()->persistent()->send();
    }

    public function subscribe(): void
    {
        $this->saveKeys();
        $clientId = PaymentSettings::clientId();
        if (PaymentSettings::secret('tochka_jwt') === null || $clientId === null) {
            Notification::make()->title('Задайте JWT-ключ и client_id')->warning()->send();

            return;
        }
        $url = route('webhooks.tochka');
        if (! str_starts_with($url, 'https://')) {
            Notification::make()->title('Адрес сайта не https')->body('Точка шлёт уведомления только на https. Адрес сейчас: '.$url)->danger()->send();

            return;
        }
        try {
            app(TochkaClient::class)->subscribe($clientId, $url);
        } catch (Throwable $exception) {
            Notification::make()->title('Уведомления не подключены')->body(mb_substr($exception->getMessage(), 0, 300))->danger()->persistent()->send();

            return;
        }
        PaymentSettings::setOptions(['tochka_webhook_at' => now()->toIso8601String(), 'tochka_webhook_url' => $url]);
        Notification::make()->title('Уведомления об оплате подключены')->body('Точка будет сообщать об оплате на '.$url)->success()->send();
    }

    public function testPayment(): void
    {
        $this->saveKeys();
        $client = app(TochkaClient::class);
        if (! $client->isConfigured()) {
            Notification::make()->title('Задайте JWT-ключ и код компании')->warning()->send();

            return;
        }
        try {
            $vat = TochkaGateway::vatType(Vat::code(Vat::defaultRate()));
            $email = (string) auth()->user()?->email;
            $answer = $client->createPayment(array_filter([
                'customerCode' => PaymentSettings::customerCode(),
                'merchantId' => PaymentSettings::merchantId(),
                'amount' => 10,
                'purpose' => 'Проверка оплаты на сайте',
                'paymentMode' => PaymentSettings::paymentModes(),
                'redirectUrl' => url('/admin/'.self::$slug),
                'ttl' => 120,
                'paymentLinkId' => 'test-'.now()->format('ymdHis'),
                'taxSystemCode' => PaymentSettings::taxSystem(),
                'Client' => ['email' => $email],
                'Items' => [['name' => 'Проверка приёма оплаты', 'amount' => 10, 'quantity' => 1, 'vatType' => $vat,
                    'paymentMethod' => 'full_payment', 'paymentObject' => 'service', 'measure' => 'шт.']],
            ], fn ($value) => $value !== null));
        } catch (Throwable $exception) {
            Notification::make()->title('Ссылка не создана')->body(mb_substr($exception->getMessage(), 0, 300))->danger()->persistent()->send();

            return;
        }
        if (blank($answer['operationId'] ?? null) || blank($answer['paymentLink'] ?? null)) {
            Notification::make()->title('Точка не вернула ссылку')->danger()->send();

            return;
        }
        PaymentSettings::setOptions(['tochka_test' => ['operation' => $answer['operationId'], 'link' => $answer['paymentLink'],
            'at' => now()->toIso8601String(), 'status' => $answer['status'] ?? 'CREATED']]);
        Notification::make()->title('Ссылка на 10 ₽ готова')
            ->body(new HtmlString('<a href="'.e($answer['paymentLink']).'" target="_blank" rel="noopener" style="text-decoration:underline">Открыть страницу оплаты</a>. Чек придёт на '.e($email).'.'))
            ->success()->persistent()->send();
    }

    public function testCheck(): void
    {
        $test = (array) PaymentSettings::option('tochka_test', []);
        if (blank($test['operation'] ?? null)) {
            Notification::make()->title('Тестового платежа ещё не было')->warning()->send();

            return;
        }
        try {
            $operation = app(TochkaClient::class)->payment((string) $test['operation']);
        } catch (Throwable $exception) {
            Notification::make()->title('Точка не ответила')->body(mb_substr($exception->getMessage(), 0, 300))->danger()->send();

            return;
        }
        $status = (string) ($operation['status'] ?? '');
        PaymentSettings::setOptions(['tochka_test' => array_merge($test, ['status' => $status])]);
        $webhook = PaymentSettings::option('tochka_webhook_at') ? '' : ' Уведомления об оплате не подключены — подключите их кнопкой выше.';
        Notification::make()->title('Тестовый платёж: '.self::statusText($status))
            ->body(($operation['paymentType'] ?? null) ? 'Способ: '.$operation['paymentType'].'.'.$webhook : trim($webhook))
            ->{$status === 'APPROVED' ? 'success' : 'info'}()->send();
    }

    public function testRefund(): void
    {
        $test = (array) PaymentSettings::option('tochka_test', []);
        if (blank($test['operation'] ?? null)) {
            Notification::make()->title('Тестового платежа ещё не было')->warning()->send();

            return;
        }
        $client = app(TochkaClient::class);
        try {
            $operation = $client->payment((string) $test['operation']);
            if (($operation['status'] ?? '') !== 'APPROVED') {
                throw new RuntimeException('Платёж в статусе «'.self::statusText((string) ($operation['status'] ?? '')).'» — вернуть можно только оплаченный.');
            }
            $client->refund((string) $test['operation'], 1000);
        } catch (Throwable $exception) {
            Notification::make()->title('Не вернули')->body(mb_substr($exception->getMessage(), 0, 300))->danger()->send();

            return;
        }
        PaymentSettings::setOptions(['tochka_test' => array_merge($test, ['status' => 'REFUNDED'])]);
        Notification::make()->title('10 ₽ возвращены')
            ->body('Через пару минут откройте кабинет CloudKassir: если там сам появился чек «Возврат прихода» на 10 ₽ — оставьте «Чеки возврата пробивает сайт» выключенным. Если не появился — включите и сохраните.')
            ->success()->persistent()->send();
    }

    // -------------------------------------------------------------- CloudKassir

    public function checkCloudKassir(): void
    {
        $this->saveKeys();
        $kassa = app(CloudKassir::class);
        if (! $kassa->isConfigured()) {
            Notification::make()->title('Задайте Public ID, API Secret и ИНН')->warning()->send();

            return;
        }
        try {
            $kassa->ping();
        } catch (Throwable $exception) {
            Notification::make()->title('CloudKassir не ответил')->body(mb_substr($exception->getMessage(), 0, 300))->danger()->send();

            return;
        }
        Notification::make()->title('CloudKassir: ключи приняты')
            ->body(PaymentSettings::taxSystem() === null ? 'Укажите систему налогообложения — без неё касса может не принять чек.' : 'Связь есть.')
            ->success()->send();
    }

    // -------------------------------------------------------------- сохранение

    /** Ключи и параметры — сразу, чтобы кнопки проверки работали до «Сохранить». */
    private function saveKeys(): void
    {
        $data = $this->data ?? [];
        PaymentSettings::setSecrets(['tochka_jwt' => $data['tochka_jwt'] ?? null, 'cloudkassir_secret' => $data['cloudkassir_secret'] ?? null]);
        $this->data['tochka_jwt'] = null;
        $this->data['cloudkassir_secret'] = null;
        PaymentSettings::setOptions([
            'tax_system' => isset(PaymentSettings::TAX_SYSTEMS[$data['tax_system'] ?? '']) ? $data['tax_system'] : null,
            'tochka_client_id' => trim((string) ($data['tochka_client_id'] ?? '')),
            'tochka_customer_code' => preg_replace('/\D/', '', (string) ($data['tochka_customer_code'] ?? '')),
            'tochka_merchant_id' => preg_replace('/\D/', '', (string) ($data['tochka_merchant_id'] ?? '')),
            'tochka_modes' => array_values(array_intersect(array_keys(PaymentSettings::TOCHKA_MODES), (array) ($data['tochka_modes'] ?? []))),
            'cloudkassir_public_id' => trim((string) ($data['cloudkassir_public_id'] ?? '')),
            'cloudkassir_inn' => preg_replace('/\D/', '', (string) ($data['cloudkassir_inn'] ?? '')),
            'cloudkassir_refund_receipts' => (bool) ($data['cloudkassir_refund_receipts'] ?? false),
        ]);
    }

    public function save(): void
    {
        $this->data = $this->form->getState();
        $this->saveKeys();

        $problems = [];
        $code = PaymentSettings::customerCode();
        if ($code !== null && strlen($code) !== 9) {
            $problems[] = 'Код компании Точки — 9 цифр.';
        }
        $merchant = PaymentSettings::merchantId();
        if ($merchant !== null && strlen($merchant) !== 15) {
            $problems[] = 'ID торговой точки — 15 цифр.';
        }
        $inn = PaymentSettings::inn();
        if ($inn !== null && ! in_array(strlen($inn), [10, 12], true)) {
            $problems[] = 'ИНН — 10 или 12 цифр.';
        }

        $want = ($this->data['provider'] ?? null) === PaymentSettings::TOCHKA ? PaymentSettings::TOCHKA : PaymentSettings::YOOKASSA;
        if ($want === PaymentSettings::TOCHKA && PaymentSettings::provider() !== PaymentSettings::TOCHKA) {
            $why = $this->notReady();
            if ($why !== null) {
                $problems[] = 'Оплата осталась через ЮKassa: '.$why;
                $want = PaymentSettings::YOOKASSA;
                $this->data['provider'] = $want;
            }
        }
        PaymentSettings::setProvider($want);

        if ($problems) {
            Notification::make()->title('Сохранено, но не всё')->body(implode("\n", $problems))->warning()->persistent()->send();
        } else {
            Notification::make()->title('Сохранено')->body('Оплата: '.PaymentSettings::providerLabel())->success()->send();
        }
    }

    private function notReady(): ?string
    {
        if (! app(TochkaClient::class)->isConfigured()) {
            return 'не заданы JWT-ключ и код компании Точки.';
        }
        if (! Vat::isConfigured()) {
            return 'не задана ставка НДС («Настройки сайта» → «Налоги и чеки»).';
        }
        try {
            TochkaGateway::vatType(Vat::code(Vat::defaultRate()));
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([Action::make('save')->label('Сохранить')->submit('save')])
                        ->alignment(Alignment::Start)->sticky()->key('form-actions'),
                ]),
        ]);
    }
}
