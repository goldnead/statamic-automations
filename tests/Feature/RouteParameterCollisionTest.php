<?php

use Goldnead\StatamicAutomations\Tests\TestCase;
use Illuminate\Support\Facades\Route;

/**
 * Route parameter names are one namespace shared by the whole application.
 *
 * `Route::bind('automation', …)` is registered on the router, not on the
 * package that calls it. From that moment every route named `{automation}` in
 * every installed addon resolves through this addon's repository — and the
 * route that loses does not fail loudly. It hands a foreign id to a repository
 * that has never heard of it and answers 404.
 *
 * That is not hypothetical. goldnead/statamic-leadhub 1.8.0 shipped
 * `/scoring/{rule}`; goldnead/statamic-webhook-manager bound `rule` to its own
 * rule repository. On the Hub, which has both, editing or deleting a scoring
 * rule did nothing at all, and said nothing at all, through a release — with a
 * green suite on both sides.
 *
 * ── The rule ──────────────────────────────────────────────────────────────
 *
 *   An addon may only bind parameter names that unambiguously belong to it.
 *   A bound name must be specific enough that no sibling package would reach
 *   for it by accident. Names that are NOT bound may stay generic — they
 *   cannot collide, because nothing resolves them.
 *
 * Here the shape of "belongs to it" is the `automation` prefix plus a capital.
 * This addon binds exactly one parameter and it is `automationFlow`; the
 * generic `{automation}` it claimed until 1.6.0 is free again. The URLs are
 * byte-identical either way — `/cp/automations/17/edit` is the same string
 * before and after, because a route parameter name is the placeholder, never
 * the path.
 *
 * ── Why this is checkable and the old snapshot list was not ───────────────
 *
 * The previous version of this file compared this addon's parameter names
 * against a hand-written list of names other installed packages bind. That
 * list can only describe the siblings as they are today: it says nothing about
 * the addon that starts binding `{handle}` next month, which is exactly the
 * case that hurts. It was also already wrong — it named `webhook`, `endpoint`,
 * `rule` and `template` as claimed by statamic-webhook-manager, which renamed
 * all four in its 1.7.0 and no longer claims any of them.
 *
 * The rule above needs no knowledge of the siblings at all. It is a property
 * of this package, so this package's own suite can enforce it.
 *
 * That is also what protects the generic names still in this route file.
 * `{handle}`, `{run}`, `{source}`, `{nodeRun}` and `{timestamp}` are unbound
 * and are staying unbound — renaming them would move text without removing any
 * exposure. They are safe as long as nobody binds them, and under the rule
 * above nobody may: a package that binds must bind a name of its own, and
 * `handle` is nobody's own.
 *
 * ── What this file still cannot do ────────────────────────────────────────
 *
 * A collision only exists once two packages are installed together, and a
 * package cannot see its siblings from inside its own suite. The last test
 * below adds the reverse direction for packages that do NOT follow our rule —
 * statamic/cms binds `{entry}`, `{collection}` and friends, and always will.
 * That list is short, third-party and stable; it is not a stand-in for knowing
 * what the siblings do.
 */

/**
 * Every route parameter this addon declares, read from the route files rather
 * than the router, so the check covers routes regardless of how the test bed
 * happens to mount them.
 *
 * Only string literals are scanned. The route files carry example URLs in their
 * comments, and a plain regex over the file text reports those as parameters.
 *
 * @return array<string, list<string>> parameter name => route files using it
 */
function automationsRouteParameters(): array
{
    $found = [];

    foreach (['cp.php', 'web.php'] as $file) {
        $path = __DIR__.'/../../routes/'.$file;

        if (! is_file($path)) {
            continue;
        }

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            preg_match_all('/\{([A-Za-z0-9_]+)\??\}/', $token[1], $matches);

            foreach ($matches[1] as $parameter) {
                $found[$parameter][] = $file;
            }
        }
    }

    return array_map('array_unique', $found);
}

/**
 * Every parameter name this addon binds application-wide, read out of its own
 * source rather than out of a list somebody keeps by hand. A binding added
 * anywhere under src/ is therefore subject to the rule, not only one added to
 * the service provider method somebody remembered to look at.
 *
 * Comments are stripped first, so a `Route::bind()` quoted in a docblock — this
 * file quotes its own rule several times — is not mistaken for a registration.
 *
 * @return array{names: list<string>, unverifiable: int}
 */
function automationsBoundParameters(): array
{
    $names = [];
    $calls = 0;

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__.'/../../src', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $code = '';

        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (! is_array($token)) {
                $code .= $token;

                continue;
            }

            $code .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1];
        }

        $calls += preg_match_all('/Route::bind\s*\(/', $code);

        preg_match_all('/Route::bind\s*\(\s*([\'"])([A-Za-z0-9_]+)\1/', $code, $matches);
        $names = array_merge($names, $matches[2]);
    }

    return [
        'names' => array_values(array_unique($names)),
        'unverifiable' => $calls - count($names),
    ];
}

