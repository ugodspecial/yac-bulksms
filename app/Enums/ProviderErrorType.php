<?php

namespace App\Enums;

/**
 * Safe, classified provider errors. The enum decides whether an operation may
 * be retried automatically and whether the outcome is ambiguous (which must
 * never be auto-retried, to avoid duplicate SMS).
 */
enum ProviderErrorType: string
{
    case NotConfigured = 'not_configured';
    case InvalidCredentials = 'invalid_credentials';
    case InsufficientBalance = 'insufficient_balance';
    case InvalidSenderId = 'invalid_sender_id';
    case InvalidRecipient = 'invalid_recipient';
    case InvalidPayload = 'invalid_payload';
    case RateLimited = 'rate_limited';
    case ServerError = 'server_error';
    case NetworkError = 'network_error';
    case AmbiguousTimeout = 'ambiguous_timeout';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::NotConfigured => 'Termii is not configured',
            self::InvalidCredentials => 'Invalid Termii credentials',
            self::InsufficientBalance => 'Insufficient Termii balance',
            self::InvalidSenderId => 'Sender ID rejected by Termii',
            self::InvalidRecipient => 'Invalid recipient number',
            self::InvalidPayload => 'Termii rejected the request',
            self::RateLimited => 'Termii rate limit reached',
            self::ServerError => 'Termii service error',
            self::NetworkError => 'Network error',
            self::AmbiguousTimeout => 'Request timed out with unknown outcome',
            self::Unknown => 'Unknown provider error',
        };
    }

    /** Safe to retry without risking a duplicate message. */
    public function isRetryable(): bool
    {
        return in_array($this, [
            self::RateLimited,
            self::ServerError,
            self::NetworkError,
        ], true);
    }

    /**
     * The request may have reached Termii. Recipients must be flagged for
     * reconciliation instead of being resent automatically.
     */
    public function isAmbiguous(): bool
    {
        return $this === self::AmbiguousTimeout;
    }

    /** Affects the whole campaign; stop dispatching further batches. */
    public function isFatal(): bool
    {
        return in_array($this, [
            self::NotConfigured,
            self::InvalidCredentials,
            self::InsufficientBalance,
            self::InvalidSenderId,
        ], true);
    }

    /** The recipient itself is the problem, not the campaign. */
    public function isRecipientLevel(): bool
    {
        return $this === self::InvalidRecipient;
    }
}
