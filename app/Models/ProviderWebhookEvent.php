<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderWebhookEvent extends Model
{
    protected $fillable = [
        'provider',
        'event_type',
        'provider_message_id',
        'phone',
        'status',
        'dedupe_key',
        'payload_summary',
        'signature_valid',
        'processed',
        'processing_note',
    ];

    protected function casts(): array
    {
        return [
            'payload_summary' => 'array',
            'signature_valid' => 'boolean',
            'processed' => 'boolean',
        ];
    }
}
