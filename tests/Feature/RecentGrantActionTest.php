<?php

namespace Goldnead\StatamicAutomations\Tests\Feature;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Integrations\Entitlements\Actions\RecentGrantAction;
use Goldnead\StatamicAutomations\Integrations\Entitlements\EntitlementsAdapter;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Goldnead\StatamicAutomations\Tests\Fixtures\FakeEntitlement;
use Goldnead\StatamicAutomations\Tests\Fixtures\FakeEntitlementManager;
use Goldnead\StatamicAutomations\Tests\Fixtures\FakeLeadMagnetGrant;
use Goldnead\StatamicAutomations\Tests\Fixtures\FakeState;
use Goldnead\StatamicAutomations\Tests\Fixtures\FakeSubjectReference;
use Goldnead\StatamicAutomations\Tests\TestCase;
use Illuminate\Support\Carbon;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

require_once __DIR__.'/../Fixtures/CommerceServiceDoubles.php';
require_once __DIR__.'/../Fixtures/LeadMagnetGrantDouble.php';

/** Stands for `Goldnead\LeadMagnets\Support\LeadMagnetSubject`: the address as a contact, id a hash. */
class FakeLeadMagnetSubject
{
    public static function for(string $email): FakeSubjectReference
    {
        return new FakeSubjectReference('lead-magnet-contact', hash('sha256', mb_strtolower(trim($email))));
    }
}

/** Stands for `Goldnead\LeadMagnets\Models\Resource`: the lead magnet whose `access_product` is asked. */
class RecentGrantFakeResource
{
    public ?string $title = null;

    private ?string $product = null;

    public static function query(): static
    {
        return new static;
    }

    public function where(string $column, mixed $value): static
    {
        $this->product = $column === 'access_product' ? (string) $value : null;

        return $this;
    }

    public function first(): ?static
    {
        $this->title = $this->product === 'toolkit' ? 'Das Toolkit' : null;

        return $this->title === null ? null : $this;
    }
}

/**
 * The "Recent Grant" node: has this person just unlocked something?
 *
 * Asked by a flow that is about to mail somebody and wants to know whether a
 * different mail already told them about a fresh unlock. "Not found" is an
 * answer, not a problem: the node succeeds with `found` false.
 */
class RecentGrantActionTest extends TestCase
{
    private FakeEntitlementManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-18 12:00:00');

        $this->manager = new FakeEntitlementManager;
        $this->app->instance(FakeEntitlementManager::class, $this->manager);

        config()->set('automations.integrations.entitlements.manager', FakeEntitlementManager::class);
        config()->set('automations.integrations.entitlements.subject_reference', FakeSubjectReference::class);
        config()->set('automations.integrations.lead_magnets.grant_model', 'Not\\There\\Grant');
        config()->set('automations.integrations.lead_magnets.subject', 'Not\\There\\Subject');

