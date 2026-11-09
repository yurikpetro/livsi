<?php

namespace App\Filament\Support;

use App\Models\DeliveryPoint;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Services\ReturnService;
use App\Support\Fulfillment;
use App\Support\Money;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Кнопки возврата в карточке заказа. Видна одна-две — по состоянию заявки:
 * ждёт решения → «Подтвердить возврат» / «Отказать»; подтверждён →
 * накладная СДЭК и «Товар получен»; получен → «Вернуть деньги за возврат».
 */
class ReturnActions
{
    /** @return list<Action> */
    public static function all(): array
    {
        return [self::approve(), self::reject(), self::waybill(), self::checkWaybill(), self::receive(), self::refund()];
    }

    private static function by(): string
    {
        return (string) (auth()->user()?->email ?? 'админка');
    }

    private static function active(Order $record, string ...$statuses): ?OrderReturn
    {
        $return = $record->activeReturn();

        return $return && in_array($return->status, $statuses, true) ? $return : null;
    }

    /** Ошибку сервиса — в уведомление, окно не закрывать. */
    private static function run(Action $action, string $failTitle, Closure $work): void
    {
        try {
            $work();
        } catch (ValidationException $exception) {
            Notification::make()->title($failTitle)->body($exception->validator->errors()->first())->danger()->send();
            $action->halt();
        }
    }

