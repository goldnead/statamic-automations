<?php

namespace Goldnead\StatamicAutomations\Tests\Feature;

use Goldnead\StatamicAutomations\Integrations\IntegrationDetector;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Nodes\Triggers\WebhookDeliveryFailedTrigger;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Goldnead\StatamicAutomations\Templates\TemplateRegistry;
use Goldnead\StatamicAutomations\Tests\TestCase;
use Illuminate\Support\Facades\Queue;

/**
 * The `webhook_manager.outbound_failed` trigger and its event bridge.
 *
 * Shipped as a template in 1.8.0 with no trigger behind it: the handle was
 * registered nowhere, so installing "Webhook Failure Alert" produced an
 * automation that could never fire. Webhook Manager has thrown
 * `DeliveryFailedTerminally` all along; these tests hold the bridge to it.
 */
class WebhookDeliveryFailedTriggerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        IntegrationDetector::flush();

        // Webhook Manager is not a dev dependency; make the detector see it
        // and point the bridge at a stand-in for its terminal-failure event.
        $app['config']->set('automations.integrations.webhook_manager.detect', [self::class]);
        $app['config']->set(
            'automations.integrations.webhook_manager.outbound_failed_event',
            FakeDeliveryFailedTerminally::class
        );
    }

    protected function tearDown(): void
    {
        IntegrationDetector::flush();

        parent::tearDown();
    }

    public function test_the_trigger_is_registered_when_webhook_manager_is_installed(): void
    {
        $nodes = $this->app->make(NodeRegistry::class);

        $this->assertTrue($nodes->has('webhook_manager.outbound_failed'));
        $this->assertSame('trigger', $nodes->kind('webhook_manager.outbound_failed'));
    }

    public function test_the_shipped_template_points_at_this_trigger(): void
    {
        $template = (new TemplateRegistry)->get('webhook_failure_alert');

        $this->assertNotNull($template);
        $this->assertSame(
            WebhookDeliveryFailedTrigger::handle(),
            $template['nodes'][0]['type'],
        );
    }

    public function test_it_matches_only_the_configured_destination(): void
    {
        $trigger = new WebhookDeliveryFailedTrigger;
        $event = $this->event(destination: 'crm', attempts: 5);

        $this->assertTrue($trigger->matches($event, []));
        $this->assertTrue($trigger->matches($event, ['destination' => 'crm']));
        $this->assertFalse($trigger->matches($event, ['destination' => 'billing']));
    }

    public function test_min_attempts_is_honoured(): void
    {
        $trigger = new WebhookDeliveryFailedTrigger;

        $this->assertFalse($trigger->matches($this->event(attempts: 2), ['min_attempts' => 3]));
        $this->assertTrue($trigger->matches($this->event(attempts: 3), ['min_attempts' => 3]));
        $this->assertTrue($trigger->matches($this->event(attempts: 9), ['min_attempts' => 3]));

        // An empty field must not be read as "at least 0 attempts" by accident
        // nor block the trigger.
        $this->assertTrue($trigger->matches($this->event(attempts: 1), ['min_attempts' => null]));
        $this->assertTrue($trigger->matches($this->event(attempts: 1), ['min_attempts' => '']));
    }

    public function test_it_exposes_the_tokens_the_shipped_template_uses(): void
    {
        $context = (new WebhookDeliveryFailedTrigger)->buildContext(
            $this->event(destination: 'crm', attempts: 4),
            [],
        );

        // The template body is
        // "Destination {{ webhook.destination }} has failed {{ webhook.attempts }} times."
        $this->assertSame('crm', $context->get('webhook.destination'));
        $this->assertSame(4, $context->get('webhook.attempts'));

        $this->assertSame('CRM', $context->get('webhook.destination_name'));
        $this->assertSame('https://crm.example.com/hook', $context->get('webhook.url'));
        $this->assertSame(500, $context->get('webhook.status'));
        $this->assertSame('Connection reset', $context->get('webhook.error'));
    }

    public function test_the_event_bridge_starts_a_run(): void
    {
        $automation = $this->makeAutomation();

        event($this->event(destination: 'crm', attempts: 5));

        $this->assertSame(1, AutomationRun::where('automation_id', $automation->id)->count());
    }

    public function test_the_bridge_respects_min_attempts_end_to_end(): void
    {
        $automation = $this->makeAutomation(['min_attempts' => 3]);

        event($this->event(destination: 'crm', attempts: 1));
        $this->assertSame(0, AutomationRun::where('automation_id', $automation->id)->count());

        event($this->event(destination: 'crm', attempts: 3));
        $this->assertSame(1, AutomationRun::where('automation_id', $automation->id)->count());
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function makeAutomation(array $config = []): Automation
    {
        $automation = Automation::create([
            'name' => 'Webhook failure alert',
            'handle' => 'webhook-failure-alert',
            'enabled' => true,
        ]);

        AutomationNode::create([
            'automation_id' => $automation->id,
            'node_key' => 'trigger',
            'type' => WebhookDeliveryFailedTrigger::handle(),
            'config' => $config,
        ]);
        AutomationNode::create([
            'automation_id' => $automation->id,
            'node_key' => 'log',
            'type' => 'add_log_entry',
            'config' => ['message' => '{{ webhook.destination }} failed {{ webhook.attempts }} times'],
        ]);
        AutomationEdge::create([
            'automation_id' => $automation->id,
            'from_node_key' => 'trigger',
            'to_node_key' => 'log',
        ]);

        return $automation;
    }

    private function event(string $destination = 'crm', int $attempts = 1): FakeDeliveryFailedTerminally
    {
        return new FakeDeliveryFailedTerminally(new FakeDelivery($destination, $attempts));
    }
}

/**
 * Stand-in for Goldnead\WebhookManager\Events\DeliveryFailedTerminally, which
 * carries the terminal Delivery on a public property.
 */
class FakeDeliveryFailedTerminally
{
    public function __construct(public FakeDelivery $delivery) {}
}

/**
 * Stand-in for Webhook Manager's Delivery model — the handful of attributes
 * the trigger reads, in the shape Eloquent exposes them.
 */
class FakeDelivery
{
    public string $request_url = 'https://crm.example.com/hook';

    public int $response_status = 500;

    public string $error_message = 'Connection reset';

    public string $uuid = 'a4d2f0f6-0000-4000-8000-000000000000';

    public object $outboundWebhook;

    public function __construct(string $destination, public int $attempts)
    {
        $this->outboundWebhook = new class($destination)
        {
            public string $name;

            public function __construct(public string $handle)
            {
                $this->name = strtoupper($handle);
            }
        };
    }
}
