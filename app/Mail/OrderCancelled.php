<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Сообщение об отменённой оплате.
 *
 * Молчать здесь нельзя: человек ждёт товар, а заказа уже нет.
 * Письмо прямо говорит, что деньги не списаны, — это первый вопрос,
 * который возникает.
 */
class OrderCancelled extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Заказ отменён — ' . $this->order->number,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.order-cancelled');
    }
}
