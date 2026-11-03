<?php

namespace App\Http\Requests;

use App\Services\DeliverySelection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'min:10', 'max:30'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'promo_code' => ['nullable', 'string', 'max:40'],
            'delivery_method' => ['required', Rule::in(array_keys(DeliverySelection::methods()))],
            'delivery_city' => ['required', 'string', 'min:2', 'max:120'],
            'delivery_region' => ['nullable', 'string', 'max:120'],
            'delivery_point_method' => ['nullable', Rule::in(DeliverySelection::pickupMethods())],
            'delivery_address' => [Rule::requiredIf(fn () => $this->input('delivery_method') === DeliverySelection::STAVROPOL_COURIER), 'nullable', 'string', 'max:500'],
            'delivery_point_hint' => ['nullable', 'string', 'max:255'],
            'delivery_point_id' => ['nullable', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/'],
            'delivery_quote_token' => ['nullable', 'string', 'max:16000'],

            'consent' => ['accepted'],

            // Как и в заявках: рассылка — отдельное добровольное согласие.
            // `sometimes`, а не `nullable`: правило `accepted` неявное
            // и сработало бы на отсутствующем поле.
            'marketing_consent' => ['sometimes', 'accepted'],
        ];
    }

    public function after(): array
    {
        return [function ($validator): void {
            if ($this->input('delivery_method') === DeliverySelection::STAVROPOL_COURIER
                && ! DeliverySelection::isStavropol((string) $this->input('delivery_city'))) {
                $validator->errors()->add('delivery_city', 'Курьерская доставка доступна только по Ставрополю.');
            }

            if ($this->filled('delivery_point_id') && $this->input('delivery_method') === DeliverySelection::PICKUP_POINT
                && ! in_array($this->input('delivery_point_method'), DeliverySelection::pickupMethods(), true)) {
                $validator->errors()->add('delivery_point_id', 'Выберите пункт выдачи на карте заново.');
            }
        }];
    }

    public function attributes(): array
    {
        return [
            'name' => 'имя',
            'phone' => 'телефон',
            'email' => 'электронная почта',
            'delivery_method' => 'способ доставки',
            'delivery_city' => 'город доставки',
            'delivery_address' => 'адрес доставки',
            'delivery_point_hint' => 'пожелание по пункту выдачи',
        ];
    }

    public function messages(): array
    {
        return [
            'consent.accepted' => 'Без согласия на обработку данных оформить заказ нельзя.',
            'email.required' => 'Почта нужна, чтобы отправить кассовый чек.',
        ];
    }
}
