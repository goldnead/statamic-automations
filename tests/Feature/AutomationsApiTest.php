<?php

namespace Goldnead\StatamicAutomations\Tests\Feature;

use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Tests\TestCase;

/**
 * Phase G smoke tests — verify the JSON endpoints work.
 *
 * These hit the real CP routes behind Statamic's authenticated CP
 * middleware as a real super user, the same way the Control Panel does.
 */
class AutomationsApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsSuperUser();
    }

    public function test_can_create_an_automation_via_api(): void
    {
        $response = $this->postJson('/cp/automations/api/automations', [
            'name' => 'My first automation',
            'nodes' => [
                ['node_key' => 't', 'type' => 'manual'],
                ['node_key' => 'log', 'type' => 'add_log_entry', 'config' => ['message' => 'hi']],
            ],
            'edges' => [
                ['from_node_key' => 't', 'to_node_key' => 'log'],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'My first automation');
        $response->assertJsonCount(2, 'data.nodes');
        $response->assertJsonCount(1, 'data.edges');
    }

    public function test_validate_endpoint_returns_issues_for_invalid_automation(): void
    {
        $automation = Automation::create(['name' => 'X', 'handle' => 'x']);

        $response = $this->postJson("/cp/automations/api/automations/{$automation->id}/validate");

        $response->assertOk();
        $response->assertJsonPath('valid', false);
        $this->assertNotEmpty($response->json('issues'));
    }

    public function test_test_endpoint_runs_automation_in_test_mode(): void
    {
        $automation = Automation::create(['name' => 'Tester', 'handle' => 'tester']);
        AutomationNode::create([
            'automation_id' => $automation->id,
            'node_key' => 't',
            'type' => 'manual',
        ]);
        AutomationNode::create([
            'automation_id' => $automation->id,
            'node_key' => 'log',
            'type' => 'add_log_entry',
            'config' => ['message' => 'hello {{ form.email }}'],
        ]);
        AutomationEdge::create([
            'automation_id' => $automation->id,
            'from_node_key' => 't',
            'to_node_key' => 'log',
        ]);

        $response = $this->postJson("/cp/automations/api/automations/{$automation->id}/test", [
            'context' => ['form' => ['email' => 'a@b.de']],
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'success');
    }

    public function test_nodes_endpoint_lists_all_built_in_nodes(): void
    {
        $response = $this->getJson('/cp/automations/api/nodes');

        $response->assertOk();
        $this->assertNotEmpty($response->json('data.triggers'));
        $this->assertNotEmpty($response->json('data.logic'));
        $this->assertNotEmpty($response->json('data.actions'));
    }

    public function test_templates_endpoint_returns_built_in_catalog(): void
    {
        $response = $this->getJson('/cp/automations/api/templates');

        $response->assertOk();
        $this->assertNotEmpty($response->json('data'));
    }

    public function test_template_install_creates_a_new_automation(): void
    {
        $response = $this->postJson('/cp/automations/api/templates/form_submission_to_webhook/install');

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Form Submission to Webhook');
        $this->assertDatabaseCount('automations', 1);
    }
}