        FakeLeadMagnetGrant::$titles = [];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_finds_a_grant_activated_inside_the_window(): void
    {
        $this->seedGrant('email', 'anna@example.test', 'toolkit', startedMinutesAgo: 20, source: 'lead_magnet');

        $result = $this->recent();

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->output['found']);
        $this->assertSame('toolkit', $result->output['product_slug']);
        $this->assertSame('lead_magnet', $result->output['source']);
        $this->assertSame(Carbon::now()->subMinutes(20)->toIso8601String(), $result->output['granted_at']);
    }

    public function test_a_grant_older_than_the_window_is_not_recent(): void
    {
        $this->seedGrant('email', 'anna@example.test', 'toolkit', startedMinutesAgo: 61);

        $result = $this->recent();

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->output['found']);
        $this->assertSame('', $result->output['product_slug']);
        $this->assertSame('', $result->output['product_title']);
        $this->assertSame('', $result->output['source']);
        $this->assertSame('', $result->output['granted_at']);
    }

    public function test_within_minutes_is_configurable_and_defaults_to_sixty(): void
    {
        $this->seedGrant('email', 'anna@example.test', 'toolkit', startedMinutesAgo: 90);

        $this->assertFalse($this->recent()->output['found']);
        $this->assertTrue($this->recent(['within_minutes' => 120])->output['found']);
        $this->assertFalse($this->recent(['within_minutes' => 30])->output['found']);
    }

    public function test_the_newest_active_grant_wins(): void
    {
        $this->seedGrant('email', 'anna@example.test', 'older', startedMinutesAgo: 40, source: 'a');
        $this->seedGrant('email', 'anna@example.test', 'newest', startedMinutesAgo: 5, source: 'b');
        $this->seedGrant('email', 'anna@example.test', 'middle', startedMinutesAgo: 15, source: 'c');

        $this->assertSame('newest', $this->recent()->output['product_slug']);
    }

    public function test_only_active_grants_count(): void
    {
        // Revoked, expired, scheduled and pending grants do not unlock anything.
        foreach ([FakeState::Revoked, FakeState::Expired, FakeState::Scheduled, FakeState::Pending] as $i => $state) {
            $this->seedGrant('email', 'anna@example.test', 'p'.$i, startedMinutesAgo: 5, state: $state);
        }

        $this->assertFalse($this->recent()->output['found']);
    }

    public function test_a_newer_dead_grant_does_not_hide_an_older_active_one(): void
    {
        $this->seedGrant('email', 'anna@example.test', 'alive', startedMinutesAgo: 30);
        $this->seedGrant('email', 'anna@example.test', 'refunded', startedMinutesAgo: 2, state: FakeState::Revoked);

        $this->assertSame('alive', $this->recent()->output['product_slug']);
    }

    public function test_sources_filter_by_a_comma_separated_list_and_empty_means_all(): void
    {
        $this->seedGrant('email', 'anna@example.test', 'bought', startedMinutesAgo: 5, source: 'thrivecart');
        $this->seedGrant('email', 'anna@example.test', 'won', startedMinutesAgo: 10, source: 'lead_magnet');

        $this->assertSame('bought', $this->recent()->output['product_slug']);
        $this->assertSame('won', $this->recent(['sources' => 'lead_magnet'])->output['product_slug']);
        $this->assertSame('bought', $this->recent(['sources' => ' lead_magnet , thrivecart '])->output['product_slug']);
        $this->assertFalse($this->recent(['sources' => 'manual'])->output['found']);
    }

    public function test_the_person_is_found_as_the_account_that_owns_the_address(): void
    {
        $user = $this->makeUser('anna@example.test');
        $this->seedGrant('user', (string) $user->id(), 'toolkit', startedMinutesAgo: 3);

        $this->assertTrue($this->recent()->output['found']);
    }

    public function test_the_address_is_matched_without_regard_to_case_and_spaces(): void
    {
        $this->seedGrant('email', 'anna@example.test', 'toolkit', startedMinutesAgo: 3);

        $this->assertTrue($this->recent(['email' => '  Anna@Example.TEST '])->output['found']);
    }

    public function test_somebody_else_is_not_found(): void
    {
        $this->seedGrant('email', 'bert@example.test', 'toolkit', startedMinutesAgo: 3);

        $this->assertFalse($this->recent()->output['found']);
    }

    public function test_the_title_comes_from_the_entitlements_product_catalogue_first(): void
    {
        $this->manager->products = [
            'toolkit' => ['slug' => 'toolkit', 'label' => 'Das Selbstanalyse-Toolkit', 'group' => null],
        ];
        config()->set('automations.entitlements.title_sources', [
            ['collection' => 'resources', 'match' => 'resource_slug', 'field' => 'title'],
        ]);
        $this->makeResourceEntry('toolkit', 'Aus dem Eintrag');
        $this->seedGrant('email', 'anna@example.test', 'toolkit', startedMinutesAgo: 3);

        $this->assertSame('Das Selbstanalyse-Toolkit', $this->recent()->output['product_title']);
    }

    public function test_the_title_falls_back_to_the_configured_entry_sources(): void
    {
        config()->set('automations.entitlements.title_sources', [
            ['collection' => 'resources', 'match' => 'resource_slug', 'field' => 'title'],
        ]);
        $this->makeResourceEntry('toolkit', 'Das Selbstanalyse-Toolkit');
        $this->seedGrant('email', 'anna@example.test', 'toolkit', startedMinutesAgo: 3);

        $this->assertSame('Das Selbstanalyse-Toolkit', $this->recent()->output['product_title']);
    }

    public function test_a_title_source_for_another_slug_does_not_leak(): void
    {
        config()->set('automations.entitlements.title_sources', [
            ['collection' => 'resources', 'match' => 'resource_slug', 'field' => 'title'],
        ]);
        $this->makeResourceEntry('other', 'Etwas anderes');
        $this->seedGrant('email', 'anna@example.test', 'toolkit', startedMinutesAgo: 3);

        $this->assertSame('', $this->recent()->output['product_title']);
    }

    public function test_the_title_falls_back_to_the_lead_magnet_resource_of_the_grant(): void
    {
        config()->set('automations.integrations.lead_magnets.grant_model', FakeLeadMagnetGrant::class);
        $this->seedGrant('email', 'anna@example.test', 'toolkit', startedMinutesAgo: 3);
        FakeLeadMagnetGrant::$titles[$this->manager->grants[0]->id] = 'Warm-up Kompass';

        $this->assertSame('Warm-up Kompass', $this->recent()->output['product_title']);
    }

    public function test_a_freebie_is_named_by_its_lead_magnet_before_the_catalogue(): void
    {
        // Production: the catalogue names the access "CVT im Chor — Masterclass", the freebie is "CVT im Chor".
        $this->manager->products = [
            'toolkit' => ['slug' => 'toolkit', 'label' => 'Das Toolkit — Masterclass', 'group' => null],
        ];
        config()->set('automations.integrations.lead_magnets.grant_model', FakeLeadMagnetGrant::class);
        $this->seedGrant('email', 'anna@example.test', 'toolkit', startedMinutesAgo: 3);
        FakeLeadMagnetGrant::$titles[$this->manager->grants[0]->id] = 'Das Toolkit';

        $this->assertSame('Das Toolkit', $this->recent()->output['product_title']);
    }

    public function test_a_freebie_is_named_by_its_lead_magnet_also_when_a_newsletter_grant_carries_the_slug(): void
    {
        // The newest grant may be the newsletter opt-in's, with no lead magnet grant behind it; the access still is
        // the one a lead magnet hands out, and that is how the dry run names it.
        $this->manager->products = [
            'toolkit' => ['slug' => 'toolkit', 'label' => 'Das Toolkit — Masterclass', 'group' => null],
        ];
        config()->set('automations.integrations.lead_magnets.resource_model', RecentGrantFakeResource::class);
        $this->seedGrant('email', 'anna@example.test', 'toolkit', startedMinutesAgo: 3, source: 'newsletter_optin');

        $this->assertSame('Das Toolkit', $this->recent()->output['product_title']);
    }

    public function test_a_purchase_of_the_same_access_keeps_the_catalogue_name(): void
    {
        // Somebody who paid for the access did not come through the freebie: the product is called what the catalogue calls it.
        $this->manager->products = [
            'toolkit' => ['slug' => 'toolkit', 'label' => 'Das Toolkit — Masterclass', 'group' => null],
        ];
        config()->set('automations.integrations.lead_magnets.resource_model', RecentGrantFakeResource::class);
        $this->seedGrant('email', 'anna@example.test', 'toolkit', startedMinutesAgo: 3, source: 'thrivecart');

        $this->assertSame('Das Toolkit — Masterclass', $this->recent()->output['product_title']);
    }

    public function test_the_dry_run_and_a_real_run_name_a_freebie_the_same_way(): void
    {
        $this->manager->products = [
            'toolkit' => ['slug' => 'toolkit', 'label' => 'Das Toolkit — Masterclass', 'group' => null],
        ];
        config()->set('automations.integrations.lead_magnets.grant_model', FakeLeadMagnetGrant::class);
        config()->set('automations.integrations.lead_magnets.resource_model', RecentGrantFakeResource::class);
        $this->seedGrant('email', 'anna@example.test', 'toolkit', startedMinutesAgo: 3);
        FakeLeadMagnetGrant::$titles[$this->manager->grants[0]->id] = 'Das Toolkit';

        $action = new RecentGrantAction(new EntitlementsAdapter);

        $this->assertSame($this->recent()->output['product_title'], $action->titleFor('toolkit'));
    }

    public function test_the_title_is_empty_and_never_the_slug_when_nothing_knows_it(): void
    {
        config()->set('automations.integrations.lead_magnets.grant_model', FakeLeadMagnetGrant::class);
        $this->seedGrant('email', 'anna@example.test', 'toolkit', startedMinutesAgo: 3);

        $result = $this->recent();

        $this->assertTrue($result->output['found']);
        $this->assertSame('toolkit', $result->output['product_slug']);
        $this->assertSame('', $result->output['product_title']);
    }

    public function test_a_catalogue_that_only_knows_the_slug_as_its_label_gives_no_title(): void
    {
        // The catalogue's label() falls back to the slug for unknown products;
        // the node must not take that for a name.
        $this->manager->products = ['toolkit' => ['slug' => 'toolkit', 'label' => 'toolkit', 'group' => null]];
        $this->seedGrant('email', 'anna@example.test', 'toolkit', startedMinutesAgo: 3);

        $this->assertSame('', $this->recent()->output['product_title']);
    }

    public function test_test_mode_answers_not_found_without_looking(): void
    {
        $this->seedGrant('email', 'anna@example.test', 'toolkit', startedMinutesAgo: 3);

        $result = (new RecentGrantAction(new EntitlementsAdapter))->execute(
            AutomationContext::make([], true),
            $this->config(),
        );

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->output['found']);
    }

    public function test_it_fails_without_an_address_or_with_an_invalid_one(): void
    {
        $this->assertTrue($this->recent(['email' => ''])->isFailed());
        $this->assertTrue($this->recent(['email' => 'kein-mail'])->isFailed());
    }

    public function test_it_fails_on_a_bad_window(): void
    {
        $this->assertTrue($this->recent(['within_minutes' => 0])->isFailed());
        $this->assertTrue($this->recent(['within_minutes' => 'bald'])->isFailed());
    }

    public function test_it_fails_when_the_entitlements_addon_is_not_there(): void
    {
        config()->set('automations.integrations.entitlements.manager', 'Not\\There\\Manager');

        $result = $this->recent();

        // "Not found" would be a lie: nothing was looked at.
        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('not installed', (string) $result->error);
    }

    public function test_the_manager_throwing_fails_the_node(): void
    {
        $this->app->instance(FakeEntitlementManager::class, new class extends FakeEntitlementManager
        {
            public function forSubject(mixed $subject): never
            {
                throw new \RuntimeException('database is gone');
            }
        });

        $result = $this->recent();

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('database is gone', (string) $result->error);
    }

    public function test_the_output_schema_names_every_field_the_node_returns(): void
    {
        $this->seedGrant('email', 'anna@example.test', 'toolkit', startedMinutesAgo: 3);

        $this->assertSame(
            array_keys(RecentGrantAction::outputSchema()),
            array_keys($this->recent()->output),
        );
        $this->assertSame('entitlements.recent_grant', RecentGrantAction::handle());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function recent(array $overrides = []): ActionResult
    {
        return (new RecentGrantAction(new EntitlementsAdapter))->execute(
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
        return array_merge(['email' => 'anna@example.test'], $overrides);
    }

    private function makeUser(string $email): mixed
    {
        $user = User::make()->email($email)->makeSuper();
        $user->save();

        return $user;
    }

    private function makeResourceEntry(string $slug, string $title): void
    {
        if (! Collection::find('resources')) {
            Collection::make('resources')->save();
        }

        Entry::make()->collection('resources')->slug($slug)->data([
            'title' => $title,
            'resource_slug' => $slug,
        ])->save();
    }

    public function test_it_finds_the_grant_a_lead_magnet_writes_on_the_address_as_a_contact(): void
    {
        // statamic-lead-magnets addresses a reader with no account as a contact whose id is a
        // hash of the address, not as an `email` subject: asking after the address must still see it.
        config()->set('automations.integrations.lead_magnets.subject', FakeLeadMagnetSubject::class);
        $this->seedGrant('lead-magnet-contact', hash('sha256', 'anna@example.test'), 'vowel-guide', startedMinutesAgo: 1, source: 'lead_magnet');

        $result = $this->recent();

        $this->assertTrue($result->output['found']);
        $this->assertSame('vowel-guide', $result->output['product_slug']);
        $this->assertSame('lead_magnet', $result->output['source']);
    }

    public function test_a_contact_grant_of_another_address_is_not_found(): void
    {
        config()->set('automations.integrations.lead_magnets.subject', FakeLeadMagnetSubject::class);
        $this->seedGrant('lead-magnet-contact', hash('sha256', 'someone-else@example.test'), 'vowel-guide', startedMinutesAgo: 1, source: 'lead_magnet');

        $this->assertFalse($this->recent()->output['found']);
    }

    public function test_without_the_lead_magnets_addon_the_contact_lookup_is_skipped(): void
    {
        $this->seedGrant('lead-magnet-contact', hash('sha256', 'anna@example.test'), 'vowel-guide', startedMinutesAgo: 1, source: 'lead_magnet');

        // `subject` points at a class that does not exist (setUp): nothing is read, nothing fails.
        $result = $this->recent();

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->output['found']);
    }

    private function seedGrant(
        string $type,
        string $id,
        string $slug,
        int $startedMinutesAgo,
        FakeState $state = FakeState::Active,
        string $source = 'test',
    ): void {
        $this->manager->seed(new FakeEntitlement(
            id: 0,
            subject_type: $type,
            subject_id: $id,
            product_slug: $slug,
            source: $source,
            source_ref: '',
            status: $state,
            starts_at: Carbon::now()->subMinutes($startedMinutesAgo),
        ));
    }
}
