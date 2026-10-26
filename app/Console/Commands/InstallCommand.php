<?php

namespace App\Console\Commands;

use App\Services\Install\InstallationManager;
use App\Services\Install\InstallationRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Optional terminal equivalent of the browser installer, for people who prefer
 * the command line. The browser installer remains the default path.
 */
class InstallCommand extends Command
{
    protected $signature = 'app:install
        {--admin-name= : Name of the first administrator}
        {--admin-email= : Email address of the first administrator}
        {--admin-password= : Password for the first administrator (a random one is generated when omitted)}
        {--force : Run even when the application already reports itself as installed}';

    protected $description = 'Create the database tables, default settings and the first administrator';

    public function handle(InstallationManager $installation, InstallationRunner $runner): int
    {
        if ($installation->isInstalled() && ! $this->option('force')) {
            $this->components->warn('The application is already installed. Use --force to re-run the installer.');

            return self::FAILURE;
        }

        $verification = $runner->verifyExistingEnvironment();

        foreach ($verification['problems'] as $problem) {
            $this->components->warn($problem);
        }

        $name = (string) ($this->option('admin-name') ?: 'YAC Administrator');
        $email = (string) ($this->option('admin-email') ?: $this->ask('Administrator email address'));
        $password = (string) ($this->option('admin-password') ?: '');

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->components->error('A valid administrator email address is required.');

            return self::FAILURE;
        }

        if ($password === '') {
            $password = Str::password(20);
            $this->components->info('Generated administrator password: '.$password);
            $this->components->warn('Store it now: it is not shown again.');
        }

        $result = $runner->complete([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);

        if (! $result['ok']) {
            $this->components->error($result['message']);

            return self::FAILURE;
        }

        $this->components->info($result['message']);
        $this->components->info(sprintf('Administrator ready: %s', $result['administrator']));
        $this->components->info('Open the application and sign in. Start a queue worker and the scheduler as described in the README.');

        return self::SUCCESS;
    }
}
