<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-950">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ $appName ?? config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="flex min-h-full flex-col justify-center bg-gradient-to-br from-slate-950 via-slate-900 to-indigo-950 px-4 py-12 antialiased">
    <div class="mx-auto w-full max-w-md">
        <div class="mb-8 text-center">
            @if (! empty($appLogo))
                <img src="{{ asset('storage/'.$appLogo) }}" alt="{{ $appName }}" class="mx-auto mb-4 h-12 w-auto rounded-lg bg-white/95 p-1.5">
            @else
                <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-indigo-500 text-lg font-black text-white">Y</div>
            @endif
            <h1 class="text-2xl font-semibold tracking-tight text-white">{{ $appName ?? config('app.name') }}</h1>
            <p class="mt-1 text-sm text-slate-400">Bulk SMS campaigns, contacts and delivery reports.</p>
        </div>

        <div class="rounded-2xl bg-white p-6 shadow-xl shadow-slate-950/30 sm:p-8">
            {{ $slot }}
        </div>

        <p class="mt-6 text-center text-xs text-slate-500">
            SMS delivery is provided by Termii. Only message recipients who have consented to receive them.
        </p>
    </div>
    @livewireScripts
</body>
</html>
