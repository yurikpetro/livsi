{{--
    Состав заказа. Один блок на все письма покупателю: суммы должны
    выглядеть одинаково в подтверждении, в оплате и в отмене — иначе
    человек начинает сверять и сомневаться.
--}}
@php use App\Support\Money; @endphp

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
       style="margin:24px 0 0 0; border-top:1px solid #000000;">
    @foreach ($order->items as $item)
        <tr>
            <td style="padding:10px 0; border-bottom:1px solid #e5e4e0; font-size:13px; line-height:1.4;">
                {{ $item->label() }}@if ($item->quantity > 1) <span style="color:#6a6a65;">× {{ $item->quantity }}</span>@endif
                @if ($item->is_gift) <span style="color:#51a940;">· подарок</span>@endif
            </td>
            <td style="padding:10px 0; border-bottom:1px solid #e5e4e0; font-size:13px; font-weight:700; text-align:right; white-space:nowrap;">
                {{ Money::rub($item->total) }}
            </td>
        </tr>
    @endforeach

    @if ($order->discount_total > 0)
        <tr>
            <td style="padding:10px 0; border-bottom:1px solid #e5e4e0; font-size:13px; color:#6a6a65;">Скидка</td>
            <td style="padding:10px 0; border-bottom:1px solid #e5e4e0; font-size:13px; text-align:right; white-space:nowrap; color:#6a6a65;">
                −{{ Money::rub($order->discount_total) }}
            </td>
        </tr>
    @endif

    <tr>
        <td style="padding:14px 0 0 0; font-size:14px; font-weight:700; text-transform:uppercase; letter-spacing:0.06em;">Итого</td>
        <td style="padding:14px 0 0 0; font-size:18px; font-weight:700; text-align:right; white-space:nowrap;">
            {{ Money::rub($order->total) }}
        </td>
    </tr>
</table>
