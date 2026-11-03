@php
    use App\Support\Fulfillment;

    $steps = Fulfillment::steps($order, $events);
    $current = Fulfillment::current($order);
    $customer = Fulfillment::forCustomer($order);
    $green = 'var(--primary-600, #51a940)';
    $muted = 'rgba(127, 127, 127, .45)';
@endphp

<div style="display: grid; gap: 20px;">
    @if ($outcome = $order->outcome())
        <p style="margin: 0; padding: 10px 14px; border-radius: 8px; font-weight: 600; color: #ef4444; box-shadow: inset 0 0 0 1px rgba(239, 68, 68, .4);">
            {{ $outcome }}.
            @if ($order->returned_to_stock_at)
                <span style="font-weight: 400; opacity: .8;">Принят на склад {{ Fulfillment::moscow($order->returned_to_stock_at, 'd.m.Y H:i') }} · {{ $order->returned_to_stock_by }}.</span>
            @elseif ($order->canReturnToStock())
                <span style="font-weight: 400; opacity: .8;">Когда посылка вернётся и товар проверен — нажмите «Принят на склад».</span>
            @endif
            @if ($order->status === \App\Models\Order::STATUS_REFUNDED)
                <span style="font-weight: 400; opacity: .8;">Покупатель видит на странице заказа: «Заказ отменён, деньги возвращены».</span>
            @endif
        </p>
    @endif
    @if ($current === null)
        @if ($order->status !== \App\Models\Order::STATUS_REFUNDED)
            <p style="margin: 0; opacity: .75;">Статус доставки появится после оплаты заказа.</p>
        @endif
    @else
        @if ($current === Fulfillment::RETURNED && ! $outcome)
            <p style="margin: 0; padding: 10px 14px; border-radius: 8px; font-weight: 600; color: #ef4444; box-shadow: inset 0 0 0 1px rgba(239, 68, 68, .4);">
                Возврат отправителю — посылку не забрали.
            </p>
        @endif

        <ol style="list-style: none; margin: 0; padding: 0; display: flex;">
            @foreach ($steps as $i => $step)
                <li style="flex: 1 1 0; min-width: 0;">
                    <div style="display: flex; align-items: center;">
                        <span style="width: 26px; height: 26px; flex: none; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700;
                            {{ $step['reached'] ? 'background: '.$green.'; color: #fff;' : 'box-shadow: inset 0 0 0 1.5px '.$muted.'; opacity: .8;' }}
                            {{ $step['current'] ? 'box-shadow: 0 0 0 4px rgba(81, 169, 64, .25);' : '' }}">
                            @if ($step['reached'])
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                            @else
                                {{ $i + 1 }}
                            @endif
                        </span>
                        @unless ($loop->last)
                            <span style="flex: 1; height: 2px; margin: 0 8px; background: {{ ($steps[$i + 1]['reached'] ?? false) ? $green : $muted }};"></span>
                        @endunless
                    </div>
                    <div style="margin-top: 8px; padding-right: 8px; font-size: 13px; font-weight: 600; {{ $step['reached'] ? '' : 'opacity: .6;' }}">{{ $step['label'] }}</div>
                    <div style="font-size: 12px; opacity: .65;">{{ $step['reached'] ? (Fulfillment::moscow($step['time']) ?? '—') : 'ожидается' }}</div>
                </li>
            @endforeach
        </ol>

        @if ($customer)
            <div style="padding: 12px 14px; border-radius: 10px; box-shadow: inset 0 0 0 1px rgba(127, 127, 127, .25);">
                <span style="opacity: .7;">Покупатель видит на странице заказа:</span>
                <strong>«{{ $customer['title'] }}»</strong>
            </div>
        @endif
    @endif

    <div>
        <div style="font-size: 13px; font-weight: 600; margin-bottom: 6px;">Журнал</div>
        @forelse ($events as $event)
            <div style="display: flex; flex-wrap: wrap; gap: 4px 14px; padding: 8px 0; border-top: 1px solid rgba(127, 127, 127, .2); font-size: 13px;">
                <span style="opacity: .7; min-width: 110px;">{{ Fulfillment::moscow($event->created_at, 'd.m.Y H:i') }} МСК</span>
                <span style="font-weight: 600;">{{ Fulfillment::label($event->status) }}</span>
                <span style="opacity: .7;">{{ $event->sourceLabel() }}</span>
                @if ($event->comment)
                    <span style="flex-basis: 100%; opacity: .85;">{{ $event->comment }}</span>
                @endif
            </div>
        @empty
            <div style="font-size: 13px; opacity: .7;">Статус ещё не меняли.</div>
        @endforelse
    </div>
</div>
