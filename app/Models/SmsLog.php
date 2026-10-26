<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsLog extends Model
{
    protected $fillable = [
        'campaign_id',
        'campaign_recipient_id',
        'provider',
        'operation',
        'endpoint',
        'http_status',
        'duration_ms',
        'request_meta',
        'response_meta',
        'provider_message_id',
        'error_type',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'request_meta' => 'array',
            'response_meta' => 'array',
            'http_status' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(CampaignRecipient::class, 'campaign_recipient_id');
    }
}
