<?php

namespace Goldnead\StatamicAutomations\Tests\Feature;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Integrations\Entitlements\Actions\CheckAccessAction;
use Goldnead\StatamicAutomations\Integrations\Entitlements\EntitlementsAdapter;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Goldnead\StatamicAutomations\Tests\Fixtures\FakeEntitlement;
use Goldnead\StatamicAutomations\Tests\Fixtures\FakeEntitlementManager;
use Goldnead\StatamicAutomations\Tests\Fixtures\FakeState;
use Goldnead\StatamicAutomations\Tests\Fixtures\FakeSubjectReference;
use Goldnead\StatamicAutomations\Tests\TestCase;
use Statamic\Facades\User;

require_once __DIR__.'/../Fixtures/CommerceServiceDoubles.php';

/**
 * The "Check Access" node: read live whether an address holds a product, and
 * stop the run when the condition the flow was built on no longer holds.
 *
 * The point of the node is the word "live". A condition on the trigger context
 * knows the state at the moment the run started; a mail ten days later needs
 * the state ten days later.
 */
class CheckAccessActionTest extends TestCase
{
    private FakeEntitlementManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = new FakeEntitlementManager;
        $this->app->instance(FakeEntitlementManager::class, $this->manager);

        config()->set('automations.integrations.entitlements.manager', FakeEntitlementManager::class);
        config()->set('automations.integrations.entitlements.subject_reference', FakeSubjectReference::class);
    }

    public function test_require_passes_when_the_person_holds_the_product(): void
    {
        $user = $this->makeUser('anna@example.test');
        $this->seedGrant('user', (string) $user->id(), 'vier-wochen-plan', FakeState::Active);

        $result = $this->check(['mode' => 'require']);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->output['has_access']);
        $this->assertSame('active', $result->output['state']);
    }

    public function test_require_stops_the_run_when_the_person_does_not_hold_the_product(): void
    {
        $this->makeUser('anna@example.test');

        $result = $this->check(['mode' => 'require']);

        $this->assertTrue($result->isStopped());
        $this->assertStringContainsString('vier-wochen-plan', (string) $result->output['reason']);
    }

    public function test_forbid_passes_when_the_person_does_not_hold_the_product(): void
    {
        $this->makeUser('anna@example.test');

        $result = $this->check(['mode' => 'forbid']);

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->output['has_access']);
    }

    public function test_forbid_stops_the_run_when_the_person_holds_the_product(): void
    {
        $user = $this->makeUser('anna@example.test');
        $this->seedGrant('user', (string) $user->id(), 'vier-wochen-plan', FakeState::Active);

        $this->assertTrue($this->check(['mode' => 'forbid'])->isStopped());
    }

    public function test_a_revoked_grant_is_not_access(): void
    {
        // Refunded after the purchase: "has the plan" must be false now.
        $user = $this->makeUser('anna@example.test');
        $this->seedGrant('user', (string) $user->id(), 'vier-wochen-plan', FakeState::Revoked);

        $this->assertTrue($this->check(['mode' => 'require'])->isStopped());
        $this->assertTrue($this->check(['mode' => 'forbid'])->isSuccess());
    }

    public function test_an_expired_grant_is_not_access(): void
    {
        $user = $this->makeUser('anna@example.test');
        $this->seedGrant('user', (string) $user->id(), 'vier-wochen-plan', FakeState::Expired);

        $this->assertTrue($this->check(['mode' => 'require'])->isStopped());
    }

    public function test_a_grant_for_another_product_does_not_count(): void
    {
        $user = $this->makeUser('anna@example.test');
        $this->seedGrant('user', (string) $user->id(), 'selbstanalyse-toolkit', FakeState::Active);

        $this->assertTrue($this->check(['mode' => 'require'])->isStopped());
    }

    public function test_somebody_with_no_account_is_found_through_a_grant_on_their_address(): void
    {
        // A buyer who never made a login still holds what she bought: a grant
        // can belong to the address itself.
        $this->seedGrant('email', 'anna@example.test', 'vier-wochen-plan', FakeState::Active);

        $result = $this->check(['mode' => 'require']);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->output['has_access']);
    }

    public function test_the_address_is_matched_without_regard_to_case_and_spaces(): void
    {
        $user = $this->makeUser('anna@example.test');
        $this->seedGrant('user', (string) $user->id(), 'vier-wochen-plan', FakeState::Active);

        $this->assertTrue($this->check(['mode' => 'require', 'email' => '  Anna@Example.test '])->isSuccess());
    }

    public function test_somebody_nobody_knows_holds_nothing(): void
    {
        $this->assertTrue($this->check(['mode' => 'require', 'email' => 'niemand@example.test'])->isStopped());
        $this->assertTrue($this->check(['mode' => 'forbid', 'email' => 'niemand@example.test'])->isSuccess());
    }

    public function test_a_stop_is_never_a_failure_and_a_failure_is_never_a_stop(): void
    {
        // The difference is what makes "the mail did not come" explainable:
        // a stop says the condition did not hold, a failure says the check
        // could not be made.
        $stopped = $this->check(['mode' => 'require']);
        $failed = $this->check(['mode' => 'require', 'email' => 'kein-mail']);

        $this->assertTrue($stopped->isStopped());
        $this->assertFalse($stopped->isFailed());
        $this->assertTrue($failed->isFailed());
        $this->assertFalse($failed->isStopped());
    }

    public function test_it_fails_when_the_entitlements_addon_is_not_there(): void
    {
        config()->set('automations.integrations.entitlements.manager', 'Not\\There\\Manager');

        $result = $this->check(['mode' => 'forbid']);

        // Passing a "forbid" because the check could not run would let a mail
        // go to somebody who must not get it.
        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('not installed', (string) $result->error);
    }

    public function test_it_fails_when_the_address_cannot_be_turned_into_a_subject(): void
    {
        // The subject class is configured but gone: nothing was looked up, so
        // "no access" would be an invention and a forbid must not pass on it.
        config()->set('automations.integrations.entitlements.subject_reference', 'Not\\There\\Subject');

        $result = $this->check(['mode' => 'forbid']);

        $this->assertTrue($result->isFailed());
        $this->assertFalse($result->isSuccess());
    }

    public function test_it_fails_on_missing_or_unknown_configuration(): void
    {
        $this->assertTrue($this->check(['product_slug' => ''])->isFailed());
        $this->assertTrue($this->check(['email' => ''])->isFailed());
        $this->assertTrue($this->check(['mode' => 'maybe'])->isFailed());
    }

    public function test_the_manager_throwing_fails_the_node_instead_of_passing_it(): void
    {
        $this->app->instance(FakeEntitlementManager::class, new class extends FakeEntitlementManager
        {
            public function decide(mixed $subject, string $productSlug): never
            {
                throw new \RuntimeException('database is gone');
            }
        });

        $result = $this->check(['mode' => 'forbid']);

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('database is gone', (string) $result->error);
    }

    public function test_test_mode_reads_for_real_and_changes_nothing(): void
    {
        $user = $this->makeUser('anna@example.test');
        $this->seedGrant('user', (string) $user->id(), 'vier-wochen-plan', FakeState::Active);
        $before = count($this->manager->grants);

        $result = (new CheckAccessAction(new EntitlementsAdapter))->execute(
            AutomationContext::make([], true),
            $this->config(['mode' => 'forbid']),
        );

        // Reading is harmless, so the preview tells the truth: this run would stop.
        $this->assertTrue($result->isStopped());
        $this->assertCount($before, $this->manager->grants);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function check(array $overrides = []): ActionResult
    {
        return (new CheckAccessAction(new EntitlementsAdapter))->execute(
            AutomationContext::make(),
            $this->config($overrides),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function config(array $overrides = []): array
    {
        return array_merge([
            'email' => 'anna@example.test',
            'product_slug' => 'vier-wochen-plan',
            'mode' => 'require',
        ], $overrides);
    }

    private function makeUser(string $email): mixed
    {
        $user = User::make()->email($email)->makeSuper();
        $user->save();

        return $user;
    }

    private function seedGrant(string $type, string $id, string $slug, FakeState $state): void
    {
        $this->manager->seed(new FakeEntitlement(
            id: 0,
            subject_type: $type,
            subject_id: $id,
            product_slug: $slug,
            source: 'test',
            source_ref: '',
            status: $state,
        ));
    }
}
