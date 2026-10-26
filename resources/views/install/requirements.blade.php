@extends('install.layout', ['step' => 1])

@section('title', 'Server requirements')

@section('content')
    <h1>Server requirements</h1>
    <p class="lead">
        This server has to meet a few requirements before the database and the application can be configured.
    </p>

    @unless ($enabled)
        <div class="alert alert-warn">
            <p class="alert-title">The web installer is disabled</p>
            <p>
                <code>YAC_INSTALLER_ENABLED=false</code> is set, so this installer will not configure the application.
                Configure the environment variables yourself and run <code>php artisan migrate --force</code>.
            </p>
        </div>
    @endunless

    @if ($problems !== [])
        <div class="alert alert-error">
            <p class="alert-title">Your existing configuration cannot be used yet</p>
            <ul>
                @foreach ($problems as $problem)
                    <li>{{ $problem }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <ul class="checks">
        @foreach ($checks as $check)
            @php
                $dot = 'dot';

                if ($check['ok']) {
                    $dot = 'dot dot-ok';
                } elseif ($check['required']) {
                    $dot = 'dot dot-fail';
                } else {
                    $dot = 'dot dot-warn';
                }
            @endphp
            <li class="check">
                <span class="{{ $dot }}"></span>
                <span class="check-body">
                    <span class="check-label">{{ $check['label'] }}</span>
                    <span class="check-detail">{{ $check['detail'] }}</span>
                </span>
                @if (! $check['required'])
                    <span class="tag tag-recommended">Recommended</span>
                @elseif (! $check['ok'])
                    <span class="tag">Required</span>
                @endif
            </li>
        @endforeach
    </ul>

    <div class="actions">
        @if ($passes)
            <a class="btn btn-primary" href="{{ route('install.database') }}">Continue to the database</a>
        @else
            <span class="btn btn-disabled">Fix the failing checks to continue</span>
            <a class="btn btn-secondary" href="{{ route('install.requirements') }}">Check again</a>
        @endif
    </div>

    <form method="POST" action="{{ route('install.skip') }}" class="skip">
        @csrf
        <p>
            Already configured <code>.env</code> or server environment variables and ran the migrations yourself?
            <button type="submit" class="link">Skip the installer</button>
            — it will check that the configuration works before stepping aside.
        </p>
    </form>
@endsection
