<?php

namespace App\Models;

use App\Enums\CampaignStatus;
use App\Enums\DispatchMode;
use App\Enums\RecipientStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    /** @use HasFactory<\Database\Factories\CampaignFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'message',
        'sender_id',
        'channel',
        'dispatch_mode',
        'status',
        'encoding',
        'max_segments',
        'estimated_total_segments',
        'total_recipients',
        'excluded_count',
        'exclusion_summary',
        'audience',
        'scheduled_at',
        'confirmed_at',
        'queued_at',
        'processing_started_at',
        'completed_at',
        'cancelled_at',
        'created_by',
        'confirmed_by',
        'batch_size',
        'balance_before',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'dispatch_mode' => DispatchMode::class,
            'exclusion_summary' => 'array',
            'audience' => 'array',
            'scheduled_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'queued_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'balance_before' => 'decimal:2',
            'batch_size' => 'integer',
            'max_segments' => 'integer',
            'estimated_total_segments' => 'integer',
            'total_recipients' => 'integer',
            'excluded_count' => 'integer',
        ];
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function smsLogs(): HasMany
    {
        return $this->hasMany(SmsLog::class);
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    public function usesPersonalisation(): bool
    {
        return $this->dispatch_mode === DispatchMode::Individual;
    }

    /** @param  Builder<Campaign>  $query */
    public function scopeStatus(Builder $query, CampaignStatus|string $status): void
    {
        $query->where('status', $status instanceof CampaignStatus ? $status->value : $status);
    }

    /** @param  Builder<Campaign>  $query */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $query->where('name', 'like', '%'.$term.'%')
                ->orWhere('message', 'like', '%'.$term.'%');
        });
    }

    /** @param  Builder<Campaign>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', [
            CampaignStatus::Scheduled->value,
            CampaignStatus::Queued->value,
            CampaignStatus::Processing->value,
        ]);
    }

    public function pendingCount(): int
    {
        return $this->recipients()->whereIn('status', [
            RecipientStatus::Pending->value,
            RecipientStatus::Processing->value,
        ])->count();
    }

    public function progressPercent(): int
    {
        if ($this->total_recipients === 0) {
            return 0;
        }

        $done = $this->total_recipients - $this->pendingCount();

        return (int) max(0, min(100, round($done / $this->total_recipients * 100)));
    }
}
