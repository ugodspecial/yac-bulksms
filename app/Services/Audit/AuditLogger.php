<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes the operational audit trail. Audit failures must never break the
 * business operation that triggered them, so writes are best-effort.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function log(
        string $action,
        ?Model $subject,
        string $description,
        array $meta = [],
        ?User $actor = null,
    ): ?AuditLog {
        try {
            $actor ??= auth()->user();

            return AuditLog::create([
                'user_id' => $actor?->id,
                'action' => $action,
                'subject_type' => $subject !== null ? $subject::class : null,
                'subject_id' => $subject?->getKey(),
                'description' => mb_substr($description, 0, 255),
                'meta' => $meta === [] ? null : $meta,
                'ip_address' => app()->runningInConsole() ? null : request()->ip(),
                'user_agent' => app()->runningInConsole() ? null : mb_substr((string) request()->userAgent(), 0, 255),
            ]);
        } catch (Throwable $e) {
            Log::warning('Unable to write audit log: '.$e->getMessage());

            return null;
        }
    }
}
