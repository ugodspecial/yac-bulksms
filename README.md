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
3. [Architecture](#architecture)
4. [Installation](#installation)
5. [First sign-in and roles](#first-sign-in-and-roles)
6. [Configuration reference](#configuration-reference)
7. [Contacts, groups and imports](#contacts-groups-and-imports)
8. [Campaigns and the send pipeline](#campaigns-and-the-send-pipeline)
9. [Personalisation and SMS segment estimation](#personalisation-and-sms-segment-estimation)
10. [Delivery reports](#delivery-reports)
11. [Queues and workers](#queues-and-workers)
12. [Scheduler](#scheduler)
13. [Console commands](#console-commands)
14. [Audit logging and exports](#audit-logging-and-exports)
15. [Tests](#tests)
16. [Security notes](#security-notes)
17. [Deployment checklist](#deployment-checklist)
18. [Limitations and provider-dependent behaviour](#limitations-and-provider-dependent-behaviour)
19. [Troubleshooting](#troubleshooting)

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

* PHP **8.2+** (8.3 recommended) with `mbstring`, `openssl`, `pdo`, `tokenizer`, `xml`, `ctype`,
  `json`, `bcmath`, `fileinfo`, `intl` (recommended) and either `pdo_mysql` or `pdo_sqlite`.
* MySQL 8 / MariaDB 10.6+ (SQLite is supported and used by the automated tests).
* Composer 2.
* Node.js 20+ and npm (to build the Tailwind/Vite assets).
* A queue worker process and a scheduler cron entry (see below).
* A Termii account with at least one approved sender ID.

---

## Architecture

```
app/
├── Enums/                     Campaign, recipient, import, provider and role enums
├── Exceptions/                Campaign state/preflight and provider-temporary exceptions
├── Http/
│   ├── Controllers/           CSV exports, import reports, logout, Termii webhook
│   └── Middleware/            Active-account and forced-password-change guards
├── Jobs/                      Dispatch, batch send, import validation/commit, reconciliation
├── Livewire/                  Screen components (dashboard, contacts, campaigns, …)
├── Models/                    Eloquent models
├── Policies/                  Per-model authorisation (contact, group, campaign, import, user)
├── Providers/AppServiceProvider.php
└── Services/
    ├── Audit/                 AuditLogger (best-effort, never breaks the operation)
    ├── Campaign/              RecipientResolver, CampaignService, CampaignProgress
    ├── Contacts/              ContactService, import parser/processor, committer
    ├── Phone/                 libphonenumber wrapper (E.164 digits)
    ├── Provider/              ProviderHealthService (balance, sender IDs, last error)
    ├── Sms/                   SegmentCalculator, MessageRenderer
    ├── Settings/              Typed settings service (database-backed, cached)
    └── Termii/                TermiiGateway + DTOs behind TermiiGatewayInterface
```

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

```bash
git clone https://github.com/ugodspecial/yac-bulksms.git
cd yac-bulksms

composer install
cp .env.example .env
php artisan key:generate

# Create the database, then configure DB_* in .env
#   CREATE DATABASE yac_bulksms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

php artisan migrate --seed

npm ci
npm run build

php artisan storage:link

# Start a worker and the scheduler (see the dedicated sections)
php artisan queue:work --tries=3 --timeout=300
php artisan schedule:work        # development only
```

`php artisan migrate --seed` runs two seeders:

* `SettingsSeeder` — writes default application settings (safe to re-run; existing values win).
* `AdminUserSeeder` — creates the first administrator from `ADMIN_NAME`, `ADMIN_EMAIL` and
  `ADMIN_PASSWORD`. If `ADMIN_PASSWORD` is empty a random password is generated and printed **once**
  by the seeder; there is deliberately no well-known default password.

**No demo contacts, campaigns or messages are seeded.** All statistics must reflect real activity.

Set `TERMII_API_KEY`, `TERMII_SENDER_ID` and (for delivery reports) `TERMII_WEBHOOK_SECRET` before
sending anything. The application refuses to confirm a campaign while Termii is not configured.

For local development you can run everything without a real Termii key: create contacts, import
files, build campaigns and open the review screen. Only confirmation requires a configured provider.

### Development server

```bash
php artisan serve
npm run dev        # Vite dev server with hot reload
php artisan queue:work
```

---

## First sign-in and roles

Sign in with the credentials from `ADMIN_EMAIL` / `ADMIN_PASSWORD` (or the generated password printed
by the seeder). You will be asked to change the password because the seeded account has
`must_change_password = true`.

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

No test sends a real SMS or calls Termii: the interface is replaced by `Tests\Support\FakeTermiiGateway`,
and the adapter itself is only exercised against `Http::fake()`.

CI (`.github/workflows/ci.yml`) runs the suite on PHP 8.3, compiles Blade views and builds the frontend
assets on every push and pull request.

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
* All database access goes through Eloquent's parameterised queries.
* CSV exports are written as-is (they are **not** protected against spreadsheet formula injection).
  Contact data is entered by authenticated operators, but if you import files from untrusted sources,
  review exported CSVs before opening them in Excel — see the limitations section.

---

## Deployment checklist

1. `git pull` / deploy the release.
2. `composer install --no-dev --optimize-autoloader`.
3. `npm ci && npm run build` (or build assets in CI and ship `public/build`).
4. Set production `.env` values (`APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, database, Termii
   credentials, webhook secret).
5. `php artisan migrate --force`.
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
| Assets unstyled | `npm run build` was not run or `public/build` was not deployed |

---

## License

MIT. See [`LICENSE`](LICENSE) for details. This application is an independent operations tool and is
not affiliated with Termii.
