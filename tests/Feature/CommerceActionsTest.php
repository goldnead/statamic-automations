<?php

namespace Goldnead\StatamicAutomations\Tests\Feature;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Integrations\Entitlements\Actions\GrantEntitlementAction;
use Goldnead\StatamicAutomations\Integrations\Entitlements\Actions\RevokeEntitlementAction;
use Goldnead\StatamicAutomations\Integrations\Entitlements\EntitlementsAdapter;
use Goldnead\StatamicAutomations\Integrations\Invoices\Actions\IssueCreditNoteAction;
use Goldnead\StatamicAutomations\Integrations\Invoices\Actions\IssueInvoiceAction;
use Goldnead\StatamicAutomations\Integrations\Invoices\InvoicesAdapter;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Goldnead\StatamicAutomations\Tests\Fixtures\FakeEntitlement;
use Goldnead\StatamicAutomations\Tests\Fixtures\FakeEntitlementManager;
use Goldnead\StatamicAutomations\Tests\Fixtures\FakeInvoiceModel;
use Goldnead\StatamicAutomations\Tests\Fixtures\FakeInvoiceWriter;
use Goldnead\StatamicAutomations\Tests\Fixtures\FakePayment;
use Goldnead\StatamicAutomations\Tests\Fixtures\FakeState;
use Goldnead\StatamicAutomations\Tests\Fixtures\FakeSubjectReference;
use Goldnead\StatamicAutomations\Tests\TestCase;

require_once __DIR__.'/../Fixtures/CommerceServiceDoubles.php';

/**
 * What the four commerce actions do when the addon behind them is actually there.
 *
 * Until this file existed the actions were tested only in the state "the addon
 * is missing", so every claim their docblocks make about the interesting cases
 * — running twice, a grant that is already revoked, a credit note asked for a
 * second time — was an untested assertion. One of those assertions was wrong.
 *
 * The services are stood in for rather than installed, because none of the four
 * addons is a dependency of this package. The doubles reproduce their rules
 * from source and refuse what the originals refuse; see
 * `tests/Fixtures/CommerceServiceDoubles.php`. They are wired in through the
 * same config keys a site would use to point at a fork, so the path under test
 * is the production path.
 */
class CommerceActionsTest extends TestCase
{
    private FakeEntitlementManager $manager;

    private FakeInvoiceWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = new FakeEntitlementManager;
        $this->writer = new FakeInvoiceWriter;

        $this->app->instance(FakeEntitlementManager::class, $this->manager);
        $this->app->instance(FakeInvoiceWriter::class, $this->writer);

        config()->set('automations.integrations.entitlements.manager', FakeEntitlementManager::class);
        config()->set('automations.integrations.entitlements.subject_reference', FakeSubjectReference::class);
        config()->set('automations.integrations.invoices.writer', FakeInvoiceWriter::class);
        config()->set('automations.integrations.invoices.model', FakeInvoiceModel::class);
        config()->set('automations.integrations.invoices.payment_model', FakePayment::class);

