<?php

namespace App\Mail;

use App\Models\OrderReturn;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Менеджеру: покупатель просит вернуть заказ. */
class OrderReturnManagerNotice extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public OrderReturn $return) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Клиент просит вернуть заказ '.$this->return->order?->number);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.return-requested-manager');
    }
}