it('mounts the CP routes with SubstituteBindings, so route bindings apply here', function (): void {
    // This addon's own {automationFlow} binding needs the middleware to do
    // anything, and so does any sibling's. If the CP route group in
    // tests/TestCase.php loses it, this callback is never invoked and the
    // request resolves normally — the state every bed in this family was in
    // when the LeadHub defect shipped.
    Route::bind('handle', function ($value) {
        abort(418, 'binding reached');
    });

    $this->getJson(cp_route('statamic-automations.api.nodes.describe', 'anything'))->assertStatus(418);
});

it('does not swallow a sibling addon\'s generic route parameter', function (): void {
    // These are not this addon's routes. TestCase::mountStandInSiblingRoutes()
    // registers them for a sibling package, with the same middleware a real CP
    // install uses, and they do nothing but echo their own parameter back. If this addon binds a name
    // they use, the echo never happens: the binder resolves the value against
    // this addon's repository first, finds nothing, and aborts 404 — precisely
    // what LeadHub's delete button did.
    //
    // Before {automation} was renamed to {automationFlow} this failed on
    // `automation` with 404. It is the test that had to fail first.
    $swallowed = [];

    foreach (TestCase::NAMES_A_SIBLING_MIGHT_USE as $name) {
        $response = $this->get('sibling-probe/'.$name.'/sibling-owned-id-42');

        if ($response->status() !== 200 || $response->getContent() !== 'sibling-owned-id-42') {
            $swallowed[] = sprintf(
                '{%s}: a sibling route with this parameter answered %d instead of echoing its own '
                    .'value — this addon resolves the name application-wide and ate it',
                $name,
                $response->status()
            );
        }
    }

    expect($swallowed)->toBe([], implode("\n", $swallowed));
});

it('binds only parameter names that belong to this addon', function (): void {
    $bound = automationsBoundParameters();

    expect($bound['unverifiable'])->toBe(0,
        'A Route::bind() whose parameter name is not a string literal cannot be checked here. '
            .'Keep the name literal, or this rule stops being enforceable.');

    $generic = [];

    foreach ($bound['names'] as $parameter) {
        if (! preg_match('/^automation[A-Z][A-Za-z0-9]*$/', $parameter)) {
            $generic[] = sprintf('{%s} is bound application-wide but is not this addon\'s name', $parameter);
        }
    }

    expect($generic)->toBe([], implode("\n", $generic)."\n"
        .'A Route::bind() reaches into every addon installed alongside. Bind only names '
        .'prefixed `automation` + a capital (automationFlow, …) so no sibling can pick one '
        .'by accident. Generic names are fine as long as they stay UNBOUND.');
});

it('keeps the bound names and the route files in agreement', function (): void {
    $bound = automationsBoundParameters()['names'];
    $declared = array_keys(automationsRouteParameters());

    expect(array_values(array_diff($bound, $declared)))->toBe([],
        'Bound but not used by any route in this addon — a binding with no route of its own is '
            .'pure exposure for the siblings, delete it.');

    $unbound = array_values(array_diff($declared, $bound));
    sort($unbound);

    // Unbound is where generic names are allowed to live: nothing resolves
    // them, so nothing can be taken from anyone. `{run}` and `{nodeRun}`
    // resolve through Laravel's *implicit* binding, which matches a route
    // parameter to a typed controller argument and is therefore scoped to that
    // one route. Only Route::bind() is application-wide, which is why only that
    // is the subject of the rule above.
    // `nodeKey` joined the list in 1.9 with the mail-list delete route. It is
    // and stays a plain string: the controller takes it as `string $nodeKey`
    // and looks the node up inside the automation the route already bound, so
    // there is nothing to resolve application-wide and nothing a sibling could
    // lose. A node key is only unique WITHIN one automation, which is the
    // reason a global binding for it could not exist even if somebody wanted
    // one.
    expect($unbound)->toBe(
        ['handle', 'nodeKey', 'nodeRun', 'run', 'source', 'timestamp'],
        'The unbound parameter names changed. Keep them unbound — and if one of these ever needs '
            .'a binding, rename it to `automation…` in the same commit.'
    );
});

it('uses no route parameter a third-party package binds', function (): void {
    // statamic/cms binds the CMS entity names application-wide and always will;
    // a route of ours called {entry} would lose in exactly the way LeadHub's
    // did. Sibling addons are deliberately NOT on this list any more — the rule
    // enforced above is what covers them, and a hand-kept snapshot of what the
    // siblings bind today is the thing that failed to see LeadHub coming, and
    // then went stale when webhook-manager renamed its four in 1.7.0.
    $boundByStatamic = [
        'asset', 'asset_container', 'collection', 'entry', 'form',
        'global', 'revision', 'site', 'taxonomy', 'term',
    ];

    $collisions = [];

    foreach (automationsRouteParameters() as $parameter => $files) {
        if (in_array($parameter, $boundByStatamic, true)) {
            $collisions[] = sprintf(
                '{%s} in routes/%s is bound application-wide by statamic/cms',
                $parameter,
                implode(', routes/', $files)
            );
        }
    }

    expect($collisions)->toBe([], implode("\n", $collisions));
});
