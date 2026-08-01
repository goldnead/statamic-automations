<?php

namespace Goldnead\StatamicAutomations\Export;

use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;

/**
 * Serializes an Automation into a portable JSON document.
 *
 * The export format is intentionally explicit: it tracks the schema
 * version, lists the integrations the automation depends on, and
 * stores the full nodes / edges graph. The result is suitable for
 * version-control, sharing in starter kits or seeding test data.
 */
class AutomationExporter
{
    public const SCHEMA_VERSION = 1;

    public function __construct(protected NodeRegistry $registry) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(Automation $automation): array
    {
        $automation = $automation->loadMissing(['nodes', 'edges']);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'exported_at' => now()->toIso8601String(),
            'automation' => [
                'name' => $automation->name,
                'handle' => $automation->handle,
                'description' => $automation->description,
            ],
            'requires' => $this->detectRequirements($automation),
            'nodes' => $automation->nodes
                ->sortBy('id')
                ->values()
                ->map(fn ($node) => [
                    'node_key' => $node->node_key,
                    'type' => $node->type,
                    'label' => $node->label,
                    'position_x' => (int) $node->position_x,
                    'position_y' => (int) $node->position_y,
                    'config' => $node->config ?? [],
                    'disabled' => (bool) $node->disabled,
                ])
                ->all(),
            'edges' => $automation->edges
                ->sortBy('id')
                ->values()
                ->map(fn ($edge) => [
                    'from_node_key' => $edge->from_node_key,
                    'from_output' => $edge->from_output,
                    'to_node_key' => $edge->to_node_key,
                    'to_input' => $edge->to_input,
                ])
                ->all(),
        ];
    }

    public function toJson(Automation $automation, int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->toArray($automation), $flags);
    }

    /**
     * Inspect the node types and infer which integrations the
     * automation needs to run.
     *
     * @return array<int, string>
     */
    protected function detectRequirements(Automation $automation): array
    {
        $requirements = [];

        foreach ($automation->nodes as $node) {
            $type = (string) $node->type;
            if (str_starts_with($type, 'leadhub.')) {
                $requirements[] = 'leadhub';
            } elseif (str_starts_with($type, 'webhook_manager.')) {
                $requirements[] = 'webhook_manager';
            }
        }

        return array_values(array_unique($requirements));
    }
}
