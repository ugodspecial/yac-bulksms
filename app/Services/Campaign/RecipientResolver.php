<?php

namespace App\Services\Campaign;

use App\Enums\SubscriptionStatus;
use App\Models\Contact;
use App\Models\SuppressionEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Resolves the audience of a campaign into a de-duplicated list of eligible
 * recipients, and reports exactly why anyone was excluded.
 */
class RecipientResolver
{
    /**
     * @param  array<int, int|string>  $contactIds
     * @param  array<int, int|string>  $groupIds
     */
    public function resolve(array $contactIds, array $groupIds): RecipientPlan
    {
        $contactIds = $this->intList($contactIds);
        $groupIds = $this->intList($groupIds);

        if ($contactIds === [] && $groupIds === []) {
            return RecipientPlan::empty();
        }

        $recipients = [];
        $exclusions = ['unsubscribed' => 0, 'suppressed' => 0, 'duplicate' => 0, 'invalid' => 0];
        $seen = [];
        $candidates = 0;
        $this->suppressedPhones = [];

        $query = Contact::query()
            ->select(['id', 'name', 'phone', 'email', 'custom_fields', 'subscription_status'])
            ->where(function (Builder $query) use ($contactIds, $groupIds): void {
                if ($contactIds !== []) {
                    $query->whereIn('id', $contactIds);
                }

                if ($groupIds !== []) {
                    $query->{$contactIds === [] ? 'whereHas' : 'orWhereHas'}(
                        'groups',
                        fn (Builder $group) => $group->whereIn('contact_groups.id', $groupIds)
                    );
                }
            })
            ->orderBy('id');

        $query->chunk(500, function (Collection $contacts) use (&$recipients, &$exclusions, &$seen, &$candidates): void {
            $candidates += $contacts->count();
            $this->loadSuppressed($contacts->pluck('phone')->all());

            foreach ($contacts as $contact) {
                $phone = trim((string) $contact->phone);

                if ($phone === '') {
                    $exclusions['invalid']++;

                    continue;
                }

                if (isset($seen[$phone])) {
                    $exclusions['duplicate']++;

                    continue;
                }

                $seen[$phone] = true;

                if (isset($this->suppressedPhones[$phone])) {
                    $exclusions['suppressed']++;

                    continue;
                }

                if ($contact->subscription_status !== SubscriptionStatus::Subscribed) {
                    $exclusions['unsubscribed']++;

                    continue;
                }

                $recipients[] = [
                    'contact_id' => $contact->id,
                    'name' => $contact->name,
                    'phone' => $phone,
                    'email' => $contact->email,
                    'custom_fields' => $contact->custom_fields,
                ];
            }
        });

        return new RecipientPlan($recipients, $exclusions, $candidates);
    }

    /**
     * @param  array{contact_ids?: array<int, int|string>, group_ids?: array<int, int|string>}|null  $audience
     */
    public function resolveForAudience(?array $audience): RecipientPlan
    {
        $audience ??= [];

        return $this->resolve($audience['contact_ids'] ?? [], $audience['group_ids'] ?? []);
    }

    /** @var array<string, true> */
    private array $suppressedPhones = [];

    /**
     * @param  array<int, string|null>  $phones
     */
    private function loadSuppressed(array $phones): void
    {
        $phones = array_values(array_filter($phones));

        if ($phones === []) {
            return;
        }

        foreach (SuppressionEntry::query()->whereIn('phone', $phones)->pluck('phone') as $phone) {
            $this->suppressedPhones[(string) $phone] = true;
        }
    }

    /**
     * @param  array<int, int|string>  $values
     * @return array<int, int>
     */
    private function intList(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($value) => is_numeric($value) ? (int) $value : 0,
            $values
        ))));
    }
}
