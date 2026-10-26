<?php

namespace App\Services\Sms;

use Illuminate\Support\Collection;

/**
 * Server-side rendering of personalised campaign messages.
 *
 * Supported placeholders: {{name}}, {{phone}}, {{email}} and
 * {{custom.<field>}} for approved custom contact fields.
 */
class MessageRenderer
{
    public const PLACEHOLDER_PATTERN = '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/';

    /**
     * Validate the template syntax and placeholders.
     *
     * @param  array<int, string>  $approvedCustomFields
     * @return array<int, string> validation error messages
     */
    public function validate(string $template, array $approvedCustomFields = []): array
    {
        $errors = [];

        if (trim($template) === '') {
            return ['The message cannot be empty.'];
        }

        $approved = array_map(
            fn ($field) => 'custom.'.strtolower(trim((string) $field)),
            $approvedCustomFields
        );

        preg_match_all(self::PLACEHOLDER_PATTERN, $template, $matches);

        foreach (array_unique($matches[1] ?? []) as $placeholder) {
            $normalized = strtolower($placeholder);

            $allowed = in_array($normalized, ['name', 'phone', 'email'], true)
                || in_array($normalized, $approved, true);

            if (! $allowed) {
                $errors[] = sprintf(
                    'The placeholder {{%s}} is not supported. Use {{name}}, {{phone}}, {{email}} or an approved custom field.',
                    $placeholder
                );
            }
        }

        // Detect malformed braces left over after removing valid placeholders.
        $stripped = preg_replace(self::PLACEHOLDER_PATTERN, '', $template) ?? '';

        if (str_contains($stripped, '{{') || str_contains($stripped, '}}')) {
            $errors[] = 'The message contains malformed placeholders. Placeholders must look like {{name}}.';
        }

        return $errors;
    }

    public function containsPlaceholders(string $template): bool
    {
        return preg_match(self::PLACEHOLDER_PATTERN, $template) === 1;
    }

    /**
     * @return array<int, string>
     */
    public function placeholders(string $template): array
    {
        preg_match_all(self::PLACEHOLDER_PATTERN, $template, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * Render the template for a recipient context.
     *
     * @param  array{name?: string|null, phone?: string|null, email?: string|null, custom_fields?: array<string, mixed>|null}  $recipient
     */
    public function render(string $template, array $recipient): string
    {
        $customFields = $recipient['custom_fields'] ?? [];

        if (! is_array($customFields)) {
            $customFields = [];
        }

        return preg_replace_callback(
            self::PLACEHOLDER_PATTERN,
            function (array $match) use ($recipient, $customFields): string {
                $placeholder = strtolower($match[1]);

                if ($placeholder === 'name') {
                    return (string) ($recipient['name'] ?? '');
                }

                if ($placeholder === 'phone') {
                    return (string) ($recipient['phone'] ?? '');
                }

                if ($placeholder === 'email') {
                    return (string) ($recipient['email'] ?? '');
                }

                if (str_starts_with($placeholder, 'custom.')) {
                    $key = substr($placeholder, 7);
                    $value = $customFields[$key] ?? null;

                    return is_scalar($value) ? (string) $value : '';
                }

                return '';
            },
            $template
        ) ?? $template;
    }

    /**
     * Build a preview using sample values for every supported placeholder.
     */
    public function sample(string $template, array $customFields = []): string
    {
        $context = [
            'name' => 'Ada Okafor',
            'phone' => '2348031234567',
            'email' => 'ada@example.com',
            'custom_fields' => [],
        ];

        foreach ($customFields as $field) {
            $context['custom_fields'][strtolower((string) $field)] = ucfirst((string) $field).' sample';
        }

        return $this->render($template, $context);
    }

    /**
     * Total estimated units for a set of rendered messages (personalisation
     * aware: each recipient may have a different rendered length).
     *
     * @param  Collection<int, string>  $renderedMessages
     */
    public function estimateUnits(Collection $renderedMessages, SegmentCalculator $calculator): int
    {
        return $renderedMessages->sum(fn (string $message) => $calculator->calculate($message)->units);
    }
}
