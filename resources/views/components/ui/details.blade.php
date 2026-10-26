@props(['label', 'value' => null])
<div class="flex items-start justify-between gap-4 py-2">
    <dt class="text-sm text-slate-500">{{ $label }}</dt>
    <dd class="text-right text-sm font-medium text-slate-900">{{ $value ?? $slot }}</dd>
</div>
