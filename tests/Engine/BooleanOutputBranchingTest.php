<?php

namespace Goldnead\StatamicAutomations\Tests\Engine;

use Goldnead\StatamicAutomations\Automations;
use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Integrations\Entitlements\Actions\RecentGrantAction;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Tests\Fixtures\FakeEntitlement;
use Goldnead\StatamicAutomations\Tests\Fixtures\FakeEntitlementManager;
use Goldnead\StatamicAutomations\Tests\Fixtures\FakeState;
use Goldnead\StatamicAutomations\Tests\Fixtures\FakeSubjectReference;
use Goldnead\StatamicAutomations\Tests\TestCase;
use Illuminate\Support\Carbon;

require_once __DIR__.'/../Fixtures/CommerceServiceDoubles.php';

/**
 * How a later `branch` or `switch` reads a boolean output such as
 * `{{ nodes.<key>.found }}`, pinned against the real runner.
 *
 * This exists because the natural way to write the `false` side is a trap.
 * `ConditionEvaluator::equals()` compares a bool with `==`, and the value typed
 * into the condition field is a string, so `found equals "false"` is `false ==
 * "false"`, and PHP reads the non-empty string as true. The tests below say
 * which spellings work and which do not, so a graph is not built on a guess.
 */
class BooleanOutputBranchingTest extends TestCase
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

        app(Automations::class)->action(RecentGrantAction::handle(), RecentGrantAction::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_equals_true_takes_the_true_edge_when_found_and_the_false_edge_when_not(): void
    {
        $conditions = [['field' => 'nodes.chk.found', 'operator' => 'equals', 'value' => 'true']];

        $this->assertSame('yes', $this->branchOutcome($conditions, found: true));
        $this->assertSame('no', $this->branchOutcome($conditions, found: false));
    }

    public function test_does_not_equal_true_is_the_mirror_image(): void
    {
        $conditions = [['field' => 'nodes.chk.found', 'operator' => 'does_not_equal', 'value' => 'true']];

        $this->assertSame('no', $this->branchOutcome($conditions, found: true));
        $this->assertSame('yes', $this->branchOutcome($conditions, found: false));
    }

    public function test_a_real_boolean_value_works_too(): void
    {
        // What a flat-file or API-written graph can carry.
        $conditions = [['field' => 'nodes.chk.found', 'operator' => 'equals', 'value' => true]];

        $this->assertSame('yes', $this->branchOutcome($conditions, found: true));
        $this->assertSame('no', $this->branchOutcome($conditions, found: false));
    }

    public function test_equals_the_string_false_is_a_trap_and_must_not_be_used(): void
    {
        // `true == "false"` is true and `false == "false"` is false in PHP: the
        // test is inverted. Use the false edge of an `equals true` branch.
        $conditions = [['field' => 'nodes.chk.found', 'operator' => 'equals', 'value' => 'false']];

        $this->assertSame('yes', $this->branchOutcome($conditions, found: true), 'found=true wrongly matches "false"');
        $this->assertSame('no', $this->branchOutcome($conditions, found: false), 'found=false wrongly misses "false"');
    }

    public function test_the_other_fields_are_plain_strings_for_conditions(): void
    {
        $conditions = [
            ['field' => 'nodes.chk.product_slug', 'operator' => 'equals', 'value' => 'toolkit'],
            ['field' => 'nodes.chk.product_title', 'operator' => 'is_not_empty'],
        ];

        $this->assertSame('yes', $this->branchOutcome($conditions, found: true, title: 'Das Toolkit'));
        $this->assertSame('no', $this->branchOutcome($conditions, found: true, title: null));
    }

    public function test_a_token_of_the_boolean_renders_as_1_or_nothing_and_a_switch_reads_that(): void
    {
        $this->assertSame('hit', $this->switchOutcome(found: true));
        $this->assertSame('default', $this->switchOutcome(found: false));
    }

    /**
     * @param  array<int, array<string, mixed>>  $conditions
     */
    private function branchOutcome(array $conditions, bool $found, ?string $title = null): string
    {
        $this->prepare($found, $title);

        $automation = $this->graph([
            ['t', 'manual', []],
            ['chk', RecentGrantAction::handle(), ['email' => 'anna@example.test']],
            ['br', 'branch', ['mode' => 'all', 'conditions' => $conditions]],
            ['yes', 'add_log_entry', ['message' => 'yes']],
            ['no', 'add_log_entry', ['message' => 'no']],
        ], [['t', 'chk'], ['chk', 'br'], ['br', 'yes', 'true'], ['br', 'no', 'false']]);

        $run = $this->start($automation);

        $this->assertSame(AutomationRun::STATUS_SUCCESS, $run->status);
        $this->assertSame($found, (bool) ($run->nodeRuns()->where('node_key', 'chk')->first()->output['found']));

        $ran = $run->nodeRuns()->whereIn('node_key', ['yes', 'no'])->pluck('node_key')->all();
        $this->assertCount(1, $ran, 'Exactly one side runs.');

        return $ran[0];
    }

    private function switchOutcome(bool $found): string
    {
        $this->prepare($found, null);

        $automation = $this->graph([
            ['t', 'manual', []],
            ['chk', RecentGrantAction::handle(), ['email' => 'anna@example.test']],
            ['sw', 'switch', ['value' => '{{ nodes.chk.found }}', 'cases' => ['1' => 'hit']]],
            ['hit', 'add_log_entry', ['message' => 'hit']],
            ['miss', 'add_log_entry', ['message' => 'miss']],
        ], [['t', 'chk'], ['chk', 'sw'], ['sw', 'hit', 'hit'], ['sw', 'miss', 'default']]);

        $run = $this->start($automation);

        $this->assertSame(AutomationRun::STATUS_SUCCESS, $run->status);

        return $run->nodeRuns()->where('node_key', 'sw')->first()->output['matched_case'] === 1 ? 'hit' : 'default';
    }

    private function prepare(bool $found, ?string $title): void
    {
        $this->manager->grants = [];
        $this->manager->products = [];

        if ($title !== null) {
            $this->manager->products = ['toolkit' => ['slug' => 'toolkit', 'label' => $title, 'group' => null]];
        }

        if ($found) {
            $this->manager->seed(new FakeEntitlement(
                id: 0,
                subject_type: 'email',
                subject_id: 'anna@example.test',
                product_slug: 'toolkit',
                source: 'test',
                source_ref: '',
                status: FakeState::Active,
                starts_at: Carbon::now()->subMinutes(5),
            ));
        }
    }

    private function start(Automation $automation): AutomationRun
    {
        $context = AutomationContext::make();
        $runner = app(WorkflowRunner::class);
        $run = $runner->createRun($automation, $context, $automation->nodes->firstWhere('node_key', 't'));

        return $runner->execute($run, $context);
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: array<string, mixed>}>  $nodes
     * @param  array<int, array{0: string, 1: string, 2?: string}>  $edges
     */
    private function graph(array $nodes, array $edges): Automation
    {
        $automation = Automation::create(['name' => 'T', 'handle' => 'test-'.uniqid()]);

        foreach ($nodes as [$key, $type, $config]) {
            AutomationNode::create([
                'automation_id' => $automation->id, 'node_key' => $key, 'type' => $type, 'config' => $config,
            ]);
        }

        foreach ($edges as $edge) {
            AutomationEdge::create([
                'automation_id' => $automation->id,
                'from_node_key' => $edge[0],
                'from_output' => $edge[2] ?? 'default',
                'to_node_key' => $edge[1],
            ]);
        }

        return $automation->fresh()->load(['nodes', 'edges']);
    }
}
