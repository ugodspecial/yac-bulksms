@props(['title', 'description' => null, 'icon' => 'info'])
<div class="flex flex-col items-center justify-center gap-3 px-6 py-14 text-center">
    <span class="flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-500">
        <x-ui.icon :name="$icon" class="h-6 w-6" />
    </span>
    <div>
        <p class="text-sm font-semibold text-slate-900">{{ $title }}</p>
        @if ($description)
            <p class="mt-1 max-w-md text-sm text-slate-500">{{ $description }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="mt-1">{{ $actions }}</div>
    @endisset
</div>
