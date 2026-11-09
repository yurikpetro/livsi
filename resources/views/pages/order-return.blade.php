@extends('layouts.app')

@section('title', 'Возврат по заказу ' . $order->number . ' — LIVSI')

@php
    use App\Models\OrderReturn;
    use App\Services\ReturnService;
    use App\Support\Money;

    $hints = [
        OrderReturn::REASON_UNFIT => 'Товар в заводской упаковке, не вскрыт. Обратная доставка — за ваш счёт: если она платная, стоимость удержим из суммы возврата, точную сумму напишем в письме.',
        OrderReturn::REASON_DEFECT => 'Опишите, что не так, и приложите фото. Доставку оплачиваем мы.',
        OrderReturn::REASON_WRONG => 'Напишите, что пришло вместо заказанного, приложите фото. Доставку оплачиваем мы.',
        OrderReturn::REASON_DAMAGED => 'Приложите фото повреждений и упаковки. Доставку оплачиваем мы.',
        OrderReturn::REASON_OTHER => 'Опишите ситуацию — подскажем, как лучше.',
    ];
    $oldQty = (array) old('qty', []);
@endphp

@section('content')
    <section class="site-container py-16 md:py-24">
        <div class="eyebrow">Заказ {{ $order->number }} от {{ $order->created_at->translatedFormat('j F Y') }}</div>
        <h1 class="mt-4 text-3xl md:text-4xl">Вернуть заказ</h1>

        <div class="mt-6 max-w-2xl border-l-4 border-ink bg-shell px-5 py-4 text-sm leading-relaxed">
            @if ($rules['until'])
                Вернуть товар, который не подошёл, можно до {{ $rules['until']->translatedFormat('j F') }} включительно.
            @else
                {{ $rules['note'] }}
            @endif
            Товар надлежащего качества принимаем в заводской упаковке, с защитной плёнкой и без следов использования:
            вскрытая косметика считается бывшей в употреблении. Деньги вернём тем же способом, которым вы платили,
            не позднее {{ OrderReturn::REFUND_DAYS }} дней со дня заявки.
            Подробно — на странице <a href="{{ route('legal.returns') }}" class="underline hover:text-ink" target="_blank">«Возврат товара»</a>.
        </div>

        <form method="POST" action="{{ route('order.return.store', ['order' => $order->number]) }}" enctype="multipart/form-data"
              class="return-form mt-10 max-w-2xl" data-return-form>
            @csrf
            @if ($token)
                <input type="hidden" name="token" value="{{ $token }}">
            @endif

            <div class="eyebrow">Что возвращаете</div>
            <div class="mt-5 border-t border-ink">
                @foreach ($rows as $row)
                    @php($item = $row['item'])
                    <div class="return-item border-b border-line py-4 text-sm">
                        @if ($row['max'] === 1)
                            <label class="consent return-item-check">
                                <input type="checkbox" name="qty[{{ $item->id }}]" value="1" @checked((int) ($oldQty[$item->id] ?? (count($rows) === 1 ? 1 : 0)) === 1)>
                                <span class="text-ink">{{ $item->label() }}</span>
                            </label>
                        @else
                            <label class="return-item-qty">
                                <span>{{ $item->label() }} <span class="text-muted">× {{ $row['max'] }} в заказе</span></span>
                                <select name="qty[{{ $item->id }}]" class="field-control">
                                    @for ($n = 0; $n <= $row['max']; $n++)
                                        <option value="{{ $n }}" @selected((int) ($oldQty[$item->id] ?? 0) === $n)>{{ $n === 0 ? 'Не возвращаю' : $n.' шт.' }}</option>
                                    @endfor
                                </select>
                            </label>
                        @endif
                        <span class="font-bold whitespace-nowrap">{{ Money::rub(intdiv((int) $item->total, max(1, (int) $item->quantity))) }} / шт.</span>
                    </div>
                @endforeach
            </div>
            @error('items')<p class="field-error mt-2">{{ $message }}</p>@enderror
            @error('qty')<p class="field-error mt-2">{{ $message }}</p>@enderror

            <div class="eyebrow mt-10">Причина</div>
            <div class="delivery-choices return-reasons mt-5">
                @foreach ($rules['reasons'] as $reason)
                    <label class="delivery-choice">
                        <input type="radio" name="reason" value="{{ $reason }}" required @checked(old('reason') === $reason)>
                        <span class="delivery-choice-copy">
                            <strong>{{ OrderReturn::reasons()[$reason] }}</strong>
                            <small>{{ $hints[$reason] ?? '' }}</small>
                        </span>
                    </label>
                @endforeach
            </div>
            @error('reason')<p class="field-error mt-2">{{ $message }}</p>@enderror

            <label class="field mt-8">
                <span class="field-label">Комментарий</span>
                <textarea name="comment" rows="4" maxlength="2000" class="field-control"
                          placeholder="Что случилось с товаром. Если удобно вернуть иначе — напишите здесь.">{{ old('comment') }}</textarea>
                @error('comment')<span class="field-error">{{ $message }}</span>@enderror
            </label>

            <label class="field mt-6">
                <span class="field-label">Фото — до {{ ReturnService::MAX_PHOTOS }}, для брака и повреждений обязательно пригодятся</span>
                <input type="file" name="photos[]" accept="image/*" multiple class="field-control" data-return-photos>
                @error('photos')<span class="field-error">{{ $message }}</span>@enderror
                @error('photos.*')<span class="field-error">{{ $message }}</span>@enderror
            </label>

            <label class="consent mt-8">
                <input type="checkbox" name="conditions" value="1" required @checked(old('conditions'))>
                <span>
                    С <a href="{{ route('legal.returns') }}" target="_blank" class="underline hover:text-ink">условиями возврата</a> ознакомлен(а)
                    @error('conditions')<span class="field-error mt-1 block">{{ $message }}</span>@enderror
                </span>
            </label>

            <div class="mt-8 flex flex-wrap items-center gap-6">
                <button type="submit" class="btn btn-dark" data-return-submit>Отправить заявку</button>
                <a href="{{ $backUrl }}" class="text-[11px] font-bold uppercase tracking-[0.08em] text-muted hover:text-ink">К заказу</a>
            </div>
        </form>
    </section>

    <script>
        // Фото с телефона весят по 3–8 МБ: перед отправкой сжимаем их до 1400 px.
        // Не вышло (старый браузер, необычный формат) — уходят как есть.
        (() => {
            const input = document.querySelector('[data-return-photos]');
            const form = document.querySelector('[data-return-form]');
            if (!input || !form || typeof DataTransfer === 'undefined') return;
            const shrink = (file) => new Promise((resolve) => {
                if (!file.type.startsWith('image/') || file.size < 400 * 1024) return resolve(file);
                const url = URL.createObjectURL(file);
                const img = new Image();
                img.onload = () => {
                    const scale = Math.min(1, 1400 / Math.max(img.naturalWidth, img.naturalHeight));
                    const canvas = document.createElement('canvas');
                    canvas.width = Math.round(img.naturalWidth * scale);
                    canvas.height = Math.round(img.naturalHeight * scale);
                    canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
                    canvas.toBlob((blob) => {
                        URL.revokeObjectURL(url);
                        resolve(blob ? new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : file);
                    }, 'image/jpeg', 0.78);
                };
                img.onerror = () => { URL.revokeObjectURL(url); resolve(file); };
                img.src = url;
            });
            input.addEventListener('change', async () => {
                const button = form.querySelector('[data-return-submit]');
                button.disabled = true;
                try {
                    const files = await Promise.all(Array.from(input.files).slice(0, {{ ReturnService::MAX_PHOTOS }}).map(shrink));
                    const box = new DataTransfer();
                    files.forEach((file) => box.items.add(file));
                    input.files = box.files;
                } catch (e) {
                    // оставляем выбранные файлы как есть
                } finally {
                    button.disabled = false;
                }
            });
        })();
    </script>
@endsection
