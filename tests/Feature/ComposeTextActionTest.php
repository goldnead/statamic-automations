<?php

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Engine\NodeExecutor;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Nodes\Actions\ComposeTextAction;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Statamic\Facades\Antlers;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\View\Antlers\Language\Runtime\GlobalRuntimeState;

/*
 * Compose Text (2.23.0): a block of plain text from the run data, with
 * Antlers, and nothing but the run data. The case it is built for is a gig
 * sheet: a timetable line per entry in Berlin time, a section only when it
 * has lines, template lines without a value left out.
 */

function composeNode(string $template, array $extra = []): AutomationNode
{
    return new AutomationNode([
        'node_key' => 'text',
        'type' => 'compose_text',
        'config' => ['template' => $template, ...$extra],
    ]);
}

function composeRun(string $template, array $data = [], array $extra = [], bool $test = false)
{
    return app(NodeExecutor::class)->execute(composeNode($template, $extra), AutomationContext::make($data, $test));
}

function gigData(): array
{
    return [
        'nodes' => [
            'zeitplan' => ['pages' => [
                ['title' => 'Get In', 'properties' => ['Date' => ['start' => '2026-10-03T13:00:00+00:00', 'end' => null, 'time_zone' => null]]],
                ['title' => 'Soundcheck', 'properties' => ['Date' => ['start' => '2026-10-03T15:30:00+02:00', 'end' => '2026-10-03T16:15:00+02:00', 'time_zone' => 'Europe/Berlin']]],
            ]],
            'sheet' => ['page' => ['title' => 'Kulturhaus', 'url' => 'https://www.notion.so/abc', 'properties' => ['Programm' => [], 'Hotel' => ['Zimmer gebucht: ']]]],
        ],
    ];
}

it('registers as an action node that supports test runs', function () {
    expect(ComposeTextAction::handle())->toBe('compose_text')
        ->and(ComposeTextAction::supportsTestMode())->toBeTrue()
        ->and(app(NodeRegistry::class)->get('compose_text'))->not->toBeNull();
});

it('renders a timetable in Berlin time, a line per entry, without the loop variables being token-resolved first', function () {
    $template = <<<'ANTLERS'
Zeitplan
{{ nodes.zeitplan.pages }}{{ properties:Date:start | timezone('Europe/Berlin') | format('H:i') }}{{ if properties:Date:end }}–{{ properties:Date:end | timezone('Europe/Berlin') | format('H:i') }}{{ /if }} {{ title }}
{{ /nodes.zeitplan.pages }}

{{ if nodes.sheet.page.properties.Programm | count }}
Programm
{{ /if }}



Notion: {{ nodes.sheet.page.url }}
ANTLERS;

    $result = composeRun($template, gigData());

    expect($result->isSuccess())->toBeTrue((string) $result->error)
        ->and($result->output['text'])->toBe("Zeitplan\n15:00 Get In\n15:30–16:15 Soundcheck\n\nNotion: https://www.notion.so/abc")
        ->and($result->output['is_empty'])->toBeFalse();
});

it('keeps the text as rendered when tidying is off', function () {
    $result = composeRun("a  \n\n\n\nb\n", [], ['collapse_blank_lines' => false]);

    expect($result->output['text'])->toBe("a  \n\n\n\nb\n");
});

it('reports an empty result as is_empty', function () {
    $result = composeRun("{{ if nothing }}x{{ /if }}\n\n", []);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->output['text'])->toBe('')
        ->and($result->output['is_empty'])->toBeTrue();
});

it('renders the same in a test run, since it only reads', function () {
    $result = composeRun('Hallo {{ name | upper }}', ['name' => 'anders'], test: true);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->output['text'])->toBe('Hallo ANDERS');
});

it('fails without a template', function () {
    expect(composeRun('   ')->isFailed())->toBeTrue();
});

it('offers foreach over a list', function () {
    $result = composeRun('{{ foreach:tags }}{{ value }};{{ /foreach:tags }}', ['tags' => ['a', 'b']]);

    expect($result->isSuccess())->toBeTrue((string) $result->error)
        ->and($result->output['text'])->toBe('a;b;');
});

