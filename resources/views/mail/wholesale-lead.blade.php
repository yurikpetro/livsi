{{--
    Простое письмо менеджеру. Без вёрстки и картинок: его читает сотрудник
    в рабочей почте, а не покупатель. Общий шаблон под транзакционные письма
    покупателю — отдельная задача (docs/08-roadmap.md § 4.11).
--}}
<p><strong>Заявка на оптовое партнёрство №{{ $lead->id }}</strong></p>

<p>
    Имя: {{ $lead->name }}<br>
    Связь: {{ $lead->contact }}<br>
    Город: {{ $lead->city ?? 'не указан' }}<br>
    Формат продаж: {{ $lead->salesFormatLabel() ?? 'не указан' }}
</p>

@if ($lead->comment)
    <p>
        Комментарий:<br>
        {!! nl2br(e($lead->comment)) !!}
    </p>
@endif

<p>
    Получена: {{ $lead->created_at->timezone(config('app.timezone'))->format('d.m.Y H:i') }}<br>
    Согласие на обработку данных: {{ $lead->consent_at->timezone(config('app.timezone'))->format('d.m.Y H:i') }}
</p>

@if ($lead->utm)
    <p>
        Источник:<br>
        @foreach ($lead->utm as $key => $value)
            {{ $key }}: {{ $value }}<br>
        @endforeach
    </p>
@endif

<p>
    <a href="{{ url('/admin/lead-requests/' . $lead->id . '/edit') }}">Открыть заявку в админке</a>
</p>
