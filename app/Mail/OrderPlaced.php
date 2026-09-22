<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Подтверждение заказа покупателю.
 *
 * Уходит сразу после оформления, до оплаты: в нём ссылка на заказ,
 * и для гостя она заменяет личный кабинет. Закрыл вкладку и не получил
 * письма — заказ для него потерян.
 */
class OrderPlaced extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Заказ принят — ' . $this->order->number,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.order-placed');
    }
}
