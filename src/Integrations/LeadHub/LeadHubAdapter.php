<?php

namespace Goldnead\StatamicAutomations\Integrations\LeadHub;

/**
 * Thin adapter that delegates to the LeadHub addon's public API
 * if it is installed.
 *
 * The expected facade surface (loosely typed):
 *   - statuses(): iterable<{handle, label}>
 *   - tags(): iterable<{handle, label}>
 *   - find(string $id): ?Lead
 *   - findByEmail(string $email): ?Lead
 *   - create(array $attributes): Lead
 *   - update(string $id, array $attributes): Lead
 *   - addTag(string $id, string $tag): void
 *   - removeTag(string $id, string $tag): void
 *   - changeStatus(string $id, string $status): void
 *   - addNote(string $id, string $body): void
 *   - createFollowUp(string $id, array $data): mixed
 *   - completeFollowUp(string $id, ?string $followUpId = null): void
 */
class LeadHubAdapter
{
    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function statuses(): array
    {
        return $this->fetchOptions('statuses');
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function tags(): array
    {
        return $this->fetchOptions('tags');
    }

    public function findByEmail(string $email): ?array
    {
        $service = $this->resolve();
        if ($service === null || ! method_exists($service, 'findByEmail')) {
            return null;
        }

        try {
            $lead = $service::findByEmail($email);

            return $lead !== null ? $this->normalizeLead($lead) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function find(string $id): ?array
    {
        $service = $this->resolve();
        if ($service === null || ! method_exists($service, 'find')) {
            return null;
        }

        try {
            $lead = $service::find($id);

            return $lead !== null ? $this->normalizeLead($lead) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{ok: bool, lead?: array<string, mixed>, error?: string}
     */
    public function createOrUpdate(array $attributes): array
    {
        $service = $this->resolve();
        if ($service === null) {
            return ['ok' => false, 'error' => 'LeadHub not installed.'];
        }

        try {
            $email = $attributes['email'] ?? null;
            $existing = is_string($email) && $email !== ''
                ? $this->findByEmail($email)
                : null;

            if ($existing && method_exists($service, 'update')) {
                $updated = $service::update($existing['id'], $attributes);

                return ['ok' => true, 'lead' => $this->normalizeLead($updated), 'created' => false];
            }

            if (method_exists($service, 'create')) {
                $created = $service::create($attributes);

                return ['ok' => true, 'lead' => $this->normalizeLead($created), 'created' => true];
            }

            return ['ok' => false, 'error' => 'LeadHub facade does not implement create().'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function changeStatus(string $leadId, string $status): array
    {
        return $this->callMutation('changeStatus', [$leadId, $status]);
    }

    public function addTag(string $leadId, string $tag): array
    {
        return $this->callMutation('addTag', [$leadId, $tag]);
    }

    public function removeTag(string $leadId, string $tag): array
    {
        return $this->callMutation('removeTag', [$leadId, $tag]);
    }

    public function addNote(string $leadId, string $body): array
    {
        return $this->callMutation('addNote', [$leadId, $body]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createFollowUp(string $leadId, array $data): array
    {
        return $this->callMutation('createFollowUp', [$leadId, $data]);
    }

    public function completeFollowUp(string $leadId, ?string $followUpId = null): array
    {
        return $this->callMutation('completeFollowUp', [$leadId, $followUpId]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createTask(array $attributes, ?string $leadId = null): array
    {
        return $this->callMutation('createTask', [$attributes, $leadId]);
    }

    public function moveStage(string $opportunityId, string $stage, ?string $note = null): array
    {
        return $this->callMutation('moveStage', [$opportunityId, $stage, $note]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function upsertOpportunity(string $leadId, string $pipeline, array $attributes = []): array
    {
        return $this->callMutation('upsertOpportunity', [$leadId, $pipeline, $attributes]);
    }

    public function mergeContacts(string $loserId, string $winnerId): array
    {
        return $this->callMutation('merge', [$loserId, $winnerId]);
    }

    /**
     * @return class-string|null
     */
    protected function resolve(): ?string
    {
        $candidates = (array) config('automations.integrations.leadhub.facade', [
            // The addon's PSR-4 namespace is Goldnead\Leadhub (lowercase "hub").
            'Goldnead\\Leadhub\\Facades\\LeadHub',
            'Goldnead\\Leadhub\\LeadHubManager',
            'Goldnead\\LeadHub\\Facades\\LeadHub',
        ]);

        foreach ($candidates as $class) {
            if (is_string($class) && class_exists($class)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    protected function fetchOptions(string $method): array
    {
        $service = $this->resolve();
        if ($service === null || ! method_exists($service, $method)) {
            return [];
        }

        try {
            $raw = $service::$method();
            $items = is_iterable($raw) ? $raw : [];
            $out = [];

            foreach ($items as $entry) {
                if (is_array($entry)) {
                    $value = $entry['handle'] ?? $entry['value'] ?? null;
                    $label = $entry['label'] ?? $entry['name'] ?? $value;
                } elseif (is_object($entry)) {
                    $value = method_exists($entry, 'handle') ? $entry->handle() : ($entry->handle ?? null);
                    $label = method_exists($entry, 'label') ? $entry->label() : ($entry->label ?? $value);
                } else {
                    $value = $entry;
                    $label = $entry;
                }

                if ($value !== null) {
                    $out[] = ['value' => (string) $value, 'label' => (string) $label];
                }
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param  array<int, mixed>  $args
     * @return array{ok: bool, error?: string, result?: mixed}
     */
    protected function callMutation(string $method, array $args): array
    {
        $service = $this->resolve();
        if ($service === null) {
            return ['ok' => false, 'error' => 'LeadHub not installed.'];
        }

        if (! method_exists($service, $method)) {
            return ['ok' => false, 'error' => "LeadHub facade does not implement {$method}()."];
        }

        try {
            $result = $service::$method(...$args);

            return ['ok' => true, 'result' => $result];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeLead(mixed $lead): array
    {
        if (is_array($lead)) {
            return $lead;
        }

        if (is_object($lead)) {
            // Attempt common accessors first.
            $array = method_exists($lead, 'toArray') ? $lead->toArray() : [];
            if (! is_array($array)) {
                $array = [];
            }

            $array['id'] ??= method_exists($lead, 'id') ? $lead->id() : ($lead->id ?? null);
            $array['email'] ??= method_exists($lead, 'email') ? $lead->email() : ($lead->email ?? null);
            $array['status'] ??= method_exists($lead, 'status') ? $lead->status() : ($lead->status ?? null);

            return $array;
        }

        return [];
    }
}