        FakeInvoiceModel::$writer = $this->writer;
        FakePayment::$rows = ['1' => new FakePayment('1')];
    }

    protected function tearDown(): void
    {
        FakeInvoiceModel::$writer = null;
        FakePayment::$rows = [];

        parent::tearDown();
    }

    // --- granting access ----------------------------------------------------

    public function test_granting_access_writes_a_grant_and_says_it_wrote_one(): void
    {
        $result = $this->grant();

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->output['created']);
        $this->assertTrue($result->output['grants_access']);
        $this->assertSame('active', $result->output['state']);
        $this->assertSame('kurs', $result->output['entitlement']['product_slug']);
        $this->assertCount(1, $this->manager->grants);
    }

    public function test_granting_twice_does_not_grant_twice(): void
    {
        $first = $this->grant();
        $second = $this->grant();

        $this->assertTrue($first->output['created']);
        // The claim the docblock makes, held against the rule that backs it: a
        // second run on the same tuple is a read, not a write.
        $this->assertFalse($second->output['created'], 'The second run wrote a second grant.');
        $this->assertTrue($second->isSuccess());
        $this->assertTrue($second->output['grants_access']);
        $this->assertCount(1, $this->manager->grants);
    }

    public function test_a_changing_source_reference_is_how_you_ask_for_a_second_grant(): void
    {
        // The escape hatch the field exists for: a repeat purchase is a second
        // grant, and only the person building the flow knows that.
        $this->grant(['source_ref' => 'payment-1']);
        $this->grant(['source_ref' => 'payment-2']);

        $this->assertCount(2, $this->manager->grants);
    }

    public function test_granting_against_a_revoked_grant_fails_instead_of_reporting_success(): void
    {
        // The defect this file was written for. The addon returns a revoked
        // grant untouched, on purpose, so that a redelivered webhook cannot
        // undo a refund. An action that reported success here would tell the
        // flow everything is fine about somebody who has no access.
        $this->manager->seed(new FakeEntitlement(
            id: 0,
            subject_type: 'user',
            subject_id: '1',
            product_slug: 'kurs',
            source: 'automation',
            source_ref: '',
            status: FakeState::Revoked,
        ));

        $result = $this->grant();

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('revoked', (string) $result->error);
        $this->assertFalse($result->output['grants_access']);
        $this->assertSame('revoked', $result->output['state']);
    }

    public function test_granting_against_an_expired_grant_fails_for_the_same_reason(): void
    {
        $this->manager->seed(new FakeEntitlement(
            id: 0,
            subject_type: 'user',
            subject_id: '1',
            product_slug: 'kurs',
            source: 'automation',
            source_ref: '',
            status: FakeState::Expired,
        ));

        $result = $this->grant();

        $this->assertTrue($result->isFailed());
        $this->assertFalse($result->output['grants_access']);
    }

    public function test_a_scheduled_grant_is_not_a_failure(): void
    {
        // No access yet and nobody has to do anything about it: the clock will.
        // Failing here would turn a correct future start date into a red node.
        $this->manager->seed(new FakeEntitlement(
            id: 0,
            subject_type: 'user',
            subject_id: '1',
            product_slug: 'kurs',
            source: 'automation',
            source_ref: '',
            status: FakeState::Scheduled,
        ));

        $result = $this->grant();

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->output['grants_access']);
        $this->assertTrue($result->output['provisional']);
    }

    public function test_confirming_a_pending_grant_opens_access_without_writing_a_row(): void
    {
        // The case that makes `created` the wrong hook for a welcome mail: the
        // confirmation turns an existing grant active, so access starts on a
        // run that wrote nothing.
        $this->manager->seed(new FakeEntitlement(
            id: 0,
            subject_type: 'user',
            subject_id: '1',
            product_slug: 'kurs',
            source: 'automation',
            source_ref: '',
            status: FakeState::Pending,
        ));

        $result = $this->grant();

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->output['created']);
        $this->assertTrue($result->output['grants_access']);
        $this->assertSame('active', $result->output['state']);
    }

    // --- revoking access ----------------------------------------------------

    public function test_revoking_withdraws_every_grant_for_the_product(): void
    {
        // Two legitimate grants for the same person and product: one won by
        // opt-in, one bought. Withdrawing the first and reporting success would
        // leave access in place.
        $this->grant(['source' => 'newsletter_optin']);
        $this->grant(['source' => 'mollie']);

        $result = $this->revoke();

        $this->assertTrue($result->isSuccess());
        $this->assertSame(2, $result->output['revoked']);
        $this->assertSame(2, $result->output['matched']);

        foreach ($this->manager->grants as $grant) {
            $this->assertSame(FakeState::Revoked, $grant->status);
        }
    }

    public function test_revoking_twice_reports_that_the_second_run_changed_nothing(): void
    {
        $this->grant();

        $this->assertSame(1, $this->revoke()->output['revoked']);
        // Not a failure, and not a second revocation: the count is what a
        // notification step behind this has to read.
        $second = $this->revoke();

        $this->assertTrue($second->isSuccess());
        $this->assertSame(0, $second->output['revoked']);
        $this->assertSame(1, $second->output['matched']);
    }

    public function test_revoking_something_nobody_has_is_not_an_error(): void
    {
        $result = $this->revoke();

        $this->assertTrue($result->isSuccess());
        $this->assertSame(0, $result->output['matched']);
        $this->assertSame(0, $result->output['revoked']);
    }

    public function test_the_reason_reaches_the_grant(): void
    {
        $this->grant();
        $this->revoke(['reason' => 'Chargeback']);

        $this->assertSame('Chargeback', $this->manager->grants[0]->revoked_reason);
    }

    public function test_a_revocation_without_a_reason_never_reaches_the_addon(): void
    {
        // The addon throws on an empty reason. The node has to catch that at
        // the form, not by letting an exception out of a queue worker.
        $result = (new RevokeEntitlementAction(new EntitlementsAdapter))->execute(
            AutomationContext::make(),
            ['subject_type' => 'user', 'subject_id' => '1', 'product_slug' => 'kurs', 'reason' => '  '],
        );

        $this->assertTrue($result->isFailed());
        $this->assertCount(0, $this->manager->grants);
    }

    // --- invoices -----------------------------------------------------------

    public function test_issuing_an_invoice_writes_one_and_says_it_wrote_one(): void
    {
        $result = $this->issue();

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->output['created']);
        $this->assertSame('RE2026-0001', $result->output['invoice']['number']);
        $this->assertSame('2026-08-29T10:00:00+00:00', $result->output['invoice']['issued_at']);
        $this->assertTrue(FakePayment::$rows['1']->itemsLoaded, 'The line items were never loaded.');
    }

    public function test_issuing_an_invoice_twice_returns_the_first_one(): void
    {
        $first = $this->issue();
        $second = $this->issue();

        $this->assertTrue($first->output['created']);
        $this->assertFalse($second->output['created'], 'The second run wrote a second invoice.');
        $this->assertSame(
            $first->output['invoice']['number'],
            $second->output['invoice']['number'],
            'A number was handed out twice.',
        );
        $this->assertCount(1, $this->writer->invoices);
    }

    public function test_an_unpaid_payment_gets_no_invoice_and_says_why(): void
    {
        FakePayment::$rows['1'] = new FakePayment('1', status: 'open');

        $result = $this->issue();

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('not paid', (string) $result->error);
    }

    public function test_a_credit_note_reverses_the_invoice(): void
    {
        $this->issue();

        $result = $this->creditNote();

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->output['created']);
        $this->assertSame('credit_note', $result->output['invoice']['kind']);
    }

    public function test_a_second_credit_note_reports_the_existing_one_rather_than_a_failure(): void
    {
        // The asymmetry the adapter exists to resolve: the writer answers with
        // nothing at all here, which on its own is indistinguishable from
        // "there was no invoice".
        $this->issue();
        $first = $this->creditNote();
        $second = $this->creditNote();

        $this->assertTrue($second->isSuccess());
        $this->assertFalse($second->output['created']);
        $this->assertSame($first->output['invoice']['number'], $second->output['invoice']['number']);
        $this->assertCount(2, $this->writer->invoices);
    }

    public function test_a_credit_note_without_an_invoice_fails_and_says_that(): void
    {
        $result = $this->creditNote();

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('no invoice', (string) $result->error);
    }

    // --- helpers ------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function grant(array $overrides = []): ActionResult
    {
        return (new GrantEntitlementAction(new EntitlementsAdapter))->execute(
            AutomationContext::make(),
            array_merge([
                'subject_type' => 'user',
                'subject_id' => '1',
                'product_slug' => 'kurs',
                'source' => 'automation',
            ], $overrides),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function revoke(array $overrides = []): ActionResult
    {
        return (new RevokeEntitlementAction(new EntitlementsAdapter))->execute(
            AutomationContext::make(),
            array_merge([
                'subject_type' => 'user',
                'subject_id' => '1',
                'product_slug' => 'kurs',
                'reason' => 'Refunded',
            ], $overrides),
        );
    }

    private function issue(): ActionResult
    {
        return (new IssueInvoiceAction(new InvoicesAdapter))->execute(
            AutomationContext::make(['payment' => ['id' => '1']]),
            [],
        );
    }

    private function creditNote(): ActionResult
    {
        return (new IssueCreditNoteAction(new InvoicesAdapter))->execute(
            AutomationContext::make(['payment' => ['id' => '1']]),
            [],
        );
    }
}
