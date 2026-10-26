<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderStatus extends Model
{
    protected $fillable = [
        'provider',
        'status',
        'balance',
        'currency',
        'sender_id',
        'sender_status',
        'sender_message',
        'last_checked_at',
        'last_balance_at',
        'last_error',
        'last_error_type',
        'last_error_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'last_checked_at' => 'datetime',
            'last_balance_at' => 'datetime',
            'last_error_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public static function termii(): self
    {
        return static::firstOrCreate(['provider' => 'termii'], ['status' => 'unknown']);
    }
}
