<?php

namespace Goldnead\StatamicAutomations\Engine;

use Carbon\CarbonInterface;
use Goldnead\StatamicAutomations\Context\AutomationContext;
use Illuminate\Support\Carbon;

/**
 * Evaluates condition lists against an AutomationContext.
 *
 * Each condition has the shape:
 *   { field: "lead.status", operator: "equals", value: "Qualified" }
 *
 * The evaluator supports two modes:
 *   - mode = "all" → every condition must match (default)
 *   - mode = "any" → at least one condition must match
 */
class ConditionEvaluator
{
    public const MODE_ALL = 'all';
    public const MODE_ANY = 'any';

    public function __construct(protected TokenResolver $tokens)
    {
    }

    /**
     * @param  array<int, array{field: string, operator: string, value?: mixed}>  $conditions
     */
    public function evaluate(array $conditions, AutomationContext $context, string $mode = self::MODE_ALL): bool
    {
        if (empty($conditions)) {
            return true;
        }

        $results = array_map(
            fn (array $condition) => $this->evaluateOne($condition, $context),
            $conditions,
        );

        return $mode === self::MODE_ANY
            ? in_array(true, $results, true)
            : ! in_array(false, $results, true);
    }

    /**
     * @param  array{field: string, operator: string, value?: mixed}  $condition
     */
    public function evaluateOne(array $condition, AutomationContext $context): bool
    {
        $left = $context->get($condition['field'] ?? '');
        $operator = $condition['operator'] ?? 'equals';
        $right = array_key_exists('value', $condition)
            ? $this->tokens->resolve($condition['value'], $context)
            : null;

        return match ($operator) {
            'equals' => $this->equals($left, $right),
            'does_not_equal' => ! $this->equals($left, $right),
            'contains' => $this->contains($left, $right),
            'does_not_contain' => ! $this->contains($left, $right),
            'starts_with' => is_string($left) && is_string($right) && str_starts_with($left, $right),
            'ends_with' => is_string($left) && is_string($right) && str_ends_with($left, $right),
            'is_empty' => $this->isEmpty($left),
            'is_not_empty' => ! $this->isEmpty($left),
            'greater_than' => $this->compareNumeric($left, $right, '>'),
            'less_than' => $this->compareNumeric($left, $right, '<'),
            'greater_than_or_equal' => $this->compareNumeric($left, $right, '>='),
            'less_than_or_equal' => $this->compareNumeric($left, $right, '<='),
            'date_before' => $this->compareDate($left, $right, '<'),
            'date_after' => $this->compareDate($left, $right, '>'),
            'includes_tag' => $this->includesTag($left, $right),
            'status_is' => $this->equals($left, $right),
            'form_is' => $this->equals($left, $right),
            'collection_is' => $this->equals($left, $right),
            'site_is' => $this->equals($left, $right),
            'matches_regex' => $this->matchesRegex($left, $right),
            default => false,
        };
    }

    protected function equals(mixed $a, mixed $b): bool
    {
        if (is_string($a) && is_string($b)) {
            return strcasecmp($a, $b) === 0;
        }

        return $a == $b;
    }

    protected function contains(mixed $haystack, mixed $needle): bool
    {
        if (is_array($haystack)) {
            foreach ($haystack as $item) {
                if ($this->equals($item, $needle)) {
                    return true;
                }
            }

            return false;
        }

        if (is_string($haystack) && is_string($needle)) {
            return stripos($haystack, $needle) !== false;
        }

        return false;
    }

    protected function isEmpty(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        if (is_array($value)) {
            return empty($value);
        }

        return false;
    }

    protected function compareNumeric(mixed $left, mixed $right, string $operator): bool
    {
        if (! is_numeric($left) || ! is_numeric($right)) {
            return false;
        }

        $left = $left + 0;
        $right = $right + 0;

        return match ($operator) {
            '>' => $left > $right,
            '<' => $left < $right,
            '>=' => $left >= $right,
            '<=' => $left <= $right,
            default => false,
        };
    }

    protected function compareDate(mixed $left, mixed $right, string $operator): bool
    {
        $a = $this->toDate($left);
        $b = $this->toDate($right);

        if ($a === null || $b === null) {
            return false;
        }

        return match ($operator) {
            '>' => $a->greaterThan($b),
            '<' => $a->lessThan($b),
            default => false,
        };
    }

    protected function toDate(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    protected function includesTag(mixed $tags, mixed $needle): bool
    {
        if (! is_array($tags) || ! is_string($needle)) {
            return false;
        }

        foreach ($tags as $tag) {
            if (is_string($tag) && strcasecmp($tag, $needle) === 0) {
                return true;
            }
        }

        return false;
    }

    protected function matchesRegex(mixed $value, mixed $pattern): bool
    {
        if (! is_string($value) || ! is_string($pattern)) {
            return false;
        }

        try {
            return (bool) @preg_match($pattern, $value);
        } catch (\Throwable) {
            return false;
        }
    }
}
