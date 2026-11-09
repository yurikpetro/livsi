{{--
    Возврат по заказу: состояние заявки и кнопка «Вернуть заказ».
    Одно место для страницы заказа и кабинета — чтобы покупатель везде
    видел одно и то же. В кабинете — по частям: $part = 'button' (рядом
    с «Повторить заказ») и $part = 'status' (строка под заказом).
--}}
@php
    use App\Models\OrderReturn;
    use App\Services\ReturnService;

    $part = $part ?? null;
    $latest = $order->latestReturn();
    $rules = app(ReturnService::class)->eligibility($order);
    $owner = $order->user_id !== null && $order->user_id === auth()->id();
    $params = ['order' => $order->number] + ($owner ? [] : ['token' => $order->access_token]);
    $showStatus = $latest && $latest->status !== OrderReturn::CANCELLED;
@endphp

@if ($showStatus || $rules['allowed'])
    @if ($part === 'button')
        @if ($rules['allowed'])
            <a href="{{ route('order.return', $params) }}" class="text-[11px] font-bold uppercase tracking-[0.08em] hover:text-green">
                Вернуть заказ
            </a>
        @endif
    @elseif ($part === 'status')
        @if ($showStatus)
            <p class="mt-2 text-sm leading-relaxed">Возврат: {{ $latest->customerStatus() }}</p>
        @endif
    @else
        <div class="return-box mt-8 border border-line p-6 md:p-8" data-return-box>
            <div class="eyebrow">Возврат</div>
            @if ($showStatus)
                <p class="mt-3 max-w-xl text-sm leading-relaxed">{{ $latest->customerStatus() }}</p>
                @if ($latest->status === OrderReturn::APPROVED && $latest->method === OrderReturn::METHOD_CDEK && $latest->cdek_point_address && $latest->shipment_number)
                    <p class="mt-2 max-w-xl text-sm leading-relaxed text-muted">
                        Пункт СДЭК: {{ $latest->cdek_point_address }}. Скажите, что это клиентский возврат, и возьмите паспорт.
                    </p>
                @endif
                @if ($latest->status === OrderReturn::REQUESTED)
                    <form method="POST" action="{{ route('order.return.cancel', $params) }}" class="mt-4">
                        @csrf
                        <button type="submit" class="text-[11px] font-bold uppercase tracking-[0.08em] text-muted hover:text-ink">
                            Отозвать заявку
                        </button>
                    </form>
                @endif
            @endif
            @if ($rules['allowed'])
                <p class="mt-3 max-w-xl text-sm leading-relaxed text-muted">
                    @if ($rules['until'])
                        Если товар не подошёл, его можно вернуть до {{ $rules['until']->translatedFormat('j F') }} включительно — семь дней со дня получения.
                    @else
                        {{ $rules['note'] }}
                    @endif
                    Условия — на странице <a href="{{ route('legal.returns') }}" class="underline hover:text-ink">возврата товара</a>.
                </p>
                <a href="{{ route('order.return', $params) }}" class="btn btn-outline mt-5">Вернуть заказ</a>
            @endif
        </div>
    @endif
@endif
