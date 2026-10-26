<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ $appName ?? config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full font-sans text-slate-900 antialiased">
    <div x-data="{ sidebarOpen: false }" class="min-h-full">
        <div x-show="sidebarOpen" x-cloak class="fixed inset-0 z-40 lg:hidden" role="dialog" aria-modal="true">
            <div class="fixed inset-0 bg-slate-900/60" x-on:click="sidebarOpen = false"></div>
            <aside class="fixed inset-y-0 left-0 z-50 flex w-72 max-w-[85%] flex-col bg-slate-900 text-white">
                @include('partials.sidebar')
            </aside>
        </div>

        <aside class="hidden lg:fixed lg:inset-y-0 lg:z-30 lg:flex lg:w-64 lg:flex-col lg:border-r lg:border-slate-800 lg:bg-slate-900">
            @include('partials.sidebar')
        </aside>

        <div class="lg:pl-64">
            <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200 bg-white/95 px-4 backdrop-blur sm:px-6 lg:px-8">
                <button type="button" x-on:click="sidebarOpen = true" class="-ml-1 rounded-lg p-2 text-slate-600 hover:bg-slate-100 lg:hidden" aria-label="Open navigation">
                    <x-ui.icon name="menu" class="h-5 w-5" />
                </button>

                <div class="flex min-w-0 flex-1 items-center gap-2">
                    <span class="truncate text-sm font-semibold text-slate-900">{{ $appName ?? config('app.name') }}</span>
                    <span class="hidden rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500 sm:inline">Bulk SMS operations</span>
                </div>

                <div class="flex items-center gap-3" x-data="{ open: false }">
                    <div class="hidden text-right sm:block">
                        <p class="text-sm font-medium leading-tight text-slate-900">{{ auth()->user()?->name }}</p>
                        <p class="text-xs leading-tight text-slate-500">{{ auth()->user()?->role?->label() }}</p>
                    </div>
                    <button type="button" x-on:click="open = !open" class="flex h-9 w-9 items-center justify-center rounded-full bg-indigo-600 text-sm font-semibold text-white" aria-label="Account menu">
                        {{ strtoupper(substr(auth()->user()?->name ?? 'U', 0, 1)) }}
                    </button>
                    <div x-show="open" x-cloak x-on:click.outside="open = false" class="absolute right-4 top-14 w-52 overflow-hidden rounded-xl border border-slate-200 bg-white py-1 shadow-lg">
                        <a href="{{ route('profile') }}" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">My profile</a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="block w-full px-4 py-2 text-left text-sm text-rose-600 hover:bg-rose-50">Sign out</button>
                        </form>
                    </div>
                </div>
            </header>

            <main class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
                @php
                    $flashTones = [
                        'success' => 'border-emerald-200 bg-emerald-50 text-emerald-900',
                        'error' => 'border-rose-200 bg-rose-50 text-rose-900',
                        'warning' => 'border-amber-200 bg-amber-50 text-amber-900',
                        'status' => 'border-sky-200 bg-sky-50 text-sky-900',
                    ];
                @endphp
                @foreach ($flashTones as $flash => $tone)
                    @if (session($flash))
                        <div class="mb-4 flex items-start gap-3 rounded-xl border px-4 py-3 text-sm {{ $tone }}" role="status">
                            <x-ui.icon name="info" class="mt-0.5 h-4 w-4 shrink-0" />
                            <span>{{ session($flash) }}</span>
                        </div>
                    @endif
                @endforeach

                {{ $slot }}
            </main>
        </div>
    </div>

    @livewireScripts
</body>
</html>
