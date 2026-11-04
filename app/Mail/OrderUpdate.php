<?php
namespace App\Mail;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
class OrderUpdate extends Mailable {
 public function __construct(public array $details){}
 public function heading(): string {
  if($this->details['event']==='setup')return 'Email setup test — no order has been placed';
  if($this->details['event']==='placed')return 'Order placed — we have received it';
  if($this->details['event']!=='status')return 'Your order details have been updated';
  return match($this->details['order']['status']) {
   'Confirmed'=>'Your order is confirmed','Processing'=>'Your order is being prepared','Packed'=>'Your order is packed',
   'Shipped'=>'Your order has been dispatched','Out for Delivery'=>'Your order is out for delivery','Delivered'=>'Your order is marked delivered',
   'Cancelled'=>'Your order has been cancelled','Returned'=>'Your order is marked returned',default=>'An update on your order'
  };
 }
 public function updateMessage(): string {
  if($this->details['event']==='setup')return 'This is a one-time email setup check for Rang Mahal. No customer order has been placed, no payment is requested and no inventory has changed.';
  if($this->details['event']==='placed')return 'Thank you for choosing Rang Mahal. We have received your order. Our team will review it and keep you informed as it moves towards your doorstep.';
  if($this->details['event']!=='status')return 'Your shipping or payment details have changed. Please review the latest recorded information below.';
  return match($this->details['order']['status']) {
   'Confirmed'=>'Our team has confirmed your order. You can follow its progress using your tracking ID.',
   'Processing'=>'Our team is preparing your selection. If you selected stitching, the measurements submitted at checkout will be used for your order.',
   'Packed'=>'Your order has been packed and is awaiting dispatch.',
   'Shipped'=>'Your order has been marked dispatched. Courier details appear below when assigned.',
   'Out for Delivery'=>'Your order has been marked out for delivery. Please keep your phone available for the courier.',
   'Delivered'=>'Your order has been marked delivered. Thank you for making Rang Mahal part of your story. If this does not match your delivery, please reply to this email.',
   'Cancelled'=>'Your order has been cancelled. Any applicable payment or refund status is shown below. Contact us if you need help.',
   'Returned'=>'Your order has been marked returned. Any replacement or refund is subject to the recorded order details and applicable policy; please contact our team for assistance.',
   default=>'Here is the latest recorded information about your Rang Mahal order.'
  };
 }
 public function envelope(): Envelope {return new Envelope(subject:$this->details['business']['brand_name'].' | '.$this->heading().' | '.$this->details['order']['reference'],replyTo:[new Address(config('mail.from.address'),config('mail.from.name'))]);}
 public function content(): Content {return new Content(view:'emails.order-update',text:'emails.order-update-text',with:['heading'=>$this->heading(),'updateMessage'=>$this->updateMessage()]);}
}
