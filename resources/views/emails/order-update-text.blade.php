{{ $details['business']['brand_name'] }}
{{ $heading }}
{{ $updateMessage }}
Dear {{ $details['order']['name'] }},
Tracking ID: {{ $details['order']['reference'] }}
Order date: {{ $details['order']['created_at'] }}
Status: {{ $details['order']['status'] }}
Payment: {{ $details['order']['payment_method'] }} — {{ $details['order']['payment_status'] }}
@foreach($details['items'] as $item)
{{ $item['name'] }} — {{ $item['variant'] }} @if(!empty($item['selected_size'])) Size {{ $item['selected_size'] }} @endif × {{ $item['quantity'] }}: PKR {{ number_format($item['price']*$item['quantity'],2) }}
@endforeach
Delivery: PKR {{ number_format($details['order']['shipping'],2) }}
Total: PKR {{ number_format($details['order']['total'],2) }}
Courier: {{ $details['order']['courier_name'] ?: 'Not yet assigned' }}
Courier tracking: {{ $details['order']['courier_tracking_number'] ?: 'Not yet assigned' }}
Estimated delivery: {{ $details['order']['estimated_delivery_date'] ?: 'Not yet confirmed' }}
Track: {{ url('/track?reference='.rawurlencode($details['order']['reference'])) }}
Use your reference and checkout email to view your order securely.
Reply to this email for help: {{ config('mail.from.address') }}
Website: {{ config('app.url') }}
Return address: {{ $details['business']['return_address'] }}
{{ $details['business']['return_policy'] }}
{{ $details['business']['refund_conditions'] }}
Policy: {{ url('/?policy=delivery') }}
This is a service message about your order, not a marketing subscription.

@if(!empty($details['order']['address']))Shipping address: {{ $details['order']['address'] }}, {{ $details['order']['city'] }} {{ $details['order']['postal_code'] }}, {{ $details['order']['country']??'Pakistan' }}@endif
@if(!empty($details['order']['dispatched_at']))Dispatch date: {{ $details['order']['dispatched_at'] }}@endif
