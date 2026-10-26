@props(['variant' => 'primary', 'size' => 'md', 'href' => null, 'type' => 'button'])

@php
    $base = 'inline-flex items-center justify-center gap-2 rounded-lg font-semibold shadow-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60';
    $variants = [
        'primary' => 'bg-indigo-600 text-white hover:bg-indigo-500 focus-visible:ring-indigo-500',
        'secondary' => 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 focus-visible:ring-slate-400',
        'danger' => 'bg-rose-600 text-white hover:bg-rose-500 focus-visible:ring-rose-500',
        'ghost' => 'text-slate-600 hover:bg-slate-100 focus-visible:ring-slate-400',
        'success' => 'bg-emerald-600 text-white hover:bg-emerald-500 focus-visible:ring-emerald-500',
    ];
    $sizes = [
        'sm' => 'px-3 py-1.5 text-xs',
        'md' => 'px-4 py-2 text-sm',
        'lg' => 'px-5 py-2.5 text-sm',
    ];
    $classes = $base.' '.($variants[$variant] ?? $variants['primary']).' '.($sizes[$size] ?? $sizes['md']);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
