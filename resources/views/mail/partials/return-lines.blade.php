{{-- Что возвращают: позиции и их стоимость в заказе. --}}
@php use App\Support\Money; @endphp

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
       style="margin:24px 0 0 0; border-top:1px solid #000000;">
    @foreach ($return->items as $item)
        <tr>
            <td style="padding:10px 0; border-bottom:1px solid #e5e4e0; font-size:13px; line-height:1.4;">
                {{ $item['label'] }}@if ((int) $item['qty'] > 1) <span style="color:#6a6a65;">× {{ $item['qty'] }}</span>@endif
            </td>
            <td style="padding:10px 0; border-bottom:1px solid #e5e4e0; font-size:13px; font-weight:700; text-align:right; white-space:nowrap;">
                {{ Money::rub((int) $item['amount']) }}
            </td>
        </tr>
    @endforeach
</table>