    public static function approve(): Action
    {
        return Action::make('approveReturn')
            ->label(fn (Order $record): string => $record->activeReturn()?->status === OrderReturn::APPROVED ? 'Изменить способ возврата' : 'Подтвердить возврат')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->databaseTransaction(false)
            ->authorize(fn (): bool => (bool) auth()->user()?->is_admin)
            ->visible(fn (Order $record): bool => ($r = $record->activeReturn()) !== null && ReturnService::canDecide($r))
            ->modalHeading(fn (Order $record): string => 'Подтвердить возврат · '.$record->number)
            ->modalDescription(fn (Order $record): string => ($r = $record->activeReturn())
                ? $r->reasonLabel().': '.$r->itemsText().' — '.Money::rub((int) $r->goods_amount).'. Покупатель получит письмо: как передать товар и сколько вернём.'
                : '')
            ->modalSubmitActionLabel('Подтвердить')
            ->fillForm(function (Order $record): array {
                $return = $record->activeReturn();
                if ($return?->status === OrderReturn::APPROVED) {
                    return ['method' => $return->method, 'cdek_point' => $return->cdek_point, 'payer' => $return->shipping_payer,
                        'refund_delivery' => $return->refund_delivery, 'instructions' => $return->instructions,
                        'shipping_cost' => $return->method !== OrderReturn::METHOD_CDEK && $return->shipping_cost
                            ? number_format($return->shipping_cost / 100, 2, '.', '') : null];
                }

                return [
                    'method' => Fulfillment::isCourier($record) ? OrderReturn::METHOD_COURIER : OrderReturn::METHOD_CDEK,
                    'cdek_point' => app(ReturnService::class)->defaultCdekPoint($record),
                    'payer' => $return?->isSellerFault() ? OrderReturn::PAYER_SELLER : OrderReturn::PAYER_BUYER,
                    'refund_delivery' => (bool) $return?->isSellerFault(),
                    'shipping_cost' => null,
                ];
            })
            ->schema(fn (Order $record): array => [
                Radio::make('method')->label('Как товар вернётся')->options(OrderReturn::methods())->required()->live(),
                Select::make('cdek_point')
                    ->label('Пункт СДЭК, куда покупатель сдаст посылку')
                    ->helperText('По умолчанию — где он получал заказ. Посылка придёт в наш пункт приёма из «Интеграций доставки». Стоимость СДЭК узнаем после создания накладной.')
                    ->searchable()
                    ->options(fn (): array => self::cityPoints($record))
                    ->getSearchResultsUsing(fn (string $search): array => self::searchPoints($search))
                    ->getOptionLabelUsing(fn ($value): ?string => ($point = self::point((string) $value)) ? ReturnService::pointLabel($point) : null)
                    ->visible(fn (Get $get): bool => $get('method') === OrderReturn::METHOD_CDEK)
                    ->required(fn (Get $get): bool => $get('method') === OrderReturn::METHOD_CDEK),
                Radio::make('payer')->label('Обратную доставку оплачивает')->required()->live()
                    ->options([OrderReturn::PAYER_SELLER => 'Магазин — брак, ошибка, повреждение', OrderReturn::PAYER_BUYER => 'Покупатель — товар не подошёл: удержим из возврата']),
                TextInput::make('shipping_cost')->label('Сколько удержать за обратную доставку, ₽')
                    ->numeric()->minValue(0)->step(0.01)
                    ->helperText('Например, стоимость поездки курьера. Можно поправить при возврате денег.')
                    ->visible(fn (Get $get): bool => $get('method') !== OrderReturn::METHOD_CDEK && $get('payer') === OrderReturn::PAYER_BUYER),
                Toggle::make('refund_delivery')
                    ->label('Вернуть и стоимость доставки заказа — '.Money::rub((int) $record->delivery_price))
                    ->helperText('Обычно — когда виноват магазин и возвращают весь заказ.')
                    ->visible(fn (): bool => (int) $record->delivery_price > 0 && (bool) $record->activeReturn()?->isFull()),
                Textarea::make('instructions')->label('Что написать покупателю')
                    ->helperText('Попадёт в письмо. Для «Иначе» — обязательно: куда и как передать товар.')
                    ->rows(3)->maxLength(2000)
                    ->required(fn (Get $get): bool => $get('method') === OrderReturn::METHOD_MANUAL),
            ])
            ->action(function (Order $record, array $data, Action $action): void {
                $return = $record->activeReturn();
                self::run($action, 'Возврат не подтверждён', function () use ($return, $data): void {
                    $cost = filled($data['shipping_cost'] ?? null) ? (int) round(((float) str_replace(',', '.', (string) $data['shipping_cost'])) * 100) : 0;
                    $done = app(ReturnService::class)->approve($return, [
                        'method' => $data['method'], 'payer' => $data['payer'], 'shipping_cost' => $cost,
                        'refund_delivery' => (bool) ($data['refund_delivery'] ?? false), 'cdek_point' => $data['cdek_point'] ?? null,
                        'instructions' => $data['instructions'] ?? null,
                    ], self::by());
                    if ($done->method === OrderReturn::METHOD_CDEK && ! $done->shipment_number) {
                        Notification::make()->title('Возврат подтверждён, накладная СДЭК не создана')
                            ->body(($done->shipment_error ?: 'СДЭК ещё присваивает номер.').' Письмо покупателю уйдёт, когда появится номер накладной.')
                            ->warning()->persistent()->send();
                    } else {
                        Notification::make()->title('Возврат подтверждён — покупателю ушло письмо')->success()->send();
                    }
                });
                $record->refresh();
            });
    }

    public static function reject(): Action
    {
        return Action::make('rejectReturn')
            ->label('Отказать в возврате')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->authorize(fn (): bool => (bool) auth()->user()?->is_admin)
            ->visible(fn (Order $record): bool => self::active($record, OrderReturn::REQUESTED) !== null)
            ->modalHeading(fn (Order $record): string => 'Отказать в возврате · '.$record->number)
            ->modalSubmitActionLabel('Отказать')
            ->schema([
                Textarea::make('reason')->label('Причина — её прочитает покупатель')->required()->minLength(5)->maxLength(1000)->rows(3),
            ])
            ->action(function (Order $record, array $data, Action $action): void {
                self::run($action, 'Не получилось', fn () => app(ReturnService::class)->reject($record->activeReturn(), $data['reason'], self::by()));
                Notification::make()->title('Отказано — покупателю ушло письмо')->success()->send();
                $record->refresh();
            });
    }

