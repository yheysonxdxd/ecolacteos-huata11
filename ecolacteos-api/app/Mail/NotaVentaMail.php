<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Correo al cliente con su nota de venta en PDF adjunta. */
class NotaVentaMail extends Mailable
{
    use Queueable;

    public function __construct(public array $datos, private string $pdf) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Nota de venta {$this->datos['numero']} · {$this->datos['empresa']['nombre']}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.nota-venta');
    }

    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->pdf, $this->datos['numero'] . '.pdf')->withMime('application/pdf')];
    }
}
