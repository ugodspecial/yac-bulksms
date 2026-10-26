<?php

namespace App\Models;

use App\Enums\RecipientStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignRecipient extends Model
{
    /** @use HasFactory<\Database\Factories\CampaignRecipientFactory> */
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'contact_id',
        'phone',
        'name',
        'rendered_message',
        'segments',
        'status',
        'provider',
        'provider_message_id',
        'provider_status',
        'error_code',
        'error_message',
        'ambiguous',
        'attempts',
        'claim_token',
        'claimed_at',
        'submitted_at',
        'delivered_at',
        'failed_at',
        'last_reconciled_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => RecipientStatus::class,
            'ambiguous' => 'boolean',
            'segments' => 'integer',
            'attempts' => 'integer',
            'claimed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
            'last_reconciled_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @param  Builder<CampaignRecipient>  $query */
    public function scopeStatus(Builder $query, RecipientStatus|string $status): void
    {
        $query->where('status', $status instanceof RecipientStatus ? $status->value : $status);
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }
}
