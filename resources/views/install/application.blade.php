@extends('install.layout', ['step' => 3])

@section('title', 'Application')

@section('content')
    <h1>Application</h1>
    <p class="lead">
        These values are written to <code>.env</code>. The administrator account is created in the database in this step.
    </p>

    @if ($summary)
        <div class="summary">
            <span>Database ready:</span>
            <strong>{{ $summary['label'] }}</strong>
        </div>
    @else
        <div class="alert alert-warn">
            <p>
                No database has been configured yet. <a href="{{ route('install.database') }}">Configure the database first</a>
                if this step fails.
            </p>
        </div>
    @endif

    @if ($errors->any())
        <div class="alert alert-error">
            <p class="alert-title">Please fix the following</p>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('install.application.store') }}">
        @csrf

        <h2>Site</h2>

        <div class="grid-2">
            <label class="field">
                <span class="label">Application name</span>
                <input type="text" name="app_name" value="{{ old('app_name', $defaults['app_name']) }}" maxlength="60">
            </label>
            <label class="field">
                <span class="label">Timezone</span>
                <select name="timezone">
                    @foreach ($timezones as $zone)
                        <option value="{{ $zone }}" @selected(old('timezone', $defaults['timezone']) === $zone)>{{ $zone }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        <label class="field">
            <span class="label">Application URL</span>
            <input type="url" name="app_url" value="{{ old('app_url', $defaults['app_url']) }}" spellcheck="false">
            <span class="help">The address people use to open the application, with or without a trailing slash.</span>
        </label>

        <label class="field">
            <span class="label">Environment</span>
            <select name="environment">
                <option value="local" @selected(old('environment', $defaults['environment']) === 'local')>local (debug messages shown)</option>
                <option value="production" @selected(old('environment', $defaults['environment']) === 'production')>production (errors hidden)</option>
            </select>
            <span class="help">Choose production for a live server, even while you test it online.</span>
        </label>

        <h2>First administrator</h2>

        <div class="grid-2">
            <label class="field">
                <span class="label">Full name</span>
                <input type="text" name="admin_name" value="{{ old('admin_name') }}" maxlength="120" autocomplete="name">
            </label>
            <label class="field">
                <span class="label">Email address</span>
                <input type="email" name="admin_email" value="{{ old('admin_email') }}" maxlength="190" autocomplete="email">
            </label>
        </div>

        <div class="grid-2">
            <label class="field">
                <span class="label">Password</span>
                <input type="password" name="admin_password" autocomplete="new-password">
                <span class="help">At least 10 characters, including a letter and a number.</span>
            </label>
            <label class="field">
                <span class="label">Confirm password</span>
                <input type="password" name="admin_password_confirmation" autocomplete="new-password">
            </label>
        </div>

        <h2>Termii (optional)</h2>

        @if ($defaults['termii_configured'])
            <div class="alert alert-info">
                <p>An API key is already configured. Leave the field empty to keep it.</p>
            </div>
        @endif

        <div class="grid-2">
            <label class="field">
                <span class="label">API key</span>
                <input type="password" name="termii_api_key" value="" autocomplete="off" spellcheck="false">
                <span class="help">Stored in <code>.env</code> only; it is never shown again in the application.</span>
            </label>
            <label class="field">
                <span class="label">Sender ID</span>
                <input type="text" name="termii_sender_id" value="{{ old('termii_sender_id', $defaults['sender_id']) }}" maxlength="11" autocomplete="off" spellcheck="false">
                <span class="help">3–11 letters, numbers or spaces. Must be approved on your Termii account.</span>
            </label>
        </div>

        <p class="help">
            You can add or change the Termii credentials later in <code>.env</code>. Campaigns can be prepared without them,
            but nothing can be sent until a key and a sender ID are configured.
        </p>

        <div class="actions">
            <button type="submit" class="btn btn-primary">Install and create the database tables</button>
            <a class="btn btn-secondary" href="{{ route('install.database') }}">Back</a>
        </div>
    </form>
@endsection
