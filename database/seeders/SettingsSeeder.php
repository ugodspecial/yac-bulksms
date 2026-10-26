<?php

namespace Database\Seeders;

use App\Services\Settings\SettingsService;
use Illuminate\Database\Seeder;

/**
 * Writes the default, non-secret application settings. Safe to run more than
 * once: existing values are only written when the key is still missing.
 */
class SettingsSeeder extends Seeder
{
    public function run(SettingsService $settings): void
    {
        $existing = $settings->all();
        $values = [];

        $values['app.name'] = $existing['app.name'] ?? config('app.name', 'YAC-BulkSMS');
        $values['app.timezone'] = $existing['app.timezone'] ?? config('app.timezone', 'Africa/Lagos');

        $senderId = trim((string) config('termii.sender_id', ''));
        $approved = $existing['messaging.approved_sender_ids'] ?? [];

        if ($senderId !== '' && is_array($approved) && $approved === []) {
            $approved = [$senderId];
        }

        $values['messaging.approved_sender_ids'] = $approved;
        $values['messaging.default_sender_id'] = $existing['messaging.default_sender_id'] ?? ($senderId !== '' ? $senderId : null);
        $values['messaging.default_channel'] = $existing['messaging.default_channel'] ?? 'generic';
        $values['messaging.batch_size'] = $existing['messaging.batch_size'] ?? 100;
        $values['messaging.max_message_length'] = $existing['messaging.max_message_length'] ?? 1000;
        $values['messaging.max_segments'] = $existing['messaging.max_segments'] ?? 8;
        $values['contacts.default_country'] = $existing['contacts.default_country'] ?? 'NG';
        $values['contacts.import_max_rows'] = $existing['contacts.import_max_rows'] ?? 20000;
        $values['contacts.import_max_size_kb'] = $existing['contacts.import_max_size_kb'] ?? 5120;
        $values['sending.paused'] = $existing['sending.paused'] ?? false;
        $values['provider.reconcile_enabled'] = $existing['provider.reconcile_enabled'] ?? true;
        $values['logs.retention_days'] = $existing['logs.retention_days'] ?? 90;

        $settings->putMany($values);
    }
}
