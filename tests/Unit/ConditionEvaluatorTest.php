<?php

namespace Goldnead\StatamicAutomations\Tests\Unit;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Engine\ConditionEvaluator;
use Goldnead\StatamicAutomations\Engine\TokenResolver;
use Goldnead\StatamicAutomations\Tests\TestCase;

class ConditionEvaluatorTest extends TestCase
{
    private function evaluator(): ConditionEvaluator
    {
        return new ConditionEvaluator(new TokenResolver);
    }

    public function test_equals_passes(): void
    {
        $context = AutomationContext::make(['lead' => ['status' => 'Qualified']]);

        $result = $this->evaluator()->evaluate([
            ['field' => 'lead.status', 'operator' => 'equals', 'value' => 'Qualified'],
        ], $context);

        $this->assertTrue($result);
    }

    public function test_does_not_equal(): void
    {
        $context = AutomationContext::make(['lead' => ['status' => 'New']]);

        $result = $this->evaluator()->evaluate([
            ['field' => 'lead.status', 'operator' => 'does_not_equal', 'value' => 'Qualified'],
        ], $context);

        $this->assertTrue($result);
    }

    public function test_contains_array(): void
    {
        $context = AutomationContext::make(['lead' => ['tags' => ['Workshop', 'VIP']]]);

        $this->assertTrue($this->evaluator()->evaluate([
            ['field' => 'lead.tags', 'operator' => 'contains', 'value' => 'Workshop'],
        ], $context));

        $this->assertFalse($this->evaluator()->evaluate([
            ['field' => 'lead.tags', 'operator' => 'contains', 'value' => 'NotPresent'],
        ], $context));
    }

    public function test_empty_and_not_empty(): void
    {
        $context = AutomationContext::make(['lead' => ['email' => null, 'name' => 'Jane']]);

        $this->assertTrue($this->evaluator()->evaluate([
            ['field' => 'lead.email', 'operator' => 'is_empty'],
        ], $context));

        $this->assertTrue($this->evaluator()->evaluate([
            ['field' => 'lead.name', 'operator' => 'is_not_empty'],
        ], $context));
    }

    public function test_numeric_comparisons(): void
    {
        $context = AutomationContext::make(['attempts' => 5]);

        $this->assertTrue($this->evaluator()->evaluate([
            ['field' => 'attempts', 'operator' => 'greater_than', 'value' => 3],
        ], $context));

        $this->assertFalse($this->evaluator()->evaluate([
            ['field' => 'attempts', 'operator' => 'less_than', 'value' => 2],
        ], $context));
    }

    public function test_date_before_after(): void
    {
        $context = AutomationContext::make([
            'submission' => ['created_at' => '2026-01-01T12:00:00Z'],
        ]);

        $this->assertTrue($this->evaluator()->evaluate([
            ['field' => 'submission.created_at', 'operator' => 'date_before', 'value' => '2026-12-31'],
        ], $context));

        $this->assertTrue($this->evaluator()->evaluate([
            ['field' => 'submission.created_at', 'operator' => 'date_after', 'value' => '2025-01-01'],
        ], $context));
    }

    public function test_includes_tag_helper(): void
    {
        $context = AutomationContext::make(['lead' => ['tags' => ['Workshop', 'VIP']]]);

        $this->assertTrue($this->evaluator()->evaluate([
            ['field' => 'lead.tags', 'operator' => 'includes_tag', 'value' => 'workshop'],
        ], $context));
    }

    public function test_mode_any_matches_when_any_condition_passes(): void
    {
        $context = AutomationContext::make(['lead' => ['status' => 'New']]);

        $result = $this->evaluator()->evaluate([
            ['field' => 'lead.status', 'operator' => 'equals', 'value' => 'Qualified'],
            ['field' => 'lead.status', 'operator' => 'equals', 'value' => 'New'],
        ], $context, ConditionEvaluator::MODE_ANY);

        $this->assertTrue($result);
    }

    public function test_unknown_operator_returns_false(): void
    {
        $context = AutomationContext::make(['x' => 1]);

        $this->assertFalse($this->evaluator()->evaluate([
            ['field' => 'x', 'operator' => 'unknown_operator', 'value' => 1],
        ], $context));
    }
}
