<?php

namespace Goldnead\StatamicAutomations\Nodes\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Goldnead\StatamicAutomations\Support\NormalizesKeyValue;
use Statamic\Facades\Term;

/**
 * Creates a taxonomy term from token-resolved data.
 *
 * Side effects are gated behind `persist_statamic_changes` so test runs
 * never write.
 */
class CreateTermAction implements AutomationAction
{
    use NormalizesKeyValue;

    public static function handle(): string
    {
        return 'create_term';
    }

    public static function label(): string
    {
        return 'Create Term';
    }

    public static function description(): ?string
    {
        return 'Creates a term in a Statamic taxonomy from token-resolved data.';
    }

    public static function group(): string
    {
        return 'Statamic';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [
            [
                'handle' => 'taxonomy',
                'label' => 'Taxonomy',
                'type' => 'select',
                'options_source' => 'statamic.taxonomies',
                'required' => true,
            ],
            [
                'handle' => 'site',
                'label' => 'Site',
                'type' => 'select',
                'options_source' => 'statamic.sites',
                'required' => false,
            ],
            [
                'handle' => 'slug',
                'label' => 'Slug',
                'type' => 'text',
                'required' => false,
                'tokenable' => true,
                'help' => 'Optional. Tokens allowed, e.g. {{ form.topic }}.',
            ],
            [
                'handle' => 'data',
                'label' => 'Field data',
                'type' => 'key_value',
                'required' => false,
                'help' => 'Field handle → value. Values may contain tokens. Include a "title" for the term label.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function outputSchema(): array
    {
        return [
            'term' => [
                'id' => 'string',
                'slug' => 'string',
                'taxonomy' => 'string',
            ],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $taxonomy = $config['taxonomy'] ?? null;
        if (empty($taxonomy)) {
            return ActionResult::failed('A taxonomy is required.');
        }

        $data = $this->normalizeKeyValue($config['data'] ?? []);
        $slug = $config['slug'] ?? null;
        $site = $config['site'] ?? null;

        if (empty($slug)) {
            $title = $data['title'] ?? null;
            $slug = is_string($title) && $title !== '' ? \Statamic\Support\Str::slug($title) : null;
        }

        if (empty($slug)) {
            return ActionResult::failed('A slug (or a "title" in field data) is required.');
        }

        if ($context->isTestMode() && ! config('automations.test_mode.persist_statamic_changes', false)) {
            return ActionResult::success([
                'preview' => compact('taxonomy', 'site', 'slug', 'data'),
                'note' => 'Test mode — term not created.',
            ]);
        }

        $term = Term::make()->taxonomy($taxonomy)->slug($slug);

        if (! empty($site) && method_exists($term, 'in')) {
            $term = $term->in($site);
        }

        $term->data($data);
        $term->save();

        return ActionResult::success([
            'term' => [
                'id' => method_exists($term, 'id') ? (string) $term->id() : "{$taxonomy}::{$slug}",
                'slug' => $slug,
                'taxonomy' => $taxonomy,
            ],
        ]);
    }
}
