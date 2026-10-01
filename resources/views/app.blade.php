<!DOCTYPE html>
<html lang="pl" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1">
    <meta name="theme-color" content="#0B0B12">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Progresywna aplikacja webowa: instalacja na ekranie glownym, pelny
         ekran bez paska adresu, obsluga braku sieci. Bez sklepu z aplikacjami,
         bo pijany gosc o drugiej w nocy nie zainstaluje aplikacji - kliknie link. --}}
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" type="image/png" sizes="192x192" href="/icons/icon-192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="/icons/icon-512.png">

    {{-- iPhone ignoruje manifest przy ikonach i trybie pelnoekranowym -
         czyta wylacznie wlasne znaczniki. --}}
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="QRowd">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="application-name" content="QRowd">
    <title inertia>{{ config('app.name', 'QRowd') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

    @routes
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body class="bg-tlo text-tekst antialiased">
    @inertia
</body>
</html>
