@extends('install.layout', ['step' => 2])

@section('title', 'Database')

@section('content')
    <h1>Database</h1>
    <p class="lead">
        The installer creates the database when it does not exist yet, then writes the connection details to
        <code>.env</code>. Nothing is written to the database until the last step.
    </p>

    @if ($summary)
        <div class="summary">
            <span>Currently configured:</span>
            <strong>{{ $summary['label'] }}</strong>
        </div>
    @endif

    @if ($errors->any())
        <div class="alert alert-error">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('install.database.store') }}">
        @csrf

        <label class="field">
            <span class="label">Database engine</span>
            <select name="db_driver" id="db_driver">
                <option value="mysql" @selected(old('db_driver', $driver) === 'mysql')>MySQL / MariaDB</option>
                <option value="sqlite" @selected(old('db_driver', $driver) === 'sqlite')>SQLite (single file)</option>
            </select>
            <span class="help">MySQL is recommended for a live server. SQLite needs no database server at all.</span>
        </label>

        <div id="mysql-fields">
            <div class="grid-2">
                <label class="field">
                    <span class="label">Host</span>
                    <input type="text" name="db_host" value="{{ old('db_host', $defaults['host']) }}" autocomplete="off" spellcheck="false">
                </label>
                <label class="field">
                    <span class="label">Port</span>
                    <input type="number" name="db_port" value="{{ old('db_port', $defaults['port']) }}" min="1" max="65535">
                </label>
            </div>

            <label class="field">
                <span class="label">Database name</span>
                <input type="text" name="db_database" value="{{ old('db_database', $defaults['database']) }}" autocomplete="off" spellcheck="false">
                <span class="help">Letters, numbers and underscores. It is created automatically if it does not exist.</span>
            </label>

            <div class="grid-2">
                <label class="field">
                    <span class="label">Username</span>
                    <input type="text" name="db_username" value="{{ old('db_username', $defaults['username']) }}" autocomplete="off" spellcheck="false">
                </label>
                <label class="field">
                    <span class="label">Password</span>
                    <input type="password" name="db_password" value="" autocomplete="new-password">
                </label>
            </div>

            <p class="help">
                The database user needs permission to create the database. If it does not have it, create the database
                manually first (for example with phpMyAdmin) and enter its name here.
            </p>
        </div>

        <div id="sqlite-fields">
            <label class="field">
                <span class="label">SQLite database file</span>
                <input type="text" name="db_sqlite_path" value="{{ old('db_sqlite_path', $sqlitePath) }}" autocomplete="off" spellcheck="false">
                <span class="help">The file is created when it does not exist. The web server user must be able to write to its directory.</span>
            </label>
        </div>

        <div class="actions">
            <button type="submit" class="btn btn-primary">Create database and continue</button>
            <a class="btn btn-secondary" href="{{ route('install.requirements') }}">Back</a>
        </div>
    </form>

    <script>
        (function () {
            var driver = document.getElementById('db_driver');
            var mysql = document.getElementById('mysql-fields');
            var sqlite = document.getElementById('sqlite-fields');

            if (!driver || !mysql || !sqlite) {
                return;
            }

            function sync() {
                var isMysql = driver.value === 'mysql';
                mysql.hidden = !isMysql;
                sqlite.hidden = isMysql;
            }

            driver.addEventListener('change', sync);
            sync();
        })();
    </script>
@endsection
