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

/**
 * Every literal key of a `__('…')` call in resources/js, with the file it sits in.
 * Escaped quotes inside the key (`\'`) are unescaped, so the key is what the
 * browser looks up.
 *
 * @return array<string, string>
 */
function automationsJsTextKeys(): array
{
    $root = __DIR__.'/../../resources/js';
    $keys = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! preg_match('/\.(vue|js)$/', (string) $file)) {
            continue;
        }

        preg_match_all(
            '/__\(\s*([\'"])((?:\\\\.|(?!\1)[^\\\\])+)\1/us',
            (string) file_get_contents((string) $file),
            $matches,
        );

        foreach ($matches[2] as $key) {
            $keys[stripcslashes($key)] ??= substr((string) $file, strlen($root) + 1);
        }
    }

    return $keys;
}

// Names of sister addons. JSON translations are global, so they stay unkeyed.
const AUTOMATIONS_ADDON_NAMES = ['Webhook Manager', 'LeadHub'];

it('translates every text of the control panel into German', function (): void {
    $de = automationsGermanJson();
    $core = json_decode((string) file_get_contents(__DIR__.'/../../vendor/statamic/cms/lang/de.json'), true);

    $missing = [];

    foreach (automationsJsTextKeys() as $key => $file) {
        if (! str_contains($key, '::') && ! in_array($key, AUTOMATIONS_ADDON_NAMES, true) && ! isset($de[$key]) && ! isset($core[$key])) {
            $missing[] = $file.': '.$key;
        }
    }

    expect($missing)->toBe([]);
});

it('keys the control panel texts in English', function (): void {
    $german = [];

    foreach (automationsJsTextKeys() as $key => $file) {
        if (preg_match('/[äöüß]|\b(?:Vorlage|Vorlagen|Bitte|Keine|Diese|Doppelte|Erneut|Abbrechen|Betreff|Flow braucht)\b/u', $key)) {
            $german[] = $file.': '.$key;
        }
    }

    expect($german)->toBe([]);
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
