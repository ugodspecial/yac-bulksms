<?php

namespace App\Models;

use App\Enums\ImportRowStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactImportRow extends Model
{
    protected $fillable = [
        'contact_import_id',
        'row_number',
        'name',
        'phone_raw',
        'phone_normalized',
        'email',
        'custom_fields',
        'status',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'custom_fields' => 'array',
            'status' => ImportRowStatus::class,
            'row_number' => 'integer',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(ContactImport::class, 'contact_import_id');
    }
}
