<select {{ $attributes->merge(['class' => 'block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 disabled:bg-slate-100']) }}>
    {{ $slot }}
</select>
