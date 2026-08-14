<?php

namespace Goldnead\StatamicAutomations\Http\Requests;

use Goldnead\StatamicAutomations\Support\Settings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for the settings form, built from {@see Settings::fields()}.
 *
 * The rules are generated rather than written out, so a field cannot be added
 * to the screen and forgotten here — which is the failure that lets an unvalidated
 * value reach `config()` and, from there, every reader in the addon.
 */
class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && method_exists($user, 'can') && $user->can('manage automation settings');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = ['settings' => ['required', 'array']];

        foreach (Settings::fields() as $key => $field) {
            // Dots are path separators to the validator, and these keys carry
            // real dots (`runs.prune_after_days`). Escaped, the rule addresses
            // the one key rather than a nested structure that does not exist.
            $at = 'settings.'.str_replace('.', '\\.', $key);

            $rules[$at] = match ($field['type']) {
                'boolean' => ['present', 'boolean'],
                'integer' => array_filter([
                    'present',
                    $field['nullable'] ? 'nullable' : 'required',
                    'integer',
                    isset($field['min']) ? 'min:'.$field['min'] : null,
                ]),
                'list' => ['present', 'array'],
                default => [
                    'present',
                    $field['nullable'] ? 'nullable' : 'required',
                    'string',
                    'max:255',
                ],
            };

            if ($field['type'] === 'list') {
                // `nullable`, because Laravel's ConvertEmptyStringsToNull has
                // already turned a blank line from the textarea into null by the
                // time the rules run. Rejecting it would mean the form refuses
                // to save over a trailing newline — and the blanks are dropped
                // in the controller anyway, so nothing empty is ever stored.
                $rules[$at.'.*'] = ['nullable', 'string', 'max:255'];
            }
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        $attributes = [];

        foreach (Settings::fields() as $key => $field) {
            $attributes['settings.'.$key] = $field['label'];
        }

        return $attributes;
    }
}
