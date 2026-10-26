@extends('install.layout', ['step' => 4])

@section('title', 'Finished')

@section('content')
    <h1>{{ $skipped ? 'Installer skipped' : 'Installation complete' }}</h1>

    @if ($skipped)
        <p class="lead">
            The installer has stepped aside and the application now uses your existing configuration.
        </p>
    @else
        <p class="lead">
            The database was created, the tables were migrated with their default settings, and your administrator account is ready.
        </p>
    @endif

    <div class="summary">
        <span>Database:</span>
        <strong>{{ $database }}</strong>
        <span>·</span>
        <span>Queue:</span>
        <strong>{{ $queue }}</strong>
        @if ($installedAt)
            <span>·</span>
            <span>{{ \Illuminate\Support\Carbon::parse($installedAt)->toDayDateTimeString() }}</span>
        @endif
    </div>

    <div class="alert alert-ok">
        <p class="alert-title">The installer is now disabled</p>
        <p>
            Re-running it would overwrite <code>.env</code> and reset the administrator password, so it is closed.
            To run it again deliberately, delete <code>storage/app/installation.json</code> and reload this page.
        </p>
    </div>

    <h2>Before you send anything</h2>
    <ul class="plain">
        <li>
            Start a queue worker so campaigns and imports are processed:
            <code>php artisan queue:work --tries=3 --timeout=300</code>
        </li>
        <li>
            Add a cron entry so scheduled campaigns and the delivery-report reconciliation run:
            <code>* * * * * cd {{ base_path() }} && php artisan schedule:run</code>
        </li>
        <li>
            Register the delivery-report webhook in the Termii console pointing at
            <code>{{ url('/webhooks/termii') }}</code>,
            then set <code>TERMII_WEBHOOK_ENABLED=true</code> and <code>TERMII_WEBHOOK_SECRET</code> in <code>.env</code>.
            @if (! $webhookEnabled)
                It is currently disabled, so messages will stay marked as accepted.
            @endif
        </li>
    </ul>

    <div class="actions">
        <a class="btn btn-primary" href="{{ $loginUrl }}">Go to the sign-in page</a>
    </div>

    <p class="skip">
        The full deployment, queue, supervisor and troubleshooting notes are in <code>README.md</code>.
    </p>
@endsection
