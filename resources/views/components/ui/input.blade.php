@props(['type' => 'text'])
<input type="{{ $type }}" {{ $attributes->merge(['class' => 'block w-full rounded-lg border-slate-300 text-sm shadow-sm placeholder:text-slate-400 focus:border-indigo-500 focus:ring-indigo-500 disabled:bg-slate-100']) }}>
