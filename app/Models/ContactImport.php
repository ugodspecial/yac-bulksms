<?php

namespace App\Models;

use App\Enums\ImportStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContactImport extends Model
{
    /** @use HasFactory<\Database\Factories\ContactImportFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'contact_group_id',
        'original_filename',
        'stored_path',
        'disk',
        'file_type',
        'status',
        'total_rows',
        'valid_rows',
        'invalid_rows',
        'duplicate_rows',
        'imported_rows',
        'max_rows',
        'error_report_path',
        'failure_reason',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'total_rows' => 'integer',
            'valid_rows' => 'integer',
            'invalid_rows' => 'integer',
            'duplicate_rows' => 'integer',
            'imported_rows' => 'integer',
            'max_rows' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ContactGroup::class, 'contact_group_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ContactImportRow::class);
    }

    public function invalidRows(): HasMany
    {
        return $this->rows()->whereIn('status', ['invalid', 'duplicate']);
    }

    public function progressPercent(): int
    {
        if ($this->total_rows === 0) {
            return $this->status === ImportStatus::Completed ? 100 : 0;
        }

        return (int) min(100, round($this->imported_rows / $this->total_rows * 100));
    }
}
