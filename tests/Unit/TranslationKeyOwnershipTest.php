<?php

/**
 * A package's JSON string translations are one Control Panel dictionary, not
 * one per addon.
 *
 * `loadJsonTranslationsFrom()` appends a directory to a single list on the
 * translator's file loader. At lookup time the loader merges every
 * `<locale>.json` in that list into one flat array, in registration order,
 * with `array_merge` — so the last package to register a key wins,
 * application-wide, for every caller of `__('…')` including statamic/cms
 * itself. There is no namespace, no prefix and no warning.
 *
 * It is the same shape as the route parameter collision next door: a thing that
 * is global by construction, claimed locally by each package, and invisible
 * from inside any one of them. Until 1.6.0 this addon's `de.json` carried four
 * bare CP words, and two of them disagreed with statamic/cms:
 *
 *     Templates   statamic/cms "Templates"     → this addon "Vorlagen"
 *     User        statamic/cms "Benutzer:in"   → this addon "Benutzer"
 *
 * Neither was confined to the automations screens. `User` is the plainer one:
 * Statamic's German uses a gender-inclusive form throughout, and four
 * convenience strings here undid it on every German CP page in the
 * application — an addon reversing a decision of the core for the whole install.
 *
 * ── The rule ──────────────────────────────────────────────────────────────
 *
 *   Do not ship a JSON translation for a key statamic/cms already owns. Where
 *   this addon genuinely means something else, change the SOURCE STRING so it
 *   is unambiguous — `Templates` became `Automation templates` — rather than
 *   overriding the core's translation of the shared word.
 *
 * `Dashboard` and `Settings` were dropped for the other reason: their values
 * were identical to statamic/cms's, so nothing a user sees changes, and a
 * duplicate that agrees today is only a disagreement waiting for one side to
 * be edited.
 *
 * ── What this file can and cannot do ──────────────────────────────────────
 *
 * statamic/cms is a hard dependency of this package, so its dictionary is
 * readable from inside this suite and this check needs no hub. What it cannot
 * see is the siblings: `Enabled` below is shipped by this addon and by
 * goldnead/statamic-leadhub with the same value, which is a shared word rather
 * than a defect, and statamic/cms does not define it at all — dropping it here
 * would leave the string untranslated in an install without LeadHub. Only the
 * hub can compare all installed packages at once; it does, in
 * `tests/Feature/GlobalTranslationDictionaryTest.php`.
 */

/**
 * Every JSON dictionary this addon ships, keyed by locale.
 *
 * @return array<string, array<string, string>>
 */
function automationsJsonTranslations(): array
{
    $dictionaries = [];

    foreach (glob(__DIR__.'/../../resources/lang/*.json') ?: [] as $path) {
        $dictionaries[basename($path, '.json')] = json_decode((string) file_get_contents($path), true) ?: [];
    }

    return $dictionaries;
}

/**
 * The same dictionary from statamic/cms, read out of the installed package.
 *
 * @return array<string, string>
 */
function statamicJsonTranslations(string $locale): array
{
    $path = __DIR__.'/../../vendor/statamic/cms/lang/'.$locale.'.json';

    return is_file($path)
        ? (json_decode((string) file_get_contents($path), true) ?: [])
        : [];
}

it('ships a dictionary for every locale it claims to translate', function (): void {
    // A guard with nothing to read passes for the wrong reason. If the lang
    // files move, this is what says so rather than the checks below going quiet.
    expect(array_keys(automationsJsonTranslations()))->toBe(['de']);
    expect(statamicJsonTranslations('de'))->toHaveKey('Templates');
});

it('retranslates no string statamic/cms already owns', function (): void {
    $collisions = [];

    foreach (automationsJsonTranslations() as $locale => $strings) {
        $core = statamicJsonTranslations($locale);

        foreach ($strings as $key => $value) {
            if (! array_key_exists($key, $core)) {
                continue;
            }

            $collisions[] = sprintf(
                '%s.json: "%s" is statamic/cms\'s string (%s); this addon %s it to "%s"',
                $locale,
                $key,
                $core[$key],
                $core[$key] === $value ? 'duplicates' : 'REPLACES',
                $value
            );
        }
    }

    expect($collisions)->toBe([], implode("\n", $collisions)."\n"
        .'JSON translations from every package merge into one Control Panel dictionary, so a key '
        .'here does not stay inside this addon — it replaces that string for statamic/cms and for '
        .'every sibling. Where this addon means something else, make the SOURCE string unambiguous '
        .'(`Templates` → `Automation templates`); where it means the same thing, drop the key and '
        .'let the core translate it.');
});

it('translates the source strings its own screens actually pass to __()', function (): void {
    // The other half of the rename: a source string changed in the code and not
    // in the dictionary is an untranslated CP, and it fails silently — __()
    // returns the key. These are the strings the nav and the page titles pass.
    $de = automationsJsonTranslations()['de'];

    foreach (['Automations', 'Runs', 'Audit log', 'Automation templates'] as $source) {
        expect($de)->toHaveKey($source);
    }

    // And the words handed back to statamic/cms are gone for good.
    foreach (['Templates', 'User', 'Dashboard', 'Settings'] as $surrendered) {
        expect($de)->not->toHaveKey($surrendered);
    }
});

/**
 * Every `__('…')` source string this addon's own code passes.
 *
 * @return list<string>
 */
function automationsSourceStrings(): array
{
    $roots = [__DIR__.'/../../resources/js', __DIR__.'/../../src'];
    $found = [];

    foreach ($roots as $root) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (! in_array($file->getExtension(), ['js', 'vue', 'mjs', 'php'], true)) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            // __('single') and __("double"), the two forms the tree uses.
            preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $contents, $single);
            preg_match_all('/__\(\s*"((?:[^"\\\\]|\\\\.)*)"/', $contents, $double);

            foreach (array_merge($single[1], $double[1]) as $match) {
                $found[] = stripcslashes($match);
            }
        }
    }

    return array_values(array_unique($found));
}

it('ships no translation for a string nothing passes to __()', function (): void {
    // `de.json` carried "No failed runs. " with a trailing space while
    // Dashboard.vue calls __('No failed runs.'). The lookup can never match, so
    // the German string was dead on arrival and nothing said so — __() just
    // returns the key and the CP shows English. A dictionary key that no source
    // string produces is either a typo like that one or a leftover from a
    // reworded screen; both are silent.
    $sources = automationsSourceStrings();

    // Guard the extraction itself: an empty or tiny result would pass the check
    // below for the wrong reason.
    expect(count($sources))->toBeGreaterThan(100);

    $orphans = [];

    foreach (automationsJsonTranslations() as $locale => $strings) {
        foreach (array_keys($strings) as $key) {
            if (! in_array($key, $sources, true)) {
                $orphans[] = sprintf('%s.json: "%s"', $locale, $key);
            }
        }
    }

    expect($orphans)->toBe([], implode("\n", $orphans)."\n"
        .'No __() call in resources/js or src passes these strings, so the translations can never '
        .'resolve. Fix the key to match the source string, or drop it.');
});
