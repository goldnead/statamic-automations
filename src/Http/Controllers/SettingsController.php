<?php

namespace Goldnead\StatamicAutomations\Http\Controllers;

use Goldnead\StatamicAutomations\Http\Requests\UpdateSettingsRequest;
use Goldnead\StatamicAutomations\Integrations\IntegrationDetector;
use Goldnead\StatamicAutomations\Support\Settings;
use Illuminate\Http\JsonResponse;

class SettingsController extends Controller
{
    public function show(IntegrationDetector $detector): JsonResponse
    {
        $this->authorizeAction('view automations');

        return response()->json([
            'data' => [
                'queue' => config('automations.queue'),
                'queue_connection' => config('automations.queue_connection'),
                'runs' => config('automations.runs'),
                'test_mode' => config('automations.test_mode'),
                'features' => config('automations.features'),
                'security' => [
                    'redact_keys' => config('automations.security.redact_keys'),
                ],
                'integrations' => $detector->snapshot(),
            ],
        ]);
    }

    /**
     * Write the settings form.
     *
     * Answers with the values as they now stand rather than with the submitted
     * ones. A blank connection is stored as null and comes back as null, an
     * integer arrives from a text input as a string and comes back an integer,
     * and a value equal to the default is dropped and comes back as whatever
     * the config file says. Echoing the request instead would leave the screen
     * agreeing with itself and disagreeing with the installation.
     */
    public function update(UpdateSettingsRequest $request, Settings $settings): JsonResponse
    {
        $settings->save($this->normalise($request->validated()['settings']));

        return response()->json(['data' => $this->current($settings)]);
    }

    /**
     * Coerce the form's values into the types the config expects.
     *
     * HTML form controls hand back strings; `config('automations.runs.prune_after_days')`
     * is read straight into date arithmetic and a `"30"` there is a bug waiting
     * for a strict comparison. An empty string from a nullable field means
     * "unset", not the empty string.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function normalise(array $values): array
    {
        $fields = Settings::fields();
        $out = [];

        foreach ($values as $key => $value) {
            $field = $fields[$key] ?? null;

            if ($field === null) {
                continue;
            }

            if (($field['nullable'] ?? false) && ($value === '' || $value === null)) {
                $out[$key] = null;

                continue;
            }

            $out[$key] = match ($field['type']) {
                'boolean' => (bool) $value,
                'integer' => (int) $value,
                'list' => array_values(array_filter(
                    array_map(fn ($item) => trim((string) $item), (array) $value),
                    fn (string $item) => $item !== '',
                )),
                default => (string) $value,
            };
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    protected function current(Settings $settings): array
    {
        $values = [];

        foreach (array_keys(Settings::fields()) as $key) {
            $values[$key] = $settings->value($key);
        }

        return $values;
    }
}
