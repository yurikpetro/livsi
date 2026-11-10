@php
    $n = fn ($v) => number_format((float) $v, 0, ',', ' ');
    $duration = function ($seconds) {
        $seconds = (int) round((float) $seconds);
        return $seconds >= 60 ? intdiv($seconds, 60).' мин '.($seconds % 60).' с' : $seconds.' с';
    };
@endphp
<x-filament-panels::page>
    @include('filament.analytics.partials.styles')

    <div class="la-grid">
        @include('filament.analytics.partials.periods', ['range' => $range])

        @if (! $ready)
            @include('filament.analytics.partials.setup', [
                'title' => 'Посещаемость появится после подключения Яндекс Метрики',
                'text' => 'Здесь будет: сколько людей заходило по дням, откуда пришли (поиск, реклама, соцсети), из каких городов, с телефона или компьютера, какие страницы смотрели и последние визиты.',
            ])
        @else
            {{-- Главные цифры --}}
            @if ($totals['error'])
                @include('filament.analytics.partials.error', ['message' => $totals['error']])
            @else
                @php $t = $totals['data']; @endphp
                <div class="la-kpis">
                    <div class="la-kpi"><b>{{ $n($t['users']) }}</b><span>посетителей (людей)</span></div>
                    <div class="la-kpi"><b>{{ $n($t['visits']) }}</b><span>визитов</span></div>
                    <div class="la-kpi"><b>{{ $n($t['pageviews']) }}</b><span>просмотров страниц</span></div>
                    <div class="la-kpi"><b>{{ $n($t['new']) }}</b><span>новых посетителей</span></div>
                    <div class="la-kpi"><b>{{ number_format($t['depth'], 1, ',', ' ') }}</b><span>страниц за визит</span></div>
                    <div class="la-kpi"><b>{{ $duration($t['duration']) }}</b><span>среднее время на сайте</span></div>
                    <div class="la-kpi"><b>{{ number_format($t['bounce'], 1, ',', ' ') }}%</b><span>отказы (ушли сразу)</span></div>
                </div>
            @endif

            <x-filament::section heading="По дням" :description="'Визиты за '.mb_strtolower($range->label()).'.'">
                @if ($daily['error'])
                    @include('filament.analytics.partials.error', ['message' => $daily['error']])
                @else
                    @include('filament.analytics.partials.chart', [
                        'points' => array_map(fn ($d) => ['date' => $d['date'], 'a' => $d['visits'], 'b' => $d['users']], $daily['data']),
                        'labels' => ['a' => 'визитов', 'b' => 'людей'],
                    ])
                    <div class="la-legend"><span><i style="background:#51a940"></i>визиты за день</span><span class="la-muted">наведите на столбик — будет и число людей</span></div>
                @endif
            </x-filament::section>

            <div class="la-cols-2">
                <x-filament::section heading="Откуда пришли" description="Последний значимый переход, как в Метрике.">
                    @if ($sources['error'])
                        @include('filament.analytics.partials.error', ['message' => $sources['error']])
                    @else
                        @include('filament.analytics.partials.breakdown', ['rows' => $sources['data'], 'first' => 'Источник'])
                    @endif
                </x-filament::section>

                <x-filament::section heading="Города">
                    @if ($cities['error'])
                        @include('filament.analytics.partials.error', ['message' => $cities['error']])
                    @else
                        @include('filament.analytics.partials.breakdown', ['rows' => $cities['data'], 'first' => 'Город'])
                    @endif
                </x-filament::section>

                <x-filament::section heading="Поисковые системы" description="Только переходы из поиска.">
                    @if ($engines['error'])
                        @include('filament.analytics.partials.error', ['message' => $engines['error']])
                    @else
                        @include('filament.analytics.partials.breakdown', ['rows' => $engines['data'], 'first' => 'Поисковик'])
                    @endif
                </x-filament::section>

                <x-filament::section heading="Устройства">
                    @if ($devices['error'])
                        @include('filament.analytics.partials.error', ['message' => $devices['error']])
                    @else
                        @include('filament.analytics.partials.breakdown', ['rows' => $devices['data'], 'first' => 'Устройство'])
                    @endif
                </x-filament::section>

                <x-filament::section heading="Ссылки с других сайтов">
                    @if ($referrers['error'])
                        @include('filament.analytics.partials.error', ['message' => $referrers['error']])
                    @else
                        @include('filament.analytics.partials.breakdown', ['rows' => $referrers['data'], 'first' => 'Сайт'])
                    @endif
                </x-filament::section>

                <x-filament::section heading="Популярные страницы">
                    @if ($pages['error'])
                        @include('filament.analytics.partials.error', ['message' => $pages['error']])
                    @elseif ($pages['data'] === [])
                        <p class="la-note">Пока пусто за этот период.</p>
                    @else
                        <table class="la-table">
                            <thead><tr><th>Страница</th><th class="num">Просмотры</th><th class="num">Люди</th></tr></thead>
                            <tbody>
                                @foreach ($pages['data'] as $row)
                                    <tr>
                                        <td><a class="la-link" href="{{ url($row['path']) }}" target="_blank" rel="noopener">{{ \Illuminate\Support\Str::limit(urldecode($row['path']), 60) }}</a></td>
                                        <td class="num">{{ $n($row['views']) }}</td>
                                        <td class="num">{{ $n($row['users']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </x-filament::section>
            </div>

            <x-filament::section heading="Последние визиты" description="Вчера и сегодня, свежие сверху. Метрика показывает данные с задержкой в несколько минут; имён и телефонов в ней нет.">
                @if ($recent['error'])
                    @include('filament.analytics.partials.error', ['message' => $recent['error']])
                @elseif ($recent['data'] === [])
                    <p class="la-note">Визитов пока нет. Метрика считает только тех, кто нажал «Принять все» в окне про cookie.</p>
                @else
                    <div class="la-scroll">
                        <table class="la-table">
                            <thead><tr><th>Когда</th><th>Город</th><th>Откуда</th><th>Устройство</th><th>Первая страница</th><th class="num">Страниц</th><th class="num">Время</th></tr></thead>
                            <tbody>
                                @foreach ($recent['data'] as $row)
                                    <tr>
                                        <td style="white-space: nowrap;">{{ \Illuminate\Support\Str::of($row['time'])->replaceMatches('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}:\d{2}).*$/', '$3.$2 $4') }}</td>
                                        <td>{{ $row['city'] }}</td>
                                        <td>{{ $row['source'] }}</td>
                                        <td>{{ $row['device'] }}</td>
                                        <td>{{ \Illuminate\Support\Str::limit(urldecode($row['page']), 45) }}</td>
                                        <td class="num">{{ number_format($row['depth'], 0, ',', ' ') }}{{ $row['visits'] > 1 ? ' ×'.$row['visits'] : '' }}</td>
                                        <td class="num">{{ $duration($row['duration']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>

            <p class="la-note">
                Метрика считает только посетителей, нажавших «Принять все» в окне про cookie, — так обещает политика конфиденциальности.
                Заказы и выручка по всем покупателям — в «Продажи и реклама».
                Подробнее и Вебвизор (запись посещений) — в <a class="la-link" href="https://metrika.yandex.ru/dashboard?id={{ $counter }}" target="_blank" rel="noopener">Метрике</a>.
            </p>
        @endif
    </div>
</x-filament-panels::page>
