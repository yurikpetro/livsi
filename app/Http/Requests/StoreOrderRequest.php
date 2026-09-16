<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Данные покупателя при оформлении заказа.
 *
 * Почта обязательна, потому что на неё уходит кассовый чек — этого
 * требует 54-ФЗ, и без адреса платёж создать нельзя.
 */
class StoreOrderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name'  => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'min:10', 'max:30'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'comment' => ['nullable', 'string', 'max:1000'],

            'consent' => ['accepted'],

            // Как и в заявках: рассылка — отдельное добровольное согласие.
            // `sometimes`, а не `nullable`: правило `accepted` неявное
            // и сработало бы на отсутствующем поле.
            'marketing_consent' => ['sometimes', 'accepted'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name'  => 'имя',
            'phone' => 'телефон',
            'email' => 'электронная почта',
        ];
    }

    public function messages(): array
    {
        return [
            'consent.accepted' => 'Без согласия на обработку данных оформить заказ нельзя.',
            'email.required'   => 'Почта нужна, чтобы отправить кассовый чек.',
        ];
    }
}
