<?php

namespace App\Services\Campaign;

/** Immutable snapshot of who will receive a campaign, and who was excluded. */
final readonly class RecipientPlan
{
    /**
     * @param  array<int, array{contact_id: int, name: string|null, phone: string, email: string|null, custom_fields: array<string, mixed>|null}>  $recipients
     * @param  array<string, int>  $exclusions
     */
    public function __construct(
        public array $recipients,
        public array $exclusions,
        public int $candidateCount,
    ) {}

    public static function empty(): self
    {
        return new self([], [], 0);
    }

    public function count(): int
    {
        return count($this->recipients);
    }

    public function excludedCount(): int
    {
        return array_sum($this->exclusions);
    }

    /**
     * Human-readable exclusion reasons for the review screen.
     *
     * @return array<int, array{reason: string, count: int}>
     */
    public function exclusionSummary(): array
    {
        $labels = [
            'unsubscribed' => 'Unsubscribed contacts',
            'suppressed' => 'Numbers on the suppression list',
            'duplicate' => 'Duplicate numbers',
            'invalid' => 'Invalid numbers',
        ];

        $summary = [];

        foreach ($this->exclusions as $reason => $count) {
            if ($count > 0) {
                $summary[] = ['reason' => $labels[$reason] ?? ucfirst($reason), 'count' => $count];
            }
        }

        return $summary;
    }

    public function exclusionsForStorage(): array
    {
        return array_filter($this->exclusions, fn (int $count) => $count > 0);
    }
}
