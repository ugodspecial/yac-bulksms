@props(['label', 'value', 'hint' => null, 'tone' => 'slate', 'icon' => null])
@php
    $tones = [
        'slate' => 'bg-slate-100 text-slate-700',
        'indigo' => 'bg-indigo-50 text-indigo-700',
        'emerald' => 'bg-emerald-50 text-emerald-700',
        'rose' => 'bg-rose-50 text-rose-700',
        'amber' => 'bg-amber-50 text-amber-700',
        'sky' => 'bg-sky-50 text-sky-700',
    ];
@endphp
<div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums text-slate-900">{{ $value }}</p>
            @if ($hint)
                <p class="mt-1 text-xs text-slate-500">{{ $hint }}</p>
            @endif
        </div>
        @if ($icon)
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg {{ $tones[$tone] ?? $tones['slate'] }}">
                <x-ui.icon :name="$icon" class="h-5 w-5" />
            </span>
        @endif
    </div>
</div>
