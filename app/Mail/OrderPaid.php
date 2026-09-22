<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Подтверждение оплаты покупателю.
 *
 * Кассовый чек сюда не вкладывается: его выпускает оператор фискальных
 * данных по сведениям, переданным в платёж, и юридическую силу имеет
 * именно он. Дублировать чек своим письмом — вводить в заблуждение.
 */
class OrderPaid extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Заказ оплачен — ' . $this->order->number,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.order-paid');
    }
}
