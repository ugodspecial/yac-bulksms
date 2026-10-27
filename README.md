# YAC-BulkSMS

A focused, production-oriented bulk SMS management application for a **single shared Termii account**.
Built with Laravel 12, Livewire 3 and Tailwind CSS, it covers the day-to-day work of a small SMS
operations team: contact management, CSV/Excel imports, campaign building, review and confirmation,
batched queue-based sending, delivery reporting, suppression handling and an audit trail.

The Termii dashboard is only needed for account-level tasks (requesting a sender ID, topping up the
wallet, configuring the delivery-report webhook). Everything routine happens inside this application.

---

## Contents

1. [What the application does](#what-the-application-does)
2. [Requirements](#requirements)
3. [PHP extensions](#php-extensions)
4. [Architecture](#architecture)
5. [Installation (browser installer, skip option, cPanel)](#installation)
6. [First sign-in and roles](#first-sign-in-and-roles)
7. [Configuration reference](#configuration-reference)
8. [Contacts, groups and imports](#contacts-groups-and-imports)
9. [Campaigns and the send pipeline](#campaigns-and-the-send-pipeline)
10. [Personalisation and SMS segment estimation](#personalisation-and-sms-segment-estimation)
11. [Delivery reports](#delivery-reports)
12. [Queues and workers](#queues-and-workers)
13. [Scheduler](#scheduler)
14. [Console commands](#console-commands)
15. [Audit logging and exports](#audit-logging-and-exports)
16. [Tests](#tests)
17. [Security notes](#security-notes)
18. [Deployment checklist](#deployment-checklist)
19. [Limitations and provider-dependent behaviour](#limitations-and-provider-dependent-behaviour)
20. [Troubleshooting](#troubleshooting)

---

## What the application does

| Area | Capability |
| --- | --- |
| Authentication | Session login with rate limiting, deactivated-account lockout, forced password change, self-service password change |
| Roles | Administrators (everything) and SMS operators with granular permissions |
| Contacts | Create, edit, delete, search, filter by group/status, unsubscribe, re-subscribe (admin only) |
| Groups | Contact groups with member management |
| Imports | CSV / XLSX upload, header mapping, row-level validation preview, duplicate detection, E.164 normalisation, background validation, background commit, error report CSV |
| Suppressions | Company-wide suppression list; opt-outs and manual blocks are never messaged |
| Campaigns | Draft → review → confirm, scheduling, audience preview, exclusion summary, personalisation samples, bulk or per-recipient dispatch |
| Sending | Queue-based batches of at most 100 recipients per Termii request, atomic claiming, idempotent confirmation, controlled retries |
| Delivery reports | Signed Termii webhook plus a polling reconciliation command; provider acceptance is clearly distinguished from confirmed delivery |
| Dashboard | Real counts only (contacts, campaigns, accepted/delivered/failed/unknown recipients) plus the provider-reported balance |
| Reports | Delivery report listing with filters and CSV export of campaign results |
| Settings | Admin-only, non-secret settings (sender IDs, batch size, limits, timezone, opt-out keywords, pause switch) |
| Users | Admin-only user management with last-administrator protection |
| Audit log | Append-only record of sign-ins, contact changes, campaign actions, settings updates and provider checks |

Explicitly **out of scope**: per-user Termii credentials, wallets, payments, subscriptions, billing,
and re-creating the Termii dashboard inside this application.

---

## Requirements

* PHP **8.2 – 8.5** (8.3 recommended) with these extensions: `mbstring`, `openssl`, `pdo`, `tokenizer`,
  `xml`, `xmlreader`, `zip`, `ctype`, `json`, `bcmath`, `fileinfo`, `intl` (recommended), plus either
  `pdo_mysql` or `pdo_sqlite`.
  `zip` and `xmlreader` are required by the spreadsheet reader used for Excel (.xlsx) imports.
* MySQL 8 / MariaDB 10.6+ (SQLite is supported and used by the automated tests).
* Composer 2. `composer.lock` is committed, so `composer install` reproduces the exact dependency set.
* Node.js **20 LTS or newer** and npm 10+ to build the Tailwind/Vite assets (`package.json` also accepts
  Node 18, which is end-of-life). `package-lock.json` is committed, so `npm ci` installs exactly the
  versions the assets were built with.
* A queue worker process and a scheduler cron entry (see below).
* A Termii account with at least one approved sender ID (optional at first: campaigns can be prepared
  without it, but nothing can be sent until it is configured).
* Write access for the web server user to the application root (for `.env`), `storage/` and
  `bootstrap/cache/`.

---

## PHP extensions

The application needs a handful of standard PHP extensions. `zip` and `xmlreader` are the ones that are
disabled by default on Windows builds, and `openspout` (the spreadsheet reader) refuses to install without
them.

### Windows / Laragon

Laragon ships `php_zip.dll` and `php_xmlreader.dll`, but both can be commented out in `php.ini`.
`php --ini` prints the file in use (for example
`C:\laragon\bin\php\php-8.4.12-nts-Win32-vs17-x64\php.ini`).

1. Open that `php.ini` in a text editor.
2. Find `;extension=zip` and `;extension=xmlreader` and remove the leading `;` from each line that is
   commented out. If a line is already active, leave it as it is (do not add a second copy — PHP warns
   about a module being loaded twice).
3. Save the file and restart the terminal (and Laragon, if PHP runs under Apache/nginx).
4. Verify:

   ```powershell
   php -m | findstr /i "zip xmlreader"
   ```

5. Run `composer install` again (the committed lock file is used).

If you prefer not to edit `php.ini` by hand, Laragon's menu has **PHP → Extensions**, where the same
extensions can be toggled.

### Linux / macOS

```bash
# Debian / Ubuntu (APT picks the right package for your PHP version)
sudo apt install php8.3-zip php8.3-xml php8.3-mbstring php8.3-intl
sudo systemctl reload php8.3-fpm    # or restart Apache/nginx

# RHEL / Fedora / Rocky
sudo dnf install php-cli php-zip php-xml php-mbstring php-intl

# macOS (Homebrew)
brew install php        # zip and xmlreader are built in
```

### Shared hosting

Enable **zip** and **xmlreader** in the hosting control panel (cPanel → *Select PHP Version* →
*Extensions*). Without them the application still runs with CSV imports, but Excel (`.xlsx`) uploads are
rejected with a clear message.

### CSV-only hosts

If the server has no `zip` extension and you only ever import CSV files, the install can proceed with:

```bash
composer install --ignore-platform-req=ext-zip
```

Excel uploads then fail with an actionable message instead of a fatal error, and the browser installer
shows `zip` as a failing requirement so the state is not hidden.

---

## Architecture

```
app/
├── Enums/                     Campaign, recipient, import, provider and role enums
├── Exceptions/                Campaign state/preflight and provider-temporary exceptions
├── Console/Commands/          Scheduler commands plus `app:install`
├── Http/
│   ├── Controllers/           CSV exports, import reports, logout, Termii webhook
│   ├── Controllers/Install/   The browser installer steps
│   └── Middleware/            Installer guard, active-account and forced-password-change guards
├── Jobs/                      Dispatch, batch send, import validation/commit, reconciliation
├── Livewire/                  Screen components (dashboard, contacts, campaigns, …)
├── Models/                    Eloquent models
├── Policies/                  Per-model authorisation (contact, group, campaign, import, user)
├── Providers/AppServiceProvider.php
└── Services/
    ├── Audit/                 AuditLogger (best-effort, never breaks the operation)
    ├── Campaign/              RecipientResolver, CampaignService, CampaignProgress
    ├── Contacts/              ContactService, import parser/processor, committer
    ├── Install/               InstallationManager, requirements checker, database installer,
    │                          .env writer and the migration/administrator runner
    ├── Phone/                 libphonenumber wrapper (E.164 digits)
    ├── Provider/              ProviderHealthService (balance, sender IDs, last error)
    ├── Sms/                   SegmentCalculator, MessageRenderer
    ├── Settings/              Typed settings service (database-backed, cached)
    └── Termii/                TermiiGateway + DTOs behind TermiiGatewayInterface
```

* **Nothing requires a pre-existing database to boot.** Until the installer has finished, the global
  `EnsureApplicationIsInstalled` middleware answers every browser request, and the provider switches
  sessions/cache to the filesystem and generates a temporary `APP_KEY`, so the installer pages work on a
  bare checkout.
* **The gateway is the single boundary to Termii.** `App\Services\Termii\TermiiGateway` wraps
  [`zeevx/lara-termii`](https://github.com/zeevx/lara-termii) (v1.3) and implements
  `TermiiGatewayInterface`. The interface is what the rest of the application (and the tests) uses.
* **Nothing is sent from a Livewire request.** Confirmation writes the campaign and dispatches queue
  jobs; the HTTP request to Termii always happens inside a worker.
* **No secrets in the database.** The Termii API key lives only in the environment; the settings screen
  shows a masked value.
* **No fabricated statistics.** The dashboard and campaign pages count what is stored. A message is only
  "delivered" when Termii says so.

---

## Installation

There is **no command-line step required to create the database**. After the code and the built
assets are on the server, open the application in a browser: a four-step installer configures
everything, creates the database automatically and writes `.env` for you.

### 1. Put the code on the server

```bash
git clone https://github.com/ugodspecial/yac-bulksms.git
cd yac-bulksms

composer install --no-dev --optimize-autoloader
npm ci && npm run build        # or deploy public/build from CI
php artisan storage:link
```

`composer install` reads the committed `composer.lock`, which was resolved for PHP 8.2 so it installs
unchanged on PHP 8.2, 8.3, 8.4 and 8.5. Composer verifies the platform first: if `ext-zip` or
`ext-xmlreader` is missing it stops with a single clear message instead of the dependency conflict shown
by older versions of this project.

`npm ci` likewise reads the committed `package-lock.json` (lock file version 3) and installs exactly the
dependencies the assets were built with. If you change `package.json`, run `npm install` once to refresh
the lock file and commit it with the change.

The application root, `storage/` and `bootstrap/cache/` must be writable by the web server user:

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache
```

You do **not** need to copy `.env.example`, run `key:generate`, create the database or run the
migrations by hand. Opening the site before that is done shows the installer instead of an error.

### 2. Open the application in a browser

`http://your-host/` (or `php artisan serve`) redirects to `/install`:

| Step | What happens |
| --- | --- |
| **1. Server requirements** | PHP version, required extensions, writable directories and the built assets are checked. Anything missing is listed with what to do; the installer only continues when the required checks pass. |
| **2. Database** | Choose **MySQL/MariaDB** or **SQLite**. For MySQL enter host, port, database name, user and password; **the database is created automatically** if it does not exist (and the user is allowed to create it). SQLite simply creates a file. The connection is tested immediately and the credentials are written to `.env`. |
| **3. Application** | Application name, timezone, URL and environment, the first administrator (name, email, password), and optionally the Termii API key and sender ID. |
| **4. Finish** | The installer runs the migrations, writes the default settings, creates the administrator, closes itself and shows the remaining operational steps (queue worker, cron entry, webhook). |

Notes:

* **Passwords are never written to `.env`.** The administrator password goes into the database (hashed);
  only non-secret configuration and the Termii API key are written to `.env`.
* `APP_KEY` is generated during step 2. An existing key in `.env` is reused, so re-running the installer
  does not invalidate sessions unnecessarily.
* A `.env.backup` copy is made before every write, and keys the installer does not manage (comments,
  custom variables) are preserved.
* Session, cache and queue are set to the `database` driver, so no Redis or extra services are needed.
* The installer keeps a temporary key in `storage/framework/installer.key` while it runs, so forms work
  before `.env` exists.
* After finishing, every `/install` URL redirects to the dashboard. The installer is disabled for good:
  re-running it would overwrite `.env` and reset the administrator password.

### 3. Start the workers

```bash
php artisan queue:work --tries=3 --timeout=300    # supervised in production
php artisan schedule:work                          # development only; use cron in production
```

Both are described in [Queues and workers](#queues-and-workers) and [Scheduler](#scheduler).

### Skipping the installer

If you prefer to configure everything yourself, provide the settings through `.env` or real environment
variables and run `php artisan migrate --force`. On step 1 press **Skip the installer**: it checks the
`APP_KEY`, the database connection and the required tables, and only then steps aside (otherwise it tells
you exactly what is missing).

You can also disable the installer entirely — useful on container platforms where the environment is
provided externally:

```dotenv
YAC_INSTALLER_ENABLED=false
```

### Command-line alternative

The installer has a terminal equivalent:

```bash
php artisan app:install --admin-name="YAC Admin" --admin-email=you@example.com
```

It uses the database configuration already present in `.env`/the environment, so create the database
first. `php artisan migrate --seed` remains available for scripted deployments; the seeder reads
`ADMIN_NAME`, `ADMIN_EMAIL` and `ADMIN_PASSWORD` (a random password is generated and printed once when
`ADMIN_PASSWORD` is empty — there is deliberately no well-known default password).

**No demo contacts, campaigns or messages are ever seeded.** All statistics must reflect real activity.

### Shared hosting (cPanel and similar)

1. Upload the repository (or a ZIP of it) outside `public_html`, for example to `~/yac-bulksms`.
2. In cPanel → *Domains*, point the document root of the domain to `~/yac-bulksms/public`.
3. Run `composer install --no-dev --optimize-autoloader` and `npm ci && npm run build` from the Terminal
   (or upload the `vendor/` and `public/build/` directories produced locally).
4. Make `storage/` and `bootstrap/cache/` writable by the web server user (File Manager → Permissions).
5. Open the domain: the installer checks the permissions, creates the MySQL database with the account you
   enter (or asks you to create it in phpMyAdmin first) and finishes the installation.
6. Add the cron entry `* * * * * cd ~/yac-bulksms && php artisan schedule:run` and start a queue worker
   from the terminal or a cPanel cron entry such as
   `* * * * * cd ~/yac-bulksms && php artisan queue:work --stop-when-empty --max-time=55`.

If the host has no Node.js, build the assets on your computer and upload `public/build/`; the installer
refuses to continue without them.

### Re-running the installer

Delete the marker and reload the site:

```bash
rm storage/app/installation.json
```

### Development server

```bash
php artisan serve
npm run dev        # Vite dev server with hot reload
php artisan queue:work
```

For local development you can run everything without a real Termii key: create contacts, import files,
build campaigns and open the review screen. Only confirmation requires a configured provider.

---

## First sign-in and roles

Sign in with the administrator account you created in the installer (or the one produced by
`php artisan migrate --seed` / `app:install`, which is asked to change its password at first sign-in).

| Role | Description |
| --- | --- |
| **Administrator** (`admin`) | Full access: settings, users, audit log, suppression list, cancellations, retries, re-subscribing contacts. Implicitly holds every permission. |
| **Operator** (`operator`) | Confirmed-by-permission access to contacts, imports, campaigns and reports. |

Operator permissions (`users.permissions` JSON column):

| Permission | Grants |
| --- | --- |
| `contacts.manage` | Create/edit/delete contacts and groups, unsubscribe contacts |
| `contacts.import` | Upload, validate and commit contact imports |
| `campaigns.send` | Confirm/send campaigns (without it, operators can only save drafts and submit them for review) |
| `reports.export` | Export contact lists and campaign results to CSV |

Guarantees:

* The last active administrator cannot be deleted, demoted or deactivated.
* Administrators cannot delete their own account.
* Deactivated users are signed out by middleware and cannot log in.

---

## Configuration reference

All values live in `.env`; **never commit a filled `.env`**.

| Variable | Purpose |
| --- | --- |
| `APP_NAME`, `APP_URL`, `APP_TIMEZONE` | Application identity, URL and default timezone |
| `APP_ENV`, `APP_DEBUG`, `APP_KEY` | Standard Laravel settings (`APP_DEBUG=false` in production) |
| `DB_*` | Database connection (MySQL by default) |
| `SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database` | Zero-extra-infrastructure defaults |
| `QUEUE_NAME` | Queue used for campaign and import jobs |
| `YAC_QUEUE_RETRY_AFTER` | Seconds a job may run before the queue considers it lost (default 120; keep it above the longest job timeout if you raise worker timeouts) |
| `TERMII_API_KEY` | **Secret.** Shared Termii account key; only read from the environment |
| `TERMII_BASE_URL` | Termii API base URL (default `https://v4.api.termii.com`) |
| `TERMII_SENDER_ID` | Fallback sender ID when none is stored in application settings |
| `TERMII_CHANNEL` | Fallback channel (`generic` or `dnd`) |
| `TERMII_TIMEOUT` | HTTP timeout in seconds (default 30) |
| `TERMII_WEBHOOK_ENABLED` | Enables `POST /webhooks/termii` |
| `TERMII_WEBHOOK_SECRET` | Secret used for the Termii HMAC-SHA512 signature; **required** for the webhook |
| `YAC_STALE_CLAIM_MINUTES` | Age after which a claimed recipient is treated as an interrupted job (default 15) |
| `YAC_INSTALLER_ENABLED` | Serve the browser installer while the application is not installed (default `true`). Set to `false` when the environment is managed outside the application |
| `ADMIN_NAME`, `ADMIN_EMAIL`, `ADMIN_PASSWORD` | First administrator created by the seeder |

Admin-only settings stored in the database (Settings screen): application name, logo, timezone,
approved sender IDs, default sender ID and channel, batch size (max 100), maximum message length and
segments, custom field names, pause sending switch, opt-out keywords, default country, import limits,
reconciliation toggle and log retention.

### Environment caching

Use `php artisan config:cache` in production. All code reads configuration through `config()`, and the
seeders read the administrator from `config('yac.admin.*')`, so they keep working with a cached config.

---

## Contacts, groups and imports

### Contact rules

* Phone numbers are stored as **E.164 digits without the plus sign** (for example `2348031234567`).
  Normalisation and validation are performed by `giggsey/libphonenumber-for-php`, configured with the
  default country from Settings (Nigeria by default).
* `contacts.phone` is unique. Creating or importing a duplicate is rejected (imports mark it as a
  duplicate row and skip it).
* Unsubscribed contacts are kept (for history and reporting) but are excluded from every audience.
* A contact whose number is on the suppression list is created as **unsubscribed** — importing or
  re-adding a number never silently re-subscribes it. Only an administrator can re-subscribe, and that
  action is audited.

### Import format

The first row must contain headers. A phone column is required; recognised header aliases include
`phone`, `phone_number`, `mobile`, `msisdn`, `number`, `telephone`, `whatsapp`. `name`, `email` and
any other column (stored as a custom field) are optional.

```csv
name,phone,email,department
Ada Okafor,08031234567,ada@example.com,Accounts
Chinedu Eze,+2348098765432,,Sales
```

CSV files may be comma, semicolon or tab separated. XLSX workbooks are read from the first worksheet
(legacy `.xls` is not supported; re-save as `.xlsx`). A CSV template is available from
**Imports → Template**.

Upload limits (Settings): 20,000 rows and 5 MB by default. Files are stored on the private `local` disk
and deleted after a completed import (or by the retention command).

### Import flow

1. **Upload** — the file is validated (extension/size) and stored privately; validation is queued.
2. **Validation** — every row is normalised and classified as `valid`, `invalid` or `duplicate`:
   missing/invalid phone, invalid email, suppressed numbers, duplicates inside the file and contacts
   that already exist. Nothing is written to `contacts` at this stage.
3. **Preview** — the import screen shows counts and the row-level results, filterable by status. The
   invalid/duplicate report can be downloaded as CSV.
4. **Commit** — valid rows are inserted in chunks inside a transaction, optionally added to a contact
   group. Duplicates are skipped again at insert time by the unique index. The file is deleted.

Invalid rows never block the rest of the import; re-upload a corrected file for the remaining rows.

---

## Campaigns and the send pipeline

### States

```
draft ──► needs_review ──► (admin) confirmed ──► queued ──► processing ──► completed
  │             │                                  │              │        completed_with_errors
  └─────────────┴──────► cancelled ◄──────────────┘              └──────► failed
scheduled ──(due, scheduler)──► queued
```

* **draft** — editable by its creator or an administrator.
* **needs_review** — submitted by an operator without `campaigns.send`; an administrator confirms it.
* **scheduled** — confirmation accepted, audience not materialised yet.
* **queued / processing** — recipients exist; batch jobs are running.
* **completed** — every recipient reached a terminal state without failures.
* **completed_with_errors** — finished, but some recipients failed or have an unknown outcome.
* **failed** — a fatal provider error stopped the campaign.
* **cancelled** — unsent recipients were skipped; already accepted messages cannot be recalled.

### Confirmation is the only way to send

`CampaignService::confirm()` locks the campaign row, re-checks the state, re-resolves the audience,
applies exclusions, materialises recipients and only then queues the dispatch job. A confirmation can
happen exactly once: repeated clicks, two browser tabs or a retried request cannot send twice.

Before queueing, the service refuses to continue when:

* Termii is not configured,
* sending is paused in Settings,
* the sender ID is not in the approved list,
* the cached Termii balance is zero,
* the message (or any personalised variant) exceeds the configured segment limit,
* no eligible recipients remain.

### Audience resolution and exclusion

For every campaign the resolver merges explicit contacts and group memberships, removes duplicates
(by contact and by phone), then excludes:

| Reason | Meaning |
| --- | --- |
| `unsubscribed` | Contact opted out |
| `suppressed` | Number is on the company suppression list |
| `duplicate` | Same phone number in the selection more than once |
| `invalid_phone` | Stored number failed re-validation |

The counts are shown on the review screen and stored with the campaign.

### Dispatch modes

| Mode | When | Provider calls |
| --- | --- | --- |
| **bulk** | Message has no placeholders, so every rendered message is identical | One request per group of up to 100 recipients (`/api/sms/send/bulk`) |
| **individual** | Message contains any placeholder | One request per recipient (`/api/sms/send`) |

Batch size is configurable (1–100, default 100) and always clamped to Termii's documented maximum of
100 numbers per request.

### Safety guarantees in the worker

`SendCampaignBatchJob` and `DispatchCampaignJob` implement the following rules:

* **Atomic claiming.** Pending recipients of a batch are claimed with a conditional `UPDATE`
  (`pending → processing`, unique claim token). Two workers — or the same batch queued twice — can never
  send the same recipient.
* **Idempotent dispatch.** `DispatchCampaignJob` is unique per campaign (`ShouldBeUnique`, one hour).
* **No automatic resend on ambiguous outcomes.** A timeout (or a dropped connection after the request
  was written) is classified as `ambiguous_timeout`. Recipients are marked `unknown` with
  `ambiguous = true` and are **never** retried automatically.
* **Bounded retries.** Rate limits, 5xx responses and connection failures are safe to retry: the batch
  is released back to `pending` and the queue retries with backoff (max 3 job attempts, max 3 attempts
  per recipient).
* **Fatal errors stop the campaign.** Invalid credentials, empty balance, unconfigured provider or a
  rejected sender ID mark the campaign `failed` and the remaining recipients `failed` instead of
  burning through the audience.
* **Interrupted workers.** A batch that died mid-flight (or a claim older than
  `YAC_STALE_CLAIM_MINUTES`) is flagged for reconciliation. The scheduler command never resends it.
* **Cancellation is respected.** Jobs re-check the campaign status after claiming and skip work when
  the campaign was cancelled.

### Retrying failed recipients

Administrators can re-queue recipients whose failure is known to be safe to retry
(`rate_limited`, `server_error`, `network_error`). Timeouts and unknown outcomes are deliberately
excluded. The action is audited and only available when no other messages of the campaign are still in
flight.

---

## Personalisation and SMS segment estimation

### Placeholders

| Token | Replaced with |
| --- | --- |
| `{{name}}` | Contact name (empty when unknown) |
| `{{phone}}` | E.164 digits without `+` |
| `{{email}}` | Contact e-mail (empty when unknown) |
| `{{custom.<field>}}` | Value of an approved custom field |

Approved custom field names are configured in Settings. Unknown placeholders are rejected before a
campaign can be saved, and rendered empty for a recipient that lacks the field. Malformed braces are
rejected as well. `{{ phone }}` with spaces is accepted.

### Segment estimation (`SegmentCalculator`)

* **GSM-7**: 160 septets in a single message; 153 per part when multipart. Characters in the GSM 03.38
  extension table (`^ { } \ [ ] ~ | €`) count as **two** septets. Accented characters that belong to the
  GSM alphabet (`é`, `à`, `ü`, …) stay in GSM-7.
* **UCS-2**: 70 UTF-16 code units in a single message; 67 per part when multipart. Emoji count as two
  code units.
* Anything outside GSM-7 forces UCS-2 for the whole message.

The review screen shows the encoding, character count and segment estimate per message, including
personalised samples. **These figures are estimates**: Termii bills according to its own rules and may
route or split messages differently. The application never invents a price per segment.

---

## Delivery reports

### What "accepted" and "delivered" mean here

* **Accepted** — Termii acknowledged the API request. No delivery evidence exists yet.
* **Delivered** — Termii explicitly reported delivery (webhook or status polling).
* **Failed** — Termii rejected the message or reported a failed/expired delivery.
* **Unknown** — the request outcome is unclear (timeout, interrupted worker). Never resent
  automatically.

A message is never displayed as delivered just because the HTTP request returned `200`.

### Webhook (recommended)

Termii pushes delivery reports (`type: outbound`) and inbound messages (`type: inbound`) as a JSON POST
with an `X-Termii-Signature` header: HMAC-SHA512 of the raw request body keyed with your Termii secret.

1. Generate a long random secret and set `TERMII_WEBHOOK_SECRET`, then set
   `TERMII_WEBHOOK_ENABLED=true`.
2. In the Termii dashboard (`Account → Webhook`), register `https://your-host/webhooks/termii` and the
   same secret.
3. The endpoint validates the signature with `hash_equals`, records every event in
   `provider_webhook_events` (deduplicated), updates the matching recipient and writes an audit entry.
   Invalid signatures are rejected with `401`; the endpoint returns `404` while disabled.

Delivery reports update the recipient through `DeliveryState::fromProviderStatus()`, which understands
Termii's documented statuses: `Delivered`, `Message Sent`, `Received`, `DND Active on Phone Number`,
`Message Failed`, `Rejected` and `Expired`.

Because Termii's bulk endpoint returns a single message ID for a batch, per-recipient provider message
IDs are only stored when a batch contains exactly one recipient (individual mode). For bulk batches the
webhook is matched by the receiving phone number and the most recent pending recipient for that number.

### Polling fallback (`campaigns:reconcile`)

Every ten minutes the scheduler queues reconciliation jobs for accepted messages that have a provider
message ID. The job calls Termii's message history endpoint and updates the recipient state. It also
flags interrupted claims for review. Polling is a fallback: without the webhook, delivered statuses are
only as fresh as the next poll, and bulk messages without an ID cannot be polled.

### Inbound opt-outs

When the webhook is enabled, inbound messages that match an opt-out keyword (`STOP`, `UNSUBSCRIBE`,
`CANCEL`, `OPT OUT`, `OPT-OUT` by default) unsubscribe the matching contact and add the number to the
suppression list. Without the webhook, operators handle opt-outs manually (contact screen or
suppression list).

---

## Queues and workers

All sending and importing runs on the queue. The default configuration uses the **database** queue, so
no Redis daemon is required.

```bash
php artisan queue:table     # only if you removed the default jobs migration
php artisan migrate
php artisan queue:work --tries=3 --timeout=300 --queue=default
```

`SendCampaignBatchJob` and `DispatchCampaignJob` define their own `tries`, `timeout` and `backoff`
(60/180 seconds for sends). Import jobs have a 900-second timeout.

### Supervisor example

```ini
[program:yac-bulksms-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/yac-bulksms/artisan queue:work database --sleep=3 --tries=3 --max-time=3600
directory=/var/www/yac-bulksms
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/yac-bulksms-worker.log
stopwaitsecs=3600
```

Notes:

* Keep `YAC_QUEUE_RETRY_AFTER` (queue `retry_after`, default 120s) higher than the longest job timeout
  you run; otherwise a long batch may be executed twice. The batch claim guard prevents double sends,
  but raising it avoids wasted work.
* Run `php artisan queue:restart` after every deployment.
* Failed jobs are visible in the Settings screen (queue health card) and with `queue:failed`.
  Investigate the cause before `queue:retry all` — a fatal provider error will fail again.
* Recommended cron for production:

```cron
* * * * * cd /var/www/yac-bulksms && php artisan schedule:run >> /dev/null 2>&1
```

---

## Scheduler

Registered in `routes/console.php`:

| Task | Frequency | Purpose |
| --- | --- | --- |
| `campaigns:dispatch-scheduled` | every minute | Queue scheduled campaigns whose time has arrived (atomic claim prevents double dispatch) |
| `campaigns:reconcile` | every 10 minutes | Poll delivery reports and flag interrupted claims |
| `termii:balance` | hourly at :10 | Refresh the cached provider balance used by the dashboard and the preflight check |
| `logs:prune` | daily at 02:30 | Apply the retention policy to SMS logs, webhook events and finished imports; flag stuck imports |

Use `php artisan schedule:work` in development and a single cron entry in production.

---

## Console commands

| Command | Description |
| --- | --- |
| `campaigns:dispatch-scheduled {--limit=50}` | Queue due scheduled campaigns |
| `campaigns:reconcile {--limit=200}` | Flag interrupted recipient claims and queue delivery-report polling |
| `termii:balance` | Refresh provider balance and connection status |
| `logs:prune {--days=} {--dry-run}` | Delete operational logs older than the retention period |
| `app:install {--admin-name=} {--admin-email=} {--admin-password=} {--force}` | Terminal equivalent of the browser installer (migrate, default settings, first administrator) |

---

## Audit logging and exports

Every sensitive action writes an entry to `audit_logs` with actor, action, description, structured
metadata, IP address and user agent (best effort: an audit write can never break the operation). The
log is append-only in the UI and is pruned by `logs:prune` according to the configured retention.

Exports (UTF-8 BOM, Excel friendly):

* Contact list — `/contacts/export` (`reports.export`)
* Campaign results — `/campaigns/{campaign}/export` (`reports.export`)
* Import error report — `/imports/{import}/errors`
* Import template — `/imports/template`

All export routes are authenticated, authorised by policy and throttled (`throttle:sensitive`).

---

## Tests

```bash
php artisan test
```

The PHPUnit configuration runs against an **in-memory SQLite** database and fakes the Termii gateway,
so no MySQL, queue worker or SMS credit is needed. The suite covers:

| Area | Tests |
| --- | --- |
| SMS units | `tests/Unit/SegmentCalculatorTest.php` — GSM-7/UCS-2, extension characters, emoji, multipart boundaries |
| Templates | `tests/Unit/MessageRendererTest.php` — placeholders, custom fields, malformed templates |
| Phones | `tests/Unit/PhoneNumberServiceTest.php` — local/international formats, invalid input |
| Provider adapter | `tests/Feature/TermiiGatewayTest.php` — `Http::fake()` payloads, error classification, timeout ambiguity, no secret in logs |
| Campaign lifecycle | `tests/Feature/CampaignLifecycleTest.php` — dedupe, exclusion, scheduling, cancellation, controlled retries |
| Batch worker | `tests/Feature/SendCampaignBatchJobTest.php` — accepted vs delivered, claim idempotency, ambiguous timeouts, fatal errors |
| Campaign UI | `tests/Feature/CampaignWizardTest.php` — build, review, acknowledge and send through Livewire |
| Delivery webhook | `tests/Feature/TermiiWebhookTest.php` — signatures, duplicate events, inbound opt-out |
| Imports | `tests/Feature/ContactImportTest.php` — validation preview, duplicates, suppressed numbers, commit |
| Auth & roles | `tests/Feature/AuthenticationTest.php`, `tests/Feature/AuthorizationTest.php` |
| Contacts | `tests/Feature/ContactsTest.php` — normalisation, duplicates, unsubscribe/re-subscribe rules |
| Settings & dashboard | `tests/Feature/SettingsTest.php`, `tests/Feature/DashboardTest.php` — secret masking, real statistics |
| Installer | `tests/Feature/InstallerTest.php`, `tests/Unit/EnvFileWriterTest.php` — redirects before installation, every page renders without a database, SQLite creation, MySQL name validation, migrations plus administrator creation, `.env` merging and escaping, skipping, and the installer closing itself |

No test sends a real SMS or calls Termii: the interface is replaced by `Tests\Support\FakeTermiiGateway`,
and the adapter itself is only exercised against `Http::fake()`.

CI (`.github/workflows/ci.yml`) runs three jobs on every push and pull request:

**PHP 8.3 · tests** — installs the dependencies, migrates and seeds a fresh SQLite database (with
throwaway CI credentials), lints every compiled Blade view, caches the routes, runs this test suite and
smoke-tests the scheduler commands.

**Browser installer · end to end** — starts the application with no `.env`, no `APP_KEY` and no database,
then drives the installer over real HTTP (redirects before installation, the SQLite database created
through the form, CSRF and sessions with the temporary key, the administrator created by the migrations,
the installer closing itself and database-backed sessions afterwards).

**Node 20 · assets** — builds the Tailwind/Vite assets.


---

## Security notes

* The Termii API key is only read from `TERMII_API_KEY`/config; it is never written to the database,
  sent to the browser, rendered in Blade or written to logs. Gateway logs contain a redacted request
  summary (recipient, sender, channel, type, message length) and a trimmed response summary; the API
  key is additionally scrubbed from strings before they are logged.
* The Settings screen only ever shows a masked key (`••••••••1234`).
* CSRF protection is disabled for exactly one route (`POST /webhooks/termii`) because Termii is not a
  browser session; that route verifies an HMAC-SHA512 signature with `hash_equals` and is disabled
  unless explicitly enabled with a secret.
* Login, exports, confirmations and provider checks are rate limited.
* Passwords use Laravel's hashing and a minimum of 10 characters with letters and numbers. The seeded
  administrator must change the initial password.
* Contact imports are stored on the private disk (`storage/app/private`), never under `public/`.
* The installer only exists while the application is not installed: once it finishes it records
  `storage/app/installation.json` and every `/install` URL redirects to the dashboard. It is the only
  place that writes secrets to `.env`, it never writes the administrator password there, and it makes a
  `.env.backup` before each write. Run the first installation over HTTPS (or on localhost) because the
  administrator password is submitted through the browser.
* Server variables (`APP_KEY`, `DB_*`, `TERMII_*`, …) always win over `.env`. On hosts that manage the
  environment externally, set `YAC_INSTALLER_ENABLED=false`.
* **Never leave the installer open on a live server.** While it is open, whoever reaches the site can
  create (or reset) an administrator account. It closes itself when it finishes or is skipped; if you
  migrate the database by hand, finish the installer or set `YAC_INSTALLER_ENABLED=false`, and check that
  `storage/app/installation.json` exists afterwards. `php artisan migrate` prints a warning while the
  installer is still open.
* All database access goes through Eloquent's parameterised queries.
* CSV exports are written as-is (they are **not** protected against spreadsheet formula injection).
  Contact data is entered by authenticated operators, but if you import files from untrusted sources,
  review exported CSVs before opening them in Excel — see the limitations section.

---

## Deployment checklist

1. `git pull` / deploy the release.
2. `composer install --no-dev --optimize-autoloader`.
3. `npm ci && npm run build` (or build assets in CI and ship `public/build`).
4. Set production `.env` values (`APP_URL`, Termii credentials, webhook secret) or run the browser
   installer once with the environment set to `production`.
5. `php artisan migrate --force` (the installer does this for you on a fresh server).
6. `php artisan config:cache && php artisan route:cache && php artisan view:cache`.
7. `php artisan storage:link` (once per server) and ensure `storage/` and `bootstrap/cache/` are
   writable by the web user.
8. `php artisan queue:restart` so workers pick up the new code.
9. Verify supervisor and the scheduler cron are running.
10. Sign in as an administrator, check Settings → provider status, queue health and webhook state.

Serve over HTTPS. If the application is behind a load balancer or reverse proxy, configure trusted
proxies so generated URLs and the webhook route use the correct scheme and host.

---

## Limitations and provider-dependent behaviour

* **Termii must be configured for sending.** Without `TERMII_API_KEY` and an approved sender ID,
  campaigns can be prepared but not confirmed.
* **Delivery reports depend on the provider.** Unless the webhook is enabled (and registered in the
  Termii console) or polling succeeds, messages remain "accepted". The application never guesses a
  delivered status.
* **Bulk requests return one message ID.** Per-recipient IDs exist only for single-recipient requests;
  webhook matching for bulk batches falls back to phone number + campaign recency.
* **Ambiguous timeouts require human verification.** Messages with an unknown outcome are flagged and
  never resent automatically. Verify them in the Termii dashboard before re-sending.
* **Termii channel restrictions.** The `generic` channel is promotional: it does not deliver to
  DND-enabled numbers and MTN reportedly blocks promotional traffic between 20:00 and 08:00 WAT. The
  `dnd` (transactional) route requires activation of the DND route on your Termii account. These rules
  are enforced by Termii, not by this application; the application does not try to bypass them.
* **`dnd` does not mean guaranteed transactional delivery.** Termii still decides routing.
* **Segment counts are estimates.** GSM-7/UCS-2 maths is implemented and unit-tested, but Termii may
  split, reroute or bill differently. No price information is guessed or displayed.
* **Sender ID validation is advisory.** `ProviderHealthService` compares the configured sender ID with
  Termii's sender-ID list. A missing/renamed sender ID is reported, but Termii remains the authority.
* **Balance is cached.** It is refreshed hourly, on demand from Settings and before confirmation (if a
  known balance is zero, sending is blocked). A stale zero can be refreshed with "Check balance".
* **Imports support CSV and modern XLSX only.** Legacy `.xls` and password-protected workbooks are not
  supported.
* **Opt-out handling needs the webhook.** Without it, inbound STOP messages are not processed
  automatically; operators must unsubscribe contacts manually.
* **The queue and scheduler must be running.** Scheduled campaigns do not send without
  `schedule:run`, and nothing sends without a worker.
* **One shared Termii account.** There are no per-user credentials, wallets, payments or subscriptions,
  by design.
* **CSV exports are not formula-escaped.** Treat exported files from untrusted sources with care.
* **A manually migrated database keeps the installer open** until you finish it or set
  `YAC_INSTALLER_ENABLED=false` (see the security notes).
* **The installer needs a writable `.env`.** On read-only filesystems (immutable containers, some PaaS
  platforms) configure the environment variables yourself, run `php artisan migrate --force` and set
  `YAC_INSTALLER_ENABLED=false`.
* **The installer creates the database, not the database user.** The MySQL user has to exist already and
  must be allowed to create the schema; otherwise create the database first and enter its name.
* **Audit retention.** Audit entries are pruned by the retention command (default 90 days), so keep
  separate backups if you need a longer regulatory history.

---

## Troubleshooting

| Symptom | Checks |
| --- | --- |
| "Termii is not configured" | `TERMII_API_KEY` set, `config:clear`/`config:cache` after changing `.env` |
| Campaign stuck in "Queued" | Is a queue worker running? Check the Settings queue card and `queue:failed` |
| Recipients stay "Accepted" | Webhook disabled/unregistered, or reconciliation disabled; check `TERMII_WEBHOOK_ENABLED`, the Termii console URL and the signed webhook log |
| Import stays "Queued" | Worker not running, or the job failed; look at `storage/logs/laravel.log` and the import's failure reason |
| "No eligible recipients" on review | Everyone selected is unsubscribed, suppressed, duplicated or invalid — check the exclusion summary |
| Balance shows "Not checked" | Run `php artisan termii:balance` or press "Check balance" in Settings |
| 401/403 on the webhook | Signature mismatch: the secret must be identical here and in the Termii console; the endpoint returns 404 while disabled |
| Assets unstyled | `npm run build` was not run or `public/build` was not deployed; the installer's first step reports the missing manifest |
| `composer install` fails: `openspout/openspout … require ext-zip` or `your php version … does not satisfy` | The `zip` extension is disabled. Enable it (see below), then run `composer install` again. The committed lock file already resolves `openspout` to a version that supports PHP 8.4 |
| `npm ci` fails: `can only install with an existing package-lock.json` | You are on an older checkout that predates the committed lock file. Run `git pull`, or use `npm install` for that one checkout. |
| `npm ci` fails: lock file out of sync with `package.json` | Someone edited `package.json` without refreshing the lock. Run `npm install`, then commit `package-lock.json`. |
| `npm run build` fails: `EBADENGINE` / unsupported Node | Check `node -v`: Vite 6 needs Node 18, 20 or 22+. Node 20 LTS or newer is recommended. |
| Installer says `.env` or storage is not writable | Give the web server user write access to the application root, `storage/` and `bootstrap/cache/` (see the installation section) |
| Installer step 2 cannot create the MySQL database | The database user lacks `CREATE`; create the database manually (for example in phpMyAdmin) and enter its name again, or grant the privilege |
| `/install` redirects to the dashboard but the tables are missing | The installation marker exists: `rm storage/app/installation.json` and reload, or run `php artisan migrate --force` |
| The application still sees old values after installing | The installer clears `bootstrap/cache/config.php`; if you cache configuration yourself, run `php artisan config:clear` |

---

## License

MIT. See [`LICENSE`](LICENSE) for details. This application is an independent operations tool and is
not affiliated with Termii.
