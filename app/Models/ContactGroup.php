<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ContactGroup extends Model
{
    /** @use HasFactory<\Database\Factories\ContactGroupFactory> */
    use HasFactory;

    protected $fillable = ['name', 'description', 'created_by'];

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'contact_group_contact')
            ->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Contacts that are subscribed (used for eligibility counts). */
    public function subscribedContacts(): BelongsToMany
    {
        return $this->contacts()->where('subscription_status', 'subscribed');
    }
}
