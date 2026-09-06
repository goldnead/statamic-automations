<?php

/**
 * Every URL a CP page hands to Vue must resolve to a route this addon
 * registered.
 *
 * The defect this exists for: `Automations/Index.vue` built its
 * "Start from a template" link with
 * `createUrl.replace('/create', '/templates')`. `createUrl` is
 * `/cp/automations/automations/create` — the create route carries a doubled
 * `automations/automations` segment — so the surgery produced
 * `/cp/automations/automations/templates`, while the real templates screen is
 * `/cp/automations/templates`. The only link on the empty state a user without
 * create-permission could click 404ed on every fresh install, and nothing in
 * the suite noticed: `php -l` cannot see it, and `CpRoutesTest` only proves the
 * templates route exists, not that anything points at it.
 *
 * So instead of asserting one link, this walks the Inertia props of every page,
 * collects every value that looks like a CP URL, and asks Laravel's router
 * whether it matches. Any future page controller that invents a URL by string
 * manipulation fails here.
 */

use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->actingAsSuperUser();
});

/**
 * Every string in the props tree that looks like a CP URL.
 *
 * @return array<string, string> flattened prop path => url
 */
function cpUrlsInProps(array $props, string $prefix = ''): array
{
    $found = [];

    foreach ($props as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

        if (is_array($value)) {
            $found += cpUrlsInProps($value, $path);

            continue;
        }

        if (! is_string($value)) {
            continue;
        }

        // Only CP targets. External links (the docs callout points at GitHub)
        // are not this test's business. `cp_route()` returns absolute URLs, so
        // match on the path component.
        $urlPath = parse_url($value, PHP_URL_PATH);

        if (! is_string($urlPath) || ! str_starts_with($urlPath, '/cp/')) {
            continue;
        }

        $host = parse_url($value, PHP_URL_HOST);

        if ($host !== null && $host !== parse_url(config('app.url'), PHP_URL_HOST)) {
            continue;
        }

        $found[$path] = $value;
    }

    return $found;
}

/**
 * Does this path reach a real handler?
 *
 * Statamic registers two catch-alls — `cp/{segments}` (`statamic.cp.404`) and
 * the front-end `{segments?}` (`statamic.site`) — so *every* `/cp/...` string
 * "matches a route" on some verb. A dead link therefore has to be recognised by
 * *which* route it lands on: a live CP target is a named `statamic.cp.*` route
 * that is not the 404 handler.
 */
function cpPathIsRoutable(string $url): bool
{
    $path = parse_url($url, PHP_URL_PATH) ?: $url;

    // Some props are POST-only endpoints (a run's retryUrl). The CP catch-all
    // swallows the GET, so every verb has to be tried before calling it dead.
    foreach (['GET', 'POST', 'PATCH', 'PUT', 'DELETE'] as $method) {
        try {
            $route = app('router')->getRoutes()->match(Request::create($path, $method));
        } catch (HttpException) {
            continue;
        }

        $name = (string) $route->getName();

        if (str_starts_with($name, 'statamic.cp.') && $name !== 'statamic.cp.404') {
            return true;
        }
    }

    return false;
}

function pageProps($response): array
{
    return json_decode($response->getContent(), true)['props'] ?? [];
}

dataset('cp pages', function () {
    return [
        'dashboard' => [fn () => cp_route('statamic-automations.dashboard')],
        'automations index (empty)' => [fn () => cp_route('statamic-automations.automations.index')],
        'builder create' => [fn () => cp_route('statamic-automations.automations.create')],
        'runs index' => [fn () => cp_route('statamic-automations.runs.index')],
        'templates' => [fn () => cp_route('statamic-automations.templates.index')],
        'import' => [fn () => cp_route('statamic-automations.import')],
        // No `settings` row: since 2026-09-06 that route renders no Vue page at
        // all, it redirects to brand-context's suite settings screen. There are
        // no props of ours to collect, and the redirect itself is pinned in
        // CpRoutesTest.
        'audit' => [fn () => cp_route('statamic-automations.audit')],
    ];
});

it('hands Vue only URLs the router can match', function (Closure $url): void {
    $response = $this->withHeaders(['X-Inertia' => 'true'])->get($url());
    $response->assertStatus(200);

    // Not every page links elsewhere, so an empty result is legitimate here.
    // The collector itself is proven by the record-bound test below.
    $urls = cpUrlsInProps(pageProps($response));

    foreach ($urls as $path => $target) {
        expect(cpPathIsRoutable($target))
            ->toBeTrue("Prop [{$path}] points at [{$target}], which matches no registered route.");
    }
})->with('cp pages');

it('hands Vue only URLs the router can match on record-bound pages', function (): void {
    $automation = Automation::create(['name' => 'Inquiry', 'handle' => 'inquiry']);
    $run = AutomationRun::create([
        'automation_id' => $automation->id,
        'trigger_node_key' => 't',
        'trigger_type' => 'manual',
        'status' => 'success',
        'context' => [],
        'is_test' => false,
    ]);

    $pages = [
        'builder edit' => cp_route('statamic-automations.automations.edit', $automation),
        'run detail' => cp_route('statamic-automations.runs.show', $run),
        'automations index (populated)' => cp_route('statamic-automations.automations.index'),
    ];

    foreach ($pages as $label => $url) {
        $response = $this->withHeaders(['X-Inertia' => 'true'])->get($url);
        $response->assertStatus(200);

        $urls = cpUrlsInProps(pageProps($response));

        // Guards the collector: these three pages all carry edit/show/api URLs,
        // so an empty result here means the extraction broke, not that the page
        // is link-free.
        expect($urls)->not->toBeEmpty("[{$label}] produced no CP URLs at all.");

        foreach ($urls as $path => $target) {
            expect(cpPathIsRoutable($target))
                ->toBeTrue("[{$label}] prop [{$path}] points at [{$target}], which matches no registered route.");
        }
    }
});

it('sends the templates screen its own route, not a rewrite of the create URL', function (): void {
    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('statamic-automations.automations.index'));

    $props = pageProps($response);

    expect($props['templatesUrl'] ?? null)->toBe(cp_route('statamic-automations.templates.index'));
    expect($props['createUrl'])->not->toBe($props['templatesUrl']);

    // The exact string the old empty state produced.
    expect(cpPathIsRoutable(str_replace('/create', '/templates', $props['createUrl'])))->toBeFalse();
});
