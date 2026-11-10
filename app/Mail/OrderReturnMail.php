<?php

namespace App\Mail;

use App\Models\OrderReturn;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Письмо покупателю по заявке на возврат.
 *
 * Четыре повода — заявка принята, возврат одобрен (как передать товар),
 * отказ, деньги вернулись. Шаблоны разные, класс один: адресат и данные
 * одни и те же.
 */
class OrderReturnMail extends Mailable
{
    use Queueable, SerializesModels;

    public const KINDS = ['requested', 'approved', 'rejected', 'refunded'];

    public function __construct(public OrderReturn $return, public string $kind) {}

    public function envelope(): Envelope
    {
        $number = $this->return->order?->number;

        return new Envelope(subject: match ($this->kind) {
            'requested' => 'Заявка на возврат по заказу '.$number.' принята',
            'approved' => 'Возврат по заказу '.$number.' одобрен — как передать товар',
            'rejected' => 'Возврат по заказу '.$number.': решение',
            'refunded' => 'Деньги за возврат по заказу '.$number.' возвращены',
            default => 'Возврат по заказу '.$number,
        });
    }

    public function content(): Content
    {
        return new Content(view: 'mail.return-'.(in_array($this->kind, self::KINDS, true) ? $this->kind : 'requested'));
    }
}
