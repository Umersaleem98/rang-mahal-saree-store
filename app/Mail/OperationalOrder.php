<?php
namespace App\Mail;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\{Content,Envelope};

class OperationalOrder extends Mailable
{
    public function __construct(public array $details){}
    public function envelope():Envelope
    {
        return new Envelope(subject:'Rang Mahal order received | '.$this->details['order']['reference']);
    }
    public function content():Content
    {
        return new Content(view:'emails.operational-order');
    }
}
