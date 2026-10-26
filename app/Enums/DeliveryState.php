<?php

namespace App\Enums;

/**
 * Normalised delivery states mapped from the provider's status strings
 * (Termii DLR webhooks and message history reports).
 */
enum DeliveryState: string
{
    case Delivered = 'delivered';
    case Sent = 'sent';
    case Failed = 'failed';
    case Pending = 'pending';
    case Unknown = 'unknown';

    /**
     * Map a Termii status string onto a delivery state.
     *
     * Termii documents the following DLR statuses: "Delivered",
     * "DND Active on Phone Number", "Message Sent", "Message Failed",
     * "Rejected" and "Expired". Message history reports use the same values.
     */
    public static function fromProviderStatus(?string $status): self
    {
        $normalized = strtolower(trim((string) $status));

        if ($normalized === '') {
            return self::Unknown;
        }

        return match (true) {
            str_contains($normalized, 'deliver') => self::Delivered,
            str_contains($normalized, 'fail'),
            str_contains($normalized, 'reject'),
            str_contains($normalized, 'expired'),
            str_contains($normalized, 'dnd') => self::Failed,
            str_contains($normalized, 'sent'),
            str_contains($normalized, 'submit'),
            str_contains($normalized, 'accept'),
            str_contains($normalized, 'queue'),
            str_contains($normalized, 'process') => self::Sent,
            str_contains($normalized, 'pending') => self::Pending,
            default => self::Unknown,
        };
    }

    public function recipientStatus(): RecipientStatus
    {
        return match ($this) {
            self::Delivered => RecipientStatus::Delivered,
            self::Failed => RecipientStatus::Failed,
            self::Sent, self::Pending => RecipientStatus::Accepted,
            self::Unknown => RecipientStatus::Unknown,
        };
    }

    /** Terminal states will not change again. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Delivered, self::Failed], true);
    }
}
