<?php

namespace App\Livewire\Settings;

use App\Models\ProviderStatus;
use App\Services\Audit\AuditLogger;
use App\Services\Provider\ProviderHealthService;
use App\Services\Settings\SettingsService;
use App\Services\Termii\TermiiGatewayInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
#[Title('Settings')]
class Index extends Component
{
    use WithFileUploads;

    public string $appName = '';

    public string $timezone = '';

    public $logo = null;

    public bool $removeLogo = false;

    public string $defaultSenderId = '';

    public string $approvedSenderIds = '';

    public string $defaultChannel = 'generic';

    public string $batchSize = '100';

    public string $maxMessageLength = '1000';

    public string $maxSegments = '8';

    public string $customFields = '';

    public bool $sendingPaused = false;

    public string $optOutKeywords = '';

    public string $defaultCountry = 'NG';

    public string $importMaxRows = '20000';

    public string $importMaxSizeKb = '5120';

    public bool $reconcileEnabled = true;

    public string $retentionDays = '90';

    /** @var array{type: string, message: string}|null */
    public ?array $providerResult = null;

    public function mount(SettingsService $settings): void
    {
        Gate::authorize('admin');

        $this->appName = $settings->appName();
        $this->timezone = $settings->timezone();
        $this->defaultSenderId = (string) ($settings->get('messaging.default_sender_id') ?? '');
        $this->approvedSenderIds = implode(', ', $settings->approvedSenderIds());
        $this->defaultChannel = $settings->defaultChannel();
        $this->batchSize = (string) $settings->batchSize();
        $this->maxMessageLength = (string) $settings->maxMessageLength();
        $this->maxSegments = (string) $settings->maxSegments();
        $this->customFields = implode(', ', $settings->customFields());
        $this->sendingPaused = $settings->sendingPaused();
        $this->optOutKeywords = implode(', ', $settings->optOutKeywords());
        $this->defaultCountry = $settings->defaultCountry();
        $this->importMaxRows = (string) $settings->importMaxRows();
        $this->importMaxSizeKb = (string) $settings->importMaxSizeKb();
        $this->reconcileEnabled = $settings->reconcileEnabled();
        $this->retentionDays = (string) $settings->retentionDays();
    }

