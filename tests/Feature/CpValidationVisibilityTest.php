<?php

/**
 * Structural guard for the failure this control-panel family keeps repeating:
 * the server rejects something, nothing is written, and the screen either does
 * not change or changes to say something the server never said.
 *
 * This addon's shape differs from its siblings. There is no Inertia form and
 * no error bag handed to a page — every call is axios, so whatever a `catch`
 * block does not dig out of the response is gone. The characteristic defect
 * here is therefore not a missing handler but a present one that discards its
 * own binding: `catch (e) { toast(__('Delete failed.')) }`, which reads to the
 * user as if the server had explained nothing.
 *
 * This layer reads sources. Whether the message then appears in the DOM is
 * asserted in tests/js/cp-validation-visibility.test.js — that is the layer
 * that caught the equivalent defect in marketing v1.5.3, where a key was
 * declared as handled at its field while the field only existed when creating.
 */

use Symfony\Component\Finder\Finder;

/** Every .vue page keyed by its path relative to resources/js/pages. */
function automationsPages(): array
{
    $pages = [];

    foreach (Finder::create()->files()->in(dirname(__DIR__, 2).'/resources/js/pages')->name('*.vue') as $file) {
        $pages[$file->getRelativePathname()] = $file->getContents();
    }

    ksort($pages);

    return $pages;
}

/**
 * Top-level `function name() { … }` bodies, keyed by name. Brace-matched
 * rather than regexed, so nested objects and closures stay inside the body
 * they belong to.
 */
function automationsFunctionBodies(string $source): array
{
    $bodies = [];
    $offset = 0;

    while (preg_match('/\bfunction\s+(\w+)\s*\([^)]*\)\s*\{/', $source, $m, PREG_OFFSET_CAPTURE, $offset)) {
        $name = $m[1][0];
        $start = $m[0][1] + strlen($m[0][0]);
        $depth = 1;
        $i = $start;

        while ($i < strlen($source) && $depth > 0) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
            }

            $i++;
        }

        $bodies[$name] = substr($source, $start, $i - $start - 1);
        $offset = $i;
    }

    return $bodies;
}

/** Does this block send something the server can reject? */
function automationsSubmits(string $block): bool
{
    return (bool) preg_match('/axios\.(post|patch|put|delete)\s*\(|router\.(post|patch|put|delete)\s*\(/', $block);
}

/** The `catch` clauses in a block, as [binding, body] pairs. */
function automationsCatchClauses(string $block): array
{
    $clauses = [];
    $offset = 0;

    while (preg_match('/\bcatch\s*(?:\(\s*(\w+)\s*\)\s*)?\{/', $block, $m, PREG_OFFSET_CAPTURE, $offset)) {
        $binding = $m[1][0] ?? '';
        $start = $m[0][1] + strlen($m[0][0]);
        $depth = 1;
        $i = $start;

        while ($i < strlen($block) && $depth > 0) {
            if ($block[$i] === '{') {
                $depth++;
            } elseif ($block[$i] === '}') {
                $depth--;
            }

            $i++;
        }

        $clauses[] = [$binding, substr($block, $start, $i - $start - 1)];
        $offset = $i;
    }

    return $clauses;
}

test('every submitting function handles the rejection it can receive', function (): void {
    $missing = [];

    foreach (automationsPages() as $page => $source) {
        foreach (automationsFunctionBodies($source) as $name => $body) {
            if (automationsSubmits($body) && automationsCatchClauses($body) === []) {
                $missing[] = "{$page}::{$name}()";
            }
        }
    }

    expect($missing)->toBe([], 'These submit to the server but ignore a rejected response, so the failure is invisible: '.implode(', ', $missing));
});

test('no catch reports a failure without reading what the server said', function (): void {
    // The two shapes this addon shipped:
    //   `catch { toast(__('Export failed.')) }`     — nothing bound at all
    //   `catch (e) { toast(__('Delete failed.')) }` — bound and then ignored
    // Both replace the server's sentence with a guess. Anything that reaches
    // into the response, directly or through support/serverErrors.js, passes.
    $blind = [];

    foreach (automationsPages() as $page => $source) {
        foreach (automationsFunctionBodies($source) as $name => $body) {
            if (! automationsSubmits($body)) {
                continue;
            }

            foreach (automationsCatchClauses($body) as [$binding, $clause]) {
                $reads = $binding !== '' && (
                    str_contains($clause, $binding.'?.response')
                    || str_contains($clause, $binding.'.response')
                    || preg_match('/\b(errorBag|errorMessages|firstMessage|report)\s*\(\s*'.preg_quote($binding, '/').'\b/', $clause)
                );

                if (! $reads) {
                    $blind[] = "{$page}::{$name}()";
                }
            }
        }
    }

    expect($blind)->toBe([], 'These answer a rejection with a message of their own invention and drop the server\'s: '.implode(', ', $blind));
});

test('the pages that act on a record can show what a refusal said', function (): void {
    // A toast is a two-second window. Where a refusal carries something the
    // user has to act on — the per-node reasons for a blocked enable, the
    // permission a delete wanted — the page keeps it on screen as well.
    $expected = ['Automations/Edit.vue', 'Automations/Index.vue'];
    $pages = automationsPages();

    $missing = array_values(array_filter(
        $expected,
        fn (string $page): bool => ! str_contains($pages[$page] ?? '', 'data-automations-form-errors')
    ));

    expect($missing)->toBe([], 'These act on a record but have no place to show why an action was refused: '.implode(', ', $missing));
});

test('the one validated key with a control on the page shows its error there', function (): void {
    // `name` is the only key Store/UpdateAutomationRequest validates that has a
    // visible input in this UI. The invalid ring said something was wrong; it
    // never said what, because it was only ever set by the client-side check.
    $edit = automationsPages()['Automations/Edit.vue'];

    expect($edit)->toContain('data-automations-field-error="name"');
    expect($edit)->toContain('serverErrors.value.name');
});

test('every key the CP validates has somewhere to show its error', function (): void {
    $validated = [];

    foreach (Finder::create()->files()->in(dirname(__DIR__, 2).'/src/Http/Requests')->name('*Request.php') as $file) {
        preg_match_all("/^\s+'([a-z_][a-z_.*]*)'\s*=>/m", $file->getContents(), $keys);
        $validated = array_merge($validated, $keys[1]);
    }

    $validated = array_values(array_unique($validated));

    expect($validated)->not->toBeEmpty();

    // Keys the canvas generates (`nodes.0.type`, `edges.1.to_node_key`) name an
    // array index no control corresponds to, and `handle` has no input at all.
    // They are covered by the collected output, which shows the bag as sent —
    // so the requirement is that the page keeps the whole bag, not that it
    // names each key.
    $edit = automationsPages()['Automations/Edit.vue'];

    expect($edit)->toContain('errorBag(e)');
    expect($edit)->toContain('generalErrors');
});
