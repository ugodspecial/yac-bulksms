@props(['class' => 'bg-slate-100 text-slate-700 ring-slate-200'])
<span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset {{ $class }}">
    {{ $slot }}
</span>