it('does not run tags that reach into content, partials or users', function (string $template) {
    Collection::make('secrets')->save();
    Entry::make()->collection('secrets')->slug('geheim')->data(['title' => 'GEHEIMER EINTRAG'])->save();

    $result = composeRun($template, ['name' => 'x']);

    expect($result->isFailed())->toBeTrue()
        ->and((string) $result->error)->toContain('could not be rendered')
        ->and(json_encode($result->output))->not->toContain('GEHEIMER EINTRAG');
})->with([
    'collection tag' => ['{{ collection:secrets }}{{ title }}{{ /collection:secrets }}'],
    'partial tag' => ['{{ partial:secret }}'],
    'partial src' => ['{{ partial src="secret" }}'],
    'user tag' => ['{{ user:profile }}{{ email }}{{ /user:profile }}'],
    'form tag' => ['{{ form:create in="contact" }}{{ /form:create }}'],
    'nocache tag' => ['{{ nocache }}x{{ /nocache }}'],
    'data modifier that is not whitelisted' => ['{{ name | resolve }}'],
    'php' => ['{{? echo "php"; ?}}'],
]);

it('proves the escape attempts above would have leaked outside the sandbox', function () {
    Collection::make('secrets')->save();
    Entry::make()->collection('secrets')->slug('geheim')->data(['title' => 'GEHEIMER EINTRAG'])->save();

    expect((string) Antlers::parse('{{ collection:secrets }}{{ title }}{{ /collection:secrets }}', [], true))
        ->toContain('GEHEIMER EINTRAG');
});

it('knows nothing of the cascade or the config', function () {
    config(['app.name' => 'SEITENNAME']);

    $result = composeRun('[{{ config:app:name }}][{{ site:handle }}][{{ current_user:email }}]', []);

    expect($result->isSuccess() ? $result->output['text'] : '')->not->toContain('SEITENNAME')
        ->and($result->isSuccess() ? $result->output['text'] : '')->not->toContain('default');
});

it('leaves the site templates outside the sandbox after a render', function () {
    $before = GlobalRuntimeState::$allowedContentTagPaths;
    $cascade = GlobalRuntimeState::$isCascadeEnabled;
    $userData = GlobalRuntimeState::$isEvaluatingUserData;

    composeRun('{{ collection:secrets }}{{ /collection:secrets }}');
    composeRun('ok');

    expect(GlobalRuntimeState::$allowedContentTagPaths)->toBe($before)
        ->and(GlobalRuntimeState::$isCascadeEnabled)->toBe($cascade)
        ->and(GlobalRuntimeState::$isEvaluatingUserData)->toBe($userData);
});

it('stops a template whose output runs over 64 KB, with the reason', function () {
    $result = composeRun('{{ rows }}{{ text }}{{ /rows }}', ['rows' => array_fill(0, 100, ['text' => str_repeat('x', 1000)])]);

    expect($result->isFailed())->toBeTrue()
        ->and((string) $result->error)->toContain('64 KB');
});

it('stops nested loops before they multiply out, with the reason', function () {
    $list = range(1, 60);

    $result = composeRun('{{ a }}{{ b }}{{ c }}.{{ /c }}{{ /b }}{{ /a }}', ['a' => $list, 'b' => $list, 'c' => $list]);

    expect($result->isFailed())->toBeTrue()
        ->and((string) $result->error)->toContain('steps');
});

it('offers no padding modifier to inflate a short value', function () {
    $result = composeRun("{{ name | str_pad(100000, 'x') }}", ['name' => 'a']);

    expect($result->isFailed())->toBeTrue()
        ->and((string) $result->error)->toContain('could not be rendered');
});

it('reads data named like a tag (user, form, collection) as data', function () {
    $data = [
        'user' => ['name' => 'Ada', 'email' => 'ada@example.org'],
        'form' => ['message' => 'Hallo'],
        'collection' => ['a', 'b'],
    ];

    $result = composeRun('{{ user.name }} {{ user:email }} {{ form.message }} {{ collection | join(",") }}{{ if user }} ja{{ /if }}', $data);

    expect($result->isSuccess())->toBeTrue((string) $result->error)
        ->and($result->output['text'])->toBe('Ada ada@example.org Hallo a,b ja');
});

it('still resolves tokens in every other node', function () {
    $node = new AutomationNode([
        'node_key' => 'vars',
        'type' => 'set_variable',
        'config' => ['variables' => ['greeting' => 'Hi {{ name }}']],
    ]);

    $result = app(NodeExecutor::class)->execute($node, AutomationContext::make(['name' => 'Ada']));

    expect($result->output['vars']['greeting'])->toBe('Hi Ada');
});
