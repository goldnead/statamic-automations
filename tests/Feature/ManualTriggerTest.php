<?php

namespace Goldnead\StatamicAutomations\Tests\Feature;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class ManualTriggerTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    public function test_full_test_run_with_filter_email_and_webhook(): void
    {
        Http::fake();

        $automation = Automation::create(['name' => 'Inquiry', 'handle' => 'inquiry']);

        AutomationNode::create([
            'automation_id' => $automation->id,
            'node_key' => 't',
            'type' => 'manual',
            'config' => [],
        ]);
        AutomationNode::create([
            'automation_id' => $automation->id,
            'node_key' => 'f',
            'type' => 'filter',
            'config' => [
                'mode' => 'all',
                'conditions' => [
                    ['field' => 'form.email', 'operator' => 'is_not_empty'],
                ],
            ],
        ]);
        AutomationNode::create([
            'automation_id' => $automation->id,
            'node_key' => 'mail',
            'type' => 'send_email',
            'config' => [
                'to' => 'admin@example.com',
                'subject' => 'Inquiry from {{ form.email }}',
                'body' => 'Message: {{ form.message }}',
            ],
        ]);
        AutomationNode::create([
            'automation_id' => $automation->id,
            'node_key' => 'hook',
            'type' => 'send_webhook',
            'config' => [
                'url' => 'https://example.com/incoming',
                'payload' => '{"email": "{{ form.email }}"}',
            ],
        ]);

        AutomationEdge::create(['automation_id' => $automation->id, 'from_node_key' => 't', 'to_node_key' => 'f']);
        AutomationEdge::create(['automation_id' => $automation->id, 'from_node_key' => 'f', 'to_node_key' => 'mail']);
        AutomationEdge::create(['automation_id' => $automation->id, 'from_node_key' => 'mail', 'to_node_key' => 'hook']);

        $automation = $automation->fresh()->load(['nodes', 'edges']);

        $context = AutomationContext::make([
            'form' => ['email' => 'lead@example.com', 'message' => 'Hi there'],
        ], testMode: true);

        $runner = app(WorkflowRunner::class);
        $run = $runner->createRun($automation, $context, $automation->nodes->first());
        $final = $runner->execute($run, $context);

        $this->assertSame(AutomationRun::STATUS_SUCCESS, $final->status);
        $this->assertSame(4, $final->nodeRuns()->count());

        // Test mode → no real HTTP calls.
        Http::assertNothingSent();
    }
}
