<?php

namespace App\Livewire\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\Permission;
use App\Exceptions\CampaignPreflightException;
use App\Exceptions\CampaignStateException;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\ProviderStatus;
use App\Services\Audit\AuditLogger;
use App\Services\Campaign\CampaignService;
use App\Services\Contacts\ContactService;
use App\Services\Phone\PhoneNumberService;
use App\Services\Sms\MessageRenderer;
use App\Services\Sms\SegmentCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Three-step campaign builder: compose -> recipients -> review & confirm.
 * Nothing is sent from this component; confirmation hands over to
 * CampaignService, which re-resolves the audience and guards duplicates.
 */
#[Layout('layouts.app')]
#[Title('Campaign builder')]
class Wizard extends Component
{
    public ?int $campaignId = null;

    public int $step = 1;

    // Step 1: compose
    public string $name = '';

    public string $message = '';

    public string $sender_id = '';

    public string $channel = 'generic';

    public bool $schedule = false;

    public string $scheduled_at = '';

    // Step 2: audience
    public string $audienceMode = 'groups';

    /** @var array<int, int|string> */
    public array $groupIds = [];

    /** @var array<int, int|string> */
    public array $contactIds = [];

    public string $contactSearch = '';

    public string $bulkNumbers = '';

    // Step 3: review
    public bool $confirmed = false;

    /** @var array<string, mixed> */
    public array $review = [];

    /** @var array<int, array<string, mixed>> */
    public array $sampleRecipients = [];

    public function mount(?Campaign $campaign = null): void
    {
        $this->sender_id = settings()->defaultSenderId() ?? '';

        if ($campaign instanceof Campaign && $campaign->exists) {
            Gate::authorize('update', $campaign);

            $this->campaignId = $campaign->id;
            $this->name = $campaign->name;
            $this->message = $campaign->message;
            $this->sender_id = $campaign->sender_id;
            $this->channel = $campaign->channel;
            $this->schedule = $campaign->scheduled_at !== null;
            $this->scheduled_at = $campaign->scheduled_at?->copy()
                ->setTimezone(settings()->timezone())
                ->format('Y-m-d\TH:i') ?? '';
            $this->groupIds = $campaign->audience['group_ids'] ?? [];
            $this->contactIds = $campaign->audience['contact_ids'] ?? [];
            $this->audienceMode = $this->groupIds !== [] ? 'groups' : 'contacts';
        }

        if ($this->sender_id === '' && settings()->approvedSenderIds() !== []) {
            $this->sender_id = settings()->approvedSenderIds()[0];
        }
    }

    public function nextStep(): void
    {
        if ($this->step === 1) {
            $this->validateComposition();

            try {
                $campaign = $this->persistDraft();
            } catch (ValidationException $e) {
                $this->addError('message', collect($e->errors())->flatten()->first() ?? 'The campaign could not be saved.');

                return;
            }

            $this->campaignId = $campaign->id;
            $this->step = 2;
            $this->recalculate();

            return;
        }

        if ($this->step === 2) {
            $this->goToReview();
        }
    }

    public function previousStep(): void
    {
        $this->step = max(1, $this->step - 1);
        $this->confirmed = false;
        $this->resetErrorBag();
    }

    public function updatedGroupIds(): void
    {
        $this->recalculateIfOnAudienceStep();
    }

    public function updatedContactIds(): void
    {
        $this->recalculateIfOnAudienceStep();
    }

    public function updatedMessage(): void
    {
        if ($this->step === 3) {
            $this->step = 2;
            $this->confirmed = false;
        }
    }

    public function saveDraft(): void
    {
        try {
            $campaign = $this->persistDraft();
        } catch (ValidationException $e) {
            $this->addErrorsFrom($e);

            return;
        }

        $this->campaignId = $campaign->id;
        $this->dispatch('notify', message: 'Draft campaign saved.', type: 'success');
        $this->redirectRoute('campaigns.show', ['campaign' => $campaign->id]);
    }

    public function review(): void
    {
        $this->goToReview();
    }

    public function backToAudience(): void
    {
        $this->step = 2;
        $this->confirmed = false;
    }

