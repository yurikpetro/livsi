@php
    use App\Models\OrderReturn;
    use App\Services\ReturnService;
    use App\Support\Fulfillment;
    use App\Support\Money;

    $return = $order->latestReturn();
    $older = $order->returns()->where('id', '!=', $return?->id)->latest('id')->get();
    $color = match ($return?->status) {
        OrderReturn::REQUESTED => '#f59e0b',
        OrderReturn::APPROVED, OrderReturn::RECEIVED => '#3b82f6',
        OrderReturn::REFUNDED => '#51a940',
        default => 'rgba(127, 127, 127, .8)',
    };
    $time = fn ($value) => $value ? Fulfillment::moscow($value, 'd.m.Y H:i') : '—';
    $row = 'display: grid; grid-template-columns: 11rem 1fr; gap: 12px; padding: 6px 0; border-top: 1px solid rgba(127, 127, 127, .15);';
@endphp

@if ($return)
    <div style="display: grid; gap: 14px;">
        <p style="margin: 0; padding: 10px 14px; border-radius: 8px; font-weight: 600; color: {{ $color }}; box-shadow: inset 0 0 0 1px {{ $color }};">
            {{ $return->status === OrderReturn::REQUESTED ? 'Клиент просит вернуть заказ' : $return->statusLabel() }}
            · {{ $return->number }} от {{ $time($return->created_at) }}
            @if ($return->status === OrderReturn::REQUESTED)
                <span style="font-weight: 400; opacity: .85;">— подтвердите или откажите кнопкой вверху страницы.</span>
            @elseif ($return->status === OrderReturn::APPROVED)
                <span style="font-weight: 400; opacity: .85;">— когда товар придёт и вы его проверите, нажмите «Товар получен».</span>
            @elseif ($return->status === OrderReturn::RECEIVED)
                <span style="font-weight: 400; opacity: .85;">— верните деньги кнопкой «Вернуть деньги за возврат».</span>
            @endif
        </p>

        <div>
            <div style="{{ $row }}"><span style="opacity: .7;">Причина</span><span>{{ $return->reasonLabel() }}</span></div>
            <div style="{{ $row }}"><span style="opacity: .7;">Возвращает</span>
                <span>
                    @foreach ($return->items as $item)
                        {{ $item['label'] }} × {{ $item['qty'] }} — {{ Money::rub((int) $item['amount']) }}<br>
                    @endforeach
                    <span style="opacity: .7;">{{ $return->partial ? 'Часть заказа' : 'Весь заказ' }}</span>
                </span>
            </div>
            @if (filled($return->comment))
                <div style="{{ $row }}"><span style="opacity: .7;">Комментарий</span><span>{!! nl2br(e($return->comment)) !!}</span></div>
            @endif
            @if (! empty($return->photos))
                <div style="{{ $row }}"><span style="opacity: .7;">Фото</span>
                    <span style="display: flex; flex-wrap: wrap; gap: 8px;">
                        @foreach ($return->photos as $index => $path)
                            <a href="{{ route('admin.returns.photo', ['return' => $return, 'index' => $index]) }}" target="_blank" rel="noopener"
                               style="display: block; width: 84px; height: 84px; border-radius: 6px; overflow: hidden; box-shadow: inset 0 0 0 1px rgba(127,127,127,.3);">
                                <img src="{{ route('admin.returns.photo', ['return' => $return, 'index' => $index]) }}" alt="Фото {{ $index + 1 }}"
                                     style="width: 100%; height: 100%; object-fit: cover;" loading="lazy">
                            </a>
                        @endforeach
                    </span>
                </div>
            @endif
            @if ($return->isActive())
                <div style="{{ $row }}"><span style="opacity: .7;">Деньги по закону</span>
                    <span>не позднее {{ Fulfillment::moscow($return->refundDeadline(), 'd.m.Y') }} — 10 дней со дня заявки</span></div>
            @endif

            @if ($return->decided_at)
                <div style="{{ $row }}"><span style="opacity: .7;">Решение</span>
                    <span>{{ $return->status === OrderReturn::REJECTED ? 'Отказано' : 'Подтверждено' }} {{ $time($return->decided_at) }} · {{ $return->decided_by }}</span></div>
            @endif
            @if ($return->status === OrderReturn::REJECTED && filled($return->reject_reason))
                <div style="{{ $row }}"><span style="opacity: .7;">Причина отказа</span><span>{!! nl2br(e($return->reject_reason)) !!}</span></div>
            @endif
            @if ($return->method)
                <div style="{{ $row }}"><span style="opacity: .7;">Как вернётся</span>
                    <span>
                        {{ OrderReturn::methods()[$return->method] ?? $return->method }}<br>
                        <span style="opacity: .8;">Обратную доставку оплачивает {{ $return->buyerPaysShipping() ? 'покупатель — удерживаем из возврата' : 'магазин' }}.</span>
                    </span>
                </div>
            @endif
            @if ($return->method === OrderReturn::METHOD_CDEK)
                <div style="{{ $row }}"><span style="opacity: .7;">Накладная СДЭК</span>
                    <span>
                        @if ($return->shipment_number)
                            <a href="https://www.cdek.ru/ru/tracking?order_id={{ $return->shipment_number }}" target="_blank" rel="noopener" style="text-decoration: underline;">№ {{ $return->shipment_number }}</a>
                            · {{ $return->shipment_status_name ?: 'создана' }}
                        @elseif ($return->shipment_uuid)
                            Создана, номер присваивается
                        @else
                            Ещё не создана
                        @endif
                        <br><span style="opacity: .8;">Покупатель сдаёт в пункт {{ $return->cdek_point_address ?: $return->cdek_point }}; посылка придёт в наш пункт приёма.</span>
                        @if ($return->shipping_cost > 0)
                            <br><span style="opacity: .8;">Стоимость у СДЭК: {{ Money::rub((int) $return->shipping_cost) }}.</span>
                        @elseif ($return->shipment_uuid)
                            <br><span style="opacity: .8;">Стоимость СДЭК ещё не сообщил — проверьте перед возвратом денег.</span>
                        @endif
                        @if ($return->shipment_error)
                            <br><span style="color: #ef4444;">{{ $return->shipment_error }}</span>
                        @endif
                    </span>
                </div>
            @endif
            @if ($return->instructions)
                <div style="{{ $row }}"><span style="opacity: .7;">Написали покупателю</span><span>{!! nl2br(e($return->instructions)) !!}</span></div>
            @endif
            @if ($return->received_at)
                <div style="{{ $row }}"><span style="opacity: .7;">Товар получен</span>
                    <span>{{ $time($return->received_at) }} · {{ $return->received_by }} · {{ $return->restocked ? 'вернули в продажу' : 'в продажу не вернули' }}
                        @if ($return->receive_note)<br><span style="opacity: .8;">{{ $return->receive_note }}</span>@endif
                    </span>
                </div>
            @endif
            @if (in_array($return->status, [OrderReturn::REQUESTED, OrderReturn::APPROVED, OrderReturn::RECEIVED], true))
                <div style="{{ $row }}"><span style="opacity: .7;">Вернуть по расчёту</span><span>{{ ReturnService::breakdown($return) }}</span></div>
            @endif
            @if ($return->status === OrderReturn::REFUNDED)
                <div style="{{ $row }}"><span style="opacity: .7;">Вернули</span><span>{{ Money::rub((int) $return->refund_amount) }} · {{ $time($return->refunded_at) }}</span></div>
                @if (! empty($return->marks_to_return))
                    @php($closing = $order->receipt(\App\Models\OrderReceipt::KIND_CLOSING))
                    <div style="{{ $row }}"><span style="opacity: .7;">Честный знак</span>
                        <div style="display: grid; gap: 8px;">
                            @if ($return->marks_returned_at)
                                <span style="color: #51a940; font-weight: 600;">✓ Коды возвращены в оборот — {{ $time($return->marks_returned_at) }} · {{ $return->marks_returned_by }}</span>
                            @else
                                <span style="color: #f59e0b; font-weight: 600;">Коды нужно вернуть в оборот вручную: чек возврата пробила Точка, а в её чеке кодов нет.</span>
                            @endif
                            <div>
                                @foreach ($return->marks_to_return as $code)
                                    <code style="user-select: all; padding: 2px 6px; border-radius: 4px; box-shadow: inset 0 0 0 1px rgba(127,127,127,.35);">{{ $code }}</code><br>
                                @endforeach
                            </div>
                            @unless ($return->marks_returned_at)
                                <ol style="margin: 0; padding-left: 1.2rem; display: grid; gap: 4px; opacity: .9;">
                                    <li><strong>Найдите чек возврата.</strong> Кабинет CloudKassir → «Чеки» → фильтр по дате
                                        {{ Fulfillment::moscow($return->refunded_at, 'd.m.Y') }}: тип <strong>«Возврат прихода»</strong>,
                                        сумма {{ Money::rub((int) $return->refund_amount) }}. Нужны его <strong>номер ФД</strong> и <strong>дата</strong>.
                                        Тот же чек приходит покупателю на почту от Точки.
                                        @if ($closing?->done_at)
                                            Не перепутайте с чеком «Приход, полный расчёт» от {{ Fulfillment::moscow($closing->done_at, 'd.m.Y') }} — у него ФД меньше, и он с кодом.
                                        @endif
                                    </li>
                                    <li><strong>Личный кабинет «Честного знака»</strong> → «Возврат в оборот».
                                        Вид возврата — <strong>«Возврат при розничной реализации»</strong>: код выбыл по нашему кассовому чеку «Полный расчёт».
                                        «Дистанционный способ продажи» и остальные виды — не наш случай.</li>
                                    <li><strong>Код</strong> — скопируйте из списка выше (нажмите на код — выделится целиком).</li>
                                    <li><strong>Документ-основание</strong> — «Кассовый чек»: номер — ФД чека «Возврат прихода», дата — дата этого чека.</li>
                                    <li>Подпишите и отправьте. Через несколько минут код в «Честном знаке» станет «В обороте».</li>
                                    <li>Вернитесь сюда и нажмите вверху <strong>«Коды вернул в оборот»</strong> — отметка останется в заказе.</li>
                                </ol>
                            @endunless
                        </div>
                    </div>
                @endif
            @endif
            @if ($return->refund_error && $return->status !== OrderReturn::REFUNDED)
                <div style="{{ $row }}"><span style="opacity: .7;">Возврат денег</span><span style="color: #ef4444;">{{ $return->refund_error }}</span></div>
            @endif
        </div>

        @if ($older->isNotEmpty())
            <p style="margin: 0; opacity: .75; font-size: .875rem;">
                Раньше:
                @foreach ($older as $old)
                    {{ $old->number }} — {{ mb_strtolower($old->statusLabel()) }} ({{ $time($old->created_at) }})@if (! $loop->last); @endif
                @endforeach
            </p>
        @endif
    </div>
@endif
