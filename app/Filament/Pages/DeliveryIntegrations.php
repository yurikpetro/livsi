<?php

namespace App\Filament\Pages;

use App\Delivery\DeliveryGateway;
use App\Delivery\DeliverySettings;
use App\Models\DeliveryPoint;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * «Интеграции доставки»: СДЭК, Яндекс и Ozon напрямую, без ApiShip.
 *
 * Для каждой службы: режим (ApiShip или напрямую), ключи доступа, пункт,
 * куда сдаём посылки, проверка связи и загрузка пунктов выдачи.
 *
 * Напрямую службу можно включить только когда всё готово: ключи заданы,
 * пункт сдачи выбран, пункты выдачи загружены. Иначе покупатель увидел
 * бы пустую карту или не смог бы рассчитать доставку.
 */
class DeliveryIntegrations extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|\UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?int $navigationSort = 91;

    protected static ?string $navigationLabel = 'Интеграции доставки';

    protected static ?string $title = 'Интеграции доставки';

    protected static ?string $slug = 'delivery-integrations';

    protected string $view = 'filament.pages.settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** Пункты приёма за один показ страницы (список и подсказка не ходят в службу дважды). */
    private array $dropoffMemo = [];

    private const TITLES = ['cdek' => 'СДЭК', 'yataxi' => 'Яндекс Доставка', 'ozondel' => 'Ozon Доставка'];

    private const KEY_LABELS = [
        'cdek' => ['client_id' => 'Account', 'client_secret' => 'Secure password'],
        'yataxi' => ['token' => 'OAuth-токен'],
        'ozondel' => ['client_id' => 'client_id приложения', 'client_secret' => 'client_secret приложения'],
    ];

    private const HOW_TO_KEYS = [
        'cdek' => 'Личный кабинет СДЭК → Интеграция → «Создать ключ».',
        'yataxi' => 'Кабинет Яндекс Доставки → Интеграция → «Получить токен».',
        'ozondel' => 'Кабинет Ozon Доставки → Управление частными приложениями → ключик у приложения.',
    ];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public function mount(): void
    {
        $values = [];
        foreach (DeliveryGateway::PROVIDERS as $provider) {
            $values[$provider] = [
                'direct' => DeliverySettings::driver($provider) === 'direct',
                'origin' => DeliverySettings::origin($provider),
            ];
        }
        $this->form->fill($values);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components(array_map(fn (string $provider): Section => $this->section($provider), DeliveryGateway::PROVIDERS))
            ->statePath('data');
    }

    private function section(string $provider): Section
    {
        $fields = [
            Placeholder::make($provider.'_state')->label('Состояние')
                ->content(fn (): HtmlString => $this->stateHtml($provider))
                ->columnSpanFull(),
            Toggle::make($provider.'.direct')->label('Работать напрямую, без ApiShip')
                ->helperText('Выключено — служба идёт через ApiShip, как раньше. Включить можно, когда заданы ключи, '
                    .($provider === 'ozondel' ? 'указан метод отгрузки' : 'выбран пункт сдачи').' и загружены пункты выдачи.')
                ->columnSpanFull(),
        ];
        foreach (self::KEY_LABELS[$provider] as $key => $label) {
            $fields[] = TextInput::make($provider.'.keys.'.$key)->label($label)
                ->password()->revealable()->autocomplete('new-password')->maxLength(500)
                ->placeholder(fn (): string => DeliverySettings::credential($provider, $key) ? 'задан — пусто, чтобы не менять' : 'не задан')
                ->helperText(self::HOW_TO_KEYS[$provider]);
        }
        $fields[] = $provider === 'ozondel'
            ? TextInput::make('ozondel.origin')->label('ID метода отгрузки Ozon')->maxLength(64)
                ->helperText('Кабинет Ozon Доставки → Методы доставки: номер под штрих-кодом метода, по которому сдаёте посылки.')
                ->columnSpanFull()
            : Select::make($provider.'.origin')->label('Пункт, куда сдаём посылки')
                ->options(fn (): array => $this->dropoffOptions($provider))
                ->searchable()->native(false)
                ->helperText(fn (): string => $this->dropoffHint($provider))
                ->columnSpanFull();
        $fields[] = Actions::make([
            Action::make('check_'.$provider)->label('Проверить связь')->icon(Heroicon::OutlinedSignal)->color('gray')
                ->action(fn () => $this->check($provider)),
            Action::make('sync_'.$provider)->label('Обновить пункты выдачи')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                ->action(fn () => $this->requestSync($provider)),
        ])->columnSpanFull();

        return Section::make(self::TITLES[$provider])->schema($fields)->columns(2)->collapsible();
    }

    private function stateHtml(string $provider): HtmlString
    {
        $direct = DeliverySettings::driver($provider) === 'direct';
        $keys = collect(array_keys(self::KEY_LABELS[$provider]))->map(fn ($key) => DeliverySettings::credentialSource($provider, $key));
        $keysText = $keys->contains(null) ? 'не все заданы' : ($keys->contains('admin') ? 'заданы здесь' : 'заданы на сервере');
        $count = DeliveryPoint::query()->where('provider', $provider)->where('is_active', true)->count();
        $sync = DeliverySettings::lastSync($provider);
        $running = DeliverySettings::runningSince($provider);
        $syncText = match (true) {
            DeliverySettings::syncRequested($provider) => 'загрузка поставлена, начнётся в течение минуты',
            $running !== null => 'идёт загрузка с '.$running->timezone('Europe/Moscow')->format('H:i').' — обновите страницу через несколько минут',
            $sync === null => 'ещё не загружались',
            isset($sync['error']) => 'последняя загрузка не удалась: '.e($sync['error']),
            default => 'обновлены '.Carbon::parse($sync['at'])->timezone('Europe/Moscow')->format('d.m H:i'),
        };
        $origin = DeliverySettings::originLabel($provider) ?? DeliverySettings::origin($provider);
        $originText = $provider === 'ozondel' ? 'метод отгрузки: '.($origin ?? 'не указан') : 'сдаём посылки: '.($origin ?? 'не выбран');

        return new HtmlString(sprintf(
            '<div style="line-height:1.7"><b>%s</b> · ключи: %s<br>пунктов выдачи: %s (%s)<br>%s</div>',
            $direct ? 'Работает напрямую' : 'Работает через ApiShip',
            e($keysText), number_format($count, 0, ',', ' '), $syncText, e($originText),
        ));
    }

    /** @return array<string, string> */
    private function dropoffOptions(string $provider): array
    {
        $options = [];
        foreach ($this->dropoffPoints($provider) ?? [] as $point) {
            $options[$point['external_id']] = $point['address'];
        }
        $current = DeliverySettings::origin($provider);
        if ($current && ! isset($options[$current])) {
            $options[$current] = DeliverySettings::originLabel($provider) ?? $current;
        }

        return $options;
    }

    private function dropoffHint(string $provider): string
    {
        $points = $this->dropoffPoints($provider);

        return match (true) {
            $points === null => 'Список не загрузился: проверьте ключи и нажмите «Проверить связь».',
            $points === [] => 'Служба не вернула пунктов приёма в Ставрополе.',
            default => count($points).' пунктов приёма в Ставрополе. Начните вводить улицу.',
        };
    }

    /** Пункты приёма в городе отгрузки; null — служба не ответила. @return list<array<string, mixed>>|null */
    private function dropoffPoints(string $provider): ?array
    {
        $carrier = app(DeliveryGateway::class)->carrier($provider);
        if (! method_exists($carrier, 'dropoffPoints') || ! $carrier->isConfigured()) {
            return null;
        }
        $key = 'delivery-dropoff:'.$provider.':'.hash('sha256', (string) DeliverySettings::credential($provider, array_key_first(self::KEY_LABELS[$provider])));
        if (array_key_exists($key, $this->dropoffMemo)) {
            return $this->dropoffMemo[$key];
        }
        $cached = Cache::get($key);
        if (is_array($cached) || $cached === false) {
            return $this->dropoffMemo[$key] = $cached ?: null;
        }
        try {
            $points = $carrier->dropoffPoints((string) config('livsi.delivery.origin_city', 'Ставрополь'));
        } catch (Throwable) {
            // служба не ответила: не дёргать её на каждом клике, «Проверить связь» сбросит
            Cache::put($key, false, now()->addMinutes(2));

            return $this->dropoffMemo[$key] = null;
        }
        usort($points, fn (array $a, array $b): int => strcmp($a['address'], $b['address']));
        Cache::put($key, $points, now()->addHours(6));

        return $this->dropoffMemo[$key] = $points;
    }

    public function check(string $provider): void
    {
        $this->saveKeys($provider);
        $carrier = app(DeliveryGateway::class)->carrier($provider);
        if (! $carrier->isConfigured()) {
            Notification::make()->title(self::TITLES[$provider].': ключи не заданы')->warning()->send();

            return;
        }
        try {
            $count = method_exists($carrier, 'dropoffPoints')
                ? count($carrier->dropoffPoints((string) config('livsi.delivery.origin_city', 'Ставрополь')))
                : (method_exists($carrier, 'ping') ? $carrier->ping() : null);
            $this->dropoffMemo = [];
            Cache::forget('delivery-dropoff:'.$provider.':'.hash('sha256', (string) DeliverySettings::credential($provider, array_key_first(self::KEY_LABELS[$provider]))));
            Notification::make()->title(self::TITLES[$provider].': связь есть')
                ->body(is_int($count) ? 'Пунктов приёма в Ставрополе: '.$count : 'Служба приняла ключи.')->success()->send();
        } catch (Throwable $exception) {
            Notification::make()->title(self::TITLES[$provider].': служба не ответила')
                ->body(mb_substr($exception->getMessage(), 0, 300))->danger()->send();
        }
    }

    public function requestSync(string $provider): void
    {
        $this->saveKeys($provider);
        if (! app(DeliveryGateway::class)->carrier($provider)->isConfigured()) {
            Notification::make()->title('Сначала задайте ключи '.self::TITLES[$provider])->warning()->send();

            return;
        }
        DeliverySettings::requestSync($provider);
        Notification::make()->title('Загрузка пунктов выдачи поставлена')
            ->body('Начнётся в течение минуты и займёт от минуты до нескольких. Обновите страницу позже.')->success()->send();
    }

    private function saveKeys(string $provider): void
    {
        $keys = $this->data[$provider]['keys'] ?? [];
        if (is_array($keys) && array_filter($keys, fn ($value) => filled($value))) {
            DeliverySettings::setCredentials($provider, $keys);
            $this->data[$provider]['keys'] = [];
        }
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $problems = [];
        foreach (DeliveryGateway::PROVIDERS as $provider) {
            $this->data[$provider]['keys'] = $data[$provider]['keys'] ?? [];
            $this->saveKeys($provider);

            $origin = trim((string) ($data[$provider]['origin'] ?? ''));
            if ($origin !== DeliverySettings::origin($provider)) {
                $label = $provider === 'ozondel' ? null : ($this->dropoffOptions($provider)[$origin] ?? null);
                DeliverySettings::setOrigin($provider, $origin !== '' ? $origin : null, $label);
            }

            $wantDirect = (bool) ($data[$provider]['direct'] ?? false);
            if ($wantDirect && DeliverySettings::driver($provider) !== 'direct') {
                $why = $this->notReady($provider);
                if ($why !== null) {
                    $problems[] = self::TITLES[$provider].': '.$why;
                    $this->data[$provider]['direct'] = false;

                    continue;
                }
            }
            DeliverySettings::setDriver($provider, $wantDirect ? 'direct' : 'apiship');
        }

        if ($problems) {
            Notification::make()->title('Сохранено, но не всё включено')->body(implode("\n", $problems))->warning()->persistent()->send();
        } else {
            Notification::make()->title('Интеграции сохранены')->success()->send();
        }
    }

    private function notReady(string $provider): ?string
    {
        if (! app(DeliveryGateway::class)->carrier($provider)->isConfigured()) {
            return 'не заданы ключи';
        }
        if (DeliverySettings::origin($provider) === null) {
            return $provider === 'ozondel' ? 'не указан метод отгрузки' : 'не выбран пункт сдачи посылок';
        }
        if (! DeliveryPoint::query()->where('provider', $provider)->where('is_active', true)->exists()) {
            return 'не загружены пункты выдачи — нажмите «Обновить пункты выдачи» и подождите';
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
