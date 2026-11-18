<!doctype html><html lang="en"><body style="font-family:Arial,sans-serif;color:#29241d;background:#faf7ef;padding:24px">
<img width="96" height="96" alt="Rang Mahal" src="{{ $message->embed(public_path('images/rang-mahal-email-logo.png')) }}"><h1>{{ $details['business']['brand_name'] }}</h1><h2>Order received</h2>
<p>Order {{ $details['order']['reference'] }} is ready for review in your dashboard.</p>
<ul>@foreach($details['items'] as $item)<li>{{ $item['name'] }} × {{ $item['quantity'] }} @if(!empty($item['code'])) · {{ $item['code'] }} @endif</li>@endforeach</ul>
<p><a href="{{ $details['link'] }}">Open your order dashboard</a></p>
</body></html>
