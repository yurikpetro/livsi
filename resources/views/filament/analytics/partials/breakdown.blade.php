{{-- Таблица «название — визиты — доля» с полоской доли. $rows из Metrika::breakdown(). --}}
@if ($rows === [])
    <p class="la-note">Пока пусто за этот период.</p>
@else
    <table class="la-table">
        <thead><tr><th>{{ $first }}</th><th class="num">Визиты</th><th class="num">Люди</th><th class="num">Доля</th></tr></thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td class="la-bar"><i style="width: {{ min(100, $row['share']) }}%;"></i><span>{{ $row['name'] }}</span></td>
                    <td class="num">{{ number_format($row['visits'], 0, ',', ' ') }}</td>
                    <td class="num">{{ number_format($row['users'], 0, ',', ' ') }}</td>
                    <td class="num">{{ str_replace('.', ',', (string) $row['share']) }}%</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
