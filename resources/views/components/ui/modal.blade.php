@props(['show' => 'showModal', 'maxWidth' => 'max-w-lg'])

<div
    x-data
    x-show="$wire.{{ $show }}"
    x-cloak
    x-on:keydown.escape.window="$wire.set('{{ $show }}', false)"
    class="fixed inset-0 z-50 overflow-y-auto"
    role="dialog"
    aria-modal="true"
>
    <div class="flex min-h-full items-end justify-center px-4 pb-4 pt-10 text-center sm:items-center sm:p-0">
        <div class="fixed inset-0 bg-slate-900/50 transition-opacity" x-on:click="$wire.set('{{ $show }}', false)"></div>

        <div class="relative w-full {{ $maxWidth }} overflow-hidden rounded-2xl bg-white p-6 text-left shadow-xl">
            {{ $slot }}
        </div>
    </div>
</div>