    public function addNumbers(
        ContactService $contacts,
        PhoneNumberService $phones,
        AuditLogger $audit,
    ): void {
        Gate::authorize('import-contacts');

        $tokens = array_values(array_filter(array_map(
            'trim',
            preg_split('/[\s,;]+/', $this->bulkNumbers) ?: []
        )));

        if ($tokens === []) {
            $this->addError('bulkNumbers', 'Paste at least one phone number.');

            return;
        }

        if (count($tokens) > 200) {
            $this->addError('bulkNumbers', 'Add at most 200 numbers at a time.');

            return;
        }

        $added = 0;
        $created = 0;
        $invalid = [];

        foreach ($tokens as $token) {
            $normalized = $phones->tryNormalize($token, settings()->defaultCountry());

            if ($normalized === null) {
                $invalid[] = $token;

                continue;
            }

            $contact = Contact::query()->where('phone', $normalized)->first();

            if ($contact === null) {
                try {
                    $contact = $contacts->create(['phone' => $normalized, 'name' => null], auth()->user());
                    $created++;
                } catch (ValidationException) {
                    continue;
                }
            }

            if (! in_array($contact->id, array_map('intval', $this->contactIds), true)) {
                $this->contactIds[] = $contact->id;
                $added++;
            }
        }

        $this->bulkNumbers = '';
        $this->audienceMode = 'contacts';
        $this->recalculate();

        $audit->log('campaign.recipients_added', $this->campaign(), sprintf(
            'Added %d number(s) to a campaign audience (%d new contacts).',
            $added,
            $created,
        ), [
            'added' => $added,
            'created' => $created,
            'invalid' => count($invalid),
        ]);

        $messageParts = [sprintf('%d number(s) added', $added)];

        if ($created > 0) {
            $messageParts[] = sprintf('%d new contact(s) created', $created);
        }

        if ($invalid !== []) {
            $messageParts[] = sprintf('%d invalid: %s', count($invalid), implode(', ', array_slice($invalid, 0, 3)));
        }

        $this->dispatch(
            'notify',
            message: implode(' · ', $messageParts).'.',
            type: $invalid === [] ? 'success' : 'warning',
        );
    }

    public function submitForReview(AuditLogger $audit): void
    {
        try {
            $campaign = $this->persistDraft();
        } catch (ValidationException $e) {
            $this->addErrorsFrom($e);

            return;
        }

        if ($campaign->status === CampaignStatus::Draft) {
            $campaign->forceFill(['status' => CampaignStatus::NeedsReview])->save();
            $audit->log('campaign.submitted_for_review', $campaign, sprintf(
                'Submitted campaign "%s" for authorisation.',
                $campaign->name,
            ));
        }

        $this->dispatch('notify', message: 'Campaign submitted for authorisation.', type: 'success');
        $this->redirectRoute('campaigns.show', ['campaign' => $campaign->id]);
    }

    public function confirmSend(CampaignService $campaigns): void
    {
        $validator = Validator::make(['confirmed' => $this->confirmed], [
            'confirmed' => ['accepted'],
        ], ['confirmed.accepted' => 'Tick the confirmation box to acknowledge that this will send real SMS messages.']);

        if ($validator->fails()) {
            $this->addError('confirmed', $validator->errors()->first('confirmed'));

            return;
        }

        try {
            $campaign = $this->persistDraft();
        } catch (ValidationException $e) {
            $this->addErrorsFrom($e);

            return;
        }

        Gate::authorize('confirm', $campaign);

        $key = 'campaign-confirm:'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->addError('confirmed', 'Too many confirmation attempts. Please wait a minute and try again.');

            return;
        }

        RateLimiter::hit($key, 60);

        try {
            $campaign = $campaigns->confirm($campaign, auth()->user());
        } catch (CampaignStateException|CampaignPreflightException $e) {
            $this->addError('confirmed', $e->getMessage());

            return;
        } catch (ValidationException $e) {
            $this->addErrorsFrom($e);

            return;
        }

        $message = $campaign->status === CampaignStatus::Scheduled
            ? 'Campaign scheduled. It will be queued automatically at the chosen time.'
            : 'Campaign confirmed and queued. Delivery reports will appear as they arrive.';