    public function save(SettingsService $settings, AuditLogger $audit): void
    {
        Gate::authorize('admin');

        $this->validate([
            'appName' => ['required', 'string', 'max:60'],
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'defaultSenderId' => ['nullable', 'string', 'min:3', 'max:11', 'regex:/^[A-Za-z0-9 ]+$/'],
            'approvedSenderIds' => ['nullable', 'string', 'max:400'],
            'defaultChannel' => ['required', Rule::in(['generic', 'dnd'])],
            'batchSize' => ['required', 'integer', 'min:1', 'max:100'],
            'maxMessageLength' => ['required', 'integer', 'min:100', 'max:5000'],
            'maxSegments' => ['required', 'integer', 'min:1', 'max:20'],
            'customFields' => ['nullable', 'string', 'max:400'],
            'optOutKeywords' => ['required', 'string', 'max:300'],
            'defaultCountry' => ['required', 'string', 'size:2'],
            'importMaxRows' => ['required', 'integer', 'min:1', 'max:100000'],
            'importMaxSizeKb' => ['required', 'integer', 'min:64', 'max:20480'],
            'retentionDays' => ['required', 'integer', 'min:7', 'max:3650'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ], [], [
            'defaultSenderId' => 'default sender ID',
            'approvedSenderIds' => 'approved sender IDs',
            'maxMessageLength' => 'maximum message length',
            'maxSegments' => 'maximum segments',
            'importMaxRows' => 'import row limit',
            'importMaxSizeKb' => 'import file size limit',
        ]);

        $approved = $this->parseList($this->approvedSenderIds);
        $customFields = $this->parseList($this->customFields);

        foreach ($approved as $sender) {
            if (preg_match('/^[A-Za-z0-9 ]{3,11}$/', $sender) !== 1) {
                $this->addError('approvedSenderIds', sprintf('"%s" is not a valid sender ID. Use 3-11 letters, numbers or spaces.', $sender));

                return;
            }
        }

        $values = [
            'app.name' => $this->appName,
            'app.timezone' => $this->timezone,
            'messaging.default_sender_id' => $this->defaultSenderId !== '' ? $this->defaultSenderId : null,
            'messaging.approved_sender_ids' => $approved,
            'messaging.default_channel' => $this->defaultChannel,
            'messaging.batch_size' => (int) $this->batchSize,
            'messaging.max_message_length' => (int) $this->maxMessageLength,
            'messaging.max_segments' => (int) $this->maxSegments,
            'messaging.custom_fields' => $customFields,
            'sending.paused' => $this->sendingPaused,
            'sending.opt_out_keywords' => array_map('strtoupper', $this->parseList($this->optOutKeywords)),
            'contacts.default_country' => strtoupper($this->defaultCountry),
            'contacts.import_max_rows' => (int) $this->importMaxRows,
            'contacts.import_max_size_kb' => (int) $this->importMaxSizeKb,
            'provider.reconcile_enabled' => $this->reconcileEnabled,
            'logs.retention_days' => (int) $this->retentionDays,
        ];

        if ($this->logo !== null) {
            $path = $this->logo->store('branding', 'public');

            if ($path !== false) {
                $this->deleteStoredLogo($settings->logoPath());
                $values['app.logo_path'] = $path;
            }
        } elseif ($this->removeLogo) {
            $this->deleteStoredLogo($settings->logoPath());
            $values['app.logo_path'] = null;
        }

        $settings->putMany($values);

        $audit->log('settings.updated', null, 'Updated the application settings.', [
            'keys' => array_keys($values),
            'sending_paused' => $this->sendingPaused,
        ]);

        $this->logo = null;
        $this->removeLogo = false;

        $this->dispatch('notify', message: 'Settings saved.', type: 'success');
    }

    public function checkBalance(ProviderHealthService $health): void
    {
        $this->runProviderAction($health, 'balance');
    }

    public function testConnection(ProviderHealthService $health): void
    {
        $this->runProviderAction($health, 'test');
    }

    public function validateSender(ProviderHealthService $health, SettingsService $settings): void
    {
        if (! $this->guardProviderAction()) {
            return;
        }

        $result = $health->validateSenderId($settings->defaultSenderId(), auth()->user());

        $this->providerResult = [
            'type' => $result['ok'] ? 'success' : 'warning',
            'message' => $result['message'],
        ];
    }

    public function render(SettingsService $settings, TermiiGatewayInterface $gateway): View
    {
        Gate::authorize('admin');

        $queue = [
            'connection' => config('queue.default'),
            'pending' => $this->safeCount('jobs'),
            'failed' => $this->safeCount('failed_jobs'),
            'oldest' => DB::table('jobs')->min('created_at'),
        ];

        $providerStatus = ProviderStatus::termii();
        $timezone = $settings->timezone();

        return view('livewire.settings.index', [
            'providerStatus' => $providerStatus,
            'maskedKey' => $gateway->maskedApiKey(),
            'baseUrlHost' => parse_url((string) config('termii.base_url'), PHP_URL_HOST) ?: config('termii.base_url'),
            'webhookEnabled' => (bool) config('termii.webhook.enabled'),
            'webhookSecretConfigured' => filled(config('termii.webhook.secret')),
            'logoPath' => $settings->logoPath(),
            'timezones' => DateTimeZone::listIdentifiers(),
            'queue' => $queue,
            'lastBalanceLabel' => $providerStatus->balance !== null
                ? number_format((float) $providerStatus->balance, 2).' '.($providerStatus->currency ?? 'NGN')
                : 'Not checked',
            'checkedAtLabel' => $providerStatus->last_balance_at?->copy()->timezone($timezone)->toDayDateTimeString() ?? 'Never',
            'senderIdLabel' => $providerStatus->sender_id ?: ($settings->defaultSenderId() ?: 'Not set'),
            'webhookEnabledLabel' => (bool) config('termii.webhook.enabled') ? 'Enabled' : 'Disabled',
            'webhookSecretLabel' => filled(config('termii.webhook.secret')) ? 'Configured' : 'Not configured',
            'oldestPendingLabel' => $queue['oldest']
                ? \Illuminate\Support\Carbon::createFromTimestamp((int) $queue['oldest'])->diffForHumans()
                : '—',
        ]);
    }

    private function runProviderAction(ProviderHealthService $health, string $action): void
    {
        Gate::authorize('admin');

        if (! $this->guardProviderAction()) {
            return;
        }

        if ($action === 'balance') {
            $result = $health->checkBalance(auth()->user());

            $this->providerResult = [
                'type' => $result->ok ? 'success' : 'error',
                'message' => $result->ok
                    ? sprintf('Connected. Balance: %s %s.', number_format((float) $result->balance, 2), $result->currency ?? 'NGN')
                    : $result->safeMessage(),
            ];

            return;
        }

        $result = $health->testConnection(auth()->user());

        $this->providerResult = [
            'type' => $result['ok'] ? 'success' : 'warning',
            'message' => sprintf('%s %s', $result['balance']->safeMessage(), $result['sender']['message']),
        ];
    }

    private function guardProviderAction(): bool
    {
        $key = 'provider-test:'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->providerResult = [
                'type' => 'error',
                'message' => 'Too many provider checks. Please wait a minute before trying again.',
            ];

            return false;
        }

        RateLimiter::hit($key, 60);

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function parseList(string $value): array
    {
        return array_values(array_filter(array_map(
            'trim',
            preg_split('/[,\n]+/', $value) ?: []
        )));
    }

    private function deleteStoredLogo(?string $path): void
    {
        if ($path !== null && $path !== '') {
            Storage::disk('public')->delete($path);
        }
    }

    private function safeCount(string $table): int
    {
        try {
            return (int) DB::table($table)->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
