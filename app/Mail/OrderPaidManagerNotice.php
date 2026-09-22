<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Уведомление менеджеру об оплаченном заказе.
 *
 * Отдельно от письма покупателю: другой адресат, другое содержание
 * и другая тема, чтобы в почте они не смешивались.
 */
class OrderPaidManagerNotice extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Оплачен заказ ' . $this->order->number,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.order-paid-manager');
    }
}
