<?php

namespace Goldnead\StatamicAutomations\Tests\Unit;

use Goldnead\StatamicAutomations\Templates\TemplateRegistry;
use Goldnead\StatamicAutomations\Tests\TestCase;

class TemplateRegistryTest extends TestCase
{
    public function test_returns_a_non_empty_catalog(): void
    {
        $registry = new TemplateRegistry();
        $all = $registry->all();

        $this->assertNotEmpty($all);
        foreach ($all as $template) {
            $this->assertArrayHasKey('handle', $template);
            $this->assertArrayHasKey('name', $template);
            $this->assertArrayHasKey('nodes', $template);
            $this->assertArrayHasKey('edges', $template);
        }
    }

    public function test_get_returns_template_by_handle(): void
    {
        $registry = new TemplateRegistry();
        $template = $registry->get('form_submission_to_webhook');

        $this->assertNotNull($template);
        $this->assertSame('form_submission_to_webhook', $template['handle']);
    }

    public function test_get_returns_null_for_unknown_handle(): void
    {
        $registry = new TemplateRegistry();
        $this->assertNull($registry->get('does_not_exist'));
    }

    public function test_every_template_has_a_trigger_node(): void
    {
        $registry = new TemplateRegistry();

        foreach ($registry->all() as $template) {
            $hasTrigger = false;
            foreach ($template['nodes'] as $node) {
                if (str_contains($node['type'], 'submitted')
                    || str_contains($node['type'], 'created')
                    || str_contains($node['type'], 'changed')
                    || str_contains($node['type'], 'published')
                    || str_contains($node['type'], 'inbound')
                    || str_contains($node['type'], 'outbound')
                    || str_contains($node['type'], 'manual')
                    || str_contains($node['type'], 'lead_')) {
                    $hasTrigger = true;
                    break;
                }
            }

            $this->assertTrue(
                $hasTrigger,
                "Template '{$template['handle']}' must have at least one trigger node.",
            );
        }
    }

    public function test_every_edge_references_existing_nodes(): void
    {
        $registry = new TemplateRegistry();

        foreach ($registry->all() as $template) {
            $nodeKeys = array_column($template['nodes'], 'node_key');
            foreach ($template['edges'] as $edge) {
                $this->assertContains(
                    $edge['from_node_key'],
                    $nodeKeys,
                    "Template '{$template['handle']}' edge from '{$edge['from_node_key']}' references missing node.",
                );
                $this->assertContains(
                    $edge['to_node_key'],
                    $nodeKeys,
                    "Template '{$template['handle']}' edge to '{$edge['to_node_key']}' references missing node.",
                );
            }
        }
    }
}
