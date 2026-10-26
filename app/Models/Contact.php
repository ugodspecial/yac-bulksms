<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    /** @use HasFactory<\Database\Factories\ContactFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'phone',
        'email',
        'custom_fields',
        'subscription_status',
        'subscribed_at',
        'unsubscribed_at',
        'unsubscribe_reason',
        'consent_source',
        'source',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'custom_fields' => 'array',
            'subscription_status' => SubscriptionStatus::class,
            'subscribed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(ContactGroup::class, 'contact_group_contact')
            ->withTimestamps();
    }

    public function campaignRecipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isSubscribed(): bool
    {
        return $this->subscription_status === SubscriptionStatus::Subscribed;
    }

    public function displayName(): string
    {
        return filled($this->name) ? (string) $this->name : $this->phone;
    }

    /** Value of a custom field, if present. */
    public function customField(string $key): ?string
    {
        $value = $this->custom_fields[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /** @param  Builder<Contact>  $query */
    public function scopeSubscribed(Builder $query): void
    {
        $query->where('subscription_status', SubscriptionStatus::Subscribed->value);
    }

    /** @param  Builder<Contact>  $query */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $digits = preg_replace('/\D+/', '', $term) ?? '';

        $query->where(function (Builder $query) use ($term, $digits): void {
            $query->where('name', 'like', '%'.$term.'%')
                ->orWhere('email', 'like', '%'.$term.'%')
                ->orWhere('phone', 'like', '%'.$term.'%');

            if ($digits !== '') {
                $query->orWhere('phone', 'like', '%'.$digits.'%');
            }
        });
    }
}
