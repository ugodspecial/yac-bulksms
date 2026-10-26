<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SuppressionEntry extends Model
{
    /** @use HasFactory<\Database\Factories\SuppressionEntryFactory> */
    use HasFactory;

    protected $fillable = ['phone', 'reason', 'source', 'note', 'created_by'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
