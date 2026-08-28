<?php

namespace Goldnead\StatamicAutomations\Tests\Feature;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Integrations\Entitlements\Actions\GrantEntitlementAction;
use Goldnead\StatamicAutomations\Integrations\Entitlements\Actions\RevokeEntitlementAction;
use Goldnead\StatamicAutomations\Integrations\Entitlements\EntitlementsAdapter;
use Goldnead\StatamicAutomations\Integrations\IntegrationDetector;
use Goldnead\StatamicAutomations\Integrations\Invoices\Actions\IssueCreditNoteAction;
use Goldnead\StatamicAutomations\Integrations\Invoices\Actions\IssueInvoiceAction;
use Goldnead\StatamicAutomations\Integrations\Invoices\InvoicesAdapter;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Goldnead\StatamicAutomations\Tests\TestCase;
use Goldnead\StatamicBooking\Events\BookingMade;

require_once __DIR__.'/../Fixtures/CommerceEventStubs.php';

/**
 * What a site without the four commerce addons sees: exactly what it saw before.
 *
 * This is the half of an optional integration that is easy to leave untested
 * and expensive to get wrong, because the failure only appears on somebody
 * else's installation. Every trigger and action class here is autoloaded on
 * every site whether or not the sibling exists, so "absent" has to mean
 * absent — not a node in the library that dies when somebody uses it, and not a
 * listener that fires into a service that is not there.
 *
 * The event stub classes are loaded here on purpose. They make `class_exists`
 * true for the event names, which is the trap: registration must be gated on
 * the addon being detected, not on its events being loadable. Without this
 * file that distinction would never be exercised.
 */
class CommerceIntegrationAbsentTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // No forcing, and nothing installed. Point the detector at names that
        // cannot exist so a stray autoloader cannot make this pass by accident.
        IntegrationDetector::flush();

        foreach (['entitlements', 'booking', 'invoices', 'payments'] as $integration) {
            $app['config']->set("automations.integrations.{$integration}.detect", ['No\\Such\\Class']);
        }
    }

    protected function tearDown(): void
    {
        IntegrationDetector::flush();

        parent::tearDown();
    }

    public function test_the_detector_reports_the_siblings_as_absent(): void
    {
        $snapshot = (new IntegrationDetector)->snapshot();

        $this->assertFalse($snapshot['entitlements']);
        $this->assertFalse($snapshot['booking']);
        $this->assertFalse($snapshot['invoices']);
        $this->assertFalse($snapshot['payments']);
    }

    public function test_none_of_the_new_nodes_appear_in_the_library(): void
    {
        $nodes = app(NodeRegistry::class);

        foreach ([
            'payments.refunded',
            'payments.subscription_started',
            'payments.subscription_renewed',
            'payments.subscription_cancelled',
            'payments.subscription_ended',
            'payments.subscription_start_failed',
            'entitlements.granted',
            'entitlements.revoked',
            'entitlements.expired',
            'entitlements.renewed',
            'entitlements.pending',
            'booking.made',
            'booking.cancelled',
            'booking.rescheduled',
            'invoices.issued',
            'invoices.credit_note_issued',
            'entitlements.grant',
            'entitlements.revoke',
            'invoices.issue',
            'invoices.issue_credit_note',
        ] as $handle) {
            $this->assertFalse(
                $nodes->has($handle),
                "{$handle} is offered in the node library on a site that cannot run it."
            );
        }
    }

    public function test_a_sibling_event_fires_and_nothing_happens(): void
    {
        // The event class exists — the stubs saw to that — so this proves the
        // gate is the detector and not `class_exists` on the event.
        $before = AutomationRun::count();

        event(new BookingMade((object) ['id' => 1, 'endpoint' => 'lesson']));

        $this->assertSame($before, AutomationRun::count());
    }

    public function test_the_adapters_report_the_addon_as_missing_rather_than_throwing(): void
    {
        $this->app['config']->set('automations.integrations.entitlements.manager', 'No\\Such\\Manager');
        $this->app['config']->set('automations.integrations.invoices.writer', 'No\\Such\\Writer');

        $entitlements = new EntitlementsAdapter;
        $invoices = new InvoicesAdapter;

        $this->assertFalse($entitlements->available());
        $this->assertFalse($invoices->available());

        $this->assertFalse($entitlements->grant('user', '1', 'kurs', 'automation')['ok']);
        $this->assertFalse($entitlements->revoke('user', '1', 'kurs', 'weil')['ok']);
        $this->assertFalse($invoices->issue('1')['ok']);
        $this->assertFalse($invoices->creditNote('1')['ok']);
    }

    public function test_an_action_whose_addon_is_missing_fails_the_node_instead_of_the_run(): void
    {
        $this->app['config']->set('automations.integrations.entitlements.manager', 'No\\Such\\Manager');
        $this->app['config']->set('automations.integrations.invoices.writer', 'No\\Such\\Writer');

        $context = AutomationContext::make(['payment' => ['id' => '1']]);

        $results = [
            'entitlements.grant' => (new GrantEntitlementAction(new EntitlementsAdapter))->execute($context, [
                'subject_type' => 'user',
                'subject_id' => '1',
                'product_slug' => 'kurs',
                'source' => 'automation',
            ]),
            'entitlements.revoke' => (new RevokeEntitlementAction(new EntitlementsAdapter))->execute($context, [
                'subject_type' => 'user',
                'subject_id' => '1',
                'product_slug' => 'kurs',
                'reason' => 'Chargeback',
            ]),
            'invoices.issue' => (new IssueInvoiceAction(new InvoicesAdapter))->execute($context, []),
            'invoices.issue_credit_note' => (new IssueCreditNoteAction(new InvoicesAdapter))->execute($context, []),
        ];

        foreach ($results as $handle => $result) {
            // Failed, with a reason a person can read — not an exception out of
            // a queue worker, and not a quiet success that leaves the flow
            // believing access was granted.
            $this->assertTrue($result->isFailed(), "{$handle} did not report a failure.");
            $this->assertNotSame('', (string) $result->error, "{$handle} failed without saying why.");
        }
    }
}
