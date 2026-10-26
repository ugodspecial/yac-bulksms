@props(['label' => null])
<label {{ $attributes->only('class')->merge(['class' => 'flex items-start gap-2 text-sm text-slate-700']) }}>
    <input type="checkbox" {{ $attributes->except('class')->merge(['class' => 'mt-0.5 h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500']) }}>
    @if ($label)
        <span>{{ $label }}</span>
    @else
        <span>{{ $slot }}</span>
    @endif
</label>
