@php
    $n = fn ($v) => number_format((float) $v, 0, ',', ' ');
    $d = fn (?string $day) => $day ? \Illuminate\Support\Carbon::parse($day)->format('d.m.Y') : '—';
    // Свой сайт — только путь; другой адрес (www, старый домен) — с хостом, иначе не понять, о чём речь.
    $ourHost = parse_url((string) config('app.url'), PHP_URL_HOST);
    $shortUrl = function (string $url) use ($ourHost) {
        $host = parse_url($url, PHP_URL_HOST);
        $rest = (string) preg_replace('#^https?://[^/]+#', '', $url) ?: '/';
        return $host === $ourHost ? $rest : $host.$rest;
    };
    // Обычная задержка Вебмастера — несколько дней. Больше недели — уже сбой.
    $stale = $ready && $until !== null && $until < now(\App\Analytics\Period::TZ)->subDays(7)->format('Y-m-d');
@endphp
<x-filament-panels::page>
    @include('filament.analytics.partials.styles')

    <div class="la-grid">
        @include('filament.analytics.partials.periods', ['range' => $range])
        @include('filament.analytics.partials.autopilot', ['autopilot' => $autopilot])

        @if (! $ready)
            @include('filament.analytics.partials.setup', [
                'title' => 'Данные поиска появятся после подключения Яндекса',
                'text' => 'Здесь будет: сколько раз сайт показан в поиске Яндекса, сколько раз по нему кликнули, на каком месте он стоит и по каким запросам его находят (в том числе «livsi» и «ливси»), сколько страниц в поиске.',
            ])
        @else
            @if ($summary['error'])
                @include('filament.analytics.partials.error', ['message' => $summary['error']])
            @else
                @php
                    $s = $summary['data']; $p = $s['problems'];
                    $serious = ($p['FATAL'] ?? 0) + ($p['CRITICAL'] ?? 0);
                @endphp
                <div class="la-kpis">
                    <div class="la-kpi"><b>{{ $n($s['searchable']) }}</b><span>страниц в поиске</span></div>
                    <a class="la-kpi" href="#la-events"><b>{{ $n($s['excluded']) }}</b><span>исключено из поиска — <u>какие</u></span></a>
                    <div class="la-kpi"><b>{{ $n($s['sqi']) }}</b><span>ИКС — индекс качества</span></div>
                    <a class="la-kpi" href="#la-problems">
                        <b @style(['color: #dc2626' => $serious > 0])>{{ $serious }}</b>
                        <span>серьёзных проблем · ещё {{ ($p['POSSIBLE_PROBLEM'] ?? 0) + ($p['RECOMMENDATION'] ?? 0) }} советов — <u>какие</u></span>
                    </a>
                </div>
            @endif

            @if ($stale)
                <div class="la-callout" style="box-shadow: inset 0 0 0 1px rgba(234, 88, 12, .55);">
                    <b>У Яндекса нет свежей статистики запросов: последние данные — за {{ $d($until) }}.</b><br>
                    Дни после этой даты на графике пунктиром: Яндекс их ещё не посчитал, это не «ноль показов».
                    Если в самом Вебмастере («Эффективность» → «Поисковые запросы») тоже пусто после {{ $d($until) }} — это сбой на стороне Яндекса,
                    стоит написать в поддержку Вебмастера: «статистика поисковых запросов по livsi.shop не обновляется с {{ $d($until) }}».
                </div>
            @endif

            @if (! $history['error'])
                @php
                    $known = array_filter($history['data'], fn ($row) => $row['shows'] !== null);
                    $shows = array_sum(array_map(fn ($row) => (int) $row['shows'], $known));
                    $clicks = array_sum(array_map(fn ($row) => (int) $row['clicks'], $known));
                @endphp
                <div class="la-kpis">
                    <div class="la-kpi"><b>{{ $known === [] ? '—' : $n($shows) }}</b><span>показов в поиске за период</span></div>
                    <div class="la-kpi"><b>{{ $known === [] ? '—' : $n($clicks) }}</b><span>кликов — переходов на сайт</span></div>
                    <div class="la-kpi"><b>{{ $shows > 0 ? str_replace('.', ',', (string) round(100 * $clicks / $shows, 1)) . '%' : '—' }}</b><span>CTR — доля кликов от показов</span></div>
                </div>
            @endif

            <x-filament::section heading="Показы в поиске по дням" description="Вебмастер отдаёт статистику с задержкой в несколько дней — дни без данных пунктиром.">
                @if ($history['error'])
                    @include('filament.analytics.partials.error', ['message' => $history['error']])
                @else
                    @include('filament.analytics.partials.chart', [
                        'points' => array_map(fn ($row) => ['date' => $row['date'], 'a' => $row['shows'], 'b' => $row['clicks']], $history['data']),
                        'labels' => ['a' => 'показов', 'b' => 'кликов'],
                    ])
                    <div class="la-legend"><span><i style="background:#51a940"></i>показы за день</span><span class="la-muted">наведите на столбик — будут и клики</span></div>
                @endif
            </x-filament::section>

            <x-filament::section heading="Запросы, по которым находят сайт" description="До 100 самых частых. Запросы с названием бренда выделены зелёным. Позиция — среднее место в выдаче: 1 — первая строка.">
                @if ($queries['error'])
                    @include('filament.analytics.partials.error', ['message' => $queries['error']])
                @elseif ($queries['data']['rows'] !== [])
                    @include('filament.analytics.partials.queries', ['rows' => $queries['data']['rows'], 'brand' => $brand])
                    @if ($queries['data']['from'] && $queries['data']['to'])
                        <p class="la-note" style="margin-top: 8px;">Яндекс посчитал за {{ $d($queries['data']['from']) }} – {{ $d($queries['data']['to']) }}.</p>
                    @endif
                @elseif ($latest && ! $latest['error'] && $latest['data']['rows'] !== [])
                    <p class="la-note" style="margin-bottom: 8px;">
                        За выбранный период у Яндекса данных нет. Последняя неделя, которая у него есть, —
                        <b>{{ $d($latest['data']['from']) }} – {{ $d($latest['data']['to']) }}</b>:
                    </p>
                    @include('filament.analytics.partials.queries', ['rows' => $latest['data']['rows'], 'brand' => $brand])
                @else
                    <p class="la-note">За этот период запросов нет. Попробуйте «3 месяца» или «Год».</p>
                @endif
            </x-filament::section>

            <x-filament::section heading="Проблемы сайта — по данным Яндекса" id="la-problems" description="То же, что в Вебмастере → «Диагностика сайта». Исправленная проблема пропадает после следующей проверки роботом (обычно несколько дней).">
                @if ($problems['error'])
                    @include('filament.analytics.partials.error', ['message' => $problems['error']])
                @elseif ($problems['data'] === [])
                    <p class="la-note">Яндекс проблем не видит.</p>
                @else
                    <table class="la-table">
                        <thead><tr><th>Важность</th><th>Что не так</th><th>С какого числа</th></tr></thead>
                        <tbody>
                            @foreach ($problems['data'] as $row)
                                <tr>
                                    <td style="white-space: nowrap; font-weight: 600; color: {{ match ($row['severity']) { 'FATAL', 'CRITICAL' => '#dc2626', 'POSSIBLE_PROBLEM' => '#ea580c', default => 'inherit' } }};">
                                        {{ \App\Analytics\Webmaster::SEVERITY[$row['severity']] ?? $row['severity'] }}
                                    </td>
                                    <td>{{ $row['label'] }}</td>
                                    <td style="white-space: nowrap;">{{ $d($row['since']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-filament::section>

            <x-filament::section heading="Страницы: появились в поиске и исключены" id="la-events" description="Последние изменения, свежие сверху. У исключённых — причина по словам Яндекса.">
                @if ($events['error'])
                    @include('filament.analytics.partials.error', ['message' => $events['error']])
                @elseif ($events['data'] === [])
                    <p class="la-note">Изменений нет.</p>
                @else
                    <div class="la-scroll">
                        <table class="la-table">
                            <thead><tr><th>Когда</th><th></th><th>Страница</th><th>Причина</th></tr></thead>
                            <tbody>
                                @foreach ($events['data'] as $row)
                                    <tr>
                                        <td style="white-space: nowrap;">{{ $d($row['date']) }}</td>
                                        <td style="white-space: nowrap; color: {{ $row['removed'] ? '#dc2626' : '#51a940' }};">{{ $row['removed'] ? 'исключена' : 'в поиске' }}</td>
                                        <td><a class="la-link" href="{{ $row['url'] }}" target="_blank" rel="noopener">{{ \Illuminate\Support\Str::limit(urldecode($shortUrl($row['url'])), 70) }}</a></td>
                                        <td>{{ $row['reason'] ?? '' }}</td>
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
