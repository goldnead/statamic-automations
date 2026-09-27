<?php

namespace Goldnead\StatamicAutomations\Nodes\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Goldnead\StatamicAutomations\Support\SandboxedAntlers;
use Throwable;

/**
 * Builds a block of plain text from the data of the run, with Antlers.
 *
 * The node that turns rows into prose: a line per timetable entry, a section
 * only when there is something in it, a time in the zone the reader lives
 * in. A token field can put one value into a sentence; it cannot repeat a
 * line per item or leave a heading out when the list under it is empty. This
 * node can, with the Antlers an editor already knows from their templates:
 * `{{ items }}…{{ /items }}`, `{{ if }}`, modifiers.
 *
 * ## The template is not token-resolved
 *
 * Its field declares `resolve_tokens: false`, so the executor hands it over
 * exactly as typed. Resolved first, `{{ title }}` inside a loop would have
 * been looked up in the run (and found empty) before Antlers ever saw the
 * loop. Every value of the run is still there: the whole context is the
 * template's data, so `{{ nodes.query.pages }}` and `{{ item.title }}`
 * read the same as they do in a token.
 *
 * ## Sandboxed
 *
 * Only the run's data, only data modifiers, no tags but `foreach`
 * ({@see SandboxedAntlers}). A template that reaches for content, a partial or
 * a tag fails the node with that reason instead of rendering around it.
 *
 * ## Pure
 *
 * Reads the context, writes nothing. A test run renders exactly what a real
 * run would, from whatever data the test run has.
 */
class ComposeTextAction implements AutomationAction
{
    public function __construct(protected SandboxedAntlers $antlers) {}

    public static function handle(): string
    {
        return 'compose_text';
    }

    public static function label(): string
    {
        return 'Compose Text';
    }

    public static function description(): ?string
    {
        return 'Builds a block of plain text from the run data with Antlers: loops, conditions, modifiers.';
    }

    public static function group(): string
    {
        return 'Logic';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [
            [
                'handle' => 'template',
                'label' => 'Template',
                'type' => 'textarea',
                'required' => true,
                'tokenable' => true,
                'resolve_tokens' => false,
                'help' => 'Antlers with the run data: {{ nodes.query.pages }}…{{ /nodes.query.pages }}, {{ if … }}, modifiers such as {{ start | timezone(\'Europe/Berlin\') | format(\'H:i\') }}. Tags other than foreach do not run.',
            ],
            [
                'handle' => 'collapse_blank_lines',
                'label' => 'Tidy blank lines',
                'type' => 'toggle',
                'default' => true,
                'help' => 'Removes trailing spaces, keeps at most one blank line in a row and trims the start and the end. Conditions and loops leave blank lines behind otherwise.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function outputSchema(): array
    {
        return [
            'text' => 'string',
            'is_empty' => 'boolean',
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $template = $config['template'] ?? null;

        if (! is_string($template) || trim($template) === '') {
            return ActionResult::failed('A template is required.');
        }

        $data = $context->all();
        unset($data['_node_type']);

        try {
            $text = $this->antlers->render($template, $data);
        } catch (Throwable $e) {
            return ActionResult::failed(
                'The template could not be rendered: '.$e->getMessage()
                    .' Only the run data, data modifiers and the foreach tag are available here.',
            );
        }

        $text = str_replace(["\r\n", "\r"], "\n", $text);

        if (filter_var($config['collapse_blank_lines'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false) {
            $text = $this->tidy($text);
        }

        return ActionResult::success([
            'text' => $text,
            'is_empty' => trim($text) === '',
        ]);
    }

    protected function tidy(string $text): string
    {
        $lines = array_map('rtrim', explode("\n", $text));
        $text = implode("\n", $lines);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text, "\n");
    }
}
