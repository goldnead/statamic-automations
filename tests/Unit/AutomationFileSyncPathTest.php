<?php

/*
 * `automations.file_storage.path` ships as null. `config($key, $default)`
 * does not fall back on a null value, so the export folder became '' and
 * files went to `/{handle}.json`. An unset path means resources/automations;
 * a path that is only the root is refused.
 */

use Goldnead\StatamicAutomations\Export\AutomationFileSync;
use Goldnead\StatamicAutomations\Models\Automation;

it('uses resources/automations when the configured path is null', function (?string $configured): void {
    config()->set('automations.file_storage.path', $configured);

    $sync = app(AutomationFileSync::class);

    expect($sync->path())->toBe(resource_path('automations'))
        ->and($sync->path('welcome'))->toBe(resource_path('automations').'/welcome.json');
})->with([
    'null (the shipped default)' => [null],
    'empty string' => [''],
    'blank' => ['  '],
]);

it('keeps a configured path and drops its trailing slash', function (): void {
    config()->set('automations.file_storage.path', '/srv/site/automations/');

    expect(app(AutomationFileSync::class)->path('welcome'))->toBe('/srv/site/automations/welcome.json');
});

it('refuses to export into the filesystem root', function (): void {
    config()->set('automations.file_storage.path', '/');

    $automation = new Automation(['name' => 'Root', 'handle' => 'root-probe']);

    expect(fn () => app(AutomationFileSync::class)->exportToFile($automation))
        ->toThrow(RuntimeException::class, 'filesystem root');

    expect(file_exists('/root-probe.json'))->toBeFalse();
});
