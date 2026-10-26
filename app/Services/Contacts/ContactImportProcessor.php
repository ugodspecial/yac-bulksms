<?php

namespace App\Services\Contacts;

use App\Enums\ImportRowStatus;
use App\Enums\ImportStatus;
use App\Models\Contact;
use App\Models\ContactImport;
use App\Models\ContactImportRow;
use App\Models\SuppressionEntry;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Phone\InvalidPhoneNumberException;
use App\Services\Phone\PhoneNumberService;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Validates an uploaded contact file and stores the per-row preview used by
 * the import screen. Nothing is written to "contacts" until the user commits.
 */
class ContactImportProcessor
{
    private const CHUNK = 400;

    public function __construct(
        private readonly ContactImportParser $parser,
        private readonly PhoneNumberService $phones,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return bool true when validation completed
     */
    public function validate(ContactImport $import): bool
    {
        $maxRows = $import->max_rows ?: $this->settings->importMaxRows();

        $import->rows()->delete();
        $import->forceFill([
            'status' => ImportStatus::Validating,
            'started_at' => now(),
            'finished_at' => null,
            'failure_reason' => null,
            'total_rows' => 0,
            'valid_rows' => 0,
            'invalid_rows' => 0,
            'duplicate_rows' => 0,
            'imported_rows' => 0,
        ])->save();

        /** @var array<int, array<string, mixed>> $buffer */
        $buffer = [];
        /** @var array<string, int> $seen */
        $seen = [];
        $counts = ['total' => 0, 'valid' => 0, 'invalid' => 0, 'duplicate' => 0];

        $flush = function () use (&$buffer, &$counts, $import): void {
            if ($buffer === []) {
                return;
            }

            $phones = array_values(array_filter(array_column($buffer, 'phone_normalized')));

            $existing = $phones === []
                ? []
                : Contact::query()->whereIn('phone', $phones)->pluck('phone')->all();

            $suppressed = $phones === []
                ? []
                : SuppressionEntry::query()->whereIn('phone', $phones)->pluck('phone')->all();

            $rows = [];
            $now = now();

            foreach ($buffer as $row) {
                if ($row['status'] === ImportRowStatus::Valid->value) {
                    if (in_array($row['phone_normalized'], $suppressed, true)) {
                        $row['status'] = ImportRowStatus::Invalid->value;
                        $row['error'] = 'This number is on the unsubscribe/suppression list.';
                    } elseif (in_array($row['phone_normalized'], $existing, true)) {
                        $row['status'] = ImportRowStatus::Duplicate->value;
                        $row['error'] = 'A contact with this phone number already exists.';
                    }
                }

                $counts[$row['status']]++;

                $rows[] = [
                    'contact_import_id' => $import->id,
                    'row_number' => $row['row_number'],
                    'name' => $row['name'],
                    'phone_raw' => $row['phone_raw'],
                    'phone_normalized' => $row['phone_normalized'],
                    'email' => $row['email'],
                    'custom_fields' => $row['custom_fields'] === [] ? null : json_encode($row['custom_fields'], JSON_UNESCAPED_UNICODE),
                    'status' => $row['status'],
                    'error' => $row['error'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            ContactImportRow::insert($rows);

            $import->forceFill([
                'total_rows' => $counts['total'],
                'valid_rows' => $counts['valid'],
                'invalid_rows' => $counts['invalid'],
                'duplicate_rows' => $counts['duplicate'],
            ])->save();

            $buffer = [];
        };

        try {
            foreach ($this->parser->rows($import) as $row) {
                $counts['total']++;

                if ($counts['total'] > $maxRows) {
                    throw new ImportFileException(sprintf(
                        'The file contains more than the configured limit of %d rows. Split it into smaller files.',
                        $maxRows
                    ));
                }

                $status = ImportRowStatus::Valid;
                $error = null;
                $normalized = null;
                $email = $row['email'];

                if (trim($row['phone']) === '') {
                    $status = ImportRowStatus::Invalid;
                    $error = 'The phone number is missing.';
                } else {
                    try {
                        $normalized = $this->phones->normalize($row['phone'], $this->settings->defaultCountry());
                    } catch (InvalidPhoneNumberException $e) {
                        $status = ImportRowStatus::Invalid;
                        $error = 'Invalid phone number: '.$row['phone'];
                    }
                }

                if ($status === ImportRowStatus::Valid && $email !== null && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $status = ImportRowStatus::Invalid;
                    $error = 'Invalid email address: '.$email;
                }

                if ($status === ImportRowStatus::Valid && $normalized !== null && isset($seen[$normalized])) {
                    $status = ImportRowStatus::Duplicate;
                    $error = sprintf('Duplicate phone number in this file (first seen on row %d).', $seen[$normalized]);
                }

                if ($normalized !== null && ! isset($seen[$normalized])) {
                    $seen[$normalized] = $row['row'];
                }

                $buffer[] = [
                    'row_number' => $row['row'],
                    'name' => $row['name'],
                    'phone_raw' => Str::limit($row['phone'], 60, ''),
                    'phone_normalized' => $normalized,
                    'email' => $email,
                    'custom_fields' => $row['custom_fields'],
                    'status' => $status->value,
                    'error' => $error !== null ? Str::limit($error, 190, '') : null,
                ];

                if (count($buffer) >= self::CHUNK) {
                    $flush();
                }
            }

            $flush();

            if ($counts['total'] === 0) {
                throw new ImportFileException('The file does not contain any data rows.');
            }

            $import->forceFill([
                'status' => ImportStatus::Ready,
                'total_rows' => $counts['total'],
                'valid_rows' => $counts['valid'],
                'invalid_rows' => $counts['invalid'],
                'duplicate_rows' => $counts['duplicate'],
            ])->save();

            $this->audit->log('import.validated', $import, sprintf(
                'Validated import "%s" (%d rows, %d valid).',
                $import->original_filename,
                $counts['total'],
                $counts['valid'],
            ), [
                'total' => $counts['total'],
                'valid' => $counts['valid'],
                'invalid' => $counts['invalid'],
                'duplicate' => $counts['duplicate'],
            ]);

            return true;
        } catch (ImportFileException $e) {
            $this->fail($import, $e->getMessage());

            return false;
        } catch (Throwable $e) {
            Log::error('Contact import validation failed', [
                'import_id' => $import->id,
                'error' => $e->getMessage(),
            ]);

            $this->fail($import, 'The file could not be validated. Please check the format and try again.');

            return false;
        }
    }

    private function fail(ContactImport $import, string $reason): void
    {
        $import->forceFill([
            'status' => ImportStatus::Failed,
            'failure_reason' => Str::limit($reason, 900, ''),
            'finished_at' => now(),
        ])->save();
    }

    /**
     * Create contacts for all valid rows, optionally adding them to the
     * import's contact group. Duplicates are skipped via the unique index.
     */
    public function commit(ContactImport $import, ?User $actor = null): int
    {
        $import->forceFill([
            'status' => ImportStatus::Importing,
            'started_at' => now(),
            'finished_at' => null,
            'failure_reason' => null,
        ])->save();

        $inserted = 0;

        try {
            DB::transaction(function () use ($import, $actor, &$inserted): void {
                ContactImportRow::query()
                    ->where('contact_import_id', $import->id)
                    ->where('status', ImportRowStatus::Valid->value)
                    ->orderBy('id')
                    ->chunkById(500, function ($rows) use ($import, $actor, &$inserted): void {
                        $now = now();

                        $contacts = $rows->map(fn (ContactImportRow $row) => [
                            'name' => $row->name,
                            'phone' => $row->phone_normalized,
                            'email' => $row->email,
                            'custom_fields' => $row->custom_fields === null
                                ? null
                                : json_encode($row->custom_fields, JSON_UNESCAPED_UNICODE),
                            'subscription_status' => 'subscribed',
                            'subscribed_at' => $now,
                            'consent_source' => 'import',
                            'source' => 'import',
                            'created_by' => $actor?->id,
                            'updated_by' => $actor?->id,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ])->all();

                        $inserted += DB::table('contacts')->insertOrIgnore($contacts);

                        if ($import->contact_group_id !== null) {
                            $contactIds = Contact::query()
                                ->whereIn('phone', $rows->pluck('phone_normalized')->all())
                                ->pluck('id');

                            $pivot = $contactIds->map(fn ($id) => [
                                'contact_group_id' => $import->contact_group_id,
                                'contact_id' => $id,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ])->all();

                            if ($pivot !== []) {
                                DB::table('contact_group_contact')->insertOrIgnore($pivot);
                            }
                        }
                    });
            });

            $import->forceFill([
                'status' => ImportStatus::Completed,
                'imported_rows' => $inserted,
                'finished_at' => now(),
            ])->save();

            $this->audit->log('import.completed', $import, sprintf(
                'Imported %d contacts from "%s".',
                $inserted,
                $import->original_filename,
            ), ['imported' => $inserted, 'group_id' => $import->contact_group_id], $actor);

            $this->deleteUpload($import);

            return $inserted;
        } catch (Throwable $e) {
            Log::error('Contact import commit failed', [
                'import_id' => $import->id,
                'error' => $e->getMessage(),
            ]);

            $import->forceFill([
                'status' => ImportStatus::Failed,
                'failure_reason' => 'The contacts could not be saved. No partial import was kept.',
                'finished_at' => now(),
            ])->save();

            return 0;
        }
    }

    private function deleteUpload(ContactImport $import): void
    {
        try {
            \Illuminate\Support\Facades\Storage::disk($import->disk)->delete($import->stored_path);
        } catch (Throwable) {
            // Not fatal: the retention command will clean up leftovers.
        }
    }
}
