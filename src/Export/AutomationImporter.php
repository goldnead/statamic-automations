<?php

namespace Goldnead\StatamicAutomations\Export;

use Goldnead\StatamicAutomations\Integrations\IntegrationDetector;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Imports a previously-exported automation JSON document.
 *
 * Validation happens up front (schema version, required keys, node-type
 * existence). Missing-integration warnings are returned alongside the
 * created automation so the caller can surface them in the UI.
 *
 * Behavior:
 *   - imports always create a new automation (we do not overwrite)
 *   - automations are imported in the disabled state by default
 *   - handle conflicts are auto-resolved by appending a short suffix
 *     (caller can opt out via $options['handle_strategy'] = 'fail')
 */
class AutomationImporter
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        protected NodeRegistry $registry,
        protected IntegrationDetector $detector,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $options
     * @return array{automation: Automation, warnings: array<int, string>, missing_integrations: array<int, string>, missing_node_types: array<int, string>}
     */
    public function import(array $payload, array $options = []): array
    {
        $this->validateShape($payload);

        $warnings = [];
        $missingIntegrations = $this->checkIntegrations($payload['requires'] ?? []);
        $missingNodeTypes = $this->checkNodeTypes($payload['nodes'] ?? []);

        if (! empty($missingIntegrations)) {
            $warnings[] = 'Missing integrations: ' . implode(', ', $missingIntegrations)
                . '. The automation will import but may not pass validation until those addons are installed.';
        }
        if (! empty($missingNodeTypes)) {
            $warnings[] = 'Unknown node types: ' . implode(', ', $missingNodeTypes)
                . '. They will be imported as-is and need to be replaced before activation.';
        }

        $handle = $this->resolveHandle($payload['automation']['handle'] ?? null, $options);

        $automation = DB::transaction(function () use ($payload, $handle) {
            $automation = Automation::create([
                'name' => $payload['automation']['name'] ?? 'Imported automation',
                'handle' => $handle,
                'description' => $payload['automation']['description'] ?? null,
                'enabled' => false,
                'created_by' => optional(auth()->user())->id,
            ]);

            foreach (($payload['nodes'] ?? []) as $node) {
                AutomationNode::create([
                    'automation_id' => $automation->id,
                    'node_key' => $node['node_key'],
                    'type' => $node['type'],
                    'label' => $node['label'] ?? null,
                    'position_x' => (int) ($node['position_x'] ?? 0),
                    'position_y' => (int) ($node['position_y'] ?? 0),
                    'config' => $node['config'] ?? [],
                    'disabled' => (bool) ($node['disabled'] ?? false),
                ]);
            }

            foreach (($payload['edges'] ?? []) as $edge) {
                AutomationEdge::create([
                    'automation_id' => $automation->id,
                    'from_node_key' => $edge['from_node_key'],
                    'from_output' => $edge['from_output'] ?? 'default',
                    'to_node_key' => $edge['to_node_key'],
                    'to_input' => $edge['to_input'] ?? 'default',
                ]);
            }

            return $automation;
        });

        return [
            'automation' => $automation->fresh(['nodes', 'edges']),
            'warnings' => $warnings,
            'missing_integrations' => $missingIntegrations,
            'missing_node_types' => $missingNodeTypes,
        ];
    }

    /**
     * Validate the bare structural shape of the export payload.
     */
    protected function validateShape(array $payload): void
    {
        if (! isset($payload['schema_version'])) {
            throw new InvalidArgumentException('Missing "schema_version" key.');
        }

        if ((int) $payload['schema_version'] !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported schema version %s (expected %d).',
                $payload['schema_version'],
                self::SCHEMA_VERSION,
            ));
        }

        if (! isset($payload['automation']) || ! is_array($payload['automation'])) {
            throw new InvalidArgumentException('Missing "automation" object.');
        }

        if (empty($payload['automation']['name'])) {
            throw new InvalidArgumentException('Automation must have a name.');
        }

        if (! isset($payload['nodes']) || ! is_array($payload['nodes'])) {
            throw new InvalidArgumentException('Missing "nodes" array.');
        }

        if (! isset($payload['edges']) || ! is_array($payload['edges'])) {
            throw new InvalidArgumentException('Missing "edges" array.');
        }

        $nodeKeys = [];
        foreach ($payload['nodes'] as $node) {
            if (! is_array($node) || empty($node['node_key']) || empty($node['type'])) {
                throw new InvalidArgumentException('Every node needs a "node_key" and "type".');
            }
            if (in_array($node['node_key'], $nodeKeys, true)) {
                throw new InvalidArgumentException("Duplicate node_key '{$node['node_key']}' in import.");
            }
            $nodeKeys[] = $node['node_key'];
        }

        foreach ($payload['edges'] as $edge) {
            if (! is_array($edge) || empty($edge['from_node_key']) || empty($edge['to_node_key'])) {
                throw new InvalidArgumentException('Every edge needs "from_node_key" and "to_node_key".');
            }
            if (! in_array($edge['from_node_key'], $nodeKeys, true)) {
                throw new InvalidArgumentException("Edge references missing node '{$edge['from_node_key']}'.");
            }
            if (! in_array($edge['to_node_key'], $nodeKeys, true)) {
                throw new InvalidArgumentException("Edge references missing node '{$edge['to_node_key']}'.");
            }
        }
    }

    /**
     * @param  array<int, string>  $requires
     * @return array<int, string>
     */
    protected function checkIntegrations(array $requires): array
    {
        $missing = [];
        foreach ($requires as $integration) {
            if ($integration === 'webhook_manager' && ! $this->detector->hasWebhookManager()) {
                $missing[] = 'webhook_manager';
            }
            if ($integration === 'leadhub' && ! $this->detector->hasLeadHub()) {
                $missing[] = 'leadhub';
            }
        }
        return $missing;
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @return array<int, string>
     */
    protected function checkNodeTypes(array $nodes): array
    {
        $missing = [];
        foreach ($nodes as $node) {
            if (! $this->registry->has((string) ($node['type'] ?? ''))) {
                $missing[] = (string) $node['type'];
            }
        }
        return array_values(array_unique($missing));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function resolveHandle(?string $candidate, array $options): string
    {
        $strategy = $options['handle_strategy'] ?? 'auto';
        $candidate = $candidate ?: 'imported-automation';
        $candidate = Str::slug($candidate);

        if (! Automation::where('handle', $candidate)->exists()) {
            return $candidate;
        }

        if ($strategy === 'fail') {
            throw new InvalidArgumentException("Handle '{$candidate}' is already in use.");
        }

        // Auto strategy → suffix until unique.
        for ($i = 0; $i < 20; $i++) {
            $candidate2 = $candidate . '-' . Str::lower(Str::random(4));
            if (! Automation::where('handle', $candidate2)->exists()) {
                return $candidate2;
            }
        }

        throw new \RuntimeException('Failed to find a unique handle after 20 attempts.');
    }
}
