<?php

namespace App\Http\Controllers;

use App\Enums\RecipientStatus;
use App\Models\Campaign;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CampaignExportController extends Controller
{
    public function __invoke(Request $request, Campaign $campaign, AuditLogger $audit): StreamedResponse
    {
        Gate::authorize('export', $campaign);

        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', array_column(RecipientStatus::cases(), 'value'))],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $audit->log('campaign.exported', $campaign, sprintf('Exported campaign "%s" results to CSV.', $campaign->name), [
            'filters' => $filters,
        ]);

        $filename = sprintf('campaign-%d-results-%s.csv', $campaign->id, now()->format('Ymd-His'));

        return response()->streamDownload(function () use ($campaign, $filters): void {
            $out = fopen('php://output', 'wb');

            if ($out === false) {
                return;
            }

            // Excel-friendly UTF-8 BOM.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'Phone',
                'Contact name',
                'Recipient status',
                'Delivery status (provider)',
                'Provider message ID',
                'Segments',
                'Failure reason',
                'Submitted at',
                'Delivered at',
                'Failed at',
                'Ambiguous outcome',
            ]);

            $query = $campaign->recipients()->orderBy('id');

            if (! empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            if (! empty($filters['from'])) {
                $query->where('created_at', '>=', $filters['from']);
            }

            if (! empty($filters['to'])) {
                $query->where('created_at', '<=', $filters['to']);
            }

            $query->chunk(1000, function ($recipients) use ($out): void {
                foreach ($recipients as $recipient) {
                    fputcsv($out, [
                        $recipient->phone,
                        $recipient->name,
                        $recipient->status->label(),
                        $recipient->provider_status,
                        $recipient->provider_message_id,
                        $recipient->segments,
                        $recipient->error_message,
                        optional($recipient->submitted_at)?->toDateTimeString(),
                        optional($recipient->delivered_at)?->toDateTimeString(),
                        optional($recipient->failed_at)?->toDateTimeString(),
                        $recipient->ambiguous ? 'yes' : 'no',
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
