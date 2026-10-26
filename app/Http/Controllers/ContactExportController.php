<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\ContactGroup;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContactExportController extends Controller
{
    public function __invoke(Request $request, AuditLogger $audit): StreamedResponse
    {
        Gate::authorize('export', Contact::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'group' => ['nullable', 'integer', 'exists:contact_groups,id'],
            'status' => ['nullable', 'in:subscribed,unsubscribed'],
            'custom_fields' => ['nullable', 'string', 'max:300'],
        ]);

        $customFields = [];

        if (! empty($filters['custom_fields'])) {
            $customFields = array_values(array_filter(array_map(
                fn ($field) => preg_replace('/[^a-z0-9_]/', '', strtolower(trim($field))) ?? '',
                explode(',', (string) $filters['custom_fields'])
            )));
        }

        $audit->log('contacts.exported', null, 'Exported the contact list to CSV.', ['filters' => $filters]);

        $filename = 'contacts-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($filters, $customFields): void {
            $out = fopen('php://output', 'wb');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");

            $headers = array_merge(
                ['Name', 'Phone', 'Email', 'Subscription status', 'Groups', 'Custom fields', 'Created at'],
                array_map(fn ($field) => 'custom:'.$field, $customFields)
            );

            fputcsv($out, $headers);

            $query = Contact::query()
                ->with('groups:id,name')
                ->search($filters['q'] ?? null)
                ->when(! empty($filters['status']), fn ($query) => $query->where('subscription_status', $filters['status']))
                ->when(! empty($filters['group']), function ($query) use ($filters): void {
                    $group = ContactGroup::query()->find($filters['group']);

                    $query->whereHas('groups', fn ($q) => $q->where('contact_groups.id', $group?->id));
                })
                ->orderBy('id');

            $query->chunk(1000, function ($contacts) use ($out, $customFields): void {
                foreach ($contacts as $contact) {
                    $row = [
                        $contact->name,
                        $contact->phone,
                        $contact->email,
                        $contact->subscription_status->label(),
                        $contact->groups->pluck('name')->implode(' | '),
                        $contact->custom_fields === null ? '' : json_encode($contact->custom_fields, JSON_UNESCAPED_UNICODE),
                        optional($contact->created_at)?->toDateTimeString(),
                    ];

                    foreach ($customFields as $field) {
                        $row[] = $contact->customField($field);
                    }

                    fputcsv($out, $row);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
