<?php

namespace App\Services\Contacts;

use App\Enums\SubscriptionStatus;
use App\Models\Contact;
use App\Models\SuppressionEntry;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Phone\InvalidPhoneNumberException;
use App\Services\Phone\PhoneNumberService;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContactService
{
    public function __construct(
        private readonly PhoneNumberService $phones,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name?: string|null, phone: string, email?: string|null, custom_fields?: array<string, mixed>|null}  $data
     *
     * @throws ValidationException
     */
    public function create(array $data, ?User $actor): Contact
    {
        $phone = $this->normalizeOrFail($data['phone']);

        if (Contact::query()->where('phone', $phone)->exists()) {
            throw ValidationException::withMessages(['phone' => 'A contact with this phone number already exists.']);
        }

        $suppressed = SuppressionEntry::query()->where('phone', $phone)->first();

        $contact = DB::transaction(function () use ($data, $phone, $actor, $suppressed): Contact {
            return Contact::create([
                'name' => $this->cleanName($data['name'] ?? null),
                'phone' => $phone,
                'email' => $this->cleanEmail($data['email'] ?? null),
                'custom_fields' => $this->cleanCustomFields($data['custom_fields'] ?? null),
                'subscription_status' => $suppressed ? SubscriptionStatus::Unsubscribed : SubscriptionStatus::Subscribed,
                'subscribed_at' => $suppressed ? null : now(),
                'unsubscribed_at' => $suppressed ? now() : null,
                'unsubscribe_reason' => $suppressed?->reason ?? ($suppressed ? 'Number is on the suppression list.' : null),
                'consent_source' => $suppressed ? 'suppression_list' : 'manual',
                'source' => 'manual',
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ]);
        });

        $this->audit->log('contact.created', $contact, sprintf('Created contact %s.', $contact->displayName()), [
            'phone' => $contact->phone,
            'suppressed' => (bool) $suppressed,
        ], $actor);

        return $contact;
    }

    /**
     * @param  array{name?: string|null, phone: string, email?: string|null, custom_fields?: array<string, mixed>|null}  $data
     *
     * @throws ValidationException
     */
    public function update(Contact $contact, array $data, ?User $actor): Contact
    {
        $phone = $this->normalizeOrFail($data['phone']);

        $exists = Contact::query()
            ->where('phone', $phone)
            ->whereKeyNot($contact->getKey())
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['phone' => 'Another contact already uses this phone number.']);
        }

        $contact->fill([
            'name' => $this->cleanName($data['name'] ?? null),
            'phone' => $phone,
            'email' => $this->cleanEmail($data['email'] ?? null),
            'custom_fields' => $this->cleanCustomFields($data['custom_fields'] ?? null),
            'updated_by' => $actor?->id,
        ])->save();

        $this->audit->log('contact.updated', $contact, sprintf('Updated contact %s.', $contact->displayName()), [
            'phone' => $contact->phone,
        ], $actor);

        return $contact;
    }

    public function delete(Contact $contact, ?User $actor): void
    {
        $label = $contact->displayName();
        $phone = $contact->phone;

        $contact->delete();

        $this->audit->log('contact.deleted', null, sprintf('Deleted contact %s.', $label), ['phone' => $phone], $actor);
    }

    public function unsubscribe(Contact $contact, ?User $actor, string $reason, string $source = 'manual'): void
    {
        DB::transaction(function () use ($contact, $actor, $reason, $source): void {
            $contact->forceFill([
                'subscription_status' => SubscriptionStatus::Unsubscribed,
                'unsubscribed_at' => now(),
                'unsubscribe_reason' => mb_substr($reason, 0, 191),
                'updated_by' => $actor?->id,
            ])->save();

            SuppressionEntry::query()->updateOrCreate(
                ['phone' => $contact->phone],
                [
                    'reason' => mb_substr($reason, 0, 191),
                    'source' => $source,
                    'created_by' => $actor?->id,
                ],
            );
        });

        $this->audit->log('contact.unsubscribed', $contact, sprintf('Unsubscribed %s.', $contact->displayName()), [
            'reason' => $reason,
            'source' => $source,
        ], $actor);
    }

    /**
     * Re-subscribing is an explicit, audited administrative action. It also
     * removes the suppression entry for the number.
     */
    public function resubscribe(Contact $contact, ?User $actor, string $reason = 'Re-subscribed by administrator'): void
    {
        DB::transaction(function () use ($contact, $actor, $reason): void {
            SuppressionEntry::query()->where('phone', $contact->phone)->delete();

            $contact->forceFill([
                'subscription_status' => SubscriptionStatus::Subscribed,
                'subscribed_at' => now(),
                'unsubscribed_at' => null,
                'unsubscribe_reason' => null,
                'consent_source' => 'manual_resubscribe',
                'updated_by' => $actor?->id,
            ])->save();
        });

        $this->audit->log('contact.resubscribed', $contact, sprintf('Re-subscribed %s.', $contact->displayName()), [
            'reason' => $reason,
        ], $actor);
    }

    public function addSuppression(string $phone, ?User $actor, string $reason, string $source = 'manual'): SuppressionEntry
    {
        $normalized = $this->normalizeOrFail($phone);

        $entry = SuppressionEntry::query()->updateOrCreate(
            ['phone' => $normalized],
            ['reason' => mb_substr($reason, 0, 191), 'source' => $source, 'created_by' => $actor?->id],
        );

        Contact::query()->where('phone', $normalized)->update([
            'subscription_status' => SubscriptionStatus::Unsubscribed->value,
            'unsubscribed_at' => now(),
            'unsubscribe_reason' => mb_substr($reason, 0, 191),
            'updated_by' => $actor?->id,
        ]);

        $this->audit->log('suppression.created', $entry, sprintf('Added %s to the suppression list.', $normalized), [
            'reason' => $reason,
            'source' => $source,
        ], $actor);

        return $entry;
    }

    public function removeSuppression(SuppressionEntry $entry, ?User $actor): void
    {
        $phone = $entry->phone;
        $entry->delete();

        $this->audit->log('suppression.removed', null, sprintf('Removed %s from the suppression list.', $phone), [
            'phone' => $phone,
        ], $actor);
    }

    private function normalizeOrFail(string $raw): string
    {
        try {
            return $this->phones->normalize($raw, $this->settings->defaultCountry());
        } catch (InvalidPhoneNumberException $e) {
            throw ValidationException::withMessages(['phone' => $e->getMessage()]);
        }
    }

    private function cleanName(?string $name): ?string
    {
        $name = trim((string) $name);

        return $name === '' ? null : mb_substr($name, 0, 120);
    }

    private function cleanEmail(?string $email): ?string
    {
        $email = trim((string) $email);

        return $email === '' ? null : mb_substr($email, 0, 190);
    }

    /**
     * @param  array<string, mixed>|null  $fields
     * @return array<string, string>|null
     */
    private function cleanCustomFields(?array $fields): ?array
    {
        if ($fields === null || $fields === []) {
            return null;
        }

        $clean = [];

        foreach ($fields as $key => $value) {
            $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $key)) ?? '';

            if ($key === '' || ! is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);

            if ($value === '') {
                continue;
            }

            $clean[mb_substr($key, 0, 40)] = mb_substr($value, 0, 255);
        }

        return $clean === [] ? null : array_slice($clean, 0, 20, true);
    }
}
