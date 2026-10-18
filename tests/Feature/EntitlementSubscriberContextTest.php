<?php

namespace Goldnead\StatamicAutomations\Tests\Feature;

use Goldnead\StatamicAutomations\Integrations\Entitlements\Triggers\EntitlementGrantedTrigger;
use Goldnead\StatamicAutomations\Integrations\Entitlements\Triggers\EntitlementPendingTrigger;
use Goldnead\StatamicAutomations\Tests\TestCase;

/**
 * Die Entitlement-Trigger geben dem Lauf `subscriber.email`, damit ein Mailknoten
 * seinen Empfaenger und die Wiedereintritts-Regel ihren Schluessel findet.
 */
class EntitlementSubscriberContextTest extends TestCase
{
    private function event(string $type, string $id): array
    {
        return ['entitlement' => ['subject_type' => $type, 'subject_id' => $id, 'product_slug' => 'kurs']];
    }

    public function test_a_subject_of_type_email_is_the_subscriber(): void
    {
        $context = (new EntitlementGrantedTrigger)->buildContext($this->event('email', 'mara@example.com'), []);

        $this->assertSame('mara@example.com', $context->get('subscriber.email'));
        $this->assertSame('', $context->get('subscriber.first_name'));
        $this->assertSame('kurs', $context->get('entitlement.product_slug'));
    }

    public function test_pending_grants_carry_it_too(): void
    {
        $context = (new EntitlementPendingTrigger)->buildContext($this->event('email', 'mara@example.com'), []);

        $this->assertSame('mara@example.com', $context->get('subscriber.email'));
    }

    public function test_an_unresolvable_subject_gives_null_values(): void
    {
        $context = (new EntitlementGrantedTrigger)->buildContext($this->event('user', 'does-not-exist'), []);

        $this->assertNull($context->get('subscriber.email'));
        $this->assertSame('kurs', $context->get('entitlement.product_slug'));
    }

    public function test_an_invalid_address_is_not_a_person(): void
    {
        $context = (new EntitlementGrantedTrigger)->buildContext($this->event('email', 'not-an-address'), []);

        $this->assertNull($context->get('subscriber.email'));
    }
}