        $this->dispatch('notify', message: $message, type: 'success');
        $this->redirectRoute('campaigns.show', ['campaign' => $campaign->id]);
    }

    public function render(): View
    {
        $messagePreview = app(MessageRenderer::class)->sample($this->message, settings()->customFields());
        $segments = app(SegmentCalculator::class)->calculate($messagePreview);

        $contactResults = collect();

        if ($this->step === 2 && $this->audienceMode === 'contacts') {
            $contactResults = Contact::query()
                ->search($this->contactSearch)
                ->orderByRaw('coalesce(name, phone) asc')
                ->limit(25)
                ->get(['id', 'name', 'phone', 'subscription_status']);
        }

        return view('livewire.campaigns.wizard', [
            'approvedSenderIds' => settings()->approvedSenderIds(),
            'groups' => ContactGroup::query()
                ->withCount([
                    'contacts',
                    'subscribedContacts as subscribed_count',
                ])
                ->orderBy('name')
                ->get(),
            'contactResults' => $contactResults,
            'previewText' => $messagePreview,
            'previewSegments' => $segments,
            'providerStatus' => ProviderStatus::termii(),
            'currentTimezone' => settings()->timezone(),
            'maxSegments' => settings()->maxSegments(),
            'canSend' => auth()->user()?->hasPermission(Permission::SendCampaigns) ?? false,
            'selectedContactTotal' => count($this->contactIds),
            'campaign' => $this->campaign(),
        ]);
    }

    private function goToReview(): void
    {
        try {
            $this->validateComposition();
        } catch (ValidationException) {
            $this->step = 1;

            return;
        }

        if ($this->groupIds === [] && $this->contactIds === []) {
            $this->addError('audience', 'Select at least one contact group or add contacts.');

            return;
        }

        try {
            $campaign = $this->persistDraft();
        } catch (ValidationException $e) {
            $this->addErrorsFrom($e);

            return;
        }

        $campaigns = app(CampaignService::class);
        $plan = $campaigns->plan($campaign);

        if ($plan->count() === 0) {
            $this->addError('audience', 'No eligible recipients remain. Unsubscribed, suppressed and duplicate numbers are excluded.');

            return;
        }

        $this->review = $campaigns->estimate($campaign, $plan);
        $renderer = app(MessageRenderer::class);
        $calculator = app(SegmentCalculator::class);

        $this->sampleRecipients = collect($plan->recipients)
            ->take(3)
            ->map(function (array $recipient) use ($campaign, $renderer, $calculator): array {
                $rendered = $renderer->render($campaign->message, $recipient);

                return [
                    'name' => $recipient['name'] ?? '',
                    'phone' => $recipient['phone'],
                    'message' => $rendered,
                    'segments' => $calculator->calculate($rendered)->segments,
                ];
            })
            ->all();

        $this->confirmed = false;
        $this->step = 3;
    }

    private function recalculateIfOnAudienceStep(): void
    {
        if ($this->step !== 2) {
            return;
        }

        $this->recalculate();
    }

    private function recalculate(): void
    {
        if ($this->groupIds === [] && $this->contactIds === []) {
            $this->review = [];

            return;
        }

        $dummy = new Campaign([
            'message' => $this->message,
            'audience' => [
                'contact_ids' => array_map('intval', $this->contactIds),
                'group_ids' => array_map('intval', $this->groupIds),
            ],
        ]);
        $service = app(CampaignService::class);
        $plan = $service->plan($dummy);

        $this->review = $service->estimate($dummy, $plan);
    }

    private function validateComposition(): void
    {
        $approved = settings()->approvedSenderIds();

        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string', 'max:'.settings()->maxMessageLength()],
            'sender_id' => ['required', 'string', Rule::in($approved)],
            'channel' => ['required', Rule::in(['generic', 'dnd'])],
            'scheduled_at' => ['nullable', 'string', 'max:30'],
            'schedule' => ['boolean'],
        ]);

        $templateErrors = app(MessageRenderer::class)->validate($data['message'], settings()->customFields());

        if ($templateErrors !== []) {
            throw ValidationException::withMessages(['message' => $templateErrors[0]]);
        }

        if ($this->schedule) {
            if (trim($this->scheduled_at) === '') {
                throw ValidationException::withMessages(['scheduled_at' => 'Choose the date and time to send this campaign.']);
            }

            try {
                $scheduled = Carbon::parse($this->scheduled_at, settings()->timezone());
            } catch (\Throwable) {
                throw ValidationException::withMessages(['scheduled_at' => 'The scheduled date is not valid.']);
            }

            if ($scheduled->isPast()) {
                throw ValidationException::withMessages(['scheduled_at' => 'The scheduled date must be in the future.']);
            }
        }
    }

    private function persistDraft(): Campaign
    {
        $service = app(CampaignService::class);

        $scheduledAt = null;

        if ($this->schedule && trim($this->scheduled_at) !== '') {
            $scheduledAt = Carbon::parse($this->scheduled_at, settings()->timezone())
                ->setTimezone((string) config('app.timezone'));
        }

        $payload = [
            'name' => $this->name,
            'message' => $this->message,
            'sender_id' => $this->sender_id,
            'channel' => $this->channel,
            'audience' => [
                'contact_ids' => array_map('intval', $this->contactIds),
                'group_ids' => array_map('intval', $this->groupIds),
            ],
            'scheduled_at' => $scheduledAt,
        ];

        if ($this->campaignId !== null) {
            $campaign = Campaign::query()->findOrFail($this->campaignId);

            if (! $campaign->status->isEditable()) {
                throw ValidationException::withMessages([
                    'name' => 'This campaign can no longer be edited because it has already been confirmed.',
                ]);
            }

            Gate::authorize('update', $campaign);

            return $service->update($campaign, auth()->user(), $payload);
        }

        Gate::authorize('create', Campaign::class);

        return $service->create(auth()->user(), $payload);
    }

    private function campaign(): ?Campaign
    {
        return $this->campaignId !== null
            ? Campaign::query()->find($this->campaignId)
            : null;
    }

    private function addErrorsFrom(ValidationException $e): void
    {
        foreach ($e->errors() as $field => $messages) {
            foreach ($messages as $message) {
                $this->addError($field, $message);
            }
        }
    }
}
