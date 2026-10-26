@php
    $step = $step ?? 1;
    $steps = [1 => 'Environment', 2 => 'Database', 3 => 'Application', 4 => 'Finish'];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Installation') · YAC-BulkSMS</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        :root {
            --ink: #0f172a;
            --muted: #64748b;
            --line: #e2e8f0;
            --brand: #4f46e5;
            --brand-dark: #4338ca;
            --ok: #059669;
            --warn: #b45309;
            --fail: #be123c;
        }
        body {
            margin: 0;
            min-height: 100vh;
            padding: 40px 16px 56px;
            background: radial-gradient(1200px 600px at 50% -10%, #1e293b 0%, #0f172a 55%, #020617 100%);
            color: var(--ink);
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            font-size: 15px;
            line-height: 1.55;
            -webkit-font-smoothing: antialiased;
        }
        .shell { width: 100%; max-width: 720px; margin: 0 auto; }
        .brand { display: flex; align-items: center; gap: 12px; margin-bottom: 22px; }
        .brand-mark {
            display: flex; align-items: center; justify-content: center;
            width: 42px; height: 42px; border-radius: 12px;
            background: var(--brand); color: #fff; font-weight: 800; font-size: 18px;
        }
        .brand-title { margin: 0; color: #fff; font-weight: 650; letter-spacing: -0.01em; }
        .brand-sub { margin: 0; color: #94a3b8; font-size: 12px; text-transform: uppercase; letter-spacing: 0.08em; }

        .steps { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 16px; padding: 0; list-style: none; }
        .step {
            display: flex; align-items: center; gap: 8px;
            padding: 6px 12px; border-radius: 999px;
            background: rgba(148, 163, 184, 0.12);
            color: #cbd5e1; font-size: 12.5px; font-weight: 500;
        }
        .step-number {
            display: inline-flex; align-items: center; justify-content: center;
            width: 19px; height: 19px; border-radius: 50%;
            background: rgba(148, 163, 184, 0.3); color: #e2e8f0; font-size: 11px; font-weight: 700;
        }
        .step-current { background: rgba(79, 70, 229, 0.9); color: #fff; }
        .step-current .step-number { background: rgba(255, 255, 255, 0.28); color: #fff; }
        .step-done { color: #a5b4fc; }
        .step-done .step-number { background: rgba(129, 140, 248, 0.35); color: #e0e7ff; }
        .step-label { white-space: nowrap; }

        .card {
            background: #fff; border-radius: 18px; padding: 28px;
            box-shadow: 0 20px 45px -20px rgba(2, 6, 23, 0.7), 0 1px 0 rgba(15, 23, 42, 0.04);
        }
        h1 { margin: 0 0 6px; font-size: 22px; letter-spacing: -0.02em; }
        h2 { margin: 26px 0 10px; font-size: 15px; letter-spacing: -0.01em; }
        .lead { margin: 0 0 18px; color: var(--muted); font-size: 14px; }
        p { margin: 0 0 10px; }
        a { color: var(--brand-dark); }

        .checks { list-style: none; margin: 0; padding: 0; border: 1px solid var(--line); border-radius: 12px; overflow: hidden; }
        .check { display: flex; gap: 10px; padding: 10px 14px; border-top: 1px solid var(--line); }
        .check:first-child { border-top: 0; }
        .dot { flex: 0 0 auto; width: 9px; height: 9px; margin-top: 7px; border-radius: 50%; background: var(--muted); }
        .dot-ok { background: var(--ok); }
        .dot-warn { background: var(--warn); }
        .dot-fail { background: var(--fail); }
        .check-body { flex: 1 1 auto; min-width: 0; }
        .check-label { display: block; font-weight: 600; font-size: 13.5px; }
        .check-detail { display: block; color: var(--muted); font-size: 12.5px; overflow-wrap: anywhere; }
        .tag { flex: 0 0 auto; align-self: flex-start; font-size: 10.5px; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; padding: 2px 7px; border-radius: 999px; background: #f1f5f9; color: #475569; }
        .tag-recommended { background: #eff6ff; color: #1d4ed8; }

        .alert { border-radius: 12px; padding: 12px 14px; margin: 0 0 18px; font-size: 13.5px; border: 1px solid transparent; }
        .alert p { margin: 0; }
        .alert ul { margin: 6px 0 0; padding-left: 20px; }
        .alert-title { font-weight: 650; }
        .alert-error { background: #fff1f2; border-color: #fecdd3; color: #881337; }
        .alert-info { background: #eef2ff; border-color: #c7d2fe; color: #3730a3; }
        .alert-warn { background: #fffbeb; border-color: #fde68a; color: #78350f; }
        .alert-ok { background: #ecfdf5; border-color: #a7f3d0; color: #065f46; }

        .field { display: block; margin-bottom: 14px; }
        .label { display: block; margin-bottom: 5px; font-size: 13px; font-weight: 600; }
        .help { margin: 5px 0 0; color: var(--muted); font-size: 12px; }
        input[type="text"], input[type="email"], input[type="password"], input[type="url"], input[type="number"], select {
            width: 100%; padding: 9px 11px; font: inherit; font-size: 14px; color: var(--ink);
            background: #fff; border: 1px solid #cbd5e1; border-radius: 9px;
            box-shadow: 0 1px 1px rgba(15, 23, 42, 0.03);
        }
        input:focus, select:focus { outline: 2px solid rgba(79, 70, 229, 0.35); outline-offset: 1px; border-color: var(--brand); }
        .grid-2 { display: grid; gap: 0 14px; grid-template-columns: 1fr 1fr; }
        @media (max-width: 560px) { .grid-2 { grid-template-columns: 1fr; } }

        .actions { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 22px; }
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            padding: 10px 18px; border: 0; border-radius: 10px; cursor: pointer;
            font: inherit; font-size: 14px; font-weight: 600; text-decoration: none;
        }
        .btn-primary { background: var(--brand); color: #fff; }
        .btn-primary:hover { background: var(--brand-dark); }
        .btn-secondary { background: #f1f5f9; color: #334155; }
        .btn-secondary:hover { background: #e2e8f0; }
        .btn-disabled { background: #e2e8f0; color: #94a3b8; cursor: not-allowed; }
        .link { padding: 0; border: 0; background: none; font: inherit; color: var(--brand-dark); text-decoration: underline; cursor: pointer; }
        .skip { margin: 22px 0 0; padding-top: 16px; border-top: 1px solid var(--line); color: var(--muted); font-size: 13px; }

        .summary { display: flex; flex-wrap: wrap; gap: 6px 10px; align-items: baseline; padding: 10px 14px; margin-bottom: 16px; border-radius: 10px; background: #f8fafc; border: 1px solid var(--line); font-size: 13px; }
        .summary strong { font-weight: 650; }
        code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12.5px; background: #f1f5f9; padding: 1.5px 5px; border-radius: 5px; }
        pre { margin: 8px 0 0; padding: 12px 14px; overflow-x: auto; border-radius: 10px; background: #0f172a; color: #e2e8f0; font-size: 12.5px; }
        pre code { background: none; padding: 0; color: inherit; }
        ul.plain { margin: 8px 0 0; padding-left: 20px; color: var(--muted); font-size: 13.5px; }
        ul.plain li { margin-bottom: 6px; }
        .footnote { margin: 18px 2px 0; color: #64748b; font-size: 12px; text-align: center; }
        @media (max-width: 560px) { .card { padding: 22px 18px; } }
    </style>
</head>
<body>
<div class="shell">
    <header class="brand">
        <div class="brand-mark">Y</div>
        <div>
            <p class="brand-title">YAC-BulkSMS</p>
            <p class="brand-sub">Installation</p>
        </div>
    </header>

    <ol class="steps">
        @foreach ($steps as $number => $label)
            @php
                $classes = 'step';

                if ($step === $number) {
                    $classes .= ' step-current';
                } elseif ($step > $number) {
                    $classes .= ' step-done';
                }
            @endphp
            <li class="{{ $classes }}">
                <span class="step-number">{{ $step > $number ? '✓' : $number }}</span>
                <span class="step-label">{{ $label }}</span>
            </li>
        @endforeach
    </ol>

    <main class="card">
        @yield('content')
    </main>

    <p class="footnote">
        The installer disables itself as soon as it finishes. Credentials are only ever written to <code>.env</code>, never to the database.
    </p>
</div>
</body>
</html>
