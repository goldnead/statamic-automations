<?php

/*
 * The German CP. The empty state of the automations list stood in English
 * ("Build your first automation", "Start from a template"), and the German
 * texts go without dashes as punctuation.
 */

function automationsGermanJson(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../../resources/lang/de.json'), true);
}

it('translates every text of the automations list into German', function (): void {
    $de = automationsGermanJson();
    $core = json_decode((string) file_get_contents(__DIR__.'/../../vendor/statamic/cms/lang/de.json'), true);

    preg_match_all(
        '/__\(\s*([\'"])((?:(?!\1).)+)\1/u',
        (string) file_get_contents(__DIR__.'/../../resources/js/pages/Automations/Index.vue'),
        $matches,
    );

    $missing = array_values(array_filter(
        array_unique($matches[2]),
        fn (string $key): bool => ! str_contains($key, '::') && ! isset($de[$key]) && ! isset($core[$key]),
    ));

    expect($missing)->toBe([]);
});

it('writes the German JSON texts without dashes', function (): void {
    $found = array_keys(array_filter(
        automationsGermanJson(),
        fn (string $text): bool => (bool) preg_match('/\s[—–]\s/u', $text),
    ));

    expect($found)->toBe([]);
});

/*
 * JSON translations are global. A key named like another addon renamed that
 * addon everywhere ("Activity" made the activity addon read "Aktivität" in the
 * addon list), and a Statamic word with another value changes Statamic itself.
 */
it('does not rename other addons or Statamic words', function (): void {
    $own = automationsGermanJson();
    $core = json_decode((string) file_get_contents(__DIR__.'/../../vendor/statamic/cms/lang/de.json'), true);

    $others = ['Accounts', 'Activity', 'Affiliates', 'App API', 'Assessments', 'Certificates', 'Courses', 'Email Templates',
        'Entitlements', 'Events', 'Inbox', 'Insights', 'Invoices', 'LeadHub', 'Lead Magnets', 'Marketing', 'Notifications',
        'Preference Center', 'Suppression', 'Teams', 'Webhook Manager'];

    expect(array_values(array_intersect($others, array_keys($own))))->toBe([])
        ->and(array_keys(array_filter($own, fn (string $value, string $key): bool => isset($core[$key]) && $core[$key] !== $value, ARRAY_FILTER_USE_BOTH)))->toBe([]);
});