    public static function waybill(): Action
    {
        return Action::make('returnWaybill')
            ->label('Создать накладную СДЭК')
            ->icon(Heroicon::OutlinedTruck)
            ->color('gray')
            ->databaseTransaction(false)
            ->authorize(fn (): bool => (bool) auth()->user()?->is_admin)
            ->visible(fn (Order $record): bool => ($r = self::active($record, OrderReturn::APPROVED)) !== null
                && $r->method === OrderReturn::METHOD_CDEK && ! $r->shipment_uuid)
            ->requiresConfirmation()
            ->modalHeading('Накладная СДЭК на возврат')
            ->modalDescription(fn (Order $record): string => 'Покупатель сдаёт посылку в пункт '.($record->activeReturn()?->cdek_point_address ?? '').'. Если прошлый запрос оборвался, сайт сначала поищет накладную у СДЭК — второй не будет.')
            ->modalSubmitActionLabel('Создать')
            ->action(function (Order $record, Action $action): void {
                self::run($action, 'Накладная не создана', function () use ($record): void {
                    $done = app(ReturnService::class)->createWaybill($record->activeReturn());
                    Notification::make()->title($done->shipment_number ? 'Накладная СДЭК № '.$done->shipment_number.' — покупателю ушло письмо' : 'СДЭК принял, номер присваивается')
                        ->success()->send();
                });
                $record->refresh();
            });
    }

    public static function checkWaybill(): Action
    {
        return Action::make('checkReturnWaybill')
            ->label('Где посылка возврата')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->authorize(fn (): bool => (bool) auth()->user()?->is_admin)
            ->visible(fn (Order $record): bool => ($r = self::active($record, OrderReturn::APPROVED)) !== null && filled($r->shipment_uuid))
            ->action(function (Order $record): void {
                try {
                    $done = app(ReturnService::class)->refreshWaybill($record->activeReturn());
                    Notification::make()->title('СДЭК: '.($done->shipment_status_name ?: 'статус не изменился'))->success()->send();
                } catch (\Throwable $exception) {
                    Notification::make()->title('СДЭК не ответил')->body(mb_substr($exception->getMessage(), 0, 200))->warning()->send();
                }
                $record->refresh();
            });
    }

    public static function receive(): Action
    {
        return Action::make('receiveReturn')
            ->label('Товар получен')
            ->icon(Heroicon::OutlinedArchiveBoxArrowDown)
            ->color('primary')
            ->authorize(fn (): bool => (bool) auth()->user()?->is_admin)
            ->visible(fn (Order $record): bool => self::active($record, OrderReturn::APPROVED) !== null)
            ->modalHeading(fn (Order $record): string => 'Товар по возврату получен · '.$record->number)
            ->modalDescription(fn (Order $record): string => 'Проверьте товар: '.($record->activeReturn()?->itemsText() ?? '').'. Деньги вернёте следующим шагом.')
            ->modalSubmitActionLabel('Товар получен')
            ->fillForm(fn (Order $record): array => ['restock' => $record->activeReturn()?->reason === OrderReturn::REASON_UNFIT ? 'yes' : 'no'])
            ->schema([
                Radio::make('restock')->label('Состояние')->required()->options([
                    'yes' => 'Цел, в заводской упаковке — вернуть в продажу на сайте',
                    'no' => 'С недостатком или вскрыт — в продажу не возвращать',
                ]),
                Textarea::make('note')->label('Заметка (только в админке)')->rows(2)->maxLength(1000),
            ])
            ->action(function (Order $record, array $data, Action $action): void {
                self::run($action, 'Не отмечено', fn () => app(ReturnService::class)->receive($record->activeReturn(), $data['restock'] === 'yes', $data['note'] ?? null, self::by()));
                Notification::make()->title('Отмечено: товар получен')->body('Теперь верните деньги кнопкой «Вернуть деньги за возврат».')->success()->send();
                $record->refresh();
            });
    }

