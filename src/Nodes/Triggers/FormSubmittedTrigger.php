<?php

namespace Goldnead\StatamicAutomations\Nodes\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;

class FormSubmittedTrigger implements AutomationTrigger
{
    public static function handle(): string
    {
        return 'form_submitted';
    }

    public static function label(): string
    {
        return 'Form Submitted';
    }

    public static function description(): ?string
    {
        return 'Triggered when a Statamic form receives a submission.';
    }

    public static function group(): string
    {
        return 'Statamic';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [
            [
                'handle' => 'form_handle',
                'label' => 'Form',
                'type' => 'select',
                'options_source' => 'statamic.forms',
                'required' => false,
                'help' => 'Leave empty to trigger on any form.',
            ],
            [
                'handle' => 'site',
                'label' => 'Site',
                'type' => 'select',
                'options_source' => 'statamic.sites',
                'required' => false,
            ],
            [
                'handle' => 'include_attachments',
                'label' => 'Include attachments',
                'type' => 'select',
                'options' => ['link_only', 'no'],
                'default' => 'link_only',
            ],
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'form' => [
                'handle' => 'string',
                'title' => 'string',
            ],
            'submission' => [
                'id' => 'string',
                'created_at' => 'datetime',
                'data' => 'array',
            ],
            'site' => ['handle' => 'string'],
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        $formHandle = $config['form_handle'] ?? null;
        $expectedSite = $config['site'] ?? null;

        $eventForm = $this->extractFormHandle($event);
        $eventSite = $this->extractSiteHandle($event);

        if ($formHandle && $eventForm && $formHandle !== $eventForm) {
            return false;
        }

        if ($expectedSite && $eventSite && $expectedSite !== $eventSite) {
            return false;
        }

        return true;
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        $submission = $this->extractSubmission($event);
        $form = $this->extractForm($event);

        $data = [
            'form' => [
                'handle' => $form['handle'] ?? null,
                'title' => $form['title'] ?? null,
            ],
            'submission' => [
                'id' => $submission['id'] ?? null,
                'created_at' => $submission['created_at'] ?? now()->toIso8601String(),
                'data' => $submission['data'] ?? [],
            ],
            'site' => [
                'handle' => $this->extractSiteHandle($event) ?? 'default',
            ],
        ];

        // Flatten submission data so authors can write {{ form.email }}.
        if (! empty($data['submission']['data']) && is_array($data['submission']['data'])) {
            foreach ($data['submission']['data'] as $key => $value) {
                if (! isset($data['form'][$key])) {
                    $data['form'][$key] = $value;
                }
            }
        }

        return AutomationContext::make($data);
    }

    protected function extractFormHandle(object|array $event): ?string
    {
        if (is_array($event)) {
            return $event['form_handle'] ?? $event['form']['handle'] ?? null;
        }

        if (isset($event->submission)) {
            $submission = $event->submission;
            if (is_object($submission) && method_exists($submission, 'form')) {
                $form = $submission->form();
                if (is_object($form) && method_exists($form, 'handle')) {
                    return $form->handle();
                }
            }
        }

        return $event->form_handle ?? null;
    }

    protected function extractSiteHandle(object|array $event): ?string
    {
        if (is_array($event)) {
            return $event['site_handle'] ?? $event['site']['handle'] ?? null;
        }

        if (isset($event->site_handle)) {
            return $event->site_handle;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extractSubmission(object|array $event): array
    {
        if (is_array($event)) {
            return $event['submission'] ?? [];
        }

        $submission = $event->submission ?? null;
        if (is_object($submission)) {
            return [
                'id' => method_exists($submission, 'id') ? $submission->id() : ($submission->id ?? null),
                'created_at' => method_exists($submission, 'date')
                    ? (string) $submission->date()
                    : now()->toIso8601String(),
                'data' => method_exists($submission, 'data')
                    ? (is_object($submission->data()) && method_exists($submission->data(), 'all')
                        ? $submission->data()->all()
                        : (array) $submission->data())
                    : [],
            ];
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function extractForm(object|array $event): array
    {
        if (is_array($event)) {
            return $event['form'] ?? [];
        }

        $submission = $event->submission ?? null;
        if (is_object($submission) && method_exists($submission, 'form')) {
            $form = $submission->form();
            if (is_object($form)) {
                return [
                    'handle' => method_exists($form, 'handle') ? $form->handle() : null,
                    'title' => method_exists($form, 'title') ? $form->title() : null,
                ];
            }
        }

        return [];
    }
}
