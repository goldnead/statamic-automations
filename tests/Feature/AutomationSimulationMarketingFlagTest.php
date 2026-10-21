<?php

use Carbon\CarbonImmutable;
use Goldnead\BrandContext\Simulation\SimEvent;
use Goldnead\BrandContext\Simulation\SimPerson;
use Goldnead\StatamicAutomations\Integrations\Map\AutomationMapObjects;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;

/*
 * A mail of the marketing addon's send step is a campaign mail. The dry run says so on the mail effect, so a
 * person who unsubscribed after an earlier mail does not get it, and the transactional mail stays.
 */

it('flags the marketing send step as marketing and the plain send step as not', function () {
    $a = Automation::query()->create(['uuid' => 'uuid-mk', 'name' => 'Mk', 'handle' => 'mk', 'enabled' => true]);

    foreach ([['t', 'manual'], ['plain', 'send_email'], ['campaign', 'marketing.send_email']] as [$key, $type]) {
        AutomationNode::query()->create(['automation_id' => $a->id, 'node_key' => $key, 'type' => $type, 'config' => ['subject' => $key]]);
    }

    foreach ([['t', 'plain'], ['plain', 'campaign']] as [$from, $to]) {
        AutomationEdge::query()->create(['automation_id' => $a->id, 'from_node_key' => $from, 'from_output' => 'default', 'to_node_key' => $to]);
    }

    $effects = app(AutomationMapObjects::class)->react('mk', new SimEvent(SimEvent::STARTED), SimPerson::known(), CarbonImmutable::now());

    expect($effects[0]->meta['node'])->toBe('plain')
        ->and($effects[0]->meta['marketing'])->toBeFalse()
        ->and($effects[0]->children[0]->meta['node'])->toBe('campaign')
        ->and($effects[0]->children[0]->meta['marketing'])->toBeTrue();
});