    public static function refund(): Action
    {
        return Action::make('refundReturn')
            ->label('Вернуть деньги за возврат')
            ->icon(Heroicon::OutlinedReceiptRefund)
            ->color('danger')
            ->databaseTransaction(false)
            ->authorize(fn (): bool => (bool) auth()->user()?->is_admin)
            ->visible(fn (Order $record): bool => self::active($record, OrderReturn::RECEIVED) !== null)
            ->modalHeading(fn (Order $record): string => 'Вернуть деньги за возврат · '.$record->number)
            ->modalDescription(fn (Order $record): string => 'По расчёту: '.(($r = $record->activeReturn()) ? ReturnService::breakdown($r) : '—')
                .'. Деньги вернёт Точка на карту или счёт покупателя, чек возврата пробьёт связка Точка — CloudKassir.')
            ->modalSubmitActionLabel('Вернуть деньги')
            ->fillForm(fn (Order $record): array => ['amount' => number_format(($record->activeReturn()?->expectedRefund() ?? 0) / 100, 2, '.', '')])
            ->schema([
                TextInput::make('amount')->label('Сумма возврата, ₽')->required()->numeric()->minValue(0.01)->step(0.01)
                    ->helperText('Поправьте, если СДЭК выставил за доставку другую сумму.'),
            ])
            ->action(function (Order $record, array $data, Action $action): void {
                $amount = (int) round(((float) str_replace(',', '.', (string) $data['amount'])) * 100);
                self::run($action, 'Деньги не возвращены', function () use ($record, $amount): void {
                    $done = app(ReturnService::class)->refund($record->activeReturn(), $amount, self::by());
                    $done->status === OrderReturn::REFUNDED
                        ? Notification::make()->title('Деньги возвращены: '.Money::rub((int) $done->refund_amount))->body('Покупателю ушло письмо.')->success()->send()
                        : Notification::make()->title('Возврат оформлен — банк обрабатывает')->warning()->send();
                });
                $record->refresh();
            });
    }

    /** Пункты СДЭК в городе заказа — чтобы выбрать без поиска. @return array<string, string> */
    private static function cityPoints(Order $order): array
    {
        $default = app(ReturnService::class)->defaultCdekPoint($order);
        $query = DeliveryPoint::query()->where('provider', 'cdek')->where('is_active', true)->where('type', 'pvz');
        $city = trim((string) $order->delivery_city);
        $points = $city !== '' ? (clone $query)->where('city', $city)->orderBy('address')->limit(60)->get() : collect();
        if ($default && ! $points->contains('external_id', $default) && ($point = self::point($default))) {
            $points->prepend($point);
        }

        return $points->filter(fn (DeliveryPoint $point): bool => ($point->limits['reception'] ?? true) !== false)
            ->mapWithKeys(fn (DeliveryPoint $point): array => [$point->external_id => ReturnService::pointLabel($point)])->all();
    }

    /** @return array<string, string> */
    private static function searchPoints(string $search): array
    {
        $search = trim($search);
        if (mb_strlen($search) < 2) {
            return [];
        }
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
        // В PostgreSQL like различает регистр, а «ставрополь» должен найти «Ставрополь».
        $op = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        return DeliveryPoint::query()->where('provider', 'cdek')->where('is_active', true)->where('type', 'pvz')
            ->where(fn ($q) => $q->where('external_id', $op, $like)->orWhere('address', $op, $like)->orWhere('city', $op, $like)->orWhere('name', $op, $like))
            ->orderBy('city')->limit(50)->get()
            ->filter(fn (DeliveryPoint $point): bool => ($point->limits['reception'] ?? true) !== false)
            ->mapWithKeys(fn (DeliveryPoint $point): array => [$point->external_id => ReturnService::pointLabel($point)])->all();
    }

    private static function point(string $code): ?DeliveryPoint
    {
        return $code === '' ? null : DeliveryPoint::query()->where('provider', 'cdek')->where('external_id', $code)->first();
    }
}
