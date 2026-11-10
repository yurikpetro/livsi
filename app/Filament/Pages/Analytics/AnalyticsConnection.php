<?php

namespace App\Filament\Pages\Analytics;

use App\Analytics\AnalyticsException;
use App\Analytics\AnalyticsSettings;
use App\Analytics\Metrika;
use App\Analytics\Webmaster;
use App\Analytics\YandexOAuth;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
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
use Throwable;

/**
 * «Подключение»: счётчик Метрики на сайте, доступ к Метрике и Вебмастеру
 * для отчётов в админке и код подтверждения Вебмастера.
 *
 * Всё вводит владелец сам, ключи — только сюда. В форму секреты не
 * возвращаются: «задан / не задан».
 */
class AnalyticsConnection extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static string|\UnitEnum|null $navigationGroup = AnalyticsPage::GROUP;

    protected static ?int $navigationSort = 11;

    protected static ?string $navigationLabel = 'Подключение';

    protected static ?string $title = 'Подключение аналитики';

    protected static ?string $slug = 'analytics/connection';

    protected string $view = 'filament.pages.settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** Счётчики аккаунта — запрашиваются один раз за открытие страницы. */
    private ?array $counters = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public function mount(): void
    {
        $this->form->fill([
            'oauth_client_id' => AnalyticsSettings::clientId(),
            'metrika_counter_id' => AnalyticsSettings::counterId() ? (string) AnalyticsSettings::counterId() : null,
            'metrika_counter_pick' => AnalyticsSettings::counterId() ? (string) AnalyticsSettings::counterId() : null,
            'metrika_webvisor' => AnalyticsSettings::webvisor(),
            'yandex_verification' => AnalyticsSettings::verificationCode(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $oauth = app(YandexOAuth::class);

        return $schema
            ->components([
                Section::make('Что где смотреть')
                    ->schema([
                        Placeholder::make('overview')->hiddenLabel()->content(new HtmlString(
                            '<div style="line-height:1.7">'
                            .'<b>Посещаемость</b> — сколько людей, по дням, откуда пришли, города, устройства, популярные страницы, последние визиты (Яндекс Метрика).<br>'
                            .'<b>Поиск Яндекса</b> — показы и клики в выдаче, средняя позиция, по каким запросам находят сайт, сколько страниц в поиске (Яндекс Вебмастер).<br>'
                            .'<b>Продажи и реклама</b> — заказы и выручка по источникам (считает сам сайт, работает уже сейчас) и визиты по рекламным меткам.<br>'
                            .'<span style="opacity:.75">Для первых двух нужно один раз пройти шаги ниже — около 10 минут.</span></div>'
                        ))->columnSpanFull(),
                    ]),

                Section::make('Шаг 1. Приложение Яндекса — пропуск сайта к вашей статистике')
                    ->description('Один раз. Ключи вставляйте только сюда — не пересылайте их в переписке.')
                    ->schema([
                        Placeholder::make('app_help')->hiddenLabel()->content(fn (): HtmlString => $this->appHelp())->columnSpanFull(),
                        TextInput::make('oauth_client_id')->label('ClientID')->maxLength(64)
                            ->helperText('Строка из 32 букв и цифр на странице приложения.'),
                        TextInput::make('oauth_client_secret')->label('Client secret')
                            ->password()->revealable()->autocomplete('new-password')->maxLength(64)
                            ->placeholder(fn (): string => AnalyticsSettings::secret('oauth_client_secret') ? 'задан — пусто, чтобы не менять' : 'не задан')
                            ->helperText('Там же, под ClientID. После вставки нажмите «Сохранить» внизу страницы.'),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->collapsed(fn (): bool => $oauth->connected()),

                Section::make('Шаг 2. Подключить Яндекс')
                    ->schema([
                        Placeholder::make('oauth_state')->label('Сейчас')->content(fn (): HtmlString => $this->connectionHtml())->columnSpanFull(),
                        Actions::make([
                            Action::make('connect')->label(fn (): string => $oauth->connected() ? 'Подключить заново' : 'Подключить Яндекс')
                                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                                ->url(fn (): string => route('admin.analytics.yandex.connect'), shouldOpenInNewTab: true)
                                ->visible(fn (): bool => $oauth->isConfigured()),
                            Action::make('disconnect')->label('Отключить')->icon(Heroicon::OutlinedXMark)->color('gray')
                                ->requiresConfirmation()->modalHeading('Отключить Яндекс от админки?')
                                ->modalDescription('Отчёты в «Аналитике сайта» перестанут загружаться. Счётчик на сайте продолжит считать посетителей.')
                                ->visible(fn (): bool => $oauth->connected())
                                ->action(fn () => $this->disconnect()),
                        ])->columnSpanFull(),
                        TextInput::make('oauth_code')->label('Код с экрана Яндекса')
                            ->maxLength(64)->autocomplete('off')
                            ->helperText('После «Разрешить» Яндекс показывает код подтверждения. Скопируйте его сюда и нажмите «Подтвердить код». Код действует 10 минут.')
                            ->visible(fn (): bool => $oauth->isConfigured()),
                        Actions::make([
                            Action::make('confirmCode')->label('Подтвердить код')->icon(Heroicon::OutlinedCheck)
                                ->action(fn () => $this->confirmCode()),
                        ])->visible(fn (): bool => $oauth->isConfigured()),
                    ])
                    ->columns(2),

                Section::make('Шаг 3. Счётчик Яндекс Метрики на сайте')
                    ->description('Метрика считает посетителей. Включается для тех, кто нажал «Принять все» в окне про cookie, — так обещает политика конфиденциальности.')
                    ->schema([
                        Placeholder::make('counter_help')->hiddenLabel()->content(fn (): HtmlString => $this->counterHelp())->columnSpanFull(),
                        Select::make('metrika_counter_pick')->label('Счётчик')
                            ->options(fn (): array => $this->counterOptions())
                            ->native(false)
                            ->placeholder('Выберите счётчик')
                            ->helperText('Счётчики вашего Яндекс-аккаунта. Создали новый — обновите страницу.')
                            ->visible(fn (): bool => $this->counterOptions() !== []),
                        TextInput::make('metrika_counter_id')->label('Номер счётчика')
                            ->maxLength(16)->regex('/^[\d\s]*$/')
                            ->helperText('8–9 цифр: Метрика → нужный счётчик, номер рядом с названием.')
                            ->visible(fn (): bool => $this->counterOptions() === []),
                        Toggle::make('metrika_webvisor')->label('Вебвизор — запись посещений')
                            ->helperText('Можно посмотреть, как человек листал и куда нажимал. На страницах оформления заказа, заказа и кабинета запись выключена: там личные данные.'),
                    ])
                    ->columns(2),

                Section::make('Яндекс Вебмастер — код подтверждения')
                    ->description('Права на livsi.shop уже подтверждены записью DNS. Код на сайте — запасной способ: если DNS-запись когда-нибудь удалят, права не пропадут.')
                    ->schema([
                        TextInput::make('yandex_verification')->label('Код подтверждения')
                            ->maxLength(300)
                            ->helperText('Вебмастер → шестерёнка → «Права доступа» → колонка «Метод проверки и код» (например 8cee32ceb06d67e7). Можно вставить и всю строку <meta …> — сайт возьмёт из неё код.'),
                        Placeholder::make('verification_state')->label('В Вебмастере')
                            ->content(fn (): HtmlString => $this->verificationHtml()),
                        Actions::make([
                            Action::make('takeCode')->label('Взять код из Вебмастера')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                                ->action(fn () => $this->takeCode()),
                        ])->visible(fn (): bool => $oauth->connected())->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->collapsible(),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([Action::make('save')->label('Сохранить')->submit('save')])
                        ->alignment(Alignment::Start)
                        ->sticky()
                        ->key('form-actions'),
                ]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $counter = $data['metrika_counter_pick'] ?? $data['metrika_counter_id'] ?? null;
        AnalyticsSettings::setOptions([
            'oauth_client_id' => trim((string) ($data['oauth_client_id'] ?? '')) ?: null,
            'metrika_counter_id' => preg_replace('/\D/', '', (string) $counter) ?: null,
            'metrika_webvisor' => (bool) ($data['metrika_webvisor'] ?? true),
        ]);
        AnalyticsSettings::setSecrets(['oauth_client_secret' => $data['oauth_client_secret'] ?? null]);
        AnalyticsSettings::setVerificationCode($data['yandex_verification'] ?? null);

        $this->data['oauth_client_secret'] = null;
        $this->data['yandex_verification'] = AnalyticsSettings::verificationCode();
        Metrika::forget();

        Notification::make()->title('Сохранено')
            ->body(app(YandexOAuth::class)->isConfigured() && ! app(YandexOAuth::class)->connected()
                ? 'Теперь нажмите «Подключить Яндекс» в шаге 2.' : null)
            ->success()->send();
    }

    // -------------------------------------------------------------- действия

    public function confirmCode(): void
    {
        $code = preg_replace('/\s+/', '', (string) ($this->data['oauth_code'] ?? ''));
        if ($code === '') {
            Notification::make()->title('Вставьте код с экрана Яндекса')->warning()->send();

            return;
        }

        try {
            app(YandexOAuth::class)->exchange($code);
        } catch (AnalyticsException $e) {
            Notification::make()->title('Не удалось подключить Яндекс')->body($e->getMessage())->danger()->persistent()->send();

            return;
        }

        $this->data['oauth_code'] = null;
        $this->counters = null;
        Metrika::forget();
        Webmaster::forget();

        $body = 'Осталось выбрать счётчик Метрики в шаге 3 и нажать «Сохранить».';
        try {
            $verification = app(Webmaster::class)->verification();
            if ($verification['uin'] && AnalyticsSettings::verificationCode() === null) {
                AnalyticsSettings::setVerificationCode($verification['uin']);
                $this->data['yandex_verification'] = $verification['uin'];
                $body = 'Код Вебмастера подставлен на сайт сам. '.$body;
            }
        } catch (Throwable) {
            // Причина будет видна в блоке Вебмастера.
        }

        Notification::make()->title('Яндекс подключён')->body($body)->success()->persistent()->send();
    }

    public function disconnect(): void
    {
        app(YandexOAuth::class)->disconnect();
        $this->counters = null;
        Notification::make()->title('Яндекс отключён')->success()->send();
    }

    public function takeCode(): void
    {
        try {
            $verification = app(Webmaster::class)->verification();
        } catch (AnalyticsException $e) {
            Notification::make()->title('Вебмастер не ответил')->body($e->getMessage())->danger()->send();

            return;
        }
        if (! $verification['uin']) {
            Notification::make()->title('Вебмастер не показал код')->warning()->send();

            return;
        }
        AnalyticsSettings::setVerificationCode($verification['uin']);
        $this->data['yandex_verification'] = $verification['uin'];
        Notification::make()->title('Код Вебмастера сохранён на сайте: '.$verification['uin'])->success()->send();
    }

    // ------------------------------------------------------------ подсказки

    private function appHelp(): HtmlString
    {
        $scopes = implode(', ', array_map(fn (string $s): string => '<code>'.$s.'</code>', YandexOAuth::SCOPES));

        return new HtmlString('<ol style="margin:0;padding-left:1.4em;line-height:1.75;list-style:decimal">'
            .'<li>Войдите в Яндекс тем аккаунтом, в котором добавлен сайт в Вебмастере (Livsi.shop), и откройте '
            .'<a href="https://oauth.yandex.ru/" target="_blank" rel="noopener" style="text-decoration:underline">oauth.yandex.ru</a>.</li>'
            .'<li>Нажмите <b>«Создать приложение»</b> и в окне выберите <b>«Для доступа к API или отладки»</b>.</li>'
            .'<li><b>Название вашего сервиса</b>: <code>LIVSI аналитика</code>. <b>Контактная почта</b>: ваша почта.</li>'
            .'<li>В поле доступов по очереди впишите и выберите: '.$scopes.' — статистика Метрики, данные сайта в Вебмастере и код подтверждения.</li>'
            .'<li>Нажмите <b>«Создать приложение»</b>. Откроется страница приложения: скопируйте <b>ClientID</b> и <b>Client secret</b> в поля ниже и нажмите <b>«Сохранить»</b>.</li>'
            .'</ol>');
    }

    private function counterHelp(): HtmlString
    {
        return new HtmlString('<div style="line-height:1.75">Если счётчика для livsi.shop ещё нет: '
            .'<a href="https://metrika.yandex.ru/add" target="_blank" rel="noopener" style="text-decoration:underline">metrika.yandex.ru → «Добавить счётчик»</a>'
            .' → имя <code>LIVSI</code>, адрес сайта <code>livsi.shop</code>, часовой пояс — Москва → включите «Вебвизор» → «Создать счётчик». '
            .'Код счётчика на сайт вставлять <b>не нужно</b> — сайт поставит его сам, когда счётчик выбран здесь. '
            .'Потом в Вебмастере: шестерёнка → <b>«Привязка к Яндекс Метрике»</b> → выберите этот счётчик.</div>');
    }

    private function connectionHtml(): HtmlString
    {
        $oauth = app(YandexOAuth::class);
        if (! $oauth->isConfigured()) {
            return new HtmlString('Не подключено. Сначала шаг 1: вставьте ClientID и Client secret и нажмите «Сохранить» — появится кнопка «Подключить Яндекс».');
        }
        if (! $oauth->connected()) {
            return new HtmlString('<div style="line-height:1.7">Не подключено.<br>'
                .'1. Нажмите <b>«Подключить Яндекс»</b> — откроется новая вкладка Яндекса.<br>'
                .'2. Проверьте, что вверху ваш аккаунт, и нажмите <b>«Разрешить»</b> (или «Войти как …»).<br>'
                .'3. Яндекс покажет <b>код подтверждения</b> — скопируйте его, вернитесь на эту вкладку, вставьте в поле «Код с экрана Яндекса» и нажмите <b>«Подтвердить код»</b>.</div>');
        }

        $at = AnalyticsSettings::option('connected_at');
        $lines = ['<b style="color:#51a940">Подключено</b>'.(is_string($at) ? ' '.e(Carbon::parse($at)->timezone('Europe/Moscow')->format('d.m.Y H:i')) : '')];
        if ($expires = $oauth->expiresAt()) {
            $lines[] = 'Доступ действует до '.e($expires->timezone('Europe/Moscow')->format('d.m.Y')).' и продлевается сам.';
        }
        $lines[] = 'Метрика: '.$this->probe(fn () => count(app(Metrika::class)->counters()).' счётч. в аккаунте');
        $lines[] = 'Вебмастер: '.$this->probe(fn () => 'сайт найден ('.e(app(Webmaster::class)->hostId()).')');

        return new HtmlString('<div style="line-height:1.7">'.implode('<br>', $lines).'</div>');
    }

    private function verificationHtml(): HtmlString
    {
        $lines = [AnalyticsSettings::verificationCode()
            ? 'На сайте стоит метатег с кодом <code>'.e((string) AnalyticsSettings::verificationCode()).'</code>.'
            : 'На сайте кода нет.'];
        if (app(YandexOAuth::class)->connected()) {
            $lines[] = $this->probe(function (): string {
                $v = app(Webmaster::class)->verification();
                $match = $v['uin'] && AnalyticsSettings::verificationCode() && $v['uin'] !== AnalyticsSettings::verificationCode()
                    ? ' <span style="color:#dc2626">Код в Вебмастере другой: '.e($v['uin']).' — нажмите «Взять код из Вебмастера».</span>' : '';

                return 'Вебмастер: '.e(Webmaster::verificationLabel($v['state'], $v['type'])).'.'.$match;
            });
        }

        return new HtmlString(implode('<br>', $lines));
    }

    /** @return array<string, string> */
    private function counterOptions(): array
    {
        if (! app(YandexOAuth::class)->connected()) {
            return [];
        }
        if ($this->counters === null) {
            try {
                $this->counters = app(Metrika::class)->counters();
            } catch (Throwable) {
                $this->counters = [];
            }
        }
        $options = [];
        // Сохранённый счётчик может быть гостевым и не попасть в список — не теряем его.
        if ($this->counters !== [] && ($current = AnalyticsSettings::counterId())) {
            $options[(string) $current] = (string) $current;
        }
        foreach ($this->counters as $counter) {
            $options[(string) $counter['id']] = $counter['id'].' — '.($counter['name'] ?: $counter['site']).($counter['site'] ? ' ('.$counter['site'].')' : '');
        }

        return $options;
    }

    private function probe(callable $check): string
    {
        try {
            return (string) $check();
        } catch (AnalyticsException $e) {
            return '<span style="color:#dc2626">'.e($e->getMessage()).'</span>';
        } catch (Throwable) {
            return '<span style="color:#dc2626">не ответил — попробуйте обновить страницу</span>';
        }
    }
}
