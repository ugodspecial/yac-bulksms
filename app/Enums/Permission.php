<?php

namespace App\Enums;

/**
 * Granular operator permissions. Administrators implicitly hold every
 * permission; operators only hold the ones assigned to them.
 */
enum Permission: string
{
    case ManageContacts = 'contacts.manage';
    case ImportContacts = 'contacts.import';
    case SendCampaigns = 'campaigns.send';
    case ExportReports = 'reports.export';

    public function label(): string
    {
        return match ($this) {
            self::ManageContacts => 'Manage contacts and groups',
            self::ImportContacts => 'Import contacts from files',
            self::SendCampaigns => 'Confirm and send campaigns',
            self::ExportReports => 'Export reports and contact lists',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ManageContacts => 'Create, edit, delete contacts and manage contact groups.',
            self::ImportContacts => 'Upload CSV/Excel contact files and commit imports.',
            self::SendCampaigns => 'Confirm, schedule and dispatch SMS campaigns.',
            self::ExportReports => 'Download CSV exports of contacts, campaigns and delivery reports.',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $permission) => [$permission->value => $permission->label()])
            ->all();
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $permission) => $permission->value, self::cases());
    }
}
