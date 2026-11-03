{{-- Статус доставки для покупателя. Показывается, только когда заказ дошёл до сборки. --}}
@php
    use App\Support\Fulfillment;

    $ffCustomer = Fulfillment::forCustomer($order);
@endphp

@if ($ffCustomer)
    @php
        $ffSteps = Fulfillment::steps($order);
        $ffCurrent = Fulfillment::current($order);
        $ffTracking = in_array($ffCurrent, [Fulfillment::SHIPPED, Fulfillment::AT_PICKUP, Fulfillment::DELIVERED], true)
            ? $order->shipment_tracking_number
            : null;
    @endphp

    <style>
        .ff-steps { list-style: none; margin: 1.75rem 0 0; padding: 0; display: grid; gap: 0; }
        .ff-step { display: grid; grid-template-columns: 1.5rem minmax(0, 1fr); column-gap: 0.875rem; }
        .ff-rail { display: flex; flex-direction: column; align-items: center; }
        .ff-dot { width: 1.5rem; height: 1.5rem; flex: none; border-radius: 50%; box-sizing: border-box; display: flex; align-items: center; justify-content: center; background: var(--color-paper, #fff); border: 1.5px solid #bdbdb8; }
        .ff-dot.is-reached { background: var(--color-neon, #d0dd56); border-color: var(--color-ink, #000); }
        .ff-line { flex: 1; width: 2px; min-height: 1.25rem; background: var(--color-line, #e5e4e0); }
        .ff-line.is-reached { background: var(--color-ink, #000); }
        .ff-body { padding-bottom: 1rem; }
        .ff-label { font-size: 0.9375rem; line-height: 1.5rem; font-weight: 500; color: var(--color-muted, #6a6a65); }
        .ff-step.is-reached .ff-label { font-weight: 700; color: var(--color-ink, #000); }
        .ff-time { font-size: 0.8125rem; color: var(--color-muted, #6a6a65); }
        .ff-now { display: inline-block; margin-left: 0.5rem; padding: 0 0.375rem; background: var(--color-ink, #000); color: var(--color-neon, #d0dd56); font-size: 0.625rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; vertical-align: 0.15em; line-height: 1.25rem; }
        .ff-track { margin-top: 0.5rem; display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.25rem 0.75rem; font-size: 0.875rem; }
        .ff-track strong { letter-spacing: 0.04em; font-variant-numeric: tabular-nums; }
        @media (min-width: 768px) {
            .ff-steps { grid-auto-flow: column; grid-auto-columns: minmax(0, 1fr); }
            .ff-step { grid-template-columns: none; grid-template-rows: auto auto; align-content: start; }
            .ff-rail { flex-direction: row; }
            .ff-line { width: auto; min-height: 0; height: 2px; margin: 0 0.75rem; }
            .ff-body { padding: 0.75rem 1rem 0 0; }
        }
    </style>

    <div class="mt-8 border border-line p-6 md:p-8" role="status" aria-live="polite">
        <div class="eyebrow">Статус доставки</div>
        <h2 class="mt-3 text-2xl">{{ $ffCustomer['title'] }}</h2>
        <p class="mt-3 max-w-xl text-sm leading-relaxed text-muted">{{ $ffCustomer['text'] }}</p>

        @if ($ffCurrent === Fulfillment::RETURNED)
            <p class="mt-3 text-sm">
                <a href="{{ route('contacts') }}" class="underline hover:text-green">Написать нам</a>
            </p>
        @else
            <ol class="ff-steps">
                @foreach ($ffSteps as $step)
                    <li class="ff-step {{ $step['reached'] ? 'is-reached' : '' }}">
                        <div class="ff-rail" aria-hidden="true">
                            <span class="ff-dot {{ $step['reached'] ? 'is-reached' : '' }}">
                                @if ($step['reached'])
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                @endif
                            </span>
                            @unless ($loop->last)
                                <span class="ff-line {{ ($ffSteps[$loop->index + 1]['reached'] ?? false) ? 'is-reached' : '' }}"></span>
                            @endunless
                        </div>
                        <div class="ff-body">
                            <div class="ff-label">
                                {{ $step['customer_label'] }}
                                @if ($step['current'])<span class="ff-now">сейчас</span>@endif
                                <span class="sr-only">{{ $step['reached'] ? '— пройдено' : '— ожидается' }}</span>
                            </div>
                            <div class="ff-time">
                                {{ $step['reached'] ? ($step['time'] ? $step['time']->copy()->timezone(Fulfillment::TIMEZONE)->translatedFormat('j F, H:i') : '') : 'ожидается' }}
                            </div>
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif

        @if ($ffTracking)
            <div class="ff-track">
                <span class="text-muted">Трек-номер службы доставки:</span>
                <strong>{{ $ffTracking }}</strong>
            </div>
        @endif
    </div>
@endif
