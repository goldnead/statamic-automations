<?php

namespace Goldnead\StatamicAutomations\Tests\Feature;

use Goldnead\StatamicAutomations\Integrations\IntegrationDetector;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Goldnead\StatamicAutomations\Templates\TemplateRegistry;
use Goldnead\StatamicAutomations\Tests\TestCase;

/**
 * Holds every built-in template against the registries it points at.
 *
 * A template is a set of strings that name registrations elsewhere: node
 * handles, config field handles, node keys, integration keys. Nothing in the
 * language checks those strings — `php -l` and PHPStan see valid array
 * literals either way — so a renamed trigger or a config field that was never
 * implemented ships as an automation the user can install and that then never
 * runs. That is exactly how `webhook_manager.outbound_failed` (a trigger
 * handle registered nowhere) and its `min_attempts` config (a field no schema
 * declared) got into 1.8.0.
 *
 * Every sister integration is force-detected here so the LeadHub and Webhook
 * Manager nodes are registered even though neither addon is a dev dependency —
 * otherwise this test would silently skip the majority of the catalog.
 */
class TemplateNodeCoverageTest extends TestCase
{
    /**
     * The integration keys a template may legitimately declare in `requires`.
     */
    private const KNOWN_INTEGRATIONS = [
        'webhook_manager', 'leadhub', 'marketing', 'funnels', 'payments',
        'entitlements', 'booking', 'invoices',
    ];

    /**
     * Node handle prefix -> the integration key a template using it must require.
     */
    private const PREFIX_REQUIREMENTS = [
        'webhook_manager.' => 'webhook_manager',
        'leadhub.' => 'leadhub',
        'marketing.' => 'marketing',
        'funnels.' => 'funnels',
        'payments.' => 'payments',
        'entitlements.' => 'entitlements',
        'booking.' => 'booking',
        'invoices.' => 'invoices',
    ];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Pretend every sister addon is installed. The detector probes
        // class_exists() against a configurable list, so pointing it at this
        // test class is enough to make registerOptionalIntegrations() run.
        IntegrationDetector::flush();

        foreach (self::KNOWN_INTEGRATIONS as $integration) {
            $app['config']->set("automations.integrations.{$integration}.detect", [self::class]);
        }
    }

    protected function tearDown(): void
    {
        // The detector caches statically; don't leak "installed" into the
        // rest of the suite.
        IntegrationDetector::flush();

        parent::tearDown();
    }

    public function test_every_sister_integration_is_registered_for_this_test(): void
    {
        $snapshot = (new IntegrationDetector)->snapshot();

        foreach (self::KNOWN_INTEGRATIONS as $integration) {
            $this->assertTrue(
                $snapshot[$integration] ?? false,
                "The {$integration} integration was not detected, so this test would not see its nodes."
            );
        }
    }

    public function test_every_template_node_type_is_a_registered_node(): void
    {
        $nodes = $this->nodeRegistry();

        foreach ($this->templates() as $template) {
            foreach ($template['nodes'] as $node) {
                $this->assertTrue(
                    $nodes->has($node['type']),
                    sprintf(
                        'Template "%s" uses node type "%s" (node key "%s"), which no registry registers. '
                        .'Installing that template produces an automation that can never run.',
                        $template['handle'],
                        $node['type'],
                        $node['node_key'] ?? '?',
                    )
                );
            }
        }
    }

    public function test_every_template_starts_with_exactly_one_trigger_node(): void
    {
        $nodes = $this->nodeRegistry();

        foreach ($this->templates() as $template) {
            $triggers = [];

            foreach ($template['nodes'] as $node) {
                if (! $nodes->has($node['type'])) {
                    continue; // covered by the test above
                }

                if ($nodes->kind($node['type']) === 'trigger') {
                    $triggers[] = $node['node_key'];
                }
            }

            $this->assertCount(
                1,
                $triggers,
                sprintf(
                    'Template "%s" has %d trigger nodes (%s); a flow needs exactly one entry point.',
                    $template['handle'],
                    count($triggers),
                    implode(', ', $triggers) ?: 'none',
                )
            );
        }
    }

    public function test_every_template_node_config_key_exists_in_that_nodes_schema(): void
    {
        $nodes = $this->nodeRegistry();

        foreach ($this->templates() as $template) {
            foreach ($template['nodes'] as $node) {
                if (! $nodes->has($node['type'])) {
                    continue; // covered above
                }

                $config = $node['config'] ?? [];
                if ($config === []) {
                    continue;
                }

                $fields = $this->schemaHandles($nodes, $node['type']);

                foreach (array_keys($config) as $key) {
                    $this->assertContains(
                        $key,
                        $fields,
                        sprintf(
                            'Template "%s" sets config "%s" on node "%s" (type "%s"), but that node\'s '
                            .'schema declares no such field, so the value is silently ignored. '
                            .'Either the node should read it or the template should drop it. '
                            .'Known fields: %s.',
                            $template['handle'],
                            $key,
                            $node['node_key'] ?? '?',
                            $node['type'],
                            implode(', ', $fields) ?: '(none)',
                        )
                    );
                }
            }
        }
    }

    public function test_every_template_edge_points_at_nodes_that_exist(): void
    {
        foreach ($this->templates() as $template) {
            $keys = array_column($template['nodes'], 'node_key');

            $this->assertSame(
                count($keys),
                count(array_unique($keys)),
                sprintf('Template "%s" reuses a node_key; edges would be ambiguous.', $template['handle'])
            );

            foreach ($template['edges'] ?? [] as $edge) {
                foreach (['from_node_key', 'to_node_key'] as $side) {
                    $this->assertContains(
                        $edge[$side],
                        $keys,
                        sprintf(
                            'Template "%s" has an edge %s "%s", which matches no node_key.',
                            $template['handle'],
                            $side,
                            $edge[$side],
                        )
                    );
                }
            }
        }
    }

    public function test_template_requirements_match_the_nodes_they_use(): void
    {
        foreach ($this->templates() as $template) {
            $requires = $template['requires'] ?? [];

            foreach ($requires as $requirement) {
                $this->assertContains(
                    $requirement,
                    self::KNOWN_INTEGRATIONS,
                    sprintf('Template "%s" requires unknown integration "%s".', $template['handle'], $requirement)
                );
            }

            foreach ($template['nodes'] as $node) {
                foreach (self::PREFIX_REQUIREMENTS as $prefix => $integration) {
                    if (! str_starts_with($node['type'], $prefix)) {
                        continue;
                    }

                    $this->assertContains(
                        $integration,
                        $requires,
                        sprintf(
                            'Template "%s" uses "%s" but does not declare "%s" in requires, so the CP '
                            .'offers it on sites where the node does not exist.',
                            $template['handle'],
                            $node['type'],
                            $integration,
                        )
                    );
                }
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function templates(): array
    {
        $templates = (new TemplateRegistry)->all();

        $this->assertNotEmpty($templates, 'No templates to check — the registry came back empty.');

        return $templates;
    }

    private function nodeRegistry(): NodeRegistry
    {
        return $this->app->make('automations')->nodes();
    }

    /**
     * The config field handles a node's schema declares.
     *
     * @return array<int, string>
     */
    private function schemaHandles(NodeRegistry $nodes, string $type): array
    {
        $described = $nodes->describe($type);

        return array_values(array_filter(array_map(
            fn ($field) => is_array($field) ? ($field['handle'] ?? null) : null,
            $described['schema'] ?? [],
        )));
    }
}
