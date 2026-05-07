<?php

namespace Goldnead\StatamicAutomations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAutomationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && (! method_exists($user, 'can') || $user->can('edit automations'));
    }

    public function rules(): array
    {
        $automation = $this->route('automation');
        $automationId = is_object($automation) ? $automation->id : $automation;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'handle' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('automations', 'handle')->ignore($automationId),
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
