<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ request()->is('admin') ? 'Rang Mahal — Store Administration' : (isset($seoProduct) ? ($seoProduct->seo_title ?: $seoProduct->name.' | Rang Mahal') : 'Buy Sarees Online in Pakistan | Rang Mahal') }}</title>
    <meta name="description" content="{{ isset($seoProduct) ? ($seoProduct->seo_description ?: $seoProduct->description) : 'Explore sarees online in Pakistan at Rang Mahal. Discover traditional elegance for weddings, gatherings and celebrations.' }}">
    <meta property="og:title" content="Rang Mahal — Colours of Tradition">
    <meta property="og:description" content="Rooted in tradition. Worn with love. Discover sarees for Eid gatherings, wedding celebrations and your everyday moments.">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="en_PK">
    <meta property="og:image" content="{{ url('/images/campaign.jpg') }}">
    <meta name="twitter:card" content="summary_large_image">
    <link rel="icon" type="image/png" sizes="32x32" href="/images/rang-mahal-icon-32.png?v=5">
    <link rel="icon" href="/favicon.ico?v=5" sizes="any">
    <link rel="apple-touch-icon" sizes="180x180" href="/images/rang-mahal-apple-touch.png">
    <link rel="canonical" href="{{ url()->current() }}">
    @if(request()->is('admin'))<meta name="robots" content="noindex,nofollow">@endif
    @viteReactRefresh
    @vite('resources/js/app.jsx')
</head>
<body><script id="business-settings" type="application/json">@json(\App\Support\BusinessSettings::get())</script><div id="app" class="{{ request()->is('admin') ? 'admin-app' : 'rang-mahal-store' }}"></div></body>
</html>
