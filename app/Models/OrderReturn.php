<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Заявка покупателя на возврат товара после получения.
 *
 * Суммы — в копейках. `items` — снимок того, что возвращают:
 * [{item_id, label, sku, qty, amount}], amount — сколько эти штуки стоили
 * в заказе с учётом скидки.
 */
class OrderReturn extends Model
{
    protected $guarded = [];

    protected $casts = [
        'items' => 'array',
        'photos' => 'array',
        'marks_to_return' => 'array',
        'marks_returned_at' => 'datetime',
        'partial' => 'boolean',
        'refund_delivery' => 'boolean',
        'restocked' => 'boolean',
        'decided_at' => 'datetime',
        'shipment_checked_at' => 'datetime',
        'arrived_notified_at' => 'datetime',
        'received_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    public const REQUESTED = 'requested';

    public const APPROVED = 'approved';

    public const RECEIVED = 'received';

    public const REFUNDED = 'refunded';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    /** Не подошёл — товар надлежащего качества, обратную доставку оплачивает покупатель. */
    public const REASON_UNFIT = 'unfit';

    public const REASON_DEFECT = 'defect';

    public const REASON_WRONG = 'wrong';

    public const REASON_DAMAGED = 'damaged';

    public const REASON_OTHER = 'other';

    public const METHOD_CDEK = 'cdek';

    public const METHOD_COURIER = 'courier';

    public const METHOD_MANUAL = 'manual';

    public const PAYER_SELLER = 'seller';

    public const PAYER_BUYER = 'buyer';

    /** Сколько дней на возврат денег со дня требования (ЗоЗПП, ст. 26.1). */
    public const REFUND_DAYS = 10;

    public static function statuses(): array
    {
        return [
            self::REQUESTED => 'Клиент просит вернуть',
            self::APPROVED => 'Возврат подтверждён',
            self::RECEIVED => 'Товар получен',
            self::REFUNDED => 'Деньги возвращены',
            self::REJECTED => 'Отказано',
            self::CANCELLED => 'Отозвана покупателем',
        ];
    }

    public static function reasons(): array
    {
        return [
            self::REASON_UNFIT => 'Не подошёл',
            self::REASON_DEFECT => 'Брак или недостаток',
            self::REASON_WRONG => 'Прислали не тот товар',
            self::REASON_DAMAGED => 'Повреждён при доставке',
            self::REASON_OTHER => 'Другое',
        ];
    }

    /** Причины, при которых обратную доставку оплачиваем мы. */
    public static function sellerReasons(): array
    {
        return [self::REASON_DEFECT, self::REASON_WRONG, self::REASON_DAMAGED];
    }

    public static function methods(): array
    {
        return [
            self::METHOD_CDEK => 'Накладная СДЭК — покупатель сдаёт посылку в пункт СДЭК',
            self::METHOD_COURIER => 'Наш курьер заберёт (Ставрополь)',
            self::METHOD_MANUAL => 'Иначе — опишу покупателю в письме',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->status] ?? $this->status;
    }

    public function reasonLabel(): string
    {
        return self::reasons()[$this->reason] ?? $this->reason;
    }

    public function methodLabel(): ?string
    {
        return match ($this->method) {
            self::METHOD_CDEK => 'накладная СДЭК',
            self::METHOD_COURIER => 'заберёт курьер',
            self::METHOD_MANUAL => 'по договорённости',
            default => null,
        };
    }

    /** Заявка ещё в работе: по заказу нельзя завести вторую. */
    public function isActive(): bool
    {
        return in_array($this->status, [self::REQUESTED, self::APPROVED, self::RECEIVED], true);
    }

    public function isSellerFault(): bool
    {
        return in_array($this->reason, self::sellerReasons(), true);
    }

    public function buyerPaysShipping(): bool
    {
        return $this->shipping_payer === self::PAYER_BUYER;
    }

    /** Срок, до которого закон велит вернуть деньги. */
    public function refundDeadline(): Carbon
    {
        return $this->created_at->copy()->addDays(self::REFUND_DAYS);
    }

    /** Возвращают все позиции заказа (кроме подарка). */
    public function isFull(): bool
    {
        return ! $this->partial;
    }

    /** Удержание за обратную доставку, копейки. */
    public function deduction(): int
    {
        return $this->buyerPaysShipping() ? max(0, (int) $this->shipping_cost) : 0;
    }

    /** Стоимость доставки заказа, которую возвращаем вместе с товаром. */
    public function deliveryRefund(): int
    {
        return $this->refund_delivery && $this->isFull() ? max(0, (int) $this->order?->delivery_price) : 0;
    }

    /** Сколько вернуть покупателю по расчёту. */
    public function expectedRefund(): int
    {
        return max(0, (int) $this->goods_amount + $this->deliveryRefund() - $this->deduction());
    }

    /** «Мультипенка, 200 мл × 2» по строкам. */
    public function itemsText(string $glue = ', '): string
    {
        return collect($this->items ?? [])
            ->map(fn (array $item): string => $item['label'].((int) $item['qty'] > 1 ? ' × '.$item['qty'] : ''))
            ->implode($glue);
    }

    /** Состояние для покупателя — одной строкой, без внутренней кухни. */
    public function customerStatus(): string
    {
        return match ($this->status) {
            self::REQUESTED => 'Заявка на возврат на рассмотрении — ответим письмом.',
            self::APPROVED => match (true) {
                $this->method === self::METHOD_CDEK && filled($this->shipment_number) => 'Возврат одобрен. Сдайте посылку в пункт СДЭК, номер возврата '.$this->shipment_number.'.',
                $this->method === self::METHOD_CDEK => 'Возврат одобрен. Оформляем накладную СДЭК — пришлём её номер письмом.',
                $this->method === self::METHOD_COURIER => 'Возврат одобрен. Курьер свяжется с вами, чтобы забрать товар.',
                default => 'Возврат одобрен. Как передать товар — в письме.',
            },
            self::RECEIVED => 'Товар получен — готовим возврат денег.',
            self::REFUNDED => 'Деньги возвращены: '.Money::rub((int) $this->refund_amount).'.',
            self::REJECTED => 'В возврате отказано'.(filled($this->reject_reason) ? ': '.$this->reject_reason : '.'),
            self::CANCELLED => 'Заявка на возврат отозвана.',
            default => $this->statusLabel(),
        };
    }

    /**
     * Заявки, по которым у магазина есть дело: ждут решения, товара, денег
     * или ручного возврата кодов в оборот. Для списка заказов и вкладки.
     */
    public static function scopeNeedsWork($query)
    {
        return $query->where(fn ($q) => $q->whereIn('status', [self::REQUESTED, self::APPROVED, self::RECEIVED])
            ->orWhere(fn ($q) => $q->where('status', self::REFUNDED)->whereNotNull('marks_to_return')->whereNull('marks_returned_at')));
    }

    /** Коды ждут ручного возврата в оборот в «Честном знаке». */
    public function marksPending(): bool
    {
        return ! empty($this->marks_to_return) && $this->marks_returned_at === null;
    }

    /**
     * Коды идентификации для «Честного знака» из записи возврата денег:
     * «GTIN серийный» → 01GTIN21серийный.
     *
     * @param  list<string>  $released
     * @return list<string>
     */
    public static function identificationCodes(array $released): array
    {
        $codes = [];
        foreach ($released as $mark) {
            [$gtin, $serial] = array_pad(explode(' ', (string) $mark, 2), 2, '');
            if ($gtin !== '' && $serial !== '') {
                $codes[] = '01'.$gtin.'21'.$serial;
            }
        }

        return $codes;
    }

    /** Есть ли у СДЭК посылка, которая пришла к нам. */
    public function arrived(): bool
    {
        return in_array($this->shipment_status_code, ['ACCEPTED_AT_PICK_UP_POINT', 'POSTOMAT_POSTED', 'DELIVERED', 'POSTOMAT_RECEIVED'], true);
    }
}
