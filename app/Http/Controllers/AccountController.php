<?php

namespace App\Http\Controllers;

use App\Auth\YandexId;
use App\Models\Order;
use App\Services\CartService;
use App\Support\Phone;
use App\Support\Text;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Closure;

/**
 * Личный кабинет покупателя.
 *
 * Ради двух вещей: видеть свои заказы и повторить любой из них. Повтор —
 * ключевой сценарий для мастеров (`06-scope-v2.md`), они берут одно и то же
 * раз в месяц, и заставлять их каждый раз собирать корзину заново значит
 * терять именно тех, кто покупает регулярно.
 *
 * Кабинет не отменяет гостевой заказ и не мешает ему: страница заказа
 * по-прежнему открывается по токену без всякого входа.
 */
class AccountController extends Controller
{
    public function __construct(private readonly CartService $carts)
    {
    }

    /**
     * Страница входа.
     *
     * Пока способ один — Яндекс. Вход по коду из СМС заказчик назвал
     * основным, но за ним договор с провайдером; место под него здесь же.
     */
    public function login(YandexId $yandex): View
    {
        return view('pages.login', [
            'yandexReady' => $yandex->isConfigured(),
            'robots'      => 'noindex, nofollow',
        ]);
    }

    public function index(Request $request): View
    {
        return view('pages.account', [
            'user'   => $request->user(),
            'orders' => $request->user()->orders()
                ->with('items')
                ->latest()
                ->paginate(10),
            // Личная страница: в поиске ей делать нечего.
            'robots' => 'noindex, nofollow',
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $raw = trim((string) $request->input('phone'));

        // Номер приводим к одному виду до проверок: «8 900…» и «+7 900…» —
        // один и тот же телефон, а уникальность сравнивает строки. Иначе
        // человек занял бы номер дважды в разных написаниях, а вход по коду
        // из СМС потом не нашёл бы владельца.
        //
        // Неразобранное оставляем как есть, чтобы правило ниже сказало об этом
        // вслух: молча стереть непонятый номер — значит потерять его без слова.
        $request->merge(['phone' => Phone::format($raw) ?? $raw]);

        $data = $request->validate([
            'name'  => ['required', 'string', 'max:120'],
            'phone' => [
                'nullable',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (filled($value) && ! Phone::isValid($value)) {
                        $fail('Не похоже на российский номер. Введите в виде +7 900 000-00-00.');
                    }
                },
                // Телефон — будущий вход по коду, поэтому он уникален.
                // Исключаем себя, иначе сохранение без изменений упадёт.
                Rule::unique('users', 'phone')->ignore($request->user()->id),
            ],
        ], [
            'phone.unique' => 'Этот номер уже привязан к другому аккаунту.',
        ]);

        $request->user()->update([
            'name'  => Text::utf8($data['name']),
            'phone' => filled($data['phone'] ?? null) ? $data['phone'] : null,
        ]);

        return back()->with('status', 'Данные сохранены.');
    }

    /**
     * Повторить заказ.
     *
     * Позиции берутся не из снимка заказа, а из живого варианта: цена
     * с тех пор могла измениться, и подставлять старую — обманывать.
     * Что закончилось или снято с продажи, пропускаем и говорим об этом
     * вслух: молча положить в корзину меньше, чем просили, хуже отказа.
     */
    public function repeat(Request $request, Order $order): RedirectResponse
    {
        abort_unless($order->user_id === $request->user()->id, 404);

        $added = 0;
        $skipped = [];

        foreach ($order->items as $item) {
            // Подарок не повторяем: его даёт промо-правило при своих условиях,
            // а не прошлая покупка.
            if ($item->is_gift) {
                continue;
            }

            $variant = $item->variant;

            if (! $variant || ! $variant->is_active || ! $variant->product?->is_active) {
                $skipped[] = $item->label();

                continue;
            }

            if ($this->carts->add($variant, $item->quantity) > 0) {
                $added++;
            } else {
                $skipped[] = $item->label();
            }
        }

        if ($added === 0) {
            return back()->with('status', 'Ничего из этого заказа сейчас нет в наличии.');
        }

        return redirect()->route('cart.index')->with('status', $skipped === []
            ? 'Заказ собран заново — проверьте корзину.'
            : 'Часть позиций недоступна и не добавлена: ' . implode(', ', $skipped) . '.');
    }
}
