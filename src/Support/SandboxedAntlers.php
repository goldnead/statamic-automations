<?php

namespace Goldnead\StatamicAutomations\Support;

use Statamic\View\Antlers\Language\Runtime\GlobalRuntimeState;
use Statamic\View\Antlers\Language\Runtime\RuntimeConfiguration;
use Statamic\View\Antlers\Language\Runtime\RuntimeParser;
use Statamic\View\Antlers\Language\Runtime\Tracing\TraceManager;

/**
 * Renders an Antlers template that an editor typed into a flow, against the
 * data of the run and nothing else.
 *
 * The template is user content in Statamic's own sense, and runs in the mode
 * Statamic uses for fields with `antlers: true`, only narrower:
 *
 *   - **No tags** except `foreach`. `{{ collection:… }}`, `{{ partial }}`,
 *     `{{ form:… }}`, `{{ user:… }}`, a site's own tags: none of them runs.
 *     A flow would otherwise read content, files or users the run was never
 *     given, and the result would land in a calendar or a mail.
 *   - **Only data modifiers** from {@see MODIFIERS}: text, lists, numbers,
 *     dates. Nothing that resolves a query, loads a translation file or
 *     turns a value into an asset.
 *   - **No cascade.** Without it `{{ site }}`, `{{ current_user }}` or a
 *     global set are unknown names, not values.
 *   - **No PHP, no method calls**, as in any user content.
 *
 * A violation throws rather than rendering empty: a template that quietly
 * drops a tag looks finished and is not.
 *
 * Statamic keeps the runtime's permissions in static state shared by every
 * parser in the process. The state is captured before and restored after
 * every render, so a flow running inside a web request does not leave the
 * site's own templates under this sandbox.
 */
class SandboxedAntlers
{
    /** The only tags a template may use. */
    public const TAGS = ['foreach', 'foreach:*'];

    /** Largest text a template may render. */
    public const MAX_BYTES = 65536;

    /**
     * Most Antlers nodes a render may enter: every variable, condition and
     * loop pass counts. A timetable of a few hundred rows needs a few
     * thousand; nested loops over large lists run into this first.
     */
    public const MAX_NODES = 50000;

    /** Modifiers that only look at the value in front of them. */
    public const MODIFIERS = [
        // text
        'ascii', 'backspace', 'camelize', 'collapse_whitespace', 'contains', 'contains_all', 'contains_any',
        'count_substring', 'dashify', 'deslugify', 'ends_with', 'ensure_left', 'ensure_right', 'entities',
        'excerpt', 'explode', 'format_number', 'headline', 'insert', 'kebab', 'lcfirst', 'length', 'lower',
        'nl2br', 'remove_left', 'remove_right', 'replace', 'safe_truncate', 'sanitize',
        'singular', 'plural', 'slugify', 'snake', 'spaceless', 'split', 'starts_with', 'strip_tags', 'studly', 'substr', 'surround',
        'swap_case', 'title', 'to_string', 'trim', 'truncate', 'ucfirst', 'upper', 'urlencode', 'urldecode',
        'rawurlencode', 'widont', 'word_count', 'wrap',
        // lists
        'at', 'chunk', 'collapse', 'count', 'filter_empty', 'first', 'flatten', 'flip', 'group_by', 'join',
        'keys', 'key_by', 'last', 'limit', 'list', 'offset', 'pluck', 'reverse', 'scope', 'select',
        'sentence_list', 'ampersand_list', 'sort', 'sum', 'unique', 'values', 'where', 'where_in', 'is_empty',
        'is_array', 'is_iterable', 'type_of', 'to_bool', 'to_json', 'bool_string', 'option_list',
        // numbers
        'add', 'ceil', 'divide', 'floor', 'mod', 'multiply', 'round', 'subtract', 'is_numeric',
        // dates
        'format', 'format_localized', 'format_translated', 'iso_format', 'modify_date', 'timezone', 'tz',
        'timestamp', 'is_future', 'is_past', 'is_today', 'is_tomorrow', 'is_yesterday', 'is_weekday',
        'is_weekend', 'days_ago', 'hours_ago', 'minutes_ago', 'relative', 'diff_for_humans',
        // checks
        'is_blank', 'is_email', 'is_url', 'is_json', 'is_lowercase', 'is_uppercase',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function render(string $template, array $data): string
    {
        $captured = $this->capture();

        try {
            $configuration = new RuntimeConfiguration;
            $configuration->throwErrorOnAccessViolation = true;
            $configuration->allowPhpInUserContent = false;
            $configuration->allowMethodsInUserContent = false;
            $configuration->guardedVariablePatterns = (array) config('statamic.antlers.guardedVariables', ['config.app.key']);
            $configuration->guardedContentVariablePatterns = $configuration->guardedVariablePatterns;
            $configuration->allowedContentTagPatterns = self::TAGS;
            $configuration->allowedContentModifiers = self::MODIFIERS;

            // The budget: a tracer that counts every node the runtime enters
            // and the size of what it has rendered, and stops the render
            // the moment either runs over, not after it filled the memory.
            $configuration->traceManager = new TraceManager;
            $configuration->traceManager->registerTracer(new SandboxBudget(self::MAX_NODES, self::MAX_BYTES));
            $configuration->isTracingEnabled = true;

            /** @var RuntimeParser $parser */
            $parser = app(RuntimeParser::class);
            $parser->setRuntimeConfiguration($configuration);

            GlobalRuntimeState::$isCascadeEnabled = false;
            GlobalRuntimeState::$isEvaluatingUserData = true;

            $output = (string) $parser->parse($template, $data);

            if (strlen($output) > self::MAX_BYTES) {
                throw new SandboxLimitExceeded('The text is longer than '.(self::MAX_BYTES / 1024).' KB.');
            }

            return $output;
        } finally {
            $this->restore($captured);
        }
    }

    /** @var list<string> */
    protected const STATE = [
        'allowPhpInContent', 'allowMethodsInContent', 'throwErrorOnAccessViolation',
        'bannedVarPaths', 'bannedContentVarPaths', 'bannedTagPaths', 'bannedContentTagPaths',
        'allowedContentTagPaths', 'bannedModifierPaths', 'bannedContentModifierPaths',
        'allowedContentModifierPaths', 'isEvaluatingUserData', 'isCascadeEnabled',
    ];

    /** @return array<string, mixed> */
    protected function capture(): array
    {
        $state = [];

        foreach (self::STATE as $property) {
            $state[$property] = GlobalRuntimeState::${$property};
        }

        return $state;
    }

    /** @param  array<string, mixed>  $state */
    protected function restore(array $state): void
    {
        foreach ($state as $property => $value) {
            GlobalRuntimeState::${$property} = $value;
        }
    }
}
