@php
    use App\Support\Money;
    $n = fn ($v) => number_format((float) $v, 0, ',', ' ');
@endphp
<x-filament-panels::page>
    @include('filament.analytics.partials.styles')

    <div class="la-grid">
        @include('filament.analytics.partials.periods', ['range' => $range])

        @if ($sales['error'])
            @include('filament.analytics.partials.error', ['message' => $sales['error']])
        @else
            @php $s = $sales['data']; @endphp
            <div class="la-kpis">
                <div class="la-kpi"><b>{{ $n($s['totals']['orders']) }}</b><span>заказов оформлено</span></div>
                <div class="la-kpi"><b>{{ $n($s['totals']['paid']) }}</b><span>оплачено</span></div>
                <div class="la-kpi"><b>{{ Money::rub($s['totals']['revenue']) }}</b><span>выручка (оплаченные, без возвратов)</span></div>
                <div class="la-kpi"><b>{{ $s['totals']['paid'] > 0 ? Money::rub(intdiv($s['totals']['revenue'], $s['totals']['paid'])) : '—' }}</b><span>средний чек</span></div>
            </div>

            <x-filament::section heading="Заказы по источникам" description="Считает сам сайт по всем покупателям: откуда человек пришёл в начале визита — поиск, реклама, соцсети, ссылка с другого сайта. У заказов до обновления 17 известны только рекламные метки, поэтому у них чаще «без источника».">
                @if ($s['channels'] === [])
                    <p class="la-note">За этот период заказов нет.</p>
                @else
                    <table class="la-table">
                        <thead><tr><th>Источник</th><th class="num">Заказов</th><th class="num">Оплачено</th><th class="num">Выручка</th><th class="num">Средний чек</th></tr></thead>
                        <tbody>
                            @foreach ($s['channels'] as $row)
                                <tr>
                                    <td class="la-bar"><i style="width: {{ $s['totals']['revenue'] > 0 ? min(100, round(100 * $row['revenue'] / $s['totals']['revenue'], 1)) : 0 }}%;"></i><span>{{ $row['label'] }}</span></td>
                                    <td class="num">{{ $n($row['orders']) }}</td>
                                    <td class="num">{{ $n($row['paid']) }}</td>
                                    <td class="num">{{ Money::rub($row['revenue']) }}</td>
                                    <td class="num">{{ $row['average'] > 0 ? Money::rub($row['average']) : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-filament::section>

            @if ($s['campaigns'] !== [])
                <x-filament::section heading="Рекламные кампании и метки" description="Заказы по меткам utm из рекламных ссылок.">
                    <table class="la-table">
                        <thead><tr><th>Кампания</th><th class="num">Заказов</th><th class="num">Оплачено</th><th class="num">Выручка</th></tr></thead>
                        <tbody>
                            @foreach ($s['campaigns'] as $row)
                                <tr>
                                    <td>{{ $row['label'] }}</td>
                                    <td class="num">{{ $n($row['orders']) }}</td>
                                    <td class="num">{{ $n($row['paid']) }}</td>
                                    <td class="num">{{ Money::rub($row['revenue']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-filament::section>
            @endif

            <x-filament::section heading="Последние заказы и откуда они" :collapsible="true">
                @if ($s['recent'] === [])
                    <p class="la-note">За этот период заказов нет.</p>
                @else
                    <div class="la-scroll">
                        <table class="la-table">
                            <thead><tr><th>Заказ</th><th>Когда</th><th>Источник</th><th class="num">Сумма</th><th>Статус</th></tr></thead>
                            <tbody>
                                @foreach ($s['recent'] as $row)
                                    <tr>
                                        <td><a class="la-link" href="{{ \App\Filament\Resources\Orders\OrderResource::getUrl('edit', ['record' => $row['id']]) }}">{{ $row['number'] }}</a></td>
                                        <td style="white-space: nowrap;">{{ $row['at']?->timezone('Europe/Moscow')->format('d.m H:i') }}</td>
                                        <td>{{ $row['channel'] }}@if ($row['detail'])<span class="la-muted"> · {{ \Illuminate\Support\Str::limit($row['detail'], 40) }}</span>@endif</td>
                                        <td class="num">{{ Money::rub($row['total']) }}</td>
                                        <td>{{ $row['status'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>
        @endif

        <x-filament::section heading="Визиты по рекламным меткам (Метрика)">
            @if (! $metrika)
                <p class="la-note">Появятся после подключения Метрики: «Аналитика сайта» → <a class="la-link" href="{{ \App\Filament\Pages\Analytics\AnalyticsConnection::getUrl() }}">«Подключение»</a>.</p>
            @elseif ($campaigns['error'])
                @include('filament.analytics.partials.error', ['message' => $campaigns['error']])
            @elseif ($campaigns['data'] === [])
                <p class="la-note">Переходов по ссылкам с метками utm за этот период не было. Когда запустим рекламу, здесь будет видно, сколько людей пришло с каждой кампании.</p>
            @else
                <table class="la-table">
                    <thead><tr><th>Источник</th><th>Кампания</th><th class="num">Визиты</th><th class="num">Люди</th><th class="num">Отказы</th></tr></thead>
                    <tbody>
                        @foreach ($campaigns['data'] as $row)
                            <tr>
                                <td>{{ $row['source'] }}</td>
                                <td>{{ $row['campaign'] }}</td>
                                <td class="num">{{ $n($row['visits']) }}</td>
                                <td class="num">{{ $n($row['users']) }}</td>
                                <td class="num">{{ number_format($row['bounce'], 1, ',', ' ') }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>

        <x-filament::section heading="Расходы на рекламу (Яндекс Директ)">
            <p class="la-note">
                Подключим, когда запустим рекламу: здесь будут расходы по кампаниям, стоимость клика и сколько стоил один заказ.
                Для этого Директ выдаёт отдельный доступ к своему API — оформим его вместе с первой кампанией.
                Заказы с рекламы уже сейчас попадают в таблицу выше как «Реклама в Яндексе (Директ)» — по меткам yclid и utm.
            </p>
        </x-filament::section>
    </div>
</x-filament-panels::page>
