@php
    $n = fn ($v) => number_format((float) $v, 0, ',', ' ');
    $f = fn ($v) => $v === null ? '—' : str_replace('.', ',', (string) $v);
@endphp
<x-filament-panels::page>
    @include('filament.analytics.partials.styles')

    <div class="la-grid">
        @include('filament.analytics.partials.periods', ['range' => $range])

        @if (! $ready)
            @include('filament.analytics.partials.setup', [
                'title' => 'Данные поиска появятся после подключения Яндекса',
                'text' => 'Здесь будет: сколько раз сайт показан в поиске Яндекса, сколько раз по нему кликнули, на каком месте он стоит и по каким запросам его находят (в том числе «livsi» и «ливси»), сколько страниц в поиске.',
            ])
        @else
            @if ($summary['error'])
                @include('filament.analytics.partials.error', ['message' => $summary['error']])
            @else
                @php $s = $summary['data']; $p = $s['problems']; @endphp
                <div class="la-kpis">
                    <div class="la-kpi"><b>{{ $n($s['searchable']) }}</b><span>страниц в поиске</span></div>
                    <div class="la-kpi"><b>{{ $n($s['excluded']) }}</b><span>исключено из поиска</span></div>
                    <div class="la-kpi"><b>{{ $n($s['sqi']) }}</b><span>ИКС — индекс качества</span></div>
                    <div class="la-kpi">
                        <b @style(['color: #dc2626' => ($p['FATAL'] ?? 0) + ($p['CRITICAL'] ?? 0) > 0])>{{ ($p['FATAL'] ?? 0) + ($p['CRITICAL'] ?? 0) }}</b>
                        <span>серьёзных проблем · ещё {{ ($p['POSSIBLE_PROBLEM'] ?? 0) + ($p['RECOMMENDATION'] ?? 0) }} советов</span>
                    </div>
                </div>
            @endif

            @if (! $history['error'])
                @php
                    $shows = array_sum(array_map(fn ($d) => (int) $d['shows'], $history['data']));
                    $clicks = array_sum(array_map(fn ($d) => (int) $d['clicks'], $history['data']));
                @endphp
                <div class="la-kpis">
                    <div class="la-kpi"><b>{{ $n($shows) }}</b><span>показов в поиске за период</span></div>
                    <div class="la-kpi"><b>{{ $n($clicks) }}</b><span>кликов — переходов на сайт</span></div>
                    <div class="la-kpi"><b>{{ $shows > 0 ? str_replace('.', ',', (string) round(100 * $clicks / $shows, 1)) : '0' }}%</b><span>CTR — доля кликов от показов</span></div>
                </div>
            @endif

            <x-filament::section heading="Показы в поиске по дням" description="Вебмастер отдаёт статистику с задержкой 2–5 дней — последние дни пунктиром, это не ошибка.">
                @if ($history['error'])
                    @include('filament.analytics.partials.error', ['message' => $history['error']])
                @else
                    @include('filament.analytics.partials.chart', [
                        'points' => array_map(fn ($d) => ['date' => $d['date'], 'a' => $d['shows'], 'b' => $d['clicks']], $history['data']),
                        'labels' => ['a' => 'показов', 'b' => 'кликов'],
                    ])
                    <div class="la-legend"><span><i style="background:#51a940"></i>показы за день</span><span class="la-muted">наведите на столбик — будут и клики</span></div>
                @endif
            </x-filament::section>

            <x-filament::section heading="Запросы, по которым находят сайт" description="До 100 самых частых. Запросы с названием бренда выделены зелёным. Позиция — среднее место в выдаче: 1 — первая строка.">
                @if ($queries['error'])
                    @include('filament.analytics.partials.error', ['message' => $queries['error']])
                @elseif ($queries['data'] === [])
                    <p class="la-note">За этот период запросов нет — Вебмастер мог ещё не собрать данные. Попробуйте период «30 дней» или «3 месяца».</p>
                @else
                    <div class="la-scroll">
                        <table class="la-table">
                            <thead><tr><th>Запрос</th><th class="num">Показы</th><th class="num">Клики</th><th class="num">CTR</th><th class="num">Позиция</th></tr></thead>
                            <tbody>
                                @foreach ($queries['data'] as $row)
                                    <tr @class(['is-brand' => preg_match($brand, $row['query'])])>
                                        <td>{{ $row['query'] }}</td>
                                        <td class="num">{{ $n($row['shows']) }}</td>
                                        <td class="num">{{ $n($row['clicks']) }}</td>
                                        <td class="num">{{ $f($row['ctr']) }}%</td>
                                        <td class="num">{{ $f($row['position']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>

            <p class="la-note">Подробнее — в <a class="la-link" href="https://webmaster.yandex.ru/sites/" target="_blank" rel="noopener">Яндекс Вебмастере</a>.</p>
        @endif
    </div>
</x-filament-panels::page>
