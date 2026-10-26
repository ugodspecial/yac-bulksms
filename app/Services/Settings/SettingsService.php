<?php

namespace App\Services\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Typed access to the non-secret application settings stored in the
 * "settings" table. Credentials never live here - they stay in the
 * environment (see config/termii.php).
 */
class SettingsService
{
    public const CACHE_KEY = 'yac.settings.all';

    /**
     * Default values used when a setting has never been saved.
     *
     * @var array<string, mixed>
     */
    public const DEFAULTS = [
        'app.name' => 'YAC-BulkSMS',
        'app.logo_path' => null,
        'app.timezone' => null,
        'messaging.default_sender_id' => null,
        'messaging.approved_sender_ids' => [],
        'messaging.default_channel' => 'generic',
        'messaging.batch_size' => 100,
        'messaging.max_message_length' => 1000,
        'messaging.max_segments' => 8,
        'messaging.custom_fields' => [],
        'contacts.default_country' => 'NG',
        'contacts.import_max_rows' => 20000,
        'contacts.import_max_size_kb' => 5120,
        'sending.paused' => false,
        'sending.opt_out_keywords' => ['STOP', 'UNSUBSCRIBE', 'OPT OUT', 'CANCEL', 'OPT-OUT'],
        'provider.reconcile_enabled' => true,
        'logs.retention_days' => 90,
    ];

    /** @var array<string, mixed>|null */
    private ?array $loaded = null;

    /**
     * All settings merged over the defaults.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        $stored = Cache::rememberForever(self::CACHE_KEY, function (): array {
            try {
                return Setting::query()
                    ->pluck('value', 'key')
                    ->map(fn ($value) => $this->decode($value))
                    ->all();
            } catch (\Throwable $e) {
                // The table may not exist yet (fresh install, migrations).
                Log::debug('Settings unavailable: '.$e->getMessage());

                return [];
            }
        });

        return $this->loaded = array_merge(self::DEFAULTS, $stored);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->all()[$key] ?? null;

        return $value ?? $default ?? (self::DEFAULTS[$key] ?? null);
    }

    public function put(string $key, mixed $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $this->encode($value)]);
        $this->forget();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function putMany(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $this->encode($value)]);
        }

        $this->forget();
    }

    public function forget(): void
    {
        $this->loaded = null;
        Cache::forget(self::CACHE_KEY);
    }

    // ------------------------------------------------------------------
    // Typed helpers
    // ------------------------------------------------------------------

    public function appName(): string
    {
        $name = trim((string) $this->get('app.name'));

        return $name !== '' ? $name : (string) config('app.name', 'YAC-BulkSMS');
    }

    public function logoPath(): ?string
    {
        $path = $this->get('app.logo_path');

        return filled($path) ? (string) $path : null;
    }

    public function timezone(): string
    {
        $timezone = (string) ($this->get('app.timezone') ?? '');

        if ($timezone !== '' && in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            return $timezone;
        }

        return (string) config('app.timezone', 'UTC');
    }

    public function defaultSenderId(): ?string
    {
        $value = trim((string) ($this->get('messaging.default_sender_id') ?? ''));

        if ($value !== '') {
            return $value;
        }

        $fallback = trim((string) config('termii.sender_id', ''));

        return $fallback !== '' ? $fallback : null;
    }

    public function defaultChannel(): string
    {
        $channel = (string) $this->get('messaging.default_channel', 'generic');

        return in_array($channel, ['generic', 'dnd'], true) ? $channel : 'generic';
    }

    /**
     * Sender IDs an operator may pick from in the campaign builder.
     *
     * @return array<int, string>
     */
    public function approvedSenderIds(): array
    {
        $ids = $this->get('messaging.approved_sender_ids', []);

        if (is_string($ids)) {
            $ids = preg_split('/[,\s]+/', $ids) ?: [];
        }

        $ids = array_values(array_filter(array_map(
            fn ($id) => is_scalar($id) ? trim((string) $id) : '',
            is_array($ids) ? $ids : []
        )));

        $default = $this->defaultSenderId();

        if ($default !== null && ! in_array($default, $ids, true)) {
            array_unshift($ids, $default);
        }

        return array_values(array_unique($ids));
    }

    public function batchSize(): int
    {
        $size = (int) $this->get('messaging.batch_size', 100);

        // Termii accepts at most 100 recipients per request.
        return max(1, min(100, $size));
    }

    public function maxMessageLength(): int
    {
        return max(1, (int) $this->get('messaging.max_message_length', 1000));
    }

    public function maxSegments(): int
    {
        return max(1, (int) $this->get('messaging.max_segments', 8));
    }

    /**
     * @return array<int, string>
     */
    public function customFields(): array
    {
        $fields = $this->get('messaging.custom_fields', []);

        if (is_string($fields)) {
            $fields = preg_split('/[,\s]+/', $fields) ?: [];
        }

        return array_values(array_filter(array_map(
            fn ($field) => is_scalar($field) ? trim((string) $field) : '',
            is_array($fields) ? $fields : []
        )));
    }

    public function defaultCountry(): string
    {
        $country = strtoupper((string) $this->get('contacts.default_country', 'NG'));

        return preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : 'NG';
    }

    public function importMaxRows(): int
    {
        return max(1, (int) $this->get('contacts.import_max_rows', 20000));
    }

    public function importMaxSizeKb(): int
    {
        return max(64, (int) $this->get('contacts.import_max_size_kb', 5120));
    }

    public function sendingPaused(): bool
    {
        return filter_var($this->get('sending.paused', false), FILTER_VALIDATE_BOOL);
    }

    /**
     * @return array<int, string>
     */
    public function optOutKeywords(): array
    {
        $keywords = $this->get('sending.opt_out_keywords', []);

        if (is_string($keywords)) {
            $keywords = explode(',', $keywords);
        }

        $keywords = array_values(array_filter(array_map(
            fn ($keyword) => is_scalar($keyword) ? strtoupper(trim((string) $keyword)) : '',
            is_array($keywords) ? $keywords : []
        )));

        return $keywords !== [] ? $keywords : (array) self::DEFAULTS['sending.opt_out_keywords'];
    }

    public function reconcileEnabled(): bool
    {
        return filter_var($this->get('provider.reconcile_enabled', true), FILTER_VALIDATE_BOOL);
    }

    public function retentionDays(): int
    {
        return max(1, (int) $this->get('logs.retention_days', 90));
    }

    private function encode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function decode(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $decoded = json_decode((string) $value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }
}
