<?php

namespace Goldnead\StatamicAutomations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAutomationRequest extends FormRequest
{
    use ScopesUniquenessToBrand;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && (! method_exists($user, 'can') || $user->can('edit automations'));
    }

    public function rules(): array
    {
        // `automationFlow`, not `automation` — the route parameter was renamed
        // so this addon binds only a name of its own. A stale key here would
        // not raise: route() would return null, the ignore-id would fall away,
        // and saving an automation without touching its handle would start
        // failing the unique rule against itself.
        $automationFlow = $this->route('automationFlow');
        $automationId = is_object($automationFlow) ? $automationFlow->id : $automationFlow;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'handle' => [
                'sometimes', 'required', 'string', 'max:255',
                $this->brandScoped(Rule::unique('automations', 'handle'))->ignore($automationId),
            ],
            'description' => ['nullable', 'string'],

            'nodes' => ['array'],
            'nodes.*.node_key' => ['required_with:nodes', 'string', 'max:255'],
            'nodes.*.type' => ['required_with:nodes', 'string', 'max:255'],
            'nodes.*.label' => ['nullable', 'string'],
            'nodes.*.position_x' => ['nullable', 'integer'],
            'nodes.*.position_y' => ['nullable', 'integer'],
            'nodes.*.config' => ['nullable', 'array'],
            'nodes.*.disabled' => ['nullable', 'boolean'],

            'edges' => ['array'],
            'edges.*.from_node_key' => ['required_with:edges', 'string'],
            'edges.*.from_output' => ['nullable', 'string'],
            'edges.*.to_node_key' => ['required_with:edges', 'string'],
            'edges.*.to_input' => ['nullable', 'string'],
        ];
    }
}
