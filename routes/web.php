<?php

use App\Http\Controllers\CampaignExportController;
use App\Http\Controllers\ContactExportController;
use App\Http\Controllers\ContactImportReportController;
use App\Http\Controllers\DeliveryReportWebhookController;
use App\Http\Controllers\LogoutController;
use App\Livewire\AuditLogs\Index as AuditLogsIndex;
use App\Livewire\Auth\Login;
use App\Livewire\Campaigns\Index as CampaignsIndex;
use App\Livewire\Campaigns\Show as CampaignShow;
use App\Livewire\Campaigns\Wizard as CampaignWizard;
use App\Livewire\Contacts\Index as ContactsIndex;
use App\Livewire\Contacts\Show as ContactShow;
use App\Livewire\Dashboard;
use App\Livewire\Groups\Index as GroupsIndex;
use App\Livewire\Groups\Show as GroupShow;
use App\Livewire\Imports\Index as ImportsIndex;
use App\Livewire\Imports\Show as ImportShow;
use App\Livewire\Profile\Index as ProfileIndex;
use App\Livewire\Reports\Index as ReportsIndex;
use App\Livewire\Settings\Index as SettingsIndex;
use App\Livewire\Suppressions\Index as SuppressionsIndex;
use App\Livewire\Users\Index as UsersIndex;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function (): void {
    Route::get('login', Login::class)->name('login');
});

Route::post('logout', LogoutController::class)->middleware('auth')->name('logout');

Route::middleware(['auth', 'active', 'password.changed'])->group(function (): void {
    Route::get('dashboard', Dashboard::class)->name('dashboard');

    Route::get('profile', ProfileIndex::class)->name('profile');

    Route::get('contacts', ContactsIndex::class)->name('contacts.index');
    Route::get('contacts/export', ContactExportController::class)
        ->middleware('throttle:sensitive')
        ->name('contacts.export');
    Route::get('contacts/{contact}', ContactShow::class)->name('contacts.show');

    Route::get('groups', GroupsIndex::class)->name('groups.index');
    Route::get('groups/{group}', GroupShow::class)->name('groups.show');

    Route::get('campaigns', CampaignsIndex::class)->name('campaigns.index');
    Route::get('campaigns/create', CampaignWizard::class)->name('campaigns.create');
    Route::get('campaigns/{campaign}/edit', CampaignWizard::class)->name('campaigns.edit');
    Route::get('campaigns/{campaign}/export', CampaignExportController::class)
        ->middleware('throttle:sensitive')
        ->name('campaigns.export');
    Route::get('campaigns/{campaign}', CampaignShow::class)->name('campaigns.show');

    Route::get('reports', ReportsIndex::class)->name('reports.index');

    Route::get('imports', ImportsIndex::class)->name('imports.index');
    Route::get('imports/template', [ContactImportReportController::class, 'template'])->name('imports.template');
    Route::get('imports/{import}/errors', [ContactImportReportController::class, 'errors'])->name('imports.errors');
    Route::get('imports/{import}', ImportShow::class)->name('imports.show');

    Route::get('suppressions', SuppressionsIndex::class)
        ->middleware('can:manage-suppressions')
        ->name('suppressions.index');

    Route::get('settings', SettingsIndex::class)
        ->middleware('can:admin')
        ->name('settings');

    Route::get('users', UsersIndex::class)
        ->middleware('can:admin')
        ->name('users.index');

    Route::get('audit-logs', AuditLogsIndex::class)
        ->middleware('can:admin')
        ->name('audit.index');
});

// Called by Termii, not by a browser session. CSRF is disabled for this path
// in bootstrap/app.php; authenticity is enforced with the HMAC signature.
Route::post('webhooks/termii', DeliveryReportWebhookController::class)
    ->middleware('throttle:termii-webhook')
    ->name('webhooks.termii');
