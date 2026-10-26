@php
    $user = auth()->user();
    $navigation = [
        ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'dashboard', 'active' => request()->routeIs('dashboard')],
        ['label' => 'Contacts', 'route' => 'contacts.index', 'icon' => 'users', 'active' => request()->routeIs('contacts.*')],
        ['label' => 'Contact Groups', 'route' => 'groups.index', 'icon' => 'collection', 'active' => request()->routeIs('groups.*')],
        ['label' => 'SMS Campaigns', 'route' => 'campaigns.index', 'icon' => 'megaphone', 'active' => request()->routeIs('campaigns.index') || request()->routeIs('campaigns.show')],
        ['label' => 'Create Campaign', 'route' => 'campaigns.create', 'icon' => 'plus', 'active' => request()->routeIs('campaigns.create') || request()->routeIs('campaigns.edit')],
        ['label' => 'Delivery Reports', 'route' => 'reports.index', 'icon' => 'chart', 'active' => request()->routeIs('reports.*')],
        ['label' => 'Imports', 'route' => 'imports.index', 'icon' => 'upload', 'active' => request()->routeIs('imports.*')],
    ];

    $adminNavigation = [
        ['label' => 'Suppressions', 'route' => 'suppressions.index', 'icon' => 'shield', 'active' => request()->routeIs('suppressions.*')],
        ['label' => 'Audit Logs', 'route' => 'audit.index', 'icon' => 'book', 'active' => request()->routeIs('audit.*')],
        ['label' => 'User Management', 'route' => 'users.index', 'icon' => 'user-cog', 'active' => request()->routeIs('users.*')],
        ['label' => 'Settings', 'route' => 'settings', 'icon' => 'cog', 'active' => request()->routeIs('settings')],
    ];
@endphp

<div class="flex h-16 shrink-0 items-center gap-3 border-b border-slate-800 px-5">
    @if (! empty($appLogo))
        <img src="{{ asset('storage/'.$appLogo) }}" alt="" class="h-9 w-9 rounded-lg bg-white object-contain p-1">
    @else
        <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-indigo-500 text-sm font-black text-white">Y</div>
    @endif
    <div class="min-w-0">
        <p class="truncate text-sm font-semibold text-white">{{ $appName ?? config('app.name') }}</p>
        <p class="truncate text-[11px] text-slate-400">Powered by Termii</p>
    </div>
</div>

<nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4" aria-label="Main">
    @foreach ($navigation as $item)
        <a href="{{ route($item['route']) }}" @class(['nav-link', 'nav-link-active' => $item['active']])>
            <x-ui.icon :name="$item['icon']" class="h-5 w-5 shrink-0" />
            <span>{{ $item['label'] }}</span>
        </a>
    @endforeach

    @if ($user?->isAdmin())
        <p class="px-3 pb-1 pt-5 text-[11px] font-semibold uppercase tracking-wider text-slate-500">Administration</p>
        @foreach ($adminNavigation as $item)
            <a href="{{ route($item['route']) }}" @class(['nav-link', 'nav-link-active' => $item['active']])>
                <x-ui.icon :name="$item['icon']" class="h-5 w-5 shrink-0" />
                <span>{{ $item['label'] }}</span>
            </a>
        @endforeach
    @endif
</nav>

<div class="border-t border-slate-800 p-4">
    <div class="flex items-center gap-3">
        <div class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-700 text-sm font-semibold text-white">
            {{ strtoupper(substr($user?->name ?? 'U', 0, 1)) }}
        </div>
        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium text-white">{{ $user?->name }}</p>
            <p class="truncate text-xs text-slate-400">{{ $user?->role?->label() }}</p>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="rounded-lg p-2 text-slate-400 hover:bg-white/10 hover:text-white" aria-label="Sign out">
                <x-ui.icon name="logout" class="h-5 w-5" />
            </button>
        </form>
    </div>
</div>
