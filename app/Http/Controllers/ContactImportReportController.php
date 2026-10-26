<?php

namespace App\Http\Controllers;

use App\Enums\ImportRowStatus;
use App\Models\ContactImport;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContactImportReportController extends Controller
{
    /**
     * Download the row-level error report for an import.
     */
    public function errors(ContactImport $import, AuditLogger $audit): StreamedResponse
    {
        Gate::authorize('view', $import);

        $audit->log('import.error_report_downloaded', $import, sprintf(
            'Downloaded the row error report for "%s".',
            $import->original_filename,
        ));

        $filename = sprintf('import-%d-errors-%s.csv', $import->id, now()->format('Ymd-His'));

        return response()->streamDownload(function () use ($import): void {
            $out = fopen('php://output', 'wb');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Row', 'Name', 'Phone (as entered)', 'Normalised phone', 'Email', 'Result', 'Reason']);

            $import->rows()
                ->whereIn('status', [ImportRowStatus::Invalid->value, ImportRowStatus::Duplicate->value])
                ->orderBy('row_number')
                ->chunk(1000, function ($rows) use ($out): void {
                    foreach ($rows as $row) {
                        fputcsv($out, [
                            $row->row_number,
                            $row->name,
                            $row->phone_raw,
                            $row->phone_normalized,
                            $row->email,
                            $row->status->label(),
                            $row->error,
                        ]);
                    }
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Download a CSV template that matches the importer's expectations.
     */
    public function template(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'wb');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['name', 'phone', 'email']);
            fputcsv($out, ['Ada Okafor', '08031234567', 'ada@example.com']);
            fputcsv($out, ['Chinedu Eze', '+2348098765432', '']);

            fclose($out);
        }, 'contact-import-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
